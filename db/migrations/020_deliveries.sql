-- 020 — Deliveries transactional table.
--
-- One table: inv_deliveries.
--   One row per BSF Deliveries tab line (every supplier purchase line).
--   Natural key = sheet_row_index (BSF row number, 1-based, header = row 1).
--
-- FK dependencies:
--   supplier_fk  → ref_suppliers(id)   (from 019)
--   ingredient_fk → ref_mi(id)         (from 018)
--
-- Upsert policy (SCD Type 1):
--   Rows upserted on each ingest run via ON DUPLICATE KEY UPDATE on sheet_row_index.
--   imported_at is set once (INSERT only); last_seen_at refreshed every run.
--   Rows that vanish from BSF are soft-deleted: status set to 'Removed'.
--
-- row_hash covers immutable identity fields only (date_received, supplier_raw,
--   ingredient_raw, lot_number, qty_delivered, unit_price, currency, invoice_ref,
--   source, submitted_at, details) — mutations to qty_remaining or status do NOT
--   produce a hash change, so they do not trigger a "row changed" signal.
--
-- No seeding here — ingest_deliveries.py owns all data population.

CREATE TABLE IF NOT EXISTS inv_deliveries (
  id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sheet_row_index   INT UNSIGNED     NOT NULL,                 -- natural key (BSF row number, e.g. 2 = first data row)
  row_hash          CHAR(64)         NOT NULL,                 -- SHA-256 over immutable fields only (11 fields)

  -- raw / source columns (A–V, col U skipped — deprecated)
  delivery_id_raw   VARCHAR(64)      NULL,                     -- col A: formula DeliveryID e.g. "DEL00123"
  date_received     DATE             NULL,                     -- col B: DateReceived
  supplier_raw      VARCHAR(255)     NULL,                     -- col C: raw operator-input supplier name
  ingredient_raw    VARCHAR(255)     NULL,                     -- col D: raw operator-input ingredient name
  mi_id_raw         VARCHAR(64)      NULL,                     -- col E: formula VLOOKUP MI_ID (may be blank if not found)
  category_raw      VARCHAR(64)      NULL,                     -- col F: formula VLOOKUP category from MI
  lot_number        VARCHAR(64)      NULL,                     -- col G: lot number / batch ID from supplier
  qty_delivered     DECIMAL(14,4)    NULL,                     -- col H: QtyDelivered in pricing units
  pricing_unit      VARCHAR(16)      NULL,                     -- col I: formula VLOOKUP from MI
  unit_price        DECIMAL(14,6)    NULL,                     -- col J: per pricing unit
  currency          VARCHAR(8)       NULL,                     -- col K: CHF | EUR | …
  eur_to_chf        DECIMAL(8,5)     NULL,                     -- col L: conversion rate when currency=EUR
  total_original    DECIMAL(14,4)    NULL,                     -- col M: formula qty × unit_price
  total_chf         DECIMAL(14,4)    NULL,                     -- col N: formula total_orig × eur_to_chf
  qty_remaining     DECIMAL(14,4)    NULL,                     -- col O: remaining qty (mutates via depletion)
  status            VARCHAR(32)      NULL,                     -- col P: Active | Pending | Consumed | Skipped | Removed
  invoice_ref       VARCHAR(64)      NULL,                     -- col Q: invoice reference number
  notes_raw         TEXT             NULL,                     -- col R: formula price-validation status text
  source            VARCHAR(64)      NULL,                     -- col S: Manual | Invoice-OCR | DN-OCR | PhotoNote | …
  submitted_at      DATETIME(6)      NULL,                     -- col T: form timestamp
                                                               -- col U: DEPRECATED — skipped
  details           TEXT             NULL,                     -- col V: editable notes (de facto Notes column)

  -- resolved FKs
  supplier_fk       INT UNSIGNED     NULL,                     -- ref_suppliers.id (best match)
  ingredient_fk     INT UNSIGNED     NULL,                     -- ref_mi.id (best match)
  resolution        VARCHAR(32)      NOT NULL DEFAULT 'unresolved',
                                     -- 'mi_id_match' | 'name_exact' | 'alias' | 'ambiguous_multi_gl' | 'unresolved'

  -- audit
  imported_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_sheet_row_index   (sheet_row_index),
  KEY idx_date_received             (date_received),
  KEY idx_status                    (status),
  KEY idx_supplier_fk               (supplier_fk),
  KEY idx_ingredient_fk             (ingredient_fk),
  KEY idx_invoice_ref               (invoice_ref),
  KEY idx_resolution                (resolution),
  CONSTRAINT fk_inv_supplier        FOREIGN KEY (supplier_fk)   REFERENCES ref_suppliers(id),
  CONSTRAINT fk_inv_ingredient      FOREIGN KEY (ingredient_fk) REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
