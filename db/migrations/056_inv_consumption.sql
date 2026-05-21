-- 056_inv_consumption.sql
--
-- Creates inv_consumption — canonical store for raw material consumption events
-- (brewing, fermenting, racking, packaging). Migrated from BSF Consumption_Parsed
-- per the warehouse-page rollout (2026-05-21). MySQL becomes the source of truth;
-- BSF tab continues to be written by parse-all-consumption.js for backwards
-- compatibility but is no longer authoritative.
--
-- Schema notes:
--   source_event — origin process; drives downstream filters
--     brewing     = mash/boil hops + adjuncts (BrewingData)
--     fermenting  = dry-hop additions (FermentingData)
--     racking     = blends/adjuncts at racking (RackingData)
--     packaging   = primary + secondary packaging draw (PackagingData)
--     manual      = operator-entered correction (no source_row_id)
--   source_row_id — FK back to the raw production row (nullable for manual)
--   hl_packaged   — for packaging events: HL of finished beer produced;
--                   used by burn-rate / HL-equivalent calculations
--   row_hash      — SHA-256 of canonical fields; idempotency guard
--
-- Dedup invariant: (mi_id_fk, consumed_at, source_event, source_row_id, qty) UNIQUE.
-- The source_row_id distinguishes multiple consumption events on the same day for
-- the same MI (e.g. two different brews using the same hop variety).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

CREATE TABLE IF NOT EXISTS inv_consumption (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mi_id_fk      INT UNSIGNED NOT NULL,
  consumed_at   DATE NOT NULL,
  qty           DECIMAL(14,4) NOT NULL,
  unit          VARCHAR(16) NULL,
  source_event  ENUM('brewing','fermenting','racking','packaging','manual') NOT NULL,
  source_row_id BIGINT UNSIGNED NULL,
  beer_name     VARCHAR(128) NULL,
  hl_packaged   DECIMAL(10,4) NULL,
  row_hash      CHAR(64) NOT NULL,
  imported_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mi_date (mi_id_fk, consumed_at),
  KEY idx_event_date (source_event, consumed_at),
  UNIQUE KEY uk_dedup (mi_id_fk, consumed_at, source_event, source_row_id, qty),
  CONSTRAINT fk_consumption_mi FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
