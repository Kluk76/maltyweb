<?php
/**
 * oversell.php — ATP-breach (oversell) detector.
 *
 * Reads fg_stock_compute() output and attributes shortfalls by trade_channel.
 *
 * ASSUMPTION — proportional attribution:
 * Each oversold SKU's units_short is distributed across trade channels
 * (on_trade, off_trade, unclassified) proportionally to each channel's share
 * of that SKU's total open order commitment (ord_order_lines WHERE
 * line_status='to_fulfil' and order status NOT IN ('shipped','cancelled')).
 * This mirrors exactly the open_total_qty computation in fg_stock_compute()
 * Step 7. If a SKU is oversold but has zero attributable open commitment
 * (edge case: negative live_futur driven by non-order factors), the full
 * shortfall lands in 'unclassified'.
 *
 * trade_channel is from ref_customers and is an ENUM('on_trade','off_trade')
 * with NULL possible. NULL/unknown → 'unclassified' bucket.
 *
 * @package maltyweb
 */

require_once __DIR__ . '/fg-stock.php';

/**
 * Detect currently oversold SKUs and attribute shortfall by trade channel.
 *
 * @param PDO $pdo
 * @return array{
 *   basis_date: string,
 *   oversold_count: int,
 *   total_units_short: float,
 *   by_channel: array{on_trade: float, off_trade: float, unclassified: float},
 *   skus: list<array{
 *     sku_id: int,
 *     sku_code: string,
 *     beer_label: string,
 *     units_short: float,
 *     live_futur: float,
 *     couverture: mixed,
 *     open_total_qty: float,
 *     on_trade_short: float,
 *     off_trade_short: float,
 *     unclassified_short: float
 *   }>
 * }
 */
function oversell_current(PDO $pdo): array
{
    // Step 1: get full FG stock result from the canonical compute function.
    $result = fg_stock_compute($pdo);
    $rows   = $result['rows'] ?? [];

    // Step 2: filter to oversold SKUs (live_futur < 0).
    $oversoldRows = array_filter($rows, static function (array $row): bool {
        return $row['flag_survendu'] === true;
    });

    if (empty($oversoldRows)) {
        return [
            'basis_date'        => date('Y-m-d'),
            'oversold_count'    => 0,
            'total_units_short' => 0.0,
            'by_channel'        => ['on_trade' => 0.0, 'off_trade' => 0.0, 'unclassified' => 0.0],
            'skus'              => [],
        ];
    }

    // Step 3: for each oversold SKU, compute channel attribution.
    // Query mirrors fg_stock_compute() Step 7 EXACTLY:
    //   FROM ord_order_lines l
    //   JOIN ord_orders o ON o.id = l.order_id_fk
    //   WHERE o.status NOT IN ('shipped', 'cancelled')
    //     AND l.line_status = 'to_fulfil'
    // Plus: JOIN ref_customers c ON c.id = o.customer_id_fk, GROUP BY trade_channel.
    $channelStmt = $pdo->prepare(
        "SELECT c.trade_channel,
                SUM(l.qty) AS channel_qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
           LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
          WHERE o.status NOT IN ('shipped', 'cancelled')
            AND l.line_status = 'to_fulfil'
            AND l.sku_id_fk = ?
          GROUP BY c.trade_channel"
    );

    $resultSkus         = [];
    $totalUnitsShort    = 0.0;
    $sumOnTrade         = 0.0;
    $sumOffTrade        = 0.0;
    $sumUnclassified    = 0.0;

    foreach ($oversoldRows as $row) {
        $skuId         = (int) $row['sku_id'];
        $liveFutur     = (float) $row['live_futur'];       // negative
        $unitsShort    = -$liveFutur;                       // positive
        $openTotalQty  = (float) ($row['open_total_qty'] ?? 0.0);

        // Fetch open-order commitment by trade_channel for this SKU.
        $channelStmt->execute([$skuId]);
        $channelRows = $channelStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build channel map: on_trade / off_trade / unclassified.
        $channelMap = ['on_trade' => 0.0, 'off_trade' => 0.0, 'unclassified' => 0.0];
        $totalOpen  = 0.0;
        foreach ($channelRows as $cr) {
            $ch  = $cr['trade_channel'];
            $qty = (float) $cr['channel_qty'];
            if ($ch === 'on_trade') {
                $channelMap['on_trade'] += $qty;
            } elseif ($ch === 'off_trade') {
                $channelMap['off_trade'] += $qty;
            } else {
                // NULL trade_channel (internal orders, eshop, taproom without a
                // ref_customers row) → unclassified.
                $channelMap['unclassified'] += $qty;
            }
            $totalOpen += $qty;
        }

        // Proportional attribution.
        if ($totalOpen > 0.0) {
            $onTradeShort      = $unitsShort * ($channelMap['on_trade']      / $totalOpen);
            $offTradeShort     = $unitsShort * ($channelMap['off_trade']     / $totalOpen);
            $unclassifiedShort = $unitsShort * ($channelMap['unclassified']  / $totalOpen);
        } else {
            // Edge case: oversold but zero open-order lines (non-order driven shortfall).
            $onTradeShort      = 0.0;
            $offTradeShort     = 0.0;
            $unclassifiedShort = $unitsShort;
        }

        $totalUnitsShort += $unitsShort;
        $sumOnTrade      += $onTradeShort;
        $sumOffTrade     += $offTradeShort;
        $sumUnclassified += $unclassifiedShort;

        $resultSkus[] = [
            'sku_id'             => $skuId,
            'sku_code'           => $row['sku_code'],
            'beer_label'         => $row['display_family'],
            'units_short'        => round($unitsShort, 2),
            'live_futur'         => $liveFutur,
            'couverture'         => $row['couverture'] ?? null,
            'open_total_qty'     => $openTotalQty,
            'on_trade_short'     => round($onTradeShort, 2),
            'off_trade_short'    => round($offTradeShort, 2),
            'unclassified_short' => round($unclassifiedShort, 2),
        ];
    }

    // Re-index result skus to guarantee list shape.
    $resultSkus = array_values($resultSkus);

    return [
        'basis_date'        => date('Y-m-d'),
        'oversold_count'    => count($resultSkus),
        'total_units_short' => round($totalUnitsShort, 2),
        'by_channel'        => [
            'on_trade'      => round($sumOnTrade, 2),
            'off_trade'     => round($sumOffTrade, 2),
            'unclassified'  => round($sumUnclassified, 2),
        ],
        'skus' => $resultSkus,
    ];
}
