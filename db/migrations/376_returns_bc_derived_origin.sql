-- db/migrations/376_returns_bc_derived_origin.sql
-- What: Pivot ord_returns from order-keyed model to BC-derived-overlay on inv_sales_ledger credits.
--       Drop the XOR CHECK (a BC-derived return need not map to any order).
--       Add origin_bc_document_no + origin_posting_date as canonical origin link.
--       Keep origin_order_id_fk / origin_sales_order_id_fk as optional enrichment (nullable, FKs stay).
--       Extend ord_return_lines.disposition ENUM to add 'rebate' (price/defect credit that is not a physical return).
--       Add origin_ledger_sku_code VARCHAR(64) for audit/reconciliation against the originating ledger line.
--       Update schema_meta notes to reflect BC-derived-overlay role.
-- Why:  Returns are now sourced from BC avoir-de-vente credit notes imported into inv_sales_ledger
--       (doc_type='credit'/'return_receipt'). The old "exactly one order FK" model is wrong for BC-derived rows.
-- Risk: Tables are empty (mig 373 created them; no live data). All ALTERs are safe.
-- Rollback:
--   ALTER TABLE ord_returns
--     DROP INDEX idx_ord_returns_bc_doc,
--     DROP COLUMN origin_bc_document_no,
--     DROP COLUMN origin_posting_date,
--     ADD CONSTRAINT chk_ord_returns_exactly_one_origin CHECK (
--       (origin_order_id_fk IS NOT NULL) <> (origin_sales_order_id_fk IS NOT NULL)
--     );
--   ALTER TABLE ord_return_lines
--     MODIFY COLUMN disposition ENUM('restock','scrap','quarantine') NOT NULL,
--     DROP COLUMN origin_ledger_sku_code;

-- ── ord_returns ──────────────────────────────────────────────────────────────

-- 1. Drop the XOR check (order-keyed assumption; BC-derived returns have no required order FK)
ALTER TABLE ord_returns
  DROP CHECK chk_ord_returns_exactly_one_origin;

-- 2. Add BC origin columns (placed after the two existing origin FKs) + index
ALTER TABLE ord_returns
  ADD COLUMN origin_bc_document_no VARCHAR(64) NULL
    COMMENT 'BC credit-note / avoir-de-vente number; canonical origin link when return is BC-derived. Matches inv_sales_ledger.bc_document_no (VARCHAR 64, utf8mb4_unicode_ci).'
    AFTER origin_sales_order_id_fk,
  ADD COLUMN origin_posting_date DATE NULL
    COMMENT 'Ledger posting_date of the BC credit note; used as anchor date for FG restock leg. Matches inv_sales_ledger.posting_date (DATE).'
    AFTER origin_bc_document_no,
  ADD INDEX idx_ord_returns_bc_doc (origin_bc_document_no);

-- ── ord_return_lines ─────────────────────────────────────────────────────────

-- 3. Extend disposition ENUM: append 'rebate' (price/defect credit — not a physical return of goods)
--    Keep existing three values in original order. No ALGORITHM hint (let MySQL pick; COPY on small tables).
ALTER TABLE ord_return_lines
  MODIFY COLUMN disposition ENUM('restock','scrap','quarantine','rebate') NOT NULL
    COMMENT 'Fate of returned stock: restock=back to FG, scrap=written off, quarantine=held for QA review, rebate=price/defect credit (no physical return)';

-- 4. Add raw SKU code column for audit reconciliation against the originating ledger line
ALTER TABLE ord_return_lines
  ADD COLUMN origin_ledger_sku_code VARCHAR(64) NULL
    COMMENT 'Raw sku_code_raw from the originating inv_sales_ledger line; aids reconciliation (bc_document_no + sku key). Matches inv_sales_ledger.sku_code_raw (VARCHAR 64, utf8mb4_unicode_ci).'
    AFTER line_comment;

-- ── schema_meta ──────────────────────────────────────────────────────────────

-- 5. Update schema_meta notes to reflect BC-derived-overlay role (rows already exist from mig 373)
UPDATE schema_meta
   SET upstream_hint = 'Return header (BC-derived overlay on inv_sales_ledger credits). Origin = inv_sales_ledger credit note (bc_document_no); disposition facts are maltytask-owned. Order FKs optional enrichment. Fix origin via inv_sales_ledger or ord_orders/inv_sales_orders.',
       notes         = 'Returned-FG tracking header. BC-derived: origin_bc_document_no links to inv_sales_ledger (doc_type=credit/return_receipt). origin_order_id_fk / origin_sales_order_id_fk optional enrichment when resolvable. XOR CHECK removed (mig 376).',
       updated_at    = NOW()
 WHERE table_name = 'ord_returns';

UPDATE schema_meta
   SET upstream_hint = 'Return line items. qty > 0 enforced. Disposition drives FG stock adjustments: restock=back to inventory, scrap=write-off, quarantine=QA hold, rebate=price/defect credit (no physical return). origin_ledger_sku_code links to originating inv_sales_ledger line.',
       notes         = 'One row per SKU per return. disposition ENUM: restock/scrap/quarantine/rebate. origin_ledger_sku_code stores raw sku_code_raw from inv_sales_ledger for reconciliation. ON DELETE CASCADE from ord_returns. Updated by mig 376.',
       updated_at    = NOW()
 WHERE table_name = 'ord_return_lines';
