-- 019 — Suppliers reference catalog.
--
-- Three tables:
--   ref_suppliers          : one row per (supplier_id, GL) pair — natural key = supplier_id
--   ref_supplier_aliases   : alternate names for a supplier (empty shell; populated later)
--   ref_supplier_summary   : denormalized projection — one row per unique display name,
--                            aggregating multi-GL siblings
--
-- Upsert policy (SCD Type 1):
--   ref_suppliers rows are upserted on each ingest run via ON DUPLICATE KEY UPDATE.
--   last_seen_at is touched on every successful pass; imported_at is set once (INSERT only).
--   Suppliers that vanish from BSF are flagged is_active=0 (not deleted) to preserve
--   any FK references from inv_deliveries and similar tables built in later chunks.
--
-- Multi-GL suppliers: one row per (supplier, GL) pair. e.g. Brau- und Rauchshop spans
--   7 GLs — each row has the same NAME but a distinct supplier_id and gl_account.
--   They are treated as independent rows throughout.
--
-- ref_supplier_summary is a derived projection recomputed at the end of each ingest run
--   (TRUNCATE + INSERT). It is safe to truncate because it carries no raw data.
--
-- No seeding here — ingest_suppliers.py owns all data population.

CREATE TABLE IF NOT EXISTS ref_suppliers (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  supplier_id   VARCHAR(64)   NOT NULL,
  name          VARCHAR(255)  NOT NULL,
  gl_account    VARCHAR(8)    NULL,
  category      VARCHAR(64)   NULL,
  currency      VARCHAR(8)    NULL,
  is_active     TINYINT       NOT NULL DEFAULT 1,
  notes         TEXT          NULL,
  row_hash      CHAR(64)      NOT NULL,
  last_seen_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_supplier_id (supplier_id),
  KEY idx_name       (name(64)),
  KEY idx_gl_account (gl_account),
  KEY idx_active     (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_supplier_aliases (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  supplier_id_fk  INT UNSIGNED  NOT NULL,
  alias           VARCHAR(255)  NOT NULL,
  source          ENUM('manual','observed') NOT NULL DEFAULT 'manual',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alias     (alias),
  KEY idx_supplier_id_fk    (supplier_id_fk),
  CONSTRAINT fk_sup_alias_supplier FOREIGN KEY (supplier_id_fk)
    REFERENCES ref_suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_supplier_summary (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name         VARCHAR(255)  NOT NULL,
  gl_count     INT           NOT NULL,
  modal_gl     VARCHAR(8)    NULL,
  is_active    TINYINT       NOT NULL DEFAULT 1,
  currency     VARCHAR(8)    NULL,
  last_seen_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
