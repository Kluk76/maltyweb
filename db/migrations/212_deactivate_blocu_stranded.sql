-- db/migrations/212_deactivate_blocu_stranded.sql
--
-- What: Deactivate ref_skus id=103 (BLOCU — "Blonde des Romands eshop can
--       single 50cl") — the BLO eshop single missed by mig 204.
--
-- Why : F2 closure pass (vessel-commissioning audit 2026-05-28). Mig 204
--       retired the BLO sub-line (BLO4 id=5, BLO4C id=6, BLOF id=7) but
--       missed BLOCU (id=103). Surfaced 2026-05-29 during the BOTH-0
--       investigation: BLOCU's recipe (id=10 "Blonde des Romands",
--       subtype='Archive') is in archive state and BLO is operator-retired
--       (no future production; the BLO brand became a relabel of ZEP from
--       2022-04 then retired entirely 2026-05-28 per [[project_production_vs_sales_skus]]).
--
--       The omission was incidental — mig 204 focused on the packaging-line
--       SKUs (B/4C/F) and the eshop unboxing single CU was overlooked
--       because the audit framing at the time didn't distinguish them.
--
-- Safety check passed: 2026-05-29
--   - 0 bd_packaging_v2 events for sku_id_fk=103 (BLOCU never produced).
--   - 0 inv_sales_bc rows for sku_id_fk=103 in our sales window
--     (2025-12 → 2026-04).
--   - 0 inv_sales_order_lines rows for sku_id_fk=103.
--   - 0 ref_sku_bom rows depending on it (eshop single = derived via
--     unboxing, not BOM).
--   - Recipe id=10 stays untouched (subtype='Archive' preserves historical
--     reference per CLAUDE.md "no rewriting history").
--
-- Risk: VERY LOW. Single SKU flag. Same pattern as mig 204.
--
-- Rollback:
--   UPDATE ref_skus SET is_active = 1 WHERE id = 103 AND sku_code = 'BLOCU';
--
-- Date   : 2026-05-29
-- Author : web

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 103
   AND sku_code = 'BLOCU';
