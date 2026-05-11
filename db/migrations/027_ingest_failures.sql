-- 027 — ingest_failures audit table.
-- Captures rows rejected by FK constraints during BSF→MySQL ingest.
-- Replaces silent INSERT IGNORE swallowing of FK errors (1452) with explicit logging.
-- UNIQUE (target_table, row_hash) means re-runs touching the same bad row UPDATE last_seen_at
-- via ON DUPLICATE KEY rather than re-inserting.

CREATE TABLE IF NOT EXISTS ingest_failures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  detected_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  source_tab       VARCHAR(64)  NOT NULL,        -- 'brewing' | 'fermenting' | 'racking' | 'packaging'
  target_table     VARCHAR(64)  NOT NULL,        -- 'bd_brewing_brewday' | ...
  sheet_row_index  INT UNSIGNED NOT NULL,        -- BSF row for traceability
  row_hash         CHAR(64)     NOT NULL,
  reason_code      SMALLINT UNSIGNED NOT NULL,   -- MySQL error code (1452 = FK violation)
  reason_text      VARCHAR(512) NOT NULL,        -- full SQL error message
  raw_row          JSON         NOT NULL,        -- the parsed dict that failed to insert
  resolved_at      TIMESTAMP    NULL,
  resolution_note  VARCHAR(255) NULL,
  UNIQUE KEY uniq_target_hash (target_table, row_hash),
  KEY idx_unresolved (resolved_at, source_tab, detected_at),
  KEY idx_sheet_row  (source_tab, sheet_row_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
