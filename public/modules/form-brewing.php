<?php
declare(strict_types=1);
/**
 * form-brewing.php — Operator brewing entry form.
 *
 * Writes to:
 *   bd_brewing_brewday_v2            — one row per (beer, batch) — idempotent on
 *                                      re-submit. NK: (beer, batch). row_hash keyed
 *                                      on (beer, batch) only.
 *   bd_brewing_ingredients_v2        — one header row per submission (raw_blob_text =
 *                                      JSON encoding of ingredient lines for audit trail)
 *   bd_brewing_ingredients_parsed_v2 — one row per ingredient line
 *                                      (header_id FK, line_idx, category,
 *                                       mi_id_fk, raw_name, qty, unit, lot)
 *   bd_brewing_gravity_v2            — up to FOUR rows per sub-brew per submit:
 *                                      event_type IN ('FirstWort','Pfannevoll','Kochwurze','Cooling').
 *                                      NK: (beer, batch, brew, event_type) — 4-tuple.
 *                                      Each is gated on the presence of its own fields.
 *   bd_brewing_timings_v2            — ONE row per sub-brew (brew_start, brew_end, event_date).
 *                                      NK: (beer, batch, brew) — 3-tuple (no event_type).
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

require_page_access('saisies');
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
$ownTx = false;

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
        $recipeId    = post_int('recipe_id_fk'); // overridden below by server-side resolution
        $eventDateRaw = post_str('event_date');

        if ($beer === '') {
            throw new RuntimeException("La bière (recette) est obligatoire.");
        }
        if ($batch === '') {
            throw new RuntimeException("Le numéro de brassin est obligatoire.");
        }

        // ── Recipe resolution (server-side, authoritative) ─────────────────────
        // The JS hidden field (recipe_id_fk) is only populated on dropdown change;
        // if the operator doesn't re-pick the recipe the field stays empty → NULL.
        // Resolve authorita­tively here by matching $beer to the unique active recipe
        // in ref_recipes. An exact-count-of-1 check ensures we never guess for ambiguous names.
        $recipeStmt = $pdo->prepare(
            "SELECT id FROM ref_recipes
              WHERE name = ? AND is_active = 1"
        );
        $recipeStmt->execute([$beer]);
        $recipeRows = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);
        // Exactly one active recipe must match — ambiguous or missing names are refused,
        // never guessed (the resolved id becomes recipe_id_fk, a load-bearing FK).
        if (count($recipeRows) !== 1) {
            throw new RuntimeException(
                "Recette introuvable ou ambiguë pour « {$beer} » — impossible d'enregistrer le brassin sans recette valide."
            );
        }
        $recipeId = (int) $recipeRows[0]['id'];

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
                    b.email, b.submitted_by_user_id_fk,
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

        // Soft-warning accumulator (e.g. time logic notices). Appended to flash.
        $softNotes = [];

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

        // In edit mode (confirmed overwrite of existing batch), append ,correction to audit flags.
        $auditFlags  = ($existingRow !== false && $confirmOverwrite) ? 'web_entry,correction' : 'web_entry';
        $isEditMode  = ($existingRow !== false && $confirmOverwrite);
        // On edit: preserve the original operator identity (email + FK).
        // On insert: stamp the current user.
        $originalEmail = $isEditMode ? ($existingRow['email'] ?? $me['username']) : $me['username'];

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
            'email'        => $originalEmail,
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
        // Set submitted_by_user_id_fk only on insert; edit leaves existing FK untouched.
        if (!$isEditMode) {
            $brewdayRow['submitted_by_user_id_fk'] = (int)$me['id'];
        }

        $brewdayNk = ['beer', 'batch'];

        // Before-snapshot for the main brewday row:
        // $existingRow was fetched above for the confirm-overwrite guard.
        // When it's non-false and the operator confirmed or is in edit-mode, this
        // is an UPDATE — capture the pre-image so log_revision emits action='update'.
        $brewdayBefore = null;
        if ($existingRow !== false) {
            $brewdayBefore = bd_fetch_before($pdo, 'bd_brewing_brewday_v2', (int)$existingRow['id']);
        }

        $brewdayResult = bd_upsert($pdo, 'bd_brewing_brewday_v2', $brewdayRow, $brewdayNk);
        $brewdayId = $brewdayResult['id'];

        // Write CIP events to bd_cip_events (shared infra, replaces flat columns)
        $cipMeta = ['submitted_at' => $submittedAt, 'email' => $me['username']];
        cip_upsert($pdo, 'brewing', $brewdayId, $cipEvents, $cipMeta);

        // $brewdayBefore is non-null when an existing row is being overwritten (edit/confirm
        // path) → action='update' with real before_json. null → action='insert' (new batch).
        log_revision(
            $pdo,
            $me,
            'bd_brewing_brewday_v2',
            $brewdayId,
            $brewdayBefore,
            $brewdayRow,
            'normal',
            $fwComment ?: null
        );

        // ── 5. Ingredients header row ─────────────────────────────────────────
        // raw_blob_text stores a JSON snapshot of the submitted ingredient lines
        // for the audit trail; the parsed rows are the authoritative structured data.
        // Open transaction wrapping §5-§7 so DELETE+reinsert is atomic.
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        $ingHeaderHash = bd_row_hash([$beer, $batch, $eventDate, $submittedAt, 'web_entry']);
        $ingHeaderRow = [
            'row_hash'                 => $ingHeaderHash,
            'audit_flags'             => $auditFlags,
            'submitted_at'            => $submittedAt,
            'email'                   => $me['username'],
            'submitted_by_user_id_fk' => (int)$me['id'],
            'event_date'              => $eventDate,
            'beer'                    => $beer,
            'batch'                   => $batch,
            'recipe_id_fk'            => $recipeId,
            'raw_blob_text'           => count($ingLines) > 0
                ? json_encode($ingLines, JSON_UNESCAPED_UNICODE)
                : null,
            'parsed_at'               => count($ingLines) > 0 ? $submittedAt : null,
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

        // ── 7a. Pre-image snapshot for audit ─────────────────────────────────────
        // Capture existing lines under ALL non-tombstoned headers for this (beer, batch)
        // before we clear them. Used as before_json in the log_revision below.
        $existingHeaderIds = [];
        $ehStmt = $pdo->prepare(
            "SELECT id FROM bd_brewing_ingredients_v2
              WHERE beer = ? AND batch = ? AND is_tombstoned = 0"
        );
        $ehStmt->execute([$beer, $batch]);
        foreach ($ehStmt->fetchAll(PDO::FETCH_COLUMN) as $ehId) {
            $existingHeaderIds[] = (int)$ehId;
        }
        $beforeLinesJson = null;
        if (!empty($existingHeaderIds)) {
            $ehPlaceholders = implode(',', array_fill(0, count($existingHeaderIds), '?'));
            $blStmt = $pdo->prepare(
                "SELECT * FROM bd_brewing_ingredients_parsed_v2
                  WHERE header_id IN ($ehPlaceholders)
                  ORDER BY header_id ASC, line_idx ASC"
            );
            $blStmt->execute($existingHeaderIds);
            $beforeLines = $blStmt->fetchAll(PDO::FETCH_ASSOC);
            $beforeLinesJson = !empty($beforeLines) ? $beforeLines : null;
        }

        // ── 7b. Delete ALL existing parsed lines for this (beer, batch) ───────
        // Hard DELETE (no tombstone column on parsed lines). The new lines are
        // reinserted immediately below. Atomic with the header upsert via the
        // transaction opened above.
        if (!empty($existingHeaderIds)) {
            $ehPlaceholders2 = implode(',', array_fill(0, count($existingHeaderIds), '?'));
            $pdo->prepare(
                "DELETE FROM bd_brewing_ingredients_parsed_v2
                  WHERE header_id IN ($ehPlaceholders2)"
            )->execute($existingHeaderIds);
        }

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

            // Plain INSERT — old lines were DELETEd above (§7b), so no collision possible.
            $pdo->prepare(
                "INSERT INTO bd_brewing_ingredients_parsed_v2
                   (header_id, line_idx, category, mi_id_fk, raw_name, qty, unit, lot,
                    confidence, parse_note, source_row)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
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

        // ── 7c. Audit: one revision record for the line-set replacement ───────
        // action='update' (non-null before_json); action='insert' if no prior lines.
        // Uses the ingHeaderId (the NEW header, canonical anchor for this submission).
        $afterLines = [];
        if (!empty($ingLines)) {
            $alStmt = $pdo->prepare(
                "SELECT * FROM bd_brewing_ingredients_parsed_v2
                  WHERE header_id = ?
                  ORDER BY line_idx ASC"
            );
            $alStmt->execute([$ingHeaderId]);
            $afterLines = $alStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        log_revision(
            $pdo,
            $me,
            'bd_brewing_ingredients_parsed_v2',
            $ingHeaderId,
            $beforeLinesJson,
            ['lines' => $afterLines, 'count' => count($afterLines)],
            'normal',
            'Line-set replaced via form-brewing DELETE+reinsert'
        );

        // ── 8. Per-sub-brew: gravity + timings rows ──────────────────────────
        //
        // View-model: one UI row = up to 5 DB rows:
        //   bd_brewing_gravity_v2 × 4 event_types (each gated on its own fields)
        //   bd_brewing_timings_v2 × 1               (always written for present rows)
        //
        // NK for gravity:  (beer, batch, brew, event_type)  — 4-tuple
        // NK for timings:  (beer, batch, brew)               — 3-tuple (no event_type)
        // row_hash for gravity MUST include the event_type discriminator.
        // row_hash for timings MUST NOT include event_type.

        // ── Read all per-brassin arrays ───────────────────────────────────────
        $fwGravsRaw      = $_POST['brew_fw_gravity']     ?? [];
        $fwPhsRaw        = $_POST['brew_fw_ph']          ?? [];
        $pvGravsRaw      = $_POST['brew_pv_gravity']     ?? [];
        $kwGravsRaw      = $_POST['brew_kw_gravity']     ?? [];
        $startDatesRaw   = $_POST['brew_start_date']     ?? [];
        $startTimesRaw   = $_POST['brew_start_time']     ?? [];
        $endDatesRaw     = $_POST['brew_end_date']       ?? [];
        $endTimesRaw     = $_POST['brew_end_time']       ?? [];
        $coolVolsRaw     = $_POST['cool_final_volume']   ?? [];
        $coolDilutsRaw   = $_POST['cool_batch_dilution'] ?? [];
        $coolOGsRaw      = $_POST['cool_final_gravity']  ?? [];
        $coolPhsRaw      = $_POST['cool_final_ph']       ?? [];

        $coolRowsWritten = 0;
        $coolTotalVol    = 0.0;
        $writtenBrews    = [];  // 1-based brew numbers actually written this submit

        // Helper: normalise decimal separator (same pattern as existing Cooling block)
        $normDec = static function (string $raw): ?string {
            return $raw !== '' ? str_replace(',', '.', $raw) : null;
        };

        // Helper: validate a Y-m-d date string; returns the string if valid, else null.
        $validDate = static function (string $raw): ?string {
            if ($raw === '') return null;
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
            return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
        };

        $allArrays = [
            $fwGravsRaw, $fwPhsRaw, $pvGravsRaw, $kwGravsRaw,
            $startDatesRaw, $startTimesRaw, $endDatesRaw, $endTimesRaw,
            $coolVolsRaw, $coolDilutsRaw, $coolOGsRaw, $coolPhsRaw,
        ];
        $brewCount = max(array_map('count', $allArrays));

        for ($pos = 0; $pos < $brewCount; $pos++) {
            $fwGravRaw    = isset($fwGravsRaw[$pos])      ? trim((string)$fwGravsRaw[$pos])      : '';
            $fwPhRaw      = isset($fwPhsRaw[$pos])        ? trim((string)$fwPhsRaw[$pos])        : '';
            $pvGravRaw    = isset($pvGravsRaw[$pos])      ? trim((string)$pvGravsRaw[$pos])      : '';
            $kwGravRaw    = isset($kwGravsRaw[$pos])      ? trim((string)$kwGravsRaw[$pos])      : '';
            $startDateRaw = isset($startDatesRaw[$pos])   ? trim((string)$startDatesRaw[$pos])   : '';
            $startTime    = isset($startTimesRaw[$pos])   ? trim((string)$startTimesRaw[$pos])   : '';
            $endDateRaw   = isset($endDatesRaw[$pos])     ? trim((string)$endDatesRaw[$pos])     : '';
            $endTime      = isset($endTimesRaw[$pos])     ? trim((string)$endTimesRaw[$pos])     : '';
            $volRaw       = isset($coolVolsRaw[$pos])     ? trim((string)$coolVolsRaw[$pos])     : '';
            $dilutRaw     = isset($coolDilutsRaw[$pos])   ? trim((string)$coolDilutsRaw[$pos])   : '';
            $ogRaw        = isset($coolOGsRaw[$pos])      ? trim((string)$coolOGsRaw[$pos])      : '';
            $phRaw        = isset($coolPhsRaw[$pos])      ? trim((string)$coolPhsRaw[$pos])      : '';

            // Whole-row presence gate: skip if all substantive fields blank.
            // Date inputs are excluded — they auto-default to the header date
            // so a row with only dates and nothing else must still count as empty.
            if ($fwGravRaw === '' && $fwPhRaw === '' && $pvGravRaw === '' && $kwGravRaw === ''
                && $startTime === '' && $endTime === ''
                && $volRaw === '' && $dilutRaw === '' && $ogRaw === '' && $phRaw === '') {
                continue;
            }

            $brew = (string)($pos + 1);

            // ── Resolve per-brew start/end dates ─────────────────────────────
            // HTML date inputs return Y-m-d; fall back to header $eventDate when
            // empty or not a valid Y-m-d (e.g. user cleared the field).
            $startDate = $validDate($startDateRaw) ?? $eventDate;
            $endDate   = $validDate($endDateRaw)   ?? $eventDate;

            // ── 8a. bd_brewing_timings_v2 ────────────────────────────────────
            // Always written for every present brassin (event_date guarantees CHECK).
            // row_hash: NK is (beer, batch, brew) — no event_type.
            // timings.event_date = startDate (the START day — occupancy/age anchor).
            $brewStartVal = null;
            $brewEndVal   = null;
            if ($startTime !== '') {
                $brewStartVal = "{$startDate} {$startTime}:00";
            }
            if ($endTime !== '') {
                $brewEndVal = "{$endDate} {$endTime}:00";
            }

            // Soft note when brew_end < brew_start (full datetime comparison — handles
            // cross-midnight brews correctly once dates differ).
            if ($brewStartVal !== null && $brewEndVal !== null
                && strcmp($brewEndVal, $brewStartVal) < 0) {
                $softNotes[] = "Brassin {$brew} : fin antérieure au début — à vérifier.";
            }

            $timingsHash = bd_row_hash([$beer, $batch, $brew]);
            $timingsRow  = [
                'row_hash'      => $timingsHash,
                'audit_flags'   => $auditFlags,
                'submitted_at'  => $submittedAt,
                'email'         => $originalEmail,
                'beer'          => $beer,
                'batch'         => $batch,
                'brew'          => $brew,
                'recipe_id_fk'  => $recipeId,  // canonical FK — was omitted (siblings all set it); fixed 2026-06-11
                'event_date'    => $startDate,  // occupancy/age anchor = brew start day
                'brew_start'    => $brewStartVal,
                'brew_end'      => $brewEndVal,
                'is_tombstoned' => 0,
            ];
            if (!$isEditMode) {
                $timingsRow['submitted_by_user_id_fk'] = (int)$me['id'];
            }
            $timingsNk     = ['beer', 'batch', 'brew'];
            $timingsResult = bd_upsert($pdo, 'bd_brewing_timings_v2', $timingsRow, $timingsNk);
            log_revision($pdo, $me, 'bd_brewing_timings_v2', $timingsResult['id'],
                null, $timingsRow, 'normal', $fwComment ?: null);

            $gravNk = ['beer', 'batch', 'brew', 'event_type'];

            // ── 8b. FirstWort gravity row ─────────────────────────────────────
            if ($fwGravRaw !== '' || $fwPhRaw !== '') {
                $fwHash = bd_row_hash([$beer, $batch, $brew, 'FirstWort']);
                $fwRow  = [
                    'row_hash'          => $fwHash,
                    'audit_flags'       => $auditFlags,
                    'submitted_at'      => $submittedAt,
                    'email'             => $originalEmail,
                    'beer'              => $beer,
                    'batch'             => $batch,
                    'brew'              => $brew,
                    'event_type'        => 'FirstWort',
                    'recipe_id_fk'      => $recipeId,
                    'session_id_fk'     => null,
                    'firstwort_gravity' => $normDec($fwGravRaw),
                    'firstwort_ph'      => $normDec($fwPhRaw),
                    'is_tombstoned'     => 0,
                ];
                if (!$isEditMode) {
                    $fwRow['submitted_by_user_id_fk'] = (int)$me['id'];
                }
                $fwResult = bd_upsert($pdo, 'bd_brewing_gravity_v2', $fwRow, $gravNk);
                log_revision($pdo, $me, 'bd_brewing_gravity_v2', $fwResult['id'],
                    null, $fwRow, 'normal', $fwComment ?: null);
            }

            // ── 8c. Pfannevoll gravity row ────────────────────────────────────
            if ($pvGravRaw !== '') {
                $pvHash = bd_row_hash([$beer, $batch, $brew, 'Pfannevoll']);
                $pvRow  = [
                    'row_hash'            => $pvHash,
                    'audit_flags'         => $auditFlags,
                    'submitted_at'        => $submittedAt,
                    'email'               => $originalEmail,
                    'beer'                => $beer,
                    'batch'               => $batch,
                    'brew'                => $brew,
                    'event_type'          => 'Pfannevoll',
                    'recipe_id_fk'        => $recipeId,
                    'session_id_fk'       => null,
                    'pfannevoll_gravity'  => $normDec($pvGravRaw),
                    'is_tombstoned'       => 0,
                ];
                if (!$isEditMode) {
                    $pvRow['submitted_by_user_id_fk'] = (int)$me['id'];
                }
                $pvResult = bd_upsert($pdo, 'bd_brewing_gravity_v2', $pvRow, $gravNk);
                log_revision($pdo, $me, 'bd_brewing_gravity_v2', $pvResult['id'],
                    null, $pvRow, 'normal', $fwComment ?: null);
            }

            // ── 8d. Kochwurze gravity row ─────────────────────────────────────
            if ($kwGravRaw !== '') {
                $kwHash = bd_row_hash([$beer, $batch, $brew, 'Kochwurze']);
                $kwRow  = [
                    'row_hash'           => $kwHash,
                    'audit_flags'        => $auditFlags,
                    'submitted_at'       => $submittedAt,
                    'email'              => $originalEmail,
                    'beer'               => $beer,
                    'batch'              => $batch,
                    'brew'               => $brew,
                    'event_type'         => 'Kochwurze',
                    'recipe_id_fk'       => $recipeId,
                    'session_id_fk'      => null,
                    'kochwurze_gravity'  => $normDec($kwGravRaw),
                    'is_tombstoned'      => 0,
                ];
                if (!$isEditMode) {
                    $kwRow['submitted_by_user_id_fk'] = (int)$me['id'];
                }
                $kwResult = bd_upsert($pdo, 'bd_brewing_gravity_v2', $kwRow, $gravNk);
                log_revision($pdo, $me, 'bd_brewing_gravity_v2', $kwResult['id'],
                    null, $kwRow, 'normal', $fwComment ?: null);
            }

            // ── 8e. Cooling / cast-out gravity row ────────────────────────────
            if ($volRaw !== '' || $dilutRaw !== '' || $ogRaw !== '' || $phRaw !== '') {
                $volParsed   = $normDec($volRaw);
                $dilutParsed = $normDec($dilutRaw);
                $ogParsed    = $normDec($ogRaw);
                $phParsed    = $normDec($phRaw);

                // Cast-out date: use endDate when an end time is present (cooling is the
                // last step, so a cross-midnight brew casts out on the END day); otherwise
                // fall back to startDate (which already defaults to the header event_date).
                $castoutDate     = ($endTime !== '') ? $endDate : $startDate;
                // Preserve the time/microsecond portion of $submittedAt for uniqueness.
                // substr($submittedAt, 10) yields " H:i:s.uuuuuu" from "Y-m-d H:i:s.u".
                $coolSubmittedAt = $castoutDate . substr($submittedAt, 10);

                // Cast-out dated by brew_end (cooling=last step); tank-sim/wort read DATE(submitted_at).
                // timings.event_date stays start-day (occupancy anchor).
                $coolHash = bd_row_hash([$beer, $batch, $brew, 'Cooling']);
                $coolRow  = [
                    'row_hash'        => $coolHash,
                    'audit_flags'     => $auditFlags,
                    'submitted_at'    => $coolSubmittedAt,
                    'email'           => $originalEmail,
                    'beer'            => $beer,
                    'batch'           => $batch,
                    'brew'            => $brew,
                    'event_type'      => 'Cooling',
                    'recipe_id_fk'    => $recipeId,
                    'session_id_fk'   => null,
                    'final_volume'    => $volParsed,
                    'batch_dilution'  => $dilutParsed,
                    'final_gravity'   => $ogParsed,
                    'final_ph'        => $phParsed,
                    'is_tombstoned'   => 0,
                ];
                if (!$isEditMode) {
                    $coolRow['submitted_by_user_id_fk'] = (int)$me['id'];
                }
                $coolResult = bd_upsert($pdo, 'bd_brewing_gravity_v2', $coolRow, $gravNk);
                log_revision($pdo, $me, 'bd_brewing_gravity_v2', $coolResult['id'],
                    null, $coolRow, 'normal', $fwComment ?: null);

                $coolRowsWritten++;
                if ($volParsed !== null && is_numeric($volParsed)) {
                    $coolTotalVol += (float)$volParsed;
                }
            }

            $writtenBrews[] = (int)$brew;
        }

        // ── FIX 1: Orphan-delete-on-shrink — all 5 surfaces ──────────────────
        // After writing all sub-brew rows, delete orphans whose brew number is
        // NOT IN $writtenBrews on both gravity_v2 (all 4 event types) and timings_v2.
        // Guard: only run when $writtenBrews is non-empty (never emit NOT IN ()).
        // Non-atomic (no transaction) — mirrors the pre-existing form-racking.php
        // pattern; idempotent re-submit is the recovery guarantee.
        if (!empty($writtenBrews)) {
            $orphanPlaceholders = implode(',', array_fill(0, count($writtenBrews), '?'));

            // Config list: [table, event_type|null]
            // gravity_v2 entries include an event_type filter; timings_v2 does not.
            $orphanSurfaces = [
                ['bd_brewing_gravity_v2', 'FirstWort'],
                ['bd_brewing_gravity_v2', 'Pfannevoll'],
                ['bd_brewing_gravity_v2', 'Kochwurze'],
                ['bd_brewing_gravity_v2', 'Cooling'],
                ['bd_brewing_timings_v2', null],
            ];

            foreach ($orphanSurfaces as [$tbl, $evType]) {
                $evFilter = $evType !== null ? "AND event_type = ?" : '';

                // Collect orphan rows for audit-tombstone BEFORE deleting.
                $selParams = array_merge([$beer, $batch], $evType !== null ? [$evType] : [], $writtenBrews);
                $orphanSelect = $pdo->prepare(
                    "SELECT * FROM `{$tbl}`
                      WHERE beer = ? AND batch = ?
                        {$evFilter}
                        AND brew NOT IN ($orphanPlaceholders)
                        AND is_tombstoned = 0"
                );
                $orphanSelect->execute($selParams);
                $orphanRows = $orphanSelect->fetchAll();

                foreach ($orphanRows as $orphan) {
                    log_revision(
                        $pdo, $me, $tbl, (int)$orphan['id'],
                        $orphan,
                        ['_tombstone' => 'deleted_by_form-brewing_shrink'],
                        'normal',
                        "Orphan {$tbl}" . ($evType ? "/{$evType}" : '') . ' row removed: batch shrank on re-submit'
                    );
                }

                if (!empty($orphanRows)) {
                    $delParams = array_merge([$beer, $batch], $evType !== null ? [$evType] : [], $writtenBrews);
                    $pdo->prepare(
                        "DELETE FROM `{$tbl}`
                          WHERE beer = ? AND batch = ?
                            {$evFilter}
                            AND brew NOT IN ($orphanPlaceholders)
                            AND is_tombstoned = 0"
                    )->execute($delParams);
                }
            }
        }

        if ($ownTx) $pdo->commit();

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
        $notesSuffix = !empty($softNotes) ? ' ⚠ ' . implode(' / ', $softNotes) : '';
        flash_set('ok', "Brassage enregistré : {$beer} (B{$batch}){$lineLabel}{$coolLabel}{$notesSuffix}");

    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        flash_set('err', pdo_friendly_error($e, 'form-brewing'));
    }

    redirect_to('/modules/form-brewing.php');
}

// ── GET (also reached via goto on overwrite conflict) ────────────────────────
render_form:
header('Content-Type: text/html; charset=utf-8');

// ── Edit-mode detection ───────────────────────────────────────────────────────
// Read with ?? default, then validate (never trust the raw param directly).
$editIdRaw = $_GET['edit'] ?? null;
$editId    = ($editIdRaw !== null && is_numeric($editIdRaw)) ? (int)$editIdRaw : null;
if ($editId !== null && $editId <= 0) {
    $editId = null;
}

$editMode       = false;
$prefillHeader  = [];   // assoc with same keys as $stickyPost header fields
$editBanner     = null; // ['recipe_name', 'batch', 'event_date'] for banner display
$contiguityWarn = false;

if ($editId !== null) {
    try {
        $pdoEdit = maltytask_pdo();

        // Load brewday header
        $editBrewday = $pdoEdit->prepare(
            "SELECT b.id, b.beer, b.batch, b.event_date, b.cct, b.yeast, b.yeast_gen,
                    b.new_yeast, b.pitched_from, b.yt_number, b.comments, b.recipe_id_fk,
                    r.name AS recipe_name
               FROM bd_brewing_brewday_v2 b
               LEFT JOIN ref_recipes r ON r.id = b.recipe_id_fk
              WHERE b.id = ? AND b.is_tombstoned = 0
              LIMIT 1"
        );
        $editBrewday->execute([$editId]);
        $editRow = $editBrewday->fetch(PDO::FETCH_ASSOC);

        if ($editRow === false) {
            flash_set('err', "Brassin ID {$editId} introuvable ou archivé.");
            // Fall through to blank-entry mode
        } else {
            $editMode = true;
            $editBeer  = (string)$editRow['beer'];
            $editBatch = (string)$editRow['batch'];

            // ── Load ingredients ──────────────────────────────────────────────
            // NK (beer, batch) on bd_brewing_ingredients_v2 — one header per batch.
            $ingHeaderStmt = $pdoEdit->prepare(
                "SELECT id FROM bd_brewing_ingredients_v2
                  WHERE beer = ? AND batch = ? AND is_tombstoned = 0
                  ORDER BY id DESC LIMIT 1"
            );
            $ingHeaderStmt->execute([$editBeer, $editBatch]);
            $ingHeaderRow = $ingHeaderStmt->fetch(PDO::FETCH_ASSOC);

            $prefillIngLines = [];
            if ($ingHeaderRow !== false) {
                $ingParsedStmt = $pdoEdit->prepare(
                    "SELECT category, raw_name, qty, unit, lot
                       FROM bd_brewing_ingredients_parsed_v2
                      WHERE header_id = ?
                      ORDER BY line_idx ASC"
                );
                $ingParsedStmt->execute([(int)$ingHeaderRow['id']]);
                $prefillIngLines = $ingParsedStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // ── Load gravity (pivot by brew) ──────────────────────────────────
            $gravStmt = $pdoEdit->prepare(
                "SELECT brew, event_type,
                        firstwort_gravity, firstwort_ph,
                        pfannevoll_gravity,
                        kochwurze_gravity,
                        final_volume, batch_dilution, final_gravity, final_ph
                   FROM bd_brewing_gravity_v2
                  WHERE beer = ? AND batch = ? AND is_tombstoned = 0"
            );
            $gravStmt->execute([$editBeer, $editBatch]);
            $gravRows = $gravStmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Load timings ──────────────────────────────────────────────────
            $timStmt = $pdoEdit->prepare(
                "SELECT brew, brew_start, brew_end, event_date
                   FROM bd_brewing_timings_v2
                  WHERE beer = ? AND batch = ? AND is_tombstoned = 0"
            );
            $timStmt->execute([$editBeer, $editBatch]);
            $timRows = $timStmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Build per-brew pivot ──────────────────────────────────────────
            // Collect distinct brew numbers from both gravity and timings sets.
            $brewNums = [];
            foreach ($gravRows as $gr) { $brewNums[(int)$gr['brew']] = true; }
            foreach ($timRows  as $tr) { $brewNums[(int)$tr['brew']] = true; }
            ksort($brewNums);
            $sortedBrewNums = array_keys($brewNums);

            // Contiguity guard: brews must be exactly 1..N
            $expectedBrews = range(1, count($sortedBrewNums));
            if ($sortedBrewNums !== $expectedBrews) {
                $contiguityWarn = true;
            }

            // Scatter gravity and timings into per-brew indexed arrays
            $brewGrav = [];  // keyed by brew number
            foreach ($gravRows as $gr) {
                $b = (int)$gr['brew'];
                if (!isset($brewGrav[$b])) {
                    $brewGrav[$b] = [];
                }
                switch ($gr['event_type']) {
                    case 'FirstWort':
                        $brewGrav[$b]['fw_gravity'] = $gr['firstwort_gravity'];
                        $brewGrav[$b]['fw_ph']      = $gr['firstwort_ph'];
                        break;
                    case 'Pfannevoll':
                        $brewGrav[$b]['pv_gravity'] = $gr['pfannevoll_gravity'];
                        break;
                    case 'Kochwurze':
                        $brewGrav[$b]['kw_gravity'] = $gr['kochwurze_gravity'];
                        break;
                    case 'Cooling':
                        $brewGrav[$b]['volume']   = $gr['final_volume'];
                        $brewGrav[$b]['dilution'] = $gr['batch_dilution'];
                        $brewGrav[$b]['og']       = $gr['final_gravity'];
                        $brewGrav[$b]['ph']        = $gr['final_ph'];
                        break;
                }
            }

            $brewTim = [];  // keyed by brew number
            $editHeaderDate = (string)($editRow['event_date'] ?? '');
            foreach ($timRows as $tr) {
                $b = (int)$tr['brew'];
                // Split DATETIME to date (Y-m-d) and time (HH:MM) parts.
                // Fallbacks: start_date → stored event_date → header date;
                //            end_date   → start_date.
                $startDate = '';
                $startHm   = '';
                $endDate   = '';
                $endHm     = '';
                if (!empty($tr['brew_start'])) {
                    $startDate = substr($tr['brew_start'], 0, 10); // "YYYY-MM-DD"
                    $startHm   = substr($tr['brew_start'], 11, 5); // "HH:MM"
                } else {
                    $startDate = !empty($tr['event_date'])
                        ? (string)$tr['event_date']
                        : $editHeaderDate;
                }
                if (!empty($tr['brew_end'])) {
                    $endDate = substr($tr['brew_end'], 0, 10);
                    $endHm   = substr($tr['brew_end'], 11, 5);
                } else {
                    $endDate = $startDate ?: $editHeaderDate;
                }
                $brewTim[$b] = [
                    'start_date' => $startDate,
                    'start'      => $startHm,
                    'end_date'   => $endDate,
                    'end'        => $endHm,
                ];
            }

            // ── Build BREWING_STICKY_COOL arrays (indexed 0..N-1) ─────────────
            $prefillCoolArrays = [
                'fw_gravities' => [],
                'fw_phs'       => [],
                'pv_gravities' => [],
                'kw_gravities' => [],
                'start_dates'  => [],
                'start_times'  => [],
                'end_dates'    => [],
                'end_times'    => [],
                'volumes'      => [],
                'dilutions'    => [],
                'gravities'    => [],
                'phs'          => [],
            ];
            foreach ($sortedBrewNums as $b) {
                $g = $brewGrav[$b] ?? [];
                $t = $brewTim[$b]  ?? [];
                $prefillCoolArrays['fw_gravities'][] = $g['fw_gravity'] ?? '';
                $prefillCoolArrays['fw_phs'][]       = $g['fw_ph']      ?? '';
                $prefillCoolArrays['pv_gravities'][] = $g['pv_gravity'] ?? '';
                $prefillCoolArrays['kw_gravities'][] = $g['kw_gravity'] ?? '';
                $prefillCoolArrays['start_dates'][]  = $t['start_date'] ?? $editHeaderDate;
                $prefillCoolArrays['start_times'][]  = $t['start']      ?? '';
                $prefillCoolArrays['end_dates'][]    = $t['end_date']   ?? $editHeaderDate;
                $prefillCoolArrays['end_times'][]    = $t['end']        ?? '';
                $prefillCoolArrays['volumes'][]      = $g['volume']     ?? '';
                $prefillCoolArrays['dilutions'][]    = $g['dilution']   ?? '';
                $prefillCoolArrays['gravities'][]    = $g['og']         ?? '';
                $prefillCoolArrays['phs'][]          = $g['ph']         ?? '';
            }

            // ── Build BREWING_STICKY_ING arrays ───────────────────────────────
            $prefillIngArrays = [
                'mi_ids' => [],
                'cats'   => [],
                'qtys'   => [],
                'units'  => [],
                'lots'   => [],
            ];
            foreach ($prefillIngLines as $il) {
                // raw_name holds the MI string id when resolved
                $prefillIngArrays['mi_ids'][] = $il['raw_name'] ?? '';
                $prefillIngArrays['cats'][]   = $il['category'] ?? '';
                $prefillIngArrays['qtys'][]   = $il['qty']      ?? '';
                $prefillIngArrays['units'][]  = $il['unit']     ?? '';
                $prefillIngArrays['lots'][]   = $il['lot']      ?? '';
            }

            // ── Prefill header fields ─────────────────────────────────────────
            // Keys mirror those used in $stickyPost reads in the render block.
            $prefillHeader = [
                'beer_select'  => $editRow['beer'],
                'batch'        => $editRow['batch'],
                'event_date'   => $editRow['event_date'],
                'cct'          => $editRow['cct'],
                'yeast_select' => $editRow['yeast'],
                'yeast_gen'    => $editRow['yeast_gen'],
                'pitched_from' => $editRow['pitched_from'],
                'new_yeast'    => $editRow['new_yeast'],
                'yt_number'    => $editRow['yt_number'],
                'comments'     => $editRow['comments'],
                'recipe_id_fk' => $editRow['recipe_id_fk'],
            ];

            $editBanner = [
                'recipe_name' => $editRow['recipe_name'] ?? $editRow['beer'],
                'batch'       => $editRow['batch'],
                'event_date'  => $editRow['event_date'],
            ];

            // ── CIP: use existing clean loader ────────────────────────────────
            // cip_events_for() exists in cip-events.php and returns the
            // structured grouped data. The CIP partial supports $cipConfig['existing'].
            $cipExisting = cip_events_for($pdoEdit, 'brewing', $editId);
        }

    } catch (Throwable $eEdit) {
        flash_set('err', 'Erreur lors du chargement du brassin : ' . htmlspecialchars($eEdit->getMessage()));
        $editMode = false;
    }
}

// $stickyPost: populated when re-rendering after an overwrite conflict so the
// operator's entered values survive the round-trip without retyping.
$stickyPost = ($overwriteConflict !== null) ? $_POST : [];

// In edit-mode, the unified prefill accessor $pf gives the same key names as
// $stickyPost. The render block reads $pf['key'] for all header fields.
$pf = $editMode ? $prefillHeader : $stickyPost;

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
        "SELECT m.id, m.mi_id, m.name, c.name AS category, m.input_unit
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
        $slug = $catToSlug[$m['category']] ?? 'adjunct';
        $raw  = $m['input_unit'] ?? null;
        if ($raw === 'kg' || $raw === 'g' || $raw === 'ml') {
            $unit = $raw;
        } elseif ($raw === 'l') {
            $unit = 'ml';
        } else {
            // month, unit, NULL, or anything unrecognised → category fallback
            $unit = ($slug === 'malt') ? 'kg' : 'g';
        }
        $miJs[] = [
            'id'   => (int)  $m['id'],
            'mi_id'=> $m['mi_id'],
            'name' => $m['name'],
            'cat'  => $slug,
            'unit' => $unit,
        ];
    }

    // CIP infra — types from ref_cip_types; no existing events on new submission
    $cipTypes = cip_type_options($pdo);

    // Recent brewing submissions (last 10 web-entered)
    $recentRows = $pdo->prepare(
        "SELECT b.id, b.event_date, b.beer, b.batch, b.cct, b.yeast,
                b.email, b.submitted_at, b.audit_flags,
                COALESCE(NULLIF(u.display_name,''), b.email) AS operator_display
           FROM bd_brewing_brewday_v2 b
           LEFT JOIN users u ON u.id = b.submitted_by_user_id_fk
          WHERE b.audit_flags LIKE '%web_entry%'
          ORDER BY b.submitted_at DESC
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
    'existing'           => $editMode ? ($cipExisting ?? null) : null,
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $editMode ? 'Modifier Brassage' : 'Saisie Brassage' ?> — MaltyTask</title>
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

<main id="main-content" class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <?php if ($editMode && $editBanner !== null):
      $ebRecipe = htmlspecialchars((string)($editBanner['recipe_name'] ?? '—'));
      $ebBatch  = htmlspecialchars((string)($editBanner['batch'] ?? '—'));
      $ebDate   = htmlspecialchars((string)($editBanner['event_date'] ?? '—'));
  ?>
    <div class="fb-edit-banner" role="status">
      <span class="fb-edit-banner__icon" aria-hidden="true">✎</span>
      <div class="fb-edit-banner__body">
        <strong>Mettre à jour le brassin <?= $ebRecipe ?> (B<?= $ebBatch ?>) brassé le <?= $ebDate ?></strong>
        <?php if ($contiguityWarn): ?>
          <br><span class="fb-edit-banner__warn">Numérotation de brassins non contiguë détectée — vérifier après sauvegarde.</span>
        <?php endif ?>
      </div>
    </div>
  <?php endif ?>

  <?php if ($overwriteConflict !== null && !$editMode):
      $owRecipe   = htmlspecialchars((string)($overwriteConflict['recipe_name'] ?? '—'));
      $owCct      = $overwriteConflict['cct'] !== null ? 'CCT-' . (int)$overwriteConflict['cct'] : '—';
      $owDate     = htmlspecialchars((string)($overwriteConflict['event_date'] ?? '—'));
  ?>
    <div class="fb-overwrite-warning" role="alert">
      <span class="fb-overwrite-warning__icon" aria-hidden="true">⚠</span>
      <div class="fb-overwrite-warning__body">
        <strong>Ce lot existe déjà —</strong>
        <?= $owRecipe ?> / <?= htmlspecialchars($owCct) ?> / brassé le <?= $owDate ?>.
        Re-soumettre va rafraîchir toutes les données du brassin : en-tête (recette, CCT, levure),
        ingrédients, gravités (première trempe, Pfannevoll, Kochwürze, cast-out) et timings.
      </div>
    </div>
  <?php endif ?>

  <!-- ── Page header ────────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Brassage · Brewing</div>
    <?php if ($editMode): ?>
    <h1 class="op-form__title">Modifier <em>brassage</em></h1>
    <p class="op-form__sub">
      Mise à jour d'un brassin existant. Les champs sont pré-remplis depuis la base de données.
      Complétez les cases vides et sauvegardez.
    </p>
    <?php else: ?>
    <h1 class="op-form__title">Saisie <em>brassage</em></h1>
    <p class="op-form__sub">
      Enregistrement d'un brassin : recette, CCT, levure, ingrédients.
      Toutes les valeurs sont acceptées sans blocage — les saisies web sont marquées
      <code>web_entry</code> pour l'audit.
    </p>
    <?php endif ?>
  </div>

  <!-- ── FORM ──────────────────────────────────────────────────────────────── -->
  <form id="brewing-form" method="post" action="/modules/form-brewing.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <!-- confirm_overwrite: pre-acknowledged in edit-mode (operator intent established
         by clicking ?edit= entry point); operator checkbox gates blank-entry conflicts. -->
    <input type="hidden" name="confirm_overwrite" id="confirm_overwrite_hidden"
           value="<?= $editMode ? '1' : '0' ?>">
    <?php if ($overwriteConflict !== null && !$editMode): ?>
    <div class="fb-overwrite-confirm">
      <label class="fb-overwrite-confirm__label">
        <input type="checkbox" id="confirm_overwrite_cb" class="fb-overwrite-confirm__cb"
               onchange="document.getElementById('confirm_overwrite_hidden').value = this.checked ? '1' : '0'">
        Confirmer le rafraîchissement complet de ce brassin (en-tête, ingrédients, gravités, timings)
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
              $pfBeer = (string)($pf['beer_select'] ?? '');
              foreach ($recipes as $r):
                $selected = ($r['name'] === $pfBeer) ? ' selected' : '';
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
          <!-- Hidden recipe FK — populated by form-brewing.js on select-change;
               pre-seeded from $pf (edit-mode DB value or sticky POST value). -->
          <input type="hidden" id="recipe_id_fk" name="recipe_id_fk"
                 value="<?= htmlspecialchars((string)($pf['recipe_id_fk'] ?? '')) ?>">
        </div>

        <!-- Batch number -->
        <div class="op-form__field">
          <label class="op-form__label" for="batch">N° brassin</label>
          <input id="batch" name="batch" type="text" class="op-form__input"
                 placeholder="ex. 215" autocomplete="off" required
                 value="<?= htmlspecialchars((string)($pf['batch'] ?? '')) ?>">
        </div>

        <!-- Brew date (HTML date picker, always Y-m-d on submit) -->
        <div class="op-form__field">
          <label class="op-form__label" for="event_date">
            Date brassage
            <span class="op-form__unit"><?= htmlspecialchars($displayFmt) ?></span>
          </label>
          <input id="event_date" name="event_date" type="date" class="op-form__input"
                 value="<?= htmlspecialchars((string)($pf['event_date'] ?? date('Y-m-d'))) ?>" required>
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
              $pfCct = $pf['cct'] ?? null;
              $cctSelected = ($pfCct !== null && $pfCct !== '' && (string)(int)$c['number'] === (string)(int)$pfCct)
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
              $yeastSelected = (((string)($pf['yeast_select'] ?? '')) === $ys['name']) ? ' selected' : '';
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
                 value="<?= htmlspecialchars((string)($pf['yeast_gen'] ?? '')) ?>">
        </div>

        <!-- Pitched from (previous batch) -->
        <div class="op-form__field">
          <label class="op-form__label" for="pitched_from">Récolte de</label>
          <input id="pitched_from" name="pitched_from" type="text" class="op-form__input"
                 placeholder="ex. ZEP 213" autocomplete="off"
                 value="<?= htmlspecialchars((string)($pf['pitched_from'] ?? '')) ?>">
        </div>

        <!-- New yeast (free-text, used when new strain not in catalog) -->
        <div class="op-form__field">
          <label class="op-form__label" for="new_yeast">
            Nouvelle souche <span class="op-form__unit">(si absente de la liste)</span>
          </label>
          <input id="new_yeast" name="new_yeast" type="text" class="op-form__input"
                 placeholder="Nom de la nouvelle souche" autocomplete="off"
                 value="<?= htmlspecialchars((string)($pf['new_yeast'] ?? '')) ?>">
        </div>

        <!-- YT number (optional — when pitched via Yeast Temp tank) -->
        <div class="op-form__field">
          <label class="op-form__label" for="yt_number">YT n°</label>
          <input id="yt_number" name="yt_number" type="number" class="op-form__input"
                 placeholder="—" min="1"
                 value="<?= htmlspecialchars((string)($pf['yt_number'] ?? '')) ?>">
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
        Adjuvant, Minéral, Process. La levure est saisie dans la section ci-dessus.<br>
        Collage / clarification (Nagardo, Clarex, Dehaze) → saisir sous <strong>Process</strong>.
      </p>
    </div>

    <!-- ── Section: Déroulé du brassage (multi-brassin) ──────────────────────── -->
    <!-- One row per sub-brew. Each row writes:                                    -->
    <!--   bd_brewing_gravity_v2 × 4 (FirstWort/Pfannevoll/Kochwurze/Cooling)     -->
    <!--   bd_brewing_timings_v2 × 1 (brew_start / brew_end / event_date)         -->
    <!-- NK gravity: (beer, batch, brew, event_type) — NK timings: (beer, batch, brew) -->
    <!-- Fully empty rows are skipped. Batch total = SUM(Cooling.final_volume).   -->
    <div class="op-form__card">
      <div class="op-form__card-title">
        — déroulé du brassage
        <span class="brew-cool__count" id="cool-count-badge"></span>
      </div>

      <div class="brew-cool__scroll-wrap">
        <table class="brew-cool__table brew-cool__table--wide">
          <thead>
            <tr>
              <th class="brew-cool__col--num">Brassin</th>
              <th class="brew-cool__col--fw-grav">Première trempe <span class="op-form__unit">°P</span></th>
              <th class="brew-cool__col--fw-ph">pH FW</th>
              <th class="brew-cool__col--pv">Pfannevoll <span class="op-form__unit">°P</span></th>
              <th class="brew-cool__col--kw">Kochwürze <span class="op-form__unit">°P</span></th>
              <th class="brew-cool__col--date">Date début</th>
              <th class="brew-cool__col--time">Heure début</th>
              <th class="brew-cool__col--date">Date fin</th>
              <th class="brew-cool__col--time">Heure fin</th>
              <th class="brew-cool__col--vol">Cast-out <span class="op-form__unit">HL</span></th>
              <th class="brew-cool__col--dilut">Dilution <span class="op-form__unit">HL</span></th>
              <th class="brew-cool__col--og">OG <span class="op-form__unit">°P</span></th>
              <th class="brew-cool__col--ph">pH</th>
              <th class="brew-cool__col--del"></th>
            </tr>
          </thead>
          <tbody id="cool-tbody">
            <!-- Rows added by form-brewing.js — one shown by default -->
          </tbody>
        </table>
      </div>

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
        Un brassin = un cycle de brassage. Pour un multi-brassin, ajouter une ligne par cycle.
        Les colonnes gravité enregistrent la progression en °Plato (première trempe → Pfannevoll →
        Kochwürze → cast-out). Le volume cast-out est l'opérande WIP/COGS —
        <code>SUM(final_volume)</code> sur les lignes <code>Cooling</code> du batch.
        La dilution est le volume d'eau ajouté au refroidissement (optionnel).
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
                    placeholder="Observations, écarts de rendement, problèmes…"><?= htmlspecialchars((string)($pf['comments'] ?? '')) ?></textarea>
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
        <?= $editMode ? 'Mettre à jour le brassin →' : 'Enregistrer le brassin →' ?>
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
            <th></th>
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
              <td class="op-form__mono"><?= htmlspecialchars($rb['operator_display'] ?? $rb['email'] ?? '') ?></td>
              <td class="fb-recent__edit-cell">
                <a href="/modules/form-brewing.php?edit=<?= (int)$rb['id'] ?>"
                   class="fb-recent__edit-link">Ouvrir / compléter</a>
              </td>
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
<?php if ($editMode): ?>
// Edit-mode: pre-seed JS blobs from canonical DB data (reassembled above from bd_*_v2 tables).
window.BREWING_STICKY_COOL = <?= json_encode($prefillCoolArrays, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.BREWING_STICKY_ING  = <?= json_encode($prefillIngArrays,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php elseif (!empty($stickyPost)): ?>
// Sticky brew-progression rows — pre-seed JS when re-rendering after an overwrite conflict.
window.BREWING_STICKY_COOL = <?= json_encode([
    'fw_gravities'  => array_values($stickyPost['brew_fw_gravity']     ?? []),
    'fw_phs'        => array_values($stickyPost['brew_fw_ph']          ?? []),
    'pv_gravities'  => array_values($stickyPost['brew_pv_gravity']     ?? []),
    'kw_gravities'  => array_values($stickyPost['brew_kw_gravity']     ?? []),
    'start_dates'   => array_values($stickyPost['brew_start_date']     ?? []),
    'start_times'   => array_values($stickyPost['brew_start_time']     ?? []),
    'end_dates'     => array_values($stickyPost['brew_end_date']       ?? []),
    'end_times'     => array_values($stickyPost['brew_end_time']       ?? []),
    'volumes'       => array_values($stickyPost['cool_final_volume']   ?? []),
    'dilutions'     => array_values($stickyPost['cool_batch_dilution'] ?? []),
    'gravities'     => array_values($stickyPost['cool_final_gravity']  ?? []),
    'phs'           => array_values($stickyPost['cool_final_ph']       ?? []),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
// Sticky ingredient rows — pre-seed JS when re-rendering after an overwrite conflict.
window.BREWING_STICKY_ING = <?= json_encode([
    'mi_ids'  => array_values($stickyPost['ing_mi_id']  ?? []),
    'cats'    => array_values($stickyPost['ing_cat']    ?? []),
    'qtys'    => array_values($stickyPost['ing_qty']    ?? []),
    'units'   => array_values($stickyPost['ing_unit']   ?? []),
    'lots'    => array_values($stickyPost['ing_lot']    ?? []),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
<?php endif ?>
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/form-brewing.js?v=<?= @filemtime(__DIR__ . '/../js/form-brewing.js') ?: time() ?>" defer></script>


</body>
</html>
