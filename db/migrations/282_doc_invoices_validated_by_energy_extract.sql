-- Migration 282 — doc_invoices: add validated_by FK + energy_extract JSON staging column
--
-- validated_by INT UNSIGNED NULL FK→users.id
--   Records which operator validated/approved this invoice (Phase B validation gate).
--   Nullable — set only once operator confirms via the validate endpoint.
--   FK type matches users.id (INT UNSIGNED).
--
-- energy_extract JSON NULL
--   Staged energy values for SIE/SIL electricity invoices.
--   Written by ingest-one-local.ts worker (energy-extraction.js) after parser dispatch.
--   Applied to inv_energydata by the operator validate endpoint (Phase B).
--   Shape: { period: 'YYYY-MM', peakKW: number|null, reactive_kVArh: number|null,
--            hp_kWh: number|null, hc_kWh: number|null, invoiceRef: string|null,
--            stagedAt: ISO-timestamp }
--
-- Pre-flight: users.id = INT UNSIGNED — FK type verified before writing this migration.
-- MySQL 8 syntax: no ADD COLUMN IF NOT EXISTS (schema_migrations provides idempotency).

ALTER TABLE doc_invoices
  ADD COLUMN validated_by INT UNSIGNED NULL
    COMMENT 'FK → users.id — operator who validated this invoice (NULL = not yet validated)',
  ADD COLUMN energy_extract JSON NULL
    COMMENT 'Staged energy values for SIE/SIL bills; written by ingest worker, applied on validate',
  ADD CONSTRAINT fk_doc_invoices_validated_by
    FOREIGN KEY (validated_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE;
