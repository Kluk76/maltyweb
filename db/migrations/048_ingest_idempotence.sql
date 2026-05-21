-- 048_ingest_idempotence.sql
--
-- Makes the invoice-ingest pipeline fully idempotent at the DB layer.
--
-- Problems solved
-- ───────────────
-- 1. doc_invoice_lines has no (invoice_id, line_index) uniqueness constraint.
--    Re-running the ingest for the same file silently duplicates lines.
--    Verified: 18 duplicate (invoice_id, line_index) groups exist (18 extra rows).
--
-- 2. inv_deliveries.row_hash integrates invoiceRef, which changes on
--    Pending→Active promotion, so the dedup guard creates a second row
--    instead of recognising the promoted version of the same delivery.
--    The new (file_id_fk, line_index) partial-unique pair anchors identity
--    to the physical document line — stable across promotion.
--
-- Structure
-- ─────────
-- Section A : Clean up doc_invoice_lines duplicates (keep lowest id per pair).
-- Section B : Recompute doc_invoice_lines.row_hash to include invoice_id.
-- Section C : Add UNIQUE KEY uq_invoice_line (invoice_id, line_index) to
--             doc_invoice_lines.
-- Section D : Add file_id_fk + line_index columns to inv_deliveries.
-- Section E : Add generated dedup_key column + UNIQUE index on inv_deliveries.
-- Section F : Backfill file_id_fk for existing node-ingest rows via invoice_ref.
--
-- Stats (measured before this migration was written)
-- ────────────────────────────────────────────────────
--   doc_invoice_lines total rows            : 277
--   Duplicate (invoice_id, line_index) groups: 18  (18 extra rows to delete)
--   inv_deliveries node-ingest rows          : 64  (45 backfillable via invoice_ref)
--   inv_deliveries bsf-mirror rows           : 484 (file_id_fk stays NULL)
--
-- Apply
-- ─────
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'
--
-- Rollback (for reference — not applied automatically)
--   ALTER TABLE inv_deliveries DROP INDEX uq_dedup_key;
--   ALTER TABLE inv_deliveries DROP COLUMN dedup_key;
--   ALTER TABLE inv_deliveries DROP COLUMN line_index;
--   ALTER TABLE inv_deliveries DROP FOREIGN KEY fk_inv_deliveries_doc_file;
--   ALTER TABLE inv_deliveries DROP INDEX idx_file_id_line;
--   ALTER TABLE inv_deliveries DROP COLUMN file_id_fk;
--   ALTER TABLE doc_invoice_lines DROP INDEX uq_invoice_line;


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section A: Delete duplicate doc_invoice_lines rows (keep lowest id per pair)
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- 18 extra rows exist where (invoice_id, line_index) is duplicated.
-- Strategy: for each duplicate group, DELETE all rows except the one with
-- MIN(id) (= first-inserted, most likely to have the correct row_hash).
-- The FK ON DELETE CASCADE on doc_invoice_lines.invoice_id means no orphan risk.

DELETE dil
FROM doc_invoice_lines dil
INNER JOIN (
  SELECT invoice_id, line_index, MIN(id) AS keep_id
  FROM doc_invoice_lines
  GROUP BY invoice_id, line_index
  HAVING COUNT(*) > 1
) keeper ON dil.invoice_id = keeper.invoice_id
        AND dil.line_index  = keeper.line_index
        AND dil.id          > keeper.keep_id;


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section B: Recompute doc_invoice_lines.row_hash with invoice_id anchored
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- Old formula (JS): SHA-256( parentInvoiceRowHash | lineIndex | ingredientName |
--                            miIdFk | qty | unitPrice | lineTotal )
-- Problem: uses the *parent's* row_hash as prefix, which means the line hash
-- changes whenever the invoice header hash changes (e.g., after a re-extract that
-- updates totalHT). That breaks INSERT IGNORE / ON DUPLICATE KEY idempotency.
--
-- New formula anchors the line to the *invoice table PK* (stable integer) and
-- structural identity fields only:
--   SHA-256( invoice_id | line_index | COALESCE(description,'') |
--            COALESCE(qty,'') | COALESCE(unit_price,'') | COALESCE(line_total,'') )
--
-- This UPDATE rewrites all existing row_hash values to the new formula.
-- After this runs, the TS computeLineRowHash function must use the same formula
-- (updated in lib/repos/mysql-doc-invoices.ts in this commit).

UPDATE doc_invoice_lines
SET row_hash = SHA2(
  CONCAT_WS('|',
    invoice_id,
    line_index,
    COALESCE(description, ''),
    COALESCE(CAST(qty        AS CHAR), ''),
    COALESCE(CAST(unit_price AS CHAR), ''),
    COALESCE(CAST(line_total AS CHAR), '')
  ),
  256
);


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section C: Add UNIQUE KEY uq_invoice_line (invoice_id, line_index)
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- Safe after Section A cleaned all duplicates.
-- Enables INSERT … ON DUPLICATE KEY UPDATE in the TS repo (idempotent re-ingest).

ALTER TABLE doc_invoice_lines
  ADD UNIQUE KEY uq_invoice_line (invoice_id, line_index);


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section D: Add file_id_fk + line_index columns to inv_deliveries
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- file_id_fk : FK to doc_files.id (the physical document that originated this
--              delivery row).  NULL for bsf-mirror rows (no associated document).
-- line_index : 0-based line index within the originating invoice.
--              NULL for DN-OCR Pending rows (no invoice line yet).
--              Also NULL for bsf-mirror and manual rows.
--
-- Together (file_id_fk, line_index) form the stable physical-document identity
-- of a delivery row — invariant across Pending→Active promotion.

ALTER TABLE inv_deliveries
  ADD COLUMN file_id_fk  BIGINT UNSIGNED NULL
    COMMENT 'FK to doc_files.id — the document that originated this row. NULL for bsf-mirror/manual rows.'
    AFTER details,
  ADD COLUMN line_index  SMALLINT NULL
    COMMENT '0-based line index within the originating invoice. NULL for DN-only, bsf-mirror, manual rows.'
    AFTER file_id_fk,
  ADD KEY idx_file_id_line (file_id_fk, line_index),
  ADD CONSTRAINT fk_inv_deliveries_doc_file
    FOREIGN KEY (file_id_fk)
    REFERENCES doc_files (id)
    ON DELETE RESTRICT;


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section E: Generated dedup_key column + partial-unique index
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- MySQL 8 UNIQUE indexes treat NULL as distinct (unlike SQL-standard, which
-- matches ANSI-null semantics), so UNIQUE(file_id_fk, line_index) would allow
-- duplicate NULLs as desired. However MySQL 5.7 compatibility is irrelevant
-- here (VPS runs MySQL 8), but the generated-column approach is still preferred
-- because it makes the dedup key visible and queryable.
--
-- dedup_key is VARCHAR(64): "docFilesId:lineIndex" when both are set, NULL when
-- either is NULL (so bsf-mirror and DN-only rows are never compared against each
-- other or against invoice-OCR rows).
--
-- VIRTUAL: no storage cost; recomputed on read.

ALTER TABLE inv_deliveries
  ADD COLUMN dedup_key VARCHAR(74)
    GENERATED ALWAYS AS (
      CASE
        WHEN file_id_fk IS NOT NULL AND line_index IS NOT NULL
        THEN CONCAT(file_id_fk, ':', line_index)
        ELSE NULL
      END
    ) VIRTUAL
    COMMENT 'Stable per-document-line identity key; NULL for rows without a document line anchor.',
  ADD UNIQUE INDEX uq_dedup_key (dedup_key);


-- ═══════════════════════════════════════════════════════════════════════════════
-- Section F: Backfill file_id_fk for existing node-ingest rows
-- ═══════════════════════════════════════════════════════════════════════════════
--
-- 45 of 64 node-ingest rows have a non-NULL invoice_ref that maps unambiguously
-- to exactly one doc_invoices row (verified: no ref maps to >1 invoice).
-- 7 have NULL invoice_ref (DN-OCR Pending rows) — line_index stays NULL.
-- 12 have invoice_ref that is not yet present in doc_invoices — also left NULL.
--
-- We backfill file_id_fk by joining inv_deliveries → doc_invoices → doc_files.
-- We set line_index = ROW_NUMBER() - 1 within each (file_id_fk) group, ordered
-- by inv_deliveries.id ASC. This approximates the original parser order but is
-- not guaranteed to match the doc_invoice_lines.line_index for the same lines —
-- the purpose is dedup identity, not strict position matching.
--
-- Rows where the join is NULL (unmatched invoice_ref or NULL ref) retain
-- file_id_fk = NULL and therefore dedup_key = NULL. They remain dedup-guarded
-- only via row_hash (existing mechanism).

UPDATE inv_deliveries d
JOIN doc_invoices di ON di.invoice_ref = d.invoice_ref
JOIN doc_files df    ON df.id = di.file_id
SET d.file_id_fk = df.id
WHERE d.source_origin = 'node-ingest'
  AND d.invoice_ref IS NOT NULL
  AND d.file_id_fk IS NULL;

-- Assign line_index = sequential position within each (file_id_fk) group,
-- ordered by id ASC (mirrors original insertion order).
-- Uses a session variable to simulate ROW_NUMBER per group (MySQL 8 supports
-- ROW_NUMBER() in subqueries, but UPDATE … JOIN subquery with window functions
-- requires a derived table).

UPDATE inv_deliveries d
JOIN (
  SELECT
    id,
    file_id_fk,
    (ROW_NUMBER() OVER (PARTITION BY file_id_fk ORDER BY id ASC) - 1) AS rn
  FROM inv_deliveries
  WHERE source_origin = 'node-ingest'
    AND file_id_fk IS NOT NULL
    AND line_index IS NULL
) ranked ON ranked.id = d.id
SET d.line_index = ranked.rn;
