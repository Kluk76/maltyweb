-- bd_racking
-- Mirror of BSF!RackingData (A:AF). Captures one CIP+rack event per row.
-- The "computed-tail" cols (24-31) are kept verbatim per user instruction
-- (IMPORTRANGE source data, not local sheet formulas). Notably col Z (idx 25)
-- holds the canonical BBT id (per project memory: "RackingData BBT col is Z").

CREATE TABLE IF NOT EXISTS bd_racking (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  row_hash                 CHAR(64)        NOT NULL,
  sheet_row_index          INT UNSIGNED    NOT NULL,
  imported_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  submitted_at             DATETIME(6)     NULL,                   -- col A
  email                    VARCHAR(255)    NULL,                   -- col B
  last_cip_date            VARCHAR(32)     NULL,                   -- col C: DD.MM.YYYY raw
  cip_type                 VARCHAR(64)     NULL,                   -- col D
  rack_type                VARCHAR(32)     NULL,                   -- col E: Centri | KZE | Centri+KZE | Pump
  client                   VARCHAR(128)    NULL,                   -- col F: Nébuleuse | contract name

  -- Nébuleuse beer/batch (cols 6-7)
  neb_beer                 VARCHAR(128)    NULL,                   -- col G
  neb_batch                VARCHAR(32)     NULL,                   -- col H

  -- Contract beer/batch (cols 8-9)
  contract_beer            VARCHAR(128)    NULL,                   -- col I
  contract_batch           VARCHAR(32)     NULL,                   -- col J

  -- Times + BBT readings (cols 10-19)
  start_time               DATETIME        NULL,                   -- col K
  end_time                 DATETIME        NULL,                   -- col L
  bbt_old                  VARCHAR(32)     NULL,                   -- col M: BBT (older format)
  bbt_co2                  DECIMAL(6,3)    NULL,                   -- col N: CO2 in BBT
  bbt_o2                   DECIMAL(6,3)    NULL,                   -- col O: O2 in BBT
  racked_vol_hl            DECIMAL(8,3)    NULL,                   -- col P: Total Racked Vol (HL)
  blend_text               TEXT            NULL,                   -- col Q: Blend description
  avg_turbidity            DECIMAL(8,3)    NULL,                   -- col R
  avg_speed                DECIMAL(8,3)    NULL,                   -- col S
  bbt_pressure             DECIMAL(6,3)    NULL,                   -- col T

  centri_rinsed            VARCHAR(8)      NULL,                   -- col U: Yes | No
  comments                 TEXT            NULL,                   -- col V: Final Comments
  blend_volume_hl          DECIMAL(8,3)    NULL,                   -- col W: Blend Volume
  nomenclature             VARCHAR(64)     NULL,                   -- col X: operator-typed

  -- IMPORTRANGE'd computed cols (cols 24-31). Kept verbatim.
  concat_nom_batch         VARCHAR(255)    NULL,                   -- col Y
  bbt                      VARCHAR(32)     NULL,                   -- col Z: canonical BBT (idx 25)
  cc_date                  DATE            NULL,                   -- col AA
  lagering_time            VARCHAR(64)     NULL,                   -- col AB
  racking_time             VARCHAR(64)     NULL,                   -- col AC: header is "RackingTIme" (sic)
  avg_seep                 DECIMAL(8,3)    NULL,                   -- col AD
  avg_turbidity_calc       DECIMAL(8,3)    NULL,                   -- col AE: second "AvgTurbidity" header
  helper                   VARCHAR(255)    NULL,                   -- col AF: "Helper Column"

  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash       (row_hash),
  KEY idx_sheet_row_index        (sheet_row_index),
  KEY idx_submitted_at           (submitted_at),
  KEY idx_email                  (email),
  KEY idx_neb_beer_batch         (neb_beer, neb_batch),
  KEY idx_contract_beer_batch    (contract_beer, contract_batch),
  KEY idx_bbt                    (bbt),
  KEY idx_cc_date                (cc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
