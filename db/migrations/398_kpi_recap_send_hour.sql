-- ============================================================
-- Migration 398: Add send_hour_local to user_kpi_recap_subs
--
-- What:    Adds a send_hour_local column (0-23, Europe/Zurich)
--          so each operator can choose their preferred recap
--          email delivery hour.
--
-- Why:     Previously next_due_at was advanced by a naive +1 day
--          from the moment the user saved their prefs, so the
--          send time drifted with each write. The new column lets
--          send-kpi-recap.php anchor next_due_at to the user's
--          chosen hour in Europe/Zurich, stored as UTC in MySQL.
--
-- Risk:    DDL-only (no data mutation). DEFAULT 8 backfills the
--          8 existing rows to 08:00 Zurich without touching
--          any other column. Column addition is an online DDL
--          operation in InnoDB (instant algorithm where supported).
--
-- Rollback: ALTER TABLE user_kpi_recap_subs
--             DROP CONSTRAINT ukrs_send_hour_chk,
--             DROP COLUMN send_hour_local;
-- ============================================================

ALTER TABLE user_kpi_recap_subs
  ADD COLUMN send_hour_local TINYINT UNSIGNED NOT NULL DEFAULT 8
    COMMENT 'Heure d''envoi voulue, 0-23, fuseau Europe/Zurich'
    AFTER cadence;

ALTER TABLE user_kpi_recap_subs
  ADD CONSTRAINT ukrs_send_hour_chk CHECK (send_hour_local BETWEEN 0 AND 23);
