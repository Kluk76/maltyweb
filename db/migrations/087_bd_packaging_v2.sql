-- db/migrations/087_bd_packaging_v2.sql
-- What: Create bd_packaging_v2 — clean upload target for PackagingData_Events.
--       38 typed cols, natural-key + row_hash double-guard, 5 FKs, 1 CHECK,
--       5 perf indexes. Replaces legacy bd_packaging (no data copied).
-- Why:  Phase 1.A of BSF→MySQL clean-break roadmap. Provides the canonical
--       MySQL table for the MaltyWeb /packaging operator form (Phase 6.A).
--       DDL ported from DBA Phase 2 audit §4B lines 442-543 (2026-05-23).
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_packaging_v2;

CREATE TABLE IF NOT EXISTS bd_packaging_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Identity (source provenance)
  source_sheet_row_index INT UNSIGNED NULL  COMMENT 'NULL for parallel rows; not used for dedup',
  row_origin ENUM('main','parallel') NOT NULL DEFAULT 'main',
  row_hash CHAR(64) NOT NULL,
  is_tombstoned TINYINT(1) NOT NULL DEFAULT 0,

  -- Timestamps
  submitted_at DATETIME(6) NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Beer identity (TEXT — source provenance; FK columns below are the canonical refs)
  neb_beer VARCHAR(128) NULL,
  neb_batch VARCHAR(32) NULL,
  neb_dlc VARCHAR(32) NULL,
  contract_beer VARCHAR(128) NULL,
  contract_batch VARCHAR(32) NULL,

  -- FK identifiers (typed, resolved)
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable, NOT NULL after backfill',
  sku_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_skus.id; derived from (recipe_id_fk, nebuleuse_format_suffix)',
  client_fk INT UNSIGNED NULL COMMENT 'FK to ref_clients.id; nullable — most events have no keg client',

  -- Format and run type
  nebuleuse_format_suffix VARCHAR(8) NULL,
  run_type ENUM('bot','can','can33','keg','cuv') NULL COMMENT 'NOT NULL after migration 090',

  -- Production volumes
  prod_total_units INT UNSIGNED NULL,
  special_qty_units INT UNSIGNED NULL COMMENT 'Unit count for parallel-row special pack',
  vendable_hl DECIMAL(8,3) NULL,

  -- QA columns (derived from bd_packaging_readings in normalization script)
  qa_analyses_units INT UNSIGNED NULL,
  qa_library_units INT UNSIGNED NULL,

  -- Typed loss columns (replacing the 7 monolithic loss_* columns)
  unsaleable_units INT UNSIGNED NULL,
  loss_liquid_other_units DECIMAL(10,3) NULL,
  loss_4pack_btl_units INT UNSIGNED NULL,
  loss_4pack_can_units INT UNSIGNED NULL,
  loss_wrap_btl_units INT UNSIGNED NULL,
  loss_wrap_can_units INT UNSIGNED NULL,
  loss_label_btl_units INT UNSIGNED NULL,
  loss_keg_collar_units INT UNSIGNED NULL,
  loss_crown_cork_units INT UNSIGNED NULL,
  loss_can_lid_units INT UNSIGNED NULL,
  loss_keg_save_units INT UNSIGNED NULL,
  loss_container_btl_units INT UNSIGNED NULL,
  loss_container_can_units INT UNSIGNED NULL,

  -- Keg-specific
  keg_client_delivered VARCHAR(128) NULL,
  new_liner_client TINYINT(1) NULL,
  new_liner_transport TINYINT(1) NULL,

  -- White label
  is_white_label TINYINT(1) NOT NULL DEFAULT 0,
  white_label_name VARCHAR(128) NULL,

  -- Audit
  audit_flags TEXT NULL,
  email VARCHAR(255) NULL,
  comments TEXT NULL,

  -- MI selection provenance (informational only; NOT used by pipeline)
  selection_can_mi_id_fk INT UNSIGNED NULL,
  selection_bottle_mi_id_fk INT UNSIGNED NULL,

  -- FK declarations
  CONSTRAINT fk_bdpv2_recipe  FOREIGN KEY (recipe_id_fk)             REFERENCES ref_recipes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bdpv2_sku     FOREIGN KEY (sku_id_fk)                REFERENCES ref_skus(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_bdpv2_client  FOREIGN KEY (client_fk)                REFERENCES ref_clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_bdpv2_sel_can_mi  FOREIGN KEY (selection_can_mi_id_fk)    REFERENCES ref_mi(id) ON DELETE SET NULL,
  CONSTRAINT fk_bdpv2_sel_bot_mi  FOREIGN KEY (selection_bottle_mi_id_fk) REFERENCES ref_mi(id) ON DELETE SET NULL,

  -- Dedup
  UNIQUE KEY uq_natural_key (submitted_at, neb_beer(64), neb_batch(32),
                              contract_beer(64), contract_batch(32),
                              row_origin, nebuleuse_format_suffix(8)),
  UNIQUE KEY uq_row_hash (row_hash),

  -- CHECK constraint: sku_id_fk or audit flag or parallel row
  CONSTRAINT chk_sku_or_flagged
    CHECK (sku_id_fk IS NOT NULL
           OR LOCATE('sku_unresolved', COALESCE(audit_flags,'')) > 0
           OR row_origin = 'parallel'),

  -- Performance indexes
  KEY idx_recipe_fk (recipe_id_fk),
  KEY idx_sku_fk (sku_id_fk),
  KEY idx_submitted_at (submitted_at),
  KEY idx_run_type (run_type),
  KEY idx_is_tombstoned (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta per [[schema-meta-db-classification]]
-- table_class 'source' = operator event data (append_only corrections_policy)
-- Verified column names: table_name, table_class, corrections_policy, notes
INSERT INTO schema_meta (table_name, table_class, corrections_policy, notes)
VALUES ('bd_packaging_v2', 'source', 'allowed',
  'PackagingData v2 — clean schema replacing legacy bd_packaging. Upload target for normalized rawdb. Phase 1.A 2026-05-23.');
