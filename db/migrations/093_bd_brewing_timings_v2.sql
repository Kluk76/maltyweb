-- db/migrations/093_bd_brewing_timings_v2.sql
-- What: Create bd_brewing_timings_v2 — clean upload target for BrewingData_Timings (2288 rows).
--       1 row per brew (beer, batch, brew). Mirrors legacy bd_brewing_timings cols +
--       adds recipe_id_fk FK, row_hash, is_tombstoned, audit_flags.
--       brew_start / brew_end preserved as DATETIME (legacy type confirmed from SHOW CREATE TABLE).
-- Why:  Phase 1.B of BSF→MySQL clean-break roadmap. Provides canonical MySQL timing record
--       for MaltyWeb /brewing form (Phase 6.B).
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_brewing_timings_v2; DELETE FROM schema_meta WHERE table_name='bd_brewing_timings_v2';

CREATE TABLE IF NOT EXISTS bd_brewing_timings_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Audit / dedup guard
  row_hash      CHAR(64)   NOT NULL,
  is_tombstoned TINYINT(1) NOT NULL DEFAULT 0,
  audit_flags   TEXT       NULL,

  -- Timestamps
  imported_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source provenance
  submitted_at  DATETIME(6)  NULL,
  email         VARCHAR(255) NULL,

  -- Natural-key identity
  beer  VARCHAR(128) NOT NULL COMMENT 'Normalised beer name',
  batch VARCHAR(32)  NOT NULL COMMENT 'Batch identifier',
  brew  VARCHAR(32)  NOT NULL COMMENT 'Brew number within batch (1-N)',

  -- FK to ref_recipes
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable',

  -- Data columns (types preserved from legacy bd_brewing_timings)
  brew_start DATETIME NULL COMMENT 'Brew session start datetime',
  brew_end   DATETIME NULL COMMENT 'Brew session end datetime',
  event_date DATE     NULL COMMENT 'Brew session date',
  start_ferm VARCHAR(255) NULL COMMENT 'Fermentation start note',

  -- FK declarations
  CONSTRAINT fk_bdtv2_recipe FOREIGN KEY (recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key: 1 timing row per brew
  UNIQUE KEY uq_natural_key (beer(64), batch(32), brew(32)),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_recipe_fk  (recipe_id_fk),
  KEY idx_beer_batch (beer, batch),
  KEY idx_event_date (event_date),
  KEY idx_tombstoned (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_brewing_timings_v2',
  'source',
  'allowed',
  'upload_bd_brewing_v2.py',
  'BrewingData_Timings v2 — 1 row per brew. NK (beer,batch,brew). brew_start/brew_end as DATETIME (mirror legacy). Phase 1.B 2026-05-23.'
);
