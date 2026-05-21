-- db/migrations/073_ref_skus_add_format_recipe_fk.sql
-- What: Extend ref_skus with format_id FK; add FK constraint on existing recipe_id col.
-- Why:  Connects each SKU to its canonical packaging format (for slot template lookup).
--       The recipe_id column already exists on ref_skus (int unsigned, MUL index,
--       139/148 rows populated across 19 recipes) — it just lacked a FK constraint.
--       We promote it to a proper FK rather than introducing a duplicate `recipe_id_fk`.
-- Risk: ALTER on ref_skus (live table, ~148 rows). ADD COLUMN IF NOT EXISTS for format_id.
--       FK on recipe_id will fail if any row holds a value not in ref_recipes — we
--       verified 19/19 distinct values resolve cleanly (2026-05-21).
-- Rollback:
--   ALTER TABLE ref_skus
--     DROP FOREIGN KEY fk_skus_format,
--     DROP FOREIGN KEY fk_skus_recipe,
--     DROP COLUMN format_id;

START TRANSACTION;

ALTER TABLE ref_skus
  ADD COLUMN format_id BIGINT UNSIGNED NULL
    COMMENT 'FK to ref_packaging_formats; NULL until SKU builder backfill' AFTER format;

ALTER TABLE ref_skus
  ADD CONSTRAINT fk_skus_format FOREIGN KEY (format_id) REFERENCES ref_packaging_formats(id),
  ADD CONSTRAINT fk_skus_recipe FOREIGN KEY (recipe_id)  REFERENCES ref_recipes(id);

COMMIT;
