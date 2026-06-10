-- Migration 312: KPI sales domain — Batch 7 wiring
-- Sets data_ready=1 for the compute-backed sales trackers.
-- Leaves data_ready=0 for gap/stub trackers with no canonical source.
--
-- SOURCE DOMAIN: inv_sales_bc (3 071 rows, 2025-12 to 2026-05)
--   + inv_sales_orders / inv_sales_order_lines (Shopify/eshop, 2025-12 to 2026-04)
--
-- FISCAL SEMANTIC NOTE (flagged for Opus):
--   inv_sales_bc.profit_chf = sales_amount_chf at 100% for all booked lines — BC
--   exports this column as "profit" but it has NOT had COGS deducted (i.e. it is
--   gross revenue, not gross margin). handler #98 (gross_margin_sku) is therefore
--   STUBBED — real gross margin requires COP-pipeline COGS which is not yet
--   available at the per-SKU / per-period granularity needed to reconcile here.
--
-- Built trackers (data_ready=1):
--   #86  revenue_month           inv_sales_bc SUM per period
--   #87  units_sold_sku          inv_sales_bc grouped by sku_code
--   #88  hl_sold_month           inv_sales_bc SUM(hl_resolved)
--   #90  sales_yoy_pace          inv_sales_bc 12-month sparkline
--   #91  revenue_by_family       inv_sales_bc × ref_skus × ref_recipes (classification)
--   #92  top_customers_revenue   inv_sales_bc × ref_customers top-N by CHF
--   #93  top_skus_volume_revenue inv_sales_bc grouped by sku_code
--   #94  sales_by_channel        inv_sales_bc (b2b/taproom) + inv_sales_orders (eshop)
--   #96  contract_vs_own_brand   inv_sales_bc × ref_skus × ref_recipes (classification)
--   #97  avg_order_value         inv_sales_orders (Shopify/eshop)
--   #99  revenue_per_hl_trend    inv_sales_bc CHF/HL 6-month sparkline
--   #100 discount_rebate_rate    inv_sales_bc.discount_amount_chf / (sales + discount)
--   #102 seasonal_demand_curve   inv_sales_bc full series sparkline
--
-- Stubbed trackers (data_ready stays 0):
--   #89  sales_velocity_sku     — requires rolling sell-through rate vs FG stock; needs
--                                  fg_stock_compute + inv_sales_bc JOIN across periods
--                                  (deferred: same complexity as fg_days_cover, needs
--                                   threshold config in ref_skus)
--   #95  swiss_vs_export        — inv_sales_bc.customer_no → ref_customers.country_code
--                                  but 2 373 of 3 057 ref_customers have trade_channel=NULL;
--                                  country_code data present (all CH in 2026-05 sample)
--                                  but ExportCustomers exclusion list not in MySQL yet
--   #98  gross_margin_sku       — profit_chf equals sales_amount_chf (BC exports revenue,
--                                  not margin); real gross margin = sales − COP-BOM COGS;
--                                  COP per-SKU COGS not available in MySQL at required
--                                  granularity (Node pipeline → sheets, not MySQL)
--   #101 days_sales_outstanding — requires payment date on invoices; inv_sales_bc has no
--                                  payment_date column; gap = 'readiness=gap' already
--   #103 lost_sales_stockout    — requires stockout threshold in ref_skus (not yet set)
--   #104 forecast_vs_actual     — no forecast table in DB; gap
--   #105 customer_churn         — requires multi-period customer history model; derivable
--                                  from inv_sales_orders but requires >12m data window
--   #262 forecast_accuracy      — no forecast table in DB; gap
--
-- IMPORTANT: DO NOT APPLY — awaiting Opus verification of fiscal values.

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE source_domain = 'sales'
   AND slug IN (
       'revenue_month',
       'units_sold_sku',
       'hl_sold_month',
       'sales_yoy_pace',
       'revenue_by_family',
       'top_customers_revenue',
       'top_skus_volume_revenue',
       'sales_by_channel',
       'contract_vs_own_brand',
       'avg_order_value',
       'revenue_per_hl_trend',
       'discount_rebate_rate',
       'seasonal_demand_curve'
   );
