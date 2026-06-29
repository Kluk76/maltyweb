<?php
declare(strict_types=1);
/**
 * scripts/backfill-packaging-readings-v2-key.php
 *
 * One-shot backfill: sets bd_packaging_readings.packaging_v2_id for reading
 * rows that are keyed only by the legacy bd_packaging.id (v1) and have
 * packaging_v2_id NULL.  This is the durable data-root fix — once readings
 * carry their v2 FK, the Consulter JOIN on packaging_v2_id works without any
 * runtime v1 fallback.
 *
 * ⚠️  SANCTIONED V1 READ EXCEPTION ─────────────────────────────────────────
 * The repo normally forbids reading v1 bd_* tables (operator standing order
 * 2026-06-11; coder/sql skill anti-pattern #31).  This script is the ONE
 * authorised exception: it reads bd_packaging.id / beer / batch / format
 * ONLY to derive the correct packaging_v2_id for orphaned readings, then
 * never touches v1 again.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Resolution gates (ALL must hold; refuse-don't-NULL on any failure):
 *   G1: bd_packaging.beer matches EXACTLY ONE ref_recipes.name
 *       (case- and whitespace-normalised) → resolves recipe_id_fk.
 *       sku-code strings (ALT4, ZEPV, …) and empty beer cells fail here by
 *       design — they need separate operator triage.
 *   G2: (recipe_id_fk + batch + mapped run_type) resolves to EXACTLY ONE
 *       non-tombstoned bd_packaging_v2 row.
 *       batch   = COALESCE(NULLIF(neb_batch,''), NULLIF(contract_batch,''))
 *       run_type mapped from v1.format via the table below.
 *       0 or >1 v2 events → unresolved.
 *   G3: Exactly ONE v1 parent resolves to that v2 event.
 *       Many-v1 → one-v2 → refused (ambiguous; needs manual reconciliation).
 *   G4: Only UPDATE rows WHERE packaging_v2_id IS NULL (idempotent; never
 *       clobbers an existing v2 key).
 *
 * v1 format → v2 run_type mapping:
 *   Bot / bot             → bot
 *   Can / can             → can
 *   Can33 / can33         → can33
 *   Keg / keg             → keg
 *   Cuve de service / cuv → cuv
 *
 * Dry-run output (always printed before any write):
 *   (A) MATCHED    — resolvable v1 parents + the v2 event + #readings affected
 *   (B) UNRESOLVED — refused parents, which gate failed, exact reason
 *
 * Usage (VPS, as www-data or root):
 *   sudo php /var/www/maltytask/scripts/backfill-packaging-readings-v2-key.php
 *       → dry-run (DEFAULT); prints MATCHED + UNRESOLVED, writes nothing.
 *
 *   sudo php /var/www/maltytask/scripts/backfill-packaging-readings-v2-key.php --apply
 *       → live UPDATE inside a transaction; snapshots before writing;
 *         writes one audit_row_revisions row per reading updated.
 *
 *   DO NOT run --apply without operator review of the MATCHED list.
 *   Re-running --apply is a no-op (Gate 4 guards against double-write).
 *
 * Verified reproducer: v1.id=2148 (Chien Bleu - Jasper, batch 28, Bot, 1 reading)
 *   → resolves to v2.id=2200 (recipe_id_fk=21, sku_id_fk=312 JASPB, batch 28).
 */

define('CLI_ACTOR',   'backfill-packaging-readings-v2-key');
define('CLI_USER_ID', 0);

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/db-write-helpers.php';

$apply = in_array('--apply', $argv ?? [], true);
$isDry = !$apply;

$pdo = maltytask_pdo();
$me  = ['id' => CLI_USER_ID, 'username' => CLI_ACTOR];

// ── v1 format → v2 run_type ──────────────────────────────────────────────────

function map_v1_format_to_run_type(string $fmt): ?string
{
    return match (strtolower(trim($fmt))) {
        'bot'             => 'bot',
        'can'             => 'can',
        'can33'           => 'can33',
        'keg'             => 'keg',
        'cuve de service' => 'cuv',
        'cuv'             => 'cuv',
        default           => null,
    };
}

// ── Step 1: all v1 parents that have at least one NULL-v2 reading ─────────────

$v1Parents = $pdo->query("
    SELECT p.id                          AS v1_id,
           TRIM(COALESCE(p.beer,  ''))   AS beer,
           TRIM(COALESCE(p.batch, ''))   AS batch,
           TRIM(COALESCE(p.format,''))   AS format,
           COUNT(r.id)                   AS reading_cnt
      FROM bd_packaging p
      JOIN bd_packaging_readings r ON r.packaging_id = p.id
     WHERE r.packaging_v2_id IS NULL
     GROUP BY p.id, p.beer, p.batch, p.format
     ORDER BY p.id
")->fetchAll(PDO::FETCH_ASSOC);

printf("v1 parents with NULL packaging_v2_id readings: %d\n\n", count($v1Parents));

// ── Step 2: load ref_recipes indexed by normalised name ──────────────────────

$recipeByNorm = [];
foreach ($pdo->query("SELECT id, name FROM ref_recipes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $rr) {
    $recipeByNorm[strtolower(trim($rr['name']))][] = $rr;
}

// ── Step 3: gate G1 + G2 per v1 parent ───────────────────────────────────────

/** @var list<array{v1_id:int,v2_id:int,recipe_id:int,recipe_name:string,batch:string,format:string,run_type:string,reading_cnt:int,event_date:string}> */
$potentialMatches = [];
/** @var list<array{v1_id:int,beer:string,batch:string,format:string,reading_cnt:int,failed_gate:string,reason:string}> */
$unresolved = [];

$v2Stmt = $pdo->prepare("
    SELECT id,
           COALESCE(NULLIF(neb_batch, ''), NULLIF(contract_batch, '')) AS v2_batch,
           run_type,
           event_date
      FROM bd_packaging_v2
     WHERE recipe_id_fk = ?
       AND run_type      = ?
       AND (NULLIF(neb_batch, '') = ? OR NULLIF(contract_batch, '') = ?)
       AND is_tombstoned  = 0
");

foreach ($v1Parents as $p) {
    $beer    = (string) $p['beer'];
    $batch   = (string) $p['batch'];
    $format  = (string) $p['format'];
    $v1Id    = (int)    $p['v1_id'];
    $readCnt = (int)    $p['reading_cnt'];

    // Gate 1 — exact normalised recipe name match (→ exactly one)
    if ($beer === '') {
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G1', 'reason' => 'empty beer string',
        ];
        continue;
    }
    $candidates = $recipeByNorm[strtolower(trim($beer))] ?? [];
    if (count($candidates) === 0) {
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G1',
            'reason' => "no recipe name match for beer='$beer'",
        ];
        continue;
    }
    if (count($candidates) > 1) {
        $names = implode(', ', array_column($candidates, 'name'));
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G1',
            'reason' => "ambiguous: beer name matches multiple recipes ($names)",
        ];
        continue;
    }
    $recipe   = $candidates[0];
    $recipeId = (int) $recipe['id'];

    // Gate 2a — format must map to a known run_type
    $runType = map_v1_format_to_run_type($format);
    if ($runType === null) {
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G2',
            'reason' => "format '$format' has no run_type mapping",
        ];
        continue;
    }

    // Gate 2b — batch must be non-empty
    if ($batch === '') {
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G2',
            'reason' => 'empty batch string',
        ];
        continue;
    }

    // Gate 2c — (recipe_id_fk + batch + run_type) → exactly one non-tombstoned v2 event
    $v2Stmt->execute([$recipeId, $runType, $batch, $batch]);
    $v2Events = $v2Stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($v2Events) === 0) {
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G2',
            'reason' => "0 v2 events for (recipe_id={$recipeId}, batch={$batch}, run_type={$runType})",
        ];
        continue;
    }
    if (count($v2Events) > 1) {
        $ids = implode(', ', array_column($v2Events, 'id'));
        $unresolved[] = [
            'v1_id' => $v1Id, 'beer' => $beer, 'batch' => $batch, 'format' => $format,
            'reading_cnt' => $readCnt, 'failed_gate' => 'G2',
            'reason' => "multiple v2 events ($ids) for (recipe_id={$recipeId}, batch={$batch}, run_type={$runType}) — ambiguous",
        ];
        continue;
    }

    $v2Event = $v2Events[0];
    $potentialMatches[] = [
        'v1_id'       => $v1Id,
        'v2_id'       => (int)   $v2Event['id'],
        'recipe_id'   => $recipeId,
        'recipe_name' => (string) $recipe['name'],
        'batch'       => $batch,
        'format'      => $format,
        'run_type'    => $runType,
        'reading_cnt' => $readCnt,
        'event_date'  => (string) $v2Event['event_date'],
    ];
}

// Gate 3 — no many-v1 → one-v2 ambiguity
$v2RefCount = [];
foreach ($potentialMatches as $m) {
    $v2RefCount[$m['v2_id']][] = $m['v1_id'];
}

$matched = [];
foreach ($potentialMatches as $m) {
    $v1sForV2 = $v2RefCount[$m['v2_id']];
    if (count($v1sForV2) > 1) {
        $conflict = implode(', ', $v1sForV2);
        $unresolved[] = [
            'v1_id' => $m['v1_id'], 'beer' => $m['recipe_name'], 'batch' => $m['batch'],
            'format' => $m['format'], 'reading_cnt' => $m['reading_cnt'],
            'failed_gate' => 'G3',
            'reason' => "v1 parents ($conflict) all resolve to the same v2 id={$m['v2_id']} — cannot determine 1:1 mapping",
        ];
    } else {
        $matched[] = $m;
    }
}

// ── Print (A) MATCHED ─────────────────────────────────────────────────────────

$totalReadings = (int) array_sum(array_column($matched, 'reading_cnt'));
printf("=== (A) MATCHED: %d v1 parents → %d readings would be re-keyed ===\n",
    count($matched), $totalReadings);
printf("%-10s %-8s %-36s %-7s %-8s %-7s %s\n",
    'v1_id', 'v2_id', 'recipe_name', 'batch', 'format', 'reads', 'v2_event_date');
printf("%s\n", str_repeat('-', 96));
foreach ($matched as $m) {
    printf("%-10d %-8d %-36s %-7s %-8s %-7d %s\n",
        $m['v1_id'], $m['v2_id'], $m['recipe_name'],
        $m['batch'], $m['format'], $m['reading_cnt'], $m['event_date']);
}

$jasperFound = false;
foreach ($matched as $m) {
    if ($m['v1_id'] === 2148 && $m['v2_id'] === 2200) {
        $jasperFound = true;
        break;
    }
}
printf("\nJasper reproducer (v1.id=2148 → v2.id=2200): %s\n",
    $jasperFound ? 'CONFIRMED' : 'NOT FOUND — check resolution logic');

// ── Print (B) UNRESOLVED ──────────────────────────────────────────────────────

printf("\n=== (B) UNRESOLVED: %d v1 parents ===\n", count($unresolved));
$byGate = [];
foreach ($unresolved as $u) {
    $byGate[$u['failed_gate']][] = $u;
}
ksort($byGate);
foreach ($byGate as $gate => $items) {
    printf("\n  --- %s failures (%d) ---\n", $gate, count($items));
    foreach ($items as $u) {
        $beerLabel  = ($u['beer'] !== '') ? $u['beer'] : '(empty)';
        $batchLabel = ($u['batch'] !== '') ? $u['batch'] : '(empty)';
        printf("    v1.id=%-10d  beer=%-35s  batch=%-8s  format=%s\n",
            $u['v1_id'], substr($beerLabel, 0, 35), substr($batchLabel, 0, 8), $u['format']);
        printf("      reason: %s\n", $u['reason']);
    }
}

printf("\n=== UNRESOLVED GATE SUMMARY ===\n");
foreach ($byGate as $gate => $items) {
    printf("  %s: %d v1 parent(s)\n", $gate, count($items));
}
printf("  TOTAL unresolved: %d  |  matched: %d  |  all parents: %d\n\n",
    count($unresolved), count($matched), count($v1Parents));

// ── Dry-run exit ──────────────────────────────────────────────────────────────

if ($isDry) {
    echo "Dry-run complete (default). Pass --apply to commit the MATCHED list.\n";
    exit(0);
}

// ── Apply: snapshot → transaction → UPDATE → audit ───────────────────────────

if (count($matched) === 0) {
    echo "--apply: nothing to do (0 matched parents).\n";
    exit(0);
}

// 1. Snapshot reading rows BEFORE any write
$snapshotDir = $root . '/data/snapshots';
if (!is_dir($snapshotDir) && !mkdir($snapshotDir, 0775, true) && !is_dir($snapshotDir)) {
    fwrite(STDERR, "ERROR: cannot create snapshot dir $snapshotDir\n");
    exit(1);
}
$ts           = gmdate('Ymd_His');
$snapshotFile = $snapshotDir . '/bd_packaging_readings-v2-backfill-' . $ts . '.json';

$matchedV1Ids   = array_column($matched, 'v1_id');
$placeholders   = implode(',', array_fill(0, count($matchedV1Ids), '?'));
$preShotStmt    = $pdo->prepare("
    SELECT id, packaging_id, packaging_v2_id, reading_idx, co2, o2, imported_at
      FROM bd_packaging_readings
     WHERE packaging_id IN ($placeholders)
       AND packaging_v2_id IS NULL
");
$preShotStmt->execute($matchedV1Ids);
$snapshotRows = $preShotStmt->fetchAll(PDO::FETCH_ASSOC);

$snapshotPayload = [
    'ts'              => gmdate('c'),
    'script'          => 'backfill-packaging-readings-v2-key.php',
    'matched_parents' => count($matched),
    'total_readings'  => count($snapshotRows),
    'rows'            => $snapshotRows,
];
if (file_put_contents($snapshotFile, json_encode($snapshotPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    fwrite(STDERR, "ERROR: cannot write snapshot to $snapshotFile\n");
    exit(1);
}
printf("Snapshot written: %s (%d reading rows)\n", $snapshotFile, count($snapshotRows));

// 2. Build v1_id → v2_id lookup
$v1ToV2 = [];
foreach ($matched as $m) {
    $v1ToV2[$m['v1_id']] = $m['v2_id'];
}

// 3. Transactional UPDATE + per-row audit
$pdo->beginTransaction();

$updateStmt = $pdo->prepare("
    UPDATE bd_packaging_readings
       SET packaging_v2_id = ?
     WHERE id              = ?
       AND packaging_v2_id IS NULL
");

$updatedTotal = 0;
$noOpTotal    = 0;

foreach ($snapshotRows as $row) {
    $readingId = (int) $row['id'];
    $v1Id      = (int) $row['packaging_id'];
    $v2Id      = $v1ToV2[$v1Id] ?? null;

    if ($v2Id === null) {
        // v1_id no longer in matched set (should not happen; guard only)
        $noOpTotal++;
        continue;
    }

    $updateStmt->execute([$v2Id, $readingId]);

    if ($updateStmt->rowCount() > 0) {
        log_revision(
            $pdo,
            $me,
            'bd_packaging_readings',
            $readingId,
            ['packaging_v2_id' => null],
            ['packaging_v2_id' => $v2Id],
            'normal',
            "backfill v1→v2 key: v1 packaging_id={$v1Id} resolved to bd_packaging_v2.id={$v2Id}"
        );
        $updatedTotal++;
    } else {
        $noOpTotal++;
    }
}

$pdo->commit();

printf("--apply complete: %d reading row(s) updated, %d no-op(s).\n",
    $updatedTotal, $noOpTotal);
printf("Snapshot: %s\n", $snapshotFile);
exit(0);
