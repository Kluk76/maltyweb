-- db/migrations/118_create_generic_can_mis.sql
-- What: Insert two ANCHOR MIs into ref_mi:
--       PKG_CAN_ALU_50 ("Canette alu 50cl (générique)")
--       PKG_CAN_ALU_33 ("Canette alu 33cl (générique)")
-- Why:  These anchor the container dimension for CAN_ALU_50 and CAN_ALU_33 container types
--       in ref_container_mi. Real can COGS stays on per-recipe printed cans (PKG_CAN_ZEP etc.).
--       is_inventoried=0: these are container-model anchors only — no physical stock tracked.
--       price NULL: no acquisition cost at the generic level (cost flows via recipe-specific cans).
-- Risk: Additive only. No existing rows touched.
-- Rollback: DELETE FROM ref_mi WHERE mi_id IN ('PKG_CAN_ALU_50','PKG_CAN_ALU_33');

INSERT INTO ref_mi
  (mi_id, name, category_id, subcategory_id, gl_account,
   is_inventoried, currency, price,
   row_hash, last_modified_by, is_active)
VALUES
  (
    'PKG_CAN_ALU_50',
    'Canette alu 50cl (générique)',
    -- category_id=8 (Packaging), subcategory_id=13 (Can) — mirrors PKG_CAN_LIDS
    8, 13, '4202',
    0,     -- is_inventoried=0: anchor only, not cost-bearing
    NULL,  -- currency NULL: no price, no currency
    NULL,  -- price NULL: cost stays on per-recipe printed cans
    SHA2('PKG_CAN_ALU_50', 256),
    'web',
    1
  ),
  (
    'PKG_CAN_ALU_33',
    'Canette alu 33cl (générique)',
    -- category_id=8 (Packaging), subcategory_id=13 (Can) — mirrors PKG_CAN_LIDS
    8, 13, '4202',
    0,     -- is_inventoried=0: anchor only, not cost-bearing
    NULL,  -- currency NULL: no price, no currency
    NULL,  -- price NULL: cost stays on per-recipe printed cans
    SHA2('PKG_CAN_ALU_33', 256),
    'web',
    1
  )
ON DUPLICATE KEY UPDATE
  name             = VALUES(name),
  category_id      = VALUES(category_id),
  subcategory_id   = VALUES(subcategory_id),
  gl_account       = VALUES(gl_account),
  is_inventoried   = VALUES(is_inventoried),
  last_modified_by = VALUES(last_modified_by),
  is_active        = VALUES(is_active);
-- NOTE: price/currency left as-is on re-run (ON DUPLICATE KEY does not touch them)
-- to preserve any future operator-set prices without overwriting.
-- The ON DUPLICATE KEY is idempotency insurance only; row_hash UNIQUE prevents silent skip.
