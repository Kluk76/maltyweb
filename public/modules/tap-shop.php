<?php
declare(strict_types=1);
/**
 * /modules/tap-shop.php — Tap&Shop direct-sales tracker (read-only, Phase 1).
 *
 * Two data planes:
 *   Plane A — Direct sales per beer/SKU/period.
 *     Eshop ← inv_sales_orders (channel='eshop') + inv_sales_order_lines.
 *     Taproom ← inv_sales_bc WHERE channel='taproom'.
 *     Double-count guard: inv_sales_bc.channel is enum('b2b','taproom') —
 *     NO eshop rows in BC. Eshop read SOLELY from inv_sales_orders.
 *     ~263 eshop + ~29 taproom lines with NULL sku_id_fk surfaced as
 *     "Non rattaché" — NEVER silently dropped.
 *
 *   Plane B — Virtual FG stock (physique) via fg_stock_compute() only.
 *     Do NOT write a second depletion query. fg_stock_compute() is the
 *     sole FG-stock source in this file.
 *
 * Forward-compat hooks:
 *   - vd_load_direct_sales() helper returns normalised rows;
 *     Phase 2 routing engine consumes same shape.
 *   - SKU set gated on ref_skus.is_direct_sales=1.
 *   - sku_id carried through all table/chart data — never beer-name strings.
 *   - Phase-2 placeholder panel rendered (inert, visible).
 *
 * READ-ONLY: ZERO INSERT/UPDATE/DELETE against inv_fg_stocktake, inv_sales_*,
 * ord_*, or any other table. Only SELECT + fg_stock_compute().
 *
 * Auth: require_page_access('tap-shop').
 * CSS:  /css/tap-shop.css   JS: /js/tap-shop.js + /js/kpi-charts.js
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/fg-stock.php';

require_page_access('tap-shop');
$me = current_user();

$pdo       = maltytask_pdo();
$loadErr   = null;
$tsData    = [];

/* ════════════════════════════════════════════════════════════════
   vd_load_direct_sales — forward-compat sales loader.

   Returns rows normalised to:
     (channel, sku_id, sku_code, beer_name, display_family, period,
      qty, hl, chf, recipe_id)

   Phase 2 routing engine will consume the same shape without changes.
   Gates the SKU set on ref_skus.is_direct_sales=1 from day one.
   Non-rattaché lines are included with sku_id=NULL.

   @param PDO    $pdo
   @param string $from  YYYY-MM (inclusive)  '' = no lower bound
   @param string $to    YYYY-MM (inclusive)  '' = no upper bound
   @return array
   ════════════════════════════════════════════════════════════════ */
function vd_load_direct_sales(PDO $pdo, string $from = '', string $to = ''): array
{
    $rows = [];

    /* ── Eshop (inv_sales_orders + inv_sales_order_lines) ─────── */
    /* Non-rattaché: sku_id_fk IS NULL — kept, labelled separately  */
    $eshopSql = "
        SELECT
            'eshop'                                            AS channel,
            l.sku_id_fk                                        AS sku_id,
            COALESCE(s.sku_code, l.sku_code)                   AS sku_code,
            COALESCE(rr.recipe_short_name, rr.name, l.title)   AS beer_name,
            COALESCE(pf.display_family, s.format, 'Autre')     AS display_family,
            o.period                                            AS period,
            rr.id                                               AS recipe_id,
            SUM(l.qty)                                          AS qty,
            SUM(COALESCE(l.hl_resolved, 0))                     AS hl,
            SUM(COALESCE(l.line_amount_chf, 0))                 AS chf
        FROM inv_sales_order_lines l
        JOIN inv_sales_orders o
             ON o.id = l.order_id_fk
            AND o.channel = 'eshop'
        LEFT JOIN ref_skus s
             ON s.id = l.sku_id_fk
            AND s.is_direct_sales = 1
        LEFT JOIN ref_recipes rr   ON rr.id = s.recipe_id
        LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
        WHERE (l.sku_id_fk IS NULL OR s.id IS NOT NULL)
    ";
    $eshopParams = [];
    if ($from !== '') { $eshopSql .= ' AND o.period >= ?'; $eshopParams[] = $from; }
    if ($to   !== '') { $eshopSql .= ' AND o.period <= ?'; $eshopParams[] = $to;   }
    $eshopSql .= '
        GROUP BY channel, l.sku_id_fk, sku_code, beer_name, display_family, o.period, recipe_id
        ORDER BY o.period, chf DESC
    ';
    $stmt = $pdo->prepare($eshopSql);
    $stmt->execute($eshopParams);
    $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));

    /* ── Taproom (inv_sales_bc channel='taproom') ─────────────── */
    /* inv_sales_bc.channel enum('b2b','taproom') — no eshop rows  */
    $taproomSql = "
        SELECT
            'taproom'                                          AS channel,
            bc.sku_id_fk                                       AS sku_id,
            COALESCE(s.sku_code, bc.sku_code)                  AS sku_code,
            COALESCE(rr.recipe_short_name, rr.name, bc.sku_description) AS beer_name,
            COALESCE(pf.display_family, s.format, 'Autre')     AS display_family,
            bc.period                                           AS period,
            rr.id                                               AS recipe_id,
            SUM(bc.qty_invoiced)                                AS qty,
            SUM(COALESCE(bc.hl_resolved, 0))                    AS hl,
            SUM(bc.sales_amount_chf)                            AS chf
        FROM inv_sales_bc bc
        LEFT JOIN ref_skus s
             ON s.id = bc.sku_id_fk
            AND s.is_direct_sales = 1
        LEFT JOIN ref_recipes rr   ON rr.id = s.recipe_id
        LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
        WHERE bc.channel = 'taproom'
          AND (bc.sku_id_fk IS NULL OR s.id IS NOT NULL)
    ";
    $taproomParams = [];
    if ($from !== '') { $taproomSql .= ' AND bc.period >= ?'; $taproomParams[] = $from; }
    if ($to   !== '') { $taproomSql .= ' AND bc.period <= ?'; $taproomParams[] = $to;   }
    $taproomSql .= '
        GROUP BY channel, bc.sku_id_fk, sku_code, beer_name, display_family, bc.period, recipe_id
        ORDER BY bc.period, chf DESC
    ';
    $stmt2 = $pdo->prepare($taproomSql);
    $stmt2->execute($taproomParams);
    $rows = array_merge($rows, $stmt2->fetchAll(PDO::FETCH_ASSOC));

    return $rows;
}

/* ════════════════════════════════════════════════════════════════
   Data assembly
   ════════════════════════════════════════════════════════════════ */
try {
    /* ── Plane B: FG stock (single call — the only FG-stock source) */
    $fgResult  = fg_stock_compute($pdo);
    $fgStockMap = [];
    foreach ($fgResult['rows'] as $sr) {
        $fgStockMap[(int)$sr['sku_id']] = $sr;
    }
    $anchorMonth = $fgResult['anchor_month'];
    $anchorDate  = $fgResult['anchor_date'];

    /* ── Plane A: Direct sales (all time — UI filters by period) */
    $salesRows = vd_load_direct_sales($pdo);

    /* ── Derive period list ──────────────────────────────────── */
    $periodSet = [];
    foreach ($salesRows as $r) {
        if (!empty($r['period'])) {
            $periodSet[$r['period']] = true;
        }
    }
    ksort($periodSet);
    $periods = array_keys($periodSet);

    /* ── Eshop data range for notice ─────────────────────────── */
    $eshopEndStmt = $pdo->query(
        "SELECT MAX(period) AS mx FROM inv_sales_orders WHERE channel='eshop'"
    );
    $eshopEnd = $eshopEndStmt->fetchColumn() ?: '—';

    $taproomEndStmt = $pdo->query(
        "SELECT MAX(period) AS mx FROM inv_sales_bc WHERE channel='taproom'"
    );
    $taproomEnd = $taproomEndStmt->fetchColumn() ?: '—';

    /* ── Build sales_by_period aggregation ───────────────────── */
    /*    {period => {eshop_hl, eshop_chf, taproom_hl, taproom_chf}} */
    $salesByPeriod = [];
    foreach ($salesRows as $r) {
        $p = $r['period'];
        if (!isset($salesByPeriod[$p])) {
            $salesByPeriod[$p] = [
                'eshop_hl'    => 0.0,
                'eshop_chf'   => 0.0,
                'taproom_hl'  => 0.0,
                'taproom_chf' => 0.0,
            ];
        }
        if ($r['channel'] === 'eshop') {
            $salesByPeriod[$p]['eshop_hl']  += (float)($r['hl']  ?? 0);
            $salesByPeriod[$p]['eshop_chf'] += (float)($r['chf'] ?? 0);
        } else {
            $salesByPeriod[$p]['taproom_hl']  += (float)($r['hl']  ?? 0);
            $salesByPeriod[$p]['taproom_chf'] += (float)($r['chf'] ?? 0);
        }
    }

    /* ── Build beer_table rows for JS ────────────────────────── */
    /*    Key: sku_id (NULL => 'unresolved-<channel>-<period>') + channel  */
    /*    Each row has period_data[period] = {total_qty, total_hl, total_chf} */
    $beerTableMap = [];
    foreach ($salesRows as $r) {
        $skuId   = $r['sku_id'] !== null ? (int)$r['sku_id'] : null;
        $channel = $r['channel'];
        $mapKey  = $skuId !== null ? $skuId . ':' . $channel : 'null:' . $channel . ':' . $r['period'];

        if (!isset($beerTableMap[$mapKey])) {
            $beerTableMap[$mapKey] = [
                'sku_id'         => $skuId,
                'sku_code'       => $r['sku_code'],
                'beer_name'      => $r['beer_name'],
                'display_family' => $r['display_family'] ?? 'Autre',
                'recipe_id'      => $r['recipe_id'] !== null ? (int)$r['recipe_id'] : null,
                'channel'        => $channel,
                'period_data'    => [],
                'total_qty_all'  => 0.0,
                'total_hl_all'   => 0.0,
                'total_chf_all'  => 0.0,
            ];
        }
        $period = $r['period'];
        if (!isset($beerTableMap[$mapKey]['period_data'][$period])) {
            $beerTableMap[$mapKey]['period_data'][$period] = [
                'total_qty' => 0.0,
                'total_hl'  => 0.0,
                'total_chf' => 0.0,
            ];
        }
        $beerTableMap[$mapKey]['period_data'][$period]['total_qty']  += (float)($r['qty'] ?? 0);
        $beerTableMap[$mapKey]['period_data'][$period]['total_hl']   += (float)($r['hl']  ?? 0);
        $beerTableMap[$mapKey]['period_data'][$period]['total_chf']  += (float)($r['chf'] ?? 0);
        $beerTableMap[$mapKey]['total_qty_all'] += (float)($r['qty'] ?? 0);
        $beerTableMap[$mapKey]['total_hl_all']  += (float)($r['hl']  ?? 0);
        $beerTableMap[$mapKey]['total_chf_all'] += (float)($r['chf'] ?? 0);
    }

    /* Sort: resolved SKUs by total CHF desc, then unresolved at bottom */
    $beerTableResolved   = [];
    $beerTableUnresolved = [];
    foreach ($beerTableMap as $row) {
        if ($row['sku_id'] !== null) {
            $beerTableResolved[] = $row;
        } else {
            $beerTableUnresolved[] = $row;
        }
    }
    usort($beerTableResolved, function ($a, $b) {
        return $b['total_chf_all'] <=> $a['total_chf_all'];
    });
    $beerTable = array_values(array_merge($beerTableResolved, $beerTableUnresolved));

    /* ── Grand totals ────────────────────────────────────────── */
    $totals = [
        'eshop_orders'  => 0,
        'taproom_rows'  => 0,
        'total_hl'      => 0.0,
        'total_chf'     => 0.0,
        'unresolved_eshop'   => 0,
        'unresolved_taproom' => 0,
    ];
    foreach ($salesRows as $r) {
        $totals['total_hl']  += (float)($r['hl']  ?? 0);
        $totals['total_chf'] += (float)($r['chf'] ?? 0);
        if ($r['sku_id'] === null) {
            if ($r['channel'] === 'eshop')    $totals['unresolved_eshop']++;
            else                              $totals['unresolved_taproom']++;
        }
    }
    /* Count distinct eshop orders and taproom bc rows */
    $cnt = $pdo->query("SELECT COUNT(*) FROM inv_sales_orders WHERE channel='eshop'");
    $totals['eshop_orders'] = (int)$cnt->fetchColumn();
    $cnt2 = $pdo->query("SELECT COUNT(*) FROM inv_sales_bc WHERE channel='taproom'");
    $totals['taproom_rows'] = (int)$cnt2->fetchColumn();

    /* ── Assemble JS payload ─────────────────────────────────── */
    $tsData = [
        'periods'         => $periods,
        'sales_by_period' => $salesByPeriod,
        'beer_table'      => $beerTable,
        'fg_stock'        => $fgStockMap,
        'totals'          => $totals,
        'anchor_month'    => $anchorMonth,
        'anchor_date'     => $anchorDate,
        'eshop_end'       => $eshopEnd,
        'taproom_end'     => $taproomEnd,
    ];

} catch (Throwable $ex) {
    $loadErr = $ex->getMessage();
    $tsData  = [
        'periods' => [], 'sales_by_period' => [], 'beer_table' => [],
        'fg_stock' => [], 'totals' => [], 'anchor_month' => '',
        'anchor_date' => '', 'eshop_end' => '', 'taproom_end' => '',
    ];
}

$tsDataJson = json_encode($tsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tap&amp;Shop — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/tap-shop.css?v=<?= @filemtime(__DIR__ . '/../css/tap-shop.css') ?: time() ?>">
</head>
<body class="home tap-shop">

<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- ── Page header ──────────────────────────────────────────── -->
  <div class="ts-page-header">
    <div class="ts-page-header__eyebrow">Logistique · Ventes directes</div>
    <h1>Tap<em>&amp;Shop</em></h1>
    <div class="ts-page-header__meta">
      Stock ancré : <?= htmlspecialchars($tsData['anchor_date'] ?? '—') ?>
      (mois <?= htmlspecialchars($tsData['anchor_month'] ?? '—') ?>)
      &nbsp;·&nbsp;
      Eshop jusqu'à <?= htmlspecialchars($tsData['eshop_end'] ?? '—') ?>
      &nbsp;·&nbsp;
      Taproom jusqu'à <?= htmlspecialchars($tsData['taproom_end'] ?? '—') ?>
    </div>
  </div>

  <!-- ── Data-gap notice ──────────────────────────────────────── -->
  <div class="ts-data-notice">
    <strong>État des données :</strong>
    Eshop — ingest actuel jusqu'à <strong><?= htmlspecialchars($tsData['eshop_end'] ?? '—') ?></strong>
    (<?= htmlspecialchars((string)($tsData['totals']['eshop_orders'] ?? 0)) ?> commandes ;
    ~6 % non rattachées : merch / hors-bière → ligne "Non rattaché").
    Taproom — granularité mensuelle, jusqu'à <strong><?= htmlspecialchars($tsData['taproom_end'] ?? '—') ?></strong>
    (~14 % non rattachées).
    Stock physique = sortie de <code>fg_stock_compute()</code> (même chiffre que le tableau hebdomadaire).
  </div>

  <!-- ── Period selector ──────────────────────────────────────── -->
  <div class="ts-period-bar" id="ts-period-bar">
    <!-- hydrated by tap-shop.js -->
  </div>

  <!-- ── Grand totals bar ─────────────────────────────────────── -->
  <div class="ts-totals-bar">
    <div class="ts-total-kpi">
      <div class="ts-total-kpi__label">Commandes eshop</div>
      <div class="ts-total-kpi__value"><em><?= htmlspecialchars((string)($tsData['totals']['eshop_orders'] ?? 0)) ?></em></div>
      <div class="ts-total-kpi__sub">tous canaux</div>
    </div>
    <div class="ts-total-kpi">
      <div class="ts-total-kpi__label">Lignes taproom</div>
      <div class="ts-total-kpi__value"><em><?= htmlspecialchars((string)($tsData['totals']['taproom_rows'] ?? 0)) ?></em></div>
      <div class="ts-total-kpi__sub">inv_sales_bc</div>
    </div>
    <div class="ts-total-kpi">
      <div class="ts-total-kpi__label">Total HL (tout)</div>
      <div class="ts-total-kpi__value"><span id="ts-grand-hl">—</span></div>
      <div class="ts-total-kpi__sub">eshop + taproom</div>
    </div>
    <div class="ts-total-kpi">
      <div class="ts-total-kpi__label">Total CHF (tout)</div>
      <div class="ts-total-kpi__value"><span id="ts-grand-chf">—</span></div>
      <div class="ts-total-kpi__sub">eshop + taproom</div>
    </div>
    <div class="ts-total-kpi">
      <div class="ts-total-kpi__label">Unités (tout)</div>
      <div class="ts-total-kpi__value"><span id="ts-grand-qty">—</span></div>
      <div class="ts-total-kpi__sub">SKUs résolus</div>
    </div>
  </div>

  <!-- ── Chart A : Sales over time ────────────────────────────── -->
  <div class="ts-section">
    <div class="ts-section__head">
      <h2 class="ts-section__title">Ventes dans le temps</h2>
      <span class="ts-section__sub">Eshop vs Taproom par mois</span>
    </div>
    <div class="ts-toggle-row" id="ts-toggle-row">
      <button class="ts-toggle-btn ts-toggle-btn--active" data-metric="hl">HL</button>
      <button class="ts-toggle-btn" data-metric="chf">CHF</button>
    </div>
    <div class="ts-chart-wrap">
      <div id="ts-chart-overtime"><div class="ts-chart-empty">Chargement…</div></div>
    </div>
  </div>

  <!-- ── Chart B : Top beers ──────────────────────────────────── -->
  <div class="ts-section">
    <div class="ts-section__head">
      <h2 class="ts-section__title">Top bières (ventes directes)</h2>
      <span class="ts-section__sub">Par bière · Top 10</span>
    </div>
    <div class="ts-chart-wrap">
      <div id="ts-chart-topbeers"><div class="ts-chart-empty">Chargement…</div></div>
    </div>
  </div>

  <!-- ── Per-beer table ───────────────────────────────────────── -->
  <div class="ts-section">
    <div class="ts-section__head">
      <h2 class="ts-section__title">Tableau par bière / SKU</h2>
      <span class="ts-section__sub">Ventes directes + stock virtuel par famille</span>
    </div>
    <div class="ts-table-scroll">
      <table class="ts-beer-table" role="table" aria-label="Tableau Tap&amp;Shop par bière">
        <thead>
          <tr>
            <th class="ts-th--left">Bière / SKU</th>
            <th class="ts-th--left">Canal</th>
            <th>Qté</th>
            <th>HL</th>
            <th>CHF</th>
            <th>Physique</th>
            <th>Semaines</th>
          </tr>
        </thead>
        <tbody id="ts-beer-tbody">
          <tr><td colspan="7" style="text-align:center;color:var(--ink-mute);padding:16px">Chargement…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Phase-2 placeholder ──────────────────────────────────── -->
  <div class="ts-phase2-placeholder">
    <div class="ts-phase2-placeholder__icon">📦</div>
    <div class="ts-phase2-placeholder__body">
      <p class="ts-phase2-placeholder__title">Consommation directe (à venir)</p>
      <p class="ts-phase2-placeholder__desc">
        Phase 2 : routage cage / vrac, livre de bord des sorties directes,
        intégration COGS ventes directes → COP. Ce panneau sera remplacé par
        le moteur de routage et le grand livre résiduel (inv_consumption).
      </p>
    </div>
    <div class="ts-phase2-placeholder__badge">Phase 2</div>
  </div>

</main><!-- /.main -->

<!-- Server-injected data payload -->
<script>
window.TS_DATA = <?= $tsDataJson ?>;
</script>

<script src="/js/kpi-charts.js?v=<?= @filemtime(__DIR__ . '/../js/kpi-charts.js') ?: time() ?>"></script>
<script src="/js/tap-shop.js?v=<?= @filemtime(__DIR__ . '/../js/tap-shop.js') ?: time() ?>"></script>

</body>
</html>
