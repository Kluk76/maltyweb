<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_page_access('wort');
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "wort";
$crumbs        = ["Accueil", "Wort Production"];

// --- Filter input validation (Tab 1: Brassins) ---
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
    $where[]          = "bb.beer = :recipe";
    $params[':recipe'] = $filterRecipe;
}
if ($filterYeast !== null) {
    $where[]         = "bb.yeast = :yeast";
    $params[':yeast'] = $filterYeast;
}
if ($filterGen !== null) {
    $where[]       = "bb.yeast_gen = :gen";
    $params[':gen'] = $filterGen;
}
if ($filterCct !== null) {
    // Match leading numeric portion of cct against the filter value
    $where[]       = "NULLIF(REGEXP_REPLACE(COALESCE(bb.cct, ''), '[^0-9].*$', ''), '') + 0 = :cct";
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
    FROM bd_brewing_brewday_v2 bb

    -- EPH join: name + vintage derived from batch ('24' -> '2024')
    LEFT JOIN ref_recipes rr
        ON  rr.name    = bb.beer
        AND rr.vintage = CONCAT('20', LPAD(REGEXP_REPLACE(COALESCE(bb.batch,''), '[^0-9].*$', ''), 2, '0'))
        AND rr.vintage <> '20'

    -- Non-EPH / no-vintage fallback join
    LEFT JOIN ref_recipes rr2
        ON  rr2.name    = bb.beer
        AND rr2.vintage = ''

    -- Client from whichever recipe joined
    LEFT JOIN ref_clients rc
        ON  rc.id = COALESCE(rr.client_id, rr2.client_id)

    -- CCT: extract leading digits from raw string, cast to int, join
    LEFT JOIN ref_cct cct
        ON  cct.number = NULLIF(
                REGEXP_REPLACE(COALESCE(bb.cct, ''), '[^0-9].*$', ''),
                ''
            ) + 0

    -- YT: same extraction
    LEFT JOIN ref_yt yt
        ON  yt.number = NULLIF(
                REGEXP_REPLACE(COALESCE(bb.yt_number, ''), '[^0-9].*$', ''),
                ''
            ) + 0
";

// ─────────────────────────────────────────────────────────────
// Tab 2 (KPIs): CANONICAL DERIVATION
//   HL produced = SUM(cl.final_volume) FROM bd_brewing_gravity_v2
//                 WHERE event_type='Cooling' AND is_tombstoned=0
//   Recipe join directly from the cooling row:
//     JOIN ref_recipes rr ON rr.id = cl.recipe_id_fk
//   (0 NULL FK — clean join, no date-equality match needed)
//   wort.php's cooling join through bd_brewing_brewday_v2 by date
//   is intentionally NOT reused here — it drops ~15% of HL.
// ─────────────────────────────────────────────────────────────

$kpiBucketExpr = "CASE
    WHEN rr.classification='Contract' THEN 'contract'
    WHEN rr.classification='Neb' AND ( rr.subtype IN('EPH','CollabIn','CollabOut')
         OR (rr.subtype='Archive' AND (rr.client_id IS NOT NULL OR lc.n = 1)) ) THEN 'special'
    WHEN rr.classification='Neb' THEN 'core'
    ELSE 'other' END";

$kpiBaseJoin = "
    FROM bd_brewing_gravity_v2 cl
    JOIN ref_recipes rr ON rr.id = cl.recipe_id_fk
    JOIN (
        SELECT recipe_id_fk AS rid, COUNT(*) AS n
        FROM bd_brewing_gravity_v2
        WHERE event_type = 'Cooling' AND is_tombstoned = 0
        GROUP BY recipe_id_fk
    ) lc ON lc.rid = cl.recipe_id_fk
    WHERE cl.event_type = 'Cooling' AND cl.is_tombstoned = 0
";

$kpiPayload = [];
$dbError    = null;

try {
    $pdo = maltytask_pdo();

    // ─── Tab 1: Brassins queries ───────────────────────────────

    // --- Dropdown data: years ---
    $yearRows = $pdo->query("
        SELECT DISTINCT YEAR(COALESCE(event_date, DATE(submitted_at))) AS yr
        FROM bd_brewing_brewday_v2
        WHERE event_date IS NOT NULL OR submitted_at IS NOT NULL
        ORDER BY yr DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: recipes ---
    $recipeRows = $pdo->query("
        SELECT DISTINCT beer
        FROM bd_brewing_brewday_v2
        WHERE beer IS NOT NULL AND beer != ''
        ORDER BY beer
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: yeasts ---
    $yeastRows = $pdo->query("
        SELECT DISTINCT yeast
        FROM bd_brewing_brewday_v2
        WHERE yeast IS NOT NULL AND yeast != ''
        ORDER BY yeast
    ")->fetchAll(PDO::FETCH_COLUMN);

    // --- Dropdown data: generations (numeric-aware sort) ---
    $genRows = $pdo->query("
        SELECT DISTINCT yeast_gen
        FROM bd_brewing_brewday_v2
        WHERE yeast_gen IS NOT NULL AND yeast_gen != ''
        ORDER BY CAST(yeast_gen AS UNSIGNED), yeast_gen
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Cooling join (1 brewday row → N brew/cooling rows for same beer+batch+date).
    $coolingJoinSql = "
        LEFT JOIN bd_brewing_gravity_v2 cl
            ON  cl.event_type = 'Cooling'
            AND cl.beer  = bb.beer
            AND cl.batch = bb.batch
            AND DATE(cl.submitted_at) = COALESCE(bb.event_date, DATE(bb.submitted_at))
    ";

    // --- KPI query (filtered) ---
    $kpiSql  = "SELECT
            COUNT(DISTINCT bb.id)                                           AS brewday_count,
            COUNT(cl.id)                                                    AS brew_count,
            COUNT(DISTINCT bb.beer)                                         AS distinct_beers,
            MAX(COALESCE(bb.event_date, DATE(bb.submitted_at)))             AS latest_date
        " . $joinsSql . $coolingJoinSql . $whereSql;
    $kpiStmt = $pdo->prepare($kpiSql);
    $kpiStmt->execute($params);
    $stats = $kpiStmt->fetch();

    // --- Last brewday detail ---
    $lastBrewday = null;
    if (!empty($stats["latest_date"])) {
        $latestDate = $stats["latest_date"];
        $lbWhereSql = $whereSql
            ? $whereSql . " AND COALESCE(bb.event_date, DATE(bb.submitted_at)) = :latest_date"
            : "WHERE COALESCE(bb.event_date, DATE(bb.submitted_at)) = :latest_date";
        $lbSql = "SELECT
                GROUP_CONCAT(DISTINCT bb.beer SEPARATOR ' / ') AS recipes,
                GROUP_CONCAT(DISTINCT NULLIF(REGEXP_REPLACE(COALESCE(bb.cct,''),'[^0-9].*$',''),'') SEPARATOR ' / ') AS ccts,
                SUM(cl.final_volume) AS total_vol_hl
            " . $joinsSql . $coolingJoinSql . $lbWhereSql;
        $lbStmt = $pdo->prepare($lbSql);
        $lbStmt->execute(array_merge($params, [':latest_date' => $latestDate]));
        $lastBrewday = $lbStmt->fetch();
    }

    // --- Row query (filtered) ---
    $rowSql = "
        SELECT
            bb.id,
            bb.event_date,
            bb.submitted_at,
            bb.beer       AS bd_beer,
            bb.batch      AS bd_batch,
            bb.cct        AS bd_cct,
            bb.yeast      AS bd_yeast,
            bb.yeast_gen  AS bd_yeast_gen,
            bb.yt_number  AS bd_yt,

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

    // ─── Tab 2: KPIs / Analyse queries ───────────────────────

    // Active KPI year (independent from brewday filter)
    $kpiYearParam = $_GET['kpi_year'] ?? null;
    if ($kpiYearParam === null) {
        $kpiActiveYear = $currentYear;
    } elseif ($kpiYearParam === 'all') {
        $kpiActiveYear = 'all';
    } elseif (ctype_digit((string) $kpiYearParam)) {
        $ky = (int) $kpiYearParam;
        $kpiActiveYear = ($ky >= 2015 && $ky <= 2030) ? $ky : $currentYear;
    } else {
        $kpiActiveYear = $currentYear;
    }

    // 1. Distinct years
    $kpiYears = $pdo->query("
        SELECT DISTINCT YEAR(DATE(cl.submitted_at)) AS yr
        " . $kpiBaseJoin . "
        ORDER BY yr DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
    $kpiPayload['years'] = array_values($kpiYears);

    // 2. Bucket totals per year × month
    $monthlyRows = $pdo->query("
        SELECT
            YEAR(DATE(cl.submitted_at)) AS yr,
            MONTH(DATE(cl.submitted_at)) AS mo,
            ({$kpiBucketExpr}) AS bucket,
            ROUND(SUM(cl.final_volume), 1) AS hl,
            COUNT(*) AS brews
        {$kpiBaseJoin}
        GROUP BY yr, mo, bucket
        ORDER BY yr, mo, bucket
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Per-recipe × month
    $recipeMonthRows = $pdo->query("
        SELECT
            YEAR(DATE(cl.submitted_at)) AS yr,
            MONTH(DATE(cl.submitted_at)) AS mo,
            COALESCE(NULLIF(rr.recipe_short_name, ''), rr.name) AS recipe,
            ({$kpiBucketExpr}) AS bucket,
            ROUND(SUM(cl.final_volume), 1) AS hl
        {$kpiBaseJoin}
        GROUP BY yr, mo, recipe, bucket
        ORDER BY yr, bucket, recipe, mo
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 4. YTD query: current + prior year, months 1–today
    $todayMonth = (int) date('n');
    $ytdStmt = $pdo->prepare("
        SELECT
            YEAR(DATE(cl.submitted_at)) AS yr,
            ({$kpiBucketExpr}) AS bucket,
            ROUND(SUM(cl.final_volume), 1) AS hl
        {$kpiBaseJoin}
          AND YEAR(DATE(cl.submitted_at)) IN (:cy, :py)
          AND MONTH(DATE(cl.submitted_at)) <= :mo
        GROUP BY yr, bucket
    ");
    $ytdStmt->execute([':cy' => $currentYear, ':py' => $currentYear - 1, ':mo' => $todayMonth]);
    $ytdRows = $ytdStmt->fetchAll(PDO::FETCH_ASSOC);

    // Assemble per-year data structures
    $byYear = [];
    foreach ($monthlyRows as $r) {
        $yr = (int)$r['yr'];
        $mo = (int)$r['mo'];
        $bk = $r['bucket'];
        if (!isset($byYear[$yr])) {
            $byYear[$yr] = ['monthly' => [], 'totals' => ['core_hl'=>0,'spec_hl'=>0,'contract_hl'=>0,'neb_hl'=>0,'total_hl'=>0,'brews'=>0,'neb_brews'=>0,'contract_brews'=>0]];
        }
        if (!isset($byYear[$yr]['monthly'][$mo])) {
            $byYear[$yr]['monthly'][$mo] = ['core'=>0.0,'special'=>0.0,'contract'=>0.0,'brews'=>['core'=>0,'special'=>0,'contract'=>0]];
        }
        $byYear[$yr]['monthly'][$mo][$bk] = (float)$r['hl'];
        $byYear[$yr]['monthly'][$mo]['brews'][$bk] = (int)$r['brews'];

        $hl = (float)$r['hl'];
        $br = (int)$r['brews'];
        if ($bk === 'core')     { $byYear[$yr]['totals']['core_hl'] += $hl; $byYear[$yr]['totals']['neb_hl'] += $hl; $byYear[$yr]['totals']['neb_brews'] += $br; }
        if ($bk === 'special')  { $byYear[$yr]['totals']['spec_hl'] += $hl; $byYear[$yr]['totals']['neb_hl'] += $hl; $byYear[$yr]['totals']['neb_brews'] += $br; }
        if ($bk === 'contract') { $byYear[$yr]['totals']['contract_hl'] += $hl; $byYear[$yr]['totals']['contract_brews'] += $br; }
        $byYear[$yr]['totals']['total_hl'] += $hl;
        $byYear[$yr]['totals']['brews'] += $br;
    }

    // Index recipe×month by year
    $recipeMo = [];
    foreach ($recipeMonthRows as $r) {
        $yr  = (int)$r['yr'];
        $mo  = (int)$r['mo'];
        $rec = $r['recipe'];
        $bk  = $r['bucket'];
        if (!isset($recipeMo[$yr][$rec])) {
            $recipeMo[$yr][$rec] = ['recipe' => $rec, 'bucket' => $bk, 'mo' => []];
        }
        $recipeMo[$yr][$rec]['mo'][$mo] = (float)$r['hl'];
    }

    // YTD index
    $ytdIndex = [];
    foreach ($ytdRows as $r) {
        $yr = (int)$r['yr'];
        $bk = $r['bucket'];
        if (!isset($ytdIndex[$yr])) $ytdIndex[$yr] = ['core'=>0.0,'special'=>0.0,'contract'=>0.0];
        $ytdIndex[$yr][$bk] = (float)$r['hl'];
    }

    // Build years_data (per-year payload)
    $yearsData = [];
    foreach ($byYear as $yr => $yd) {
        $monthly12 = [];
        $lastDataMonth = 0;
        for ($mo = 1; $mo <= 12; $mo++) {
            $m = $yd['monthly'][$mo] ?? ['core'=>0.0,'special'=>0.0,'contract'=>0.0];
            $monthly12[] = [
                round((float)$m['core'], 1),
                round((float)$m['special'], 1),
                round((float)$m['contract'], 1),
            ];
            if ($m['core'] + $m['special'] + $m['contract'] > 0) {
                $lastDataMonth = $mo;
            }
        }

        $isPartial = ($yr === $currentYear);

        $t = $yd['totals'];
        $cards = [
            'core_hl'         => round($t['core_hl'], 1),
            'spec_hl'         => round($t['spec_hl'], 1),
            'neb_hl'          => round($t['neb_hl'], 1),
            'contract_hl'     => round($t['contract_hl'], 1),
            'total_hl'        => round($t['total_hl'], 1),
            'brews'           => $t['brews'],
            'neb_brews'       => $t['neb_brews'],
            'contract_brews'  => $t['contract_brews'],
        ];

        $yoy = null;
        $yoyLabel = 'totale';
        if ($isPartial) {
            $nebCY = ($ytdIndex[$currentYear]['core'] ?? 0.0) + ($ytdIndex[$currentYear]['special'] ?? 0.0);
            $nebPY = ($ytdIndex[$currentYear - 1]['core'] ?? 0.0) + ($ytdIndex[$currentYear - 1]['special'] ?? 0.0);
            if ($nebPY > 0) {
                $yoy = round(($nebCY - $nebPY) / $nebPY * 100, 1);
                $yoyLabel = 'jan–' . $monthsFR[min($todayMonth, 12)];
            }
        } elseif ($yr >= 2022) {
            $prevYrNeb = isset($byYear[$yr - 1]) ? round($byYear[$yr - 1]['totals']['neb_hl'], 1) : null;
            if ($prevYrNeb !== null && $prevYrNeb > 0) {
                $yoy = round(($t['neb_hl'] - $prevYrNeb) / $prevYrNeb * 100, 1);
            }
        }

        // recipe_month: [{recipe, bucket, vals:[n]}]
        $recipeMonth = [];
        if (isset($recipeMo[$yr])) {
            foreach ($recipeMo[$yr] as $rec => $rd) {
                $vals = [];
                for ($mo = 1; $mo <= $lastDataMonth; $mo++) {
                    $vals[] = round($rd['mo'][$mo] ?? 0.0, 1);
                }
                $recipeMonth[] = [
                    'recipe' => $rd['recipe'],
                    'bucket' => $rd['bucket'],
                    'vals'   => $vals,
                ];
            }
        }

        // recipe_quarter: [{recipe, bucket, vals:[4]}]
        $recipeQuarter = [];
        if (isset($recipeMo[$yr])) {
            foreach ($recipeMo[$yr] as $rec => $rd) {
                $qVals = [0.0, 0.0, 0.0, 0.0];
                foreach ($rd['mo'] as $mo => $hl) {
                    $qi = (int)ceil($mo / 3) - 1;
                    if ($qi >= 0 && $qi < 4) $qVals[$qi] = round($qVals[$qi] + $hl, 1);
                }
                $recipeQuarter[] = [
                    'recipe' => $rd['recipe'],
                    'bucket' => $rd['bucket'],
                    'vals'   => array_map(fn($v) => round($v, 1), $qVals),
                ];
            }
        }

        $yearsData[$yr] = [
            'monthly'          => $monthly12,
            'cards'            => $cards,
            'is_partial'       => $isPartial,
            'is_illustrative'  => false,
            'last_month'       => $lastDataMonth,
            'yoy'              => $yoy,
            'yoy_label'        => $yoyLabel,
            'recipe_month'     => $recipeMonth,
            'recipe_quarter'   => $recipeQuarter,
        ];
    }

    // Annual view (year=all)
    $annualRows  = [];
    $annualCards = ['core_hl'=>0.0,'spec_hl'=>0.0,'neb_hl'=>0.0,'contract_hl'=>0.0,'total_hl'=>0.0,'brews'=>0,'neb_brews'=>0,'contract_brews'=>0];
    foreach ($byYear as $yr => $yd) {
        $t = $yd['totals'];
        $annualRows[] = [
            'year'     => $yr,
            'core'     => round($t['core_hl'], 1),
            'spec'     => round($t['spec_hl'], 1),
            'contract' => round($t['contract_hl'], 1),
            'neb'      => round($t['neb_hl'], 1),
            'total'    => round($t['total_hl'], 1),
        ];
        $annualCards['core_hl']        += $t['core_hl'];
        $annualCards['spec_hl']        += $t['spec_hl'];
        $annualCards['neb_hl']         += $t['neb_hl'];
        $annualCards['contract_hl']    += $t['contract_hl'];
        $annualCards['total_hl']       += $t['total_hl'];
        $annualCards['brews']          += $t['brews'];
        $annualCards['neb_brews']      += $t['neb_brews'];
        $annualCards['contract_brews'] += $t['contract_brews'];
    }
    usort($annualRows, fn($a,$b) => $a['year'] <=> $b['year']);
    foreach (['core_hl','spec_hl','neb_hl','contract_hl','total_hl'] as $k) {
        $annualCards[$k] = round($annualCards[$k], 1);
    }

    $annualView = [
        'annual_monthly_stub' => true,
        'cards'               => $annualCards,
        'is_partial'          => false,
        'is_illustrative'     => false,
        'yoy'                 => null,
        'yoy_label'           => null,
        'recipe_month'        => [],
        'recipe_quarter'      => [],
    ];

    $lastDataMonthCurrent = 0;
    if (isset($yearsData[$currentYear])) {
        $lastDataMonthCurrent = $yearsData[$currentYear]['last_month'];
    }

    // ─── YoY block: per-beer × month for kpi_year vs (kpi_year-1) ────────────
    // Cross-year beer-identity key = rr.name (NOT recipe_short_name — two contract
    // beers share short_name 'Pale Ale'; NOT sku_prefix — mostly NULL).
    // EPH vintages share one rr.name and must aggregate, so GROUP BY rr.name.
    // LATENT CAVEAT: if a future collab is renamed per vintage (different rr.name
    // each year) it would appear as separate nouveau/arrêté rows.
    // Zero such cases exist today (2026-05-31).
    $yoyKpiYear  = is_int($kpiActiveYear) ? $kpiActiveYear : $currentYear;
    $yoyPrevYear = $yoyKpiYear - 1;

    $yoyStmt = $pdo->prepare("
        SELECT
            rr.name                              AS beer_name,
            MAX(COALESCE(NULLIF(rr.recipe_short_name, ''), rr.name)) AS beer_label,
            ({$kpiBucketExpr})                   AS bucket,
            YEAR(DATE(cl.submitted_at))           AS yr,
            MONTH(DATE(cl.submitted_at))          AS mo,
            ROUND(SUM(cl.final_volume), 1)        AS hl
        {$kpiBaseJoin}
          AND YEAR(DATE(cl.submitted_at)) IN (:cy, :py)
        GROUP BY rr.name, bucket, yr, mo
        ORDER BY rr.name, yr, mo
    ");
    $yoyStmt->execute([':cy' => $yoyKpiYear, ':py' => $yoyPrevYear]);
    $yoyRawRows = $yoyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build per-beer 12-month arrays for curr + prev year
    $yoyBeerMap = [];   // name => { bucket, curr:[12], prev:[12] }
    foreach ($yoyRawRows as $r) {
        $name = $r['beer_name'];
        $yr   = (int) $r['yr'];
        $mo   = (int) $r['mo'];
        $hl   = (float) $r['hl'];
        $bk   = $r['bucket'];
        if (!isset($yoyBeerMap[$name])) {
            $yoyBeerMap[$name] = [
                // match/key on rr.name; DISPLAY the short label (consistent with
                // the recipe heatmap) so contracts read "Jasper" not "Chien Bleu - Jasper".
                'label'  => $r['beer_label'] ?: $name,
                'bucket' => $bk,
                'curr'   => array_fill(0, 12, 0.0),
                'prev'   => array_fill(0, 12, 0.0),
            ];
        }
        if ($yr === $yoyKpiYear)  $yoyBeerMap[$name]['curr'][$mo - 1] += $hl;
        if ($yr === $yoyPrevYear) $yoyBeerMap[$name]['prev'][$mo - 1] += $hl;
    }

    // Compute lastDataMonthIdx for the current/selected year
    // (0-based index of last month with any HL in kpi_year)
    $yoyLastDataMonthIdx = -1;
    foreach ($yoyBeerMap as $bd) {
        foreach ($bd['curr'] as $idx => $v) {
            if ($v > 0 && $idx > $yoyLastDataMonthIdx) {
                $yoyLastDataMonthIdx = $idx;
            }
        }
    }

    // Build the yoy.beers array sorted by currTotal desc
    $yoyBeers = [];
    foreach ($yoyBeerMap as $name => $bd) {
        $currTotal   = array_sum($bd['curr']);
        $prevTotal   = array_sum($bd['prev']);
        $pct         = ($prevTotal > 0) ? round($currTotal / $prevTotal * 100, 1) : null;
        // paceRefPrev = sum of prev[0..lastDataMonthIdx]
        $paceRefPrev = 0.0;
        if ($yoyLastDataMonthIdx >= 0) {
            for ($mi = 0; $mi <= $yoyLastDataMonthIdx; $mi++) {
                $paceRefPrev += $bd['prev'][$mi];
            }
        }
        $pacePct  = ($paceRefPrev > 0) ? round($currTotal / $paceRefPrev * 100, 1) : null;
        if ($currTotal == 0 && $prevTotal > 0) {
            $status = 'arrete';
        } elseif ($prevTotal == 0 && $currTotal > 0) {
            $status = 'nouveau';
        } else {
            $status = 'actif';
        }
        $yoyBeers[] = [
            'name'           => $name,
            'label'          => $bd['label'],
            'bucket'         => $bd['bucket'],
            'curr'           => array_map(fn($v) => round($v, 1), $bd['curr']),
            'prev'           => array_map(fn($v) => round($v, 1), $bd['prev']),
            'currTotal'      => round($currTotal, 1),
            'prevTotal'      => round($prevTotal, 1),
            'pct'            => $pct,
            'paceRefPrev'    => round($paceRefPrev, 1),
            'pacePct'        => $pacePct,
            'status'         => $status,
        ];
    }
    // Sort by currTotal desc (arrêté beers fall to bottom naturally)
    usort($yoyBeers, fn($a, $b) => $b['currTotal'] <=> $a['currTotal']);

    $yoyPayload = [
        'prevYear'         => $yoyPrevYear,
        'kpiYear'          => $yoyKpiYear,
        'lastDataMonthIdx' => $yoyLastDataMonthIdx,
        'beers'            => $yoyBeers,
    ];
    // ─── End YoY block ────────────────────────────────────────────────────────

    $kpiPayload = [
        'years'          => array_values($kpiYears),
        'active_year'    => $kpiActiveYear,
        'years_data'     => $yearsData,
        'annual'         => $annualRows,
        'annual_view'    => $annualView,
        'last_data_month'=> $lastDataMonthCurrent,
        'yoy'            => $yoyPayload,
    ];

} catch (Throwable $e) {
    $rows        = [];
    $stats       = null;
    $lastBrewday = null;
    $dbError     = $e->getMessage();
    $yearRows    = [];
    $recipeRows  = [];
    $yeastRows   = [];
    $genRows     = [];
    $kpiPayload  = ['error' => $e->getMessage()];
    $kpiActiveYear = $currentYear;
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
  <link rel="stylesheet" href="/css/wort-kpis.css?v=<?= @filemtime(__DIR__ . '/../css/wort-kpis.css') ?: time() ?>">
</head>
<body class="home wort">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main wort-main">

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- ── Tab switcher ── -->
  <nav class="wort-tabs" aria-label="Sections Wort Production" role="tablist">
    <button class="wort-tab-btn active" data-tab="brassins" role="tab" aria-selected="true"  aria-controls="wort-panel-brassins">Brassins</button>
    <button class="wort-tab-btn"        data-tab="kpis"     role="tab" aria-selected="false" aria-controls="wort-panel-kpis">KPIs / Analyse</button>
  </nav>

  <!-- ══════════════════════════════════════════
       TAB 1 — Brassins
       ══════════════════════════════════════════ -->
  <div class="wort-tab-panel active" id="wort-panel-brassins" role="tabpanel" aria-labelledby="">

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
                  $showRaw    = $shortName && strtolower(trim($shortName)) !== strtolower(trim($rawBeer));
                  $showClient = ($r["classification"] === "Contract") && !empty($r["client_name"]);

                  // --- Batch column ---
                  $batch   = $r["bd_batch"] ?? "";
                  $vintage = $r["vintage"]  ?? "";
                  $showVintage = ($vintage !== "" && strlen($batch) === 2
                                  && str_starts_with($vintage, "20" . $batch));

                  // --- CCT column ---
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

  </div><!-- /#wort-panel-brassins -->

  <!-- ══════════════════════════════════════════
       TAB 2 — KPIs / Analyse
       ══════════════════════════════════════════ -->
  <div class="wort-tab-panel" id="wort-panel-kpis" role="tabpanel" aria-labelledby="">

    <?php if ($dbError): ?>
      <div class="wort-error">
        Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
      </div>
    <?php else: ?>

    <div class="wk-wrap">

      <!-- ── Page header ─────────────────────────── -->
      <header class="wk-header">
        <div>
          <div class="wk-header__title">Production de <em>moût</em></div>
          <div class="wk-header__sub">Analytique · La Nébuleuse · KPIs annuels</div>
        </div>
        <span class="wk-header__badge">Données réelles</span>
      </header>

      <!-- ── Year selector ───────────────────────── -->
      <div class="wk-year-bar">
        <span class="wk-year-bar__label">Année</span>
        <?php foreach ($kpiPayload['years'] as $yr): ?>
          <button class="wk-year-btn<?= ($kpiActiveYear === $yr || ($kpiActiveYear === (int)$yr)) ? ' active' : '' ?>"
                  data-year="<?= htmlspecialchars((string)$yr) ?>"><?= htmlspecialchars((string)$yr) ?></button>
        <?php endforeach ?>
        <button class="wk-year-btn<?= ($kpiActiveYear === 'all') ? ' active' : '' ?>" data-year="all">Tous</button>
      </div>

      <!-- ── YTD notice (current year only) ─────── -->
      <div class="wk-ytd-note" id="wk-ytd-note" style="<?= ($kpiActiveYear === $currentYear) ? '' : 'display:none' ?>">
        Données <?= htmlspecialchars((string)$currentYear) ?> jusqu'au <?= htmlspecialchars(date('j')) ?> <?= htmlspecialchars($monthsFR[(int)date('n')] ?? '') ?> — année en cours
      </div>

      <!-- A. HEADLINE STAT CARDS -->
      <section class="wk-section">
        <div class="wk-stats" id="wk-stat-cards"></div>
      </section>

      <!-- ANNUAL TREND (shown only in "Tous" view) -->
      <section class="wk-section" id="wk-section-annual" style="<?= ($kpiActiveYear !== 'all') ? 'display:none' : '' ?>">
        <div class="wk-section__head">
          <h2 class="wk-section__title">Tendance annuelle — HL produits</h2>
          <span class="wk-section__note">Toutes années</span>
        </div>
        <div class="wk-legend">
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--core"></span>Gamme principale</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--spec"></span>Spéciales</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--contract"></span>Contrat</div>
        </div>
        <div class="wk-chart-card">
          <div class="wk-annual-wrap" id="wk-chart-annual"></div>
        </div>
      </section>

      <!-- B. Nébuleuse vs Contrat — HL par mois -->
      <section class="wk-section" id="wk-section-monthly" style="<?= ($kpiActiveYear === 'all') ? 'display:none' : '' ?>">
        <div class="wk-section__head">
          <h2 class="wk-section__title">Nébuleuse vs Contrat — HL par mois</h2>
        </div>
        <div class="wk-legend">
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--neb"></span>Nébuleuse (total)</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--contract"></span>Contrat</div>
        </div>
        <div class="wk-chart-card">
          <div class="wk-svg-wrap" id="wk-chart-neb-contract"></div>
        </div>
      </section>

      <!-- C + E in 2-col row -->
      <div class="wk-row-2" id="wk-section-row2" style="<?= ($kpiActiveYear === 'all') ? 'display:none' : '' ?>">
        <section class="wk-section" style="margin-bottom:0">
          <div class="wk-section__head">
            <h2 class="wk-section__title">Composition Nébuleuse par mois</h2>
          </div>
          <div class="wk-legend">
            <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--core"></span>Gamme principale</div>
            <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--spec"></span>Spéciales</div>
          </div>
          <div class="wk-chart-card">
            <div class="wk-svg-wrap" id="wk-chart-neb-comp"></div>
          </div>
        </section>
        <section class="wk-section" style="margin-bottom:0">
          <div class="wk-section__head">
            <h2 class="wk-section__title">Contrat par mois</h2>
            <span class="wk-section__note">Zéros réels affichés</span>
          </div>
          <div class="wk-legend">
            <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--contract"></span>Contrat (HL)</div>
          </div>
          <div class="wk-chart-card">
            <div class="wk-svg-wrap" id="wk-chart-contract"></div>
          </div>
        </section>
      </div><!-- /.wk-row-2 -->

      <!-- D. ROLLUP TRIMESTRIEL -->
      <section class="wk-section" id="wk-section-quarterly" style="<?= ($kpiActiveYear === 'all') ? 'display:none' : '' ?>">
        <div class="wk-section__head">
          <h2 class="wk-section__title">Rollup trimestriel — Total HL par trimestre</h2>
        </div>
        <div class="wk-legend">
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--core"></span>Gamme principale</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--spec"></span>Spéciales</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-legend__dot--contract"></span>Contrat</div>
        </div>
        <div class="wk-chart-card">
          <div class="wk-qtr-grid" id="wk-chart-quarterly"></div>
        </div>
      </section>

      <!-- F. CUMUL YTD NÉBULEUSE -->
      <section class="wk-section" id="wk-section-cumul" style="<?= ($kpiActiveYear === 'all') ? 'display:none' : '' ?>">
        <div class="wk-section__head">
          <h2 class="wk-section__title">Cumul YTD — Nébuleuse (HL)</h2>
        </div>
        <div class="wk-chart-card">
          <div class="wk-cumul-wrap" id="wk-chart-cumul"></div>
        </div>
      </section>

      <!-- G. PRODUCTION PAR RECETTE × MOIS / TRIMESTRE (HEATMAP) -->
      <section class="wk-section" id="wk-section-heatmap">
        <div class="wk-section__head">
          <h2 class="wk-section__title">Production par recette</h2>
          <span class="wk-section__note" id="wk-heatmap-note">HL brassés — données réelles</span>
          <div class="wk-hm-toggle">
            <button class="wk-hm-toggle-btn active" data-gran="month">Mois</button>
            <button class="wk-hm-toggle-btn"        data-gran="quarter">Trimestre</button>
          </div>
        </div>
        <div class="wk-chart-card">
          <div class="wk-heatmap-wrap" id="wk-chart-heatmap"></div>
        </div>
      </section>

      <!-- H. VS ANNÉE PRÉCÉDENTE — % du total par bière -->
      <section class="wk-section" id="wk-section-yoy-pct">
        <div class="wk-section__head">
          <h2 class="wk-section__title" id="wk-yoy-pct-title">Par bière · % du total <span id="wk-yoy-prev-year-label"><?= htmlspecialchars((string)($kpiActiveYear !== 'all' ? (int)$kpiActiveYear - 1 : $currentYear - 1)) ?></span></h2>
          <span class="wk-section__note">YTD vs année complète précédente</span>
        </div>
        <div class="wk-chart-card">
          <div class="wk-yoy-pct-wrap" id="wk-chart-yoy-pct"></div>
        </div>
      </section>

      <!-- I. COMPARATIF MENSUEL PAR BIÈRE -->
      <section class="wk-section" id="wk-section-yoy-spark">
        <div class="wk-section__head">
          <h2 class="wk-section__title" id="wk-yoy-spark-title">Comparatif mensuel par bière · <span id="wk-yoy-curr-year-label"><?= htmlspecialchars((string)($kpiActiveYear !== 'all' ? (int)$kpiActiveYear : $currentYear)) ?></span> vs <span id="wk-yoy-prev-year-label2"><?= htmlspecialchars((string)($kpiActiveYear !== 'all' ? (int)$kpiActiveYear - 1 : $currentYear - 1)) ?></span></h2>
        </div>
        <div class="wk-legend wk-yoy-legend">
          <div class="wk-legend__item"><span class="wk-legend__dot wk-yoy-dot--curr"></span><span id="wk-yoy-legend-curr"><?= htmlspecialchars((string)($kpiActiveYear !== 'all' ? (int)$kpiActiveYear : $currentYear)) ?></span> (HL brassés)</div>
          <div class="wk-legend__item"><span class="wk-legend__dot wk-yoy-dot--prev"></span><span id="wk-yoy-legend-prev"><?= htmlspecialchars((string)($kpiActiveYear !== 'all' ? (int)$kpiActiveYear - 1 : $currentYear - 1)) ?></span> (référence)</div>
        </div>
        <div class="wk-chart-card">
          <div class="wk-yoy-spark-grid" id="wk-chart-yoy-spark"></div>
        </div>
      </section>

      <p class="wk-footnote" id="wk-footnote" style="<?= ($kpiActiveYear === $currentYear) ? 'display:none' : '' ?>">
        Les Spéciales incluent les recettes ponctuelles / retirées, comptabilisées sous leur année de brassage.
      </p>

    </div><!-- /.wk-wrap -->

    <?php endif ?>

  </div><!-- /#wort-panel-kpis -->

</main>

<!-- Tooltip (shared, fixed position — lives outside main so it always floats on top) -->
<div class="wk-tooltip" id="wk-tooltip"></div>

<script>
window.WORT_KPIS = <?= json_encode($kpiPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script defer src="/js/kpi-charts.js?v=<?= @filemtime(__DIR__ . '/../js/kpi-charts.js') ?: time() ?>"></script>
<script defer src="/js/wort-kpis.js?v=<?= @filemtime(__DIR__ . '/../js/wort-kpis.js') ?: time() ?>"></script>

</body>
</html>
