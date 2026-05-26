-- db/migrations/156_normalize_malt_cassonade_sugar_kg.sql
-- What: Normalize MALT_CASSONADE (id 17) and MALT_SUGAR (id 22) from grams to kg
--       in ref_mi and their derived ref_sku_bom rows.
-- Why:  Operator-set rule 2026-05-26: ALL MALT_* MIs must use input_unit='kg' and
--       conversion_factor=1.0. These two were modeled as input_unit='g',
--       conversion_factor=0.001 — a legacy artefact. The source data (bd_brewing,
--       inv_consumption, ref_recipe_ingredients) is ALREADY in kg; only the ref_mi
--       and ref_sku_bom rows carry the defect.
--       Cost is PRESERVED: currently cost = qty_grams * price * 0.001.
--       After fix: cost = (qty_grams/1000) * price * 1.0 = identical.
--       ref_sku_bom.ing_unit is already 'kg' — the label becomes truthful once
--       qty_per_unit is divided by 1000.
-- Risk: LOW. Scope is exactly 2 ref_mi rows (EST* SKUs only: EST4/ESTF/ESTBU/ESTP25/
--       ESTP50, 10 ref_sku_bom rows). Pre-migration uniformity check confirmed all
--       10 BOM rows are gram-scale (cost == qty*price*0.001 within 0.0001). No other
--       MALT_* MI is affected. ref_mi.cost column is NOT touched (already correct).
-- Rollback:
--   UPDATE ref_mi SET input_unit = 'g', conversion_factor = 0.001000 WHERE id IN (17, 22);
--   UPDATE ref_sku_bom SET qty_per_unit = ROUND(qty_per_unit * 1000, 6) WHERE mi_id IN (17, 22);

UPDATE ref_mi SET input_unit = 'kg', conversion_factor = 1.000000 WHERE id IN (17, 22);
UPDATE ref_sku_bom SET qty_per_unit = ROUND(qty_per_unit / 1000, 6) WHERE mi_id IN (17, 22);
