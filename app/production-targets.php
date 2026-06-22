<?php
declare(strict_types=1);
require_once __DIR__ . '/settings.php';

/**
 * Production-targets compute helper.
 * Single source of truth for objectives + actuals consumed by:
 *   - salle-de-controle.php ?sec=objectifs (render)
 *   - kpi-handlers.php (kpi_handler_production_targets)
 *
 * Returns an array:
 * [
 *   'settings'   => [key_name => row, ...],
 *   'objectives' => [
 *     'wort_hl'    => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'brews'      => ['week'=>float, 'month'=>float, 'year'=>int],
 *     'keg_hl'     => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'bottle_hl'  => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'can_hl'     => ['week'=>float, 'month'=>float, 'year'=>float],
 *   ],
 *   'actuals'    => [
 *     'wort_hl'    => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'brews'      => ['week'=>int,   'month'=>int,   'year'=>int],
 *     'keg_hl'     => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'bottle_hl'  => ['week'=>float, 'month'=>float, 'year'=>float],
 *     'can_hl'     => ['week'=>float, 'month'=>float, 'year'=>float],
 *   ],
 *   'windows'    => [
 *     'week'  => ['start_dt'=>string, 'end_dt'=>string, 'start_d'=>string, 'end_d'=>string],
 *     'month' => ['start_dt'=>string, 'end_dt'=>string, 'start_d'=>string, 'end_d'=>string],
 *     'year'  => ['start_dt'=>string, 'end_dt'=>string, 'start_d'=>string, 'end_d'=>string],
 *   ],
 * ]
 */
function production_targets_compute(PDO $pdo): array
{
    // 1. Load settings
    $stmt = $pdo->prepare(
        "SELECT key_name, label_fr, description_fr, value_num, default_num, unit_fr
           FROM system_settings
          WHERE section = 'production_targets' AND is_active = 1
          ORDER BY id ASC"
    );
    $stmt->execute();
    $settingsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byKey = [];
    foreach ($settingsRows as $row) {
        $byKey[$row['key_name']] = $row;
    }

    $get = function(string $k, float $fallback) use ($byKey): float {
        return isset($byKey[$k]) ? (float)$byKey[$k]['value_num'] : $fallback;
    };

    // 2. Derive objectives
    $opWeeks  = max(1.0, $get('operating_weeks',  48.0));
    $opMonths = max(1.0, $get('operating_months', 12.0));

    $wortYearHl  = $get('wort_hl_year',      16700.0);
    $brewsYear   = (int) round($get('wort_brews_year', 528.0));
    $kegYearHl   = $get('pkg_keg_hl_year',    7920.0);
    $botYearHl   = $get('pkg_bottle_hl_year', 4620.0);
    $canYearHl   = $get('pkg_can_hl_year',    1008.0);

    $objectives = [
        'wort_hl'   => [
            'week'  => round($wortYearHl / $opWeeks,  2),
            'month' => round($wortYearHl / $opMonths, 2),
            'year'  => $wortYearHl,
        ],
        'brews'     => [
            'week'  => round($brewsYear / $opWeeks,  2),
            'month' => round($brewsYear / $opMonths, 2),
            'year'  => $brewsYear,
        ],
        'keg_hl'    => [
            'week'  => round($kegYearHl / $opWeeks,  2),
            'month' => round($kegYearHl / $opMonths, 2),
            'year'  => $kegYearHl,
        ],
        'bottle_hl' => [
            'week'  => round($botYearHl / $opWeeks,  2),
            'month' => round($botYearHl / $opMonths, 2),
            'year'  => $botYearHl,
        ],
        'can_hl'    => [
            'week'  => round($canYearHl / $opWeeks,  2),
            'month' => round($canYearHl / $opMonths, 2),
            'year'  => $canYearHl,
        ],
    ];

    // 3. Time windows (app display timezone)
    $tz = new DateTimeZone(app_timezone());
    $now = new DateTime('now', $tz);
    $dow = (int) $now->format('N'); // 1=Mon … 7=Sun

    $weekStart  = (clone $now)->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
    $weekEnd    = (clone $weekStart)->modify('+7 days');
    $monthStart = new DateTime('first day of this month 00:00:00', $tz);
    $monthEnd   = new DateTime('first day of next month 00:00:00', $tz);
    $yearStart  = new DateTime('2026-01-01 00:00:00', $tz);
    $yearEnd    = new DateTime('2027-01-01 00:00:00', $tz);

    $windows = [
        'week'  => [
            'start_dt' => $weekStart->format('Y-m-d H:i:s'),
            'end_dt'   => $weekEnd->format('Y-m-d H:i:s'),
            'start_d'  => $weekStart->format('Y-m-d'),
            'end_d'    => $weekEnd->format('Y-m-d'),
        ],
        'month' => [
            'start_dt' => $monthStart->format('Y-m-d H:i:s'),
            'end_dt'   => $monthEnd->format('Y-m-d H:i:s'),
            'start_d'  => $monthStart->format('Y-m-d'),
            'end_d'    => $monthEnd->format('Y-m-d'),
        ],
        'year'  => [
            'start_dt' => $yearStart->format('Y-m-d H:i:s'),
            'end_dt'   => $yearEnd->format('Y-m-d H:i:s'),
            'start_d'  => $yearStart->format('Y-m-d'),
            'end_d'    => $yearEnd->format('Y-m-d'),
        ],
    ];

    // 4. Actuals — wort: submitted_at boundaries (datetime)
    $wortStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(final_volume), 0) AS hl, COUNT(*) AS brews
           FROM bd_brewing_gravity_v2
          WHERE event_type = 'Cooling' AND is_tombstoned = 0
            AND submitted_at >= ? AND submitted_at < ?"
    );

    $fetchWort = function(string $start, string $end) use ($wortStmt): array {
        $wortStmt->execute([$start, $end]);
        $row = $wortStmt->fetch(PDO::FETCH_ASSOC);
        return ['hl' => round((float)($row['hl'] ?? 0), 2), 'brews' => (int)($row['brews'] ?? 0)];
    };

    // Actuals — packaging: event_date boundaries (date only)
    $pkgStmt = $pdo->prepare(
        "SELECT run_type, ROUND(COALESCE(SUM(vendable_hl), 0), 3) AS hl
           FROM bd_packaging_v2
          WHERE is_tombstoned = 0 AND reuses_packaging_id_fk IS NULL
            AND event_date >= ? AND event_date < ?
          GROUP BY run_type"
    );

    $fetchPkg = function(string $start, string $end) use ($pkgStmt): array {
        $pkgStmt->execute([$start, $end]);
        $rows = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
        $map = ['keg' => 0.0, 'bot' => 0.0, 'can' => 0.0];
        foreach ($rows as $r) {
            $rt = $r['run_type'];
            if ($rt === 'keg') $map['keg'] += (float)$r['hl'];
            elseif ($rt === 'bot') $map['bot'] += (float)$r['hl'];
            elseif (in_array($rt, ['can', 'can33'], true)) $map['can'] += (float)$r['hl'];
            // cuv excluded
        }
        return $map;
    };

    $wortWk = $fetchWort($windows['week']['start_dt'],  $windows['week']['end_dt']);
    $wortMo = $fetchWort($windows['month']['start_dt'], $windows['month']['end_dt']);
    $wortYr = $fetchWort($windows['year']['start_dt'],  $windows['year']['end_dt']);
    $pkgWk  = $fetchPkg($windows['week']['start_d'],    $windows['week']['end_d']);
    $pkgMo  = $fetchPkg($windows['month']['start_d'],   $windows['month']['end_d']);
    $pkgYr  = $fetchPkg($windows['year']['start_d'],    $windows['year']['end_d']);

    $actuals = [
        'wort_hl'   => ['week' => $wortWk['hl'],   'month' => $wortMo['hl'],   'year' => $wortYr['hl']],
        'brews'     => ['week' => $wortWk['brews'], 'month' => $wortMo['brews'], 'year' => $wortYr['brews']],
        'keg_hl'    => ['week' => $pkgWk['keg'],   'month' => $pkgMo['keg'],   'year' => $pkgYr['keg']],
        'bottle_hl' => ['week' => $pkgWk['bot'],   'month' => $pkgMo['bot'],   'year' => $pkgYr['bot']],
        'can_hl'    => ['week' => $pkgWk['can'],   'month' => $pkgMo['can'],   'year' => $pkgYr['can']],
    ];

    return [
        'settings'   => $byKey,
        'objectives' => $objectives,
        'actuals'    => $actuals,
        'windows'    => $windows,
    ];
}
