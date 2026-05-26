-- db/migrations/155_retire_collab24_alias_dgdb.sql
-- What: Retire COLLAB24 (sku 301) as an alias of DGDB (sku 8). COLLAB24 was a
--       Shopify dupe of the DGD 24-pack (DGDB), not a distinct SKU.
-- Why:  Operator-confirmed 2026-05-26. COLLAB24's format (6x4 carton, 24x33cl)
--       and the DGDB 24-pack box are the same product; the COLLAB24 code is a
--       Shopify artifact. Fold it like PACKDEC/PAD: alias -> canonical, deactivate
--       the SKU, and remove its now-invalid collab-temporal recipe mapping so the
--       compiler never tries to build it. COLLAB12 (real 12-pack) and COLLAB4PACK
--       keep their collab_temporal rows.
--       NB: PAC is_composite is intentionally NOT touched here -- ref_skus has NO
--       is_composite column; compositeness is carried by the format (PAC -> format
--       23, is_composite=1, migration 152) and the compiler detects composites by
--       ref_sku_composite_slots presence. Nothing to flip.
-- Risk: LOW -- one alias insert + one SKU deactivation + one collab_temporal delete.
--       No COGS movement (COLLAB24 has no compiled BOM; it resolves to DGDB now).
-- Rollback:
--   DELETE FROM ref_sku_aliases WHERE alias='COLLAB24';
--   UPDATE ref_skus SET is_active=1 WHERE id=301;
--   INSERT INTO ref_sku_collab_temporal (sku_code, effective_from, effective_until, recipe_id, notes)
--     VALUES ('COLLAB24','2025-12-01',NULL,31,'Galactic Drift 2.0 - DrunkBeard collab');

INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes) VALUES
  ('COLLAB24', 8, 'Shopify dupe of the DGD 24-pack; canonical DGDB (id 8). Operator-confirmed 2026-05-26.');

UPDATE ref_skus SET is_active = 0 WHERE id = 301;

DELETE FROM ref_sku_collab_temporal WHERE sku_code = 'COLLAB24';
