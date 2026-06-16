<?php
declare(strict_types=1);

/**
 * Returns synthèse compute helper.
 * Single source of truth for returns aggregates consumed by:
 *   - expeditions.php ?view=retours (Synthèse panel)
 *   - kpi-handlers.php kpi_logi_returns_synthese()
 *
 * CARDINAL: disposition='rebate' is EXCLUDED from all volume figures.
 * Volume = restock + scrap + quarantine only.
 *
 * Returns:
 * [
 *   'by_client'    => [{customer_name, total_units, restock_units, scrap_units, quarantine_units}] DESC by total
 *   'by_beer'      => [{beer_label, total_units}] DESC by total
 *   'mix'          => {restock_units, scrap_units, quarantine_units, total_units, restock_pct, scrap_pct, quarantine_pct}
 *   'period_days'  => int
 *   'pending_count'=> int (always over 180-day window to match the pending queue)
 * ]
 */
function returns_synthese(PDO $pdo, int $periodDays = 90): array
{
    // ── by_client: group by customer resolved from inv_sales_ledger ──────────
    $byClientStmt = $pdo->prepare(
        "SELECT COALESCE(
                  (SELECT MIN(rc.name)
                     FROM inv_sales_ledger l2
                     JOIN ref_customers rc ON rc.id = l2.customer_id_fk
                    WHERE l2.bc_document_no = r.origin_bc_document_no
                      AND l2.customer_id_fk IS NOT NULL
                    LIMIT 1),
                  r.origin_bc_document_no
                ) AS customer_name,
                SUM(rl.qty)                                                          AS total_units,
                SUM(CASE WHEN rl.disposition = 'restock'    THEN rl.qty ELSE 0 END) AS restock_units,
                SUM(CASE WHEN rl.disposition = 'scrap'      THEN rl.qty ELSE 0 END) AS scrap_units,
                SUM(CASE WHEN rl.disposition = 'quarantine' THEN rl.qty ELSE 0 END) AS quarantine_units
           FROM ord_returns r
           JOIN ord_return_lines rl ON rl.return_id_fk = r.id
          WHERE r.origin_posting_date >= DATE_SUB(CURDATE(), INTERVAL :periodDays DAY)
            AND rl.disposition IN ('restock', 'scrap', 'quarantine')
          GROUP BY r.origin_bc_document_no
          ORDER BY total_units DESC"
    );
    $byClientStmt->bindValue(':periodDays', $periodDays, PDO::PARAM_INT);
    $byClientStmt->execute();
    $byClient = $byClientStmt->fetchAll(PDO::FETCH_ASSOC);
    // Cast numerics
    foreach ($byClient as &$row) {
        $row['total_units']      = (float) $row['total_units'];
        $row['restock_units']    = (float) $row['restock_units'];
        $row['scrap_units']      = (float) $row['scrap_units'];
        $row['quarantine_units'] = (float) $row['quarantine_units'];
    }
    unset($row);

    // ── by_beer: group by recipe ──────────────────────────────────────────────
    $byBeerStmt = $pdo->prepare(
        "SELECT COALESCE(rr.name, s.sku_code) AS beer_label,
                SUM(rl.qty)                   AS total_units
           FROM ord_return_lines rl
           JOIN ord_returns r  ON r.id = rl.return_id_fk
           JOIN ref_skus s     ON s.id = rl.sku_id_fk
           LEFT JOIN ref_recipes rr ON rr.id = s.recipe_id
          WHERE r.origin_posting_date >= DATE_SUB(CURDATE(), INTERVAL :periodDays DAY)
            AND rl.disposition IN ('restock', 'scrap', 'quarantine')
          GROUP BY rr.id, COALESCE(rr.name, s.sku_code)
          ORDER BY total_units DESC"
    );
    $byBeerStmt->bindValue(':periodDays', $periodDays, PDO::PARAM_INT);
    $byBeerStmt->execute();
    $byBeer = $byBeerStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($byBeer as &$row) {
        $row['total_units'] = (float) $row['total_units'];
    }
    unset($row);

    // ── mix: derive from $byClient (no extra SQL round-trip needed) ──────────
    // $byClient already contains per-document restock/scrap/quarantine aggregates;
    // summing in PHP is equivalent to a second GROUP-less query on the same window.
    $restockUnits    = (float) array_sum(array_column($byClient, 'restock_units'));
    $scrapUnits      = (float) array_sum(array_column($byClient, 'scrap_units'));
    $quarantineUnits = (float) array_sum(array_column($byClient, 'quarantine_units'));
    $totalUnits      = $restockUnits + $scrapUnits + $quarantineUnits;

    $mix = [
        'restock_units'    => $restockUnits,
        'scrap_units'      => $scrapUnits,
        'quarantine_units' => $quarantineUnits,
        'total_units'      => $totalUnits,
        'restock_pct'      => $totalUnits > 0 ? round(100 * $restockUnits    / $totalUnits, 1) : 0.0,
        'scrap_pct'        => $totalUnits > 0 ? round(100 * $scrapUnits      / $totalUnits, 1) : 0.0,
        'quarantine_pct'   => $totalUnits > 0 ? round(100 * $quarantineUnits / $totalUnits, 1) : 0.0,
    ];

    // ── pending_count: ALWAYS 180-day window (must match the P1 pending queue) ─
    // Do NOT use $periodDays here — the operator sees a queue over 180 days;
    // using a narrower window would make this count disagree with the visible queue.
    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
           FROM inv_sales_ledger l
           JOIN ref_skus rs ON rs.id = l.sku_id_fk
          WHERE l.doc_type IN ('credit', 'return_receipt')
            AND l.qty_signed > 0
            AND rs.recipe_id IS NOT NULL
            AND l.sku_id_fk IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM ord_returns r
                JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                WHERE r.origin_bc_document_no = l.bc_document_no
                  AND rl.sku_id_fk = l.sku_id_fk
            )"
    );
    $pendingStmt->execute();
    $pendingCount = (int) ($pendingStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

    return [
        'by_client'     => $byClient,
        'by_beer'       => $byBeer,
        'mix'           => $mix,
        'period_days'   => $periodDays,
        'pending_count' => $pendingCount,
    ];
}
