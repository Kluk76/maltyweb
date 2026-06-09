<?php
declare(strict_types=1);
/**
 * app/loss-metrics.php — Per-batch total-loss and per-stage loss derivation (C7).
 *
 * Event-sourced read-only resolver. No stored columns, no new tables.
 * Formulas from racking-redesign-ws1-4.md §LAYER 5 R3/R4/R5 (operator-confirmed).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * FORMULAS (all percentages returned as numbers: 9.1 = 9.1%)
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   brewing_loss_pct       = (nominal_hl − cast_out_hl) / nominal_hl          [R5]
 *   rack_loss_pct          = (loss_source_hl + loss_dest_hl) / racked_vol_hl  [R4]
 *   packaging_loss_pct     = packaging_loss_hl / packaged_hl                  [R4]
 *     where packaging_loss_hl = pertes_packaging_loss_hl × n_packaging_events
 *   loss_vs_effectif_pct   = (cast_out_hl − packaged_hl) / cast_out_hl        [R3]
 *   loss_vs_nominal_pct    = (nominal_hl  − packaged_hl) / nominal_hl         [R3]
 *
 * All ÷0-guarded: returns null (not 0, not 100) when a denominator is zero/null.
 *
 * Negative brewing_loss_pct means the batch yielded MORE than nominal — this is
 * physically possible (efficiency bonus, high-adjunct recipe). Not a bug.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * COMPLETENESS GATE (avoids false total-loss alarms)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A batch whose BBT still holds volume is not yet fully packaged.
 * The total-loss flags (flags.total_effectif, flags.total_nominal) are raised
 * ONLY for complete=true batches. brewing_loss and rack_loss flags are
 * stage-local and computed regardless.
 *
 * Completeness proxy used (TankSimulator is avoided here to keep this resolver
 * lightweight; calling the full sim replay for every batch at KPI time would be
 * O(all-events × all-batches)):
 *
 *   complete = (packaged_hl > 0) AND
 *              (bbtsim_drawn_hl >= racked_vol_hl - BBT_EMPTY_THRESHOLD_HL)
 *
 *   where bbtsim_drawn_hl = SUM(vendable_hl) + SUM(loss_liquid_l / 100)
 *                           from bd_packaging for this batch
 *         BBT_EMPTY_THRESHOLD_HL = 2.5  (mirrors TankSimulator::BBT_EMPTY_THRESHOLD_HL)
 *
 * If racked_vol_hl is NULL (batch never racked, still in CCT), complete=false.
 *
 * NOTE: the richer completeness signal is TankSimulator::run() which tracks
 * the live BBT volume. If at some point this resolver is called from a context
 * that already has a sim result, pass the sim-derived residual volume to decide
 * completeness instead. This proxy is conservative (may call a batch incomplete
 * slightly longer than the sim would), which is the safe direction.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * NOMINAL VOLUME BASIS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * nominal_hl = ref_brewhouse_size.size_hl effective at brew_date × n_brews.
 * SCD2 resolution: the row whose effective_from <= brew_date AND
 * (effective_until IS NULL OR effective_until >= brew_date) is selected.
 * If no row covers the brew_date (e.g. historical brews predate the first seed
 * of 2026-05-21), the EARLIEST available row is used as the best-known value.
 * This is surfaced in nominal_basis: 'global brewhouse size N HL @ brew date'
 * (or 'global brewhouse size N HL [fallback: brew date predates SCD2 seed]').
 *
 * There is NO per-recipe nominal column on ref_recipes today. The global
 * brewhouse size is the only available basis. Per-recipe nominal is a
 * deliberate later phase.
 *
 * // TODO(wort-contract): exclude process_type='wort_contract' batches from
 * // total-loss computation once ref_recipes.process_type exists. Wort-sale
 * // batches never reach a BBT and have no packaged volume (total-loss
 * // is meaningless for them). Today no wort recipes exist so standard
 * // treatment applies. Mark this callsite when the column is added.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * PACKAGING DATA SOURCE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * bd_packaging.neb_beer stores SKU codes (e.g. "EMBF", "ZEPC").
 * The prefix (first 3-4 chars) is stripped and mapped to the simulator's
 * internal canonical beer name via the SKU_BEER_PREFIX_MAP below —
 * identical to TankSimulator::SKU_BEER_MAP so the join key matches.
 *
 * bd_packaging_v2 (web-form entries) stores full beer names (e.g. "Embuscade")
 * and is included via UNION. Both sources are deduped by (beer_canonical, batch)
 * — the same dedup logic as TankSimulator::loadPackagingEvents().
 *
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Public API:
 *   loss_thresholds(PDO $pdo): array
 *     → flat map of key_name → float (7 pertes keys + hardcoded defaults)
 *
 *   loss_metrics_for_batches(PDO $pdo, ?array $filter = null): array
 *     → list of per-batch rows (see return-shape in function docblock)
 *
 * Dependencies: app/db.php (maltytask_pdo() — caller passes $pdo).
 * No writes. No side effects. Pure compute-on-read.
 */

require_once __DIR__ . '/db.php';

// ─── Beer-name normalisation ─────────────────────────────────────────────────
//
// Mirrors TankSimulator::BEER_NAME_MAP (app/tank-simulator.php).
// Kept in sync — do NOT add an independent mapping here. If the sim map changes,
// update this constant too.
//
const LOSS_BEER_NAME_MAP = [
    'DGD'               => 'DrunkBeard - Galactic Drift',
    'QDG'               => 'Qrew - Diversion Gose',
    'docf'              => 'Docks - NEIPA',
    'docb'              => 'Docks - NEIPA',
    'Les Docks - NEIPA' => 'Docks - NEIPA',
    'Dockeuse'          => 'Docks - NEIPA',
    'Diversion Blanche' => 'Div.Blanche',
    'Diversion Gose'    => 'Div.Gose',
    'Diversion Panaché' => 'Div.Panaché',
    'MeltingPote - IPA' => 'MeltingPote - Cropette',
];

// Mirrors TankSimulator::SKU_BEER_MAP.
// Maps 3-or-4-char SKU prefix → canonical beer name as used by bd_racking_v2.neb_beer.
const LOSS_SKU_BEER_MAP = [
    'ZEP'  => 'Zepp',          'EMB'  => 'Embuscade',     'MOO'  => 'Moonshine',
    'STI'  => 'Stirling',      'SPY'  => 'Speakeasy',     'DIV'  => 'Diversion',
    'DOA'  => 'Double Oat',    'EST'  => 'Estafette',     'ALT'  => 'Alternative',
    'DIB'  => 'Div.Blanche',   'DIG'  => 'Div.Gose',      'DIP'  => 'Div.Panaché',
    'DGD'  => 'DrunkBeard - Galactic Drift',              'QDG'  => 'Qrew - Diversion Gose',
    'BLO'  => 'Blonde des Romands', 'BLA' => 'Div.Blanche', 'DOC' => 'Docks - NEIPA',
    'EPH1' => 'EPH1', 'EPH2' => 'EPH2', 'EPH3' => 'EPH3', 'EPH4' => 'EPH4',
];

// Dead-volume threshold (HL) below which the sim considers a BBT empty.
// Mirrors TankSimulator::BBT_EMPTY_THRESHOLD_HL.
const LOSS_BBT_EMPTY_THRESHOLD_HL = 2.5;

// Wort-sale beers never enter a BBT — their loss model is meaningless under
// the rack→pkg→completeness gate. Mirrors TankSimulator::WORT_SALES.
// TODO(wort-contract): replace with ref_recipes.process_type='wort_contract'
// when the column lands.
const LOSS_WORT_SALES = [
    'Chien Bleu - Moût Chaud',
    'Chien Bleu - Moût Froid',
];

/* ═══════════════════════════════════════════════════════════════════════════
   1. THRESHOLDS (commissioning_settings section='pertes')
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Read the seven pertes constants from commissioning_settings.
 *
 * Mirrors the pattern in qc_global_bands() (app/qc-thresholds.php):
 *   - one parameterised IN-clause SELECT WHERE section='pertes' AND is_active=1
 *   - PDO::FETCH_KEY_PAIR into a flat map
 *   - static memoized per request
 *   - hardcoded $defaults fallback when a key is absent
 *
 * Returned keys (all float):
 *   pertes_racking_loss_hl          — fixed process racking loss constant (HL)
 *   pertes_packaging_loss_hl        — fixed process packaging loss constant (HL/event)
 *   pertes_rack_warn_pct            — rack-stage warn palier (%)
 *   pertes_packaging_warn_pct       — packaging-stage warn palier (%)
 *   pertes_brewing_warn_pct         — brewing-stage warn palier (%)
 *   pertes_total_effectif_warn_pct  — total vs cast-out warn palier (%)
 *   pertes_total_nominal_warn_pct   — total vs nominal warn palier (%)
 *
 * @param PDO $pdo  Active DB connection.
 * @return array<string, float>
 */
function loss_thresholds(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $keys = [
        'pertes_racking_loss_hl',
        'pertes_packaging_loss_hl',
        'pertes_rack_warn_pct',
        'pertes_packaging_warn_pct',
        'pertes_brewing_warn_pct',
        'pertes_total_effectif_warn_pct',
        'pertes_total_nominal_warn_pct',
    ];

    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        "SELECT key_name, value_num
           FROM commissioning_settings
          WHERE section = 'pertes'
            AND key_name IN ({$placeholders})
            AND is_active = 1"
    );
    $stmt->execute($keys);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // key_name => value_num

    // Defaults match migration 184 seed values — used when a key is absent (first-run safety).
    $defaults = [
        'pertes_racking_loss_hl'         => 0.9,
        'pertes_packaging_loss_hl'       => 0.15,
        'pertes_rack_warn_pct'           => 2.0,
        'pertes_packaging_warn_pct'      => 1.0,
        'pertes_brewing_warn_pct'        => 5.0,
        'pertes_total_effectif_warn_pct' => 18.0,
        'pertes_total_nominal_warn_pct'  => 10.0,
    ];

    $cache = [];
    foreach ($keys as $k) {
        $cache[$k] = (float)($rows[$k] ?? $defaults[$k]);
    }

    return $cache;
}

/* ═══════════════════════════════════════════════════════════════════════════
   2. BEER-NAME HELPERS (mirrors TankSimulator — reuse, don't reinvent)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Apply the same beer-name normalisation the simulator uses for bd_brewing_gravity_v2
 * and bd_racking_v2 (both store full canonical names with some abbreviations).
 *
 * @internal
 */
function _loss_normalize_beer(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') return '';
    return LOSS_BEER_NAME_MAP[$trimmed] ?? $trimmed;
}

/**
 * Derive canonical beer name from an SKU code (e.g. "SPYF" → "Speakeasy",
 * "EMB4C" → "Embuscade", "EPH2B" → "EPH2"). Returns '' if no prefix matches.
 *
 * Mirrors TankSimulator::deriveBeerFromSku() — 4-char prefix tried first.
 *
 * @internal
 */
function _loss_derive_beer_from_sku(string $sku): string
{
    if ($sku === '') return '';
    $s = strtoupper($sku);
    if (preg_match('/^EPH[1-4]/', $s)) {
        return LOSS_SKU_BEER_MAP[substr($s, 0, 4)] ?? '';
    }
    return LOSS_SKU_BEER_MAP[substr($s, 0, 4)]
        ?? LOSS_SKU_BEER_MAP[substr($s, 0, 3)]
        ?? '';
}

/**
 * Strip the canonical beer prefix from an SKU code to get the format suffix.
 * "EPH1B" + "EPH1" → "B"; "MOOF" + "Moonshine" → "F"; "ZEPC" → "C"; "EMB4" → "4".
 * Falls back to a 3- or 4-char prefix probe against LOSS_SKU_BEER_MAP when
 * the canonical name isn't directly a prefix (most cases — the map is the
 * authoritative prefix → name mapping). Returns '' if no prefix matches.
 *
 * @internal
 */
function _loss_extract_format_suffix(string $rawSku, string $canonicalBeer): string
{
    if ($rawSku === '') return '';
    $s = strtoupper($rawSku);

    if (preg_match('/^EPH[1-4]/', $s)) {
        return substr($s, 4);
    }
    if (isset(LOSS_SKU_BEER_MAP[substr($s, 0, 4)])) {
        return substr($s, 4);
    }
    if (isset(LOSS_SKU_BEER_MAP[substr($s, 0, 3)])) {
        return substr($s, 3);
    }
    return '';
}

/* ═══════════════════════════════════════════════════════════════════════════
   3. MAIN RESOLVER
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Compute per-batch loss metrics for all batches (or a filtered subset).
 *
 * All DB reads are batched — at most one query per source table regardless of
 * how many batches are in scope. Result is memoized per request (static cache
 * keyed by the serialised $filter).
 *
 * @param PDO        $pdo     Active DB connection.
 * @param array|null $filter  Optional filter. Supported keys (all additive / combinable):
 *                             ['beer'  => string]               — restrict to one recipe
 *                             ['beer'  => string, 'batch' => string] — restrict to one batch
 *                             ['session_id' => int]             — restrict to the beer+batch linked
 *                               to a given op_sessions.id (via bd_racking_v2.session_id_fk).
 *                               If no bd_racking_v2 row is found for the session, returns [].
 *                               Additive with beer/batch keys (both applied when present).
 *                             ['view' => 'core'|'collab-eph'|'contract']
 *                               — restrict to beer family. Default: 'core'.
 *                               Beers absent from ref_beer_types default to 'core' (visible
 *                               by default; the normalization step maps e.g. "MeltingPote - IPA"
 *                               to its canonical ref_beer_types name before the filter).
 *                               Ignored when 'beer' or 'batch' keys restrict to a single batch.
 *
 * @return array<int, array{
 *   beer:                      string,
 *   batch:                     string,
 *   brew_date:                 string,            // 'YYYY-MM-DD' of first Cooling event
 *   cast_out_hl:               float|null,
 *   nominal_hl:                float|null,
 *   nominal_basis:             string,            // human note about the nominal derivation
 *   packaged_hl:               float|null,
 *   n_packaging_events:        int,
 *   complete:                  bool,              // false = still in tank, total-loss flags suppressed
 *   rack_loss_pct:             float|null,        // null when no racking data
 *   packaging_loss_pct:        float|null,        // null when packaged_hl = 0
 *   brewing_loss_pct:          float|null,        // null when nominal_hl = 0; can be negative (bonus)
 *   loss_vs_effectif_pct:      float|null,        // null when cast_out_hl = 0
 *   loss_vs_nominal_pct:       float|null,        // null when nominal_hl = 0
 *   bbt_vide_scrapped_hl:      float,             // phantom HL discarded via BBT-vide — 0.0 when none
 *   flags: array{
 *     rack:           bool,
 *     packaging:      bool,
 *     brewing:        bool,
 *     total_effectif: bool,    // always false when complete=false
 *     total_nominal:  bool,    // always false when complete=false
 *   },
 * }>
 */
function loss_metrics_for_batches(PDO $pdo, ?array $filter = null): array
{
    static $cache = [];
    $cacheKey = serialize($filter);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    // ── session_id filter: translate to beer+batch via bd_racking_v2 ──────────
    //
    // Additive: if 'session_id' is present, resolve the canonical (beer, batch)
    // pair from bd_racking_v2.session_id_fk, then merge into the existing filter
    // so _loss_load_cast_out and siblings pick it up normally.
    // Existing callers passing only 'beer'/'batch' are unaffected.
    if (isset($filter['session_id'])) {
        $sessId = (int)$filter['session_id'];
        if ($sessId > 0) {
            $rackRowStmt = $pdo->prepare(
                "SELECT COALESCE(NULLIF(neb_beer,''), contract_beer)   AS beer,
                        COALESCE(NULLIF(neb_batch,''), contract_batch) AS batch
                   FROM bd_racking_v2
                  WHERE session_id_fk = ? AND is_tombstoned = 0
                  ORDER BY id ASC
                  LIMIT 1"
            );
            $rackRowStmt->execute([$sessId]);
            $rackRow = $rackRowStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rackRow || ($rackRow['beer'] === null && $rackRow['batch'] === null)) {
                // No racking row linked to this session yet → empty result.
                $cache[$cacheKey] = [];
                return [];
            }

            $resolvedBeer  = _loss_normalize_beer((string)($rackRow['beer']  ?? ''));
            $resolvedBatch = (string)($rackRow['batch'] ?? '');

            // Merge — allow caller to further restrict if they supplied beer/batch too.
            if (!isset($filter['beer'])) {
                $filter['beer'] = $resolvedBeer;
            }
            if (!isset($filter['batch'])) {
                $filter['batch'] = $resolvedBatch;
            }
        }
        unset($filter['session_id']); // consumed — helpers do not know this key
    }

    $thresholds = loss_thresholds($pdo);

    // ── A. Cast-out per (beer, batch) from bd_brewing_gravity_v2 ─────────────
    //
    // Also captures n_brews (COUNT of Cooling events) and brew_date
    // (earliest Cooling submitted_at).
    //
    $castOutRows = _loss_load_cast_out($pdo, $filter);

    if (empty($castOutRows)) {
        $cache[$cacheKey] = [];
        return [];
    }

    // ── B/C/D. Nominal + racking + packaging loaders ──────────────────────────
    //
    $brewhouses = _loss_load_brewhouse_sizes($pdo);
    $nominalMap = _loss_build_nominal_map($castOutRows, $brewhouses);
    $rackMap    = _loss_load_rack_map($pdo, $filter);
    $pkgMap     = _loss_load_packaging_map($pdo, $filter);

    $result = [];
    foreach ($castOutRows as $row) {
        $beer      = $row['beer'];
        $batch     = $row['batch'];
        $key       = $beer . '|' . $batch;
        $castOut   = ($row['cast_out_hl'] !== null) ? (float)$row['cast_out_hl'] : null;
        $nBrews    = (int)$row['n_brews'];
        $brewDate  = $row['brew_date'];

        [$nominalHl, $nominalBasis] = $nominalMap[$key] ?? [null, 'no brewhouse size found'];

        $rackData  = $rackMap[$key] ?? null;
        $pkgData   = $pkgMap[$key] ?? ['packaged_hl' => null, 'n_events' => 0, 'total_drawn_hl' => 0.0];

        $packedHl     = $pkgData['packaged_hl'];
        $nPkgEvents   = $pkgData['n_events'];
        $totalDrawnHl = (float)$pkgData['total_drawn_hl'];

        // ── Completeness ────────────────────────────────────────────────────
        //
        // Proxy: batch is complete when the amount drawn from the BBT
        // (vendable_hl + loss_liquid_l) is close enough to what was racked in,
        // leaving at most BBT_EMPTY_THRESHOLD_HL (2.5 HL) in the tank.
        //
        $rackedVol = $rackData !== null ? (float)$rackData['racked_vol_hl'] : null;
        $complete  = _loss_is_complete($packedHl, $totalDrawnHl, $rackedVol);

        // ── Per-stage formulas (R4/R5) ───────────────────────────────────────
        //
        // All ÷0-guarded with null (NOT 0, NOT 100).

        // brewing_loss_pct: can be negative when cast_out > nominal (bonus — correct).
        $brewingLossPct = ($nominalHl !== null && $nominalHl > 0.0 && $castOut !== null)
            ? (($nominalHl - $castOut) / $nominalHl) * 100.0
            : null;

        // rack_loss_pct: excludes the 0.9 HL fixed process constant (R4).
        $rackLossPct = null;
        if ($rackData !== null && $rackData['racked_vol_hl'] > 0.0) {
            $rackLossPct = (((float)$rackData['loss_source_hl'] + (float)$rackData['loss_dest_hl'])
                            / (float)$rackData['racked_vol_hl']) * 100.0;
        }

        // packaging_loss_pct: fixed constant × n events as numerator.
        // Slot for future explicit packaging-loss input (add to numerator when available).
        $pkgLossHl = $thresholds['pertes_packaging_loss_hl'] * $nPkgEvents;
        // future: $pkgLossHl += explicit_packaging_loss_hl_from_form_when_available;
        $packagingLossPct = ($packedHl !== null && $packedHl > 0.0)
            ? ($pkgLossHl / $packedHl) * 100.0
            : null;

        // loss_vs_effectif: total loss relative to actual cast-out volume.
        $lossVsEffectifPct = ($castOut !== null && $castOut > 0.0 && $packedHl !== null)
            ? (($castOut - $packedHl) / $castOut) * 100.0
            : null;

        // loss_vs_nominal: total loss relative to nominal brewhouse volume.
        $lossVsNominalPct = ($nominalHl !== null && $nominalHl > 0.0 && $packedHl !== null)
            ? (($nominalHl - $packedHl) / $nominalHl) * 100.0
            : null;

        // ── Flags ────────────────────────────────────────────────────────────
        //
        // Total-loss flags suppressed for incomplete batches.
        // Stage-local flags (brewing, rack) fire regardless of completeness.
        //
        $warnRack      = $rackLossPct      !== null && $rackLossPct      > $thresholds['pertes_rack_warn_pct'];
        $warnPkg       = $packagingLossPct !== null && $packagingLossPct > $thresholds['pertes_packaging_warn_pct'];
        $warnBrewing   = $brewingLossPct   !== null && $brewingLossPct   > $thresholds['pertes_brewing_warn_pct'];

        $warnEffectif  = $complete && $lossVsEffectifPct !== null
                         && $lossVsEffectifPct > $thresholds['pertes_total_effectif_warn_pct'];
        $warnNominal   = $complete && $lossVsNominalPct  !== null
                         && $lossVsNominalPct  > $thresholds['pertes_total_nominal_warn_pct'];

        $result[] = [
            'beer'                  => $beer,
            'batch'                 => $batch,
            'brew_date'             => $brewDate,
            'cast_out_hl'           => $castOut,
            'nominal_hl'            => $nominalHl,
            'nominal_basis'         => $nominalBasis,
            'packaged_hl'           => $packedHl,
            'n_packaging_events'    => $nPkgEvents,
            'complete'              => $complete,
            'rack_loss_pct'         => $rackLossPct,
            'packaging_loss_pct'    => $packagingLossPct,
            'brewing_loss_pct'      => $brewingLossPct,
            'loss_vs_effectif_pct'  => $lossVsEffectifPct,
            'loss_vs_nominal_pct'   => $lossVsNominalPct,
            // Phantom HL discarded via BBT-vide override.  0.0 when none this batch.
            // ZERO CHF — not valued, NOT in rack_loss_pct, NOT in loss_vs_effectif.
            // Display-only cause surfaced separately in the Pertes report.
            'bbt_vide_scrapped_hl'  => $rackData !== null
                ? (float)($rackData['bbt_vide_scrapped_hl'] ?? 0.0)
                : 0.0,
            'flags'                 => [
                'rack'           => $warnRack,
                'packaging'      => $warnPkg,
                'brewing'        => $warnBrewing,
                'total_effectif' => $warnEffectif,
                'total_nominal'  => $warnNominal,
            ],
        ];
    }

    $cache[$cacheKey] = $result;
    return $result;
}

/* ═══════════════════════════════════════════════════════════════════════════
   4. INTERNAL HELPERS (prefixed _loss_ to avoid polluting the global namespace)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Load cast-out volume (SUM of Cooling final_volume) per (beer, batch).
 *
 * @internal
 * @return list<array{beer:string,batch:string,brew_date:string,n_brews:int,cast_out_hl:string|null}>
 */
function _loss_load_cast_out(PDO $pdo, ?array $filter): array
{
    $where  = ['g.event_type = \'Cooling\'', 'g.is_tombstoned = 0', 'g.final_volume > 0'];
    $params = [];

    if (isset($filter['beer'])) {
        $where[]  = 'g.beer = ?';
        $params[] = $filter['beer'];
    }
    if (isset($filter['batch'])) {
        $where[]  = 'g.batch = ?';
        $params[] = $filter['batch'];
    }

    // Brewed batches whose brew_date predates racking-form coverage will never
    // reach a valid racked_vol_hl. The 6-month rolling floor is the dominant
    // constraint today; GREATEST ensures we never regress if the racking floor
    // advances past 6 months in the future.
    $rackingFloor = _loss_racking_data_floor($pdo);
    $havingClause = '';
    if ($rackingFloor !== null) {
        // Compose both floors: racking-data coverage AND 6-month rolling window.
        $havingClause = 'HAVING brew_date >= GREATEST(?, (NOW() - INTERVAL 6 MONTH))';
        $params[] = $rackingFloor;
        error_log('[loss-metrics] _loss_load_cast_out floor: GREATEST(' . $rackingFloor . ', NOW()-6mo)');
    } else {
        $havingClause = 'HAVING brew_date >= (NOW() - INTERVAL 6 MONTH)';
        error_log('[loss-metrics] _loss_load_cast_out floor: NOW()-6mo (no racking floor)');
    }

    // View-filter join: restrict to the beer family selected via $filter['view'].
    // Applied only when not restricted to a single beer/batch (single-batch calls
    // bypass the family filter — the caller asked for an exact match).
    $viewJoin  = '';
    $viewWhere = '';
    $activeView = $filter['view'] ?? 'core';
    if (!isset($filter['beer']) && !isset($filter['batch'])) {
        $viewJoin = "LEFT JOIN ref_beer_types rbt
                        ON rbt.beer_name COLLATE utf8mb4_unicode_ci = g.beer COLLATE utf8mb4_unicode_ci";
        switch ($activeView) {
            case 'collab-eph':
                // EPH + CollabIn + CollabOut regardless of type
                $viewWhere = "AND (rbt.subtype IN ('EPH', 'CollabIn', 'CollabOut'))";
                break;
            case 'contract':
                $viewWhere = "AND (rbt.type = 'Contract')";
                break;
            case 'core':
            default:
                // Core Nébuleuse beers; unclassified beers (rbt.beer_name IS NULL) also default here
                // so they remain visible in the default view rather than disappearing silently.
                $viewWhere = "AND (rbt.type = 'Neb' AND rbt.subtype = 'Core' OR rbt.beer_name IS NULL)";
                break;
        }
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT g.beer,
                g.batch,
                DATE(MIN(g.submitted_at)) AS brew_date,
                COUNT(*)                  AS n_brews,
                SUM(g.final_volume)       AS cast_out_hl
           FROM bd_brewing_gravity_v2 g
           {$viewJoin}
          WHERE {$whereClause}
            {$viewWhere}
          GROUP BY g.beer, g.batch
          {$havingClause}
          ORDER BY brew_date DESC, g.beer, g.batch"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Wort-sale beers never enter a BBT — exclude from PPB.
        if (in_array($row['beer'], LOSS_WORT_SALES, true)) continue;

        $beer = _loss_normalize_beer($row['beer']);
        if ($beer !== '') {
            $row['beer'] = $beer;
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Earliest racking-form submission date (YYYY-MM-DD) or null if none.
 * Memoised. Defines the data-coverage horizon below which PPB cannot meaningfully
 * compute completeness (no racked_vol_hl exists).
 *
 * @internal
 */
function _loss_racking_data_floor(PDO $pdo): ?string
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) return $cached;
    $loaded = true;

    $stmt = $pdo->query(
        "SELECT DATE(MIN(submitted_at)) AS floor_date
           FROM bd_racking_v2
          WHERE is_tombstoned = 0"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cached = ($row && $row['floor_date']) ? (string)$row['floor_date'] : null;
    return $cached;
}

/**
 * Public accessor for the racking data-coverage floor. Surfaces it to the UI
 * so the operator understands why pre-floor batches are absent from PPB.
 */
function loss_racking_data_floor(PDO $pdo): ?string
{
    return _loss_racking_data_floor($pdo);
}

/**
 * Load all ref_brewhouse_size rows ordered by effective_from.
 *
 * @internal
 * @return list<array{size_hl:string, effective_from:string, effective_until:string|null}>
 */
function _loss_load_brewhouse_sizes(PDO $pdo): array
{
    return $pdo->query(
        "SELECT size_hl, effective_from, effective_until
           FROM ref_brewhouse_size
          ORDER BY effective_from ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * For each cast-out row, resolve the nominal HL using SCD2 logic.
 *
 * @internal
 * @param list<array{beer:string,batch:string,brew_date:string,n_brews:int}> $castOutRows
 * @param list<array{size_hl:string,effective_from:string,effective_until:string|null}> $brewhouses
 * @return array<string, array{float|null, string}>   key='beer|batch' → [nominal_hl, basis_note]
 */
function _loss_build_nominal_map(array $castOutRows, array $brewhouses): array
{
    $map = [];
    foreach ($castOutRows as $row) {
        $key      = $row['beer'] . '|' . $row['batch'];
        $brewDate = $row['brew_date'];  // 'YYYY-MM-DD'
        $nBrews   = (int)$row['n_brews'];

        $sizeHl = null;
        $basis  = '';

        // SCD2 resolution: find row effective at brew_date.
        foreach ($brewhouses as $bh) {
            if ($bh['effective_from'] <= $brewDate
                && ($bh['effective_until'] === null || $bh['effective_until'] >= $brewDate)
            ) {
                $sizeHl = (float)$bh['size_hl'];
                $basis  = sprintf(
                    'global brewhouse size %.1f HL @ brew date %s',
                    $sizeHl,
                    $brewDate
                );
                break;
            }
        }

        // Fallback: brew_date predates the earliest SCD2 seed — use earliest row.
        if ($sizeHl === null && !empty($brewhouses)) {
            $sizeHl = (float)$brewhouses[0]['size_hl'];
            $basis  = sprintf(
                'global brewhouse size %.1f HL [fallback: brew date %s predates SCD2 seed %s]',
                $sizeHl,
                $brewDate,
                $brewhouses[0]['effective_from']
            );
        }

        $nominalHl = ($sizeHl !== null) ? $sizeHl * $nBrews : null;
        if ($nominalHl !== null) {
            $basis = sprintf('× %d brew%s = %.1f HL nominal; ', $nBrews, $nBrews > 1 ? 's' : '', $nominalHl) . $basis;
        }

        $map[$key] = [$nominalHl, $basis];
    }
    return $map;
}

/**
 * Load racking metrics per (beer, batch): SUM of racked_vol_hl, loss_source_hl, loss_dest_hl.
 *
 * Uses the same beer-name resolution as the simulator: neb_beer / contract_beer → normalize.
 *
 * @internal
 * @return array<string, array{racked_vol_hl:float, loss_source_hl:float, loss_dest_hl:float}>
 */
function _loss_load_rack_map(PDO $pdo, ?array $filter): array
{
    $where  = ['r.is_tombstoned = 0'];
    $params = [];

    if (isset($filter['beer'])) {
        // Filter applies to the canonical beer name after normalization.
        // We filter post-fetch to avoid duplicating the normalization in SQL.
        // For large datasets a pre-filter on the raw neb_beer column can be added.
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(r.neb_beer, ''), r.contract_beer)   AS raw_beer,
                COALESCE(NULLIF(r.neb_batch, ''), r.contract_batch) AS batch,
                SUM(COALESCE(r.racked_vol_hl, 0))                   AS racked_vol_hl,
                SUM(COALESCE(r.loss_source_hl, 0))                  AS loss_source_hl,
                SUM(COALESCE(r.loss_dest_hl, 0))                    AS loss_dest_hl,
                SUM(COALESCE(r.bbt_vide_scrapped_hl, 0))            AS bbt_vide_scrapped_hl
           FROM bd_racking_v2 r
          WHERE r.is_tombstoned = 0
          GROUP BY raw_beer, batch
          HAVING raw_beer IS NOT NULL AND batch IS NOT NULL"
    );
    $stmt->execute($params);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $beer = _loss_normalize_beer($row['raw_beer'] ?? '');
        if ($beer === '') continue;

        // Apply optional beer filter post-normalization.
        if (isset($filter['beer']) && $beer !== $filter['beer']) continue;
        if (isset($filter['batch']) && $row['batch'] !== $filter['batch']) continue;

        $key       = $beer . '|' . $row['batch'];
        $map[$key] = [
            'racked_vol_hl'        => (float)$row['racked_vol_hl'],
            'loss_source_hl'       => (float)$row['loss_source_hl'],
            'loss_dest_hl'         => (float)$row['loss_dest_hl'],
            // Phantom HL discarded via BBT-vide override — display-only cause.
            // ZERO CHF: not valued, not folded into rack_loss_pct numerator.
            'bbt_vide_scrapped_hl' => (float)$row['bbt_vide_scrapped_hl'],
        ];
    }
    return $map;
}

/**
 * Load packaged HL per (beer, batch) from bd_packaging_v2 (sole source).
 * Every row is a distinct packaging event — Cuv same-day rows are distinct
 * serving-tank fills; no cross-source dedup is needed.
 *
 * Returns total vendable_hl (for packaging_loss_pct and loss_vs_effectif),
 * n_events (for the fixed-constant-times-n calculation), and total_drawn_hl
 * (equals vendable_hl — used for the completeness proxy).
 *
 * @internal
 * @return array<string, array{packaged_hl:float|null, n_events:int, total_drawn_hl:float}>
 */
function _loss_load_packaging_map(PDO $pdo, ?array $filter): array
{
    // Load web-form rows from bd_packaging_v2 (full beer names).
    $v2Stmt = $pdo->query(
        "SELECT COALESCE(NULLIF(neb_beer, ''), contract_beer)   AS beer,
                COALESCE(NULLIF(neb_batch, ''), contract_batch) AS batch,
                vendable_hl,
                DATE(submitted_at)                              AS pkg_date
           FROM bd_packaging_v2
          WHERE submitted_at IS NOT NULL
            AND vendable_hl IS NOT NULL
            AND CAST(vendable_hl AS DECIMAL(14,4)) > 0
            AND is_tombstoned = 0
            AND reuses_packaging_id_fk IS NULL"
    );
    $v2Rows = $v2Stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse every v2 row as a distinct packaging event.
    // neb_beer/contract_beer hold racking-style names — _loss_normalize_beer()
    // keeps the key consistent with the racking side.
    $map = [];  // 'beer|batch' → [vendable_sum, n_events, total_drawn_sum]

    $mergeRow = function (array $row) use (&$map, $filter): void {
        $beer  = _loss_normalize_beer(trim($row['beer'] ?? ''));
        $batch = trim($row['batch'] ?? '');

        if ($beer === '' || $batch === '') return;

        // Apply filter post-normalization.
        if (isset($filter['beer'])  && $beer  !== $filter['beer'])  return;
        if (isset($filter['batch']) && $batch !== $filter['batch'])  return;

        $vendable = (float)($row['vendable_hl'] ?? 0);

        $key = $beer . '|' . $batch;
        if (!isset($map[$key])) {
            $map[$key] = ['vendable_sum' => 0.0, 'n_events' => 0, 'drawn_sum' => 0.0];
        }
        $map[$key]['vendable_sum'] += $vendable;
        $map[$key]['n_events']++;
        $map[$key]['drawn_sum']    += $vendable;  // v2 has no loss_liquid_l; drawn = vendable
    };

    foreach ($v2Rows as $row) { $mergeRow($row); }

    // Re-shape to the returned structure.
    $result = [];
    foreach ($map as $key => $agg) {
        $result[$key] = [
            'packaged_hl'    => $agg['vendable_sum'] > 0 ? $agg['vendable_sum'] : null,
            'n_events'       => $agg['n_events'],
            'total_drawn_hl' => $agg['drawn_sum'],
        ];
    }
    return $result;
}

/**
 * Completeness proxy: returns true when the batch has been drawn down to within
 * BBT_EMPTY_THRESHOLD_HL of its racked volume.
 *
 * A batch is NOT complete when:
 *   - packaged_hl is null/0 (nothing packaged yet)
 *   - rackedVol is null (batch still in CCT, never racked)
 *   - totalDrawnHl < rackedVol − BBT_EMPTY_THRESHOLD_HL (still volume in BBT)
 *
 * @internal
 */
function _loss_is_complete(?float $packedHl, float $totalDrawnHl, ?float $rackedVol): bool
{
    if ($packedHl === null || $packedHl <= 0.0) return false;
    if ($rackedVol === null || $rackedVol <= 0.0) return false;
    // Drawn amount must have emptied the BBT to within the dead-volume threshold.
    return $totalDrawnHl >= ($rackedVol - LOSS_BBT_EMPTY_THRESHOLD_HL);
}

/* ═══════════════════════════════════════════════════════════════════════════
   5. PER-BEER AGGREGATE (6-month rolling, complete batches only)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Compute per-beer 6-month rolling average loss metrics.
 *
 * Calls loss_metrics_for_batches() (which itself uses the 6-month window set
 * in _loss_load_cast_out) then groups complete batches by beer.
 * Incomplete batches are excluded from all averages — they inflate loss figures
 * because packaged_hl is 0 or partial while cast_out/nominal are already set.
 *
 * @param PDO        $pdo    Active DB connection.
 * @param array|null $filter Optional. Supported keys:
 *                             ['view' => 'core'|'collab-eph'|'contract']
 *                             (passed through to loss_metrics_for_batches).
 *
 * @return array<int, array{
 *   beer:                       string,
 *   n_batches:                  int,        // complete batches in window
 *   n_incomplete:               int,        // incomplete batches in window (informational)
 *   avg_brewing_loss_pct:       float|null,
 *   avg_rack_loss_pct:          float|null,
 *   avg_packaging_loss_pct:     float|null,
 *   avg_loss_vs_effectif_pct:   float|null,
 *   avg_loss_vs_nominal_pct:    float|null,
 *   sum_bbt_vide_scrapped_hl:   float,      // total phantom HL discarded (0.0 when none)
 *   last_brew_date:             string,     // 'YYYY-MM-DD' of most recent complete batch
 * }>
 * Sorted by avg_loss_vs_nominal_pct DESC (worst-loss beers first — most actionable).
 * Beers with no complete batches in the window are omitted entirely.
 */
function loss_metrics_per_beer(PDO $pdo, ?array $filter = null): array
{
    $allBatches = loss_metrics_for_batches($pdo, $filter);

    // Group complete vs incomplete counts per beer.
    $groups = [];
    foreach ($allBatches as $row) {
        $beer = $row['beer'];
        if (!isset($groups[$beer])) {
            $groups[$beer] = [
                'complete_rows' => [],
                'n_incomplete'  => 0,
            ];
        }
        if ($row['complete']) {
            $groups[$beer]['complete_rows'][] = $row;
        } else {
            $groups[$beer]['n_incomplete']++;
        }
    }

    $result = [];
    foreach ($groups as $beer => $g) {
        $rows = $g['complete_rows'];
        if (empty($rows)) continue;

        $nBatches = count($rows);

        // Helper: average a nullable float field across rows, skipping nulls.
        $avg = function (string $field) use ($rows): ?float {
            $sum   = 0.0;
            $count = 0;
            foreach ($rows as $r) {
                if ($r[$field] !== null) {
                    $sum += (float)$r[$field];
                    $count++;
                }
            }
            return $count > 0 ? $sum / $count : null;
        };

        $lastBrewDate = '';
        foreach ($rows as $r) {
            if ($r['brew_date'] > $lastBrewDate) {
                $lastBrewDate = $r['brew_date'];
            }
        }

        // Sum phantom HL across ALL batches in window (complete + incomplete).
        // An incomplete batch may have already had a BBT-vide event; surface it.
        $sumBbtVide = 0.0;
        foreach ($g['complete_rows'] as $r) {
            $sumBbtVide += (float)($r['bbt_vide_scrapped_hl'] ?? 0.0);
        }

        $result[] = [
            'beer'                     => $beer,
            'n_batches'                => $nBatches,
            'n_incomplete'             => $g['n_incomplete'],
            'avg_brewing_loss_pct'     => $avg('brewing_loss_pct'),
            'avg_rack_loss_pct'        => $avg('rack_loss_pct'),
            'avg_packaging_loss_pct'   => $avg('packaging_loss_pct'),
            'avg_loss_vs_effectif_pct' => $avg('loss_vs_effectif_pct'),
            'avg_loss_vs_nominal_pct'  => $avg('loss_vs_nominal_pct'),
            // Sum of phantom HL discarded across the window — display-only, zero CHF.
            'sum_bbt_vide_scrapped_hl' => $sumBbtVide,
            'last_brew_date'           => $lastBrewDate,
        ];
    }

    // Worst-loss beers first — most actionable at the top.
    // NULLs sort last (no complete batches with nominal data → least useful).
    usort($result, function (array $a, array $b): int {
        $va = $a['avg_loss_vs_nominal_pct'];
        $vb = $b['avg_loss_vs_nominal_pct'];
        if ($va === null && $vb === null) return 0;
        if ($va === null) return 1;
        if ($vb === null) return -1;
        return $vb <=> $va;
    });

    return $result;
}
