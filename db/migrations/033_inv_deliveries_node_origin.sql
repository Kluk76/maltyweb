-- 033 — Relax inv_deliveries for Node-side writes (Phase C cutover).
--
-- Before this migration, inv_deliveries was populated exclusively by the
-- Python `ingest_deliveries.py` ingester, which mirrors BSF!Deliveries
-- row-by-row. Each row carried `sheet_row_index INT UNSIGNED NOT NULL UNIQUE`
-- tying it to the source BSF row number.
--
-- Phase C of the document-vision migration introduces a second writer:
-- maltytask's `scripts/ingest-documents.js` calls into the new
-- `lib/repos/mysql-deliveries.ts` repo (this commit's companion) and
-- writes directly to inv_deliveries — bypassing BSF. These Node-origin rows
-- have no BSF row index because BSF!Deliveries is no longer being written
-- after cutover.
--
-- Two schema changes:
--   1. sheet_row_index: NOT NULL → NULL (MySQL UNIQUE allows multiple NULLs).
--      Legacy BSF-mirror rows keep their value; Node-origin rows store NULL.
--   2. ADD source_origin VARCHAR(32) NOT NULL DEFAULT 'bsf-mirror' with INDEX.
--      Values: 'bsf-mirror' (Python ingest_deliveries.py pulling from BSF) |
--              'node-ingest' (maltytask ingest-documents.js writing direct).
--      The DEFAULT backfills all 442 existing rows as 'bsf-mirror' implicitly.
--
-- Audit-friendly: a single indexed VARCHAR column lets the maltyweb PHP admin
-- views, COGS Python computations, and any future reconciler filter by origin
-- without inferring from sheet_row_index presence.
--
-- No data backfill needed — DEFAULT covers it. INSERT of new Node rows must
-- set source_origin='node-ingest' explicitly (the repo enforces this).
--
-- Down-migration:
--   ALTER TABLE inv_deliveries DROP INDEX idx_inv_deliveries_source_origin;
--   ALTER TABLE inv_deliveries DROP COLUMN source_origin;
--   ALTER TABLE inv_deliveries MODIFY COLUMN sheet_row_index INT UNSIGNED NOT NULL;
-- (The third statement only succeeds if no Node-origin rows exist — i.e., no
--  rows with sheet_row_index IS NULL. Manual cleanup required first.)

ALTER TABLE inv_deliveries MODIFY COLUMN sheet_row_index INT UNSIGNED NULL;

ALTER TABLE inv_deliveries
  ADD COLUMN source_origin VARCHAR(32) NOT NULL DEFAULT 'bsf-mirror' AFTER details;

ALTER TABLE inv_deliveries
  ADD INDEX idx_inv_deliveries_source_origin (source_origin);
