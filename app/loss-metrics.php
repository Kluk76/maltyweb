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
 * @param array|null $filter  Optional. ['beer' => string, 'batch' => string] to
 *                             restrict to one batch; ['beer' => string] for one recipe;
 *                             null (default) = all batches.
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

    // ── B. Nominal HL from ref_brewhouse_size (SCD2 temporal) ─────────────────
    //
    // One query for all distinct brew_dates.
    //
    $brewhouses   = _loss_load_brewhouse_sizes($pdo);
    $nominalMap   = _loss_build_nominal_map($castOutRows, $brewhouses);

    // ── C. Racking losses per (beer, batch) ───────────────────────────────────
    //
    $rackMap = _loss_load_rack_map($pdo, $filter);

    // ── D. Packaged HL per (beer, batch) ──────────────────────────────────────
    //
    $pkgMap = _loss_load_packaging_map($pdo, $filter);

    // ── E. Assemble result rows ────────────────────────────────────────────────
    //
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
            'beer'                 => $beer,
            'batch'                => $batch,
            'brew_date'            => $brewDate,
            'cast_out_hl'          => $castOut,
            'nominal_hl'           => $nominalHl,
            'nominal_basis'        => $nominalBasis,
            'packaged_hl'          => $packedHl,
            'n_packaging_events'   => $nPkgEvents,
            'complete'             => $complete,
            'rack_loss_pct'        => $rackLossPct,
            'packaging_loss_pct'   => $packagingLossPct,
            'brewing_loss_pct'     => $brewingLossPct,
            'loss_vs_effectif_pct' => $lossVsEffectifPct,
            'loss_vs_nominal_pct'  => $lossVsNominalPct,
            'flags'                => [
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

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT g.beer,
                g.batch,
                DATE(MIN(g.submitted_at)) AS brew_date,
                COUNT(*)                  AS n_brews,
                SUM(g.final_volume)       AS cast_out_hl
           FROM bd_brewing_gravity_v2 g
          WHERE {$whereClause}
          GROUP BY g.beer, g.batch
          ORDER BY brew_date DESC, g.beer, g.batch"
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $beer = _loss_normalize_beer($row['beer']);
        if ($beer !== '') {
            $row['beer'] = $beer;
            $rows[] = $row;
        }
    }
    return $rows;
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
                SUM(COALESCE(r.loss_dest_hl, 0))                    AS loss_dest_hl
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
            'racked_vol_hl'   => (float)$row['racked_vol_hl'],
            'loss_source_hl'  => (float)$row['loss_source_hl'],
            'loss_dest_hl'    => (float)$row['loss_dest_hl'],
        ];
    }
    return $map;
}

/**
 * Load packaged HL per (beer, batch) from both bd_packaging (legacy, SKU codes)
 * and bd_packaging_v2 (web-form, full names). Deduped by (beer_canonical, batch).
 *
 * Returns total vendable_hl (for packaging_loss_pct and loss_vs_effectif),
 * n_events (for the fixed-constant-times-n calculation), and total_drawn_hl
 * (vendable + loss_liquid converted to HL — used for the completeness proxy).
 *
 * @internal
 * @return array<string, array{packaged_hl:float|null, n_events:int, total_drawn_hl:float}>
 */
function _loss_load_packaging_map(PDO $pdo, ?array $filter): array
{
    // 1. Legacy bd_packaging (SKU-coded beer names)
    $legacyStmt = $pdo->query(
        "SELECT beer,
                batch,
                vendable_hl,
                COALESCE(loss_liquid_l, 0) AS loss_liquid_l,
                DATE(submitted_at)          AS pkg_date
           FROM bd_packaging
          WHERE submitted_at IS NOT NULL
            AND (vendable_hl > 0 OR loss_liquid_l > 0)"
    );
    $legacyRows = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Web-form bd_packaging_v2 (full beer names; no loss_liquid_l column)
    $v2Stmt = $pdo->query(
        "SELECT COALESCE(NULLIF(neb_beer, ''), contract_beer)   AS beer,
                COALESCE(NULLIF(neb_batch, ''), contract_batch) AS batch,
                vendable_hl,
                0                                               AS loss_liquid_l,
                DATE(submitted_at)                              AS pkg_date
           FROM bd_packaging_v2
          WHERE submitted_at IS NOT NULL
            AND vendable_hl IS NOT NULL
            AND CAST(vendable_hl AS DECIMAL(14,4)) > 0
            AND is_tombstoned = 0"
    );
    $v2Rows = $v2Stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Merge and dedup — same logic as TankSimulator::loadPackagingEvents().
    //    Dedup key: (beer_canonical, batch, date). Legacy rows loaded first so they
    //    win when there is a same-day collision (they carry loss_liquid_l too).
    $seen     = [];  // 'beer|batch|YYYY-MM-DD' → true
    $map      = [];  // 'beer|batch' → [vendable_sum, n_events, total_drawn_sum]

    $mergeRow = function (array $row, bool $isSkuSource) use (&$seen, &$map, $filter): void {
        $rawBeer = trim($row['beer'] ?? '');
        if ($isSkuSource) {
            $beer = _loss_derive_beer_from_sku($rawBeer);
            if ($beer === '') {
                $beer = _loss_normalize_beer($rawBeer);
            }
        } else {
            $beer = _loss_normalize_beer($rawBeer);
        }
        $batch   = trim($row['batch'] ?? '');
        $pkgDate = $row['pkg_date'] ?? '';

        if ($beer === '' || $batch === '') return;

        // Apply filter post-normalization.
        if (isset($filter['beer'])  && $beer  !== $filter['beer'])  return;
        if (isset($filter['batch']) && $batch !== $filter['batch'])  return;

        $dedupKey = $beer . '|' . $batch . '|' . $pkgDate;
        if (isset($seen[$dedupKey])) return;
        $seen[$dedupKey] = true;

        $vendable  = (float)($row['vendable_hl']   ?? 0);
        $lossLiqL  = (float)($row['loss_liquid_l'] ?? 0);
        $drawnHl   = $vendable + ($lossLiqL / 100.0);  // loss_liquid_l is in litres

        $key = $beer . '|' . $batch;
        if (!isset($map[$key])) {
            $map[$key] = ['vendable_sum' => 0.0, 'n_events' => 0, 'drawn_sum' => 0.0];
        }
        $map[$key]['vendable_sum'] += $vendable;
        $map[$key]['n_events']++;
        $map[$key]['drawn_sum']    += $drawnHl;
    };

    foreach ($legacyRows as $row) { $mergeRow($row, true); }
    foreach ($v2Rows    as $row) { $mergeRow($row, false); }

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
