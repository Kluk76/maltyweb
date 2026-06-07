<?php
declare(strict_types=1);
/**
 * app/fg-stock.php — FG live-stock computation helpers.
 *
 * Architecture contract (NON-NEGOTIABLE): stock is DERIVED, never stored.
 * No new tables, no caches.
 *
 * Per-SKU physique formula:
 *   anchor      = inv_fg_stocktake qty, latest month_closed with is_active rows
 *   anchor_date = LAST DAY of the anchor month_closed
 *   production  = Σ (bd_packaging_v2.prod_total_units / ref_skus.units_per_pack)
 *                 WHERE sku_id_fk AND event_date > anchor_date AND is_tombstoned=0
 *                 NOTE: prod_total_units is in individual containers; divide by
 *                 units_per_pack to convert to SKU pack units (same as anchor).
 *   expédié_b2b = Σ ord_order_lines.qty over ord_orders WHERE status='shipped'
 *                 AND requested_date > anchor_date  (ALL order_types)
 *   eshop_auto  = Σ inv_sales_order_lines.qty over inv_sales_orders WHERE
 *                 channel='eshop' AND DATE(created_at) > anchor_date
 *                 SOURCE CHOICE: inv_sales_orders is order-grained and preferred
 *                 over inv_sales_bc (fiscal record, no 'eshop' channel).
 *                 Note: inv_sales_bc only carries b2b + taproom channels.
 *   taproom_auto= Σ inv_sales_bc.qty_invoiced WHERE channel='taproom'
 *                 AND period > anchor_month AND sku_id_fk IS NOT NULL
 *                 SOURCE CHOICE: inv_sales_orders has no taproom rows (only
 *                 eshop source per inspection 2026-06-07); inv_sales_bc taproom
 *                 is the sole fiscal-grade taproom depletion source.
 *
 * Velocity:
 *   trailing 8-week avg weekly depletion = (b2b_qty + taproom_qty + eshop_qty)
 *   over the 56 days ending at the most recent depletion data.
 *   Source: inv_sales_bc (b2b+taproom, period-based → map to days in period)
 *           + inv_sales_order_lines (eshop, date-based).
 *   If the 56-day window spans periods with no data → velocity = null → "∞".
 *
 * Pure SELECT queries only. No N+1. No writes.
 *
 * Public API:
 *   fg_stock_compute(PDO $pdo): array   — full per-SKU result + meta
 *   fg_stock_for_skus(PDO $pdo, array $skuIds): array — filtered subset
 */

/**
 * Compute live FG stock for all SKUs.
 *
 * @return array{
 *   anchor_month: string,       e.g. '2026-04'
 *   anchor_date:  string,       e.g. '2026-04-30' (last day of anchor month)
 *   rows:         array,        per-SKU rows (see below)
 *   computed_at:  string,       ISO datetime
 * }
 *
 * Each row: {
 *   sku_id, sku_code, format, hl_per_unit,
 *   anchor_qty, prod_qty, expedie_qty, eshop_qty, taproom_qty,
 *   physique,           float: anchor + prod - expedie - eshop - taproom
 *   open_week_qty,      int: Σ open order lines with requested_date ≤ end-of-current-ISO-week
 *   open_total_qty,     int: Σ ALL open order lines
 *   live_semaine,       int: physique - open_week_qty
 *   live_futur,         int: physique - open_total_qty
 *   velocity_weekly,    float|null: trailing 8-week avg weekly depletion (null = no data)
 *   semaines_stock,     float|null: physique / velocity_weekly (null if velocity=0 or null)
 *   flag_survendu,      bool: live_futur < 0
 *   flag_low_stock,     bool: semaines_stock < 2 AND physique > 0
 *   flag_dormant,       bool: physique=0 AND no movement since anchor
 *   // Drill-down subtotals (event counts):
 *   prod_events,        int: number of packaging events
 *   expedie_orders,     int: number of distinct shipped orders touching this SKU
 *   eshop_orders,       int: number of eshop inv_sales_orders lines
 *   taproom_rows,       int: number of inv_sales_bc taproom rows
 * }
 */
function fg_stock_compute(PDO $pdo): array
{
    // ── Step 1: find anchor month (latest month_closed with is_active rows) ──
    $anchorStmt = $pdo->query(
        'SELECT MAX(month_closed) AS mc
           FROM inv_fg_stocktake
          WHERE is_active = 1'
    );
    $anchorRow = $anchorStmt->fetch(PDO::FETCH_ASSOC);
    $anchorMonth = $anchorRow['mc'] ?? null;

    if ($anchorMonth === null) {
        // No anchor exists yet — return empty state
        return [
            'anchor_month' => null,
            'anchor_date'  => null,
            'rows'         => [],
            'computed_at'  => date('c'),
        ];
    }

    // Compute last-day-of-anchor-month
    $anchorDate = date('Y-m-d', strtotime('last day of ' . $anchorMonth));

    // ── Step 2: anchor qty per sku_id_fk ────────────────────────────────────
    $anchorStmt2 = $pdo->prepare(
        'SELECT s.sku_id_fk,
                r.sku_code,
                r.format,
                r.hl_per_unit,
                CAST(s.qty AS SIGNED) AS anchor_qty
           FROM inv_fg_stocktake s
           JOIN ref_skus r ON r.id = s.sku_id_fk
          WHERE s.month_closed = ?
            AND s.is_active = 1
          ORDER BY r.format ASC, r.sku_code ASC'
    );
    $anchorStmt2->execute([$anchorMonth]);
    $anchorRows = $anchorStmt2->fetchAll(PDO::FETCH_ASSOC);

    // Index by sku_id for quick lookup
    $byId = [];
    foreach ($anchorRows as $ar) {
        $sid = (int) $ar['sku_id_fk'];
        $byId[$sid] = [
            'sku_id'      => $sid,
            'sku_code'    => $ar['sku_code'],
            'format'      => $ar['format'],
            'hl_per_unit' => (float) $ar['hl_per_unit'],
            'anchor_qty'  => (int) $ar['anchor_qty'],
            // flows (filled below)
            'prod_qty'     => 0,
            'prod_events'  => 0,
            'expedie_qty'  => 0,
            'expedie_orders' => 0,
            'eshop_qty'    => 0,
            'eshop_orders' => 0,
            'taproom_qty'  => 0,
            'taproom_rows' => 0,
        ];
    }

    // ── Step 3: production since anchor (bd_packaging_v2) ───────────────────
    // prod_total_units is in INDIVIDUAL CONTAINERS (bottles, cans, kegs).
    // All other legs (anchor, orders, eshop, taproom) are in SKU PACK UNITS.
    // For bottles/cans: units_per_pack = 24 → divide to get pack units.
    // For kegs/cuves: units_per_pack = 1 → division is a no-op.
    // Lesson: same class of bug as maltytask commit 942431e (~24x inflation).
    $prodStmt = $pdo->prepare(
        'SELECT p.sku_id_fk,
                SUM(p.prod_total_units / COALESCE(NULLIF(r.units_per_pack, 0), 1)) AS prod_qty,
                COUNT(*)                                                             AS prod_events
           FROM bd_packaging_v2 p
           JOIN ref_skus r ON r.id = p.sku_id_fk
          WHERE p.event_date > ?
            AND p.is_tombstoned = 0
            AND p.sku_id_fk IS NOT NULL
          GROUP BY p.sku_id_fk'
    );
    $prodStmt->execute([$anchorDate]);
    foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $sid = (int) $pr['sku_id_fk'];
        if (isset($byId[$sid])) {
            $byId[$sid]['prod_qty']    = round((float) $pr['prod_qty'], 2);
            $byId[$sid]['prod_events'] = (int) $pr['prod_events'];
        }
        // Production of a SKU not in the anchor (new SKU added post-anchor):
        // add a minimal placeholder row so it appears in the table.
        else {
            // Fetch SKU metadata on-the-fly (rare case; accepted extra query)
            $skuMeta = $pdo->prepare(
                'SELECT sku_code, format, hl_per_unit, units_per_pack FROM ref_skus WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $skuMeta->execute([$sid]);
            $meta = $skuMeta->fetch(PDO::FETCH_ASSOC);
            if ($meta !== false) {
                $byId[$sid] = [
                    'sku_id'         => $sid,
                    'sku_code'       => $meta['sku_code'],
                    'format'         => $meta['format'],
                    'hl_per_unit'    => (float) $meta['hl_per_unit'],
                    'anchor_qty'     => 0,
                    'prod_qty'       => round((float) $pr['prod_qty'], 2),
                    'prod_events'    => (int) $pr['prod_events'],
                    'expedie_qty'    => 0,
                    'expedie_orders' => 0,
                    'eshop_qty'      => 0,
                    'eshop_orders'   => 0,
                    'taproom_qty'    => 0,
                    'taproom_rows'   => 0,
                ];
            }
        }
    }

    // ── Step 4: expédié B2B (ord_order_lines, status='shipped') ─────────────
    // ALL order_types deplete here — internal channels (cage/shop/taproom/eshop
    // manual) also decrement physical stock when their order is shipped.
    $expStmt = $pdo->prepare(
        'SELECT l.sku_id_fk,
                SUM(l.qty)                            AS expedie_qty,
                COUNT(DISTINCT l.order_id_fk)         AS expedie_orders
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
          WHERE o.status = ?
            AND o.requested_date > ?
          GROUP BY l.sku_id_fk'
    );
    $expStmt->execute(['shipped', $anchorDate]);
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $sid = (int) $er['sku_id_fk'];
        if (isset($byId[$sid])) {
            $byId[$sid]['expedie_qty']    = (int) $er['expedie_qty'];
            $byId[$sid]['expedie_orders'] = (int) $er['expedie_orders'];
        }
    }

    // ── Step 5: eshop auto (inv_sales_order_lines via inv_sales_orders) ──────
    // SOURCE CHOICE: inv_sales_orders is order-grained and preferred over
    // inv_sales_bc (which carries b2b + taproom only, no 'eshop' channel).
    // Both tables confirmed by inspection (2026-06-07): inv_sales_orders has
    // ONLY channel='eshop'; inv_sales_bc has ONLY b2b + taproom.
    // No overlap risk.
    $eshopStmt = $pdo->prepare(
        'SELECT isol.sku_id_fk,
                SUM(isol.qty)   AS eshop_qty,
                COUNT(*)        AS eshop_orders
           FROM inv_sales_order_lines isol
           JOIN inv_sales_orders iso ON iso.id = isol.order_id_fk
          WHERE iso.channel = ?
            AND DATE(iso.created_at) > ?
            AND isol.sku_id_fk IS NOT NULL
          GROUP BY isol.sku_id_fk'
    );
    $eshopStmt->execute(['eshop', $anchorDate]);
    foreach ($eshopStmt->fetchAll(PDO::FETCH_ASSOC) as $es) {
        $sid = (int) $es['sku_id_fk'];
        if (isset($byId[$sid])) {
            $byId[$sid]['eshop_qty']    = (int) $es['eshop_qty'];
            $byId[$sid]['eshop_orders'] = (int) $es['eshop_orders'];
        }
    }

    // ── Step 6: taproom auto (inv_sales_bc, channel='taproom') ──────────────
    // SOURCE CHOICE: inv_sales_orders has NO taproom rows (sole source is
    // inv_sales_bc). Confirmed by inspection (2026-06-07): inv_sales_orders
    // channel ENUM only contains 'eshop'. inv_sales_bc has taproom since 2026-01.
    // Filter: period > anchor_month (YYYY-MM comparison on CHAR(7) is safe).
    $tapStmt = $pdo->prepare(
        'SELECT sku_id_fk,
                SUM(qty_invoiced)    AS taproom_qty,
                COUNT(*)             AS taproom_rows
           FROM inv_sales_bc
          WHERE channel    = ?
            AND period     > ?
            AND sku_id_fk IS NOT NULL
          GROUP BY sku_id_fk'
    );
    $tapStmt->execute(['taproom', $anchorMonth]);
    foreach ($tapStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $sid = (int) $tr['sku_id_fk'];
        if (isset($byId[$sid])) {
            $byId[$sid]['taproom_qty']  = (int) round((float) $tr['taproom_qty']);
            $byId[$sid]['taproom_rows'] = (int) $tr['taproom_rows'];
        }
    }

    // ── Step 7: open order lines (for live_semaine / live_futur) ────────────
    $isoWeekEnd = fg_stock_iso_week_end(date('Y-m-d'));

    $openStmt = $pdo->prepare(
        "SELECT l.sku_id_fk,
                SUM(CASE WHEN o.requested_date <= ? THEN l.qty ELSE 0 END) AS week_qty,
                SUM(l.qty)                                                   AS total_qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
          WHERE o.status NOT IN ('shipped', 'cancelled')
          GROUP BY l.sku_id_fk"
    );
    $openStmt->execute([$isoWeekEnd]);
    $openBySkuId = [];
    foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) as $or) {
        $openBySkuId[(int) $or['sku_id_fk']] = [
            'week_qty'  => (int) $or['week_qty'],
            'total_qty' => (int) $or['total_qty'],
        ];
    }

    // ── Step 8: velocity — trailing 56-day avg weekly depletion ─────────────
    // Data sources:
    //   inv_sales_bc  — b2b + taproom (period-based YYYY-MM, use days_in_period)
    //   inv_sales_order_lines/orders — eshop (date-based)
    //
    // We compute the depletion over the trailing 56 calendar days ending on
    // the last date we have data (= MAX date in each source), then divide by 8.
    // If no data at all for a SKU → velocity = null → semaines_stock shown as "∞".
    //
    // For inv_sales_bc (period-based): allocate the period's qty evenly across
    // days of the month, then multiply by the days that fall in our window.
    // E.g. 2026-04 (30 days): if window covers 2026-03-13..2026-05-07, that
    // period contributes (days of April in window / 30) × total_qty.
    //
    // Trailing window: [today−56 days, today] (inclusive).
    $today   = date('Y-m-d');
    $winStart = date('Y-m-d', strtotime('-56 days', strtotime($today)));
    $winEnd   = $today;

    // inv_sales_bc b2b + taproom (period = YYYY-MM CHAR(7))
    // We only need periods that OVERLAP with our window.
    // period >= month(winStart) AND period <= month(winEnd) covers it.
    $winStartMonth = substr($winStart, 0, 7);
    $winEndMonth   = substr($winEnd, 0, 7);

    $velBcStmt = $pdo->prepare(
        'SELECT sku_id_fk,
                period,
                SUM(qty_invoiced) AS qty
           FROM inv_sales_bc
          WHERE period >= ?
            AND period <= ?
            AND sku_id_fk IS NOT NULL
          GROUP BY sku_id_fk, period'
    );
    $velBcStmt->execute([$winStartMonth, $winEndMonth]);
    // Accumulate fractional days contribution per SKU
    $velQty = []; // sku_id => total qty-days / 7 (weekly equiv)
    foreach ($velBcStmt->fetchAll(PDO::FETCH_ASSOC) as $vr) {
        $sid    = (int) $vr['sku_id_fk'];
        $period = (string) $vr['period'];
        $qty    = (float) $vr['qty'];
        // Days in this period's month
        $daysInMonth = (int) date('t', strtotime($period . '-01'));
        // Overlap of this month with [winStart, winEnd]
        $monthStart = $period . '-01';
        $monthEnd   = $period . '-' . sprintf('%02d', $daysInMonth);
        $overlapStart = max($winStart, $monthStart);
        $overlapEnd   = min($winEnd, $monthEnd);
        $overlapDays = max(0, (int) round(
            (strtotime($overlapEnd) - strtotime($overlapStart)) / 86400
        ) + 1);
        if ($overlapDays <= 0 || $daysInMonth <= 0) continue;
        $contribution = $qty * ($overlapDays / $daysInMonth);
        $velQty[$sid] = ($velQty[$sid] ?? 0.0) + $contribution;
    }

    // eshop from inv_sales_order_lines (date-based, 56-day window)
    $velEshopStmt = $pdo->prepare(
        'SELECT isol.sku_id_fk,
                SUM(isol.qty) AS qty
           FROM inv_sales_order_lines isol
           JOIN inv_sales_orders iso ON iso.id = isol.order_id_fk
          WHERE iso.channel = ?
            AND DATE(iso.created_at) >= ?
            AND DATE(iso.created_at) <= ?
            AND isol.sku_id_fk IS NOT NULL
          GROUP BY isol.sku_id_fk'
    );
    $velEshopStmt->execute(['eshop', $winStart, $winEnd]);
    foreach ($velEshopStmt->fetchAll(PDO::FETCH_ASSOC) as $ve) {
        $sid = (int) $ve['sku_id_fk'];
        $velQty[$sid] = ($velQty[$sid] ?? 0.0) + (float) $ve['qty'];
    }
    // Convert 56-day total to per-week average (/8)
    $velWeekly = [];
    foreach ($velQty as $sid => $total) {
        $velWeekly[$sid] = $total / 8.0;
    }

    // ── Step 9: assemble output rows ─────────────────────────────────────────
    $rows = [];
    foreach ($byId as $sid => $r) {
        $anchor   = $r['anchor_qty'];
        $prod     = $r['prod_qty'];
        $expedie  = $r['expedie_qty'];
        $eshop    = $r['eshop_qty'];
        $taproom  = $r['taproom_qty'];
        $physique = $anchor + $prod - $expedie - $eshop - $taproom;

        $openWeek  = $openBySkuId[$sid]['week_qty']  ?? 0;
        $openTotal = $openBySkuId[$sid]['total_qty'] ?? 0;

        $liveSemaine = $physique - $openWeek;
        $liveFutur   = $physique - $openTotal;

        $vel     = $velWeekly[$sid] ?? null;
        $semaines = null;
        if ($vel !== null && $vel > 0.0 && $physique > 0) {
            $semaines = $physique / $vel;
        }

        // Dormant: physique=0 AND no movement (prod=0, expedie=0, eshop=0, taproom=0)
        // Use == 0 (loose) to handle float physique (e.g. 0.0 === 0 is false in PHP).
        $isDormant = ($physique == 0)
            && ($prod == 0)
            && ($expedie === 0)
            && ($eshop === 0)
            && ($taproom === 0);

        $rows[] = [
            'sku_id'          => $sid,
            'sku_code'        => $r['sku_code'],
            'format'          => $r['format'],
            'hl_per_unit'     => $r['hl_per_unit'],
            // Anchor + flows
            'anchor_qty'      => $anchor,
            'prod_qty'        => $prod,
            'prod_events'     => $r['prod_events'],
            'expedie_qty'     => $expedie,
            'expedie_orders'  => $r['expedie_orders'],
            'eshop_qty'       => $eshop,
            'eshop_orders'    => $r['eshop_orders'],
            'taproom_qty'     => $taproom,
            'taproom_rows'    => $r['taproom_rows'],
            // Computed
            'physique'        => $physique,
            'open_week_qty'   => $openWeek,
            'open_total_qty'  => $openTotal,
            'live_semaine'    => $liveSemaine,
            'live_futur'      => $liveFutur,
            'velocity_weekly' => $vel,
            'semaines_stock'  => $semaines,
            // Flags
            'flag_survendu'   => $liveFutur < 0,
            'flag_low_stock'  => ($semaines !== null && $semaines < 2.0 && $physique > 0),
            'flag_dormant'    => $isDormant,
        ];
    }

    // TODO drift-vs-take audit chip (later arc):
    // If multiple anchor months exist, compare latest vs previous stocktake
    // and surface a drift chip per SKU where the delta exceeds a threshold.

    return [
        'anchor_month' => $anchorMonth,
        'anchor_date'  => $anchorDate,
        'rows'         => $rows,
        'computed_at'  => date('c'),
    ];
}

/**
 * Filtered variant: compute FG stock only for the given sku_id list.
 * Useful for inline "stock hint" in the Saisie form.
 *
 * @param int[] $skuIds
 */
function fg_stock_for_skus(PDO $pdo, array $skuIds): array
{
    $result = fg_stock_compute($pdo);
    if (empty($skuIds)) {
        return $result;
    }
    $idSet = array_flip($skuIds);
    $result['rows'] = array_values(
        array_filter($result['rows'], fn($r) => isset($idSet[$r['sku_id']]))
    );
    return $result;
}

/**
 * Return the Sunday (end of ISO week) of the ISO week that contains $date.
 * ISO weeks start Monday; we report through end-of-Sunday to match the UI.
 */
function fg_stock_iso_week_end(string $date): string
{
    $dto = new DateTimeImmutable($date);
    // ISO day: Monday=1, Sunday=7
    $dow    = (int) $dto->format('N');
    $sunday = $dto->modify('+' . (7 - $dow) . ' days');
    return $sunday->format('Y-m-d');
}
