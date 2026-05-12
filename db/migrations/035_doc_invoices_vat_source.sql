-- 035 — Add doc_invoices.vat_source + backfill missing total_vat.
--
-- Mirrors the existing ht_source pattern. Some parsers (notably the
-- manual-walkthrough variants) populate total_ht and total_ttc but not
-- total_vat — leaving the column NULL even when VAT can be derived
-- arithmetically from TTC - HT. The result is that "VAT" looks missing
-- on review while the underlying data is complete.
--
-- Two changes:
--   1. ADD vat_source ENUM after total_vat. Values:
--        'extracted'           — VAT line parsed directly from the invoice
--        'derived_from_ttc_ht' — total_vat = total_ttc - total_ht
--        'derived_from_lines'  — total_vat = SUM(line_total × vat_rate)
--        'unknown'             — neither extracted nor derivable
--      Mirrors the ht_source ENUM. Same precision-of-provenance pattern.
--   2. Backfill existing rows. Order of attempts per row:
--        (a) total_vat already non-null  → vat_source = 'extracted'
--        (b) total_ttc + total_ht both non-null
--                                        → total_vat = total_ttc - total_ht
--                                          vat_source = 'derived_from_ttc_ht'
--        (c) sum(line_total × vat_rate) computable for the invoice
--                                        → total_vat = that sum
--                                          vat_source = 'derived_from_lines'
--        (d) otherwise                   → vat_source = 'unknown'
--
-- ingest-documents.js gets the matching mirror-side logic in the same
-- commit so new ingests follow the same rules.
--
-- Down-migration:
--   ALTER TABLE doc_invoices DROP COLUMN vat_source;
-- (No need to revert backfilled total_vat values — they are correct.)

ALTER TABLE doc_invoices
  ADD COLUMN vat_source ENUM('extracted','derived_from_ttc_ht','derived_from_lines','unknown')
    NULL AFTER total_vat;

-- Step (a): rows where total_vat is already extracted.
UPDATE doc_invoices
SET vat_source = 'extracted'
WHERE total_vat IS NOT NULL;

-- Step (b): rows where TTC and HT both present, total_vat NULL.
UPDATE doc_invoices
SET total_vat  = total_ttc - total_ht,
    vat_source = 'derived_from_ttc_ht'
WHERE total_vat IS NULL
  AND total_ttc IS NOT NULL
  AND total_ht  IS NOT NULL;

-- Step (c): rows where lines have vat_rate populated and at least one
-- non-zero line_total. SUM in a correlated subquery.
UPDATE doc_invoices di
SET
  di.total_vat = (
    SELECT ROUND(SUM(line_total * vat_rate), 4)
    FROM doc_invoice_lines
    WHERE invoice_id = di.id
      AND line_total IS NOT NULL
      AND vat_rate   IS NOT NULL
  ),
  di.vat_source = 'derived_from_lines'
WHERE di.total_vat IS NULL
  AND EXISTS (
    SELECT 1 FROM doc_invoice_lines
    WHERE invoice_id = di.id
      AND line_total IS NOT NULL
      AND vat_rate IS NOT NULL
      AND vat_rate > 0
  );

-- Step (d): everything still null.
UPDATE doc_invoices
SET vat_source = 'unknown'
WHERE vat_source IS NULL;
