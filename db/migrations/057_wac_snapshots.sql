-- 057_wac_snapshots.sql
--
-- Creates wac_snapshots — period-end weighted-average-cost snapshots per MI.
-- The derived audit artifact for inventory valuation; satisfies Swiss OR Art. 960c
-- (period-end balance-sheet inventory at fixed date) without requiring full
-- historical replay of inv_deliveries.qty_remaining at every query.
--
-- Schema notes:
--   period                 — YYYY-MM (canonical month key)
--   wac_chf                — qty-weighted avg unit_price, converted to CHF;
--                            NULL = no cost basis (no qualifying deliveries)
--   qty_remaining_at_close — sum of inv_deliveries.qty_remaining filtered to
--                            date_received ≤ period end
--   total_value_chf        — qty_remaining_at_close × wac_chf
--   delivery_row_ids       — JSON array of inv_deliveries.id that contributed
--   replay_source — current_approximation: uses current qty_remaining as proxy
--                                          (acceptable v1 trade-off; v2 = historical replay)
--                   historical_replay: full FIFO replay from date_received to period end
--                   operator_override: manual snapshot edit
--
-- The page reads from this table; the existing compute-weighted-prices.ts script
-- is rewired in Phase D to write here at every month-close.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

CREATE TABLE IF NOT EXISTS wac_snapshots (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mi_id_fk               INT UNSIGNED NOT NULL,
  period                 VARCHAR(7) NOT NULL,
  wac_chf                DECIMAL(14,6) NULL,
  qty_remaining_at_close DECIMAL(14,4) NULL,
  total_value_chf        DECIMAL(14,4) NULL,
  delivery_row_ids       JSON NOT NULL,
  eur_chf_rate           DECIMAL(8,6) NOT NULL,
  replay_source          ENUM('current_approximation','historical_replay','operator_override') NOT NULL,
  computed_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  row_hash               CHAR(64) NOT NULL,
  UNIQUE KEY uk_mi_period (mi_id_fk, period),
  KEY idx_period (period),
  CONSTRAINT fk_wac_mi FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
