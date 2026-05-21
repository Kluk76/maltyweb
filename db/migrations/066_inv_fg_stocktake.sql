-- db/migrations/066_inv_fg_stocktake.sql
-- What: Extend inv_fg_stocktake (created in 031) to support warehouse FG tab.
--       Adds sku_id_fk (JOIN to ref_skus), source ENUM (tracks origin of row),
--       and counted_at DATE. The existing form-sync rows get source='maltyweb-form'.
-- Why: Warehouse FG sub-tab needs sku_id_fk for BOM/cost joins and source for
--      dedup logic when the backfill script inserts bsf-stocktake rows.
-- Risk: ALTER TABLE on an empty table — zero data risk.
-- Note: Applied manually via php -r on 2026-05-21 because migrate.php skipped
--       this file (031 already created the table via IF NOT EXISTS).
-- Rollback:
--   ALTER TABLE inv_fg_stocktake
--     DROP FOREIGN KEY fk_fg_sku,
--     DROP COLUMN sku_id_fk,
--     DROP COLUMN source,
--     DROP COLUMN counted_at;

-- These statements are idempotent when re-run because of the IF EXISTS / IF NOT EXISTS guards.
ALTER TABLE inv_fg_stocktake
  ADD COLUMN IF NOT EXISTS sku_id_fk  INT UNSIGNED NULL AFTER sku,
  ADD COLUMN IF NOT EXISTS source      ENUM('bsf-stocktake','maltyweb-form','manual-adjustment') NOT NULL DEFAULT 'maltyweb-form' AFTER sku_id_fk,
  ADD COLUMN IF NOT EXISTS counted_at  DATE NULL AFTER source;

-- FK: only add if not already present (manual guard needed for older MySQL)
-- Applied manually 2026-05-21: ALTER TABLE inv_fg_stocktake ADD CONSTRAINT fk_fg_sku FOREIGN KEY (sku_id_fk) REFERENCES ref_skus(id) ON DELETE SET NULL;
