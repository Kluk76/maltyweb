-- 440_bc_order_reconciliation.sql — BC order reconciliation snapshot table

-- 1. Create ord_bc_reconciliation table
CREATE TABLE `ord_bc_reconciliation` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `run_at` DATETIME NOT NULL,
  `bc_order_no` VARCHAR(40) NOT NULL,
  `state` ENUM('present','excluded_shopify','excluded_system','excluded_recency','skip_backlog','collision_sunk','missing') NOT NULL,
  `reason` VARCHAR(255) NULL,
  `kept_row_id` BIGINT UNSIGNED NULL,
  `ord_order_id_fk` BIGINT UNSIGNED NULL,
  `customer_id_fk` INT UNSIGNED NULL,
  `customer_name` VARCHAR(255) NULL,
  `requested_date` DATE NULL,
  `sku_ids` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_run` (`run_at`),
  INDEX `idx_state` (`state`),
  INDEX `idx_bc` (`bc_order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ALTER doc_review_queue to add 'bc-order-not-imported' to the type ENUM
ALTER TABLE `doc_review_queue` MODIFY COLUMN `type` ENUM(
  'supplier-unknown','ingredient-unknown','gl-drift','archive-candidate','inactive-candidate',
  'dynamic-vs-take-drift','rm-stale','rm-negative','rm-orphan-mi','invoice-no-dn','dn-no-invoice',
  'photonote-audit','sales-sku-unknown','doc-classify-ambiguous','invoice-line-items-needed',
  'dn-invoice-duplicate','dn-low-confidence-line','sku-bom-unresolved','garde_seuil_overdue',
  'contamination_flagged','mother_abandoned','packaged_volume_anomaly','repack-unresolved-bundle',
  'bc-customer-identity-drift','bc-order-correction-required','repack-carton-unresolved',
  'bc-order-not-imported'
) NOT NULL;

-- 3. schema_meta row for ord_bc_reconciliation
INSERT INTO `schema_meta`
  (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`, `updated_at`)
VALUES
  ('ord_bc_reconciliation', 'derived', 'ingest_bc_sales_orders.py', 'blocked',
   'Re-run ingest_bc_sales_orders.py to regenerate',
   'Point-in-time reconciliation snapshot: every BC open order classified per run. Truncate-and-reload per run.',
   NOW());
