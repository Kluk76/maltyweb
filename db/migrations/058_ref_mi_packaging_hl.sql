-- 058_ref_mi_packaging_hl.sql
--
-- Adds packaging_hl_equivalent column to ref_mi.
-- For packaging MIs (bottles, cans, cartons, wraparounds, 4-packs, découverte/xmas
-- packs, labels, lids, crown corks): the HL of liquid this MI can theoretically
-- package. Used by the warehouse page's "HL équivalent" column + headline KPI.
--
-- Examples:
--   1× wraparound carton (24×33cl) = 24 × 0.0033 = 0.0792 HL
--   1× bottle 33cl                  = 0.0033 HL
--   1× can 33cl                     = 0.0033 HL
--   1× 4-pack (4×33cl can)          = 4 × 0.0033 = 0.0132 HL
--   1× label                        = matches the underlying bottle/can format
--
-- NULL = not a packaging MI (or not in the operator-defined inclusion list).
-- The page's HL column stays blank for these.
--
-- Populated by scripts/_warehouse-populate-hl-equivalent.ts (one-off seed).
-- New packaging MIs created after this migration should set the column at
-- creation time; the ref_mi write path will be updated separately.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

ALTER TABLE ref_mi
  ADD COLUMN packaging_hl_equivalent DECIMAL(10,6) NULL
    COMMENT 'HL of liquid this MI can theoretically package. NULL for non-packaging MIs.';
