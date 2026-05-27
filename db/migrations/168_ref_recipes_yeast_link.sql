-- db/migrations/168_ref_recipes_yeast_link.sql
-- What: Add yeast strain FK + per-recipe process overrides to ref_recipes.
--       (A) yeast_strain_id_fk → ref_yeast_strains(id): links a recipe to its yeast.
--           Family resolves via JOIN: ref_recipes → ref_yeast_strains → ref_yeast_family_defaults.
--       (B) garde_days_min_override: when non-NULL, overrides the family default for this recipe.
--       (C) ferm_temp_min_override / ferm_temp_max_override: per-recipe temperature overrides.
--       No garde-MAX column — the operator dropped garde-max entirely.
--       No yeast_family string column — family always derives via FK JOIN, never stored redundantly.
-- Why:  Completes the three-tier COALESCE chain for the Saisie Transferts eligibility gate:
--         COALESCE(ref_recipes.garde_days_min_override,
--                  ref_yeast_family_defaults.garde_days_min, <fallback floor>)
--       All new columns are NULL = "inherit from family" (no data loss if FK not yet populated).
-- Risk: LOW — ADD COLUMN NULL, ALGORITHM=INSTANT. FK is ON DELETE RESTRICT (a yeast strain
--       cannot be deleted while recipes reference it). No existing rows are invalidated.
--       ref_yeast_strains.id is INT UNSIGNED — FK column type matches exactly (anti-pattern #3).
-- Rollback:
--   ALTER TABLE ref_recipes
--     DROP FOREIGN KEY fk_recipes_yeast_strain,
--     DROP INDEX idx_recipes_yeast_strain_fk,
--     DROP COLUMN ferm_temp_max_override,
--     DROP COLUMN ferm_temp_min_override,
--     DROP COLUMN garde_days_min_override,
--     DROP COLUMN yeast_strain_id_fk;

-- ── A. Add columns (INSTANT: nullable, appended at end) ──────────────────────

ALTER TABLE ref_recipes
  ADD COLUMN `yeast_strain_id_fk`      INT UNSIGNED NULL
    COMMENT 'FK to ref_yeast_strains.id. Family resolves via JOIN → ref_yeast_family_defaults. NULL = not yet classified by operator.',
  ADD COLUMN `garde_days_min_override`  INT UNSIGNED NULL
    COMMENT 'Per-recipe garde minimum (days). Overrides ref_yeast_family_defaults.garde_days_min when non-NULL. NULL = inherit from family.',
  ADD COLUMN `ferm_temp_min_override`   DECIMAL(4,1) NULL
    COMMENT 'Per-recipe lower fermentation temperature bound (°C). Overrides family default when non-NULL.',
  ADD COLUMN `ferm_temp_max_override`   DECIMAL(4,1) NULL
    COMMENT 'Per-recipe upper fermentation temperature bound (°C). Overrides family default when non-NULL.',
  ALGORITHM=INSTANT;

-- ── B. FK constraint and index ────────────────────────────────────────────────
-- Separate ALTER so that if A succeeded but the FK data check fails, the column
-- additions are already recorded and re-running is safe.
-- ON DELETE RESTRICT: a yeast strain in use by a recipe cannot be deleted.
-- ON UPDATE CASCADE: if a yeast strain's id changes, the FK follows automatically.

ALTER TABLE ref_recipes
  ADD CONSTRAINT fk_recipes_yeast_strain
    FOREIGN KEY (yeast_strain_id_fk) REFERENCES ref_yeast_strains (id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  ADD KEY idx_recipes_yeast_strain_fk (yeast_strain_id_fk);
