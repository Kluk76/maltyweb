-- db/migrations/196_inv_energydata.sql
--
-- What: New source table inv_energydata — canonicalises the utility meter
--       readings from the BSF energydata tab.
--
-- Why:  energydata is currently the only canonical source for monthly utility
--       consumption figures (water, gas, electricity day/night) read by
--       ingest-sie-invoice.js and build-cogs-report.js. Phase 1 BSF-exit.
--
-- Source: BSF energydata A2:F. Live-inspected 2026-05-28: 40 rows.
--   Columns: Year, Month, Eau (water m³), Gaz (gas kWh), Elec Jour (day kWh),
--            Elec Nuit (night kWh).
--   Note: amounts are stored with French decimal comma formatting (e.g. "30,111")
--   in BSF. The seed script converts to DECIMAL.
--
-- Design:
--   - UNIQUE KEY on (period) — one row per YYYY-MM period
--   - period CHAR(7) derived from Year + Month at seed time
--   - meter readings as DECIMAL(14,3) — fractional kWh/m³ is meaningful
--   - source ENUM('invoice','estimate') for future distinction between
--     invoice-confirmed and estimated values
--   - No meter_id column: BSF has a single location (La Nébuleuse brewery).
--     If multi-meter support is needed later, ALTER ADD meter_id + drop UNIQUE
--     on period + add UNIQUE on (period, meter_id).
--
-- Rollback:
--   DROP TABLE IF EXISTS inv_energydata;
--   DELETE FROM schema_meta WHERE table_name = 'inv_energydata';
--
-- NOTE: No bare SELECT statements.

-- ============================================================================
-- TABLE: inv_energydata
-- ============================================================================

CREATE TABLE IF NOT EXISTS inv_energydata (
  id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,

  -- ── PERIOD ─────────────────────────────────────────────────────────────
  period          CHAR(7)             NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'YYYY-MM derived from BSF Year + Month cols',

  -- ── METER READINGS ─────────────────────────────────────────────────────
  eau_m3          DECIMAL(14,3)       NULL
                    COMMENT 'Water consumption m³ (BSF col Eau)',
  gaz_kwh         DECIMAL(14,3)       NULL
                    COMMENT 'Gas consumption kWh (BSF col Gaz)',
  elec_jour_kwh   DECIMAL(14,3)       NULL
                    COMMENT 'Electricity day tariff kWh (BSF col Elec Jour)',
  elec_nuit_kwh   DECIMAL(14,3)       NULL
                    COMMENT 'Electricity night tariff kWh (BSF col Elec Nuit)',

  -- ── SOURCE ─────────────────────────────────────────────────────────────
  source          ENUM('invoice','estimate')
                    NOT NULL DEFAULT 'invoice'
                    COMMENT 'invoice = confirmed from SIE/SIL bill; estimate = interpolated',

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
  UNIQUE KEY uq_inv_energydata_period   (period),
  UNIQUE KEY uq_inv_energydata_row_hash (row_hash),

  CONSTRAINT chk_inv_energydata_period_format
    CHECK (period REGEXP '^[0-9]{4}-[0-9]{2}$')

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Monthly utility meter readings (water, gas, electricity). BSF energydata tab exit target. Phase 1 BSF-exit foundation migration 196.';

-- ============================================================================
-- schema_meta row for inv_energydata
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'inv_energydata',
  'source',
  'scripts/ingest-sie-invoice.ts (Phase 2)',
  'allowed_with_side_effect',
  'Phase 1 BSF-exit foundation. Monthly utility meter readings: water (m³), gas (kWh), electricity day/night (kWh). Single-row-per-period design for La Nébuleuse brewery single-site. Seeded from BSF energydata tab via scripts/_phase-bsf-exit/seed-inv-energydata.ts. Side-effect: corrections here flow into COGS utilities section (build-cogs-report.js) — rerun COGS after any correction.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  writer_script      = VALUES(writer_script),
  corrections_policy = VALUES(corrections_policy),
  notes              = VALUES(notes);

-- end migration 196
