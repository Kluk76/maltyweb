-- 406_inv_sales_order_lines_stable_dedup.sql
-- Fix inv_sales_order_lines dedup: the positional UNIQUE key uk_lines_order_line
-- (order_id_fk, line_index) is wrong — when a Shopify line's content changes
-- (qty edit, partial refund), INSERT IGNORE no-ops on the positional key and
-- silently keeps stale content.  Replace with a content-stable UNIQUE on
-- (order_id_fk, external_line_id), which is Shopify's durable identity for a
-- line across edits.
--
-- Also drops uk_lines_row_hash: with two UNIQUE keys present, MySQL's
-- ON DUPLICATE KEY UPDATE can fire on EITHER key, causing ambiguous affectedRows
-- semantics.  row_hash is demoted to a change-detection column; the single
-- remaining UNIQUE uk_lines_order_ext is the sole dedup gate.
--
-- line_index and row_hash columns are KEPT:
--   line_index  → still feeds ORDER BY in repack.php:183 (display sort only)
--   row_hash    → still populated for change detection / audit diffing
--
-- external_line_id is confirmed 100 % populated (0 NULL, 0 empty) across all
-- existing rows — safe to add a UNIQUE constraint.
--
-- NON-FISCAL structural index change — does not touch any data or COGS/stock/WAC.
-- ALGORITHM=INPLACE, LOCK=NONE: online DDL for secondary-index changes on InnoDB.

ALTER TABLE inv_sales_order_lines
  DROP KEY     uk_lines_order_line,
  DROP KEY     uk_lines_row_hash,
  ADD  UNIQUE KEY uk_lines_order_ext (order_id_fk, external_line_id),
  ALGORITHM=INPLACE, LOCK=NONE;
