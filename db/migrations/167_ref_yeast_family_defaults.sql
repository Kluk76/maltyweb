-- db/migrations/167_ref_yeast_family_defaults.sql
-- What: Create ref_yeast_family_defaults lookup table and seed 5 process-family rows.
--       One row per yeast family (ale/lager/non_alcool/spontane/mixte). Holds the
--       brewery-wide defaults for garde-min, fermentation temperature range, and
--       production status. Per-recipe overrides live in ref_recipes (migration 168).
-- Why:  The Saisie Transferts eligibility gate computes:
--         COALESCE(ref_recipes.garde_days_min_override,
--                  ref_yeast_family_defaults.garde_days_min, <fallback floor>)
--       This table is the middle tier of that three-level COALESCE.
-- Risk: LOW — new table, no existing consumers. Seed rows use INSERT … ON DUPLICATE KEY
--       so the file is safely idempotent in case of interrupted apply (anti-pattern #20).
-- Rollback: DROP TABLE IF EXISTS ref_yeast_family_defaults;

-- ── A. Create table ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ref_yeast_family_defaults` (
  `family`        ENUM('ale','lager','non_alcool','spontane','mixte') COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Process-family key (PK). Matches ref_yeast_strains.family ENUM.',
  `label_fr`      VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'Operator-facing French label shown in forms and dashboards.',
  `garde_days_min` INT UNSIGNED NULL
    COMMENT 'Brewery-wide minimum lagering time after cold-crash (days). NULL = not applicable (non-produced families).',
  `ferm_temp_min` DECIMAL(4,1) NULL
    COMMENT 'Lower bound of typical fermentation temperature range (°C). Operator sets on Biochimie page; NULL until configured.',
  `ferm_temp_max` DECIMAL(4,1) NULL
    COMMENT 'Upper bound of typical fermentation temperature range (°C). Operator sets on Biochimie page; NULL until configured.',
  `is_produced`   TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = currently produced in-house. 0 = theoretical row (spontanée, mixte) kept for completeness.',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '0 = soft-deleted / retired family.',
  `updated_at`    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    COMMENT 'Automatically updated on any row change.',
  `updated_by`    VARCHAR(64) COLLATE utf8mb4_unicode_ci NULL
    COMMENT 'Username or script that last modified this row.',
  PRIMARY KEY (`family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Brewery-wide yeast-family process defaults (garde-min, ferm temps). Per-recipe overrides live in ref_recipes.';

-- ── B. Seed rows ──────────────────────────────────────────────────────────────
-- Idempotent: ON DUPLICATE KEY UPDATE re-applies the seed values safely.
-- ferm_temp_min/max left NULL — operator will fill via the Biochimie page later.
-- garde_days_min for spontane/mixte = NULL (not currently produced, garde not defined).

INSERT INTO `ref_yeast_family_defaults`
  (`family`, `label_fr`, `garde_days_min`, `ferm_temp_min`, `ferm_temp_max`, `is_produced`, `is_active`, `updated_by`)
VALUES
  ('ale',        'Ale',          7,    NULL, NULL, 1, 1, 'migration_167'),
  ('lager',      'Lager',       14,    NULL, NULL, 1, 1, 'migration_167'),
  ('non_alcool', 'Non-Alcool',   7,    NULL, NULL, 1, 1, 'migration_167'),
  ('spontane',   'Spontanée',   NULL,  NULL, NULL, 0, 1, 'migration_167'),
  ('mixte',      'Mixte',       NULL,  NULL, NULL, 0, 1, 'migration_167')
ON DUPLICATE KEY UPDATE
  `label_fr`      = VALUES(`label_fr`),
  `garde_days_min` = VALUES(`garde_days_min`),
  `is_produced`   = VALUES(`is_produced`),
  `is_active`     = VALUES(`is_active`),
  `updated_by`    = VALUES(`updated_by`);

-- ── C. schema_meta classification ────────────────────────────────────────────
-- ref_yeast_family_defaults is a lookup table: small, operator-maintained,
-- correctable directly via web UI (corrections_policy = 'allowed').

INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  (
    'ref_yeast_family_defaults',
    'reference',
    'allowed',
    'manual/web (admin)',
    'Edit via Biochimie / Salle des Machines admin page. Changes apply immediately to next eligibility-gate evaluation.'
  )
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  upstream_hint      = VALUES(upstream_hint);
