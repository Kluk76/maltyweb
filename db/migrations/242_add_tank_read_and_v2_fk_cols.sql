-- ============================================================================
-- Migration 242: Additive FK columns — bd_packaging_v2 → bd_tank_readings,
--                bd_packaging_readings → bd_packaging_v2
--
-- WHAT:
--   Two additive nullable column adds.  NO drops, NO data changes.
--   The old columns (tank_reading_session_id_fk, co2o2_measures relationship)
--   remain untouched until the packaging form is cut over in a later phase.
--
--   1. bd_packaging_v2 — ADD COLUMN tank_read_id_fk BIGINT UNSIGNED NULL
--        Placed AFTER the existing tank_reading_session_id_fk (mig 240).
--        FK fk_bdpv2_tank_read → bd_tank_readings(id) ON DELETE RESTRICT.
--        Backing index idx_bdpv2_tank_read.
--        Purpose: each packaging run (v2 row) will point to its lot-day
--        in-tank read once the form cut-over ships.  Backfill in a later
--        migration after bd_tank_readings is populated.
--
--   2. bd_packaging_readings — ADD COLUMN packaging_v2_id BIGINT UNSIGNED NULL
--        Placed AFTER the existing packaging_id column.
--        FK fk_pkg_readings_v2 → bd_packaging_v2(id) ON DELETE SET NULL.
--        Backing index idx_pkg_readings_v2.
--        Purpose: links in-filling sensor rows back to the v2 packaging run
--        they belong to.  ON DELETE SET NULL so archiving/tombstoning a v2
--        row doesn't cascade-delete sensor history.
--        Backfilled in a later migration.
--
-- WHY:
--   Establishing the FK links now (as NULLable columns with no data) lets
--   the schema reflect the intended relationships before the form cut-over
--   and backfill migrations, which keeps the derivation tree coherent at
--   each migration step.  Both columns are purely additive — safe to apply
--   against the live form with no disruption to existing read/write paths.
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_readings
--     DROP FOREIGN KEY fk_pkg_readings_v2,
--     DROP KEY idx_pkg_readings_v2,
--     DROP COLUMN packaging_v2_id;
--   ALTER TABLE bd_packaging_v2
--     DROP FOREIGN KEY fk_bdpv2_tank_read,
--     DROP KEY idx_bdpv2_tank_read,
--     DROP COLUMN tank_read_id_fk;
-- ============================================================================

-- ── 1. bd_packaging_v2: link to lot-day in-tank read ─────────────────────────
ALTER TABLE bd_packaging_v2
  ADD COLUMN `tank_read_id_fk` BIGINT UNSIGNED NULL
    COMMENT 'FK to bd_tank_readings.id — the lot-day in-tank (BBT pre-fill) CO₂/O₂ row for this packaging run. NULL until populated by form cut-over + backfill (mig 242). Old inheritance column tank_reading_session_id_fk remains for now.'
    AFTER `tank_reading_session_id_fk`;

ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT `fk_bdpv2_tank_read`
    FOREIGN KEY (`tank_read_id_fk`) REFERENCES `bd_tank_readings` (`id`)
    ON DELETE RESTRICT;

ALTER TABLE bd_packaging_v2
  ADD KEY `idx_bdpv2_tank_read` (`tank_read_id_fk`);

-- ── 2. bd_packaging_readings: back-link to v2 packaging run ──────────────────
ALTER TABLE bd_packaging_readings
  ADD COLUMN `packaging_v2_id` BIGINT UNSIGNED NULL
    COMMENT 'FK to bd_packaging_v2.id — the packaging run (v2) this in-filling sensor row belongs to. NULL until backfilled (mig 242). ON DELETE SET NULL: sensor history is preserved if the v2 run row is tombstoned.'
    AFTER `packaging_id`;

ALTER TABLE bd_packaging_readings
  ADD CONSTRAINT `fk_pkg_readings_v2`
    FOREIGN KEY (`packaging_v2_id`) REFERENCES `bd_packaging_v2` (`id`)
    ON DELETE SET NULL;

ALTER TABLE bd_packaging_readings
  ADD KEY `idx_pkg_readings_v2` (`packaging_v2_id`);
