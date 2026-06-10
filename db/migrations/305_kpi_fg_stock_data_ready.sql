-- Migration 305: mark fg_stock KPI trackers as data_ready=1 for the 9 compute handlers
-- shipped in Phase 2b Batch 1 (kpi_handler_fg_stock in app/kpi-handlers.php).
--
-- Handlers built and smoke-passed: fg_units_in_stock, fg_inventory_value,
-- fg_days_cover, fg_produced_vs_sold, fg_stock_turnover, slow_mover_flag,
-- fg_by_location, value_tied_per_beer, fg_stock_variation.
--
-- Handlers left stub (readiness='gap' or missing source data):
--   fg_stockouts (#75): no reorder threshold in ref_skus
--   fg_aging_best_before (#77): no BBD tracking per batch
--   warehouse_cage_fill (#79, #133): no warehouse capacity in system_settings
--   consignment_keg_fleet (#82): gap
--   return_breakage_rate (#83): gap
--
-- Fiscal reconciliation verified 2026-06-10:
--   ZEPF: 1256 units × CHF 1.9563/unit = CHF 2,457.07
--   Total anchor inventory value = CHF 43,350.11 (52 SKUs with BOM cost)

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE source_domain = 'fg_stock'
   AND compute_handler IN (
       'fg_units_in_stock',
       'fg_inventory_value',
       'fg_days_cover',
       'fg_produced_vs_sold',
       'fg_stock_turnover',
       'slow_mover_flag',
       'fg_by_location',
       'value_tied_per_beer',
       'fg_stock_variation'
   );
