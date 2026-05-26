-- db/migrations/152_pac_format_2x6_box.sql
-- What: Create the PAC composite packaging format (12×33cl, "Pack du Chef") and
--       re-point sku PAC (295) onto it, replacing the wrong format 7 (24-can box).
-- Why:  PAC (Pack du Chef) is a 12×33cl bottle pack = 2 recipes × 6 bottles
--       (SPY×6 + DIB×6, in ref_sku_composite_slots). It sat on format 7
--       ("24-pack can box 24×50cl", 0.12 HL) — impossible for a bottle pack, and
--       it gave PAC the wrong SKU volume (0.120 vs correct 0.0396). Operator-
--       confirmed 2026-05-26: PAC gets its own composite format like its siblings
--       (PD8→PD8, PAL→PAL, XMASPACK→XMASPACK). Naming follows convention — display
--       uses TOTAL bottles × bottle volume ("12×33cl composite"), NOT the recipe
--       split; the 2-recipes-of-6 structure lives in composite_slots. Attributes
--       mirror PAL (id 21: 12×33cl, 0.0396 HL, composite, catalog_id 16).
-- Risk: LOW — one format insert + one sku re-point. Fixes PAC volume
--       (0.120 → 0.0396 HL). Format 7 stays (it is a real can-box format used
--       elsewhere); only PAC stops referencing it.
-- Rollback:
--   UPDATE ref_skus SET format_id=7 WHERE id=295;
--   DELETE FROM ref_packaging_formats WHERE format_code='PAC';

INSERT INTO ref_packaging_formats
  (format_code, display_name, hl_per_unit, run_type, is_composite, is_active, catalog_id)
VALUES
  ('PAC', 'Pack du Chef (12×33cl composite)', 0.039600, NULL, 1, 1, 16);

UPDATE ref_skus
   SET format_id = (SELECT id FROM ref_packaging_formats WHERE format_code = 'PAC')
 WHERE id = 295;
