<?php
declare(strict_types=1);
/**
 * CLI verifier for TankSimulator.
 *
 * Prints current tank state in the same format as the BSF Tank_Balances output:
 *   CCT <n>  <beer>  #<batch>  <vol> hl
 *   BBT <n>  <beer>  #<batch>  <vol> hl  [blend detail]
 *
 * Run on VPS:
 *   sudo -u maltytask bash -c "cd /var/www/maltytask && php scripts/verify_tank_state.php"
 */

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/tank-simulator.php';

$pdo = maltytask_pdo();

// ── Event counts for data-shape sanity check ────────────────────────────────
$counts = [];
$counts['cooling_since_oct23'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM bd_brewing_cooling WHERE event_date >= '2023-10-01'"
)->fetchColumn();
$counts['racking_total'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM bd_racking WHERE COALESCE(start_time, submitted_at) IS NOT NULL"
)->fetchColumn();
$counts['packaging_total'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM bd_packaging WHERE submitted_at IS NOT NULL"
)->fetchColumn();

echo "=== Event counts ===\n";
echo sprintf("  Cooling (since Oct 2023): %d\n", $counts['cooling_since_oct23']);
echo sprintf("  Racking:                  %d\n", $counts['racking_total']);
echo sprintf("  Packaging:                %d\n", $counts['packaging_total']);
echo "\n";

// ── Run simulation ───────────────────────────────────────────────────────────
$state = (new TankSimulator($pdo))->run();

$ccts = $state['cct'];
$bbts = $state['bbt'];

ksort($ccts);
ksort($bbts);

$totalCctHl = 0.0;
$totalBbtHl = 0.0;

echo "=== CCT state ===\n";
foreach ($ccts as $num => $s) {
    if ($s === null) continue;
    $vol = round($s['volume_hl'], 1);
    $totalCctHl += $vol;
    printf(
        "  CCT %-3d  %-30s  #%-6s  %6.1f hl\n",
        $num,
        $s['beer'],
        $s['batch'],
        $vol
    );
}

echo "\n=== BBT state ===\n";
foreach ($bbts as $num => $s) {
    if ($s === null) continue;
    $vol = round($s['volume_hl'], 1);
    $totalBbtHl += $vol;
    // Blend detail string
    $blendStr = '';
    if (!empty($s['blend_info']) && count($s['blend_info']) > 1) {
        $parts = array_map(
            fn($bi) => '#' . $bi['batch'] . ': ' . round((float)$bi['vol']) . 'hl',
            $s['blend_info']
        );
        $blendStr = implode(' + ', $parts);
    }
    printf(
        "  BBT %-3d  %-30s  #%-6s  %6.1f hl  %s\n",
        $num,
        $s['beer'],
        $s['batch'],
        $vol,
        $blendStr
    );
}

echo "\n=== Summary ===\n";
printf("  Occupied CCTs: %d  (%.1f hl total)\n",
    count(array_filter($ccts)),
    $totalCctHl
);
printf("  Occupied BBTs: %d  (%.1f hl total)\n",
    count(array_filter($bbts)),
    $totalBbtHl
);
printf("  Grand total in tank: %.1f hl\n", $totalCctHl + $totalBbtHl);
