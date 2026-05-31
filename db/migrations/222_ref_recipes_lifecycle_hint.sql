-- db/migrations/222_ref_recipes_lifecycle_hint.sql
--
-- What: Add a per-recipe lifecycle override hint column to ref_recipes.
--       Set TM-ST (id=55) to 'historical' so the lifecycle view can force
--       it to 'passee' even though it has never been brewed.
--
-- Why : The derived lifecycle model cannot distinguish "genuinely new,
--       upcoming contract" (last_brew IS NULL + recently created) from
--       "pre-2021 contract that will never be brewed again" (last_brew IS
--       NULL + bulk-imported with uniform created_at). TM-ST is an example
--       of the latter. A minimal per-recipe hint column lets the operator
--       override exactly this underivable case without changing the model.
--
--       Contracts MUST still be able to show 'upcoming' for genuinely new
--       contracts — this is NOT a blanket "contracts never upcoming" rule.
--       The DEFAULT is 'auto', so every other contract is unaffected.
--
-- Idempotency:
--   The ALTER TABLE has no IF NOT EXISTS guard (MySQL 8 does not support it
--   on ADD COLUMN). migrate.php records the filename in schema_migrations
--   after first application, so this file will not be re-run. If applied
--   manually a second time, MySQL will raise a duplicate-column error — the
--   expected and safe outcome.
--
--   The audit INSERT and UPDATE both carry a WHERE guard that matches only
--   the pre-migration state (lifecycle_hint <> 'historical'), so re-running
--   the data statements after the column exists changes 0 rows.
--
-- Audit:
--   ref_recipes is a corrections_policy-gated reference table. An audit row
--   is written via INSERT...SELECT (captures live before-state) before the
--   data UPDATE. action='update' (ENUM has no 'delete' value).
--   Pattern follows migration 221.
--
-- Safety check: 2026-05-31
--   ref_recipes id=55 confirmed: name='TM-ST', classification='Contract',
--   vintage='', is_active=1. lifecycle_hint column absent (pre-migration).
--   Current v_recipe_lifecycle: state=upcoming, flag=production_a_venir,
--   list_bucket=contrats.
--   Expected post-migration: state=passee, flag=NULL, list_bucket=contrats
--   (Contract gate in list_bucket CASE fires first — bucket is unchanged).
--
-- Rollback:
--   UPDATE ref_recipes SET lifecycle_hint = 'auto' WHERE id = 55 AND lifecycle_hint = 'historical';
--   ALTER TABLE ref_recipes DROP COLUMN lifecycle_hint;
--   DELETE FROM audit_row_revisions WHERE comment = 'lifecycle_hint_mig222_tmst';
--
-- Date  : 2026-05-31
-- Author: migration_222


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: Add lifecycle_hint column to ref_recipes
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE ref_recipes
    ADD COLUMN lifecycle_hint ENUM('auto', 'historical') NOT NULL DEFAULT 'auto';


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: Audit ref_recipes id=55 before setting lifecycle_hint='historical'
--
-- INSERT...SELECT captures the live before-state. The WHERE guard
-- (lifecycle_hint <> 'historical') ensures this is a no-op on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_222',
    'ref_recipes',
    id,
    'update',
    JSON_OBJECT(
        'id',             id,
        'name',           name,
        'classification', classification,
        'lifecycle_hint', lifecycle_hint
    ),
    JSON_OBJECT('lifecycle_hint', 'historical'),
    'lifecycle_hint_mig222_tmst'
FROM ref_recipes
WHERE id = 55
  AND lifecycle_hint <> 'historical';


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: Set lifecycle_hint='historical' on TM-ST (id=55)
--
-- The WHERE guard makes this idempotent: re-running after first application
-- matches 0 rows (lifecycle_hint is already 'historical').
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_recipes
   SET lifecycle_hint = 'historical'
 WHERE id = 55
   AND lifecycle_hint <> 'historical';
