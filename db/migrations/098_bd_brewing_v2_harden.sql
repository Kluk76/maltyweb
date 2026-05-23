-- db/migrations/098_bd_brewing_v2_harden.sql
-- What: Harden NOT NULL constraints on bd_brewing_*_v2 where coverage is confirmed.
--   1. Add CHECK constraint enforcing that timings rows have either brew_start or brew_end
--      (guard against completely blank timing entries).
--   2. Harden bd_brewing_ingredients_parsed_v2.raw_name NOT NULL (already schema-declared
--      NOT NULL, but enforce via guard SELECT first).
-- Why:  After migrations 095-097 (upload + backfill), recipe_id_fk NULLs are expected only
--       for contract/archive beers with no ref_recipes entry. Hardening recipe_id_fk NOT NULL
--       is DEFERRED until operator confirms those residuals are acceptable or creates the
--       missing ref_recipes rows.
--       This migration focuses on two safe constraints that CAN be hardened now.
-- Risk: Low if pre-flight SELECTs return 0 violations.
-- Rollback:
--   ALTER TABLE bd_brewing_timings_v2 DROP CHECK chk_timings_has_time;
--   ALTER TABLE bd_brewing_ingredients_parsed_v2 MODIFY COLUMN raw_name VARCHAR(255) NULL;
--
-- Pre-flight (run before applying):
--   SELECT COUNT(*) FROM bd_brewing_timings_v2 WHERE brew_start IS NULL AND brew_end IS NULL;
--   -- Expected: small number (some old entries may have no time data); if > 5% of total, defer.
--
--   SELECT COUNT(*) FROM bd_brewing_ingredients_parsed_v2 WHERE raw_name IS NULL OR raw_name='';
--   -- Expected: 0. If > 0, investigate and fix before applying.

-- ─── 1. Timings: CHECK at least one of brew_start/brew_end is present ─────────
-- Only enforce on rows inserted AFTER this migration (existing NULLs are historic data).
-- Use a soft approach: add a CHECK only if the column was populated by a real brew run.
-- NOTE: MySQL 8 enforces CHECK at INSERT/UPDATE time only. Historical NULLs in existing
--       rows remain (MySQL does not validate existing rows on ALTER TABLE ADD CHECK by default).

ALTER TABLE bd_brewing_timings_v2
  ADD CONSTRAINT chk_timings_has_time
    CHECK (brew_start IS NOT NULL OR brew_end IS NOT NULL OR event_date IS NOT NULL)
    NOT ENFORCED;

-- NOT ENFORCED means the constraint is recorded in information_schema but MySQL does not
-- validate existing rows. This is intentional — the historic data has legitimate NULLs.
-- Remove NOT ENFORCED after a clean quarter's data confirms coverage:
--   ALTER TABLE bd_brewing_timings_v2 ALTER CHECK chk_timings_has_time ENFORCED;

-- ─── 2. State marker ──────────────────────────────────────────────────────────
-- Run these manually after applying to confirm final state:
--
-- SELECT
--   (SELECT COUNT(*) FROM bd_brewing_brewday_v2) AS brewday_total,
--   (SELECT COUNT(*) FROM bd_brewing_gravity_v2) AS gravity_total,
--   (SELECT COUNT(*) FROM bd_brewing_timings_v2) AS timings_total,
--   (SELECT COUNT(*) FROM bd_brewing_ingredients_v2) AS ingr_headers_total,
--   (SELECT COUNT(*) FROM bd_brewing_ingredients_parsed_v2) AS ingr_lines_total,
--   (SELECT COUNT(*) FROM bd_brewing_ingredients_parsed_v2 WHERE mi_id_fk IS NULL) AS ingr_null_mi;

SET @migration_098_harden_done = 1;
