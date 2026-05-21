-- db/migrations/072_ref_sku_packaging_choices.sql
-- What: Per-SKU MI override for packaging slots (overrides the format-level defaults
--       defined in ref_packaging_items). NULL mi_id_fk = use format default.
-- Why:  Different beers use different label / scotch / sticker MIs within the same
--       format. This table stores those per-SKU overrides without duplicating the
--       shared defaults.
-- Risk: New table. No existing data affected.
-- Rollback: DROP TABLE ref_sku_packaging_choices;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS ref_sku_packaging_choices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku_id          INT UNSIGNED NOT NULL,
  slot_name       VARCHAR(48) NOT NULL,
  mi_id_fk        INT UNSIGNED NULL COMMENT 'NULL = use format default from ref_packaging_items',
  qty_per_unit    DECIMAL(10,4) NOT NULL,
  is_checked      BOOL NOT NULL DEFAULT 1,
  effective_from  DATE NULL,
  effective_until DATE NULL,
  UNIQUE KEY uk_sku_slot (sku_id, slot_name),
  KEY idx_effective (effective_from, effective_until),
  CONSTRAINT fk_rspc_sku FOREIGN KEY (sku_id) REFERENCES ref_skus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Per-SKU overrides (e.g. EMBB uses PKG_SCOTCH_EMB branded scotch, not PKG_SCOTCH_TRANSP)
-- will be populated via the SKU builder UI. The operator configures each SKU's packaging
-- choices through the builder and the UI writes rows here. Seed data is not inserted here
-- to avoid pre-empting UI-driven configuration.

COMMIT;
