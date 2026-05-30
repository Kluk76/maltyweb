-- db/migrations/217_consolidate_dig_qdg_recipes.sql
--
-- What: Consolidate ref_recipes id=77 ("Qrew - Diversion Gose", empty stub)
--       INTO id=27 ("Diversion Gose", all production data). Renames id=27
--       to "Qrew - Diversion Gose" and retires id=77.
--
-- Why : Two recipe rows existed for the same CollabIn beer (Qrew × La
--       Nébuleuse Diversion Gose). id=77 was ingested as the "official"
--       Qrew-branded name (sku_prefix='QDG') but holds zero production
--       rows. All brewdays, fermentation, racking, packaging, recipe
--       ingredients, SKUs (DIGB/DIGBU), and the recipe profile live on
--       id=27 ("Diversion Gose", sku_prefix='DIG'). Keeping two rows for
--       the same beer creates orphan alias QDG, two NULL profile shells,
--       and a dangling sku_prefix that will confuse future SKU resolution.
--
-- Note on audit action: audit_row_revisions.action ENUM is ('insert','update')
--   only — no 'delete'. Deletions are tombstoned via action='update' with
--   after_json={"_tombstone":"deleted_by_mig217"} per the established
--   schema convention. The before_json captures the full pre-delete row
--   state for rollback. STEPs 3 and 5 (profile + recipe-77 deletion audits)
--   follow this pattern.
--
-- Re-application note: this migration FAILED first attempt at STEP 3
--   (initial action='delete' rejected by ENUM). Partial state at that
--   point: STEP 1 audit row 8628 written, STEP 2 alias 23 UPDATE applied
--   (recipe_id 77→27). All subsequent steps did not run. Every step uses
--   a defensive WHERE clause matching the pre-migration state, so re-apply
--   is naturally idempotent: STEPs 1 and 2 INSERT/UPDATE 0 rows on
--   re-attempt (the alias is already migrated), STEPs 3–10 proceed normally.
--
-- Safety check passed: 2026-05-30
--   - id=77 holds 0 bd_brewing_v2 / bd_fermenting_v2 / bd_racking_v2 /
--     bd_packaging_v2 rows.
--   - id=77 holds 0 ref_recipe_ingredients rows.
--   - id=77 holds 0 ref_skus rows (DIGB id=12, DIGBU id=87 both FK to
--     id=27).
--   - id=77 holds 0 ref_recipe_profile rows with real data; only 2 NULL
--     shells (ids 1818, 1819) auto-created on record creation.
--   - id=77 holds 1 alias: QDG id=23 — migrated to id=27 in this
--     migration before the delete.
--   - ref_recipes.uniq_name_vintage is a composite UNIQUE on (name, vintage).
--     Both rows have vintage=''. DELETE id=77 MUST precede UPDATE id=27.name
--     to avoid a transient duplicate-key error on the unique constraint.
--
-- ******************************************************************************
-- *** SOURCE-RECONVERGENCE HARD-GATE ***
-- ***                                                                        ***
-- *** RawDB.xlsx recipe master LIKELY holds BOTH "Diversion Gose" AND       ***
-- *** "Qrew - Diversion Gose" as SEPARATE ROWS. Re-ingest of RawDB.xlsx     ***
-- *** into ref_recipes WILL REINTRODUCE id=77 (or an equivalent stub row)   ***
-- *** UNLESS the xlsx is consolidated first.                                 ***
-- ***                                                                        ***
-- *** ACTION REQUIRED before or alongside this migration:                   ***
-- ***   Update RawDB.xlsx recipe master so "Diversion Gose" and             ***
-- ***   "Qrew - Diversion Gose" map to a single row named                   ***
-- ***   "Qrew - Diversion Gose" (matching the post-migration canonical).    ***
-- ***   OR: add this consolidation to the re-ingest checklist so that       ***
-- ***   any future RawDB re-ingest does NOT re-create the id=77 stub.       ***
-- ***                                                                        ***
-- *** This migration is a NO-OP guard if the xlsx drift is not resolved.    ***
-- ******************************************************************************
--
-- Risk: LOW. id=77 has zero production references. The only FK being
--       redirected is alias id=23 (recipe_id 77→27). ref_beer_types id=52
--       aliases column is extended, not narrowed. ref_recipes id=27 rename
--       is a string update on a row with full data integrity; all downstream
--       FKs to id=27 are unaffected (integer FK, not string).
--
-- Rollback:
--   -- 1. Revert ref_beer_types id=52
--   UPDATE ref_beer_types
--      SET beer_name = 'Diversion Gose',
--          aliases   = 'Div.Gose'
--    WHERE id = 52
--      AND beer_name = 'Qrew - Diversion Gose';
--
--   -- 2. Revert ref_recipes id=27 name
--   UPDATE ref_recipes
--      SET name = 'Diversion Gose'
--    WHERE id = 27
--      AND name = 'Qrew - Diversion Gose';
--
--   -- 3. Re-insert ref_recipes id=77
--   INSERT INTO ref_recipes
--     (id, name, sku_prefix, subtype, vintage, is_active)
--   VALUES
--     (77, 'Qrew - Diversion Gose', 'QDG', 'CollabIn', '', 1);
--
--   -- 4. Revert alias id=23 back to recipe_id=77
--   UPDATE ref_recipe_aliases SET recipe_id = 77 WHERE id = 23 AND recipe_id = 27;
--
--   -- 5. Re-insert profile shells for id=77
--   INSERT INTO ref_recipe_profile (id, recipe_id)
--   VALUES (1818, 77), (1819, 77);
--
--   -- 6. Purge the audit trail written by this migration
--   DELETE FROM audit_row_revisions WHERE comment LIKE 'consolidation_mig217_%';
--
-- Date   : 2026-05-30
-- Author : web

-- ── STEP 1: Audit alias migration (BEFORE update) ────────────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_217',
  'ref_recipe_aliases',
  id,
  'update',
  JSON_OBJECT('recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 27),
  'consolidation_mig217_alias_migrate'
FROM ref_recipe_aliases
WHERE id = 23
  AND recipe_id = 77;

-- ── STEP 2: Migrate alias QDG (id=23) from recipe 77 → 27 ───────────────────
UPDATE ref_recipe_aliases
   SET recipe_id = 27
 WHERE id = 23
   AND recipe_id = 77;

-- ── STEP 3: Audit profile shell deletion (BEFORE delete) ─────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_217',
  'ref_recipe_profile',
  id,
  'update',
  JSON_OBJECT('id', id, 'recipe_id', recipe_id),
  JSON_OBJECT('_tombstone', 'deleted_by_mig217'),
  'consolidation_mig217_profile_cleanup'
FROM ref_recipe_profile
WHERE recipe_id = 77;

-- ── STEP 4: Delete NULL profile shells for recipe 77 ─────────────────────────
DELETE FROM ref_recipe_profile
 WHERE recipe_id = 77;

-- ── STEP 5: Audit recipe 77 deletion (BEFORE delete) ─────────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_217',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT(
    'id',         id,
    'name',       name,
    'sku_prefix', sku_prefix,
    'subtype',    subtype,
    'vintage',    vintage,
    'is_active',  is_active
  ),
  JSON_OBJECT('_tombstone', 'deleted_by_mig217'),
  'consolidation_mig217_recipe_delete'
FROM ref_recipes
WHERE id = 77
  AND name = 'Qrew - Diversion Gose';

-- ── STEP 6: Delete stub recipe 77 ────────────────────────────────────────────
DELETE FROM ref_recipes
 WHERE id = 77
   AND name = 'Qrew - Diversion Gose';

-- ── STEP 7: Audit recipe 27 rename (BEFORE update) ───────────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_217',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT('name', name),
  JSON_OBJECT('name', 'Qrew - Diversion Gose'),
  'consolidation_mig217_rename_canonical'
FROM ref_recipes
WHERE id = 27
  AND name = 'Diversion Gose';

-- ── STEP 8: Rename canonical recipe 27 ───────────────────────────────────────
UPDATE ref_recipes
   SET name = 'Qrew - Diversion Gose'
 WHERE id = 27
   AND name = 'Diversion Gose';

-- ── STEP 9: Audit ref_beer_types rename (BEFORE update) ──────────────────────
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_217',
  'ref_beer_types',
  id,
  'update',
  JSON_OBJECT('beer_name', beer_name, 'aliases', aliases),
  JSON_OBJECT(
    'beer_name', 'Qrew - Diversion Gose',
    'aliases',   'Div.Gose, Diversion Gose, QDG, Qrew - Diversion Gose'
  ),
  'consolidation_mig217_beertypes_rename'
FROM ref_beer_types
WHERE id = 52
  AND beer_name = 'Diversion Gose';

-- ── STEP 10: Rename ref_beer_types id=52 and extend aliases ──────────────────
UPDATE ref_beer_types
   SET beer_name = 'Qrew - Diversion Gose',
       aliases   = 'Div.Gose, Diversion Gose, QDG, Qrew - Diversion Gose'
 WHERE id = 52
   AND beer_name = 'Diversion Gose';
