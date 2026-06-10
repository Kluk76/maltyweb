-- Migration 323: add is_non_stock flag to ref_skus
-- Equipment / rental products (e.g. T7 tap machines) sold via Shopify eshop
-- that must NOT deplete finished-goods beer stock.  The fg_stock_compute() and
-- fg_stock_location_snapshot() eshop legs filter out rows where this flag is 1.
-- All rows default to 0 (no change in behaviour until a SKU is explicitly flagged).
-- No schema_meta change required — ref_skus already has a schema_meta row.

ALTER TABLE ref_skus
  ADD COLUMN is_non_stock TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Equipment/rental (e.g. T7 tap machines) — sold via Shopify but NOT FG beer; excluded from fg_stock depletion'
  AFTER is_direct_sales;
