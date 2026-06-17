-- 394_sales_orders_fulfilment_date.sql
-- Capture the real future fulfilment date for Shopify "izyrent" portable-tap
-- rental orders. The date lives in a Shopify line-item property named "Date"
-- (a French date RANGE, e.g. "2 Juillet 2026 au 6 Juillet 2026"). It is parsed
-- by scripts/ingest-shopify-orders.ts into start/end. Regular pickups/deliveries
-- have no such property and keep these columns NULL.
--
-- created_at / period are untouched (fiscal period stays the placement month).
-- The Expéditions "boutique en ligne" board groups + sorts on
-- COALESCE(fulfilment_date, DATE(created_at)) so dated rentals surface on their
-- real prep-week while everything else falls through to placement day.
--
-- No new table → no schema_meta row.

ALTER TABLE inv_sales_orders
  ADD COLUMN fulfilment_date     DATE NULL,
  ADD COLUMN fulfilment_date_end DATE NULL;
