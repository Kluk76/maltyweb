-- db/migrations/198_ref_cogs_targets.sql
--
-- What: New reference table ref_cogs_targets — canonicalises the board KPI
--       COGS target values from the BSF COGS_Targets tab.
--
-- Why:  COGS_Targets holds per-SKU cost-per-unit budget targets used by the
--       COGS dashboard for variance analysis. Currently lives only in BSF.
--       Phase 1 BSF-exit foundation.
--
-- Source: BSF COGS_Targets A2:F. Live-inspected 2026-05-28: 53 rows.
--   BSF cols: SKU (e.g. ZEPB), Beer (e.g. Zepp), Format (Bot/Keg/Can/Can33/Cuv),
--             2026 (DECIMAL target CHF/unit), 2027 (future — mostly empty),
--             Notes (free text, e.g. "Missing from 2026 budget").
--
-- Normalisation decision:
--   BSF has wide columns per year (2026, 2027). Normalised as rows:
--   (sku_code, year, target_cost_chf) with UNIQUE (sku_code, year).
--   This means the 53 BSF rows become UP TO 106 DB rows (one per non-null year
--   value). Empty year columns are skipped at seed time.
--   Rationale: avoids adding a column per year; consistent with Phase 7 ingest
--   design (annual budget uploads add rows, not columns).
--
-- Rollback:
--   DROP TABLE IF EXISTS ref_cogs_targets;
--   DELETE FROM schema_meta WHERE table_name = 'ref_cogs_targets';
--
-- NOTE: No bare SELECT statements.

-- ============================================================================
-- TABLE: ref_cogs_targets
-- ============================================================================

CREATE TABLE IF NOT EXISTS ref_cogs_targets (
  id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,

  -- ── IDENTITY ───────────────────────────────────────────────────────────
  sku_code        VARCHAR(16)         NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col A SKU (e.g. ZEPB, ZEPC, ZEPF)',
  year            SMALLINT UNSIGNED   NOT NULL
                    COMMENT 'Budget year (2026, 2027, ...)',

  -- ── DIMENSION METADATA (denormalised for readability; not FK-linked yet) ──
  beer_name       VARCHAR(128)        NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col B Beer — denormalised for human readability',
  format          VARCHAR(32)         NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col C Format (Bot, Keg, Can, Can33, Cuv)',

  -- ── TARGET VALUE ───────────────────────────────────────────────────────
  target_cost_chf DECIMAL(12,4)       NULL
                    COMMENT 'Budget target cost CHF/unit for this SKU × year',

  -- ── NOTES ──────────────────────────────────────────────────────────────
  notes           TEXT                NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'BSF col F Notes (e.g. Missing from 2026 budget)',

  -- ── AUDIT ──────────────────────────────────────────────────────────────
  row_hash        CHAR(64)            NOT NULL
                    COLLATE utf8mb4_unicode_ci,
  last_modified_by ENUM('ingest','web')
                    NOT NULL DEFAULT 'ingest',
  created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,

  -- ── CONSTRAINTS ────────────────────────────────────────────────────────
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_cogs_targets_sku_year (sku_code, year),
  UNIQUE KEY uq_ref_cogs_targets_row_hash (row_hash),

  KEY idx_ref_cogs_targets_year (year),

  CONSTRAINT chk_ref_cogs_targets_year_range
    CHECK (year >= 2020 AND year <= 2099),
  CONSTRAINT chk_ref_cogs_targets_cost_positive
    CHECK (target_cost_chf IS NULL OR target_cost_chf >= 0)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-SKU per-year COGS budget targets (CHF/unit). BSF COGS_Targets tab exit target. Phase 1 BSF-exit foundation migration 198. Normalised: one row per (sku_code, year).';

-- ============================================================================
-- schema_meta row for ref_cogs_targets
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'ref_cogs_targets',
  'reference',
  'maltyweb admin UI',
  'allowed',
  'Phase 1 BSF-exit foundation. Per-SKU per-year COGS budget targets used by the COGS dashboard for variance analysis. Normalised from BSF wide format (one col per year) to row-per-(sku_code,year). 53 BSF rows → up to 53 rows at 2026 seed (2027 column mostly empty). Seeded via scripts/_phase-bsf-exit/seed-ref-cogs-targets.ts. Read by build-cogs-report.js variance section.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  writer_script      = VALUES(writer_script),
  corrections_policy = VALUES(corrections_policy),
  notes              = VALUES(notes);

-- end migration 198
