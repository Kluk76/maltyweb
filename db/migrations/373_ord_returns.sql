-- Migration 373: ord_returns (header) + ord_return_lines (lines)
-- Operational returned-FG tracking. Two origin populations:
--   origin_order_id_fk → ord_orders (B2B / internal fulfilment orders)
--   origin_sales_order_id_fk → inv_sales_orders (Shopify / taproom direct-sales)
-- Exactly one origin FK must be non-null (CHECK chk_ord_returns_exactly_one_origin).
-- Writer: to be wired in a future returns UI (retours.php).

CREATE TABLE `ord_returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `origin_order_id_fk` bigint unsigned DEFAULT NULL COMMENT 'FK→ord_orders(id); set when the return originates from a fulfilment order',
  `origin_sales_order_id_fk` bigint unsigned DEFAULT NULL COMMENT 'FK→inv_sales_orders(id); set when the return originates from a Shopify/taproom sale',
  `returned_on` date NOT NULL COMMENT 'Physical return date',
  `comment` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` int unsigned DEFAULT NULL COMMENT 'FK→users(id); NULL for system-created rows',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ord_returns_origin_order` (`origin_order_id_fk`),
  KEY `idx_ord_returns_origin_sales_order` (`origin_sales_order_id_fk`),
  KEY `idx_ord_returns_returned_on` (`returned_on`),
  KEY `fk_ord_returns_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_ord_returns_origin_order` FOREIGN KEY (`origin_order_id_fk`) REFERENCES `ord_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ord_returns_origin_sales_order` FOREIGN KEY (`origin_sales_order_id_fk`) REFERENCES `inv_sales_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ord_returns_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_ord_returns_exactly_one_origin` CHECK (
    ((`origin_order_id_fk` IS NOT NULL) <> (`origin_sales_order_id_fk` IS NOT NULL))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Returned-FG header. Exactly one origin FK set (ord_orders XOR inv_sales_orders). Writer: retours.php (future).';

CREATE TABLE `ord_return_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_id_fk` bigint unsigned NOT NULL COMMENT 'FK→ord_returns(id)',
  `sku_id_fk` int unsigned NOT NULL COMMENT 'FK→ref_skus(id)',
  `qty` decimal(10,2) NOT NULL COMMENT 'Returned quantity in SKU units; must be > 0',
  `disposition` enum('restock','scrap','quarantine') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Fate of returned stock: restock=back to FG, scrap=written off, quarantine=held for QA review',
  `line_comment` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ord_return_lines_return` (`return_id_fk`),
  KEY `idx_ord_return_lines_sku` (`sku_id_fk`),
  KEY `idx_ord_return_lines_disposition` (`disposition`),
  CONSTRAINT `fk_ord_return_lines_return` FOREIGN KEY (`return_id_fk`) REFERENCES `ord_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ord_return_lines_sku` FOREIGN KEY (`sku_id_fk`) REFERENCES `ref_skus` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ord_return_lines_qty_pos` CHECK (`qty` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Returned-FG line items. One row per SKU per return. qty > 0 enforced. Writer: retours.php (future).';

INSERT INTO `schema_meta`
  (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`)
VALUES
  (
    'ord_returns',
    'source',
    'retours.php (future)',
    'allowed',
    'Return header. Exactly one of origin_order_id_fk / origin_sales_order_id_fk must be set. Fix origin references via ord_orders or inv_sales_orders respectively.',
    'Returned-FG tracking header. Two origin lanes: ord_orders (B2B/internal) and inv_sales_orders (Shopify/taproom). Created by mig 373.'
  ),
  (
    'ord_return_lines',
    'source',
    'retours.php (future)',
    'allowed',
    'Return line items. qty > 0 enforced. Disposition drives FG stock adjustments: restock=back to inventory, scrap=write-off, quarantine=QA hold.',
    'One row per SKU per return. disposition ENUM: restock/scrap/quarantine. ON DELETE CASCADE from ord_returns. Created by mig 373.'
  );
