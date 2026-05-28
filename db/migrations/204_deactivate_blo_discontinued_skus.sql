-- Migration 204: Deactivate discontinued BLO (Blonde des Romands) SKUs
--
-- Purpose : BLO (Blonde des Romands) is a discontinued sub-line. Originally its
--           own recipe (id=10, brewed 2021-01 → 2022-03, 16 brews total). From
--           2022-04 onwards continued as a relabel of ZEP (Zepp) recipe via the
--           brewing alias `BLO → ref_recipes.id=57` (recorded in ref_recipe_aliases).
--           Operator confirmed 2026-05-28 the sub-line is retired with no future
--           production planned.
--
--           Three SKUs to deactivate (mirror of PBD migration 185, ZEP6C
--           migration 191 pattern):
--             - BLO4   (id=5, 24-pack 33cl bottles)  — 29 pkg events / 171,468 bottles
--             - BLO4C  (id=6, 4-pack 50cl cans)      — 28 pkg events / 183,656 cans
--             - BLOF   (id=7, 20L keg)               —  9 pkg events /   1,133 kegs
--
--           Total historical production: ~1,693 HL of finished BLO product
--           over ~5 years. Historical packaging events (66 rows in
--           bd_packaging_v2) preserved as production records.
--
-- Safety check passed: 2026-05-28
--   - 66 bd_packaging_v2 rows for these 3 SKUs (preserved as historical
--     production records — deactivation is forward-only).
--   - 0 inv_sales_bc rows / 0 inv_sales_order_lines rows for BLO* SKU codes
--     in our sales-data window (2025-12 → 2026-04). Any 2024-era sales lived
--     in legacy pre-MySQL accounting; nothing to break in current sales flows.
--   - ref_recipe_aliases.BLO → 57 (Zepp) preserved — this alias is correct
--     brewing-side (records that BLO liquid is sourced from ZEP recipe).
--     Deactivating the SKUs is orthogonal to the brewing alias.
--   - Companion fix to scripts/python/ingest_bd_packaging_v2.py (literal raw
--     SKU text first for sku_id_fk resolution) landed in the same session
--     (commit pending). 66 historical events now correctly attribute to BLO*
--     SKUs in bd_packaging_v2 rather than mis-routing to ZEP*/MOO*.
--
--   - 5 RQ rows (sku-bom-unresolved type, ids 441-445) reference BLO4/BLO4C —
--     close on next compile (deactivated SKUs are gated out by the compiler
--     since migration 191's commissioning-chain INNER-JOIN).
--
-- Date   : 2026-05-28
-- Author : web

UPDATE ref_skus
   SET is_active = 0
 WHERE id IN (5, 6, 7)
   AND sku_code IN ('BLO4', 'BLO4C', 'BLOF');
