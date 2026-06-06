-- 268_user_page_access.sql
-- Sparse per-user page override table.
-- Absence of a row = fall through to preset (or min_role if no preset).
-- granted=1 = explicit allow; granted=0 = explicit deny.
-- No NULL state — absence is the "undecided" / "inherit" signal.
-- ============================================================

CREATE TABLE `user_page_access` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id_fk`  INT UNSIGNED NOT NULL,
  `page_id_fk`  INT UNSIGNED NOT NULL,
  `granted`     TINYINT(1)   NOT NULL COMMENT '1=explicit allow, 0=explicit deny',
  `set_by_fk`   INT UNSIGNED NULL DEFAULT NULL,
  `set_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_page` (`user_id_fk`, `page_id_fk`),
  KEY `idx_user_id` (`user_id_fk`),
  KEY `idx_page_id` (`page_id_fk`),
  CONSTRAINT `fk_upa_user` FOREIGN KEY (`user_id_fk`)
      REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upa_page` FOREIGN KEY (`page_id_fk`)
      REFERENCES `ref_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upa_set_by` FOREIGN KEY (`set_by_fk`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('user_page_access', 'reference', 'allowed',
     'admin UI (future: per-user override matrix)',
     'Sparse per-user page overrides. Absence = inherit from preset (access_preset_id_fk) or min_role. granted=1 explicit allow, granted=0 explicit deny. Admin-only surface.');
