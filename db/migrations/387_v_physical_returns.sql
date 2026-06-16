-- Migration 387: v_physical_returns
-- Creates a composite index on inv_sales_ledger for same-day reversal checks,
-- then defines v_physical_returns (physical return lines; same-day booking reversals
-- and rebate-tagged lines excluded). General-purpose view — no beer/date filter;
-- add those in consumers. Used by returns-synthese.php rate + overship blocks.
-- See: app/returns-synthese.php Changes A-D (mig 387).

-- 1. Composite index for the reversal NOT EXISTS subquery
CREATE INDEX ix_isl_revcheck ON inv_sales_ledger (customer_id_fk, sku_id_fk, posting_date, doc_type);

-- 2. View: physical returns only
CREATE OR REPLACE VIEW v_physical_returns AS
SELECT l.* FROM inv_sales_ledger l
WHERE l.doc_type IN ('credit','return_receipt')
  AND l.qty_signed > 0
  AND l.sku_id_fk IS NOT NULL
  -- exclude SAME-DAY booking reversals (a same-customer/same-SKU shipment|invoice posted the same day)
  AND NOT EXISTS (SELECT 1 FROM inv_sales_ledger s
                  WHERE s.customer_id_fk = l.customer_id_fk AND s.sku_id_fk = l.sku_id_fk
                    AND s.doc_type IN ('shipment','invoice') AND s.posting_date = l.posting_date)
  -- exclude rebate-tagged (non-physical price credits)
  AND NOT EXISTS (SELECT 1 FROM ord_returns r JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                  WHERE r.origin_bc_document_no = l.bc_document_no AND rl.sku_id_fk = l.sku_id_fk
                    AND rl.disposition = 'rebate');

-- 3. Register view in schema_meta
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes, updated_at)
VALUES ('v_physical_returns', 'derived', 'CREATE OR REPLACE VIEW (DDL only)', 'blocked',
        'Physical returns only — same-day booking reversals and rebate-tagged lines excluded. Redefine via a new migration to change exclusion logic.',
        'Created by migration 387. General-purpose view (no beer/date filter — add in consumers). Used by returns-synthese.php rate + overship blocks.',
        NOW());
