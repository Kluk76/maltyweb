<?php
declare(strict_types=1);
/**
 * form-brewing.php — Operator brewing entry form.
 *
 * Writes to:
 *   bd_brewing_brewday_v2          — one row per (beer, batch) — idempotent on
 *                                    re-submit. NK: (beer, batch). row_hash keyed
 *                                    on (beer, batch) only — same hash on re-submit
 *                                    → ON DUPLICATE KEY UPDATE, no duplicate header.
 *   bd_brewing_ingredients_v2      — one header row per submission (raw_blob_text =
 *                                    JSON encoding of ingredient lines for audit trail)
 *   bd_brewing_ingredients_parsed_v2 — one row per ingredient line
 *                                    (header_id FK, line_idx, category,
 *                                     mi_id_fk, raw_name, qty, unit, lot)
 *   bd_brewing_gravity_v2          — N Cooling rows per batch (one per sub-brew).
 *                                    NK: (beer, batch, brew, event_type='Cooling').
 *                                    brew = row position 1..N. Each row is idempotent
 *                                    on re-submit (same NK → upsert refreshes values).
 *                                    Batch total cast-out = SUM(final_volume) over all
 *                                    Cooling rows — downstream already groups by batch.
 *
 * NOT wired:
 *   bd_brewing_gravity_v2 (other event types) — FirstWort / Pfannevoll / Kochwurze
 *                          require a per-sub-brew gravity form (brew > 1).
 *   bd_brewing_timings_v2   — per-brew start/end timestamps. Separate form required.
 *
 * Pattern: mirrors form-racking.php exactly —
 *   CSRF → coerce → hash → bd_upsert → log_revision → flash → PRG redirect.
 *
 * NOT added to topbar nav — orchestrator will flip the saisies.php card and
 * add nav when approved.
 * URL: /modules/form-brewing.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/cip-events.php';

require_login();
$me = current_user();

// ── Allowed enum values ───────────────────────────────────────────────────────
// Mirrors db ENUM exactly: enum('malt','hops_kettle','hops_dry','adjunct','mineral','process')
const ING_CATEGORIES = ['malt','hops_kettle','hops_dry','adjunct','mineral','process'];
// Mirrors db ENUM: enum('kg','g','ml')
const ING_UNITS = ['kg','g','ml'];

// ── POST handler ──────────────────────────────────────────────────────────────
// $overwriteConflict: non-null when an existing brewday header was detected and
// the operator did not yet confirm the overwrite. The GET render below checks
// this variable to inject the WARNING banner and preserve all POST values as
// sticky defaults, so the operator does not have to retype anything.
$overwriteConflict = null;  // ['recipe_name'=>string, 'cct'=>int|null, 'event_date'=>string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-brewing.php');
    }

    try {
        $pdo = maltytask_pdo();

        // ── 1. Coerce header inputs ───────────────────────────────────────────
        $beer        = post_str('beer_select') ?? '';
        $batch       = post_str('batch')       ?? '';
        $recipeId    = post_int('recipe_id_fk');
        $eventDateRaw = post_str('event_date');

        if ($beer === '') {
            throw new RuntimeException("La bière (recette) est obligatoire.");
        }
        if ($batch === '') {
            throw new RuntimeException("Le numéro de brassin est obligatoire.");
        }

        // Date: always store as Y-m-d in DB; HTML date input always returns Y-m-d
        $eventDate = $eventDateRaw ?? date('Y-m-d');

        // CCT number (integer 1–99)
        $cct = post_int('cct');

        // CIP events via shared parser (replaces old flat cct_cip/cct_cip_date/yt_cip_date)
        $cipEvents = cip_parse_post($_POST, 'brewing');

        // Yeast
        $yeast       = post_str('yeast_select') ?? post_str('yeast_other');
        $yeastGen    = post_str('yeast_gen');
        $newYeast    = post_str('new_yeast');
        $pitchedFrom = post_str('pitched_from');
        $ytNumber    = post_int('yt_number');

        $brewComments = post_str('comments');

        // fw_comment from diff-preview dialog
        $fwComment = post_str('fw_comment');

        // ── FIX 2: Confirm-overwrite guard (brewday header) ───────────────────
        // Check for an existing non-tombstoned brewday row for (beer, batch).
        // NEW batches pass through instantly (no existing row → no prompt).
        // Existing batches require explicit confirmation to prevent accidental
        // overwrites of recipe_id_fk / cct / yeast that feed sb_fermentation_occupancy().
        $confirmOverwrite = ($_POST['confirm_overwrite'] ?? '') === '1';

        $existingBrewday = $pdo->prepare(
            "SELECT b.id, b.recipe_id_fk, b.cct, b.event_date,
                    r.name AS recipe_name
               FROM bd_brewing_brewday_v2 b
               LEFT JOIN ref_recipes r ON r.id = b.recipe_id_fk
              WHERE b.beer = ? AND b.batch = ? AND b.is_tombstoned = 0
              LIMIT 1"
        );
        $existingBrewday->execute([$beer, $batch]);
        $existingRow = $existingBrewday->fetch();

        if ($existingRow !== false && !$confirmOverwrite) {
            // Conflict detected — do NOT write anything. Fall through to GET
            // render with the warning banner and sticky POST values.
            $overwriteConflict = [
                'recipe_name' => $existingRow['recipe_name'] ?? $existingRow['recipe_id_fk'],
                'cct'         => $existingRow['cct'],
                'event_date'  => $existingRow['event_date'],
            ];
            // Do not redirect — fall through to the GET render block below.
            // The $overwriteConflict variable signals the render to show the banner.
            goto render_form;
        }

        // ── 2. Parse ingredient lines ─────────────────────────────────────────
        // Input arrays: ing_mi_id[], ing_cat[], ing_qty[], ing_unit[], ing_lot[]
        $ingMiIds  = $_POST['ing_mi_id']  ?? [];
        $ingCats   = $_POST['ing_cat']    ?? [];
        $ingQtys   = $_POST['ing_qty']    ?? [];
        $ingUnits  = $_POST['ing_unit']   ?? [];
        $ingLots   = $_POST['ing_lot']    ?? [];

        $ingLines = [];
        $indices = array_keys($ingMiIds);
        foreach ($indices as $i) {
            $miId   = isset($ingMiIds[$i])  ? trim((string) $ingMiIds[$i])  : '';
            $cat    = isset($ingCats[$i])   ? trim((string) $ingCats[$i])   : '';
            $qtyRaw = isset($ingQtys[$i])   ? trim((string) $ingQtys[$i])   : '';
            $unit   = isset($ingUnits[$i])  ? trim((string) $ingUnits[$i])  : '';
            $lot    = isset($ingLots[$i])   ? trim((string) $ingLots[$i])   : '';

            // Skip fully empty rows
            if ($miId === '' && $qtyRaw === '') continue;

            // Validate category against ENUM
            if ($cat !== '' && !in_array($cat, ING_CATEGORIES, true)) {
                throw new RuntimeException("Catégorie invalide « {$cat} » pour la ligne MI « {$miId} ».");
            }

            // Validate unit
            if ($unit !== '' && !in_array($unit, ING_UNITS, true)) {
                throw new RuntimeException("Unité invalide « {$unit} » pour la ligne MI « {$miId} ».");
            }

            // Parse qty (accepts comma as decimal separator)
            $qty = null;
            if ($qtyRaw !== '') {
                $qtyNorm = str_replace(',', '.', $qtyRaw);
                if (!is_numeric($qtyNorm)) {
                    throw new RuntimeException("Quantité invalide « {$qtyRaw} » pour la ligne MI « {$miId} ».");
                }
                $qty = $qtyNorm;
            }

            $ingLines[] = [
                'mi_id'  => $miId,
                'cat'    => $cat  !== '' ? $cat  : null,
                'qty'    => $qty,
                'unit'   => $unit !== '' ? $unit : null,
                'lot'    => $lot  !== '' ? $lot  : null,
            ];
        }

        // ── 3. Build submitted_at ─────────────────────────────────────────────
        $submittedAt = date('Y-m-d H:i:s.u');

        $auditFlags = 'web_entry';

        // ── 4. Brewday header row ─────────────────────────────────────────────
        // Idempotency: uq_natural_key (beer, batch) already enforced at DB level.
        // row_hash is keyed on identity (beer, batch) only — excluding submitted_at
        // so re-submitting the same batch produces the same hash → hits uq_row_hash
        // → ON DUPLICATE KEY UPDATE refreshes the brewday fields without inserting a
        // duplicate. NK matches: ['beer', 'batch'] is all that's needed.
        //
        // cct_cip / cct_cip_date / yt_cip_date intentionally absent — CIP goes to
        // bd_cip_events via cip_upsert; flat columns are legacy-ingest only.
        $brewdayHash = bd_row_hash([$beer, $batch]);

        $brewdayRow = [
            'row_hash'     => $brewdayHash,
            'audit_flags'  => $auditFlags,
            'submitted_at' => $submittedAt,
            'email'        => $me['username'],
            'beer'         => $beer,
            'batch'        => $batch,
            'recipe_id_fk' => $recipeId,
            'event_date'   => $eventDate,
            'cct'          => $cct,
            'yeast'        => $yeast,
            'yeast_gen'    => $yeastGen,
            'new_yeast'    => $newYeast,
            'pitched_from' => $pitchedFrom,
            'yt_number'    => $ytNumber,
            'comments'     => $brewComments,
        ];

        $brewdayNk = ['beer', 'batch'];
        $brewdayResult = bd_upsert($pdo, 'bd_brewing_brewday_v2', $brewdayRow, $brewdayNk);
        $brewdayId = $brewdayResult['id'];

        // Write CIP events to bd_cip_events (shared infra, replaces flat columns)
        $cipMeta = ['submitted_at' => $submittedAt, 'email' => $me['username']];
        cip_upsert($pdo, 'brewing', $brewdayId, $cipEvents, $cipMeta);

        log_revision(
            $pdo,
            $me,
            'bd_brewing_brewday_v2',
            $brewdayId,
            null,   // new insert from web form; submitted_at is always fresh
            $brewdayRow,
            'normal',
            $fwComment ?: null
        );

        // ── 5. Ingredients header row ─────────────────────────────────────────
        // raw_blob_text stores a JSON snapshot of the submitted ingredient lines
        // for the audit trail; the parsed rows are the authoritative structured data.
        $ingHeaderHash = bd_row_hash([$beer, $batch, $eventDate, $submittedAt, 'web_entry']);
        $ingHeaderRow = [
            'row_hash'     => $ingHeaderHash,
            'audit_flags'  => $auditFlags,
            'submitted_at' => $submittedAt,
            'email'        => $me['username'],
            'event_date'   => $eventDate,
            'beer'         => $beer,
            'batch'        => $batch,
            'recipe_id_fk' => $recipeId,
            'raw_blob_text'=> count($ingLines) > 0
                ? json_encode($ingLines, JSON_UNESCAPED_UNICODE)
                : null,
            'parsed_at'    => count($ingLines) > 0 ? $submittedAt : null,
        ];

        $ingHeaderNk = ['submitted_at', 'beer', 'batch'];
        $ingHeaderResult = bd_upsert($pdo, 'bd_brewing_ingredients_v2', $ingHeaderRow, $ingHeaderNk);
        $ingHeaderId = $ingHeaderResult['id'];

        log_revision(
            $pdo,
            $me,
            'bd_brewing_ingredients_v2',
            $ingHeaderId,
            null,
            $ingHeaderRow,
            'normal',
            $fwComment ?: null
        );

        // ── 6. Resolve mi_id_fk for each ingredient line ─────────────────────
        // Build a map: mi_id → ref_mi.id (INT FK)
        $miIds = array_filter(array_column($ingLines, 'mi_id'));
        $miIdFkMap = [];
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

        // ── 7. Insert parsed ingredient rows ─────────────────────────────────
        // Each line goes to bd_brewing_ingredients_parsed_v2.
        // Natural key: (header_id, line_idx) — guaranteed unique per submission.
        foreach ($ingLines as $lineIdx => $line) {
            $miIdStr  = $line['mi_id'] ?? '';
            $miFk     = $miIdStr !== '' ? ($miIdFkMap[$miIdStr] ?? null) : null;
            $rawName  = $miIdStr !== '' ? $miIdStr : ($line['cat'] ?? 'unknown');

            // Category: prefer what the operator selected; fallback to null
            $cat = $line['cat'];

            $parsedRow = [
                'header_id'  => $ingHeaderId,
                'line_idx'   => $lineIdx,
                'category'   => $cat,
                'mi_id_fk'   => $miFk,
                'raw_name'   => $rawName,
                'qty'        => $line['qty'],
                'unit'       => $line['unit'],
                'lot'        => $line['lot'],
                'confidence' => 'web_entry',
                'parse_note' => $miFk !== null ? 'direct-mi-pick' : 'unresolved-mi-id',
                'source_row' => null,
            ];

            // UPSERT on (header_id, line_idx) — if form is resubmitted with the
            // same submitted_at (which feeds header_id), updates the line.
            $pdo->prepare(
                "INSERT INTO bd_brewing_ingredients_parsed_v2
                   (header_id, line_idx, category, mi_id_fk, raw_name, qty, unit, lot,
                    confidence, parse_note, source_row)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   category=VALUES(category), mi_id_fk=VALUES(mi_id_fk),
                   raw_name=VALUES(raw_name), qty=VALUES(qty), unit=VALUES(unit),
                   lot=VALUES(lot), confidence=VALUES(confidence),
                   parse_note=VALUES(parse_note),
                   updated_at=CURRENT_TIMESTAMP"
            )->execute([
                $ingHeaderId,
                $lineIdx,
                $parsedRow['category'],
                $parsedRow['mi_id_fk'],
                $parsedRow['raw_name'],
                $parsedRow['qty'],
                $parsedRow['unit'],
                $parsedRow['lot'],
                $parsedRow['confidence'],
                $parsedRow['parse_note'],
                $parsedRow['source_row'],
            ]);
        }

        // ── 8. Cooling / cast-out rows (bd_brewing_gravity_v2) — MULTI-BREW ───
        // Each sub-brew row submitted as cool_final_volume[], cool_final_gravity[],
        // cool_final_ph[]. Array position (0-based) maps to brew number (1-based).
        // Skip rows where all three fields are empty.
        // NK = (beer, batch, brew, event_type) — matches uq_natural_key on the table.
        // Re-submitting the same (beer, batch, brew) Cooling row hits uq_row_hash
        // → ON DUPLICATE KEY UPDATE refreshes values without inserting a duplicate.
        $coolVolsRaw    = $_POST['cool_final_volume']   ?? [];
        $coolOGsRaw     = $_POST['cool_final_gravity']  ?? [];
        $coolPhsRaw     = $_POST['cool_final_ph']       ?? [];

        $coolRowsWritten = 0;
        $coolTotalVol    = 0.0;
        $writtenBrews    = [];  // 1-based brew numbers actually written this submit

        // Iterate over submitted array positions; brew number = position + 1
        $coolCount = max(count($coolVolsRaw), count($coolOGsRaw), count($coolPhsRaw));
        for ($pos = 0; $pos < $coolCount; $pos++) {
            $volRaw = isset($coolVolsRaw[$pos]) ? trim((string)$coolVolsRaw[$pos]) : '';
            $ogRaw  = isset($coolOGsRaw[$pos])  ? trim((string)$coolOGsRaw[$pos])  : '';
            $phRaw  = isset($coolPhsRaw[$pos])  ? trim((string)$coolPhsRaw[$pos])  : '';

            // Skip fully-empty rows (all three blank)
            if ($volRaw === '' && $ogRaw === '' && $phRaw === '') {
                continue;
            }

            // Normalise decimal separator (accept comma from European keyboards)
            $volParsed = $volRaw !== '' ? str_replace(',', '.', $volRaw) : null;
            $ogParsed  = $ogRaw  !== '' ? str_replace(',', '.', $ogRaw)  : null;
            $phParsed  = $phRaw  !== '' ? str_replace(',', '.', $phRaw)  : null;

            $coolBrew = (string)($pos + 1);
            // Hash is deterministic over the NK identity — same as ingest convention
            $coolHash = bd_row_hash([$beer, $batch, $coolBrew, 'Cooling']);

            $coolRow = [
                'row_hash'      => $coolHash,
                'audit_flags'   => $auditFlags,
                'submitted_at'  => $submittedAt,
                'email'         => $me['username'],
                'beer'          => $beer,
                'batch'         => $batch,
                'brew'          => $coolBrew,
                'event_type'    => 'Cooling',
                'recipe_id_fk'  => $recipeId,
                'session_id_fk' => null,
                'final_volume'  => $volParsed,
                'final_gravity' => $ogParsed,
                'final_ph'      => $phParsed,
                'is_tombstoned' => 0,
            ];

            $coolNk = ['beer', 'batch', 'brew', 'event_type'];
            $coolResult = bd_upsert($pdo, 'bd_brewing_gravity_v2', $coolRow, $coolNk);

            log_revision(
                $pdo,
                $me,
                'bd_brewing_gravity_v2',
                $coolResult['id'],
                null,
                $coolRow,
                'normal',
                $fwComment ?: null
            );

            $writtenBrews[] = (int)$coolBrew;
            $coolRowsWritten++;
            if ($volParsed !== null && is_numeric($volParsed)) {
                $coolTotalVol += (float)$volParsed;
            }
        }

        // ── FIX 1: Delete orphan Cooling rows on shrinking re-submit ──────────
        // After writing the brews from this submit, remove any Cooling rows for
        // this (beer, batch) whose brew number is NOT in $writtenBrews.
        // Guard: only run when at least one brew was written (never emit NOT IN ()).
        // NOTE: the delete + upsert sequence above is non-atomic (no transaction,
        // mirroring the pre-existing form-racking.php pattern). A failed delete
        // after a successful upsert leaves stale rows recoverable by idempotent
        // re-submit with the correct brew count.
        if (!empty($writtenBrews)) {
            // Collect orphan rows BEFORE deleting so we can audit-tombstone each.
            $orphanPlaceholders = implode(',', array_fill(0, count($writtenBrews), '?'));
            $orphanSelect = $pdo->prepare(
                "SELECT id, beer, batch, brew, event_type, final_volume, final_gravity, final_ph,
                        row_hash, audit_flags, submitted_at, email, recipe_id_fk,
                        session_id_fk, is_tombstoned
                   FROM bd_brewing_gravity_v2
                  WHERE beer = ? AND batch = ?
                    AND event_type = 'Cooling'
                    AND brew NOT IN ($orphanPlaceholders)
                    AND is_tombstoned = 0"
            );
            $orphanSelect->execute(array_merge([$beer, $batch], $writtenBrews));
            $orphanRows = $orphanSelect->fetchAll();

            if (!empty($orphanRows)) {
                // Audit-tombstone each orphan row before deleting it.
                // audit_row_revisions.action ENUM has no 'delete' — convention is
                // action='update' with after_json={"_tombstone":"..."}.
                foreach ($orphanRows as $orphan) {
                    log_revision(
                        $pdo,
                        $me,
                        'bd_brewing_gravity_v2',
                        (int)$orphan['id'],
                        $orphan,    // before = full row snapshot
                        ['_tombstone' => 'deleted_by_form-brewing_shrink'],
                        'normal',
                        'Orphan Cooling row removed: batch shrank on re-submit'
                    );
                }

                // Now delete the orphan rows (parameterized, never string-interpolated).
                $delPlaceholders = implode(',', array_fill(0, count($writtenBrews), '?'));
                $pdo->prepare(
                    "DELETE FROM bd_brewing_gravity_v2
                      WHERE beer = ? AND batch = ?
                        AND event_type = 'Cooling'
                        AND brew NOT IN ($delPlaceholders)
                        AND is_tombstoned = 0"
                )->execute(array_merge([$beer, $batch], $writtenBrews));
            }
        }

        // ── 9. Success flash ──────────────────────────────────────────────────
        $nLines = count($ingLines);
        $lineLabel = $nLines > 0 ? " — {$nLines} ingrédient" . ($nLines > 1 ? 's' : '') : '';
        $coolLabel = '';
        if ($coolRowsWritten > 0) {
            $brewLabel = $coolRowsWritten > 1
                ? "{$coolRowsWritten} brassins"
                : '1 brassin';
            $coolLabel = " · cast-out {$brewLabel} / " . round($coolTotalVol, 1) . ' HL total';
        }
        flash_set('ok', "Brassage enregistré : {$beer} (B{$batch}){$lineLabel}{$coolLabel}");

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-brewing'));
    }

    redirect_to('/modules/form-brewing.php');
}

// ── GET (also reached via goto on overwrite conflict) ────────────────────────
render_form:
header('Content-Type: text/html; charset=utf-8');

// $stickyPost: populated when re-rendering after an overwrite conflict so the
// operator's entered values survive the round-trip without retyping.
$stickyPost = ($overwriteConflict !== null) ? $_POST : [];

try {
    $pdo = maltytask_pdo();

    // Recipes (active, for the beer picker)
    $recipes = $pdo->query(
        "SELECT id, name, classification, recipe_short_name
         FROM ref_recipes
         WHERE is_active = 1
         ORDER BY name ASC"
    )->fetchAll();

    // CCTs (active only — state-gated to commissioned vessels)
    $ccts = $pdo->query(
        "SELECT number, capacity_hl
         FROM ref_cct
         WHERE status = 'active'
         ORDER BY number ASC"
    )->fetchAll();

    // Yeast strains (active)
    $yeastStrains = $pdo->query(
        "SELECT id, name FROM ref_yeast_strains WHERE is_active = 1 ORDER BY name ASC"
    )->fetchAll();

    // MI catalog for the ingredient picker (brewing-relevant categories only)
    // Joined to categories to get the category slug for JS.
    $miCatalog = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, c.name AS category, m.pricing_unit
         FROM ref_mi m
         LEFT JOIN ref_mi_categories c ON m.category_id = c.id
         WHERE c.name IN ('Malt','Hops','Yeast','Brewing Adjunct','Brewing Mineral','Process Chemical')
           AND m.is_active = 1
         ORDER BY c.name, m.mi_id ASC"
    )->fetchAll();

    // Map category name → JS slug for the ingredient category chips
    // Matches ING_CATEGORIES ENUM values: malt, hops_kettle, hops_dry, adjunct, mineral, process
    $catToSlug = [
        'Malt'             => 'malt',
        'Hops'             => 'hops_kettle',   // default hops to kettle; operator can override
        'Yeast'            => 'process',        // yeast stored under process category per ENUM
        'Brewing Adjunct'  => 'adjunct',
        'Brewing Mineral'  => 'mineral',
        'Process Chemical' => 'process',
    ];

    // Build a compact JS-safe structure for BREWING_MI
    $miJs = [];
    foreach ($miCatalog as $m) {
        $miJs[] = [
            'id'   => (int)  $m['id'],
            'mi_id'=> $m['mi_id'],
            'name' => $m['name'],
            'cat'  => $catToSlug[$m['category']] ?? 'adjunct',
            'unit' => $m['pricing_unit'] ?? 'kg',
        ];
    }

    // CIP infra — types from ref_cip_types; no existing events on new submission
    $cipTypes = cip_type_options($pdo);

    // Recent brewing submissions (last 10 web-entered)
    $recentRows = $pdo->prepare(
        "SELECT id, event_date, beer, batch, cct, yeast, email, submitted_at, audit_flags
         FROM bd_brewing_brewday_v2
         WHERE audit_flags LIKE '%web_entry%'
         ORDER BY submitted_at DESC
         LIMIT 10"
    );
    $recentRows->execute();
    $recentBrews = $recentRows->fetchAll();

    $loadErr = null;

} catch (Throwable $e) {
    $recipes      = [];
    $ccts         = [];
    $yeastStrains = [];
    $miJs         = [];
    $cipTypes     = [];
    $recentBrews  = [];
    $loadErr = $e->getMessage();
}

$csrf          = csrf_token();
$active_module = 'saisies';
$displayFmt    = date_display_format();   // e.g. 'd/m/Y'

// CIP partial config (vessel-only: CCT + YT; no machines for brewing)
// Vessel numbers are null at render time — form-brewing.js syncs
// cip_vessel_0_number from the CCT select and cip_vessel_1_number from
// yt_number when they change. The flat columns (cct_cip / cct_cip_date /
// yt_cip_date) are no longer written from the web form.
$cipConfig = [
    'machines'           => [],           // no machine CIP for brewing
    'show_inline_combine'=> false,
    'vessels'            => [
        [
            'code'          => 'cct',
            'number'        => null,      // populated client-side from #cct select
            'label'         => 'CIP CCT',
            'dynamic_label' => false,
            'required'      => false,     // capturable, not mandated
        ],
        [
            'code'          => 'yt',
            'number'        => null,      // populated client-side from #yt_number input
            'label'         => 'CIP YT',
            'dynamic_label' => false,
            'required'      => false,
        ],
    ],
    'cip_types'          => $cipTypes,
    'existing'           => null,         // new submission
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Brassage — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/cip-section.css?v=<?= @filemtime(__DIR__ . '/../css/cip-section.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/form-brewing.css?v=<?= @filemtime(__DIR__ . '/../css/form-brewing.css') ?: time() ?>">
</head>
<body class="home op-form-page form-brewing">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <?php if ($overwriteConflict !== null):
      $owRecipe   = htmlspecialchars((string)($overwriteConflict['recipe_name'] ?? '—'));
      $owCct      = $overwriteConflict['cct'] !== null ? 'CCT-' . (int)$overwriteConflict['cct'] : '—';
      $owDate     = htmlspecialchars((string)($overwriteConflict['event_date'] ?? '—'));
  ?>
    <div class="fb-overwrite-warning" role="alert">
      <span class="fb-overwrite-warning__icon" aria-hidden="true">⚠</span>
      <div class="fb-overwrite-warning__body">
        <strong>Ce lot existe déjà —</strong>
        <?= $owRecipe ?> / <?= htmlspecialchars($owCct) ?> / brassé le <?= $owDate ?>.
        Re-soumettre va écraser l'en-tête du brassin (recette, CCT, levure).
      </div>
    </div>
  <?php endif ?>

  <!-- ── Page header ────────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Brassage · Brewing</div>
    <h1 class="op-form__title">Saisie <em>brassage</em></h1>
    <p class="op-form__sub">
      Enregistrement d'un brassin : recette, CCT, levure, ingrédients.
      Toutes les valeurs sont acceptées sans blocage — les saisies web sont marquées
      <code>web_entry</code> pour l'audit.
    </p>
  </div>

  <!-- ── FORM ──────────────────────────────────────────────────────────────── -->
  <form id="brewing-form" method="post" action="/modules/form-brewing.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <!-- FIX 2: confirm_overwrite flag — 0 by default; JS sets to 1 when the
         operator ticks the confirmation checkbox shown in the warning banner.
         The hidden input is always present so the handler always sees it. -->
    <input type="hidden" name="confirm_overwrite" id="confirm_overwrite_hidden" value="0">
    <?php if ($overwriteConflict !== null): ?>
    <div class="fb-overwrite-confirm">
      <label class="fb-overwrite-confirm__label">
        <input type="checkbox" id="confirm_overwrite_cb" class="fb-overwrite-confirm__cb"
               onchange="document.getElementById('confirm_overwrite_hidden').value = this.checked ? '1' : '0'">
        Confirmer l'écrasement de l'en-tête de ce brassin
      </label>
    </div>
    <?php endif ?>

    <!-- Warning panel (populated by form-framework.js) -->
    <div id="brewing-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

    <!-- ── Section: CIP (FIRST — shared partial, vessel-only) ───────────────── -->
    <?php require __DIR__ . '/../../app/partials/cip-section.php' ?>

    <!-- ── Section: Identité bière ─────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— identité du brassin</div>
      <div class="op-form__grid">

        <!-- Recipe picker (state-gated: is_active = 1) -->
        <div class="op-form__field">
          <label class="op-form__label" for="recipe_select">Recette</label>
          <select id="recipe_select" name="beer_select" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php
              $stickyBeer = htmlspecialchars($stickyPost['beer_select'] ?? '');
              foreach ($recipes as $r):
                $selected = ($r['name'] === ($stickyPost['beer_select'] ?? '')) ? ' selected' : '';
            ?>
              <option value="<?= htmlspecialchars($r['name']) ?>"
                      data-recipe-id="<?= (int)$r['id'] ?>"<?= $selected ?>>
                <?= htmlspecialchars($r['name']) ?>
                <?php if ($r['recipe_short_name']): ?>
                  (<?= htmlspecialchars($r['recipe_short_name']) ?>)
                <?php endif ?>
              </option>
            <?php endforeach ?>
          </select>
          <!-- Hidden recipe FK — populated by form-brewing.js (seeded from sticky on conflict) -->
          <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
                 value="<?= htmlspecialchars($stickyPost['recipe_id_fk'] ?? '') ?>">
        </div>

        <!-- Batch number -->
        <div class="op-form__field">
          <label class="op-form__label" for="batch">N° brassin</label>
          <input id="batch" name="batch" type="text" class="op-form__input"
                 placeholder="ex. 215" autocomplete="off" required
                 value="<?= htmlspecialchars($stickyPost['batch'] ?? '') ?>">
        </div>

        <!-- Brew date (HTML date picker, always Y-m-d on submit) -->
        <div class="op-form__field">
          <label class="op-form__label" for="event_date">
            Date brassage
            <span class="op-form__unit"><?= htmlspecialchars($displayFmt) ?></span>
          </label>
          <input id="event_date" name="event_date" type="date" class="op-form__input"
                 value="<?= htmlspecialchars($stickyPost['event_date'] ?? date('Y-m-d')) ?>" required>
        </div>

      </div>
    </div>

    <!-- ── Section: Cuve de fermentation ───────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— cuve de fermentation (CCT)</div>
      <div class="op-form__grid">

        <!-- CCT (state-gated: status = 'active') -->
        <div class="op-form__field">
          <label class="op-form__label" for="cct">CCT</label>
          <select id="cct" name="cct" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach ($ccts as $c):
              $cctSelected = (isset($stickyPost['cct']) && (string)(int)$c['number'] === (string)(int)$stickyPost['cct'])
                  ? ' selected' : '';
            ?>
              <option value="<?= (int)$c['number'] ?>"<?= $cctSelected ?>>
                CCT <?= (int)$c['number'] ?> (<?= htmlspecialchars((string)$c['capacity_hl']) ?> HL)
              </option>
            <?php endforeach ?>
          </select>
        </div>

      </div>
    </div>

    <!-- ── Section: Levure ─────────────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— levure</div>
      <div class="op-form__grid">

        <!-- Yeast picker (state-gated: is_active = 1 in ref_yeast_strains) -->
        <div class="op-form__field">
          <label class="op-form__label" for="yeast_select">Souche de levure</label>
          <select id="yeast_select" name="yeast_select" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach ($yeastStrains as $ys):
              $yeastSelected = (($stickyPost['yeast_select'] ?? '') === $ys['name']) ? ' selected' : '';
            ?>
              <option value="<?= htmlspecialchars($ys['name']) ?>"<?= $yeastSelected ?>>
                <?= htmlspecialchars($ys['name']) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>

        <!-- Yeast generation -->
        <div class="op-form__field">
          <label class="op-form__label" for="yeast_gen">Génération</label>
          <input id="yeast_gen" name="yeast_gen" type="text" class="op-form__input"
                 placeholder="ex. 3" autocomplete="off"
                 value="<?= htmlspecialchars($stickyPost['yeast_gen'] ?? '') ?>">
        </div>

        <!-- Pitched from (previous batch) -->
        <div class="op-form__field">
          <label class="op-form__label" for="pitched_from">Récolte de</label>
          <input id="pitched_from" name="pitched_from" type="text" class="op-form__input"
                 placeholder="ex. ZEP 213" autocomplete="off"
                 value="<?= htmlspecialchars($stickyPost['pitched_from'] ?? '') ?>">
        </div>

        <!-- New yeast (free-text, used when new strain not in catalog) -->
        <div class="op-form__field">
          <label class="op-form__label" for="new_yeast">
            Nouvelle souche <span class="op-form__unit">(si absente de la liste)</span>
          </label>
          <input id="new_yeast" name="new_yeast" type="text" class="op-form__input"
                 placeholder="Nom de la nouvelle souche" autocomplete="off"
                 value="<?= htmlspecialchars($stickyPost['new_yeast'] ?? '') ?>">
        </div>

        <!-- YT number (optional — when pitched via Yeast Temp tank) -->
        <div class="op-form__field">
          <label class="op-form__label" for="yt_number">YT n°</label>
          <input id="yt_number" name="yt_number" type="number" class="op-form__input"
                 placeholder="—" min="1"
                 value="<?= htmlspecialchars($stickyPost['yt_number'] ?? '') ?>">
        </div>

      </div>
    </div>

    <!-- ── Section: Ingrédients ─────────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">
        — ingrédients
        <span class="brew-ing__count" id="ing-count-badge"></span>
      </div>

      <table class="brew-ing__table">
        <thead>
          <tr>
            <th class="brew-ing__col--cat"></th>
            <th class="brew-ing__col--mi">Ingrédient (MI)</th>
            <th class="brew-ing__col--qty">Quantité</th>
            <th class="brew-ing__col--unit">Unité</th>
            <th class="brew-ing__col--lot">N° lot</th>
            <th class="brew-ing__col--del"></th>
          </tr>
        </thead>
        <tbody id="ing-tbody">
          <!-- Rows added by form-brewing.js -->
        </tbody>
      </table>

      <button type="button" class="brew-ing__add-btn" onclick="window._brewingAddRow()">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <line x1="7" y1="1" x2="7" y2="13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          <line x1="1" y1="7" x2="13" y2="7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
        Ajouter un ingrédient
      </button>

      <p class="brew-note">
        Seules les catégories suivantes sont disponibles : Malt, Houblon (kettle / dry),
        Adjuvant, Minéral, Process. La levure est saisie dans la section ci-dessus.
      </p>
    </div>

    <!-- ── Section: Refroidissement / Cast-out (multi-brassin) ────────────────── -->
    <!-- Each sub-brew row writes one bd_brewing_gravity_v2 Cooling row.           -->
    <!-- NK: (beer, batch, brew, event_type='Cooling') — idempotent on re-submit.  -->
    <!-- brew = row position 1..N. Batch total = SUM(final_volume) over all rows.  -->
    <div class="op-form__card">
      <div class="op-form__card-title">
        — refroidissement / cast-out
        <span class="brew-cool__count" id="cool-count-badge"></span>
      </div>

      <table class="brew-cool__table">
        <thead>
          <tr>
            <th class="brew-cool__col--num">Brassin</th>
            <th class="brew-cool__col--vol">Volume cast-out <span class="op-form__unit">HL</span></th>
            <th class="brew-cool__col--og">OG <span class="op-form__unit">°Plato</span></th>
            <th class="brew-cool__col--ph">pH <span class="op-form__unit">(opt.)</span></th>
            <th class="brew-cool__col--del"></th>
          </tr>
        </thead>
        <tbody id="cool-tbody">
          <!-- Rows added by form-brewing.js — one shown by default -->
        </tbody>
      </table>

      <div class="brew-cool__footer">
        <button type="button" class="brew-ing__add-btn brew-cool__add-btn"
                onclick="window._brewingAddCoolRow()">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <line x1="7" y1="1" x2="7" y2="13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            <line x1="1" y1="7" x2="13" y2="7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          </svg>
          Ajouter un brassin
        </button>
        <div class="brew-cool__total" id="cool-total-display" hidden>
          Total : <strong id="cool-total-vol">0.0</strong> HL
        </div>
      </div>

      <p class="brew-note">
        Un brassin = un cycle de brassage (cast-out vers le CCT). Pour un multi-brassin,
        ajouter une ligne par cycle. Le volume cast-out est l'opérande WIP/COGS —
        <code>SUM(final_volume)</code> sur les lignes <code>Cooling</code> du batch.
        La densité est en °Plato (OG au refroidissement, typiquement 9.8–19.2°P).
        Les lignes entièrement vides sont ignorées.
      </p>
    </div>

    <!-- ── Section: Commentaires ────────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— commentaires</div>
      <div class="op-form__grid--1 op-form__grid">
        <div class="op-form__field op-form__field--full">
          <label class="op-form__label" for="comments">Commentaires brassage</label>
          <textarea id="comments" name="comments" class="op-form__textarea" rows="3"
                    placeholder="Observations, écarts de rendement, problèmes…"><?= htmlspecialchars($stickyPost['comments'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Submit bar -->
    <div class="op-form__submit-bar">
      <button type="button" class="op-form__btn op-form__btn--secondary"
              onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('brewing-draft');location.reload();}">
        Effacer brouillon
      </button>
      <button type="submit" class="op-form__btn op-form__btn--primary">
        Enregistrer le brassin →
      </button>
    </div>

  </form>

  <!-- ── Recent submissions ───────────────────────────────────────────────── -->
  <div class="op-form__recent">
    <div class="op-form__recent-title">— saisies récentes (web)</div>
    <?php if (empty($recentBrews)): ?>
      <p class="op-form__muted brew-empty-note">Aucune saisie web pour le moment.</p>
    <?php else: ?>
      <table class="op-form__recent-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bière</th>
            <th>Brassin</th>
            <th>CCT</th>
            <th>Levure</th>
            <th>Opérateur</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentBrews as $rb): ?>
            <tr>
              <td class="op-form__mono"><?= htmlspecialchars($rb['event_date'] ?? '') ?></td>
              <td><?= htmlspecialchars($rb['beer'] ?? '') ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($rb['batch'] ?? '') ?></td>
              <td class="op-form__mono"><?= $rb['cct'] !== null ? 'CCT ' . (int)$rb['cct'] : '—' ?></td>
              <td><?= htmlspecialchars($rb['yeast'] ?? '—') ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($rb['email'] ?? '') ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>

</main>

<!-- MI catalog injected server-side for the ingredient picker JS -->
<script>
window.BREWING_MI = <?= json_encode($miJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php if (!empty($stickyPost)): ?>
// Sticky cooling rows — pre-seed JS when re-rendering after an overwrite conflict.
window.BREWING_STICKY_COOL = <?= json_encode([
    'volumes'   => array_values($stickyPost['cool_final_volume']  ?? []),
    'gravities' => array_values($stickyPost['cool_final_gravity'] ?? []),
    'phs'       => array_values($stickyPost['cool_final_ph']      ?? []),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php endif ?>
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/form-brewing.js?v=<?= @filemtime(__DIR__ . '/../js/form-brewing.js') ?: time() ?>" defer></script>

</body>
</html>
