-- bd_packaging
-- Mirror of BSF!PackagingData (A:CN). The 15 paired O2/CO2 readings (cols
-- 14-43, indices N-AR) are externalized to bd_packaging_readings — see 008.
-- All trailing computed/IMPORTRANGE'd cols are kept verbatim per user request.

CREATE TABLE IF NOT EXISTS bd_packaging (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash                 CHAR(64)        NOT NULL,
  sheet_row_index          INT UNSIGNED    NOT NULL,
  imported_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- col 0 (A) header is empty in source — skipped (no semantic).
  submitted_at             DATETIME(6)     NULL,                   -- col B (idx 1)
  email                    VARCHAR(255)    NULL,                   -- col C (idx 2)

  last_cip_date            VARCHAR(32)     NULL,                   -- col D
  cip_type                 VARCHAR(64)     NULL,                   -- col E
  tank_co2                 DECIMAL(6,3)    NULL,                   -- col F
  tank_o2                  DECIMAL(6,3)    NULL,                   -- col G
  client                   VARCHAR(128)    NULL,                   -- col H

  neb_beer                 VARCHAR(128)    NULL,                   -- col I (idx 8): Recettes Nébuleuse
  neb_batch                VARCHAR(32)     NULL,                   -- col J (idx 9)
  neb_dlc                  VARCHAR(32)     NULL,                   -- col K (idx 10): DLC raw

  contract_beer            VARCHAR(128)    NULL,                   -- col L (idx 11)
  contract_batch           VARCHAR(32)     NULL,                   -- col M (idx 12)
  contract_dlc             VARCHAR(32)     NULL,                   -- col N (idx 13): DLC raw

  -- cols 14-43 (15 paired O2/CO2 readings) → bd_packaging_readings child table

  -- Form continues at col AS (idx 44)
  format                   VARCHAR(16)     NULL,                   -- col AS: Bot | Can | Keg | Cuve de service
  sel_can                  VARCHAR(64)     NULL,                   -- col AT
  sel_pack_can             VARCHAR(64)     NULL,                   -- col AU
  sel_bottle               VARCHAR(64)     NULL,                   -- col AV
  sel_pack_bot             VARCHAR(64)     NULL,                   -- col AW

  prod_total_units         INT UNSIGNED    NULL,                   -- col AX (idx 49)
  unsaleable_units         INT UNSIGNED    NULL,                   -- col AY

  loss_liquid_l            DECIMAL(10,3)   NULL,                   -- col AZ (litres)
  loss_4pack               INT UNSIGNED    NULL,                   -- col BA
  loss_wrap                INT UNSIGNED    NULL,                   -- col BB
  loss_label               INT UNSIGNED    NULL,                   -- col BC
  loss_cap                 INT UNSIGNED    NULL,                   -- col BD
  loss_container           INT UNSIGNED    NULL,                   -- col BE

  special_flag             VARCHAR(8)      NULL,                   -- col BF: Oui | Non
  special_container        VARCHAR(64)     NULL,                   -- col BG
  special_pack             VARCHAR(64)     NULL,                   -- col BH
  special_qty_units        INT UNSIGNED    NULL,                   -- col BI (idx 60)
  special_pack_qty         INT UNSIGNED    NULL,                   -- col BJ (idx 61)

  comments                 TEXT            NULL,                   -- col BK (idx 62)
  vendable_hl              DECIMAL(8,3)    NULL,                   -- col BL (idx 63)

  -- IMPORTRANGE'd computed cols (idx 64+). Kept verbatim per user request.
  total_with_losses_hl     DECIMAL(8,3)    NULL,                   -- col BM (idx 64)
  objective_volume_hl      DECIMAL(8,3)    NULL,                   -- col BN (idx 65)
  result                   VARCHAR(255)    NULL,                   -- col BO (idx 66)
  avg_o2                   DECIMAL(6,3)    NULL,                   -- col BP (idx 67)
  avg_co2                  DECIMAL(6,3)    NULL,                   -- col BQ (idx 68)
  min_o2                   DECIMAL(6,3)    NULL,                   -- col BR (idx 69)
  max_o2                   DECIMAL(6,3)    NULL,                   -- col BS (idx 70)
  beer                     VARCHAR(128)    NULL,                   -- col BT (idx 71): canonical beer/SKU code
  batch                    VARCHAR(32)     NULL,                   -- col BU (idx 72)
  pct_loss                 DECIMAL(8,4)    NULL,                   -- col BV (idx 73): %Losses
  delta_o2_pickup          DECIMAL(6,3)    NULL,                   -- col BW (idx 74)
  recipe_code              VARCHAR(32)     NULL,                   -- col BX (idx 75): e.g. "SPY 13"
  recipe_name              VARCHAR(64)     NULL,                   -- col BY (idx 76): e.g. "Speakeasy"
  weeknum                  SMALLINT UNSIGNED NULL,                 -- col BZ (idx 77)
  year                     SMALLINT UNSIGNED NULL,                 -- col CA (idx 78)
  total_units              INT UNSIGNED    NULL,                   -- col CB (idx 79)
  month                    TINYINT UNSIGNED NULL,                  -- col CC (idx 80)
  timestamp_2              DATETIME(6)     NULL,                   -- col CD (idx 81): second Timestamp
  second_packaging         VARCHAR(64)     NULL,                   -- col CE (idx 82): "2ème Packaging"
  second_packaging_qty     INT UNSIGNED    NULL,                   -- col CF (idx 83)
  hl_second_packaging      DECIMAL(8,3)    NULL,                   -- col CG (idx 84)
  hl_first_packaging       DECIMAL(8,3)    NULL,                   -- col CH (idx 85)
  weeknum_alt              SMALLINT UNSIGNED NULL,                 -- col CI (idx 86)

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash       (row_hash),
  KEY idx_sheet_row_index        (sheet_row_index),
  KEY idx_submitted_at           (submitted_at),
  KEY idx_email                  (email),
  KEY idx_neb_beer_batch         (neb_beer, neb_batch),
  KEY idx_contract_beer_batch    (contract_beer, contract_batch),
  KEY idx_beer_batch             (beer, batch),
  KEY idx_year_month             (year, month),
  KEY idx_format                 (format)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
