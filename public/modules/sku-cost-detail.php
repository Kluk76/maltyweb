<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require_once __DIR__ . "/../../app/settings.php";

require_page_access('sku-costs');
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "sku-costs";

// --- Validate SKU param ---
$skuParam = isset($_GET['sku']) ? trim((string) $_GET['sku']) : '';
// Sanitise: allow only alphanumeric + hyphen/underscore (all real SKU codes)
if (!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $skuParam)) {
    $skuParam = '';
}

$crumbs = ["Accueil", "SKU Costs", $skuParam !== '' ? strtoupper($skuParam) : '—'];

$skuRow      = null;
$bomRows     = [];
$dbError     = null;
$notFound    = false;
$anomalyFlags= [];
$bomFreshness= null;

try {
    $pdo = maltytask_pdo();

    if ($skuParam === '') {
        $notFound = true;
    } else {
        // Lookup SKU
        $skuStmt = $pdo->prepare("
            SELECT
                s.id, s.sku_code, s.format, s.unit_label, s.hl_per_unit,
                rr.recipe_short_name, rr.classification, rr.subtype,
                ROUND(SUM(b.cost), 3)                                                   AS total_cost,
                ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit, 0), 2)                        AS chf_per_hl,
                ROUND(SUM(CASE WHEN b.source = 'Brewing'   THEN b.cost ELSE 0 END), 3) AS brewing_cost,
                ROUND(SUM(CASE WHEN b.source = 'Packaging' THEN b.cost ELSE 0 END), 3) AS packaging_cost,
                MAX(b.qty_per_unit / NULLIF(s.hl_per_unit, 0))                          AS max_qty_per_hl
            FROM ref_skus s
            LEFT JOIN ref_sku_bom b  ON b.sku_id  = s.id
            LEFT JOIN ref_recipes rr ON rr.id      = s.recipe_id
            WHERE s.sku_code = :sku
            GROUP BY s.id
        ");
        $skuStmt->execute([':sku' => $skuParam]);
        $skuRow = $skuStmt->fetch();

        if (!$skuRow) {
            $notFound = true;
        } else {
            // BOM lines
            $bomStmt = $pdo->prepare("
                SELECT
                    b.source, b.category_raw, b.ingredient_raw,
                    b.qty_per_unit, b.ing_unit,
                    b.pricing_unit, b.price, b.currency, b.cost,
                    b.mi_id, b.resolution,
                    m.mi_id      AS mi_canonical,
                    c.name       AS category_canonical
                FROM ref_sku_bom b
                LEFT JOIN ref_mi m            ON m.id = b.mi_id
                LEFT JOIN ref_mi_categories c ON c.id = m.category_id
                WHERE b.sku_id = :sku_id
                ORDER BY
                    CASE b.source WHEN 'Brewing' THEN 1 WHEN 'Packaging' THEN 2 ELSE 3 END,
                    b.cost DESC
            ");
            $bomStmt->execute([':sku_id' => $skuRow['id']]);
            $bomRows = $bomStmt->fetchAll();

            // Freshness: MAX(compiled_at) for this specific SKU's BOM rows
            $freshStmt = $pdo->prepare("
                SELECT MAX(compiled_at) AS compiled_at
                FROM ref_sku_bom
                WHERE sku_id = :sku_id
            ");
            $freshStmt->execute([':sku_id' => $skuRow['id']]);
            $freshRow     = $freshStmt->fetch();
            $bomFreshness = (!empty($freshRow['compiled_at']))
                ? (new DateTimeImmutable($freshRow['compiled_at']))->format(date_display_format())
                : null;

            // --- Anomaly detection ---
            $chfHL = is_numeric($skuRow['chf_per_hl']) ? (float) $skuRow['chf_per_hl'] : 0.0;
            $fmt   = $skuRow['format'] ?? '';
            $rec   = $skuRow['recipe_short_name'] ?? '';

            // keg-inverted: fetch bottle median for same recipe
            if ($fmt === 'Keg' && $chfHL > 0) {
                $btlStmt = $pdo->prepare("
                    SELECT ROUND(SUM(b2.cost) / NULLIF(s2.hl_per_unit, 0), 2) AS cph
                    FROM ref_skus s2
                    LEFT JOIN ref_sku_bom b2 ON b2.sku_id = s2.id
                    LEFT JOIN ref_recipes rr2 ON rr2.id = s2.recipe_id
                    WHERE s2.is_active = 1
                      AND s2.format = 'Bot'
                      AND rr2.recipe_short_name = :rec
                    GROUP BY s2.id
                ");
                $btlStmt->execute([':rec' => $rec]);
                $btlVals = array_filter(
                    array_map('floatval', $btlStmt->fetchAll(PDO::FETCH_COLUMN)),
                    fn($v) => $v > 0
                );
                if (!empty($btlVals)) {
                    sort($btlVals);
                    $n = count($btlVals);
                    $mid = (int) floor($n / 2);
                    $btlMedian = ($n % 2 === 0) ? (($btlVals[$mid-1] + $btlVals[$mid]) / 2.0) : $btlVals[$mid];
                    if ($chfHL > $btlMedian) {
                        $anomalyFlags[] = 'keg-inverted';
                    }
                }
            }

            // outlier: > 3× format median
            if ($chfHL > 0) {
                $fmtStmt = $pdo->prepare("
                    SELECT ROUND(SUM(b3.cost) / NULLIF(s3.hl_per_unit, 0), 2) AS cph
                    FROM ref_skus s3
                    LEFT JOIN ref_sku_bom b3 ON b3.sku_id = s3.id
                    WHERE s3.is_active = 1
                      AND s3.format = :fmt
                    GROUP BY s3.id
                ");
                $fmtStmt->execute([':fmt' => $fmt]);
                $fmtVals = array_filter(
                    array_map('floatval', $fmtStmt->fetchAll(PDO::FETCH_COLUMN)),
                    fn($v) => $v > 0
                );
                if (!empty($fmtVals)) {
                    sort($fmtVals);
                    $n = count($fmtVals);
                    $mid = (int) floor($n / 2);
                    $fmtMedian = ($n % 2 === 0) ? (($fmtVals[$mid-1] + $fmtVals[$mid]) / 2.0) : $fmtVals[$mid];
                    if ($chfHL > 3 * $fmtMedian) {
                        $anomalyFlags[] = 'outlier';
                    }
                }
            }

            // extreme-bom-line
            if (is_numeric($skuRow['max_qty_per_hl']) && (float) $skuRow['max_qty_per_hl'] > 5) {
                $anomalyFlags[] = 'extreme-bom-line';
            }
        }
    }

} catch (Throwable $e) {
    $dbError      = $e->getMessage();
    $bomFreshness = null;
}

$totalCost = is_numeric($skuRow['total_cost'] ?? null) ? (float) $skuRow['total_cost'] : 0.0;
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $skuRow ? htmlspecialchars($skuRow['sku_code']) . ' — SKU Costs' : 'SKU Costs' ?> — MaltyTask</title>
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

  <?php if ($notFound && !$dbError): ?>
    <div class="sku-detail-empty">
      <p class="sku-detail-empty__msg">SKU non trouvé.</p>
      <a class="sku-detail-back" href="/modules/sku-costs.php">&larr; Retour à la liste</a>
    </div>

  <?php elseif ($skuRow): ?>

    <!-- Anomaly banners -->
    <?php if (!empty($anomalyFlags)): ?>
      <div class="sku-anomaly-banners" role="alert">
        <?php if (in_array('keg-inverted', $anomalyFlags)): ?>
          <div class="sku-banner sku-banner--red">
            Anomalie: Coût/HL inversé — ce keg coûte plus cher par HL que les bouteilles de la même recette.
          </div>
        <?php endif ?>
        <?php if (in_array('outlier', $anomalyFlags)): ?>
          <div class="sku-banner sku-banner--amber">
            Anomalie: Outlier — CHF/HL supérieur à 3× la médiane du format <?= htmlspecialchars($skuRow['format'] ?? '') ?>.
          </div>
        <?php endif ?>
        <?php if (in_array('extreme-bom-line', $anomalyFlags)): ?>
          <div class="sku-banner sku-banner--red">
            Anomalie: BOM extrême — au moins une ligne dépasse 5 kg d'ingrédient par HL (&gt;50 g/L).
          </div>
        <?php endif ?>
      </div>
    <?php endif ?>

    <!-- SKU header card -->
    <div class="sku-header-card">
      <div class="sku-header-card__top">
        <span class="sku-header-card__code"><?= htmlspecialchars($skuRow['sku_code'] ?? '') ?></span>
        <?php if (($skuRow['classification'] ?? '') === 'Contract'): ?>
          <span class="sku-badge sku-badge--contract">Contract</span>
        <?php endif ?>
        <?php
        $fmt = $skuRow['format'] ?? '';
        if ($fmt !== ''):
        ?>
          <span class="sku-format-badge sku-format-badge--<?= htmlspecialchars(strtolower($fmt)) ?>">
            <?= htmlspecialchars($fmt) ?>
          </span>
        <?php endif ?>
      </div>
      <div class="sku-header-card__meta">
        <span class="sku-header-meta__item">
          <span class="sku-header-meta__label">Recette</span>
          <span class="sku-header-meta__val"><?= htmlspecialchars($skuRow['recipe_short_name'] ?? '—') ?></span>
        </span>
        <span class="sku-header-meta__sep">·</span>
        <span class="sku-header-meta__item">
          <span class="sku-header-meta__label">Unité</span>
          <span class="sku-header-meta__val"><?= htmlspecialchars($skuRow['unit_label'] ?? '—') ?></span>
        </span>
        <span class="sku-header-meta__sep">·</span>
        <span class="sku-header-meta__item">
          <span class="sku-header-meta__label">HL/unit</span>
          <span class="sku-header-meta__val wort-mono">
            <?= is_numeric($skuRow['hl_per_unit']) ? htmlspecialchars(number_format((float) $skuRow['hl_per_unit'], 4)) : '—' ?>
          </span>
        </span>
      </div>
      <div class="sku-header-card__costs">
        <div class="sku-header-cost">
          <span class="sku-header-cost__val wort-mono">
            <?= is_numeric($skuRow['total_cost']) ? htmlspecialchars(number_format((float) $skuRow['total_cost'], 2)) : '—' ?>
          </span>
          <span class="sku-header-cost__label">Total CHF</span>
        </div>
        <div class="sku-header-cost sku-header-cost--focus">
          <span class="sku-header-cost__val wort-mono<?= in_array('keg-inverted', $anomalyFlags) ? ' sku-chf-hl--ember' : '' ?>">
            <?= is_numeric($skuRow['chf_per_hl']) ? htmlspecialchars(number_format((float) $skuRow['chf_per_hl'], 2)) : '—' ?>
          </span>
          <span class="sku-header-cost__label">CHF / HL</span>
        </div>
      </div>
    </div>

    <!-- BOM table -->
    <section class="wort-section sku-bom-section" aria-label="BOM">
      <div class="wort-section__head">
        <span class="wort-section__label">— bill of materials</span>
        <?php if (!empty($bomFreshness)): ?>
          <span class="sku-freshness">Coûts BOM à jour au <?= htmlspecialchars($bomFreshness) ?></span>
        <?php endif ?>
      </div>

      <?php if (empty($bomRows)): ?>
        <div class="empty">Aucune ligne BOM.</div>
      <?php else: ?>
        <div class="wort-table-wrap">
          <table class="wort-table sku-bom-table">
            <thead>
              <tr>
                <th scope="col">Catégorie</th>
                <th scope="col">Ingrédient</th>
                <th scope="col">MI ID</th>
                <th scope="col">Qté</th>
                <th scope="col">Unité</th>
                <th scope="col">Prix</th>
                <th scope="col">Coût CHF</th>
                <th scope="col">% du total</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $prevSource = null;
              foreach ($bomRows as $b):
                  $source    = $b['source'] ?? '';
                  $cost      = is_numeric($b['cost']) ? (float) $b['cost'] : 0.0;
                  $pct       = ($totalCost > 0) ? min(100.0, ($cost / $totalCost) * 100.0) : 0.0;
                  $miMatched = !empty($b['mi_canonical']);
              ?>
              <?php if ($source !== $prevSource): ?>
                <tr class="sku-bom-source-head">
                  <td colspan="8" class="sku-bom-source-head__cell">
                    <?= htmlspecialchars($source !== '' ? $source : '—') ?>
                  </td>
                </tr>
                <?php $prevSource = $source; ?>
              <?php endif ?>
              <tr class="sku-bom-row">
                <td class="wort-td sku-bom-td sku-bom-td--cat">
                  <?= htmlspecialchars($b['category_canonical'] ?? $b['category_raw'] ?? '—') ?>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--ing">
                  <span class="<?= $miMatched ? 'sku-ing--matched' : 'sku-ing--unresolved' ?>">
                    <?= htmlspecialchars($b['ingredient_raw'] ?? '—') ?>
                  </span>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--miid">
                  <?php if (!empty($b['mi_canonical'])): ?>
                    <span class="wort-mono wort-muted sku-bom-miid"><?= htmlspecialchars($b['mi_canonical']) ?></span>
                  <?php else: ?>
                    <span class="wort-muted">—</span>
                  <?php endif ?>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--num">
                  <span class="wort-mono">
                    <?= is_numeric($b['qty_per_unit']) ? htmlspecialchars(number_format((float) $b['qty_per_unit'], 4)) : '—' ?>
                  </span>
                </td>
                <td class="wort-td sku-bom-td">
                  <?= htmlspecialchars($b['ing_unit'] ?? '—') ?>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--num">
                  <span class="wort-mono wort-muted">
                    <?php if (is_numeric($b['price'])): ?>
                      <?= htmlspecialchars(number_format((float) $b['price'], 4)) ?>
                      <?php if (!empty($b['currency']) && $b['currency'] !== 'CHF'): ?>
                        <span class="sku-bom-currency"><?= htmlspecialchars($b['currency']) ?></span>
                      <?php endif ?>
                    <?php else: ?>
                      —
                    <?php endif ?>
                  </span>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--num">
                  <span class="wort-mono sku-total-cost">
                    <?= $cost > 0 ? htmlspecialchars(number_format($cost, 3)) : '—' ?>
                  </span>
                </td>
                <td class="wort-td sku-bom-td sku-bom-td--pct">
                  <div class="sku-bom-pct">
                    <div class="sku-bom-pct__bar" style="width: <?= number_format(min($pct, 100), 1) ?>%"></div>
                    <span class="sku-bom-pct__num wort-mono">
                      <?= $pct > 0 ? htmlspecialchars(number_format($pct, 1)) . '%' : '—' ?>
                    </span>
                  </div>
                </td>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>

    <!-- Footer nav -->
    <div class="sku-detail-footer">
      <a class="sku-detail-back" href="/modules/sku-costs.php">&larr; Retour</a>
    </div>

  <?php endif ?>

</main>

</body>
</html>
