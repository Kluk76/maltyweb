-- db/migrations/210_add_ref_skus_sku_type_flags.sql
--
-- What: Add is_packaging_line + is_direct_sales TINYINT(1) flags to ref_skus.
--       Defaults both to 0. Mig 211 backfills from live data.
--
-- Why : F2 of vessel-commissioning audit (2026-05-28). The audit surfaced 7
--       "idle commissioned formats" with active SKUs (B12, 4PB, BU, X, 12C,
--       4PC, CU). PM verdict 2026-05-29: these are not idle — they serve
--       direct-sales SKUs (eshop unboxing into singles / 4-packs, B2B pallet)
--       that don't come off the packaging line. ref_skus has no discriminator
--       today.
--
--       Two orthogonal flags (not ENUM) because some SKUs are BOTH (F, 4, B,
--       C ship from the line AND sell direct). ENUM('production','direct_sales',
--       'both') collapses two real axes into one column — refuse.
--
--       Compositeness is NOT a flag — it lives in ref_sku_composite_slots
--       (canonical per sku-decomposition-tree.md). Draft-pour is NOT a flag —
--       lives in ref_packaging_formats.run_type='-'. Adding either flag here
--       would be a parallel-store smell.
--
-- Defaults = 0 / 0 (not 1 / 0 — "guess from a pattern" is forbidden; backfill
--   from evidence in mig 211).
--
-- Risk: VERY LOW. Pure ADD COLUMN. No data transform. No FK cascades.
--   Reversible via DROP COLUMN.
--
-- Rollback:
--   ALTER TABLE ref_skus DROP COLUMN is_direct_sales, DROP COLUMN is_packaging_line;
--
-- Date   : 2026-05-29
-- Author : web

ALTER TABLE ref_skus
  ADD COLUMN is_packaging_line TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Comes off the packaging line as a bd_packaging_v2 event (production)'
    AFTER is_active,
  ADD COLUMN is_direct_sales TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Sold direct via Shopify (inv_sales_order_lines) or BC (inv_sales_bc) — eshop / B2B / taproom'
    AFTER is_packaging_line;
