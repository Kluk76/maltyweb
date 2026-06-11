-- Migration 326: Historical Commandes surface over inv_sales_ledger (BC canonical, 2021→cutover).
-- Grain = shipment documents (deliveries). Resolved-FG, B2B-scope, pre-cutover 2026-06-08.
-- READ-ONLY lens; never a materialised store. inv_sales_ledger stays the one fact.
--
-- Views:
--   v_sales_ledger_orders        — one row per shipment document (header)
--   v_sales_ledger_order_lines   — one row per (shipment doc × SKU)
--   v_sales_ledger_unresolved    — audit tail: unresolved sku_code_raw by year
--
-- UI wiring contract: see docs/historical-commandes-read-contract.md
-- Cutover constant: 2026-06-08 (forward lane uses ord_orders.requested_date >= cutover)


CREATE OR REPLACE VIEW v_sales_ledger_orders AS
SELECT
  CONCAT('BC:', l.bc_document_no)        AS synthetic_order_id,
  l.bc_document_no,
  l.posting_date,
  l.customer_id_fk,
  c.name                                 AS customer_name,
  c.trade_channel,
  COUNT(*)                               AS line_count,
  ROUND(-SUM(l.qty_signed))              AS total_units,
  ROUND(-SUM(l.hl_resolved), 2)          AS total_hl
FROM inv_sales_ledger l
JOIN ref_customers c ON c.id = l.customer_id_fk
WHERE l.doc_type = 'shipment'
  AND l.sku_id_fk IS NOT NULL
  AND l.posting_date < '2026-06-08'
  AND c.sale_class NOT IN ('eshop','taproom','customs_artifact','transfer','sample')
GROUP BY l.bc_document_no, l.posting_date, l.customer_id_fk, c.name, c.trade_channel;


CREATE OR REPLACE VIEW v_sales_ledger_order_lines AS
SELECT
  CONCAT('BC:', l.bc_document_no)        AS synthetic_order_id,
  l.bc_document_no,
  l.posting_date,
  l.sku_id_fk,
  s.sku_code,
  ROUND(-SUM(l.qty_signed))              AS qty,
  ROUND(-SUM(l.hl_resolved), 2)          AS hl
FROM inv_sales_ledger l
JOIN ref_skus s       ON s.id = l.sku_id_fk
JOIN ref_customers c  ON c.id = l.customer_id_fk
WHERE l.doc_type = 'shipment'
  AND l.sku_id_fk IS NOT NULL
  AND l.posting_date < '2026-06-08'
  AND c.sale_class NOT IN ('eshop','taproom','customs_artifact','transfer','sample')
GROUP BY l.bc_document_no, l.posting_date, l.sku_id_fk, s.sku_code;


CREATE OR REPLACE VIEW v_sales_ledger_unresolved AS
SELECT
  l.sku_code_raw,
  YEAR(l.posting_date)                   AS yr,
  COUNT(*)                               AS line_count,
  ROUND(-SUM(l.qty_signed))              AS units,
  MIN(l.posting_date)                    AS first_seen,
  MAX(l.posting_date)                    AS last_seen
FROM inv_sales_ledger l
WHERE l.doc_type = 'shipment'
  AND l.sku_id_fk IS NULL
  AND l.posting_date < '2026-06-08'
GROUP BY l.sku_code_raw, YEAR(l.posting_date);
