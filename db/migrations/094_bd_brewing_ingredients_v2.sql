-- db/migrations/094_bd_brewing_ingredients_v2.sql
-- What: Create two tables:
--   1. bd_brewing_ingredients_v2 (header) — 1 row per batch raw ingredients blob.
--      NK (beer, batch). Carries the original cell content blob for traceability.
--   2. bd_brewing_ingredients_parsed_v2 (child long-form) — 1 row per resolved line.
--      NK (header_id, line_idx). FK mi_id_fk ON DELETE RESTRICT (fixes the SET NULL
--      weakness of the legacy bd_brewing_ingredients_parsed table per DBA audit).
--      Source cols from BrewingData_Ingredients xlsx sheet (1550 rows):
--      category, line_idx, mi_id_resolved, raw_name, qty, unit, lot, confidence,
--      parse_note, source_row.
-- Why:  Phase 1.B of BSF→MySQL clean-break roadmap. Provides canonical MySQL ingredient
--       records for MaltyWeb /brewing form (Phase 6.B) and COGS brewing consumption.
-- Risk: Additive only (CREATE TABLE x2). No impact on existing tables.
-- Rollback: DROP TABLE bd_brewing_ingredients_parsed_v2;
--           DROP TABLE bd_brewing_ingredients_v2;
--           DELETE FROM schema_meta WHERE table_name IN ('bd_brewing_ingredients_v2','bd_brewing_ingredients_parsed_v2');

-- ─── Header table ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS bd_brewing_ingredients_v2 (
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
  event_date    DATE         NULL COMMENT 'Brew session date (from xlsx event_date col)',

  -- Natural-key identity
  beer  VARCHAR(128) NOT NULL COMMENT 'Normalised beer name',
  batch VARCHAR(32)  NOT NULL COMMENT 'Batch identifier',

  -- FK to ref_recipes
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable',

  -- Raw blob (original cell text, preserved for re-parse without BSF)
  raw_blob_text TEXT NULL COMMENT 'Original malt+hops cell content concatenated; preserved for re-parse',

  -- Parse metadata
  parsed_at TIMESTAMP NULL COMMENT 'Timestamp of last parse run against this header row',

  -- FK declarations
  CONSTRAINT fk_bdiv2_recipe FOREIGN KEY (recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key: 1 ingredients header per batch
  UNIQUE KEY uq_natural_key (beer(64), batch(32)),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_recipe_fk  (recipe_id_fk),
  KEY idx_event_date (event_date),
  KEY idx_tombstoned (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Child long-form table ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS bd_brewing_ingredients_parsed_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Parent reference
  header_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to bd_brewing_ingredients_v2.id',

  -- Natural-key within header
  line_idx INT NOT NULL COMMENT '0-based index of this line within the parsed ingredient list',

  -- Classification
  category ENUM('malt','hops_kettle','hops_dry') NOT NULL COMMENT 'Ingredient category',

  -- MI resolution
  mi_id_fk INT UNSIGNED NULL
    COMMENT 'FK to ref_mi.id — ON DELETE RESTRICT (fixes legacy SET NULL weakness; do not change)',

  -- Data cols (sourced from BrewingData_Ingredients normalized xlsx)
  raw_name    VARCHAR(255) NOT NULL COMMENT 'Free-text ingredient name as entered by brewer',
  qty         DECIMAL(10,3) NULL    COMMENT 'Quantity as parsed',
  unit        ENUM('kg','g')  NULL  COMMENT 'Unit of qty',
  lot         VARCHAR(64)  NULL     COMMENT 'Lot number if present',
  confidence  VARCHAR(32)  NULL     COMMENT 'Resolution confidence label (e.g. exact, alias, fuzzy)',
  parse_note  TEXT         NULL     COMMENT 'Parser diagnostics (why this resolution was chosen)',
  source_row  INT          NULL     COMMENT 'Source BSF sheet row index for traceability',

  -- Timestamps
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- FK declarations
  CONSTRAINT fk_bdipv2_header FOREIGN KEY (header_id) REFERENCES bd_brewing_ingredients_v2(id) ON DELETE CASCADE,
  CONSTRAINT fk_bdipv2_mi     FOREIGN KEY (mi_id_fk)  REFERENCES ref_mi(id) ON DELETE RESTRICT,

  -- Natural key within a header
  UNIQUE KEY uq_natural_key (header_id, line_idx),

  -- Performance indexes
  KEY idx_mi_fk      (mi_id_fk),
  KEY idx_category   (category),
  KEY idx_header_id  (header_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── schema_meta registration ────────────────────────────────────────────────

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_brewing_ingredients_v2',
  'source',
  'allowed',
  'upload_bd_brewing_v2.py',
  'BrewingData_Ingredients header v2 — 1 row per batch. NK (beer,batch). Raw blob preserved. Phase 1.B 2026-05-23.'
);

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES (
  'bd_brewing_ingredients_parsed_v2',
  'derived',
  'allowed_with_side_effect',
  'parse_bd_ingredients.py',
  'Correct mi_id_fk via ref_mi_aliases upsert so the fix survives re-parse (same rule as legacy table).',
  'BrewingData parsed lines v2 — child of bd_brewing_ingredients_v2. mi_id_fk ON DELETE RESTRICT (not SET NULL). Phase 1.B 2026-05-23.'
);
