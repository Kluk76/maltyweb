-- 277_user_kpi_recap_subs.sql
-- Per-user KPI recap email subscription.
-- Absence of a row = no recap for that user (sparse — minimize-NULL doctrine).
-- cadence controls how often the recap fires; next_due_at drives the hourly cron gate.
-- ============================================================

CREATE TABLE `user_kpi_recap_subs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id_fk`    INT UNSIGNED NOT NULL,
  `cadence`       ENUM('daily','weekly','monthly') NOT NULL,
  `last_sent_at`  DATETIME NULL DEFAULT NULL,
  `next_due_at`   DATETIME NULL DEFAULT NULL
                  COMMENT 'Cron checks: WHERE next_due_at <= NOW() AND is_active = 1',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id_fk`),
  KEY `idx_next_due` (`next_due_at`, `is_active`),
  CONSTRAINT `fk_ukrs_user` FOREIGN KEY (`user_id_fk`)
      REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta: operational (like user_kpi_selections mig 270)
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('user_kpi_recap_subs', 'reference', 'allowed',
     'scripts/send-kpi-recap.php (cron) + mon-tableau.php (user self-service cadence)',
     'Per-user KPI email subscription. Absence = no recap. Cadence set by user on Mon tableau. next_due_at computed by send-kpi-recap.php after each send. Admin can edit directly.');
