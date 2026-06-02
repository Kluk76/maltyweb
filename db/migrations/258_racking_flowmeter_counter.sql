-- Migration 258: Add flowmeter counter readings to bd_racking_v2
--
-- Stores raw flowmeter start/end readings (DECIMAL(8,1), format xxxxx.x hl,
-- max 99999.9) alongside the existing racked_vol_hl column.
-- racked_vol_hl is computed as (flowmeter_end_hl - flowmeter_start_hl) when
-- both readings are present; it remains manually entered otherwise.
-- The raw readings are immutable event facts stored unconditionally.
--
-- MySQL 8 — no ADD COLUMN IF NOT EXISTS (idempotency via schema_migrations).

ALTER TABLE bd_racking_v2
    ADD COLUMN flowmeter_start_hl DECIMAL(8,1) NULL AFTER racked_vol_hl,
    ADD COLUMN flowmeter_end_hl   DECIMAL(8,1) NULL AFTER flowmeter_start_hl;
