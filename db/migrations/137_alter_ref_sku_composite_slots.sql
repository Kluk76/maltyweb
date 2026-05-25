-- db/migrations/137_alter_ref_sku_composite_slots.sql
-- What: Two alterations to ref_sku_composite_slots (0 rows — safe to alter freely):
--       1. RENAME COLUMN `multiple` → `units_per_recipe` (clarifies semantics: how many
--          units of THIS recipe appear in the composite pack; #recipes = COUNT(rows)/sku_id
--          is derived, not stored).
--       2. ADD COLUMN `member_format_id BIGINT UNSIGNED NULL` — allows a composite slot
--          to specify which packaging format each member uses (e.g. PD8 slot for ZEP
--          might use format B12; slot for EMB might use format B). NULL = inherits from
--          the member recipe's default/active format.
-- Why: Phase 1. Multipacks (PD8, future 8-beer etc.) need to know which format each
--      member recipe contributes; without member_format_id the compiler can't determine
--      per-slot container+label costs. The rename removes the ambiguous `multiple` name
--      (was it #recipes? #units? copies?).
-- Risk: RENAME COLUMN on a 0-row table → INSTANT DDL, effectively zero risk.
--       ADD COLUMN nullable → INSTANT DDL. LOW.
-- Rollback:
--   ALTER TABLE ref_sku_composite_slots CHANGE COLUMN units_per_recipe multiple INT NOT NULL DEFAULT 1;
--   ALTER TABLE ref_sku_composite_slots DROP COLUMN member_format_id;
--   ALTER TABLE ref_sku_composite_slots DROP FOREIGN KEY fk_rscs_member_format;

ALTER TABLE ref_sku_composite_slots
  CHANGE COLUMN `multiple` `units_per_recipe` INT NOT NULL DEFAULT 1
    COMMENT 'How many units of this recipe appear in the composite pack. #distinct recipes = COUNT(rows) GROUP BY sku_id.';

ALTER TABLE ref_sku_composite_slots
  ADD COLUMN member_format_id BIGINT UNSIGNED NULL
    COMMENT 'FK to ref_packaging_formats.id (BIGINT UNSIGNED — matches parent type). NULL = member contributes in its recipe-default format.',
  ADD CONSTRAINT fk_rscs_member_format
    FOREIGN KEY (member_format_id) REFERENCES ref_packaging_formats(id) ON DELETE RESTRICT;
