-- db/migrations/147_remove_x_format_cage_outer_slots.sql
-- What: Remove the spurious `outer_box` and `pallet` slots from the X format
--       (ref_packaging_formats id 6, format_code 'X').
-- Why:  The "-X" SKUs (ZEP-X, MOO-X, …) ARE reusable metal CAGES manually filled
--       with 1027 bottles during packaging. Their BOM is the CONTENTS only —
--       generic except the label: 1027× PKG_BOT_PIVO + 1027× PKG_BOT_CROWN_CAPS
--       + 1027× PKG_LABEL_{beer}. The cage crate itself is REUSABLE, so it is NOT
--       a per-unit consumable and must not appear as a BOM line. The `outer_box`
--       (PKG_BOX_%, no default → unresolvable) and `pallet` (PKG_PALETTE_NOMOQ)
--       slots were spurious and produced refuse-don't-NULL RQ rows on recompile.
--       Operator-confirmed 2026-05-26. (The reusable crate becomes its own
--       activable-at-root PKG_CAGE SKU in a later build — not modelled here.)
-- Risk: LOW — deletes two reference rows; the remaining bottle/crown_caps/label
--       slots are untouched. volume_hl still lands on the `bottle` container slot
--       (1027 × 0.0033 = 3.3891 HL) on the next recompile.
-- Idempotency: DELETE … WHERE → re-run removes nothing (0 rows affected).
-- Rollback (restores the two slots with their original values):
--   INSERT INTO ref_packaging_items
--     (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
--      is_default_checked, display_order, effective_from, effective_until, slot_scope)
--   VALUES
--     (6, 'outer_box', 1.0000, 'PKG_BOX_%',     NULL, 1, 50, NULL, NULL, 'we_supply_only'),
--     (6, 'pallet',    1.0000, 'PKG_PALETTE%',  578,  0, 60, NULL, NULL, 'we_supply_only');

DELETE FROM ref_packaging_items
 WHERE format_id = 6
   AND slot_name IN ('outer_box', 'pallet');
