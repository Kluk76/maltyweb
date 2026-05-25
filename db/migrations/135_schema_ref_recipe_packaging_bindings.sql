-- db/migrations/135_schema_ref_recipe_packaging_bindings.sql
-- What: Create ref_recipe_packaging_bindings — resolves beer-specific placeholder slots
--       (label, can, sticker, holder, outer_tray, scotch) to their actual MI per recipe.
--       mi_id_fk NOT NULL is the load-bearing gate: the compiler must REFUSE to emit a
--       NULL BOM line; it emits a sku-bom-unresolved ReviewQueue row instead.
-- Why: Phase 1. Replaces the brittle PKG_LABEL_{beer}% string-match in build-sku-bom.js.
--      Keyed by recipe (not sku_prefix) so Contract recipes with empty sku_prefix work.
--      SCD1 temporal (effective_from/until) supports DK transitions without history loss.
-- Risk: CREATE TABLE only. LOW.
-- Rollback: DROP TABLE ref_recipe_packaging_bindings;

CREATE TABLE IF NOT EXISTS ref_recipe_packaging_bindings (
  id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
  recipe_id       INT UNSIGNED        NOT NULL COMMENT 'FK to ref_recipes.id (INT UNSIGNED)',
  role            ENUM('label','can','sticker','holder','outer_tray','scotch')
                  NOT NULL COLLATE utf8mb4_unicode_ci
                  COMMENT 'Slot role being resolved; matches ref_packaging_items.slot_name vocab',
  mi_id_fk        INT UNSIGNED        NOT NULL COMMENT 'FK to ref_mi.id (INT UNSIGNED) — NOT NULL: refuse-not-null is the design contract',
  effective_from  DATE                NOT NULL COMMENT 'When this binding takes effect (use recipe creation date or 2023-10-01 for seed)',
  effective_until DATE                NULL COMMENT 'NULL = current; set when transitioning to a new label design (DK migration etc.)',
  notes           VARCHAR(255)        NULL,
  created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rrpb_recipe_role_from (recipe_id, role, effective_from),
  KEY idx_rrpb_recipe (recipe_id),
  KEY idx_rrpb_mi (mi_id_fk),
  CONSTRAINT fk_rrpb_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_rrpb_mi FOREIGN KEY (mi_id_fk) REFERENCES ref_mi(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Resolves beer-specific BOM slot placeholders (label/can/sticker/…) to ref_mi per recipe. mi_id_fk NOT NULL enforces refuse-not-NULL rule.';

-- schema_meta row
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
  'ref_recipe_packaging_bindings',
  'reference',
  'allowed',
  'backfill-recipe-packaging-bindings.ts + Recettes UI (Phase 4)',
  'Populated by one-shot backfill script then maintained via Recettes activation page. Run backfill --apply after operator reviews dry-run output.'
);
