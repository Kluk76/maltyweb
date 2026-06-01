-- Migration 253: hop addition stage taxonomy on ref_recipe_ingredients
--
-- MODEL
-- ------
-- Adds hop_addition_stage (mash|first_wort|boil|hop_stand|dry_hop) and
-- hop_boil_time_min (SMALLINT) to ref_recipe_ingredients, enabling the
-- derivation: recipe is dry-hopped ⟺ has ≥1 hop line with stage='dry_hop'.
--
-- Two GENERATED STORED columns (stage_key, boil_time_key) replace the two
-- unique-key NULL coalesce that MySQL would otherwise need per-lookup, and
-- allow the composite UNIQUE to handle multiple hop additions of the same MI
-- (e.g. same hop added at boil and at dry-hop) without colliding.
--
-- EXISTING DATA
-- -------------
-- All 47 existing hop lines — and all non-hop lines — retain stage=NULL,
-- boil_time=NULL. No seeding is performed: we never guess which existing
-- line is a dry-hop addition. Operator assigns stages via the recipe UI.
--
-- The old UNIQUE uq_recipe_mi (recipe_id, mi_id_fk) is replaced by the
-- wider uq_recipe_mi_stage (recipe_id, mi_id_fk, stage_key, boil_time_key).
-- For all existing rows stage_key='none' and boil_time_key=-1, so existing
-- uniqueness is fully preserved (no collisions introduced).
--
-- NOTE on boil_time_key: it is signed SMALLINT (not UNSIGNED) because the
-- COALESCE sentinel for "no boil time" is -1, which UNSIGNED cannot hold.
--
-- NOTE on CHECK name: prefixed chk_rri_ per the MySQL-8 CHECK gotcha
-- (CHECK constraint names are schema-scoped, not table-scoped).

-- Step 1: add the four new columns
ALTER TABLE ref_recipe_ingredients
    ADD COLUMN hop_addition_stage ENUM('mash','first_wort','boil','hop_stand','dry_hop') NULL
        AFTER unit,
    ADD COLUMN hop_boil_time_min SMALLINT UNSIGNED NULL
        AFTER hop_addition_stage,
    ADD COLUMN stage_key VARCHAR(16) GENERATED ALWAYS AS (COALESCE(hop_addition_stage, 'none')) STORED
        AFTER hop_boil_time_min,
    ADD COLUMN boil_time_key SMALLINT GENERATED ALWAYS AS (COALESCE(hop_boil_time_min, -1)) STORED
        AFTER stage_key;

-- Step 2: drop the old two-column unique
ALTER TABLE ref_recipe_ingredients
    DROP INDEX uq_recipe_mi;

-- Step 3: add the new four-column unique (replaces uq_recipe_mi)
ALTER TABLE ref_recipe_ingredients
    ADD UNIQUE KEY uq_recipe_mi_stage (recipe_id, mi_id_fk, stage_key, boil_time_key);

-- Step 4: enforce the boil-time ↔ stage invariant
--   - stage='boil'  → boil_time_min MUST be set
--   - stage≠'boil'  → boil_time_min MUST be NULL
--   - stage=NULL    → boil_time_min MUST be NULL
ALTER TABLE ref_recipe_ingredients
    ADD CONSTRAINT chk_rri_boil_time CHECK (
        (hop_addition_stage = 'boil' AND hop_boil_time_min IS NOT NULL)
        OR (hop_addition_stage <> 'boil' AND hop_boil_time_min IS NULL)
        OR (hop_addition_stage IS NULL    AND hop_boil_time_min IS NULL)
    ) ENFORCED;
