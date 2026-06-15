-- 348_inv_sales_invoice_lines.sql
-- BC Standard API v2.0 sales invoice lines (discounts).
-- NK: bc_line_id (line GUID from salesInvoiceLines / salesCreditMemoLines).
-- Credit memo discount components stored NEGATIVE so SUM nets correctly.

CREATE TABLE IF NOT EXISTS inv_sales_invoice_lines (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bc_line_id               VARCHAR(64)     NOT NULL  COMMENT 'BC salesInvoiceLine/salesCreditMemoLine systemId GUID — idempotent upsert NK',
    document_type            ENUM('invoice','credit') NOT NULL,
    document_no              VARCHAR(40)     NOT NULL,
    posting_date             DATE            NOT NULL,
    customer_no              VARCHAR(40)     NULL,
    sku_code                 VARCHAR(64)     NULL      COMMENT 'lineObjectNumber when lineType=Item, NULL otherwise',
    line_type                VARCHAR(24)     NULL,
    quantity                 DECIMAL(14,4)   NULL,
    line_amount_excl_tax_chf DECIMAL(14,4)   NULL      COMMENT 'netAmount — reconcile guard, not rate denominator',
    discount_amount_chf      DECIMAL(14,4)   NOT NULL  DEFAULT 0  COMMENT 'discountAmount; credit-memo rows NEGATIVE',
    invoice_disc_alloc_chf   DECIMAL(14,4)   NOT NULL  DEFAULT 0  COMMENT 'invoiceDiscountAllocation; credit-memo rows NEGATIVE',
    source                   VARCHAR(48)     NOT NULL  COMMENT 'bc-api:salesInvoiceLines or bc-api:salesCreditMemoLines',
    ingested_at              TIMESTAMP       NOT NULL  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bc_line_id (bc_line_id),
    KEY idx_posting_date (posting_date),
    KEY idx_sku_code (sku_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
    'inv_sales_invoice_lines',
    'source',
    'blocked_with_redirect',
    'ingest_bc_sales_invoice_lines.py',
    'BC Standard API v2.0 salesInvoices/salesCreditMemos $expand=Lines. Fix in BC + re-pull. Upsert on bc_line_id (line GUID).'
)
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
