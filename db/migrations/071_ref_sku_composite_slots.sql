-- db/migrations/071_ref_sku_composite_slots.sql
-- What: New table storing the constituent recipes for composite SKUs (PD8, PAL, XMASPACK, PAD).
--       Each row = one recipe slot within a composite, with a multiple (e.g. PD8 has 8 beers × 1).
-- Why:  SKU builder UI needs to know which beers compose a composite SKU and in what
--       proportion, so it can drive the liquid-cost breakdown.
-- Risk: New table. No existing data affected.
-- Rollback: DROP TABLE ref_sku_composite_slots;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS ref_sku_composite_slots (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku_id          INT UNSIGNED NOT NULL,
  recipe_id       INT UNSIGNED NOT NULL,
  multiple        INT NOT NULL DEFAULT 1 COMMENT 'How many bottles of this beer in the composite unit',
  slot_order      INT NOT NULL,
  effective_from  DATE NULL,
  effective_until DATE NULL,
  UNIQUE KEY uk_sku_slot (sku_id, slot_order),
  KEY idx_effective (effective_from, effective_until),
  CONSTRAINT fk_rscs_sku    FOREIGN KEY (sku_id)    REFERENCES ref_skus(id) ON DELETE CASCADE,
  CONSTRAINT fk_rscs_recipe FOREIGN KEY (recipe_id) REFERENCES ref_recipes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: Seed data for composite slot assignments (PD8, PAL, XMASPACK, PAD) should be
-- inserted via the SKU builder UI once operator confirms the exact constituent-beer mix
-- for each composite SKU, OR via a follow-up migration after operator confirmation.
-- The audit (sku-bom-audit-2026-05-21.md) documents the liquid lines already present
-- in ref_sku_bom but does not enumerate the slot-order assignments needed here.

COMMIT;
