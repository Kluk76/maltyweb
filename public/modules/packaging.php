<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "packaging";
$crumbs        = ["Accueil", "Packaging", "BBT & Conditionnement"];

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
        ];
    }

    $activeBbts  = array_filter($bbtRef, fn($r) => $r['status'] === 'active');
    $totalBbt    = count($activeBbts);
    $occupiedBbt = count($bbtOccMap);

    $hlInBbts = 0.0;
    foreach ($bbtOccMap as $row) {
        $hlInBbts += (float)($row['remaining_hl'] ?? 0);
    }

    // Packaging KPIs — year-filtered totals.
    // LEGACY-ONLY (v2 cutover blocker, 2026-05-24): bd_packaging_v2 has NO `format`
    // or `year` column (now run_type enum + nebuleuse_format_suffix; year via
    // YEAR(submitted_at)) AND vendable_hl is 100% NULL — the per-row HL valuation
    // has not been computed/backfilled into v2 yet. All three packaging HL queries
    // below (totals, by-format, top-5) sum vendable_hl, so repointing to v2 now
    // would zero out the entire dashboard. Stays on bd_packaging until v2.vendable_hl
    // is populated and the format→run_type/suffix remap is wired.
    $pkgTotalsStmt = $pdo->prepare("
        SELECT
          COALESCE(SUM(vendable_hl), 0)   AS hl_total,
          COUNT(DISTINCT CONCAT(
            COALESCE(neb_beer, contract_beer), '|', format, '|',
            COALESCE(sel_pack_bot, sel_pack_can, '')
          ))                               AS distinct_skus,
          COUNT(*)                         AS pkg_events
        FROM bd_packaging
        WHERE year = :year
          AND COALESCE(neb_beer, contract_beer) IS NOT NULL
          AND vendable_hl IS NOT NULL
    ");
    $pkgTotalsStmt->execute([':year' => $pkgYear]);
    $pkgTotals = $pkgTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['hl_total' => 0, 'distinct_skus' => 0, 'pkg_events' => 0];

    // HL by format — consolidated
    $pkgFmtStmt = $pdo->prepare("
        SELECT
          CASE WHEN format IN ('Can','Can33') THEN 'Can'
               WHEN format IN ('Bot')         THEN 'Bot'
               WHEN format IN ('Keg')         THEN 'Keg'
               WHEN format IN ('Cuve de service') THEN 'Cuve de service'
               ELSE format END AS fmt,
          SUM(vendable_hl) AS hl
        FROM bd_packaging
        WHERE year = :year AND vendable_hl IS NOT NULL
        GROUP BY fmt
        ORDER BY hl DESC
    ");
    $pkgFmtStmt->execute([':year' => $pkgYear]);
    $pkgByFormat = $pkgFmtStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 SKUs per format (by HL)
    $pkgTop5Stmt = $pdo->prepare("
        WITH grouped AS (
          SELECT
            CASE WHEN format IN ('Can','Can33') THEN 'Can' ELSE format END AS fmt,
            COALESCE(neb_beer, contract_beer) AS sku_code,
            SUM(vendable_hl) AS hl_sum,
            COUNT(*) AS n_events
          FROM bd_packaging
          WHERE year = :year
            AND vendable_hl IS NOT NULL
            AND COALESCE(neb_beer, contract_beer) IS NOT NULL
          GROUP BY
            CASE WHEN format IN ('Can','Can33') THEN 'Can' ELSE format END,
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
    $dbError         = $e->getMessage();
}

$today = $asOfDT;

// Precompute format bar segments (pct of total HL)
$fmtTotalHl = array_sum(array_column($pkgByFormat, 'hl'));
$fmtColors  = [
    'Keg' => 'var(--oak)',
    'Bot' => 'var(--hop-soft)',
    'Can' => 'var(--ember)',
    'Cuve de service' => 'var(--steel)',
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

  <!-- Packaging KPI section -->
  <section class="tanks-section pkg-stats" aria-label="Statistiques de conditionnement">
    <div class="wort-section__head pkg-stats__head">
      <h2 class="tanks-section__title">
        Packaging
        <span class="tanks-section__tag">Conditionnement</span>
      </h2>
      <a href="/modules/form-packaging.php" class="wort-filters__reset tanks-filters__reset">
        + Saisir un conditionnement
      </a>
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

  <!-- BBT Section -->
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
      <div class="tank-card <?= $stateClass ?>">
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
          </div>

        <?php else:
          $beerLabel = htmlspecialchars($occ['recipe_short_name'] ?? $occ['beer'] ?? '');
          $batch     = htmlspecialchars($occ['batch'] ?? '');
          $remainHl  = (float)($occ['remaining_hl'] ?? 0);
          $blendStr  = $occ['blend_str'] ?? '';
          $rackDate  = !empty($occ['rack_date'])
              ? fmt_date_fr_tanks($occ['rack_date'], $monthsFR)
              : '—';

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
            <span class="tank-card__brewdate tanks-mute"><?= htmlspecialchars($rackDate) ?></span>
            <span class="tank-badge tank-badge--days">J+<?= $daysInBbt ?></span>
          </div>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>

</main>

</body>
</html>
