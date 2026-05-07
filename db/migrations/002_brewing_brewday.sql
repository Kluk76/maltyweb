-- bd_brewing_brewday
-- Mirror of BSF!BrewingData rows where col C event_type = 'Brewday'.
-- Captures the brewday submission: CCT info, yeast, intermediate gravities,
-- brew start/end times. Each Sheets row maps to ONE row here (1:1, not split).
-- Idempotence: row_hash = SHA-256(canonical row content).

CREATE TABLE IF NOT EXISTS bd_brewing_brewday (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash             CHAR(64)        NOT NULL,
  sheet_row_index      INT UNSIGNED    NOT NULL,
  imported_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- common form columns (cols 0-2)
  submitted_at         DATETIME(6)     NULL,                       -- col A: Timestamp
  email                VARCHAR(255)    NULL,                       -- col B: Email Address
  event_type           VARCHAR(64)     NOT NULL,                   -- col C: Type de Tâche

  -- Brewday block (cols 3-13)
  bd_beer              VARCHAR(128)    NULL,                       -- col D
  bd_batch             VARCHAR(32)     NULL,                       -- col E
  bd_cct               VARCHAR(32)     NULL,                       -- col F: CCT
  bd_cct_cip           VARCHAR(32)     NULL,                       -- col G
  bd_cct_cip_date      VARCHAR(32)     NULL,                       -- col H: DD.MM.YYYY raw
  bd_yeast             VARCHAR(128)    NULL,                       -- col I
  bd_yeast_gen         VARCHAR(32)     NULL,                       -- col J
  bd_yeast_new         VARCHAR(255)    NULL,                       -- col K: If New Yeast - Which one ?
  bd_pitched_from      VARCHAR(255)    NULL,                       -- col L
  bd_yt                VARCHAR(32)     NULL,                       -- col M: YT Number
  bd_yt_cip_date       VARCHAR(32)     NULL,                       -- col N

  -- First Wort gravity (cols 14-18)
  first_wort_beer      VARCHAR(128)    NULL,                       -- col O
  first_wort_batch     VARCHAR(32)     NULL,                       -- col P
  first_wort_brew      VARCHAR(32)     NULL,                       -- col Q
  first_wort_gravity   DECIMAL(6,3)    NULL,                       -- col R
  first_wort_ph        DECIMAL(4,2)    NULL,                       -- col S

  -- Pfannevoll gravity (cols 19-22)
  pfann_beer           VARCHAR(128)    NULL,                       -- col T
  pfann_batch          VARCHAR(32)     NULL,                       -- col U
  pfann_brew           VARCHAR(32)     NULL,                       -- col V
  pfann_gravity        DECIMAL(6,3)    NULL,                       -- col W

  -- Kochwürze gravity (cols 23-26)
  koch_beer            VARCHAR(128)    NULL,                       -- col X
  koch_batch           VARCHAR(32)     NULL,                       -- col Y
  koch_brew            VARCHAR(32)     NULL,                       -- col Z
  koch_gravity         DECIMAL(6,3)    NULL,                       -- col AA

  -- Brew start/end block (cols 34-38)
  brew_beer            VARCHAR(128)    NULL,                       -- col AI
  brew_batch           VARCHAR(32)     NULL,                       -- col AJ
  brew_label           VARCHAR(64)     NULL,                       -- col AK: Brew
  brew_start           DATETIME        NULL,                       -- col AL
  brew_end             DATETIME        NULL,                       -- col AM

  -- IMPORTRANGE'd computed cols (still raw in our context — kept verbatim, cols 48-50)
  concatenate          VARCHAR(255)    NULL,                       -- col AW
  event_date           DATE            NULL,                       -- col AX
  start_ferm           VARCHAR(255)    NULL,                       -- col AY

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash       (row_hash),
  KEY idx_sheet_row_index        (sheet_row_index),
  KEY idx_submitted_at           (submitted_at),
  KEY idx_email                  (email),
  KEY idx_bd_beer_batch          (bd_beer, bd_batch),
  KEY idx_event_date             (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
