-- db/migrations/106_bd_racking_v2.sql
-- What: Create bd_racking_v2 — clean upload target for RackingData (399 rows).
--       1 row per racking event. Models the destination tank, timings, CO2/O2
--       measurements, and the two CIP blocks (equipment CIP + destination-BBT CIP).
-- Why:  Phase 1.D of BSF→MySQL clean-break roadmap. Replaces legacy bd_racking
--       (which dedups only on sheet_row_index). Provides canonical MySQL racking record
--       for the MaltyWeb /racking form (Phase 6.D).
--
-- Key modeling decisions (verified against the raw export, NOT guessed):
--   * Destination tank: the canonical field is "Which BBT" (raw col 25), populated
--     93% as "BBT 1".."BBT 8" / "CCT 5". The legacy "BBT" (raw col 12) is a bare-int
--     field populated only 15% — kept as bbt_old fallback. (Confirmed via full-row
--     population profiling + memory reference_rackingdata_bbt_col_z + parse-tank-simulation.js
--     which reads col Z first, col M fallback.)
--   * racking_destination_type ENUM parsed from the "BBT N"/"CCT N" prefix.
--   * Natural key needs a `seq` disambiguator: one batch (Malt Capone #1) was racked
--     twice (Centri→BBT4 and KZE→BBT1) and both rows submitted in the SAME second —
--     genuinely distinct events that collide on (submitted_at, beer, batch). seq is a
--     deterministic content-sorted within-group ordinal (0 for 398/399 rows).
--   * Racking date = DATE(submitted_at) per reference_bd_racking_date_columns
--     (no event_date column exists; start/end are times-of-day combined with that date).
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_racking_v2; DELETE FROM schema_meta WHERE table_name='bd_racking_v2';

CREATE TABLE IF NOT EXISTS bd_racking_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Audit / dedup guard
  row_hash      CHAR(64)   NOT NULL,
  is_tombstoned TINYINT(1) NOT NULL DEFAULT 0,
  audit_flags   TEXT       NULL,

  -- Timestamps
  imported_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source provenance + NK
  submitted_at  DATETIME(6)  NOT NULL COMMENT 'Form submission timestamp (part of NK)',
  email         VARCHAR(255) NULL,
  event_date    DATE         NULL COMMENT 'Racking date = DATE(submitted_at); no event_date in source',
  seq           INT          NOT NULL DEFAULT 0
                COMMENT 'Within-(submitted_at,beer,batch) ordinal; disambiguates same-second multi-racking. 0 for singletons.',

  -- Identity (Nébuleuse + contract; exactly one populated, both blank = no_beer_identity)
  neb_beer       VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Recettes Nébuleuse',
  neb_batch      VARCHAR(32)  NOT NULL DEFAULT '',
  neb_recipe_id_fk      INT UNSIGNED NULL COMMENT 'FK ref_recipes.id (neb)',
  contract_beer  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'Recettes Contract',
  contract_batch VARCHAR(32)  NOT NULL DEFAULT '',
  contract_recipe_id_fk INT UNSIGNED NULL COMMENT 'FK ref_recipes.id (contract)',

  -- Equipment / centri CIP (raw cols 2-3)
  last_cip_date VARCHAR(32) NULL COMMENT 'Date du dernier CIP (equipment, free-text as entered)',
  cip_type      VARCHAR(64) NULL COMMENT 'Type de CIP',

  -- Racking operation
  rack_type     VARCHAR(32)  NULL COMMENT 'Type de Rack: Centri / KZE / Pump / Centri+KZE',
  client        VARCHAR(128) NULL COMMENT 'Client (Nébuleuse / contract client)',
  start_time    DATETIME     NULL COMMENT 'Racking start (event_date + Start Time)',
  end_time      DATETIME     NULL COMMENT 'Racking end (event_date + End Time)',

  -- Destination tank (canonical = "Which BBT" col 25; bbt_old = legacy "BBT" col 12)
  racking_destination_type ENUM('BBT','CCT','YT') NULL COMMENT 'Parsed from target_tank_raw prefix',
  bbt_number    INT UNSIGNED NULL COMMENT 'FK ref_bbt.number when destination is a BBT',
  cct_number    INT UNSIGNED NULL COMMENT 'FK ref_cct.number when destination is a CCT (serving tank)',
  target_tank_raw VARCHAR(32) NULL COMMENT 'Raw destination string: "BBT 1" / "CCT 5" / legacy bare int',
  bbt_old       INT UNSIGNED NULL COMMENT 'Legacy bare-int BBT field (raw col 12); fallback only, NOT FK',

  -- Measurements
  bbt_co2       DECIMAL(8,3)  NULL COMMENT 'CO2 in BBT (g/L or ppb as entered)',
  bbt_o2        DECIMAL(10,3) NULL COMMENT 'O2 in BBT (ppb as entered)',
  racked_vol_hl DECIMAL(8,3)  NULL COMMENT 'Total Racked Vol (hL)',
  blend_hl      DECIMAL(8,3)  NULL COMMENT 'Blend volume (hL); NULL + flag if source was non-numeric',
  avg_turbidity DECIMAL(8,3)  NULL,
  avg_speed     DECIMAL(8,3)  NULL,
  bbt_pressure  DECIMAL(6,3)  NULL COMMENT 'Pressure in BBT (bar)',
  centri_rinsed VARCHAR(8)    NULL COMMENT 'Centri Rinsed? Yes/No',
  comments      TEXT          NULL COMMENT 'Final Comments',

  -- Destination-BBT CIP block (raw cols 22-24; "Which BBT" col 25 feeds destination above)
  cip_bbt_done  VARCHAR(8)  NULL COMMENT 'CIP BBT? Yes/No',
  cip_bbt_type  VARCHAR(64) NULL COMMENT 'CIP Type (of the destination BBT)',
  cip_bbt_date  VARCHAR(32) NULL COMMENT 'Date of BBT CIP (free-text as entered)',

  -- FK declarations (all targets are INT UNSIGNED)
  CONSTRAINT fk_bdrkv2_neb_recipe      FOREIGN KEY (neb_recipe_id_fk)      REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdrkv2_contract_recipe FOREIGN KEY (contract_recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdrkv2_bbt             FOREIGN KEY (bbt_number)            REFERENCES ref_bbt(number) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdrkv2_cct             FOREIGN KEY (cct_number)            REFERENCES ref_cct(number) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key
  UNIQUE KEY uq_natural_key (submitted_at, neb_beer(64), neb_batch(32), contract_beer(64), contract_batch(32), seq),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_neb_recipe   (neb_recipe_id_fk),
  KEY idx_neb_beer     (neb_beer(64), neb_batch(32)),
  KEY idx_event_date   (event_date),
  KEY idx_dest_type    (racking_destination_type),
  KEY idx_bbt          (bbt_number),
  KEY idx_tombstoned   (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_racking_v2',
  'source',
  'allowed',
  'ingest_bd_racking_v2.py',
  'RackingData v2 — 1 row per racking event. NK (submitted_at,neb_beer,neb_batch,contract_beer,contract_batch,seq). Destination from canonical "Which BBT" (col25), legacy bare-int in bbt_old. Replaces bd_racking (sheet_row_index-only dedup). Phase 1.D 2026-05-24.'
);
