-- ============================================================================
-- Migration 246: fold bd_packaging_co2o2_measures into bd_packaging_readings,
--                then drop it (contract phase)
--
-- WHAT:
--   1. Fold the 11 in-filling CO₂/O₂ rows from bd_packaging_co2o2_measures into
--      bd_packaging_readings (the single canonical in-filling store).
--      Mapping: packaging_id=NULL (v2-only), packaging_v2_id=packaging_id_fk,
--               reading_idx=reading_index, o2=o2_ppb, co2=co2_gl.
--   2. DROP TABLE bd_packaging_co2o2_measures.
--   3. Remove its schema_meta classification row.
--
-- WHY:
--   bd_packaging_co2o2_measures (mig 232) and bd_packaging_readings both held
--   IN-FILLING reads (multiple pairs pulled from finished units during a fill —
--   these ARE losses). Per operator request the two are consolidated onto ONE
--   table: bd_packaging_readings. The web form (mig f93e250) already writes
--   in-filling reads there via packaging_v2_id and no longer touches this table;
--   app/packaging-stats.php (fc7e1a6) reads from there too. This migration moves
--   the 11 remaining rows and drops the now-orphaned table.
--
--   The 11 rows are all on live, non-tombstoned v2 runs (6709, 6711, 6712) with
--   ZERO (packaging_v2_id, reading_idx) collisions against existing readings
--   (verified pre-flight) — the fold is loss-less and the uq_pkg_readings_v2
--   UNIQUE (mig 245) cannot reject them.
--
--   Pre-fold values (provenance; preserved by step 1):
--     v2 6709: (1) 4.980/75.70  (2) 4.950/79.80  (3) 4.950/32.70
--     v2 6711: (1) 5.130/29.80  (2) 5.280/77.50  (3) 5.190/34.50
--     v2 6712: (1) 4.820/148.0  (2) 4.860/182.0  (3) 4.890/109.0
--              (4) 4.940/31.80  (5) 4.930/31.00     [co2_gl / o2_ppb]
--
-- NOTE: in-tank reads are a SEPARATE concern — they live in bd_tank_readings
--   (mig 241) and are NOT involved here.
--
-- ROLLBACK:
--   Recreate the table from mig 232 source, then
--     INSERT INTO bd_packaging_co2o2_measures (packaging_id_fk,reading_index,co2_gl,o2_ppb,source)
--       SELECT packaging_v2_id, reading_idx, co2, o2, 'web_entry'
--       FROM bd_packaging_readings WHERE packaging_id IS NULL AND packaging_v2_id IN (6709,6711,6712);
--     DELETE FROM bd_packaging_readings WHERE packaging_id IS NULL AND packaging_v2_id IN (6709,6711,6712);
--   and re-insert the schema_meta row.
-- ============================================================================

-- 1. Fold (INSERT ... SELECT — no client result set, migrate.php-safe).
INSERT INTO bd_packaging_readings (packaging_id, packaging_v2_id, reading_idx, o2, co2)
SELECT NULL, c.packaging_id_fk, c.reading_index, c.o2_ppb, c.co2_gl
FROM bd_packaging_co2o2_measures c;

-- 2. Drop the orphaned table.
DROP TABLE bd_packaging_co2o2_measures;

-- 3. Remove its schema_meta classification row.
DELETE FROM schema_meta WHERE table_name = 'bd_packaging_co2o2_measures';
