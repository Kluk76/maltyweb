-- 395_sales_orders_fulfilment_mode_source.sql
-- Provenance flag for inv_sales_orders.fulfilment_mode so a human (or auto)
-- classification of an "à classer" (review) eshop order is never clobbered by
-- the Shopify ingest's re-sync.
--
--   'auto'   — derived by scripts/ingest-shopify-orders.ts (shipping-method map,
--              or the izyrent→pickup rule). Deterministically re-derivable, so the
--              ingest is free to recompute/overwrite fulfilment_mode on every run.
--   'manual' — set by the operator via /api/eshop-fulfilment-status.php (action
--              'classify') on a review-mode card. The ingest's existing-row UPDATE
--              leg EXCLUDES fulfilment_mode from change-detection + the SET when the
--              source is 'manual', so the operator's decision is preserved.
--
-- The ingest NEVER writes fulfilment_mode_source (it always inserts the column's
-- DEFAULT 'auto'); only the classify action sets 'manual'. The izyrent backfill
-- leaves source = 'auto' (izyrent is deterministically re-derivable).
--
-- No new table → no schema_meta row.

ALTER TABLE inv_sales_orders
  ADD COLUMN fulfilment_mode_source ENUM('auto','manual') NOT NULL DEFAULT 'auto';
