<?php
declare(strict_types=1);
/**
 * api/cogs-fiche-csv.php — Fiche COGS CSV download
 * Auth: manager+ (require_page_access)
 * ?month=YYYY-MM  (required)
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

require_page_access('financier');

$month = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'month param required (YYYY-MM)']);
    exit;
}

// Validate month is actually in our data
try {
    $pdo = maltytask_pdo();

    $stmt_months = $pdo->query("
        SELECT DISTINCT month_key FROM (
            SELECT month_key FROM cogs_fiche_seed
            UNION
            SELECT month_key FROM cogs_fiche_monthly
        ) AS all_months
    ");
    $valid_months = $stmt_months->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array($month, $valid_months, true)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'reason' => 'No data for ' . $month]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.category_key, c.label_fr, c.inv_gl, c.charge_gl, c.display_order,
            COALESCE(m.rm_chf,        s.rm_chf)        AS rm_chf,
            COALESCE(m.wip_chf,       s.wip_chf)       AS wip_chf,
            COALESCE(m.fg_chf,        s.fg_chf)        AS fg_chf,
            COALESCE(m.total_chf,     s.total_chf)     AS total_chf,
            COALESCE(m.opening_chf,   s.opening_chf)   AS opening_chf,
            COALESCE(m.variation_chf, s.variation_chf) AS variation_chf,
            m.basis_adjustment_chf,
            CASE WHEN m.id IS NOT NULL THEN 'computed' ELSE 'seed' END AS provenance
        FROM ref_cogs_fiche_categories c
        LEFT JOIN cogs_fiche_seed s    ON s.month_key = :month  AND s.category_key = c.category_key
        LEFT JOIN cogs_fiche_monthly m ON m.month_key = :month2 AND m.category_key = c.category_key
        WHERE c.is_active = 1
          AND (s.month_key IS NOT NULL OR m.month_key IS NOT NULL)
        ORDER BY c.display_order
    ");
    $stmt->execute([':month' => $month, ':month2' => $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[cogs-fiche-csv] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'DB error']);
    exit;
}

if (empty($rows)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'reason' => 'No data for ' . $month]);
    exit;
}

$totals = ['rm_chf'=>0.0,'wip_chf'=>0.0,'fg_chf'=>0.0,'total_chf'=>0.0,'opening_chf'=>0.0,'variation_chf'=>0.0];
foreach ($rows as $row) {
    foreach (array_keys($totals) as $k) {
        $totals[$k] += (float)($row[$k] ?? 0);
    }
}

$today    = date('Y-m-d');
$filename = 'fiche-cogs-' . $month . '-' . $today . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

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

foreach ($rows as $row) {
    if ($row['basis_adjustment_chf'] !== null) {
        fputcsv($out, [
            'Ajustement de base — ' . $row['label_fr'],
            '', '', '', '', '', '', '',
            number_format((float)$row['basis_adjustment_chf'], 2, '.', ''),
        ], ',', '"');
    }
}

fclose($out);
