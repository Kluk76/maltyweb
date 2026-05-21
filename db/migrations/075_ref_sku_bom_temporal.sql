-- db/migrations/075_ref_sku_bom_temporal.sql
-- What: Extend ref_sku_bom with temporal columns (compiled_at, source enum,
--       effective_from, effective_until) + index.
-- Why:  v1 scaffold for SCD2 versioning (project_recipe_versioning_temporal.md).
--       Columns default NULL — zero impact on existing rows / queries.
--       v2: recompile writes compiled_at=NOW(), effective_from=NOW(); old rows get
--       effective_until = NOW() - INTERVAL 1 SECOND.
--       v3: downstream consumers add WHERE :as_of BETWEEN effective_from AND COALESCE(effective_until, '2999-12-31').
-- Also adds source ENUM to distinguish liquid vs packaging lines for builder UI display.
-- Risk: ALTER TABLE on ref_sku_bom (~300+ rows). IF NOT EXISTS guards. No data change.
-- Rollback:
--   ALTER TABLE ref_sku_bom
--     DROP KEY idx_bom_effective,
--     DROP COLUMN compiled_at,
--     DROP COLUMN bom_source,
--     DROP COLUMN effective_from,
--     DROP COLUMN effective_until;

START TRANSACTION;

ALTER TABLE ref_sku_bom
  ADD COLUMN IF NOT EXISTS compiled_at    TIMESTAMP NULL
    COMMENT 'Set when row is written by SKU builder compiler; NULL = legacy row',
  ADD COLUMN IF NOT EXISTS bom_source     ENUM('liquid','packaging','composite_liquid','composite_packaging') NULL
    COMMENT 'Line category for UI grouping: liquid=recipe ingredient, packaging=consumable, composite_*=from constituent beer',
  ADD COLUMN IF NOT EXISTS effective_from DATE NULL
    COMMENT 'v1 dormant; v2 SCD2 activation: date from which this BOM version is valid',
  ADD COLUMN IF NOT EXISTS effective_until DATE NULL
    COMMENT 'v1 dormant; v2 SCD2 close-out: date from which this BOM version is superseded';

ALTER TABLE ref_sku_bom
  ADD KEY IF NOT EXISTS idx_bom_effective (effective_from, effective_until);

COMMIT;
