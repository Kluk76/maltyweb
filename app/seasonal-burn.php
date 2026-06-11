<?php
declare(strict_types=1);

/**
 * app/seasonal-burn.php — Forward-seasonal stock-burn engine.
 *
 * Shared pure-function library for the two consumers:
 *   - scripts/seasonal-index-cli.php  (writes kpi_sku_seasonal_index weekly)
 *   - app/fg-stock.php                (reads the index, runs per-SKU forward simulation)
 *
 * This is THE single burn path — both consumers call these functions.
 * No duplicated math elsewhere.
 *
 * Model: classical multiplicative seasonal decomposition over inv_sales_ledger.
 *   Burn   = -SUM(qty_signed) across ALL doc_types (net physical depletion).
 *   Level  = EW-mean of deseasonalized weekly burn over a trailing window.
 *   Sim    = step forward week by week: stock − level × index[w] until zero.
 *
 * Tunable params live in system_settings section='stock' (migration 325).
 * Every function uses sb_load_params() as its param source.
 *
 * PHP 8 strict, no side effects, no globals.
 *
 * Prerequisites: app/settings.php must be required before this file
 * (system_setting() function and $_system_settings_cache global).
 */

// Ensure system_setting() is available regardless of caller's require order.
// Guard against double-declaration when this file is loaded from a path
// different from the canonical repo location (e.g. /tmp during CLI dry-runs).
if (!function_exists('system_setting')) {
    require_once __DIR__ . '/settings.php';
}

// ── 1. Parameter loader ───────────────────────────────────────────────────────

/**
 * Read all burn_* tunable params from system_settings section='stock'.
 * Always passes a sane hard-coded default so cold-config / missing rows work.
 *
 * @return array<string,float|int>
 */
function sb_load_params(): array
{
    return [
        // EWMA decay factor for level estimation (closer to 1 = slower decay = more memory)
        'burn_ewma_lambda'              => (float) system_setting('burn_ewma_lambda',              'stock', 0.95),
        // Trailing weeks used for the seasonal-index moving-average decomposition
        'burn_season_weeks'             => (int)   system_setting('burn_season_weeks',             'stock', 52),
        // Maximum forward-simulation horizon (weeks); returned as-is when stock never runs out
        'burn_horizon_weeks'            => (int)   system_setting('burn_horizon_weeks',            'stock', 104),
        // Minimum seasonal index after clamping (prevents division-explosion for very slow weeks)
        'burn_index_floor'              => (float) system_setting('burn_index_floor',              'stock', 0.2),
        // Maximum seasonal index after clamping (prevents single-week blowup from promo spikes)
        'burn_index_cap'                => (float) system_setting('burn_index_cap',                'stock', 4.0),
        // Width of the circular smoothing window applied to the raw seasonal indices
        'burn_index_smooth_weeks'       => (int)   system_setting('burn_index_smooth_weeks',       'stock', 3),
        // Minimum span of history (years) required for a family curve to be trusted
        'burn_min_family_years'         => (float) system_setting('burn_min_family_years',         'stock', 2.0),
        // Trailing window (weeks) over which the level L is computed
        'burn_level_window_weeks'       => (int)   system_setting('burn_level_window_weeks',       'stock', 52),
        // Minimum total weeks present in the level window for a "normal" status
        'burn_provisional_min_weeks'    => (int)   system_setting('burn_provisional_min_weeks',    'stock', 13),
        // Minimum non-zero weeks in the level window for a "normal" status
        'burn_provisional_min_nonzero_weeks' => (int) system_setting('burn_provisional_min_nonzero_weeks', 'stock', 6),
        // Weeks of no net sale before classifying a SKU as end-of-life
        'burn_eol_dormant_weeks'        => (int)   system_setting('burn_eol_dormant_weeks',        'stock', 26),
    ];
}

// ── 2. Family weekly series ───────────────────────────────────────────────────

/**
 * Build the net-out weekly series for a recipe family (or global aggregate).
 *
 * All doc_types intentionally — net physical depletion.
 * Deliberately DIVERGES from v_sales_ledger_order_lines (shipment-only B2B DISPLAY
 * lens, mig 326): different questions — order-line display vs total stock depletion
 * — MUST NOT be unified.
 *
 * Channel filter: exclude sale_class='customs_artifact' only.
 * NULL customer rows are KEPT (real physical depletion with no customer record).
 * sku_id_fk IS NULL rows are EXCLUDED (unresolved; counted separately by callers).
 *
 * Zero-fill: weeks within the span [min_posting_date, max_posting_date] that have
 * no ledger rows are included with qty=0 so the centered moving-average is
 * contiguous.
 *
 * @param PDO $pdo
 * @param int $recipeId  0 = global (aggregate all resolved SKUs); N = family filter
 * @return list<array{yw:int,isoweek:int,qty:float}>  ordered by yw ASC
 */
function sb_family_weekly_series(PDO $pdo, int $recipeId): array
{
    // Build the family-filter clause
    if ($recipeId === 0) {
        $familyClause = '';
        $params       = [];
    } else {
        $familyClause = 'AND s.recipe_id = ?';
        $params       = [$recipeId];
    }

    // All doc_types intentionally — net physical depletion.
    // Deliberately DIVERGES from v_sales_ledger_order_lines (shipment-only B2B DISPLAY
    // lens, mig 326): different questions — order-line display vs total stock depletion
    // — MUST NOT be unified.
    $sql = "
        SELECT YEARWEEK(l.posting_date, 3)    AS yw,
               WEEK(l.posting_date, 3)        AS isoweek,
               GREATEST(0, -SUM(l.qty_signed)) AS qty
          FROM inv_sales_ledger l
          JOIN ref_skus s ON s.id = l.sku_id_fk
          LEFT JOIN ref_customers c ON c.id = l.customer_id_fk
         WHERE l.sku_id_fk IS NOT NULL
           AND (c.sale_class IS NULL OR c.sale_class NOT IN ('customs_artifact'))
           $familyClause
         GROUP BY YEARWEEK(l.posting_date, 3), WEEK(l.posting_date, 3)
         ORDER BY yw ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $observed = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($observed)) {
        return [];
    }

    // Build observed-qty map: yw => qty
    $obsMap = [];
    foreach ($observed as $row) {
        $obsMap[(int) $row['yw']] = (float) $row['qty'];
    }

    // Determine date span from first and last observed posting_date
    if ($recipeId === 0) {
        $spanSql  = "SELECT MIN(l.posting_date) AS mindt, MAX(l.posting_date) AS maxdt
                       FROM inv_sales_ledger l
                      WHERE l.sku_id_fk IS NOT NULL";
        $spanStmt = $pdo->query($spanSql);
    } else {
        $spanSql  = "SELECT MIN(l.posting_date) AS mindt, MAX(l.posting_date) AS maxdt
                       FROM inv_sales_ledger l
                       JOIN ref_skus s ON s.id = l.sku_id_fk
                      WHERE l.sku_id_fk IS NOT NULL
                        AND s.recipe_id = ?";
        $spanStmt = $pdo->prepare($spanSql);
        $spanStmt->execute([$recipeId]);
    }
    $span = $spanStmt->fetch(PDO::FETCH_ASSOC);

    if ($span['mindt'] === null || $span['maxdt'] === null) {
        return [];
    }

    // Generate the full weekly axis from mindt to maxdt by stepping 7 days,
    // anchored to Monday of each ISO week (DateTimeImmutable for safety).
    $result  = [];
    $cursor  = new DateTimeImmutable($span['mindt']);
    // Rewind to Monday of the starting ISO week
    $dow     = (int) $cursor->format('N'); // 1=Mon … 7=Sun
    if ($dow > 1) {
        $cursor = $cursor->modify('-' . ($dow - 1) . ' days');
    }
    $endDt  = new DateTimeImmutable($span['maxdt']);

    while ($cursor <= $endDt) {
        $yw      = (int) $cursor->format('oW'); // ISO year-week: oW is YYYYWW (padded)
        $isoweek = (int) $cursor->format('W');
        $result[] = [
            'yw'      => $yw,
            'isoweek' => $isoweek,
            'qty'     => $obsMap[$yw] ?? 0.0,
        ];
        $cursor = $cursor->modify('+7 days');
    }

    return $result;
}

// ── 3. Seasonal index computation ─────────────────────────────────────────────

/**
 * Classical multiplicative decomposition of a contiguous weekly series.
 *
 * Steps:
 *   a) 53-term centered moving average (half-weights on the two endpoints).
 *   b) ratio[t] = qty[t] / MA[t] where MA[t] > 0.
 *   c) Sraw[w] = MEDIAN of ratios per ISO week-of-year.
 *   d) Normalize so mean over populated weeks = 1.0; unpopulated weeks → 1.0.
 *   e) Circular 3-week smoothing (week 53 = week 52 value).
 *   f) Clamp to [burn_index_floor, burn_index_cap].
 *
 * Returns null when the series does not have enough span/data to be trusted
 * (caller should fall back to the global curve).
 *
 * @param list<array{yw:int,isoweek:int,qty:float}> $series  ordered contiguous
 * @param array $params  from sb_load_params()
 * @return array{index:array<int,float>,n_obs:array<int,int>,sample_years:float,usable:bool}|null
 */
function sb_compute_seasonal_index(array $series, array $params): ?array
{
    $n = count($series);
    if ($n < 2) {
        return null;
    }

    $minFamilyYears = (float) $params['burn_min_family_years'];
    $indexFloor     = (float) $params['burn_index_floor'];
    $indexCap       = (float) $params['burn_index_cap'];

    // Sample span in years
    $spanWeeks   = $n;
    $sampleYears = $spanWeeks / 52.18;

    // ── a) Centered 53-term moving average ────────────────────────────────────
    // Standard centered even-order (52-term) convention:
    //   MA[t] = (0.5*y[t-26] + y[t-25] + … + y[t+25] + 0.5*y[t+26]) / 52
    // Endpoints needing unavailable data are left as NaN (null in PHP).
    $half = 26; // half-window
    $ma   = array_fill(0, $n, null);
    for ($t = $half; $t < $n - $half; $t++) {
        $sum = 0.5 * $series[$t - $half]['qty']
             + 0.5 * $series[$t + $half]['qty'];
        for ($k = $t - $half + 1; $k <= $t + $half - 1; $k++) {
            $sum += $series[$k]['qty'];
        }
        $ma[$t] = $sum / 52.0;
    }

    // ── b) Ratios ─────────────────────────────────────────────────────────────
    // ratio[t] = qty[t] / MA[t], only where MA[t] > 0
    // Group by ISO week-of-year (1..53)
    $ratiosByWeek = []; // isoweek => float[]
    for ($t = 0; $t < $n; $t++) {
        if ($ma[$t] === null || $ma[$t] <= 0.0) {
            continue;
        }
        $w                  = $series[$t]['isoweek'];
        $ratiosByWeek[$w][] = $series[$t]['qty'] / $ma[$t];
    }

    if (empty($ratiosByWeek)) {
        return null;
    }

    // ── c) Median per ISO week ────────────────────────────────────────────────
    $sRaw  = []; // isoweek => float (median)
    $nObs  = []; // isoweek => int  (count of ratios)
    for ($w = 1; $w <= 53; $w++) {
        if (!isset($ratiosByWeek[$w]) || count($ratiosByWeek[$w]) === 0) {
            $nObs[$w] = 0;
            continue;
        }
        $vals       = $ratiosByWeek[$w];
        sort($vals);
        $cnt        = count($vals);
        $nObs[$w]   = $cnt;
        if ($cnt % 2 === 1) {
            $sRaw[$w] = $vals[(int) ($cnt / 2)];
        } else {
            $sRaw[$w] = ($vals[$cnt / 2 - 1] + $vals[$cnt / 2]) / 2.0;
        }
    }

    // ── d) Normalize over populated weeks → mean = 1.0 ───────────────────────
    $populated = array_keys(array_filter($nObs, fn($c) => $c > 0));
    if (count($populated) < 4) {
        // Too sparse to normalize meaningfully
        return null;
    }

    $popCount = count($populated);
    $sumRaw   = 0.0;
    foreach ($populated as $w) {
        $sumRaw += $sRaw[$w];
    }
    $scaleFactor = ($sumRaw > 0) ? ($popCount / $sumRaw) : 1.0;

    $sNorm = [];
    for ($w = 1; $w <= 53; $w++) {
        if (isset($sRaw[$w])) {
            $sNorm[$w] = $sRaw[$w] * $scaleFactor;
        } else {
            // Unpopulated week — default 1.0 (the neutral multiplier)
            $sNorm[$w] = 1.0;
            // nObs already 0 for unpopulated weeks
        }
    }

    // ── e) Circular 3-week smoothing over weeks 1..52; week 53 = week 52 ──────
    $smoothedWeeks = $params['burn_index_smooth_weeks'];
    // Build smoothed for 1..52 using circular wrap
    $sSmooth = [];
    for ($w = 1; $w <= 52; $w++) {
        $prev = ($w === 1) ? 52 : $w - 1;
        $next = ($w === 52) ? 1  : $w + 1;
        $sSmooth[$w] = ($sNorm[$prev] + $sNorm[$w] + $sNorm[$next]) / 3.0;
    }
    // Week 53 = week 52's smoothed value
    $sSmooth[53] = $sSmooth[52];

    // ── f) Clamp ──────────────────────────────────────────────────────────────
    $sIndex = [];
    for ($w = 1; $w <= 53; $w++) {
        $sIndex[$w] = max($indexFloor, min($indexCap, $sSmooth[$w]));
    }

    $usable = $sampleYears >= $minFamilyYears;

    return [
        'index'        => $sIndex,  // [1..53 => float]
        'n_obs'        => $nObs,    // [1..53 => int]
        'sample_years' => round($sampleYears, 1),
        'usable'       => $usable,
    ];
}

// ── 4. Load index map from cache table ────────────────────────────────────────

/**
 * Read kpi_sku_seasonal_index in one query; return a structured map.
 *
 * @return array{byFamily:array<int,array<int,float>>,global:array<int,float>,computed_at:string|null,empty:bool}
 */
function sb_load_index_map(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT recipe_id, iso_week, seasonal_index, is_global_fallback, computed_at
               FROM kpi_sku_seasonal_index
              ORDER BY recipe_id, iso_week'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not exist yet (migration 325 not applied) — cold cache
        return ['byFamily' => [], 'global' => [], 'computed_at' => null, 'empty' => true];
    }

    if (empty($rows)) {
        return ['byFamily' => [], 'global' => [], 'computed_at' => null, 'empty' => true];
    }

    $byFamily   = [];
    $global     = [];
    $computedAt = null;

    foreach ($rows as $row) {
        $recipeId = (int) $row['recipe_id'];
        $isoWeek  = (int) $row['iso_week'];
        $idx      = (float) $row['seasonal_index'];
        if ($computedAt === null) {
            $computedAt = $row['computed_at'];
        }

        if ((int) $row['is_global_fallback'] === 1 || $recipeId === 0) {
            $global[$isoWeek] = $idx;
        } else {
            $byFamily[$recipeId][$isoWeek] = $idx;
        }
    }

    return [
        'byFamily'    => $byFamily,
        'global'      => $global,
        'computed_at' => $computedAt,
        'empty'       => false,
    ];
}

// ── 5. Resolve family index for a SKU ─────────────────────────────────────────

/**
 * Return the [isoweek => index] for one SKU's family.
 * Precedence: family curve → global curve → flat-1.0 (cold cache).
 *
 * When flat-1.0, sb_forward_weeks() degenerates to physique/L — still valid.
 * Never throws.
 *
 * @param array  $indexMap   from sb_load_index_map()
 * @param int|null $recipeId  null means no family → use global
 * @return array<int,float>  [1..53 => float]
 */
function sb_resolve_family_index(array $indexMap, ?int $recipeId): array
{
    if ($recipeId !== null && isset($indexMap['byFamily'][$recipeId])) {
        return $indexMap['byFamily'][$recipeId];
    }
    if (!empty($indexMap['global'])) {
        return $indexMap['global'];
    }
    // Cold cache: flat 1.0 for all 53 weeks
    $flat = [];
    for ($w = 1; $w <= 53; $w++) {
        $flat[$w] = 1.0;
    }
    return $flat;
}

// ── 6. Bulk per-SKU level computation ─────────────────────────────────────────

/**
 * BULK per-SKU level L for ALL resolved SKUs in ONE SQL query.
 *
 * Deseasonalizes each week of the trailing burn_level_window_weeks window,
 * then computes the EWMA-weighted mean over weeks present since the SKU's
 * first sale (zero-filled from max(first_sale_week, window_start) to today).
 *
 * @param PDO   $pdo
 * @param array $indexMap  from sb_load_index_map()
 * @param array $params    from sb_load_params()
 * @return array<int, array{
 *   level:float,
 *   weeks_present:int,
 *   nonzero_weeks:int,
 *   first_sale:string|null,
 *   last_sale:string|null,
 *   lived_full_summer:bool,
 *   weeks_since_last_sale:int
 * }>  keyed by sku_id
 */
function sb_all_sku_levels(PDO $pdo, array $indexMap, array $params): array
{
    $windowWeeks = (int) $params['burn_level_window_weeks'];
    $lambda      = (float) $params['burn_ewma_lambda'];

    $windowStart = date('Y-m-d', strtotime("-{$windowWeeks} weeks"));
    $today       = date('Y-m-d');

    // One query: per-SKU weekly net-out in the window + recipe_id + lifetime stats
    // All doc_types intentionally — net physical depletion.
    // Deliberately DIVERGES from v_sales_ledger_order_lines (shipment-only B2B DISPLAY
    // lens, mig 326): different questions — order-line display vs total stock depletion
    // — MUST NOT be unified.
    $stmt = $pdo->prepare("
        SELECT l.sku_id_fk,
               s.recipe_id,
               YEARWEEK(l.posting_date, 3)     AS yw,
               WEEK(l.posting_date, 3)          AS isoweek,
               GREATEST(0, -SUM(l.qty_signed))  AS qty,
               MIN(l.posting_date)              AS period_min_date,
               MAX(l.posting_date)              AS period_max_date
          FROM inv_sales_ledger l
          JOIN ref_skus s ON s.id = l.sku_id_fk
          LEFT JOIN ref_customers c ON c.id = l.customer_id_fk
         WHERE l.sku_id_fk IS NOT NULL
           AND l.posting_date >= ?
           AND (c.sale_class IS NULL OR c.sale_class NOT IN ('customs_artifact'))
         GROUP BY l.sku_id_fk, s.recipe_id, YEARWEEK(l.posting_date, 3), WEEK(l.posting_date, 3)
         ORDER BY l.sku_id_fk, yw
    ");
    $stmt->execute([$windowStart]);
    $windowRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lifetime stats (first sale, last sale, lived_full_summer) per SKU
    $lifetimeStmt = $pdo->prepare("
        SELECT l.sku_id_fk,
               MIN(l.posting_date) AS first_sale,
               MAX(l.posting_date) AS last_sale,
               MAX(CASE WHEN WEEK(l.posting_date, 3) BETWEEN 26 AND 35 THEN 1 ELSE 0 END) AS lived_full_summer
          FROM inv_sales_ledger l
          LEFT JOIN ref_customers c ON c.id = l.customer_id_fk
         WHERE l.sku_id_fk IS NOT NULL
           AND (c.sale_class IS NULL OR c.sale_class NOT IN ('customs_artifact'))
         GROUP BY l.sku_id_fk
    ");
    $lifetimeStmt->execute();
    $lifetimeMap = [];
    foreach ($lifetimeStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $lifetimeMap[(int) $lr['sku_id_fk']] = $lr;
    }

    // Build per-SKU week arrays from query results
    $skuData = []; // sku_id => ['recipe_id'=>?, 'weeks'=>[yw=>{isoweek,qty}]]
    foreach ($windowRows as $row) {
        $sid = (int) $row['sku_id_fk'];
        if (!isset($skuData[$sid])) {
            $skuData[$sid] = [
                'recipe_id' => ($row['recipe_id'] !== null) ? (int) $row['recipe_id'] : null,
                'weeks'     => [],
            ];
        }
        $skuData[$sid]['weeks'][(int) $row['yw']] = [
            'isoweek' => (int) $row['isoweek'],
            'qty'     => (float) $row['qty'],
        ];
    }

    // Build the full week axis for the window (same approach as sb_family_weekly_series)
    $weekAxis = _sb_build_week_axis($windowStart, $today);

    $result = [];
    $todayTs = strtotime($today);

    foreach ($skuData as $sid => $sdata) {
        $recipeId   = $sdata['recipe_id'];
        $familyIdx  = sb_resolve_family_index($indexMap, $recipeId);
        $lifetimeLr = $lifetimeMap[$sid] ?? null;

        // First-sale date determines zero-fill start within the window
        $firstSale  = $lifetimeLr['first_sale'] ?? null;
        $firstSaleYw = $firstSale !== null ? (int) date('oW', strtotime($firstSale)) : 0;

        // Compute EWMA-weighted deseasonalized mean
        $weightedSum = 0.0;
        $weightTotal = 0.0;
        $weeksPres   = 0;
        $nonzeroWks  = 0;

        $nWeeks = count($weekAxis);
        foreach ($weekAxis as $k => $wk) {
            $yw      = $wk['yw'];
            $isoweek = $wk['isoweek'];

            // Only count weeks from first-sale forward
            if ($firstSaleYw > 0 && $yw < $firstSaleYw) {
                continue;
            }

            $qty    = $sdata['weeks'][$yw]['qty'] ?? 0.0;
            $index  = $familyIdx[$isoweek] ?? 1.0;
            $idxSafe = max($index, 1e-6);

            // Deseasonalize
            $ds = $qty / $idxSafe;

            // EWMA weight: lambda^k where k = distance from the most recent week
            $age    = $nWeeks - 1 - $k;
            $weight = $lambda ** $age;

            $weightedSum += $weight * $ds;
            $weightTotal += $weight;
            $weeksPres++;
            if ($qty > 0) {
                $nonzeroWks++;
            }
        }

        $level = ($weightTotal > 0) ? ($weightedSum / $weightTotal) : 0.0;

        // Weeks since last sale
        $lastSale = $lifetimeLr['last_sale'] ?? null;
        $weeksSinceLastSale = 0;
        if ($lastSale !== null) {
            $diffSecs           = max(0, $todayTs - strtotime($lastSale));
            $weeksSinceLastSale = (int) floor($diffSecs / (7 * 86400));
        }

        $result[$sid] = [
            'level'               => max(0.0, $level),
            'weeks_present'       => $weeksPres,
            'nonzero_weeks'       => $nonzeroWks,
            'first_sale'          => $firstSale,
            'last_sale'           => $lastSale,
            'lived_full_summer'   => ((int) ($lifetimeLr['lived_full_summer'] ?? 0)) === 1,
            'weeks_since_last_sale' => $weeksSinceLastSale,
        ];
    }

    return $result;
}

/**
 * Build an ordered weekly axis from $startDate to $endDate (inclusive).
 * Each entry: {yw: int YYYYWW, isoweek: int 1..53}
 * Anchored to Monday of the ISO week containing $startDate.
 *
 * @internal
 * @return list<array{yw:int,isoweek:int}>
 */
function _sb_build_week_axis(string $startDate, string $endDate): array
{
    $axis   = [];
    $cursor = new DateTimeImmutable($startDate);
    // Rewind to Monday of the starting ISO week
    $dow    = (int) $cursor->format('N'); // 1=Mon … 7=Sun
    if ($dow > 1) {
        $cursor = $cursor->modify('-' . ($dow - 1) . ' days');
    }
    $end = new DateTimeImmutable($endDate);

    while ($cursor <= $end) {
        $axis[] = [
            'yw'      => (int) $cursor->format('oW'),
            'isoweek' => (int) $cursor->format('W'),
        ];
        $cursor = $cursor->modify('+7 days');
    }
    return $axis;
}

// ── 7. Forward depletion simulation ───────────────────────────────────────────

/**
 * Core forward depletion simulation — order-aware.
 *
 * Each week's demand = max(L × seasonalIndex[w], open_orders_that_week).
 * The seasonal L is built from historical B2B sales; open orders are the same
 * B2B demand materializing — NOT additive (no double-count). An order FLOORS
 * that week's demand; an order-free week burns the seasonal baseline.
 *
 * Null logic (rule 5): null ONLY when level<=0 AND no open orders (truly no
 * demand signal — rendered as "sans rotation"). If there are open orders,
 * always run the sim and return a finite number even when physique<=0.
 *
 * @param float  $physique    Current stock on hand (may be 0 or negative)
 * @param float  $level       Deseasonalized weekly run-rate L (EW-mean); 0 = no history
 * @param array  $familyIndex [1..53 => float] seasonal index for this SKU's family
 * @param string $today       YYYY-MM-DD reference date
 * @param array  $params      from sb_load_params()
 * @param array  $openByWeek  [h => float] committed qty due in offset-h weeks from today
 *                            (h=1..N; overdue/current-week orders fold into h=1).
 *                            Keyed by INTEGER week offset, not ISO week-of-year.
 * @return array{weeks:float|null,trace:list<array>}
 *   weeks — fractional weeks to stock-out, or (float)horizon if stock survives,
 *           or null only when level<=0 AND openByWeek is empty.
 *   trace — per-week projection list, capped at min(stockout_week+2, 16) entries.
 *           Each entry: {h, week_start (YYYY-MM-DD Monday), iso_week, expected_burn,
 *           open_orders, demand, stock_after}
 */
function sb_forward_sim(
    float  $physique,
    float  $level,
    array  $familyIndex,
    string $today,
    array  $params,
    array  $openByWeek = []
): array {
    $noOpenOrders = empty($openByWeek) || array_sum($openByWeek) <= 0;

    // Null: no demand signal at all
    if ($level <= 0 && $noOpenOrders) {
        return ['weeks' => null, 'trace' => []];
    }

    $horizon  = (int) $params['burn_horizon_weeks'];
    $stock    = $physique;
    $todayTs  = strtotime($today);
    $trace    = [];
    $weeks    = (float) $horizon; // default: survives full horizon

    // Cap trace at 16 entries; the stockout week always appears in the trace.
    $traceLimit = 16;

    for ($h = 1; $h <= $horizon; $h++) {
        $futureTs = strtotime("+{$h} weeks", $todayTs);
        $wCal     = (int) date('W', $futureTs);   // ISO week-of-year 1..53
        $idx      = $familyIndex[$wCal] ?? 1.0;

        // Seasonal baseline burn (never below 0)
        $burn   = max($level * $idx, 0.0);
        // Open-order floor: a large order dominates its week
        $openQty = (float) ($openByWeek[$h] ?? 0.0);
        // Reconciled demand — at least 1e-9 to avoid /0
        $demand  = max($burn, $openQty, 1e-9);

        // Monday of the target week
        $weekStartTs  = strtotime('monday this week', $futureTs);
        // Ensure it's strictly in the future (strtotime 'monday this week' can land today)
        if ($weekStartTs <= $todayTs) {
            $weekStartTs = strtotime("+{$h} weeks monday", $todayTs);
        }
        $weekStart = date('Y-m-d', $weekStartTs);

        if ($stock <= $demand) {
            // Stockout during this week — record the final entry and break.
            // weeks is set once and only once at the first stockout.
            // max($stock, 0) prevents negative stock from making weeks > h-1.
            $weeks      = (float) ($h - 1) + max($stock, 0.0) / $demand;
            $stockAfter = $stock - $demand; // may go negative (shows survendu depth to operator)
            $stockoutH  = $h;
            if (count($trace) < $traceLimit) {
                $trace[] = [
                    'h'             => $h,
                    'week_start'    => $weekStart,
                    'iso_week'      => $wCal,
                    'expected_burn' => round($burn, 1),
                    'open_orders'   => $openQty,
                    'demand'        => round($demand, 4),
                    'stock_after'   => round($stockAfter, 4),
                ];
            }
            // Add one post-stockout context week to the trace if horizon allows
            if ($h < $horizon && count($trace) < $traceLimit) {
                $h2      = $h + 1;
                $fut2Ts  = strtotime("+{$h2} weeks", $todayTs);
                $wCal2   = (int) date('W', $fut2Ts);
                $idx2    = $familyIndex[$wCal2] ?? 1.0;
                $burn2   = max($level * $idx2, 0.0);
                $open2   = (float) ($openByWeek[$h2] ?? 0.0);
                $demand2 = max($burn2, $open2, 1e-9);
                $wkSt2   = strtotime('monday this week', $fut2Ts);
                if ($wkSt2 <= $todayTs) {
                    $wkSt2 = strtotime("+{$h2} weeks monday", $todayTs);
                }
                $trace[] = [
                    'h'             => $h2,
                    'week_start'    => date('Y-m-d', $wkSt2),
                    'iso_week'      => $wCal2,
                    'expected_burn' => round($burn2, 1),
                    'open_orders'   => $open2,
                    'demand'        => round($demand2, 4),
                    'stock_after'   => round($stockAfter - $demand2, 4),
                ];
            }
            break; // weeks is finalised; exit the loop immediately
        }

        $stock -= $demand;

        if (count($trace) < $traceLimit) {
            $trace[] = [
                'h'             => $h,
                'week_start'    => $weekStart,
                'iso_week'      => $wCal,
                'expected_burn' => round($burn, 1),
                'open_orders'   => $openQty,
                'demand'        => round($demand, 4),
                'stock_after'   => round($stock, 4),
            ];
        }
    }

    return ['weeks' => $weeks, 'trace' => $trace];
}

/**
 * Back-compat thin wrapper around sb_forward_sim().
 * Returns just the weeks value so no existing callers break.
 *
 * @param float  $physique
 * @param float  $level
 * @param array  $familyIndex [1..53 => float]
 * @param string $today       YYYY-MM-DD
 * @param array  $params      from sb_load_params()
 * @param array  $openByWeek  [h => float] optional order-book buckets (forwarded to sim)
 * @return float|null
 */
function sb_forward_weeks(
    float  $physique,
    float  $level,
    array  $familyIndex,
    string $today,
    array  $params,
    array  $openByWeek = []
): ?float {
    return sb_forward_sim($physique, $level, $familyIndex, $today, $params, $openByWeek)['weeks'];
}

// ── 8. SKU status classification ──────────────────────────────────────────────

/**
 * Classify a SKU's burn status.
 *
 * EOL    — no net sale in trailing burn_eol_dormant_weeks weeks.
 * provisoire — insufficient history (too few weeks present or non-zero, or no
 *              full summer lived through).
 * normal — sufficient history for a reliable forward estimate.
 *
 * EOL takes precedence over provisoire.
 *
 * @param array $lvl    Element from sb_all_sku_levels() result
 * @param array $params from sb_load_params()
 * @return string 'eol'|'provisoire'|'normal'
 */
function sb_status(array $lvl, array $params): string
{
    $eolDormantWeeks    = (int) $params['burn_eol_dormant_weeks'];
    $provMinWeeks       = (int) $params['burn_provisional_min_weeks'];
    $provMinNonzeroWeeks = (int) $params['burn_provisional_min_nonzero_weeks'];

    if ($lvl['weeks_since_last_sale'] >= $eolDormantWeeks) {
        return 'eol';
    }

    if (
        $lvl['weeks_present']    < $provMinWeeks
        || $lvl['nonzero_weeks'] < $provMinNonzeroWeeks
        || $lvl['lived_full_summer'] === false
    ) {
        return 'provisoire';
    }

    return 'normal';
}
