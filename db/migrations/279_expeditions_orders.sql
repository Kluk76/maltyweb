-- db/migrations/279_expeditions_orders.sql
-- What: Create the Expéditions (fulfilment) data model — 5 tables + schema_meta
--       rows + ref_pages activation for the 'fulfilment' placeholder.
--
--   1. ref_transporters  — carrier reference table (Galliker, Loxya …)
--   2. ref_customers     — commercial sold-to master (distinct from ref_clients =
--                          contract-brewing companies)
--   3. ord_orders        — order header with status-cache + CHECK for exactly-one party
--   4. ord_order_lines   — line items (qty in SKU units; HL derived at read time)
--   5. ord_order_status_events — append-only event log (truth; ord_orders.status
--                                is the materialized cache)
--   6. schema_meta rows for all 5 tables
--   7. ref_pages: activate 'fulfilment' placeholder (page_key renamed →
--                 'expeditions', href set, is_active=1)
--
-- Why: Phase 1 of the Expéditions arc — canonical MySQL data model so the PHP
--      module and status-advance API have a well-typed, auditable home.
--      ref_customers is intentionally separate from ref_clients (contract brewers).
--      ord_orders.status is a materialized cache of ord_order_status_events,
--      updated in the same transaction as the event INSERT.
--
-- Risk: Additive only (five CREATE TABLE + INSERT/UPDATE on non-order tables).
--       No existing tables altered.
-- Rollback:
--   UPDATE ref_pages SET page_key='fulfilment', label='Fulfilment', href='#',
--          is_active=0 WHERE page_key='expeditions';
--   DELETE FROM schema_meta WHERE table_name IN
--     ('ref_transporters','ref_customers','ord_orders',
--      'ord_order_lines','ord_order_status_events');
--   DROP TABLE ord_order_status_events;
--   DROP TABLE ord_order_lines;
--   DROP TABLE ord_orders;
--   DROP TABLE ref_customers;
--   DROP TABLE ref_transporters;
-- Applied via: ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'
-- ============================================================================


-- ── 1. ref_transporters ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ref_transporters` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(96)   NOT NULL COMMENT 'Carrier display name (operator-entered)',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`  INT           NOT NULL DEFAULT 0 COMMENT 'Display order in form dropdown; lower = first',
  `notes`       TEXT          NULL,
  `updated_by`  VARCHAR(64)   NULL     COMMENT 'Username of last editor (informational only)',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_transporters_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Carrier / transporter reference list. Writer: expeditions.php admin UI (future)';

INSERT INTO `ref_transporters` (`name`, `is_active`, `sort_order`) VALUES
  ('Galliker', 1, 10),
  ('Loxya',    1, 20);


-- ── 2. ref_customers ─────────────────────────────────────────────────────────
-- Commercial sold-to master. Distinct from ref_clients (contract-brewing companies).
CREATE TABLE IF NOT EXISTS `ref_customers` (
  `id`                       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`                     VARCHAR(160)  NOT NULL COMMENT 'Customer display name',
  `bc_customer_no`           VARCHAR(32)   NULL     COMMENT 'BC/ERP customer number; NULL = sheet-only or privé customer',
  `trade_channel`            ENUM('on_trade','off_trade')
                                           NULL     DEFAULT NULL,
  `is_private`               TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = individual/privé customer',
  `default_transporter_id_fk` INT UNSIGNED NULL     COMMENT 'Preferred carrier for this customer; overridable per order',
  `needs_review`             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = inline-created draft awaiting operator validation',
  `is_active`                TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`                    TEXT          NULL,
  `updated_by`               VARCHAR(64)   NULL     COMMENT 'Username of last editor (informational only)',
  `created_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_customers_name` (`name`),
  UNIQUE KEY `uq_ref_customers_bc_no` (`bc_customer_no`),
  CONSTRAINT `fk_ref_customers_transporter`
    FOREIGN KEY (`default_transporter_id_fk`)
    REFERENCES `ref_transporters` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Commercial sold-to master. Distinct from ref_clients (contract brewers). Writer: expeditions.php + bootstrap script';


-- ── 3. ord_orders ─────────────────────────────────────────────────────────────
-- Order header. status is a materialized cache of ord_order_status_events;
-- it MUST be updated in the same DB transaction as the event INSERT.
-- Future status stages APPEND LAST to the ENUM — ordering is via rank map in
-- application code, never via ENUM declaration order.
-- customer_id_fk uses ON DELETE RESTRICT so the column can appear in a CHECK
-- constraint (MySQL 8 forbids CHECK expressions on CASCADE-FK columns).
CREATE TABLE IF NOT EXISTS `ord_orders` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_type`           ENUM('customer','internal') NOT NULL,
  `customer_id_fk`       INT UNSIGNED    NULL COMMENT 'FK→ref_customers(id); set when order_type=customer',
  `internal_channel`     ENUM('taproom','eshop','cage','shop')
                                         NULL DEFAULT NULL COMMENT 'Set when order_type=internal',
  `requested_date`       DATE            NOT NULL,
  `status`               ENUM('entered','confirmed','picked','bl_printed','shipped','cancelled')
                                         NOT NULL DEFAULT 'entered'
                                         COMMENT 'Cache of ord_order_status_events; updated in same transaction. Future stages APPEND LAST; ordering via rank map in code, never ENUM order.',
  `transporter_id_fk`    INT UNSIGNED    NULL COMMENT 'Carrier for this order; overrides customer default',
  `comment`              TEXT            NULL,
  `source`               ENUM('web','email','import') NOT NULL DEFAULT 'web',
  `source_file_id_fk`    BIGINT UNSIGNED NULL COMMENT 'FK→doc_files(id) BIGINT PK (not the UUID). Set when order imported from a document.',
  `parse_confidence`     DECIMAL(4,3)    NULL COMMENT 'Parser confidence [0,1] when source=import',
  `review_status`        ENUM('none','pending','accepted') NOT NULL DEFAULT 'none',
  `created_by_user_id`   INT UNSIGNED    NULL COMMENT 'FK→users(id); NULL for system/import-created orders',
  `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Exactly-one-party constraint: either a named customer OR an internal channel,
  -- never both, never neither. ON DELETE RESTRICT on customer_id_fk is required
  -- so MySQL 8 permits this column inside a CHECK expression.
  CONSTRAINT `ord_orders_chk_exactly_one_party`
    CHECK (
      (order_type = 'customer'  AND customer_id_fk  IS NOT NULL AND internal_channel IS NULL)
      OR
      (order_type = 'internal'  AND internal_channel IS NOT NULL AND customer_id_fk  IS NULL)
    ),

  CONSTRAINT `fk_ord_orders_customer`
    FOREIGN KEY (`customer_id_fk`)
    REFERENCES `ref_customers` (`id`) ON DELETE RESTRICT,

  CONSTRAINT `fk_ord_orders_transporter`
    FOREIGN KEY (`transporter_id_fk`)
    REFERENCES `ref_transporters` (`id`) ON DELETE SET NULL,

  CONSTRAINT `fk_ord_orders_source_file`
    FOREIGN KEY (`source_file_id_fk`)
    REFERENCES `doc_files` (`id`) ON DELETE SET NULL,

  CONSTRAINT `fk_ord_orders_created_by`
    FOREIGN KEY (`created_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,

  KEY `idx_ord_orders_requested_date` (`requested_date`),
  KEY `idx_ord_orders_status` (`status`),
  KEY `idx_ord_orders_customer` (`customer_id_fk`),
  KEY `idx_ord_orders_source_review` (`source`, `review_status`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Order header. status = materialized cache of ord_order_status_events. Writer: expeditions.php';


-- ── 4. ord_order_lines ───────────────────────────────────────────────────────
-- One row per SKU per order. qty is in SKU units; HL is derived at read time
-- via ref_skus.hl_per_unit — never stored here to avoid drift.
CREATE TABLE IF NOT EXISTS `ord_order_lines` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id_fk`   BIGINT UNSIGNED NOT NULL COMMENT 'FK→ord_orders(id)',
  `sku_id_fk`     INT UNSIGNED    NOT NULL COMMENT 'FK→ref_skus(id); ON DELETE RESTRICT — retiring a SKU requires migrating orders first',
  `qty`           DECIMAL(10,2)   NOT NULL COMMENT 'Quantity in SKU units. HL derived at read time via ref_skus.hl_per_unit.',
  `line_comment`  VARCHAR(255)    NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  CONSTRAINT `ord_order_lines_chk_qty_pos`
    CHECK (`qty` > 0),

  CONSTRAINT `fk_ord_order_lines_order`
    FOREIGN KEY (`order_id_fk`)
    REFERENCES `ord_orders` (`id`) ON DELETE CASCADE,

  CONSTRAINT `fk_ord_order_lines_sku`
    FOREIGN KEY (`sku_id_fk`)
    REFERENCES `ref_skus` (`id`) ON DELETE RESTRICT,

  KEY `idx_ord_order_lines_order` (`order_id_fk`),
  KEY `idx_ord_order_lines_sku` (`sku_id_fk`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Order line items. qty in SKU units; HL = qty × ref_skus.hl_per_unit at read time. Writer: expeditions.php';


-- ── 5. ord_order_status_events ───────────────────────────────────────────────
-- Append-only event log. This is the source of truth for order lifecycle.
-- ord_orders.status is the materialized cache, kept in sync in the same
-- DB transaction as every INSERT here.
CREATE TABLE IF NOT EXISTS `ord_order_status_events` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id_fk`   BIGINT UNSIGNED NOT NULL COMMENT 'FK→ord_orders(id)',
  `status`        ENUM('entered','confirmed','picked','bl_printed','shipped','cancelled')
                                  NOT NULL COMMENT 'Same ENUM as ord_orders.status; append-only log of transitions',
  `occurred_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id_fk`    INT UNSIGNED    NULL COMMENT 'FK→users(id); NULL for system/import events',
  `comment`       VARCHAR(255)    NULL,

  PRIMARY KEY (`id`),

  CONSTRAINT `fk_ord_status_events_order`
    FOREIGN KEY (`order_id_fk`)
    REFERENCES `ord_orders` (`id`) ON DELETE CASCADE,

  CONSTRAINT `fk_ord_status_events_user`
    FOREIGN KEY (`user_id_fk`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,

  KEY `idx_ord_status_events_order_time` (`order_id_fk`, `occurred_at`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only order status event log. Truth; ord_orders.status is the cache. Writer: expeditions.php status-advance API';


-- ── 6. schema_meta rows ───────────────────────────────────────────────────────
-- ref_transporters and ref_customers: 'reference' class, corrections allowed.
-- ord_* tables: 'source' class (operator-entered event data), corrections allowed.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_transporters', 'reference', 'allowed',
     'public/modules/expeditions.php (admin section)',
     'Carrier reference list. Add/edit via Expéditions admin UI or direct MySQL edit. Do not delete rows — set is_active=0.'),

    ('ref_customers', 'reference', 'allowed',
     'public/modules/expeditions.php + bootstrap script',
     'Commercial sold-to customer master. Distinct from ref_clients (contract brewers). needs_review=1 = inline-created draft awaiting validation.'),

    ('ord_orders', 'source', 'allowed',
     'public/modules/expeditions.php',
     'Order header. status is a cache of ord_order_status_events — update both in the same transaction. Fix customer/transporter upstream via ref_customers/ref_transporters.'),

    ('ord_order_lines', 'source', 'allowed',
     'public/modules/expeditions.php',
     'Order line items. qty in SKU units; HL derived at read time via ref_skus.hl_per_unit. Retire a SKU only after migrating all open order lines.'),

    ('ord_order_status_events', 'source', 'allowed',
     'public/modules/expeditions.php status-advance API',
     'Append-only order status event log. Truth for order lifecycle; ord_orders.status is the materialized cache. Do not delete or UPDATE rows — append corrections only.');


-- ── 7. ref_pages activation ──────────────────────────────────────────────────
-- Activate the 'fulfilment' placeholder seeded in migration 266.
-- Rename page_key from 'fulfilment' to 'expeditions' to match the module name,
-- set the real href, and flip is_active to 1.
-- min_role='viewer', domain='logistics', sort=100 are unchanged.
UPDATE `ref_pages`
   SET `page_key` = 'expeditions',
       `label`    = 'Expéditions',
       `href`     = '/modules/expeditions.php',
       `is_active` = 1
 WHERE `page_key` = 'fulfilment';
