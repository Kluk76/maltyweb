-- db/migrations/085_ref_recipe_aliases.sql
-- What: Seed ref_recipe_aliases with 4 Kouros-confirmed RawDB display names
--       (Div.Blanche, Div.Panaché, Div.Gose, Malt Capone) and update
--       schema_meta to reflect the canonical writer.
-- Why:  normalize-rawdb.py beer resolver loads ref_recipe_aliases; these 4
--       aliases bridge the RawDB operator shorthand to the canonical sku_prefix.
--       Confirmed by Kouros 2026-05-23 during RawDB normalization session.
-- Note: ref_recipe_aliases was created in a prior migration (065). If aliases
--       already exist they are silently skipped (INSERT IGNORE). Safe to re-run.
-- Rollback: DELETE FROM ref_recipe_aliases WHERE notes LIKE '%RawDB alias 2026-05-23%';

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Div.Blanche', id, 'RawDB alias 2026-05-23'
FROM ref_recipes WHERE sku_prefix = 'DIB' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Div.Panaché', id, 'RawDB alias 2026-05-23'
FROM ref_recipes WHERE sku_prefix = 'DIP' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Div.Gose', id, 'RawDB alias 2026-05-23'
FROM ref_recipes WHERE sku_prefix = 'DIG' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Malt Capone', id, 'RawDB alias 2026-05-23'
FROM ref_recipes WHERE sku_prefix = 'EPH4' LIMIT 1;

-- Update schema_meta to record that the canonical writer now includes the
-- normalize-rawdb.py seeding path.
UPDATE schema_meta
SET writer_script = 'manual/web + migration 065 + normalize-rawdb.py seed',
    notes         = 'Raw-name -> recipe_id mapping for RawDB beer resolver. Curated.'
WHERE table_name  = 'ref_recipe_aliases';
