-- db/migrations/221_eph_sku_prefix_backfill.sql
--
-- What: Backfill sku_prefix onto the operative (newest) ref_recipes row for
--       each of the four EPH recipes (EPH1–EPH4), and repoint all ref_skus
--       rows from the oldest vintage row to the operative row.
--
-- Why : EPH beers have one ref_recipes row per vintage. The sku_prefix column
--       was only set on the OLDEST vintage row; the operative (newest) row has
--       sku_prefix = NULL. SKU-format activation checks
--       `WHERE recipe_id = ? AND format_id = ?` against ref_skus — when
--       ref_skus.recipe_id points at the oldest row but the activation call
--       uses the operative row id, the check finds nothing and attempts a
--       duplicate INSERT, which then fails on the ref_skus.uniq_sku_code
--       UNIQUE constraint. Fix: set sku_prefix on the operative row AND
--       repoint the existing SKUs to that row so the activation check succeeds.
--
-- Operative row ← oldest row (sku_prefix to copy):
--   EPH1: operative id=62 ← oldest id=58 (prefix 'EPH1'); 7 ref_skus rows
--   EPH2: operative id=76 ← oldest id=63 (prefix 'EPH2'); 9 ref_skus rows
--   EPH3: operative id=71 ← oldest id=68 (prefix 'EPH3'); 7 ref_skus rows
--   EPH4: operative id=75 ← oldest id=72 (prefix 'EPH4'); 11 ref_skus rows
--
-- Idempotency: every UPDATE carries a WHERE guard matching the pre-migration
--   state (sku_prefix IS NULL for recipe rows; original recipe_id for sku rows).
--   Re-running after application changes 0 rows.
--
-- Audit: both ref_recipes and ref_skus are corrections_policy-gated reference
--   tables. Audit rows are written via INSERT...SELECT (captures live before-
--   state) before each data UPDATE, following the house pattern from mig217.
--   action='update' (ENUM has no 'delete' value — do NOT use 'delete').
--
-- Safety check: 2026-05-31
--   ref_recipes ids 62/76/71/75 confirmed sku_prefix=NULL.
--   ref_recipes ids 58/63/68/72 confirmed sku_prefix='EPH1'/'EPH2'/'EPH3'/'EPH4'.
--   ref_skus counts: recipe_id=58 → 7, 63 → 9, 68 → 7, 72 → 11.
--   ref_skus recipe_ids 62/76/71/75 all have 0 rows (nothing pre-repointed).
--
-- Rollback:
--   -- Revert sku_prefix on operative rows
--   UPDATE ref_recipes SET sku_prefix = NULL WHERE id IN (62, 76, 71, 75) AND sku_prefix IN ('EPH1','EPH2','EPH3','EPH4');
--   -- Repoint ref_skus back to oldest rows
--   UPDATE ref_skus SET recipe_id = 58 WHERE recipe_id = 62;
--   UPDATE ref_skus SET recipe_id = 63 WHERE recipe_id = 76;
--   UPDATE ref_skus SET recipe_id = 68 WHERE recipe_id = 71;
--   UPDATE ref_skus SET recipe_id = 72 WHERE recipe_id = 75;
--   -- Purge audit rows written by this migration
--   DELETE FROM audit_row_revisions WHERE comment LIKE '%_mig221_%';
--
-- Date   : 2026-05-31
-- Author : migration_221


-- ═══════════════════════════════════════════════════════════════════════════════
-- EPH1  (operative id=62 ← oldest id=58, prefix 'EPH1')
-- ═══════════════════════════════════════════════════════════════════════════════

-- STEP 1: Audit ref_recipes id=62 before setting sku_prefix
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT('id', id, 'name', name, 'sku_prefix', sku_prefix, 'vintage', vintage),
  JSON_OBJECT('sku_prefix', 'EPH1'),
  'eph_prefix_backfill_mig221_recipes_eph1'
FROM ref_recipes
WHERE id = 62
  AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 2: Set sku_prefix='EPH1' on operative row id=62
UPDATE ref_recipes
   SET sku_prefix = 'EPH1'
 WHERE id = 62
   AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 3: Audit ref_skus rows before repointing recipe_id 58 → 62
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_skus',
  id,
  'update',
  JSON_OBJECT('id', id, 'sku_code', sku_code, 'recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 62),
  'eph_prefix_backfill_mig221_skus_eph1'
FROM ref_skus
WHERE recipe_id = 58;

-- STEP 4: Repoint ref_skus from oldest row (58) to operative row (62)
UPDATE ref_skus
   SET recipe_id = 62
 WHERE recipe_id = 58;


-- ═══════════════════════════════════════════════════════════════════════════════
-- EPH2  (operative id=76 ← oldest id=63, prefix 'EPH2')
-- ═══════════════════════════════════════════════════════════════════════════════

-- STEP 5: Audit ref_recipes id=76 before setting sku_prefix
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT('id', id, 'name', name, 'sku_prefix', sku_prefix, 'vintage', vintage),
  JSON_OBJECT('sku_prefix', 'EPH2'),
  'eph_prefix_backfill_mig221_recipes_eph2'
FROM ref_recipes
WHERE id = 76
  AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 6: Set sku_prefix='EPH2' on operative row id=76
UPDATE ref_recipes
   SET sku_prefix = 'EPH2'
 WHERE id = 76
   AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 7: Audit ref_skus rows before repointing recipe_id 63 → 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_skus',
  id,
  'update',
  JSON_OBJECT('id', id, 'sku_code', sku_code, 'recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 76),
  'eph_prefix_backfill_mig221_skus_eph2'
FROM ref_skus
WHERE recipe_id = 63;

-- STEP 8: Repoint ref_skus from oldest row (63) to operative row (76)
UPDATE ref_skus
   SET recipe_id = 76
 WHERE recipe_id = 63;


-- ═══════════════════════════════════════════════════════════════════════════════
-- EPH3  (operative id=71 ← oldest id=68, prefix 'EPH3')
-- ═══════════════════════════════════════════════════════════════════════════════

-- STEP 9: Audit ref_recipes id=71 before setting sku_prefix
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT('id', id, 'name', name, 'sku_prefix', sku_prefix, 'vintage', vintage),
  JSON_OBJECT('sku_prefix', 'EPH3'),
  'eph_prefix_backfill_mig221_recipes_eph3'
FROM ref_recipes
WHERE id = 71
  AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 10: Set sku_prefix='EPH3' on operative row id=71
UPDATE ref_recipes
   SET sku_prefix = 'EPH3'
 WHERE id = 71
   AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 11: Audit ref_skus rows before repointing recipe_id 68 → 71
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_skus',
  id,
  'update',
  JSON_OBJECT('id', id, 'sku_code', sku_code, 'recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 71),
  'eph_prefix_backfill_mig221_skus_eph3'
FROM ref_skus
WHERE recipe_id = 68;

-- STEP 12: Repoint ref_skus from oldest row (68) to operative row (71)
UPDATE ref_skus
   SET recipe_id = 71
 WHERE recipe_id = 68;


-- ═══════════════════════════════════════════════════════════════════════════════
-- EPH4  (operative id=75 ← oldest id=72, prefix 'EPH4')
-- ═══════════════════════════════════════════════════════════════════════════════

-- STEP 13: Audit ref_recipes id=75 before setting sku_prefix
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_recipes',
  id,
  'update',
  JSON_OBJECT('id', id, 'name', name, 'sku_prefix', sku_prefix, 'vintage', vintage),
  JSON_OBJECT('sku_prefix', 'EPH4'),
  'eph_prefix_backfill_mig221_recipes_eph4'
FROM ref_recipes
WHERE id = 75
  AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 14: Set sku_prefix='EPH4' on operative row id=75
UPDATE ref_recipes
   SET sku_prefix = 'EPH4'
 WHERE id = 75
   AND (sku_prefix IS NULL OR sku_prefix = '');

-- STEP 15: Audit ref_skus rows before repointing recipe_id 72 → 75
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0,
  'migration_221',
  'ref_skus',
  id,
  'update',
  JSON_OBJECT('id', id, 'sku_code', sku_code, 'recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 75),
  'eph_prefix_backfill_mig221_skus_eph4'
FROM ref_skus
WHERE recipe_id = 72;

-- STEP 16: Repoint ref_skus from oldest row (72) to operative row (75)
UPDATE ref_skus
   SET recipe_id = 75
 WHERE recipe_id = 72;
