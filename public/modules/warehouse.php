<?php
declare(strict_types=1);
require __DIR__ . "/../../app/auth.php";
require_login();
$me = current_user();
$active_module = "warehouse";

$view    = in_array($_GET['view'] ?? 'rm', ['rm', 'fg', 'wip'], true) ? $_GET['view'] : 'rm';
$periodRaw = $_GET['period'] ?? '';
$period    = (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $periodRaw) ? $periodRaw : '');
$miId    = isset($_GET['mi_id']) && ctype_digit((string) $_GET['mi_id']) ? (int) $_GET['mi_id'] : null;
$cat     = $_GET['cat'] ?? '';
$q       = trim($_GET['q'] ?? '');
$sortCol = $_GET['sort'] ?? 'mi_id';
if (!in_array($sortCol, ['mi_id', 'mi_name', 'category', 'live_qty', 'wac_chf', 'stock_value', 'weeks_remaining', 'hl_equivalent', 'last_delivery'], true)) {
    $sortCol = 'mi_id';
}
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

$pdo = maltytask_pdo();

header("Content-Type: text/html; charset=utf-8");

// ── helpers ──────────────────────────────────────────────────────────────────

$monthsFR = [
    1 => "jan", 2 => "fév", 3 => "mar", 4 => "avr",
    5 => "mai", 6 => "jun", 7 => "jul", 8 => "aoû",
    9 => "sep", 10 => "oct", 11 => "nov", 12 => "déc",
];

$monthsFRLong = [
    1 => "janvier", 2 => "février", 3 => "mars", 4 => "avril",
    5 => "mai", 6 => "juin", 7 => "juillet", 8 => "août",
    9 => "septembre", 10 => "octobre", 11 => "novembre", 12 => "décembre",
];

function wh_date_fr(string $d, array $months): string {
    $ts = strtotime($d);
    if ($ts === false) return htmlspecialchars($d);
    return sprintf("%02d %s %s", (int) date("j", $ts), $months[(int) date("n", $ts)], date("Y", $ts));
}

function wh_num(mixed $v, int $dec = 2, string $fallback = '—'): string {
    if ($v === null || $v === '') return $fallback;
    $f = (float) $v;
    return number_format($f, $dec, '.', ' ');
}

/**
 * Format a number with smart trailing-zero trimming.
 * - Always shows at least $min decimals.
 * - Shows up to $max decimals.
 * - Trims trailing zeros once past $min.
 * Example: wh_num_smart(5,   1, 3) → "5.0"
 *          wh_num_smart(5.5, 1, 3) → "5.5"
 *          wh_num_smart(5.123456, 1, 3) → "5.123"
 *          wh_num_smart(5.10000, 0, 2) → "5.1"
 */
function wh_num_smart(mixed $v, int $min = 0, int $max = 4, string $fallback = '—'): string {
    if ($v === null || $v === '') return $fallback;
    $f = (float) $v;
    // round to $max decimals
    $s = number_format($f, $max, '.', ' ');
    // strip trailing zeros but keep at least $min decimals
    if ($max > $min && strpos($s, '.') !== false) {
        $s = preg_replace('/(\.\d*?)0+$/', '$1', $s);
        $s = rtrim($s, '.');
        // re-add minimum decimals if we went below
        $dotPos = strpos($s, '.');
        $currentDec = $dotPos === false ? 0 : strlen($s) - $dotPos - 1;
        if ($currentDec < $min) {
            $s = number_format((float) str_replace(' ', '', $s), $min, '.', ' ');
        }
    }
    return $s;
}

function wh_sort_href(string $col, string $currentCol, string $currentDir, string $baseView, ?int $miId, string $cat, string $q): string {
    $nextDir = ($col === $currentCol && $currentDir === 'asc') ? 'desc' : 'asc';
    $params = ['view' => $baseView, 'sort' => $col, 'dir' => $nextDir];
    if ($miId !== null) $params['mi_id'] = (string) $miId;
    if ($cat !== '')    $params['cat']   = $cat;
    if ($q !== '')      $params['q']     = $q;
    return '?' . http_build_query($params);
}

function wh_sort_indicator(string $col, string $currentCol, string $currentDir): string {
    if ($col !== $currentCol) return '';
    $arrow = $currentDir === 'asc' ? '▲' : '▼';
    $label = $currentDir === 'asc' ? 'croissant' : 'décroissant';
    return ' <span aria-hidden="true">' . $arrow . '</span><span class="sr-only">(' . $label . ')</span>';
}

/**
 * Last day of YYYY-MM as a long French string: "30 avril 2026"
 */
function wh_period_label(string $ym, array $longMonths): string {
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m)) return htmlspecialchars($ym);
    $lastDay = (int) date('t', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]));
    return $lastDay . ' ' . $longMonths[(int) $m[2]] . ' ' . $m[1];
}

// ── data layer ───────────────────────────────────────────────────────────────

$dbError  = null;
$rows     = [];
$miRow    = null;
$delivH   = [];
$consH    = [];
$kpis     = ['stock_value' => 0.0, 'mis_in_stock' => 0, 'burn_critique' => 0, 'hl_total' => 0.0, 'carried' => 0, 'no_basis_count' => 0];
$catRows  = [];
$sparkPts = [];

// WIP tab data
$wipPeriods   = [];   // array of available month_keys
$wipPeriod    = '';   // resolved period
$wipCcts      = [];   // CCT tank rows
$wipBbts      = [];   // BBT tank rows
$wipMiRows    = [];   // MI breakdown rows
$wipGlRecap   = [];   // GL recap rows
$wipKpis      = ['hl_total' => 0.0, 'cct_count' => 0, 'bbt_count' => 0, 'wip_value' => 0.0];

// FG tab data
$fgPeriods    = [];
$fgPeriod     = '';
$fgRows       = [];   // each entry: sku, beer, format, unit_label, qty, hl_equiv, liquid_per_unit,
                      // packaging_per_unit, total_per_unit, total_chf, gl_breakdown, cost_source_month, no_cost_basis
$fgKpis       = ['valeur_fg' => 0.0, 'hl_total' => 0.0, 'sku_count' => 0, 'valeur_liquide' => 0.0, 'valeur_emballage' => 0.0];
$fgGlRecap    = [];   // GL account => total CHF

try {

    // Categories for filter dropdown (always needed)
    $catRows = $pdo->query(
        "SELECT name FROM ref_mi_categories ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($view === 'rm' && $miId !== null) {

        // ── DETAIL VIEW ───────────────────────────────────────────────────────

        // Main MI header row with live stock calc
        $hdrSql = "
            WITH
              anchor AS (
                -- Use ONLY counted_qty (operator's physical count = col G in BSF form).
                -- expected_qty (col F) is a system-predicted guess (sometimes negative) and
                -- must never leak into live_qty when source='carried' (operator didn't count).
                SELECT mi_id_fk,
                       MAX(counted_at) AS anchor_at,
                       (SELECT counted_qty FROM inv_rm_stocktake rm2
                         WHERE rm2.mi_id_fk = rm1.mi_id_fk
                           AND rm2.counted_at = MAX(rm1.counted_at)
                         LIMIT 1) AS anchor_qty
                FROM inv_rm_stocktake rm1
                WHERE mi_id_fk = :mid
                GROUP BY mi_id_fk
              ),
              deliveries_since AS (
                SELECT SUM(d.qty_delivered) AS qty_in
                FROM inv_deliveries d
                JOIN anchor a ON a.mi_id_fk = d.ingredient_fk
                WHERE d.date_received > a.anchor_at
                  AND d.status IN ('Active','Pending')
                  AND d.exclusion_class IS NULL
              ),
              consumption_since AS (
                SELECT SUM(
                  c.qty * COALESCE(
                    CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                         THEN cu.to_base_factor / su.to_base_factor
                         ELSE 1
                    END, 1)
                ) AS qty_out
                FROM inv_consumption c
                JOIN anchor a ON a.mi_id_fk = c.mi_id_fk
                JOIN ref_mi m ON m.id = c.mi_id_fk
                LEFT JOIN ref_units cu ON cu.code = c.unit
                LEFT JOIN ref_units su ON su.code = m.pricing_unit
                WHERE c.consumed_at > a.anchor_at
              ),
              consumption_13w AS (
                SELECT SUM(
                  c.qty * COALESCE(
                    CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                         THEN cu.to_base_factor / su.to_base_factor
                         ELSE 1
                    END, 1)
                ) / 13 AS avg_weekly_qty
                FROM inv_consumption c
                JOIN ref_mi m ON m.id = c.mi_id_fk
                LEFT JOIN ref_units cu ON cu.code = c.unit
                LEFT JOIN ref_units su ON su.code = m.pricing_unit
                WHERE c.mi_id_fk = :mid2
                  AND c.consumed_at >= DATE_SUB(CURDATE(), INTERVAL 91 DAY)
              )
            SELECT m.id, m.mi_id, m.name AS mi_name,
                   c.name AS category, s.name AS subcategory,
                   m.pricing_unit AS unit, m.is_active,
                   m.packaging_hl_equivalent,
                   COALESCE(a.anchor_qty, 0)
                     + COALESCE(ds.qty_in, 0)
                     - COALESCE(cs.qty_out, 0) AS live_qty,
                   -- Display WAC: prefer computed (wac_snapshots, always CHF). Fall back to
                   -- legacy ref_mi.price converted to CHF when currency='EUR' (0.945 rate matches
                   -- all existing inv_deliveries.eur_to_chf entries).
                   COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS wac_chf,
                   CASE WHEN w.wac_chf IS NULL AND m.price IS NOT NULL AND m.price > 0
                        THEN 1 ELSE 0 END AS wac_is_legacy,
                   (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                     * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS stock_value,
                   CASE WHEN cw.avg_weekly_qty > 0
                        THEN (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                             / cw.avg_weekly_qty
                        ELSE NULL END AS weeks_remaining,
                   CASE WHEN m.packaging_hl_equivalent IS NOT NULL
                        THEN (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                             * m.packaging_hl_equivalent
                        ELSE NULL END AS hl_equivalent
              FROM ref_mi m
              LEFT JOIN ref_mi_categories    c ON c.id = m.category_id
              LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
              LEFT JOIN anchor               a ON a.mi_id_fk = m.id
              LEFT JOIN deliveries_since     ds ON 1=1
              LEFT JOIN consumption_since    cs ON 1=1
              LEFT JOIN consumption_13w      cw ON 1=1
              LEFT JOIN wac_snapshots        w  ON w.mi_id_fk = m.id
                AND w.period = (SELECT MAX(period) FROM wac_snapshots WHERE mi_id_fk = :mid3)
             WHERE m.id = :mid4
               AND m.is_inventoried = 1
        ";
        $hdrStmt = $pdo->prepare($hdrSql);
        $hdrStmt->execute([':mid' => $miId, ':mid2' => $miId, ':mid3' => $miId, ':mid4' => $miId]);
        $miRow = $hdrStmt->fetch();

        // Delivery history
        $dStmt = $pdo->prepare("
            SELECT date_received, qty_delivered, pricing_unit, unit_price, currency,
                   supplier_raw, invoice_ref
              FROM inv_deliveries
             WHERE ingredient_fk = :mid
             ORDER BY date_received DESC, id DESC
             LIMIT 20
        ");
        $dStmt->execute([':mid' => $miId]);
        $delivH = $dStmt->fetchAll();

        // Consumption history
        $cStmt = $pdo->prepare("
            SELECT consumed_at, qty, unit, source_event, beer_name, hl_packaged
              FROM inv_consumption
             WHERE mi_id_fk = :mid
             ORDER BY consumed_at DESC, id DESC
             LIMIT 20
        ");
        $cStmt->execute([':mid' => $miId]);
        $consH = $cStmt->fetchAll();

        // Sparkline: full history — anchor + all deliveries + all consumptions for this MI
        // We build time-ordered events and compute running balance
        $anchorStmt = $pdo->prepare("
            SELECT counted_qty AS qty, counted_at
              FROM inv_rm_stocktake
             WHERE mi_id_fk = :mid
               AND counted_at = (SELECT MAX(counted_at) FROM inv_rm_stocktake WHERE mi_id_fk = :mid2)
             LIMIT 1
        ");
        $anchorStmt->execute([':mid' => $miId, ':mid2' => $miId]);
        $anchorRow = $anchorStmt->fetch();

        if ($anchorRow) {
            $anchorDate = substr((string) $anchorRow['counted_at'], 0, 10);
            $anchorQty  = (float) $anchorRow['qty'];

            // Deliveries since anchor (only Active/Pending)
            $spDStmt = $pdo->prepare("
                SELECT DATE(date_received) AS evt_date, SUM(qty_delivered) AS delta
                  FROM inv_deliveries
                 WHERE ingredient_fk = :mid
                   AND date_received > :adate
                   AND status IN ('Active','Pending')
                   AND exclusion_class IS NULL
                 GROUP BY DATE(date_received)
                 ORDER BY evt_date ASC
            ");
            $spDStmt->execute([':mid' => $miId, ':adate' => $anchorDate]);
            $spDeliveries = $spDStmt->fetchAll();

            // Consumption since anchor
            $spCStmt = $pdo->prepare("
                SELECT c.consumed_at AS evt_date, SUM(
                  c.qty * COALESCE(
                    CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                         THEN cu.to_base_factor / su.to_base_factor
                         ELSE 1
                    END, 1)
                ) AS delta
                  FROM inv_consumption c
                  JOIN ref_mi m ON m.id = c.mi_id_fk
                  LEFT JOIN ref_units cu ON cu.code = c.unit
                  LEFT JOIN ref_units su ON su.code = m.pricing_unit
                 WHERE c.mi_id_fk = :mid
                   AND c.consumed_at > :adate
                 GROUP BY c.consumed_at
                 ORDER BY c.consumed_at ASC
            ");
            $spCStmt->execute([':mid' => $miId, ':adate' => $anchorDate]);
            $spConsumptions = $spCStmt->fetchAll();

            // Merge and replay
            $events = [];
            foreach ($spDeliveries as $r) {
                $dt = $r['evt_date'];
                $events[$dt] = ($events[$dt] ?? 0.0) + (float) $r['delta'];
            }
            foreach ($spConsumptions as $r) {
                $dt = $r['evt_date'];
                $events[$dt] = ($events[$dt] ?? 0.0) - (float) $r['delta'];
            }
            ksort($events);

            $sparkPts[] = ['date' => $anchorDate, 'qty' => round($anchorQty, 4)];
            $running = $anchorQty;
            foreach ($events as $dt => $delta) {
                $running += $delta;
                $sparkPts[] = ['date' => $dt, 'qty' => round($running, 4)];
            }
        }

    } elseif ($view === 'rm') {

        // ── LIST VIEW ────────────────────────────────────────────────────────

        // Whitelist both col alias → SQL expression and sort col
        $sortMap = [
            'mi_id'           => 'm.mi_id',
            'mi_name'         => 'm.name',
            'category'        => 'c.name',
            'live_qty'        => 'live_qty',
            'wac_chf'         => "COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))",
            'stock_value'     => 'stock_value',
            'weeks_remaining' => 'weeks_remaining',
            'hl_equivalent'   => 'hl_equivalent',
            'last_delivery'   => 'last_delivery',
        ];
        $orderExpr = $sortMap[$sortCol] . ' ' . $sortDir;

        $listSql = "
            WITH
              anchor AS (
                -- Use ONLY counted_qty (operator's physical count = col G in BSF form).
                -- expected_qty (col F) is a system-predicted guess (sometimes negative) and
                -- must never leak into live_qty when source='carried' (operator didn't count).
                SELECT rm1.mi_id_fk,
                       MAX(rm1.counted_at) AS anchor_at,
                       (SELECT rm2.counted_qty FROM inv_rm_stocktake rm2
                         WHERE rm2.mi_id_fk = rm1.mi_id_fk
                           AND rm2.counted_at = MAX(rm1.counted_at)
                         LIMIT 1) AS anchor_qty
                FROM inv_rm_stocktake rm1
                GROUP BY rm1.mi_id_fk
              ),
              deliveries_since AS (
                SELECT d.ingredient_fk AS mi_id_fk, SUM(d.qty_delivered) AS qty_in
                  FROM inv_deliveries d
                  JOIN anchor a ON a.mi_id_fk = d.ingredient_fk
                 WHERE d.date_received > a.anchor_at
                   AND d.status IN ('Active','Pending')
                   AND d.exclusion_class IS NULL
                 GROUP BY d.ingredient_fk
              ),
              consumption_since AS (
                SELECT c.mi_id_fk, SUM(
                  c.qty * COALESCE(
                    CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                         THEN cu.to_base_factor / su.to_base_factor
                         ELSE 1
                    END, 1)
                ) AS qty_out
                  FROM inv_consumption c
                  JOIN anchor a ON a.mi_id_fk = c.mi_id_fk
                  JOIN ref_mi m ON m.id = c.mi_id_fk
                  LEFT JOIN ref_units cu ON cu.code = c.unit
                  LEFT JOIN ref_units su ON su.code = m.pricing_unit
                 WHERE c.consumed_at > a.anchor_at
                 GROUP BY c.mi_id_fk
              ),
              consumption_13w AS (
                SELECT c.mi_id_fk, SUM(
                  c.qty * COALESCE(
                    CASE WHEN cu.dimension = su.dimension AND su.to_base_factor > 0
                         THEN cu.to_base_factor / su.to_base_factor
                         ELSE 1
                    END, 1)
                ) / 13 AS avg_weekly_qty
                  FROM inv_consumption c
                  JOIN ref_mi m ON m.id = c.mi_id_fk
                  LEFT JOIN ref_units cu ON cu.code = c.unit
                  LEFT JOIN ref_units su ON su.code = m.pricing_unit
                 WHERE c.consumed_at >= DATE_SUB(CURDATE(), INTERVAL 91 DAY)
                 GROUP BY c.mi_id_fk
              )
            SELECT m.id, m.mi_id, m.name AS mi_name,
                   c.name AS category, s.name AS subcategory,
                   m.pricing_unit AS unit, m.is_active,
                   COALESCE(a.anchor_qty, 0)
                     + COALESCE(ds.qty_in, 0)
                     - COALESCE(cs.qty_out, 0) AS live_qty,
                   -- Display WAC: prefer computed (wac_snapshots, always CHF). Fall back to
                   -- legacy ref_mi.price converted to CHF when currency='EUR' (0.945 rate).
                   COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS wac_chf,
                   CASE WHEN w.wac_chf IS NULL AND m.price IS NOT NULL AND m.price > 0
                        THEN 1 ELSE 0 END AS wac_is_legacy,
                   (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                     * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS stock_value,
                   CASE WHEN cw.avg_weekly_qty > 0
                        THEN (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                             / cw.avg_weekly_qty
                        ELSE NULL END AS weeks_remaining,
                   CASE WHEN m.packaging_hl_equivalent IS NOT NULL
                        THEN (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                             * m.packaging_hl_equivalent
                        ELSE NULL END AS hl_equivalent,
                   (SELECT MAX(d2.date_received)
                      FROM inv_deliveries d2
                     WHERE d2.ingredient_fk = m.id) AS last_delivery
              FROM ref_mi m
              LEFT JOIN ref_mi_categories    c ON c.id = m.category_id
              LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
              LEFT JOIN anchor               a ON a.mi_id_fk = m.id
              LEFT JOIN deliveries_since     ds ON ds.mi_id_fk = m.id
              LEFT JOIN consumption_since    cs ON cs.mi_id_fk = m.id
              LEFT JOIN consumption_13w      cw ON cw.mi_id_fk = m.id
              LEFT JOIN wac_snapshots        w  ON w.mi_id_fk = m.id
                AND w.period = (SELECT MAX(period) FROM wac_snapshots WHERE mi_id_fk = m.id)
             WHERE m.is_inventoried = 1
               AND (a.anchor_qty IS NOT NULL
                    OR (COALESCE(a.anchor_qty,0)+COALESCE(ds.qty_in,0)-COALESCE(cs.qty_out,0)) > 0)
               AND (:cat = '' OR c.name = :cat2)
               AND (:q = ''
                    OR m.mi_id LIKE CONCAT('%', :q2, '%')
                    OR m.name  LIKE CONCAT('%', :q3, '%'))
             ORDER BY $orderExpr
        ";

        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute([
            ':cat'  => $cat,
            ':cat2' => $cat,
            ':q'    => $q,
            ':q2'   => $q,
            ':q3'   => $q,
        ]);
        $rows = $listStmt->fetchAll();

        // KPIs from fetched rows (avoids second round-trip for most)
        foreach ($rows as $r) {
            $lq  = (float) ($r['live_qty']   ?? 0);
            $wac = isset($r['wac_chf']) && $r['wac_chf'] !== null ? (float) $r['wac_chf'] : null;
            $sv  = $wac !== null ? $lq * $wac : 0.0;
            $kpis['stock_value'] += $sv;
            if ($lq > 0) $kpis['mis_in_stock']++;
            if ($lq > 0 && $wac === null) $kpis['no_basis_count']++;
            $wr = isset($r['weeks_remaining']) && $r['weeks_remaining'] !== null ? (float) $r['weeks_remaining'] : null;
            if ($wr !== null && $wr < 4) $kpis['burn_critique']++;
            $hl = isset($r['hl_equivalent']) && $r['hl_equivalent'] !== null ? (float) $r['hl_equivalent'] : 0.0;
            $kpis['hl_total'] += $hl;
        }

        // Carried sources KPI — separate query
        $carriedStmt = $pdo->query("
            SELECT COUNT(*) AS cnt
              FROM inv_rm_stocktake
             WHERE source = 'carried'
               AND period = (SELECT MAX(period) FROM inv_rm_stocktake)
        ");
        $carriedRow = $carriedStmt->fetch();
        $kpis['carried'] = (int) ($carriedRow['cnt'] ?? 0);
    }

    if ($view === 'fg') {

        // ── FG: rolling 6-month period picker ─────────────────────────────────
        $today = new DateTimeImmutable('today');
        $defaultPeriodDT = $today->modify('first day of last month');
        for ($i = 0; $i < 6; $i++) {
            $fgPeriods[] = $defaultPeriodDT->modify("-$i months")->format('Y-m');
        }
        $fgPeriod = (in_array($period, $fgPeriods, true)) ? $period : $fgPeriods[0];

        // ── Step 3a — fetch FG counts joined to ref_skus + ref_recipes ────────
        $fgSt = $pdo->prepare("
            SELECT fg.sku                    AS sku_code,
                   fg.qty                    AS qty,
                   s.id                      AS sku_id,
                   ANY_VALUE(s.recipe_id)    AS recipe_id,
                   ANY_VALUE(s.beer_raw)     AS beer_raw,
                   ANY_VALUE(s.format)       AS format,
                   ANY_VALUE(s.unit_label)   AS unit_label,
                   ANY_VALUE(s.hl_per_unit)  AS hl_per_unit,
                   ANY_VALUE(r.name)         AS canonical_beer
              FROM inv_fg_stocktake fg
              LEFT JOIN ref_skus    s ON s.sku_code = fg.sku
              LEFT JOIN ref_recipes r ON r.id = s.recipe_id
             WHERE fg.month_closed = ?
               AND fg.qty > 0
             GROUP BY fg.sku, fg.qty, s.id
             ORDER BY ANY_VALUE(r.name), ANY_VALUE(s.format), fg.sku
        ");
        $fgSt->execute([$fgPeriod]);
        $fgRaw = $fgSt->fetchAll();

        if (!empty($fgRaw)) {
            // ── Step 3b — walk-back liquid cost lookup per beer ────────────────
            // For April FG we want month_key strictly before fgPeriod (brew in N-1).
            $prevPeriod = (new DateTime($fgPeriod . '-01'))->modify('-1 month')->format('Y-m');

            // TEMPORARY: composite-pack liquid composition.
            // Remove once ref_sku_bom / a dedicated composites table holds this.
            $COMPOSITE_PACK_LIQUID = [
                // PD8 = Pack Découverte: 8 × 33cl, 1 bottle of each of 8 core beers (0.0264 HL)
                // Source: BSF CompositePacks tab. PAD was the old BSF header; PD8 is canonical (inv_fg_stocktake renamed 2026-05-21).
                'PD8' => ['Zepp' => 1, 'Diversion' => 1, 'Double Oat' => 1, 'Embuscade' => 1,
                          'Moonshine' => 1, 'Speakeasy' => 1, 'Stirling' => 1, 'Alternative' => 1],
                // PAL = Pack Louis: 12 × 33cl, 2 bottles each of 6 beers (0.396 HL)
                'PAL' => ['Speakeasy' => 2, 'Alternative' => 2, 'Diversion Blanche' => 2,
                          'Double Oat' => 2, 'Embuscade' => 2, 'Moonshine' => 2],
            ];
            $BOTTLE_HL = 0.0033;  // 33cl = 0.33 L = 0.0033 HL (1 HL = 100 L)

            $beerCostMap = [];   // canonical_beer => ['month'=>'YYYY-MM','cost_per_hl'=>float,'gl_split'=>[GL=>cost/hl]]

            $uniqueBeers = array_values(array_unique(array_filter(
                array_column($fgRaw, 'canonical_beer')
            )));

            // Also ensure constituent beers from composite packs are walked back
            // so $beerCostMap is populated for them even if they have no FG row of their own.
            foreach ($COMPOSITE_PACK_LIQUID as $compSku => $constituents) {
                foreach (array_keys($constituents) as $constituentBeer) {
                    if (!in_array($constituentBeer, $uniqueBeers, true)) {
                        $uniqueBeers[] = $constituentBeer;
                    }
                }
            }

            foreach ($uniqueBeers as $beer) {
                // Walk back: most recent month_key <= prevPeriod with non-null brew_cost_per_hl
                // Volume-weighted average across tanks for that month
                $costSt = $pdo->prepare("
                    SELECT month_key,
                           SUM(volume_hl * brew_cost_per_hl) / NULLIF(SUM(volume_hl), 0) AS cost_per_hl
                      FROM inv_tank_balances
                     WHERE beer_name COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                       AND month_key <= ?
                       AND brew_cost_per_hl IS NOT NULL
                     GROUP BY month_key
                    HAVING cost_per_hl IS NOT NULL
                     ORDER BY month_key DESC
                     LIMIT 1
                ");
                $costSt->execute([$beer, $prevPeriod]);
                $costRow = $costSt->fetch();

                if (!$costRow || $costRow['cost_per_hl'] === null) {
                    $beerCostMap[$beer] = ['month' => null, 'cost_per_hl' => null, 'gl_split' => []];
                    continue;
                }

                $costMonth = (string) $costRow['month_key'];
                $costPerHl = (float)  $costRow['cost_per_hl'];

                // GL split: derive ratio of each GL's ingredient cost in that brew month
                // Uses bd_brewing_ingredients_parsed for the (beer, month) pair.
                // ANY_VALUE(c.default_gl_account) groups by gl text (sql #1 ONLY_FULL_GROUP_BY).
                // COLLATE on JOIN across bd_* (0900) and ref_* (unicode_ci) tables (sql #2).
                $glSt = $pdo->prepare("
                    SELECT c.default_gl_account AS gl,
                           SUM(bip.qty * IF(bip.unit='g', 0.001, 1)
                               * COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))) AS gl_cost
                      FROM bd_brewing_ingredients_parsed bip
                      JOIN ref_mi m ON m.id = bip.mi_id_fk
                      LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                      LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
                     WHERE bip.beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                       AND bip.mi_id_fk IS NOT NULL
                       AND EXISTS (
                         SELECT 1 FROM bd_brewing_cooling bc
                          WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = bip.beer  COLLATE utf8mb4_unicode_ci
                            AND bc.cool_batch COLLATE utf8mb4_unicode_ci = bip.batch COLLATE utf8mb4_unicode_ci
                            AND DATE_FORMAT(bc.event_date, '%Y-%m') = ?
                       )
                     GROUP BY c.default_gl_account
                ");
                $glSt->execute([$costMonth, $beer, $costMonth]);
                $glTotalRows  = $glSt->fetchAll();
                $totalGlCost  = (float) array_sum(array_column($glTotalRows, 'gl_cost'));
                $glSplit = [];
                foreach ($glTotalRows as $glr) {
                    $gl = (string) ($glr['gl'] ?? '');
                    if ($totalGlCost > 0 && $gl !== '') {
                        // Store cost/HL for this GL (proportional to total brew cost/HL)
                        $glSplit[$gl] = $costPerHl * ((float) $glr['gl_cost'] / $totalGlCost);
                    }
                }

                $beerCostMap[$beer] = [
                    'month'       => $costMonth,
                    'cost_per_hl' => $costPerHl,
                    'gl_split'    => $glSplit,
                ];
            }

            // ── Step 3c — packaging BOM per SKU ───────────────────────────────
            // Aggregate packaging cost per SKU, split by GL account.
            // All packaging MIs currently share GL 4200 but query dynamically
            // so future subcategories (4201/4202/etc.) are auto-handled.
            $skuIds = array_values(array_unique(array_filter(array_column($fgRaw, 'sku_id'))));
            $bomMap = [];   // sku_id => ['total_chf_per_unit'=>float, 'gl_split'=>[GL=>float]]

            if (!empty($skuIds)) {
                $phBom = implode(',', array_fill(0, count($skuIds), '?'));
                $bomSt = $pdo->prepare("
                    SELECT b.sku_id,
                           COALESCE(c.default_gl_account, '4200') AS gl,
                           SUM(b.cost) AS gl_cost
                      FROM ref_sku_bom b
                      JOIN ref_mi m ON m.id = b.mi_id
                      LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                     WHERE b.sku_id IN ($phBom)
                       AND m.mi_id LIKE 'PKG\\_%' ESCAPE '\\\\'
                     GROUP BY b.sku_id, COALESCE(c.default_gl_account, '4200')
                ");
                $bomSt->execute($skuIds);
                foreach ($bomSt->fetchAll() as $br) {
                    $sid = (int) $br['sku_id'];
                    $gl  = (string) $br['gl'];
                    $glCost = (float) $br['gl_cost'];
                    $bomMap[$sid]['gl_split'][$gl]        = ($bomMap[$sid]['gl_split'][$gl] ?? 0.0) + $glCost;
                    $bomMap[$sid]['total_chf_per_unit']    = ($bomMap[$sid]['total_chf_per_unit'] ?? 0.0) + $glCost;
                }
            }

            // ── Step 3d — build display rows + KPIs + GL recap ────────────────
            foreach ($fgRaw as $r) {
                $skuCode    = (string) $r['sku_code'];
                $beer       = (string) ($r['canonical_beer'] ?? $r['beer_raw'] ?? '—');
                $qty        = (int)   $r['qty'];
                $hlPerUnit  = (float) ($r['hl_per_unit'] ?? 0.0);
                $hlEquiv    = $qty * $hlPerUnit;
                $skuId      = (int)   ($r['sku_id'] ?? 0);

                // Composite-pack override: sum constituent liquid costs instead of
                // single-recipe walk-back.
                if (isset($COMPOSITE_PACK_LIQUID[$skuCode])) {
                    $liquidPerUnit = 0.0;
                    $glBreakdown   = [];
                    $noCostBasis   = false;
                    foreach ($COMPOSITE_PACK_LIQUID[$skuCode] as $constituentBeer => $bottles) {
                        $cbc = $beerCostMap[$constituentBeer] ?? null;
                        if (!$cbc || $cbc['cost_per_hl'] === null) {
                            $noCostBasis = true;
                            $liquidPerUnit = null;
                            break;
                        }
                        $constituentHl   = $bottles * $BOTTLE_HL;
                        $liquidPerUnit  += $constituentHl * $cbc['cost_per_hl'];
                        foreach ($cbc['gl_split'] as $gl => $costPerHlGl) {
                            $glBreakdown[$gl] = ($glBreakdown[$gl] ?? 0.0) + ($qty * $constituentHl * $costPerHlGl);
                        }
                    }
                    $packagingPerUnit = $bomMap[$skuId]['total_chf_per_unit'] ?? 0.0;
                    if ($skuId > 0 && !empty($bomMap[$skuId]['gl_split'])) {
                        foreach ($bomMap[$skuId]['gl_split'] as $gl => $costPerUnitGl) {
                            $glBreakdown[$gl] = ($glBreakdown[$gl] ?? 0.0) + ($qty * $costPerUnitGl);
                        }
                    }
                    $totalPerUnit = ($liquidPerUnit ?? 0.0) + $packagingPerUnit;
                    $totalChf     = $qty * $totalPerUnit;
                    foreach ($glBreakdown as $gl => $chf) {
                        $fgGlRecap[$gl] = ($fgGlRecap[$gl] ?? 0.0) + $chf;
                    }
                    $fgRows[] = [
                        'sku_code'           => $skuCode,
                        'beer'               => $beer,
                        'format'             => (string) ($r['format']     ?? '—'),
                        'unit_label'         => (string) ($r['unit_label'] ?? '—'),
                        'qty'                => $qty,
                        'hl_equiv'           => $hlEquiv,
                        'liquid_per_unit'    => $liquidPerUnit,
                        'packaging_per_unit' => $packagingPerUnit,
                        'total_per_unit'     => $totalPerUnit,
                        'total_chf'          => $totalChf,
                        'gl_breakdown'       => $glBreakdown,
                        'cost_source_month'  => null,
                        'no_cost_basis'      => $noCostBasis ?? false,
                    ];
                    $fgKpis['valeur_fg']        += $totalChf;
                    $fgKpis['hl_total']          += $hlEquiv;
                    $fgKpis['valeur_liquide']    += ($liquidPerUnit !== null) ? $qty * $liquidPerUnit : 0.0;
                    $fgKpis['valeur_emballage']  += $qty * $packagingPerUnit;
                    continue;
                }

                $bc = $beerCostMap[$beer] ?? null;
                $liquidPerUnit  = ($bc && $bc['cost_per_hl'] !== null)
                    ? $hlPerUnit * $bc['cost_per_hl']
                    : null;
                $packagingPerUnit = $bomMap[$skuId]['total_chf_per_unit'] ?? 0.0;
                $totalPerUnit   = ($liquidPerUnit ?? 0.0) + $packagingPerUnit;
                $totalChf       = $qty * $totalPerUnit;

                // GL breakdown for this SKU row
                $glBreakdown = [];
                if ($bc && !empty($bc['gl_split'])) {
                    foreach ($bc['gl_split'] as $gl => $costPerHlGl) {
                        $glBreakdown[$gl] = ($glBreakdown[$gl] ?? 0.0) + ($qty * $hlPerUnit * $costPerHlGl);
                    }
                }
                if ($skuId > 0 && !empty($bomMap[$skuId]['gl_split'])) {
                    foreach ($bomMap[$skuId]['gl_split'] as $gl => $costPerUnitGl) {
                        $glBreakdown[$gl] = ($glBreakdown[$gl] ?? 0.0) + ($qty * $costPerUnitGl);
                    }
                }

                // Accumulate GL recap
                foreach ($glBreakdown as $gl => $chf) {
                    $fgGlRecap[$gl] = ($fgGlRecap[$gl] ?? 0.0) + $chf;
                }

                $fgRows[] = [
                    'sku_code'           => (string) $r['sku_code'],
                    'beer'               => $beer,
                    'format'             => (string) ($r['format']     ?? '—'),
                    'unit_label'         => (string) ($r['unit_label'] ?? '—'),
                    'qty'                => $qty,
                    'hl_equiv'           => $hlEquiv,
                    'liquid_per_unit'    => $liquidPerUnit,
                    'packaging_per_unit' => $packagingPerUnit,
                    'total_per_unit'     => $totalPerUnit,
                    'total_chf'          => $totalChf,
                    'gl_breakdown'       => $glBreakdown,
                    'cost_source_month'  => $bc['month'] ?? null,
                    'no_cost_basis'      => ($bc === null || $bc['cost_per_hl'] === null),
                ];

                $fgKpis['valeur_fg']       += $totalChf;
                $fgKpis['hl_total']        += $hlEquiv;
                $fgKpis['valeur_liquide']  += ($liquidPerUnit !== null) ? $qty * $liquidPerUnit : 0.0;
                $fgKpis['valeur_emballage'] += $qty * $packagingPerUnit;
            }
            $fgKpis['sku_count'] = count($fgRows);
        }
    }

    if ($view === 'wip') {

        // ── WIP: use the proven TankSimulator (same engine as /modules/tanks.php) ──
        require_once __DIR__ . '/../../app/tank-simulator.php';

        /* TEMPORARY HARDCODE — remove when maltyweb-native ingredient form ships */
        // Patches a gap where bd_brewing_ingredients_parsed.category ENUM only covers
        // malt/hops_kettle/hops_dry — no slot for adjuncts, process aids, or microbial
        // stabilizers — so GL 4104 ingredients are never captured via the form.
        // REMOVE this entire block once the native inputting form writes these directly
        // to bd_brewing_ingredients_parsed.
        $HARDCODED_INGREDIENT_RULES = [
            // per-brew rules keyed by canonical beer name
            'Moonshine' => [
                ['mi_id' => 'ADJ_CORIANDER',   'qty_per_brew_kg' => 2.2],
                ['mi_id' => 'ADJ_ORANGE_PEEL',  'qty_per_brew_kg' => 3.3],
            ],
            'Stirling' => [
                // skip_if_in_parsed: true → omit if PROC_DEHAZE already appears in
                // bd_brewing_ingredients_parsed for this (beer, batch) to avoid double-count
                ['mi_id' => 'PROC_DEHAZE', 'qty_per_brew_kg' => 0.150, 'skip_if_in_parsed' => true],
            ],
            'Diversion Blanche' => [
                ['mi_id' => 'ADJ_PEACH_TEA', 'qty_per_brew_kg' => 4.0],
            ],
        ];
        // Yeast vitalizer: all Neb beers EXCEPT the three below.
        // $HC_YEASTVIT_EXCLUDE: canonical names of beers where PROC_YEASTVIT is NOT added.
        $HC_YEASTVIT_EXCLUDE = ['Diversion', 'Diversion Blanche', 'Alternative'];
        $HC_YEASTVIT_RULE    = ['mi_id' => 'PROC_YEASTVIT', 'qty_per_brew_kg' => 0.240];
        // Nagardo: 2 g/HL of BBT volume only for Diversion + Diversion Blanche.
        // qty_per_hl_kg = 0.002 (2 g = 0.002 kg per HL).
        $HC_NAGARDO_BEERS = ['Diversion', 'Diversion Blanche'];
        $HC_NAGARDO_RULE  = ['mi_id' => 'PROC_NAGARDO', 'qty_per_hl_kg' => 0.002];

        // Collect all unique MI IDs across all rules (for the batched price query below).
        // PROC_YEASTVIT and PROC_NAGARDO are included unconditionally — they'll only be
        // applied to qualifying beers at runtime.
        $HC_ALL_MI_IDS = array_unique(array_merge(
            ['PROC_YEASTVIT', 'PROC_NAGARDO'],
            ...array_values(array_map(fn($rules) => array_column($rules, 'mi_id'), $HARDCODED_INGREDIENT_RULES))
        ));

        // Query Neb beer names once (PROC_YEASTVIT scope).
        // Result used during the per-tank loop to decide whether to apply the rule.
        $HC_NEB_BEERS = [];   // flat list of canonical Neb beer names
        {
            $nebStmt = $pdo->query(
                "SELECT name FROM ref_recipes WHERE classification = 'Neb'"
            );
            foreach ($nebStmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $HC_NEB_BEERS[] = (string) $n;
            }
        }
        $HC_YEASTVIT_BEERS = array_values(array_filter(
            $HC_NEB_BEERS,
            fn(string $n) => !in_array($n, $HC_YEASTVIT_EXCLUDE, true)
        ));
        /* END TEMPORARY HARDCODE */

        // Price lookup ($hardcodedMiPrices) is populated after $wipPeriod is resolved below.
        $hardcodedMiPrices   = [];   // mi_id_string => ['id'=>int,'name'=>str,'gl'=>str,'unit'=>str,'unit_price_chf'=>float]
        $hardcodedMiWarnings = [];   // mi_id_string => warning message

        // Period dropdown: rolling 6 months ending at last closed month.
        // "Closed month" = last full calendar month (today's month-1).
        $today = new DateTimeImmutable('today');
        $defaultPeriodDT = $today->modify('first day of last month');
        $wipPeriods = [];
        for ($i = 0; $i < 6; $i++) {
            $wipPeriods[] = $defaultPeriodDT->modify("-$i months")->format('Y-m');
        }

        if ($period !== '' && in_array($period, $wipPeriods, true)) {
            $wipPeriod = $period;
        } else {
            $wipPeriod = $wipPeriods[0];
        }

        /* TEMPORARY HARDCODE — resolve MI unit prices (CHF/kg) for all hardcoded rules */
        // Effective price = COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)).
        // Keyed by mi_id string. Missing MIs emit a warning and are skipped — no crash.
        {
            if (!empty($HC_ALL_MI_IDS)) {
                $hcPlaceholders  = implode(',', array_fill(0, count($HC_ALL_MI_IDS), '?'));
                // param order: $wipPeriod first (for ws.period = ?), then mi_id list
                $hcParamsOrdered = [$wipPeriod];
                foreach ($HC_ALL_MI_IDS as $hcMid) { $hcParamsOrdered[] = $hcMid; }
                $hcSql = "
                    SELECT m.mi_id,
                           m.id,
                           m.name,
                           ANY_VALUE(c.default_gl_account) AS default_gl_account,
                           m.pricing_unit,
                           COALESCE(ws.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1)) AS unit_price_chf
                      FROM ref_mi m
                      LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                      LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = m.id AND ws.period = ?
                     WHERE m.mi_id IN ($hcPlaceholders)
                     GROUP BY m.id, m.mi_id, m.name, m.pricing_unit, ws.wac_chf, m.price, m.currency
                ";
                $hcStmt = $pdo->prepare($hcSql);
                $hcStmt->execute($hcParamsOrdered);
                foreach ($hcStmt->fetchAll() as $hcRow) {
                    $hardcodedMiPrices[(string) $hcRow['mi_id']] = [
                        'id'             => (int)    $hcRow['id'],
                        'name'           => (string) $hcRow['name'],
                        'gl'             => (string) ($hcRow['default_gl_account'] ?? '4104'),
                        'unit'           => (string) ($hcRow['pricing_unit'] ?? 'kg'),
                        'unit_price_chf' => (float)  ($hcRow['unit_price_chf'] ?? 0),
                    ];
                }
                foreach ($HC_ALL_MI_IDS as $hcMid) {
                    if (!isset($hardcodedMiPrices[$hcMid])) {
                        $hardcodedMiWarnings[$hcMid] = "MI '$hcMid' introuvable dans ref_mi — ligne ignorée";
                    }
                }
            }
        }
        /* END TEMPORARY HARDCODE — price lookup */

        // As-of date = last day of selected month
        [$y, $m] = array_map('intval', explode('-', $wipPeriod));
        $asOfDT  = (new DateTimeImmutable())->setDate($y, $m, 1)->modify('last day of this month')->setTime(23, 59, 59);

        // ── Run the canonical tank simulator ──────────────────────────────────
        $sim     = new TankSimulator($pdo);
        $simRes  = $sim->run($asOfDT);

        // Collect (beer, batch, volume_hl) tuples from CCT + BBT
        $tankTuples = [];   // array of ['type'=>'CCT'|'BBT', 'tank'=>num, 'beer'=>'raw', 'batch'=>'N', 'volume_hl'=>X]
        foreach (($simRes['cct'] ?? []) as $num => $st) {
            if ($st === null) continue;
            $tankTuples[] = [
                'type' => 'CCT', 'tank' => (int) $num,
                'beer' => $st['raw_beer'] ?? $st['beer'] ?? '',
                'batch' => (string) ($st['batch'] ?? ''),
                'volume_hl' => (float) ($st['volume_hl'] ?? 0),
            ];
        }
        foreach (($simRes['bbt'] ?? []) as $num => $st) {
            if ($st === null) continue;
            $tankTuples[] = [
                'type' => 'BBT', 'tank' => (int) $num,
                'beer' => $st['raw_beer'] ?? $st['beer'] ?? '',
                'batch' => (string) ($st['batch'] ?? ''),
                'volume_hl' => (float) ($st['volume_hl'] ?? 0),
            ];
        }

        // ── Per-batch brew cost map (single query, per-GL breakdown) ─────────
        $brewCostByKeyGl = [];   // "beer|batch" → ['4101'=>x,'4102'=>y,'4104'=>z,'other'=>w,'total'=>t] CHF/HL
        $batchBrewMeta   = [];   // "beer|batch" → ['n'=>int,'hl'=>float] — populated inside TEMPORARY block
        $uniqueBatches = [];
        foreach ($tankTuples as $t) {
            $key = $t['beer'] . '|' . $t['batch'];
            if ($t['batch'] !== '' && !isset($uniqueBatches[$key])) {
                $uniqueBatches[$key] = ['beer' => $t['beer'], 'batch' => $t['batch']];
            }
        }
        if (!empty($uniqueBatches)) {
            $params = [];
            foreach ($uniqueBatches as $ub) {
                $params[] = $ub['beer']; $params[] = $ub['batch'];
            }
            // One $wipPeriod per correlated subquery (4101, 4102, 4104, other)
            $params[] = $wipPeriod;
            $params[] = $wipPeriod;
            $params[] = $wipPeriod;
            $params[] = $wipPeriod;
            $batchCostSql = "
                WITH wanted AS (
                  SELECT * FROM (VALUES " . implode(',', array_fill(0, count($uniqueBatches), 'ROW(?, ?)')) . ") AS v(beer, batch)
                )
                SELECT w.beer COLLATE utf8mb4_unicode_ci AS beer,
                       w.batch COLLATE utf8mb4_unicode_ci AS batch,
                       (SELECT COUNT(*) FROM bd_brewing_cooling bc
                         WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bc.cool_final_volume_hl > 0) AS n_brews,
                       (SELECT SUM(bc.cool_final_volume_hl) FROM bd_brewing_cooling bc
                         WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bc.cool_final_volume_hl > 0) AS total_hl,
                       (SELECT SUM(CASE WHEN c.default_gl_account = '4101'
                                        THEN bip.qty * IF(bip.unit='g', 0.001, 1)
                                             * COALESCE(ws.wac_chf, mi.price * IF(mi.currency='EUR', 0.945, 1))
                                        ELSE 0 END)
                          FROM bd_brewing_ingredients_parsed bip
                          JOIN ref_mi mi ON mi.id = bip.mi_id_fk
                          LEFT JOIN ref_mi_categories c ON c.id = mi.category_id
                          LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = mi.id AND ws.period = ?
                         WHERE bip.beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bip.batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bip.mi_id_fk IS NOT NULL) AS one_brew_4101_chf,
                       (SELECT SUM(CASE WHEN c.default_gl_account = '4102'
                                        THEN bip.qty * IF(bip.unit='g', 0.001, 1)
                                             * COALESCE(ws.wac_chf, mi.price * IF(mi.currency='EUR', 0.945, 1))
                                        ELSE 0 END)
                          FROM bd_brewing_ingredients_parsed bip
                          JOIN ref_mi mi ON mi.id = bip.mi_id_fk
                          LEFT JOIN ref_mi_categories c ON c.id = mi.category_id
                          LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = mi.id AND ws.period = ?
                         WHERE bip.beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bip.batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bip.mi_id_fk IS NOT NULL) AS one_brew_4102_chf,
                       (SELECT SUM(CASE WHEN c.default_gl_account = '4104'
                                        THEN bip.qty * IF(bip.unit='g', 0.001, 1)
                                             * COALESCE(ws.wac_chf, mi.price * IF(mi.currency='EUR', 0.945, 1))
                                        ELSE 0 END)
                          FROM bd_brewing_ingredients_parsed bip
                          JOIN ref_mi mi ON mi.id = bip.mi_id_fk
                          LEFT JOIN ref_mi_categories c ON c.id = mi.category_id
                          LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = mi.id AND ws.period = ?
                         WHERE bip.beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bip.batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bip.mi_id_fk IS NOT NULL) AS one_brew_4104_chf,
                       (SELECT SUM(CASE WHEN c.default_gl_account NOT IN ('4101','4102','4104')
                                             OR c.default_gl_account IS NULL
                                        THEN bip.qty * IF(bip.unit='g', 0.001, 1)
                                             * COALESCE(ws.wac_chf, mi.price * IF(mi.currency='EUR', 0.945, 1))
                                        ELSE 0 END)
                          FROM bd_brewing_ingredients_parsed bip
                          JOIN ref_mi mi ON mi.id = bip.mi_id_fk
                          LEFT JOIN ref_mi_categories c ON c.id = mi.category_id
                          LEFT JOIN wac_snapshots ws ON ws.mi_id_fk = mi.id AND ws.period = ?
                         WHERE bip.beer  COLLATE utf8mb4_unicode_ci = w.beer COLLATE utf8mb4_unicode_ci
                           AND bip.batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                           AND bip.mi_id_fk IS NOT NULL) AS one_brew_other_chf
                  FROM wanted w
            ";
            $cs = $pdo->prepare($batchCostSql);
            $cs->execute($params);
            foreach ($cs->fetchAll() as $row) {
                $n    = (int)   ($row['n_brews']  ?? 0);
                $hl   = (float) ($row['total_hl'] ?? 0);
                if ($n > 0 && $hl > 0) {
                    $gl4101 = (float) ($row['one_brew_4101_chf'] ?? 0);
                    $gl4102 = (float) ($row['one_brew_4102_chf'] ?? 0);
                    $gl4104 = (float) ($row['one_brew_4104_chf'] ?? 0);
                    $other  = (float) ($row['one_brew_other_chf'] ?? 0);
                    $total  = $gl4101 + $gl4102 + $gl4104 + $other;
                    $brewCostByKeyGl[$row['beer'] . '|' . $row['batch']] = [
                        '4101'  => ($gl4101 * $n) / $hl,
                        '4102'  => ($gl4102 * $n) / $hl,
                        '4104'  => ($gl4104 * $n) / $hl,
                        'other' => ($other  * $n) / $hl,
                        'total' => ($total  * $n) / $hl,
                    ];
                }
            }

            /* TEMPORARY HARDCODE — merge all hardcoded ingredient costs into $brewCostByKeyGl */
            // Covers: per-brew rules (HARDCODED_INGREDIENT_RULES), Neb-wide PROC_YEASTVIT,
            // and BBT-only per-HL PROC_NAGARDO. Skipped when total_brewed_hl = 0 (sql #15).
            $batchBrewMeta = [];   // "beer|batch" => ['n'=>int,'hl'=>float]
            {
                // Collect (beer, batch) pairs that need n_brews + total_hl metadata.
                // Includes: beers with per-brew rules, YEASTVIT beers, NAGARDO beers.
                $batchMetaParams = [];
                $batchMetaTuples = [];
                foreach ($tankTuples as $t) {
                    if ($t['batch'] === '') continue;
                    $beerName = $t['beer'];
                    $needsMeta = isset($HARDCODED_INGREDIENT_RULES[$beerName])
                        || in_array($beerName, $HC_YEASTVIT_BEERS, true)
                        || in_array($beerName, $HC_NAGARDO_BEERS, true);
                    if (!$needsMeta) continue;
                    $bk = $beerName . '|' . $t['batch'];
                    if (!isset($batchBrewMeta[$bk])) {
                        $batchMetaTuples[] = 'ROW(?, ?)';
                        $batchMetaParams[] = $beerName;
                        $batchMetaParams[] = $t['batch'];
                        $batchBrewMeta[$bk] = null; // placeholder — filled below
                    }
                }
                if (!empty($batchMetaTuples)) {
                    $bmSql = "
                        WITH wanted AS (
                          SELECT * FROM (VALUES " . implode(',', $batchMetaTuples) . ") AS v(beer, batch)
                        )
                        SELECT w.beer COLLATE utf8mb4_unicode_ci  AS beer,
                               w.batch COLLATE utf8mb4_unicode_ci AS batch,
                               (SELECT COUNT(*) FROM bd_brewing_cooling bc
                                 WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer  COLLATE utf8mb4_unicode_ci
                                   AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                                   AND bc.cool_final_volume_hl > 0) AS n_brews,
                               (SELECT SUM(bc.cool_final_volume_hl) FROM bd_brewing_cooling bc
                                 WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = w.beer  COLLATE utf8mb4_unicode_ci
                                   AND bc.cool_batch COLLATE utf8mb4_unicode_ci = w.batch COLLATE utf8mb4_unicode_ci
                                   AND bc.cool_final_volume_hl > 0) AS total_hl
                          FROM wanted w
                    ";
                    $bmStmt = $pdo->prepare($bmSql);
                    $bmStmt->execute($batchMetaParams);
                    foreach ($bmStmt->fetchAll() as $bmRow) {
                        $bk = $bmRow['beer'] . '|' . $bmRow['batch'];
                        $batchBrewMeta[$bk] = [
                            'n'  => (int)   ($bmRow['n_brews']  ?? 0),
                            'hl' => (float) ($bmRow['total_hl'] ?? 0),
                        ];
                    }
                }

                // Pre-fetch skip_if_in_parsed flags for PROC_DEHAZE / Stirling.
                // Query bd_brewing_ingredients_parsed for any Stirling batch in batchBrewMeta.
                // Result: set of "beer|batch" keys where PROC_DEHAZE is already present.
                $HC_DEHAZE_PARSED_BATCHES = [];
                {
                    $stirBatches = [];
                    foreach ($batchBrewMeta as $bk => $meta) {
                        if ($meta === null) continue;
                        $parts = explode('|', $bk, 2);
                        if (($parts[0] ?? '') === 'Stirling') {
                            $stirBatches[] = $bk;
                        }
                    }
                    if (!empty($stirBatches)) {
                        $stirParams  = [];
                        $stirTuples  = [];
                        foreach ($stirBatches as $bk) {
                            [$b, $bt] = explode('|', $bk, 2);
                            $stirTuples[] = 'ROW(?, ?)';
                            $stirParams[] = $b;
                            $stirParams[] = $bt;
                        }
                        // Get ref_mi.id for PROC_DEHAZE — use a subquery to stay parameterised.
                        $dehazeSql = "
                            SELECT DISTINCT bip.beer COLLATE utf8mb4_unicode_ci AS beer,
                                            bip.batch COLLATE utf8mb4_unicode_ci AS batch
                              FROM bd_brewing_ingredients_parsed bip
                              JOIN ref_mi m ON m.id = bip.mi_id_fk
                                           AND m.mi_id = 'PROC_DEHAZE'
                             WHERE (bip.beer, bip.batch) IN (
                               SELECT * FROM (VALUES " . implode(',', $stirTuples) . ") AS v(beer, batch)
                             )
                        ";
                        // Note: (beer, batch) IN (VALUES ...) requires COLLATE on the CTE values side.
                        // Simpler: use explicit OR pairs for small sets.
                        if (count($stirBatches) === 1) {
                            [$sb, $sbt] = explode('|', $stirBatches[0], 2);
                            $dehazeSql2 = "
                                SELECT DISTINCT bip.beer  COLLATE utf8mb4_unicode_ci AS beer,
                                                bip.batch COLLATE utf8mb4_unicode_ci AS batch
                                  FROM bd_brewing_ingredients_parsed bip
                                  JOIN ref_mi m ON m.id = bip.mi_id_fk
                                 WHERE m.mi_id = 'PROC_DEHAZE'
                                   AND bip.beer  COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                                   AND bip.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
                            ";
                            $dhStmt = $pdo->prepare($dehazeSql2);
                            $dhStmt->execute([$sb, $sbt]);
                        } else {
                            $orClauses = implode(' OR ', array_fill(0, count($stirBatches),
                                '(bip.beer COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci AND bip.batch COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci)'));
                            $dehazeSqlFull = "
                                SELECT DISTINCT bip.beer  COLLATE utf8mb4_unicode_ci AS beer,
                                                bip.batch COLLATE utf8mb4_unicode_ci AS batch
                                  FROM bd_brewing_ingredients_parsed bip
                                  JOIN ref_mi m ON m.id = bip.mi_id_fk
                                 WHERE m.mi_id = 'PROC_DEHAZE'
                                   AND ($orClauses)
                            ";
                            $dhStmt = $pdo->prepare($dehazeSqlFull);
                            $dhStmt->execute($stirParams);
                        }
                        foreach ($dhStmt->fetchAll() as $dhRow) {
                            $HC_DEHAZE_PARSED_BATCHES[$dhRow['beer'] . '|' . $dhRow['batch']] = true;
                        }
                    }
                }
            }

            // Apply per-brew hardcoded rules (HARDCODED_INGREDIENT_RULES + YEASTVIT) to $brewCostByKeyGl.
            foreach ($batchBrewMeta as $bk => $meta) {
                if ($meta === null || $meta['n'] <= 0 || $meta['hl'] <= 0) continue;
                $bkParts  = explode('|', $bk, 2);
                $beerName = $bkParts[0] ?? '';

                $hcDelta4104 = 0.0;

                // Per-beer rules from $HARDCODED_INGREDIENT_RULES
                foreach (($HARDCODED_INGREDIENT_RULES[$beerName] ?? []) as $rule) {
                    if (isset($hardcodedMiWarnings[$rule['mi_id']])) continue;
                    // skip_if_in_parsed: omit when the MI is already in bd_brewing_ingredients_parsed
                    if (!empty($rule['skip_if_in_parsed']) && isset($HC_DEHAZE_PARSED_BATCHES[$bk])) continue;
                    $miPrice = $hardcodedMiPrices[$rule['mi_id']]['unit_price_chf'] ?? 0.0;
                    $hcDelta4104 += $rule['qty_per_brew_kg'] * $miPrice;
                }

                // PROC_YEASTVIT: all Neb beers except $HC_YEASTVIT_EXCLUDE
                if (in_array($beerName, $HC_YEASTVIT_BEERS, true)
                    && !isset($hardcodedMiWarnings[$HC_YEASTVIT_RULE['mi_id']])) {
                    $yvPrice = $hardcodedMiPrices[$HC_YEASTVIT_RULE['mi_id']]['unit_price_chf'] ?? 0.0;
                    $hcDelta4104 += $HC_YEASTVIT_RULE['qty_per_brew_kg'] * $yvPrice;
                }

                if ($hcDelta4104 <= 0.0) continue;  // nothing to add for this batch

                // hcDelta4104 = CHF for ONE brew; scale to CHF/HL
                $perHlDelta = ($hcDelta4104 * $meta['n']) / $meta['hl'];
                if (isset($brewCostByKeyGl[$bk])) {
                    $brewCostByKeyGl[$bk]['4104']  += $perHlDelta;
                    $brewCostByKeyGl[$bk]['total'] += $perHlDelta;
                } else {
                    $brewCostByKeyGl[$bk] = [
                        '4101'  => 0.0, '4102'  => 0.0, '4104'  => $perHlDelta,
                        'other' => 0.0, 'total' => $perHlDelta,
                    ];
                }
            }

            // PROC_NAGARDO: per-HL of BBT volume (not per-brew). Applied per-tank below.
            // We inject the delta tank-by-tank in the foreach ($tankTuples) loop that
            // builds $wipCcts/$wipBbts, so the GL split already carries the BBT-specific cost.
            // Here we only pre-compute the per-HL price so it's ready for that loop.
            $HC_NAGARDO_PRICE_PER_HL = 0.0;
            if (!isset($hardcodedMiWarnings[$HC_NAGARDO_RULE['mi_id']])) {
                $HC_NAGARDO_PRICE_PER_HL =
                    ($hardcodedMiPrices[$HC_NAGARDO_RULE['mi_id']]['unit_price_chf'] ?? 0.0)
                    * $HC_NAGARDO_RULE['qty_per_hl_kg'];
            }
            /* END TEMPORARY HARDCODE — batchBrewMeta + cost merge */
        }

        // ── Build $wipCcts / $wipBbts rows + KPIs ─────────────────────────────
        foreach ($tankTuples as $t) {
            $key    = $t['beer'] . '|' . $t['batch'];
            $glData = $brewCostByKeyGl[$key] ?? null;
            $vol    = $t['volume_hl'];

            /* TEMPORARY HARDCODE — PROC_NAGARDO BBT per-HL injection */
            // Nagardo is applied to the BBT volume directly (2 g/HL),
            // not per-brew. We add it to this tank's cost only when:
            //   - tank is BBT
            //   - beer is Diversion or Diversion Blanche
            //   - PROC_NAGARDO price resolved without warning
            $nagarDeltaPerHl = 0.0;
            if ($t['type'] === 'BBT'
                && in_array($t['beer'], $HC_NAGARDO_BEERS, true)
                && $HC_NAGARDO_PRICE_PER_HL > 0.0) {
                $nagarDeltaPerHl = $HC_NAGARDO_PRICE_PER_HL;
            }
            /* END TEMPORARY HARDCODE — NAGARDO */

            // Merge NAGARDO delta into per-HL cost (may create entry from scratch)
            if ($nagarDeltaPerHl > 0.0) {
                if ($glData !== null) {
                    $glData['4104']  += $nagarDeltaPerHl;
                    $glData['total'] += $nagarDeltaPerHl;
                } else {
                    $glData = [
                        '4101'  => 0.0, '4102'  => 0.0, '4104'  => $nagarDeltaPerHl,
                        'other' => 0.0, 'total' => $nagarDeltaPerHl,
                    ];
                }
            }

            $costPerHl = $glData !== null ? $glData['total'] : null;
            $row = [
                'tank_id'          => (string) $t['tank'],
                'tank_type'        => $t['type'],
                'beer_name'        => $t['beer'],
                'batch'            => $t['batch'],
                'volume_hl'        => $vol,
                'brew_cost_per_hl' => $costPerHl,
                'cost_4101_chf'    => $glData !== null ? $vol * $glData['4101']  : null,
                'cost_4102_chf'    => $glData !== null ? $vol * $glData['4102']  : null,
                'cost_4104_chf'    => $glData !== null ? $vol * $glData['4104']  : null,
                'cost_other_chf'   => $glData !== null ? $vol * $glData['other'] : null,
                'total_chf'        => $glData !== null ? $vol * $glData['total'] : null,
            ];
            if ($t['type'] === 'CCT') { $wipCcts[] = $row; $wipKpis['cct_count']++; }
            else                       { $wipBbts[] = $row; $wipKpis['bbt_count']++; }
            $wipKpis['hl_total']  += $vol;
            $wipKpis['wip_value'] += $row['total_chf'] ?? 0;
        }

        // ── MI breakdown via VALUES list of (beer, batch, volume_hl) ──────────
        if (!empty($tankTuples)) {
            $miParams = [];
            $miTuples = [];
            foreach ($tankTuples as $t) {
                $miTuples[] = "ROW(?, ?, ?)";
                $miParams[] = $t['beer']; $miParams[] = $t['batch']; $miParams[] = $t['volume_hl'];
            }
            $miParams[] = $wipPeriod;  // ws.period
            $miSql = "
                WITH tanks AS (
                  SELECT * FROM (VALUES " . implode(',', $miTuples) . ") AS v(beer_name, batch, volume_hl)
                ),
                tank_batches AS (
                  SELECT t.beer_name COLLATE utf8mb4_unicode_ci AS beer_name,
                         t.batch     COLLATE utf8mb4_unicode_ci AS batch,
                         t.volume_hl,
                         (SELECT SUM(bc.cool_final_volume_hl) FROM bd_brewing_cooling bc
                           WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                             AND bc.cool_batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                             AND bc.cool_final_volume_hl > 0) AS total_brewed_hl,
                         (SELECT COUNT(*) FROM bd_brewing_cooling bc
                           WHERE bc.cool_beer  COLLATE utf8mb4_unicode_ci = t.beer_name COLLATE utf8mb4_unicode_ci
                             AND bc.cool_batch COLLATE utf8mb4_unicode_ci = t.batch     COLLATE utf8mb4_unicode_ci
                             AND bc.cool_final_volume_hl > 0) AS n_brews
                    FROM tanks t
                )
                -- ANY_VALUE wraps per-mi attributes that are functionally
                -- dependent on m.id but MySQL's ONLY_FULL_GROUP_BY can't infer
                -- the dependency across LEFT JOINs. The unit_price expression
                -- is constant per row so it's safe to pull a single value.
                SELECT ANY_VALUE(m.mi_id)                AS mi_id,
                       ANY_VALUE(m.name)                 AS mi_name,
                       ANY_VALUE(c.name)                 AS category,
                       ANY_VALUE(c.default_gl_account)   AS default_gl_account,
                       ANY_VALUE(m.pricing_unit)         AS pricing_unit,
                       SUM(bip.qty * IF(bip.unit='g', 0.001, 1) * COALESCE(tb.n_brews, 1)
                           * tb.volume_hl / NULLIF(tb.total_brewed_hl, 0)) AS qty_total_kg,
                       ANY_VALUE(COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))) AS unit_price_chf,
                       SUM(bip.qty * IF(bip.unit='g', 0.001, 1) * COALESCE(tb.n_brews, 1)
                           * tb.volume_hl / NULLIF(tb.total_brewed_hl, 0)
                           * COALESCE(w.wac_chf, m.price * IF(m.currency='EUR', 0.945, 1))) AS total_chf
                  FROM tank_batches tb
                  JOIN bd_brewing_ingredients_parsed bip
                    ON bip.beer  COLLATE utf8mb4_unicode_ci = tb.beer_name COLLATE utf8mb4_unicode_ci
                   AND bip.batch COLLATE utf8mb4_unicode_ci = tb.batch     COLLATE utf8mb4_unicode_ci
                   AND bip.mi_id_fk IS NOT NULL
                  JOIN ref_mi m ON m.id = bip.mi_id_fk
                  LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                  LEFT JOIN wac_snapshots w ON w.mi_id_fk = m.id AND w.period = ?
                 GROUP BY m.id
                 ORDER BY ANY_VALUE(c.default_gl_account), ANY_VALUE(c.name), ANY_VALUE(m.mi_id)
            ";
            $mb = $pdo->prepare($miSql);
            $mb->execute($miParams);
            $wipMiRows = $mb->fetchAll();

            /* TEMPORARY HARDCODE — inject synthetic MI rows for hardcoded ingredients */
            // The MI breakdown SQL above only joins bd_brewing_ingredients_parsed,
            // so hardcoded ingredients won't appear. Build synthetic rows with the same
            // shape and append them. REMOVE once the native inputting form writes these.
            $hcSyntheticAccum = [];   // mi_id_string => ['qty_kg'=>float,'total_chf'=>float]

            foreach ($tankTuples as $t) {
                if ($t['batch'] === '') continue;
                $beerName = $t['beer'];
                $bk       = $beerName . '|' . $t['batch'];

                // Per-brew rules from $HARDCODED_INGREDIENT_RULES
                $perBrewRules = $HARDCODED_INGREDIENT_RULES[$beerName] ?? [];
                if (!empty($perBrewRules)) {
                    $meta = $batchBrewMeta[$bk] ?? null;
                    if ($meta !== null && $meta['n'] > 0 && $meta['hl'] > 0) {
                        foreach ($perBrewRules as $rule) {
                            $miCode = $rule['mi_id'];
                            if (isset($hardcodedMiWarnings[$miCode])) continue;
                            if (!empty($rule['skip_if_in_parsed']) && isset($HC_DEHAZE_PARSED_BATCHES[$bk])) continue;
                            $priceInfo = $hardcodedMiPrices[$miCode] ?? null;
                            if ($priceInfo === null) continue;
                            // qty for this tank = qty_per_brew × n_brews × (tank_vol / total_brewed_hl)
                            $tankQty = $rule['qty_per_brew_kg'] * $meta['n'] * ($t['volume_hl'] / $meta['hl']);
                            if (!isset($hcSyntheticAccum[$miCode])) {
                                $hcSyntheticAccum[$miCode] = ['qty_kg' => 0.0, 'total_chf' => 0.0];
                            }
                            $hcSyntheticAccum[$miCode]['qty_kg']    += $tankQty;
                            $hcSyntheticAccum[$miCode]['total_chf'] += $tankQty * $priceInfo['unit_price_chf'];
                        }
                    }
                }

                // PROC_YEASTVIT: per-brew, all qualifying Neb beers
                if (in_array($beerName, $HC_YEASTVIT_BEERS, true)
                    && !isset($hardcodedMiWarnings[$HC_YEASTVIT_RULE['mi_id']])) {
                    $meta = $batchBrewMeta[$bk] ?? null;
                    if ($meta !== null && $meta['n'] > 0 && $meta['hl'] > 0) {
                        $miCode    = $HC_YEASTVIT_RULE['mi_id'];
                        $priceInfo = $hardcodedMiPrices[$miCode] ?? null;
                        if ($priceInfo !== null) {
                            $tankQty = $HC_YEASTVIT_RULE['qty_per_brew_kg'] * $meta['n'] * ($t['volume_hl'] / $meta['hl']);
                            if (!isset($hcSyntheticAccum[$miCode])) {
                                $hcSyntheticAccum[$miCode] = ['qty_kg' => 0.0, 'total_chf' => 0.0];
                            }
                            $hcSyntheticAccum[$miCode]['qty_kg']    += $tankQty;
                            $hcSyntheticAccum[$miCode]['total_chf'] += $tankQty * $priceInfo['unit_price_chf'];
                        }
                    }
                }

                // PROC_NAGARDO: per-HL of BBT volume, Diversion + Diversion Blanche only
                if ($t['type'] === 'BBT'
                    && in_array($beerName, $HC_NAGARDO_BEERS, true)
                    && !isset($hardcodedMiWarnings[$HC_NAGARDO_RULE['mi_id']])) {
                    $miCode    = $HC_NAGARDO_RULE['mi_id'];
                    $priceInfo = $hardcodedMiPrices[$miCode] ?? null;
                    if ($priceInfo !== null) {
                        $tankQty = $HC_NAGARDO_RULE['qty_per_hl_kg'] * $t['volume_hl'];
                        if (!isset($hcSyntheticAccum[$miCode])) {
                            $hcSyntheticAccum[$miCode] = ['qty_kg' => 0.0, 'total_chf' => 0.0];
                        }
                        $hcSyntheticAccum[$miCode]['qty_kg']    += $tankQty;
                        $hcSyntheticAccum[$miCode]['total_chf'] += $tankQty * $priceInfo['unit_price_chf'];
                    }
                }
            }

            foreach ($hcSyntheticAccum as $miCode => $accum) {
                $priceInfo = $hardcodedMiPrices[$miCode];
                $wipMiRows[] = [
                    'mi_id'              => $miCode,
                    'mi_name'            => $priceInfo['name'],
                    'category'           => 'Ingrédients',   // GL 4104
                    'default_gl_account' => $priceInfo['gl'],
                    'pricing_unit'       => $priceInfo['unit'],
                    'qty_total_kg'       => $accum['qty_kg'],
                    'unit_price_chf'     => $priceInfo['unit_price_chf'],
                    'total_chf'          => $accum['total_chf'],
                ];
            }
            // Re-sort $wipMiRows by default_gl_account, category, mi_id — matches SQL ORDER BY
            usort($wipMiRows, function (array $a, array $b): int {
                $glCmp  = strcmp((string) ($a['default_gl_account'] ?? ''), (string) ($b['default_gl_account'] ?? ''));
                if ($glCmp !== 0) return $glCmp;
                $catCmp = strcmp((string) ($a['category'] ?? ''), (string) ($b['category'] ?? ''));
                if ($catCmp !== 0) return $catCmp;
                return strcmp((string) ($a['mi_id'] ?? ''), (string) ($b['mi_id'] ?? ''));
            });
            /* END TEMPORARY HARDCODE — synthetic MI rows */
        }

        // ── GL recap (4101 / 4102 / 4104 only) ───────────────────────────────
        $glTotals = [];
        foreach ($wipMiRows as $mr) {
            $gl = (string) ($mr['default_gl_account'] ?? '');
            if (!in_array($gl, ['4101', '4102', '4104'], true)) continue;
            $glTotals[$gl] = ($glTotals[$gl] ?? 0.0) + (float) ($mr['total_chf'] ?? 0);
        }
        $glLabels = ['4101' => 'Malt', '4102' => 'Houblon', '4104' => 'Ingrédients'];
        foreach (['4101', '4102', '4104'] as $gl) {
            $wipGlRecap[] = [
                'gl'    => $gl,
                'label' => $glLabels[$gl],
                'total' => $glTotals[$gl] ?? 0.0,
            ];
        }
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Warehouse — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>
<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main wort-main">

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- Sub-tab switcher + export button -->
  <div class="wh-tabs-bar">
    <nav class="wh-tabs" aria-label="Vue entrepôt">
      <a class="wh-tab<?= $view === 'rm' ? ' wh-tab--active' : '' ?>"
         href="?view=rm"
         <?= $view === 'rm' ? 'aria-current="page"' : '' ?>>Matières premières</a>
      <a class="wh-tab<?= $view === 'fg' ? ' wh-tab--active' : '' ?>"
         href="?view=fg"
         <?= $view === 'fg' ? 'aria-current="page"' : '' ?>>Produits finis</a>
      <a class="wh-tab<?= $view === 'wip' ? ' wh-tab--active' : '' ?>"
         href="?view=wip"
         <?= $view === 'wip' ? 'aria-current="page"' : '' ?>>En cours de fermentation</a>
    </nav>
    <?php
      // CSV export covers all 3 tabs (RM live + WIP @ period + FG @ period) in one file.
      // For RM (live stock, no period selector) default to last closed month so the
      // WIP/FG snapshot portions are sensible.
      if ($view === 'fg' && !empty($fgPeriod)) {
          $exportPeriod = $fgPeriod;
      } elseif ($view === 'wip' && !empty($wipPeriod)) {
          $exportPeriod = $wipPeriod;
      } else {
          $exportPeriod = (new DateTimeImmutable('first day of last month'))->format('Y-m');
      }
    ?>
    <a class="wh-export-btn"
       href="warehouse-export.php?period=<?= htmlspecialchars($exportPeriod) ?>&amp;view=<?= htmlspecialchars($view) ?>"
       title="Export RM + WIP + FG en un seul CSV">Télécharger CSV</a>
  </div>

  <?php if ($view === 'fg'): ?>

    <!-- ── FG VIEW ──────────────────────────────────────────────────────── -->

    <!-- Period selector -->
    <form class="wh-wip-date" method="get" action="">
      <input type="hidden" name="view" value="fg">
      <label class="wh-wip-date__label">Période&nbsp;:
        <select class="wh-wip-date__select" name="period" onchange="this.form.submit()">
          <?php foreach ($fgPeriods as $pk): ?>
            <option value="<?= htmlspecialchars($pk) ?>"<?= ($pk === $fgPeriod) ? ' selected' : '' ?>>
              <?= htmlspecialchars(wh_period_label($pk, $GLOBALS['monthsFRLong'])) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>
    </form>

    <?php if (empty($fgRows)): ?>

      <div class="wh-placeholder" role="status">
        <span class="wh-placeholder__msg">Aucune donnée FG disponible pour cette période.</span>
      </div>

    <?php else: ?>

      <!-- KPI strip (5 tiles) -->
      <section class="wort-kpis wh-kpis--5" aria-label="Indicateurs produits finis">
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($fgKpis['valeur_fg'], 0, 0, '0') ?></span>
          <span class="wort-kpi__label">Valeur FG (CHF)</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($fgKpis['hl_total'], 1, 1, '0') ?></span>
          <span class="wort-kpi__label">HL équivalent total</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= $fgKpis['sku_count'] ?></span>
          <span class="wort-kpi__label">SKUs en stock</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($fgKpis['valeur_liquide'], 0, 0, '0') ?></span>
          <span class="wort-kpi__label">Valeur liquide (CHF)</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($fgKpis['valeur_emballage'], 0, 0, '0') ?></span>
          <span class="wort-kpi__label">Valeur emballage (CHF)</span>
        </div>
      </section>

      <!-- Per-SKU table -->
      <section class="wort-section" aria-label="Stock produits finis">
        <div class="wort-section__head">
          <span class="wort-section__label">— produits finis (<?= htmlspecialchars($fgPeriod) ?>)</span>
          <span class="wort-filters__count"><?= count($fgRows) ?> SKU<?= count($fgRows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="wort-table-wrap">
          <table class="wort-table wh-fg-table">
            <thead>
              <tr>
                <th scope="col">SKU</th>
                <th scope="col">Bière</th>
                <th scope="col">Format</th>
                <th scope="col">Conditionnement</th>
                <th scope="col">Qté</th>
                <th scope="col">HL équiv.</th>
                <th scope="col">Coût liquide/u</th>
                <th scope="col">Coût emb./u</th>
                <th scope="col">Total/u (CHF)</th>
                <th scope="col">Total CHF</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fgRows as $fr): ?>
                <?php
                  $showFallbackLabel = (
                    $fr['cost_source_month'] !== null &&
                    $fr['cost_source_month'] !== (new DateTime($fgPeriod . '-01'))->modify('-1 month')->format('Y-m')
                  );
                ?>
                <tr class="<?= $fr['no_cost_basis'] ? 'wh-fg-row--no-cost-basis' : '' ?>">
                  <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($fr['sku_code']) ?></span></td>
                  <td class="wort-td"><?= htmlspecialchars($fr['beer']) ?></td>
                  <td class="wort-td"><?= htmlspecialchars($fr['format']) ?></td>
                  <td class="wort-td"><?= htmlspecialchars($fr['unit_label']) ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($fr['qty'], 0, 0) ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($fr['hl_equiv'], 2, 3) ?></td>
                  <td class="wort-td wh-td--num">
                    <?php if ($fr['no_cost_basis']): ?>
                      <span class="wh-no-basis">—</span>
                    <?php else: ?>
                      <?= wh_num_smart($fr['liquid_per_unit'], 2, 4) ?>
                      <?php if ($showFallbackLabel): ?>
                        <sup class="wh-fg-cost-source">(coût de <?= htmlspecialchars($fr['cost_source_month']) ?>)</sup>
                      <?php endif ?>
                    <?php endif ?>
                  </td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($fr['packaging_per_unit'], 2, 4, '—') ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($fr['total_per_unit'], 2, 4, '—') ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($fr['total_chf'], 2, 2, '—') ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- GL recap -->
      <section class="wort-section wh-section--mt" aria-label="Récapitulatif GL produits finis">
        <div class="wort-section__head">
          <span class="wort-section__label">— récap GL</span>
        </div>
        <div class="wort-table-wrap">
          <?php
            $glLabelsFG = [
                '4101' => 'Malt',
                '4102' => 'Houblon',
                '4103' => 'Levure',
                '4104' => 'Ingrédients',
                '4200' => 'Emballage',
            ];
            $fgGlGrandTotal = 0.0;
            ksort($fgGlRecap);
          ?>
          <table class="wort-table wh-fg-gl-recap wh-gl-recap">
            <thead>
              <tr>
                <th scope="col">GL</th>
                <th scope="col">Catégorie</th>
                <th scope="col">Total CHF</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fgGlRecap as $gl => $total):
                    $fgGlGrandTotal += $total;
                    $glStr = (string) $gl; ?>
                <tr>
                  <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($glStr) ?></span></td>
                  <td class="wort-td"><?= htmlspecialchars((string) ($glLabelsFG[$glStr] ?? $glLabelsFG[$gl] ?? $glStr)) ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($total, 2, 2, '0.00') ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
            <tfoot>
              <tr class="wh-gl-recap__total">
                <td class="wort-td" colspan="2">TOTAL</td>
                <td class="wort-td wh-td--num"><?= wh_num_smart($fgGlGrandTotal, 2, 2, '0.00') ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

    <?php endif ?>

  <?php elseif ($view === 'wip'): ?>

    <!-- ── WIP VIEW ──────────────────────────────────────────────────────── -->

    <?php if (empty($wipPeriods)): ?>

      <div class="wh-placeholder" role="status">
        <span class="wh-placeholder__msg">Aucune donnée WIP disponible pour le moment.<br>Les cuves seront visibles après la première clôture mensuelle.</span>
      </div>

    <?php else: ?>

      <!-- Date picker -->
      <form class="wh-wip-date" method="get" action="">
        <input type="hidden" name="view" value="wip">
        <label class="wh-wip-date__label">Période&nbsp;:
          <select class="wh-wip-date__select" name="period" onchange="this.form.submit()">
            <?php foreach ($wipPeriods as $pk): ?>
              <option value="<?= htmlspecialchars($pk) ?>"<?= ($pk === $wipPeriod) ? ' selected' : '' ?>>
                <?= htmlspecialchars(wh_period_label($pk, $GLOBALS['monthsFRLong'])) ?>
              </option>
            <?php endforeach ?>
          </select>
        </label>
      </form>

      <?php if (!empty($hardcodedMiWarnings)): ?>
        <!-- TEMPORARY: warning strip for missing hardcoded MIs -->
        <div class="wort-error" role="alert">
          <?php foreach ($hardcodedMiWarnings as $warnMsg): ?>
            <div><?= htmlspecialchars($warnMsg) ?></div>
          <?php endforeach ?>
        </div>
      <?php endif ?>

      <!-- KPI strip (4 tiles) -->
      <section class="wort-kpis wh-kpis--4" aria-label="Indicateurs WIP">
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($wipKpis['hl_total'], 1, 1, '0') ?></span>
          <span class="wort-kpi__label">Total HL en cuve</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= $wipKpis['cct_count'] ?></span>
          <span class="wort-kpi__label">Tanks CCT actifs</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= $wipKpis['bbt_count'] ?></span>
          <span class="wort-kpi__label">Tanks BBT actifs</span>
        </div>
        <div class="wort-kpi">
          <span class="wort-kpi__num"><?= wh_num_smart($wipKpis['wip_value'], 0, 0, '0') ?></span>
          <span class="wort-kpi__label">Valeur WIP (CHF)</span>
        </div>
      </section>

      <?php
        // Emit "Autres CHF" column only when at least one row has a non-zero other cost
        $showAutresCol = (
            array_sum(array_column($wipCcts, 'cost_other_chf')) +
            array_sum(array_column($wipBbts, 'cost_other_chf'))
        ) > 0.01;
      ?>

      <!-- CCT table -->
      <section class="wort-section" aria-label="Fermenteurs CCT">
        <div class="wort-section__head">
          <span class="wort-section__label">— CCT (fermenteurs)</span>
        </div>
        <?php if (empty($wipCcts)): ?>
          <div class="empty">Aucun fermenteur CCT pour cette période.</div>
        <?php else: ?>
          <div class="wort-table-wrap">
            <table class="wort-table">
              <thead>
                <tr>
                  <th scope="col">Tank</th>
                  <th scope="col">Bière</th>
                  <th scope="col">Batch</th>
                  <th scope="col">HL</th>
                  <th scope="col">Coût brew / HL</th>
                  <th scope="col">4101 Malt CHF</th>
                  <th scope="col">4102 Houblon CHF</th>
                  <th scope="col">4104 Ingrédients CHF</th>
                  <?php if ($showAutresCol): ?><th scope="col">Autres CHF</th><?php endif ?>
                  <th scope="col">Total CHF</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wipCcts as $t): ?>
                  <tr>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($t['tank_id'] ?? '—') ?></span></td>
                    <td class="wort-td"><?= htmlspecialchars($t['beer_name'] ?? '—') ?></td>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($t['batch'] ?? '—') ?></span></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['volume_hl'], 1, 1) ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['brew_cost_per_hl'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4101_chf'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4102_chf'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4104_chf'], 2, 2, '—') ?></td>
                    <?php if ($showAutresCol): ?><td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_other_chf'], 2, 2, '—') ?></td><?php endif ?>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['total_chf'], 2, 2, '—') ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- BBT table -->
      <section class="wort-section wh-section--mt" aria-label="Tanks BBT">
        <div class="wort-section__head">
          <span class="wort-section__label">— BBT (tanks brillants)</span>
        </div>
        <?php if (empty($wipBbts)): ?>
          <div class="empty">Aucun tank BBT pour cette période.</div>
        <?php else: ?>
          <div class="wort-table-wrap">
            <table class="wort-table">
              <thead>
                <tr>
                  <th scope="col">Tank</th>
                  <th scope="col">Bière</th>
                  <th scope="col">Batch</th>
                  <th scope="col">HL</th>
                  <th scope="col">Coût brew / HL</th>
                  <th scope="col">4101 Malt CHF</th>
                  <th scope="col">4102 Houblon CHF</th>
                  <th scope="col">4104 Ingrédients CHF</th>
                  <?php if ($showAutresCol): ?><th scope="col">Autres CHF</th><?php endif ?>
                  <th scope="col">Total CHF</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wipBbts as $t): ?>
                  <tr>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($t['tank_id'] ?? '—') ?></span></td>
                    <td class="wort-td"><?= htmlspecialchars($t['beer_name'] ?? '—') ?></td>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($t['batch'] ?? '—') ?></span></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['volume_hl'], 1, 1) ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['brew_cost_per_hl'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4101_chf'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4102_chf'], 2, 2, '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_4104_chf'], 2, 2, '—') ?></td>
                    <?php if ($showAutresCol): ?><td class="wort-td wh-td--num"><?= wh_num_smart($t['cost_other_chf'], 2, 2, '—') ?></td><?php endif ?>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($t['total_chf'], 2, 2, '—') ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- MI breakdown -->
      <section class="wort-section wh-section--mt" aria-label="Décomposition MI">
        <div class="wort-section__head">
          <span class="wort-section__label">— décomposition par MI</span>
        </div>
        <?php if (empty($wipMiRows)): ?>
          <div class="empty">Aucune donnée MI disponible (bd_brewing_ingredients_parsed vide ou batch non résolu).</div>
        <?php else: ?>
          <div class="wort-table-wrap">
            <table class="wort-table">
              <thead>
                <tr>
                  <th scope="col">GL</th>
                  <th scope="col">Catégorie</th>
                  <th scope="col">MI</th>
                  <th scope="col">Nom</th>
                  <th scope="col">Qté totale</th>
                  <th scope="col">Unité</th>
                  <th scope="col">CHF total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($wipMiRows as $mr): ?>
                  <tr>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($mr['default_gl_account'] ?? '—') ?></span></td>
                    <td class="wort-td"><?= htmlspecialchars($mr['category'] ?? '—') ?></td>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($mr['mi_id'] ?? '—') ?></span></td>
                    <td class="wort-td"><?= htmlspecialchars($mr['mi_name'] ?? '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($mr['qty_total_kg'], 1, 3, '—') ?></td>
                    <td class="wort-td">kg</td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($mr['total_chf'], 2, 2, '—') ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- GL recap -->
      <section class="wort-section wh-section--mt" aria-label="Récapitulatif GL">
        <div class="wort-section__head">
          <span class="wort-section__label">— récap GL</span>
        </div>
        <div class="wort-table-wrap">
          <table class="wort-table wh-gl-recap">
            <thead>
              <tr>
                <th scope="col">GL</th>
                <th scope="col">Catégorie</th>
                <th scope="col">Total CHF</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $glGrandTotal = 0.0;
                foreach ($wipGlRecap as $gr):
                    $glGrandTotal += $gr['total'];
              ?>
                <tr>
                  <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($gr['gl']) ?></span></td>
                  <td class="wort-td"><?= htmlspecialchars($gr['label']) ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($gr['total'], 2, 2, '0.00') ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
            <tfoot>
              <tr class="wh-gl-recap__total">
                <td class="wort-td" colspan="2">TOTAL</td>
                <td class="wort-td wh-td--num"><?= wh_num_smart($glGrandTotal, 2, 2, '0.00') ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

    <?php endif ?>

  <?php elseif ($view === 'rm' && $miId !== null): ?>

    <!-- ── DETAIL VIEW ────────────────────────────────────────────────────── -->

    <a class="wh-back" href="?view=rm<?= $cat !== '' ? '&cat=' . urlencode($cat) : '' ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">&#8592; Retour</a>

    <?php if ($miRow): ?>

      <?php
        $liveQty = (float) ($miRow['live_qty']   ?? 0);
        $wac     = $miRow['wac_chf'] !== null ? (float) $miRow['wac_chf'] : null;
        $sv      = $wac !== null ? $liveQty * $wac : null;
        $wr      = $miRow['weeks_remaining'] !== null ? (float) $miRow['weeks_remaining'] : null;
        $hl      = $miRow['hl_equivalent']   !== null ? (float) $miRow['hl_equivalent']   : null;

        if ($wr === null)     $burnClass = '';
        elseif ($wr < 4)      $burnClass = 'wh-burn-rate--critical';
        elseif ($wr < 8)      $burnClass = 'wh-burn-rate--warn';
        else                  $burnClass = 'wh-burn-rate--ok';
      ?>

      <div class="sku-header-card">
        <div class="sku-header-card__top">
          <span class="sku-header-card__code"><?= htmlspecialchars($miRow['mi_id']) ?></span>
          <?php if (!(bool) $miRow['is_active']): ?>
            <span class="wh-badge-inactive">inactif</span>
          <?php endif ?>
        </div>
        <div class="sku-header-card__meta">
          <div class="sku-header-meta__item">
            <span class="sku-header-meta__label">Nom</span>
            <span class="sku-header-meta__val"><?= htmlspecialchars($miRow['mi_name'] ?? '—') ?></span>
          </div>
          <span class="sku-header-meta__sep">·</span>
          <div class="sku-header-meta__item">
            <span class="sku-header-meta__label">Catégorie</span>
            <span class="sku-header-meta__val"><?= htmlspecialchars($miRow['category'] ?? '—') ?></span>
          </div>
          <?php if (!empty($miRow['subcategory'])): ?>
            <span class="sku-header-meta__sep">·</span>
            <div class="sku-header-meta__item">
              <span class="sku-header-meta__label">Sous-catégorie</span>
              <span class="sku-header-meta__val"><?= htmlspecialchars($miRow['subcategory']) ?></span>
            </div>
          <?php endif ?>
          <span class="sku-header-meta__sep">·</span>
          <div class="sku-header-meta__item">
            <span class="sku-header-meta__label">Unité</span>
            <span class="sku-header-meta__val"><?= htmlspecialchars($miRow['unit'] ?? '—') ?></span>
          </div>
        </div>
        <div class="sku-header-card__costs">
          <div class="sku-header-cost sku-header-cost--focus">
            <span class="sku-header-cost__val"><?= wh_num_smart($liveQty, 0, 2) ?></span>
            <span class="sku-header-cost__label">Qté live (<?= htmlspecialchars($miRow['unit'] ?? '—') ?>)</span>
          </div>
          <div class="sku-header-cost">
            <?php if ($wac === null): ?>
              <span class="sku-header-cost__val wh-no-basis">— (no cost basis)</span>
            <?php elseif ($wac < 0): ?>
              <span class="sku-header-cost__val wh-no-basis">&#9888; net credit</span>
            <?php else: ?>
              <span class="sku-header-cost__val<?= !empty($miRow['wac_is_legacy']) ? ' wh-wac--legacy' : '' ?>"
                    <?php if (!empty($miRow['wac_is_legacy'])): ?>title="Prix de référence (ref_mi.price) — pas de facture MySQL"<?php endif ?>>
                <?= wh_num_smart($wac, 2, 5) ?> CHF<?= !empty($miRow['wac_is_legacy']) ? ' <span class="wh-wac-tag">réf.</span>' : '' ?>
              </span>
            <?php endif ?>
            <span class="sku-header-cost__label">WAC</span>
          </div>
          <?php if ($sv !== null): ?>
          <div class="sku-header-cost">
            <span class="sku-header-cost__val"><?= wh_num_smart($sv, 2, 2) ?> CHF</span>
            <span class="sku-header-cost__label">Valeur CHF</span>
          </div>
          <?php endif ?>
          <?php if ($wr !== null): ?>
          <div class="sku-header-cost">
            <span class="sku-header-cost__val <?= htmlspecialchars($burnClass) ?>"><?= wh_num_smart($wr, 1, 1) ?></span>
            <span class="sku-header-cost__label">Semaines restantes</span>
          </div>
          <?php endif ?>
          <?php if ($hl !== null): ?>
          <div class="sku-header-cost">
            <span class="sku-header-cost__val"><?= wh_num_smart($hl, 1, 2) ?></span>
            <span class="sku-header-cost__label">HL équivalent</span>
          </div>
          <?php endif ?>
        </div>
      </div>

      <!-- Delivery history -->
      <section class="wort-section" aria-label="Historique livraisons">
        <div class="wort-section__head">
          <span class="wort-section__label">— dernières livraisons</span>
        </div>
        <?php if (empty($delivH)): ?>
          <div class="empty">Aucune livraison enregistrée.</div>
        <?php else: ?>
          <div class="wort-table-wrap">
            <table class="wort-table">
              <thead>
                <tr>
                  <th scope="col">Date</th>
                  <th scope="col">Qté</th>
                  <th scope="col">Unité</th>
                  <th scope="col">Prix unit.</th>
                  <th scope="col">Devise</th>
                  <th scope="col">Fournisseur</th>
                  <th scope="col">Réf. facture</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($delivH as $d): ?>
                  <tr>
                    <td class="wort-td wort-td--date"><?= $d['date_received'] ? wh_date_fr($d['date_received'], $monthsFR) : '—' ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($d['qty_delivered'], 0, 2) ?></td>
                    <td class="wort-td"><?= htmlspecialchars($d['pricing_unit'] ?? '—') ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($d['unit_price'], 2, 5, '—') ?></td>
                    <td class="wort-td"><?= htmlspecialchars($d['currency'] ?? '—') ?></td>
                    <td class="wort-td"><?= htmlspecialchars($d['supplier_raw'] ?? '—') ?></td>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($d['invoice_ref'] ?? '—') ?></span></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- Consumption history -->
      <section class="wort-section wh-section--mt" aria-label="Historique consommation">
        <div class="wort-section__head">
          <span class="wort-section__label">— dernières consommations</span>
        </div>
        <?php if (empty($consH)): ?>
          <div class="empty">Aucune consommation enregistrée.</div>
        <?php else: ?>
          <div class="wort-table-wrap">
            <table class="wort-table">
              <thead>
                <tr>
                  <th scope="col">Date</th>
                  <th scope="col">Qté</th>
                  <th scope="col">Unité</th>
                  <th scope="col">Événement</th>
                  <th scope="col">Bière</th>
                  <th scope="col">HL brassé</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($consH as $c): ?>
                  <tr>
                    <td class="wort-td wort-td--date"><?= $c['consumed_at'] ? wh_date_fr($c['consumed_at'], $monthsFR) : '—' ?></td>
                    <td class="wort-td wh-td--num"><?= wh_num_smart($c['qty'], 0, 2) ?></td>
                    <td class="wort-td"><?= htmlspecialchars($c['unit'] ?? '—') ?></td>
                    <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($c['source_event'] ?? '—') ?></span></td>
                    <td class="wort-td"><?= htmlspecialchars($c['beer_name'] ?? '—') ?></td>
                    <td class="wort-td wh-td--num"><?= $c['hl_packaged'] !== null ? wh_num_smart($c['hl_packaged'], 1, 2) : '—' ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- Stock sparkline -->
      <?php if (!empty($sparkPts)): ?>
      <section class="wort-section wh-section--mt" aria-label="Évolution du stock">
        <div class="wort-section__head">
          <span class="wort-section__label">— évolution du stock</span>
        </div>
        <div class="wh-chart" id="wh-stocktake-chart"
             data-points='<?= htmlspecialchars(json_encode($sparkPts), ENT_QUOTES, 'UTF-8') ?>'></div>
      </section>
      <?php endif ?>

    <?php else: ?>
      <div class="empty">MI introuvable.</div>
    <?php endif ?>

    <script defer src="/js/warehouse.js?v=<?= @filemtime(__DIR__ . '/../js/warehouse.js') ?: time() ?>"></script>

  <?php else: ?>

    <!-- ── LIST VIEW ─────────────────────────────────────────────────────── -->

    <!-- KPI strip -->
    <section class="wort-kpis wh-kpis--5" aria-label="Indicateurs entrepôt">
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= wh_num_smart($kpis['stock_value'], 0, 0, '—') ?></span>
        <span class="wort-kpi__label">Valeur stock (CHF) <span class="wort-kpi__sublabel">— bases connues</span></span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= $kpis['mis_in_stock'] ?></span>
        <span class="wort-kpi__label">MIs en stock</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num<?= $kpis['burn_critique'] > 0 ? ' wh-kpi__num--warn' : '' ?>"><?= $kpis['burn_critique'] ?></span>
        <span class="wort-kpi__label">Burn critique (&lt;4&nbsp;sem.)</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= wh_num_smart($kpis['hl_total'], 1, 1, '—') ?></span>
        <span class="wort-kpi__label">HL équivalent total</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num<?= $kpis['carried'] > 0 ? ' wh-kpi__num--warn' : '' ?>"><?= $kpis['carried'] ?></span>
        <span class="wort-kpi__label">Carried sources</span>
      </div>
    </section>

    <!-- Filter bar -->
    <form class="wort-filters" method="get" action="">
      <input type="hidden" name="view" value="rm">
      <div class="wort-filters__row">
        <label class="wort-filters__field">
          <span class="wort-filters__label">Catégorie</span>
          <select name="cat" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php foreach ($catRows as $cn): ?>
              <option value="<?= htmlspecialchars($cn) ?>"<?= ($cat === $cn) ? ' selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label class="wort-filters__field">
          <span class="wort-filters__label">Recherche</span>
          <input class="wh-search" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="MI_ID ou nom…" autocomplete="off">
        </label>
        <?php if ($cat !== '' || $q !== ''): ?>
          <a class="wort-filters__reset" href="?view=rm">Réinitialiser</a>
        <?php endif ?>
      </div>
    </form>

    <!-- Table section -->
    <section class="wort-section" aria-label="Matières premières — stock live">
      <div class="wort-section__head">
        <span class="wort-section__label">— matières premières (live stock)<?= $kpis['no_basis_count'] > 0 ? ' · <span class="wh-no-basis">' . $kpis['no_basis_count'] . ' MI' . ($kpis['no_basis_count'] !== 1 ? 's' : '') . ' sans base de coût</span>' : '' ?></span>
        <?php if (!empty($rows)): ?>
          <span class="wort-filters__count"><?= count($rows) ?> ligne<?= count($rows) !== 1 ? 's' : '' ?></span>
        <?php endif ?>
      </div>
      <?php if (empty($rows) && !$dbError): ?>
        <div class="empty">Aucune matière première en stock.<?= ($cat !== '' || $q !== '') ? ' Essayez de modifier les filtres.' : ' Les données seront disponibles après le premier stocktake.' ?></div>
      <?php elseif (!empty($rows)): ?>
        <div class="wort-table-wrap">
          <table class="wort-table wh-rm-table">
            <thead>
              <tr>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('mi_id', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">MI_ID<?= wh_sort_indicator('mi_id', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('mi_name', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Nom<?= wh_sort_indicator('mi_name', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('category', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Cat.<?= wh_sort_indicator('category', $sortCol, $sortDir) ?></a></th>
                <th scope="col">Sous-cat.</th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('live_qty', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Qté live<?= wh_sort_indicator('live_qty', $sortCol, $sortDir) ?></a></th>
                <th scope="col">Unité</th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('wac_chf', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">WAC (CHF)<?= wh_sort_indicator('wac_chf', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('stock_value', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Valeur CHF<?= wh_sort_indicator('stock_value', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('weeks_remaining', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Burn (sem.)<?= wh_sort_indicator('weeks_remaining', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('hl_equivalent', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">HL équiv.<?= wh_sort_indicator('hl_equivalent', $sortCol, $sortDir) ?></a></th>
                <th scope="col"><a class="wh-sort" href="<?= htmlspecialchars(wh_sort_href('last_delivery', $sortCol, $sortDir, 'rm', null, $cat, $q)) ?>">Dernière livr.<?= wh_sort_indicator('last_delivery', $sortCol, $sortDir) ?></a></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $rLq  = (float) ($r['live_qty']   ?? 0);
                  $rWac = $r['wac_chf'] !== null ? (float) $r['wac_chf'] : null;
                  $rSv  = ($rWac !== null) ? $rLq * $rWac : null;
                  $rWr  = $r['weeks_remaining'] !== null ? (float) $r['weeks_remaining'] : null;
                  $rHl  = $r['hl_equivalent']   !== null ? (float) $r['hl_equivalent']   : null;
                  $rDeact = !(bool) $r['is_active'];

                  if ($rWr === null)     $rBurnClass = '';
                  elseif ($rWr < 4)      $rBurnClass = 'wh-burn-rate--critical';
                  elseif ($rWr < 8)      $rBurnClass = 'wh-burn-rate--warn';
                  else                   $rBurnClass = 'wh-burn-rate--ok';
                ?>
                <?php
                  $rowHref = '?view=rm&mi_id=' . $r['id']
                    . ($cat !== '' ? '&cat=' . urlencode($cat) : '')
                    . ($q   !== '' ? '&q='   . urlencode($q)   : '');
                ?>
                <tr class="wh-row<?= $rDeact ? ' wh-deactivated' : '' ?>"
                    onclick="location.href='<?= htmlspecialchars($rowHref) ?>'"
                    tabindex="0"
                    role="button"
                    onkeydown="if(event.key==='Enter'||event.key===' ')location.href='<?= htmlspecialchars($rowHref) ?>'">
                  <td class="wort-td"><span class="wort-mono"><?= htmlspecialchars($r['mi_id']) ?></span></td>
                  <td class="wort-td"><?= htmlspecialchars($r['mi_name'] ?? '—') ?></td>
                  <td class="wort-td"><?= htmlspecialchars($r['category'] ?? '—') ?></td>
                  <td class="wort-td"><?= htmlspecialchars($r['subcategory'] ?? '—') ?></td>
                  <td class="wort-td wh-td--num"><?= wh_num_smart($rLq, 0, 2) ?></td>
                  <td class="wort-td"><?= htmlspecialchars($r['unit'] ?? '—') ?></td>
                  <td class="wort-td wh-td--num">
                    <?php if ($rWac === null): ?>
                      <span class="wh-no-basis">— (no cost basis)</span>
                    <?php elseif ($rWac < 0): ?>
                      <span class="wh-no-basis">&#9888; net credit</span>
                    <?php else: ?>
                      <span<?= !empty($r['wac_is_legacy']) ? ' class="wh-wac--legacy" title="Prix de référence — pas de facture MySQL"' : '' ?>>
                        <?= wh_num_smart($rWac, 2, 5) ?><?= !empty($r['wac_is_legacy']) ? ' <span class="wh-wac-tag">réf.</span>' : '' ?>
                      </span>
                    <?php endif ?>
                  </td>
                  <td class="wort-td wh-td--num"><?= $rSv !== null ? wh_num_smart($rSv, 2, 2) : '—' ?></td>
                  <td class="wort-td wh-td--num">
                    <?php if ($rWr !== null): ?>
                      <?php
                        $rBurnLabel = match(true) {
                            $rWr < 4  => number_format($rWr, 1) . ' semaines — critique',
                            $rWr < 8  => number_format($rWr, 1) . ' semaines — attention',
                            default   => number_format($rWr, 1) . ' semaines',
                        };
                      ?>
                      <span class="<?= htmlspecialchars($rBurnClass) ?>" aria-label="<?= htmlspecialchars($rBurnLabel) ?>"><?= wh_num_smart($rWr, 1, 1) ?></span>
                    <?php else: ?>—<?php endif ?>
                  </td>
                  <td class="wort-td wh-td--num"><?= $rHl !== null ? wh_num_smart($rHl, 1, 2) : '' ?></td>
                  <td class="wort-td wort-td--date"><?= $r['last_delivery'] ? wh_date_fr($r['last_delivery'], $monthsFR) : '—' ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>

  <?php endif ?>

</main>

</body>
</html>
