-- Migration 299: fulfilment site attribution
-- Adds:
--   1. inv_stock_movements   — inter-site FG stock transfer log (tombstone-only)
--   2. ord_orders.fulfilment_site_id_fk   — per-order site override
--   3. ref_customers.default_delivery_site_id_fk — customer default delivery site
--   4. schema_meta row for inv_stock_movements
-- No consumers wired in this migration (Phase 1 of 2).

-- ─── 1. inv_stock_movements ────────────────────────────────────────────────
CREATE TABLE `inv_stock_movements` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku_id_fk`            INT UNSIGNED    NOT NULL,
  `from_site_id_fk`      INT UNSIGNED    NOT NULL,
  `to_site_id_fk`        INT UNSIGNED    NOT NULL,
  `qty`                  DECIMAL(10,2)   NOT NULL,
  `moved_on`             DATE            NOT NULL,
  `comment`              TEXT            NULL,
  `is_tombstoned`        TINYINT(1)      NOT NULL DEFAULT 0,
  `created_by_user_id`   INT UNSIGNED    NULL,
  `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  CONSTRAINT `chk_inv_stock_mov_diff` CHECK (`from_site_id_fk` <> `to_site_id_fk`),
  CONSTRAINT `chk_inv_stock_mov_qty`  CHECK (`qty` > 0),

  CONSTRAINT `fk_inv_stock_mov_sku`  FOREIGN KEY (`sku_id_fk`)          REFERENCES `ref_skus`(`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_stock_mov_from` FOREIGN KEY (`from_site_id_fk`)    REFERENCES `ref_sites`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_stock_mov_to`   FOREIGN KEY (`to_site_id_fk`)      REFERENCES `ref_sites`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_stock_mov_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`)     ON DELETE SET NULL,

  KEY `idx_inv_stock_mov_sku`          (`sku_id_fk`),
  KEY `idx_inv_stock_mov_moved_on`     (`moved_on`),
  KEY `idx_inv_stock_mov_tombstoned`   (`is_tombstoned`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2. ord_orders.fulfilment_site_id_fk ───────────────────────────────────
ALTER TABLE `ord_orders`
  ADD COLUMN `fulfilment_site_id_fk` INT UNSIGNED NULL
    AFTER `transporter_id_fk`,
  ADD CONSTRAINT `fk_ord_orders_fulfil_site`
    FOREIGN KEY (`fulfilment_site_id_fk`) REFERENCES `ref_sites`(`id`) ON DELETE RESTRICT;

-- ─── 3. ref_customers.default_delivery_site_id_fk ──────────────────────────
ALTER TABLE `ref_customers`
  ADD COLUMN `default_delivery_site_id_fk` INT UNSIGNED NULL
    AFTER `default_transporter_id_fk`,
  ADD CONSTRAINT `fk_ref_customers_deliv_site`
    FOREIGN KEY (`default_delivery_site_id_fk`) REFERENCES `ref_sites`(`id`) ON DELETE SET NULL;

-- ─── 4. schema_meta row for inv_stock_movements ────────────────────────────
-- mirrors inv_fg_stocktake pattern: source class, allowed corrections, tombstone-only policy
INSERT INTO `schema_meta`
  (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`, `updated_at`)
VALUES
  (
    'inv_stock_movements',
    'source',
    'app/fulfilment-site.php (future consumer)',
    'allowed',
    NULL,
    'Inter-site FG stock movements. Soft-delete via is_tombstoned=1; no hard deletes.',
    NOW()
  )
ON DUPLICATE KEY UPDATE
  `table_class`        = VALUES(`table_class`),
  `writer_script`      = VALUES(`writer_script`),
  `corrections_policy` = VALUES(`corrections_policy`),
  `notes`              = VALUES(`notes`),
  `updated_at`         = NOW();
