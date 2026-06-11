-- Migration 329: Add v_sales_ledger_weekly_client aggregate view
-- Purpose: Per-week × per-client B2B shipment summary for the Historique tab.
-- Grain: same scope as v_sales_ledger_orders (shipment docs, resolved-FG,
--        B2B scope, cutover < 2026-06-08). Aggregated to ISO week × customer.
-- No schema_meta row needed (read-only view, no writes).
-- 2026-06-11

CREATE OR REPLACE VIEW v_sales_ledger_weekly_client AS
SELECT
  YEARWEEK(l.posting_date, 3)                                          AS iso_yearweek,
  -- iso_year/iso_week/week_start are functionally dependent on iso_yearweek;
  -- ANY_VALUE() avoids ONLY_FULL_GROUP_BY rejection while preserving determinism.
  ANY_VALUE(YEAR(l.posting_date))                                       AS iso_year,
  ANY_VALUE(WEEK(l.posting_date, 3))                                    AS iso_week,
  ANY_VALUE(STR_TO_DATE(CONCAT(YEARWEEK(l.posting_date,3),' Monday'),'%X%V %W')) AS week_start,
  l.customer_id_fk,
  c.name                                                                AS customer_name,
  c.trade_channel,
  COUNT(DISTINCT l.bc_document_no)                                      AS doc_count,
  ROUND(-SUM(l.qty_signed))                                             AS total_units,
  ROUND(-SUM(l.hl_resolved),2)                                         AS total_hl
FROM inv_sales_ledger l
JOIN ref_customers c ON c.id = l.customer_id_fk
WHERE l.doc_type = 'shipment'
  AND l.sku_id_fk IS NOT NULL
  AND l.posting_date < '2026-06-08'
  AND c.sale_class NOT IN ('eshop','taproom','customs_artifact','transfer','sample')
GROUP BY iso_yearweek, l.customer_id_fk, c.name, c.trade_channel;
