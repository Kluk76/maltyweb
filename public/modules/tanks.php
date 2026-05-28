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

// --- Rack-stats year filter ---
$rackYearMin = 2023;
$rackYearMax = $currentYear;
// Default to latest year that actually has data (will be refined after DB query)
$rackYearDefault = $currentYear;
$rackYear = $rackYearDefault;
$_gry = $_GET['rack_year'] ?? '';
if (ctype_digit($_gry)) {
    $py = (int)$_gry;
    if ($py >= $rackYearMin && $py <= $rackYearMax) $rackYear = $py;
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
require_once __DIR__ . "/../../app/loss-metrics.php";

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
          -- LEGACY-ONLY (v2 cutover blocker, 2026-05-24): bd_brewing_timings_v2.start_ferm
          -- is 100% NULL — the fermentation-start timestamp was not carried into v2
          -- (v2 populates brew_start/brew_end, a DIFFERENT event). Every fermentation
          -- duration / CC-offset calc on this page keys off start_ferm, so these timings
          -- reads stay on bd_brewing_timings until start_ferm is populated in v2.
          SELECT beer, batch, MAX(STR_TO_DATE(start_ferm, '%d.%m.%Y %H:%i:%s')) AS ferm_start_dt
          FROM bd_brewing_timings
          WHERE start_ferm IS NOT NULL AND beer IS NOT NULL AND batch IS NOT NULL
          GROUP BY beer, batch
        ),
        cc AS (
          -- bd_fermenting → bd_fermenting_v2: ColdCrash rows carry the 'PREFIX BATCH'
          -- text in beer_raw (event_type discriminator replaces the beers_to_cold_crash col).
          -- GROUP BY the full derived expressions, NOT the aliases: bd_fermenting_v2 has
          -- a physical `batch` column, so GROUP BY batch would bind to it and trip
          -- ONLY_FULL_GROUP_BY on the beer_raw-derived prefix.
          SELECT
            TRIM(SUBSTRING_INDEX(beer_raw, ' ', -1)) AS batch,
            TRIM(SUBSTRING(beer_raw, 1, LENGTH(beer_raw) - LENGTH(SUBSTRING_INDEX(beer_raw, ' ', -1)) - 1)) AS prefix,
            MIN(event_date) AS cc_date
          FROM bd_fermenting_v2
          WHERE event_type = 'ColdCrash' AND beer_raw IS NOT NULL AND beer_raw != ''
            AND event_date IS NOT NULL
          GROUP BY TRIM(SUBSTRING(beer_raw, 1, LENGTH(beer_raw) - LENGTH(SUBSTRING_INDEX(beer_raw, ' ', -1)) - 1)),
                   TRIM(SUBSTRING_INDEX(beer_raw, ' ', -1))
        ),
        cc_canon AS (
          -- Prefix → canonical recipe name via ref_recipe_aliases (single source of truth).
          -- JOIN is on alias column; only aliases that match a Core active recipe are used.
          SELECT
            rr.name AS beer,
            cc.batch, cc.cc_date
          FROM cc
          JOIN ref_recipe_aliases ra
            ON ra.alias COLLATE utf8mb4_unicode_ci = cc.prefix COLLATE utf8mb4_unicode_ci
          JOIN ref_recipes rr
            ON rr.id = ra.recipe_id
           AND rr.subtype = 'Core'
           AND rr.is_active = 1
           AND rr.vintage = ''
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
          -- bd_fermenting → bd_fermenting_v2: ColdCrash rows, 'PREFIX BATCH' in beer_raw.
          -- GROUP BY full expressions (physical `batch` column collides with the alias).
          SELECT
            TRIM(SUBSTRING(beer_raw, 1, LENGTH(beer_raw) - LENGTH(SUBSTRING_INDEX(beer_raw, ' ', -1)) - 1)) AS prefix,
            TRIM(SUBSTRING_INDEX(beer_raw, ' ', -1)) AS batch,
            MIN(event_date) AS cc_date
          FROM bd_fermenting_v2
          WHERE event_type = 'ColdCrash' AND beer_raw IS NOT NULL AND beer_raw != ''
            AND event_date IS NOT NULL
          GROUP BY TRIM(SUBSTRING(beer_raw, 1, LENGTH(beer_raw) - LENGTH(SUBSTRING_INDEX(beer_raw, ' ', -1)) - 1)),
                   TRIM(SUBSTRING_INDEX(beer_raw, ' ', -1))
        ),
        reads_with_parse AS (
          -- Reads rows, 'PREFIX BATCH' in beer_raw (event_type='Reads').
          SELECT
            TRIM(SUBSTRING(beer_raw, 1, LENGTH(beer_raw) - LENGTH(SUBSTRING_INDEX(beer_raw, ' ', -1)) - 1)) AS prefix,
            TRIM(SUBSTRING_INDEX(beer_raw, ' ', -1)) AS batch,
            event_date, gravity, ph
          FROM bd_fermenting_v2
          WHERE event_type = 'Reads' AND beer_raw IS NOT NULL AND beer_raw != ''
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
          -- Derive prefix→canonical mapping from ref_recipe_aliases (single source of truth).
          -- Scope: Core active recipes with a non-empty vintage='' row (base recipe).
          SELECT ra.alias AS prefix, rr.name AS beer
            FROM ref_recipe_aliases ra
            JOIN ref_recipes rr
              ON rr.id = ra.recipe_id
             AND rr.subtype = 'Core'
             AND rr.is_active = 1
             AND rr.vintage = ''
           WHERE ra.alias REGEXP '^[A-Z]{2,4}[0-9]?$'
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
            'SELECT event_date FROM bd_brewing_brewday_v2
             WHERE beer = :beer AND batch = :batch
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

        // Use centralised resolver (recipe-resolver.php) for the canonical → short-code
        // reverse lookup so the prefix is always in sync with ref_recipe_aliases.
        // Falls back to TankSimulator::beerPrefix for simulator-internal names
        // (Div.Blanche etc.) that differ from ref_recipes canonical names.
        $beerPrefix = canonical_to_short_code($pdo, $beer)
            ?? canonical_to_short_code($pdo, $simRow['raw_beer'] ?? $beer)
            ?? TankSimulator::beerPrefix($beer);
        $exactMatch = $beerPrefix . ' ' . $batch;
        $withTrail  = $beerPrefix . ' ' . $batch . ' %';

        $gravStmt = $pdo->prepare(
            'SELECT gravity AS last_gravity, event_date AS last_gravity_date
             FROM bd_fermenting_v2
             WHERE event_type = "Reads"
               AND (beer_raw = :exact OR beer_raw LIKE :withTrail)
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
             FROM bd_fermenting_v2
             WHERE event_type = "ColdCrash"
               AND (beer_raw = :exact OR beer_raw LIKE :withTrail)
               AND beer_raw IS NOT NULL AND beer_raw != \'\''
        );
        $ccStmt->execute([
            ':exact'     => $exactMatch,
            ':withTrail' => $withTrail,
        ]);
        $ccRow = $ccStmt->fetch() ?: [];

        // LEGACY-ONLY: bd_brewing_timings_v2.start_ferm is 100% NULL (see fer_end note).
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
            'SELECT yeast AS bd_yeast, yeast_gen AS bd_yeast_gen
             FROM bd_brewing_brewday_v2
             WHERE beer = :beer AND batch = :batch
             ORDER BY event_date DESC LIMIT 1'
        );
        $yeastStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $yeastRow = $yeastStmt->fetch() ?: [];

        // Re-pitch count: how many times this batch's yeast has been used to pitch another batch
        $pitchKey     = $beerPrefix . ' ' . $batch;
        $repitchStmt  = $pdo->prepare(
            'SELECT COUNT(*) FROM bd_brewing_brewday_v2 WHERE pitched_from = :src'
        );
        $repitchStmt->execute([':src' => $pitchKey]);
        $repitchCount = (int)$repitchStmt->fetchColumn();

        // Original gravity — latest cooling row of this batch.
        // bd_brewing_cooling folded into bd_brewing_gravity_v2 WHERE event_type='Cooling'.
        $ogStmt = $pdo->prepare(
            'SELECT MAX(final_gravity) AS og
             FROM bd_brewing_gravity_v2
             WHERE event_type = "Cooling" AND beer = :beer AND batch = :batch'
        );
        $ogStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
        $ogRow = $ogStmt->fetch() ?: [];

        // Latest pH from fermenting reads
        $phStmt = $pdo->prepare(
            'SELECT ph, event_date AS ph_date
             FROM bd_fermenting_v2
             WHERE event_type = "Reads"
               AND (beer_raw = :exact OR beer_raw LIKE :withTrail)
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


    // ---- Per-CCT detail bundles for modal popup ----
    $cctDetails = [];
    foreach ($cctOccMap as $num => $occ) {
        try {
            $rawBeer    = $occ['bd_beer_raw'] ?? ($occ['bd_beer'] ?? '');
            $batch      = $occ['bd_batch'] ?? '';
            $beerCanon  = $occ['bd_beer']  ?? '';

            $beerPrefix = canonical_to_short_code($pdo, $beerCanon)
                ?? canonical_to_short_code($pdo, $rawBeer)
                ?? TankSimulator::beerPrefix($beerCanon);
            $exactMatch = $beerPrefix . ' ' . $batch;
            $withTrail  = $beerPrefix . ' ' . $batch . ' %';

            $hasColdCrash = !empty($occ['last_cc_date']);
            $state        = $hasColdCrash ? 'cold' : 'ferment';
            $capHl        = 0.0;
            foreach ($cctRef as $r) {
                if ((int)$r['number'] === $num) {
                    $capHl = (float)$r['capacity_hl'];
                    break;
                }
            }
            $volHl = (float)($occ['volume_hl'] ?? 0);

            // Days in fermentation
            $daysIn = null;
            $fermStartDT = $occ['ferm_start_dt'] ?? null;
            if ($fermStartDT === null) {
                // fetch from DB
                // LEGACY-ONLY: bd_brewing_timings_v2.start_ferm is 100% NULL (see fer_end note).
                $fsStmt = $pdo->prepare(
                    'SELECT MAX(STR_TO_DATE(start_ferm, \'%d.%m.%Y %H:%i:%s\')) AS fsd
                     FROM bd_brewing_timings
                     WHERE beer = :beer AND batch = :batch AND start_ferm IS NOT NULL'
                );
                $fsStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
                $fsRow = $fsStmt->fetch();
                $fermStartDT = $fsRow['fsd'] ?? null;
            }
            if ($fermStartDT !== null) {
                $daysIn = (int)(new DateTimeImmutable($fermStartDT))->diff($asOfDT)->days;
            }

            // CC offsets
            $ccEstimated = null;
            $avgDays     = $avgFermDaysByBeer[$rawBeer] ?? null;
            if ($fermStartDT !== null && $avgDays !== null) {
                $ccEstimated = $avgDays;
            }
            $ccActual = null;
            if (!empty($occ['last_cc_date']) && $fermStartDT !== null) {
                $ccActual = (int)(new DateTimeImmutable($fermStartDT))
                    ->diff(new DateTimeImmutable($occ['last_cc_date']))->days;
            }

            // Metrics
            $ogVal      = $occ['og']           ?? null;
            $fgCurrent  = $occ['last_gravity'] ?? null;
            $phCurrent  = $occ['last_ph']      ?? null;
            $avgFinR    = $avgFinalsByBeer[$rawBeer] ?? null;
            $targetFg   = $avgFinR['grav'] ?? null;
            $targetPh   = $avgFinR['ph']   ?? null;
            $attenPct   = null;
            $progressPct = null;
            if ($ogVal !== null && $fgCurrent !== null && (float)$ogVal > 0) {
                $attenPct = (int)round(((float)$ogVal - (float)$fgCurrent) / (float)$ogVal * 100.0);
            }
            if ($ogVal !== null && $fgCurrent !== null && $targetFg !== null
                && (float)$ogVal > (float)$targetFg) {
                $progressPct = (int)round(
                    min(1.0, ((float)$ogVal - (float)$fgCurrent) / ((float)$ogVal - (float)$targetFg)) * 100.0
                );
            }

            // Yeast
            $pitchedFromStmt = $pdo->prepare(
                'SELECT pitched_from AS bd_pitched_from FROM bd_brewing_brewday_v2
                 WHERE beer = :beer AND batch = :batch
                 ORDER BY event_date DESC LIMIT 1'
            );
            $pitchedFromStmt->execute([':beer' => $rawBeer, ':batch' => $batch]);
            $pitchedFromRow = $pitchedFromStmt->fetch() ?: [];

            // Current reads
            $readsStmt = $pdo->prepare(
                'SELECT event_date, gravity, ph
                 FROM bd_fermenting_v2
                 WHERE event_type = "Reads"
                   AND (beer_raw = :exact OR beer_raw LIKE :trail)
                   AND (gravity IS NOT NULL OR ph IS NOT NULL)
                 ORDER BY event_date ASC'
            );
            $readsStmt->execute([':exact' => $exactMatch, ':trail' => $withTrail]);
            $rawReads = $readsStmt->fetchAll(PDO::FETCH_ASSOC);

            $currentReads = [];
            if ($fermStartDT !== null) {
                // Day-0 read from cooling OG
                if ($ogVal !== null) {
                    $currentReads[] = ['day' => 0, 'fg' => (float)$ogVal, 'ph' => null];
                }
                foreach ($rawReads as $rr) {
                    $day = (int)(new DateTimeImmutable($fermStartDT))
                        ->diff(new DateTimeImmutable($rr['event_date']))->days;
                    $entry = ['day' => $day];
                    $entry['fg'] = $rr['gravity'] !== null ? (float)$rr['gravity'] : null;
                    $entry['ph'] = $rr['ph']      !== null ? (float)$rr['ph']      : null;
                    if ($entry['fg'] !== null || $entry['ph'] !== null) {
                        $currentReads[] = $entry;
                    }
                }
                // Deduplicate by day (keep last)
                $dedupReads = [];
                foreach ($currentReads as $cr) {
                    $dedupReads[$cr['day']] = $cr;
                }
                ksort($dedupReads);
                $currentReads = array_values($dedupReads);
            }

            // Historical batches (up to 5 prior)
            $histBatchStmt = $pdo->prepare(
                'SELECT batch AS bd_batch FROM bd_brewing_brewday_v2
                 WHERE beer = :beer AND batch != :cur
                 ORDER BY event_date DESC LIMIT 5'
            );
            $histBatchStmt->execute([':beer' => $rawBeer, ':cur' => $batch]);
            $histBatches = $histBatchStmt->fetchAll(PDO::FETCH_COLUMN);

            $historical = [];
            foreach ($histBatches as $hb) {
                $hPrefix    = $beerPrefix . ' ' . $hb;
                $hWithTrail = $hPrefix . ' %';

                // LEGACY-ONLY: bd_brewing_timings_v2.start_ferm is 100% NULL (see fer_end note).
                $hFermStmt = $pdo->prepare(
                    'SELECT MAX(STR_TO_DATE(start_ferm, \'%d.%m.%Y %H:%i:%s\')) AS fsd
                     FROM bd_brewing_timings
                     WHERE beer = :beer AND batch = :batch AND start_ferm IS NOT NULL'
                );
                $hFermStmt->execute([':beer' => $rawBeer, ':batch' => $hb]);
                $hFermRow = $hFermStmt->fetch();
                $hFermStart = $hFermRow['fsd'] ?? null;
                if ($hFermStart === null) continue;

                $hReadsStmt = $pdo->prepare(
                    'SELECT event_date, gravity, ph
                     FROM bd_fermenting_v2
                     WHERE event_type = "Reads"
                       AND (beer_raw = :exact OR beer_raw LIKE :trail)
                       AND (gravity IS NOT NULL OR ph IS NOT NULL)
                     ORDER BY event_date ASC'
                );
                $hReadsStmt->execute([':exact' => $hPrefix, ':trail' => $hWithTrail]);
                $hRawReads = $hReadsStmt->fetchAll(PDO::FETCH_ASSOC);

                $hReads = [];
                foreach ($hRawReads as $hr) {
                    $day = (int)(new DateTimeImmutable($hFermStart))
                        ->diff(new DateTimeImmutable($hr['event_date']))->days;
                    $entry = ['day' => $day];
                    $entry['fg'] = $hr['gravity'] !== null ? (float)$hr['gravity'] : null;
                    $entry['ph'] = $hr['ph']      !== null ? (float)$hr['ph']      : null;
                    if ($entry['fg'] !== null || $entry['ph'] !== null) {
                        $hReads[] = $entry;
                    }
                }
                $dedupH = [];
                foreach ($hReads as $hr) { $dedupH[$hr['day']] = $hr; }
                ksort($dedupH);
                $hReads = array_values($dedupH);

                // CC day
                $hCcStmt = $pdo->prepare(
                    'SELECT MIN(event_date) AS cc_date
                     FROM bd_fermenting_v2
                     WHERE event_type = "ColdCrash"
                       AND (beer_raw = :exact OR beer_raw LIKE :trail)
                       AND beer_raw IS NOT NULL AND beer_raw != \'\''
                );
                $hCcStmt->execute([':exact' => $hPrefix, ':trail' => $hWithTrail]);
                $hCcRow    = $hCcStmt->fetch();
                $hCcDay    = null;
                if (!empty($hCcRow['cc_date'])) {
                    $hCcDay = (int)(new DateTimeImmutable($hFermStart))
                        ->diff(new DateTimeImmutable($hCcRow['cc_date']))->days;
                }

                if (!empty($hReads)) {
                    $historical[] = [
                        'batch'  => $hb,
                        'cc_day' => $hCcDay,
                        'reads'  => $hReads,
                    ];
                }
            }

            // Brewdate formatted
            $brewdateFormatted = !empty($occ['brewday_date'])
                ? sprintf('%d %s', (int)(new DateTimeImmutable($occ['brewday_date']))->format('j'),
                    $monthsFR[(int)(new DateTimeImmutable($occ['brewday_date']))->format('n')])
                : null;

            // Beer classification display
            $beerClassification = $occ['classification'] ?? null;
            $clientName         = $occ['client_name']    ?? null;
            $classificationDisplay = $clientName ?: $beerClassification;

            $cctDetails[$num] = [
                'cct'                  => $num,
                'capacity_hl'          => $capHl,
                'volume_hl'            => $volHl,
                'state'                => $state,
                'beer'                 => $occ['recipe_short_name'] ?? $beerCanon,
                'beer_classification'  => $classificationDisplay,
                'batch'                => $batch,
                'brewdate'             => $brewdateFormatted,
                'days_in'              => $daysIn,
                'cc_estimated'         => $ccEstimated,
                'cc_actual'            => $ccActual,
                'metrics'              => [
                    'og'              => $ogVal !== null ? (float)$ogVal : null,
                    'fg_current'      => $fgCurrent !== null ? (float)$fgCurrent : null,
                    'ph_current'      => $phCurrent !== null ? (float)$phCurrent : null,
                    'target_fg'       => $targetFg  !== null ? (float)$targetFg  : null,
                    'target_ph'       => $targetPh  !== null ? (float)$targetPh  : null,
                    'attenuation_pct' => $attenPct,
                    'progress_pct'    => $progressPct,
                ],
                'yeast'                => [
                    'strain'        => $occ['yeast']         ?? null,
                    'generation'    => $occ['yeast_gen'] !== null ? (int)$occ['yeast_gen'] : null,
                    'repitch_count' => (int)($occ['repitch_count'] ?? 0),
                    'pitched_from'  => $pitchedFromRow['bd_pitched_from'] ?? null,
                    'pitched_into'  => null,
                    'pcr'           => null,
                ],
                'current_reads'        => $currentReads,
                'historical'           => $historical,
            ];
        } catch (Throwable $detailEx) {
            // A bad CCT doesn't break the page — skip its detail bundle silently
        }
    }

    // ---- Racking KPIs: last-30-day operator strip ----
    $rackKpiStmt = $pdo->query("
        SELECT
          COUNT(*) AS racks_30d,
          COALESCE(SUM(racked_vol_hl), 0) AS vol_30d,
          SUM(CASE WHEN YEARWEEK(event_date, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS racks_this_week
        FROM bd_racking_v2
        WHERE is_tombstoned = 0
          AND event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $rackKpi = $rackKpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ---- Racking stats: available years ----
    $rackYearsStmt = $pdo->query("
        SELECT DISTINCT YEAR(event_date) AS yr
        FROM bd_racking_v2
        WHERE is_tombstoned = 0 AND event_date IS NOT NULL
        ORDER BY yr DESC
    ");
    $rackYears = array_column($rackYearsStmt->fetchAll(PDO::FETCH_ASSOC), 'yr');
    // If no GET param was set, default to the latest year that has data
    if (!ctype_digit($_GET['rack_year'] ?? '')) {
        $rackYear = !empty($rackYears) ? (int)$rackYears[0] : $currentYear;
    }

    // ---- Racking stats: per-beer historical table for selected year ----
    // Nébuleuse and contract rows are GROUP'd separately via their own FK columns.
    // Both NULL FKs (id=7, empty row) and is_tombstoned=1 rows are excluded.
    $rackStatsStmt = $pdo->prepare("
        SELECT
          (r.neb_recipe_id_fk IS NOT NULL)                                     AS is_neb,
          COALESCE(rr_neb.recipe_short_name, rr_con.recipe_short_name,
                   r.neb_beer, r.contract_beer)                                AS short_name,
          COUNT(*)                                                              AS n_racks,
          COALESCE(SUM(r.racked_vol_hl), 0)                                    AS vol_hl,
          AVG(r.bbt_co2)                                                       AS avg_co2,
          AVG(r.bbt_o2)                                                        AS avg_o2
        FROM bd_racking_v2 r
        LEFT JOIN ref_recipes rr_neb ON rr_neb.id = r.neb_recipe_id_fk
        LEFT JOIN ref_recipes rr_con ON rr_con.id = r.contract_recipe_id_fk
        WHERE r.is_tombstoned = 0
          AND YEAR(r.event_date) = :year
          AND (r.neb_recipe_id_fk IS NOT NULL OR r.contract_recipe_id_fk IS NOT NULL)
        GROUP BY r.neb_recipe_id_fk, r.contract_recipe_id_fk, is_neb, short_name
        ORDER BY is_neb DESC, vol_hl DESC
    ");
    $rackStatsStmt->execute([':year' => $rackYear]);
    $rackStatsRows = $rackStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Max volume across all rows — for CSS bar normalisation (largest = 100%)
    $rackMaxVol = 0.0;
    foreach ($rackStatsRows as $rsr) {
        $rackMaxVol = max($rackMaxVol, (float)$rsr['vol_hl']);
    }

    // ---- Pertes par batch (C8) — per-beer 6-month rolling averages ----
    // ppb_view: sanitized to whitelist before use to prevent header injection.
    $ppbViewAllowed = ['core', 'collab-eph', 'contract'];
    $ppbViewRaw     = $_GET['ppb_view'] ?? '';
    $ppbView        = in_array($ppbViewRaw, $ppbViewAllowed, true) ? $ppbViewRaw : 'core';

    $perBeerMetrics   = loss_metrics_per_beer($pdo, ['view' => $ppbView]);
    $lossThresholds   = loss_thresholds($pdo);
    $lossRackingFloor = loss_racking_data_floor($pdo);

    $dbError = null;

} catch (Throwable $e) {
    $cctRef          = [];
    $cctOccMap       = [];
    $fermStatsRows   = [];
    $avgFinalsByBeer = [];
    $totalCct        = 0;
    $occupiedCct     = 0;
    $hlInCcts        = 0.0;
    $rackKpi             = [];
    $rackYears           = [];
    $rackStatsRows       = [];
    $rackMaxVol          = 0.0;
    $perBeerMetrics   = [];
    $lossThresholds   = [];
    $lossRackingFloor = null;
    $dbError          = $e->getMessage();
    $cctDetails       = [];
    $ppbView          = 'core';
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
  <link rel="stylesheet" href="/css/tankboard-pertes.css?v=<?= @filemtime(__DIR__ . '/../css/tankboard-pertes.css') ?: time() ?>">
  <script src="/js/cct-detail-modal.js?v=<?= @filemtime(__DIR__ . '/../js/cct-detail-modal.js') ?: time() ?>" defer></script>
  <script src="/js/tankboard-pertes.js?v=<?= @filemtime(__DIR__ . '/../js/tankboard-pertes.js') ?: time() ?>" defer></script>
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

      ?>
      <?php if ($occ !== null && !$isMaint): ?>
        <button class="tank-card-btn tank-card <?= $stateClass ?>" data-cct="<?= $num ?>" type="button" aria-label="Détails CCT <?= $num ?>">
      <?php else: ?>
        <div class="tank-card <?= $stateClass ?>">
      <?php endif ?>
        <?php if ($isMaint): ?>
          <div class="tank-card__svg">
            <?= svg_cct(0.0, 'maint', $num, '', 'fill', []) ?>
          </div>
          <div class="tank-card__info">
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
            <span class="tank-badge tank-badge--maint"><?= htmlspecialchars($status) ?></span>
          </div>

        <?php elseif ($occ === null): ?>
          <div class="tank-card__svg">
            <?= svg_cct(0.0, '', $num, '', 'fill', []) ?>
          </div>
          <div class="tank-card__info">
            <span class="tank-card__empty-label">—</span>
            <span class="tank-card__cap tanks-mute"><?= htmlspecialchars(number_format($capHl, 0)) ?> HL</span>
          </div>

        <?php else:
          $beerLabel  = htmlspecialchars($occ['recipe_short_name'] ?? $occ['bd_beer'] ?? '');
          $batchLabel = htmlspecialchars($occ['bd_batch'] ?? '');
        ?>
          <div class="tank-card__svg">
            <?= svg_cct(
              $fillRatio,
              $svgState,
              $num,
              (string)($occ['bd_beer'] ?? ''),
              'fill',
              [
                  'og'            => $occ['og']            ?? null,
                  'ph'            => $occ['last_ph']       ?? null,
                  'yeast'         => $occ['yeast']         ?? null,
                  'yeast_gen'     => $occ['yeast_gen']     ?? null,
                  'repitch_count' => $occ['repitch_count'] ?? 0,
              ]
            ) ?>
          </div>
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
            <span class="tank-card__batch tanks-mono"><?= $batchLabel ?></span>
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

      <?php if ($occ !== null && !$isMaint): ?>
        </button>
      <?php else: ?>
        </div>
      <?php endif ?>
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
        <?php if (isset($_GET['year']))  : ?><input type="hidden" name="year"  value="<?= htmlspecialchars($_GET['year'])  ?>"><?php endif ?>
        <?php if (isset($_GET['month'])) : ?><input type="hidden" name="month" value="<?= htmlspecialchars($_GET['month']) ?>"><?php endif ?>
        <?php if (isset($_GET['day']))   : ?><input type="hidden" name="day"   value="<?= htmlspecialchars($_GET['day'])   ?>"><?php endif ?>
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


  <!-- ================================================================
       Racking / Transferts — KPI strip + historical per-beer table
       Data source: bd_racking_v2 (is_tombstoned=0 always).
       Nébuleuse (neb_recipe_id_fk) and contract (contract_recipe_id_fk)
       rows are NEVER merged — they remain separate table rows.
       avg_speed / destination-split are intentionally excluded (data gaps).
  ================================================================ -->

  <!-- Racking operator KPI strip (last 30 days) -->
  <section class="wort-kpis rack-kpis" aria-label="KPI Transferts 30 jours">
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= (int)($rackKpi['racks_30d'] ?? 0) ?></span>
      <span class="wort-kpi__label">Transferts · 30 jours</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= $rackKpi['vol_30d'] > 0 ? number_format((float)$rackKpi['vol_30d'], 1) : '—' ?></span>
      <span class="wort-kpi__label">HL transférés · 30 jours</span>
    </div>
    <div class="wort-kpi">
      <span class="wort-kpi__num"><?= (int)($rackKpi['racks_this_week'] ?? 0) ?></span>
      <span class="wort-kpi__label">Transferts · semaine en cours</span>
    </div>
  </section>

  <!-- Racking historical per-beer table -->
  <section class="tanks-section rack-stats" aria-label="Statistiques de transfert par bière">
    <div class="wort-section__head rack-stats__head">
      <h2 class="tanks-section__title">
        Transferts par bière
        <span class="tanks-section__tag">Rack</span>
      </h2>
      <form class="rack-stats__year-form" method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
        <?php if (isset($_GET['year']))      : ?><input type="hidden" name="year"      value="<?= htmlspecialchars($_GET['year'])      ?>"><?php endif ?>
        <?php if (isset($_GET['month']))     : ?><input type="hidden" name="month"     value="<?= htmlspecialchars($_GET['month'])     ?>"><?php endif ?>
        <?php if (isset($_GET['day']))       : ?><input type="hidden" name="day"       value="<?= htmlspecialchars($_GET['day'])       ?>"><?php endif ?>
        <?php if (isset($_GET['ferm_year'])) : ?><input type="hidden" name="ferm_year" value="<?= htmlspecialchars($_GET['ferm_year']) ?>"><?php endif ?>
        <label class="rack-stats__year-label" for="rs-year">Année</label>
        <select id="rs-year" name="rack_year" onchange="this.form.submit()">
          <?php foreach ($rackYears as $ry): ?>
            <option value="<?= (int)$ry ?>"<?= (int)$ry === $rackYear ? ' selected' : '' ?>><?= (int)$ry ?></option>
          <?php endforeach ?>
          <?php if (empty($rackYears)): ?>
            <option value="<?= $rackYear ?>" selected><?= $rackYear ?></option>
          <?php endif ?>
        </select>
      </form>
    </div>

    <?php if (empty($rackStatsRows)): ?>
      <p class="rack-stats__empty">Aucun transfert pour <?= $rackYear ?>.</p>
    <?php else: ?>

    <?php
      // Split rows into Nébuleuse vs Contract for distinct rendering
      $rackNeb = array_filter($rackStatsRows, fn($r) => (bool)(int)$r['is_neb']);
      $rackCon = array_filter($rackStatsRows, fn($r) => !(bool)(int)$r['is_neb']);
    ?>

    <div class="rack-stats__wrap">
      <table class="rack-stats__table" aria-label="Volume transféré par recette">
        <caption class="rack-stats__caption">CCT → BBT · transferts <?= $rackYear ?></caption>
        <thead>
          <tr>
            <th scope="col" class="rack-stats__th rack-stats__th--beer">Bière</th>
            <th scope="col" class="rack-stats__th rack-stats__th--n">Lots</th>
            <th scope="col" class="rack-stats__th rack-stats__th--vol">Vol. (HL)</th>
            <th scope="col" class="rack-stats__th rack-stats__th--co2">CO₂ moy.</th>
            <th scope="col" class="rack-stats__th rack-stats__th--o2">O₂ moy.</th>
            <th scope="col" class="rack-stats__th rack-stats__th--bar"><span class="rack-stats__th-sr">Volume relatif</span></th>
          </tr>
        </thead>
        <tbody>

        <?php if (!empty($rackNeb)): ?>
          <tr class="rack-stats__group-header">
            <td colspan="6" class="rack-stats__group-label">Nébuleuse</td>
          </tr>
          <?php foreach ($rackNeb as $rsr):
            $volHl    = (float)$rsr['vol_hl'];
            $barPct   = $rackMaxVol > 0 ? round($volHl / $rackMaxVol * 100) : 0;
            $avgCo2   = $rsr['avg_co2'] !== null ? number_format((float)$rsr['avg_co2'], 2) : '—';
            $avgO2    = $rsr['avg_o2']  !== null ? number_format((float)$rsr['avg_o2'],  3) : '—';
            $beerLabel = htmlspecialchars($rsr['short_name'] ?: '—');
          ?>
          <tr class="rack-stats__row">
            <td class="rack-stats__td rack-stats__td--beer"><?= $beerLabel ?></td>
            <td class="rack-stats__td rack-stats__td--n"><?= (int)$rsr['n_racks'] ?></td>
            <td class="rack-stats__td rack-stats__td--vol"><?= number_format($volHl, 1) ?><span class="rack-stats__unit"> HL</span></td>
            <td class="rack-stats__td rack-stats__td--co2"><?= $avgCo2 ?><span class="rack-stats__unit"> g/L</span></td>
            <td class="rack-stats__td rack-stats__td--o2"><?= $avgO2 ?><span class="rack-stats__unit"> ppb</span></td>
            <td class="rack-stats__td rack-stats__td--bar">
              <div class="rack-stats__bar-wrap" title="<?= number_format($volHl, 1) ?> HL · <?= (int)$rsr['n_racks'] ?> transfert<?= (int)$rsr['n_racks'] !== 1 ? 's' : '' ?>">
                <span class="rack-stats__bar-track">
                  <span class="rack-stats__bar-fill" style="--vol-pct:<?= $barPct ?>%"></span>
                </span>
              </div>
            </td>
          </tr>
          <?php endforeach ?>
        <?php endif ?>

        <?php if (!empty($rackCon)): ?>
          <tr class="rack-stats__group-header rack-stats__group-header--contract">
            <td colspan="6" class="rack-stats__group-label rack-stats__group-label--contract">Contrat</td>
          </tr>
          <?php foreach ($rackCon as $rsr):
            $volHl    = (float)$rsr['vol_hl'];
            $barPct   = $rackMaxVol > 0 ? round($volHl / $rackMaxVol * 100) : 0;
            $avgCo2   = $rsr['avg_co2'] !== null ? number_format((float)$rsr['avg_co2'], 2) : '—';
            $avgO2    = $rsr['avg_o2']  !== null ? number_format((float)$rsr['avg_o2'],  3) : '—';
            $beerLabel = htmlspecialchars($rsr['short_name'] ?: '—');
          ?>
          <tr class="rack-stats__row rack-stats__row--contract">
            <td class="rack-stats__td rack-stats__td--beer"><?= $beerLabel ?></td>
            <td class="rack-stats__td rack-stats__td--n"><?= (int)$rsr['n_racks'] ?></td>
            <td class="rack-stats__td rack-stats__td--vol"><?= number_format($volHl, 1) ?><span class="rack-stats__unit"> HL</span></td>
            <td class="rack-stats__td rack-stats__td--co2"><?= $avgCo2 ?><span class="rack-stats__unit"> g/L</span></td>
            <td class="rack-stats__td rack-stats__td--o2"><?= $avgO2 ?><span class="rack-stats__unit"> ppb</span></td>
            <td class="rack-stats__td rack-stats__td--bar">
              <div class="rack-stats__bar-wrap" title="<?= number_format($volHl, 1) ?> HL · <?= (int)$rsr['n_racks'] ?> transfert<?= (int)$rsr['n_racks'] !== 1 ? 's' : '' ?>">
                <span class="rack-stats__bar-track">
                  <span class="rack-stats__bar-fill" style="--vol-pct:<?= $barPct ?>%"></span>
                </span>
              </div>
            </td>
          </tr>
          <?php endforeach ?>
        <?php endif ?>

        </tbody>
      </table>
    </div>
    <?php endif ?>
  </section>


  <!-- ================================================================
       Pertes par batch (C8) — per-beer 6-month rolling averages.
       Data source: app/loss-metrics.php — loss_metrics_per_beer().
       Incomplete batches excluded from averages entirely.
  ================================================================ -->
  <?php
    // ── helpers for number formatting ──────────────────────────────────────────
    function ppb_fmt_pct(float $val): string {
        $s = number_format(abs($val), 1);
        return ($val < 0 ? '−' : '') . $s;
    }

    function ppb_fmt_date(string $dateStr, array $monthsFR): string {
        $ts = strtotime($dateStr);
        if ($ts === false) return htmlspecialchars($dateStr);
        return sprintf('%d %s %s', (int)date('j', $ts), $monthsFR[(int)date('n', $ts)], date('Y', $ts));
    }

    /**
     * Render a single percentage cell (td) with appropriate class and pill.
     * All rendered rows are complete-batch aggregates — $flagged drives Seuil pill.
     *
     * @param float|null $val        The percentage value (can be negative).
     * @param bool       $flagged    Should the Seuil pill appear?
     * @param bool       $isTotalEff Is this the "Vs effectif" column (extra class + oddity semantics).
     *                               loss_vs_effectif_pct < 0 means packaged > cast_out — data oddity.
     *                               loss_vs_nominal_pct < 0 means packaged > nominal — yield bonus.
     * @return string HTML for the <td>.
     */
    function ppb_render_pct_cell(
        ?float $val,
        bool $flagged,
        bool $isTotalEff = false
    ): string {
        $extraClass = $isTotalEff ? ' ppb-td--total-eff' : '';

        if ($val === null) {
            return sprintf(
                '<td class="ppb-td ppb-td--pct%s"><span class="ppb-pct--null">—</span></td>',
                $extraClass
            );
        }

        $tdClass = '';
        $pill    = '';

        if ($val < 0) {
            if ($isTotalEff) {
                // Average loss_vs_effectif < 0: packaged > cast_out across batches — data oddity
                $tdClass = 'ppb-td--oddity';
                $pill    = '<span class="ppb-pill ppb-pill--oddity">Données</span>';
            } else {
                // Negative stage or nominal-total average = yield bonus
                $tdClass = 'ppb-td--bonus';
                $pill    = '<span class="ppb-pill ppb-pill--bonus">+Rendement</span>';
            }
        } elseif ($flagged) {
            $tdClass = 'ppb-td--flagged';
            $pill    = '<span class="ppb-pill ppb-pill--warn">Seuil</span>';
        }

        return sprintf(
            '<td class="ppb-td ppb-td--pct %s%s">%s<span class="ppb-unit"> %%</span>%s</td>',
            htmlspecialchars($tdClass),
            $extraClass,
            htmlspecialchars(ppb_fmt_pct($val)),
            $pill
        );
    }

    // Build threshold caption string
    $ppbCaption = 'Moyennes des 6 derniers mois, brassins complets uniquement (les saisies incomplètes sont exclues du calcul).';
    if (!empty($lossThresholds)) {
        $ppbCaption .= sprintf(
            ' Seuils : brassage %s %% · transferts %s %% · conditionnement %s %%.',
            number_format((float)($lossThresholds['pertes_brewing_warn_pct'] ?? 5.0), 1),
            number_format((float)($lossThresholds['pertes_rack_warn_pct'] ?? 2.0), 1),
            number_format((float)($lossThresholds['pertes_packaging_warn_pct'] ?? 1.0), 1)
        );
    }
  ?>

  <section class="ppb-section" id="ppb-section-main" aria-label="Pertes par batch">

    <!-- Section header -->
    <div class="ppb-section__head">
      <div class="ppb-section__title-group">
        <h2 class="ppb-section__title">
          Pertes par batch
          <span class="ppb-section__tag">Rendement</span>
        </h2>
      </div>
      <div class="ppb-section__controls">
        <a class="ppb-section__config-link"
           href="/modules/salle-de-controle.php?sec=pertes"
           title="Modifier les seuils dans Salle de Contrôle">
          Seuils ↗
        </a>
      </div>
    </div><!-- /.ppb-section__head -->

    <!-- View toggle: Core / Collab+EPH / Contract -->
    <?php
      // Build the base URL preserving all existing GET params except ppb_view.
      $toggleBase = array_filter($_GET, fn($k) => $k !== 'ppb_view', ARRAY_FILTER_USE_KEY);
      $mkToggleUrl = fn(string $v) => '?' . http_build_query(array_merge($toggleBase, ['ppb_view' => $v]));
    ?>
    <nav class="ppb-view-toggle" aria-label="Filtrer par famille de bière">
      <a href="<?= htmlspecialchars($mkToggleUrl('core')) ?>"
         class="ppb-view-toggle__btn<?= $ppbView === 'core' ? ' ppb-view-toggle__btn--active' : '' ?>"
         aria-current="<?= $ppbView === 'core' ? 'true' : 'false' ?>">Core</a>
      <a href="<?= htmlspecialchars($mkToggleUrl('collab-eph')) ?>"
         class="ppb-view-toggle__btn<?= $ppbView === 'collab-eph' ? ' ppb-view-toggle__btn--active' : '' ?>"
         aria-current="<?= $ppbView === 'collab-eph' ? 'true' : 'false' ?>">Collab + EPH</a>
      <a href="<?= htmlspecialchars($mkToggleUrl('contract')) ?>"
         class="ppb-view-toggle__btn<?= $ppbView === 'contract' ? ' ppb-view-toggle__btn--active' : '' ?>"
         aria-current="<?= $ppbView === 'contract' ? 'true' : 'false' ?>">Contrat</a>
    </nav>

    <?php if (empty($perBeerMetrics)): ?>
      <p class="ppb-empty">Aucune moyenne disponible pour cette catégorie sur les 6 derniers mois.</p>
    <?php else: ?>

    <div class="ppb-wrap">
      <table class="ppb-table" aria-label="Pertes de brassage par bière — moyennes 6 mois">
        <caption class="ppb-caption"><?= htmlspecialchars($ppbCaption) ?></caption>

        <colgroup>
          <col><!-- beer identity + batch count -->
          <col><!-- brewing loss -->
          <col><!-- rack loss -->
          <col><!-- packaging loss -->
          <col style="width:1px"><!-- separator -->
          <col><!-- total vs effectif -->
          <col><!-- total vs nominal -->
        </colgroup>

        <thead>
          <tr>
            <th class="ppb-th-group" scope="colgroup" style="text-align:left; border-top:1px solid var(--hairline-2);"></th>
            <th class="ppb-th-group" scope="colgroup" colspan="3" style="border-top:1px solid var(--hairline-2);">Pertes par étape</th>
            <th class="ppb-th--sep" rowspan="2" aria-hidden="true"></th>
            <th class="ppb-th-group ppb-th--total-eff" scope="colgroup" colspan="2" style="border-top:1px solid var(--hairline-2);">Perte totale</th>
          </tr>
          <tr>
            <th scope="col" class="ppb-th">Bière</th>
            <th scope="col" class="ppb-th ppb-th--pct">Brewing %</th>
            <th scope="col" class="ppb-th ppb-th--pct">Racking %</th>
            <th scope="col" class="ppb-th ppb-th--pct">Packaging %</th>
            <th scope="col" class="ppb-th ppb-th--pct ppb-th--total-eff">Vs effectif %</th>
            <th scope="col" class="ppb-th ppb-th--pct">Vs nominal %</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($perBeerMetrics as $pbr):
            $thresholds = $lossThresholds;
            $beerHtml   = htmlspecialchars($pbr['beer']);
            $nBatches   = (int)$pbr['n_batches'];
            $nInc       = (int)$pbr['n_incomplete'];

            $nLabel = (string)$nBatches;
            if ($nInc > 0) {
                $nLabel .= ' <span class="ppb-batch__meta--incomplete">(+' . $nInc . ' incomplet' . ($nInc > 1 ? 's' : '') . ')</span>';
            }

            $flagBrew = $pbr['avg_brewing_loss_pct']     !== null
                        && $pbr['avg_brewing_loss_pct']     > (float)($thresholds['pertes_brewing_warn_pct']    ?? 5.0);
            $flagRack = $pbr['avg_rack_loss_pct']         !== null
                        && $pbr['avg_rack_loss_pct']         > (float)($thresholds['pertes_rack_warn_pct']       ?? 2.0);
            $flagPkg  = $pbr['avg_packaging_loss_pct']   !== null
                        && $pbr['avg_packaging_loss_pct']   > (float)($thresholds['pertes_packaging_warn_pct']  ?? 1.0);
            $flagEff  = $pbr['avg_loss_vs_effectif_pct'] !== null
                        && $pbr['avg_loss_vs_effectif_pct'] > (float)($thresholds['pertes_total_effectif_warn_pct'] ?? 18.0);
            $flagNom  = $pbr['avg_loss_vs_nominal_pct']  !== null
                        && $pbr['avg_loss_vs_nominal_pct']  > (float)($thresholds['pertes_total_nominal_warn_pct']  ?? 10.0);
          ?>
          <tr class="ppb-row">
            <td class="ppb-td ppb-td--batch">
              <span class="ppb-batch__beer"><?= $beerHtml ?></span>
              <span class="ppb-batch__meta"><?= $nBatches ?> brassin<?= $nBatches !== 1 ? 's' : '' ?><?php if ($nInc > 0): ?> <span class="ppb-batch__meta--incomplete">(+<?= $nInc ?> incomplet<?= $nInc > 1 ? 's' : '' ?>)</span><?php endif ?></span>
            </td>
            <?= ppb_render_pct_cell($pbr['avg_brewing_loss_pct'],     $flagBrew, false) ?>
            <?= ppb_render_pct_cell($pbr['avg_rack_loss_pct'],         $flagRack, false) ?>
            <?= ppb_render_pct_cell($pbr['avg_packaging_loss_pct'],   $flagPkg,  false) ?>
            <td class="ppb-td ppb-td--sep ppb-td--sep-total" aria-hidden="true"></td>
            <?= ppb_render_pct_cell($pbr['avg_loss_vs_effectif_pct'], $flagEff,  true) ?>
            <?= ppb_render_pct_cell($pbr['avg_loss_vs_nominal_pct'],  $flagNom,  false) ?>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div><!-- /.ppb-wrap -->

    <?php endif ?>
  </section><!-- /.ppb-section -->


  <!-- CCT Detail Modal -->
  <dialog class="cct-modal" id="cct-detail-modal">
    <div class="cct-modal__overlay" data-close></div>
    <div class="cct-modal__card" id="cct-modal-card">
      <!-- populated by JS -->
    </div>
  </dialog>

  <!-- Per-CCT SVG templates for modal (pre-rendered server-side) -->
  <?php foreach ($cctDetails as $tNum => $td): ?>
  <template id="cct-svg-<?= $tNum ?>"><?= svg_cct(
    $td['capacity_hl'] > 0 ? min(1.0, $td['volume_hl'] / $td['capacity_hl']) : 0.0,
    $td['state'],
    $tNum,
    $td['beer'],
    'fill',
    []
  ) ?></template>
  <?php endforeach ?>

  <script>window.CCT_DETAILS = <?= json_encode($cctDetails, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
</main>

</body>
</html>
