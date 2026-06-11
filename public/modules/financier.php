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
require_once __DIR__ . '/../../app/settings.php';        // date_display_format() — used by the SKU-cost BOM freshness stamp
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

/* ── GL Months — months available in inv_charges_bc ──────────────────────── */
/* Fetched once server-side and injected for the grid month pickers.          */
$glMonths        = [];
$glLatestMonth   = null;

try {
    $pdoGl = maltytask_pdo();
    // Fetch month + row count to default to the most-populated (complete) month
    $glPtRows = $pdoGl->query(
        "SELECT period_text, COUNT(*) AS cnt FROM inv_charges_bc WHERE is_summary = 0 GROUP BY period_text ORDER BY period_text"
    )->fetchAll(PDO::FETCH_ASSOC);

    $glMonthCounts = [];   // mk → row count
    foreach ($glPtRows as $row) {
        $pt = $row['period_text'] ?? '';
        if (!preg_match('/01\.(\d{2})\.(\d{2})/', $pt, $ptm)) continue;
        $mk = '20' . $ptm[2] . '-' . $ptm[1];
        if (!isset($glMonthCounts[$mk])) {
            $glMonthCounts[$mk] = 0;
            $glMonths[] = $mk;
        }
        $glMonthCounts[$mk] += (int) $row['cnt'];
    }
    sort($glMonths);
    // Default = month with the highest row count (most complete GL export)
    if (!empty($glMonthCounts)) {
        arsort($glMonthCounts);
        $glLatestMonth = array_key_first($glMonthCounts);
    }
} catch (Throwable $e) {
    // Non-fatal — grid will show empty state
}

/* ── Module D — Coût par SKU (lec ture BOM compilé depuis ref_sku_bom) ────── */
/* Même requête que sku-costs.php — lecture seule, DB canonique, jamais recalculé ici */

/** Calcule la médiane d'un tableau de flottants */
function fin_array_median(array $vals): float {
    if (empty($vals)) return 0.0;
    sort($vals);
    $n   = count($vals);
    $mid = (int) floor($n / 2);
    return ($n % 2 === 0) ? (($vals[$mid - 1] + $vals[$mid]) / 2.0) : (float) $vals[$mid];
}

/** Formate un montant CHF en style suisse : 2 décimales, espace pour les milliers */
function fin_fmt_chf(float $val): string {
    return number_format($val, 2, '.', ' ');
}

$skuRows          = [];
$skuKpiCounts     = null;
$skuMedianCHFperHL = 0.0;
$skuBomFreshness  = null;
$skuRecipeList    = [];   // pour les filtres client-side
$skuDbError       = null;

try {
    $pdo = maltytask_pdo();

    /* --- KPI counts --- */
    $skuKpiStmt = $pdo->query("
        SELECT
            COUNT(DISTINCT s.id)        AS total_skus,
            COUNT(DISTINCT s.recipe_id) AS distinct_recipes
        FROM ref_skus s
        WHERE s.is_active = 1
    ");
    $skuKpiCounts = $skuKpiStmt->fetch();

    /* --- Valeurs CHF/HL pour médiane --- */
    $skuMedRows = $pdo->query("
        SELECT ROUND(SUM(b.cost) / NULLIF(s.hl_per_unit, 0), 2) AS chf_per_hl
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b ON b.sku_id = s.id
        WHERE s.is_active = 1
        GROUP BY s.id
    ")->fetchAll(PDO::FETCH_COLUMN);
    $skuMedVals = array_filter(array_map('floatval', $skuMedRows), fn($v) => $v > 0);
    $skuMedianCHFperHL = fin_array_median(array_values($skuMedVals));

    /* --- Requête principale (VERBATIM depuis sku-costs.php, sans filtre server-side) --- */
    $skuRowStmt = $pdo->query("
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
        WHERE s.is_active = 1
        GROUP BY s.id
        ORDER BY rr.recipe_short_name,
                 FIELD(s.format, 'Bot', 'Can', 'Keg', 'Cuve de service'),
                 s.sku_code
    ");
    $skuRows = $skuRowStmt->fetchAll();

    /* --- Fraîcheur BOM --- */
    $skuFreshRow  = $pdo->query("
        SELECT MAX(b.compiled_at) AS compiled_at
        FROM ref_skus s
        LEFT JOIN ref_sku_bom b ON b.sku_id = s.id
        WHERE s.is_active = 1
    ")->fetch();
    $skuBomFreshness = (!empty($skuFreshRow['compiled_at']))
        ? (new DateTimeImmutable($skuFreshRow['compiled_at']))->format(date_display_format())
        : null;

    /* --- Liste de recettes pour filtres JS --- */
    foreach ($skuRows as $r) {
        $rec = $r['recipe_short_name'] ?? '';
        if ($rec !== '' && !in_array($rec, $skuRecipeList, true)) {
            $skuRecipeList[] = $rec;
        }
    }

} catch (Throwable $e) {
    $skuDbError = $e->getMessage();
    // COP/COGS panels non affectés — le catch est local à ce bloc
}

/* --- Détection d'anomalies (même logique que sku-costs.php) --- */
$skuBottlesByRecipe = [];
foreach ($skuRows as $r) {
    $rec = $r['recipe_short_name'] ?? '';
    if ($r['format'] === 'Bot' && is_numeric($r['chf_per_hl']) && (float) $r['chf_per_hl'] > 0) {
        $skuBottlesByRecipe[$rec][] = (float) $r['chf_per_hl'];
    }
}
$skuBottleMedianByRecipe = [];
foreach ($skuBottlesByRecipe as $rec => $vals) {
    $skuBottleMedianByRecipe[$rec] = fin_array_median($vals);
}
$skuValsByFormat = [];
foreach ($skuRows as $r) {
    $fmt = $r['format'] ?? '';
    if (is_numeric($r['chf_per_hl']) && (float) $r['chf_per_hl'] > 0) {
        $skuValsByFormat[$fmt][] = (float) $r['chf_per_hl'];
    }
}
$skuFormatMedians = [];
foreach ($skuValsByFormat as $fmt => $vals) {
    $skuFormatMedians[$fmt] = fin_array_median($vals);
}
foreach ($skuRows as &$r) {
    $flags = [];
    $val   = is_numeric($r['chf_per_hl']) ? (float) $r['chf_per_hl'] : 0.0;
    $rec   = $r['recipe_short_name'] ?? '';
    $fmt   = $r['format'] ?? '';
    if ($fmt === 'Keg' && $val > 0 && isset($skuBottleMedianByRecipe[$rec]) && $skuBottleMedianByRecipe[$rec] > 0) {
        if ($val > $skuBottleMedianByRecipe[$rec]) { $flags[] = 'keg-inverted'; }
    }
    if ($val > 0 && isset($skuFormatMedians[$fmt]) && $skuFormatMedians[$fmt] > 0) {
        if ($val > 3 * $skuFormatMedians[$fmt]) { $flags[] = 'outlier'; }
    }
    if (is_numeric($r['max_qty_per_hl']) && (float) $r['max_qty_per_hl'] > 50) {
        $flags[] = 'extreme-bom-line';
    }
    $r['_flags'] = $flags;
}
unset($r);

/* ── Fiche COGS data ─────────────────────────────────────────────────── */
$ficheDbError      = null;
$ficheMonths       = [];
$ficheData         = [];
$ficheTotals       = [];
$ficheLatestKey    = null;
$ficheIsIncomplete = [];
$ficheProvenance   = [];
$ficheBasisRows    = [];

try {
    $pdo_fiche = maltytask_pdo();
    $stmt_fiche = $pdo_fiche->query("
        SELECT
            c.category_key, c.label_fr, c.inv_gl, c.charge_gl, c.display_order,
            all_mk.month_key,
            COALESCE(m.rm_chf,        s.rm_chf)        AS rm_chf,
            COALESCE(m.wip_chf,       s.wip_chf)       AS wip_chf,
            COALESCE(m.fg_chf,        s.fg_chf)        AS fg_chf,
            COALESCE(m.total_chf,     s.total_chf)     AS total_chf,
            COALESCE(m.opening_chf,   s.opening_chf)   AS opening_chf,
            COALESCE(m.variation_chf, s.variation_chf) AS variation_chf,
            m.basis_adjustment_chf,
            CASE WHEN m.id IS NOT NULL THEN 'computed' ELSE 'seed' END AS provenance
        FROM (
            SELECT DISTINCT month_key FROM cogs_fiche_seed
            UNION
            SELECT DISTINCT month_key FROM cogs_fiche_monthly
        ) AS all_mk
        CROSS JOIN ref_cogs_fiche_categories c
        LEFT JOIN cogs_fiche_seed s     ON s.month_key = all_mk.month_key AND s.category_key = c.category_key
        LEFT JOIN cogs_fiche_monthly m  ON m.month_key = all_mk.month_key AND m.category_key = c.category_key
        WHERE c.is_active = 1
        ORDER BY all_mk.month_key, c.display_order
    ");
    $ficheRows = $stmt_fiche->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ficheRows as $row) {
        $mk = $row['month_key'];
        if (!in_array($mk, $ficheMonths, true)) {
            $ficheMonths[] = $mk;
        }
        $ficheData[$mk][] = $row;
    }

    sort($ficheMonths);
    $ficheLatestKey = !empty($ficheMonths) ? end($ficheMonths) : null;

    foreach ($ficheMonths as $mk) {
        $rows_mk = $ficheData[$mk] ?? [];
        // Totals
        $t = ['rm_chf'=>0.0,'wip_chf'=>0.0,'fg_chf'=>0.0,'total_chf'=>0.0,'opening_chf'=>0.0,'variation_chf'=>0.0];
        foreach ($rows_mk as $r) {
            foreach (array_keys($t) as $k) { $t[$k] += (float)($r[$k] ?? 0); }
        }
        $ficheTotals[$mk] = $t;

        // Provenance — computed first so the incomplete gate can use it
        $hasComputed = !empty(array_filter($rows_mk, fn($r) => $r['provenance'] === 'computed'));
        $hasSeed     = !empty(array_filter($rows_mk, fn($r) => $r['provenance'] === 'seed'));
        $ficheProvenance[$mk] = ($hasComputed && $hasSeed) ? 'mixed' : ($hasComputed ? 'computed' : 'seed');

        // Incomplete: only applies to computed months (seed months are signed+complete by definition)
        $ficheIsIncomplete[$mk] = ($ficheProvenance[$mk] !== 'seed')
            && !array_filter(array_column($rows_mk, 'fg_chf'), fn($v) => (float)$v > 0.0);

        // Basis rows
        $ficheBasisRows[$mk] = array_values(array_filter($rows_mk, fn($r) => $r['basis_adjustment_chf'] !== null));
    }
} catch (Throwable $e) {
    $ficheDbError = $e->getMessage();
}

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

  <?php if ($copData === null && $salesData === null && empty($ficheMonths)): ?>
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
    <button class="fin-tab" role="tab" aria-selected="false"
            aria-controls="fin-panel-fiche" id="fin-tab-fiche" data-tab="fiche">
      Fiche COGS
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

    <!-- ── P&L Grid — COP tab (top card) ───────────────────────────────────── -->
    <?php if (!empty($glMonths)): ?>
    <div class="fin-card fin-card--grid" id="cop-grid-card">
      <div class="fin-card__head">
        <h3 class="fin-card__title">P&amp;L Grid — Coût de Production (CHF/HL)</h3>
        <div class="fin-grid-controls">
          <label class="fin-picker-label" for="cop-grid-month-select">Mois</label>
          <select id="cop-grid-month-select" class="fin-month-select" aria-label="Sélection du mois P&L COP">
            <?php foreach (array_reverse($glMonths) as $mk): ?>
              <option value="<?= htmlspecialchars($mk) ?>"
                <?= ($mk === $glLatestMonth) ? 'selected' : '' ?>>
                <?= htmlspecialchars(fin_month_label($mk)) ?>
              </option>
            <?php endforeach ?>
          </select>
          <span class="fin-loading-indicator" id="cop-grid-loading" hidden aria-live="polite">Chargement…</span>
        </div>
      </div>
      <p class="fin-grid-source-chip">ACTUALS = pipeline opérationnel (cogs-report-data.json) · ÷ HL packagé</p>
      <div class="fin-grid-wrap" id="cop-grid-wrap">
        <p class="fin-empty">Chargement…</p>
      </div>
    </div>
    <?php endif ?>

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

    <!-- ── P&L Grid — COGS tab (top card) ──────────────────────────────────── -->
    <?php if (!empty($glMonths)): ?>
    <div class="fin-card fin-card--grid" id="cogs-grid-card">
      <div class="fin-card__head">
        <h3 class="fin-card__title">P&amp;L Grid — COGS Variables (CHF/HL)</h3>
        <div class="fin-grid-controls">
          <label class="fin-picker-label" for="cogs-grid-month-select">Mois</label>
          <select id="cogs-grid-month-select" class="fin-month-select" aria-label="Sélection du mois P&L COGS">
            <?php foreach (array_reverse($glMonths) as $mk): ?>
              <option value="<?= htmlspecialchars($mk) ?>"
                <?= ($mk === $glLatestMonth) ? 'selected' : '' ?>>
                <?= htmlspecialchars(fin_month_label($mk)) ?>
              </option>
            <?php endforeach ?>
          </select>
          <span class="fin-loading-indicator" id="cogs-grid-loading" hidden aria-live="polite">Chargement…</span>
        </div>
      </div>
      <p class="fin-grid-source-chip">ACTUALS = pipeline opérationnel (sales-cogs-data.json) · ÷ HL vendu (ventes BC) · BUDGET = budget 2026 board, CHF/HL annuel (ref_budget_cogs)</p>
      <div class="fin-grid-wrap" id="cogs-grid-wrap">
        <p class="fin-empty">Chargement…</p>
      </div>
    </div>
    <?php endif ?>

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
       MODULE D — Coût par SKU (inline, BOM compilé — ref_sku_bom)
  ══════════════════════════════════════════════════════════════════════════ -->
  <section class="fin-panel" id="fin-panel-sku" hidden
           role="tabpanel" aria-labelledby="fin-tab-sku">

    <?php if ($skuDbError !== null): ?>
      <div class="wort-error">
        Erreur base de données (module Coût par SKU)&nbsp;: <?= htmlspecialchars($skuDbError) ?>
      </div>
    <?php else: ?>

    <!-- KPI bar -->
    <section class="wort-kpis sku-kpis" aria-label="Statistiques Coût par SKU">
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= htmlspecialchars((string) ($skuKpiCounts['total_skus'] ?? '—')) ?></span>
        <span class="wort-kpi__label">SKUs actifs</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= htmlspecialchars((string) ($skuKpiCounts['distinct_recipes'] ?? '—')) ?></span>
        <span class="wort-kpi__label">Recettes avec SKUs</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num sku-kpi__chf">
          <?= $skuMedianCHFperHL > 0 ? htmlspecialchars(number_format($skuMedianCHFperHL, 2)) : '—' ?>
        </span>
        <span class="wort-kpi__label">CHF/HL médian</span>
      </div>
    </section>

    <!-- Filtres client-side — show/hide les lignes sans rechargement -->
    <div class="wort-filters fin-sku-filters" id="fin-sku-filters">
      <div class="wort-filters__row">
        <label class="wort-filters__field">
          <span class="wort-filters__label">Recette</span>
          <select id="fin-sku-filter-recipe" aria-label="Filtrer par recette">
            <option value="">Toutes</option>
            <?php foreach ($skuRecipeList as $rec): ?>
              <option value="<?= htmlspecialchars($rec) ?>"><?= htmlspecialchars($rec) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label class="wort-filters__field">
          <span class="wort-filters__label">Format</span>
          <select id="fin-sku-filter-format" aria-label="Filtrer par format">
            <option value="">Tous</option>
            <?php foreach (['Bot', 'Can', 'Keg', 'Cuve de service'] as $fmt): ?>
              <option value="<?= htmlspecialchars($fmt) ?>"><?= htmlspecialchars($fmt) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label class="wort-filters__field">
          <span class="wort-filters__label">Classification</span>
          <select id="fin-sku-filter-class" aria-label="Filtrer par classification">
            <option value="">Toutes</option>
            <option value="Neb">Neb</option>
            <option value="Contract">Contract</option>
          </select>
        </label>
        <button type="button" id="fin-sku-filter-reset"
                class="wort-filters__reset" hidden>Réinitialiser</button>
      </div>
      <span id="fin-sku-filter-count" class="wort-filters__count" hidden></span>
    </div>

    <!-- Tableau SKU -->
    <section class="wort-section" aria-label="Coût par SKU">
      <?php if ($skuBomFreshness !== null): ?>
        <div class="wort-section__head">
          <span class="sku-freshness">Coûts BOM à jour au <?= htmlspecialchars($skuBomFreshness) ?></span>
        </div>
      <?php endif ?>

      <?php if (empty($skuRows)): ?>
        <div class="fin-empty">Aucun SKU actif.</div>
      <?php else: ?>
        <div class="wort-table-wrap">
          <table class="wort-table sku-table" id="fin-sku-table">
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
              foreach ($skuRows as $r):
                  $recipe     = $r['recipe_short_name'] ?? '';
                  $flags      = $r['_flags'];
                  $hasFlag    = !empty($flags);
                  $chfHL      = is_numeric($r['chf_per_hl']) ? (float) $r['chf_per_hl'] : null;
                  $isContract = ($r['classification'] === 'Contract');
                  $fmt        = $r['format'] ?? '';
                  $cls        = $r['classification'] ?? '';
              ?>
              <?php if ($recipe !== $prevRecipe): ?>
                <tr class="sku-group-head"
                    data-recipe="<?= htmlspecialchars($recipe) ?>"
                    data-classification="<?= htmlspecialchars($cls) ?>">
                  <td colspan="10" class="sku-group-head__cell">
                    <?= htmlspecialchars($recipe !== '' ? $recipe : '—') ?>
                  </td>
                </tr>
                <?php $prevRecipe = $recipe; ?>
              <?php endif ?>
              <tr class="sku-row<?= $hasFlag ? ' sku-row--flagged' : '' ?>"
                  data-recipe="<?= htmlspecialchars($recipe) ?>"
                  data-format="<?= htmlspecialchars($fmt) ?>"
                  data-classification="<?= htmlspecialchars($cls) ?>">
                <td class="wort-td sku-td sku-td--recipe">
                  <?php if ($isContract): ?>
                    <span class="sku-badge sku-badge--contract">Contract</span>
                  <?php endif ?>
                </td>
                <td class="wort-td sku-td sku-td--code">
                  <button type="button" class="sku-code-link sku-drilldown-btn"
                          data-sku="<?= htmlspecialchars($r['sku_code'] ?? '', ENT_QUOTES) ?>"
                          aria-label="Détail BOM — <?= htmlspecialchars($r['sku_code'] ?? '', ENT_QUOTES) ?>">
                    <span class="wort-mono"><?= htmlspecialchars($r['sku_code'] ?? '—') ?></span>
                  </button>
                </td>
                <td class="wort-td sku-td sku-td--format">
                  <span class="sku-format-badge sku-format-badge--<?= htmlspecialchars(strtolower($fmt)) ?>">
                    <?= htmlspecialchars($fmt ?: '—') ?>
                  </span>
                </td>
                <td class="wort-td sku-td"><?= htmlspecialchars($r['unit_label'] ?? '—') ?></td>
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
                  <?php foreach ($flags as $flag):
                      $badgeClass = match($flag) {
                          'keg-inverted'     => 'sku-anomaly sku-anomaly--red',
                          'outlier'          => 'sku-anomaly sku-anomaly--amber',
                          'extreme-bom-line' => 'sku-anomaly sku-anomaly--red',
                          default            => 'sku-anomaly',
                      };
                      $badgeLabel = match($flag) {
                          'keg-inverted'     => 'Keg inversé',
                          'outlier'          => 'Outlier',
                          'extreme-bom-line' => 'BOM extrême',
                          default            => htmlspecialchars($flag),
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
        <p id="fin-sku-empty-msg" class="fin-empty" hidden>Aucun SKU pour ces filtres.</p>
      <?php endif ?>
    </section>

    <?php endif ?>
  </section>

    <section class="fin-panel" id="fin-panel-fiche" role="tabpanel" aria-labelledby="fin-tab-fiche" hidden>
      <?php if (!empty($ficheDbError)): ?>
        <p class="fin-empty">Erreur base de données : <?= htmlspecialchars($ficheDbError) ?></p>
      <?php elseif (empty($ficheMonths)): ?>
        <p class="fin-empty">Aucune donnée Fiche COGS disponible.</p>
      <?php else: ?>

      <div class="fin-fiche-controls">
        <div class="fin-controls">
          <label class="fin-picker-label" for="fiche-month-select">Mois</label>
          <select id="fiche-month-select" class="fin-month-select" aria-label="Sélection du mois Fiche COGS">
            <?php foreach (array_reverse($ficheMonths) as $mk): ?>
              <option value="<?= htmlspecialchars($mk) ?>" <?= ($mk === $ficheLatestKey) ? 'selected' : '' ?>>
                <?= htmlspecialchars(fin_month_label($mk)) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>
        <span class="fin-fiche-provenance-chip" id="fiche-provenance-chip" aria-live="polite"></span>
        <a class="fin-fiche-csv-btn" id="fiche-csv-btn"
           href="/api/cogs-fiche-csv.php?month=<?= htmlspecialchars($ficheLatestKey ?? '') ?>"
           download aria-label="Télécharger la fiche CSV">
          ↓ Télécharger CSV
        </a>
      </div>

      <div class="fin-fiche-warn" id="fiche-incomplete-warn" hidden role="alert">
        <span class="fin-fiche-warn__icon" aria-hidden="true">⚠</span>
        Données incomplètes : les bières prêtes (FG) ne sont pas encore saisies pour ce mois.
      </div>

      <?php foreach ($ficheMonths as $mk):
          $rows    = $ficheData[$mk] ?? [];
          $totals  = $ficheTotals[$mk] ?? [];
          $basisRows = $ficheBasisRows[$mk] ?? [];
          $isActive = ($mk === $ficheLatestKey);
      ?>
      <div class="fin-fiche-month-block" data-month="<?= htmlspecialchars($mk) ?>"
           <?= $isActive ? '' : 'hidden' ?>>

        <div class="fin-table-scroll">
          <table class="fin-table fin-fiche-table" aria-label="Fiche COGS <?= htmlspecialchars(fin_month_label($mk)) ?>">
            <thead>
              <tr>
                <th class="fin-th--label">Catégorie</th>
                <th class="fin-th--gl">Comptes Inv.</th>
                <th class="fin-th--gl">Cptes Charge</th>
                <th class="fin-th--num">Valeur Stock</th>
                <th class="fin-th--num">Bières en cours</th>
                <th class="fin-th--num">Bières prêtes</th>
                <th class="fin-th--num fin-th--total">Total Inventaire</th>
                <th class="fin-th--num">Compta mois préc.</th>
                <th class="fin-th--num fin-th--variation">Variation Stock</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row):
                  $variation = (float) $row['variation_chf'];
                  $varClass  = $variation < 0 ? ' fin-td--variation-neg' : ($variation > 0 ? ' fin-td--variation-pos' : '');
              ?>
              <tr>
                <td class="fin-td--label"><?= htmlspecialchars($row['label_fr']) ?></td>
                <td class="fin-td--gl fin-td--mono"><?= htmlspecialchars($row['inv_gl'] ?? '') ?></td>
                <td class="fin-td--gl fin-td--mono"><?= htmlspecialchars($row['charge_gl'] ?? '') ?></td>
                <td class="fin-td--num"><?= fin_fmt_chf((float) $row['rm_chf']) ?></td>
                <td class="fin-td--num"><?= fin_fmt_chf((float) $row['wip_chf']) ?></td>
                <td class="fin-td--num"><?= fin_fmt_chf((float) $row['fg_chf']) ?></td>
                <td class="fin-td--num fin-td--total"><?= fin_fmt_chf((float) $row['total_chf']) ?></td>
                <td class="fin-td--num"><?= fin_fmt_chf((float) $row['opening_chf']) ?></td>
                <td class="fin-td--num fin-td--variation<?= $varClass ?>"><?= fin_fmt_chf($variation) ?></td>
              </tr>
              <?php endforeach ?>
            </tbody>
            <tfoot>
              <tr class="fin-fiche-total-row">
                <td class="fin-td--label fin-td--total-label"><strong>Total</strong></td>
                <td class="fin-td--gl"></td>
                <td class="fin-td--gl"></td>
                <td class="fin-td--num fin-td--total-sum"><?= fin_fmt_chf($totals['rm_chf'] ?? 0) ?></td>
                <td class="fin-td--num fin-td--total-sum"><?= fin_fmt_chf($totals['wip_chf'] ?? 0) ?></td>
                <td class="fin-td--num fin-td--total-sum"><?= fin_fmt_chf($totals['fg_chf'] ?? 0) ?></td>
                <td class="fin-td--num fin-td--total fin-td--total-sum"><?= fin_fmt_chf($totals['total_chf'] ?? 0) ?></td>
                <td class="fin-td--num fin-td--total-sum"><?= fin_fmt_chf($totals['opening_chf'] ?? 0) ?></td>
                <?php $totalVar = (float)($totals['variation_chf'] ?? 0);
                      $totalVarClass = $totalVar < 0 ? ' fin-td--variation-neg' : ($totalVar > 0 ? ' fin-td--variation-pos' : ''); ?>
                <td class="fin-td--num fin-td--variation fin-td--total-sum<?= $totalVarClass ?>"><?= fin_fmt_chf($totalVar) ?></td>
              </tr>
              <?php if (!empty($basisRows)): ?>
              <tr class="fin-fiche-basis-row">
                <td colspan="9" class="fin-fiche-basis-note">
                  <span class="fin-fiche-basis-label">Ajustement de base (WAC + révision BOM F2, non récurrent)</span>
                  <?php foreach ($basisRows as $br):
                      if ($br['basis_adjustment_chf'] === null) continue; ?>
                    <span class="fin-fiche-basis-item">
                      <?= htmlspecialchars($br['label_fr']) ?> : <?= fin_fmt_chf((float) $br['basis_adjustment_chf']) ?>
                    </span>
                  <?php endforeach ?>
                  <em class="fin-fiche-basis-disclaimer">Ce mois inclut un ajustement non récurrent (rebasage WAC/F2). La variation ci-dessus l'intègre ; elle ne reflète pas un mouvement réel équivalent.</em>
                </td>
              </tr>
              <?php endif ?>
            </tfoot>
          </table>
        </div>
      </div>
      <?php endforeach ?>

      <?php endif ?>
    </section>

  <?php endif ?>

</div><!-- /.fin-wrap -->

<!-- ── Modal BOM drilldown — opened by JS on SKU click ─────────────────────
     Top-layer rules (ui skill):
     · Base `dialog` has NO display property — the UA default (display:none when
       closed) must stand; any unconditional display rule makes it permanently visible.
     · Layout is scoped to dialog[open] only (position:fixed + display:grid).
     · Helper elements (close button, loading spinner) are mounted INSIDE the dialog
       so they render in the browser top layer with the modal.
     · No loading="lazy" on any content — content is hidden until showModal(),
       so lazy images/elements would never enter the viewport.
──────────────────────────────────────────────────────────────────────────── -->
<dialog id="fin-sku-modal" aria-labelledby="fin-sku-modal-title" aria-modal="true">
  <div class="fin-modal-inner">
    <header class="fin-modal-header">
      <div class="fin-modal-header__meta">
        <span id="fin-sku-modal-title" class="fin-modal-sku-code wort-mono"></span>
        <span class="fin-modal-recipe"></span>
        <span class="fin-modal-format-badge"></span>
      </div>
      <div class="fin-modal-header__costs">
        <div class="fin-modal-kpi">
          <span class="fin-modal-kpi__val wort-mono" id="fin-modal-total-chf"></span>
          <span class="fin-modal-kpi__label">Total CHF</span>
        </div>
        <div class="fin-modal-kpi fin-modal-kpi--accent">
          <span class="fin-modal-kpi__val wort-mono" id="fin-modal-chf-hl"></span>
          <span class="fin-modal-kpi__label">CHF / HL</span>
        </div>
      </div>
      <button type="button" class="fin-modal-close" id="fin-sku-modal-close"
              aria-label="Fermer le détail BOM">&#x2715;</button>
    </header>

    <div class="fin-modal-body" id="fin-modal-body">
      <!-- Populated by JS on fetch; never lazy-loaded -->
    </div>

    <footer class="fin-modal-footer">
      <span class="fin-modal-freshness" id="fin-modal-freshness"></span>
    </footer>
  </div>
</dialog>
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

/* GL months — months with booked GL rows in inv_charges_bc */
window.FIN_GL_MONTHS   = <?= json_encode($glMonths,      $JSON_FLAGS) ?>;
window.FIN_GL_DEFAULT  = <?= json_encode($glLatestMonth, $JSON_FLAGS) ?>;

/* Fiche COGS — variation de stock (source: cogs_fiche_seed + cogs_fiche_monthly) */
window.FIN_FICHE_MONTHS     = <?= json_encode($ficheMonths ?? [],      $JSON_FLAGS) ?>;
window.FIN_FICHE_DEFAULT    = <?= json_encode($ficheLatestKey ?? null,  $JSON_FLAGS) ?>;
window.FIN_FICHE_PROVENANCE = <?= json_encode($ficheProvenance ?? [],   $JSON_FLAGS) ?>;
window.FIN_FICHE_INCOMPLETE = <?= json_encode($ficheIsIncomplete ?? [], $JSON_FLAGS) ?>;
</script>

<script src="/js/kpi-charts.js?v=<?= @filemtime(__DIR__ . '/../js/kpi-charts.js') ?: time() ?>"></script>
<script src="/js/financier.js?v=<?= @filemtime(__DIR__ . '/../js/financier.js') ?: time() ?>"></script>

</body>
</html>
