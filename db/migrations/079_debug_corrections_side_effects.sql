-- db/migrations/079_debug_corrections_side_effects.sql
-- What: Add `side_effects` column to debug_corrections for alias-upsert audit trail
-- Why:  When an operator corrects bd_brewing_ingredients_parsed.mi_id_fk, the fix
--       now durably upserts the raw_name into ref_mi_aliases so that future re-parses
--       resolve the same mapping without wiping the correction. The structured record
--       of which aliases were inserted/updated lives here.
-- Risk: Additive-only (nullable column). No existing rows affected. No rollback needed.
-- Rollback: ALTER TABLE debug_corrections DROP COLUMN side_effects;

ALTER TABLE debug_corrections
  ADD COLUMN `side_effects` MEDIUMTEXT COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'JSON record of alias upserts and other side effects applied alongside this correction'
  AFTER `old_values`;
