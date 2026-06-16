-- 377_ref_sku_shopify_variants.sql
-- Bridge: Shopify variant_id -> canonical ref_skus.id.
--
-- Some eshop order lines arrive with NO sku_code, only a Shopify
-- product_id/variant_id (the "title-only" packs: Pack de Noël, Pack Explorer,
-- Beer Des Rosses). This bridge lets the ingest + the repack decomposition
-- resolve such lines to a canonical SKU by variant_id.
--
-- Sits alongside the ref_sku_aliases family but keyed on the Shopify numeric
-- variant string, NOT the internal sku_code. Distinct from:
--   - ref_sku_aliases          (sku_code string -> sku_id)
--   - ref_sku_collab_temporal  (date-effective collab recipe binding)
-- variant_id matches inv_sales_order_lines.variant_id type exactly (VARCHAR(64)).

CREATE TABLE ref_sku_shopify_variants (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shopify_variant_id  VARCHAR(64)     NOT NULL,
  sku_id_fk           INT UNSIGNED    NOT NULL,
  shopify_product_id  VARCHAR(64)     NULL,
  title_snapshot      VARCHAR(255)    NULL,
  effective_from      DATE            NULL,
  effective_until     DATE            NULL,
  notes               VARCHAR(255)    NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_variant (shopify_variant_id),
  KEY idx_ssv_sku (sku_id_fk),
  CONSTRAINT fk_ssv_sku FOREIGN KEY (sku_id_fk) REFERENCES ref_skus(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint)
VALUES (
  'ref_sku_shopify_variants',
  'reference',
  'manual/web',
  'allowed',
  'Shopify variant_id -> ref_skus.id bridge for code-less eshop order lines (title-only packs: Pack de Noel, Pack Explorer, Beer Des Rosses). Seeded manually / via SKU admin. Distinct from ref_sku_aliases (sku_code key) and ref_sku_collab_temporal (date-effective collab).'
);
