-- 321_inv_shipping_method_map.sql
-- Shopify → maltyweb fulfilment Phase 0: shipping-method classification map.
-- Creates ref_shipping_methods (title_norm → pickup/delivery) and wires
-- shipping_method_title + fulfilment_mode onto inv_sales_orders.
--
-- MySQL 8 — NO ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php exec() leaves result sets open).

-- ── 1. Reference table ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS ref_shipping_methods (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  title_norm      VARCHAR(255)    NOT NULL,
  raw_sample      VARCHAR(255)        NULL,
  fulfilment_mode ENUM('pickup','delivery') NOT NULL,
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  notes           TEXT                NULL,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipping_methods_title_norm (title_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Seed known shipping method titles ─────────────────────────────────────
-- title_norm = lowercase, trimmed, internal-whitespace collapsed (same normalization
-- used by ingest-shopify-orders.ts at runtime).

INSERT IGNORE INTO ref_shipping_methods (title_norm, raw_sample, fulfilment_mode) VALUES
  -- pickup
  ('la tap & shop (mercredi - samedi ; 14h-22h)', 'La Tap & Shop (mercredi - samedi ; 14h-22h)', 'pickup'),
  ('retrait a crissier',                           'RETRAIT A CRISSIER',                          'pickup'),
  ('retrait à crissier',                           'RETRAIT à CRISSIER',                          'pickup'),
  ('la nébuleuse sa',                              'La Nébuleuse SA',                              'pickup'),
  -- delivery
  ('la poste - vinologue (fragile)',               'La Poste - Vinologue (fragile)',               'delivery'),
  ('vinologue suisse - fragile',                   'Vinologue Suisse - Fragile',                   'delivery'),
  ('standard suisse',                              'Standard Suisse',                              'delivery'),
  -- Both 'Livraison Offerte' and 'Livraison offerte' normalize to the same title_norm;
  -- INSERT IGNORE keeps the first row, drops the second silently — correct behaviour.
  ('livraison offerte',                            'Livraison Offerte',                            'delivery'),
  ('livraison gratuite',                           'Livraison gratuite',                           'delivery');

-- ── 3. schema_meta row ────────────────────────────────────────────────────────

INSERT IGNORE INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES (
  'ref_shipping_methods',
  'reference',
  'ingest-shopify-orders.ts (reads only)',
  'allowed',
  'Shopify shipping-method title → pickup/delivery classification map; seed from live titles; unknown titles land orders in fulfilment_mode=''review''.',
  'Phase 0 Shopify fulfilment integration (2026-06-10). Seeded from verified live Shopify titles.'
);

-- ── 4. Add columns to inv_sales_orders ───────────────────────────────────────
-- MySQL 8: no IF NOT EXISTS on ADD COLUMN; idempotency via schema_migrations.
-- AFTER source_name places shipping_method_title in logical sequence.

ALTER TABLE inv_sales_orders
  ADD COLUMN shipping_method_title VARCHAR(255) NULL        AFTER source_name,
  ADD COLUMN fulfilment_mode       ENUM('pickup','delivery','review') NOT NULL DEFAULT 'review'
                                                             AFTER shipping_method_title;
