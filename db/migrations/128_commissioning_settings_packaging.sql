-- db/migrations/128_commissioning_settings_packaging.sql
-- What: (A) Create commissioning_settings key-value store for admin-tunable process
--       thresholds. First use: packaging eligibility gate (days after racking).
--       (B) Extend bd_packaging_v2 with hors_process_flag + hors_process_reason
--       for the "Choix Hors Process" manager/admin override (Task 2).
-- Why:  Operator mandate — process thresholds must never be hardcoded. Any
--       condition that gates whether an operator can proceed with a process step
--       must live in a tunable commissioning setting. Hardcoded constants are a
--       process-governance violation (decided 2026-05-25).
-- Risk: LOW — new table (no existing consumers); ADD COLUMN INSTANT on bd_packaging_v2
--       (nullable with default, no existing rows affected).
-- Rollback:
--   ALTER TABLE bd_packaging_v2
--     DROP COLUMN hors_process_reason,
--     DROP COLUMN hors_process_flag;
--   DROP TABLE IF EXISTS commissioning_settings;

-- ── A. commissioning_settings ─────────────────────────────────────────────────
-- Generic key-value store for admin-tunable commissioning parameters.
-- Each row is one named setting in one section (e.g. section='packaging').
-- Typed as DECIMAL + VARCHAR for value (use value_num when the setting is numeric,
-- value_text when free-form; exactly one is non-NULL by CHECK).
-- SCD-style updated_at + updated_by for lightweight audit trail.
-- schema_meta row inserted in this migration.

CREATE TABLE IF NOT EXISTS commissioning_settings (
  id           INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  section      VARCHAR(64)         NOT NULL COLLATE utf8mb4_unicode_ci
                 COMMENT 'Logical grouping (e.g. packaging, fermentation, racking)',
  key_name     VARCHAR(128)        NOT NULL COLLATE utf8mb4_unicode_ci
                 COMMENT 'Setting identifier within the section (snake_case)',
  label_fr     VARCHAR(255)        NOT NULL COLLATE utf8mb4_unicode_ci
                 COMMENT 'Operator-facing French label shown in the admin UI',
  description_fr TEXT              NULL     COLLATE utf8mb4_unicode_ci
                 COMMENT 'Longer explanation of what this setting controls',
  value_num    DECIMAL(10,4)       NULL
                 COMMENT 'Numeric value (use for thresholds, counts, ratios). NULL if text setting.',
  value_text   VARCHAR(255)        NULL     COLLATE utf8mb4_unicode_ci
                 COMMENT 'Text value. NULL if numeric setting.',
  unit_fr      VARCHAR(32)         NULL     COLLATE utf8mb4_unicode_ci
                 COMMENT 'Display unit (e.g. "jours", "HL", "%") — for numeric settings',
  default_num  DECIMAL(10,4)       NULL
                 COMMENT 'Fallback value when value_num is NULL or the setting is missing',
  is_active    TINYINT(1)          NOT NULL DEFAULT 1
                 COMMENT '0 = soft-deleted / disabled',
  updated_at   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   VARCHAR(64)         NULL     COLLATE utf8mb4_unicode_ci
                 COMMENT 'Username of the admin who last changed this setting',
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_commissioning_section_key (section, key_name),
  KEY          idx_commissioning_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin-tunable commissioning thresholds. One row per process parameter.';

-- CHECK: exactly one of value_num / value_text is non-NULL (or both NULL = not yet set)
-- We allow both NULL (unset state); enforce "not both non-NULL":
ALTER TABLE commissioning_settings
  ADD CONSTRAINT commissioning_settings_chk_value_exclusive CHECK (
    NOT (value_num IS NOT NULL AND value_text IS NOT NULL)
  );

-- ── A.1 Seed: packaging_min_days_after_racking ────────────────────────────────
-- Default = 1 (matching the former hardcoded constant PACKAGING_MIN_DAYS_AFTER_RACKING).
-- The form reads this at GET time. Missing row → falls back to default_num (1).

INSERT INTO commissioning_settings
  (section, key_name, label_fr, description_fr, value_num, value_text, unit_fr, default_num, updated_by)
VALUES
  (
    'packaging',
    'min_days_after_racking',
    'Délai minimum après soutirage',
    'Nombre de jours minimum qu''un lot doit attendre après son soutirage avant d''être éligible au conditionnement. Réduit à 0 pour désactiver la restriction temporelle (déconseillé hors test). Valeur habituelle : 1 jour.',
    1.0000,
    NULL,
    'jours',
    1.0000,
    'migration_128'
  );

-- ── A.2 schema_meta row for commissioning_settings ───────────────────────────
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  (
    'commissioning_settings',
    'config',
    'allowed',
    'manual/web (admin)',
    'Edit via /admin/settings/packaging.php. Changes take effect immediately on next form load.'
  )
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  upstream_hint      = VALUES(upstream_hint);

-- ── B. Extend bd_packaging_v2 with hors_process_flag / hors_process_reason ────
-- Tracks whether a packaging record was created using the "Choix Hors Process"
-- manager/admin override (bypasses the days-after-racking eligibility gate).
-- hors_process_flag = 1 → override was active when this row was submitted.
-- hors_process_reason = operator-provided justification (optional free text).
-- Both nullable-to-start for INSTANT DDL; flag has default 0 so new rows without
-- the override path always carry 0 (no ambiguity about "missing" vs "not overridden").
--
-- ALGORITHM=INSTANT: adding nullable columns at end of table, MySQL 8 default.

ALTER TABLE bd_packaging_v2
  ADD COLUMN hors_process_flag   TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT 'Set to 1 when this row was created via the Choix Hors Process override (manager/admin only). Normal path always 0.',
  ADD COLUMN hors_process_reason VARCHAR(255)  NULL COLLATE utf8mb4_unicode_ci
    COMMENT 'Optional free-text justification entered by the manager/admin when using the override. NULL on normal rows.';

-- Index on the flag for operational queries (find all override-sourced rows):
ALTER TABLE bd_packaging_v2
  ADD KEY idx_bdpv2_hors_process (hors_process_flag);

-- ── schema_meta: no update needed ────────────────────────────────────────────
-- bd_packaging_v2 is already classified (source / allowed). New columns on an
-- existing table do not need a schema_meta update.
