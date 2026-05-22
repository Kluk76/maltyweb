-- 084_inv_consumption_sales_derived.sql
-- Add 'sales_derived' value to inv_consumption.source_event enum.
-- Used for eshop packaging consumption derived from inv_sales_orders
-- (Shopify orders → PKG_CARTON12_ESHOP, PKG_RENFORTS_ESHOP, PKG_BOX_24_BTL_BLANC,
-- PKG_RENFORTS_PD_SGL/DBL, PKG_BOX_12_CAN_BLANC, PKG_BOX_24_CAN_BLANC).
--
-- Provenance: source_row_id will reference inv_sales_orders.id when source_event='sales_derived'.

ALTER TABLE inv_consumption
  MODIFY COLUMN source_event
    ENUM('brewing','fermenting','racking','packaging','manual','sales_derived') NOT NULL;
