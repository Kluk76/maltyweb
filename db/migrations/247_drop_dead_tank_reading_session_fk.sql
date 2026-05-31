-- ============================================================================
-- Migration 247: drop the dead bd_packaging_v2.tank_reading_session_id_fk
--                (contract phase)
--
-- WHAT:
--   Drops the self-FK column tank_reading_session_id_fk (mig 240) from
--   bd_packaging_v2, along with its FK constraint fk_bdpv2_tank_reading and
--   backing index idx_bdpv2_tank_reading_fk.
--
-- WHY:
--   Mig 240 added this column to anchor lot-day CO₂/O₂ read-sets, built on the
--   (now corrected) mistaken belief that bd_packaging_co2o2_measures held in-tank
--   reads. The in-tank read is now a first-class dimension (bd_tank_readings,
--   mig 241) referenced by bd_packaging_v2.tank_read_id_fk (mig 242). The web
--   form (f93e250) no longer writes tank_reading_session_id_fk, so the column is
--   dead. Only 1 row ever carried a non-NULL value (the old 6710→6711 link),
--   which is superseded by 6710's correct tank_read_id_fk (set separately).
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_v2
--     ADD COLUMN tank_reading_session_id_fk BIGINT UNSIGNED NULL AFTER reuses_packaging_id_fk,
--     ADD CONSTRAINT fk_bdpv2_tank_reading FOREIGN KEY (tank_reading_session_id_fk)
--       REFERENCES bd_packaging_v2(id) ON DELETE SET NULL;
--   ALTER TABLE bd_packaging_v2 ADD KEY idx_bdpv2_tank_reading_fk (tank_reading_session_id_fk);
--   (the single historical value is not restored — it was wrong.)
-- ============================================================================

ALTER TABLE bd_packaging_v2
  DROP FOREIGN KEY fk_bdpv2_tank_reading,
  DROP KEY idx_bdpv2_tank_reading_fk,
  DROP COLUMN tank_reading_session_id_fk;
