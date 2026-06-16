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
 *   'rate'         => {window_days, overall_pct, returned_units, sold_units, basis_count, by_beer, by_channel}
 *                     Numerator: physical-returns (rebate-excluded) from inv_sales_ledger
 *   'overship'     => {window_days, min_shipped_floor, watchlist[top 25 by overship_pct], excluded_low_basis}
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

    // ── rate: trailing-365-day return-rate block ──────────────────────────────
    // Window is hardcoded to 365 days (full trailing year) regardless of $periodDays,
    // same precedent as pending_count pinning 180d.
    // NOTE: sale_class column does not exist on inv_sales_ledger; customs-artifact
    // filter is omitted.

    // physical-returns numerator (rebate-excluded), distinct from disposition-based mix
    // Uses inv_sales_ledger directly — captures all physical returns even when not yet
    // dispositioned in ord_return_lines (which only ~1 return has been through).
    $rateNumStmt = $pdo->query(
        "SELECT SUM(l.qty_signed) AS returned_units,
                COUNT(*) AS basis_count
           FROM inv_sales_ledger l
           JOIN ref_skus rs ON rs.id = l.sku_id_fk
          WHERE l.doc_type IN ('credit','return_receipt')
            AND l.qty_signed > 0
            AND rs.recipe_id IS NOT NULL
            AND l.sku_id_fk IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM ord_returns r
                JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                WHERE r.origin_bc_document_no = l.bc_document_no
                  AND rl.sku_id_fk = l.sku_id_fk
                  AND rl.disposition = 'rebate'
            )"
    );
    $rateNumRow     = $rateNumStmt->fetch(PDO::FETCH_ASSOC);
    $rateReturned   = (float) ($rateNumRow['returned_units'] ?? 0);
    $rateBasisCount = (int)   ($rateNumRow['basis_count']   ?? 0);

    // Denominator: sold units (shipments + invoices, beer SKUs only)
    // sale_class column does not exist on inv_sales_ledger — no customs-artifact filter
    $rateDenStmt = $pdo->query(
        "SELECT GREATEST(0, -SUM(l.qty_signed)) AS sold_units
           FROM inv_sales_ledger l
           JOIN ref_skus rs ON rs.id = l.sku_id_fk
          WHERE l.doc_type IN ('shipment', 'invoice')
            AND l.sku_id_fk IS NOT NULL
            AND rs.recipe_id IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)"
    );
    $rateDenRow  = $rateDenStmt->fetch(PDO::FETCH_ASSOC);
    $rateSold    = (float) ($rateDenRow['sold_units'] ?? 0);

    $rateOverallPct = $rateSold > 0
        ? round($rateReturned / $rateSold * 100, 3)
        : 0.0;

    // Per-beer numerator: physical-returns numerator (rebate-excluded) grouped by recipe
    $rateBeerNumStmt = $pdo->query(
        "SELECT rs.recipe_id,
                COALESCE(rr.name, rs.sku_code) AS beer_label,
                SUM(l.qty_signed)              AS returned_units
           FROM inv_sales_ledger l
           JOIN ref_skus rs ON rs.id = l.sku_id_fk
           LEFT JOIN ref_recipes rr ON rr.id = rs.recipe_id
          WHERE l.doc_type IN ('credit','return_receipt')
            AND l.qty_signed > 0
            AND rs.recipe_id IS NOT NULL
            AND l.sku_id_fk IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM ord_returns r
                JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                WHERE r.origin_bc_document_no = l.bc_document_no
                  AND rl.sku_id_fk = l.sku_id_fk
                  AND rl.disposition = 'rebate'
            )
          GROUP BY rs.recipe_id, COALESCE(rr.name, rs.sku_code)"
    );
    $rateBeerNum = $rateBeerNumStmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-beer denominator (sold units by recipe)
    $rateBeerDenStmt = $pdo->query(
        "SELECT rs.recipe_id,
                COALESCE(rr.name, rs.sku_code)      AS beer_label,
                GREATEST(0, -SUM(l.qty_signed))      AS sold_units
           FROM inv_sales_ledger l
           JOIN ref_skus rs    ON rs.id = l.sku_id_fk
           LEFT JOIN ref_recipes rr ON rr.id = rs.recipe_id
          WHERE l.doc_type IN ('shipment', 'invoice')
            AND l.sku_id_fk IS NOT NULL
            AND rs.recipe_id IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
          GROUP BY rs.recipe_id, COALESCE(rr.name, rs.sku_code)"
    );
    // Index denominator by recipe_id for O(1) merge
    $rateBeerDenIdx = [];
    foreach ($rateBeerDenStmt->fetchAll(PDO::FETCH_ASSOC) as $denRow) {
        $rateBeerDenIdx[(int) $denRow['recipe_id']] = (float) $denRow['sold_units'];
    }

    $byBeerRate = [];
    foreach ($rateBeerNum as $numRow) {
        $recipeId    = (int)   $numRow['recipe_id'];
        $beerLabel   = (string) $numRow['beer_label'];
        $beerRet     = (float)  $numRow['returned_units'];
        $beerSold    = $rateBeerDenIdx[$recipeId] ?? 0.0;
        $beerRatePct = $beerSold > 0 ? round($beerRet / $beerSold * 100, 3) : 0.0;
        $byBeerRate[] = [
            'beer_label'      => $beerLabel,
            'returned_units'  => $beerRet,
            'sold_units'      => $beerSold,
            'rate_pct'        => $beerRatePct,
        ];
    }
    // DESC by returned_units
    usort($byBeerRate, fn($a, $b) => $b['returned_units'] <=> $a['returned_units']);

    // Per-channel numerator: physical-returns numerator (rebate-excluded) grouped by trade_channel
    $rateChanNumStmt = $pdo->query(
        "SELECT rc.trade_channel,
                SUM(l.qty_signed) AS returned_units
           FROM inv_sales_ledger l
           JOIN ref_skus rs ON rs.id = l.sku_id_fk
           LEFT JOIN ref_customers rc ON rc.id = l.customer_id_fk
          WHERE l.doc_type IN ('credit','return_receipt')
            AND l.qty_signed > 0
            AND rs.recipe_id IS NOT NULL
            AND l.sku_id_fk IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM ord_returns r
                JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                WHERE r.origin_bc_document_no = l.bc_document_no
                  AND rl.sku_id_fk = l.sku_id_fk
                  AND rl.disposition = 'rebate'
            )
          GROUP BY rc.trade_channel"
    );
    $rateChanNumRaw = $rateChanNumStmt->fetchAll(PDO::FETCH_ASSOC);
    // Index by channel key (NULL → 'non classé')
    $rateChanNumIdx = [];
    foreach ($rateChanNumRaw as $chanRow) {
        $key = $chanRow['trade_channel'] ?? 'non classé';
        $rateChanNumIdx[$key] = (float) $chanRow['returned_units'];
    }

    // Per-channel denominator
    $rateChanDenStmt = $pdo->query(
        "SELECT rc.trade_channel,
                GREATEST(0, -SUM(l.qty_signed)) AS sold_units
           FROM inv_sales_ledger l
           JOIN ref_skus rs    ON rs.id = l.sku_id_fk
           LEFT JOIN ref_customers rc ON rc.id = l.customer_id_fk
          WHERE l.doc_type IN ('shipment', 'invoice')
            AND l.sku_id_fk IS NOT NULL
            AND rs.recipe_id IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
          GROUP BY rc.trade_channel"
    );
    $rateChanDenRaw = $rateChanDenStmt->fetchAll(PDO::FETCH_ASSOC);
    $rateChanDenIdx = [];
    foreach ($rateChanDenRaw as $chanRow) {
        $key = $chanRow['trade_channel'] ?? 'non classé';
        $rateChanDenIdx[$key] = (float) $chanRow['sold_units'];
    }

    // Build all three buckets, always emit all three
    $byChannelRate = [];
    foreach (['on_trade', 'off_trade', 'non classé'] as $chanKey) {
        $chanRet  = $rateChanNumIdx[$chanKey] ?? 0.0;
        $chanSold = $rateChanDenIdx[$chanKey] ?? 0.0;
        $byChannelRate[] = [
            'channel'        => $chanKey,
            'returned_units' => $chanRet,
            'sold_units'     => $chanSold,
            'rate_pct'       => $chanSold > 0 ? round($chanRet / $chanSold * 100, 3) : 0.0,
        ];
    }

    $rate = [
        'window_days'    => 365,
        'overall_pct'    => $rateOverallPct,
        'returned_units' => $rateReturned,
        'sold_units'     => $rateSold,
        'basis_count'    => $rateBasisCount,
        'by_beer'        => $byBeerRate,
        'by_channel'     => $byChannelRate,
    ];

    // ── overship: per-customer over-ship watchlist (trailing 365 days) ────────
    // Documented floor constant — customers below this shipped volume are excluded to kill noise
    $minShipped = 12;

    $overshipStmt = $pdo->query(
        "SELECT
            l.customer_id_fk,
            rc.name AS customer_name,
            rc.trade_channel,
            -- physical returned units (rebate-excluded), same def as rate numerator
            SUM(CASE WHEN l.doc_type IN ('credit','return_receipt') AND l.qty_signed > 0
                         AND NOT EXISTS (
                             SELECT 1 FROM ord_returns r2
                             JOIN ord_return_lines rl2 ON rl2.return_id_fk = r2.id
                             WHERE r2.origin_bc_document_no = l.bc_document_no
                               AND rl2.sku_id_fk = l.sku_id_fk
                               AND rl2.disposition = 'rebate'
                         )
                     THEN l.qty_signed ELSE 0 END) AS returned_units,
            -- shipped units
            GREATEST(0, -SUM(CASE WHEN l.doc_type IN ('shipment','invoice') THEN l.qty_signed ELSE 0 END)) AS shipped_units,
            -- count of return ledger lines
            SUM(CASE WHEN l.doc_type IN ('credit','return_receipt') AND l.qty_signed > 0 THEN 1 ELSE 0 END) AS return_lines
        FROM inv_sales_ledger l
        JOIN ref_skus rs ON rs.id = l.sku_id_fk
        LEFT JOIN ref_customers rc ON rc.id = l.customer_id_fk
        WHERE l.sku_id_fk IS NOT NULL
          AND rs.recipe_id IS NOT NULL
          AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
          AND l.customer_id_fk IS NOT NULL
        GROUP BY l.customer_id_fk, rc.name, rc.trade_channel
        HAVING returned_units > 0"
    );
    $overshipRaw = $overshipStmt->fetchAll(PDO::FETCH_ASSOC);

    $overshipWatchlist  = [];
    $excludedLowBasis   = 0;
    foreach ($overshipRaw as $osRow) {
        $shippedUnits = (float) $osRow['shipped_units'];
        if ($shippedUnits < $minShipped) {
            $excludedLowBasis++;
            continue;
        }
        $returnedUnits = (float) $osRow['returned_units'];
        $overshipPct   = $shippedUnits > 0
            ? round(100 * $returnedUnits / $shippedUnits, 1)
            : 0.0;
        $overshipWatchlist[] = [
            'customer_id'    => (int)    $osRow['customer_id_fk'],
            'customer_name'  => (string) ($osRow['customer_name'] ?? ''),
            'trade_channel'  => (string) ($osRow['trade_channel'] ?? 'non classé'),
            'shipped_units'  => $shippedUnits,
            'returned_units' => $returnedUnits,
            'overship_pct'   => $overshipPct,
            'return_lines'   => (int) $osRow['return_lines'],
        ];
    }
    // Sort by overship_pct DESC, limit to top 25
    usort($overshipWatchlist, fn($a, $b) => $b['overship_pct'] <=> $a['overship_pct']);
    $overshipWatchlist = array_slice($overshipWatchlist, 0, 25);

    $overship = [
        'window_days'        => 365,
        'min_shipped_floor'  => $minShipped,
        'watchlist'          => $overshipWatchlist,
        'excluded_low_basis' => $excludedLowBasis,
    ];

    return [
        'by_client'     => $byClient,
        'by_beer'       => $byBeer,
        'mix'           => $mix,
        'period_days'   => $periodDays,
        'pending_count' => $pendingCount,
        'rate'          => $rate,
        'overship'      => $overship,
    ];
}
