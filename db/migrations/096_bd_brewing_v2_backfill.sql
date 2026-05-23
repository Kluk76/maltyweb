-- db/migrations/096_bd_brewing_v2_backfill.sql
-- What: Post-upload backfill for all bd_brewing_*_v2 tables.
--   Step 1: Re-derive recipe_id_fk from ref_recipes.name where it is NULL
--           (covers rows whose xlsx beer_recipe_id was absent or unrecognised).
--   Step 2: Report rows that remain recipe_id_fk=NULL after backfill (for operator review).
-- Why:  Migration 095 (Python upload) sets recipe_id_fk=NULL + audit_flags when xlsx
--       beer_recipe_id is absent or not in ref_recipes. This migration attempts a name-match
--       fallback, which catches brewing records submitted before recipe IDs were stable.
-- Risk: Low. UPDATE only on rows WHERE recipe_id_fk IS NULL. No data deleted.
--       JOIN on exact LOWER(TRIM()) match — no fuzzy matching, no silent misassignment.
-- Rollback: UPDATE bd_brewing_brewday_v2 SET recipe_id_fk=NULL, audit_flags=CONCAT_WS(',', audit_flags, 'recipe_backfill_reverted') WHERE ...;
--           (selective; only affects rows touched by this migration)

-- ─── Step 1A: brewday backfill ────────────────────────────────────────────────

UPDATE bd_brewing_brewday_v2 bd
JOIN ref_recipes rr
  ON LOWER(TRIM(rr.name)) = LOWER(TRIM(bd.beer))
SET
  bd.recipe_id_fk = rr.id,
  bd.audit_flags = NULLIF(
    TRIM(BOTH ',' FROM
      REGEXP_REPLACE(COALESCE(bd.audit_flags, ''), 'recipe_id_not_found:[^,]*(,|$)', '')
    ),
    ''
  ),
  bd.updated_at = NOW()
WHERE bd.recipe_id_fk IS NULL;

-- ─── Step 1B: gravity backfill ───────────────────────────────────────────────

UPDATE bd_brewing_gravity_v2 bg
JOIN ref_recipes rr
  ON LOWER(TRIM(rr.name)) = LOWER(TRIM(bg.beer))
SET
  bg.recipe_id_fk = rr.id,
  bg.audit_flags = NULLIF(
    TRIM(BOTH ',' FROM
      REGEXP_REPLACE(COALESCE(bg.audit_flags, ''), 'recipe_id_not_found:[^,]*(,|$)', '')
    ),
    ''
  ),
  bg.updated_at = NOW()
WHERE bg.recipe_id_fk IS NULL;

-- ─── Step 1C: timings backfill ───────────────────────────────────────────────

UPDATE bd_brewing_timings_v2 bt
JOIN ref_recipes rr
  ON LOWER(TRIM(rr.name)) = LOWER(TRIM(bt.beer))
SET
  bt.recipe_id_fk = rr.id,
  bt.audit_flags = NULLIF(
    TRIM(BOTH ',' FROM
      REGEXP_REPLACE(COALESCE(bt.audit_flags, ''), 'recipe_id_not_found:[^,]*(,|$)', '')
    ),
    ''
  ),
  bt.updated_at = NOW()
WHERE bt.recipe_id_fk IS NULL;

-- ─── Step 1D: ingredients header backfill ────────────────────────────────────

UPDATE bd_brewing_ingredients_v2 bi
JOIN ref_recipes rr
  ON LOWER(TRIM(rr.name)) = LOWER(TRIM(bi.beer))
SET
  bi.recipe_id_fk = rr.id,
  bi.audit_flags = NULLIF(
    TRIM(BOTH ',' FROM
      REGEXP_REPLACE(COALESCE(bi.audit_flags, ''), 'recipe_id_not_found:[^,]*(,|$)', '')
    ),
    ''
  ),
  bi.updated_at = NOW()
WHERE bi.recipe_id_fk IS NULL;

-- ─── Step 2: Residual NULL summary ───────────────────────────────────────────
-- Run these manually after applying to identify beers needing recipe assignment.
-- Expect 0 for Nébuleuse beers; contract/archive beers may legitimately remain NULL.
--
-- SELECT 'brewday' AS t, beer, batch, audit_flags FROM bd_brewing_brewday_v2 WHERE recipe_id_fk IS NULL LIMIT 50;
-- SELECT 'gravity' AS t, beer, batch, brew, event_type, audit_flags FROM bd_brewing_gravity_v2 WHERE recipe_id_fk IS NULL LIMIT 50;
-- SELECT 'timings' AS t, beer, batch, brew, audit_flags FROM bd_brewing_timings_v2 WHERE recipe_id_fk IS NULL LIMIT 50;
-- SELECT 'ingredients' AS t, beer, batch, audit_flags FROM bd_brewing_ingredients_v2 WHERE recipe_id_fk IS NULL LIMIT 50;

SELECT
  (SELECT COUNT(*) FROM bd_brewing_brewday_v2 WHERE recipe_id_fk IS NULL) AS brewday_null_recipe,
  (SELECT COUNT(*) FROM bd_brewing_gravity_v2 WHERE recipe_id_fk IS NULL) AS gravity_null_recipe,
  (SELECT COUNT(*) FROM bd_brewing_timings_v2 WHERE recipe_id_fk IS NULL) AS timings_null_recipe,
  (SELECT COUNT(*) FROM bd_brewing_ingredients_v2 WHERE recipe_id_fk IS NULL) AS ingredients_null_recipe;
