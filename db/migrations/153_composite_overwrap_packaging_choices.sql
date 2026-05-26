-- db/migrations/153_composite_overwrap_packaging_choices.sql
-- What: Seed ref_sku_packaging_choices with operator-confirmed overwrap bindings
--       for the four composite packs (PD8, XMASPACK, PAL, PAC).
-- Why:  The composite compiler (sku-bom-compile.php, step ③) resolves composite_packaging
--       lines exclusively from ref_sku_packaging_choices for composite SKUs.
--       Without these rows the compiler emits RQ('composite_packaging_no_choices') for all 4
--       composites and inserts zero packaging lines.
-- Who:  Operator sign-off 2026-05-26 (all four confirmed CLEARED in the build spec):
--         PD8 (137)  → PKG_PACK_DEC (id 167, CHF 0.98) × 1
--         XMASPACK (138) → PKG_VERRE_25CL (id 186, CHF 0.8774) × 1 + PKG_XMAS_PAC (id 171, CHF 1.595) × 1
--         PAL (278)  → PKG_CARTON12_ESHOP (id 166, CHF 0.95) × 1 + LOG_SCOTCH_ESHOP (id 201, CHF 2.50) × 1
--         PAC (295)  → PKG_CARTON12_ESHOP (id 166, CHF 0.95) × 1 + LOG_SCOTCH_ESHOP (id 201, CHF 2.50) × 1
-- Risk: LOW — inserts into a reference table; ON DUPLICATE KEY UPDATE makes it idempotent.
--       No data loss. ref_sku_bom is recomputed by the compiler after this runs.
-- Rollback: DELETE FROM ref_sku_packaging_choices WHERE sku_id IN (137,138,278,295) AND slot_name IN ('outer_box','verre','scotch_eshop');
-- Note on PAL outer_box slot_name: uses 'outer_box' to match the packaging items template
--   (ref_packaging_items format_id=21 has an 'outer_box' slot — we bind PKG_CARTON12_ESHOP there
--   rather than PKG_PACK_LOUIS which has no MI). The compiler reads choices irrespective of
--   whether a matching packaging_items slot exists for the composite format.
-- Post-apply: re-run the compiler:
--   sudo -u www-data php scripts/sku-bom-compile-cli.php --dry-run --sku 137,138,278,295
--   sudo -u www-data php scripts/sku-bom-compile-cli.php --apply  --sku 137,138,278,295

-- PD8 (sku_id 137): outer box = PKG_PACK_DEC (id 167), qty 1
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (137, 'outer_box', 167, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

-- XMASPACK (sku_id 138): verre (glass) + xmas box
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (138, 'verre',     186, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (138, 'outer_box', 171, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

-- PAL (sku_id 278): carton 12 eshop + scotch eshop
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (278, 'outer_box',   166, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (278, 'scotch_eshop', 201, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

-- PAC (sku_id 295): carton 12 eshop + scotch eshop
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (295, 'outer_box',   166, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked)
VALUES (295, 'scotch_eshop', 201, 1.0000, 1)
ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk), qty_per_unit=VALUES(qty_per_unit), is_checked=1;

-- schema_meta: no new table → no new schema_meta row needed.
-- ref_sku_packaging_choices already has a schema_meta row (reference / allowed / SKU builder UI).
