-- Migration 306: rm_procurement KPI handlers — data_ready flips + pending-rows fix
-- Phase 2b Batch 2 of Le Zeppelin KPI board.
--
-- Part A: Flip data_ready=1 for all rm_procurement trackers whose compute handlers
--         are being implemented in app/kpi-handlers.php in this same atom.
--         Trackers with readiness='gap' are intentionally left stub (no source data).
--
-- Part B: Resolve the 6 inv_deliveries UTIL_DRECH_ENTRANORD rows with qty_delivered=0,
--         negative total_chf, and status='Pending' that have been aging since Dec 2025.
--         These are confirmed eco-charge/deposit lines (qty=0 makes them non-inventory),
--         already paired with an Active parent delivery on the same invoice_ref.
--         Setting them to 'Active' matches: (a) the May 2026 version (id=567) which was
--         already Active, (b) inv_deliveries schema (Active = invoice confirmed), and (c)
--         qty=0 means RM-dynamic is unaffected (no phantom stock change). The aging handler
--         (kpi_ops_pending_deliveries_aging) filters status='Pending' — flipping these to
--         Active drops them from that counter and from kpi_rm_pending_deliveries.
--         audit_row_revisions snapshots are written before the UPDATE.
-- ──────────────────────────────────────────────────────────────────────────────────────────

-- ── Part A: data_ready flips ──────────────────────────────────────────────────

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE source_domain = 'rm_procurement'
   AND readiness = 'compute'
   AND compute_handler IN (
       -- #106 rm_stock_value
       'rm_stock_value',
       -- #107 rm_stock_by_category
       'rm_stock_by_category',
       -- #110 rm_days_cover
       'rm_days_cover',
       -- #115 top_suppliers_spend
       'top_suppliers_spend',
       -- #116 wac_trend_per_mi
       'wac_trend_per_mi',
       -- #117 price_anomalies
       'price_anomalies',
       -- #118 consumption_per_mi_month
       'consumption_per_mi_period',
       -- #120 caution_deposit_balance
       'caution_deposit_balance',
       -- #121 import_vat_freight_trend
       'import_vat_freight_trend',
       -- #122 ingredient_cost_pct_cogs
       'ingredient_cost_pct_cogs',
       -- #123 supplier_lead_time
       'supplier_lead_time',
       -- #124 on_time_delivery_rate
       'on_time_delivery_rate',
       -- #127 single_source_risk
       'single_source_risk',
       -- #128 spend_yoy
       'spend_yoy',
       -- #129 malt_hops_cost_split
       'malt_hops_cost_split',
       -- #130 fx_eur_chf_exposure
       'fx_eur_chf_exposure',
       -- #131 overpriced_purchase_flag
       'overpriced_purchase_flag'
   );

-- Intentionally NOT flipped (readiness='gap' — no source data):
--   #111 rm_reorder_alerts   — no reorder threshold table
--   #125 open_pos_expected   — no PO source table
--   #126 price_vs_budget_variance — no budget source

-- ── Part B: audit snapshot + fix of the 6 Pending eco-charge/deposit rows ────
-- Write audit_row_revisions records (action='update') BEFORE the UPDATE so the
-- before_json captures the current state. IDs confirmed by SELECT on 2026-06-10.

INSERT INTO audit_row_revisions
    (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    1,
    'migration_306',
    '127.0.0.1',
    'inv_deliveries',
    id,
    'update',
    JSON_OBJECT(
        'id',            id,
        'mi_id_raw',     mi_id_raw,
        'qty_delivered', CAST(qty_delivered AS CHAR),
        'total_chf',     CAST(total_chf AS CHAR),
        'status',        status,
        'date_received', CAST(date_received AS CHAR),
        'invoice_ref',   invoice_ref
    ),
    JSON_OBJECT(
        'id',            id,
        'mi_id_raw',     mi_id_raw,
        'qty_delivered', CAST(qty_delivered AS CHAR),
        'total_chf',     CAST(total_chf AS CHAR),
        'status',        'Active',
        'date_received', CAST(date_received AS CHAR),
        'invoice_ref',   invoice_ref
    ),
    'mig-306: eco-charge/deposit rows (qty=0, negative CHF) were stuck Pending since Dec 2025; set Active to match May-2026 sibling (id=567) and drop from aging handler. Non-inventory: qty_delivered=0 so RM-dynamic unaffected.'
FROM inv_deliveries
WHERE id IN (42, 95, 187, 251, 298, 399)
  AND status = 'Pending';

-- Apply the status flip:
UPDATE inv_deliveries
   SET status = 'Active'
 WHERE id IN (42, 95, 187, 251, 298, 399)
   AND status = 'Pending';
