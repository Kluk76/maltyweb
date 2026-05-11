<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "wort";
$crumbs        = ["Accueil", "Wort Production"];

// --- Filter input validation ---
$filterYear           = null;
$filterMonth          = null;
$filterRecipe         = null;
$filterYeast          = null;
$filterGen            = null;
$filterCct            = null;
$filterClassification = null;

$currentYear = (int) date('Y');
$yearParam   = $_GET['year'] ?? null;
if ($yearParam === null) {
    $filterYear = $currentYear;
} elseif ($yearParam === 'all') {
    $filterYear = null;
} elseif (ctype_digit((string) $yearParam)) {
    $y = (int) $yearParam;
    if ($y >= 2015 && $y <= 2030) {
        $filterYear = $y;
    }
}
if (!empty($_GET['month']) && ctype_digit((string) $_GET['month'])) {
    $m = (int) $_GET['month'];
    if ($m >= 1 && $m <= 12) {
        $filterMonth = $m;
    }
}
if (isset($_GET['recipe']) && $_GET['recipe'] !== '') {
    $filterRecipe = (string) $_GET['recipe'];
}
if (isset($_GET['yeast']) && $_GET['yeast'] !== '') {
    $filterYeast = (string) $_GET['yeast'];
}
if (isset($_GET['gen']) && $_GET['gen'] !== '') {
    $filterGen = (string) $_GET['gen'];
}
if (!empty($_GET['cct']) && ctype_digit((string) $_GET['cct'])) {
    $c = (int) $_GET['cct'];
    if ($c >= 1 && $c <= 18) {
        $filterCct = $c;
    }
}
if (!empty($_GET['classification']) && in_array($_GET['classification'], ['Neb', 'Contract'], true)) {
    $filterClassification = $_GET['classification'];
}

$anyFilter = ($filterYear !== $currentYear || $filterMonth !== null || $filterRecipe !== null
           || $filterYeast !== null || $filterGen !== null || $filterCct !== null
           || $filterClassification !== null);

$rowLimit = $anyFilter ? 200 : 50;

// --- Build WHERE clauses ---
$where  = [];
$params = [];

if ($filterYear !== null) {
    $where[]        = "YEAR(COALESCE(bb.event_date, DATE(bb.submitted_at))) = :year";
    $params[':year'] = $filterYear;
}
if ($filterMonth !== null) {
    $where[]         = "MONTH(COALESCE(bb.event_date, DATE(bb.submitted_at))) = :month";
    $params[':month'] = $filterMonth;
}
if ($filterRecipe !== null) {
    $where[]          = "bb.bd_beer = :recipe";
    $params[':recipe'] = $filterRecipe;
}
if ($filterYeast !== null) {
    $where[]         = "bb.bd_yeast = :yeast";
    $params[':yeast'] = $filterYeast;
}
if ($filterGen !== null) {
    $where[]       = "bb.bd_yeast_gen = :gen";
    $params[':gen'] = $filterGen;
}
if ($filterCct !== null) {
    // Match leading numeric portion of bd_cct against the filter value
    $where[]       = "NULLIF(REGEXP_REPLACE(COALESCE(bb.bd_cct, ''), '[^0-9].*$', ''), '') + 0 = :cct";
    $params[':cct'] = $filterCct;
}
if ($filterClassification !== null) {
    $where[]                    = "COALESCE(rr.classification, rr2.classification) = :classification";
    $params[':classification']   = $filterClassification;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Date helpers (FR locale, no intl extension needed) ---
$monthsFR = [
    1 => "jan", 2 => "fév", 3 => "mar", 4 => "avr",
    5 => "mai", 6 => "jun", 7 => "jul", 8 => "aoû",
    9 => "sep", 10 => "oct", 11 => "nov", 12 => "déc",
];
$monthsFRFull = [
    1 => "Janvier", 2 => "Février", 3 => "Mars", 4 => "Avril",
    5 => "Mai", 6 => "Juin", 7 => "Juillet", 8 => "Août",
    9 => "Septembre", 10 => "Octobre", 11 => "Novembre", 12 => "Décembre",
];

function fmt_date_fr(string $dateStr, array $months): string {
    $ts = strtotime($dateStr);
    if ($ts === false) return htmlspecialchars($dateStr);
    $d = (int) date("j", $ts);
    $m = (int) date("n", $ts);
    $y = date("Y", $ts);
    return sprintf("%02d %s %s", $d, $months[$m], $y);
}

function fmt_submitted(string $dt): string {
    $ts = strtotime($dt);
    if ($ts === false) return htmlspecialchars($dt);
    return date("d/m H:i", $ts);
}

function best_date(array $row): string {
    return $row["event_date"] ?? (
        $row["submitted_at"] ? substr($row["submitted_at"], 0, 10) : ""
    );
}

// Shared JOIN fragment (reused by both KPI and row queries)
$joinsSql = "
    FROM bd_brewing_brewday bb

    -- EPH join: name + vintage derived from bd_batch ('24' -> '2024')
    LEFT JOIN ref_recipes rr
        ON  rr.name    = bb.bd_beer
        AND rr.vintage = CONCAT('20', LPAD(REGEXP_REPLACE(COALESCE(bb.bd_batch,''), '[^0-9].*$', ''), 2, '0'))
        AND rr.vintage <> '20'

    -- Non-EPH / no-vintage fallback join
    LEFT JOIN ref_recipes rr2
        ON  rr2.name    = bb.bd_beer
        AND rr2.vintage = ''

    -- Client from whichever recipe joined
    LEFT JOIN ref_clients rc
        ON  rc.id = COALESCE(rr.client_id, rr2.client_id)

    -- CCT: extract leading digits from raw string, cast to int, join
    LEFT JOIN ref_cct cct
        ON  cct.number = NULLIF(
                REGEXP_REPLACE(COALESCE(bb.bd_cct, ''), '[^0-9].*$', ''),
                ''
            ) + 0

    -- YT: same extraction
    LEFT JOIN ref_yt yt
        ON  yt.number = NULLIF(
                REGEXP_REPLACE(COALESCE(bb.bd_yt, ''), '[^0-9].*$', ''),
                ''
            ) + 0
";

try {
    $pdo = maltytask_pdo();

    // --- Dropdown data: years ---
    $yearRows = $pdo->query("
        SELECT DISTINCT YEAR(COALESCE(event_date, DATE(submitted_at))) AS yr
        FROM bd_brewing_brewday
        WHERE event_date IS NOT NULL OR submitted_at IS NOT NULL
        ORDER BY yr DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: recipes ---
    $recipeRows = $pdo->query("
        SELECT DISTINCT bd_beer
        FROM bd_brewing_brewday
        WHERE bd_beer IS NOT NULL AND bd_beer != ''
        ORDER BY bd_beer
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: yeasts ---
    $yeastRows = $pdo->query("
        SELECT DISTINCT bd_yeast
        FROM bd_brewing_brewday
        WHERE bd_yeast IS NOT NULL AND bd_yeast != ''
        ORDER BY bd_yeast
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: generations (numeric-aware sort) ---
    $genRows = $pdo->query("
        SELECT DISTINCT bd_yeast_gen
        FROM bd_brewing_brewday
        WHERE bd_yeast_gen IS NOT NULL AND bd_yeast_gen != ''
        ORDER BY CAST(bd_yeast_gen AS UNSIGNED), bd_yeast_gen
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Cooling join (1 brewday row → N brew/cooling rows for same beer+batch+date)
    $coolingJoinSql = "
        LEFT JOIN bd_brewing_cooling cl
            ON  cl.cool_beer  = bb.bd_beer
            AND cl.cool_batch = bb.bd_batch
            AND COALESCE(cl.event_date, DATE(cl.submitted_at)) = COALESCE(bb.event_date, DATE(bb.submitted_at))
    ";

    // --- KPI query (filtered) ---
    $kpiSql  = "SELECT
            COUNT(DISTINCT bb.id)                                           AS brewday_count,
            COUNT(cl.id)                                                    AS brew_count,
            COUNT(DISTINCT bb.bd_beer)                                      AS distinct_beers,
            MAX(COALESCE(bb.event_date, DATE(bb.submitted_at)))             AS latest_date
        " . $joinsSql . $coolingJoinSql . $whereSql;
    $kpiStmt = $pdo->prepare($kpiSql);
    $kpiStmt->execute($params);
    $stats = $kpiStmt->fetch();

    // --- Last brewday detail (recipe / volume / CCT for the latest event_date in the filtered set) ---
    $lastBrewday = null;
    if (!empty($stats["latest_date"])) {
        $latestDate = $stats["latest_date"];
        $lbWhereSql = $whereSql
            ? $whereSql . " AND COALESCE(bb.event_date, DATE(bb.submitted_at)) = :latest_date"
            : "WHERE COALESCE(bb.event_date, DATE(bb.submitted_at)) = :latest_date";
        $lbSql = "SELECT
                GROUP_CONCAT(DISTINCT bb.bd_beer SEPARATOR ' / ') AS recipes,
                GROUP_CONCAT(DISTINCT NULLIF(REGEXP_REPLACE(COALESCE(bb.bd_cct,''),'[^0-9].*$',''),'') SEPARATOR ' / ') AS ccts,
                SUM(cl.cool_final_volume_hl) AS total_vol_hl
            " . $joinsSql . $coolingJoinSql . $lbWhereSql;
        $lbStmt = $pdo->prepare($lbSql);
        $lbStmt->execute(array_merge($params, [':latest_date' => $latestDate]));
        $lastBrewday = $lbStmt->fetch();
    }

    // --- Row query (filtered) ---
    // EPH rows: bd_batch typically encodes the year suffix, e.g. "24" -> vintage "2024".
    // We attempt two left-joins to ref_recipes:
    //   rr  — match name + vintage built from bd_batch (EPH pattern)
    //   rr2 — match name + vintage='' (non-EPH / no vintage)
    // COALESCE picks rr first, rr2 as fallback.
    $rowSql = "
        SELECT
            bb.id,
            bb.event_date,
            bb.submitted_at,
            bb.bd_beer,
            bb.bd_batch,
            bb.bd_cct,
            bb.bd_yeast,
            bb.bd_yeast_gen,
            bb.bd_yt,

            COALESCE(rr.recipe_short_name,  rr2.recipe_short_name)  AS recipe_short_name,
            COALESCE(rr.classification,     rr2.classification)      AS classification,
            COALESCE(rr.subtype,            rr2.subtype)             AS subtype,
            COALESCE(rr.vintage,            rr2.vintage)             AS vintage,
            COALESCE(rr.client_id,          rr2.client_id)           AS client_id,

            rc.name                                                   AS client_name,

            cct.capacity_hl                                           AS cct_capacity_hl,
            yt.capacity_hl                                            AS yt_capacity_hl
    " . $joinsSql . $whereSql . "
        ORDER BY COALESCE(bb.event_date, DATE(bb.submitted_at)) DESC,
                 bb.submitted_at DESC
        LIMIT " . $rowLimit . "
    ";

    $rowStmt = $pdo->prepare($rowSql);
    $rowStmt->execute($params);
    $rows    = $rowStmt->fetchAll();
    $dbError = null;

} catch (Throwable $e) {
    $rows        = [];
    $stats       = null;
    $lastBrewday = null;
    $dbError     = $e->getMessage();
    $yearRows    = [];
    $recipeRows  = [];
    $yeastRows   = [];
    $genRows     = [];
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wort Production — MaltyTask</title>
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

  <!-- KPI stats bar -->
  <section class="wort-kpis" aria-label="Statistiques brassage">
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= htmlspecialchars((string) ($stats["brewday_count"] ?? "—")) ?></span>
      <span class="wort-kpi__label">Brewdays</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= htmlspecialchars((string) ($stats["brew_count"] ?? "—")) ?></span>
      <span class="wort-kpi__label">Brews</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= htmlspecialchars((string) ($stats["distinct_beers"] ?? "—")) ?></span>
      <span class="wort-kpi__label">Recettes distinctes</span>
    </div>
    <div class="wort-kpi wort-kpi--compound">
      <?php if (!empty($stats["latest_date"])): ?>
        <span class="wort-kpi__date"><?= fmt_date_fr($stats["latest_date"], $monthsFR) ?></span>
        <?php
          $lbRecipes = $lastBrewday["recipes"] ?? "";
          $lbCcts    = $lastBrewday["ccts"]    ?? "";
          $lbVolHl   = isset($lastBrewday["total_vol_hl"]) ? (float) $lastBrewday["total_vol_hl"] : 0.0;

          $volDisplay = $lbVolHl > 0 ? number_format($lbVolHl, 1) . " HL" : "—";
          if ($lbCcts !== "") {
              $cctParts = array_filter(array_map('trim', explode("/", $lbCcts)), fn($c) => $c !== "");
              $cctDisplay = $cctParts ? "CCT " . implode(" / ", $cctParts) : "—";
          } else {
              $cctDisplay = "—";
          }
        ?>
        <?php if ($lbRecipes !== ""): ?>
          <span class="wort-kpi__detail"><?= htmlspecialchars($lbRecipes) ?></span>
        <?php endif ?>
        <span class="wort-kpi__detail"><?= htmlspecialchars($volDisplay) ?> · <?= htmlspecialchars($cctDisplay) ?></span>
      <?php else: ?>
        <span class="wort-kpi__date">—</span>
      <?php endif ?>
      <span class="wort-kpi__label">Dernier brewday</span>
    </div>
  </section>

  <!-- Filter bar -->
  <form class="wort-filters" method="get" action="">
    <div class="wort-filters__row">

      <label class="wort-filters__field">
        <span class="wort-filters__label">Année</span>
        <select name="year" onchange="this.form.submit()">
          <option value="all"<?= ($filterYear === null) ? ' selected' : '' ?>>Tous</option>
          <?php foreach ($yearRows as $yr): ?>
            <option value="<?= htmlspecialchars((string) $yr) ?>"<?= ($filterYear === (int) $yr) ? ' selected' : '' ?>>
              <?= htmlspecialchars((string) $yr) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="wort-filters__field">
        <span class="wort-filters__label">Mois</span>
        <select name="month" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php for ($mi = 1; $mi <= 12; $mi++): ?>
            <option value="<?= $mi ?>"<?= ($filterMonth === $mi) ? ' selected' : '' ?>>
              <?= htmlspecialchars($monthsFRFull[$mi]) ?>
            </option>
          <?php endfor ?>
        </select>
      </label>

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
        <span class="wort-filters__label">Levure</span>
        <select name="yeast" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php foreach ($yeastRows as $ye): ?>
            <option value="<?= htmlspecialchars($ye) ?>"<?= ($filterYeast === $ye) ? ' selected' : '' ?>>
              <?= htmlspecialchars($ye) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="wort-filters__field">
        <span class="wort-filters__label">Génération</span>
        <select name="gen" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php foreach ($genRows as $gen): ?>
            <option value="<?= htmlspecialchars($gen) ?>"<?= ($filterGen === $gen) ? ' selected' : '' ?>>
              G<?= htmlspecialchars($gen) ?>
            </option>
          <?php endforeach ?>
        </select>
      </label>

      <label class="wort-filters__field">
        <span class="wort-filters__label">CCT</span>
        <select name="cct" onchange="this.form.submit()">
          <option value="">Tous</option>
          <?php for ($ci = 1; $ci <= 18; $ci++): ?>
            <option value="<?= $ci ?>"<?= ($filterCct === $ci) ? ' selected' : '' ?>>
              CCT <?= $ci ?>
            </option>
          <?php endfor ?>
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

  <!-- Brewday table -->
  <section class="wort-section" aria-label="Derniers brewdays">
    <div class="wort-section__head">
      <span class="wort-section__label">
        <?php if ($anyFilter): ?>
          — résultats filtrés
        <?php else: ?>
          — derniers 50 brewdays
        <?php endif ?>
      </span>
      <?php if ($anyFilter): ?>
        <span class="wort-filters__count"><?= count($rows) ?> résultat<?= count($rows) !== 1 ? 's' : '' ?></span>
      <?php endif ?>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty">Aucun brewday enregistré.</div>
    <?php else: ?>
      <div class="wort-table-wrap">
        <table class="wort-table">
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Recette</th>
              <th scope="col">Batch</th>
              <th scope="col">CCT</th>
              <th scope="col">Levure</th>
              <th scope="col">YT</th>
              <th scope="col">Soumis</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                // --- Date column ---
                $dateStr = best_date($r);
                $dateFmt = $dateStr ? fmt_date_fr($dateStr, $monthsFR) : "—";

                // --- Recipe column ---
                $shortName  = $r["recipe_short_name"] ?? null;
                $rawBeer    = $r["bd_beer"] ?? "";
                // Show raw beer name below short name only when they differ
                $showRaw    = $shortName && strtolower(trim($shortName)) !== strtolower(trim($rawBeer));
                // Contract client badge
                $showClient = ($r["classification"] === "Contract") && !empty($r["client_name"]);

                // --- Batch column ---
                $batch   = $r["bd_batch"] ?? "";
                $vintage = $r["vintage"]  ?? "";
                // Show vintage badge when EPH-style batch resolves to a vintage
                $showVintage = ($vintage !== "" && strlen($batch) === 2
                                && str_starts_with($vintage, "20" . $batch));

                // --- CCT column ---
                // bd_cct is INT UNSIGNED NULL since migration 026d — cast to string before regex.
                $rawCct     = (string) ($r["bd_cct"] ?? "");
                $cctCap     = $r["cct_capacity_hl"] ?? null;
                $cctNum     = preg_replace('/[^0-9].*$/', '', $rawCct);
                if ($cctNum !== "" && $cctCap !== null) {
                    $cctDisplay = $cctNum . " · " . $cctCap . " HL";
                } elseif ($rawCct !== "") {
                    $cctDisplay = $rawCct;
                } else {
                    $cctDisplay = "—";
                }

                // --- Yeast column ---
                $yeast    = $r["bd_yeast"]     ?? "";
                $yeastGen = $r["bd_yeast_gen"] ?? "";

                // --- YT column ---
                // bd_yt is INT UNSIGNED NULL since migration 026d — cast to string before regex.
                $rawYt  = (string) ($r["bd_yt"] ?? "");
                $ytCap  = $r["yt_capacity_hl"] ?? null;
                $ytNum  = preg_replace('/[^0-9].*$/', '', $rawYt);
                if ($ytNum !== "" && $ytCap !== null) {
                    $ytDisplay = $ytNum . " · " . $ytCap . " HL";
                } elseif ($rawYt !== "") {
                    $ytDisplay = $rawYt;
                } else {
                    $ytDisplay = "—";
                }

                // --- Submitted column ---
                $submittedFmt = !empty($r["submitted_at"])
                    ? fmt_submitted($r["submitted_at"])
                    : "—";
              ?>
              <tr>
                <td class="wort-td wort-td--date">
                  <?= htmlspecialchars($dateFmt) ?>
                </td>

                <td class="wort-td wort-td--recipe">
                  <span class="wort-recipe__short">
                    <?= htmlspecialchars($shortName ?? $rawBeer) ?>
                  </span>
                  <?php if ($showRaw): ?>
                    <span class="wort-recipe__raw"><?= htmlspecialchars($rawBeer) ?></span>
                  <?php endif ?>
                  <?php if ($showClient): ?>
                    <span class="wort-recipe__client"><?= htmlspecialchars($r["client_name"]) ?></span>
                  <?php endif ?>
                </td>

                <td class="wort-td wort-td--batch">
                  <?php if ($showVintage): ?>
                    <span class="wort-badge wort-badge--vintage"><?= htmlspecialchars($vintage) ?></span>
                  <?php endif ?>
                  <span class="wort-mono"><?= htmlspecialchars($batch !== "" ? $batch : "—") ?></span>
                </td>

                <td class="wort-td wort-td--cct">
                  <span class="wort-mono"><?= htmlspecialchars($cctDisplay) ?></span>
                </td>

                <td class="wort-td wort-td--yeast">
                  <?php if ($yeast !== ""): ?>
                    <span class="wort-yeast__name"><?= htmlspecialchars($yeast) ?></span>
                  <?php else: ?>
                    <span class="wort-muted">—</span>
                  <?php endif ?>
                  <?php if ($yeastGen !== ""): ?>
                    <span class="wort-badge wort-badge--gen">G<?= htmlspecialchars($yeastGen) ?></span>
                  <?php endif ?>
                </td>

                <td class="wort-td wort-td--yt">
                  <span class="wort-mono"><?= htmlspecialchars($ytDisplay) ?></span>
                </td>

                <td class="wort-td wort-td--submitted">
                  <span class="wort-mono wort-muted"><?= htmlspecialchars($submittedFmt) ?></span>
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
