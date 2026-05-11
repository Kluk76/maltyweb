-- 031 — Inventory snapshot tables (RM stocktake, FG stocktake, tank balances).
--
-- Three tables:
--   inv_rm_stocktake     : one row per (MI_ID × period) — mirrors RM_StockTake tab
--   inv_fg_stocktake     : one row per (SKU × month_closed × form_response_id)
--   inv_tank_balances    : one row per (tank_id × month_key)
--
-- RM stocktake logic (from lib/inventory.js parseRMRows):
--   final_qty = counted_qty when counted_qty is an explicit number (including 0);
--   falls back to expected_qty when counted_qty is NULL (operator skipped row).
--   final_qty is stored as a generated column (COALESCE) so queries don't need to
--   replicate the fallback logic.
--   Categories 'Logistics' and 'Keg' are excluded from COGS; this filter lives in
--   queries, not in the schema.
--
-- FG stocktake additive aggregation: multiple submissions per month are summed in
--   queries (matching parseFGRows behavior). UNIQUE on (sku, month_closed,
--   source_form_response_id) preserves individual submissions for re-run idempotence.
--
-- Tank balances: UNIQUE on (tank_id, month_key) — one snapshot per tank per month.
--   brew_cost_per_hl comes from SKU_BOM beerBrewCostPerHL at the time of computation.
--
-- FK on mi_id_fk in inv_rm_stocktake: RESTRICT (not CASCADE) — a missing MI_ID
--   in ref_mi is a data-quality signal, not a normal delete path.
--
-- is_active on inv_rm_stocktake and inv_fg_stocktake: soft-delete for corrected
--   periods; the canonical row stays active=1, a superseded row is marked active=0.
-- inv_tank_balances: no is_active — recomputed each month-close run via ON DUPLICATE KEY.
--
-- No seeding — ingest scripts and build scripts own all data population.

CREATE TABLE IF NOT EXISTS inv_rm_stocktake (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mi_id                   VARCHAR(64)     NOT NULL,
  mi_id_fk                INT UNSIGNED    NULL,
  period                  CHAR(7)         NOT NULL,
  expected_qty            DECIMAL(14,4)   NULL,
  counted_qty             DECIMAL(14,4)   NULL,
  final_qty               DECIMAL(14,4)   GENERATED ALWAYS AS (COALESCE(counted_qty, expected_qty)) STORED,
  source                  VARCHAR(32)     NULL,
  counted_by              VARCHAR(128)    NULL,
  counted_at              DATETIME        NULL,
  source_form_response_id VARCHAR(255)    NULL,
  notes                   TEXT            NULL,
  is_active               TINYINT(1)      NOT NULL DEFAULT 1,
  row_hash                CHAR(64)        NOT NULL,
  created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_mi_period          (mi_id, period),
  UNIQUE KEY uniq_row_hash           (row_hash),
  KEY idx_inv_rm_mi_id_fk            (mi_id_fk),
  KEY idx_inv_rm_period              (period),
  KEY idx_inv_rm_active              (is_active),
  CONSTRAINT fk_inv_rm_mi            FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_fg_stocktake (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku                     VARCHAR(64)     NOT NULL,
  month_closed            CHAR(7)         NOT NULL,
  qty                     INT             NOT NULL DEFAULT 0,
  form_timestamp          DATETIME        NULL,
  submitted_by            VARCHAR(255)    NULL,
  source_form_response_id VARCHAR(255)    NULL,
  is_active               TINYINT(1)      NOT NULL DEFAULT 1,
  row_hash                CHAR(64)        NOT NULL,
  created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sku_month_response   (sku, month_closed, source_form_response_id),
  UNIQUE KEY uniq_row_hash             (row_hash),
  KEY idx_inv_fg_month_closed          (month_closed),
  KEY idx_inv_fg_sku                   (sku),
  KEY idx_inv_fg_active                (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_tank_balances (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tank_id             VARCHAR(32)     NOT NULL,
  month_key           CHAR(7)         NOT NULL,
  tank_type           VARCHAR(8)      NULL,
  beer_name           VARCHAR(128)    NULL,
  batch               VARCHAR(32)     NULL,
  volume_hl           DECIMAL(10,3)   NOT NULL DEFAULT 0,
  brew_cost_per_hl    DECIMAL(10,4)   NULL,
  blend_detail        VARCHAR(512)    NULL,
  computed_at         DATETIME        NULL,
  row_hash            CHAR(64)        NOT NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tank_month           (tank_id, month_key),
  UNIQUE KEY uniq_row_hash             (row_hash),
  KEY idx_inv_tank_month_key           (month_key),
  KEY idx_inv_tank_beer                (beer_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Down-migration order:
--   DROP TABLE IF EXISTS inv_tank_balances;
--   DROP TABLE IF EXISTS inv_fg_stocktake;
--   DROP TABLE IF EXISTS inv_rm_stocktake;
