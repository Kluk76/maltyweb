-- db/migrations/166_yeast_strains_add_family.sql
-- What: Add `family` column to ref_yeast_strains.
--       `family` is the process bucket (ale/lager/non_alcool/spontane/mixte) used
--       to resolve garde-min thresholds via ref_yeast_family_defaults (migration 167).
--       It is distinct from the existing `type` column, which encodes strain biology
--       (ale/lager/wild/hybrid/unknown). Do NOT conflate them — a "lager" type strain
--       used in a non-alcoholic recipe would carry type=lager, family=non_alcool.
-- Why:  Phase 1 of Saisie Transferts — the eligibility gate resolves garde_days_min as
--       COALESCE(ref_recipes.garde_days_min_override,
--                ref_yeast_family_defaults.garde_days_min, <floor>)
--       via ref_recipes.yeast_strain_id_fk → ref_yeast_strains.family.
-- Risk: LOW — ADD COLUMN NULL, ALGORITHM=INSTANT. No existing consumers of this column.
--       Leave all rows NULL; operator classifies strains explicitly (never infer from `type`).
-- Rollback: ALTER TABLE ref_yeast_strains DROP COLUMN family;

ALTER TABLE ref_yeast_strains
  ADD COLUMN family ENUM('ale','lager','non_alcool','spontane','mixte') COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'Process-family bucket for garde-min resolution. Distinct from `type` (biology). Operator-classified; NULL until set.'
  AFTER `type`,
  ALGORITHM=INSTANT;
