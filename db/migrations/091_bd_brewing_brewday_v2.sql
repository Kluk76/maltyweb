-- db/migrations/091_bd_brewing_brewday_v2.sql
-- What: Create bd_brewing_brewday_v2 — clean upload target for BrewingData_Brewday (831 rows).
--       1 row per batch. Natural key (beer, batch). Adds recipe_id_fk FK, row_hash,
--       is_tombstoned, audit_flags. Mirrors cols from legacy bd_brewing_brewday.
-- Why:  Phase 1.B of BSF→MySQL clean-break roadmap. Provides the canonical table for
--       the MaltyWeb /brewing form (Phase 6.B). Legacy bd_brewing_brewday coexists
--       (read-only, not dropped) until Phase 8.
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_brewing_brewday_v2; DELETE FROM schema_meta WHERE table_name='bd_brewing_brewday_v2';

CREATE TABLE IF NOT EXISTS bd_brewing_brewday_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Audit / dedup guard
  row_hash     CHAR(64)     NOT NULL,
  is_tombstoned TINYINT(1)  NOT NULL DEFAULT 0,
  audit_flags  TEXT         NULL,

  -- Timestamps
  imported_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source provenance (not used for dedup in v2; preserved for traceability)
  submitted_at DATETIME(6)  NULL,
  email        VARCHAR(255) NULL,

  -- Natural-key identity (beer + batch = 1 brewday row per batch)
  beer  VARCHAR(128) NOT NULL COMMENT 'Normalised beer name (neb_beer equivalent)',
  batch VARCHAR(32)  NOT NULL COMMENT 'Batch identifier (e.g. A1, B2)',

  -- FK to ref_recipes (mirrors bd_brewing_brewday.bd_beer_recipe_id; INT UNSIGNED per ref_recipes.id)
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable, NOT NULL after backfill confirms full coverage',

  -- Data columns (mirrored from legacy bd_brewing_brewday)
  cct            INT UNSIGNED NULL COMMENT 'CCT number (cf. ref_cct.number)',
  cct_cip        VARCHAR(32)  NULL COMMENT 'CCT CIP code',
  cct_cip_date   VARCHAR(32)  NULL COMMENT 'Date of CCT CIP (free-text as entered)',
  yeast          VARCHAR(128) NULL COMMENT 'Yeast strain name (cf. ref_yeast_strains.name)',
  yeast_gen      VARCHAR(32)  NULL COMMENT 'Yeast generation number',
  new_yeast      VARCHAR(255) NULL COMMENT 'New yeast details when fresh pitch',
  pitched_from   VARCHAR(255) NULL COMMENT 'Source vessel/batch for yeast repitch',
  yt_number      INT UNSIGNED NULL COMMENT 'Yeast tank number (cf. ref_yt.number)',
  yt_cip_date    VARCHAR(32)  NULL COMMENT 'Date of YT CIP (free-text as entered)',
  event_date     DATE         NULL COMMENT 'Brew session date',
  start_ferm     VARCHAR(255) NULL COMMENT 'Fermentation start note',

  -- FK declarations
  CONSTRAINT fk_bdbd_v2_recipe  FOREIGN KEY (recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdbd_v2_cct     FOREIGN KEY (cct)          REFERENCES ref_cct(number)  ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdbd_v2_yt      FOREIGN KEY (yt_number)    REFERENCES ref_yt(number)   ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key: 1 brewday row per batch
  UNIQUE KEY uq_natural_key (beer(64), batch(32)),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_recipe_fk   (recipe_id_fk),
  KEY idx_event_date  (event_date),
  KEY idx_tombstoned  (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_brewing_brewday_v2',
  'source',
  'allowed',
  'upload_bd_brewing_v2.py',
  'BrewingData_Brewday v2 — 1 row per batch. Natural key (beer, batch). Phase 1.B 2026-05-23.'
);
