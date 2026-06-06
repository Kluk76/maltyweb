-- 270_user_kpi_selections.sql
-- Sparse per-user KPI selection table for the personal dashboard (mon-tableau.php).
-- Absence of a row = tracker not on this user's dashboard.
-- position controls the display order within a user's selection.
-- ============================================================

CREATE TABLE `user_kpi_selections` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id_fk`     INT UNSIGNED NOT NULL,
  `tracker_id_fk`  INT UNSIGNED NOT NULL,
  `position`       INT          NOT NULL DEFAULT 0
                                COMMENT 'Display order within this user s dashboard',
  `added_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_tracker` (`user_id_fk`, `tracker_id_fk`),
  KEY `idx_user_id` (`user_id_fk`),
  KEY `idx_tracker_id` (`tracker_id_fk`),
  CONSTRAINT `fk_uks_user` FOREIGN KEY (`user_id_fk`)
      REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uks_tracker` FOREIGN KEY (`tracker_id_fk`)
      REFERENCES `ref_kpi_trackers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('user_kpi_selections', 'reference', 'allowed',
     'mon-tableau.php (user self-service picker)',
     'Sparse per-user KPI selections for the personal dashboard. Absence = tracker not on this user dashboard. position = display order. Admin can edit; users manage via mon-tableau picker.');
