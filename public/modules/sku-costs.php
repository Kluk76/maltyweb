<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/settings.php";

require_page_access('sku-costs');
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "sku-costs";
$crumbs        = ["Accueil", "SKU Costs"];

// --- Filter input validation ---
$filterRecipe         = null;
$filterFormat         = null;
$filterClassification = null;

if (isset($_GET['recipe']) && $_GET['recipe'] !== '') {
    $filterRecipe = (string) $_GET['recipe'];
}
if (!empty($_GET['format']) && in_array($_GET['format'], ['Bot', 'Can', 'Keg', 'Cuve de service'], true)) {
    $filterFormat = $_GET['format'];
}
if (!empty($_GET['classification']) && in_array($_GET['classification'], ['Neb', 'Contract'], true)) {
    $filterClassification = $_GET['classification'];
}

$anyFilter = ($filterRecipe !== null || $filterFormat !== null || $filterClassification !== null);
$rowLimit  = $anyFilter ? 500 : 200;

// --- Build WHERE clauses ---
$where  = ["s.is_active = 1"];
$params = [];

if ($filterRecipe !== null) {
    $where[]           = "rr.recipe_short_name = :recipe";
    $params[':recipe'] = $filterRecipe;
}
if ($filterFormat !== null) {
    $where[]           = "s.format = :format";
    $params[':format'] = $filterFormat;
}
if ($filterClassification !== null) {
    $where[]                   = "rr.classification = :classification";
    $params[':classification'] = $filterClassification;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Helper: compute median of a plain numeric array
function array_median(array $vals): float {
    if (empty($vals)) return 0.0;
    sort($vals);
    $n = count($vals);
    $mid = (int) floor($n / 2);
    return ($n % 2 === 0) ? (($vals[$mid - 1] + $vals[$mid]) / 2.0) : (float) $vals[$mid];
}

try {
    $pdo = maltytask_pdo();

    // --- Dropdown data: distinct recipes ---
    $recipeRows = $pdo->query("
        SELECT DISTINCT rr.recipe_short_name
        FROM ref_skus s
        LEFT JOIN ref_recipes rr ON rr.id = s.recipe_id
        WHERE s.is_active = 1
          AND rr.recipe_short_name IS NOT NULL
          AND rr.recipe_short_name != ''
        ORDER BY rr.recipe_short_name
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- KPI: total active SKUs ---
    $kpiSql = "
        SELECT
            COUNT(DISTINCT s.id)                                      AS total_skus,
            COUNT(DISTINCT s.recipe_id)                               AS distinct_recipes,
            GROUP_CONCAT(ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit,0), 2) ORDER BY s.id SEPARATOR '|') AS chf_per_hl_list
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b ON b.sku_id = s.id
        LEFT JOIN ref_recipes rr ON rr.id = s.recipe_id
        {$whereSql}
        GROUP BY s.id
    ";
    // Simpler KPI query — count and median computed separately
    $kpiCountStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.id)        AS total_skus,
            COUNT(DISTINCT s.recipe_id) AS distinct_recipes
        FROM ref_skus s
        LEFT JOIN ref_recipes rr ON rr.id = s.recipe_id
        {$whereSql}
    ");
    $kpiCountStmt->execute($params);
    $kpiCounts = $kpiCountStmt->fetch();

    // Fetch all chf_per_hl values for median
    $kpiMedStmt = $pdo->prepare("
        SELECT ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit, 0), 2) AS chf_per_hl
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b ON b.sku_id = s.id
        LEFT JOIN ref_recipes rr ON rr.id = s.recipe_id
        {$whereSql}
        GROUP BY s.id
    ");
    $kpiMedStmt->execute($params);
    $kpiMedVals = $kpiMedStmt->fetchAll(PDO::FETCH_COLUMN);
    $kpiMedVals = array_filter(array_map('floatval', $kpiMedVals), fn($v) => $v > 0);
    $medianCHFperHL = array_median(array_values($kpiMedVals));

    // --- Main row query ---
    $rowSql = "
        SELECT
            s.id, s.sku_code, s.format, s.unit_label, s.hl_per_unit,
            rr.recipe_short_name, rr.classification, rr.subtype,
            ROUND(SUM(CASE WHEN b.source = 'Brewing'   THEN b.cost ELSE 0 END), 3) AS brewing_cost,
            ROUND(SUM(CASE WHEN b.source = 'Packaging' THEN b.cost ELSE 0 END), 3) AS packaging_cost,
            ROUND(SUM(b.cost), 3)                                                   AS total_cost,
            ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit, 0), 2)                        AS chf_per_hl,
            MAX(CASE WHEN b.source = 'Brewing'
                     THEN (b.qty_per_unit * COALESCE(m.conversion_factor, 1.0)) / NULLIF(s.hl_per_unit, 0)
                     ELSE NULL END) AS max_qty_per_hl
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b  ON b.sku_id  = s.id
        LEFT JOIN ref_mi      m  ON m.id      = b.mi_id
        LEFT JOIN ref_recipes rr ON rr.id      = s.recipe_id
        {$whereSql}
        GROUP BY s.id
        ORDER BY rr.recipe_short_name,
                 FIELD(s.format, 'Bot', 'Can', 'Keg', 'Cuve de service'),
                 s.sku_code
        LIMIT {$rowLimit}
    ";
    $rowStmt = $pdo->prepare($rowSql);
    $rowStmt->execute($params);
    $rows    = $rowStmt->fetchAll();

    // Freshness: MAX(compiled_at) across active SKUs in the current filter scope
    $freshSql = "
        SELECT MAX(b.compiled_at) AS compiled_at
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b  ON b.sku_id  = s.id
        LEFT JOIN ref_recipes rr ON rr.id      = s.recipe_id
        {$whereSql}
    ";
    $freshStmt = $pdo->prepare($freshSql);
    $freshStmt->execute($params);
    $freshRow  = $freshStmt->fetch();
    $bomFreshness = (!empty($freshRow['compiled_at']))
        ? (new DateTimeImmutable($freshRow['compiled_at']))->format(date_display_format())
        : null;

    $dbError = null;

} catch (Throwable $e) {
    $rows       = [];
    $kpiCounts  = null;
    $dbError    = $e->getMessage();
    $recipeRows = [];
    $medianCHFperHL = 0.0;
    $bomFreshness   = null;
}

// --- Anomaly detection (PHP, post-fetch) ---
// Build per-recipe bottle median
$bottlesByRecipe = [];
foreach ($rows as $r) {
    $rec = $r['recipe_short_name'] ?? '';
    if ($r['format'] === 'Bot' && is_numeric($r['chf_per_hl']) && (float) $r['chf_per_hl'] > 0) {
        $bottlesByRecipe[$rec][] = (float) $r['chf_per_hl'];
    }
}
$bottleMedianByRecipe = [];
foreach ($bottlesByRecipe as $rec => $vals) {
    $bottleMedianByRecipe[$rec] = array_median($vals);
}

// Build per-format median
$valsByFormat = [];
foreach ($rows as $r) {
    $fmt = $r['format'] ?? '';
    if (is_numeric($r['chf_per_hl']) && (float) $r['chf_per_hl'] > 0) {
        $valsByFormat[$fmt][] = (float) $r['chf_per_hl'];
    }
}
$formatMedians = [];
foreach ($valsByFormat as $fmt => $vals) {
    $formatMedians[$fmt] = array_median($vals);
}

// Tag each row
foreach ($rows as &$r) {
    $flags = [];
    $val   = is_numeric($r['chf_per_hl']) ? (float) $r['chf_per_hl'] : 0.0;
    $rec   = $r['recipe_short_name'] ?? '';
    $fmt   = $r['format'] ?? '';

    // keg-inverted: keg CHF/HL > bottle median for same recipe
    if ($fmt === 'Keg' && $val > 0 && isset($bottleMedianByRecipe[$rec]) && $bottleMedianByRecipe[$rec] > 0) {
        if ($val > $bottleMedianByRecipe[$rec]) {
            $flags[] = 'keg-inverted';
        }
    }
    // outlier: > 3× format median
    if ($val > 0 && isset($formatMedians[$fmt]) && $formatMedians[$fmt] > 0) {
        if ($val > 3 * $formatMedians[$fmt]) {
            $flags[] = 'outlier';
        }
    }
    // extreme-bom-line: any single ingredient > 50 kg/HL of pricing-unit
    // (= 500 g/L; max plausible is ~300 g/L for strongest beers' grain bill).
    if (is_numeric($r['max_qty_per_hl']) && (float) $r['max_qty_per_hl'] > 50) {
        $flags[] = 'extreme-bom-line';
    }

    $r['_flags'] = $flags;
}
unset($r);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SKU Costs — MaltyTask</title>
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

  <!-- KPI bar -->
  <section class="wort-kpis sku-kpis" aria-label="Statistiques SKU Costs">
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= htmlspecialchars((string) ($kpiCounts['total_skus'] ?? '—')) ?></span>
      <span class="wort-kpi__label">SKUs actifs</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= htmlspecialchars((string) ($kpiCounts['distinct_recipes'] ?? '—')) ?></span>
      <span class="wort-kpi__label">Recettes avec SKUs</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num sku-kpi__chf">
        <?= $medianCHFperHL > 0 ? htmlspecialchars(number_format($medianCHFperHL, 2)) : '—' ?>
      </span>
      <span class="wort-kpi__label">CHF/HL médian</span>
    </div>
  </section>

  <!-- Filter bar -->
  <form class="wort-filters" method="get" action="">
    <div class="wort-filters__row">

      <label class="wort-filters__field">
        <span class="wort-filters__label">Recette</span>
        <select name="recipe" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php foreach ($recipeRows as $rec): ?>
            <option value="<?= htmlspecialchars($rec) ?>"<?= ($filterRecipe === $rec) ? ' selected' : '' ?>>
              <?= htmlspecialchars($rec) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="wort-filters__field">
        <span class="wort-filters__label">Format</span>
        <select name="format" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php foreach (['Bot', 'Can', 'Keg', 'Cuve de service'] as $fmt): ?>
            <option value="<?= htmlspecialchars($fmt) ?>"<?= ($filterFormat === $fmt) ? ' selected' : '' ?>>
              <?= htmlspecialchars($fmt) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="wort-filters__field">
        <span class="wort-filters__label">Classification</span>
        <select name="classification" onchange="this.form.submit()">
          <option value="">Tous</option>
          <option value="Neb"<?= ($filterClassification === 'Neb') ? ' selected' : '' ?>>Neb</option>
          <option value="Contract"<?= ($filterClassification === 'Contract') ? ' selected' : '' ?>>Contract</option>
        </select>
      </label>

      <?php if ($anyFilter): ?>
        <a class="wort-filters__reset" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">Réinitialiser</a>
      <?php endif ?>

    </div>
  </form>

  <!-- SKU table -->
  <section class="wort-section" aria-label="SKU Costs">
    <div class="wort-section__head">
      <span class="wort-section__label">
        <?php if ($anyFilter): ?>
          — résultats filtrés
        <?php else: ?>
          — tous les SKUs actifs
        <?php endif ?>
      </span>
      <?php if ($anyFilter): ?>
        <span class="wort-filters__count"><?= count($rows) ?> résultat<?= count($rows) !== 1 ? 's' : '' ?></span>
      <?php endif ?>
      <?php if ($bomFreshness !== null): ?>
        <span class="sku-freshness">Coûts BOM à jour au <?= htmlspecialchars($bomFreshness) ?></span>
      <?php endif ?>
    </div>

    <?php if (empty($rows) && !$dbError): ?>
      <div class="empty">Aucun SKU.</div>
    <?php elseif (!empty($rows)): ?>
      <div class="wort-table-wrap">
        <table class="wort-table sku-table">
          <thead>
            <tr>
              <th scope="col">Recette</th>
              <th scope="col">SKU</th>
              <th scope="col">Format</th>
              <th scope="col">Unité</th>
              <th scope="col">HL/unit</th>
              <th scope="col">Brewing CHF</th>
              <th scope="col">Packaging CHF</th>
              <th scope="col">Total CHF</th>
              <th scope="col">CHF / HL</th>
              <th scope="col">Anomalies</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $prevRecipe = null;
            foreach ($rows as $r):
                $recipe  = $r['recipe_short_name'] ?? '';
                $flags   = $r['_flags'];
                $hasFlag = !empty($flags);
                $chfHL   = is_numeric($r['chf_per_hl']) ? (float) $r['chf_per_hl'] : null;
                $isContract = ($r['classification'] === 'Contract');
            ?>
            <?php if ($recipe !== $prevRecipe): ?>
              <tr class="sku-group-head">
                <td colspan="10" class="sku-group-head__cell">
                  <?= htmlspecialchars($recipe !== '' ? $recipe : '—') ?>
                </td>
              </tr>
              <?php $prevRecipe = $recipe; ?>
            <?php endif ?>
            <tr class="sku-row<?= $hasFlag ? ' sku-row--flagged' : '' ?>">
              <td class="wort-td sku-td sku-td--recipe">
                <?php if ($isContract): ?>
                  <span class="sku-badge sku-badge--contract">Contract</span>
                <?php endif ?>
              </td>
              <td class="wort-td sku-td sku-td--code">
                <a class="sku-code-link" href="/modules/sku-cost-detail.php?sku=<?= urlencode($r['sku_code'] ?? '') ?>">
                  <span class="wort-mono"><?= htmlspecialchars($r['sku_code'] ?? '—') ?></span>
                </a>
              </td>
              <td class="wort-td sku-td sku-td--format">
                <span class="sku-format-badge sku-format-badge--<?= htmlspecialchars(strtolower($r['format'] ?? '')) ?>">
                  <?= htmlspecialchars($r['format'] ?? '—') ?>
                </span>
              </td>
              <td class="wort-td sku-td">
                <?= htmlspecialchars($r['unit_label'] ?? '—') ?>
              </td>
              <td class="wort-td sku-td sku-td--num">
                <span class="wort-mono">
                  <?= is_numeric($r['hl_per_unit']) ? htmlspecialchars(number_format((float) $r['hl_per_unit'], 4)) : '—' ?>
                </span>
              </td>
              <td class="wort-td sku-td sku-td--num">
                <span class="wort-mono wort-muted">
                  <?= is_numeric($r['brewing_cost']) ? htmlspecialchars(number_format((float) $r['brewing_cost'], 2)) : '—' ?>
                </span>
              </td>
              <td class="wort-td sku-td sku-td--num">
                <span class="wort-mono wort-muted">
                  <?= is_numeric($r['packaging_cost']) ? htmlspecialchars(number_format((float) $r['packaging_cost'], 2)) : '—' ?>
                </span>
              </td>
              <td class="wort-td sku-td sku-td--num">
                <span class="wort-mono sku-total-cost">
                  <?= is_numeric($r['total_cost']) ? htmlspecialchars(number_format((float) $r['total_cost'], 2)) : '—' ?>
                </span>
              </td>
              <td class="wort-td sku-td sku-td--chf-hl">
                <span class="wort-mono sku-chf-hl<?= in_array('keg-inverted', $flags) ? ' sku-chf-hl--ember' : '' ?>">
                  <?= $chfHL !== null ? htmlspecialchars(number_format($chfHL, 2)) : '—' ?>
                </span>
              </td>
              <td class="wort-td sku-td sku-td--flags">
                <?php foreach ($flags as $flag): ?>
                  <?php
                  $badgeClass = match($flag) {
                      'keg-inverted'    => 'sku-anomaly sku-anomaly--red',
                      'outlier'         => 'sku-anomaly sku-anomaly--amber',
                      'extreme-bom-line'=> 'sku-anomaly sku-anomaly--red',
                      default           => 'sku-anomaly',
                  };
                  $badgeLabel = match($flag) {
                      'keg-inverted'    => 'Keg inversé',
                      'outlier'         => 'Outlier',
                      'extreme-bom-line'=> 'BOM extrême',
                      default           => htmlspecialchars($flag),
                  };
                  ?>
                  <span class="<?= htmlspecialchars($badgeClass) ?>"><?= $badgeLabel ?></span>
                <?php endforeach ?>
              </td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>

</main>

</body>
</html>
