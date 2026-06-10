<?php
declare(strict_types=1);
/**
 * modules/financier.php — Pôle Financier (hub analytique fiscal, lecture seule)
 *
 * Consomme uniquement des artefacts JSON pré-calculés par le pipeline maltytask :
 *   - cogs-report-data.json  → Module A (COP) via kpi_load_cogs_json()
 *   - sales-cogs-data.json   → Modules B/C (COGS + Marge) via kpi_load_sales_cogs_json()
 *
 * Architecture mémoire :
 *   - COP : injecté en entier (31 mois × ~10 champs = petit)
 *   - COGS/Ventes : seule la série de tendance (totaux par mois, sans bySKU) est injectée.
 *     Le détail par SKU du mois sélectionné est chargé en lazy via
 *     /api/financier-data.php?module=cogs&month=YYYY-MM (endpoint manager-only).
 *
 * NE recalcule JAMAIS les chiffres fiscaux. Lecture seule, manager+ uniquement.
 *
 * Auth : require_page_access('financier') → min_role='manager' dans ref_pages.
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/kpi-handlers.php';

require_page_access('financier');
$me = current_user();

/* ── Charge les artefacts JSON ────────────────────────────────────────────── */
$copData   = kpi_load_cogs_json();
$salesData = kpi_load_sales_cogs_json();

/* ── Fonctions utilitaires ─────────────────────────────────────────────────── */

/** Formate "YYYY-MM" → "Jan. 2026" (format fr-CH court) */
function fin_month_label(string $key): string {
    $fr = ['01'=>'Jan.','02'=>'Fév.','03'=>'Mar.','04'=>'Avr.','05'=>'Mai',
           '06'=>'Jun.','07'=>'Jul.','08'=>'Aoû.','09'=>'Sep.','10'=>'Oct.',
           '11'=>'Nov.','12'=>'Déc.'];
    [$y, $m] = explode('-', $key, 2);
    return ($fr[$m] ?? $m) . ' ' . $y;
}

/* ── COP : mois et dernier mois ───────────────────────────────────────────── */
$copMonths    = [];
$copLatestKey = null;

if ($copData !== null && !empty($copData['months'])) {
    foreach ($copData['months'] as $mo) {
        if (isset($mo['monthKey'])) $copMonths[] = $mo['monthKey'];
    }
    $copLatestKey = end($copMonths) ?: null;
}

$copArtifactPath = '/var/www/maltytask/interfaces/cogs-report-data.json';
$copFileMtime    = is_readable($copArtifactPath) ? filemtime($copArtifactPath) : null;
$copFreshnessTs  = $copFileMtime ? date('d.m.Y H:i', $copFileMtime) : null;
$copFreshnessLabel = $copLatestKey ? fin_month_label($copLatestKey) : null;

/* ── COP payload complet (petit — 31 mois × ~10 champs) ──────────────────── */
$copPayload = [];
if ($copData !== null && !empty($copData['months'])) {
    foreach ($copData['months'] as $mo) {
        $mk  = $mo['monthKey'] ?? null;
        $cop = $mo['cop']      ?? [];
        if ($mk === null) continue;
        $copPayload[$mk] = [
            'hlBrewed'       => $cop['hlBrewed']                ?? null,
            'hlPackaged'     => $cop['hlPackaged']               ?? null,
            'brewing'        => $cop['brewing']                  ?? [],
            'packaging'      => ['total' => $cop['packaging']['total'] ?? null],
            'indirect'       => ['total' => $cop['indirect']['total']  ?? null],
            'utilities'      => ['total' => $cop['utilities']['total'] ?? null],
            'rd'             => ['total' => $cop['rd']['total']        ?? null],
            'totalVariables' => $cop['totalVariables']           ?? [],
        ];
    }
}

/* ── COGS/Ventes : série tendance seulement (sans bySKU) ─────────────────── */
/* La serie est injectée une fois (compacte). bySKU est lazy-fetché par mois. */
$salesMonths    = [];
$salesLatestKey = null;
$salesGeneratedAt = null;

$salesTrendSeries = [];   // compact: monthKey → totals uniquement
if ($salesData !== null && !empty($salesData['months'])) {
    $salesMonths      = array_keys($salesData['months']);
    sort($salesMonths);
    $salesLatestKey   = end($salesMonths) ?: null;
    $salesGeneratedAt = $salesData['generatedAt'] ?? null;

    foreach ($salesData['months'] as $mk => $mo) {
        $t = $mo['totals'] ?? [];
        $salesTrendSeries[$mk] = [
            'units'          => $t['units']           ?? 0,
            'HL'             => $t['HL']              ?? 0,
            'material_CHF'   => $t['material_CHF']    ?? 0,
            'beerTax_CHF'    => $t['beerTax_CHF']     ?? 0,
            'salesCOGS_CHF'  => $t['salesCOGS_CHF']  ?? 0,
            'revenueCHF'     => $t['revenueCHF']      ?? 0,
        ];
    }
}

/* ── Default COGS slice (lazy endpoint fetches the rest) ─────────────────── */
/* Pré-charge le dernier mois pour un affichage immédiat sans requête JS. */
$defaultSalesSlice = null;
if ($salesLatestKey !== null) {
    $rawSlice = kpi_sales_cogs_month_slice($salesLatestKey);
    if ($rawSlice !== null) {
        $bySku = [];
        foreach ($rawSlice['bySKU'] ?? [] as $sku => $sd) {
            $bySku[$sku] = [
                'units'         => $sd['units']         ?? 0,
                'HL'            => $sd['HL']             ?? 0,
                'material_CHF'  => $sd['material_CHF']   ?? 0,
                'beerTax_CHF'   => $sd['beerTax_CHF']    ?? 0,
                'salesCOGS_CHF' => $sd['salesCOGS_CHF']  ?? 0,
                'revenueCHF'    => $sd['revenueCHF']     ?? 0,
                'unitCost'      => $sd['unitCost']       ?? 0,
                'hlPerUnit'     => $sd['hlPerUnit']      ?? 0,
                'beerTaxCat'    => $sd['beerTaxCat']     ?? null,
            ];
        }
        $defaultSalesSlice = [
            'month'       => $salesLatestKey,
            'totals'      => $rawSlice['totals']      ?? [],
            'bySKU'       => $bySku,
            'beerTax'     => $rawSlice['beerTax']     ?? [],
            'unknownSKUs' => $rawSlice['unknownSKUs'] ?? [],
            'nonBeerSKUs' => $rawSlice['nonBeerSKUs'] ?? [],
        ];
    }
}

/* ── Fraîcheur COGS ───────────────────────────────────────────────────────── */
$salesArtifactPath = '/var/www/maltytask/interfaces/sales-cogs-data.json';
$salesFileMtime    = is_readable($salesArtifactPath) ? filemtime($salesArtifactPath) : null;
$salesFreshnessTs  = $salesGeneratedAt
    ? (function() use ($salesGeneratedAt): ?string {
        try {
            $dt = new DateTimeImmutable($salesGeneratedAt, new DateTimeZone('Europe/Zurich'));
            return $dt->format('d.m.Y H:i');
        } catch (Throwable $e) { return null; }
    })()
    : ($salesFileMtime ? date('d.m.Y H:i', $salesFileMtime) : null);
$salesFreshnessLabel = $salesLatestKey ? fin_month_label($salesLatestKey) : null;

/* Flags JSON pour injection XSS-safe */
$JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

$active_module = 'financier';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Financier — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/financier.css?v=<?= @filemtime(__DIR__ . '/../css/financier.css') ?: time() ?>">
</head>
<body class="home financier">

<?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

<main id="main-content" class="main">
<div class="fin-wrap">

  <!-- ── En-tête de page ──────────────────────────────────────────────────── -->
  <header class="fin-page-head">
    <h1 class="fin-title">Pôle Financier</h1>
    <p class="fin-desc">
      Analytique fiscale — lecture seule.
      Source : grand-livre des ventes (BC, depuis 2021) + pipeline COP.
    </p>
  </header>

  <?php if ($copData === null && $salesData === null): ?>
  <div class="fin-no-data">
    <p>Aucun artefact JSON disponible. Lance le pipeline maltytask pour générer les données.</p>
  </div>
  <?php else: ?>

  <!-- ── Navigation entre modules ─────────────────────────────────────────── -->
  <nav class="fin-tabs" role="tablist" aria-label="Modules Financier">
    <button class="fin-tab fin-tab--active" role="tab" aria-selected="true"
            aria-controls="fin-panel-cop" id="fin-tab-cop" data-tab="cop">
      COP
    </button>
    <button class="fin-tab" role="tab" aria-selected="false"
            aria-controls="fin-panel-cogs" id="fin-tab-cogs" data-tab="cogs">
      COGS &amp; Ventes
    </button>
    <button class="fin-tab" role="tab" aria-selected="false"
            aria-controls="fin-panel-marge" id="fin-tab-marge" data-tab="marge">
      Marge / ASP
    </button>
    <button class="fin-tab" role="tab" aria-selected="false"
            aria-controls="fin-panel-sku" id="fin-tab-sku" data-tab="sku">
      Coût par SKU
    </button>
  </nav>

  <!-- ══════════════════════════════════════════════════════════════════════════
       MODULE A — COP (Coût de Production)
  ══════════════════════════════════════════════════════════════════════════ -->
  <section class="fin-panel fin-panel--active" id="fin-panel-cop"
           role="tabpanel" aria-labelledby="fin-tab-cop">

    <?php if (empty($copPayload)): ?>
      <p class="fin-empty">Données COP indisponibles.</p>
    <?php else: ?>

    <div class="fin-freshness">
      <span class="fin-freshness__label">
        COP au <?= htmlspecialchars($copFreshnessLabel ?? '—') ?>
      </span>
      <span class="fin-freshness__ts">
        Données générées le <?= htmlspecialchars($copFreshnessTs ?? '—') ?>
      </span>
      <span class="fin-freshness__hint">Régénération manuelle (pipeline maltytask)</span>
    </div>

    <div class="fin-controls">
      <label class="fin-picker-label" for="cop-month-select">Mois</label>
      <select id="cop-month-select" class="fin-month-select" aria-label="Sélection du mois COP">
        <?php foreach (array_reverse($copMonths) as $mk): ?>
          <option value="<?= htmlspecialchars($mk) ?>"
            <?= ($mk === $copLatestKey) ? 'selected' : '' ?>>
            <?= htmlspecialchars(fin_month_label($mk)) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>

    <div class="fin-kpi-grid" id="cop-kpis" role="group" aria-label="KPI COP du mois sélectionné">
    </div>
    <p role="status" class="fin-kpi-status sr-only" id="cop-kpi-status"></p>

    <div class="fin-sections-grid" id="cop-sections">
    </div>

    <div class="fin-card fin-card--brew" id="cop-brew-detail">
    </div>

    <div class="fin-card fin-card--chart" id="cop-trend-wrap">
      <h3 class="fin-card__title">COP/HL — tendance mensuelle</h3>
      <div id="cop-trend-chart" class="fin-chart-area" aria-label="Tendance COP par HL">
      </div>
    </div>

    <?php endif ?>
  </section>

  <!-- ══════════════════════════════════════════════════════════════════════════
       MODULE B — COGS & Ventes
  ══════════════════════════════════════════════════════════════════════════ -->
  <section class="fin-panel" id="fin-panel-cogs" hidden
           role="tabpanel" aria-labelledby="fin-tab-cogs">

    <?php if (empty($salesTrendSeries)): ?>
      <p class="fin-empty">Données COGS indisponibles.</p>
    <?php else: ?>

    <div class="fin-freshness">
      <span class="fin-freshness__label">
        Ventes au <?= htmlspecialchars($salesFreshnessLabel ?? '—') ?>
      </span>
      <span class="fin-freshness__ts">
        Données générées le <?= htmlspecialchars($salesFreshnessTs ?? '—') ?>
      </span>
      <span class="fin-freshness__source">
        Grand-livre des ventes (BC, depuis 2021)
      </span>
      <span class="fin-freshness__hint">Régénération manuelle (pipeline maltytask)</span>
    </div>

    <div class="fin-controls">
      <label class="fin-picker-label" for="cogs-month-select">Mois</label>
      <select id="cogs-month-select" class="fin-month-select" aria-label="Sélection du mois COGS">
        <?php foreach (array_reverse($salesMonths) as $mk): ?>
          <option value="<?= htmlspecialchars($mk) ?>"
            <?= ($mk === $salesLatestKey) ? 'selected' : '' ?>>
            <?= htmlspecialchars(fin_month_label($mk)) ?>
          </option>
        <?php endforeach ?>
      </select>
      <span class="fin-loading-indicator" id="cogs-loading" hidden aria-live="polite">
        Chargement…
      </span>
    </div>

    <div class="fin-kpi-grid fin-kpi-grid--cogs" id="cogs-kpis"
         role="group" aria-label="Totaux COGS du mois sélectionné">
    </div>
    <p role="status" class="fin-kpi-status sr-only" id="cogs-kpi-status"></p>

    <div class="fin-card fin-card--table" id="cogs-sku-wrap">
      <div class="fin-card__head">
        <h3 class="fin-card__title">Détail par SKU</h3>
        <div class="fin-sort-controls">
          <label class="fin-sort-label" for="cogs-sort-select">Trier par</label>
          <select id="cogs-sort-select" class="fin-sort-select" aria-label="Tri du tableau SKU">
            <option value="revenueCHF">CA (CHF)</option>
            <option value="salesCOGS_CHF">COGS (CHF)</option>
            <option value="units">Unités</option>
            <option value="sku">SKU</option>
          </select>
        </div>
      </div>
      <div class="fin-table-scroll">
        <table class="fin-table" id="cogs-sku-table" aria-label="COGS par SKU">
          <thead>
            <tr>
              <th scope="col">SKU</th>
              <th scope="col" class="fin-th--num">Unités</th>
              <th scope="col" class="fin-th--num">Coût unit. (CHF)</th>
              <th scope="col" class="fin-th--num">Matières (CHF)</th>
              <th scope="col" class="fin-th--num">Taxe bière (CHF)</th>
              <th scope="col" class="fin-th--num">COGS total (CHF)</th>
              <th scope="col" class="fin-th--num">CA (CHF)</th>
              <th scope="col">Catégorie taxe</th>
            </tr>
          </thead>
          <tbody id="cogs-sku-tbody">
          </tbody>
        </table>
      </div>
    </div>

    <div class="fin-card" id="cogs-beertax-wrap">
      <h3 class="fin-card__title">Taxe bière — répartition par catégorie</h3>
      <div id="cogs-beertax" class="fin-beertax-grid">
      </div>
    </div>

    <div class="fin-card fin-card--warn" id="cogs-unmatched-wrap" hidden>
      <h3 class="fin-card__title fin-card__title--warn">SKUs non rattachés</h3>
      <div id="cogs-unmatched"></div>
    </div>

    <div class="fin-card fin-card--chart" id="cogs-trend-wrap">
      <h3 class="fin-card__title">COGS &amp; CA — tendance mensuelle</h3>
      <div id="cogs-trend-chart" class="fin-chart-area" aria-label="Tendance COGS et CA mensuel">
      </div>
    </div>

    <?php endif ?>
  </section>

  <!-- ══════════════════════════════════════════════════════════════════════════
       MODULE C — Marge / ASP
  ══════════════════════════════════════════════════════════════════════════ -->
  <section class="fin-panel" id="fin-panel-marge" hidden
           role="tabpanel" aria-labelledby="fin-tab-marge">

    <?php if (empty($salesTrendSeries)): ?>
      <p class="fin-empty">Données de marge indisponibles (artefact sales-cogs requis).</p>
    <?php else: ?>

    <div class="fin-freshness">
      <span class="fin-freshness__label">
        Marge au <?= htmlspecialchars($salesFreshnessLabel ?? '—') ?>
      </span>
      <span class="fin-freshness__ts">
        Données générées le <?= htmlspecialchars($salesFreshnessTs ?? '—') ?>
      </span>
      <span class="fin-freshness__hint">Régénération manuelle (pipeline maltytask)</span>
    </div>

    <div class="fin-controls">
      <label class="fin-picker-label" for="marge-month-select">Mois</label>
      <select id="marge-month-select" class="fin-month-select" aria-label="Sélection du mois Marge">
        <?php foreach (array_reverse($salesMonths) as $mk): ?>
          <option value="<?= htmlspecialchars($mk) ?>"
            <?= ($mk === $salesLatestKey) ? 'selected' : '' ?>>
            <?= htmlspecialchars(fin_month_label($mk)) ?>
          </option>
        <?php endforeach ?>
      </select>
      <span class="fin-loading-indicator" id="marge-loading" hidden aria-live="polite">
        Chargement…
      </span>
    </div>

    <div class="fin-kpi-grid fin-kpi-grid--marge" id="marge-kpis"
         role="group" aria-label="KPI Marge du mois sélectionné">
    </div>
    <p role="status" class="fin-kpi-status sr-only" id="marge-kpi-status"></p>

    <div class="fin-card fin-card--table" id="marge-sku-wrap">
      <div class="fin-card__head">
        <h3 class="fin-card__title">Marge par SKU</h3>
        <div class="fin-sort-controls">
          <label class="fin-sort-label" for="marge-sort-select">Trier par</label>
          <select id="marge-sort-select" class="fin-sort-select" aria-label="Tri marge par SKU">
            <option value="grossMargin">Marge brute (CHF)</option>
            <option value="marginPct">Marge %</option>
            <option value="revenueCHF">CA (CHF)</option>
            <option value="asp">ASP (CHF/unité)</option>
            <option value="sku">SKU</option>
          </select>
        </div>
      </div>
      <div class="fin-table-scroll">
        <table class="fin-table" id="marge-sku-table" aria-label="Marge par SKU">
          <thead>
            <tr>
              <th scope="col">SKU</th>
              <th scope="col" class="fin-th--num">CA (CHF)</th>
              <th scope="col" class="fin-th--num">COGS (CHF)</th>
              <th scope="col" class="fin-th--num">Marge brute (CHF)</th>
              <th scope="col" class="fin-th--num">Marge %</th>
              <th scope="col" class="fin-th--num">ASP (CHF/u)</th>
              <th scope="col">Segmentation</th>
            </tr>
          </thead>
          <tbody id="marge-sku-tbody">
          </tbody>
        </table>
      </div>
    </div>

    <?php endif ?>
  </section>

  <!-- ══════════════════════════════════════════════════════════════════════════
       MODULE D — Coût par SKU (lien externe)
  ══════════════════════════════════════════════════════════════════════════ -->
  <section class="fin-panel" id="fin-panel-sku" hidden
           role="tabpanel" aria-labelledby="fin-tab-sku">
    <div class="fin-linkout-card">
      <div class="fin-linkout-card__icon" aria-hidden="true">◈</div>
      <div class="fin-linkout-card__body">
        <h3 class="fin-linkout-card__title">Coût par SKU</h3>
        <p class="fin-linkout-card__desc">
          Détail du coût de revient par SKU, décomposé par source (brassage, packaging).
          Données issues du BOM actif.
        </p>
        <a href="/modules/sku-costs.php" class="fin-linkout-btn">
          Ouvrir SKU Costs →
        </a>
      </div>
    </div>
  </section>

  <?php endif ?>

</div><!-- /.fin-wrap -->
</main>

<!-- ─── Payload JSON injecté côté serveur — consommé par financier.js ─────── -->
<script>
/* COP complet — keyed by monthKey */
window.FIN_COP        = <?= json_encode($copPayload,       $JSON_FLAGS) ?>;
window.FIN_COP_MONTHS = <?= json_encode($copMonths,        $JSON_FLAGS) ?>;
window.FIN_COP_DEFAULT = <?= json_encode($copLatestKey,    $JSON_FLAGS) ?>;

/* COGS : série tendance seulement (sans bySKU pour économiser ~5MB d'HTML) */
window.FIN_SALES_TREND  = <?= json_encode($salesTrendSeries, $JSON_FLAGS) ?>;
window.FIN_SALES_MONTHS = <?= json_encode($salesMonths,      $JSON_FLAGS) ?>;
window.FIN_SALES_DEFAULT = <?= json_encode($salesLatestKey,  $JSON_FLAGS) ?>;

/* Slice du mois par défaut pré-chargée pour rendu immédiat (dernier mois seulement) */
window.FIN_SALES_DEFAULT_SLICE = <?= json_encode($defaultSalesSlice, $JSON_FLAGS) ?>;
</script>

<script src="/js/kpi-charts.js?v=<?= @filemtime(__DIR__ . '/../js/kpi-charts.js') ?: time() ?>"></script>
<script src="/js/financier.js?v=<?= @filemtime(__DIR__ . '/../js/financier.js') ?: time() ?>"></script>

</body>
</html>
