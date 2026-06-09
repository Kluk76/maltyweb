<?php
declare(strict_types=1);
/**
 * app/fg-stock.php — FG live-stock computation helpers.
 *
 * Architecture contract (NON-NEGOTIABLE): stock is DERIVED, never stored.
 * No new tables, no caches.
 *
 * Per-SKU physique formula:
 *   anchor      = latest count per (sku_id_fk, location_id_fk) across all
 *                 locations — MAX(id) per pair with is_active=1. Anchor qty
 *                 per SKU = SUM of those rows across locations.
 *   anchor_date = MAX(counted_at) across all anchor rows (the actual count date,
 *                 not the last day of a calendar month).
 *   production  = Σ FLOOR(bd_packaging_v2.prod_total_units / ref_skus.units_per_pack)
 *                 WHERE sku_id_fk AND event_date > prod-site-cutoff AND is_tombstoned=0
 *                 NOTE: prod_total_units is in individual containers; divide by
 *                 units_per_pack to convert to SKU pack units (same as anchor).
 *                 FLOOR is applied PER EVENT before summing — operator rule: PF
 *                 stock is FULL BOXES ONLY (loose units on shelf are not PF).
 *                 units_per_pack=1 for kegs/cuves → FLOOR is a no-op, correct.
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
 *   fg_stock_compute(PDO $pdo): array                      — full per-SKU result + meta
 *   fg_stock_location_snapshot(PDO $pdo): array            — per-location breakdown
 *   fg_stock_for_skus(PDO $pdo, array $skuIds): array      — filtered subset
 */

/**
 * Private helper: floored production per SKU since each SKU's production-site
 * count date. Called by BOTH fg_stock_compute() and fg_stock_location_snapshot()
 * to guarantee they are single-sourced and cannot drift.
 *
 * Production sites are derived from ref_sites (site_type='production',
 * is_active=1) — never hardcoded.
 *
 * The FLOOR is applied PER EVENT before summing (operator rule: full boxes
 * only; loose units within a run are not counted as PF stock).
 * For kegs and cuves (units_per_pack=1), FLOOR is a no-op.
 *
 * Per-SKU cutoff = MAX(counted_at) at production site(s) for that SKU,
 * with COALESCE fallback to $globalAnchor for SKUs never counted at
 * a production site.
 *
 * @param PDO    $pdo
 * @param string $globalAnchor  YYYY-MM-DD — global fallback cutoff date
 * @return array{
 *   prod_site_ids: int[],
 *   by_sku: array<int, array{prod_qty: int, prod_events: int}>
 * }
 */
function fg_prod_since_anchor(PDO $pdo, string $globalAnchor): array
{
    // Derive production site ids from DB — never hardcode
    $prodSiteStmt = $pdo->prepare(
        'SELECT id FROM ref_sites WHERE site_type = ? AND is_active = 1'
    );
    $prodSiteStmt->execute(['production']);
    $prodSiteIds = array_map('intval', array_column($prodSiteStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $bySkuRaw = [];

    if (!empty($prodSiteIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($prodSiteIds), '?'));

        // Per-SKU cutoff = MAX(counted_at) at production site(s).
        // COALESCE(pa.prod_anchor, ?) falls back to $globalAnchor for SKUs
        // never counted at the production site.
        // FLOOR applied PER EVENT (before SUM) so partial boxes are excluded.
        $prodStmt = $pdo->prepare(
            'SELECT p.sku_id_fk,
                    SUM(FLOOR(p.prod_total_units / COALESCE(NULLIF(r.units_per_pack,0),1))) AS prod_qty,
                    COUNT(*) AS prod_events
               FROM bd_packaging_v2 p
               JOIN ref_skus r ON r.id = p.sku_id_fk
               LEFT JOIN (
                   SELECT sku_id_fk, MAX(counted_at) AS prod_anchor
                     FROM inv_fg_stocktake
                    WHERE is_active = 1
                      AND location_id_fk IN (' . $inPlaceholders . ')
                    GROUP BY sku_id_fk
               ) pa ON pa.sku_id_fk = p.sku_id_fk
              WHERE p.is_tombstoned = 0
                AND p.is_white_label = 0
                AND p.sku_id_fk IS NOT NULL
                AND p.run_type <> ?
                AND p.event_date > COALESCE(pa.prod_anchor, ?)
              GROUP BY p.sku_id_fk'
        );
        // Params: prodSiteIds... (for the IN), then 'cuv', then $globalAnchor (COALESCE fallback)
        $prodParams = array_merge($prodSiteIds, ['cuv', $globalAnchor]);
        $prodStmt->execute($prodParams);
    } else {
        // No production site found — fall back to global cutoff for all SKUs.
        // run_type='cuv' still excluded.
        $prodStmt = $pdo->prepare(
            'SELECT p.sku_id_fk,
                    SUM(FLOOR(p.prod_total_units / COALESCE(NULLIF(r.units_per_pack,0),1))) AS prod_qty,
                    COUNT(*) AS prod_events
               FROM bd_packaging_v2 p
               JOIN ref_skus r ON r.id = p.sku_id_fk
              WHERE p.event_date > ?
                AND p.is_tombstoned = 0
                AND p.is_white_label = 0
                AND p.sku_id_fk IS NOT NULL
                AND p.run_type <> ?
              GROUP BY p.sku_id_fk'
        );
        $prodStmt->execute([$globalAnchor, 'cuv']);
    }

    foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $bySkuRaw[(int) $pr['sku_id_fk']] = [
            'prod_qty'    => (int) $pr['prod_qty'],    // FLOOR guarantees integer
            'prod_events' => (int) $pr['prod_events'],
        ];
    }

    return [
        'prod_site_ids' => $prodSiteIds,
        'by_sku'        => $bySkuRaw,
    ];
}

/**
 * Compute live FG stock for all SKUs.
 *
 * Anchor model: latest count per (sku_id_fk, location_id_fk) — the row with
 * MAX(id) per pair among is_active=1. anchor_qty(sku) = SUM across locations.
 * anchor_date = MAX(counted_at) across all anchor rows.
 *
 * @return array{
 *   anchor_month: string,       e.g. '2026-06'
 *   anchor_date:  string,       e.g. '2026-06-08' (MAX counted_at of anchor rows)
 *   rows:         array,        per-SKU rows (see below)
 *   computed_at:  string,       ISO datetime
 * }
 *
 * Each row: {
 *   sku_id, sku_code, format, display_family, hl_per_unit,
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
    // ── Step 1: anchor rows = latest count per (sku_id_fk, location_id_fk) ──
    // Uses MAX(id) per pair (is_active=1) — replaces the old month_closed anchor
    // which silently overwrote multi-location rows for the same SKU.
    $anchorStmt = $pdo->query(
        'SELECT s.sku_id_fk,
                r.sku_code,
                r.format,
                COALESCE(pf.display_family, r.format) AS display_family,
                r.hl_per_unit,
                CAST(s.qty AS SIGNED) AS anchor_qty,
                s.counted_at,
                s.month_closed
           FROM inv_fg_stocktake s
           JOIN ref_skus r ON r.id = s.sku_id_fk
           LEFT JOIN ref_packaging_formats pf ON pf.id = r.format_id
          WHERE s.id IN (
              SELECT MAX(t2.id)
                FROM inv_fg_stocktake t2
               WHERE t2.is_active = 1
               GROUP BY t2.sku_id_fk, t2.location_id_fk
          )'
    );
    $anchorRows = $anchorStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($anchorRows)) {
        // No anchor exists yet — return empty state
        return [
            'anchor_month' => null,
            'anchor_date'  => null,
            'rows'         => [],
            'computed_at'  => date('c'),
        ];
    }

    // Build per-SKU map: SUM anchor_qty across locations (fixes the overwrite bug).
    // anchor_date = MAX(counted_at) across all anchor rows.
    $byId       = [];
    $anchorDate = null;
    foreach ($anchorRows as $ar) {
        $sid = (int) $ar['sku_id_fk'];
        if (!isset($byId[$sid])) {
            $byId[$sid] = [
                'sku_id'         => $sid,
                'sku_code'       => $ar['sku_code'],
                'format'         => $ar['format'],
                'display_family' => $ar['display_family'],
                'hl_per_unit'    => (float) $ar['hl_per_unit'],
                'anchor_qty'     => 0,
                // flows (filled below)
                'prod_qty'       => 0,
                'prod_events'    => 0,
                'expedie_qty'    => 0,
                'expedie_orders' => 0,
                'eshop_qty'      => 0,
                'eshop_orders'   => 0,
                'taproom_qty'    => 0,
                'taproom_rows'   => 0,
            ];
        }
        $byId[$sid]['anchor_qty'] += (int) $ar['anchor_qty'];
        if ($ar['counted_at'] !== null) {
            if ($anchorDate === null || $ar['counted_at'] > $anchorDate) {
                $anchorDate = $ar['counted_at'];
            }
        }
    }

    // anchor_month derived from anchor_date
    // SCOPE NOTE: $anchorDate is the GLOBAL MAX(counted_at) across all sites. It
    // still drives the SALES legs (expedie / eshop / taproom, Steps 4-6) and the
    // displayed meta.anchor_date. The PRODUCTION leg (Step 3) NO LONGER uses it
    // as the cutoff — it derives a per-SKU production-site cutoff instead (see
    // Step 3). So a partial count (e.g. taproom-only) still coarsens the sales
    // legs (e.g. taproom: period > anchorMonth can exclude the current month).
    // Per-SKU/per-location settlement of the sales legs is deferred to the
    // future stock-movement saisie arc — prod-fixed does NOT mean all-fixed.
    $anchorDate  = $anchorDate ?? date('Y-m-d');
    $anchorMonth = substr($anchorDate, 0, 7);

    // ── Step 3: production since anchor (bd_packaging_v2) ───────────────────
    // Single-sourced via fg_prod_since_anchor() — the same helper is called by
    // fg_stock_location_snapshot() so both functions can never drift.
    // prod_total_units is in INDIVIDUAL CONTAINERS (bottles, cans, kegs).
    // All other legs (anchor, orders, eshop, taproom) are in SKU PACK UNITS.
    // For bottles/cans: units_per_pack = 24 → divide to get pack units.
    // For kegs/cuves: units_per_pack = 1 → division is a no-op.
    // FLOOR is applied PER EVENT (full boxes only; operator rule).
    // is_white_label=1 rows are EXCLUDED: beer packaged under another brand
    // (e.g. La Carougeoise) never enters Nebuleuse sellable FG stock.
    // run_type='cuv' events are EXCLUDED: cuve-de-service fills go direct to
    //   client venue (filled-at-client = sold), never FG stock.

    $prodHelper = fg_prod_since_anchor($pdo, $anchorDate);

    foreach ($prodHelper['by_sku'] as $sid => $pdata) {
        if (isset($byId[$sid])) {
            $byId[$sid]['prod_qty']    = $pdata['prod_qty'];    // int
            $byId[$sid]['prod_events'] = $pdata['prod_events'];
        }
        // Production of a SKU not in the anchor (new SKU added post-anchor):
        // add a minimal placeholder row so it appears in the table.
        else {
            // Fetch SKU metadata on-the-fly (rare case; accepted extra query)
            $skuMeta = $pdo->prepare(
                'SELECT s.sku_code, s.format, s.hl_per_unit, s.units_per_pack,
                        COALESCE(pf.display_family, s.format) AS display_family
                   FROM ref_skus s
                   LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
                  WHERE s.id = ? AND s.is_active = 1 LIMIT 1'
            );
            $skuMeta->execute([$sid]);
            $meta = $skuMeta->fetch(PDO::FETCH_ASSOC);
            if ($meta !== false) {
                $byId[$sid] = [
                    'sku_id'         => $sid,
                    'sku_code'       => $meta['sku_code'],
                    'format'         => $meta['format'],
                    'display_family' => $meta['display_family'],
                    'hl_per_unit'    => (float) $meta['hl_per_unit'],
                    'anchor_qty'     => 0,
                    'prod_qty'       => $pdata['prod_qty'],    // int
                    'prod_events'    => $pdata['prod_events'],
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
            'display_family'  => $r['display_family'],
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

/**
 * Per-location breakdown of the same anchor rows used by fg_stock_compute().
 *
 * Returns all holds_fg_stock=1 is_active=1 sites, including those with no
 * counted rows (Taproom → last_counted=null, totals 0).
 *
 * Production since the production-site count date is attributed to the
 * production site(s) by calling fg_prod_since_anchor() — the SAME helper
 * used by fg_stock_compute() (single-source: no drift possible).
 *
 * Verification invariant: when all sales legs are zero,
 *   Σ(all location row qty across all locations) == Σ(fg_stock_compute physique).
 *
 * HL stays decimal (round to 3); unit/box quantities are integers.
 *
 * @return array{
 *   anchor_date: string|null,
 *   locations: array<int, array{
 *     id: int,
 *     name: string,
 *     site_type: string,
 *     last_counted: string|null,
 *     total_units: int,
 *     total_hl: float,
 *     rows: array<int, array{sku_id: int, sku_code: string, format: string, display_family: string, qty: int, hl: float}>
 *   }>
 * }
 */
function fg_stock_location_snapshot(PDO $pdo): array
{
    // Load all holds_fg_stock sites (sorted by sort_order)
    $sitesStmt = $pdo->query(
        'SELECT id, name, site_type, sort_order
           FROM ref_sites
          WHERE holds_fg_stock = 1 AND is_active = 1
          ORDER BY sort_order ASC'
    );
    $sites = $sitesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Load anchor rows (same subquery as fg_stock_compute)
    $rowsStmt = $pdo->query(
        'SELECT t.sku_id_fk,
                t.location_id_fk,
                t.qty,
                t.counted_at,
                r.sku_code,
                r.format,
                r.hl_per_unit,
                COALESCE(pf.display_family, r.format) AS display_family
           FROM inv_fg_stocktake t
           JOIN ref_skus r ON r.id = t.sku_id_fk
           LEFT JOIN ref_packaging_formats pf ON pf.id = r.format_id
          WHERE t.id IN (
              SELECT MAX(t2.id)
                FROM inv_fg_stocktake t2
               WHERE t2.is_active = 1
               GROUP BY t2.sku_id_fk, t2.location_id_fk
          )
          ORDER BY t.location_id_fk ASC, r.sku_code ASC'
    );
    $anchorRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine global anchor_date
    $anchorDate = null;
    foreach ($anchorRows as $ar) {
        if ($ar['counted_at'] !== null && ($anchorDate === null || $ar['counted_at'] > $anchorDate)) {
            $anchorDate = $ar['counted_at'];
        }
    }
    $anchorDate = $anchorDate ?? date('Y-m-d');

    // Floored production per SKU — single-sourced, same helper as fg_stock_compute()
    $prodHelper    = fg_prod_since_anchor($pdo, $anchorDate);
    $prodSiteIds   = $prodHelper['prod_site_ids'];    // int[]
    $prodBySku     = $prodHelper['by_sku'];           // sku_id => {prod_qty, prod_events}
    $prodSiteIdSet = array_flip($prodSiteIds);        // for O(1) membership test

    // Group anchor rows by location_id_fk, with qty keyed by sku_id for easy merging
    // Structure: location_id → sku_id → anchor row data
    $byLocation = []; // location_id → sku_id → row array
    foreach ($anchorRows as $ar) {
        $lid = (int) $ar['location_id_fk'];
        $sid = (int) $ar['sku_id_fk'];
        $byLocation[$lid][$sid] = $ar;
    }

    // Build output — include ALL sites even with zero rows
    $locations = [];
    foreach ($sites as $site) {
        $lid      = (int) $site['id'];
        $ancSkus  = $byLocation[$lid] ?? [];
        $isProdSite = isset($prodSiteIdSet[$lid]);

        // Merge production into production-site rows
        // Start with anchor rows, then overlay production additions
        $mergedBySkuId = [];
        foreach ($ancSkus as $sid => $ar) {
            $mergedBySkuId[$sid] = [
                'sku_id'         => $sid,
                'sku_code'       => $ar['sku_code'],
                'format'         => $ar['format'],
                'display_family' => $ar['display_family'],
                'hl_per_unit'    => (float) $ar['hl_per_unit'],
                'qty'            => (int) $ar['qty'],
                'counted_at'     => $ar['counted_at'],
            ];
        }

        if ($isProdSite) {
            // Add floored production to each SKU at this production site.
            // If a SKU has production but no anchor row here, create a new row for it.
            foreach ($prodBySku as $sid => $pdata) {
                if ($pdata['prod_qty'] <= 0) continue;
                if (isset($mergedBySkuId[$sid])) {
                    $mergedBySkuId[$sid]['qty'] += $pdata['prod_qty'];
                } else {
                    // Newly-packaged SKU not yet counted at this site — fetch metadata
                    $skuMeta = $pdo->prepare(
                        'SELECT s.sku_code, s.format, s.hl_per_unit,
                                COALESCE(pf.display_family, s.format) AS display_family
                           FROM ref_skus s
                           LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
                          WHERE s.id = ? AND s.is_active = 1 LIMIT 1'
                    );
                    $skuMeta->execute([$sid]);
                    $meta = $skuMeta->fetch(PDO::FETCH_ASSOC);
                    if ($meta !== false) {
                        $mergedBySkuId[$sid] = [
                            'sku_id'         => $sid,
                            'sku_code'       => $meta['sku_code'],
                            'format'         => $meta['format'],
                            'display_family' => $meta['display_family'],
                            'hl_per_unit'    => (float) $meta['hl_per_unit'],
                            'qty'            => $pdata['prod_qty'],
                            'counted_at'     => null,
                        ];
                    }
                }
            }
        }

        // Sort by sku_code for consistent output
        uasort($mergedBySkuId, fn($a, $b) => strcmp($a['sku_code'], $b['sku_code']));

        $totalUnits  = 0;
        $totalHl     = 0.0;
        $lastCounted = null;
        $outRows     = [];

        foreach ($mergedBySkuId as $row) {
            $qty = (int) $row['qty'];
            // Recompute HL after production addition; HL stays decimal
            $hl  = round($qty * $row['hl_per_unit'], 3);
            $totalUnits  += $qty;
            $totalHl     += $hl;
            if (($row['counted_at'] ?? null) !== null && ($lastCounted === null || $row['counted_at'] > $lastCounted)) {
                $lastCounted = $row['counted_at'];
            }
            $outRows[] = [
                'sku_id'         => $row['sku_id'],
                'sku_code'       => $row['sku_code'],
                'format'         => $row['format'],
                'display_family' => $row['display_family'],
                'qty'            => $qty,              // integer
                'hl'             => $hl,               // decimal
            ];
        }

        $locations[] = [
            'id'           => $lid,
            'name'         => $site['name'],
            'site_type'    => $site['site_type'],
            'last_counted' => $lastCounted,
            'total_units'  => $totalUnits,             // integer
            'total_hl'     => round($totalHl, 3),      // decimal
            'rows'         => $outRows,
        ];
    }

    return [
        'anchor_date' => $anchorDate,
        'locations'   => $locations,
    ];
}
