-- 043 — Widen O2 measurement columns in bd_packaging from DECIMAL(6,3) to
--       DECIMAL(10,3).
--
-- Root cause: the same DECIMAL(6,3) overflow that was fixed for bd_racking in
-- migration 041 also affects five O2-related columns in bd_packaging.
-- BSF PackagingData already contains ppb values that exceed 999.999:
--
--   tank_o2:          8 rows with values up to 3563 ppb (e.g. rows 2045, 2048)
--   avg_o2:           1 row with value 2000 ppb (row 1733)
--   min_o2:           4 rows with values up to 1468 ppb (rows 1967–1996)
--   delta_o2_pickup:  5 rows with values up to 3536 ppb (rows 1804–1899)
--   max_o2:           0 current rows exceed 999 ppb, but same physical column.
--
-- avg_co2, min_co2 (does not exist as column), tank_co2 are in g/L (< 10 g/L
-- typical) and stay at DECIMAL(6,3) — no change needed.
--
-- All five columns are widened to DECIMAL(10,3) for consistency with bbt_o2 in
-- bd_racking (same measurement, same ppb range, same design decision from 041).
--
-- Down-migration:
--   ALTER TABLE bd_packaging
--     MODIFY tank_o2         DECIMAL(6,3) NULL,
--     MODIFY avg_o2          DECIMAL(6,3) NULL,
--     MODIFY min_o2          DECIMAL(6,3) NULL,
--     MODIFY max_o2          DECIMAL(6,3) NULL,
--     MODIFY delta_o2_pickup DECIMAL(6,3) NULL;
--   (Caution: would truncate any stored value > 999.999.)

ALTER TABLE bd_packaging
  MODIFY tank_o2         DECIMAL(10,3) NULL,
  MODIFY avg_o2          DECIMAL(10,3) NULL,
  MODIFY min_o2          DECIMAL(10,3) NULL,
  MODIFY max_o2          DECIMAL(10,3) NULL,
  MODIFY delta_o2_pickup DECIMAL(10,3) NULL;
