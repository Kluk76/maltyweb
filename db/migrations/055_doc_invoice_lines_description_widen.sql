-- Migration 055: widen doc_invoice_lines.description from VARCHAR(512) to VARCHAR(1024).
--
-- Raben Sieber (and potentially other forwarder parsers) can emit verbose
-- charge-block breakdowns that exceed 512 chars.  The Zod schema in
-- lib/repos/mysql-doc-invoices.ts is updated in parallel to max(1024).
--
-- Safe to re-run: ALTER on varchar widening is a metadata-only change in InnoDB
-- (no row rebuild needed), so it is near-instant even on large tables.

ALTER TABLE doc_invoice_lines
  MODIFY COLUMN description VARCHAR(1024) NULL;
