<?php
declare(strict_types=1);
/**
 * app/recap-lines.php — shared helper for invoice per-line recap.
 *
 * Provides fetch_recap_for_invoice(PDO, int $invoiceId): array|null
 *
 * Returns the recap array used by:
 *   - public/api/upload-status.php  (JSON recap for the upload page inline card)
 *   - public/modules/invoice-validate.php (server-rendered per-card lines)
 *
 * The two consumers use the same join + shape so they never drift.
 *
 * Return shape:
 * [
 *   'supplier_name' => string|null,
 *   'invoice_ref'   => string|null,
 *   'invoice_date'  => string|null,  // YYYY-MM-DD
 *   'total_ht'      => float|null,
 *   'currency'      => string,
 *   'lines' => [
 *     [
 *       'line_index'       => int,
 *       'mi_label'         => string|null,  // "MI_ID — Name" or null
 *       'mi_unresolved'    => bool,
 *       'description'      => string|null,
 *       'qty'              => float|null,
 *       'unit'             => string|null,
 *       'unit_price'       => float|null,
 *       'line_total'       => float|null,
 *       'low_confidence'   => bool,
 *       'gate_failed'      => bool,
 *       'accounting_class' => string|null,
 *     ], ...
 *   ],
 * ]
 * Returns null when no doc_invoices row exists for the given ID.
 */

function fetch_recap_for_invoice(PDO $pdo, int $invoiceId): ?array
{
    // ── Header ────────────────────────────────────────────────────────────────
    $hStmt = $pdo->prepare(
        "SELECT supplier_name, invoice_ref, invoice_date,
                total_ht, currency
           FROM doc_invoices
          WHERE id = ?
          LIMIT 1"
    );
    $hStmt->execute([$invoiceId]);
    $header = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) {
        return null;
    }

    // ── Lines ─────────────────────────────────────────────────────────────────
    // Left-join ref_mi so mi_id + name are resolved in one query.
    $lStmt = $pdo->prepare(
        "SELECT
             il.line_index,
             il.ingredient_name,
             il.description,
             il.mi_id_fk,
             rm.mi_id            AS mi_code,
             rm.name             AS mi_name,
             il.qty,
             il.unit,
             il.unit_price,
             il.line_total,
             il.name_confidence,
             il.price_confidence,
             il.accounting_class,
             il.gate_failures
           FROM doc_invoice_lines il
           LEFT JOIN ref_mi rm ON rm.id = il.mi_id_fk
          WHERE il.invoice_id = ?
          ORDER BY il.line_index"
    );
    $lStmt->execute([$invoiceId]);
    $rawLines = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $lines = [];
    foreach ($rawLines as $l) {
        $gateFailures    = ($l['gate_failures'] !== null)
                           ? json_decode((string)$l['gate_failures'], true)
                           : null;
        $hasGateFailures = is_array($gateFailures) && count($gateFailures) > 0;

        $miUnresolved = ($l['mi_id_fk'] === null && $l['accounting_class'] === null);

        $nameConf  = ($l['name_confidence']  !== null) ? (float)$l['name_confidence']  : null;
        $priceConf = ($l['price_confidence'] !== null) ? (float)$l['price_confidence'] : null;
        $lowConf   = ($nameConf  !== null && $nameConf  < 0.95)
                  || ($priceConf !== null && $priceConf < 0.95);

        $miLabel = null;
        if ($l['mi_code'] !== null) {
            $miLabel = $l['mi_code'] . ' — ' . $l['mi_name'];
        }

        $desc = $l['ingredient_name'] ?? $l['description'] ?? null;

        $lines[] = [
            'line_index'       => (int)$l['line_index'],
            'mi_label'         => $miLabel,
            'mi_unresolved'    => $miUnresolved,
            'description'      => $desc !== null ? (string)$desc : null,
            'qty'              => ($l['qty']        !== null) ? (float)$l['qty']        : null,
            'unit'             => ($l['unit']        !== null) ? (string)$l['unit']     : null,
            'unit_price'       => ($l['unit_price']  !== null) ? (float)$l['unit_price'] : null,
            'line_total'       => ($l['line_total']  !== null) ? (float)$l['line_total'] : null,
            'low_confidence'   => $lowConf,
            'gate_failed'      => $hasGateFailures,
            'accounting_class' => ($l['accounting_class'] !== null) ? (string)$l['accounting_class'] : null,
        ];
    }

    return [
        'supplier_name' => ($header['supplier_name'] !== null && $header['supplier_name'] !== '')
                           ? (string)$header['supplier_name'] : null,
        'invoice_ref'   => ($header['invoice_ref']   !== null && $header['invoice_ref']   !== '')
                           ? (string)$header['invoice_ref']   : null,
        'invoice_date'  => ($header['invoice_date']  !== null) ? (string)$header['invoice_date'] : null,
        'total_ht'      => ($header['total_ht']      !== null) ? (float)$header['total_ht']      : null,
        'currency'      => ($header['currency']      !== null && $header['currency'] !== '')
                           ? (string)$header['currency'] : 'CHF',
        'lines'         => $lines,
    ];
}
