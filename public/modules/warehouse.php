<?php
declare(strict_types=1);
require __DIR__ . "/../../app/auth.php";
require_login();
$me = current_user();
$active_module = "warehouse";

$view    = in_array($_GET['view'] ?? 'rm', ['rm', 'fg'], true) ? $_GET['view'] : 'rm';
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

// ── data layer ───────────────────────────────────────────────────────────────

$dbError  = null;
$rows     = [];
$miRow    = null;
$delivH   = [];
$consH    = [];
$kpis     = ['stock_value' => 0.0, 'mis_in_stock' => 0, 'burn_critique' => 0, 'hl_total' => 0.0, 'carried' => 0, 'no_basis_count' => 0];
$catRows  = [];
$sparkPts = [];

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
                   w.wac_chf,
                   (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                     * w.wac_chf AS stock_value,
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
            'wac_chf'         => 'w.wac_chf',
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
                   w.wac_chf,
                   (COALESCE(a.anchor_qty, 0) + COALESCE(ds.qty_in, 0) - COALESCE(cs.qty_out, 0))
                     * w.wac_chf AS stock_value,
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

  <!-- Sub-tab switcher -->
  <nav class="wh-tabs" aria-label="Vue entrepôt">
    <a class="wh-tab<?= $view === 'rm' ? ' wh-tab--active' : '' ?>"
       href="?view=rm"
       <?= $view === 'rm' ? 'aria-current="page"' : '' ?>>Matières premières</a>
    <a class="wh-tab<?= $view === 'fg' ? ' wh-tab--active' : '' ?>"
       href="?view=fg"
       <?= $view === 'fg' ? 'aria-current="page"' : '' ?>>Produits finis</a>
  </nav>

  <?php if ($view === 'fg'): ?>

    <!-- FG placeholder -->
    <div class="wh-placeholder" role="status">
      <span class="wh-placeholder__msg">Vue FG — à venir</span>
    </div>

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
              <span class="sku-header-cost__val"><?= wh_num_smart($wac, 2, 5) ?> CHF</span>
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
                      <?= wh_num_smart($rWac, 2, 5) ?>
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
