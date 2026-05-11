-- 029 — Document ingestion tables.
--
-- Six tables covering the full document pipeline:
--   doc_files          : one row per physical file (Drive or VPS storage)
--   doc_invoices       : one row per invoice, FK → doc_files
--   doc_invoice_lines  : one row per line item, FK → doc_invoices (CASCADE)
--   doc_delivery_notes : one row per delivery note, FK → doc_files
--   doc_dn_lines       : one row per DN line item, FK → doc_delivery_notes (CASCADE)
--   doc_ambiguous      : one row per file that the classifier could not decide
--
-- FK discipline:
--   doc_invoices.file_id        → doc_files.id        ON DELETE RESTRICT
--   doc_invoice_lines.invoice_id → doc_invoices.id    ON DELETE CASCADE
--   doc_invoice_lines.mi_id_fk  → ref_mi.id           ON DELETE RESTRICT (nullable)
--   doc_delivery_notes.file_id  → doc_files.id         ON DELETE RESTRICT
--   doc_dn_lines.dn_id          → doc_delivery_notes.id ON DELETE CASCADE
--   doc_dn_lines.mi_id_fk       → ref_mi.id            ON DELETE RESTRICT (nullable)
--   doc_ambiguous.file_id       → doc_files.id         ON DELETE RESTRICT
--   *.supplier_fk               → ref_suppliers.id     ON DELETE RESTRICT (nullable)
--
-- row_hash covers the immutable identity fields of each table (documented inline).
-- Classifier output is stored as JSON because it is an opaque blob (signal weights,
-- per-classifier scores) that we would not query column-by-column.
--
-- No seeding — ingest pipeline owns all data population.

CREATE TABLE IF NOT EXISTS doc_files (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id           VARCHAR(255)    NOT NULL,
  file_name         VARCHAR(512)    NOT NULL,
  local_path        VARCHAR(1024)   NULL,
  file_hash         CHAR(64)        NULL,
  mime_type         VARCHAR(128)    NULL,
  source_folder     VARCHAR(64)     NULL,
  file_size_bytes   BIGINT          NULL,
  uploaded_at       DATETIME        NULL,
  downloaded_at     DATETIME        NULL,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  row_hash          CHAR(64)        NOT NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_file_id          (file_id),
  UNIQUE KEY uniq_row_hash         (row_hash),
  KEY idx_doc_files_name           (file_name(128)),
  KEY idx_doc_files_source_folder  (source_folder),
  KEY idx_doc_files_active         (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_invoices (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id               BIGINT UNSIGNED NOT NULL,
  supplier_name         VARCHAR(512)    NULL,
  supplier_fk           INT UNSIGNED    NULL,
  invoice_ref           VARCHAR(128)    NULL,
  invoice_date          DATE            NULL,
  service_period_start  DATE            NULL,
  service_period_end    DATE            NULL,
  total_ht              DECIMAL(14,4)   NULL,
  total_ttc             DECIMAL(14,4)   NULL,
  total_vat             DECIMAL(14,4)   NULL,
  currency              VARCHAR(8)      NULL,
  ht_source             ENUM('extracted','derived_from_ttc_vat','derived_from_ttc_vat_rate','unknown') NULL,
  parser_name           VARCHAR(128)    NULL,
  ocr_text_length       INT             NULL,
  ocr_at                DATETIME        NULL,
  extracted_by          VARCHAR(128)    NULL,
  validated_at          DATETIME        NULL,
  ocr_text              MEDIUMTEXT      NULL,
  skipped_at            DATETIME        NULL,
  skipped_reason        VARCHAR(128)    NULL,
  is_active             TINYINT(1)      NOT NULL DEFAULT 1,
  row_hash              CHAR(64)        NOT NULL,
  created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash               (row_hash),
  KEY idx_doc_invoices_file_id           (file_id),
  KEY idx_doc_invoices_supplier_fk       (supplier_fk),
  KEY idx_doc_invoices_invoice_ref       (invoice_ref),
  KEY idx_doc_invoices_invoice_date      (invoice_date),
  KEY idx_doc_invoices_parser            (parser_name),
  KEY idx_doc_invoices_active            (is_active),
  CONSTRAINT fk_doc_invoices_file        FOREIGN KEY (file_id)      REFERENCES doc_files(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_doc_invoices_supplier    FOREIGN KEY (supplier_fk)  REFERENCES ref_suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_invoice_lines (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id          BIGINT UNSIGNED NOT NULL,
  line_index          SMALLINT        NOT NULL,
  ingredient_name     VARCHAR(512)    NULL,
  description         VARCHAR(512)    NULL,
  mi_id_fk            INT UNSIGNED    NULL,
  qty                 DECIMAL(14,4)   NULL,
  unit                VARCHAR(32)     NULL,
  unit_price          DECIMAL(14,4)   NULL,
  line_total          DECIMAL(14,2)   NULL,
  vat_rate            DECIMAL(5,2)    NULL,
  name_confidence     DECIMAL(4,3)    NULL,
  price_confidence    DECIMAL(4,3)    NULL,
  pack_converted      TINYINT(1)      NULL DEFAULT 0,
  gate_failures       JSON            NULL,
  line_metadata       JSON            NULL,
  row_hash            CHAR(64)        NOT NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash                  (row_hash),
  KEY idx_doc_invoice_lines_invoice_id      (invoice_id),
  KEY idx_doc_invoice_lines_mi_id_fk        (mi_id_fk),
  CONSTRAINT fk_doc_invoice_lines_invoice   FOREIGN KEY (invoice_id) REFERENCES doc_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_invoice_lines_mi        FOREIGN KEY (mi_id_fk)   REFERENCES ref_mi(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_delivery_notes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id           BIGINT UNSIGNED NOT NULL,
  supplier_name     VARCHAR(512)    NULL,
  supplier_fk       INT UNSIGNED    NULL,
  date_received     DATE            NULL,
  delivery_note_ref VARCHAR(128)    NULL,
  currency          VARCHAR(8)      NULL,
  ocr_at            DATETIME        NULL,
  extracted_by      VARCHAR(128)    NULL,
  ocr_text          MEDIUMTEXT      NULL,
  is_active         TINYINT(1)      NOT NULL DEFAULT 1,
  row_hash          CHAR(64)        NOT NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash                   (row_hash),
  KEY idx_doc_delivery_notes_file_id         (file_id),
  KEY idx_doc_delivery_notes_supplier_fk     (supplier_fk),
  KEY idx_doc_delivery_notes_date_received   (date_received),
  KEY idx_doc_delivery_notes_dn_ref          (delivery_note_ref),
  KEY idx_doc_delivery_notes_active          (is_active),
  CONSTRAINT fk_doc_delivery_notes_file      FOREIGN KEY (file_id)     REFERENCES doc_files(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_doc_delivery_notes_supplier  FOREIGN KEY (supplier_fk) REFERENCES ref_suppliers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_dn_lines (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dn_id                 BIGINT UNSIGNED NOT NULL,
  line_index            SMALLINT        NOT NULL,
  ingredient_name       VARCHAR(512)    NULL,
  mi_id_fk              INT UNSIGNED    NULL,
  qty                   DECIMAL(14,4)   NULL,
  unit                  VARCHAR(32)     NULL,
  lot_number            VARCHAR(128)    NULL,
  resolved_confidence   DECIMAL(4,3)    NULL,
  row_hash              CHAR(64)        NOT NULL,
  created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash             (row_hash),
  KEY idx_doc_dn_lines_dn_id           (dn_id),
  KEY idx_doc_dn_lines_mi_id_fk        (mi_id_fk),
  CONSTRAINT fk_doc_dn_lines_dn        FOREIGN KEY (dn_id)    REFERENCES doc_delivery_notes(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_dn_lines_mi        FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id)             ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_ambiguous (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_id           BIGINT UNSIGNED NOT NULL,
  classified_at     DATETIME        NULL,
  confidence        DECIMAL(4,3)    NULL,
  invoice_signals   JSON            NULL,
  dn_signals        JSON            NULL,
  ocr_text_hash     CHAR(64)        NULL,
  ocr_text_length   INT             NULL,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_file_id          (file_id),
  KEY idx_doc_ambiguous_classified (classified_at),
  CONSTRAINT fk_doc_ambiguous_file FOREIGN KEY (file_id) REFERENCES doc_files(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Down-migration order (reverse dependency):
--   DROP TABLE IF EXISTS doc_ambiguous;
--   DROP TABLE IF EXISTS doc_dn_lines;
--   DROP TABLE IF EXISTS doc_delivery_notes;
--   DROP TABLE IF EXISTS doc_invoice_lines;
--   DROP TABLE IF EXISTS doc_invoices;
--   DROP TABLE IF EXISTS doc_files;
