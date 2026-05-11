<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "fermentation";
$crumbs        = ["Accueil", "Fermentation", "Tank Board"];

require_once __DIR__ . "/../../app/svg-tanks.php";

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

// --- Ferm-stats year filter ---
$fermYearDefault = 2024;
$fermYearMin     = 2023;
$fermYearMax     = $currentYear;
$fermYear        = $fermYearDefault;
$_gfy = $_GET['ferm_year'] ?? '';
if (ctype_digit($_gfy)) {
    $py = (int)$_gfy;
    if ($py >= $fermYearMin && $py <= $fermYearMax) $fermYear = $py;
}

// --- CCT view toggle (fill = liquid + state color | metrics = OG/pH/avgs | levure = yeast/gen/repitch) ---
$cctView = 'fill';
if (isset($_GET['cct_view']) && in_array($_GET['cct_view'], ['fill', 'metrics', 'levure'], true)) {
    $cctView = $_GET['cct_view'];
}

function fmt_date_fr_tanks_full(DateTimeImmutable $dt, array $monthsFull): string {
    return sprintf('%d %s %s', (int)$dt->format('j'), $monthsFull[(int)$dt->format('n')], $dt->format('Y'));
}

function fmt_date_fr_tanks(string $dateStr, array $months): string {
    $ts = strtotime($dateStr);
    if ($ts === false) return htmlspecialchars($dateStr);
    $d = (int) date("j", $ts);
    $m = (int) date("n", $ts);
    $y = date("Y", $ts);
    return sprintf("%d %s %s", $d, $months[$m], $y);
}

// -------------------------------------------------------------------
// Database queries + event-sourced simulation
// -------------------------------------------------------------------
require __DIR__ . "/../../app/tank-simulator.php";

try {
    $pdo = maltytask_pdo();

    $cctRef = $pdo->query("
        SELECT number, capacity_hl, status
        FROM ref_cct
        ORDER BY number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $simState  = (new TankSimulator($pdo))->run($asOfDT);
    $cctSimMap = $simState['cct'];

    // ---- Per-beer fermentation stats ----
    $fermStmt = $pdo->prepare("
        WITH fer_end AS (
          SELECT beer, batch, MAX(STR_TO_DATE(start_ferm, '%d.%m.%Y %H:%i:%s')) AS ferm_start_dt
          FROM bd_brewing_timings
          WHERE start_ferm IS NOT NULL AND beer IS NOT NULL AND batch IS NOT NULL
          GROUP BY beer, batch
        ),
        cc AS (
          SELECT
            TRIM(SUBSTRING_INDEX(beers_to_cold_crash, ' ', -1)) AS batch,
            TRIM(SUBSTRING(beers_to_cold_crash, 1, LENGTH(beers_to_cold_crash) - LENGTH(SUBSTRING_INDEX(beers_to_cold_crash, ' ', -1)) - 1)) AS prefix,
            MIN(event_date) AS cc_date
          FROM bd_fermenting
          WHERE beers_to_cold_crash IS NOT NULL AND beers_to_cold_crash != ''
            AND event_date IS NOT NULL
          GROUP BY prefix, batch
        ),
        cc_canon AS (
          SELECT
            CASE prefix
              WHEN 'ZEP' THEN 'Zepp' WHEN 'EMB' THEN 'Embuscade' WHEN 'MOO' THEN 'Moonshine'
              WHEN 'STI' THEN 'Stirling' WHEN 'SPY' THEN 'Speakeasy' WHEN 'DIV' THEN 'Diversion'
              WHEN 'DOA' THEN 'Double Oat' WHEN 'ALT' THEN 'Alternative' WHEN 'DIB' THEN 'Diversion Blanche'
            END AS beer,
            batch, cc_date
          FROM cc
          WHERE prefix IN ('ZEP','EMB','MOO','STI','SPY','DIV','DOA','ALT','DIB')
        )
        SELECT
          rr.name              AS beer,
          rr.recipe_short_name AS short_name,
          COUNT(*)             AS n,
          AVG(DATEDIFF(c.cc_date, DATE(f.ferm_start_dt)))  AS avg_days,
          MIN(DATEDIFF(c.cc_date, DATE(f.ferm_start_dt)))  AS min_days,
          MAX(DATEDIFF(c.cc_date, DATE(f.ferm_start_dt)))  AS max_days
        FROM fer_end f
        JOIN cc_canon c ON c.beer = f.beer AND c.batch = f.batch
        JOIN ref_recipes rr ON rr.name = f.beer AND rr.subtype = 'Core' AND rr.is_active = 1
        WHERE DATEDIFF(c.cc_date, DATE(f.ferm_start_dt)) BETWEEN 1 AND 60
          AND YEAR(f.ferm_start_dt) = :year
        GROUP BY rr.name, rr.recipe_short_name
        ORDER BY rr.name ASC
    ");
    $fermStmt->execute([':year' => $fermYear]);
    $fermStatsRows = $fermStmt->fetchAll(PDO::FETCH_ASSOC);

    $avgFermDaysByBeer = [];
    foreach ($fermStatsRows as $row) {
        $avgFermDaysByBeer[$row['beer']] = (int)round((float)$row['avg_days']);
    }

    // ---- Per-recipe averages of last gravity + last pH before cold-crash ----
    $avgFinalsStmt = $pdo->query("
        WITH cc_per_batch AS (
          SELECT
            TRIM(SUBSTRING(beers_to_cold_crash, 1, LENGTH(beers_to_cold_crash) - LENGTH(SUBSTRING_INDEX(beers_to_cold_crash, ' ', -1)) - 1)) AS prefix,
            TRIM(SUBSTRING_INDEX(beers_to_cold_crash, ' ', -1)) AS batch,
            MIN(event_date) AS cc_date
          FROM bd_fermenting
          WHERE beers_to_cold_crash IS NOT NULL AND beers_to_cold_crash != ''
            AND event_date IS NOT NULL
          GROUP BY prefix, batch
        ),
        reads_with_parse AS (
          SELECT
            TRIM(SUBSTRING(beers_to_read, 1, LENGTH(beers_to_read) - LENGTH(SUBSTRING_INDEX(beers_to_read, ' ', -1)) - 1)) AS prefix,
            TRIM(SUBSTRING_INDEX(beers_to_read, ' ', -1)) AS batch,
            event_date, gravity, ph
          FROM bd_fermenting
          WHERE beers_to_read IS NOT NULL AND beers_to_read != ''
        ),
        last_grav_before_cc AS (
          SELECT r.prefix, r.batch, r.gravity,
                 ROW_NUMBER() OVER (PARTITION BY r.prefix, r.batch ORDER BY r.event_date DESC) AS rk
          FROM reads_with_parse r
          JOIN cc_per_batch c ON c.prefix = r.prefix AND c.batch = r.batch
          WHERE r.event_date < c.cc_date AND r.gravity IS NOT NULL
        ),
        last_ph_before_cc AS (
          SELECT r.prefix, r.batch, r.ph,
                 ROW_NUMBER() OVER (PARTITION BY r.prefix, r.batch ORDER BY r.event_date DESC) AS rk
          FROM reads_with_parse r
          JOIN cc_per_batch c ON c.prefix = r.prefix AND c.batch = r.batch
          WHERE r.event_date < c.cc_date AND r.ph IS NOT NULL
        ),
        grav_avg AS (
          SELECT prefix, AVG(gravity) AS avg_final_grav, COUNT(*) AS n_grav
          FROM last_grav_before_cc WHERE rk = 1
          GROUP BY prefix
        ),
        ph_avg AS (
          SELECT prefix, AVG(ph) AS avg_final_ph, COUNT(*) AS n_ph
          FROM last_ph_before_cc WHERE rk = 1
          GROUP BY prefix
        ),
        prefix_to_recipe AS (
          SELECT * FROM (VALUES
            ROW('ZEP','Zepp'), ROW('EMB','Embuscade'), ROW('MOO','Moonshine'),
            ROW('STI','Stirling'), ROW('SPY','Speakeasy'), ROW('DIV','Diversion'),
            ROW('DOA','Double Oat'), ROW('ALT','Alternative'), ROW('DIB','Diversion Blanche')
          ) AS t(prefix, beer)
        )
        SELECT
          p.beer AS beer,
          g.avg_final_grav,
          ph.avg_final_ph,
          g.n_grav,
          ph.n_ph
        FROM prefix_to_recipe p
        LEFT JOIN grav_avg g ON g.prefix = p.prefix
        LEFT JOIN ph_avg ph ON ph.prefix = p.prefix
        WHERE EXISTS (
          SELECT 1 FROM ref_recipes rr WHERE rr.name = p.beer AND rr.subtype = 'Core' AND rr.is_active = 1
        )
    ");
    $avgFinalsByBeer = [];
    foreach ($avgFinalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $avgFinalsByBeer[$row['beer']] = [
            'grav'   => $row['avg_final_grav'] !== null ? (float)$row['avg_final_grav'] : null,
            'ph'     => $row['avg_final_ph']   !== null ? (float)$row['avg_final_ph']   : null,
            'n_grav' => (int)($row['n_grav'] ?? 0),
            'n_ph'   => (int)($row['n_ph']   ?? 0),
        ];
    }

    // ---- Per-CCT supplemental queries ----
    $cctOccMap = [];
    foreach ($cctSimMap as $num => $simRow) {
        if ($simRow === null) continue;

        $beer    = $simRow['beer'];
        $rawBeer = $simRow['raw_beer'] ?? TankSimulator::rawBeerName($beer);
        $batch   = $simRow['batch'];

        $brewdayDate = $pdo->prepare(
            'SELECT event_date FROM bd_brewing_brewday
             WHERE bd_beer = :beer AND bd_batch = :batch
             ORDER BY event_date DESC LIMIT 1'
        );
        $brewdayDate->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $brewRow = $brewdayDate->fetch();

        $recipeStmt = $pdo->prepare(
            'SELECT COALESCE(rr.recipe_short_name, rr2.recipe_short_name) AS recipe_short_name,
                    COALESCE(rr.classification, rr2.classification)        AS classification,
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

        $beerPrefix = TankSimulator::beerPrefix($beer);
        $exactMatch = $beerPrefix . ' ' . $batch;
        $withTrail  = $beerPrefix . ' ' . $batch . ' %';

        $gravStmt = $pdo->prepare(
            'SELECT gravity AS last_gravity, event_date AS last_gravity_date
             FROM bd_fermenting
             WHERE (beers_to_read = :exact OR beers_to_read LIKE :withTrail)
               AND gravity IS NOT NULL
             ORDER BY event_date DESC LIMIT 1'
        );
        $gravStmt->execute([
            ':exact'     => $exactMatch,
            ':withTrail' => $withTrail,
        ]);
        $gravRow = $gravStmt->fetch() ?: [];

        $ccStmt = $pdo->prepare(
            'SELECT MAX(event_date) AS last_cc_date
             FROM bd_fermenting
             WHERE (beers_to_cold_crash = :exact OR beers_to_cold_crash LIKE :withTrail)
               AND beers_to_cold_crash IS NOT NULL AND beers_to_cold_crash != \'\''
        );
        $ccStmt->execute([
            ':exact'     => $exactMatch,
            ':withTrail' => $withTrail,
        ]);
        $ccRow = $ccStmt->fetch() ?: [];

        $fermStartStmt = $pdo->prepare(
            'SELECT MAX(STR_TO_DATE(start_ferm, \'%d.%m.%Y %H:%i:%s\')) AS ferm_start_dt
             FROM bd_brewing_timings
             WHERE beer = :beer AND batch = :batch AND start_ferm IS NOT NULL'
        );
        $fermStartStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $fermStartRow = $fermStartStmt->fetch() ?: [];
        $fermStartDT  = $fermStartRow['ferm_start_dt'] ?? null;

        // Yeast + generation (from brewday)
        $yeastStmt = $pdo->prepare(
            'SELECT bd_yeast, bd_yeast_gen
             FROM bd_brewing_brewday
             WHERE bd_beer = :beer AND bd_batch = :batch
             ORDER BY event_date DESC LIMIT 1'
        );
        $yeastStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $yeastRow = $yeastStmt->fetch() ?: [];

        // Re-pitch count: how many times this batch's yeast has been used to pitch another batch
        $pitchKey     = $beerPrefix . ' ' . $batch;
        $repitchStmt  = $pdo->prepare(
            'SELECT COUNT(*) FROM bd_brewing_brewday WHERE bd_pitched_from = :src'
        );
        $repitchStmt->execute([':src' => $pitchKey]);
        $repitchCount = (int)$repitchStmt->fetchColumn();

        // Original gravity — latest cooling row of this batch
        $ogStmt = $pdo->prepare(
            'SELECT cool_final_gravity AS og
             FROM bd_brewing_cooling
             WHERE cool_beer = :beer AND cool_batch = :batch AND cool_final_gravity IS NOT NULL
             ORDER BY event_date DESC LIMIT 1'
        );
        $ogStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $ogRow = $ogStmt->fetch() ?: [];

        // Latest pH from fermenting reads
        $phStmt = $pdo->prepare(
            'SELECT ph, event_date AS ph_date
             FROM bd_fermenting
             WHERE (beers_to_read = :exact OR beers_to_read LIKE :withTrail)
               AND ph IS NOT NULL
             ORDER BY event_date DESC LIMIT 1'
        );
        $phStmt->execute([':exact' => $exactMatch, ':withTrail' => $withTrail]);
        $phRow = $phStmt->fetch() ?: [];

        $avgDays   = $avgFermDaysByBeer[$rawBeer] ?? null;
        $estCcDate = null;
        $ccOverdue = null;
        if ($fermStartDT !== null && $avgDays !== null) {
            $estDT     = (new DateTimeImmutable($fermStartDT))->modify('+' . (int)$avgDays . ' days');
            $estCcDate = $estDT->format('Y-m-d');
            $ccOverdue = $estDT < $asOfDT;
        }

        $cctOccMap[$num] = [
            'cct_number'       => $num,
            'bd_beer'          => $beer,
            'bd_beer_raw'      => $rawBeer,
            'bd_batch'         => $batch,
            'volume_hl'        => $simRow['volume_hl'],
            'brewday_date'     => $brewRow['event_date'] ?? null,
            'recipe_short_name'=> $recipeRow['recipe_short_name'] ?? null,
            'classification'   => $recipeRow['classification'] ?? null,
            'client_name'      => $recipeRow['client_name'] ?? null,
            'last_gravity'     => $gravRow['last_gravity'] ?? null,
            'last_gravity_date'=> $gravRow['last_gravity_date'] ?? null,
            'last_cc_date'     => $ccRow['last_cc_date'] ?? null,
            'est_cc_date'      => $estCcDate,
            'cc_overdue'       => $ccOverdue,
            'avg_ferm_days'    => $avgDays,
            'og'               => $ogRow['og']           ?? null,
            'last_ph'          => $phRow['ph']           ?? null,
            'last_ph_date'     => $phRow['ph_date']      ?? null,
            'yeast'            => $yeastRow['bd_yeast']  ?? null,
            'yeast_gen'        => $yeastRow['bd_yeast_gen'] ?? null,
            'repitch_count'    => $repitchCount,
        ];
    }

    $activeCcts  = array_filter($cctRef, fn($r) => $r['status'] === 'active');
    $totalCct    = count($activeCcts);
    $occupiedCct = count($cctOccMap);

    $hlInCcts = 0.0;
    foreach ($cctOccMap as $row) {
        $hlInCcts += (float)($row['volume_hl'] ?? 0);
    }

    $dbError = null;

} catch (Throwable $e) {
    $cctRef          = [];
    $cctOccMap       = [];
    $fermStatsRows   = [];
    $avgFinalsByBeer = [];
    $totalCct        = 0;
    $occupiedCct     = 0;
    $hlInCcts        = 0.0;
    $dbError         = $e->getMessage();
}

$today = $asOfDT;
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tank Board — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main tanks-main">

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- As-of date filter -->
  <form class="tanks-filters" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
    <?php if (isset($_GET['cct_view'])): ?>
      <input type="hidden" name="cct_view" value="<?= htmlspecialchars($_GET['cct_view']) ?>">
    <?php endif ?>
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

  <!-- As-of banner -->
  <?php if ($filterActive): ?>
    <div class="tanks-asof-banner">
      État au <?= htmlspecialchars(fmt_date_fr_tanks_full($asOfDT, $monthsFRFull)) ?>
    </div>
  <?php else: ?>
    <div class="tanks-asof-banner tanks-asof-banner--current">
      État actuel · <?= htmlspecialchars(fmt_date_fr_tanks_full($todayDT, $monthsFRFull)) ?>
    </div>
  <?php endif ?>

  <!-- KPI bar -->
  <section class="wort-kpis" aria-label="État des fermenteurs">
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $occupiedCct ?><span class="wort-kpi__denom"> / <?= $totalCct ?></span></span>
      <span class="wort-kpi__label">Fermenteurs occupés</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $hlInCcts > 0 ? number_format($hlInCcts, 1) : '—' ?></span>
      <span class="wort-kpi__label">HL en fermenteurs</span>
    </div>
  </section>

  <!-- CCT Section -->
  <section class="tanks-section" aria-label="Fermenteurs CCT">
    <div class="wort-section__head">
      <h2 class="tanks-section__title">Fermenteurs <span class="tanks-section__tag">CCT</span></h2>
      <span class="wort-section__label">— <?= $occupiedCct ?> occupé<?= $occupiedCct !== 1 ? 's' : '' ?> sur <?= $totalCct ?> actifs</span>
      <form class="cct-view-toggle" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
        <?php foreach (['year','month','day','ferm_year'] as $k): ?>
          <?php if (isset($_GET[$k]) && $_GET[$k] !== ''): ?>
            <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k]) ?>">
          <?php endif ?>
        <?php endforeach ?>
        <span class="cct-view-toggle__label">Vue</span>
        <button type="submit" name="cct_view" value="fill"
                class="cct-view-toggle__btn<?= $cctView === 'fill' ? ' cct-view-toggle__btn--active' : '' ?>">
          Niveau
        </button>
        <button type="submit" name="cct_view" value="metrics"
                class="cct-view-toggle__btn<?= $cctView === 'metrics' ? ' cct-view-toggle__btn--active' : '' ?>">
          Mesures
        </button>
        <button type="submit" name="cct_view" value="levure"
                class="cct-view-toggle__btn<?= $cctView === 'levure' ? ' cct-view-toggle__btn--active' : '' ?>">
          Levure
        </button>
      </form>
    </div>

    <div class="tanks-grid">
      <?php foreach ($cctRef as $cct):
        $num      = (int)$cct['number'];
        $capHl    = (float)$cct['capacity_hl'];
        $status   = $cct['status'];
        $occ      = $cctOccMap[$num] ?? null;
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
            $hasColdCrash = !empty($occ['last_cc_date']);
            $stateClass   = $hasColdCrash ? 'tank-card--cold' : 'tank-card--ferment';
            $svgState     = $hasColdCrash ? 'cold' : 'ferment';
            $volHl        = (float)($occ['volume_hl'] ?? 0);
            $fillRatio    = $capHl > 0 ? min(1.0, $volHl / $capHl) : 0.0;
        }

        // Mesures view: override fill to fermentation-progress indicator
        $displayFillRatio = $fillRatio;
        if ($cctView === 'metrics' && $occ) {
            $rawBeer = $occ['bd_beer_raw'] ?? ($occ['bd_beer'] ?? '');
            if (!empty($occ['last_cc_date'])) {
                $displayFillRatio = 1.0;  // CC: cuve shown full
            } else {
                $ogV    = $occ['og']           ?? null;
                $lgV    = $occ['last_gravity'] ?? null;
                $avgFV  = $avgFinalsByBeer[$rawBeer] ?? null;
                $tgtFGV = $avgFV['grav'] ?? null;
                if ($ogV !== null && $lgV !== null && $tgtFGV !== null
                    && (float)$ogV > (float)$tgtFGV) {
                    $progress = ((float)$ogV - (float)$lgV) / ((float)$ogV - (float)$tgtFGV);
                    $displayFillRatio = max(0.0, min(1.0, $progress));
                } else {
                    $displayFillRatio = 0.0;
                }
            }
        }
      ?>
      <div class="tank-card <?= $stateClass ?>">
        <div class="tank-card__svg">
          <?= svg_cct(
            $displayFillRatio,
            $svgState,
            $num,
            (string)($occ['bd_beer'] ?? ''),
            $cctView,
            $occ ? [
                'og'           => $occ['og']            ?? null,
                'ph'           => $occ['last_ph']       ?? null,
                'yeast'        => $occ['yeast']         ?? null,
                'yeast_gen'    => $occ['yeast_gen']     ?? null,
                'repitch_count'=> $occ['repitch_count'] ?? 0,
            ] : []
          ) ?>
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
          </div>

        <?php else:
          $beerLabel = htmlspecialchars($occ['recipe_short_name'] ?? $occ['bd_beer'] ?? '');
          $batch     = htmlspecialchars($occ['bd_batch'] ?? '');
        ?>

          <?php if ($cctView === 'metrics'): ?>
          <?php
            $og       = $occ['og']           ?? null;
            $ph       = $occ['last_ph']      ?? null;
            $lastGrav = $occ['last_gravity'] ?? null;
            $rawBeer  = $occ['bd_beer_raw']  ?? ($occ['bd_beer'] ?? '');
            $avgF     = $avgFinalsByBeer[$rawBeer] ?? null;
            $targetFG = $avgF['grav'] ?? null;
            $targetPH = $avgF['ph']   ?? null;
            $isCCBatch = !empty($occ['last_cc_date']);

            // Attenuation % (fermenting only)
            $attenPct = null;
            if (!$isCCBatch && $og !== null && $lastGrav !== null && $targetFG !== null
                && (float)$og > (float)$targetFG) {
                $progress = ((float)$og - (float)$lastGrav) / ((float)$og - (float)$targetFG);
                $attenPct = max(0.0, min(1.0, $progress)) * 100.0;
            }

            $attenBand = '';
            if ($attenPct !== null) {
                if      ($attenPct >= 95) $attenBand = 'metric--good';
                elseif  ($attenPct >= 80) $attenBand = 'metric--mid';
                else                       $attenBand = 'metric--caution';
            }

            // pH band class
            // 4.0–4.6 = healthy end-ferm range (good)
            // 4.7–4.8 = still fermenting / slightly high (mid)
            // <4.0 or >4.8 = unusual (warn)
            $phBand = '';
            if ($ph !== null) {
                $phF = (float)$ph;
                if ($phF >= 4.0 && $phF <= 4.6)    $phBand = 'metric--good';
                elseif ($phF > 4.6 && $phF <= 4.8) $phBand = 'metric--mid';
                else                                $phBand = 'metric--warn';
            }
          ?>
            <div class="tank-card__info tank-card__info--metrics">
              <span class="tank-card__beer"><?= $beerLabel ?></span>
              <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
              <?php if ($isCCBatch): ?>
              <!-- CC state: OG / pH fin / FG fin / pH cible -->
              <dl class="metric-list">
                <div class="metric metric--og">
                  <dt>OG</dt>
                  <dd class="tanks-mono"><?= $og !== null ? number_format((float)$og, 1) . '<span class="metric__unit"> °P</span>' : '—' ?></dd>
                </div>
                <div class="metric <?= $phBand ?>">
                  <dt>pH fin</dt>
                  <dd class="tanks-mono"><?= $ph !== null ? number_format((float)$ph, 2) : '—' ?></dd>
                </div>
                <div class="metric metric--fg">
                  <dt>FG fin</dt>
                  <dd class="tanks-mono"><?= $lastGrav !== null ? number_format((float)$lastGrav, 1) . '<span class="metric__unit"> °P</span>' : '—' ?></dd>
                </div>
                <div class="metric metric--target">
                  <dt>pH cible</dt>
                  <dd class="tanks-mono"><?= $targetPH !== null ? number_format((float)$targetPH, 2) : '—' ?></dd>
                </div>
              </dl>
              <?php else: ?>
              <!-- Fermenting state: OG / pH / Atténuation / FG cible -->
              <dl class="metric-list">
                <div class="metric metric--og">
                  <dt>OG</dt>
                  <dd class="tanks-mono"><?= $og !== null ? number_format((float)$og, 1) . '<span class="metric__unit"> °P</span>' : '—' ?></dd>
                </div>
                <div class="metric <?= $phBand ?>">
                  <dt>pH</dt>
                  <dd class="tanks-mono"><?= $ph !== null ? number_format((float)$ph, 2) : '—' ?></dd>
                </div>
                <div class="metric <?= $attenBand ?>">
                  <dt>Atténuation</dt>
                  <dd class="tanks-mono"><?= $attenPct !== null ? round($attenPct) . '<span class="metric__unit"> %</span>' : '—' ?></dd>
                </div>
                <div class="metric metric--target">
                  <dt>FG cible</dt>
                  <dd class="tanks-mono"><?= $targetFG !== null ? number_format((float)$targetFG, 1) . '<span class="metric__unit"> °P</span>' : '—' ?></dd>
                </div>
              </dl>
              <?php endif ?>
            </div>

          <?php elseif ($cctView === 'levure'): ?>
          <?php
            $yeast        = $occ['yeast']         ?? null;
            $yeastGen     = $occ['yeast_gen']     ?? null;
            $repitchCount = $occ['repitch_count'] ?? 0;

            // Color band for yeast generation — orange from 8, red from 16
            $genBand = '';
            if ($yeastGen !== null) {
                $g = (int)$yeastGen;
                if      ($g >= 16) $genBand = 'metric--warn';
                elseif  ($g >=  8) $genBand = 'metric--caution';
                else               $genBand = 'metric--good';
            }
          ?>
            <div class="tank-card__info tank-card__info--metrics">
              <span class="tank-card__beer"><?= $beerLabel ?></span>
              <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
              <dl class="metric-list">
                <div class="metric metric--yeast-full">
                  <dt>Souche</dt>
                  <dd class="tanks-mono"><?= $yeast !== null ? htmlspecialchars((string)$yeast) : '—' ?></dd>
                </div>
                <div class="metric <?= $genBand ?>">
                  <dt>Gén</dt>
                  <dd class="tanks-mono"><?= $yeastGen !== null ? (int)$yeastGen : '—' ?></dd>
                </div>
                <div class="metric metric--repitch">
                  <dt>Re-pitch</dt>
                  <dd class="tanks-mono"><?= $repitchCount ?><span class="metric__unit"> ×</span></dd>
                </div>
              </dl>
            </div>

          <?php else: ?>
          <?php
            $volHlFmt   = $occ['volume_hl'] !== null ? number_format((float)$occ['volume_hl'], 1) . ' HL' : '—';
            $brewDate   = !empty($occ['brewday_date'])
                ? fmt_date_fr_tanks($occ['brewday_date'], $monthsFR)
                : '—';

            $lastGrav     = $occ['last_gravity']      ?? null;
            $lastGravDate = $occ['last_gravity_date'] ?? null;
            $gravFmt      = $lastGrav !== null
                ? number_format((float)$lastGrav, 1) . '°P'
                : null;
            $gravDateFmt  = $lastGravDate
                ? fmt_date_fr_tanks($lastGravDate, $monthsFR)
                : null;

            $ccDate    = $occ['last_cc_date'] ?? null;
            $ccDays    = null;
            $ccDateFmt = null;
            if ($ccDate) {
                $ccDT      = new DateTimeImmutable($ccDate);
                $ccDays    = (int)$ccDT->diff($asOfDT)->days;
                $ccDateFmt = fmt_date_fr_tanks($ccDate, $monthsFR);
            }
          ?>
            <div class="tank-card__info">
              <span class="tank-card__beer"><?= $beerLabel ?></span>
              <span class="tank-card__batch tanks-mono"><?= $batch ?></span>
              <span class="tank-card__vol tanks-mute"><?= htmlspecialchars($volHlFmt) ?></span>
              <span class="tank-card__brewdate tanks-mute"><?= htmlspecialchars($brewDate) ?></span>
              <?php if ($gravFmt): ?>
                <span class="tank-card__grav tanks-mono">
                  <?= htmlspecialchars($gravFmt) ?>
                  <?php if ($gravDateFmt): ?>
                    <span class="tanks-mute"><?= htmlspecialchars($gravDateFmt) ?></span>
                  <?php endif ?>
                </span>
              <?php endif ?>
              <?php if ($ccDays !== null): ?>
                <span class="tank-badge tank-badge--cold">❄ J+<?= $ccDays ?> · <?= htmlspecialchars($ccDateFmt ?? '') ?></span>
              <?php endif ?>
              <?php if (empty($ccDate) && !empty($occ['est_cc_date'])):
                  $estCcFmt       = fmt_date_fr_tanks($occ['est_cc_date'], $monthsFR);
                  $rackBadgeClass = $occ['cc_overdue'] ? 'tank-badge--rack-overdue' : 'tank-badge--rack-future';
                  $rackBadgeLabel = $occ['cc_overdue'] ? '❄ CC prévu' : '❄ CC ~';
              ?>
                <span class="tank-badge <?= $rackBadgeClass ?>" title="Moyenne <?= (int)$occ['avg_ferm_days'] ?> j de fermentation pour cette bière">
                  <?= $rackBadgeLabel ?> <?= htmlspecialchars($estCcFmt) ?>
                </span>
              <?php endif ?>
            </div>
          <?php endif ?>

        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>

  <!-- Ferm-stats intermediate table -->
  <section class="tanks-section ferm-stats" aria-label="Statistiques de fermentation">
    <div class="wort-section__head ferm-stats__head">
      <h2 class="tanks-section__title">
        Temps moyen en fermenteur
        <span class="tanks-section__tag">Core actifs</span>
      </h2>
      <form class="ferm-stats__year-form" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
        <?php if (isset($_GET['year']))     : ?><input type="hidden" name="year"     value="<?= htmlspecialchars($_GET['year'])     ?>"><?php endif ?>
        <?php if (isset($_GET['month']))    : ?><input type="hidden" name="month"    value="<?= htmlspecialchars($_GET['month'])    ?>"><?php endif ?>
        <?php if (isset($_GET['day']))      : ?><input type="hidden" name="day"      value="<?= htmlspecialchars($_GET['day'])      ?>"><?php endif ?>
        <?php if (isset($_GET['cct_view'])): ?><input type="hidden" name="cct_view" value="<?= htmlspecialchars($_GET['cct_view']) ?>"><?php endif ?>
        <label class="ferm-stats__year-label" for="fs-year">Année</label>
        <select id="fs-year" name="ferm_year" onchange="this.form.submit()">
          <?php for ($y = $fermYearMin; $y <= $fermYearMax; $y++): ?>
            <option value="<?= $y ?>"<?= $y === $fermYear ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor ?>
        </select>
      </form>
    </div>

    <?php if (empty($fermStatsRows)): ?>
      <p class="ferm-stats__empty">Aucune donnée pour <?= $fermYear ?>.</p>
    <?php else: ?>
    <div class="ferm-stats__wrap">
      <table class="ferm-stats__table" aria-label="Durée moyenne de fermentation par bière">
        <caption class="ferm-stats__caption">Fin du cooling → cold crash · <?= $fermYear ?></caption>
        <thead>
          <tr>
            <th scope="col" class="ferm-stats__th ferm-stats__th--beer">Bière</th>
            <th scope="col" class="ferm-stats__th ferm-stats__th--n">Lots</th>
            <th scope="col" class="ferm-stats__th ferm-stats__th--avg">Moyenne</th>
            <th scope="col" class="ferm-stats__th ferm-stats__th--range">Plage min – max</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fermStatsRows as $fsr):
            $avgD  = (int)round((float)$fsr['avg_days']);
            $minD  = (int)$fsr['min_days'];
            $maxD  = (int)$fsr['max_days'];
            $label = htmlspecialchars($fsr['short_name'] ?: $fsr['beer']);
            $barMin  = max(5, $minD);
            $barMax  = min(90, $maxD);
            $barSpan = max(1, $barMax - $barMin);
            $avgPct  = min(100, max(0, round(($avgD - $barMin) / $barSpan * 100)));
          ?>
          <tr class="ferm-stats__row">
            <td class="ferm-stats__td ferm-stats__td--beer"><?= $label ?></td>
            <td class="ferm-stats__td ferm-stats__td--n"><?= (int)$fsr['n'] ?></td>
            <td class="ferm-stats__td ferm-stats__td--avg"><?= $avgD ?><span class="ferm-stats__unit"> j</span></td>
            <td class="ferm-stats__td ferm-stats__td--range">
              <div class="ferm-stats__bar-wrap" title="min <?= $minD ?> j · moy <?= $avgD ?> j · max <?= $maxD ?> j">
                <span class="ferm-stats__bar-track">
                  <span class="ferm-stats__bar-fill" style="--avg-pct:<?= $avgPct ?>%"></span>
                </span>
                <span class="ferm-stats__bar-labels">
                  <span class="ferm-stats__bar-min"><?= $minD ?></span>
                  <span class="ferm-stats__bar-max"><?= $maxD ?></span>
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

</main>

</body>
</html>
