-- db/migrations/138_fix_spy12c50.sql
-- What: Correct SPY12C50 ref_skus row:
--       • format_id: NULL → 11 (format_code='12C', 12-pack can)
--       • hl_per_unit: 0.00500 → 0.06000 (12 × 50cl = 6L = 0.06 hL)
-- Why: Phase 0 confirmed SPY12C50 = 12× pre-printed SPY 50cl cans + 12 lids + 1 "12-box
--      can blanc" (a 12-pack, NOT a single can). hl_per_unit=0.005 was wrong (single-can
--      logic). Sibling SPY12C is already format_id=11, hl_per_unit=0.06000 — this row
--      must match. The "12C50" suffix means 12 Cans 50cl; the naming is correct.
-- Pre-check: confirm sibling SPY12C has format_id=11 hl_per_unit=0.06 (verified in audit).
--   SELECT sku_code, format_id, hl_per_unit FROM ref_skus WHERE sku_code IN ('SPY12C','SPY12C50');
--   Expected: SPY12C → 11, 0.06; SPY12C50 → NULL, 0.005
-- Risk: Single-row UPDATE on a reference table. LOW. Snapshot recommended before apply.
-- Rollback: UPDATE ref_skus SET format_id=NULL, hl_per_unit=0.00500 WHERE sku_code='SPY12C50';

UPDATE ref_skus
SET
  format_id    = 11,      -- ref_packaging_formats.id=11, format_code='12C'
  hl_per_unit  = 0.06000, -- 12 × 50cl = 6L = 0.06 hL (matches sibling SPY12C)
  last_modified_by = 'web'
WHERE sku_code = 'SPY12C50';
