-- db/migrations/101_bd_fermenting_v2.sql
-- What: Create bd_fermenting_v2 — single clean table folding 4 FermentationData xlsx
--       sheets via event_type ENUM discriminator. Expected rows post-upload: ~6686.
--         FermentationData_DryHop   →  279 rows (per-ingredient lines for dry hops)
--         FermentationData_Reads    → 5262 rows (gravity/pH/temp readings)
--         FermentationData_Purge    →  325 rows (CCT CO2 purge events)
--         FermentationData_ColdCrash →  820 rows (cold crash events)
--
-- Natural key: (submitted_at, event_type, beer_raw, batch, line_idx)
--   line_idx = ingredient position for DryHop; 0 for all other event types
--   (i.e. 1 row per Reads/Purge/ColdCrash event per batch per timestamp)
--
-- Why:  Phase 1.C of BSF→MySQL clean-break roadmap. Replaces legacy bd_fermenting
--       (6549 rows, only sheet_row_index UNIQUE — highest-risk silent-dup table per DBA).
--       Provides canonical MySQL fermentation record for MaltyWeb /fermenting form (Phase 6.C).
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_fermenting_v2;
--           DELETE FROM schema_meta WHERE table_name='bd_fermenting_v2';

CREATE TABLE IF NOT EXISTS bd_fermenting_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Audit / dedup guard
  row_hash      CHAR(64)   NOT NULL,
  is_tombstoned TINYINT(1) NOT NULL DEFAULT 0,
  audit_flags   TEXT       NULL,

  -- Timestamps
  imported_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source provenance
  submitted_at  DATETIME(6)  NOT NULL COMMENT 'Form submission timestamp (part of NK)',
  email         VARCHAR(255) NULL,
  event_date    DATE         NULL     COMMENT 'Brew-day date of the event (from DryHop sheet col 2; derived from submitted_at for others)',

  -- Natural-key identity
  event_type  ENUM('DryHop','Reads','Purge','ColdCrash') NOT NULL
              COMMENT 'Discriminator: which FermentationData sheet this row came from',
  beer_raw    VARCHAR(128) NOT NULL  COMMENT 'Beer identifier as entered (e.g. "STI 147", "BLO 11")',
  batch       VARCHAR(32)  NOT NULL  COMMENT 'Batch number; "" when absent (sentinel for legacy rows)',
  line_idx    INT          NOT NULL DEFAULT 0
              COMMENT '0-based ingredient index (DryHop only); always 0 for Reads/Purge/ColdCrash',

  -- FK to ref_recipes (initially nullable; hardened post-backfill)
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable',

  -- ── DryHop-specific columns (NULL for other event_types) ──────────────────
  dh_category    ENUM('hops_dry') NULL  COMMENT 'DryHop only: always hops_dry',
  dh_mi_id_fk    INT UNSIGNED NULL      COMMENT 'DryHop only: FK to ref_mi.id (ON DELETE RESTRICT)',
  dh_raw_name    VARCHAR(255) NULL      COMMENT 'DryHop only: free-text hop name as entered',
  dh_qty         DECIMAL(10,3) NULL     COMMENT 'DryHop only: quantity',
  dh_unit        ENUM('kg','g') NULL    COMMENT 'DryHop only: unit of quantity',
  dh_lot         VARCHAR(64)  NULL      COMMENT 'DryHop only: lot/batch number',
  dh_confidence  VARCHAR(32)  NULL      COMMENT 'DryHop only: MI resolution confidence label',
  dh_parse_note  TEXT         NULL      COMMENT 'DryHop only: parser diagnostics',
  dh_source_row  INT          NULL      COMMENT 'DryHop only: BSF source row index',

  -- ── Reads-specific columns (NULL for other event_types) ───────────────────
  gravity     DECIMAL(6,3) NULL COMMENT 'Reads only: fermentation gravity (°Plato)',
  ph          DECIMAL(4,2) NULL COMMENT 'Reads only: fermentation pH',
  temperature DECIMAL(5,2) NULL COMMENT 'Reads only: fermentation temperature (°C)',

  -- ── Purge-specific columns ────────────────────────────────────────────────
  comment_purge TEXT NULL COMMENT 'Purge only: operator note for CO2 purge event',

  -- ── ColdCrash-specific columns ────────────────────────────────────────────
  comment_cold_crash TEXT NULL COMMENT 'ColdCrash only: operator note for cold crash event',

  -- ── Shared optional columns ───────────────────────────────────────────────
  final_comments TEXT NULL COMMENT 'Reads + Purge: end-of-event operator notes',

  -- FK declarations
  CONSTRAINT fk_bdfv2_recipe FOREIGN KEY (recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_bdfv2_mi     FOREIGN KEY (dh_mi_id_fk)  REFERENCES ref_mi(id)      ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key: 1 row per event per (beer_raw, batch, timestamp, line_idx)
  UNIQUE KEY uq_natural_key (submitted_at, event_type, beer_raw(64), batch(32), line_idx),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_recipe_fk   (recipe_id_fk),
  KEY idx_beer_batch  (beer_raw(64), batch(32)),
  KEY idx_event_type  (event_type),
  KEY idx_event_date  (event_date),
  KEY idx_tombstoned  (is_tombstoned),
  KEY idx_dh_mi_fk    (dh_mi_id_fk)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_fermenting_v2',
  'source',
  'allowed',
  'ingest_bd_fermenting_v2.py',
  'FermentationData v2 — folds DryHop+Reads+Purge+ColdCrash via event_type. NK (submitted_at,event_type,beer_raw,batch,line_idx). Replaces bd_fermenting (highest-risk silent-dup table). Phase 1.C 2026-05-23.'
);
