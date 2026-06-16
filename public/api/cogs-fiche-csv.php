<?php
declare(strict_types=1);
/**
 * api/cogs-fiche-csv.php — Fiche COGS CSV download
 * Auth: manager+ (require_page_access)
 * ?month=YYYY-MM  (required)
 *
 * Data is read via cogs_fiche_resolve_month() — precedence sealed > seed > live.
 * A Provenance header line is emitted as the first row.
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/cogs-fiche-resolve.php';

require_page_access('financier');

$month = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'month param required (YYYY-MM)']);
    exit;
}

try {
    $pdo      = maltytask_pdo();
    $resolved = cogs_fiche_resolve_month($pdo, $month);

    if ($resolved['provenance'] === 'unavailable') {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'reason' => 'No data for ' . $month]);
        exit;
    }

    // Fetch category metadata (label, GL accounts, display order)
    $stmtCat = $pdo->prepare("
        SELECT category_key, label_fr, inv_gl, charge_gl, display_order
        FROM ref_cogs_fiche_categories
        WHERE is_active = 1
        ORDER BY display_order
    ");
    $stmtCat->execute();
    $catMeta = [];
    foreach ($stmtCat->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $catMeta[$row['category_key']] = $row;
    }
} catch (Throwable $e) {
    error_log('[cogs-fiche-csv] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'DB error']);
    exit;
}

// Build ordered rows from resolver output + category metadata
$rows    = [];
$totals  = ['rm_chf'=>0.0,'wip_chf'=>0.0,'fg_chf'=>0.0,'total_chf'=>0.0,'opening_chf'=>0.0,'variation_chf'=>0.0];
$basisRows = [];
foreach ($catMeta as $ck => $meta) {
    $catVals = $resolved['categories'][$ck] ?? [
        'rm_chf'=>0.0,'wip_chf'=>0.0,'fg_chf'=>0.0,'total_chf'=>0.0,
        'opening_chf'=>0.0,'variation_chf'=>0.0,'basis_adjustment_chf'=>0.0,
    ];
    $row = array_merge($meta, $catVals);
    $rows[] = $row;
    foreach (array_keys($totals) as $k) {
        $totals[$k] += (float)($catVals[$k] ?? 0);
    }
    if (abs((float)($catVals['basis_adjustment_chf'] ?? 0)) > 0.0001) {
        $basisRows[] = $row;
    }
}

// Provenance header string
$prov = $resolved['provenance'];
$today = date('Y-m-d');
if ($prov === 'sealed') {
    $sealedAt = $resolved['sealed_at'] ?? '';
    $sealedBy = $resolved['sealed_by'] ?? '';
    $provenanceLabel = 'Clôturé (signé) le ' . $sealedAt
        . ($sealedBy !== '' ? ' par ' . $sealedBy : '');
} elseif ($prov === 'live') {
    $provenanceLabel = 'Calculé en direct le ' . $today;
} else {
    $provenanceLabel = 'Référence d\'ouverture';
}

$filename = 'fiche-cogs-' . $month . '-' . $today . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

// Provenance header line
fputcsv($out, ['Provenance', $provenanceLabel], ',', '"');
fputcsv($out, [], ',', '"');

fputcsv($out, [
    'Catégorie', 'Comptes Inv.', 'Cptes Charge',
    'Valeur Stock', 'Bières en cours', 'Bières prêtes',
    'Total Inventaire', 'Compta mois préc.', 'Variation Stock',
], ',', '"');

foreach ($rows as $row) {
    fputcsv($out, [
        $row['label_fr'],
        $row['inv_gl'] ?? '',
        $row['charge_gl'] ?? '',
        number_format((float)$row['rm_chf'],        2, '.', ''),
        number_format((float)$row['wip_chf'],       2, '.', ''),
        number_format((float)$row['fg_chf'],        2, '.', ''),
        number_format((float)$row['total_chf'],     2, '.', ''),
        number_format((float)$row['opening_chf'],   2, '.', ''),
        number_format((float)$row['variation_chf'], 2, '.', ''),
    ], ',', '"');
}

fputcsv($out, [
    'Total', '', '',
    number_format($totals['rm_chf'],        2, '.', ''),
    number_format($totals['wip_chf'],       2, '.', ''),
    number_format($totals['fg_chf'],        2, '.', ''),
    number_format($totals['total_chf'],     2, '.', ''),
    number_format($totals['opening_chf'],   2, '.', ''),
    number_format($totals['variation_chf'], 2, '.', ''),
], ',', '"');

foreach ($basisRows as $row) {
    fputcsv($out, [
        'Ajustement de base — ' . $row['label_fr'],
        '', '', '', '', '', '', '',
        number_format((float)$row['basis_adjustment_chf'], 2, '.', ''),
    ], ',', '"');
}

fclose($out);
