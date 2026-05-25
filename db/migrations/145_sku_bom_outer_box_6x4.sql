-- db/migrations/145_sku_bom_outer_box_6x4.sql
-- What: Add the missing `outer_box` slot to the 6×4-pack carton formats
--       (3 = 6×4 bottle, 9 = 6×4 can).
-- Why:  A 6×4 pack is THREE physical layers — 24 bottles/cans → 6 four-pack holders
--       (the existing `outer_tray` slot, qty 6) → 1 OUTER CARTON. The outer-carton
--       layer (PKG_6X4_{BTL|CAN}_{beer}, ~0.52–0.80 CHF) had no slot, so the BOM
--       recompile dropped it from every -4 SKU (verified: EMB4 went 5→4 lines).
--       Operator-confirmed 2026-05-26: the carton is real and sits ON TOP of the
--       4-packs (3-layer model). The per-beer MIs already exist (ids 127–141).
-- Risk: LOW — two reference rows. Resolves per-beer via the {beer} pattern, the same
--       mechanism `outer_tray` already uses. Beers with no PKG_6X4_CAN MI (e.g. some
--       cans) go to ReviewQueue on recompute (refuse-don't-NULL), never a NULL/0 line.
-- Idempotency: INSERT IGNORE on uk_format_slot(format_id, slot_name) → re-run = no-op.
-- Rollback:
--   DELETE FROM ref_packaging_items WHERE slot_name = 'outer_box' AND format_id IN (3,9);

-- display_order: format 3 has outer_tray at 50 → outer_box at 60;
--                format 9 has can(10) can_lids(20) outer_tray(30) intercal(40) → outer_box at 50.
-- default_mi_id_fk NULL: the carton is per-beer (resolved by the {beer} pattern), like outer_tray.
INSERT IGNORE INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, effective_until, slot_scope)
VALUES
  (3, 'outer_box', 1.0000, 'PKG_6X4_BTL_{beer}%', NULL, 1, 60, NULL, NULL, 'we_supply_only'),
  (9, 'outer_box', 1.0000, 'PKG_6X4_CAN_{beer}%', NULL, 1, 50, NULL, NULL, 'we_supply_only');
