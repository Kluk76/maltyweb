-- Relabel KPI tracker #87: units_sold_sku → HL volume
-- Change label from "Unités vendues par SKU/format" to "Volume vendu par SKU/format (HL)"
-- No schema change; label-only relabel.
-- Idempotency: safe to re-run (UPDATE is idempotent when label already matches).

UPDATE ref_kpi_trackers
   SET label = 'Volume vendu par SKU/format (HL)'
 WHERE slug = 'units_sold_sku'
   AND label != 'Volume vendu par SKU/format (HL)';
