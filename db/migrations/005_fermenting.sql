-- bd_fermenting
-- Mirror of BSF!FermentingData (A:R). Single table — heterogeneous via col C
-- (Read | Dry Hop | Purge | Cold Crash) but all event types share the same
-- 18-column layout, so 1:1 mirror.

CREATE TABLE IF NOT EXISTS bd_fermenting (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash                 CHAR(64)        NOT NULL,
  sheet_row_index          INT UNSIGNED    NOT NULL,
  imported_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at             DATETIME(6)     NULL,                   -- col A
  email                    VARCHAR(255)    NULL,                   -- col B
  event_type               VARCHAR(64)     NOT NULL,               -- col C: Read | Dry Hop | Purge | Cold Crash

  -- Read block (cols 3-6)
  beers_to_read            VARCHAR(255)    NULL,                   -- col D
  gravity                  DECIMAL(6,3)    NULL,                   -- col E
  ph                       DECIMAL(4,2)    NULL,                   -- col F
  temperature              DECIMAL(5,2)    NULL,                   -- col G

  -- Dry Hop block (cols 7-9)
  beers_to_dry_hop         VARCHAR(255)    NULL,                   -- col H: format "CODE BATCH" e.g. "STI 147"
  hops_raw                 TEXT            NULL,                   -- col I: newline-separated triplets
  dry_hop_comment          TEXT            NULL,                   -- col J

  -- Purge block (cols 10-11)
  beers_to_purge           VARCHAR(255)    NULL,                   -- col K
  purge_comment            TEXT            NULL,                   -- col L

  -- Cold Crash block (cols 12-13)
  beers_to_cold_crash      VARCHAR(255)    NULL,                   -- col M
  cold_crash_comment       TEXT            NULL,                   -- col N

  final_comments           TEXT            NULL,                   -- col O
  event_date               DATE            NULL,                   -- col P

  -- IMPORTRANGE'd computed cols (cols 16-17)
  beer_reads               VARCHAR(255)    NULL,                   -- col Q
  beer_dh                  VARCHAR(255)    NULL,                   -- col R

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash      (row_hash),
  KEY idx_sheet_row_index       (sheet_row_index),
  KEY idx_submitted_at          (submitted_at),
  KEY idx_email                 (email),
  KEY idx_event_type            (event_type),
  KEY idx_event_date            (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
