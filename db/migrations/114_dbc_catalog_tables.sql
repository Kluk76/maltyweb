-- db/migrations/114_dbc_catalog_tables.sql
-- What: Create dbc_catalog_version and the four DBCommissioning catalog tables:
--       dbc_container_types, dbc_packaging_format_templates,
--       dbc_equipment_types, dbc_vessel_types.
-- Why:  First migration of the transmissible "Salle des Machines / Capacités" catalog.
--       Catalog rows are seeded in 115_dbc_seed_v1.sql; instance tables + backlinks follow.
-- Risk: New tables only — no existing rows touched.
-- Rollback: DROP TABLE dbc_vessel_types, dbc_equipment_types,
--           dbc_packaging_format_templates, dbc_container_types, dbc_catalog_version;

CREATE TABLE IF NOT EXISTS dbc_catalog_version (
  catalog_version INT UNSIGNED NOT NULL,
  shipped_at      DATE NOT NULL,
  notes           VARCHAR(255) NULL,
  PRIMARY KEY (catalog_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dbc_container_types (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  container_code  VARCHAR(32)  NOT NULL COMMENT 'Stable catalog key e.g. BOT_GLASS_33',
  display_name    VARCHAR(96)  NOT NULL,
  material        ENUM('glass','aluminium','pet','steel','other') NOT NULL,
  vessel_class    ENUM('bottle','can','keg','liner','other') NOT NULL,
  volume_l        DECIMAL(8,4) NULL COMMENT 'Physical fill volume in litres',
  hl_per_unit     DECIMAL(10,6) NULL COMMENT 'volume_l/100; matches ref_packaging_formats.hl_per_unit scale',
  run_type        ENUM('bot','can','can33','keg','cuv') NULL COMMENT 'Maps to ref_packaging_formats.run_type for retrofit',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      INT NOT NULL DEFAULT 0,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_container_code (container_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dbc_packaging_format_templates (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_code    VARCHAR(32)  NOT NULL COMMENT 'Stable catalog key e.g. BOX24_33, 6X4_33',
  display_name     VARCHAR(96)  NOT NULL,
  container_code   VARCHAR(32)  NULL     COMMENT 'FK-by-code to dbc_container_types.container_code; NULL for composites',
  units_per_format INT UNSIGNED NULL     COMMENT '24, 6x4=24, 12, 1; NULL for composites',
  is_composite     TINYINT(1)   NOT NULL DEFAULT 0,
  default_run_type ENUM('bot','can','can33','keg','cuv') NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_template_code (template_code),
  KEY idx_container (container_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dbc_equipment_types (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipment_code      VARCHAR(32)  NOT NULL COMMENT 'Stable catalog key e.g. CENTRIFUGE, BOTTLE_FILLER',
  display_name        VARCHAR(96)  NOT NULL,
  machine_type        ENUM('centrifuge','kze','filler_bottle','filler_can','filler_keg','cartoner') NOT NULL,
  process_stage       ENUM('cellar','packaging') NOT NULL,
  has_throughput_hl_h TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'centrifuge, kze',
  has_speed_units_h   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'fillers',
  has_temp_c          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'kze',
  takes_containers    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'fillers reference containers; cartoner derives',
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order          INT          NOT NULL DEFAULT 0,
  notes               VARCHAR(255) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_equipment_code (equipment_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dbc_vessel_types (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vessel_code      VARCHAR(32)  NOT NULL COMMENT 'Stable catalog key e.g. HLT, CCT, BBT',
  display_name     VARCHAR(96)  NOT NULL,
  process_stage    ENUM('water','brewhouse','fermentation','maturation') NOT NULL,
  target_ref_table ENUM('ref_brewhouse_vessels','ref_yt','ref_cct','ref_bbt') NOT NULL
                   COMMENT 'Which instance table commissioning inserts into',
  is_numbered      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=supports >1 (CCT/BBT/YT); 0=single instance',
  default_count    INT UNSIGNED NULL COMMENT 'Expected count at La Neb: HLT=1, CLT=2, YT=3 etc.',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order       INT          NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_vessel_code (vessel_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta registration for all four catalog tables + version table
INSERT IGNORE INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('dbc_catalog_version',              'config',    'blocked',        'db/migrations/115_dbc_seed_v1.sql',    'Bump by adding a new seed migration; never hand-edit'),
  ('dbc_container_types',              'reference', 'blocked',        'db/migrations/115_dbc_seed_v1.sql',    'Catalog data — extend via new seed migration, not direct INSERT'),
  ('dbc_packaging_format_templates',   'reference', 'blocked',        'db/migrations/115_dbc_seed_v1.sql',    'Catalog data — extend via new seed migration, not direct INSERT'),
  ('dbc_equipment_types',              'reference', 'blocked',        'db/migrations/115_dbc_seed_v1.sql',    'Catalog data — extend via new seed migration, not direct INSERT'),
  ('dbc_vessel_types',                 'reference', 'blocked',        'db/migrations/115_dbc_seed_v1.sql',    'Catalog data — extend via new seed migration, not direct INSERT');
