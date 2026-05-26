-- db/migrations/150_pad_alias_of_pd8.sql
-- What: Fold PAD (sku 277) into an alias of PD8 (sku 137), and retire the
--       spurious PAD packaging format (id 22).
-- Why:  Operator confirmed 2026-05-26 that PAD is the same 8-beer sampler as
--       PD8 ("Pack Découverte 8"), NOT a larger pack. The PAD format (id 22)
--       was defined "Pack Découverte large (80×33cl) [VERIFY]" — that 80-bottle
--       definition was wrong/unverified. PAD (277) had 0 BOM rows and is the
--       only SKU using format 22 (catalog_id NULL, no dbc_* link), so the format
--       is retired. PAD resolves to PD8 like PACKDEC (migration 149).
-- Risk: LOW — one alias insert + two deactivations. No COGS movement (nothing
--       decomposes PAD until sales reference it, and then it resolves to PD8).
-- Rollback:
--   DELETE FROM ref_sku_aliases WHERE alias='PAD';
--   UPDATE ref_skus SET is_active=1 WHERE id=277;
--   UPDATE ref_packaging_formats SET is_active=1 WHERE id=22;

INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes) VALUES
  ('PAD', 137, 'Discontinued code; operator-confirmed same 8-beer sampler as PD8. Canonical: PD8 (id 137, composite). Folded 2026-05-26. Old PAD format id 22 (80x33cl) was unverified/wrong, retired.');

UPDATE ref_skus SET is_active = 0 WHERE id = 277;

UPDATE ref_packaging_formats SET is_active = 0 WHERE id = 22;
