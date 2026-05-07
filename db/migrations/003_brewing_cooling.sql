-- bd_brewing_cooling
-- Mirror of BSF!BrewingData rows where col C event_type = 'Cooling'.
-- Captures the post-cooling final readings: final OG (in Plato — see memory:
-- "BrewingData Final Gravity col is OG-at-cooling"), pH, volume, dilution.

CREATE TABLE IF NOT EXISTS bd_brewing_cooling (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash             CHAR(64)        NOT NULL,
  sheet_row_index      INT UNSIGNED    NOT NULL,
  imported_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at         DATETIME(6)     NULL,                       -- col A
  email                VARCHAR(255)    NULL,                       -- col B
  event_type           VARCHAR(64)     NOT NULL,                   -- col C

  -- Cooling block (cols 27-33)
  cool_beer            VARCHAR(128)    NULL,                       -- col AB
  cool_batch           VARCHAR(32)     NULL,                       -- col AC
  cool_brew            VARCHAR(32)     NULL,                       -- col AD
  cool_final_ph        DECIMAL(4,2)    NULL,                       -- col AE
  cool_final_gravity   DECIMAL(6,3)    NULL,                       -- col AF: Final Gravity = OG-at-cooling (°Plato)
  cool_final_volume_hl DECIMAL(8,3)    NULL,                       -- col AG: Final Volume (HL)
  cool_batch_dilution  VARCHAR(64)     NULL,                       -- col AH

  -- IMPORTRANGE'd computed cols (cols 48-50)
  concatenate          VARCHAR(255)    NULL,                       -- col AW
  event_date           DATE            NULL,                       -- col AX
  start_ferm           VARCHAR(255)    NULL,                       -- col AY

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash       (row_hash),
  KEY idx_sheet_row_index        (sheet_row_index),
  KEY idx_submitted_at           (submitted_at),
  KEY idx_email                  (email),
  KEY idx_cool_beer_batch        (cool_beer, cool_batch),
  KEY idx_event_date             (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
