-- db/migrations/076_ref_recipes_sku_prefix_backfill.sql
-- What: Populate ref_recipes.sku_prefix for the 19 recipes that have ref_skus rows
--       linked via the legacy ref_skus.recipe_id column.
-- Why:  SKU builder backfill (scripts/_backfill-skus-format-recipe.ts) needs
--       sku_prefix to map recipe codes (EMB) → recipe rows during SKU code parsing.
--       Currently sku_prefix is NULL for every row.
-- Source: derived 2026-05-21 from `SELECT r.id, r.name, GROUP_CONCAT(s.sku_code)
--         FROM ref_recipes r JOIN ref_skus s ON s.recipe_id=r.id GROUP BY r.id`.
-- Risk: UPDATE on ref_recipes for 19 rows. Each UPDATE is keyed to an explicit
--       recipe id verified to currently hold ref_skus rows. IS NULL guard makes
--       re-runs no-op.
-- Rollback: UPDATE ref_recipes SET sku_prefix = NULL WHERE id IN (...);
--
-- Out of scope (flagged as follow-up):
--   * Contract beer Les Combières (recipe ids 39, 40) — the BLA4 SKU exists but has
--     recipe_id = NULL on ref_skus. Linking + sku_prefix needs operator confirmation
--     (memory: 2026-05-13 session — Blanche = La Grande à Meylan, but ambiguous).
--   * All other Contract recipes (ids 1-5, 7-9, 11-24, 34, 35, …) — no SKUs.

START TRANSACTION;

-- Neb / Core (9 recipes)
UPDATE ref_recipes SET sku_prefix = 'ALT' WHERE id = 6  AND sku_prefix IS NULL;  -- Alternative
UPDATE ref_recipes SET sku_prefix = 'DIV' WHERE id = 25 AND sku_prefix IS NULL;  -- Diversion
UPDATE ref_recipes SET sku_prefix = 'DIB' WHERE id = 26 AND sku_prefix IS NULL;  -- Diversion Blanche
UPDATE ref_recipes SET sku_prefix = 'DOA' WHERE id = 30 AND sku_prefix IS NULL;  -- Double Oat
UPDATE ref_recipes SET sku_prefix = 'EMB' WHERE id = 32 AND sku_prefix IS NULL;  -- Embuscade
UPDATE ref_recipes SET sku_prefix = 'MOO' WHERE id = 44 AND sku_prefix IS NULL;  -- Moonshine
UPDATE ref_recipes SET sku_prefix = 'SPY' WHERE id = 51 AND sku_prefix IS NULL;  -- Speakeasy
UPDATE ref_recipes SET sku_prefix = 'STI' WHERE id = 52 AND sku_prefix IS NULL;  -- Stirling
UPDATE ref_recipes SET sku_prefix = 'ZEP' WHERE id = 57 AND sku_prefix IS NULL;  -- Zepp

-- Neb / EPH — only the row currently holding SKUs gets the prefix.
-- The other EPH vintages (e.g. EPH1 ids 59-62) are historical batches with no SKUs.
UPDATE ref_recipes SET sku_prefix = 'EPH1' WHERE id = 58 AND sku_prefix IS NULL;
UPDATE ref_recipes SET sku_prefix = 'EPH2' WHERE id = 63 AND sku_prefix IS NULL;
UPDATE ref_recipes SET sku_prefix = 'EPH3' WHERE id = 68 AND sku_prefix IS NULL;
UPDATE ref_recipes SET sku_prefix = 'EPH4' WHERE id = 72 AND sku_prefix IS NULL;

-- Neb / CollabIn (3 with SKUs + 1 anticipated)
UPDATE ref_recipes SET sku_prefix = 'DIG' WHERE id = 27 AND sku_prefix IS NULL;  -- Diversion Gose
UPDATE ref_recipes SET sku_prefix = 'DOC' WHERE id = 29 AND sku_prefix IS NULL;  -- Docks - NEIPA
UPDATE ref_recipes SET sku_prefix = 'DGD' WHERE id = 31 AND sku_prefix IS NULL;  -- DrunkBeard - Galactic Drift
UPDATE ref_recipes SET sku_prefix = 'QDG' WHERE id = 77 AND sku_prefix IS NULL;  -- Qrew - Diversion Gose (no SKUs yet)

-- Neb / Archive (3 with SKUs)
UPDATE ref_recipes SET sku_prefix = 'BLO' WHERE id = 10 AND sku_prefix IS NULL;  -- Blonde des Romands
UPDATE ref_recipes SET sku_prefix = 'DIP' WHERE id = 28 AND sku_prefix IS NULL;  -- Diversion Panaché
UPDATE ref_recipes SET sku_prefix = 'EST' WHERE id = 33 AND sku_prefix IS NULL;  -- Estafette

COMMIT;
