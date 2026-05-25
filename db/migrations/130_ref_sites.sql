-- db/migrations/130_ref_sites.sql
-- What: Create ref_sites — reference table for physical brewery sites / locations.
--       Seeded with one row (name='La Nébuleuse') with NULL addresses for the
--       operator to fill via the Réglages généraux admin page.
-- Why:  Site identity must not be hardcoded. The Réglages généraux page exposes
--       site management (add/edit/deactivate). Downstream features (delivery notes,
--       shipping, QA location attribution) will FK to this table.
-- Risk: LOW — new table, no FKs from existing tables yet.
-- Rollback: DROP TABLE IF EXISTS ref_sites;

CREATE TABLE IF NOT EXISTS ref_sites (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120)    NOT NULL                  COLLATE utf8mb4_unicode_ci
                  COMMENT 'Official site name (e.g. La Nébuleuse — Brasserie)',
  address_line  VARCHAR(255)    NULL                      COLLATE utf8mb4_unicode_ci
                  COMMENT 'Street address line (rue + numéro)',
  postal_code   VARCHAR(16)     NULL                      COLLATE utf8mb4_unicode_ci
                  COMMENT 'Postal / ZIP code',
  city          VARCHAR(120)    NULL                      COLLATE utf8mb4_unicode_ci
                  COMMENT 'City / locality',
  country       CHAR(2)         NOT NULL DEFAULT 'CH'     COLLATE utf8mb4_unicode_ci
                  COMMENT 'ISO 3166-1 alpha-2 country code',
  is_active     TINYINT(1)      NOT NULL DEFAULT 1
                  COMMENT '0 = deactivated site (soft delete)',
  notes         TEXT            NULL                      COLLATE utf8mb4_unicode_ci
                  COMMENT 'Free-form operator notes',
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    VARCHAR(64)     NULL                      COLLATE utf8mb4_unicode_ci
                  COMMENT 'Username of the admin who last modified this row',
  PRIMARY KEY  (id),
  UNIQUE KEY   uk_ref_sites_name (name),
  KEY          idx_ref_sites_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Physical brewery sites / locations. Managed via Réglages généraux (admin).';

-- ── Seed: one skeleton row — address fields intentionally NULL ─────────────────
-- The operator fills address details via the admin UI.
-- Do NOT guess the real address — leave it for Kouros to enter.

INSERT INTO ref_sites
  (name, address_line, postal_code, city, country, is_active, notes, updated_by)
VALUES
  ('La Nébuleuse', NULL, NULL, NULL, 'CH', 1, NULL, 'migration_130');

-- ── schema_meta row ───────────────────────────────────────────────────────────
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  (
    'ref_sites',
    'reference',
    'allowed',
    'web (admin)',
    'Manage via /modules/reglages-generaux.php (admin only). Add/edit/deactivate sites there.'
  )
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  upstream_hint      = VALUES(upstream_hint);
