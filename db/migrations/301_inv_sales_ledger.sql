-- Migration 301: inv_sales_ledger
--
-- Raw, append-only, dated QUANTITY source-of-record for BC sales exports.
-- Role in the sales-commercial surface:
--   - Ledger = dated QUANTITY SoR for B2B going-forward (shipment/invoice/credit/return)
--   - inv_sales_bc stays the CHF SoR (sales_amount_chf here is PARTIAL by design:
--     0 on shipment ILEs; inv_sales_bc carries the reconciled CHF amounts)
--   - Never feeds COGS
--   - The derived `sales_weekly_normalised` projection (later arc) reads from here
--
-- FK types verified against live schema (2026-06-09, read-only):
--   ref_customers.id = int unsigned  ✓
--   ref_skus.id      = int unsigned  ✓
--
-- Dedup key: CONCAT_WS('|', source_file, bc_line_seq) — STORED generated column.
-- Unresolved customer / SKU rows are surfaced (NULL FK) not dropped.

CREATE TABLE inv_sales_ledger (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  posting_date     DATE NOT NULL,
  -- Date comptabilisation from BC export

  doc_type         ENUM('shipment','invoice','credit','return_receipt') NOT NULL,
  doc_type_raw     VARCHAR(40) NULL,
  -- Raw BC label: Expédition vente / Facture vente / Avoir vente / Réception retour vente

  bc_document_no   VARCHAR(64) NULL,
  -- N° document from BC

  bc_line_seq      BIGINT NULL,
  -- N° séquence — BC's unique line identifier within the document

  bc_source_no     VARCHAR(32) NULL,
  -- N° origine = customer number as it appears in the BC export

  customer_id_fk   INT UNSIGNED NULL,
  -- Resolved ref_customers.id. NULL = unresolved or non-customer line; surface, never drop.

  sku_code_raw     VARCHAR(64) NOT NULL,
  -- N° article verbatim from the BC export line

  sku_id_fk        INT UNSIGNED NULL,
  -- Resolved ref_skus.id. NULL = unresolved or non-FG article; surface, never drop.

  qty_signed       DECIMAL(14,4) NOT NULL,
  -- Quantité (signed: outbound sales negative, returns/credits positive per BC convention)

  qty_invoiced     DECIMAL(14,4) NULL,
  -- Quantité facturée (separate BC column; may differ from qty_signed on shipment ILEs)

  sales_amount_chf DECIMAL(14,4) NULL,
  -- Montant vente (réel) — PARTIAL: 0 on shipment ILEs; CHF SoR remains inv_sales_bc

  hl_resolved      DECIMAL(14,6) NULL,
  -- Computed at ingest: qty_signed × ref_skus.hl_per_unit (NULL when sku_id_fk is NULL)

  source_file      VARCHAR(255) NULL,
  -- Filename / identifier of the BC CSV that produced this row

  imported_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  dedup_key        VARCHAR(190) GENERATED ALWAYS AS (
                     CONCAT_WS('|', source_file, bc_line_seq)
                   ) STORED,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_sales_ledger_dedup (dedup_key),
  KEY idx_sl_posting  (posting_date),
  KEY idx_sl_customer (customer_id_fk),
  KEY idx_sl_sku      (sku_id_fk),
  KEY idx_sl_doctype  (doc_type),

  CONSTRAINT fk_sl_customer FOREIGN KEY (customer_id_fk)
    REFERENCES ref_customers (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sl_sku FOREIGN KEY (sku_id_fk)
    REFERENCES ref_skus (id) ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification — raw source, corrections go back to BC system
INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES (
  'inv_sales_ledger',
  'source',
  'ingest-bc-sales-ledger.py',
  'blocked_with_redirect',
  'Raw BC csv ingest (shipment/invoice/credit/return). Fix in BC system + re-export CSV → re-ingest. Dedup on (source_file, bc_line_seq).',
  'Dated QUANTITY SoR for B2B going-forward. inv_sales_bc stays CHF SoR (sales_amount_chf partial by design). Never feeds COGS. sales_weekly_normalised projection reads from here.'
);
