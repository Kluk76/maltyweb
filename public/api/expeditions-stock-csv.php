<?php
declare(strict_types=1);
/**
 * api/expeditions-stock-csv.php — Stock PF CSV download
 * Auth: require_page_access('expeditions') — same read gate as viewing the page.
 * No CSRF (GET, no state change).
 *
 * Reuses fg_stock_compute() + fg_stock_location_snapshot() verbatim.
 * Numbers must match the on-screen values exactly because they come from
 * the same functions.
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/fg-stock.php';

require_page_access('expeditions');

try {
    $pdo      = maltytask_pdo();
    $stock    = fg_stock_compute($pdo);
    $snapshot = fg_stock_location_snapshot($pdo);
} catch (Throwable $e) {
    error_log('[expeditions-stock-csv] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'DB error']);
    exit;
}

$anchorDate = $stock['anchor_date'] ?? null;
$anchorDateFmt = $anchorDate !== null
    ? sprintf('%s/%s/%s', substr($anchorDate,8,2), substr($anchorDate,5,2), substr($anchorDate,0,4))
    : '—';
$todayFmt = date('d/m/Y');
$todayISO = date('Y-m-d');

$locations = $snapshot['locations'] ?? [];

// Build per-location, per-sku lookup: locId → skuId → row
$locSkuMap = [];
foreach ($locations as $loc) {
    $locId = (int) $loc['id'];
    foreach ($loc['rows'] as $lr) {
        $locSkuMap[$locId][(int) $lr['sku_id']] = $lr;
    }
}

// Effective family (BU/CU suffix → 'À l'unité', else display_family)
$effFamily = static function (array $sr): string {
    return preg_match('/(BU|CU)$/', $sr['sku_code'] ?? '') ? "À l'unité" : ($sr['display_family'] ?? $sr['format']);
};

// Sort rows: by family order, then sku_code (mirrors on-screen grouping)
$familyOrder = fg_stock_family_order();
$rows = $stock['rows'] ?? [];
usort($rows, static function (array $a, array $b) use ($familyOrder, $effFamily): int {
    $famA = $effFamily($a);
    $famB = $effFamily($b);
    $oa = $familyOrder[$famA] ?? 99;
    $ob = $familyOrder[$famB] ?? 99;
    if ($oa !== $ob) return $oa <=> $ob;
    return strcmp($a['sku_code'], $b['sku_code']);
});

// burn_status → human label
$burnLabel = static function (string $s): string {
    return match ($s) {
        'normal'     => 'Normal',
        'provisoire' => 'Provisoire',
        'eol'        => 'Fin de série',
        default      => $s,
    };
};

// Headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="stock-pf-' . $todayISO . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

// Provenance header
fputcsv($out, ['Provenance', 'Stock PF calculé en direct le ' . $todayFmt . ' — ancrage comptage ' . $anchorDateFmt], ',', '"');
fputcsv($out, [], ',', '"');

// Dynamic header: fixed cols + per-location cols (in snapshot order) + aggregate cols
$headerRow = ['Code SKU', 'Format', 'Famille'];
foreach ($locations as $loc) {
    $headerRow[] = $loc['name'] . ' (u)';
    $headerRow[] = $loc['name'] . ' (HL)';
}
$headerRow = array_merge($headerRow, [
    'Physique total (u)',
    'Physique total (HL)',
    'Production depuis comptage (u)',
    'Commandes cette semaine (u)',
    'Commandes ≤ 2 sem. (u)',
    'Commandes ouvertes total (u)',
    'Dispo après cmd. semaine (u)',
    'Dispo après cmd. 2 sem. (u)',
    'Dispo après cmd. total (u)',
    'Survendu',
    'Rythme base (u/sem.)',
    'Semaines de couverture',
    'Statut écoulement',
]);
fputcsv($out, $headerRow, ',', '"');

// Data rows
foreach ($rows as $sr) {
    $skuId    = (int) $sr['sku_id'];
    $physique = (float) $sr['physique'];

    $row = [
        $sr['sku_code'],
        $sr['format'],
        fg_family_label(fg_format_family($sr['format'])),
    ];

    // Per-location qty/HL (in snapshot order; 0 if SKU absent at that site)
    foreach ($locations as $loc) {
        $locId  = (int) $loc['id'];
        $locRow = $locSkuMap[$locId][$skuId] ?? null;
        $locQty = $locRow !== null ? (float) $locRow['qty'] : 0;
        $locHl  = $locRow !== null ? (float) $locRow['hl']  : 0;
        $row[]  = number_format($locQty, 0, '.', '');
        $row[]  = number_format($locHl,  3, '.', '');
    }

    // Aggregate cols
    $row[] = number_format($physique, 0, '.', '');
    $row[] = number_format($physique * (float) $sr['hl_per_unit'], 3, '.', '');
    $row[] = number_format((int) $sr['prod_qty'], 0, '.', '');
    $row[] = number_format((int) $sr['open_week_qty'], 0, '.', '');
    $row[] = number_format((int) $sr['open_2wk_qty'], 0, '.', '');
    $row[] = number_format((int) $sr['open_total_qty'], 0, '.', '');
    $row[] = number_format((int) $sr['live_semaine'], 0, '.', '');
    $row[] = number_format((int) $sr['live_2sem'], 0, '.', '');
    $row[] = number_format((int) $sr['live_futur'], 0, '.', '');
    $row[] = $sr['flag_survendu'] ? 'Oui' : 'Non';
    $row[] = $sr['rythme_base'] !== null ? number_format((float) $sr['rythme_base'], 2, '.', '') : '';
    $row[] = $sr['semaines_stock'] !== null ? number_format((float) $sr['semaines_stock'], 1, '.', '') : 'sans rotation';
    $row[] = $burnLabel($sr['burn_status'] ?? '');

    fputcsv($out, $row, ',', '"');
}

fclose($out);
