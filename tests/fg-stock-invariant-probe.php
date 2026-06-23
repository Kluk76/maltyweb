<?php
declare(strict_types=1);
/**
 * FG stock invariant probe — READ-ONLY.
 * Runs on the VPS as www-data:
 *   scp tests/fg-stock-invariant-probe.php maltyweb:/tmp/fg-stock-probe.php
 *   ssh maltyweb 'sudo -u www-data php /tmp/fg-stock-probe.php'
 *
 * To test a modified fg-stock.php without deploying:
 *   scp app/fg-stock.php maltyweb:/tmp/fg-stock-new.php
 *   ssh maltyweb 'FG_STOCK_FILE=/tmp/fg-stock-new.php sudo -u www-data -E php /tmp/fg-stock-probe.php'
 *
 * No writes. Asserts Σcards==Σphysique per SKU and shows ZEPB@Zgeg detail.
 */

$fgStockFile = getenv('FG_STOCK_FILE') ?: '/var/www/maltytask/app/fg-stock.php';
$appBase     = '/var/www/maltytask';

require_once $appBase . '/app/db.php';
require_once $appBase . '/app/settings.php';
require_once $appBase . '/app/fulfilment-site.php';
require_once $appBase . '/app/seasonal-burn.php';
require_once $appBase . '/app/repack.php';
require_once $fgStockFile;

$pdo = maltytask_pdo();

// ── Unit tests for fg_event_is_post_census + fg_norm_ts ─────────────────────

// fg_norm_ts: truncates microseconds
assert(fg_norm_ts('2026-06-22 11:47:14.000000') === '2026-06-22 11:47:14', 'norm_ts microseconds');
assert(fg_norm_ts('2026-06-22 06:43:46') === '2026-06-22 06:43:46', 'norm_ts plain');

// Census before → false
assert(!fg_event_is_post_census('2026-06-21', '2026-06-21 10:00:00', '2026-06-22', '2026-06-22 06:43:46', '2026-06-15'), 'census_after_event');
// Census after → true
assert( fg_event_is_post_census('2026-06-23', '2026-06-23 10:00:00', '2026-06-22', '2026-06-22 06:43:46', '2026-06-15'), 'event_after_census');
// Same day, event ts AFTER census ts → true
assert( fg_event_is_post_census('2026-06-22', '2026-06-22 11:47:14.000000', '2026-06-22', '2026-06-22 06:43:46', '2026-06-15'), 'same_day_after_ts');
// Same day, event ts BEFORE census ts → false
assert(!fg_event_is_post_census('2026-06-22', '2026-06-22 05:00:00', '2026-06-22', '2026-06-22 06:43:46', '2026-06-15'), 'same_day_before_ts');
// Same day, ts null both → false (exclude ambiguous)
assert(!fg_event_is_post_census('2026-06-22', null, '2026-06-22', null, '2026-06-15'), 'same_day_null_ts');
// Subsecond equality after normalisation: '11:47:14.000000' normalises to '11:47:14', same as census '11:47:14' → false (equal is NOT after)
assert(!fg_event_is_post_census('2026-06-22', '2026-06-22 11:47:14.000000', '2026-06-22', '2026-06-22 11:47:14', '2026-06-15'), 'same_day_equal_after_norm');
// No census (null) → inclusiveFallback=false → strict >
assert( fg_event_is_post_census('2026-06-23', null, null, null, '2026-06-22', false), 'no_census_strict_after');
assert(!fg_event_is_post_census('2026-06-22', null, null, null, '2026-06-22', false), 'no_census_strict_equal');
// No census → inclusiveFallback=true → >=
assert( fg_event_is_post_census('2026-06-22', null, null, null, '2026-06-22', true),  'no_census_inclusive_equal');

echo "Unit tests: ALL PASS\n";

// ── Live computation ─────────────────────────────────────────────────────────
$compute  = fg_stock_compute($pdo);
$snapshot = fg_stock_location_snapshot($pdo);

// Build snapshot total per SKU (sum qty across all locations)
$snapBySku = [];
foreach ($snapshot['locations'] as $loc) {
    foreach ($loc['rows'] as $row) {
        $sid = (int) $row['sku_id'];
        $snapBySku[$sid] = ($snapBySku[$sid] ?? 0.0) + (float) $row['qty'];
    }
}

// Build compute physique per SKU
$computeBySku = [];
foreach ($compute['rows'] as $row) {
    $computeBySku[(int) $row['sku_id']] = (float) $row['physique'];
}

// Assert Σcards==Σphysique per SKU (tolerance ±0.01 for float)
$allSkus   = array_unique(array_merge(array_keys($snapBySku), array_keys($computeBySku)));
$failures  = [];
$checked   = 0;
foreach ($allSkus as $sid) {
    $snapQ    = $snapBySku[$sid]    ?? 0.0;
    $computeQ = $computeBySku[$sid] ?? 0.0;
    if (abs($snapQ - $computeQ) > 0.01) {
        $failures[] = ['sku_id' => $sid, 'snap' => $snapQ, 'compute' => $computeQ, 'diff' => $snapQ - $computeQ];
    }
    $checked++;
}

// Global totals
$snapTotal    = array_sum($snapBySku);
$computeTotal = array_sum($computeBySku);

// ZEPB detail (sku_id=58)
$zepbCompute = $computeBySku[58] ?? 'NOT IN COMPUTE';
$zepbSnap    = $snapBySku[58]    ?? 'NOT IN SNAPSHOT';
$zepbComputeRow = null;
foreach ($compute['rows'] as $r) {
    if ((int) $r['sku_id'] === 58) { $zepbComputeRow = $r; break; }
}

// ZEPB per-site detail from snapshot
$zepbSites = [];
foreach ($snapshot['locations'] as $loc) {
    foreach ($loc['rows'] as $row) {
        if ((int) $row['sku_id'] === 58) {
            $zepbSites[] = ['site' => $loc['name'], 'qty' => $row['qty'],
                            'sales_qty' => $row['sales_qty'], 'transfer_in' => $row['transfer_in'],
                            'transfer_out' => $row['transfer_out']];
        }
    }
}

echo "\n=== ZEPB (sku_id=58) ===\n";
echo "  compute physique:  " . $zepbCompute . "\n";
echo "  snapshot total:    " . $zepbSnap . "\n";
if ($zepbComputeRow) {
    echo "  anchor_qty:        " . $zepbComputeRow['anchor_qty'] . "\n";
    echo "  prod_qty:          " . $zepbComputeRow['prod_qty'] . "\n";
    echo "  expedie_qty:       " . $zepbComputeRow['expedie_qty'] . "\n";
    echo "  eshop_qty:         " . $zepbComputeRow['eshop_qty'] . "\n";
    echo "  returns_restock:   " . $zepbComputeRow['returns_restock_qty'] . "\n";
    echo "  repack_open:       " . $zepbComputeRow['repack_open_qty'] . "\n";
    echo "  repack_assembled:  " . $zepbComputeRow['repack_assembled_qty'] . "\n";
}
echo "  per-site breakdown:\n";
foreach ($zepbSites as $s) {
    echo "    {$s['site']}: qty={$s['qty']} sales={$s['sales_qty']} xfr_in={$s['transfer_in']} xfr_out={$s['transfer_out']}\n";
}

echo "\n=== INVARIANT Σcards==Σphysique ===\n";
echo "  Compute total:  " . $computeTotal . "\n";
echo "  Snapshot total: " . $snapTotal . "\n";
echo "  SKUs checked:   " . $checked . "\n";

if (empty($failures)) {
    echo "  PASS: all {$checked} SKUs match within ±0.01\n";
} else {
    echo "  FAIL: " . count($failures) . " SKU(s) mismatch:\n";
    foreach ($failures as $f) {
        echo "    sku_id={$f['sku_id']} snap={$f['snap']} compute={$f['compute']} diff={$f['diff']}\n";
    }
}

echo "\n=== OVERALL ===\n";
$allPass = empty($failures) && abs($snapTotal - $computeTotal) <= 0.01;
echo ($allPass ? "PASS" : "FAIL") . "\n";
