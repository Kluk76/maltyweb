-- db/migrations/068_ref_recipe_ingredients.sql
-- What: New table storing per-recipe ingredient quantities (brewing/liquid BOM).
--       Temporal columns added (v1 dormant, NULL=always-current).
--       v2: SCD2 activation after SKU builder UI commissioning.
--       v3: downstream consumers add as-of date filter.
-- Why:  Foundation for SKU builder UI — decouples recipe ingredient management
--       from the legacy build-sku-bom.js BSF pipeline.
-- Risk: New table — no existing data affected.
-- Rollback: DROP TABLE ref_recipe_ingredients;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS ref_recipe_ingredients (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_id       INT UNSIGNED NOT NULL,
  mi_id_fk        INT UNSIGNED NOT NULL,
  qty_per_hl      DECIMAL(14,6) NOT NULL,
  unit            VARCHAR(8) NOT NULL,
  is_active       BOOL NOT NULL DEFAULT 1,
  effective_from  DATE NULL COMMENT 'v1 dormant; v2 SCD2 activation',
  effective_until DATE NULL COMMENT 'v1 dormant; v2 SCD2 close-out',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_recipe (recipe_id),
  KEY idx_mi (mi_id_fk),
  KEY idx_effective (effective_from, effective_until),
  CONSTRAINT fk_rri_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id),
  CONSTRAINT fk_rri_mi    FOREIGN KEY (mi_id_fk)  REFERENCES ref_mi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
