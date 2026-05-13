-- 042 — Widen bd_packaging.recipe_code from VARCHAR(32) to VARCHAR(255).
--
-- Root cause: recipe_code VARCHAR(32) was rejecting long contract-beer recipe
-- identifiers such as "Les Combières - La P'tite à Piguet 3" (36 chars) and
-- "BLZ Company - WestCoast Pale Ale 12" (35 chars), crashing the BSF→MySQL
-- ingest cron on every packaging row containing such a value.  Last successful
-- packaging ingest was 2026-05-08 16:59:49; 4 rows were blocked.
--
-- Note: the field is NOT a short code — operators enter full human-readable
-- recipe identifiers, typically of the form "<Client> - <Beer name> <batch>".
-- VARCHAR(255) is the safe generous ceiling; no realistic recipe name should
-- exceed that limit.  The underlying data are client/beer names, not fixed-
-- length codes, so widening is the correct fix (not rejecting long values).
--
-- Down-migration:
--   ALTER TABLE bd_packaging MODIFY recipe_code VARCHAR(32) NULL;
--   (Caution: would truncate any stored value longer than 32 chars.)

ALTER TABLE bd_packaging MODIFY recipe_code VARCHAR(255) NULL;
