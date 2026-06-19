<?php
declare(strict_types=1);
/**
 * api/supplier-evaluation-export.php — Supplier evaluation auditor export (CSV)
 * Auth: manager+ (require_manager_or_admin)
 * No params — exports all active suppliers with latest final evaluation.
 * UTF-8 BOM for Excel-FR accent support.
 */

require __DIR__ . '/../../app/auth.php';

require_manager_or_admin();

require_once __DIR__ . '/../../app/db.php';

$pdo = maltytask_pdo();

$stmt = $pdo->prepare("
SELECT
    rs.id              AS supplier_id,
    rs.name            AS supplier_name,
    rs.criticality,
    se.evaluated_at,
    se.evaluation_type,
    se.total_pct,
    se.result,
    se.food_safety_ko,
    se.valid_until,
    se.status          AS eval_status,
    (SELECT COUNT(*) FROM supplier_nc nc
      WHERE nc.supplier_id_fk = rs.id AND nc.status = 'open') AS open_nc_count,
    (SELECT COUNT(*) FROM supplier_cert_documents cd
      WHERE cd.supplier_id_fk = rs.id AND cd.is_active = 1) AS cert_count
FROM ref_suppliers rs
LEFT JOIN supplier_evaluations se ON se.supplier_id_fk = rs.id
    AND se.status = 'final'
    AND se.superseded_by_id IS NULL
WHERE rs.is_active = 1
ORDER BY
    CASE rs.criticality WHEN 'critique' THEN 0 ELSE 1 END,
    rs.name ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="evaluations-fournisseurs-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

fputcsv($out, [
    'Fournisseur', 'Criticité', 'Dernière évaluation', "Type d'évaluation",
    'Score %', 'Résultat', 'Critère éliminatoire', "Valable jusqu'au",
    "Statut d'agrément", 'NC ouvertes', 'Certificats liés',
], ',', '"');

foreach ($rows as $row) {
    // Score %
    $scorePct = ($row['total_pct'] !== null)
        ? number_format((float)$row['total_pct'], 2, '.', '')
        : '';

    // Food safety KO label (only if there IS an evaluation row)
    if ($row['eval_status'] === null) {
        $foodSafetyLabel = '';
    } else {
        $foodSafetyLabel = ((int)$row['food_safety_ko'] === 1) ? 'O' : 'N';
    }

    // Statut d'agrément
    if ($row['eval_status'] === null) {
        $statutLabel = 'À évaluer';
    } else {
        $statutLabel = match($row['result'] ?? '') {
            'agree'                   => 'Agréé',
            'agree_sous_surveillance' => 'Agréé sous surveillance',
            'non_agree'               => 'Non agréé',
            default                   => 'Brouillon',
        };
    }

    fputcsv($out, [
        $row['supplier_name'],
        $row['criticality'],
        $row['evaluated_at'] ?? '',
        $row['evaluation_type'] ?? '',
        $scorePct,
        $row['result'] ?? '',
        $foodSafetyLabel,
        $row['valid_until'] ?? '',
        $statutLabel,
        (int)$row['open_nc_count'],
        (int)$row['cert_count'],
    ], ',', '"');
}

fclose($out);
