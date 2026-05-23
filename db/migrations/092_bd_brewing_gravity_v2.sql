-- db/migrations/092_bd_brewing_gravity_v2.sql
-- What: Create bd_brewing_gravity_v2 — folds 4 xlsx sheets (FirstWort, Pfannevoll,
--       Kochwurze, Cooling) into one table via event_type discriminator.
--       Expected rows post-upload: ~8 813 (2135 + 2175 + 2199 + 2304).
--       Natural key (beer, batch, brew, event_type). Cooling event carries OG-at-cooling
--       per memory reference_brewingdata_og_column (Final_Gravity = OG post-boil).
-- Why:  Phase 1.B of BSF→MySQL clean-break roadmap. Replaces the legacy bd_brewing_gravity
--       table (which has stage ENUM('FirstWort','Pfannevoll','Kochwurze') only — no Cooling).
--       Provides canonical MySQL gravity record for MaltyWeb /brewing form (Phase 6.B).
-- Risk: Additive only (CREATE TABLE). No impact on existing tables.
-- Rollback: DROP TABLE bd_brewing_gravity_v2; DELETE FROM schema_meta WHERE table_name='bd_brewing_gravity_v2';

CREATE TABLE IF NOT EXISTS bd_brewing_gravity_v2 (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Audit / dedup guard
  row_hash      CHAR(64)    NOT NULL,
  is_tombstoned TINYINT(1)  NOT NULL DEFAULT 0,
  audit_flags   TEXT        NULL,

  -- Timestamps
  imported_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Source provenance
  submitted_at  DATETIME(6)  NULL,
  email         VARCHAR(255) NULL,

  -- Natural-key identity
  beer       VARCHAR(128) NOT NULL COMMENT 'Normalised beer name',
  batch      VARCHAR(32)  NOT NULL COMMENT 'Batch identifier',
  brew       VARCHAR(32)  NOT NULL COMMENT 'Brew number within batch (1-N)',
  event_type ENUM('FirstWort','Pfannevoll','Kochwurze','Cooling') NOT NULL
             COMMENT 'Discriminator: which stage these readings belong to',

  -- FK to ref_recipes
  recipe_id_fk INT UNSIGNED NULL COMMENT 'FK to ref_recipes.id; initially nullable',

  -- Event-specific data cols (nullable — populated only for the matching event_type)

  -- FirstWort
  firstwort_gravity  DECIMAL(6,3) NULL COMMENT 'First-wort gravity (°Plato). Populated for event_type=FirstWort',
  firstwort_ph       DECIMAL(4,2) NULL COMMENT 'First-wort pH. Populated for event_type=FirstWort',

  -- Pfannevoll
  pfannevoll_gravity DECIMAL(6,3) NULL COMMENT 'Pre-boil (Pfannevoll) gravity (°Plato). Populated for event_type=Pfannevoll',

  -- Kochwurze
  kochwurze_gravity  DECIMAL(6,3) NULL COMMENT 'Boiling wort gravity (°Plato). Populated for event_type=Kochwurze',

  -- Cooling (OG-at-cooling per reference_brewingdata_og_column)
  final_ph           DECIMAL(4,2)  NULL COMMENT 'Post-cooling pH. Populated for event_type=Cooling',
  final_gravity      DECIMAL(6,3)  NULL COMMENT 'OG at cooling (°Plato, col Final_Gravity = OG post-boil). Populated for event_type=Cooling',
  final_volume       DECIMAL(8,3)  NULL COMMENT 'Volume into fermenter (hL). Populated for event_type=Cooling',
  batch_dilution     DECIMAL(8,3)  NULL COMMENT 'Batch dilution volume (hL). Populated for event_type=Cooling',

  -- FK declarations
  CONSTRAINT fk_bdgv2_recipe FOREIGN KEY (recipe_id_fk) REFERENCES ref_recipes(id) ON DELETE RESTRICT ON UPDATE CASCADE,

  -- Natural key: 1 row per (beer, batch, brew, stage)
  UNIQUE KEY uq_natural_key (beer(64), batch(32), brew(32), event_type),

  -- Row-content dedup guard
  UNIQUE KEY uq_row_hash (row_hash),

  -- Performance indexes
  KEY idx_recipe_fk  (recipe_id_fk),
  KEY idx_beer_batch (beer, batch),
  KEY idx_event_type (event_type),
  KEY idx_tombstoned (is_tombstoned)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'bd_brewing_gravity_v2',
  'source',
  'allowed',
  'upload_bd_brewing_v2.py',
  'BrewingData gravity v2 — folds FirstWort+Pfannevoll+Kochwurze+Cooling via event_type. NK (beer,batch,brew,event_type). Phase 1.B 2026-05-23.'
);
