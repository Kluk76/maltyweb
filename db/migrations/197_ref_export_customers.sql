-- db/migrations/197_ref_export_customers.sql
--
-- What: New reference table ref_export_customers — canonicalises the list of
--       non-Swiss customer IDs excluded from the beer-tax base (BSF tab
--       ExportCustomers).
--
-- Why:  Export customers are used by beer-tax routing in build-cogs-report.js
--       to correctly exclude non-Swiss sales. Currently lives only in BSF.
--       Phase 1 BSF-exit foundation.
--
-- Source: BSF ExportCustomers A2:D. Live-inspected 2026-05-28: 2 data rows.
--   Columns: CustomerID (BC customer number), CustomerName, Country (ISO-2),
--            Since (year/date — sparse, often blank).
--
-- Design:
--   - customer_id UNIQUE — the natural dedup key (BC customer number)
--   - country_code CHAR(2) — ISO 3166-1 alpha-2 (FR, DE, BE, etc.)
--   - since VARCHAR(16) — stored as text since BSF has mixed formats
--   - note TEXT — operator free text
--
-- Rollback:
--   DROP TABLE IF EXISTS ref_export_customers;
--   DELETE FROM schema_meta WHERE table_name = 'ref_export_customers';
--
-- NOTE: No bare SELECT statements.

-- ============================================================================
-- TABLE: ref_export_customers
-- ============================================================================

CREATE TABLE IF NOT EXISTS ref_export_customers (
  id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,

  -- ── IDENTITY ───────────────────────────────────────────────────────────
  customer_id     VARCHAR(32)         NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'Business Central customer number (BSF col A CustomerID)',

  -- ── METADATA ───────────────────────────────────────────────────────────
  customer_name   VARCHAR(128)        NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col B CustomerName',
  country_code    CHAR(2)             NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'ISO 3166-1 alpha-2 country code (BSF col C Country)',
  since           VARCHAR(16)         NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col D Since — year or date first classified as export',
  note            TEXT                NULL
                    COLLATE utf8mb4_unicode_ci,

  -- ── AUDIT ──────────────────────────────────────────────────────────────
  row_hash        CHAR(64)            NOT NULL
                    COLLATE utf8mb4_unicode_ci,
  last_modified_by ENUM('ingest','web')
                    NOT NULL DEFAULT 'ingest',
  created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

  -- ── CONSTRAINTS ────────────────────────────────────────────────────────
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_export_customers_customer_id (customer_id),
  UNIQUE KEY uq_ref_export_customers_row_hash    (row_hash),

  CONSTRAINT chk_ref_export_customers_country_code
    CHECK (country_code IS NULL OR CHAR_LENGTH(country_code) = 2)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Non-Swiss customers excluded from beer-tax base. BSF ExportCustomers tab exit target. Phase 1 BSF-exit foundation migration 197.';

-- ============================================================================
-- schema_meta row for ref_export_customers
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'ref_export_customers',
  'reference',
  'maltyweb admin UI (Le Cockpit)',
  'allowed',
  'Phase 1 BSF-exit foundation. Non-Swiss customer IDs (BC customer numbers) that must be excluded from the beer-tax calculation base. Tiny table (2 rows at seed time). Seeded from BSF ExportCustomers tab via scripts/_phase-bsf-exit/seed-ref-export-customers.ts. Read by build-cogs-report.js beer-tax routing.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  writer_script      = VALUES(writer_script),
  corrections_policy = VALUES(corrections_policy),
  notes              = VALUES(notes);

-- end migration 197
