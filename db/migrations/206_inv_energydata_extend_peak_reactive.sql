-- migration 206: extend inv_energydata with peak_kw + reactive_kvarh
-- Purpose: unblock ingest-sie-invoice.js + update-energy-actuals.js write flip (BSF-exit Phase 3.6)
-- Two new nullable columns to store SIE/SIL invoice-derived peak demand and reactive energy.
-- period is the existing UNIQUE key — no meter_id needed (single-meter brewery).
-- Applied: 2026-05-28

ALTER TABLE inv_energydata
  ADD COLUMN peak_kw        DECIMAL(10,3) NULL AFTER elec_nuit_kwh,
  ADD COLUMN reactive_kvarh DECIMAL(12,3) NULL AFTER peak_kw;

-- Update schema_meta: corrections_policy unchanged (source table, ingest writes)
-- No schema_meta INSERT needed — the row for inv_energydata already exists (migration 196).
-- Verify: SELECT table_name, table_class FROM schema_meta WHERE table_name = 'inv_energydata';
