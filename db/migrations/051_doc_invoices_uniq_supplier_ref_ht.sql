-- Migration 051 — doc_invoices UNIQUE (supplier_fk, invoice_ref, total_ht)
--
-- Date: 2026-05-18
-- Author: pre-reingest QC (Phase G)
-- Scope: prevent header-level duplicate invoices from being inserted
--
-- Background: the 2026-05-16 Phase F bulk re-ingest produced 33 duplicate
-- groups (40 excess doc_invoices rows) because the same physical invoice
-- was uploaded to Drive multiple times under different filenames. The
-- existing row_hash UNIQUE catches identical bytes, but not identical
-- business-identity headers from different Drive uploads.
--
-- The application MAY catch the resulting IntegrityError and treat it as
-- a graceful upsert / skip. For the Phase G nuke + re-ingest, this is
-- applied against an empty table so no existing data conflicts.
--
-- NULL behaviour: MySQL UNIQUE allows multiple NULL combinations, so rows
-- where invoice_ref or total_ht is not yet extracted (early-state) will
-- not collide. Caller (mysql-doc-invoices.ts) must handle IntegrityError
-- for the non-NULL duplicate case.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

-- ── UP ───────────────────────────────────────────────────────────────────────────

ALTER TABLE doc_invoices
  ADD UNIQUE KEY uniq_supplier_ref_ht (supplier_fk, invoice_ref, total_ht);

-- ── DOWN ─────────────────────────────────────────────────────────────────────────

-- ALTER TABLE doc_invoices DROP INDEX uniq_supplier_ref_ht;
