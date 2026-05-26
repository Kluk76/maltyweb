-- db/migrations/151_delete_spurious_pad_format.sql
-- What: Delete the spurious PAD packaging format (ref_packaging_formats id 22).
--       Migration 150 only deactivated it; the operator wants it removed (an
--       80×33cl format that never existed as a real product).
-- Why:  PAD folded to an alias of PD8 (migration 150); format 22 is unused. The
--       only remaining reference is ref_skus.277.format_id=22 (PAD, inactive
--       alias) — set NULL to match the other PD8 alias PACKDEC (288, which has
--       format_id NULL). Verified: no rows in ref_packaging_items /
--       ref_packaging_bom_templates / ref_packaging_format_mis for format 22;
--       ref_sku_composite_slots.member_format_id never = 22. Deletes cleanly.
-- Risk: LOW — one format_id null + one format-row delete. No COGS movement.
-- Rollback:
--   INSERT INTO ref_packaging_formats
--     (id, format_code, display_name, hl_per_unit, run_type, is_composite, is_active, catalog_id)
--     VALUES (22, 'PAD', 'Pack Découverte large (80×33cl composite) [VERIFY]',
--             0.264000, NULL, 1, 0, NULL);
--   UPDATE ref_skus SET format_id=22 WHERE id=277;

UPDATE ref_skus SET format_id = NULL WHERE id = 277;

DELETE FROM ref_packaging_formats WHERE id = 22;
