-- 335_recipe_design_targets.sql
-- Human-entered design targets stored directly on ref_recipes.
-- OG and FG in °Plato (matching bd_* gravity columns which are stored in °Plato, NOT SG).
-- pH is dimensionless (typically 4.0–5.5 for beer).
-- ABV in % vol.
-- All columns are NULL by default — "no target set" — zero impact on existing readers.
-- These are operator-entered DESIGN INPUTS, not derived/computed values.
-- ref_recipe_profile (which holds observed avg_og etc.) is NOT touched.

ALTER TABLE ref_recipes
  ADD COLUMN og_target  DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN fg_target  DECIMAL(5,2) NULL DEFAULT NULL,
  ADD COLUMN ph_target  DECIMAL(4,2) NULL DEFAULT NULL,
  ADD COLUMN abv_target DECIMAL(4,2) NULL DEFAULT NULL;
