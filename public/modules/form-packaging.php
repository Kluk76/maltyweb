<?php
declare(strict_types=1);
/**
 * form-packaging.php — Operator packaging-run entry form (bd_packaging_v2).
 *
 * Pattern: fan-out of form-racking.php (the reference operator form).
 *   1. require app/db-write-helpers.php
 *   2. POST handler: csrf_verify → coerce inputs → bd_qc_flag → build rows (main + parallels)
 *      → bd_upsert each → log_revision → flash_set → redirect
 *   3. GET: load candidate lots (bd_racking_v2, eligibility ≥1 day before today,
 *            BBT or CCT destinations) + ref_clients + ref data
 *      → render form with op-form__* CSS classes
 *   4. JS: tank card selection → hidden fields; multi-format parallel rows; client dropdown
 *          (contract/WL only); CIP section; loss fields.
 *
 * ── WRITE GUARD ──────────────────────────────────────────────────────────────
 * Real writes to bd_packaging_v2 are DISABLED by default (PACKAGING_WRITE_ENABLED
 * constant below = false). The submit handler validates and logs all fields but
 * INSERTs into a clearly-named draft table (packaging_form_draft). This lets
 * the operator confirm the field→column mapping before live writes are enabled.
 *
 * To enable real writes: set PACKAGING_WRITE_ENABLED = true after operator
 * confirms the mapping in the "Proposed write mapping" section at bottom of file.
 *
 * URL: /modules/form-packaging.php
 * Writes to (draft mode): audit_row_revisions only
 * Writes to (live mode):  bd_packaging_v2 + audit_row_revisions
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/tank-simulator.php';

require_login();
$me = current_user();

// ── WRITE GUARD ───────────────────────────────────────────────────────────────
// Set to true only after operator confirms field→column mapping AND migration 127 is applied.
const PACKAGING_WRITE_ENABLED = true;
const PACKAGING_LIVE_TABLE    = 'bd_packaging_v2';

// ── Minimum days between racking and eligible packaging ───────────────────────
// Read from commissioning_settings (section='packaging', key='min_days_after_racking').
// Falls back to PACKAGING_MIN_DAYS_FALLBACK when the setting row is missing (migration
// 128 not yet applied) — keeps the form functional during the migration window.
// IMPORTANT: process conditions must NEVER be hardcoded — this fallback is only a
// bootstrap guard. Once migration 128 is applied this constant is unused.
const PACKAGING_MIN_DAYS_FALLBACK = 1;

// ── Allowed enum values ───────────────────────────────────────────────────────
// run_type matches bd_packaging_v2.run_type ENUM exactly
const RUN_TYPES = ['bot', 'can', 'can33', 'keg', 'cuv'];

// Human labels for run_type (operator-facing — no DB codes in the UI)
const RUN_TYPE_LABELS = [
    'bot'   => 'Bouteille 33cl',
    'can'   => 'Canette 50cl',
    'can33' => 'Canette 33cl',
    'keg'   => 'Fût 20L',
    'cuv'   => 'Cuve de service',
];

// nebuleuse_format_suffix options (the SKU suffix per bd_packaging_v2.uq_natural_key)
const FORMAT_SUFFIXES = [
    ''    => '— principal (pas de suffixe) —',
    '4'   => '4 (carton 6×4)',
    'B'   => 'B (box 24)',
    'F'   => 'F (fût 20L)',
    'V'   => 'V (cuve de service)',
    'C'   => 'C (canette)',
    'BU'  => 'BU (single bottle)',
    'CU'  => 'CU (single can)',
];

// CIP Yes/No options
const CIP_YESNO = ['Oui' => 'Oui', 'Non' => 'Non'];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-packaging.php');
    }

    try {
        $pdo = maltytask_pdo();

        // ── 1. Coerce + validate inputs ──────────────────────────────────────

        // ── Override: Choix Hors Process (manager/admin only) ───────────────────
        // Server-side role enforcement: if a non-manager/admin somehow sends
        // hors_process=1, silently ignore it (never trust the client-side gate alone).
        $horsProcessRequested = (post_int('hors_process') === 1);
        $horsProcessAllowed   = (is_admin($me) || is_manager($me));
        $horsProcessFlag      = ($horsProcessRequested && $horsProcessAllowed) ? 1 : 0;
        $horsProcessReason    = ($horsProcessFlag === 1) ? post_str('hors_process_reason') : null;

        // Source tank (decision 2: type + FK ID)
        $sourceTankType = post_str('source_tank_type');   // 'BBT' or 'CCT'
        $sourceTankId   = post_int('source_tank_id');     // ref_bbt.id or ref_cct.id
        $sourceTankNum  = post_int('source_tank_num');    // display number (for summary)

        if ($sourceTankType === null || !in_array($sourceTankType, ['BBT', 'CCT'], true)) {
            throw new RuntimeException("Sélectionner un lot source (BBT ou CCT).");
        }
        if ($sourceTankId === null) {
            throw new RuntimeException("Identifiant de cuve source manquant.");
        }

        $bbtSourceFk = ($sourceTankType === 'BBT') ? $sourceTankId : null;
        $cctSourceFk = ($sourceTankType === 'CCT') ? $sourceTankId : null;

        // Beer identity (auto-filled from tank selection)
        $nebBeer      = post_str('neb_beer')      ?? '';
        $nebBatch     = post_str('neb_batch')      ?? '';
        $contractBeer = post_str('contract_beer')  ?? '';
        $contractBatch= post_str('contract_batch') ?? '';

        if ($nebBeer === '' && $contractBeer === '') {
            throw new RuntimeException("Identité bière manquante — resélectionner le lot.");
        }

        // Recipe FK
        $recipeIdFk = post_int('recipe_id_fk');

        // Event date (decision 3 — defaults to today)
        $eventDateRaw = post_str('event_date') ?? date('Y-m-d');
        // Basic validation: must be a real date
        $eventDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateRaw))
            ? $eventDateRaw
            : date('Y-m-d');

        // QA measurements (shared across all format rows in the session)
        $tankCo2 = post_decimal('tank_co2');
        $tankO2  = post_decimal('tank_o2');

        // CIP fields (decision 4)
        $cipTankDone     = post_str('cip_tank_done');
        $cipTankType     = post_str('cip_tank_type');
        $cipTankDate     = post_str('cip_tank_date');
        $cipMachinesDone = post_str('cip_machines_done');
        $cipMachinesType = post_str('cip_machines_type');
        $cipMachinesDate = post_str('cip_machines_date');

        // Keg / cuv specific (decision 1 fields carried over)
        $kegClientDelivered = post_str('keg_client_delivered');
        $newLinerClient     = post_int('new_liner_client');
        $newLinerTransport  = post_int('new_liner_transport');

        // White label
        $isWhiteLabel   = (post_int('is_white_label') === 1) ? 1 : 0;
        $whiteLabelName = post_str('white_label_name');

        // Client FK (decision 7 — dropdown from ref_clients, visible for contract/WL)
        $clientFk = post_int('client_fk');   // ref_clients.id or null

        // Comments / framework comment
        $comments  = post_str('comments');
        $fwComment = post_str('fw_comment');

        // DLC
        $nebDlc      = post_str('neb_dlc');
        $contractDlc = post_str('contract_dlc');

        // ── 2. QC flag ─────────────────────────────────────────────────────
        $measurements = array_filter([
            'bbt_co2' => $tankCo2,
            'bbt_o2'  => $tankO2,
        ], fn($v) => $v !== null);
        $qcFlag = bd_qc_flag($measurements);

        // ── 3. Build submitted_at ───────────────────────────────────────────
        $submittedAt = date('Y-m-d H:i:s.u');

        $auditTokens = ['web_entry', 'write_guard_active'];
        if (!PACKAGING_WRITE_ENABLED) $auditTokens[] = 'draft_mode';
        if ($qcFlag !== 'normal') $auditTokens[] = "qc_{$qcFlag}";
        if ($horsProcessFlag === 1) $auditTokens[] = 'hors_process_override';
        $auditFlags = implode(',', $auditTokens);

        // ── 4. Parse multi-format rows (decision 8) ─────────────────────────
        // The form submits N format lines. Each has:
        //   formats[N][run_type], formats[N][format_suffix], formats[N][row_origin] (main|parallel)
        //   formats[N][prod_total_units], formats[N][qte_unites], formats[N][vendable_hl]
        //   formats[N][unsaleable_units], formats[N][loss_*], ...
        //
        // prod_total  = main run only (row_origin='main')
        // qte_unites  = parallel rows only (special_qty_units in v2), ADD not subtract
        $formatsRaw = $_POST['formats'] ?? [];
        if (empty($formatsRaw) || !is_array($formatsRaw)) {
            throw new RuntimeException("Au moins un format de conditionnement est requis.");
        }

        // Validate that there is exactly one 'main' row
        $mainCount = 0;
        foreach ($formatsRaw as $f) {
            if (($f['row_origin'] ?? 'main') === 'main') $mainCount++;
        }
        if ($mainCount !== 1) {
            throw new RuntimeException("Exactement un format principal (main) requis.");
        }

        // Build one row per format line
        $rows = [];
        foreach ($formatsRaw as $idx => $f) {
            $fRunType   = $f['run_type'] ?? '';
            $fOrigin    = $f['row_origin'] ?? 'main';
            $fSuffix    = $f['format_suffix'] ?? null;
            $fProdTotal = isset($f['prod_total_units']) && $f['prod_total_units'] !== ''
                            ? (int)$f['prod_total_units'] : null;
            $fQteUnites = isset($f['qte_unites']) && $f['qte_unites'] !== ''
                            ? (int)$f['qte_unites'] : null;
            $fVendableHl= isset($f['vendable_hl']) && $f['vendable_hl'] !== ''
                            ? $f['vendable_hl'] : null;
            $fUnsaleable= isset($f['unsaleable_units']) && $f['unsaleable_units'] !== ''
                            ? (int)$f['unsaleable_units'] : null;

            // Per-type loss fields (only collected, stored directly)
            $fLoss4packBtl   = isset($f['loss_4pack_btl_units'])    && $f['loss_4pack_btl_units']    !== '' ? (int)$f['loss_4pack_btl_units']    : null;
            $fLoss4packCan   = isset($f['loss_4pack_can_units'])     && $f['loss_4pack_can_units']    !== '' ? (int)$f['loss_4pack_can_units']    : null;
            $fLossWrapBtl    = isset($f['loss_wrap_btl_units'])      && $f['loss_wrap_btl_units']    !== '' ? (int)$f['loss_wrap_btl_units']    : null;
            $fLossWrapCan    = isset($f['loss_wrap_can_units'])      && $f['loss_wrap_can_units']    !== '' ? (int)$f['loss_wrap_can_units']    : null;
            $fLossLabelBtl   = isset($f['loss_label_btl_units'])     && $f['loss_label_btl_units']   !== '' ? (int)$f['loss_label_btl_units']   : null;
            $fLossKegCollar  = isset($f['loss_keg_collar_units'])    && $f['loss_keg_collar_units']  !== '' ? (int)$f['loss_keg_collar_units']  : null;
            $fLossCrownCork  = isset($f['loss_crown_cork_units'])    && $f['loss_crown_cork_units']  !== '' ? (int)$f['loss_crown_cork_units']  : null;
            $fLossCanLid     = isset($f['loss_can_lid_units'])       && $f['loss_can_lid_units']     !== '' ? (int)$f['loss_can_lid_units']     : null;
            $fLossKegSave    = isset($f['loss_keg_save_units'])      && $f['loss_keg_save_units']    !== '' ? (int)$f['loss_keg_save_units']    : null;
            $fLossContBtl    = isset($f['loss_container_btl_units']) && $f['loss_container_btl_units'] !== '' ? (int)$f['loss_container_btl_units'] : null;
            $fLossContCan    = isset($f['loss_container_can_units']) && $f['loss_container_can_units'] !== '' ? (int)$f['loss_container_can_units'] : null;
            $fLossLiquid     = isset($f['loss_liquid_other_units'])  && $f['loss_liquid_other_units'] !== '' ? $f['loss_liquid_other_units'] : null;
            $fQaAnalyses     = isset($f['qa_analyses_units'])        && $f['qa_analyses_units']     !== '' ? (int)$f['qa_analyses_units']     : null;
            $fQaLibrary      = isset($f['qa_library_units'])         && $f['qa_library_units']      !== '' ? (int)$f['qa_library_units']      : null;

            must_be_one_of("formats[{$idx}][run_type]", $fRunType, RUN_TYPES);
            if (!in_array($fOrigin, ['main', 'parallel'], true)) {
                throw new RuntimeException("row_origin invalide pour le format #{$idx}.");
            }

            // Row hash: include session identity + format-specific fields
            $hashCols = [
                $nebBeer, $nebBatch,
                $contractBeer, $contractBatch,
                $sourceTankType, (string)$sourceTankId,
                $fRunType, $fOrigin,
                $fSuffix ?? '',
                $eventDate,
                $submittedAt,   // timestamp ensures uniqueness per session
                (string)$idx,
            ];
            $rowHash = bd_row_hash($hashCols);

            $rows[] = [
                'row'    => [
                    'row_hash'               => $rowHash,
                    'row_origin'             => $fOrigin,
                    'audit_flags'            => $auditFlags,
                    'submitted_at'           => $submittedAt,
                    'email'                  => $me['username'],
                    'event_date'             => $eventDate,
                    'source_tank_type'       => $sourceTankType,
                    'bbt_source_fk'          => $bbtSourceFk,
                    'cct_source_fk'          => $cctSourceFk,
                    'neb_beer'               => $nebBeer,
                    'neb_batch'              => $nebBatch,
                    'neb_dlc'                => $nebDlc,
                    'contract_beer'          => $contractBeer,
                    'contract_batch'         => $contractBatch,
                    'recipe_id_fk'           => $recipeIdFk,
                    'nebuleuse_format_suffix' => ($fSuffix !== '' && $fSuffix !== null) ? $fSuffix : null,
                    'run_type'               => $fRunType,
                    // main: prod_total; parallel: special_qty_units (qte_unites)
                    'prod_total_units'       => ($fOrigin === 'main') ? $fProdTotal : null,
                    'special_qty_units'      => ($fOrigin === 'parallel') ? $fQteUnites : null,
                    'vendable_hl'            => $fVendableHl,
                    'unsaleable_units'       => $fUnsaleable,
                    'qa_analyses_units'      => $fQaAnalyses,
                    'qa_library_units'       => $fQaLibrary,
                    // Per-type losses (decision 6)
                    'loss_4pack_btl_units'   => $fLoss4packBtl,
                    'loss_4pack_can_units'   => $fLoss4packCan,
                    'loss_wrap_btl_units'    => $fLossWrapBtl,
                    'loss_wrap_can_units'    => $fLossWrapCan,
                    'loss_label_btl_units'   => $fLossLabelBtl,
                    'loss_keg_collar_units'  => $fLossKegCollar,
                    'loss_crown_cork_units'  => $fLossCrownCork,
                    'loss_can_lid_units'     => $fLossCanLid,
                    'loss_keg_save_units'    => $fLossKegSave,
                    'loss_container_btl_units'=> $fLossContBtl,
                    'loss_container_can_units'=> $fLossContCan,
                    'loss_liquid_other_units' => $fLossLiquid,
                    // Keg / cuv (shared for main row; parallel rows inherit the same tank)
                    'keg_client_delivered'   => $kegClientDelivered,
                    'new_liner_client'       => ($newLinerClient !== null) ? (bool)$newLinerClient : null,
                    'new_liner_transport'    => ($newLinerTransport !== null) ? (bool)$newLinerTransport : null,
                    // White label
                    'is_white_label'         => (bool)$isWhiteLabel,
                    'white_label_name'       => $whiteLabelName,
                    // Client FK (decision 7)
                    'client_fk'              => $clientFk,
                    // CIP (decision 4; requires migration 127 — columns absent until applied)
                    'cip_tank_done'          => $cipTankDone,
                    'cip_tank_type'          => $cipTankType,
                    'cip_tank_date'          => $cipTankDate,
                    'cip_machines_done'      => $cipMachinesDone,
                    'cip_machines_type'      => $cipMachinesType,
                    'cip_machines_date'      => $cipMachinesDate,
                    // QA
                    'tank_co2'               => $tankCo2,
                    'tank_o2'                => $tankO2,
                    // Comments (on main row only; parallels inherit the session)
                    'comments'               => ($fOrigin === 'main') ? $comments : null,
                    // Hors Process override (migration 128 — columns absent until applied)
                    'hors_process_flag'      => $horsProcessFlag,
                    'hors_process_reason'    => $horsProcessReason,
                ],
                'origin' => $fOrigin,
            ];
        }

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'row_origin', 'nebuleuse_format_suffix'];

        $beerLabel = $nebBeer !== '' ? $nebBeer : $contractBeer;

        if (PACKAGING_WRITE_ENABLED) {
            // ── Live write path (disabled until operator confirms mapping + migration applied) ──
            // NOTE: cip_tank_* / cip_machines_* / source_tank_type / bbt_source_fk /
            //   cct_source_fk / event_date columns require migration 127 to be applied first.
            foreach ($rows as $rSpec) {
                // Strip CIP + new-schema columns from row if migration not yet applied
                // (safety shim — remove this block after migration 127 is applied)
                $safeRow = $rSpec['row'];
                $result = bd_upsert($pdo, PACKAGING_LIVE_TABLE, $safeRow, $nkCols);
                log_revision($pdo, $me, PACKAGING_LIVE_TABLE, $result['id'], null, $safeRow, $qcFlag,
                    ($rSpec['origin'] === 'parallel' ? '[parallel] ' : '') . ($fwComment ?: null));
            }
            $qcLabel = match($qcFlag) {
                'elevated' => ' — valeurs à vérifier',
                'outlier'  => ' — outlier enregistré',
                default    => '',
            };
            $nFmt = count($rows);
            flash_set('ok', "Conditionnement enregistré : {$beerLabel} — {$nFmt} format(s){$qcLabel}");

        } else {
            // ── Draft write path (safe sandbox — no real bd_packaging_v2 rows) ──
            foreach ($rows as $i => $rSpec) {
                log_revision(
                    $pdo,
                    $me,
                    '[DRAFT] ' . PACKAGING_LIVE_TABLE,
                    0,
                    null,
                    array_merge($rSpec['row'], ['_format_index' => $i]),
                    $qcFlag,
                    '[DRAFT MODE] format_index=' . $i . ' origin=' . $rSpec['origin']
                    . ' — Write guard active. ' . ($fwComment ?: 'No real row created.')
                );
            }
            $nFmt = count($rows);
            flash_set('ok', "[BROUILLON] Saisie enregistrée (mode test) : {$beerLabel} — {$nFmt} format(s). Aucune ligne bd_packaging_v2 créée.");
        }

    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'form-packaging'));
    }

    redirect_to('/modules/form-packaging.php');
}

// ── GET ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    // ── Read packaging.min_days_after_racking from commissioning_settings ─────
    // The setting must NOT be hardcoded. Read from DB; fall back to
    // PACKAGING_MIN_DAYS_FALLBACK only when migration 128 is not yet applied.
    $settingStmt = $pdo->prepare(
        "SELECT value_num, default_num
           FROM commissioning_settings
          WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
            AND is_active = 1
          LIMIT 1"
    );
    $settingStmt->execute();
    $settingRow = $settingStmt->fetch(PDO::FETCH_ASSOC);
    $minDays = $settingRow !== false
        ? (int) ($settingRow['value_num'] ?? $settingRow['default_num'] ?? PACKAGING_MIN_DAYS_FALLBACK)
        : PACKAGING_MIN_DAYS_FALLBACK;

    // ── Current user role for override capability ──────────────────────────
    $canOverride = (is_admin($me) || is_manager($me));

    // ── Candidate lots: racking events ≥ $minDays before today ───────────────
    //
    // Decision 1: candidates = (beer, batch, source_tank) from bd_racking_v2 where:
    //   - racking_destination_type IN ('BBT', 'CCT')  — BBT ~99%, CCT edge case
    //   - event_date <= DATE_SUB(CURDATE(), INTERVAL N DAY)  — configurable minimum
    //   - is_tombstoned = 0
    //   - Most recent racking per (destination_type, tank_number) — latest racking wins
    //
    // The normal candidate query respects $minDays.
    // The override query (Choix Hors Process) drops the date gate entirely — shows ALL
    // lots currently in CCT/BBT (latest racking per tank, regardless of event_date).
    // Both queries are built here; the override list is injected into window.* only
    // when the user has manager/admin role (server-enforced).
    //
    // Note on dates (2026-05-25): an earlier read showed many "future" racking rows.
    // That was NOT scheduling — it was a day<->month swap in bd_racking_v2 (corrected
    // in place 2026-05-25, 147 rows; 0 future rows remain). The gate now runs on real
    // racking dates. The override (Choix Hors Process) is the escape hatch for
    // packaging a lot before $minDays has elapsed when operationally justified — it
    // relaxes the TIME gate only, never the physical CCT-emptied guard below.

    $candidateBaseSql = "SELECT
           r.id                                                     AS racking_id,
           r.racking_destination_type                               AS tank_type,
           COALESCE(r.bbt_number, r.cct_number)                    AS tank_number,
           CASE
             WHEN r.racking_destination_type = 'BBT' THEN rb.id
             WHEN r.racking_destination_type = 'CCT' THEN rc.id
             ELSE NULL
           END                                                      AS tank_fk_id,
           CASE
             WHEN r.racking_destination_type = 'BBT' THEN rb.capacity_hl
             WHEN r.racking_destination_type = 'CCT' THEN rc.capacity_hl
             ELSE NULL
           END                                                      AS capacity_hl,
           COALESCE(NULLIF(r.neb_beer, ''), r.contract_beer)       AS beer,
           COALESCE(NULLIF(r.neb_batch,''), r.contract_batch)      AS batch_num,
           r.racked_vol_hl,
           r.event_date                                             AS racked_at,
           r.neb_beer,
           r.neb_batch,
           r.neb_recipe_id_fk,
           r.contract_beer,
           r.contract_batch,
           r.contract_recipe_id_fk
         FROM bd_racking_v2 r
         LEFT JOIN ref_bbt rb ON rb.number = r.bbt_number
         LEFT JOIN ref_cct rc ON rc.number = r.cct_number
         WHERE r.racking_destination_type IN ('BBT', 'CCT')
           AND r.is_tombstoned = 0
           AND r.id = (
             SELECT id FROM bd_racking_v2 r2
             WHERE r2.racking_destination_type = r.racking_destination_type
               AND COALESCE(r2.bbt_number, r2.cct_number) = COALESCE(r.bbt_number, r.cct_number)
               AND r2.is_tombstoned = 0
             ORDER BY r2.submitted_at DESC
             LIMIT 1
           )
           -- CCT-emptied guard: a CCT is freed when a NEW beer is brewed into it
           -- (bd_brewing_brewday_v2.cct), not by a racking. BBTs are freed by the
           -- 'latest racking wins' subquery above; CCTs need this extra check or a
           -- stale racked-into-CCT lot lingers forever (e.g. Speakeasy b57 racked
           -- into CCT5 2025-10-20, but CCT5 re-brewed many times since). Applies to
           -- both the dated and override lists — a physically-refilled tank no longer
           -- holds the old lot regardless of the date gate.
           AND NOT (
             r.racking_destination_type = 'CCT'
             AND EXISTS (
               SELECT 1 FROM bd_brewing_brewday_v2 bb
               WHERE bb.cct = r.cct_number
                 AND bb.is_tombstoned = 0
                 AND bb.event_date IS NOT NULL
                 AND bb.event_date > r.event_date
             )
           )";

    // Normal candidate query (with date gate)
    $candStmt = $pdo->prepare(
        $candidateBaseSql .
        "  AND r.event_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         ORDER BY r.racking_destination_type ASC, tank_number ASC"
    );
    $candStmt->execute([$minDays]);
    $candidates = $candStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Apply TankSimulator volumes (replace racked_vol_hl with sim remaining) ─
    // The simulator is the authoritative tank-state engine: it accounts for
    // subsequent packaging depletion and blend adjustments, which racked_vol_hl
    // does not. Candidates whose tank is null in the sim (empty/below threshold/
    // expired) are dropped — the operator's form must be consistent with the
    // packaging dashboard which uses the same sim.
    //
    // Keying: we join on (strtolower(tank_type), (int)tank_number) only.
    // Beer-name normalisation in the sim is complex (Div.Blanche, etc.) so we
    // do NOT compare beer strings. We trust the sim's tank-occupancy as truth.
    $simState = (new TankSimulator($pdo))->run(new DateTimeImmutable('today'));

    /**
     * Filter a candidate list through the sim state:
     *  - Drops candidates whose tank is null (empty/expired).
     *  - Replaces the displayed volume with the sim remaining volume.
     *  - Preserves racked_vol_hl as a secondary field for context.
     *
     * @param array[] $list
     * @param array   $sim  ['bbt' => [...], 'cct' => [...]]
     * @return array[]
     */
    $applySimVolumes = function (array $list, array $sim): array {
        $out = [];
        foreach ($list as $cand) {
            $tankKey = strtolower((string)($cand['tank_type'] ?? ''));   // 'bbt' or 'cct'
            $tankNum = (int)($cand['tank_number'] ?? 0);
            $tankState = $sim[$tankKey][$tankNum] ?? null;

            if ($tankState === null) {
                // Tank is empty/expired in the sim — drop this candidate.
                continue;
            }

            // Inject sim remaining volume; preserve racked_vol_hl as secondary.
            $cand['sim_vol_hl']    = round((float)$tankState['volume_hl'], 2);
            $cand['racked_vol_hl'] = $cand['racked_vol_hl'];  // unchanged
            $out[] = $cand;
        }
        return $out;
    };

    $candidates = $applySimVolumes($candidates, $simState);

    // ── Override candidate list: ALL sim-occupied tanks (BBT + CCT) ──────────
    // Built from the TankSimulator, not from the racking query — so CCT-fermenting
    // lots (brewed in, not yet racked → no bd_racking_v2 row) are included.
    //
    // Strategy:
    //   1. Fetch the racking-derived override rows (no date gate) for identity richness.
    //   2. Build a keyed index from them: (tank_type, tank_number) → candidate array.
    //   3. For each sim-occupied tank, reuse the racking-derived candidate if one
    //      exists (it has neb/contract split + recipe_id_fk from the racking event).
    //      Otherwise (CCT-fermenting, no racking row), resolve from bd_brewing_brewday_v2.
    //   4. Apply sim_vol_hl from the simulator state.
    //
    // Contract: recipe_id_fk is NULL when it cannot be cleanly resolved.  The form's
    // recipe confirmation dropdown lets the operator pick the recipe in that case.
    // NEVER guess neb/contract or recipe assignments.
    $candidatesOverride = [];
    if ($canOverride) {
        // 1. Fetch racking-derived rows (no date gate) as an identity base
        $overrideRackingStmt = $pdo->prepare(
            $candidateBaseSql .
            " ORDER BY r.racking_destination_type ASC, tank_number ASC"
        );
        $overrideRackingStmt->execute();
        $rackingRows = $overrideRackingStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Key racking rows by (TYPE, number) — uppercase type matches sim keys
        $rackingByTank = [];
        foreach ($rackingRows as $rr) {
            $key = strtoupper((string)($rr['tank_type'] ?? '')) . '|' . (int)($rr['tank_number'] ?? 0);
            $rackingByTank[$key] = $rr;
        }

        // Prepare brewday lookup for CCT-fermenting lots (no racking row)
        $brewdayStmt = $pdo->prepare(
            "SELECT cct, batch, beer, recipe_id_fk
               FROM bd_brewing_brewday_v2
              WHERE cct = ? AND batch = ? AND is_tombstoned = 0
              ORDER BY event_date DESC
              LIMIT 1"
        );

        // 3. Walk every sim-occupied tank (BBT first, then CCT — by number)
        $capCache = [];
        foreach (['bbt', 'cct'] as $tankTypeKey) {
            $tankTypeUC = strtoupper($tankTypeKey);  // 'BBT' or 'CCT'
            $simTanks   = $simState[$tankTypeKey] ?? [];
            ksort($simTanks);  // ascending by tank number

            // Load ref_bbt / ref_cct capacity lookup once per type
            if (!isset($capCache[$tankTypeKey])) {
                $tbl = ($tankTypeKey === 'bbt') ? 'ref_bbt' : 'ref_cct';
                $capRows = $pdo->query("SELECT number, id, capacity_hl FROM {$tbl} ORDER BY number")->fetchAll(PDO::FETCH_ASSOC);
                $capCache[$tankTypeKey] = [];
                foreach ($capRows as $cr) {
                    $capCache[$tankTypeKey][(int)$cr['number']] = ['id' => (int)$cr['id'], 'cap' => $cr['capacity_hl']];
                }
            }

            foreach ($simTanks as $tankNum => $simTank) {
                if ($simTank === null) continue;

                $tankNum  = (int)$tankNum;
                $simVolHl = round((float)$simTank['volume_hl'], 2);
                $simBatch = (int)$simTank['batch'];
                $tankRef  = $capCache[$tankTypeKey][$tankNum] ?? null;
                $tankFkId = $tankRef ? $tankRef['id']  : null;
                $capHl    = $tankRef ? $tankRef['cap'] : null;

                $rackingKey    = $tankTypeUC . '|' . $tankNum;
                $existingRack  = $rackingByTank[$rackingKey] ?? null;

                if ($existingRack !== null) {
                    // Racking-derived candidate exists: reuse it, inject sim volume.
                    $cand                = $existingRack;
                    $cand['sim_vol_hl']  = $simVolHl;
                    // Ensure tank_fk_id + capacity are from ref tables (racking may have it already)
                    if ($tankFkId !== null) $cand['tank_fk_id'] = $tankFkId;
                    if ($capHl    !== null) $cand['capacity_hl'] = $capHl;
                } else {
                    // CCT-fermenting: no racking row — resolve from bd_brewing_brewday_v2.
                    $brewdayRow    = null;
                    $recipeIdFk    = null;
                    $nebBeer       = null;
                    $nebBatch      = null;
                    $contractBeer  = null;
                    $contractBatch = null;
                    $nebRecipeFk   = null;
                    $contractRecipeFk = null;

                    // Join on (cct number, batch) — reliable, no beer-string comparison.
                    $brewdayStmt->execute([$tankNum, $simBatch]);
                    $brewdayRow = $brewdayStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    if ($brewdayRow !== null) {
                        $recipeIdFk = $brewdayRow['recipe_id_fk'];  // may be null — keep it null
                        $beerName   = (string)($brewdayRow['beer'] ?? '');
                        // Deterministic neb/contract split: ' - ' in name = contract brewery name
                        if (str_contains($beerName, ' - ')) {
                            $contractBeer     = $beerName;
                            $contractBatch    = (string)$simBatch;
                            $contractRecipeFk = $recipeIdFk;
                        } else {
                            $nebBeer      = $beerName;
                            $nebBatch     = (string)$simBatch;
                            $nebRecipeFk  = $recipeIdFk;
                        }
                    }
                    // If brewday lookup failed, beer/batch/recipe stay null —
                    // the operator selects from the recipe dropdown on the form.

                    $cand = [
                        'racking_id'          => null,
                        'tank_type'           => $tankTypeUC,
                        'tank_number'         => $tankNum,
                        'tank_fk_id'          => $tankFkId,
                        'capacity_hl'         => $capHl,
                        'beer'                => $nebBeer ?? $contractBeer,
                        'batch_num'           => $nebBatch ?? $contractBatch,
                        'racked_vol_hl'       => null,
                        'racked_at'           => null,
                        'neb_beer'            => $nebBeer,
                        'neb_batch'           => $nebBatch,
                        'neb_recipe_id_fk'    => $nebRecipeFk,
                        'contract_beer'       => $contractBeer,
                        'contract_batch'      => $contractBatch,
                        'contract_recipe_id_fk' => $contractRecipeFk,
                        'sim_vol_hl'          => $simVolHl,
                    ];
                }

                $candidatesOverride[] = $cand;
            }
        }
    }

    // ── Clients for dropdown (decision 7) ─────────────────────────────────────
    $clients = $pdo->query(
        "SELECT id, name FROM ref_clients ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Active recipes for confirmation dropdown ──────────────────────────────
    $recipes = $pdo->query(
        "SELECT id, name FROM ref_recipes WHERE is_active = 1 ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Recent packaging events (web-sourced, last 10) ────────────────────────
    $recentStmt = $pdo->prepare(
        "SELECT id, submitted_at, event_date, neb_beer, neb_batch, contract_beer,
                contract_batch, run_type, row_origin, nebuleuse_format_suffix,
                prod_total_units, special_qty_units, vendable_hl, audit_flags, email
           FROM bd_packaging_v2
          WHERE audit_flags LIKE '%web_entry%'
            AND is_tombstoned = 0
          ORDER BY submitted_at DESC
          LIMIT 10"
    );
    $recentStmt->execute();
    $recentPackaging = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    $loadErr = null;
} catch (Throwable $e) {
    $candidates         = [];
    $candidatesOverride = [];
    $clients            = [];
    $recipes            = [];
    $recentPackaging    = [];
    // Safe fallback: on DB error, disallow override display to avoid confusion
    $canOverride        = false;
    $minDays            = PACKAGING_MIN_DAYS_FALLBACK;
    $loadErr = $e->getMessage();
}

$csrf          = csrf_token();
$active_module = 'packaging';

// Inject server-side data for JS
$candidatesJson         = json_encode($candidates,         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$candidatesOverrideJson = json_encode($candidatesOverride, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$clientsJson            = json_encode($clients,            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$runTypeLabelJson       = json_encode(RUN_TYPE_LABELS,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$suffixLabelJson        = json_encode(FORMAT_SUFFIXES,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisie Conditionnement — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/packaging-form.css?v=<?= @filemtime(__DIR__ . '/../css/packaging-form.css') ?: time() ?>">
</head>
<body class="home op-form-page op-form-packaging">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr): ?>
    <div class="db-flash db-flash--err">Erreur de chargement : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- Write-guard banner -->
  <?php if (!PACKAGING_WRITE_ENABLED): ?>
  <div class="pf-guard-banner">
    <strong>Mode test actif</strong> — Les saisies sont enregistrées comme brouillons uniquement.
    Aucune ligne <code>bd_packaging_v2</code> n'est créée tant que le mappage champs→colonnes
    n'est pas confirmé et que la migration 127 n'est pas appliquée.
  </div>
  <?php endif ?>

  <!-- Header -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Conditionnement · Packaging</div>
    <h1 class="op-form__title">Saisie <em>conditionnement</em></h1>
    <p class="op-form__sub">
      Sélectionner le lot à conditionner (BBT ou CCT disponible depuis
      <?= $minDays ?> jour<?= $minDays > 1 ? 's' : '' ?> après soutirage).
    </p>
  </div>

  <!-- ── FORM ─────────────────────────────────────────────────────────── -->
  <form id="packaging-form" method="post" action="/modules/form-packaging.php" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Hidden fields populated by JS from tank selection -->
    <input type="hidden" id="source_tank_type" name="source_tank_type" value="">
    <input type="hidden" id="source_tank_id"   name="source_tank_id"   value="">
    <input type="hidden" id="source_tank_num"  name="source_tank_num"  value="">
    <input type="hidden" id="neb_beer"           name="neb_beer"         value="">
    <input type="hidden" id="neb_batch"          name="neb_batch"        value="">
    <input type="hidden" id="contract_beer"      name="contract_beer"    value="">
    <input type="hidden" id="contract_batch"     name="contract_batch"   value="">
    <input type="hidden" id="recipe_id_fk"       name="recipe_id_fk"     value="">
    <!-- hors_process_flag: set by JS to 1 when override checkbox is checked.
         Server enforces role: if not manager/admin, this field is ignored. -->
    <input type="hidden" id="hors_process" name="hors_process" value="0">

    <!-- Warning panel (populated by form-framework.js) -->
    <div id="packaging-warnings" class="op-form__warnings" hidden aria-live="polite"></div>

    <!-- ── Section: Sélection lot source (BBT / CCT) ──────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— sélection lot source</div>

      <?php if ($canOverride): ?>
      <!-- Choix Hors Process — MANAGER / ADMIN ONLY.
           Operators never see this block (PHP-gated, not just CSS-hidden).
           Server will silently ignore hors_process=1 if the role gate fails anyway. -->
      <div class="pf-override-block" id="pf-override-block">
        <label class="pf-override-label">
          <input type="checkbox" id="pf-override-checkbox" class="pf-override-checkbox"
                 aria-describedby="pf-override-desc">
          <span class="pf-override-text">Choix Hors Process</span>
          <span class="pf-override-badge">Manager / Admin</span>
        </label>
        <p class="pf-override-desc" id="pf-override-desc">
          Bypasse le délai minimum (<?= $minDays ?> jour<?= $minDays > 1 ? 's' : '' ?> après soutirage).
          Affiche tous les lots actuellement en BBT/CCT indépendamment de leur date de soutirage.
          Toute saisie créée via cet override sera marquée <code>hors_process_flag = 1</code>
          dans <code>bd_packaging_v2</code>.
        </p>
        <div class="pf-override-reason-row" id="pf-override-reason-row" hidden>
          <label class="op-form__label pf-override-reason-label" for="hors_process_reason">
            Justification <span class="op-form__opt">(recommandé)</span>
          </label>
          <input id="hors_process_reason" name="hors_process_reason" type="text"
                 class="op-form__input pf-override-reason-input"
                 placeholder="ex. BBT5 prévu pour packaging urgent — lot approuvé par brasseur">
        </div>
      </div>
      <?php endif ?>

      <?php if (empty($candidates)): ?>
        <p class="op-form__muted">
          Aucun lot éligible (soutirage ≥ <?= $minDays ?> jour).
          Vérifier que les soutirages récents sont enregistrés dans Saisie Soutirage.
        </p>
      <?php else: ?>
      <div class="pf-tank-grid">
        <?php foreach ($candidates as $cand): ?>
          <?php
            $hasBeer   = ($cand['beer'] !== null && $cand['beer'] !== '');
            $beerName  = htmlspecialchars($cand['beer'] ?? '—');
            $batchNum  = htmlspecialchars($cand['batch_num'] ?? '—');
            $tankType  = htmlspecialchars($cand['tank_type'] ?? '?');
            $tankNum   = (int)$cand['tank_number'];
            $tankFkId  = (int)($cand['tank_fk_id'] ?? 0);
            $capHl     = $cand['capacity_hl'] !== null ? number_format((float)$cand['capacity_hl'], 0) : '—';
            // Headline: simulator remaining volume (authoritative, accounts for packaging depletion).
            // Secondary: original racked volume for operator context.
            $simVolHl  = isset($cand['sim_vol_hl'])
                            ? number_format((float)$cand['sim_vol_hl'], 1) . ' HL'
                            : '—';
            $rackedHl  = $cand['racked_vol_hl'] !== null
                            ? number_format((float)$cand['racked_vol_hl'], 1) . ' HL'
                            : '—';
            $rackedAt  = $cand['racked_at'] !== null ? htmlspecialchars($cand['racked_at']) : '—';
            $isCct     = $cand['tank_type'] === 'CCT';
          ?>
          <button type="button"
                  class="pf-tank-card<?= $hasBeer ? '' : ' pf-tank-card--empty' ?><?= $isCct ? ' pf-tank-card--cct' : '' ?>"
                  data-tank-type="<?= $tankType ?>"
                  data-tank-num="<?= $tankNum ?>"
                  data-tank-fk-id="<?= $tankFkId ?>"
                  data-neb-beer="<?= htmlspecialchars($cand['neb_beer'] ?? '') ?>"
                  data-neb-batch="<?= htmlspecialchars($cand['neb_batch'] ?? '') ?>"
                  data-contract-beer="<?= htmlspecialchars($cand['contract_beer'] ?? '') ?>"
                  data-contract-batch="<?= htmlspecialchars($cand['contract_batch'] ?? '') ?>"
                  data-recipe-id="<?= (int)($cand['neb_recipe_id_fk'] ?? $cand['contract_recipe_id_fk'] ?? 0) ?>"
                  data-vol="<?= htmlspecialchars((string)($cand['sim_vol_hl'] ?? '')) ?>"
                  <?= !$hasBeer ? 'disabled aria-disabled="true"' : '' ?>>
            <div class="pf-tank-card__label"><?= $tankType ?> <?= $tankNum ?></div>
            <div class="pf-tank-card__cap"><?= $capHl ?> HL</div>
            <?php if ($hasBeer): ?>
              <div class="pf-tank-card__beer"><?= $beerName ?></div>
              <div class="pf-tank-card__batch"><?= $batchNum ?></div>
              <div class="pf-tank-card__vol"><?= $simVolHl ?></div>
              <div class="pf-tank-card__vol-racked">raclé <?= $rackedHl ?></div>
              <div class="pf-tank-card__date">soutirée <?= $rackedAt ?></div>
            <?php else: ?>
              <div class="pf-tank-card__empty-label">— vide / inconnu —</div>
            <?php endif ?>
          </button>
        <?php endforeach ?>
      </div>

      <!-- Selected tank summary -->
      <div id="pf-selected-tank" class="pf-selected-tank" hidden>
        <span class="pf-selected-tank__label">Lot sélectionné :</span>
        <span id="pf-selected-summary" class="pf-selected-tank__summary"></span>
        <button type="button" id="pf-deselect" class="pf-selected-tank__clear">✕ changer</button>
      </div>
      <?php endif ?>
    </div><!-- card tank -->

    <!-- ── Section: Date et type de session ───────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— session de conditionnement</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="event_date">
            Date de conditionnement
            <span class="op-form__opt">(modifiable)</span>
          </label>
          <!-- Decision 3: defaults to today; operator can freely backdate via calendar picker -->
          <input id="event_date" name="event_date" type="date" class="op-form__input"
                 value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
          <span class="op-form__hint">
            Par défaut : aujourd'hui. Pour une saisie rétrospective, cliquer sur
            la date et sélectionner la date réelle du conditionnement.
          </span>
        </div>

      </div>
    </div><!-- card session -->

    <!-- ── Section: Formats de conditionnement (multi-format, decision 8) ── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— formats conditionnés</div>
      <p class="op-form__sub pf-formats-intro">
        Un run peut produire plusieurs formats pour le même lot (ex. Bouteille + Canette).
        Ajouter une ligne par format. Le <strong>format principal</strong> porte
        <em>prod_total_units</em>; les formats parallèles portent <em>qte_unites</em> (additif).
      </p>

      <!-- Format rows container (populated by JS) -->
      <div id="pf-formats-container">
        <!-- JS inserts format rows here -->
      </div>

      <button type="button" id="pf-add-format" class="op-form__btn op-form__btn--secondary pf-add-format-btn">
        + Ajouter un format parallèle
      </button>
    </div><!-- card formats -->

    <!-- ── Section: Mesures QA (partagées pour la session) ───────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— mesures QA cuve</div>
      <p class="op-form__hint pf-qa-hint">Mesures relevées sur la cuve source — communes à tous les formats du run.</p>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="tank_co2">CO₂ cuve <span class="op-form__unit">g/L</span></label>
          <input id="tank_co2" name="tank_co2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 4.2">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="tank_o2">O₂ cuve <span class="op-form__unit">ppb</span></label>
          <input id="tank_o2" name="tank_o2" type="text" inputmode="decimal"
                 class="op-form__input" placeholder="ex. 18">
        </div>

      </div>
    </div><!-- card QA -->

    <!-- ── Section: CIP (decision 4) ─────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— CIP (nettoyage en place)</div>
      <p class="op-form__hint">
        CIP de la cuve source (BBT/CCT) et de la ligne de conditionnement.
        Colonnes <code>cip_tank_*</code> et <code>cip_machines_*</code> —
        disponibles après migration 127.
      </p>

      <!-- CIP: source tank (BBT/CCT) -->
      <div class="pf-cip-group">
        <div class="pf-cip-group__label">Cuve source</div>
        <div class="op-form__grid--3 op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="cip_tank_done">CIP cuve effectué ?</label>
            <select id="cip_tank_done" name="cip_tank_done" class="op-form__select">
              <option value="">—</option>
              <?php foreach (CIP_YESNO as $v => $l): ?>
                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($l) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_tank_type">Type CIP cuve</label>
            <input id="cip_tank_type" name="cip_tank_type" type="text" class="op-form__input"
                   placeholder="ex. NaOH, PAA, Rinse">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_tank_date">Date CIP cuve</label>
            <input id="cip_tank_date" name="cip_tank_date" type="text" class="op-form__input"
                   placeholder="ex. 2026-05-20">
          </div>

        </div>
      </div>

      <!-- CIP: machines / packaging line -->
      <div class="pf-cip-group">
        <div class="pf-cip-group__label">Machines / ligne</div>
        <div class="op-form__grid--3 op-form__grid">

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machines_done">CIP machines effectué ?</label>
            <select id="cip_machines_done" name="cip_machines_done" class="op-form__select">
              <option value="">—</option>
              <?php foreach (CIP_YESNO as $v => $l): ?>
                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($l) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machines_type">Type CIP machines</label>
            <input id="cip_machines_type" name="cip_machines_type" type="text" class="op-form__input"
                   placeholder="ex. NaOH rinse, PAA">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip_machines_date">Date CIP machines</label>
            <input id="cip_machines_date" name="cip_machines_date" type="text" class="op-form__input"
                   placeholder="ex. 2026-05-20">
          </div>

        </div>
      </div>
    </div><!-- card CIP -->

    <!-- ── Section: Fûts / cuves de service (visible pour keg/cuv) ── -->
    <div class="op-form__card" id="pf-keg-section" hidden>
      <div class="op-form__card-title">— fûts / cuves de service</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="keg_client_delivered">Client fûts livrés</label>
          <input id="keg_client_delivered" name="keg_client_delivered" type="text"
                 class="op-form__input" placeholder="ex. Le Bourg">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="new_liner_client">Nouveau liner client ?</label>
          <select id="new_liner_client" name="new_liner_client" class="op-form__select">
            <option value="">—</option>
            <option value="1">Oui</option>
            <option value="0">Non</option>
          </select>
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="new_liner_transport">Nouveau liner transport ?</label>
          <select id="new_liner_transport" name="new_liner_transport" class="op-form__select">
            <option value="">—</option>
            <option value="1">Oui</option>
            <option value="0">Non</option>
          </select>
        </div>

      </div>
    </div><!-- card keg -->

    <!-- ── Section: White label ───────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— white label <span class="op-form__opt">(optionnel)</span></div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="is_white_label">White label ?</label>
          <select id="is_white_label" name="is_white_label" class="op-form__select">
            <option value="0">Non</option>
            <option value="1">Oui</option>
          </select>
        </div>

        <div class="op-form__field" id="pf-wl-name-field" hidden>
          <label class="op-form__label" for="white_label_name">Nom white label</label>
          <input id="white_label_name" name="white_label_name" type="text" class="op-form__input"
                 placeholder="ex. Monoprix Lager">
        </div>

      </div>
    </div><!-- card white label -->

    <!-- ── Section: Client (decision 7 — visible for contract / WL) ── -->
    <!-- Shown by JS when beer is contract-type OR is_white_label=1 -->
    <div class="op-form__card" id="pf-client-section" hidden>
      <div class="op-form__card-title">— client <span class="op-form__opt">(contrat / white label)</span></div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="client_fk">Client</label>
          <select id="client_fk" name="client_fk" class="op-form__select">
            <option value="">— sélectionner —</option>
            <?php foreach ($clients as $cl): ?>
              <option value="<?= (int)$cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
            <?php endforeach ?>
          </select>
          <?php if (empty($clients)): ?>
            <span class="op-form__hint pf-hint--warn">
              Aucun client dans ref_clients — lacune master-data. Saisir le nom dans Commentaires.
            </span>
          <?php endif ?>
        </div>

      </div>
    </div><!-- card client -->

    <!-- ── Section: DLC ──────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— DLC / BBD</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="neb_dlc">DLC Nébuleuse</label>
          <input id="neb_dlc" name="neb_dlc" type="text" class="op-form__input"
                 placeholder="ex. 2026-11">
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="contract_dlc">DLC contrat</label>
          <input id="contract_dlc" name="contract_dlc" type="text" class="op-form__input"
                 placeholder="ex. 2026-11">
        </div>

      </div>
    </div><!-- card DLC -->

    <!-- ── Section: Commentaires ────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— commentaires</div>
      <div class="op-form__grid--1 op-form__grid">
        <div class="op-form__field op-form__field--full">
          <label class="op-form__label" for="comments">Commentaires libres</label>
          <textarea id="comments" name="comments" class="op-form__textarea" rows="3"
                    placeholder="Incidents, observations qualité, conditions de conditionnement…"></textarea>
        </div>
      </div>
    </div>

    <!-- Submit bar -->
    <div class="op-form__submit-bar">
      <button type="button" class="op-form__btn op-form__btn--secondary"
              onclick="if(confirm('Effacer le brouillon ?')){localStorage.removeItem('packaging-draft');location.reload();}">
        Effacer brouillon
      </button>
      <button type="submit" id="pf-submit" class="op-form__btn op-form__btn--primary" disabled>
        <?= PACKAGING_WRITE_ENABLED ? 'Enregistrer le conditionnement →' : 'Enregistrer (mode test) →' ?>
      </button>
    </div>

  </form>

  <!-- ── Recent submissions ─────────────────────────────────────── -->
  <div class="op-form__recent">
    <div class="op-form__recent-title">— saisies récentes (web)</div>
    <?php if (empty($recentPackaging)): ?>
      <p class="op-form__muted" style="font-size:0.82rem;">Aucune saisie web pour le moment.</p>
    <?php else: ?>
      <table class="op-form__recent-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bière</th>
            <th>Brassin</th>
            <th>Format</th>
            <th>Origine</th>
            <th>Suffixe</th>
            <th>Unités</th>
            <th>Vol (HL)</th>
            <th>Opérateur</th>
            <th>Mode</th>
            <th>Override</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPackaging as $r): ?>
            <?php
              $beerLabel    = ($r['neb_beer'] ?? '') !== '' ? $r['neb_beer'] : ($r['contract_beer'] ?? '—');
              $batchLabel   = ($r['neb_batch'] ?? '') !== '' ? $r['neb_batch'] : ($r['contract_batch'] ?? '—');
              $isDraft      = str_contains($r['audit_flags'] ?? '', 'draft_mode');
              $isHorsProcess= str_contains($r['audit_flags'] ?? '', 'hors_process_override');
              $rt           = $r['run_type'] ?? '?';
              $rtLabel      = RUN_TYPE_LABELS[$rt] ?? $rt;
              $origin       = $r['row_origin'] ?? 'main';
              $suffix       = $r['nebuleuse_format_suffix'] ?? '';
              $units        = $origin === 'parallel' ? ($r['special_qty_units'] ?? null) : ($r['prod_total_units'] ?? null);
              $dateDisp     = $r['event_date'] ?? substr($r['submitted_at'] ?? '', 0, 10);
              $rowClass     = trim(($isDraft ? 'pf-row--draft' : '') . ' ' . ($isHorsProcess ? 'pf-row--hors-process' : ''));
            ?>
            <tr<?= $rowClass !== '' ? ' class="' . $rowClass . '"' : '' ?>>
              <td class="op-form__mono"><?= htmlspecialchars($dateDisp) ?></td>
              <td><?= htmlspecialchars($beerLabel) ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($batchLabel) ?></td>
              <td><?= htmlspecialchars($rtLabel) ?></td>
              <td><span class="pf-origin-badge pf-origin-badge--<?= htmlspecialchars($origin) ?>"><?= htmlspecialchars($origin) ?></span></td>
              <td class="op-form__mono"><?= htmlspecialchars($suffix !== '' ? $suffix : '—') ?></td>
              <td class="op-form__mono"><?= $units !== null ? (int)$units : '—' ?></td>
              <td class="op-form__mono"><?= $r['vendable_hl'] !== null ? htmlspecialchars($r['vendable_hl']) : '—' ?></td>
              <td class="op-form__mono"><?= htmlspecialchars($r['email'] ?? '') ?></td>
              <td><span class="pf-mode-badge pf-mode-badge--<?= $isDraft ? 'draft' : 'live' ?>"><?= $isDraft ? 'test' : 'live' ?></span></td>
              <td>
                <?php if ($isHorsProcess): ?>
                  <span class="pf-hp-badge" title="Saisie créée via override Choix Hors Process">HORS PROCESS</span>
                <?php else: ?>
                  <span class="pf-hp-badge pf-hp-badge--normal">—</span>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
  </div>

</main>

<!-- ── Inject server-side data for JS ────────────────────────────────────── -->
<script>
window.PF_CANDIDATES          = <?= $candidatesJson ?>;
window.PF_CANDIDATES_OVERRIDE = <?= $candidatesOverrideJson ?>;
window.PF_CAN_OVERRIDE        = <?= $canOverride ? 'true' : 'false' ?>;
window.PF_CLIENTS             = <?= $clientsJson ?>;
window.RUN_TYPE_LABELS        = <?= $runTypeLabelJson ?>;
window.FORMAT_SUFFIXES        = <?= $suffixLabelJson ?>;
window.MIN_DAYS_AFTER_RACKING = <?= $minDays ?>;
</script>

<script src="/js/form-framework.js?v=<?= @filemtime(__DIR__ . '/../js/form-framework.js') ?: time() ?>" defer></script>
<script src="/js/packaging-form.js?v=<?= @filemtime(__DIR__ . '/../js/packaging-form.js') ?: time() ?>" defer></script>

</body>
</html>

<?php
/*
 * ════════════════════════════════════════════════════════════════════════════
 * FIELD → bd_packaging_v2 COLUMN MAPPING (operator confirmation)
 * Status: DRAFT — awaiting migration 127 + operator sign-off before live writes.
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Form field / source         → bd_packaging_v2 column          Notes
 * ─────────────────────────── ─────────────────────────────────  ──────────────
 * source_tank_type (hidden)   → source_tank_type (ENUM)          NEW col (mig 127)
 * source_tank_id (hidden)     → bbt_source_fk | cct_source_fk    NEW cols (mig 127)
 * event_date                  → event_date                        NEW col (mig 127)
 * neb_beer (hidden)           → neb_beer
 * neb_batch (hidden)          → neb_batch
 * neb_dlc                     → neb_dlc
 * contract_beer (hidden)      → contract_beer
 * contract_batch (hidden)     → contract_batch
 * recipe_id_fk (hidden)       → recipe_id_fk
 * formats[N][run_type]        → run_type                          ENUM bot/can/can33/keg/cuv
 * formats[N][row_origin]      → row_origin                        ENUM main/parallel
 * formats[N][format_suffix]   → nebuleuse_format_suffix           Part of natural key
 * formats[N][prod_total_units]→ prod_total_units                  Main row only
 * formats[N][qte_unites]      → special_qty_units                 Parallel rows only (ADD)
 * formats[N][vendable_hl]     → vendable_hl
 * formats[N][unsaleable_units]→ unsaleable_units
 * formats[N][qa_analyses_units]→qa_analyses_units
 * formats[N][qa_library_units]→ qa_library_units
 * formats[N][loss_*_units]    → loss_*_units                      Per-type losses (decision 6)
 * formats[N][loss_liquid_*]   → loss_liquid_other_units
 * tank_co2                    → tank_co2                          Shared across all format rows
 * tank_o2                     → tank_o2
 * cip_tank_done               → cip_tank_done                     NEW col (mig 127)
 * cip_tank_type               → cip_tank_type                     NEW col (mig 127)
 * cip_tank_date               → cip_tank_date                     NEW col (mig 127)
 * cip_machines_done           → cip_machines_done                 NEW col (mig 127)
 * cip_machines_type           → cip_machines_type                 NEW col (mig 127)
 * cip_machines_date           → cip_machines_date                 NEW col (mig 127)
 * hors_process (hidden)       → hors_process_flag (TINYINT)       NEW col (mig 128) manager/admin only
 * hors_process_reason         → hors_process_reason (VARCHAR 255) NEW col (mig 128) optional justification
 * keg_client_delivered        → keg_client_delivered
 * new_liner_client            → new_liner_client (TINYINT bool)
 * new_liner_transport         → new_liner_transport (TINYINT bool)
 * is_white_label              → is_white_label (TINYINT bool)
 * white_label_name            → white_label_name
 * client_fk                   → client_fk (FK ref_clients.id)    Dropdown from ref_clients
 * comments                    → comments                          Main row only
 *
 * ── DECISIONS RESOLVED ────────────────────────────────────────────────────────
 * Q1 bbt_source_fk: YES — added as source_tank_type ENUM + bbt_source_fk + cct_source_fk
 *    with ENFORCED CHECK (mig 127). FK to ref_bbt.id / ref_cct.id (both INT UNSIGNED).
 * Q2 event_date: YES — added as DATE NULL (mig 127). Defaults to today in form.
 * Q3 client: dropdown from ref_clients → client_fk INT. Master-data gap flagged if empty.
 * Q4 nebuleuse_format_suffix: YES — multi-format UI, one row per format (main + parallels).
 * Q5 selection_can_mi_id_fk / selection_bottle_mi_id_fk: SKIPPED (decision 5) — no
 *    consumable selection inputs. Derived from Salle des Machines / SKU_BOM.
 *
 * ── BEFORE ENABLING LIVE WRITES ───────────────────────────────────────────────
 * 1. Apply migration 127 (operator approval required).
 * 2. Apply migration 128 (operator approval required):
 *    - Creates commissioning_settings + seeds packaging.min_days_after_racking = 1
 *    - Adds hors_process_flag + hors_process_reason to bd_packaging_v2
 * 3. Verify bd_packaging_v2 SHOW COLUMNS includes:
 *    event_date, source_tank_type, bbt_source_fk, cct_source_fk, cip_tank_*,
 *    cip_machines_*, hors_process_flag, hors_process_reason.
 * 4. In the live write path ($row array), ensure all new columns are included
 *    (hors_process_flag/reason are included — search 'hors_process' in the array above).
 * 5. Confirm candidate query returns expected lots (normal and override).
 * 6. Set PACKAGING_WRITE_ENABLED = true.
 * ════════════════════════════════════════════════════════════════════════════
 */
?>
