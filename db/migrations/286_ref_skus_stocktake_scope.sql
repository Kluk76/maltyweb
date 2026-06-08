-- Migration 286: Add stocktake_scope to ref_skus
-- What:    Adds an editable per-SKU ENUM column 'stocktake_scope' controlling which
--          site types include the SKU in the FG stocktake saisie.
-- Why:     Different sites count different SKU types: production+warehouse count cages,
--          POS counts single-unit bottles/cans, consignment sites count nothing extra.
--          Filtering by scope × site_type replaces the implicit "show everything" model.
-- Rollback: ALTER TABLE ref_skus DROP COLUMN stocktake_scope;

ALTER TABLE ref_skus
    ADD COLUMN stocktake_scope ENUM('base','cage','single','none') NOT NULL DEFAULT 'none'
    COMMENT 'Which sites count this SKU in FG stocktake: base=all sites, cage=production+warehouse only, single=POS only, none=not inventorised. Editable per-SKU.'
    AFTER units_per_pack;

-- Seed: base (all sites) — 24-packs, kegs, 6×4 packs, PD8, Xmas Pack
-- Note: U+00D7 multiplication sign in unit_label values below (×).
UPDATE ref_skus
   SET stocktake_scope = 'base'
 WHERE unit_label IN (
     '24-pack box (24 × 33cl)',
     '24-pack box (24 × 50cl)',
     '1 keg (20L)',
     '6×4 pack (24 × 33cl)',
     '6×4 pack (24 × 50cl)',
     'Pack Découverte (8 × 33cl)',
     'Xmas Pack (3 × 33cl + verre)'
 );

-- Seed: cage (production+warehouse only) — fractional crate units (-X suffix)
UPDATE ref_skus
   SET stocktake_scope = 'cage'
 WHERE unit_label LIKE 'Crate%';

-- Seed: single (POS only) — individual bottles and cans
UPDATE ref_skus
   SET stocktake_scope = 'single'
 WHERE unit_label IN (
     '1 bottle (33cl)',
     '1 can (50cl)',
     '1 can (33cl)'
 );

-- Remaining rows (12-packs, 4-packs, cuves '1 litre%', draft pours, PAC/PAL packs,
-- collab/shopify aliases, special multi-packs, etc.) retain DEFAULT 'none'.
