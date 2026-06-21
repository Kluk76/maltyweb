<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_page_access('packaging');
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "packaging";
$crumbs        = ["Accueil", "Packaging", "BBT & Conditionnement"];

require_once __DIR__ . "/../../app/svg-tanks.php";
require_once __DIR__ . "/../../app/packaging-stats.php";
require_once __DIR__ . "/../../app/cip-cadence.php";
require_once __DIR__ . "/../../app/yeast-eligibility.php";
require_once __DIR__ . "/../../app/sku_catalog.php";
require_once __DIR__ . "/../../app/user-prefs.php";

// --- Date helpers (FR locale) ---
$monthsFR = [
    1 => "jan", 2 => "fév", 3 => "mar", 4 => "avr",
    5 => "mai", 6 => "jun", 7 => "jul", 8 => "aoû",
    9 => "sep", 10 => "oct", 11 => "nov", 12 => "déc",
];

$monthsFRFull = [
    1  => "janvier",   2  => "février",   3  => "mars",
    4  => "avril",     5  => "mai",        6  => "juin",
    7  => "juillet",   8  => "août",       9  => "septembre",
    10 => "octobre",   11 => "novembre",   12 => "décembre",
];

// --- As-of filter ---
$todayDT      = new DateTimeImmutable('today');
$minDate      = new DateTimeImmutable('2023-10-01');
$currentYear  = (int)$todayDT->format('Y');

$asOfDT       = $todayDT;
$filterActive = false;

$_gy = $_GET['year']  ?? '';
$_gm = $_GET['month'] ?? '';
$_gd = $_GET['day']   ?? '';

if (ctype_digit($_gy) && ctype_digit($_gm) && ctype_digit($_gd)) {
    $py = (int)$_gy;
    $pm = (int)$_gm;
    $pd = (int)$_gd;
    if ($py >= 2023 && $py <= $currentYear && $pm >= 1 && $pm <= 12 && $pd >= 1 && $pd <= 31) {
        $parsed = DateTimeImmutable::createFromFormat('Y-n-j', "{$py}-{$pm}-{$pd}");
        if ($parsed !== false) {
            if ($parsed > $todayDT) $parsed = $todayDT;
            if ($parsed < $minDate) $parsed = $minDate;
            $asOfDT       = $parsed;
            $filterActive = ($asOfDT->format('Y-m-d') !== $todayDT->format('Y-m-d'));
        }
    }
}

$selYear  = (int)$asOfDT->format('Y');
$selMonth = (int)$asOfDT->format('n');
$selDay   = (int)$asOfDT->format('j');

// --- Packaging year filter ---
$pkgYearDefault = (int)$todayDT->format('Y');
$pkgYearMin     = 2021;
$pkgYearMax     = $currentYear;
$pkgYear        = $pkgYearDefault;
$_gpy = $_GET['pkg_year'] ?? '';
if (ctype_digit($_gpy)) {
    $py = (int)$_gpy;
    if ($py >= $pkgYearMin && $py <= $pkgYearMax) $pkgYear = $py;
}

if (!function_exists('fmt_date_fr_tanks_full')) {
    function fmt_date_fr_tanks_full(DateTimeImmutable $dt, array $monthsFull): string {
        return sprintf('%d %s %s', (int)$dt->format('j'), $monthsFull[(int)$dt->format('n')], $dt->format('Y'));
    }
}

if (!function_exists('fmt_date_fr_tanks')) {
    function fmt_date_fr_tanks(string $dateStr, array $months): string {
        $ts = strtotime($dateStr);
        if ($ts === false) return htmlspecialchars($dateStr);
        $d = (int) date("j", $ts);
        $m = (int) date("n", $ts);
        $y = date("Y", $ts);
        return sprintf("%d %s %s", $d, $months[$m], $y);
    }
}

// -------------------------------------------------------------------
// Database queries
// -------------------------------------------------------------------
require __DIR__ . "/../../app/tank-simulator.php";

try {
    $pdo = maltytask_pdo();

    // BBT reference + simulation
    $bbtRef   = $pdo->query("SELECT number, capacity_hl, status FROM ref_bbt ORDER BY number ASC")->fetchAll(PDO::FETCH_ASSOC);
    $simState = (new TankSimulator($pdo))->run($asOfDT);
    $bbtSimMap = $simState['bbt'];

    // Per-BBT supplemental: recipe short name + blend detail
    // Observed yeast (strain + generation) — mirrors the CCT board query (tanks.php).
    // Strain is the raw observed coalesce IF(yeast='New Yeast', new_yeast, yeast) — old
    // forms wrote the real strain into new_yeast when "New Yeast" was picked — then resolved
    // to a clean display name via resolve_observed_yeast_strain() below.
    // Reads bd_brewing_brewday_v2 (v2 canonical); prepared once, executed per BBT (≤8, N+1 fine).
    $bbtYeastStmt = $pdo->prepare(
        'SELECT yeast AS bd_yeast_raw, new_yeast AS bd_new_yeast, yeast_gen AS bd_yeast_gen
         FROM bd_brewing_brewday_v2 b
         WHERE b.beer = :beer AND b.batch = :batch
         ORDER BY b.event_date DESC LIMIT 1'
    );

    $bbtOccMap = [];
    foreach ($bbtSimMap as $num => $simRow) {
        if ($simRow === null) continue;

        $beer    = $simRow['beer'];
        $rawBeer = TankSimulator::rawBeerName($beer);
        $batch   = $simRow['batch'];

        $recipeStmt = $pdo->prepare(
            'SELECT COALESCE(rr.recipe_short_name, rr2.recipe_short_name) AS recipe_short_name,
                    COALESCE(rc.name, \'\')                                AS client_name
             FROM (SELECT 1) dummy
             LEFT JOIN ref_recipes rr
                 ON  rr.name    = :beer
                 AND rr.vintage = CONCAT(\'20\', LPAD(REGEXP_REPLACE(:batch_v, \'[^0-9].*$\', \'\'), 2, \'0\'))
                 AND rr.vintage <> \'20\'
             LEFT JOIN ref_recipes rr2
                 ON  rr2.name    = :beer2
                 AND rr2.vintage = \'\'
             LEFT JOIN ref_clients rc
                 ON  rc.id = COALESCE(rr.client_id, rr2.client_id)
             LIMIT 1'
        );
        $recipeStmt->execute([':beer' => $rawBeer, ':batch_v' => $batch, ':beer2' => $rawBeer]);
        $recipeRow = $recipeStmt->fetch() ?: [];

        $bbtYeastStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $yeastRow = $bbtYeastStmt->fetch() ?: [];

        // Resolve the coalesced observed strain to a clean display name (exact → alias → raw).
        $yeastResolved = resolve_observed_yeast_strain(
            $pdo,
            $yeastRow['bd_yeast_raw'] ?? null,
            $yeastRow['bd_new_yeast'] ?? null
        );

        $blendStr = '';
        if (!empty($simRow['blend_info']) && count($simRow['blend_info']) > 1) {
            $parts = array_map(
                fn($bi) => '#' . $bi['batch'] . ': ' . round((float)$bi['vol']) . 'hl',
                $simRow['blend_info']
            );
            $blendStr = implode(' + ', $parts);
        }

        $bbtOccMap[$num] = [
            'bbt_number'       => $num,
            'beer'             => $beer,
            'batch'            => $batch,
            'remaining_hl'     => $simRow['volume_hl'],
            'rack_date'        => $simRow['filled_date']->format('Y-m-d'),
            'recipe_short_name'=> $recipeRow['recipe_short_name'] ?? null,
            'client_name'      => $recipeRow['client_name'] ?? null,
            'blend_str'        => $blendStr,
            'yeast'            => $yeastResolved['strain'] ?? null,
            'yeast_gen'        => $yeastRow['bd_yeast_gen'] ?? null,
        ];
    }

    $activeBbts  = array_filter($bbtRef, fn($r) => $r['status'] === 'active');
    $totalBbt    = count($activeBbts);
    $occupiedBbt = count($bbtOccMap);

    $hlInBbts = 0.0;
    foreach ($bbtOccMap as $row) {
        $hlInBbts += (float)($row['remaining_hl'] ?? 0);
    }

    // Packaging KPIs — repointed to bd_packaging_v2 (v1 bd_packaging retired 2026-06-11).
    // Format mapping: run_type enum ('can','can33','bot','keg','cuv') replaces v1 `format` varchar.
    // Year filter: YEAR(event_date) replaces v1 `year` column.
    // vendable_hl: 2194/2275 rows populated (96.4%) as of 2026-06-11 — ready for use.
    $pkgTotalsStmt = $pdo->prepare("
        SELECT
          COALESCE(SUM(vendable_hl), 0)   AS hl_total,
          COUNT(DISTINCT CONCAT(COALESCE(neb_beer, contract_beer), '|', run_type))  AS distinct_skus,
          COUNT(*)                         AS pkg_events
        FROM bd_packaging_v2
        WHERE YEAR(event_date) = :year
          AND is_tombstoned = 0
          AND COALESCE(neb_beer, contract_beer) IS NOT NULL
          AND vendable_hl IS NOT NULL
    ");
    $pkgTotalsStmt->execute([':year' => $pkgYear]);
    $pkgTotals = $pkgTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['hl_total' => 0, 'distinct_skus' => 0, 'pkg_events' => 0];

    // HL by format — consolidated
    $pkgFmtStmt = $pdo->prepare("
        SELECT
          CASE WHEN run_type IN ('can','can33') THEN 'Can'
               WHEN run_type = 'bot'            THEN 'Bot'
               WHEN run_type = 'keg'            THEN 'Keg'
               WHEN run_type = 'cuv'            THEN 'Cuve de service'
               ELSE run_type END AS fmt,
          SUM(vendable_hl) AS hl
        FROM bd_packaging_v2
        WHERE YEAR(event_date) = :year
          AND is_tombstoned = 0
          AND vendable_hl IS NOT NULL
        GROUP BY fmt
        ORDER BY hl DESC
    ");
    $pkgFmtStmt->execute([':year' => $pkgYear]);
    $pkgByFormat = $pkgFmtStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 SKUs per format (by HL)
    $pkgTop5Stmt = $pdo->prepare("
        WITH grouped AS (
          SELECT
            CASE WHEN run_type IN ('can','can33') THEN 'Can' ELSE run_type END AS fmt,
            COALESCE(neb_beer, contract_beer) AS sku_code,
            SUM(vendable_hl) AS hl_sum,
            COUNT(*) AS n_events
          FROM bd_packaging_v2
          WHERE YEAR(event_date) = :year
            AND is_tombstoned = 0
            AND vendable_hl IS NOT NULL
            AND COALESCE(neb_beer, contract_beer) IS NOT NULL
          GROUP BY
            CASE WHEN run_type IN ('can','can33') THEN 'Can' ELSE run_type END,
            COALESCE(neb_beer, contract_beer)
        ),
        ranked AS (
          SELECT g.*, ROW_NUMBER() OVER (PARTITION BY fmt ORDER BY hl_sum DESC) AS rk
          FROM grouped g
        )
        SELECT fmt, sku_code, hl_sum AS hl, n_events
        FROM ranked
        WHERE rk <= 5
        ORDER BY fmt, hl_sum DESC
    ");
    $pkgTop5Stmt->execute([':year' => $pkgYear]);
    $pkgTop5Rows = $pkgTop5Stmt->fetchAll(PDO::FETCH_ASSOC);

    $pkgTop5ByFormat = [];
    foreach ($pkgTop5Rows as $r) {
        $pkgTop5ByFormat[$r['fmt']][] = $r;
    }

    // Packaging KPI dashboard data (V2-only, year-filtered by $pkgYear)
    $pkgStatsNebByMonth         = pkg_neb_hl_by_month($pdo, $pkgYear);
    $pkgStatsNebBySkuMonth      = pkg_neb_hl_by_sku_month($pdo, $pkgYear);
    $pkgStatsNebByFormatMonth   = pkg_hl_by_format_month($pdo, $pkgYear);
    $pkgStatsContractByFmtMonth = pkg_contract_hl_by_format_month($pdo, $pkgYear);

    // A3a — current-week events (anchored to data max, no year filter)
    $pkgWeekEvents = pkg_current_week_events($pdo);

    // A3b — year QA metrics (summary card — kept for weekly view compatibility)
    $pkgQaMetrics = pkg_qa_metrics($pdo, $pkgYear);

    // Change 2/3 — canonical beer-loss and new QA section data
    $pkgBeerLossByYear        = pkg_beer_loss_by_year($pdo);
    $pkgBeerLossByFormatMonth = pkg_beer_loss_by_format_month($pdo, $pkgYear);
    $pkgConsumableLossRates   = pkg_consumable_loss_rates($pdo, $pkgYear);
    $pkgQaTrendByMonth        = pkg_qa_trend_by_month($pdo, $pkgYear);
    $pkgCo2o2Readings         = pkg_co2o2_readings($pdo, $pkgYear);

    // A4 — quarterly + monthly YTD (current + prior year)
    $pkgQuarterlyHl = pkg_quarterly_hl($pdo, $pkgYear);
    $pkgMonthlyYtd  = pkg_monthly_hl_ytd($pdo, $pkgYear);

    // CIP cadence — non-fatal, fetched inside the main try block
    $bbtCipCadence = cadence_for_all_bbts($pdo);

    $dbError = null;

} catch (Throwable $e) {
    $bbtRef          = [];
    $bbtOccMap       = [];
    $totalBbt        = 0;
    $occupiedBbt     = 0;
    $hlInBbts        = 0.0;
    $pkgTotals       = ['hl_total' => 0, 'distinct_skus' => 0, 'pkg_events' => 0];
    $pkgByFormat     = [];
    $pkgTop5ByFormat = [];
    $pkgStatsNebByMonth         = [];
    $pkgStatsNebBySkuMonth      = [];
    $pkgStatsNebByFormatMonth   = [];
    $pkgStatsContractByFmtMonth = [];
    $pkgWeekEvents  = ['list' => [], 'week_label' => '', 'week_start' => '', 'week_end' => '', 'total_events' => 0];
    $pkgQaMetrics   = ['year' => $pkgYear, 'total_events' => 0, 'qa_analyses_total' => 0, 'qa_library_total' => 0, 'unsaleable_total' => 0, 'loss_units_total' => 0, 'prod_units_total' => 0, 'loss_pct' => null, 'co2o2_events_with_measures' => 0, 'co2o2_total_events' => 0, 'avg_co2_where_measured' => null, 'avg_o2_where_measured' => null, 'n_co2o2_readings' => 0];
    $pkgQuarterlyHl = [];
    $pkgMonthlyYtd  = [];
    $pkgBeerLossByYear        = [];
    $pkgBeerLossByFormatMonth = [];
    $pkgConsumableLossRates   = ['rateable' => [], 'raw_count' => []];
    $pkgQaTrendByMonth        = [];
    $pkgCo2o2Readings         = ['readings' => [], 'n_readings' => 0, 'n_events' => 0];
    $bbtCipCadence  = [];
    $dbError        = $e->getMessage();
}

$today = $asOfDT;

// Precompute format bar segments (pct of total HL)
$fmtTotalHl = array_sum(array_column($pkgByFormat, 'hl'));
// Segment colors aligned with PKG_FORMAT_COLOR in packaging.js:
// Bouteille→--hop, Canette→--ember, Fût→--oak, Cuve de service→--steel-mid
$fmtColors  = [
    'Keg' => 'var(--oak)',
    'Bot' => 'var(--hop)',
    'Can' => 'var(--ember)',
    'Cuve de service' => 'var(--steel-mid)',
];
$fmtLabels = ['Keg' => 'Fût', 'Bot' => 'Bouteille', 'Can' => 'Canette', 'Cuve de service' => 'Cuve de service'];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Packaging — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/bbt-detail-modal.css?v=<?= @filemtime(__DIR__ . '/../css/bbt-detail-modal.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/lookup-panel.css?v=<?= @filemtime(__DIR__ . '/../css/lookup-panel.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/sku-class-filter.css?v=<?= @filemtime(__DIR__ . '/../css/sku-class-filter.css') ?: time() ?>">
</head>
<body class="home">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main id="main-content" class="main tanks-main">

<?php
// Lookup SKU options — built separately from $skuOptions used by the saisie dropdown.
// Do NOT change the saisie dropdown; this catalog is for the lookup panel only.
$lookupSkuOptions    = isset($pdo) ? sku_catalog($pdo, ['active_only' => true, 'order_by' => 'sku_code']) : [];
$skuClassFilterValue = isset($pdo) ? user_pref_get($pdo, (int) $me['id'], 'sku_class_filter', 'Neb') : 'Neb';
$lookupConfig = [
    'panel_id'          => 'packaging-lookup',
    'api_endpoint'      => '/api/packaging-lookup.php',
    'mode_batch_label'  => 'Par SKU + lot',
    'type'              => 'packaging',
    'show_class_filter' => true,
    'batch_fields'      => [
        [
            'name'       => 'sku_id',
            'label'      => 'SKU',
            'type'       => 'select',
            'options'    => $lookupSkuOptions,
            'value_col'  => 'id',
            'label_col'  => 'sku_code',
            'class_col'  => 'classification',
            'filterable' => true,
        ],
        ['name' => 'batch', 'label' => 'Lot', 'type' => 'text'],
    ],
];
?>
  <!-- ── Packaging lookup — labelled header ──────────────────────────────── -->
  <div class="pkg-lkp-header">
    <p class="pkg-lkp-eyebrow">MaltyTask · Conditionnement</p>
    <h2 class="pkg-lkp-title">Consulter un <em>packaging</em></h2>
    <p class="pkg-lkp-sub">Recherchez les données d'un conditionnement par date ou par SKU et numéro de lot.</p>
  </div>
<?php
require __DIR__ . '/partials/lookup-panel.php';
?>

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- As-of date filter -->
  <form class="tanks-filters" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
    <div class="wort-filters__row">

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-year">Année</label>
        <select id="tf-year" name="year" onchange="this.form.submit()">
          <?php for ($y = 2023; $y <= $currentYear; $y++): ?>
            <option value="<?= $y ?>"<?= $y === $selYear ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor ?>
        </select>
      </div>

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-month">Mois</label>
        <select id="tf-month" name="month" onchange="this.form.submit()">
          <?php foreach ($monthsFRFull as $mn => $ml): ?>
            <option value="<?= $mn ?>"<?= $mn === $selMonth ? ' selected' : '' ?>><?= htmlspecialchars($ml) ?></option>
          <?php endforeach ?>
        </select>
      </div>

      <div class="wort-filters__field">
        <label class="wort-filters__label" for="tf-day">Jour</label>
        <select id="tf-day" name="day" onchange="this.form.submit()">
          <?php for ($d = 1; $d <= 31; $d++): ?>
            <option value="<?= $d ?>"<?= $d === $selDay ? ' selected' : '' ?>><?= $d ?></option>
          <?php endfor ?>
        </select>
      </div>

      <?php if ($filterActive): ?>
        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="wort-filters__reset tanks-filters__reset">
          Réinitialiser
        </a>
      <?php endif ?>

    </div>
  </form>

  <!-- As-of banner (affects BBT tank state) -->
  <?php if ($filterActive): ?>
    <div class="tanks-asof-banner">
      État au <?= htmlspecialchars(fmt_date_fr_tanks_full($asOfDT, $monthsFRFull)) ?>
    </div>
  <?php else: ?>
    <div class="tanks-asof-banner tanks-asof-banner--current">
      État actuel · <?= htmlspecialchars(fmt_date_fr_tanks_full($todayDT, $monthsFRFull)) ?>
    </div>
  <?php endif ?>

  <!-- ══════════════════════════════════════════════════════════
       BBT Tank Grid — FIRST content block (operator request A0/T1)
       Positioned at top so current fill state is immediately visible.
       ══════════════════════════════════════════════════════════ -->
  <section class="tanks-section" aria-label="Tanks de garde BBT">
    <div class="wort-section__head">
      <h2 class="tanks-section__title">Tanks de garde <span class="tanks-section__tag">BBT</span></h2>
      <span class="wort-section__label">— <?= $occupiedBbt ?> occupé<?= $occupiedBbt !== 1 ? 's' : '' ?> sur <?= $totalBbt ?> actifs</span>
    </div>

    <div class="tanks-grid">
      <?php foreach ($bbtRef as $bbt):
        $num      = (int)$bbt['number'];
        $capHl    = (float)$bbt['capacity_hl'];
        $status   = $bbt['status'];
        $occ      = $bbtOccMap[$num] ?? null;
        $isMaint  = ($status === 'maintenance' || $status === 'retired');

        if ($isMaint) {
            $stateClass = 'tank-card--maint';
            $svgState   = 'maint';
            $fillRatio  = 0.0;
        } elseif ($occ === null) {
            $stateClass = 'tank-card--empty';
            $svgState   = '';
            $fillRatio  = 0.0;
        } else {
            $stateClass = 'tank-card--bbt-occ';
            $svgState   = 'bbt';
            $remainHl   = (float)($occ['remaining_hl'] ?? 0);
            $fillRatio  = $capHl > 0 ? min(1.0, $remainHl / $capHl) : 0.0;
        }
      ?>
      <?php
        $cad = $bbtCipCadence[$num] ?? null;
        $cipSeverity = $cad['severity'] ?? 'ok';
      ?>
      <?php if (!$isMaint): ?>
        <button class="tank-card-btn tank-card <?= $stateClass ?>" data-bbt="<?= $num ?>" type="button" aria-label="Détails BBT <?= $num ?>">
      <?php else: ?>
        <div class="tank-card <?= $stateClass ?>">
      <?php endif ?>
        <div class="tank-card__svg">
          <?= svg_bbt($fillRatio, $svgState, $num, (string)($occ['beer'] ?? '')) ?>
        </div>

        <?php if ($isMaint): ?>
          <div class="tank-card__info">
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
            <span class="tank-badge tank-badge--maint"><?= htmlspecialchars($status) ?></span>
          </div>

        <?php elseif ($occ === null): ?>
          <div class="tank-card__info">
            <span class="tank-card__empty-label">—</span>
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
            <?php if ($cad && ($cad['recommended_action'] ?? 'none') !== 'none'):
                $badgeLabel = ($cad['next_cip_type'] ?? 'acid') === 'full' ? 'CIP complet' : 'CIP acide';
                $badgeClass = ($cad['severity'] ?? 'ok') === 'critical' ? 'cip-badge--critical' : 'cip-badge--warn';
            ?>
              <span class="cip-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <?php endif ?>
          </div>

        <?php else:
          $beerLabel = htmlspecialchars($occ['recipe_short_name'] ?? $occ['beer'] ?? '');
          $batch     = htmlspecialchars($occ['batch'] ?? '');
          $remainHl  = (float)($occ['remaining_hl'] ?? 0);
          $blendStr  = $occ['blend_str'] ?? '';
          $rackDate  = !empty($occ['rack_date'])
              ? fmt_date_fr_tanks($occ['rack_date'], $monthsFR)
              : '—';

          $yeastStrain = trim((string)($occ['yeast'] ?? ''));
          $yeastGen    = trim((string)($occ['yeast_gen'] ?? ''));
          $yeastChip   = '';
          if ($yeastStrain !== '') {
              $yeastChip = 'Levure · ' . $yeastStrain;
              if ($yeastGen !== '') {
                  $yeastChip .= ' (G' . $yeastGen . ')';
              }
          }

          $daysInBbt = 0;
          if (!empty($occ['rack_date'])) {
              $rackDT    = new DateTimeImmutable($occ['rack_date']);
              $daysInBbt = (int)$rackDT->diff($today)->days;
          }
        ?>
          <div class="tank-card__info">
            <span class="tank-card__beer"><?= $beerLabel ?></span>
            <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
            <span class="tank-card__vol"><?= htmlspecialchars(number_format($remainHl, 1)) ?> HL</span>
            <?php if ($blendStr !== ''): ?>
              <span class="tank-card__sub tanks-mute"><?= htmlspecialchars($blendStr) ?></span>
            <?php endif ?>
            <?php if ($yeastChip !== ''): ?>
              <span class="tank-card__yeast tanks-mute"><?= htmlspecialchars($yeastChip) ?></span>
            <?php endif ?>
            <span class="tank-card__brewdate tanks-mute"><?= htmlspecialchars($rackDate) ?></span>
            <span class="tank-badge tank-badge--days">J+<?= $daysInBbt ?></span>
            <?php if ($cad && ($cad['recommended_action'] ?? 'none') !== 'none'):
                $badgeLabel = ($cad['next_cip_type'] ?? 'acid') === 'full' ? 'CIP complet' : 'CIP acide';
                $badgeClass = ($cad['severity'] ?? 'ok') === 'critical' ? 'cip-badge--critical' : 'cip-badge--warn';
            ?>
              <span class="cip-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <?php endif ?>
          </div>
        <?php endif ?>
      <?php if (!$isMaint): ?>
        </button>
      <?php else: ?>
        </div>
      <?php endif ?>
      <?php endforeach ?>
    </div>
  </section>

  <!-- Packaging KPI section -->
  <section class="tanks-section pkg-stats" aria-label="Statistiques de conditionnement">
    <div class="wort-section__head pkg-stats__head">
      <h2 class="tanks-section__title">
        Packaging
        <span class="tanks-section__tag">Conditionnement</span>
      </h2>
      <form class="ferm-stats__year-form" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
        <?php if (isset($_GET['year']))  : ?><input type="hidden" name="year"  value="<?= htmlspecialchars($_GET['year'])  ?>"><?php endif ?>
        <?php if (isset($_GET['month'])) : ?><input type="hidden" name="month" value="<?= htmlspecialchars($_GET['month']) ?>"><?php endif ?>
        <?php if (isset($_GET['day']))   : ?><input type="hidden" name="day"   value="<?= htmlspecialchars($_GET['day'])   ?>"><?php endif ?>
        <label class="ferm-stats__year-label" for="pkg-year">Année</label>
        <select id="pkg-year" name="pkg_year" onchange="this.form.submit()">
          <?php for ($y = $pkgYearMin; $y <= $pkgYearMax; $y++): ?>
            <option value="<?= $y ?>"<?= $y === $pkgYear ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor ?>
        </select>
      </form>
    </div>

    <?php if ((int)$pkgTotals['pkg_events'] === 0): ?>
      <p class="ferm-stats__empty">Aucun packaging enregistré en <?= $pkgYear ?>.</p>
    <?php else: ?>

    <!-- Three-up KPI cards -->
    <div class="wort-kpis pkg-kpis" aria-label="KPI packaging <?= $pkgYear ?>">
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= number_format((float)$pkgTotals['hl_total'], 1) ?></span>
        <span class="wort-kpi__label">HL packagés</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= (int)$pkgTotals['distinct_skus'] ?></span>
        <span class="wort-kpi__label">SKUs packagés</span>
      </div>
      <div class="wort-kpi">
        <span class="wort-kpi__num"><?= (int)$pkgTotals['pkg_events'] ?></span>
        <span class="wort-kpi__label">Lots packagés</span>
      </div>
    </div>

    <?php if (!empty($pkgByFormat) && $fmtTotalHl > 0): ?>
    <!-- HL par format — stacked bar + legend -->
    <div class="pkg-format-bar" aria-label="Répartition HL par format <?= $pkgYear ?>">
      <div class="pkg-format-bar__label-row">
        <span class="pkg-format-bar__title">HL par format</span>
        <span class="pkg-format-bar__total tanks-mono"><?= number_format($fmtTotalHl, 1) ?> HL total</span>
      </div>
      <div class="pkg-format-bar__track" role="img" aria-label="Barre de répartition par format">
        <?php foreach ($pkgByFormat as $fmtRow):
          $fmtKey = $fmtRow['fmt'] ?? '';
          $fmtHl  = (float)$fmtRow['hl'];
          $pct    = $fmtTotalHl > 0 ? round($fmtHl / $fmtTotalHl * 100, 1) : 0;
          $color  = $fmtColors[$fmtKey] ?? 'var(--steel-mid)';
        ?>
          <span
            class="pkg-format-bar__segment"
            style="--seg-pct:<?= $pct ?>%;--seg-color:<?= $color ?>"
            title="<?= htmlspecialchars($fmtLabels[$fmtKey] ?? $fmtKey) ?> · <?= number_format($fmtHl, 1) ?> HL (<?= $pct ?>%)"
          ></span>
        <?php endforeach ?>
      </div>
      <div class="pkg-format-bar__legend">
        <?php foreach ($pkgByFormat as $fmtRow):
          $fmtKey = $fmtRow['fmt'] ?? '';
          $fmtHl  = (float)$fmtRow['hl'];
          $pct    = $fmtTotalHl > 0 ? round($fmtHl / $fmtTotalHl * 100, 1) : 0;
          $color  = $fmtColors[$fmtKey] ?? 'var(--steel-mid)';
          $label  = $fmtLabels[$fmtKey] ?? $fmtKey;
        ?>
          <span class="pkg-format-bar__item" tabindex="0">
            <span class="pkg-format-bar__swatch" style="--seg-color:<?= $color ?>"></span>
            <span class="pkg-format-bar__item-label"><?= htmlspecialchars($label) ?></span>
            <span class="pkg-format-bar__item-hl tanks-mono"><?= number_format($fmtHl, 1) ?></span>
            <span class="pkg-format-bar__item-pct tanks-mute"><?= $pct ?>%</span>
            <?php $top5 = $pkgTop5ByFormat[$fmtKey] ?? []; ?>
            <?php if (!empty($top5)): ?>
              <div class="pkg-format-bar__popup" role="tooltip" aria-label="Top 5 SKUs <?= htmlspecialchars($label) ?>">
                <div class="pkg-format-bar__popup-head">
                  <span class="pkg-format-bar__popup-swatch" style="--seg-color:<?= $color ?>"></span>
                  <span class="pkg-format-bar__popup-title">Top 5 · <?= htmlspecialchars($label) ?></span>
                </div>
                <ol class="pkg-format-bar__popup-list">
                  <?php foreach ($top5 as $i => $row): ?>
                    <li class="pkg-format-bar__popup-row">
                      <span class="pkg-format-bar__popup-rank"><?= $i + 1 ?></span>
                      <span class="pkg-format-bar__popup-sku tanks-mono"><?= htmlspecialchars((string)$row['sku_code']) ?></span>
                      <span class="pkg-format-bar__popup-hl tanks-mono"><?= number_format((float)$row['hl'], 1) ?><span class="pkg-format-bar__popup-unit"> HL</span></span>
                      <span class="pkg-format-bar__popup-events tanks-mute"><?= (int)$row['n_events'] ?> lots</span>
                    </li>
                  <?php endforeach ?>
                </ol>
              </div>
            <?php endif ?>
          </span>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>

    <?php endif ?>
  </section>

  <!-- ════════════════════════════════════════════════════════
       KPI Dashboard (KPIs 1–4) — year-filtered, JS-rendered
       Year controlled by the pkg_year selector already on page.
       ════════════════════════════════════════════════════════ -->
  <section class="tanks-section pkg-kpi-dashboard" aria-label="Tableau de bord KPI conditionnement <?= $pkgYear ?>">

    <!-- KPI 1: Total Nébuleuse HL par mois -->
    <div class="pkg-kpi-section" id="pkg-kpi-1" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement KPI 1…</p>
    </div>

    <!-- KPI 2: Nébuleuse HL par SKU exact par mois -->
    <div class="pkg-kpi-section" id="pkg-kpi-2" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement KPI 2…</p>
    </div>

    <!-- KPI 3: Nébuleuse HL par format par mois -->
    <div class="pkg-kpi-section" id="pkg-kpi-3" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement KPI 3…</p>
    </div>

    <!-- KPI 4: Contract HL par format par mois (own section, visually distinct) -->
    <div class="pkg-kpi-section pkg-kpi-section--contract" id="pkg-kpi-4" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement KPI 4…</p>
    </div>

    <!-- A3a: Cette semaine — current-week events table (JS-rendered) -->
    <div class="pkg-kpi-section" id="pkg-kpi-week" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement semaine…</p>
    </div>

    <!-- A3b: QA overview — year-filtered (JS-rendered) -->
    <div class="pkg-kpi-section" id="pkg-kpi-qa" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement QA…</p>
    </div>

    <!-- A4: Quarterly + YTD rhythm with prior-year comparison (JS-rendered) -->
    <div class="pkg-kpi-section" id="pkg-kpi-rhythm" aria-live="polite">
      <p class="pkg-kpi-loading">Chargement rythme…</p>
    </div>

  </section>

  <!-- BBT CIP Detail Modal -->
  <dialog class="bbt-modal" id="bbt-detail-modal">
    <div class="bbt-modal__overlay" data-close></div>
    <div class="bbt-modal__card" id="bbt-modal-card"><!-- populated by JS --></div>
  </dialog>

</main>

<!-- Floating tooltip for SVG chart hovers -->
<div class="pkg-tooltip" id="pkg-tooltip"></div>

<?php
$bbtCipDetails = [];
foreach ($bbtCipCadence as $bbtNum => $cad) {
    $occ = $bbtOccMap[$bbtNum] ?? null;
    $entry = $cad;
    if ($occ !== null) {
        $entry['beer']              = $occ['beer'] ?? null;
        $entry['batch']             = $occ['batch'] ?? null;
        $entry['recipe_short_name'] = $occ['recipe_short_name'] ?? null;
        $entry['remaining_hl']      = $occ['remaining_hl'] ?? null;
    }
    $bbtCipDetails[$bbtNum] = $entry;
}
?>
<script>
window.BBT_CIP_DETAILS = <?= json_encode((object)$bbtCipDetails, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
</script>
<script>
window.PKG_STATS = <?= json_encode([
    'year'                        => $pkgYear,
    'neb_hl_by_month'             => $pkgStatsNebByMonth,
    'neb_hl_by_sku_month'         => $pkgStatsNebBySkuMonth,
    'neb_hl_by_format_month'      => $pkgStatsNebByFormatMonth,
    'contract_hl_by_format_month' => $pkgStatsContractByFmtMonth,
    'week_events'                 => $pkgWeekEvents,
    'qa_metrics'                  => $pkgQaMetrics,
    'quarterly_hl'                => $pkgQuarterlyHl,
    'monthly_ytd'                 => $pkgMonthlyYtd,
    // Change 2/3 — canonical beer-loss + new QA section
    'beer_loss_by_year'           => array_values($pkgBeerLossByYear),
    'beer_loss_by_format_month'   => $pkgBeerLossByFormatMonth,
    'consumable_loss_rates'       => $pkgConsumableLossRates,
    'qa_trend_by_month'           => $pkgQaTrendByMonth,
    'co2o2_readings'              => $pkgCo2o2Readings,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script defer src="/js/packaging.js?v=<?= @filemtime(__DIR__ . '/../js/packaging.js') ?: time() ?>"></script>
<script defer src="/js/bbt-detail-modal.js?v=<?= @filemtime(__DIR__ . '/../js/bbt-detail-modal.js') ?: time() ?>"></script>
<script defer src="/js/sku-class-filter.js?v=<?= @filemtime(__DIR__ . '/../js/sku-class-filter.js') ?: time() ?>"></script>
<script defer src="/js/lookup-panel.js?v=<?= @filemtime(__DIR__ . '/../js/lookup-panel.js') ?: time() ?>"></script>

</body>
</html>
