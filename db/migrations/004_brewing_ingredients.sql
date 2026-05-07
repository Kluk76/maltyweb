-- bd_brewing_ingredients
-- Mirror of BSF!BrewingData rows where col C event_type = 'Ingredients & Lot Numbers'.
-- Captures malt + hops + comments per batch. Ingredient cells are stored as
-- TEXT raw (newline-separated triplets name/qty/lot); parsing is deferred.

CREATE TABLE IF NOT EXISTS bd_brewing_ingredients (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash             CHAR(64)        NOT NULL,
  sheet_row_index      INT UNSIGNED    NOT NULL,
  imported_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at         DATETIME(6)     NULL,                       -- col A
  email                VARCHAR(255)    NULL,                       -- col B
  event_type           VARCHAR(64)     NOT NULL,                   -- col C

  -- Ingredients block (cols 39-43)
  ing_beer             VARCHAR(128)    NULL,                       -- col AN
  ing_batch            VARCHAR(32)     NULL,                       -- col AO
  ing_malt_raw         TEXT            NULL,                       -- col AP: newline-separated triplets
  ing_hops_raw         TEXT            NULL,                       -- col AQ: newline-separated triplets
  comments             TEXT            NULL,                       -- col AR: header reads "Commentaires"

  -- IMPORTRANGE'd computed cols (cols 48-50)
  concatenate          VARCHAR(255)    NULL,                       -- col AW
  event_date           DATE            NULL,                       -- col AX
  start_ferm           VARCHAR(255)    NULL,                       -- col AY

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash       (row_hash),
  KEY idx_sheet_row_index        (sheet_row_index),
  KEY idx_submitted_at           (submitted_at),
  KEY idx_email                  (email),
  KEY idx_ing_beer_batch         (ing_beer, ing_batch),
  KEY idx_event_date             (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
