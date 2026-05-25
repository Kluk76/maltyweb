-- db/migrations/129_system_settings.sql
-- What: Create system_settings — a typed key/value store for org-level configuration
--       (date format, language, brewery name, locale prefs). Mirrors the structure of
--       commissioning_settings (migration 128) but targets system/org config rather than
--       process thresholds. Seeds the 'general' section with five initial settings.
-- Why:  Ingestion and normalization code must never hardcode date formats, locale, or
--       org identity. system_settings is the canonical read source via app/settings.php.
--       Establishes the convention that prevents the racking date-swap class of bug.
-- Risk: LOW — new table, no existing consumers. Seed rows only.
-- Rollback: DROP TABLE IF EXISTS system_settings;

CREATE TABLE IF NOT EXISTS system_settings (
  id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  section        VARCHAR(64)    NOT NULL                  COLLATE utf8mb4_unicode_ci
                   COMMENT 'Logical grouping (e.g. general, ingest, display)',
  key_name       VARCHAR(128)   NOT NULL                  COLLATE utf8mb4_unicode_ci
                   COMMENT 'Setting identifier within the section (snake_case)',
  label_fr       VARCHAR(255)   NOT NULL                  COLLATE utf8mb4_unicode_ci
                   COMMENT 'Operator-facing French label shown in the admin UI',
  description_fr TEXT           NULL                      COLLATE utf8mb4_unicode_ci
                   COMMENT 'Longer explanation of what this setting controls',
  value_text     VARCHAR(255)   NULL                      COLLATE utf8mb4_unicode_ci
                   COMMENT 'Text value. NULL if this is a numeric setting.',
  value_num      DECIMAL(10,4)  NULL
                   COMMENT 'Numeric value (thresholds, counts, flags). NULL if text setting.',
  unit_fr        VARCHAR(32)    NULL                      COLLATE utf8mb4_unicode_ci
                   COMMENT 'Display unit label for numeric settings (e.g. "jours")',
  default_text   VARCHAR(255)   NULL                      COLLATE utf8mb4_unicode_ci
                   COMMENT 'Fallback value when value_text is NULL',
  default_num    DECIMAL(10,4)  NULL
                   COMMENT 'Fallback value when value_num is NULL',
  is_active      TINYINT(1)     NOT NULL DEFAULT 1
                   COMMENT '0 = soft-deleted / disabled',
  updated_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by     VARCHAR(64)    NULL                      COLLATE utf8mb4_unicode_ci
                   COMMENT 'Username of the admin who last modified this setting',
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_system_settings_section_key (section, key_name),
  KEY          idx_system_settings_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Org-level system configuration. Read via app/settings.php::system_setting().';

-- CHECK: not both value_num and value_text set simultaneously
ALTER TABLE system_settings
  ADD CONSTRAINT system_settings_chk_value_exclusive CHECK (
    NOT (value_num IS NOT NULL AND value_text IS NOT NULL)
  );

-- ── Seed: section='general' ───────────────────────────────────────────────────
-- These five settings bootstrap the system. All read via system_setting($key).
-- Do NOT edit these seeds to change live values — use the Réglages généraux page.

INSERT INTO system_settings
  (section, key_name, label_fr, description_fr, value_text, value_num, unit_fr, default_text, default_num, updated_by)
VALUES
  (
    'general', 'date_format',
    'Format de date (affichage)',
    'Format PHP utilisé pour afficher les dates dans l''interface (ex: d/m/Y = jj/mm/aaaa, Y-m-d = aaaa-mm-jj). Ne modifie pas le stockage MySQL.',
    'd/m/Y', NULL, NULL, 'd/m/Y', NULL, 'migration_129'
  ),
  (
    'general', 'date_parse_dayfirst',
    'Dates saisies jour-d''abord (jj/mm/aaaa)',
    'Lorsque activé (1), les dates saisies par les opérateurs sont interprétées en format européen jj/mm/aaaa. Désactiver (0) pour le format ISO aaaa-mm-jj. Influe sur l''analyse des dates dans les imports et formulaires.',
    NULL, 1.0000, NULL, NULL, 1.0000, 'migration_129'
  ),
  (
    'general', 'time_format',
    'Format d''heure (affichage)',
    'Format PHP pour l''affichage de l''heure. H:i = 24h (14:30), h:i A = 12h (02:30 PM).',
    'H:i', NULL, NULL, 'H:i', NULL, 'migration_129'
  ),
  (
    'general', 'language',
    'Langue de l''interface',
    'Code de langue ISO 639-1 (fr, de, en). Actuellement utilisé pour l''affichage ; l''internationalisation complète est prévue.',
    'fr', NULL, NULL, 'fr', NULL, 'migration_129'
  ),
  (
    'general', 'brewery_name',
    'Nom de la brasserie',
    'Nom officiel de la brasserie, utilisé dans les entêtes de rapports et documents générés.',
    'La Nébuleuse', NULL, NULL, 'La Nébuleuse', NULL, 'migration_129'
  );

-- ── schema_meta row ───────────────────────────────────────────────────────────
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  (
    'system_settings',
    'config',
    'allowed',
    'manual/web (admin)',
    'Edit via /modules/reglages-generaux.php (admin only). Changes take effect immediately on next page load. Read via app/settings.php::system_setting().'
  )
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  upstream_hint      = VALUES(upstream_hint);
