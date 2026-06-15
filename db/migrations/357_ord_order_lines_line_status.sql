-- Migration 357: ord_order_lines.line_status — operational line-level status
-- Tracks ordered-but-undeliverable references (backorder / lost-sales).
-- These lines are NOT sent to BC and must NOT deplete physical FG stock.
--
-- ENUM values:
--   to_fulfil  — default; normal fulfillable line (stock is depleted when order ships)
--   non_livre  — other operational reason line cannot be delivered
--   rupture    — supplier/stock stockout
--
-- NOT NULL DEFAULT 'to_fulfil': INSTANT ADD (metadata-only, zero-copy).
-- No FK constraint needed — ENUM is self-validating.
-- schema_meta updated below.

ALTER TABLE ord_order_lines
  ADD COLUMN `line_status` ENUM('to_fulfil','non_livre','rupture')
      NOT NULL DEFAULT 'to_fulfil'
      COMMENT 'Operational fulfilment status. non_livre/rupture lines are excluded from FG stock depletion and demand simulation. Writer: /api/expeditions-line-status.php'
  AFTER `line_comment`;

-- schema_meta: update notes + writer_script to reflect new column
UPDATE schema_meta
   SET notes         = 'Order line items. qty in SKU units; HL derived at read time via ref_skus.hl_per_unit. line_status=non_livre/rupture lines excluded from FG depletion. Retire a SKU only after migrating all open order lines.',
       writer_script = 'public/modules/expeditions.php, public/api/expeditions-line-status.php'
 WHERE table_name = 'ord_order_lines';
