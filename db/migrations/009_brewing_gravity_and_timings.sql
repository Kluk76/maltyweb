-- bd_brewing_gravity + bd_brewing_timings
-- Two new event-type tables discovered after live data ingestion:
--   * gravity: 3 form-stage submissions (First Wort, Pfannevoll, Kochwürze)
--     unified into one table with a `stage` discriminator. Filter by stage
--     for any single measurement; UNION not needed.
--   * timings: brew start/end times (event_type='Timings').

CREATE TABLE IF NOT EXISTS bd_brewing_gravity (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash          CHAR(64)        NOT NULL,
  sheet_row_index   INT UNSIGNED    NOT NULL,
  imported_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at      DATETIME(6)     NULL,
  email             VARCHAR(255)    NULL,
  event_type        VARCHAR(64)     NOT NULL,
  stage             ENUM('FirstWort','Pfannevoll','Kochwurze') NOT NULL,

  beer              VARCHAR(128)    NULL,
  batch             VARCHAR(32)     NULL,
  brew              VARCHAR(32)     NULL,
  gravity           DECIMAL(6,3)    NULL,
  ph                DECIMAL(4,2)    NULL,                       -- only First Wort fills this; NULL elsewhere

  -- IMPORTRANGE'd trailing cols (cols 48-50)
  concatenate       VARCHAR(255)    NULL,
  event_date        DATE            NULL,
  start_ferm        VARCHAR(255)    NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash         (row_hash),
  KEY idx_sheet_row_index          (sheet_row_index),
  KEY idx_submitted_at             (submitted_at),
  KEY idx_email                    (email),
  KEY idx_stage                    (stage),
  KEY idx_beer_batch               (beer, batch),
  KEY idx_event_date               (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS bd_brewing_timings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash          CHAR(64)        NOT NULL,
  sheet_row_index   INT UNSIGNED    NOT NULL,
  imported_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at      DATETIME(6)     NULL,
  email             VARCHAR(255)    NULL,
  event_type        VARCHAR(64)     NOT NULL,

  beer              VARCHAR(128)    NULL,                       -- col AI (idx 34)
  batch             VARCHAR(32)     NULL,                       -- col AJ
  brew              VARCHAR(32)     NULL,                       -- col AK
  brew_start        DATETIME        NULL,                       -- col AL
  brew_end          DATETIME        NULL,                       -- col AM

  -- IMPORTRANGE'd trailing cols
  concatenate       VARCHAR(255)    NULL,
  event_date        DATE            NULL,
  start_ferm        VARCHAR(255)    NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash         (row_hash),
  KEY idx_sheet_row_index          (sheet_row_index),
  KEY idx_submitted_at             (submitted_at),
  KEY idx_email                    (email),
  KEY idx_beer_batch               (beer, batch),
  KEY idx_event_date               (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
