-- Migration 285 — Multi-location FG stock: ref_sites site_type + FG flags,
--               inv_fg_stocktake location dimension, inv_fg_transfers inter-site moves
--
-- WHAT:
--   1. Extends ref_sites with site_type ENUM, holds_fg_stock flag,
--      customer_id_fk (consignment sites), sort_order.
--      Seeds Taproom (pos) and Nausikraft (consignment) sites.
--      Backfills existing Production + Logistique rows.
--   2. Adds location_id_fk to inv_fg_stocktake; backfills 41 April rows to site 1
--      (Production, Renens — single-pool count location); tightens to NOT NULL;
--      drops + recreates uniq_sku_month_response to include location_id_fk.
--   3. Creates inv_fg_transfers for inter-site pallet moves (net-zero on total,
--      shifts per-location physical stock). Registers in schema_meta.
--
-- WHY:
--   Stock PF will track FG physically at Production, Logistique, Taproom,
--   and consignment at Nausikraft. A single-pool stocktake is no longer sufficient.
--   Transfers record pallet moves so per-location derivation stays correct.
--
-- RISK:
--   inv_fg_stocktake uniq_sku_month_response index DROP + recreate is safe because:
--   all 41 existing rows backfilled to same location_id_fk=1, so the (sku, month_closed,
--   location_id_fk, source_form_response_id) tuple stays unique for all existing rows.
--   inv_fg_transfers is a new table — zero blast radius on existing consumers.
--
-- ROLLBACK:
--   DROP TABLE inv_fg_transfers;
--   ALTER TABLE inv_fg_stocktake
--     DROP FOREIGN KEY fk_fg_stocktake_location,
--     DROP INDEX idx_fg_stocktake_location_sku,
--     DROP COLUMN location_id_fk;
--   -- Restore old unique (no location column):
--   ALTER TABLE inv_fg_stocktake
--     ADD UNIQUE KEY uniq_sku_month_response (sku, month_closed, source_form_response_id);
--   DELETE FROM ref_sites WHERE name IN ('La Nébuleuse - Taproom', 'Nausikraft (consignation)');
--   ALTER TABLE ref_sites
--     DROP FOREIGN KEY fk_ref_sites_customer,
--     DROP COLUMN site_type,
--     DROP COLUMN holds_fg_stock,
--     DROP COLUMN customer_id_fk,
--     DROP COLUMN sort_order;
-- ---------------------------------------------------------------------------

-- ============================================================
-- 1. Extend ref_sites
-- ============================================================

ALTER TABLE ref_sites
  ADD COLUMN site_type     ENUM('production','warehouse','pos','consignment') NOT NULL DEFAULT 'warehouse' AFTER notes,
  ADD COLUMN holds_fg_stock TINYINT(1) NOT NULL DEFAULT 0                                                  AFTER site_type,
  ADD COLUMN customer_id_fk INT UNSIGNED NULL                                                              AFTER holds_fg_stock,
  ADD COLUMN sort_order     INT         NOT NULL DEFAULT 0                                                 AFTER customer_id_fk;

ALTER TABLE ref_sites
  ADD CONSTRAINT fk_ref_sites_customer
    FOREIGN KEY (customer_id_fk) REFERENCES ref_customers(id) ON DELETE SET NULL;

-- Backfill existing sites
UPDATE ref_sites SET site_type = 'production', holds_fg_stock = 1, sort_order = 10 WHERE id = 1;
UPDATE ref_sites SET site_type = 'warehouse',  holds_fg_stock = 1, sort_order = 20 WHERE id = 2;

-- Insert Taproom
INSERT INTO ref_sites (name, city, country, is_active, site_type, holds_fg_stock, sort_order, updated_by)
  VALUES ('La Nébuleuse - Taproom', 'Renens', 'CH', 1, 'pos', 1, 30, 'mig');

-- Insert Nausikraft consignment
INSERT INTO ref_sites (name, country, is_active, site_type, holds_fg_stock, customer_id_fk, sort_order, updated_by)
  VALUES ('Nausikraft (consignation)', 'CH', 1, 'consignment', 1, 1, 40, 'mig');

-- ============================================================
-- 2. inv_fg_stocktake — location dimension
-- ============================================================

-- Add column nullable first to allow backfill
ALTER TABLE inv_fg_stocktake
  ADD COLUMN location_id_fk INT UNSIGNED NULL AFTER source_form_response_id;

-- Backfill all 41 existing April rows to Production site (id=1)
UPDATE inv_fg_stocktake SET location_id_fk = 1;

-- Add FK constraint (after backfill so no NULL FK rows remain)
ALTER TABLE inv_fg_stocktake
  ADD CONSTRAINT fk_fg_stocktake_location
    FOREIGN KEY (location_id_fk) REFERENCES ref_sites(id) ON DELETE RESTRICT;

-- Tighten to NOT NULL
ALTER TABLE inv_fg_stocktake
  MODIFY COLUMN location_id_fk INT UNSIGNED NOT NULL;

-- Drop old unique (did not include location), recreate with location included
ALTER TABLE inv_fg_stocktake
  DROP INDEX uniq_sku_month_response;

ALTER TABLE inv_fg_stocktake
  ADD UNIQUE KEY uniq_sku_month_location_response (sku, month_closed, location_id_fk, source_form_response_id);

-- Composite index for per-location derivation reads
ALTER TABLE inv_fg_stocktake
  ADD KEY idx_fg_stocktake_location_sku (location_id_fk, sku_id_fk);

-- ============================================================
-- 3. inv_fg_transfers — inter-site pallet moves
-- ============================================================

CREATE TABLE inv_fg_transfers (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_site_id_fk     INT UNSIGNED    NOT NULL,
  to_site_id_fk       INT UNSIGNED    NOT NULL,
  sku_id_fk           INT UNSIGNED    NOT NULL,
  qty                 DECIMAL(10,2)   NOT NULL,
  transfer_date       DATE            NOT NULL,
  comment             VARCHAR(255)    NULL,
  created_by_user_id  INT UNSIGNED    NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_fg_transfers_from_site
    FOREIGN KEY (from_site_id_fk) REFERENCES ref_sites(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_fg_transfers_to_site
    FOREIGN KEY (to_site_id_fk)   REFERENCES ref_sites(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_fg_transfers_sku
    FOREIGN KEY (sku_id_fk)       REFERENCES ref_skus(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_fg_transfers_user
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)   ON DELETE SET NULL,

  CONSTRAINT inv_fg_transfers_chk_qty_pos
    CHECK (qty > 0),
  CONSTRAINT inv_fg_transfers_chk_sites_differ
    CHECK (from_site_id_fk <> to_site_id_fk),

  KEY idx_fg_transfers_transfer_date (transfer_date),
  KEY idx_fg_transfers_sku_id_fk     (sku_id_fk)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta registration
INSERT INTO schema_meta
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('inv_fg_transfers', 'source', 'allowed',
   'public/modules/expeditions.php (transfer saisie)',
   'Inter-site FG pallet moves. Net-zero on total stock; shifts per-location physique. Writer: Expéditions transfer form.');
