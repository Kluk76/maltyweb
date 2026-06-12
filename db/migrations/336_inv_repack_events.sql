-- 336_inv_repack_events.sql
-- Eshop repacking capture (Phase A): same-site, bottle-count-conserving
-- state conversion: −from_pack (+derived loose) → loose/bundle/PD8-slot.
-- Append-only, tombstone-only. Unit-counts only — NOT COGS, NOT beer-tax.
CREATE TABLE `inv_repack_events` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id_fk`           INT UNSIGNED    NOT NULL COMMENT 'Same-site conversion: from and to are the SAME site.',
  `from_sku_id_fk`       INT UNSIGNED    NOT NULL COMMENT 'Source pack opened (base box, scope=base in Phase A).',
  `from_qty`             INT             NOT NULL COMMENT 'Packs opened (>0).',
  `to_sku_id_fk`         INT UNSIGNED        NULL COMMENT 'Resulting bundle SKU; NULL when to-side is pure loose or PD8 multi-slot.',
  `to_qty`               INT             NOT NULL DEFAULT 0 COMMENT 'Bundles/PD8 produced (>=0).',
  `loose_units`          INT             NOT NULL COMMENT 'Signed loose-unit delta = from_qty*from.units_per_pack − Σ(to-side units).',
  `to_kind`              ENUM('bundle','pd8','loose','adjustment') NOT NULL COMMENT 'Decomposition shape.',
  `source_order_id_fk`   BIGINT UNSIGNED     NULL COMMENT 'inv_sales_orders.id when auto-proposed from an eshop order; NULL for ad-hoc.',
  `moved_on`             DATE            NOT NULL,
  `note`                 VARCHAR(255)        NULL,
  `is_tombstoned`        TINYINT(1)      NOT NULL DEFAULT 0,
  `tombstoned_at`        DATETIME            NULL,
  `submitted_by_user_fk` INT UNSIGNED        NULL,
  `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `repack_key`           VARCHAR(64)         NULL COMMENT 'Idempotency for auto rows: order_id:from_sku_id. NULL for ad-hoc/tombstoned.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repack_key` (`repack_key`),
  KEY `idx_repack_site_live` (`site_id_fk`,`is_tombstoned`,`moved_on`),
  KEY `idx_repack_from_sku` (`from_sku_id_fk`,`is_tombstoned`),
  KEY `idx_repack_to_sku` (`to_sku_id_fk`),
  KEY `idx_repack_order` (`source_order_id_fk`),
  KEY `fk_repack_user` (`submitted_by_user_fk`),
  CONSTRAINT `fk_repack_site`     FOREIGN KEY (`site_id_fk`)         REFERENCES `ref_sites` (`id`)        ON DELETE RESTRICT,
  CONSTRAINT `fk_repack_from_sku` FOREIGN KEY (`from_sku_id_fk`)     REFERENCES `ref_skus` (`id`)         ON DELETE RESTRICT,
  CONSTRAINT `fk_repack_to_sku`   FOREIGN KEY (`to_sku_id_fk`)       REFERENCES `ref_skus` (`id`)         ON DELETE RESTRICT,
  CONSTRAINT `fk_repack_order`    FOREIGN KEY (`source_order_id_fk`) REFERENCES `inv_sales_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_repack_user`     FOREIGN KEY (`submitted_by_user_fk`) REFERENCES `users` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `chk_repack_from_pos`  CHECK (`from_qty` > 0),
  CONSTRAINT `chk_repack_to_nonneg` CHECK (`to_qty` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES (
  'inv_repack_events', 'source',
  'public/api/expeditions-repack.php',
  'allowed',
  'inv_sales_orders + ref_skus.units_per_pack / ref_sku_composite_slots (auto-decomposition)',
  'Eshop repacking capture (Phase A). Same-site bottle-count-conserving conversion: −from_sku pack → +to_sku/loose. Unit-counts ONLY — NOT COGS, NOT beer-tax (net-HL-zero). Feeds fg_stock_compute()+fg_stock_location_snapshot() symmetric repack leg (R1: Σcards==Σphysique). Soft-delete via is_tombstoned. Phase B (cage-vs-box COGS) gated on 2026-06-15 cage anchor.'
);
