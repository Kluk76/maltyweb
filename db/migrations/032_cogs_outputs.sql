-- 032 — COGS and COP output tables.
--
-- Three tables:
--   cogs_monthly                 : RM/FG/WIP inventory values by month + category
--   cop_monthly                  : COP line items by month + section (variable cost structure)
--   mi_weighted_prices_monthly   : weighted-average ingredient prices per month
--
-- cogs_monthly captures the opening/inflows/outflows/closing reconciliation for each
--   inventory layer (rm/fg/wip) × category. Corresponds to the Month_Closure tab output
--   from build-month-closure.js. UNIQUE on (month_key, category, subcategory, source)
--   allows idempotent recompute via ON DUPLICATE KEY UPDATE.
--
-- cop_monthly captures the variable cost structure (COP = COGS Variable):
--   sections: brewing, packaging, indirect, utilities, rd, maintenance, sales_visible.
--   mi_id_fk is nullable — section-level rows have no single MI attribution.
--   UNIQUE on (month_key, section, category, mi_id_fk) is not sufficient when mi_id_fk
--   is NULL (MySQL treats two NULLs as distinct in UNIQUE indexes), so we use a surrogate
--   natural key via row_hash for dedup.
--
-- mi_weighted_prices_monthly stores the per-MI weighted-average CHF price computed by
--   compute-weighted-prices.js from Active deliveries in the window. UNIQUE on
--   (mi_id_fk, month_key) — one price snapshot per ingredient per month.
--
-- All three tables are computed outputs: no is_active (no semantic delete — recompute
--   overwrites via ON DUPLICATE KEY or row_hash dedup). A full month recompute truncates
--   the month's rows and re-inserts; partial recompute uses ON DUPLICATE KEY UPDATE.
--
-- No seeding — build scripts own all data population.

CREATE TABLE IF NOT EXISTS cogs_monthly (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  month_key             CHAR(7)         NOT NULL,
  category              VARCHAR(64)     NOT NULL,
  subcategory           VARCHAR(64)     NOT NULL DEFAULT '',
  source                ENUM('rm','fg','wip') NOT NULL,
  opening_value_chf     DECIMAL(14,2)   NOT NULL DEFAULT 0,
  inflows_value_chf     DECIMAL(14,2)   NOT NULL DEFAULT 0,
  outflows_value_chf    DECIMAL(14,2)   NOT NULL DEFAULT 0,
  closing_value_chf     DECIMAL(14,2)   NOT NULL DEFAULT 0,
  computed_at           DATETIME        NOT NULL,
  row_hash              CHAR(64)        NOT NULL,
  created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_month_cat_subcat_src   (month_key, category, subcategory, source),
  UNIQUE KEY uniq_row_hash               (row_hash),
  KEY idx_cogs_monthly_month_key         (month_key),
  KEY idx_cogs_monthly_source            (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cop_monthly (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  month_key     CHAR(7)         NOT NULL,
  section       ENUM('brewing','packaging','indirect','utilities','rd','maintenance','sales_visible') NOT NULL,
  category      VARCHAR(64)     NOT NULL DEFAULT '',
  mi_id_fk      INT UNSIGNED    NULL,
  value_chf     DECIMAL(14,2)   NOT NULL DEFAULT 0,
  computed_at   DATETIME        NOT NULL,
  row_hash      CHAR(64)        NOT NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_row_hash             (row_hash),
  KEY idx_cop_monthly_month_section    (month_key, section),
  KEY idx_cop_monthly_mi_id_fk         (mi_id_fk),
  CONSTRAINT fk_cop_monthly_mi         FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mi_weighted_prices_monthly (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mi_id_fk                 INT UNSIGNED    NOT NULL,
  month_key                CHAR(7)         NOT NULL,
  weighted_price_chf       DECIMAL(14,4)   NOT NULL,
  source_deliveries_count  INT             NOT NULL DEFAULT 0,
  computed_at              DATETIME        NOT NULL,
  row_hash                 CHAR(64)        NOT NULL,
  created_at               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_mi_month             (mi_id_fk, month_key),
  UNIQUE KEY uniq_row_hash             (row_hash),
  KEY idx_mi_wp_month_key              (month_key),
  CONSTRAINT fk_mi_wp_mi               FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Down-migration order:
--   DROP TABLE IF EXISTS mi_weighted_prices_monthly;
--   DROP TABLE IF EXISTS cop_monthly;
--   DROP TABLE IF EXISTS cogs_monthly;
