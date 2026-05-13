-- 045 — Widen bd_packaging_readings.o2 from DECIMAL(6,3) to DECIMAL(10,3).
--
-- Root cause: same DECIMAL(6,3) overflow pattern already fixed for bd_packaging
-- in migration 043 and bd_racking.bbt_o2 in migration 041.
-- bd_packaging_readings stores per-reading O2 values in ppb (parts per billion),
-- which legitimately exceed 999 ppb in some packaging runs with elevated DO pickup.
--
-- Confirmed burned rows (2026-05-13 audit):
--   id=5115  packaging_id=1966  reading_idx=2  o2=999.999 (real BSF value: 1623 ppb)
--   id=5116  packaging_id=1966  reading_idx=3  o2=999.999 (real BSF value: 1826 ppb)
--   id=5118  packaging_id=1967  reading_idx=2  o2=999.999 (real BSF value: 1623 ppb)
--   id=5119  packaging_id=1967  reading_idx=3  o2=999.999 (real BSF value: 1826 ppb)
--   id=5161  packaging_id=1994  reading_idx=2  o2=999.999 (real BSF value: 1327 ppb)
--   id=5163  packaging_id=1995  reading_idx=2  o2=999.999 (real BSF value: 1327 ppb)
--
-- After running this migration, run:
--   python3 scripts/python/maintenance/repair_truncated_packaging_o2.py --apply
-- to write the real ppb values from BSF into these 6 rows.
--
-- The bd_packaging_readings.co2 column stays at DECIMAL(6,3) — CO2 is measured
-- in g/L (typical range 3–8 g/L) and does not require widening.
--
-- Down-migration:
--   ALTER TABLE bd_packaging_readings MODIFY o2 DECIMAL(6,3) NULL;
--   (Caution: would re-truncate any stored value > 999.999.)

ALTER TABLE bd_packaging_readings
  MODIFY o2 DECIMAL(10,3) NULL;
