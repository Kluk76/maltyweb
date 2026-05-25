-- db/migrations/124_merge_alfa_laval.sql
-- What: DATA MIGRATION — merge duplicate supplier ALFALAVAL (id=5) into canonical
--       Alfa Laval (id=4). Reassigns id=5's only FK references (2 doc_invoices rows),
--       preserves the name variant as an alias on id=4, then deletes the zombie row.
-- Why:  ref_suppliers has two rows for the same physical supplier (DBA report §0, §6):
--         id=4  "Alfa Laval"  SUP_ALFA_LAVAL  is_active=1  6 inv_deliveries  1 alias
--         id=5  "ALFALAVAL"   SUP_ALFALAVAL   is_active=0  0 inv_deliveries  0 aliases
--       The duplicate was flagged in parser-supplier-map.ts and verified live 2026-05-25.
--       id=5 has ONLY 2 FK references across all 6 FK tables: doc_invoices id=14
--       (ref 250001955) and id=135 (ref 263000071). id=4's only invoice is ref 250001755,
--       so the two incoming refs are distinct and the reassignment will NOT collide on
--       the UNIQUE KEY uniq_supplier_ref_ht(supplier_fk, invoice_ref, total_ht) — both
--       rows have total_ht NULL, and the invoice_ref values differ from id=4's existing
--       invoice. doc_invoices.supplier_fk FK is RESTRICT, so id=5 cannot be deleted
--       until the invoices are moved first. ref_supplier_aliases has a UNIQUE on (alias),
--       and 'ALFALAVAL' is not currently assigned to id=4 (verified), so the INSERT
--       will not collide.
-- Decision: Merge is intentional and permanent. id=5's supplier_id 'SUP_ALFALAVAL'
--           is freed. The ALFALAVAL name variant is preserved as a manual alias on id=4
--           so future invoice OCR variants ("ALFALAVAL") still resolve correctly.
-- Rollback: NOT cleanly reversible — the duplicate row is intentionally gone. To undo,
--           manually re-INSERT id=5, reassign the 2 doc_invoices rows back, and delete
--           the alias. Snapshot the state before applying if a rollback path is needed.

-- Step 1: Reassign the 2 doc_invoices rows from zombie id=5 to canonical id=4.
UPDATE doc_invoices SET supplier_fk = 4 WHERE supplier_fk = 5;

-- Step 2: Record the name variant 'ALFALAVAL' as a manual alias on the canonical row
--         so future OCR matches on this string resolve to id=4.
INSERT INTO ref_supplier_aliases (supplier_id_fk, alias, source)
VALUES (4, 'ALFALAVAL', 'manual');

-- Step 3: Delete the zombie row. The RESTRICT FK on doc_invoices is now clear (step 1);
--         no other tables reference id=5.
DELETE FROM ref_suppliers WHERE id = 5;
