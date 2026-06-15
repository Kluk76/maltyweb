-- Migration 352: fix production_targets_packaging tracker category from 'production' to 'packaging'
-- Corrective data fix; no schema change.

UPDATE ref_kpi_trackers
   SET category = 'packaging'
 WHERE slug = 'production_targets_packaging';
