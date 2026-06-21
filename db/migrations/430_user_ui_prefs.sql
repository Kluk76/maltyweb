-- 430_user_ui_prefs.sql
-- Generic per-user UI preference key/value store (reusable beyond first consumer).
-- First consumer: sku_class_filter — lets each operator persist their Toutes|Neb|Contract
-- SKU view preference without polluting the users table with one-off columns.
--
-- Design: absence of a row means "use the default" — callers supply their own fallback.
-- ON DELETE CASCADE: user deletion automatically purges all their prefs.
-- UNIQUE(user_id_fk, pref_key): enforces one value per pref per user; upsert-safe.
--
-- PDO-safe: no standalone result-returning SELECT statements.
-- MySQL 8 syntax only (no ADD COLUMN IF NOT EXISTS — MariaDB-only).
-- Audit: no audit_row_revisions row needed for DDL migrations.

CREATE TABLE `user_ui_prefs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id_fk`  INT UNSIGNED NOT NULL,
  `pref_key`    VARCHAR(64)  NOT NULL,
  `pref_value`  VARCHAR(255) NOT NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_pref` (`user_id_fk`, `pref_key`),
  CONSTRAINT `fk_uiprefs_user`
    FOREIGN KEY (`user_id_fk`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES
  ('user_ui_prefs',
   'config', 'allowed',
   '/api/ui-pref.php (user self-service UI prefs)',
   'Generic per-user UI preference key/value store. Absence = use default. First consumer: sku_class_filter (Toutes|Neb|Contract). Admin may edit directly.',
   NULL)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
