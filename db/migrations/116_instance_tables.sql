-- db/migrations/116_instance_tables.sql
-- What: Create brewery-instance tables: ref_brewhouse_vessels, ref_process_machines,
--       dbc_container_types back-link dimension table for filler ↔ container M:N
--       (ref_filler_containers), ref_container_mi (container→primary MI binding, temporal),
--       and add catalog_id back-links to ref_cct/bbt/yt.
--       Also creates ref_packaging_format_mis (format → secondary MIs bridge).
-- Why:  Materialises the Salle des Machines data model. Instance tables gain catalog_id
--       provenance FK (back-link only — brewery's row is authoritative once set).
--       SCD2 pair (effective_from/effective_until) on all four operational settings tables
--       so Capacités changes affect future data only; past epochs are archived.
--       Container→MI binding lives in ref_container_mi (per-brewery + COGS-critical + temporal)
--       NOT on the shippable dbc_container_types catalog (corrections_policy='blocked').
-- Risk: New tables + nullable column adds on existing ref_cct/bbt/yt/ref_packaging_formats.
--       No row data changed.
-- Rollback: DROP TABLE ref_container_mi, ref_filler_containers, ref_process_machines,
--           ref_brewhouse_vessels, ref_packaging_format_mis;
--           then remove catalog_id cols from ref_cct/bbt/yt/ref_packaging_formats.
--
-- SCD2 update pattern (documented, not executed here):
--   A settings change closes the current row:  UPDATE <table> SET effective_until=NOW() WHERE id=<old>;
--   Then inserts a new row with effective_from=NOW(), effective_until='9999-12-31 23:59:59'.
--   The UNIQUE key on (natural_key, effective_until) enforces one current row per natural key
--   (9999 sentinel is NOT NULL, so it participates in UNIQUE — no NULL bypass needed).
--   The created_at/updated_at columns are row-level audit; they coexist with the SCD2 pair.

-- -----------------------------------------------------------------------
-- ref_brewhouse_vessels  (hot-side vessels — HLT, CLT, mash, lauter, buffer, kettle, whirlpool)
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_brewhouse_vessels (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vessel_type      ENUM('hlt','clt','mash','lauter','buffer','kettle','whirlpool') NOT NULL,
  number           INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Supports >1 of same type (e.g. 2× CLT)',
  name             VARCHAR(96)  NULL COLLATE utf8mb4_unicode_ci,
  volume_hl        DECIMAL(8,2) NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  catalog_id       INT UNSIGNED NULL COMMENT 'FK to dbc_vessel_types.id (provenance, not live sync)',
  notes            TEXT         NULL COLLATE utf8mb4_unicode_ci,
  effective_from   DATETIME     NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT 'SCD2 epoch start; 1970 = retroactive commissioning baseline',
  effective_until  DATETIME     NOT NULL DEFAULT '9999-12-31 23:59:59' COMMENT 'SCD2 sentinel; 9999 = current row',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_vessel_type_number_until (vessel_type, number, effective_until),
  CONSTRAINT fk_rbv_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_vessel_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- ref_process_machines  (centrifuge, KZE, fillers, cartoner)
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_process_machines (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_type     ENUM('centrifuge','kze','filler_bottle','filler_can','filler_keg','cartoner') NOT NULL,
  name             VARCHAR(96)  NULL COLLATE utf8mb4_unicode_ci,
  throughput_hl_h  DECIMAL(8,2) NULL COMMENT 'Centrifuge/KZE: débit in hl/h',
  speed_units_h    INT UNSIGNED NULL COMMENT 'Fillers: speed in units/h (bottles, cans, kegs)',
  temp_c           DECIMAL(5,2) NULL COMMENT 'KZE: flash-pasteurisation temperature',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  catalog_id       INT UNSIGNED NULL COMMENT 'FK to dbc_equipment_types.id (provenance)',
  notes            TEXT         NULL COLLATE utf8mb4_unicode_ci,
  effective_from   DATETIME     NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT 'SCD2 epoch start; 1970 = retroactive commissioning baseline',
  effective_until  DATETIME     NOT NULL DEFAULT '9999-12-31 23:59:59' COMMENT 'SCD2 sentinel; 9999 = current row',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_machine_type_name_until (machine_type, name, effective_until),
  CONSTRAINT fk_rpm_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_equipment_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- ref_filler_containers  (M:N: which container types each filler machine handles)
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_filler_containers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_id       INT UNSIGNED NOT NULL COMMENT 'FK to ref_process_machines.id',
  container_id     INT UNSIGNED NOT NULL COMMENT 'FK to dbc_container_types.id',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  effective_from   DATETIME     NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT 'SCD2 epoch start; 1970 = retroactive commissioning baseline',
  effective_until  DATETIME     NOT NULL DEFAULT '9999-12-31 23:59:59' COMMENT 'SCD2 sentinel; 9999 = current row',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_filler_container_until (machine_id, container_id, effective_until),
  CONSTRAINT fk_rfc_machine   FOREIGN KEY (machine_id)   REFERENCES ref_process_machines(id) ON DELETE CASCADE,
  CONSTRAINT fk_rfc_container FOREIGN KEY (container_id) REFERENCES dbc_container_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- ref_packaging_format_mis  (format → secondary MI bill-of-materials bridge)
-- format_id is BIGINT UNSIGNED to match ref_packaging_formats.id (known drift)
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_packaging_format_mis (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  format_id        BIGINT UNSIGNED NOT NULL COMMENT 'FK to ref_packaging_formats.id (BIGINT UNSIGNED — known drift)',
  mi_id_fk         INT UNSIGNED    NOT NULL COMMENT 'FK to ref_mi.id',
  role             ENUM('container','closure','label','box','divider','other') NOT NULL,
  qty_per_unit     DECIMAL(14,6)   NOT NULL,
  is_active        TINYINT(1)      NOT NULL DEFAULT 1,
  effective_from   DATETIME        NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT 'SCD2 epoch start; 1970 = retroactive commissioning baseline',
  effective_until  DATETIME        NOT NULL DEFAULT '9999-12-31 23:59:59' COMMENT 'SCD2 sentinel; 9999 = current row',
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_format_mi_role_until (format_id, mi_id_fk, role, effective_until),
  KEY idx_format (format_id),
  KEY idx_mi (mi_id_fk),
  CONSTRAINT fk_rfm_format FOREIGN KEY (format_id) REFERENCES ref_packaging_formats(id),
  CONSTRAINT fk_rfm_mi     FOREIGN KEY (mi_id_fk)  REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- ref_container_mi  (container → primary MI binding; per-brewery + COGS-critical + temporal)
-- Lives here (instance), NOT on dbc_container_types (catalog, corrections_policy='blocked').
-- MI bindings are seeded in a post-operator-signoff migration (118+),
-- pending capacites-mi-binding-preview-2026-05-24.md operator review.
-- FK type notes: dbc_container_types.id = INT UNSIGNED; ref_mi.id = INT UNSIGNED — both match.
-- -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ref_container_mi (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  container_id     INT UNSIGNED NOT NULL COMMENT 'FK to dbc_container_types.id',
  mi_id_fk         INT UNSIGNED NOT NULL COMMENT 'FK to ref_mi.id',
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  effective_from   DATETIME     NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT 'SCD2 epoch start; 1970 = retroactive commissioning baseline',
  effective_until  DATETIME     NOT NULL DEFAULT '9999-12-31 23:59:59' COMMENT 'SCD2 sentinel; 9999 = current row',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_container_mi_until (container_id, effective_until),
  KEY idx_rcm_mi (mi_id_fk),
  CONSTRAINT fk_rcm_container FOREIGN KEY (container_id) REFERENCES dbc_container_types(id),
  CONSTRAINT fk_rcm_mi        FOREIGN KEY (mi_id_fk)     REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Add catalog_id back-link columns to existing instance tables
-- (MySQL 8: no ADD COLUMN IF NOT EXISTS — idempotency via schema_migrations)
-- -----------------------------------------------------------------------
ALTER TABLE ref_cct
  ADD COLUMN catalog_id INT UNSIGNED NULL COMMENT 'FK to dbc_vessel_types.id (provenance)',
  ADD CONSTRAINT fk_rcct_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_vessel_types(id);

ALTER TABLE ref_bbt
  ADD COLUMN catalog_id INT UNSIGNED NULL COMMENT 'FK to dbc_vessel_types.id (provenance)',
  ADD CONSTRAINT fk_rbbt_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_vessel_types(id);

ALTER TABLE ref_yt
  ADD COLUMN catalog_id INT UNSIGNED NULL COMMENT 'FK to dbc_vessel_types.id (provenance)',
  ADD CONSTRAINT fk_ryt_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_vessel_types(id);

ALTER TABLE ref_packaging_formats
  ADD COLUMN catalog_id INT UNSIGNED NULL COMMENT 'FK to dbc_packaging_format_templates.id (provenance)',
  ADD CONSTRAINT fk_rpf_catalog FOREIGN KEY (catalog_id) REFERENCES dbc_packaging_format_templates(id);

-- -----------------------------------------------------------------------
-- schema_meta registration
-- -----------------------------------------------------------------------
INSERT IGNORE INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('ref_brewhouse_vessels',    'reference', 'allowed',        'manual/web',                   NULL),
  ('ref_process_machines',     'reference', 'allowed',        'manual/web',                   NULL),
  ('ref_filler_containers',    'reference', 'allowed',        'manual/web',                   NULL),
  ('ref_packaging_format_mis', 'reference', 'allowed',        'manual/web',                   'Seeded after operator review of capacites-mi-binding-preview-2026-05-24.md'),
  ('ref_container_mi',         'reference', 'allowed',        'manual/web',                   'Container→primary MI binding; seeded after operator review of capacites-mi-binding-preview-2026-05-24.md');
