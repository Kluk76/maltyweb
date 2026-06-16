<?php
declare(strict_types=1);
require_once __DIR__ . '/fulfilment-site.php';
require_once __DIR__ . '/seasonal-burn.php';
/**
 * app/fg-stock.php — FG live-stock computation helpers.
 *
 * Architecture contract (NON-NEGOTIABLE): stock is DERIVED, never stored.
 * No new tables, no caches.
 *
 * Per-SKU physique formula:
 *   anchor      = census-date model: for each location, the anchor is every
 *                 is_active=1 row whose counted_at = MAX(counted_at) for that
 *                 location (the "census date"). A SKU absent from the latest
 *                 census = 0 — no phantom carry-over from prior counts.
 *                 Within a census, ties broken by MAX(id) DESC (latest row).
 *                 Anchor qty per SKU = SUM of census rows across locations.
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
 * Velocity (forward-seasonal burn model, replaced 2026-06-11):
 *   Forward depletion simulation using a classical multiplicative seasonal
 *   decomposition over inv_sales_ledger (2021→now, ~44k rows).
 *   Level L = EW-mean of deseasonalized weekly burn over a trailing 52-week window.
 *   Index  S = per-family (recipe_id) or global fallback, pre-computed weekly
 *             by scripts/seasonal-index-cli.php → kpi_sku_seasonal_index.
 *   Sim    = step forward week by week: stock − L × S[w] until zero.
 *   Cold-cache safety: if kpi_sku_seasonal_index is empty (cron not yet run),
 *   sb_resolve_family_index() returns flat 1.0 → sim degenerates to physique/L.
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
 * Production cutoff = MAX(counted_at) across ALL is_active=1 rows at production
 * site(s), regardless of SKU. This is a site-level census date, not a per-SKU
 * date. A packaging run after this date counts for ALL SKUs — SKU-independence
 * is correct because the census is a complete location snapshot.
 * COALESCE fallback to $globalAnchor when no prod-site census exists at all.
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

        // Production cutoff (census-date model): site-level MAX(counted_at) at
        // production sites, applied to ALL SKUs. A single scalar pa.prod_anchor
        // is CROSS JOINed to every packaging event row.
        //
        // prod_anchor_ts is set to NULL because the cutoff is now site-level, not
        // per-SKU — there is no meaningful per-SKU anchor timestamp to tiebreak on.
        // The same-day branch (p.submitted_at > pa.prod_anchor_ts) fires only when
        // pa.prod_anchor_ts IS NOT NULL, so setting it to NULL disables the branch
        // (conservative and correct: no double-count risk from the same-day path).
        //
        // COALESCE(pa.prod_anchor, ?) falls back to $globalAnchor when no prod-site
        // census exists at all.
        // FLOOR applied PER EVENT (before SUM) so partial boxes are excluded.
        $prodStmt = $pdo->prepare(
            'SELECT p.sku_id_fk,
                    SUM(FLOOR(p.prod_total_units / COALESCE(NULLIF(r.units_per_pack,0),1))) AS prod_qty,
                    COUNT(*) AS prod_events
               FROM bd_packaging_v2 p
               JOIN ref_skus r ON r.id = p.sku_id_fk
               LEFT JOIN (
                   SELECT MAX(counted_at) AS prod_anchor, NULL AS prod_anchor_ts
                     FROM inv_fg_stocktake
                    WHERE is_active = 1
                      AND counted_at IS NOT NULL
                      AND location_id_fk IN (' . $inPlaceholders . ')
               ) pa ON 1=1
              WHERE p.is_tombstoned = 0
                AND p.is_white_label = 0
                AND p.sku_id_fk IS NOT NULL
                AND p.run_type <> ?
                -- SCOPE NOTE: primary cutoff is business event_date (calendar truth).
                -- pa.prod_anchor_ts is always NULL (site-level anchor, not per-SKU),
                -- so the same-day submitted_at tiebreak is disabled (conservative).
                -- Accepted residual: a packaging run done physically BEFORE a same-day
                -- count but SUBMITTED after it would be double-counted. Left un-clamped
                -- on purpose — a plausibility clamp would mask real data; if it ever
                -- bites, the fix is an explicit count
                -- timestamp, not a clamp here.
                -- NULL-SAFE: when pa.prod_anchor IS NULL (SKU never counted at prod site),
                -- the second OR branch is excluded (IS NOT NULL = false), so COALESCE
                -- falls back to $globalAnchor exactly as before.
                AND (
                      p.event_date > COALESCE(pa.prod_anchor, ?)
                   OR (pa.prod_anchor IS NOT NULL
                       AND p.event_date = pa.prod_anchor
                       AND p.submitted_at > pa.prod_anchor_ts)
                )
              GROUP BY p.sku_id_fk'
        );
        // Params: prodSiteIds... (for the IN clause in pa subquery), then 'cuv',
        // then $globalAnchor (COALESCE fallback). pa.prod_anchor_ts = NULL literal,
        // no extra bound params needed.
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
 * Anchor model: census-date model. For each location L, the anchor is every
 * is_active=1 row whose counted_at = MAX(counted_at) for that location (the
 * location's latest census). A SKU absent from the census = 0 (no row, no
 * anchor_qty contribution). Within a census, ties broken by MAX(id) DESC.
 * anchor_qty(sku) = SUM across locations.
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
 *   open_2wk_qty,       int: Σ open order lines with requested_date ≤ end-of-NEXT-ISO-week (cumulative)
 *   open_total_qty,     int: Σ ALL open order lines
 *   shipped_week_qty,   int: Σ shipped order lines whose requested_date falls in the current ISO week
 *                           — DISPLAY ONLY, already reflected in physique when the shipment predates
 *                             the anchor; never deducted again. Use for "commandé cette semaine (total)"
 *                             reconciliation: open_week_qty + shipped_week_qty = total week demand.
 *   live_semaine,       int: physique - open_week_qty
 *   live_2sem,          int: physique - open_2wk_qty  (physique − cumulative orders due ≤ end of next ISO week)
 *   live_futur,         int: physique - open_total_qty
 *   velocity_weekly,    float|null: deseasonalized weekly run-rate L (null = no data)
 *   rythme_base,        float|null: same as velocity_weekly (semantic alias for render layer)
 *   semaines_stock,     float|null: forward sim result in weeks (null if no data / eol)
 *   burn_status,        string: 'normal'|'provisoire'|'eol'
 *   burn_cache_cold,    bool: true when kpi_sku_seasonal_index is empty (cron not yet run)
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
    // ── Step 1: anchor rows = census-date model ──────────────────────────────
    // Anchor for location L = ALL rows whose counted_at equals that location's
    // MAX(counted_at) (census_date). A SKU absent from that census = 0 (no row
    // here, no anchor_qty contribution). This replaces the old per-(sku,location)
    // ROW_NUMBER() pattern that carried phantom quantities forward for absent SKUs.
    //
    // Within a census (same location + same counted_at), ties are broken by MAX(id)
    // DESC — picks the latest-inserted row, same as before.
    $anchorStmt = $pdo->query(
        'SELECT s.sku_id_fk,
                r.sku_code,
                r.format,
                COALESCE(pf.display_family, r.format) AS display_family,
                r.hl_per_unit,
                r.recipe_id,
                s.qty AS anchor_qty,
                r.stocktake_scope,
                s.counted_at,
                s.month_closed
           FROM inv_fg_stocktake s
           JOIN ref_skus r ON r.id = s.sku_id_fk
           LEFT JOIN ref_packaging_formats pf ON pf.id = r.format_id
          WHERE s.id IN (
              SELECT s.id FROM inv_fg_stocktake s
              JOIN (
                SELECT location_id_fk, MAX(counted_at) AS census_date
                  FROM inv_fg_stocktake
                 WHERE is_active = 1
                   AND counted_at IS NOT NULL
                 GROUP BY location_id_fk
              ) lc ON lc.location_id_fk = s.location_id_fk AND lc.census_date = s.counted_at
             WHERE s.is_active = 1
               AND s.id = (
                 SELECT s2.id FROM inv_fg_stocktake s2
                  WHERE s2.is_active = 1
                    AND s2.sku_id_fk = s.sku_id_fk
                    AND s2.location_id_fk = s.location_id_fk
                    AND s2.counted_at = s.counted_at
                  ORDER BY s2.id DESC LIMIT 1
               )
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
                'recipe_id'      => ($ar['recipe_id'] !== null) ? (int) $ar['recipe_id'] : null,
                'anchor_qty'     => 0,
                'stocktake_scope'=> '',
                // flows (filled below)
                'prod_qty'          => 0,
                'prod_events'       => 0,
                'expedie_qty'       => 0,
                'expedie_orders'    => 0,
                'eshop_qty'         => 0,
                'eshop_orders'      => 0,
                'taproom_qty'           => 0,
                'taproom_rows'          => 0,
                'repack_open_qty'       => 0,
                'repack_assembled_qty'  => 0,
                'returns_restock_qty'   => 0,
            ];
        }
        $byId[$sid]['anchor_qty'] += (float) $ar['anchor_qty'];
        $byId[$sid]['stocktake_scope'] = $ar['stocktake_scope'] ?? '';
        if ($ar['counted_at'] !== null) {
            if ($anchorDate === null || $ar['counted_at'] > $anchorDate) {
                $anchorDate = $ar['counted_at'];
            }
        }
    }

    // anchor_month derived from anchor_date
    // SCOPE NOTE: $anchorDate is the GLOBAL MAX(counted_at) across all sites.
    // It drives the displayed meta.anchor_date and is the fallback cutoff for legs
    // that have no finer per-site resolution. Each leg's effective cutoff:
    //
    //   Production (Step 3) — per-SKU production-site cutoff via fg_prod_since_anchor();
    //                         globalAnchor is only the COALESCE fallback for SKUs never
    //                         counted at the production site.
    //
    //   eshop (Step 5)      — per-(sku, warehouse-site) same-day tiebreak, IDENTICAL to
    //                         the eshop leg in fg_stock_location_snapshot(). Resolved via
    //                         fg_site_sku_anchor_map() + the warehouse site id.  Fallback
    //                         for SKUs with no warehouse count = strict > $anchorDate.
    //                         THIS MUST STAY SYMMETRIC with the snapshot eshop leg —
    //                         they are intentionally the same predicate so that
    //                         Σ(snapshot eshop deductions) == fg_stock_compute eshop
    //                         deduction per SKU and the hard invariant Σcards==Σphysique holds.
    //
    //   B2B expédié (Step 4) — three-tier per-(sku, ship-from-site) predicate, symmetric with
    //                          snapshot Leg 1 (MUST stay identical — invariant coupling):
    //                          (a) counted at ship-from site → requested_date >= site_anchor_date
    //                          (b) counted elsewhere but not this site → >= global $anchorDate
    //                          (c) never counted anywhere → > global $anchorDate (strict)
    //                          Site resolved via resolve_fulfilment_site() per order.
    //                          Accepted residual: same-day ship in count double-deducts (deferred).
    //
    //   taproom (Step 6)    — month-grained period > $anchorMonth; genuinely deferred
    //                         (inv_sales_bc has no finer timestamp). Symmetric in both.
    //
    //   transfers           — compute has NO transfer term by construction; transfers are
    //                         globally net-zero so they cancel in the total physique.
    //                         The snapshot applies them per-site with a unit-gate
    //                         (see fg_stock_location_snapshot transfer leg) that guarantees
    //                         per-row net-zero regardless of per-site anchor divergence.
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
                    'sku_id'           => $sid,
                    'sku_code'         => $meta['sku_code'],
                    'format'           => $meta['format'],
                    'display_family'   => $meta['display_family'],
                    'hl_per_unit'      => (float) $meta['hl_per_unit'],
                    'anchor_qty'       => 0,
                    'prod_qty'         => $pdata['prod_qty'],    // int
                    'prod_events'      => $pdata['prod_events'],
                    'expedie_qty'           => 0,
                    'expedie_orders'        => 0,
                    'eshop_qty'             => 0,
                    'eshop_orders'          => 0,
                    'taproom_qty'           => 0,
                    'taproom_rows'          => 0,
                    'repack_open_qty'       => 0,
                    'repack_assembled_qty'  => 0,
                    'returns_restock_qty'   => 0,
                ];
            }
        }
    }

    // ── Step 4: expédié B2B (ord_order_lines, status='shipped') ─────────────
    // ALL order_types deplete here — internal channels (cage/shop/taproom/eshop
    // manual) also decrement physical stock when their order is shipped.
    //
    // Three-tier predicate (symmetric with snapshot Leg 1 — MUST stay identical):
    //   (a) counted at ship-from site → requested_date >= site_anchor_date (inclusive)
    //   (b) counted elsewhere but not this site → requested_date >= global_anchorDate (inclusive)
    //   (c) never counted at any site → requested_date > global_anchorDate (strict;
    //       no morning baseline, same-day ambiguous, preserves pre-fix behaviour)
    // Accepted residual: a same-day ship already in the count double-deducts (case a/b
    // only; rare; only fixable with an explicit ship timestamp — deferred).
    // Fetch per-order rows + site-resolution cols; LEFT JOIN ref_customers avoids N+1.
    // Safe lower-bound in SQL: >= 30 days; PHP predicate is the authoritative gate.
    //
    // fg_site_sku_anchor_map() called once here; reused in Step 5.
    $siteSkuAnchorMapCompute = fg_site_sku_anchor_map($pdo);

    $expStmt = $pdo->prepare(
        'SELECT o.id                                   AS order_id,
                o.fulfilment_site_id_fk,
                o.customer_id_fk,
                o.internal_channel,
                c.default_delivery_site_id_fk          AS customer_default_site_id,
                l.sku_id_fk,
                o.requested_date,
                SUM(l.qty)                             AS qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
           JOIN ref_skus rs ON rs.id = l.sku_id_fk AND rs.stocktake_scope <> ?
           LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
          WHERE o.status = ?
            AND o.requested_date >= DATE_SUB(?, INTERVAL 30 DAY)
            AND l.sku_id_fk IS NOT NULL
            AND l.line_status = \'to_fulfil\'
          GROUP BY o.id, o.fulfilment_site_id_fk, o.customer_id_fk, o.internal_channel,
                   c.default_delivery_site_id_fk, l.sku_id_fk, o.requested_date'
    );
    $expStmt->execute(['none', 'shipped', $anchorDate]);
    // Build per-SKU "counted anywhere" set so the fallback predicate can distinguish:
    //   (a) counted at ship-from site → use site anchor, inclusive >=
    //   (b) counted somewhere but NOT at this site → global anchor, inclusive >=
    //   (c) never counted at any site → global anchor, strict > (old behaviour;
    //       no morning baseline for this SKU, so same-day is ambiguous)
    $skuCountedAnywhereCompute = [];
    foreach ($siteSkuAnchorMapCompute as $_sid_map) {
        foreach (array_keys($_sid_map) as $_sid) {
            $skuCountedAnywhereCompute[$_sid] = true;
        }
    }

    $expOrdersSeen = []; // track DISTINCT order_ids per sku for expedie_orders count
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $sid           = (int) $er['sku_id_fk'];
        $requestedDate = (string) $er['requested_date'];

        // Resolve the ship-from site for this order (same resolver as snapshot)
        $siteId = resolve_fulfilment_site($pdo, [
            'fulfilment_site_id_fk'    => $er['fulfilment_site_id_fk'],
            'customer_id_fk'           => $er['customer_id_fk'],
            'channel'                  => $er['internal_channel'],
            '_customer_default_site_id'=> $er['customer_default_site_id'],
        ]);

        // Per-(site, sku) anchor with three-tier fallback:
        $siteAnchor = $siteSkuAnchorMapCompute[$siteId][$sid] ?? null;
        if ($siteAnchor !== null) {
            // (a) counted at ship-from site — inclusive >=
            if ($requestedDate < $siteAnchor['counted_at']) continue;
        } elseif (isset($skuCountedAnywhereCompute[$sid])) {
            // (b) counted elsewhere but not here — global anchor, inclusive >=
            if ($requestedDate < $anchorDate) continue;
        } else {
            // (c) never counted — global anchor, strict > (no morning baseline)
            if ($requestedDate <= $anchorDate) continue;
        }

        if (!isset($byId[$sid])) continue;
        $qty = (int) round((float) $er['qty']);
        $byId[$sid]['expedie_qty'] += $qty;
        // Count distinct order_ids per sku
        $orderId = (int) $er['order_id'];
        if (!isset($expOrdersSeen[$sid])) $expOrdersSeen[$sid] = [];
        $expOrdersSeen[$sid][$orderId] = true;
    }
    // Write distinct order counts
    foreach ($expOrdersSeen as $sid => $orderMap) {
        if (isset($byId[$sid])) {
            $byId[$sid]['expedie_orders'] = count($orderMap);
        }
    }

    // ── Step 5: eshop auto (inv_sales_order_lines via inv_sales_orders) ──────
    // SOURCE CHOICE: inv_sales_orders is order-grained and preferred over
    // inv_sales_bc (which carries b2b + taproom only, no 'eshop' channel).
    // Both tables confirmed by inspection (2026-06-07): inv_sales_orders has
    // ONLY channel='eshop'; inv_sales_bc has ONLY b2b + taproom.
    // No overlap risk.
    //
    // TIEBREAK: uses >= anchorDate + per-(sku, warehouse-site) same-day tiebreak,
    // IDENTICAL to the eshop leg in fg_stock_location_snapshot() (~L814-850).
    // This symmetry is non-negotiable: both legs must produce the same eshop
    // deduction per SKU so that Σcards==Σphysique holds under the same-day window.
    //
    // Predicate (per-row, in PHP):
    //   pass = orderDate > siteAnchorDate
    //       || (orderDate === siteAnchorDate && orderTs > siteAnchorTs)
    // Fallback (no warehouse count for this SKU): strict > $anchorDate.
    //
    // $siteSkuAnchorMapCompute already built in Step 4 — reused here, no double-call.
    $eshopWarehouseSiteId = resolve_fulfilment_site($pdo, ['channel' => 'eshop']);

    $eshopStmt = $pdo->prepare(
        'SELECT isol.sku_id_fk,
                iso.created_at AS order_created_at,
                isol.qty
           FROM inv_sales_order_lines isol
           JOIN inv_sales_orders iso ON iso.id = isol.order_id_fk
           JOIN ref_skus rs ON rs.id = isol.sku_id_fk AND rs.stocktake_scope <> ?
          WHERE iso.channel = ?
            AND DATE(iso.created_at) >= ?
            AND isol.sku_id_fk IS NOT NULL'
    );
    $eshopStmt->execute(['none', 'eshop', $anchorDate]);
    foreach ($eshopStmt->fetchAll(PDO::FETCH_ASSOC) as $es) {
        $sid       = (int) $es['sku_id_fk'];
        $orderTs   = (string) $es['order_created_at'];
        $orderDate = substr($orderTs, 0, 10); // DATE portion of the DATETIME

        // Per-(sku, warehouse-site) tiebreak — IDENTICAL predicate to snapshot Leg 2
        $siteAnchor = $siteSkuAnchorMapCompute[$eshopWarehouseSiteId][$sid] ?? null;
        if ($siteAnchor !== null) {
            $siteAnchorDate = $siteAnchor['counted_at'];
            $siteAnchorTs   = $siteAnchor['anchor_ts'];
            $pass = ($orderDate > $siteAnchorDate)
                || ($orderDate === $siteAnchorDate && $orderTs > $siteAnchorTs);
            if (!$pass) continue;
        } else {
            // No count at warehouse site for this SKU — fall back to global anchor (strict >)
            if ($orderDate <= $anchorDate) continue;
        }

        if (isset($byId[$sid])) {
            $byId[$sid]['eshop_qty']    += (int) round((float) $es['qty']);
            $byId[$sid]['eshop_orders'] += 1;
        }
    }

    // ── Step 5.5: repack box-opens (inv_repack_events) ──────────────────────
    // FEATURE-GATED: only active when repack_depletion_live() === true.
    // Until the 2026-06-15 cage count the flag stays OFF so physique is
    // byte-identical to the pre-repack-leg state. Set
    //   system_settings (section='features', key_name='repack_depletion_live', value_num=1)
    // to activate without a redeploy.
    //
    // A repack event: −from_qty on from_sku (base-box depleted), +to_qty on to_sku when
    // to_sku.stocktake_scope='base' (assembled pack enters physique). For to_sku.scope='none'
    // (bundles/loose excluded from stocktake JOINs) no positive term is emitted.
    //
    // Same-day tiebreak predicate (BYTE-IDENTICAL to snapshot repack leg):
    //   pass = moved_on > site_anchor_date
    //       || (moved_on === site_anchor_date && created_at > anchor_ts)
    // Fallback (no anchor for this (site, sku)): strict moved_on > $anchorDate.
    //
    // $siteSkuAnchorMapCompute already built in Step 4 — reused here.
    if (repack_depletion_live()) {
        $repackStmt = $pdo->prepare(
            'SELECT r.from_sku_id_fk, r.site_id_fk, r.from_qty, r.moved_on, r.created_at,
                    r.to_sku_id_fk, r.to_qty, rs_to.stocktake_scope AS to_scope
               FROM inv_repack_events r
               LEFT JOIN ref_skus rs_to ON rs_to.id = r.to_sku_id_fk
              WHERE r.is_tombstoned = 0
                AND r.moved_on >= ?'
        );
        $repackStmt->execute([$anchorDate]);
        foreach ($repackStmt->fetchAll(PDO::FETCH_ASSOC) as $rk) {
            $sid     = (int) $rk['from_sku_id_fk'];
            $siteId  = (int) $rk['site_id_fk'];
            $movedOn = (string) $rk['moved_on'];
            $rkTs    = (string) $rk['created_at'];

            // Per-(site, sku) anchor gate — BYTE-IDENTICAL predicate to snapshot repack leg
            $siteAnchor = $siteSkuAnchorMapCompute[$siteId][$sid] ?? null;
            if ($siteAnchor !== null) {
                $pass = ($movedOn > $siteAnchor['counted_at'])
                    || ($movedOn === $siteAnchor['counted_at'] && $rkTs > $siteAnchor['anchor_ts']);
                if (!$pass) continue;
            } else {
                if ($movedOn <= $anchorDate) continue;
            }

            if (!isset($byId[$sid])) continue;
            $byId[$sid]['repack_open_qty'] += (int) $rk['from_qty'];

            // +to_qty for scope='base' targets (e.g. PD8): assembled packs increment physique.
            // Predicate uses the SAME anchor gate already applied above — byte-symmetric with −from_qty.
            // scope='none' targets (bundles/loose excluded from stocktake JOINs) get no positive term.
            // scope='cage'/'single' targets: not expected per to_kind ENUM ('bundle','pd8','loose',
            // 'adjustment') — if a future repack type produces a cage/single target, extend here.
            // SAME-SITE: inv_repack_events.site_id_fk is the single site for both source and target
            // (schema_meta notes "Same-site bottle-count-conserving conversion").
            // UN-ANCHORED STUB: if to_sku has no stocktake anchor yet, create a zero-anchor
            // $byId entry so the +to_qty is not silently dropped (R1 symmetry with snapshot).
            // The stub uses the same full default shape as all other $byId entries (~L292-313).
            // Gate: same $pass predicate already applied above to the −from_qty term, so no
            // phantom physique is injected for events outside the anchor window.
            // scope='none'/'cage'/'single' targets still get no positive term (per to_kind ENUM).
            $toSid   = isset($rk['to_sku_id_fk']) ? (int) $rk['to_sku_id_fk'] : null;
            $toScope = $rk['to_scope'] ?? '';
            if ($toSid !== null && $toScope === 'base') {
                // If to_sku has no anchor yet, create a zero-anchor stub so the
                // +to_qty is not silently dropped (R1 symmetry with snapshot).
                // Gate: same $pass predicate already applied above (anchor/movedOn).
                // Shape mirrors the prod-placeholder init block (~L395-413).
                if (!isset($byId[$toSid])) {
                    $toMeta = $pdo->prepare(
                        'SELECT s.sku_code, s.format, s.hl_per_unit,
                                s.recipe_id,
                                COALESCE(pf.display_family, s.format) AS display_family
                           FROM ref_skus s
                           LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
                          WHERE s.id = ? AND s.is_active = 1 LIMIT 1'
                    );
                    $toMeta->execute([$toSid]);
                    $toMetaRow = $toMeta->fetch(PDO::FETCH_ASSOC);
                    if ($toMetaRow !== false) {
                        $byId[$toSid] = [
                            'sku_id'               => $toSid,
                            'sku_code'             => $toMetaRow['sku_code'],
                            'format'               => $toMetaRow['format'],
                            'display_family'       => $toMetaRow['display_family'],
                            'hl_per_unit'          => (float) $toMetaRow['hl_per_unit'],
                            'recipe_id'            => ($toMetaRow['recipe_id'] !== null) ? (int) $toMetaRow['recipe_id'] : null,
                            'anchor_qty'           => 0,
                            'stocktake_scope'      => 'base',
                            'prod_qty'             => 0,
                            'prod_events'          => 0,
                            'expedie_qty'          => 0,
                            'expedie_orders'       => 0,
                            'eshop_qty'            => 0,
                            'eshop_orders'         => 0,
                            'taproom_qty'          => 0,
                            'taproom_rows'         => 0,
                            'repack_open_qty'      => 0,
                            'repack_assembled_qty' => 0,
                            'returns_restock_qty'  => 0,
                        ];
                    }
                }
                if (isset($byId[$toSid])) {
                    $byId[$toSid]['repack_assembled_qty'] += (int) $rk['to_qty'];
                }
            }
        }
    }

    // ── Step 6: taproom auto (inv_sales_bc, channel='taproom') ──────────────
    // SOURCE CHOICE: inv_sales_orders has NO taproom rows (sole source is
    // inv_sales_bc). Confirmed by inspection (2026-06-07): inv_sales_orders
    // channel ENUM only contains 'eshop'. inv_sales_bc has taproom since 2026-01.
    // Filter: period > anchor_month (YYYY-MM comparison on CHAR(7) is safe).
    $tapStmt = $pdo->prepare(
        'SELECT b.sku_id_fk,
                SUM(b.qty_invoiced)  AS taproom_qty,
                COUNT(*)             AS taproom_rows
           FROM inv_sales_bc b
           JOIN ref_skus rs ON rs.id = b.sku_id_fk AND rs.stocktake_scope <> ?
          WHERE b.channel    = ?
            AND b.period     > ?
            AND b.sku_id_fk IS NOT NULL
          GROUP BY b.sku_id_fk'
    );
    $tapStmt->execute(['none', 'taproom', $anchorMonth]);
    foreach ($tapStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $sid = (int) $tr['sku_id_fk'];
        if (isset($byId[$sid])) {
            $byId[$sid]['taproom_qty']  = (int) round((float) $tr['taproom_qty']);
            $byId[$sid]['taproom_rows'] = (int) $tr['taproom_rows'];
        }
    }

    // ── Step 6.7: returns restock (ord_return_lines, disposition='restock') ─────
    // Restocked returns are physical kegs/units that come back into FG stock.
    // They are an ADDITION to physique — the additive mirror of the depletion legs.
    //
    // Cutoff: strict > $anchorDate (same global-anchor rule used for never-counted
    // SKUs in the expédié leg, case (c)). Returns are not site-resolved, so the
    // global anchor is the only defensible cutoff. A return posted on/before
    // the anchor date is already baked into that count — do NOT double-add it.
    //
    // qty is in SKU pack units already (kegs: units_per_pack=1 — the operator
    // dispositions whole BC ledger lines; no unit conversion needed unlike
    // production which is in containers).
    //
    // One grouped query — no N+1. Pure SELECT.
    //
    // Symmetry note: the SAME query + SAME predicate (strict > $anchorDate) is
    // used in fg_stock_location_snapshot() Leg 4, assigned 100% to the warehouse
    // site. By construction Σ(returns added in compute) == Σ(returns added in
    // snapshot) — the Σcards==Σphysique invariant holds.
    $restockStmt = $pdo->prepare(
        'SELECT rl.sku_id_fk,
                SUM(rl.qty) AS restock_qty
           FROM ord_return_lines rl
           JOIN ord_returns r ON r.id = rl.return_id_fk
          WHERE rl.disposition = ?
            AND r.origin_posting_date > ?
            AND rl.sku_id_fk IS NOT NULL
          GROUP BY rl.sku_id_fk'
    );
    $restockStmt->execute(['restock', $anchorDate]);
    foreach ($restockStmt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        $sid = (int) $rr['sku_id_fk'];
        $qty = (int) round((float) $rr['restock_qty']);
        if (isset($byId[$sid])) {
            $byId[$sid]['returns_restock_qty'] += $qty;
        } else {
            // Restocked SKU not yet in $byId (no anchor, no production).
            // Add a placeholder so the snapshot total can match (invariant).
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
                    'sku_id'                => $sid,
                    'sku_code'              => $meta['sku_code'],
                    'format'                => $meta['format'],
                    'display_family'        => $meta['display_family'],
                    'hl_per_unit'           => (float) $meta['hl_per_unit'],
                    'recipe_id'             => null,
                    'anchor_qty'            => 0,
                    'stocktake_scope'       => '',
                    'prod_qty'              => 0,
                    'prod_events'           => 0,
                    'expedie_qty'           => 0,
                    'expedie_orders'        => 0,
                    'eshop_qty'             => 0,
                    'eshop_orders'          => 0,
                    'taproom_qty'           => 0,
                    'taproom_rows'          => 0,
                    'repack_open_qty'       => 0,
                    'repack_assembled_qty'  => 0,
                    'returns_restock_qty'   => $qty,
                ];
            }
        }
    }

    // $today is used by Steps 7 and 8; define once here.
    $today = date('Y-m-d');

    // ── Step 7: open order lines (for live_semaine / live_2sem / live_futur) ───
    // ONE query supplies BOTH the display aggregates (week_qty/twowk_qty/total_qty)
    // AND the per-line detail needed to bucket orders by sim-week offset h.
    // Filter is IDENTICAL to Step 9's couverture sim: same FROM/JOIN/WHERE —
    // both lenses must never diverge.
    $isoWeekEnd   = fg_stock_iso_week_end($today);
    $iso2wkEnd    = (new DateTimeImmutable($isoWeekEnd))->modify('+7 days')->format('Y-m-d');
    // ISO week Monday: Sunday - 6 days.
    $isoWeekStart = (new DateTimeImmutable($isoWeekEnd))->modify('-6 days')->format('Y-m-d');

    // Aggregate query (display: week/2wk/total) — unchanged from before
    // line_status filter: non_livre/rupture lines will not be fulfilled; exclude from demand sim.
    $openStmt = $pdo->prepare(
        "SELECT l.sku_id_fk,
                SUM(CASE WHEN o.requested_date <= ? THEN l.qty ELSE 0 END) AS week_qty,
                SUM(CASE WHEN o.requested_date <= ? THEN l.qty ELSE 0 END) AS twowk_qty,
                SUM(l.qty)                                                   AS total_qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
          WHERE o.status NOT IN ('shipped', 'cancelled')
            AND l.line_status = 'to_fulfil'
          GROUP BY l.sku_id_fk"
    );
    $openStmt->execute([$isoWeekEnd, $iso2wkEnd]);
    $openBySkuId = [];
    foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) as $or) {
        $openBySkuId[(int) $or['sku_id_fk']] = [
            'week_qty'  => (int) $or['week_qty'],
            'twowk_qty' => (int) $or['twowk_qty'],
            'total_qty' => (int) $or['total_qty'],
        ];
    }

    // Per-line detail query for sim bucketing and drill payload.
    // EXACT same FROM/JOIN/WHERE as the aggregate above — filter parity is the invariant.
    $openDetailStmt = $pdo->prepare(
        "SELECT l.sku_id_fk, o.requested_date, o.status, SUM(l.qty) AS qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
          WHERE o.status NOT IN ('shipped', 'cancelled')
            AND l.line_status = 'to_fulfil'
          GROUP BY l.sku_id_fk, o.requested_date, o.status
          ORDER BY l.sku_id_fk, o.requested_date"
    );
    $openDetailStmt->execute();

    $todayMidnight    = strtotime($today . ' 00:00:00');
    $openByWeekBySku  = []; // [sku_id][h] => float qty  (for sim)
    $openBookBySku    = []; // [sku_id][] => [date,qty,status] (for drill)

    foreach ($openDetailStmt->fetchAll(PDO::FETCH_ASSOC) as $dl) {
        $sid       = (int) $dl['sku_id_fk'];
        $qty       = (float) $dl['qty'];
        $reqDate   = $dl['requested_date'];   // YYYY-MM-DD or null
        $status    = $dl['status'];

        // Bucket offset h: overdue/current-week orders → h=1
        if ($reqDate === null) {
            $h = 1;
        } else {
            $diffDays = (int) ceil((strtotime($reqDate . ' 00:00:00') - $todayMidnight) / 86400);
            $h        = max(1, (int) ceil($diffDays / 7));
        }

        $openByWeekBySku[$sid][$h] = ($openByWeekBySku[$sid][$h] ?? 0.0) + $qty;
        $openBookBySku[$sid][]     = [
            'date'   => $reqDate,
            'qty'    => $qty,
            'status' => $status,
        ];
    }

    // BUILD-TIME ASSERTION: Σ(openByWeekBySku[sid]) == openBySkuId[sid].total_qty per SKU.
    // Filter-drift between the two queries would silently mis-bucket the sim.
    foreach ($openBySkuId as $sid => $agg) {
        $simSum = array_sum($openByWeekBySku[$sid] ?? []);
        if (abs($simSum - (float) $agg['total_qty']) > 0.01) {
            error_log(sprintf(
                '[fg-stock] ASSERTION FAIL sku_id=%d: openByWeekBySku sum=%.2f != total_qty=%.2f — filter drift between open-order queries',
                $sid,
                $simSum,
                (float) $agg['total_qty']
            ));
        }
    }

    // Trailing-3-ISO-week net-out per SKU (for couverture drill recent_spike).
    // Same burn semantics: -SUM(qty_signed), exclude customs_artifact.
    $recentSpikeStmt = $pdo->prepare(
        "SELECT l.sku_id_fk,
                GREATEST(0, -SUM(l.qty_signed)) AS spike_qty
           FROM inv_sales_ledger l
           LEFT JOIN ref_customers c ON c.id = l.customer_id_fk
          WHERE l.sku_id_fk IS NOT NULL
            AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 3 WEEK)
            AND (c.sale_class IS NULL OR c.sale_class NOT IN ('customs_artifact'))
          GROUP BY l.sku_id_fk"
    );
    $recentSpikeStmt->execute();
    $recentSpikeBySku = [];
    foreach ($recentSpikeStmt->fetchAll(PDO::FETCH_ASSOC) as $rs) {
        $recentSpikeBySku[(int) $rs['sku_id_fk']] = (float) $rs['spike_qty'];
    }

    // Shipped-this-week: DISPLAY ONLY — already baked into physique via the expedie leg.
    // Summing open_week_qty + shipped_week_qty gives the operator "total orders due this week".
    // Uses the same ISO week bounds [isoWeekStart, isoWeekEnd].
    $shippedWkStmt = $pdo->prepare(
        'SELECT l.sku_id_fk, SUM(l.qty) AS shipped_week_qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
          WHERE o.status = ?
            AND o.requested_date BETWEEN ? AND ?
            AND l.line_status = \'to_fulfil\'
          GROUP BY l.sku_id_fk'
    );
    $shippedWkStmt->execute(['shipped', $isoWeekStart, $isoWeekEnd]);
    $shippedWeekBySkuId = [];
    foreach ($shippedWkStmt->fetchAll(PDO::FETCH_ASSOC) as $sw) {
        $shippedWeekBySkuId[(int) $sw['sku_id_fk']] = (int) $sw['shipped_week_qty'];
    }

    // ── Step 8: forward-seasonal burn (order-aware, 2026-06-11) ─────────────
    // sb_forward_sim() is the core engine in app/seasonal-burn.php.
    // Per-SKU openByWeekBySku (built in Step 7) is fed into the sim so committed
    // open orders floor each week's demand — preventing falsely rosy coverage
    // numbers when the order book exceeds seasonal baseline.
    // Cold-cache safety: if kpi_sku_seasonal_index is empty, flat 1.0 index.
    $burnParams = sb_load_params();
    $indexMap   = sb_load_index_map($pdo);
    $skuLevels  = sb_all_sku_levels($pdo, $indexMap, $burnParams);

    // Peak week for the seasonal drill payload: derive per-family from indexMap.
    // Returns [peak_week => int, peak_index => float] for a given familyIndex array.
    $peakOfFamilyIndex = static function (array $fi): array {
        $peakW = 1;
        $peakI = 0.0;
        foreach ($fi as $w => $idx) {
            if ($idx > $peakI) {
                $peakI = $idx;
                $peakW = $w;
            }
        }
        return ['peak_week' => $peakW, 'peak_index' => $peakI];
    };

    // ── Step 9: assemble output rows ─────────────────────────────────────────
    $rows = [];
    foreach ($byId as $sid => $r) {
        $anchor          = $r['anchor_qty'];
        $prod            = $r['prod_qty'];
        $expedie         = $r['expedie_qty'];
        $eshop           = $r['eshop_qty'];
        $taproom         = $r['taproom_qty'];
        $repackOpen      = $r['repack_open_qty'];
        $repackAssembled = $r['repack_assembled_qty'];
        $returnsRestock  = $r['returns_restock_qty'];
        $physique        = $anchor + $prod + $returnsRestock + $repackAssembled - $expedie - $eshop - $taproom - $repackOpen;

        $openWeek  = $openBySkuId[$sid]['week_qty']  ?? 0;
        $open2wk   = $openBySkuId[$sid]['twowk_qty'] ?? 0;
        $openTotal = $openBySkuId[$sid]['total_qty'] ?? 0;

        $liveSemaine = $physique - $openWeek;
        $live2sem    = $physique - $open2wk;
        $liveFutur   = $physique - $openTotal;

        // Forward-seasonal burn simulation — order-aware (Step 8 → Step 9 handoff)
        $lvl        = $skuLevels[$sid] ?? null;
        $familyIdx  = sb_resolve_family_index($indexMap, $r['recipe_id'] ?? null);
        $rythmeBase = $lvl !== null ? $lvl['level'] : null;
        $burnStatus = $lvl ? sb_status($lvl, $burnParams) : 'provisoire';

        // EOL short-circuit: eol → null regardless of open orders (tier 6 "sans rotation").
        $simResult  = null;
        $semaines   = null;
        if ($burnStatus !== 'eol') {
            $openByWeek = $openByWeekBySku[$sid] ?? [];
            // sb_forward_sim owns the null decision (rule 5):
            // returns null only when level<=0 AND no open orders.
            $simResult = sb_forward_sim(
                $physique,
                (float) ($rythmeBase ?? 0.0),
                $familyIdx,
                $today,
                $burnParams,
                $openByWeek
            );
            $semaines = $simResult['weeks'];
        }

        // Dormant: physique=0 AND no movement (prod=0, expedie=0, eshop=0, taproom=0, repack=0, restock=0)
        // Use == 0 (loose) to handle float physique (e.g. 0.0 === 0 is false in PHP).
        $isDormant = ($physique == 0)
            && ($prod == 0)
            && ($expedie === 0)
            && ($eshop === 0)
            && ($taproom === 0)
            && ($repackOpen === 0)
            && ($repackAssembled === 0)
            && ($returnsRestock === 0);

        // Couverture drill payload (per-SKU drilldown for the UI)
        $nowIsoWeek   = (int) (new DateTimeImmutable($today))->format('W');
        $nowIdx       = $familyIdx[$nowIsoWeek] ?? 1.0;
        $peakInfo     = $peakOfFamilyIndex($familyIdx);
        $couverture   = [
            'physique'      => $physique,
            'anchor_qty'    => $anchor,
            'prod_qty'      => $prod,
            'expedie_qty'   => $expedie,
            'eshop_qty'     => $eshop,
            'taproom_qty'           => $taproom,
            'repack_open_qty'       => $repackOpen,
            'repack_assembled_qty'  => $repackAssembled,
            'returns_restock_qty'   => $returnsRestock,
            'open_total'            => $openTotal,
            'open_book'      => $openBookBySku[$sid] ?? [],
            'live_futur'    => $liveFutur,
            'rythme_base'   => $rythmeBase,
            'nonzero_weeks' => $lvl['nonzero_weeks'] ?? null,
            'weeks_present' => $lvl['weeks_present'] ?? null,
            'recent_spike'  => $recentSpikeBySku[$sid] ?? 0.0,
            'seasonal'      => [
                'now_week'   => $nowIsoWeek,
                'now_index'  => round($nowIdx, 4),
                'peak_week'  => $peakInfo['peak_week'],
                'peak_index' => round($peakInfo['peak_index'], 4),
            ],
            'projection'    => $simResult['trace'] ?? [],
            'burn_status'   => $burnStatus,
        ];

        $rows[] = [
            'sku_id'          => $sid,
            'sku_code'        => $r['sku_code'],
            'format'          => $r['format'],
            'display_family'  => $r['display_family'],
            'hl_per_unit'     => $r['hl_per_unit'],
            'stocktake_scope' => $r['stocktake_scope'] ?? '',
            // Anchor + flows
            'anchor_qty'      => $anchor,
            'prod_qty'        => $prod,
            'prod_events'     => $r['prod_events'],
            'expedie_qty'     => $expedie,
            'expedie_orders'  => $r['expedie_orders'],
            'eshop_qty'       => $eshop,
            'eshop_orders'    => $r['eshop_orders'],
            'taproom_qty'           => $taproom,
            'taproom_rows'          => $r['taproom_rows'],
            'repack_open_qty'       => $repackOpen,
            'repack_assembled_qty'  => $repackAssembled,
            'returns_restock_qty'   => $returnsRestock,
            // Computed
            'physique'          => $physique,
            'open_week_qty'     => $openWeek,
            'open_2wk_qty'      => $open2wk,
            'open_total_qty'    => $openTotal,
            'shipped_week_qty'  => $shippedWeekBySkuId[$sid] ?? 0,
            'live_semaine'      => $liveSemaine,
            'live_2sem'         => $live2sem,
            'live_futur'        => $liveFutur,
            'velocity_weekly'            => $rythmeBase,
            'rythme_base'                => $rythmeBase,
            'semaines_stock'             => $semaines,
            'burn_status'                => $burnStatus,
            'burn_cache_cold'            => $indexMap['empty'],
            'burn_index_computed_at'     => $indexMap['computed_at'],
            // Flags
            'flag_survendu'   => $liveFutur < 0,
            'flag_low_stock'  => ($semaines !== null && $semaines < 2.0 && $physique > 0),
            'flag_dormant'    => $isDormant,
            // Drill payload
            'couverture'      => $couverture,
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
 * Private helper: return the per-(site_id, sku_id) anchor info — the winning
 * stocktake row's counted_at (DATE) AND created_at (TIMESTAMP) — using the
 * census-date model (same semantics as fg_stock_compute Step 1).
 *
 * Only rows from each location's latest census date (MAX counted_at) are
 * returned. A SKU absent from the latest census has no entry in the map
 * (callers treat absence as "no census for this SKU at this site").
 *
 * Within a census (same location + same counted_at), the highest id wins
 * (latest-inserted row), which guarantees a coherent (counted_at, created_at)
 * pair from a single row — same correctness guarantee as the old ROW_NUMBER pick.
 *
 * @param PDO $pdo
 * @return array<int, array<int, array{counted_at: string, anchor_ts: string}>>
 *         [site_id][sku_id] => {counted_at, anchor_ts}
 */
function fg_site_sku_anchor_map(PDO $pdo): array
{
    // Census-date anchor: only rows from each location's latest census date
    // (MAX counted_at per location_id_fk) are returned. SKUs absent from the
    // latest census have no entry — callers treat absence as no census.
    // Tie-break within a census: highest id (latest-inserted) wins, giving a
    // coherent (counted_at, created_at) pair from a single row.
    $stmt = $pdo->query(
        'SELECT s.sku_id_fk, s.location_id_fk, s.counted_at, s.created_at AS anchor_ts
           FROM inv_fg_stocktake s
           JOIN (
             SELECT location_id_fk, MAX(counted_at) AS census_date
               FROM inv_fg_stocktake
              WHERE is_active = 1
                AND counted_at IS NOT NULL
              GROUP BY location_id_fk
           ) lc ON lc.location_id_fk = s.location_id_fk AND lc.census_date = s.counted_at
          WHERE s.is_active = 1
            AND s.id = (
              SELECT s2.id FROM inv_fg_stocktake s2
               WHERE s2.is_active = 1
                 AND s2.sku_id_fk = s.sku_id_fk
                 AND s2.location_id_fk = s.location_id_fk
                 AND s2.counted_at = s.counted_at
               ORDER BY s2.id DESC LIMIT 1
            )'
    );
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $siteId = (int) $row['location_id_fk'];
        $skuId  = (int) $row['sku_id_fk'];
        if (!isset($map[$siteId])) $map[$siteId] = [];
        $map[$siteId][$skuId] = [
            'counted_at' => (string) $row['counted_at'],
            'anchor_ts'  => (string) $row['anchor_ts'],
        ];
    }
    return $map;
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
 * Sales are attributed per site using the SAME cutoff predicates as
 * fg_stock_compute() steps 4/5/6 to guarantee Σ(per-site sales) == total sales.
 * resolve_fulfilment_site() is used for all site attribution — never inline.
 *
 * Transfers from inv_stock_movements (is_tombstoned=0, moved_on > anchorDate)
 * are applied: +qty to to_site_id_fk, −qty from from_site_id_fk.
 *
 * Same-day anchor tiebreak (Leg 2 / Transfer): a sale/transfer dated
 * the same calendar day as the latest stocktake is included when its event
 * timestamp is later than that count's created_at. Per-site anchor info is
 * supplied by fg_site_sku_anchor_map() — one ROW_NUMBER window, coherent pair.
 * Taproom (Leg 3) is intentionally excluded: its cutoff is month-grained
 * (CHAR(7) period), so same-day collisions are impossible and there is no
 * finer timestamp to tiebreak on.
 *
 * Accepted residual (mirrored from fg_prod_since_anchor): a sale/transfer that
 * happened physically before a same-day count but was submitted after it is a
 * rare mis-settle left un-clamped on purpose. A clamp would mask real data.
 *
 * Verification invariant (HARD):
 *   Σ(all location row qty across all locations) == Σ(fg_stock_compute physique).
 * This holds even when sales are non-zero because the same depletion totals
 * distributed across sites net to the same total as the unsplit depletion.
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
 *     rows: array<int, array{sku_id: int, sku_code: string, format: string, display_family: string, qty: int, hl: float, sales_qty: int, transfer_in: int, transfer_out: int}>
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

    // Load anchor rows (census-date model — same semantics as fg_stock_compute
    // Step 1; MUST stay byte-symmetric with the compute function or the
    // Σcards==Σphysique invariant breaks). For each location, only rows whose
    // counted_at = MAX(counted_at) for that location are included. SKUs absent
    // from the latest census contribute 0 (no row returned here).
    $rowsStmt = $pdo->query(
        'SELECT t.sku_id_fk,
                t.location_id_fk,
                t.qty,
                t.counted_at,
                r.sku_code,
                r.format,
                r.hl_per_unit,
                r.stocktake_scope,
                COALESCE(pf.display_family, r.format) AS display_family
           FROM inv_fg_stocktake t
           JOIN ref_skus r ON r.id = t.sku_id_fk
           LEFT JOIN ref_packaging_formats pf ON pf.id = r.format_id
          WHERE t.id IN (
              SELECT s.id FROM inv_fg_stocktake s
              JOIN (
                SELECT location_id_fk, MAX(counted_at) AS census_date
                  FROM inv_fg_stocktake
                 WHERE is_active = 1
                   AND counted_at IS NOT NULL
                 GROUP BY location_id_fk
              ) lc ON lc.location_id_fk = s.location_id_fk AND lc.census_date = s.counted_at
             WHERE s.is_active = 1
               AND s.id = (
                 SELECT s2.id FROM inv_fg_stocktake s2
                  WHERE s2.is_active = 1
                    AND s2.sku_id_fk = s.sku_id_fk
                    AND s2.location_id_fk = s.location_id_fk
                    AND s2.counted_at = s.counted_at
                  ORDER BY s2.id DESC LIMIT 1
               )
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

    // Per-(site, sku) anchor info — one ROW_NUMBER window, coherent
    // (counted_at, created_at) pair from the same winning row.
    // Used by Leg 1 (B2B expédié), Leg 2 (eshop), and the Transfer leg.
    $siteSkuAnchorMap = fg_site_sku_anchor_map($pdo);

    // Floored production per SKU — single-sourced, same helper as fg_stock_compute()
    $prodHelper    = fg_prod_since_anchor($pdo, $anchorDate);
    $prodSiteIds   = $prodHelper['prod_site_ids'];    // int[]
    $prodBySku     = $prodHelper['by_sku'];           // sku_id => {prod_qty, prod_events}
    $prodSiteIdSet = array_flip($prodSiteIds);        // for O(1) membership test

    // anchor_month for the taproom leg (period > anchorMonth comparison)
    $anchorMonth = substr($anchorDate, 0, 7);

    // ── Sales by site/SKU ────────────────────────────────────────────────────
    // $salesBySiteSku[site_id][sku_id] = int (depletion qty attributed to that site)
    // Three legs exactly mirror fg_stock_compute() steps 4/5/6 so that
    // Σ(per-site sales per sku) == fg_stock_compute sales per sku.
    $salesBySiteSku = []; // site_id => sku_id => int

    // Leg 1: expédié B2B — per-(sku, ship-from-site) anchor + inclusive >=
    // (symmetric with fg_stock_compute() Step 4 — MUST use identical three-tier predicate).
    //
    // Three-tier predicate (mirrors compute Step 4 exactly — invariant coupling):
    //   (a) counted at ship-from site → requested_date >= site_anchor_date (inclusive)
    //   (b) counted elsewhere but not this site → requested_date >= global_anchorDate (inclusive)
    //   (c) never counted at any site → requested_date > global_anchorDate (strict;
    //       no morning baseline, same-day ambiguous, keeps pre-fix behaviour for uncounted SKUs)
    // Created_at is NOT used as a tiebreak — it is order-ENTRY time, not ship time, and
    // can be back-dated. Accepted residual: a same-day ship already in the count
    // double-deducts (rare; only fixable with an explicit ship timestamp — deferred).
    // LEFT JOIN ref_customers prefetches default_delivery_site_id_fk → avoids N+1.
    $expStmt = $pdo->prepare(
        'SELECT o.id          AS order_id,
                o.fulfilment_site_id_fk,
                o.customer_id_fk,
                o.internal_channel,
                c.default_delivery_site_id_fk AS customer_default_site_id,
                l.sku_id_fk,
                o.requested_date,
                SUM(l.qty) AS qty
           FROM ord_order_lines l
           JOIN ord_orders o ON o.id = l.order_id_fk
           JOIN ref_skus rs ON rs.id = l.sku_id_fk AND rs.stocktake_scope <> ?
           LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
          WHERE o.status = ?
            AND o.requested_date >= DATE_SUB(?, INTERVAL 30 DAY)
            AND l.sku_id_fk IS NOT NULL
            AND l.line_status = \'to_fulfil\'
          GROUP BY o.id, o.fulfilment_site_id_fk, o.customer_id_fk, o.internal_channel,
                   c.default_delivery_site_id_fk, l.sku_id_fk, o.requested_date'
    );
    // Build per-SKU "counted anywhere" set for the three-tier fallback below.
    // Mirrors the identical set built in fg_stock_compute() Step 4.
    $skuCountedAnywhere = [];
    foreach ($siteSkuAnchorMap as $_sid_map) {
        foreach (array_keys($_sid_map) as $_sid) {
            $skuCountedAnywhere[$_sid] = true;
        }
    }

    $expStmt->execute(['none', 'shipped', $anchorDate]);
    $expedieOrdersResolved = 0;
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
        $siteId = resolve_fulfilment_site($pdo, [
            'fulfilment_site_id_fk'    => $er['fulfilment_site_id_fk'],
            'customer_id_fk'           => $er['customer_id_fk'],
            'channel'                  => $er['internal_channel'],
            '_customer_default_site_id'=> $er['customer_default_site_id'],
        ]);
        $sid           = (int) $er['sku_id_fk'];
        $requestedDate = (string) $er['requested_date'];

        // Three-tier fallback (mirrors fg_stock_compute() Step 4 exactly):
        //   (a) counted at ship-from site → use site anchor, inclusive >=
        //   (b) counted elsewhere but not here → global anchor, inclusive >=
        //   (c) never counted anywhere → global anchor, strict > (no morning baseline)
        $siteAnchor = $siteSkuAnchorMap[$siteId][$sid] ?? null;
        if ($siteAnchor !== null) {
            if ($requestedDate < $siteAnchor['counted_at']) continue;
        } elseif (isset($skuCountedAnywhere[$sid])) {
            if ($requestedDate < $anchorDate) continue;
        } else {
            if ($requestedDate <= $anchorDate) continue;
        }

        $qty = (int) round((float) $er['qty']);
        if (!isset($salesBySiteSku[$siteId])) $salesBySiteSku[$siteId] = [];
        $salesBySiteSku[$siteId][$sid] = ($salesBySiteSku[$siteId][$sid] ?? 0) + $qty;
        $expedieOrdersResolved++;
    }

    // Leg 2: eshop (inv_sales_order_lines, channel='eshop', DATE(created_at) >= anchorDate)
    // Constant site: always resolves to warehouse via channel='eshop' → call once.
    // >= instead of > so same-day orders are fetched; the per-site tiebreak in PHP
    // uses iso.created_at (DATETIME) as both the event-date and the timestamp.
    $eshopSiteId = resolve_fulfilment_site($pdo, ['channel' => 'eshop']);
    $eshopStmt = $pdo->prepare(
        'SELECT isol.sku_id_fk,
                iso.created_at AS order_created_at,
                isol.qty
           FROM inv_sales_order_lines isol
           JOIN inv_sales_orders iso ON iso.id = isol.order_id_fk
           JOIN ref_skus rs ON rs.id = isol.sku_id_fk AND rs.stocktake_scope <> ?
          WHERE iso.channel = ?
            AND DATE(iso.created_at) >= ?
            AND isol.sku_id_fk IS NOT NULL'
    );
    $eshopStmt->execute(['none', 'eshop', $anchorDate]);
    foreach ($eshopStmt->fetchAll(PDO::FETCH_ASSOC) as $es) {
        $sid      = (int) $es['sku_id_fk'];
        $orderTs  = (string) $es['order_created_at'];
        $orderDate = substr($orderTs, 0, 10); // DATE portion of the DATETIME
        $siteAnchor = $siteSkuAnchorMap[$eshopSiteId][$sid] ?? null;
        // Per-site tiebreak predicate (mirrors production leg):
        //   include if date > site_anchor, OR (same date AND created_at > anchor_ts).
        if ($siteAnchor !== null) {
            $siteAnchorDate = $siteAnchor['counted_at'];
            $siteAnchorTs   = $siteAnchor['anchor_ts'];
            $pass = ($orderDate > $siteAnchorDate)
                || ($orderDate === $siteAnchorDate && $orderTs > $siteAnchorTs);
            if (!$pass) continue;
        } else {
            // No count at eshop site for this SKU → fall back to global anchor date (strict >)
            if ($orderDate <= $anchorDate) continue;
        }
        $qty = (int) round((float) $es['qty']);
        if (!isset($salesBySiteSku[$eshopSiteId])) $salesBySiteSku[$eshopSiteId] = [];
        $salesBySiteSku[$eshopSiteId][$sid] = ($salesBySiteSku[$eshopSiteId][$sid] ?? 0) + $qty;
    }

    // Leg 3: taproom (inv_sales_bc, channel='taproom', period > anchorMonth)
    // INTENTIONALLY excluded from the same-day tiebreak: its cutoff is month-grained
    // (CHAR(7) period field), so a same-day collision between a count and a taproom
    // sale within the same calendar month is impossible to resolve without a finer
    // timestamp — and inv_sales_bc has none. Leave on the global month cutoff.
    // Constant site: always resolves to pos via channel='taproom' → call once.
    $tapSiteId = resolve_fulfilment_site($pdo, ['channel' => 'taproom']);
    $tapStmt = $pdo->prepare(
        'SELECT b.sku_id_fk,
                SUM(b.qty_invoiced) AS qty
           FROM inv_sales_bc b
           JOIN ref_skus rs ON rs.id = b.sku_id_fk AND rs.stocktake_scope <> ?
          WHERE b.channel   = ?
            AND b.period    > ?
            AND b.sku_id_fk IS NOT NULL
          GROUP BY b.sku_id_fk'
    );
    $tapStmt->execute(['none', 'taproom', $anchorMonth]);
    foreach ($tapStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $sid = (int) $tr['sku_id_fk'];
        $qty = (int) round((float) $tr['qty']);
        if (!isset($salesBySiteSku[$tapSiteId])) $salesBySiteSku[$tapSiteId] = [];
        $salesBySiteSku[$tapSiteId][$sid] = ($salesBySiteSku[$tapSiteId][$sid] ?? 0) + $qty;
    }

    // ── Transfers by site/SKU ────────────────────────────────────────────────
    // moved_on >= anchorDate (>= not >) so same-day transfers are fetched;
    // the per-site tiebreak in PHP uses created_at (TIMESTAMP on inv_stock_movements).
    //
    // UNIT-GATE SEMANTICS (non-negotiable for Σcards==Σphysique invariant):
    //   Each transfer row affects TWO sites: +qty to to_site, −qty from from_site.
    //   The tiebreak evaluates independently per-side ($fromPass, $toPass) using
    //   each site's per-SKU anchor, but the ADMISSION decision is taken ATOMICALLY:
    //     $rowPass = ($fromPass || $toPass)
    //   When $rowPass is TRUE, BOTH the +qty (to_site) AND the −qty (from_site)
    //   are applied together. When FALSE, NEITHER side is applied.
    //   This guarantees per-row net-zero unconditionally.
    //
    //   WHY not independent per-side gates?  When two sites have divergent anchor
    //   dates for the same SKU (e.g. site A counted yesterday, site B counted
    //   last month), an independent gate can admit the +qty (to_site B: passes
    //   B's old anchor) while rejecting the −qty (from_site A: fails A's newer
    //   anchor) — or vice versa — producing a phantom net ±qty in the global
    //   Σ. The unit-gate eliminates this class of failure: a row is in or out as
    //   a unit, so its net contribution is always exactly zero or exactly ±qty on
    //   both sides simultaneously.
    //
    // Accepted residual: same as production/sales legs (submitted-after mis-settle).
    // $transfersIn[site_id][sku_id] = int  (positive: stock arriving)
    // $transfersOut[site_id][sku_id] = int (positive: stock leaving)
    $transfersIn  = []; // site_id => sku_id => int
    $transfersOut = []; // site_id => sku_id => int

    $mvStmt = $pdo->prepare(
        'SELECT sku_id_fk, from_site_id_fk, to_site_id_fk,
                qty,
                moved_on,
                created_at AS mv_created_at
           FROM inv_stock_movements
          WHERE is_tombstoned = 0
            AND moved_on >= ?'
    );
    $mvStmt->execute([$anchorDate]);
    foreach ($mvStmt->fetchAll(PDO::FETCH_ASSOC) as $mv) {
        $sid      = (int) $mv['sku_id_fk'];
        $fromSite = (int) $mv['from_site_id_fk'];
        $toSite   = (int) $mv['to_site_id_fk'];
        $qty      = (int) round((float) $mv['qty']);
        $movedOn  = (string) $mv['moved_on'];
        $mvTs     = (string) $mv['mv_created_at'];
        if ($qty <= 0) continue; // skip non-positive movements (tombstoning guard)

        // Per-site tiebreak: from-site anchor for this SKU
        $fromAnchor = $siteSkuAnchorMap[$fromSite][$sid] ?? null;
        $fromPass = ($fromAnchor !== null)
            ? ($movedOn > $fromAnchor['counted_at']
               || ($movedOn === $fromAnchor['counted_at'] && $mvTs > $fromAnchor['anchor_ts']))
            : ($movedOn > $anchorDate);
        // Per-site tiebreak: to-site anchor for this SKU
        $toAnchor = $siteSkuAnchorMap[$toSite][$sid] ?? null;
        $toPass = ($toAnchor !== null)
            ? ($movedOn > $toAnchor['counted_at']
               || ($movedOn === $toAnchor['counted_at'] && $mvTs > $toAnchor['anchor_ts']))
            : ($movedOn > $anchorDate);

        // Unit-gate: admit or reject the whole row together — never one side alone.
        // $fromPass and $toPass are preserved above for clarity; the gate is their OR.
        $rowPass = ($fromPass || $toPass);
        if (!$rowPass) continue;

        // Apply BOTH sides atomically (guaranteed net-zero per row)
        if (!isset($transfersOut[$fromSite])) $transfersOut[$fromSite] = [];
        $transfersOut[$fromSite][$sid] = ($transfersOut[$fromSite][$sid] ?? 0) + $qty;
        if (!isset($transfersIn[$toSite])) $transfersIn[$toSite] = [];
        $transfersIn[$toSite][$sid]  = ($transfersIn[$toSite][$sid]  ?? 0) + $qty;
    }

    // ── Repack box-opens by site/SKU ────────────────────────────────────────
    // FEATURE-GATED: only active when repack_depletion_live() === true (mirrors
    // compute Step 5.5 gate — both must be toggled in lockstep to preserve the
    // Σcards==Σphysique invariant).
    // $repackOpens[site_id][from_sku_id] = int (box-opens since site anchor)
    // $repackAssembled[site_id][to_sku_id] = int (assembled packs for scope='base' targets)
    //
    // A repack event: −from_qty on from_sku (base-box depleted), +to_qty on to_sku when
    // to_sku.stocktake_scope='base' (assembled pack enters physique). For to_sku.scope='none'
    // (bundles/loose excluded from stocktake JOINs) no positive term is emitted.
    //
    // Anchor gate predicate (BYTE-IDENTICAL to Step 5.5 in fg_stock_compute):
    //   pass = moved_on > site_anchor_date
    //       || (moved_on === site_anchor_date && created_at > anchor_ts)
    // Fallback (no anchor for this (site, sku)): strict moved_on > $anchorDate.
    $repackOpens    = []; // site_id => sku_id => int
    $repackAssembled = []; // site_id => sku_id => int
    if (repack_depletion_live()) {
        $rkMvStmt = $pdo->prepare(
            'SELECT r.from_sku_id_fk, r.site_id_fk, r.from_qty, r.moved_on, r.created_at,
                    r.to_sku_id_fk, r.to_qty, rs_to.stocktake_scope AS to_scope
               FROM inv_repack_events r
               LEFT JOIN ref_skus rs_to ON rs_to.id = r.to_sku_id_fk
              WHERE r.is_tombstoned = 0
                AND r.moved_on >= ?'
        );
        $rkMvStmt->execute([$anchorDate]);
        foreach ($rkMvStmt->fetchAll(PDO::FETCH_ASSOC) as $rk) {
            $sid     = (int) $rk['from_sku_id_fk'];
            $siteId  = (int) $rk['site_id_fk'];
            $movedOn = (string) $rk['moved_on'];
            $rkTs    = (string) $rk['created_at'];

            // Per-(site, sku) anchor gate — BYTE-IDENTICAL predicate to compute Step 5.5
            $siteAnchor = $siteSkuAnchorMap[$siteId][$sid] ?? null;
            if ($siteAnchor !== null) {
                $pass = ($movedOn > $siteAnchor['counted_at'])
                    || ($movedOn === $siteAnchor['counted_at'] && $rkTs > $siteAnchor['anchor_ts']);
                if (!$pass) continue;
            } else {
                if ($movedOn <= $anchorDate) continue;
            }

            if (!isset($repackOpens[$siteId])) $repackOpens[$siteId] = [];
            $repackOpens[$siteId][$sid] = ($repackOpens[$siteId][$sid] ?? 0) + (int) $rk['from_qty'];

            // +to_qty for scope='base' targets — BYTE-SYMMETRIC to fg_stock_compute Step 5.5.
            // Same-site: site_id_fk belongs to both source and target (same-site-only constraint).
            // scope='cage'/'single': not expected per to_kind ENUM; extend if new target types added.
            $toSid   = isset($rk['to_sku_id_fk']) ? (int) $rk['to_sku_id_fk'] : null;
            $toScope = $rk['to_scope'] ?? '';
            if ($toSid !== null && $toScope === 'base') {
                if (!isset($repackAssembled[$siteId])) $repackAssembled[$siteId] = [];
                $repackAssembled[$siteId][$toSid] = ($repackAssembled[$siteId][$toSid] ?? 0) + (int) $rk['to_qty'];
            }
        }
    }

    // ── Leg 4: returns restock (ord_return_lines, disposition='restock') ────────
    // Symmetry invariant: this leg uses the IDENTICAL query and cutoff predicate
    // (strict > $anchorDate) as Step 6.7 in fg_stock_compute(). Because 100% of
    // the restock qty is attributed to the warehouse site (assumption below), and
    // fg_stock_compute() adds the same total globally, Σ(snapshot location qty) ==
    // Σ(compute physique) — the Σcards==Σphysique invariant holds by construction.
    //
    // WAREHOUSE-SITE ASSUMPTION: restocked kegs are received at the main FG
    // warehouse (the same site that handles eshop fulfilment). This is resolved via
    // resolve_fulfilment_site($pdo, ['channel'=>'eshop']) — the identical call used
    // by Leg 2. If a future deployment routes returns to a different site, this
    // resolve call must be updated AND fg_stock_compute() Step 6.7 must stay in
    // lockstep. Flag: ASSUMPTION-restock-site-warehouse-2026-06-16.
    //
    // One grouped query — no N+1. Pure SELECT.
    $restockWarehouseSiteId = resolve_fulfilment_site($pdo, ['channel' => 'eshop']);
    $returnsBySiteSku = []; // site_id => sku_id => int

    $restockSnapshotStmt = $pdo->prepare(
        'SELECT rl.sku_id_fk,
                SUM(rl.qty) AS restock_qty
           FROM ord_return_lines rl
           JOIN ord_returns r ON r.id = rl.return_id_fk
          WHERE rl.disposition = ?
            AND r.origin_posting_date > ?
            AND rl.sku_id_fk IS NOT NULL
          GROUP BY rl.sku_id_fk'
    );
    $restockSnapshotStmt->execute(['restock', $anchorDate]);
    foreach ($restockSnapshotStmt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        $sid = (int) $rr['sku_id_fk'];
        $qty = (int) round((float) $rr['restock_qty']);
        if (!isset($returnsBySiteSku[$restockWarehouseSiteId])) {
            $returnsBySiteSku[$restockWarehouseSiteId] = [];
        }
        $returnsBySiteSku[$restockWarehouseSiteId][$sid] =
            ($returnsBySiteSku[$restockWarehouseSiteId][$sid] ?? 0) + $qty;
    }

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
                'qty'            => (float) $ar['qty'],   // float, NOT (int): cages are decimal cage-units (mig 363); flooring here broke Σcards==Σphysique
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

        // Ensure rows exist for any site/SKU that has sales or transfers but
        // no anchor/production row at this site — those rows may go negative,
        // which is an HONEST signal (stock shipped from a site with less attributed
        // than was actually there; per-order override + transfers exist to correct it).
        // SCOPE NOTE: negative qty is intentional — do NOT clamp to 0.
        $skuIdsWithFlows = array_unique(array_merge(
            array_keys($salesBySiteSku[$lid]        ?? []),
            array_keys($transfersIn[$lid]            ?? []),
            array_keys($transfersOut[$lid]           ?? []),
            array_keys($repackOpens[$lid]            ?? []),
            array_keys($repackAssembled[$lid]        ?? []),
            array_keys($returnsBySiteSku[$lid]       ?? [])
        ));
        foreach ($skuIdsWithFlows as $sid) {
            if (!isset($mergedBySkuId[$sid])) {
                // Fetch SKU metadata on-the-fly (rare; only when there's a flow but no anchor/prod)
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
                        'qty'            => 0,
                        'counted_at'     => null,
                    ];
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
            $sid = (int) $row['sku_id'];

            // Sales, transfer, repack, and returns terms for this site/SKU
            $salesQty        = (int) ($salesBySiteSku[$lid][$sid]   ?? 0);
            $transferIn      = (int) ($transfersIn[$lid][$sid]       ?? 0);
            $transferOut     = (int) ($transfersOut[$lid][$sid]      ?? 0);
            $repackOpen      = (int) ($repackOpens[$lid][$sid]       ?? 0);
            $repackAssembly  = (int) ($repackAssembled[$lid][$sid]   ?? 0);
            $returnsRestock  = (int) ($returnsBySiteSku[$lid][$sid]  ?? 0);

            // Final qty = anchor_at_site + production_at_site + returns_restock_at_site
            //           + repack_assembled_at_site − sales_at_site + transfers_net − repack_opens
            // INVARIANT: Σ(qty across all locations) == Σ(fg_stock_compute physique).
            // The returns term contributes only at the warehouse site (Leg 4 assumption);
            // fg_stock_compute() Step 6.7 adds the same global total — match by construction.
            $qty = (float) $row['qty'] + $returnsRestock + $repackAssembly - $salesQty + $transferIn - $transferOut - $repackOpen;

            // Recompute HL after production/sales/transfer adjustment; HL stays decimal
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
                'stocktake_scope'=> $row['stocktake_scope'] ?? '',
                'qty'            => $qty,              // float for cage SKUs; may be negative (honest signal)
                'hl'             => $hl,               // decimal
                'sales_qty'      => $salesQty,         // units depleted via sales
                'transfer_in'    => $transferIn,       // units arriving via transfers
                'transfer_out'   => $transferOut,      // units leaving via transfers
                'repack_open'     => $repackOpen,       // base-box units opened for repack
                'repack_assembled'=> $repackAssembly,  // scope='base' packs assembled from repack events
                'returns_qty'    => $returnsRestock,   // units restocked from returns (Leg 4)
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
        'anchor_date'             => $anchorDate,
        'locations'               => $locations,
        '_expedie_orders_resolved' => $expedieOrdersResolved, // diagnostic: resolver call count for expedie leg
    ];
}
