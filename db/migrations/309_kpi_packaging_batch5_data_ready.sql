-- Migration 309: flip data_ready for Phase 2b Batch 5 packaging KPI handlers
-- Built trackers: #55 fill_efficiency, #58 packaging_material_consumption,
--   #60 avg_losses_per_category, #61 avg_losses_per_sku, #66 packaging_cost_per_unit
-- Left stub (no source data): #64 suggested_packaging_events (needs tank-sim port),
--   #65 packaging_deviations (no plan/schedule source)
-- All 'gap' trackers (#62, #67, #68, #70, #71, #256, #257) remain gap/data_ready=0.

UPDATE `ref_kpi_trackers`
   SET `data_ready` = 1
 WHERE `source_domain` = 'packaging'
   AND `compute_handler` IN (
       'fill_efficiency',
       'packaging_material_consumption',
       'avg_losses_per_category',
       'avg_losses_per_sku',
       'packaging_cost_per_unit'
   );
