-- 441_db_hygiene_tier_a.sql
-- PM-blessed schema hygiene: collation alignment, duplicate FK/index removal,
-- leftover probe/snapshot table cleanup. 2026-06-23 audit.
-- No COGS/COP impact. bd_* dup indexes (idx_sheet_row_index/uq_sri, 8 entries)
-- DEFERRED to the v1-bd-tables decommission arc.
-- Rollback:
--   Collation: ALTER TABLE inv_repack_events      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--              ALTER TABLE inv_side_stock_ledger   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--              ALTER TABLE ref_customer_identity   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
--   FK: ALTER TABLE ref_skus ADD CONSTRAINT fk_sku_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id);
--   Indexes: ALTER TABLE bd_tank_readings ADD KEY idx_tank_read_lotday (recipe_id_fk, neb_batch, read_date);
--            ALTER TABLE pl_plan_days ADD KEY idx_pdays_date (plan_date);
--   Tables: restore from /var/www/maltytask/data/snapshots/tier-a-dropped-tables-20260623.sql

SET lock_wait_timeout = 10;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Collation alignment — three tables are utf8mb4_0900_ai_ci; project
--    standard is utf8mb4_unicode_ci. Convert all three.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `inv_repack_events`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `inv_side_stock_ledger`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `ref_customer_identity`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Drop duplicate FK on ref_skus
--    Verified: two FKs on recipe_id → ref_recipes(id):
--      fk_skus_recipe  (keep — canonical name, pattern fk_<table>_<referenced>)
--      fk_sku_recipe   (drop — shorter non-canonical duplicate)
--    One backing index: idx_recipe (NON_UNIQUE) — it is shared between both FKs.
--    MySQL retains idx_recipe automatically when fk_sku_recipe is dropped
--    (fk_skus_recipe still references it), so NO index DROP is needed here.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `ref_skus`
  DROP FOREIGN KEY `fk_sku_recipe`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Drop duplicate index on bd_tank_readings
--    idx_tank_read_lotday (NON_UNIQUE, cols: recipe_id_fk, neb_batch, read_date)
--    is a strict subset of uq_tank_read_neb (UNIQUE, same three cols).
--    The UNIQUE index already serves all range-scan and equality lookups that
--    the non-unique duplicate was intended to support.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `bd_tank_readings`
  DROP INDEX `idx_tank_read_lotday`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Drop duplicate index on pl_plan_days
--    idx_pdays_date (NON_UNIQUE, col: plan_date) is redundant with
--    uniq_plan_date (UNIQUE, same col). UNIQUE already provides O(log n)
--    lookups by plan_date.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `pl_plan_days`
  DROP INDEX `idx_pdays_date`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Drop leftover probe / snapshot tables
--    Archived to /var/www/maltytask/data/snapshots/tier-a-dropped-tables-20260623.sql
--    before this migration was applied (765591 bytes / 748K).
--      _alt2_probe                                    → 0 rows (empty probe, not archived)
--      _snap_refskus_cuv_hlrevert_20260610            → 3 rows  (archived)
--      _snap_skubom_cuv_hlrevert_20260610             → 37 rows (archived)
--      bd_packaging_v2_contractfix_snapshot_20260609_153201 → 162 rows (archived)
--      bd_packaging_v2_lossfix_snapshot_20260609143950      → 790 rows (archived)
-- ─────────────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `_alt2_probe`;
DROP TABLE IF EXISTS `_snap_refskus_cuv_hlrevert_20260610`;
DROP TABLE IF EXISTS `_snap_skubom_cuv_hlrevert_20260610`;
DROP TABLE IF EXISTS `bd_packaging_v2_contractfix_snapshot_20260609_153201`;
DROP TABLE IF EXISTS `bd_packaging_v2_lossfix_snapshot_20260609143950`;
