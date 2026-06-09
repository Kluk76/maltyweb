-- Migration 289: SCD2 write-side for ref_recipe_ingredients
--
-- THE CENTRAL CONSTRAINT:
--   The existing UNIQUE KEY uq_recipe_mi_stage (recipe_id, mi_id_fk, stage_key, boil_time_key)
--   prevents close-then-insert: closing an old row and opening a new one for the same
--   (recipe_id, mi_id_fk, stage, boil) slot hits ER_DUP_ENTRY 1062 because effective_*
--   are not part of the key.
--
-- SOLUTION — partial uniqueness on the OPEN version only:
--   Add a STORED generated column `open_key TINYINT` that is 1 when the row is the
--   current open version (effective_until IS NULL AND is_active = 1) and NULL otherwise.
--   MySQL ignores NULL values in unique indexes, so closed historical rows (open_key=NULL)
--   can coexist with any number of siblings; at most ONE row per slot can have open_key=1.
--
-- Steps:
--   1. Add open_key GENERATED STORED column
--   2. Drop old broad unique index
--   3. Add new partial-open unique index
--   4. Backfill: set effective_from='2025-01-01' for all rows where effective_from IS NULL
--      (leaves effective_until NULL = open; marks the start of version history)
--
-- schema_meta update: record v2 SCD2 write-side activation.

ALTER TABLE ref_recipe_ingredients
  ADD COLUMN open_key TINYINT
    GENERATED ALWAYS AS (
      CASE WHEN effective_until IS NULL AND is_active = 1 THEN 1 ELSE NULL END
    ) STORED
    AFTER effective_until;

DROP INDEX uq_recipe_mi_stage ON ref_recipe_ingredients;

ALTER TABLE ref_recipe_ingredients
  ADD UNIQUE KEY uq_rri_open (recipe_id, mi_id_fk, stage_key, boil_time_key, open_key);

UPDATE ref_recipe_ingredients
   SET effective_from = '2025-01-01'
 WHERE effective_from IS NULL;

UPDATE schema_meta
   SET notes      = 'Per-recipe ingredient quantities, per-HL basis. Seeded 2026-05-21. v2 SCD2 write-side activated 2026-06-09.',
       updated_at = NOW()
 WHERE table_name = 'ref_recipe_ingredients';
