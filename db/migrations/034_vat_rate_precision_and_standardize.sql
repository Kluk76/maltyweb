-- 034 — Standardize doc_invoice_lines.vat_rate encoding + widen precision.
--
-- Problem observed during the Phase C 58-invoice validation pass
-- (2026-05-12): vat_rate is double-encoded across the source JSON
-- (data/invoice-log.json):
--
--   parser=llm-fallback              → fraction (0.081, 0.026, ...)
--   parser=(none) / manual-llm-walk  → fraction (0.081)
--   parser=manual-walkthrough-05-02  → 0 only (no signal)
--   parser=manual-walkthrough-05-03  → percentage (8.1, 2.6)
--   parser=manual-walkthrough-05-04  → percentage (8.1)
--
-- The MySQL doc_invoice_lines.vat_rate column is DECIMAL(5,2), so
-- fraction rows truncate (0.081 → 0.08) — losing 0.001 of precision
-- per line. Percentage rows store cleanly but mix with fractions in
-- the same column.
--
-- Standardize on FRACTION (0.0810 means 8.10% VAT). This is the
-- mathematical convention, matches the default parser (llm-fallback),
-- and keeps qty × unit_price × vat_rate = vat_amount direct.
--
-- Two changes:
--   1. Widen vat_rate from DECIMAL(5,2) to DECIMAL(6,4) — fraction
--      values get full 4-decimal precision (0.0810 not 0.08).
--   2. Backfill rows where vat_rate > 1 by dividing by 100. Cutoff is
--      safe: no real-world VAT rate exceeds 100% (= 1.0 fraction);
--      EU rates max around 27% = 0.27.
--
-- After migration: doc_invoice_lines.vat_rate is always fraction with
-- 4-decimal precision. New rows MUST be written as fraction (the
-- ingest-documents.js mirror gets a guard in the same commit).
--
-- Down-migration:
--   UPDATE doc_invoice_lines SET vat_rate = vat_rate * 100
--     WHERE vat_rate > 0 AND vat_rate < 1;
--   ALTER TABLE doc_invoice_lines MODIFY COLUMN vat_rate DECIMAL(5,2);
-- (Down is lossy: 0.0810 → 8.10, but precision past 2 decimals lost.)

ALTER TABLE doc_invoice_lines MODIFY COLUMN vat_rate DECIMAL(6,4) NULL;

UPDATE doc_invoice_lines
SET vat_rate = vat_rate / 100
WHERE vat_rate IS NOT NULL AND vat_rate > 1;
