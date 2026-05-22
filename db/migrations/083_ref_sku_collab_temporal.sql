-- 083_ref_sku_collab_temporal.sql
-- SCD2 temporal mapping for generic COLLAB SKUs that recycle across collabs.
-- A single Shopify article (e.g. COLLAB24) reuses the same SKU code for
-- successive collabs. At sale time, we need to know WHICH specific recipe
-- was active for that COLLAB SKU on the sale date — for COGS attribution.
--
-- Operational rule:
--   - When a new collab launches under the same COLLAB* SKU, the operator
--     INSERTs a new row with effective_from = new collab start date and
--     simultaneously UPDATEs the previous row's effective_until to the same date.
--   - effective_until = NULL means "current" (no end date).
--   - One COLLAB SKU code may have N rows over time; lookup is by date range.

CREATE TABLE IF NOT EXISTS ref_sku_collab_temporal (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku_code VARCHAR(64) NOT NULL,
  effective_from DATE NOT NULL,
  effective_until DATE,
  recipe_id INT UNSIGNED NOT NULL,
  notes VARCHAR(255),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_collab_sku_from (sku_code, effective_from),
  KEY ix_collab_sku (sku_code),
  KEY ix_collab_recipe (recipe_id),
  CONSTRAINT fk_collab_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Galactic Drift 2.0 (recipe 31 = DrunkBeard - Galactic Drift) active since Dec 2025
INSERT INTO ref_sku_collab_temporal (sku_code, effective_from, effective_until, recipe_id, notes)
VALUES
  ('COLLAB12',    '2025-12-01', NULL, 31, 'Galactic Drift 2.0 — DrunkBeard collab (seed, may extend further back if needed)'),
  ('COLLAB24',    '2025-12-01', NULL, 31, 'Galactic Drift 2.0 — DrunkBeard collab'),
  ('COLLAB4PACK', '2025-12-01', NULL, 31, 'Galactic Drift 2.0 — DrunkBeard collab');

-- schema_meta classification
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES ('ref_sku_collab_temporal', 'reference', 'manual / future N1 UI',
        'allowed_with_side_effect',
        'When a new collab launches under a generic COLLAB SKU: (1) INSERT new row with effective_from = launch date + recipe_id of new collab. (2) UPDATE previous row''s effective_until = same date. This preserves historical COGS attribution.',
        'Resolution at read time via WHERE effective_from <= sale_date AND (effective_until IS NULL OR effective_until > sale_date). One COLLAB SKU may have N rows over time.');
