-- 014 — fix UNIQUE(name, vintage) to actually dedup rows where vintage IS unset.
--
-- MySQL UNIQUE allows multiple NULL values (NULL != NULL). With vintage NULL
-- on most non-EPH recipes, INSERT IGNORE re-inserted them on every run.
-- Fix: vintage NOT NULL DEFAULT '' so missing vintage normalizes to '' and
-- UNIQUE strictly enforces.
--
-- Steps:
--   1. Clear current contents (75 rows is cheap to re-seed).
--   2. Coerce remaining NULLs (defensive — already cleared, but in case).
--   3. ALTER vintage to NOT NULL DEFAULT ''.

DELETE FROM ref_recipes;
DELETE FROM ref_clients;

ALTER TABLE ref_recipes
  MODIFY COLUMN vintage VARCHAR(8) NOT NULL DEFAULT '';
