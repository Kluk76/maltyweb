<?php
declare(strict_types=1);
/**
 * scripts/apply_merge_decisions.php
 *
 * Batch-apply client merge/validate/deactivate decisions produced by
 * convert_merge_xlsx.py.  Replicates EXACTLY the client_merge transaction
 * from public/modules/expeditions.php (client_merge action).
 *
 * Usage (VPS):
 *   sudo php scripts/apply_merge_decisions.php /path/to/decisions.json          # dry-run
 *   sudo php scripts/apply_merge_decisions.php /path/to/decisions.json --apply  # live
 *
 * Decision semantics:
 *   MERGE      → merge id into the CRM row identified by suggested_bc
 *   MERGE_BC   → merge id into the CRM row identified by target_bc
 *   VALIDER    → set needs_review=0 (confirmed non-BC client)
 *   DÉSACTIVER → set is_active=0, needs_review=0
 *   NON        → skip (leave in queue)
 *
 * MERGE semantics (mirrors client_merge in expeditions.php exactly):
 *   1. Reassign ord_orders.customer_id_fk  dup → target
 *   2. Copy trade_channel / default_transporter_id_fk onto target if target's IS NULL
 *   3. Append 'alias: <dup name>' to target.notes
 *   4. Tombstone dup: is_active=0, needs_review=0, notes += 'merged_into: <target_id>'
 *   5. log_revision for every touched row
 *
 * Validations (abort individual rows, never the whole batch):
 *   - id must exist, is_active=1, needs_review=1          (else SKIP+reason)
 *   - BC target must exist, is_active=1, needs_review=0, bc_customer_no IS NOT NULL
 *   - Same target receiving multiple merges is FINE
 *   - Duplicate id across sheets → abort the entire run (Python pre-checks this too)
 *
 * Idempotency: validations re-check current DB state — if a row was already
 * merged/validated in a prior run its needs_review/is_active flags will fail
 * the pre-flight check and it will be SKIP'd (no double-write).
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

define('CLI_ACTOR', 'batch-merge-cli');
define('CLI_USER_ID', 0);

$apply  = in_array('--apply', $argv ?? [], true);
$isDry  = !$apply;

// Locate decisions JSON (first non-flag argument)
$jsonPath = null;
foreach (($argv ?? []) as $arg) {
    if ($arg === $argv[0]) continue;  // skip script name
    if (str_starts_with($arg, '--')) continue;
    $jsonPath = $arg;
    break;
}

if ($jsonPath === null || !file_exists($jsonPath)) {
    fwrite(STDERR, "Usage: sudo php scripts/apply_merge_decisions.php /path/to/decisions.json [--apply]\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/db-write-helpers.php';

$pdo = maltytask_pdo();

// ── Load + validate JSON ─────────────────────────────────────────────────────

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read: $jsonPath\n");
    exit(1);
}
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['decisions'])) {
    fwrite(STDERR, "Invalid JSON format — expected {decisions: [...]}\n");
    exit(1);
}

// Check Python-side parse errors (duplicate ids are fatal)
$parseErrors = $data['errors'] ?? [];
$hasDupIds   = false;
foreach ($parseErrors as $pe) {
    if (str_contains($pe, 'ABORT')) {
        fwrite(STDERR, "ABORT: Python parse found duplicate ids — fix the xlsx first.\n");
        fwrite(STDERR, implode("\n", $parseErrors) . "\n");
        exit(2);
    }
}
if ($parseErrors) {
    echo "[WARN] Python parse warnings:\n";
    foreach ($parseErrors as $pe) {
        echo "  $pe\n";
    }
    echo "\n";
}

$decisions = $data['decisions'];

// ── CLI $me context (for log_revision) ───────────────────────────────────────

$me = ['id' => CLI_USER_ID, 'username' => CLI_ACTOR];

// ── Pre-flight: cross-sheet duplicate id check ────────────────────────────────
// (Python does this too, but we double-check server-side)

$seenIds = [];
foreach ($decisions as $dec) {
    $id = (int)($dec['id'] ?? 0);
    if (isset($seenIds[$id])) {
        fwrite(STDERR,
            "ABORT: id=$id appears in both sheet {$seenIds[$id]} and sheet {$dec['sheet']} — fix the xlsx.\n"
        );
        exit(2);
    }
    $seenIds[$id] = $dec['sheet'];
}

// ── Fetch all target rows by BC (batch read to avoid N+1 queries) ─────────────

function fetchTargetByBc(PDO $pdo, string $bc): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, bc_customer_no, is_active, needs_review, trade_channel,
                default_transporter_id_fk, notes
           FROM ref_customers
          WHERE bc_customer_no = ?
          LIMIT 1'
    );
    $stmt->execute([$bc]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function fetchRowById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, bc_customer_no, is_active, needs_review, trade_channel,
                default_transporter_id_fk, notes
           FROM ref_customers
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// ── Action counters ──────────────────────────────────────────────────────────

$counts = [
    'MERGE'        => 0,
    'VALIDER'      => 0,
    'DÉSACTIVER'   => 0,
    'NON'          => 0,
    'SKIP'         => 0,
];

$actionLog  = [];   // per-row action summaries
$errorList  = [];   // validation errors (rows skipped)

// ── Process each decision ─────────────────────────────────────────────────────

foreach ($decisions as $dec) {
    $sheetKey  = $dec['sheet']        ?? '?';
    $ref       = $dec['ref']          ?? '';
    $id        = (int)($dec['id']     ?? 0);
    $name      = $dec['name']         ?? '';
    $decision  = $dec['decision']     ?? 'NON';
    $suggestBc = $dec['suggested_bc'] ?? null;
    $targetBc  = $dec['target_bc']    ?? null;

    // ── NON: skip unconditionally ─────────────────────────────────────────
    if ($decision === 'NON') {
        $counts['NON']++;
        $actionLog[] = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — decision=NON";
        continue;
    }

    // ── Validate source row ───────────────────────────────────────────────
    $sourceRow = fetchRowById($pdo, $id);
    if ($sourceRow === null) {
        $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — row not found in DB";
        $errorList[]  = $msg;
        $actionLog[]  = $msg;
        $counts['SKIP']++;
        continue;
    }
    if (!(bool)$sourceRow['is_active']) {
        $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — already is_active=0 (previously merged/deactivated)";
        $errorList[]  = $msg;
        $actionLog[]  = $msg;
        $counts['SKIP']++;
        continue;
    }
    if (!(bool)$sourceRow['needs_review']) {
        $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — needs_review=0 (already processed)";
        $errorList[]  = $msg;
        $actionLog[]  = $msg;
        $counts['SKIP']++;
        continue;
    }

    // ── VALIDER ───────────────────────────────────────────────────────────
    if ($decision === 'VALIDER') {
        $before = $sourceRow;
        if (!$isDry) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE ref_customers
                        SET needs_review = 0, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?'
                );
                $stmt->execute([CLI_ACTOR, $id]);
                log_revision($pdo, $me, 'ref_customers', $id, $before,
                    ['needs_review' => 0, 'updated_by' => CLI_ACTOR],
                    'normal', 'Batch merge-decisions: validé tel quel');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = "ERROR [{$sheetKey}] #{$id} \"{$name}\" — VALIDER failed: " . $e->getMessage();
                $errorList[]  = $msg;
                $actionLog[]  = $msg;
                $counts['SKIP']++;
                continue;
            }
        }
        $counts['VALIDER']++;
        $actionLog[] = "VALIDER [{$sheetKey}] #{$id} \"{$name}\"";
        continue;
    }

    // ── DÉSACTIVER ────────────────────────────────────────────────────────
    if ($decision === 'DÉSACTIVER') {
        $before = $sourceRow;
        if (!$isDry) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'UPDATE ref_customers
                        SET is_active = 0, needs_review = 0, updated_by = ?,
                            updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?'
                );
                $stmt->execute([CLI_ACTOR, $id]);
                log_revision($pdo, $me, 'ref_customers', $id, $before,
                    ['is_active' => 0, 'needs_review' => 0, 'updated_by' => CLI_ACTOR],
                    'normal', 'Batch merge-decisions: désactivé');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = "ERROR [{$sheetKey}] #{$id} \"{$name}\" — DÉSACTIVER failed: " . $e->getMessage();
                $errorList[]  = $msg;
                $actionLog[]  = $msg;
                $counts['SKIP']++;
                continue;
            }
        }
        $counts['DÉSACTIVER']++;
        $actionLog[] = "DÉSACTIVER [{$sheetKey}] #{$id} \"{$name}\"";
        continue;
    }

    // ── MERGE / MERGE_BC ──────────────────────────────────────────────────
    if ($decision === 'MERGE' || $decision === 'MERGE_BC') {
        $bcToUse = ($decision === 'MERGE_BC') ? $targetBc : $suggestBc;

        if (empty($bcToUse)) {
            $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — no BC number for merge target";
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }

        $targetRow = fetchTargetByBc($pdo, $bcToUse);

        // Validate target
        if ($targetRow === null) {
            $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — target BC={$bcToUse} not found";
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }
        if (!(bool)$targetRow['is_active']) {
            $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — target BC={$bcToUse} is_active=0";
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }
        if ((bool)$targetRow['needs_review']) {
            $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — target BC={$bcToUse} is itself needs_review=1 (ineligible)";
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }
        // Self-merge guard
        $targetId = (int)$targetRow['id'];
        if ($targetId === $id) {
            $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — source and target are the same row";
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }

        // ── Dry-run: just log intent ──────────────────────────────────────
        if ($isDry) {
            $counts['MERGE']++;
            $actionLog[] =
                "MERGE [{$sheetKey}] #{$id} \"{$name}\" → #{$targetId} BC={$bcToUse} \"{$targetRow['name']}\"";
            continue;
        }

        // ── Live merge (mirrors expeditions.php client_merge exactly) ─────
        $pdo->beginTransaction();
        try {
            $clientId = $id;
            $clientRow = $sourceRow;

            // 1. Reassign orders
            $countOrdStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM ord_orders WHERE customer_id_fk = ?'
            );
            $countOrdStmt->execute([$clientId]);
            $ordCount = (int)$countOrdStmt->fetchColumn();

            if ($ordCount > 0) {
                $updOrd = $pdo->prepare(
                    'UPDATE ord_orders SET customer_id_fk = ?, updated_at = CURRENT_TIMESTAMP
                      WHERE customer_id_fk = ?'
                );
                $updOrd->execute([$targetId, $clientId]);
                log_revision($pdo, $me, 'ord_orders', $clientId, null,
                    ['reassigned_to' => $targetId, 'count' => $ordCount],
                    'normal', 'Batch fusion client: commandes réaffectées vers #' . $targetId);
            }

            // 2. Copy missing fields onto target
            $targetUpdates = [];
            $targetBefore  = $targetRow;
            if ($targetRow['trade_channel'] === null && $clientRow['trade_channel'] !== null) {
                $targetUpdates['trade_channel'] = $clientRow['trade_channel'];
            }
            if ($targetRow['default_transporter_id_fk'] === null
                    && $clientRow['default_transporter_id_fk'] !== null) {
                $targetUpdates['default_transporter_id_fk'] = $clientRow['default_transporter_id_fk'];
            }
            // Append alias to target notes
            $aliasNote   = 'alias: ' . $clientRow['name'];
            $targetNotes = trim(($targetRow['notes'] ?? '') . "\n" . $aliasNote);
            $targetUpdates['notes']      = $targetNotes;
            $targetUpdates['updated_by'] = CLI_ACTOR;

            $setClauses = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($targetUpdates)));
            $updTarget  = $pdo->prepare(
                "UPDATE ref_customers SET {$setClauses}, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updTarget->execute([...array_values($targetUpdates), $targetId]);
            log_revision($pdo, $me, 'ref_customers', $targetId, $targetBefore,
                array_merge($targetUpdates, ['id' => $targetId]),
                'normal', 'Batch fusion client: champs copiés depuis #' . $clientId);

            // 3. Tombstone the dup
            $dupNotes = trim(($clientRow['notes'] ?? '') . "\nmerged_into: {$targetId}");
            $updDup   = $pdo->prepare(
                'UPDATE ref_customers
                    SET is_active = 0, needs_review = 0, notes = ?, updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $updDup->execute([$dupNotes, CLI_ACTOR, $clientId]);
            log_revision($pdo, $me, 'ref_customers', $clientId, $clientRow,
                ['is_active' => 0, 'needs_review' => 0, 'notes' => $dupNotes,
                 'updated_by' => CLI_ACTOR],
                'normal', 'Batch fusion client: fusionné dans #' . $targetId);

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = "ERROR [{$sheetKey}] #{$id} \"{$name}\" — MERGE failed: " . $e->getMessage();
            $errorList[]  = $msg;
            $actionLog[]  = $msg;
            $counts['SKIP']++;
            continue;
        }

        $counts['MERGE']++;
        $actionLog[] =
            "MERGE [{$sheetKey}] #{$id} \"{$name}\" → #{$targetId} BC={$bcToUse} \"{$targetRow['name']}\"";
        continue;
    }

    // Unknown decision — should never reach here
    $msg = "SKIP  [{$sheetKey}] #{$id} \"{$name}\" — unknown decision: {$decision}";
    $errorList[]  = $msg;
    $actionLog[]  = $msg;
    $counts['SKIP']++;
}

// ── Output ────────────────────────────────────────────────────────────────────

$mode = $isDry ? '[DRY-RUN]' : '[APPLY]';
echo "\n=== apply_merge_decisions.php $mode ===\n\n";

echo "── Action counts ─────────────────────────────────────────────────────\n";
echo sprintf("  MERGE       : %d\n", $counts['MERGE']);
echo sprintf("  VALIDER     : %d\n", $counts['VALIDER']);
echo sprintf("  DÉSACTIVER  : %d\n", $counts['DÉSACTIVER']);
echo sprintf("  NON (skipped by operator): %d\n", $counts['NON']);
echo sprintf("  SKIP (validation errors) : %d\n", $counts['SKIP']);
echo "\n";

echo "── Full action list ───────────────────────────────────────────────────\n";
foreach ($actionLog as $line) {
    echo "  $line\n";
}
echo "\n";

if ($errorList) {
    echo "── Validation errors / skips ─────────────────────────────────────────\n";
    foreach ($errorList as $e) {
        echo "  $e\n";
    }
    echo "\n";
}

if (!$isDry) {
    // Final remaining needs_review count
    $remaining = (int)$pdo->query(
        'SELECT COUNT(*) FROM ref_customers WHERE needs_review=1 AND is_active=1'
    )->fetchColumn();
    echo "── Post-apply DB state ────────────────────────────────────────────────\n";
    echo sprintf("  Remaining needs_review=1 AND is_active=1 : %d\n", $remaining);
    echo "\n";
}

if ($isDry) {
    echo "[DRY-RUN] No writes performed. Re-run with --apply to execute.\n";
}
