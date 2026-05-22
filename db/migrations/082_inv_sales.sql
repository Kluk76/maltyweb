-- 082_inv_sales.sql
-- Sales tables for retro RM/FG valuation Dec 2025 → Apr 2026 onwards.
-- 3 tables: inv_sales_bc (Business Central csv aggregates), inv_sales_orders
-- (Shopify/Lightspeed line-item granular orders), inv_sales_order_lines (lines).
--
-- Channel discriminator:
--   BC csv: customer_no='3822' → taproom, others → b2b. Rows where
--   customer_no='1080' (eshop privé) MUST be SKIPPED at load — Shopify covers eshop.
--
-- Volume HL is resolved against ref_skus.hl_per_unit (NOT BC Liter_Equiv, which
-- is bugged: some lines report 0L). Unmatched SKUs (vouchers, merchandise,
-- non-beer items) get sku_id_fk=NULL + hl_resolved=NULL.

CREATE TABLE IF NOT EXISTS inv_sales_bc (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  period VARCHAR(7) NOT NULL,                                -- 'YYYY-MM' derived from BC csv header
  customer_no VARCHAR(32) NOT NULL,
  customer_name VARCHAR(255),
  channel ENUM('b2b','taproom') NOT NULL,                    -- '3822' → taproom, all else → b2b
  sku_code VARCHAR(64) NOT NULL,
  sku_description VARCHAR(255),
  qty_invoiced DECIMAL(14,4) NOT NULL,
  unit_of_measure VARCHAR(16),
  sales_amount_chf DECIMAL(14,4) NOT NULL,
  discount_amount_chf DECIMAL(14,4),
  profit_chf DECIMAL(14,4),
  liter_equiv_raw DECIMAL(14,4),                             -- BC col 39 stored raw for reference (NOT used)
  sku_id_fk INT UNSIGNED,                                    -- resolved against ref_skus.id
  hl_resolved DECIMAL(14,6),                                 -- qty_invoiced × ref_skus.hl_per_unit
  source_file VARCHAR(255) NOT NULL,
  row_hash CHAR(64) NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_bc_row_hash (row_hash),
  KEY ix_bc_period (period),
  KEY ix_bc_customer (customer_no),
  KEY ix_bc_sku (sku_code),
  KEY ix_bc_channel (channel),
  CONSTRAINT fk_bc_sku FOREIGN KEY (sku_id_fk) REFERENCES ref_skus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sales_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source ENUM('shopify','lightspeed') NOT NULL,
  external_order_id VARCHAR(64) NOT NULL,                    -- Shopify order id / Lightspeed sale id
  order_name VARCHAR(32),                                    -- Shopify '#13152' etc.
  channel ENUM('eshop','taproom') NOT NULL,                  -- shopify→eshop, lightspeed→taproom
  customer_external_id VARCHAR(64),
  customer_email VARCHAR(255),
  customer_first_name VARCHAR(128),
  customer_last_name VARCHAR(128),
  created_at DATETIME NOT NULL,
  period VARCHAR(7) NOT NULL,                                -- derived 'YYYY-MM' from created_at
  financial_status VARCHAR(32),
  fulfillment_status VARCHAR(32),
  currency VARCHAR(8),
  subtotal_chf DECIMAL(14,4),
  total_chf DECIMAL(14,4) NOT NULL,
  total_discount_chf DECIMAL(14,4),
  total_tax_chf DECIMAL(14,4),
  total_shipping_chf DECIMAL(14,4),
  source_name VARCHAR(64),                                   -- Shopify source_name (web, pos, …)
  tags VARCHAR(500),
  row_hash CHAR(64) NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_orders_external (source, external_order_id),
  UNIQUE KEY uk_orders_row_hash (row_hash),
  KEY ix_orders_period (period),
  KEY ix_orders_channel (channel),
  KEY ix_orders_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sales_order_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id_fk BIGINT UNSIGNED NOT NULL,
  line_index SMALLINT NOT NULL,
  external_line_id VARCHAR(64),
  sku_code VARCHAR(64),
  title VARCHAR(255),
  variant_id VARCHAR(64),
  product_id VARCHAR(64),
  qty DECIMAL(14,4) NOT NULL,
  unit_price_chf DECIMAL(14,6),
  line_amount_chf DECIMAL(14,4),
  discount_amount_chf DECIMAL(14,4),
  sku_id_fk INT UNSIGNED,                                    -- resolved against ref_skus.id
  hl_resolved DECIMAL(14,6),                                 -- qty × ref_skus.hl_per_unit
  row_hash CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_lines_order_line (order_id_fk, line_index),
  UNIQUE KEY uk_lines_row_hash (row_hash),
  KEY ix_lines_sku (sku_code),
  CONSTRAINT fk_lines_order FOREIGN KEY (order_id_fk) REFERENCES inv_sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_lines_sku FOREIGN KEY (sku_id_fk) REFERENCES ref_skus(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classifications
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
('inv_sales_bc', 'source', 'ingest-bc-sales.py',
 'blocked_with_redirect',
 'Raw BC csv ingest. Fix in BC system + re-export csv → re-ingest. Custom-no=1080 (eshop privé) rows are SKIPPED at load; eshop sales come from inv_sales_orders (Shopify).',
 'Customer 3822 → taproom; everything except 1080/3822 → b2b. Volume HL via ref_skus.hl_per_unit (NOT BC Liter_Equiv).'),
('inv_sales_orders', 'source', 'ingest-shopify-orders.ts / ingest-lightspeed-orders.ts',
 'blocked_with_redirect',
 'Raw API pull from Shopify / Lightspeed. Fix in source POS / eshop UI, then re-pull.',
 'Eshop channel = Shopify ; Taproom channel = Lightspeed K-Series (deferred). One row per order; lines in inv_sales_order_lines.'),
('inv_sales_order_lines', 'source', 'ingest-shopify-orders.ts / ingest-lightspeed-orders.ts',
 'blocked_with_redirect',
 'Raw API line items. Fix in source then re-pull.',
 'sku_id_fk via ref_skus lookup; NULL when SKU is non-beer (vouchers, merch, soft drinks).');
