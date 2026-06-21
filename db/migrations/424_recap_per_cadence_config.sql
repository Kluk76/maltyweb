-- 424_recap_per_cadence_config.sql
-- Option A: independent daily/weekly/monthly recaps per user.
-- Re-key user_kpi_recap_subs to (user_id_fk, cadence); add weekly day-of-week;
-- add a per-cadence widget-selection table; backfill existing subscribers.

ALTER TABLE user_kpi_recap_subs
  DROP INDEX uniq_user,
  ADD UNIQUE KEY uniq_user_cadence (user_id_fk, cadence);

ALTER TABLE user_kpi_recap_subs
  ADD COLUMN send_dow TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Jour d''envoi ISO 1=Lun..7=Dim (hebdomadaire uniquement)'
    AFTER send_hour_local;

ALTER TABLE user_kpi_recap_subs
  ADD CONSTRAINT ukrs_send_dow_chk CHECK (send_dow IS NULL OR send_dow BETWEEN 1 AND 7);

CREATE TABLE user_recap_tracker_selections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id_fk    INT UNSIGNED NOT NULL,
  cadence       ENUM('daily','weekly','monthly') NOT NULL,
  tracker_id_fk INT UNSIGNED NOT NULL,
  position      INT NOT NULL DEFAULT 0,
  added_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_cadence_tracker (user_id_fk, cadence, tracker_id_fk),
  KEY idx_urts_user_cadence (user_id_fk, cadence),
  CONSTRAINT fk_urts_user    FOREIGN KEY (user_id_fk)    REFERENCES users(id)            ON DELETE CASCADE,
  CONSTRAINT fk_urts_tracker FOREIGN KEY (tracker_id_fk) REFERENCES ref_kpi_trackers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: each existing subscriber keeps their current dashboard widgets
-- as the widget set for their existing cadence (no behaviour change for them).
INSERT INTO user_recap_tracker_selections (user_id_fk, cadence, tracker_id_fk, position)
SELECT s.user_id_fk, s.cadence, k.tracker_id_fk, k.position
  FROM user_kpi_recap_subs s
  JOIN user_kpi_selections k ON k.user_id_fk = s.user_id_fk;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES ('user_recap_tracker_selections','reference','allowed',
  'mon-tableau.php (user self-service) + send-kpi-recap.php (reader)',
  'Per-user, per-cadence recap widget selection (Option A). Absence for a cadence = that recap sends nothing. Independent of on-screen user_kpi_selections.');
