-- db/migrations/211_backfill_ref_skus_sku_type_flags.sql
--
-- What: Backfill is_packaging_line + is_direct_sales on all 169 ref_skus
--       rows (154 active + 15 inactive) from live evidence.
--
-- Why : F2 architectural arc, mig 210 added the columns with default 0/0.
--       Backfill rules per PM 2026-05-29:
--
--       is_packaging_line = 1 IFF
--         (a) ref_skus.id appears as sku_id_fk in bd_packaging_v2 — production
--             line evidence
--         OR
--         (b) ref_skus.format_id = 6 (X cage) — cardinal invariant per
--             packaging-bom-model.md: '-X is internal WIP, NEVER sold
--             direct; carries is_packaging_line=1 regardless of historical
--             v2 event presence'
--
--       is_direct_sales = 1 IFF
--         ref_skus.id appears as sku_id_fk in inv_sales_bc OR
--         inv_sales_order_lines (Shopify + BC sales evidence)
--
-- Projection (verified 2026-05-29 pre-apply on VPS):
--   is_packaging_line=1: 65 SKUs
--   is_direct_sales=1: 123 SKUs
--   BOTH=1: the core production-sold beers (F, 4, B, C, etc.)
--   BOTH=0: 20 active SKUs — investigated 2026-05-29, breakdown:
--     - 2 CollabIn: DGDB/DGDF (DrunkBeard "Galactic Drift", new collab
--       ingested 2026-05-28, pre-first-cycle)
--     - 5 Core eshop / draft: ALTBU, DIVP25, DIVP50, SPY12C50, ZEPCU
--     - 9 EPH seasonal eshop: EPH24PB, EPH44PB, EPH1CU, EPH2BU/CU/P50,
--       EPH3BU/CU/P50, EPH4CU
--     - 2 Composites: PAC, PAL (recipe_id=NULL — slot-membership canonical)
--     - 1 Archive STRANDED: BLOCU (BLO retired mig 204, BLOCU missed —
--       deactivated separately via mig 212 in the same batch)
--   None warrant doc_review_queue rows — all expected, none anomalous.
--
-- Risk: VERY LOW. Two UPDATEs on a column added in mig 210. Idempotent on
--       re-run (WHERE clauses ensure no-op). audit_row_revisions captures
--       every change.
--
-- Rollback:
--   UPDATE ref_skus SET is_packaging_line=0, is_direct_sales=0
--    WHERE id IN (SELECT target_pk FROM audit_row_revisions
--                  WHERE target_table='ref_skus'
--                    AND comment IN ('backfill_is_packaging_line_mig211',
--                                    'backfill_is_direct_sales_mig211'));
--   DELETE FROM audit_row_revisions
--    WHERE target_table='ref_skus'
--      AND comment IN ('backfill_is_packaging_line_mig211',
--                      'backfill_is_direct_sales_mig211');
--
-- Date   : 2026-05-29
-- Author : web

-- STEP 1: audit BEFORE the is_packaging_line UPDATE
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_211', 'ref_skus', s.id, 'update',
  JSON_OBJECT('is_packaging_line', s.is_packaging_line),
  JSON_OBJECT('is_packaging_line', 1),
  'backfill_is_packaging_line_mig211'
FROM ref_skus s
WHERE s.is_packaging_line = 0
  AND (
    EXISTS(SELECT 1 FROM bd_packaging_v2 p WHERE p.sku_id_fk = s.id)
    OR s.format_id = 6
  );

UPDATE ref_skus s
   SET s.is_packaging_line = 1
 WHERE s.is_packaging_line = 0
   AND (
     EXISTS(SELECT 1 FROM bd_packaging_v2 p WHERE p.sku_id_fk = s.id)
     OR s.format_id = 6
   );

-- STEP 2: audit BEFORE the is_direct_sales UPDATE
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_211', 'ref_skus', s.id, 'update',
  JSON_OBJECT('is_direct_sales', s.is_direct_sales),
  JSON_OBJECT('is_direct_sales', 1),
  'backfill_is_direct_sales_mig211'
FROM ref_skus s
WHERE s.is_direct_sales = 0
  AND (
    EXISTS(SELECT 1 FROM inv_sales_bc b WHERE b.sku_id_fk = s.id)
    OR EXISTS(SELECT 1 FROM inv_sales_order_lines o WHERE o.sku_id_fk = s.id)
  );

UPDATE ref_skus s
   SET s.is_direct_sales = 1
 WHERE s.is_direct_sales = 0
   AND (
     EXISTS(SELECT 1 FROM inv_sales_bc b WHERE b.sku_id_fk = s.id)
     OR EXISTS(SELECT 1 FROM inv_sales_order_lines o WHERE o.sku_id_fk = s.id)
   );
