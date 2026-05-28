-- db/migrations/199_ref_beer_types_ale_or_lager_nonalc.sql
--
-- What: Extend ref_beer_types.ale_or_lager ENUM to include 'NonAlc'.
--
-- Why:  BSF BeerTypes tab has AleOrLager='NonAlc' for non-alcoholic beers
--       (discovered during Phase 1 seed run 2026-05-28). The original migration
--       194 only included 'Ale'|'Lager'. MySQL strict mode rejects unknown ENUM
--       values → seed fails. Extending the ENUM is the correct fix (preserve
--       the data, not NULL-coerce it).
--
-- MySQL 8 note: ENUM extension via MODIFY COLUMN is not INSTANT DDL but the
--   table is empty at this point (seed has not run yet), so no rewrite needed.
--   ALGORITHM=INPLACE is safe here.
--
-- Rollback:
--   ALTER TABLE ref_beer_types
--     MODIFY COLUMN ale_or_lager ENUM('Ale','Lager') NULL DEFAULT NULL
--     COLLATE utf8mb4_unicode_ci;
--
-- NOTE: No bare SELECT statements.

ALTER TABLE ref_beer_types
  MODIFY COLUMN ale_or_lager
    ENUM('Ale','Lager','NonAlc')
    NULL DEFAULT NULL
    COLLATE utf8mb4_unicode_ci
    COMMENT 'AleOrLager classification from BSF BeerTypes col J';

-- end migration 199
