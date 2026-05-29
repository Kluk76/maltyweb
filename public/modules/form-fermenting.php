<?php
declare(strict_types=1);
/**
 * form-fermenting.php — Operator fermentation entry form.
 *
 * Writes to:
 *   bd_fermenting_v2   — one row per event submission.
 *
 * The table uses a ONE-ROW-PER-EVENT model keyed by (event_type, beer_raw,
 * batch, line_idx).  This form writes:
 *
 *   Reads   — gravity/pH/temperature readings + final_comments.
 *   DryHop  — one row per dry-hop addition (line_idx 0…N); each row carries
 *              dh_mi_id_fk/dh_raw_name/dh_qty/dh_unit/dh_lot + readings can
 *              be included on the first DryHop row (line_idx 0).
 *   Purge   — comment_purge + optional Reads on same row.
 *   ColdCrash — comment_cold_crash + optional Reads on same row.
 *
 * Column mapping (live bd_fermenting_v2 schema):
 *   row_hash      — sha256 of canonical content
 *   audit_flags   — 'web_entry'
 *   submitted_at  — datetime(6) UNIQUE per row
 *   email         — operator username
 *   event_date    — date of event (from HTML date input)
 *   event_type    — ENUM('DryHop','Reads','Purge','ColdCrash') — db column
 *   beer_raw      — raw beer identifier (e.g. "ZEP 213")
 *   batch         — batch number string (e.g. "213")
 *   line_idx      — 0 for all non-DryHop events; 0…N for DryHop rows
 *   recipe_id_fk  — INT UNSIGNED FK to ref_recipes.id (nullable)
 *   dh_category   — ENUM('hops_dry') — always 'hops_dry' on DryHop rows
 *   dh_mi_id_fk   — INT UNSIGNED FK to ref_mi.id (nullable on non-DH rows)
 *   dh_raw_name   — raw hop name as entered (nullable)
 *   dh_qty        — dry-hop quantity DECIMAL(10,3) in dh_unit
 *   dh_unit       — ENUM('kg','g') — always 'g' from this form (operators
 *                   enter grams for dry-hop additions)
 *   dh_lot        — lot number (nullable)
 *   dh_confidence — 'web_entry'
 *   dh_parse_note — 'direct-mi-pick' or 'unresolved-mi-id'
 *   gravity       — DECIMAL(6,3) in °Plato (stored value = °Plato, not SG)
 *   ph            — DECIMAL(4,2)
 *   temperature   — DECIMAL(5,2) in °C
 *   comment_purge — free-text, set on Purge rows
 *   comment_cold_crash — free-text, set on ColdCrash rows
 *   final_comments — general free-text notes (all event types)
 *
 * FLAGGED / NOT WIRED (mock vs schema):
 *   - Mock shows "OG" and "FG" inputs with computed attenuation. The DB
 *     stores only one 'gravity' column — not separate OG/FG. We send
 *     the gravity reading in the 'gravity' column. The operator labels
 *     which reading this is via the event context (Reads at day 0 = OG,
 *     Reads at ColdCrash stage = FG). There is NO separate OG/FG column.
 *     The form therefore has a single "Densité (°Plato)" field — this is
 *     the correct mapping to the live schema. If a separate OG vs FG split
 *     is needed in future, a migration would be required.
 *   - Mock "Purge" section shows CO2 pressure + dissolved CO2 fields.
 *     There are NO such columns in bd_fermenting_v2. These are UNWIRED.
 *     A future migration could add co2_pressure and co2_dissolved columns.
 *   - Mock "ColdCrash" shows target temp / current temp / crash date /
 *     planned duration. The DB has only comment_cold_crash. Only the
 *     temperature reading (shared gravity/ph/temp columns) + comment are
 *     wired. A future migration could add crash-specific columns.
 *   - Mock shows live beer identity context strip (beer/batch/CCT/day).
 *     This requires looking up a live in-fermentation batch. Currently
 *     the form picks beer/batch from pickers; the CCT and day-count are
 *     not exposed — they would require joining bd_brewing_brewday_v2 which
 *     is out of scope for this form pass.
 *   - The 'is_tombstoned' column is never set by the web form (defaults 0).
 *
 * Pattern: mirrors form-brewing.php exactly —
 *   CSRF → coerce → hash → bd_upsert → log_revision → flash → PRG redirect.
 *
 * NOT added to topbar nav — orchestrator will flip the saisies.php card
 * when approved.
 * URL: /modules/form-fermenting.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';

require_login();
$me = current_user();

// ── Allowed enum values ───────────────────────────────────────────────────────
// Mirrors bd_fermenting_v2.event_type ENUM exactly
const FERM_EVENT_TYPES = ['Reads', 'DryHop', 'Purge', 'ColdCrash'];
// Mirrors bd_fermenting_v2.dh_unit ENUM exactly
const DH_UNITS = ['g', 'kg'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-fermenting.php');
    }

    try {
        $pdo = maltytask_pdo();

        // ── 1. Coerce header inputs ────────────────────────────────────────
        $beerRaw     = post_str('beer_select') ?? '';
        $batch       = post_str('batch')       ?? '';
        $recipeId    = post_int('recipe_id_fk');
        $eventDateRaw = post_str('event_date');
        $eventType   = post_str('event_type')  ?? 'Reads';

        if ($beerRaw === '') {
            throw new RuntimeException("La bière est obligatoire.");
        }
        if ($batch === '') {
            throw new RuntimeException("Le numéro de brassin est obligatoire.");
        }

        must_be_one_of('event_type', $eventType, FERM_EVENT_TYPES);

        // Date: always store as Y-m-d in DB
        $eventDate = $eventDateRaw ?? date('Y-m-d');

        // ── 2. Shared measurements ─────────────────────────────────────────
        // gravity is stored in °Plato — operators enter °Plato directly.
        $gravityRaw = post_str('gravity');
        $gravity    = null;
        if ($gravityRaw !== null && $gravityRaw !== '') {
            $gNorm = str_replace(',', '.', $gravityRaw);
            if (!is_numeric($gNorm)) {
                throw new RuntimeException("Densité invalide « {$gravityRaw} ».");
            }
            $gravity = $gNorm;
        }

        $phRaw = post_str('ph');
        $ph    = null;
        if ($phRaw !== null && $phRaw !== '') {
            $phNorm = str_replace(',', '.', $phRaw);
            if (!is_numeric($phNorm)) {
                throw new RuntimeException("pH invalide « {$phRaw} ».");
            }
            $ph = $phNorm;
        }

        $tempRaw    = post_str('temperature');
        $temperature = null;
        if ($tempRaw !== null && $tempRaw !== '') {
            $tNorm = str_replace(',', '.', $tempRaw);
            if (!is_numeric($tNorm)) {
                throw new RuntimeException("Température invalide « {$tempRaw} ».");
            }
            $temperature = $tNorm;
        }

        // ── 3. Event-specific fields ───────────────────────────────────────
        $finalComments   = post_str('final_comments');
        $commentPurge    = ($eventType === 'Purge')      ? post_str('comment_purge')      : null;
        $commentColdCrash = ($eventType === 'ColdCrash') ? post_str('comment_cold_crash') : null;

        // fw_comment from diff-preview dialog
        $fwComment = post_str('fw_comment');

        // ── 4. Build submitted_at ──────────────────────────────────────────
        $submittedAt = date('Y-m-d H:i:s.u');
        $auditFlags  = 'web_entry';

        // ── 5. DryHop lines ───────────────────────────────────────────────
        // Input arrays: dh_mi_id[], dh_qty[], dh_unit[], dh_lot[]
        $dhMiIds = $_POST['dh_mi_id'] ?? [];
        $dhQtys  = $_POST['dh_qty']   ?? [];
        $dhUnits = $_POST['dh_unit']  ?? [];
        $dhLots  = $_POST['dh_lot']   ?? [];

        $dhLines = [];
        if ($eventType === 'DryHop') {
            $indices = array_keys($dhMiIds);
            foreach ($indices as $i) {
                $miId   = isset($dhMiIds[$i]) ? trim((string) $dhMiIds[$i]) : '';
                $qtyRaw = isset($dhQtys[$i])  ? trim((string) $dhQtys[$i])  : '';
                $unit   = isset($dhUnits[$i]) ? trim((string) $dhUnits[$i]) : 'g';
                $lot    = isset($dhLots[$i])  ? trim((string) $dhLots[$i])  : '';

                // Skip fully empty rows
                if ($miId === '' && $qtyRaw === '') continue;

                if ($unit !== '' && !in_array($unit, DH_UNITS, true)) {
                    throw new RuntimeException("Unité dry-hop invalide « {$unit} ».");
                }

                $qty = null;
                if ($qtyRaw !== '') {
                    $qNorm = str_replace(',', '.', $qtyRaw);
                    if (!is_numeric($qNorm)) {
                        throw new RuntimeException("Quantité dry-hop invalide « {$qtyRaw} ».");
                    }
                    $qty = $qNorm;
                }

                $dhLines[] = [
                    'mi_id' => $miId,
                    'qty'   => $qty,
                    'unit'  => $unit !== '' ? $unit : 'g',
                    'lot'   => $lot !== '' ? $lot : null,
                ];
            }

            if (empty($dhLines)) {
                throw new RuntimeException("Un houblonnage à froid doit contenir au moins une ligne.");
            }
        }

        // ── 6. Resolve dh_mi_id_fk for DryHop lines ──────────────────────
        $miIdFkMap = [];
        if (!empty($dhLines)) {
            $miIds = array_filter(array_column($dhLines, 'mi_id'));
            if (!empty($miIds)) {
                $placeholders = implode(',', array_fill(0, count($miIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT mi_id, id FROM ref_mi WHERE mi_id IN ($placeholders) AND is_active = 1"
                );
                $stmt->execute(array_values($miIds));
                foreach ($stmt->fetchAll() as $r) {
                    $miIdFkMap[$r['mi_id']] = (int) $r['id'];
                }
            }
        }

        // ── 7. Build and insert rows ───────────────────────────────────────
        // For DryHop: one row per line (line_idx 0…N).
        //   Readings (gravity/ph/temp) are copied onto the first line (line_idx=0)
        //   so they are captured even for DryHop submissions.
        // For all other event types: one row with line_idx=0.
        $rowsToInsert = [];

        if ($eventType === 'DryHop' && !empty($dhLines)) {
            foreach ($dhLines as $lineIdx => $line) {
                $miIdStr = $line['mi_id'] ?? '';
                $miFk    = $miIdStr !== '' ? ($miIdFkMap[$miIdStr] ?? null) : null;

                $hashCols = [
                    $eventType, $beerRaw, $batch, (string) $lineIdx,
                    $recipeId ?? '',
                    $eventDate, $miIdStr,
                    $line['qty'] ?? '',
                    $line['unit'],
                    $line['lot'] ?? '',
                    $gravity ?? '',
                    $ph ?? '',
                    $temperature ?? '',
                    $finalComments ?? '',
                    $submittedAt,
                ];

                $rowsToInsert[] = [
                    'row'      => [
                        'row_hash'            => bd_row_hash($hashCols),
                        'audit_flags'         => $auditFlags,
                        'submitted_at'        => $submittedAt,
                        'email'               => $me['username'],
                        'event_date'          => $eventDate,
                        'event_type'          => $eventType,
                        'beer_raw'            => $beerRaw,
                        'batch'               => $batch,
                        'line_idx'            => $lineIdx,
                        'recipe_id_fk'        => $recipeId,
                        'dh_category'         => 'hops_dry',
                        'dh_mi_id_fk'         => $miFk,
                        'dh_raw_name'         => $miIdStr !== '' ? $miIdStr : null,
                        'dh_qty'              => $line['qty'],
                        'dh_unit'             => $line['unit'],
                        'dh_lot'              => $line['lot'],
                        'dh_confidence'       => 'web_entry',
                        'dh_parse_note'       => $miFk !== null ? 'direct-mi-pick' : 'unresolved-mi-id',
                        'dh_source_row'       => null,
                        // Readings on first row only
                        'gravity'             => ($lineIdx === 0) ? $gravity : null,
                        'ph'                  => ($lineIdx === 0) ? $ph      : null,
                        'temperature'         => ($lineIdx === 0) ? $temperature : null,
                        'comment_purge'       => null,
                        'comment_cold_crash'  => null,
                        'final_comments'      => ($lineIdx === 0) ? $finalComments : null,
                    ],
                    'line_idx' => $lineIdx,
                ];
            }
        } else {
            // Reads / Purge / ColdCrash — single row, line_idx=0
            $hashCols = [
                $eventType, $beerRaw, $batch, '0',
                $recipeId ?? '',
                $eventDate,
                $gravity ?? '',
                $ph ?? '',
                $temperature ?? '',
                $commentPurge ?? '',
                $commentColdCrash ?? '',
                $finalComments ?? '',
                $submittedAt,
            ];

            $rowsToInsert[] = [
                'row'      => [
                    'row_hash'           => bd_row_hash($hashCols),
                    'audit_flags'        => $auditFlags,
                    'submitted_at'       => $submittedAt,
                    'email'              => $me['username'],
                    'event_date'         => $eventDate,
                    'event_type'         => $eventType,
                    'beer_raw'           => $beerRaw,
                    'batch'              => $batch,
                    'line_idx'           => 0,
                    'recipe_id_fk'       => $recipeId,
                    'dh_category'        => null,
                    'dh_mi_id_fk'        => null,
                    'dh_raw_name'        => null,
                    'dh_qty'             => null,
                    'dh_unit'            => null,
                    'dh_lot'             => null,
                    'dh_confidence'      => null,
                    'dh_parse_note'      => null,
                    'dh_source_row'      => null,
                    'gravity'            => $gravity,
                    'ph'                 => $ph,
                    'temperature'        => $temperature,
                    'comment_purge'      => $commentPurge,
                    'comment_cold_crash' => $commentColdCrash,
                    'final_comments'     => $finalComments,
                ],
                'line_idx' => 0,
            ];
        }

        // ── 8. Write rows ─────────────────────────────────────────────────
        $nkCols = ['submitted_at', 'event_type', 'beer_raw', 'batch', 'line_idx'];
        $firstId = null;
        foreach ($rowsToInsert as $entry) {
            $result = bd_upsert($pdo, 'bd_fermenting_v2', $entry['row'], $nkCols);
            if ($firstId === null) $firstId = $result['id'];

            log_revision(
                $pdo,
                $me,
                'bd_fermenting_v2',
                $result['id'],
                null,
                $entry['row'],
                'normal',
                $fwComment ?: null
            );
        }

        // ── 9. Success flash ──────────────────────────────────────────────
        $eventLabel = match($eventType) {
            'Reads'      => 'Mesures',
            'DryHop'     => 'Houblonnage à froid (' . count($rowsToInsert) . ' addition' . (count($rowsToInsert) > 1 ? 's' : '') . ')',
            'Purge'      => 'Purge',
            'ColdCrash'  => 'Cold Crash',
            default      => $eventType,
        };
        flash_set('ok', "{$eventLabel} enregistré — {$beerRaw} (B{$batch})");

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-fermenting'));
    }

    redirect_to('/modules/form-fermenting.php');
}

// ── GET ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // Recipes (active, for the beer picker)
    $recipes = $pdo->query(
        "SELECT id, name, classification, recipe_short_name
         FROM ref_recipes
         WHERE is_active = 1
         ORDER BY name ASC"
    )->fetchAll();

    // Hops MI catalog for dry-hop picker (Hops category, active only)
    $hopsMi = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.pricing_unit
         FROM ref_mi m
         JOIN ref_mi_categories c ON m.category_id = c.id
         WHERE c.name = 'Hops'
           AND m.is_active = 1
         ORDER BY m.name ASC"
    )->fetchAll();

    // Build compact JS-safe hop structure
    $hopsJs = [];
    foreach ($hopsMi as $h) {
        $hopsJs[] = [
            'id'    => (int)   $h['id'],
            'mi_id' => $h['mi_id'],
            'name'  => $h['name'],
            'unit'  => $h['pricing_unit'] ?? 'kg',
        ];
    }

    // Recent fermentation submissions (last 10 web-entered, any event type)
    $recentStmt = $pdo->prepare(
        "SELECT id, event_date, event_type, beer_raw, batch, gravity, ph, temperature,
                email, submitted_at
         FROM bd_fermenting_v2
         WHERE audit_flags LIKE '%web_entry%'
         ORDER BY submitted_at DESC
         LIMIT 10"
    );
    $recentStmt->execute();
    $recentFerm = $recentStmt->fetchAll();

    $loadErr = null;

} catch (Throwable $e) {
    $recipes   = [];
    $hopsJs    = [];
    $recentFerm = [];
    $loadErr   = $e->getMessage();
}

$csrf          = csrf_token();
$active_module = 'saisies';
$displayFmt    = date_display_format();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Fermentation — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/form-fermenting.css?v=<?= @filemtime(__DIR__ . '/../css/form-fermenting.css') ?: time() ?>">
</head>
<body class="home op-form-page form-fermenting">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Fermentation · Brewing</div>
    <h1 class="op-form__title">Saisie <em>fermentation</em></h1>
    <p class="op-form__sub">
      Enregistrement des mesures et évènements de fermentation : densités (°Plato),
      pH, température, houblonnage à froid, purge, cold crash. Toutes les valeurs
      sont acceptées sans blocage — saisies marquées <code>web_entry</code> pour l'audit.
    </p>
  </div>

  <!-- ── Phase dispatcher — composes form + recent via partials ────────────── -->
  <?php require __DIR__ . '/partials/session-body-fermenting.php' ?>

</main>

<!-- Hops catalog injected server-side for the dry-hop picker JS -->
<script>
window.FERMENTING_HOPS = <?= json_encode($hopsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/form-fermenting.js?v=<?= @filemtime(__DIR__ . '/../js/form-fermenting.js') ?: time() ?>" defer></script>

</body>
</html>
