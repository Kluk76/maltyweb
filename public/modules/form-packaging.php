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
 *            BBT or CCT destinations) + ref_packaging_clients + ref data
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
require_once __DIR__ . '/../../app/cip-events.php';

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

// ── SKU resolution ────────────────────────────────────────────────────────────
/**
 * Resolves sku_id_fk for a packaging row at form-submit time.
 *
 * Mirrors the Python ingest's three-layer SKU resolution:
 *   1. Literal raw SKU text (neb_beer / contract_beer) → ref_skus.sku_code.
 *      This is the primary path for all normal and white-label rows because
 *      the form field already carries the SKU code as entered by the operator.
 *   2. When $isWhiteLabel && $whiteLabelSkuCode is set (future explicit WL path):
 *      explicit literal lookup before falling through — same mechanism, earlier check.
 *   3. (recipe_id, format_code) JOIN: fallback when literal lookup found nothing
 *      and format_suffix is non-empty. NULL/empty suffix is skipped (mirrors Python:
 *      `sku_map.get((recipe_id, format_suffix))` only when format_suffix is not None).
 *
 * Static array caches both lookup paths within a single POST so repeated
 * (recipe_id, format_code) pairs across parallel rows hit MySQL only once.
 */
function resolve_packaging_sku_id(
    PDO $pdo,
    ?string $rawSkuText,
    ?int $recipeIdFk,
    ?string $formatSuffix,
    bool $isWhiteLabel,
    ?string $whiteLabelSkuCode = null
): ?int {
    static $codeCache  = [];   // UPPER(sku_code) → id
    static $recipeCache = [];  // "{recipe_id}:{format_code}" → id

    // Step 1: explicit white-label SKU code (future path — form doesn't yet collect this,
    // but the helper accepts it so the function signature is complete).
    if ($isWhiteLabel && $whiteLabelSkuCode !== null && $whiteLabelSkuCode !== '') {
        $key = strtoupper($whiteLabelSkuCode);
        if (!array_key_exists($key, $codeCache)) {
            $st = $pdo->prepare(
                'SELECT id FROM ref_skus WHERE UPPER(sku_code) = ? LIMIT 1'
            );
            $st->execute([$key]);
            $codeCache[$key] = ($row = $st->fetchColumn()) !== false ? (int)$row : null;
        }
        if ($codeCache[$key] !== null) return $codeCache[$key];
    }

    // Step 2: literal raw SKU text (neb_beer / contract_beer IS the SKU code in this form).
    if ($rawSkuText !== null && $rawSkuText !== '') {
        $key = strtoupper($rawSkuText);
        if (!array_key_exists($key, $codeCache)) {
            $st = $pdo->prepare(
                'SELECT id FROM ref_skus WHERE UPPER(sku_code) = ? LIMIT 1'
            );
            $st->execute([$key]);
            $codeCache[$key] = ($row = $st->fetchColumn()) !== false ? (int)$row : null;
        }
        if ($codeCache[$key] !== null) return $codeCache[$key];
    }

    // Step 3: (recipe_id, format_code) JOIN — only when both are present.
    // NULL/empty format_suffix means no-suffix rows (BU/F/V/CU/33C via literal text only).
    if ($recipeIdFk !== null && $formatSuffix !== null && $formatSuffix !== '') {
        $cacheKey = "{$recipeIdFk}:{$formatSuffix}";
        if (!array_key_exists($cacheKey, $recipeCache)) {
            $st = $pdo->prepare(
                'SELECT s.id
                   FROM ref_skus s
                   JOIN ref_packaging_formats f ON f.id = s.format_id
                  WHERE s.recipe_id = ?
                    AND f.format_code = ?
                    AND s.is_active = 1
                  LIMIT 1'
            );
            $st->execute([$recipeIdFk, $formatSuffix]);
            $recipeCache[$cacheKey] = ($row = $st->fetchColumn()) !== false ? (int)$row : null;
        }
        if ($recipeCache[$cacheKey] !== null) return $recipeCache[$cacheKey];
    }

    return null;
}

// ── vendable_hl compute ───────────────────────────────────────────────────────
/**
 * Computes vendable_hl, beer_tax_base_hl, and loss_kpi_hl server-side,
 * mirroring v_bd_packaging_v2_vendable (mig 231).
 *
 * Run-type-aware formulas. c(x) = COALESCE(x, 0).
 *
 * BOTTLE/CAN (run_type ∈ bot, can, can33):
 *   vendable_units = prod + special_eff
 *                  − c(unsaleable) − c(loss_uncapped)
 *                  − c(loss_half_filled) * 0.5
 *                  − c(qa_library) − c(qa_analyses)
 *                  // material scraps do NOT decrement vendable_units
 *   vendable_hl    = (vendable_units / units_per_pack) * hl_per_unit
 *                  − c(loss_liquid_other_units) / 100
 *   beer_tax_base_hl = vendable_hl + c(unsaleable) / units_per_pack * hl_per_unit
 *   loss_kpi_hl    = (c(unsaleable) + c(loss_uncapped) + c(loss_half_filled)*0.5)
 *                    / units_per_pack * hl_per_unit
 *
 * KEG/CUV (run_type ∈ keg, cuv):
 *   vendable_units = prod + special_eff
 *   vendable_hl    = (vendable_units / units_per_pack) * hl_per_unit
 *                  − c(loss_keg_liquid_l) / 100
 *                  − c(taproom_keg_l) / 100
 *                  − c(loss_liquid_other_units) / 100
 *   beer_tax_base_hl = vendable_hl + c(taproom_keg_l) / 100
 *   loss_kpi_hl    = c(loss_keg_liquid_l) / 100
 *
 * SKU resolution paths:
 *   SKU present (sku_id_fk set)     → hl_per_unit / units_per_pack from ref_skus (existing path).
 *   No SKU + contract + run_type ∈ {keg, bot, can33} → run_type fallback (see $isContract).
 *     keg   → 0.20 HL/unit, units_per_pack = 1
 *     bot   → 0.0033 HL/unit, units_per_pack = 1
 *     can33 → 0.0033 HL/unit, units_per_pack = 1
 *   No SKU + contract + run_type = cuv → NULL (serving-tank volume is variable; refuse).
 *   No SKU + non-contract (Neb beer with broken SKU) → NULL + qc_flag='sku_meta_missing'.
 *
 * bcmath scale=6 throughout; vendable_hl rounded to DECIMAL(8,3).
 *
 * $row:        the row array as built by the format loop (same keys as bd_packaging_v2).
 * $skuMeta:    ['hl_per_unit' => string|null, 'units_per_pack' => string|null]
 * $runType:    the row's run_type string (bot|can|can33|keg|cuv)
 * $isContract: true when sku_id_fk is NULL and contract_beer is non-empty — enables
 *              run_type fallback instead of sku_meta_missing refusal.
 *
 * Returns: ['vendable_hl' => string|null, 'beer_tax_base_hl' => string|null,
 *           'loss_kpi_hl' => string|null, 'qc_flag' => string|null]
 */
function compute_packaging_vendable_hl(
    array  $row,
    array  $skuMeta,
    string $runType    = '',
    bool   $isContract = false
): array {
    $null4 = ['vendable_hl' => null, 'beer_tax_base_hl' => null, 'loss_kpi_hl' => null, 'qc_flag' => null];

    $hlPerUnit    = $skuMeta['hl_per_unit']    ?? null;
    $unitsPerPack = $skuMeta['units_per_pack'] ?? null;

    // When SKU meta is missing, check whether a run_type fallback applies.
    if ($hlPerUnit === null || $unitsPerPack === null
        || bccomp((string)$unitsPerPack, '0', 6) <= 0
    ) {
        if ($isContract) {
            // Contract beer (no SKU row): derive per-unit volume from run_type.
            // cuv is excluded — serving-tank volume is variable, no fixed per-unit volume.
            $contractVolMap = ['keg' => '0.200000', 'bot' => '0.003300', 'can33' => '0.003300'];
            if (!isset($contractVolMap[$runType])) {
                // cuv or unknown run_type: refuse (volume is not fixed).
                return array_merge($null4, ['qc_flag' => 'sku_meta_missing']);
            }
            $hlPerUnit    = $contractVolMap[$runType];
            $unitsPerPack = '1';
            // Falls through to the shared computation below.
        } else {
            // Neb beer with broken/missing SKU: refuse-don't-NULL.
            return array_merge($null4, ['qc_flag' => 'sku_meta_missing']);
        }
    }

    $scale = 6;
    $zero  = '0';

    // COALESCE(x, 0) helper
    $c = static fn(?string $v): string => ($v !== null && $v !== '') ? (string)$v : $zero;

    $prodTotal   = $c(isset($row['prod_total_units'])        ? (string)$row['prod_total_units']        : null);
    $specialQty  = $c(isset($row['special_qty_units'])       ? (string)$row['special_qty_units']       : null);
    $lLiquid     = $c(isset($row['loss_liquid_other_units']) ? (string)$row['loss_liquid_other_units'] : null);

    // Echo-row guard: when special_qty_units = prod_total_units, special contributes 0.
    $effectiveSpecial = (bccomp($specialQty, $prodTotal, $scale) === 0) ? $zero : $specialQty;

    $isKegOrCuv = in_array($runType, ['keg', 'cuv'], true);

    if (!$isKegOrCuv) {
        // ── BOTTLE / CAN ──────────────────────────────────────────────────────
        $unsaleable  = $c(isset($row['unsaleable_units'])        ? (string)$row['unsaleable_units']        : null);
        $lUncapped   = $c(isset($row['loss_uncapped_units'])     ? (string)$row['loss_uncapped_units']     : null);
        $lHalfFilled    = $c(isset($row['loss_half_filled_units'])    ? (string)$row['loss_half_filled_units']    : null);
        $lUntaxedFull   = $c(isset($row['loss_untaxed_full_units'])  ? (string)$row['loss_untaxed_full_units']  : null);
        $qaAnalyses     = $c(isset($row['qa_analyses_units'])        ? (string)$row['qa_analyses_units']        : null);
        $qaLibrary      = $c(isset($row['qa_library_units'])         ? (string)$row['qa_library_units']         : null);

        // half_filled counts at 0.5 volume (½ fill before seal)
        $halfFilledEff = bcdiv($lHalfFilled, '2', $scale);   // * 0.5

        $vendableUnits = $prodTotal;
        $vendableUnits = bcadd($vendableUnits, $effectiveSpecial, $scale);
        $vendableUnits = bcsub($vendableUnits, $unsaleable,    $scale);
        $vendableUnits = bcsub($vendableUnits, $lUncapped,     $scale);
        $vendableUnits = bcsub($vendableUnits, $halfFilledEff,  $scale);
        $vendableUnits = bcsub($vendableUnits, $lUntaxedFull,  $scale);
        $vendableUnits = bcsub($vendableUnits, $qaLibrary,     $scale);
        $vendableUnits = bcsub($vendableUnits, $qaAnalyses,    $scale);
        // Material scraps (loss_4pack_*, loss_wrap_*, loss_label_*, loss_crown_cork,
        // loss_can_lid, loss_keg_collar, loss_container_*) are NOT subtracted from
        // vendable_units — they track material waste, not filled-unit destruction.

        $perPack    = bcdiv($vendableUnits, (string)$unitsPerPack, $scale);
        $hlGross    = bcmul($perPack, (string)$hlPerUnit, $scale);
        $liqAdj     = bcdiv($lLiquid, '100', $scale);
        $vendableHl = bcsub($hlGross, $liqAdj, $scale);

        // beer_tax_base: vendable + invendable (taxed even if not sold)
        $unsaleableHl   = bcmul(bcdiv($unsaleable, (string)$unitsPerPack, $scale), (string)$hlPerUnit, $scale);
        $beerTaxBaseHl  = bcadd($vendableHl, $unsaleableHl, $scale);

        // loss_kpi_hl: beer-disposition losses only (material scraps excluded)
        // untaxed_full is a full unit, counts in full (no ×0.5 unlike half_filled)
        $lossUnits      = bcadd(bcadd($unsaleable, $lUncapped, $scale), $halfFilledEff, $scale);
        $lossUnits      = bcadd($lossUnits, $lUntaxedFull, $scale);
        $lossKpiHl      = bcmul(bcdiv($lossUnits, (string)$unitsPerPack, $scale), (string)$hlPerUnit, $scale);

    } else {
        // ── KEG / CUV ─────────────────────────────────────────────────────────
        $lKegLiquid = $c(isset($row['loss_keg_liquid_l']) ? (string)$row['loss_keg_liquid_l'] : null);
        $taproom    = $c(isset($row['taproom_keg_l'])     ? (string)$row['taproom_keg_l']     : null);

        $vendableUnits = $prodTotal;
        $vendableUnits = bcadd($vendableUnits, $effectiveSpecial, $scale);

        $perPack    = bcdiv($vendableUnits, (string)$unitsPerPack, $scale);
        $hlGross    = bcmul($perPack, (string)$hlPerUnit, $scale);
        $vendableHl = bcsub($hlGross, bcdiv($lKegLiquid, '100', $scale), $scale);
        $vendableHl = bcsub($vendableHl, bcdiv($taproom, '100', $scale), $scale);
        $vendableHl = bcsub($vendableHl, bcdiv($lLiquid, '100', $scale), $scale);

        // beer_tax_base: vendable + taproom (taproom is taxed)
        $beerTaxBaseHl = bcadd($vendableHl, bcdiv($taproom, '100', $scale), $scale);

        // loss_kpi: liquid lost from the vessel (not taproom — that's intentional fill)
        $lossKpiHl = bcdiv($lKegLiquid, '100', $scale);
    }

    $fmt = fn(string $v): string => number_format((float)$v, 3, '.', '');

    return [
        'vendable_hl'      => $fmt($vendableHl),
        'beer_tax_base_hl' => $fmt($beerTaxBaseHl),
        'loss_kpi_hl'      => $fmt($lossKpiHl),
        'qc_flag'          => null,
    ];
}

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

        // CO₂/O₂ session measurements — up to 20 pairs, POSTed as co2o2[N][co2|o2].
        // A reading is "present" when co2 OR o2 is non-empty; fully-blank rows are skipped.
        // reading_index = N + 1 (1-based, matches bd_packaging_co2o2_measures.reading_index).
        $co2o2Raw = $_POST['co2o2'] ?? [];
        $co2o2Pairs = [];  // [['co2_gl'=>float|null,'o2_ppb'=>float|null,'measured_at'=>null], ...]
        if (is_array($co2o2Raw)) {
            $pairIdx = 0;
            foreach ($co2o2Raw as $pairRaw) {
                if (!is_array($pairRaw)) continue;
                $co2Val = isset($pairRaw['co2']) && $pairRaw['co2'] !== '' ? (float)$pairRaw['co2'] : null;
                $o2Val  = isset($pairRaw['o2'])  && $pairRaw['o2']  !== '' ? (float)$pairRaw['o2']  : null;
                if ($co2Val === null && $o2Val === null) continue; // skip fully-blank rows
                // Time-of-reading removed from the form (operator request) — always null.
                $measAt = null;
                if ($pairIdx < 20) { // cap at 20
                    $co2o2Pairs[] = [
                        'reading_index' => $pairIdx + 1,
                        'co2_gl'        => $co2Val,
                        'o2_ppb'        => $o2Val,
                        'measured_at'   => $measAt,
                    ];
                    $pairIdx++;
                }
            }
        }

        // CIP events (decision 4) — parsed via shared infra; written to bd_cip_events after upsert.
        // Packaging uses filler+kze as the inline-combine pair (not centri+kze like racking).
        // combineAnchor='filler' enforces that KZE-alone is dropped server-side.
        $cipEvents = cip_parse_post($_POST, 'packaging', ['filler', 'kze'], 'filler');

        // White label
        $isWhiteLabel   = (post_int('is_white_label') === 1) ? 1 : 0;
        $whiteLabelName = post_str('white_label_name');

        // Comments / framework comment
        $comments  = post_str('comments');
        $fwComment = post_str('fw_comment');

        // DLC/BBD — single input, single column. The selected beer already tells us
        // Nébuleuse vs contract (no need for two questions/columns like BSF had), so the
        // one value is stored in the existing neb_dlc column for every row. There is no
        // contract_dlc column on bd_packaging_v2.
        $nebDlc = post_str('dlc');

        // ── Tank-reading override inputs (manager/admin only, mirrors hors_process) ──
        $tankReadingOverrideRequested = (isset($_POST['tank_reading_override']) && $_POST['tank_reading_override'] === '1');
        $tankReadingOverrideAllowed   = (is_admin($me) || is_manager($me));
        $tankReadingOverrideFlag      = ($tankReadingOverrideRequested && $tankReadingOverrideAllowed) ? 1 : 0;
        $tankReadingOverrideReason    = ($tankReadingOverrideFlag === 1)
            ? (isset($_POST['tank_reading_override_reason']) && $_POST['tank_reading_override_reason'] !== ''
                ? (string)$_POST['tank_reading_override_reason'] : null)
            : null;

        // ── 1b. Lot-day anchor resolution (mig 240) ──────────────────────────
        // Re-query authoritatively: never trust the client's co2o2_inherit_anchor_id alone.
        // An anchor = a main row, not tombstoned, not a reuse, owns its reads
        // (tank_reading_session_id_fk IS NULL), and has ≥1 co2o2 row.
        // NULL-safe lot-day key: Neb lane (recipe_id_fk + neb_batch) or
        // Contract lane (recipe_id_fk IS NULL + contract_beer + contract_batch).
        $lotDayAnchorId = null;
        {
            $anchorSql = "SELECT p.id
               FROM bd_packaging_v2 p
              WHERE p.row_origin = 'main'
                AND p.is_tombstoned = 0
                AND p.reuses_packaging_id_fk IS NULL
                AND p.tank_reading_session_id_fk IS NULL
                AND EXISTS (SELECT 1 FROM bd_packaging_co2o2_measures c WHERE c.packaging_id_fk = p.id)
                AND p.event_date <=> ?
                AND p.recipe_id_fk <=> ?
                AND (
                    (p.recipe_id_fk IS NOT NULL AND p.neb_batch <=> ?)
                    OR
                    (p.recipe_id_fk IS NULL AND p.contract_beer <=> ? AND p.contract_batch <=> ?)
                )
              ORDER BY p.event_date DESC, p.id DESC LIMIT 1";
            $anchorStmt = $pdo->prepare($anchorSql);
            $anchorStmt->execute([
                $eventDate,
                $recipeIdFk,
                $recipeIdFk !== null ? ($nebBatch !== '' ? $nebBatch : null) : null,
                $recipeIdFk === null ? ($contractBeer !== '' ? $contractBeer : null) : null,
                $recipeIdFk === null ? ($contractBatch !== '' ? $contractBatch : null) : null,
            ]);
            $anchorRow = $anchorStmt->fetchColumn();
            $lotDayAnchorId = ($anchorRow !== false) ? (int)$anchorRow : null;
        }

        // ── 1c. Determine tank-reading ownership for this session ─────────────
        // Case a: operator entered reads → session OWNS reads (fresh anchor or fork)
        // Case b: no reads AND anchor exists → INHERIT
        // Case c: no reads AND no anchor → GATE (first lot-day run, reads required)
        $operatorEnteredReads = !empty($co2o2Pairs);

        if ($operatorEnteredReads) {
            // Case a: own
            $effectiveAnchorMode = 'own';
            $sessionTankReadingFk = null; // main row gets NULL (owns reads)
        } elseif ($lotDayAnchorId !== null) {
            // Case b: inherit
            $effectiveAnchorMode = 'inherit';
            $sessionTankReadingFk = $lotDayAnchorId;
        } else {
            // Case c: gate — first run of lot-day with no reads → block unless overridden
            if ($tankReadingOverrideFlag === 1) {
                $effectiveAnchorMode = 'overridden';
                $sessionTankReadingFk = null; // no reads, no anchor
            } else {
                throw new RuntimeException(
                    "Relevés in-tank CO₂/O₂ requis pour la première saisie de ce lot-jour " .
                    "(toutes formes, y compris cuve). Saisir au moins une mesure, " .
                    "ou utiliser l'override manager/admin si les relevés ne sont pas disponibles."
                );
            }
        }

        // ── 2. QC flag ─────────────────────────────────────────────────────
        // Evaluate each CO₂/O₂ pair; take the worst flag across all pairs.
        // When no pairs were submitted, no CO₂/O₂ QC check is performed.
        $qcFlag = 'normal';
        foreach ($co2o2Pairs as $pair) {
            $pairMeasurements = array_filter([
                'bbt_co2' => $pair['co2_gl'],
                'bbt_o2'  => $pair['o2_ppb'],
            ], fn($v) => $v !== null);
            if (!empty($pairMeasurements)) {
                $pairFlag = bd_qc_flag($pairMeasurements);
                // Escalate: outlier > elevated > normal
                $order = ['normal' => 0, 'elevated' => 1, 'outlier' => 2];
                if (($order[$pairFlag] ?? 0) > ($order[$qcFlag] ?? 0)) {
                    $qcFlag = $pairFlag;
                }
            }
        }

        // ── 3. Build submitted_at ───────────────────────────────────────────
        $submittedAt = date('Y-m-d H:i:s.u');

        $baseAuditTokens = ['web_entry', 'write_guard_active'];
        if (!PACKAGING_WRITE_ENABLED) $baseAuditTokens[] = 'draft_mode';
        if ($qcFlag !== 'normal') $baseAuditTokens[] = "qc_{$qcFlag}";
        if ($horsProcessFlag === 1) $baseAuditTokens[] = 'hors_process_override';
        if ($tankReadingOverrideFlag === 1) $baseAuditTokens[] = 'tank_reading_override';
        if ($effectiveAnchorMode === 'inherit') $baseAuditTokens[] = 'co2o2_inherited';
        if ($effectiveAnchorMode === 'own') $baseAuditTokens[] = 'co2o2_own';

        // Per-row audit tokens are built inside the format loop below.
        // $baseAuditTokens is the session-level base; each row appends row-local tokens.

        // Sku meta cache (hl_per_unit, units_per_pack) keyed by sku_id.
        // Populated lazily inside the format loop; one SELECT per distinct sku_id.
        $skuMetaCache = [];

        // ── 4. Parse multi-format rows (decision 8) ─────────────────────────
        // The form submits N format lines. Each has:
        //   formats[N][run_type], formats[N][format_suffix], formats[N][row_origin] (main|parallel)
        //   formats[N][prod_total_units], formats[N][qte_unites]
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

        // Allowed liner-MI id set for server-side validation of the cuv liner dropdowns (mig 239).
        // Sourced canonically from ref_mi (subcategory 'Liner'); mirrors the GET-path build.
        // Built once per POST here, before the per-format loop that validates against it many times.
        $allowedLinerIds = [];
        foreach ($pdo->query(
            "SELECT id FROM ref_mi
              WHERE subcategory_id = (SELECT id FROM ref_mi_subcategories WHERE name = 'Liner' LIMIT 1)
                AND is_active = 1"
        ) as $lmRow) {
            $allowedLinerIds[(int)$lmRow['id']] = true;
        }

        // Build one row per format line
        $rows = [];
        foreach ($formatsRaw as $idx => $f) {
            $fRunType   = $f['run_type'] ?? '';
            $fOrigin    = $f['row_origin'] ?? 'main';

            // ── Cuve réutilisée (mig 237) ─────────────────────────────────────
            // A cuv row may carry a reuses_packaging_id_fk pointing at the source
            // cuv row.  When set: this row is a re-allocation — zero new volume.
            // Server validates: source row must exist, be run_type='cuv', not
            // tombstoned, not itself a reuse, and not already reused.
            // Only applicable when $fRunType = 'cuv'.
            $fReusesPackagingIdFk = null;
            if ($fRunType === 'cuv') {
                $rawReuse = isset($f['reuses_packaging_id_fk']) && $f['reuses_packaging_id_fk'] !== ''
                    ? (int)$f['reuses_packaging_id_fk'] : null;
                if ($rawReuse !== null && $rawReuse > 0) {
                    // Validate: source exists, is cuv, not tombstoned, not a reuse
                    $reuseCheckSt = $pdo->prepare(
                        'SELECT id FROM bd_packaging_v2
                          WHERE id = ?
                            AND run_type = "cuv"
                            AND is_tombstoned = 0
                            AND reuses_packaging_id_fk IS NULL
                          LIMIT 1'
                    );
                    $reuseCheckSt->execute([$rawReuse]);
                    if ($reuseCheckSt->fetchColumn() === false) {
                        throw new RuntimeException(
                            "Cuve réutilisée #{$rawReuse} introuvable, déjà marquée comme réutilisée, ou non éligible."
                        );
                    }
                    // Validate: source not already referenced (prevent double-reuse of same source)
                    $reuseDupSt = $pdo->prepare(
                        'SELECT COUNT(*) FROM bd_packaging_v2
                          WHERE reuses_packaging_id_fk = ?
                            AND is_tombstoned = 0'
                    );
                    $reuseDupSt->execute([$rawReuse]);
                    if ((int)$reuseDupSt->fetchColumn() > 0) {
                        throw new RuntimeException(
                            "La cuve #{$rawReuse} a déjà été réutilisée dans une autre saisie."
                        );
                    }
                    $fReusesPackagingIdFk = $rawReuse;
                }
            }
            $fSuffix    = $f['format_suffix'] ?? null;
            $fProdTotal = isset($f['prod_total_units']) && $f['prod_total_units'] !== ''
                            ? (int)$f['prod_total_units'] : null;
            $fQteUnites = isset($f['qte_unites']) && $f['qte_unites'] !== ''
                            ? (int)$f['qte_unites'] : null;
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
            // New disposition fields (mig 231)
            $fLossUncapped      = isset($f['loss_uncapped_units'])      && $f['loss_uncapped_units']      !== '' ? (int)$f['loss_uncapped_units']      : null;
            $fLossHalfFilled    = isset($f['loss_half_filled_units'])   && $f['loss_half_filled_units']   !== '' ? (int)$f['loss_half_filled_units']   : null;
            // New loss category (mig 233): full unit lost, NOT in beer-tax base
            $fLossUntaxedFull   = isset($f['loss_untaxed_full_units'])  && $f['loss_untaxed_full_units']  !== '' ? (int)$f['loss_untaxed_full_units']  : null;
            $fLossKegLiquid  = isset($f['loss_keg_liquid_l'])        && $f['loss_keg_liquid_l']     !== '' ? $f['loss_keg_liquid_l']         : null;
            $fTaproomKeg     = isset($f['taproom_keg_l'])            && $f['taproom_keg_l']         !== '' ? $f['taproom_keg_l']             : null;

            must_be_one_of("formats[{$idx}][run_type]", $fRunType, RUN_TYPES);
            if (!in_array($fOrigin, ['main', 'parallel'], true)) {
                throw new RuntimeException("row_origin invalide pour le format #{$idx}.");
            }

            // ── Per-row client / liner fields ─────────────────────────────────
            // Read with isset+'' check FIRST, then gate — never trust a stale hidden value.
            //
            // client_fk: cuv ONLY. NULLed for all other run_types.
            //   Previously wired to ref_clients (contract brewers) for contract/WL sessions —
            //   that was a mis-wire (mig 237). Now: ref_packaging_clients (venues/events),
            //   cuv rows only. Contract/WL rows do NOT get a client_fk.
            //
            // keg_client_delivered: no longer written from new submissions.
            //   Column kept in table as historical provenance. Always NULL for new rows.
            //   The FK-backed client_fk is the sole structured field going forward.
            //
            // new_liner_client + new_liner_transport bools: no longer read from new submissions.
            // new rows use liner_client_mi_id_fk / liner_transport_mi_id_fk (mig 239).
            $fClientFk = isset($f['client_fk']) && $f['client_fk'] !== ''
                           ? (int)$f['client_fk'] : null;

            // Liner MI FKs (mig 239): two-step read — isset+'' guard FIRST, then validate.
            $fLinerClientMi    = isset($f['liner_client_mi_id_fk'])    && $f['liner_client_mi_id_fk']    !== ''
                                   ? (int)$f['liner_client_mi_id_fk']    : null;
            $fLinerTransportMi = isset($f['liner_transport_mi_id_fk']) && $f['liner_transport_mi_id_fk'] !== ''
                                   ? (int)$f['liner_transport_mi_id_fk'] : null;
            // Defense-in-depth: reject ids not in the canonical liner-MI set (stale form value).
            if ($fLinerClientMi !== null && !isset($allowedLinerIds[$fLinerClientMi])) {
                $fLinerClientMi = null;
            }
            if ($fLinerTransportMi !== null && !isset($allowedLinerIds[$fLinerTransportMi])) {
                $fLinerTransportMi = null;
            }

            // Defense-in-depth: NULL client_fk unless cuv.
            if ($fRunType !== 'cuv') {
                $fClientFk = null;
            }
            // Liner fields: cuv only.
            if ($fRunType !== 'cuv') {
                $fLinerClientMi    = null;
                $fLinerTransportMi = null;
            }

            // ── Server-side sku_id_fk resolution ─────────────────────────────
            // Primary: literal neb_beer text → ref_skus.sku_code (mirrors Python ingest).
            // Fallback: (recipe_id, format_suffix=format_code) JOIN when suffix is set.
            // NULL/empty suffix rows rely on literal lookup only (no suffix = no (recipe,fmt) key).
            $rawSkuText = ($nebBeer !== '') ? $nebBeer : (($contractBeer !== '') ? $contractBeer : null);
            $skuIdFk    = resolve_packaging_sku_id(
                $pdo,
                $rawSkuText,
                $recipeIdFk,
                ($fSuffix !== '' && $fSuffix !== null) ? $fSuffix : null,
                (bool)$isWhiteLabel,
                null  // whiteLabelSkuCode: form doesn't collect this yet
            );

            // ── SKU meta (hl_per_unit, units_per_pack) ────────────────────────
            // Loaded once per distinct sku_id within this POST (cached in $skuMetaCache).
            $skuMeta = ['hl_per_unit' => null, 'units_per_pack' => null];
            if ($skuIdFk !== null) {
                if (!isset($skuMetaCache[$skuIdFk])) {
                    $st = $pdo->prepare(
                        'SELECT hl_per_unit, units_per_pack FROM ref_skus WHERE id = ? LIMIT 1'
                    );
                    $st->execute([$skuIdFk]);
                    $skuMetaCache[$skuIdFk] = $st->fetch(PDO::FETCH_ASSOC) ?: ['hl_per_unit' => null, 'units_per_pack' => null];
                }
                $skuMeta = $skuMetaCache[$skuIdFk];
            }

            // ── vendable_hl: always computed (no operator override) ──────────────
            // Cuve réutilisée: force vendable_hl = 0 (no new volume packaged).
            // Skip the normal computation entirely for reuse rows.
            if ($fReusesPackagingIdFk !== null) {
                $computed = ['vendable_hl' => '0.000', 'beer_tax_base_hl' => '0.000', 'loss_kpi_hl' => '0.000', 'qc_flag' => null];
                $finalVendableHl = '0.000';
            } else {
                // Build the partial row now so compute_packaging_vendable_hl() can read it.
                $partialRow = [
                    'prod_total_units'        => ($fOrigin === 'main') ? $fProdTotal : null,
                    'special_qty_units'       => ($fOrigin === 'parallel') ? $fQteUnites : null,
                    'qa_analyses_units'       => $fQaAnalyses,
                    'qa_library_units'        => $fQaLibrary,
                    'unsaleable_units'        => $fUnsaleable,
                    'loss_uncapped_units'      => $fLossUncapped,
                    'loss_half_filled_units'  => $fLossHalfFilled,
                    'loss_untaxed_full_units' => $fLossUntaxedFull,
                    'loss_keg_liquid_l'       => $fLossKegLiquid,
                    'taproom_keg_l'           => $fTaproomKeg,
                    'loss_liquid_other_units' => $fLossLiquid,
                ];
                // $isContract: true when no SKU resolved AND this is a contract beer.
                // Enables the run_type volume fallback instead of sku_meta_missing refusal.
                $isContractRow = ($skuIdFk === null && $contractBeer !== '');
                $computed = compute_packaging_vendable_hl($partialRow, $skuMeta, $fRunType, $isContractRow);
                $finalVendableHl = $computed['vendable_hl'];
            }

            // Per-row audit tokens (session base + row-local additions).
            $rowAuditTokens = $baseAuditTokens;

            if ($fReusesPackagingIdFk !== null) {
                $rowAuditTokens[] = 'cuv_reuse_no_deplete';
            }

            if ($computed['qc_flag'] !== null) {
                $rowAuditTokens[] = $computed['qc_flag'];
            }

            if ($skuIdFk === null && $fOrigin === 'main' && !(bool)$isWhiteLabel && $fReusesPackagingIdFk === null) {
                // White-label rows may legitimately have no matching sku_code yet.
                // Non-WL main rows with no sku_id are a real gap — flag for triage.
                $rowAuditTokens[] = 'sku_unresolved';
            }

            // ── Row hash: include session identity + format-specific fields ───
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
                    'audit_flags'            => implode(',', $rowAuditTokens),
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
                    // Server-resolved — never trust operator-entered sku_id_fk.
                    'sku_id_fk'              => $skuIdFk,
                    // main: prod_total; parallel: special_qty_units (qte_unites)
                    'prod_total_units'       => ($fOrigin === 'main') ? $fProdTotal : null,
                    'special_qty_units'      => ($fOrigin === 'parallel') ? $fQteUnites : null,
                    'vendable_hl'            => $finalVendableHl,
                    'beer_tax_base_hl'       => $computed['beer_tax_base_hl'],
                    'loss_kpi_hl'            => $computed['loss_kpi_hl'],
                    'unsaleable_units'       => $fUnsaleable,
                    'qa_analyses_units'      => $fQaAnalyses,
                    'qa_library_units'       => $fQaLibrary,
                    // New disposition fields (mig 231 + 233)
                    'loss_uncapped_units'     => $fLossUncapped,
                    'loss_half_filled_units'  => $fLossHalfFilled,
                    'loss_untaxed_full_units' => $fLossUntaxedFull,
                    'loss_keg_liquid_l'       => $fLossKegLiquid,
                    'taproom_keg_l'          => $fTaproomKeg,
                    // Per-type material scraps (decision 6 — stored, not subtracted from vendable)
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
                    // Cuv-only per-row fields (NULL for keg/bot/can/can33 — server-enforced above)
                    // keg_client_delivered intentionally not written — historical provenance column only.
                    // New rows use client_fk (→ ref_packaging_clients) exclusively.
                    // new_liner_client / new_liner_transport bools: explicitly NULL for all new rows.
                    // Legacy bool provenance is preserved in the column; new rows use the FK below.
                    'new_liner_client'          => null,
                    'new_liner_transport'       => null,
                    // Liner MI FKs (mig 239): the specific liner MI installed per event.
                    // NULL = no liner selected (operator left dropdown on "— aucun —").
                    'liner_client_mi_id_fk'     => $fLinerClientMi,
                    'liner_transport_mi_id_fk'  => $fLinerTransportMi,
                    // White label
                    'is_white_label'         => $isWhiteLabel,
                    'white_label_name'       => $whiteLabelName,
                    // Client FK — per-row for cuv; NULL for all other run_types (enforced above)
                    'client_fk'              => $fClientFk,
                    // Cuve réutilisée (mig 237): self-FK; NULL for normal rows
                    'reuses_packaging_id_fk' => $fReusesPackagingIdFk,
                    // CIP: flat cip_tank_*/cip_machines_* columns are intentionally NOT written
                    // from the web form — CIP now goes to bd_cip_events via cip_upsert().
                    // Historical flat columns remain in the table for legacy ingest rows only.
                    // CO₂/O₂ measurements are NOT on bd_packaging_v2 rows — they live in
                    // bd_packaging_co2o2_measures (mig 232) linked to the main row id.
                    // Written after the upsert loop via the dedicated persist block below.
                    // tank_reading_session_id_fk (mig 240):
                    //   main row: $sessionTankReadingFk (NULL=own/overridden; anchorId=inherit)
                    //   parallel rows: WILL BE SET in the write loop after main is inserted.
                    //   Sentinel '_needs_effective_anchor' for parallel rows is replaced in
                    //   the write loop once effectiveAnchorId is known.
                    'tank_reading_session_id_fk' => ($fOrigin === 'main')
                        ? $sessionTankReadingFk
                        : '_needs_effective_anchor',  // placeholder — replaced in write loop
                    // Comments (on main row only; parallels inherit the session)
                    'comments'               => ($fOrigin === 'main') ? $comments : null,
                    // Hors Process override (migration 128 — columns absent until applied)
                    'hors_process_flag'      => $horsProcessFlag,
                    'hors_process_reason'    => $horsProcessReason,
                ],
                'origin'             => $fOrigin,
                'computed_vendable'  => $finalVendableHl,
                'sku_id_fk'          => $skuIdFk,
                'format_suffix_used' => ($fSuffix !== '' && $fSuffix !== null) ? $fSuffix : null,
            ];
        }

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'row_origin', 'nebuleuse_format_suffix'];

        $beerLabel = $nebBeer !== '' ? $nebBeer : $contractBeer;

        $cipMeta = ['submitted_at' => $submittedAt, 'email' => $me['username']];

        if (PACKAGING_WRITE_ENABLED) {
            // ── Live write path ──────────────────────────────────────────────────
            // One transaction covers all format rows + CIP events for this session.
            // bd_upsert + log_revision + cip_upsert are all atomic — a partial write
            // would leave orphaned rows with no corresponding audit trail.
            $pdo->beginTransaction();
            try {
                $mainPackagingId = null;
                // effectiveAnchorId is computed after the main row is inserted:
                //   own       → mainRowId   (this session's main row owns reads)
                //   inherit   → $lotDayAnchorId  (another row owns reads)
                //   overridden→ null  (no reads, no anchor)
                // All parallel rows in this session receive this value as their
                // tank_reading_session_id_fk so COALESCE(tank_reading_session_id_fk,id)
                // resolves every row to the read-owning row.
                $effectiveAnchorId = null;
                $effectiveAnchorResolved = false;

                // Insert the main row FIRST so $mainPackagingId is known before any
                // parallel row resolves its tank_reading_session_id_fk in 'own' mode
                // (otherwise a parallel ordered ahead of the main locks effectiveAnchorId
                // to a still-null main id). PHP 8 usort is stable → parallels keep order.
                usort($rows, fn($a, $b) =>
                    (($a['origin'] ?? 'main') === 'main' ? 0 : 1)
                    <=> (($b['origin'] ?? 'main') === 'main' ? 0 : 1)
                );

                foreach ($rows as $rSpec) {
                    $safeRow          = $rSpec['row'];
                    $computedVendable = $rSpec['computed_vendable'];
                    $skuIdResolved    = $rSpec['sku_id_fk'];
                    $suffixUsed       = $rSpec['format_suffix_used'];

                    // Replace sentinel for parallel rows once effectiveAnchorId is known.
                    if ($safeRow['tank_reading_session_id_fk'] === '_needs_effective_anchor') {
                        // effectiveAnchorResolved is set after main row insert below.
                        // For inherit/overridden it's already resolvable from pre-insert values.
                        if (!$effectiveAnchorResolved) {
                            // inherit: $lotDayAnchorId; overridden: null
                            // own: $mainPackagingId (set in previous iteration)
                            $effectiveAnchorId = match($effectiveAnchorMode) {
                                'inherit'    => $lotDayAnchorId,
                                'overridden' => null,
                                default      => $mainPackagingId, // 'own'
                            };
                            $effectiveAnchorResolved = true;
                        }
                        $safeRow['tank_reading_session_id_fk'] = $effectiveAnchorId;
                    }

                    $result           = bd_upsert($pdo, PACKAGING_LIVE_TABLE, $safeRow, $nkCols);
                    $rowId            = (int)$result['id'];

                    // After main insert: compute effectiveAnchorId for subsequent parallel rows.
                    if ($rSpec['origin'] === 'main' && $mainPackagingId === null) {
                        $mainPackagingId = $rowId;
                        if (!$effectiveAnchorResolved) {
                            $effectiveAnchorId = match($effectiveAnchorMode) {
                                'own'        => $mainPackagingId,
                                'inherit'    => $lotDayAnchorId,
                                'overridden' => null,
                                default      => null,
                            };
                            $effectiveAnchorResolved = true;
                        }
                    }

                    // Audit comment encodes vendable_hl provenance and SKU resolution status.
                    $auditParts = [];
                    if ($rSpec['origin'] === 'parallel') $auditParts[] = '[parallel]';
                    if ($fwComment) $auditParts[] = $fwComment;
                    if ($computedVendable !== null) {
                        $auditParts[] = "vendable_hl computed: {$computedVendable} HL";
                    }
                    if (in_array('sku_meta_missing', explode(',', $safeRow['audit_flags']), true)) {
                        $auditParts[] = "vendable_hl NULL — SKU meta (hl_per_unit / units_per_pack) missing";
                    }
                    if (in_array('sku_unresolved', explode(',', $safeRow['audit_flags']), true)) {
                        $auditParts[] = "sku_id_fk unresolved (recipe={$recipeIdFk}, suffix=" . ($suffixUsed ?? 'null') . ")";
                    }
                    if ($effectiveAnchorMode === 'inherit') {
                        $auditParts[] = "co2o2 inherited from anchor #{$lotDayAnchorId}";
                    } elseif ($tankReadingOverrideFlag === 1) {
                        $auditParts[] = "tank_reading_override: " . ($tankReadingOverrideReason ?? 'no reason given');
                    }
                    $revisionComment = implode(' — ', array_filter($auditParts)) ?: null;

                    log_revision($pdo, $me, PACKAGING_LIVE_TABLE, $rowId, null, $safeRow, $qcFlag, $revisionComment);
                }
                // Write CIP events once, linked to the main packaging row.
                if ($mainPackagingId !== null) {
                    cip_upsert($pdo, 'packaging', $mainPackagingId, $cipEvents, $cipMeta);
                }

                // Write CO₂/O₂ pairs to bd_packaging_co2o2_measures (idempotent: delete-then-insert).
                // GATED: only write when this session OWNS reads (case a).
                // Inheriting (case b) and overridden (case c) sessions do NOT write co2o2 rows.
                if ($mainPackagingId !== null && $effectiveAnchorMode === 'own' && !empty($co2o2Pairs)) {
                    $pdo->prepare(
                        'DELETE FROM bd_packaging_co2o2_measures WHERE packaging_id_fk = ?'
                    )->execute([$mainPackagingId]);
                    $stCo2 = $pdo->prepare(
                        'INSERT INTO bd_packaging_co2o2_measures
                           (packaging_id_fk, reading_index, co2_gl, o2_ppb, measured_at, source)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($co2o2Pairs as $pair) {
                        $stCo2->execute([
                            $mainPackagingId,
                            $pair['reading_index'],
                            $pair['co2_gl'],
                            $pair['o2_ppb'],
                            $pair['measured_at'],
                            'web_entry',
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $txErr) {
                $pdo->rollBack();
                throw $txErr;
            }

            // ── Auto-link to mother shell (Phase 2-5, Atom 10) ───────────────────────
            // Opens or finds the op_sessions row for this packaging run, then links it
            // to the mother session for (recipe_id_fk, batch) if one exists.
            // Fires only after the packaging transaction commits successfully.
            // Fail-open: any error is logged and silently swallowed — the form submit
            // has already succeeded; auto-link failure must NOT block the operator.
            $pkgBatch = $nebBatch !== '' ? $nebBatch : $contractBatch;
            if ($recipeIdFk !== null && $pkgBatch !== '') {
                try {
                    require_once __DIR__ . '/../../app/sessions.php';
                    require_once __DIR__ . '/../../app/mother-shell.php';

                    // Open or look up an op_sessions row for this packaging run.
                    // Lookup: find the most recent open packaging session for
                    //         (recipe_id_fk, batch) — same vessel means same run.
                    $pkgSessWhere  = 'form_type = ? AND recipe_id_fk = ? AND batch = ? AND status = ?';
                    $pkgSessParams = ['packaging', $recipeIdFk, $pkgBatch, 'open'];
                    $pkgSessStmt   = $pdo->prepare(
                        "SELECT id FROM op_sessions WHERE {$pkgSessWhere} ORDER BY id DESC LIMIT 1"
                    );
                    $pkgSessStmt->execute($pkgSessParams);
                    $pkgSessRow = $pkgSessStmt->fetch(PDO::FETCH_ASSOC);

                    if ($pkgSessRow !== false) {
                        $pkgSessionId = (int)$pkgSessRow['id'];
                    } else {
                        // No open session — open one.
                        $pkgCtx = [
                            'form_type'    => 'packaging',
                            'recipe_id_fk' => $recipeIdFk,
                            'batch'        => $pkgBatch,
                        ];
                        if ($sourceTankNum !== null && $sourceTankNum > 0) {
                            // RULE-2 BLOCK fix: SESSION_VESSEL_KINDS is lowercase ('cct','bbt'...)
                            // session_open throws InvalidArgumentException on 'BBT'/'CCT'.
                            $pkgCtx['vessel_kind']   = strtolower($sourceTankType);
                            $pkgCtx['vessel_number'] = $sourceTankNum;
                        }
                        $pkgSessionId = session_open($pdo, $pkgCtx, (int)$me['id']);
                    }

                    // Link to mother (idempotent — no-op if already linked).
                    link_daily_to_mother($pdo, $pkgSessionId, $recipeIdFk, $pkgBatch);

                } catch (\Throwable $_linkErr) {
                    error_log('[mother-shell] auto-link (packaging recipe=' . $recipeIdFk
                        . ', batch=' . $pkgBatch . '): ' . $_linkErr->getMessage());
                }
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
            // CIP events are logged to audit_row_revisions only (no bd_cip_events write).
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
            // Log CIP event summary to audit only (no bd_cip_events write in draft mode)
            if (!empty($cipEvents)) {
                log_revision(
                    $pdo,
                    $me,
                    '[DRAFT] bd_cip_events',
                    0,
                    null,
                    ['cip_event_count' => count($cipEvents), 'events' => $cipEvents],
                    'normal',
                    '[DRAFT MODE] CIP events not written to bd_cip_events (no live packaging_id).'
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

    // ── Active SKUs per recipe (for the format mosaic) ───────────────────────
    // Collect all candidate recipe_ids (non-null) across normal + override lists.
    $allCandidateRecipeIds = [];
    foreach (array_merge($candidates, $candidatesOverride) as $cand) {
        $rid = (int)($cand['neb_recipe_id_fk'] ?? $cand['contract_recipe_id_fk'] ?? 0);
        if ($rid > 0) {
            $allCandidateRecipeIds[$rid] = true;
        }
    }
    $pfRecipeSkus       = [];  // recipe_id → [{sku_id, sku_code, format_id, format_code, display_name, run_type}]
    $pfRecipeUnassigned = [];  // recipe_id → [{sku_code}] (format_id IS NULL)
    if (!empty($allCandidateRecipeIds)) {
        $rids    = array_keys($allCandidateRecipeIds);
        $inMarks = implode(',', array_fill(0, count($rids), '?'));

        // Tiles: active, non-composite, run_type set
        $skuStmt = $pdo->prepare(
            "SELECT s.id AS sku_id, s.recipe_id, s.sku_code, s.format_id,
                    f.format_code, f.display_name, f.run_type
               FROM ref_skus s
               JOIN ref_packaging_formats f ON f.id = s.format_id
              WHERE s.is_active = 1
                AND f.is_composite = 0
                AND f.run_type <> ''
                AND s.recipe_id IN ({$inMarks})
              ORDER BY s.recipe_id, f.run_type, f.format_code"
        );
        $skuStmt->execute($rids);
        foreach ($skuStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['recipe_id'];
            if (!isset($pfRecipeSkus[$rid])) $pfRecipeSkus[$rid] = [];
            $pfRecipeSkus[$rid][] = [
                'sku_id'       => (int)$row['sku_id'],
                'sku_code'     => $row['sku_code'],
                'format_id'    => (int)$row['format_id'],
                'format_code'  => $row['format_code'],
                'display_name' => $row['display_name'],
                'run_type'     => $row['run_type'],
            ];
        }

        // Tray: active SKUs with NULL format_id for these recipes
        $unassignedStmt = $pdo->prepare(
            "SELECT id AS sku_id, recipe_id, sku_code
               FROM ref_skus
              WHERE is_active = 1
                AND format_id IS NULL
                AND recipe_id IN ({$inMarks})
              ORDER BY recipe_id, sku_code"
        );
        $unassignedStmt->execute($rids);
        foreach ($unassignedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['recipe_id'];
            if (!isset($pfRecipeUnassigned[$rid])) $pfRecipeUnassigned[$rid] = [];
            $pfRecipeUnassigned[$rid][] = ['sku_code' => $row['sku_code']];
        }
    }

    // ── Cuve réutilisée candidates (mig 237) ─────────────────────────────────
    // Cuves packaged in the last 14 days that:
    //   - Are not themselves reuse rows (reuses_packaging_id_fk IS NULL)
    //   - Have not already been reused (no existing row references them)
    //   - Are active (is_tombstoned = 0)
    // Emitted as window.PF_CUVE_CANDIDATES for the per-row reuse dropdown.
    // Falls back to [] if the column does not yet exist (migration not applied).
    $cuveCandidates = [];
    try {
        $cuveCandStmt = $pdo->prepare(
            "SELECT p.id,
                    COALESCE(NULLIF(p.neb_beer,''), p.contract_beer)   AS beer,
                    COALESCE(NULLIF(p.neb_batch,''), p.contract_batch) AS batch,
                    p.event_date,
                    p.prod_total_units,
                    p.vendable_hl,
                    p.client_fk,
                    p.keg_client_delivered
               FROM bd_packaging_v2 p
              WHERE p.run_type       = 'cuv'
                AND p.is_tombstoned  = 0
                AND p.event_date    >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                AND p.reuses_packaging_id_fk IS NULL
                AND p.id NOT IN (
                    SELECT reuses_packaging_id_fk
                      FROM bd_packaging_v2
                     WHERE reuses_packaging_id_fk IS NOT NULL
                )
              ORDER BY p.event_date DESC"
        );
        $cuveCandStmt->execute();
        $cuveCandidatesRaw = $cuveCandStmt->fetchAll(PDO::FETCH_ASSOC);
        // Augment with client name for display label
        $clientNameCache = [];
        foreach ($cuveCandidatesRaw as $cc) {
            $clientLabel = $cc['keg_client_delivered'] ?? null;
            if ($clientLabel === null && $cc['client_fk'] !== null) {
                $cfk = (int)$cc['client_fk'];
                if (!isset($clientNameCache[$cfk])) {
                    $cnSt = $pdo->prepare('SELECT name FROM ref_packaging_clients WHERE id = ? LIMIT 1');
                    $cnSt->execute([$cfk]);
                    $clientNameCache[$cfk] = (string)($cnSt->fetchColumn() ?: '');
                }
                $clientLabel = $clientNameCache[$cfk];
            }
            $cuveCandidates[] = [
                'id'          => (int)$cc['id'],
                'beer'        => $cc['beer'] ?? '',
                'batch'       => $cc['batch'] ?? '',
                'event_date'  => $cc['event_date'] ?? '',
                'vendable_hl' => $cc['vendable_hl'],
                'client_label'=> $clientLabel,
            ];
        }
    } catch (\Throwable $_cuvEx) {
        // Migration 237 not yet applied — column absent; degrade gracefully
        $cuveCandidates = [];
    }

    // ── Lot-day anchor preload for CO₂/O₂ inheritance (mig 240) ─────────────
    // An anchor = a main row that OWNS reads: row_origin='main', not tombstoned,
    // not a reuse, tank_reading_session_id_fk IS NULL (it owns the reads), and
    // has at least one co2o2 row. Window = last 60 days (keeps payload small).
    // The reads array is loaded via a single JOIN and folded per anchor in PHP.
    $tankAnchors = [];
    try {
        $anchorStmt = $pdo->prepare(
            "SELECT p.id, p.recipe_id_fk, p.neb_batch, p.contract_beer, p.contract_batch,
                    p.event_date,
                    c.co2_gl, c.o2_ppb, c.reading_index
               FROM bd_packaging_v2 p
               JOIN bd_packaging_co2o2_measures c ON c.packaging_id_fk = p.id
              WHERE p.row_origin = 'main'
                AND p.is_tombstoned = 0
                AND p.reuses_packaging_id_fk IS NULL
                AND p.tank_reading_session_id_fk IS NULL
                AND p.event_date >= (CURDATE() - INTERVAL 60 DAY)
              ORDER BY p.id ASC, c.reading_index ASC"
        );
        $anchorStmt->execute();
        $anchorRowsRaw = $anchorStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fold co2o2 rows into per-anchor arrays
        $anchorMap = [];
        foreach ($anchorRowsRaw as $ar) {
            $aid = (int)$ar['id'];
            if (!isset($anchorMap[$aid])) {
                $anchorMap[$aid] = [
                    'id'               => $aid,
                    'recipe_id_fk'     => $ar['recipe_id_fk'] !== null ? (int)$ar['recipe_id_fk'] : null,
                    'neb_batch'        => $ar['neb_batch'],
                    'contract_beer'    => $ar['contract_beer'],
                    'contract_batch'   => $ar['contract_batch'],
                    'event_date'       => $ar['event_date'],
                    'reads'            => [],
                ];
            }
            $anchorMap[$aid]['reads'][] = [
                'co2_gl'  => $ar['co2_gl'] !== null  ? (float)$ar['co2_gl']  : null,
                'o2_ppb'  => $ar['o2_ppb'] !== null  ? (float)$ar['o2_ppb']  : null,
            ];
        }
        $tankAnchors = array_values($anchorMap);
    } catch (\Throwable $_anchorEx) {
        // Column or table absent — degrade gracefully (pre-mig240 environments)
        $tankAnchors = [];
    }

    // ── Packaging clients for cuv dropdown ────────────────────────────────────
    // Source: ref_packaging_clients (venues/festivals), not ref_clients (contract brewers).
    $packagingClientsForForm = $pdo->query(
        "SELECT id, name FROM ref_packaging_clients WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Liner MIs for cuv liner dropdowns (mig 239) ───────────────────────────
    // Source: ref_mi rows whose subcategory is 'Liner'. Never hardcode ids or patterns.
    $linerMisForForm = $pdo->query(
        "SELECT id, mi_id, name
           FROM ref_mi
          WHERE subcategory_id = (SELECT id FROM ref_mi_subcategories WHERE name = 'Liner' LIMIT 1)
            AND is_active = 1
          ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    // Build an O(1) allowed-id set for server-side validation below.
    $allowedLinerIds = [];
    foreach ($linerMisForForm as $lm) {
        $allowedLinerIds[(int)$lm['id']] = true;
    }

    // Default liner from BOM slot (not hardcoded — read from ref_packaging_items).
    $linerDefaultStmt = $pdo->query(
        "SELECT default_mi_id_fk
           FROM ref_packaging_items
          WHERE slot_name = 'liner_client'
            AND mi_filter_pattern = 'PKG_LINER_%'
          LIMIT 1"
    );
    $linerDefaultRow  = $linerDefaultStmt ? $linerDefaultStmt->fetch(PDO::FETCH_ASSOC) : false;
    $linerDefaultMiId = ($linerDefaultRow && $linerDefaultRow['default_mi_id_fk'] !== null)
                          ? (int)$linerDefaultRow['default_mi_id_fk'] : 0;

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

    // ── CIP infra ─────────────────────────────────────────────────────────────
    $cipTypes = cip_type_options($pdo);

    $loadErr = null;
} catch (Throwable $e) {
    $candidates         = [];
    $candidatesOverride = [];
    $pfRecipeSkus       = [];
    $pfRecipeUnassigned = [];
    $clients            = [];
    $recipes            = [];
    $recentPackaging    = [];
    $cipTypes           = [];
    $cuveCandidates     = [];
    $tankAnchors        = [];
    $linerMisForForm    = [];
    $allowedLinerIds    = [];
    $linerDefaultMiId   = 0;
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
$pfRecipeSkusJson       = json_encode($pfRecipeSkus,       JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$pfRecipeUnassignedJson = json_encode($pfRecipeUnassigned, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$packagingClientsJson   = json_encode($packagingClientsForForm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$linerMisJson           = json_encode($linerMisForForm,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$runTypeLabelJson       = json_encode(RUN_TYPE_LABELS,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$suffixLabelJson        = json_encode(FORMAT_SUFFIXES,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$cuveCandidatesJson     = json_encode($cuveCandidates,     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$tankAnchorsJson        = json_encode($tankAnchors,        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// ── CIP partial config ────────────────────────────────────────────────────────
// Packaging CIPs only the filling machine (Soutireuse / filler) and optionally
// the KZE (flash pasteurizer) as an inline-combined pair. No centrifuge, no pump,
// no generic tank vessel — those are racking/brewing concerns.
//
// combine_anchor='filler' enforces the constraint that filler is always present:
// valid packaging CIP states are "Soutireuse alone" or "Soutireuse + KZE".
// KZE-alone is impossible — the partial renders no independent KZE row and the
// parser drops a forged partner-without-anchor submission.
$cipConfig = [
    'machines'            => ['filler', 'kze'],
    'show_inline_combine' => true,
    'combine_pair'        => ['filler', 'kze'],
    'combine_anchor'      => 'filler',
    'vessels'             => [],
    'cip_types'           => $cipTypes,
    'existing'            => null,  // new submission only (no edit path yet)
];
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
  <link rel="stylesheet" href="/css/cip-section.css?v=<?= @filemtime(__DIR__ . '/../css/cip-section.css') ?: time() ?>">
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

    <!-- ── Section: CIP (FIRST — shared partial, mirrors racking form) ─── -->
    <?php require __DIR__ . '/../../app/partials/cip-section.php' ?>

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

      <!-- SKU mosaic (populated by JS when a tank is selected) -->
      <div id="pf-sku-mosaic" hidden></div>

      <!-- Format rows container (populated by JS) -->
      <div id="pf-formats-container">
        <!-- JS inserts format rows here -->
      </div>

      <button type="button" id="pf-add-format" class="op-form__btn op-form__btn--secondary pf-add-format-btn">
        + Ajouter un format parallèle
      </button>
    </div><!-- card formats -->

    <!-- ── Section: Mesures CO₂/O₂ (partagées pour la session) ──── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— mesures CO₂ / O₂</div>
      <p class="op-form__hint pf-qa-hint">
        Mesures relevées sur la cuve source — communes à tous les formats du run.
        Jusqu'à 20 relevés par session.
      </p>

      <!-- Inherit banner (shown by JS when a matching lot-day anchor is found) -->
      <div id="pf-co2o2-inherit-banner" class="pf-co2o2-inherit-banner" hidden>
        <!-- content injected by JS -->
      </div>

      <!-- Hidden input: set by JS when the operator accepts inherited reads -->
      <input type="hidden" id="co2o2_inherit_anchor_id" name="co2o2_inherit_anchor_id" value="">

      <div id="pf-co2o2-list" class="pf-co2o2-list">
        <!-- Rows injected by JS (packaging-form.js: addCo2O2Row) -->
      </div>

      <button type="button" id="pf-add-co2o2"
              class="op-form__btn op-form__btn--secondary pf-co2o2-add-btn">
        + Ajouter une mesure
      </button>

      <?php if ($canOverride): ?>
      <!-- Tank-reading override (manager/admin only — mirrors hors_process pattern) -->
      <div class="pf-co2o2-override-block" id="pf-co2o2-override-block" hidden>
        <label class="pf-override-label">
          <input type="checkbox" id="pf-tank-reading-override-checkbox" class="pf-override-checkbox"
                 name="tank_reading_override" value="1"
                 aria-describedby="pf-tank-reading-override-desc">
          <span class="pf-override-text">Override relevés in-tank</span>
          <span class="pf-override-badge">Manager / Admin</span>
        </label>
        <p class="pf-override-desc" id="pf-tank-reading-override-desc">
          Bypasse l'obligation de saisir les relevés CO₂/O₂ pour la première saisie d'un lot-jour.
          La saisie sera marquée <code>tank_reading_override</code> dans <code>audit_flags</code>.
        </p>
        <div class="pf-override-reason-row" id="pf-tank-reading-override-reason-row" hidden>
          <label class="op-form__label pf-override-reason-label" for="tank_reading_override_reason">
            Justification <span class="op-form__opt">(recommandé)</span>
          </label>
          <input id="tank_reading_override_reason" name="tank_reading_override_reason" type="text"
                 class="op-form__input pf-override-reason-input"
                 placeholder="ex. Premières mesures prises par le labo — saisie différée">
        </div>
      </div>
      <?php endif ?>
    </div><!-- card CO2/O2 -->

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

    <!-- ── Section: DLC ──────────────────────────────────────────── -->
    <div class="op-form__card">
      <div class="op-form__card-title">— DLC / BBD</div>
      <div class="op-form__grid--3 op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="dlc">DLC / BBD</label>
          <input id="dlc" name="dlc" type="month" class="op-form__input">
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
window.PF_RECIPE_SKUS         = <?= $pfRecipeSkusJson ?>;
window.PF_RECIPE_UNASSIGNED   = <?= $pfRecipeUnassignedJson ?>;
window.PF_CAN_OVERRIDE        = <?= $canOverride ? 'true' : 'false' ?>;
window.PF_PACKAGING_CLIENTS   = <?= $packagingClientsJson ?>;
window.PF_LINER_MIS           = <?= $linerMisJson ?>;
window.PF_LINER_DEFAULT_MI    = <?= (int)$linerDefaultMiId ?>;
window.RUN_TYPE_LABELS        = <?= $runTypeLabelJson ?>;
window.FORMAT_SUFFIXES        = <?= $suffixLabelJson ?>;
window.MIN_DAYS_AFTER_RACKING = <?= $minDays ?>;
window.PF_CUVE_CANDIDATES     = <?= $cuveCandidatesJson ?>;
window.PF_TANK_ANCHORS        = <?= $tankAnchorsJson ?>;
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
 * dlc                         → neb_dlc (single DLC/BBD column; beer selection disambiguates neb/contract)
 * contract_beer (hidden)      → contract_beer
 * contract_batch (hidden)     → contract_batch
 * recipe_id_fk (hidden)       → recipe_id_fk
 * formats[N][run_type]        → run_type                          ENUM bot/can/can33/keg/cuv
 * formats[N][row_origin]      → row_origin                        ENUM main/parallel
 * formats[N][format_suffix]   → nebuleuse_format_suffix           Part of natural key
 * formats[N][prod_total_units]→ prod_total_units                  Main row only
 * formats[N][qte_unites]      → special_qty_units                 Parallel rows only (ADD)
 * formats[N][vendable_hl]     → vendable_hl                        ALWAYS COMPUTED (mig 231)
 * formats[N][unsaleable_units]→ unsaleable_units
 * formats[N][qa_analyses_units]→qa_analyses_units
 * formats[N][qa_library_units]→ qa_library_units
 * formats[N][loss_*_units]    → loss_*_units                      Per-type losses (decision 6)
 * formats[N][loss_liquid_*]   → loss_liquid_other_units
 * co2o2[N][co2]               → bd_packaging_co2o2_measures.co2_gl   Session-level (mig 232)
 * co2o2[N][o2]                → bd_packaging_co2o2_measures.o2_ppb   Up to 20 pairs per session
 * [CIP — not written to bd_packaging_v2]
 * cip_machine_centri/kze/pump → bd_cip_events (source_form='packaging', target_kind='machine')
 * cip_vessel_0 (tank)         → bd_cip_events (source_form='packaging', target_kind='vessel', target_code='tank')
 * cip_tank_done/type/date     → NOT written (flat columns kept in table for legacy ingest only)
 * cip_machines_done/type/date → NOT written (flat columns kept in table for legacy ingest only)
 * hors_process (hidden)       → hors_process_flag (TINYINT)       NEW col (mig 128) manager/admin only
 * hors_process_reason         → hors_process_reason (VARCHAR 255) NEW col (mig 128) optional justification
 * formats[N][client_fk]       → client_fk (FK ref_packaging_clients.id)  cuv only; NULLed otherwise
 * [keg_client_delivered not written — historical provenance column only; new rows always NULL]
 * [new_liner_client bool no longer written — always NULL for new rows; legacy provenance preserved]
 * [new_liner_transport bool no longer written — always NULL for new rows; legacy provenance preserved]
 * formats[N][liner_client_mi_id_fk]    → liner_client_mi_id_fk (INT UNSIGNED FK ref_mi)   cuv only; NULL = no liner
 * formats[N][liner_transport_mi_id_fk] → liner_transport_mi_id_fk (INT UNSIGNED FK ref_mi) cuv only; NULL = no liner
 * is_white_label              → is_white_label (TINYINT bool)
 * white_label_name            → white_label_name
 * comments                    → comments                          Main row only
 *
 * ── DECISIONS RESOLVED ────────────────────────────────────────────────────────
 * Q1 bbt_source_fk: YES — added as source_tank_type ENUM + bbt_source_fk + cct_source_fk
 *    with ENFORCED CHECK (mig 127). FK to ref_bbt.id / ref_cct.id (both INT UNSIGNED).
 * Q2 event_date: YES — added as DATE NULL (mig 127). Defaults to today in form.
 * Q3 client: dropdown from ref_packaging_clients (venues/festivals) → client_fk INT. cuv only.
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
 *    event_date, source_tank_type, bbt_source_fk, cct_source_fk,
 *    hors_process_flag, hors_process_reason.
 *    (cip_tank_* and cip_machines_* remain in the table but are no longer written by this form.)
 * 4. In the live write path ($row array), ensure all new columns are included
 *    (hors_process_flag/reason are included — search 'hors_process' in the array above).
 * 5. Confirm candidate query returns expected lots (normal and override).
 * 6. Set PACKAGING_WRITE_ENABLED = true.
 * ════════════════════════════════════════════════════════════════════════════
 */
?>
