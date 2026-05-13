-- 047_recipe_profile.sql
-- Recipe averaging system: observed averages per recipe × time window.
-- Read by: tank board, R&D dashboards, COGS expected-vs-actual.
-- Written by: scripts/python/refresh_recipe_profile.py (nightly cron + admin endpoint).

-- ─── 1. Recipe revision marker ───────────────────────────────────────────────
-- Brewer manually sets this when a recipe is intentionally revised; defines
-- the baseline for the `since_revision` profile window.

ALTER TABLE ref_recipes
  ADD COLUMN revision_date DATE NULL
    COMMENT 'Last intentional recipe revision; baseline for since_revision window'
    AFTER notes;


-- ─── 2. Parsed malt + hops rows ──────────────────────────────────────────────
-- Long-format normalized output from the malt/hops text-parser.
-- Source rows: bd_brewing_ingredients (malt + kettle hops) and bd_fermenting
-- (dry-hop events). One row per parsed ingredient triplet.

CREATE TABLE bd_brewing_ingredients_parsed (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  source_table    ENUM('bd_brewing_ingredients','bd_fermenting') NOT NULL,
  source_id       BIGINT UNSIGNED NOT NULL
                  COMMENT 'PK of the row in source_table',
  line_idx        SMALLINT UNSIGNED NOT NULL
                  COMMENT '0-based index of this triplet within the raw text',

  beer            VARCHAR(128) NOT NULL,
  batch           VARCHAR(32) NOT NULL,
  event_date      DATE NOT NULL,

  category        ENUM('malt','hops_kettle','hops_dry') NOT NULL,
  raw_name        VARCHAR(255) NOT NULL
                  COMMENT 'Free-text name as entered by brewer',
  mi_id_fk        INT UNSIGNED NULL
                  COMMENT 'FK to ref_mi.id (NULL if name did not resolve)',
  qty             DECIMAL(10,3) NOT NULL,
  unit            ENUM('kg','g') NOT NULL,
  lot             VARCHAR(64) NULL,

  parsed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_source_line (source_table, source_id, category, line_idx),
  KEY ix_batch      (beer, batch),
  KEY ix_mi         (mi_id_fk),
  KEY ix_event_date (event_date),
  FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ─── 3. Recipe profile (scalar metrics) ──────────────────────────────────────
-- One row per (recipe, window_kind). 3 windows: rolling_12mo, all_time,
-- since_revision (where ref_recipes.revision_date is set).

CREATE TABLE ref_recipe_profile (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipe_id                   INT UNSIGNED NOT NULL,
  window_kind                 ENUM('rolling_12mo','all_time','since_revision') NOT NULL,

  -- Sample
  batch_count                 INT UNSIGNED NOT NULL DEFAULT 0,
  earliest_batch_date         DATE NULL,
  latest_batch_date           DATE NULL,
  computed_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                  ON UPDATE CURRENT_TIMESTAMP,

  -- Gravity (°Plato)
  avg_first_wort_gravity      DECIMAL(5,2) NULL,
  avg_kochwurze_gravity       DECIMAL(5,2) NULL,
  avg_og                      DECIMAL(5,2) NULL,
  avg_fg                      DECIMAL(5,2) NULL,
  avg_apparent_atten_pct      DECIMAL(5,2) NULL
                              COMMENT '(avg_og - avg_fg) / avg_og * 100',

  -- pH
  avg_first_wort_ph           DECIMAL(4,2) NULL,
  avg_kochwurze_ph            DECIMAL(4,2) NULL,
  avg_cooling_ph              DECIMAL(4,2) NULL,
  avg_end_ferm_ph             DECIMAL(4,2) NULL,

  -- Timing (days)
  avg_ferm_days               DECIMAL(5,2) NULL COMMENT 'start_ferm → cc_date',
  avg_cc_days                 DECIMAL(5,2) NULL COMMENT 'cc_date → racking date',
  avg_garde_days              DECIMAL(5,2) NULL COMMENT 'racking → first packaging date',

  -- Packaging (median — outlier-resistant)
  median_packaging_yield_pct  DECIMAL(5,2) NULL
                              COMMENT 'vendable_hl / objective_volume_hl * 100',
  median_loss_pct             DECIMAL(5,2) NULL,
  median_packaged_o2_ppb      DECIMAL(8,2) NULL,
  median_packaged_co2_vol     DECIMAL(5,2) NULL,

  UNIQUE KEY uk_recipe_window (recipe_id, window_kind),
  FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ─── 4. Per-malt averages (side table) ───────────────────────────────────────
-- One row per (recipe, window, mi_id). Malt grist composition varies between
-- recipes, so wide columns aren't viable.

CREATE TABLE ref_recipe_profile_malt (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipe_id           INT UNSIGNED NOT NULL,
  window_kind         ENUM('rolling_12mo','all_time','since_revision') NOT NULL,
  mi_id_fk            INT UNSIGNED NOT NULL,

  avg_kg_per_brew     DECIMAL(8,2) NULL,
  avg_kg_per_hl       DECIMAL(8,3) NULL,
  pct_of_grist        DECIMAL(5,2) NULL
                      COMMENT '% of total malt weight in the average grist',
  appearance_count    INT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Number of batches in this window that used this malt',
  total_batches       INT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Total batches in this window (for appearance_count denominator)',

  computed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_recipe_window_mi (recipe_id, window_kind, mi_id_fk),
  FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE CASCADE,
  FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ─── 5. Per-hop averages (side table) ────────────────────────────────────────
-- Hops carry a stage qualifier (kettle vs dry_hop). One row per
-- (recipe, window, mi_id, stage).

CREATE TABLE ref_recipe_profile_hops (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  recipe_id           INT UNSIGNED NOT NULL,
  window_kind         ENUM('rolling_12mo','all_time','since_revision') NOT NULL,
  mi_id_fk            INT UNSIGNED NOT NULL,
  stage               ENUM('kettle','dry_hop') NOT NULL,

  avg_g_per_brew      DECIMAL(8,2) NULL,
  avg_g_per_hl        DECIMAL(8,3) NULL,
  appearance_count    INT UNSIGNED NOT NULL DEFAULT 0,
  total_batches       INT UNSIGNED NOT NULL DEFAULT 0,

  computed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_recipe_window_mi_stage (recipe_id, window_kind, mi_id_fk, stage),
  FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE CASCADE,
  FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
