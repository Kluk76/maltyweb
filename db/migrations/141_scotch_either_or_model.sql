-- db/migrations/141_scotch_either_or_model.sql
-- What: Scotch either/or model — fix slot placement + add can-box scotch/sticker slots
-- Why: Scotch belongs ONLY on 24-box formats (B id1, C id7, BC id8). The prior seed put
--      scotch slots on B12 (id2), carton 4 (id3), and pallet X (id6) — all wrong. The
--      24-box CAN formats (C/BC) had NO scotch or box-sticker slots at all. The box-sticker
--      on fmt1 B was scoped labelled_only; it must be we_supply_only so the recompute
--      includes it on a pre-printed-can box (config B = TRANSP+sticker, orthogonal to
--      decoration_integral). Migration 141 corrects all four issues.
-- Risk: DELETE on ref_packaging_items; INSERT new rows; UPDATE scope on item_id=6.
--       item_ids 10/16/28 verified CORRECT for deletion (B12/carton/pallet) via live probe
--       2026-05-25. item_id=4 (fmt1 B, 24-box bottle) is KEPT.
-- Rollback: INSERT the 3 deleted rows back; DELETE the 4 inserted can rows;
--           UPDATE ref_packaging_items SET slot_scope='labelled_only' WHERE id=6;

-- 1. Remove scotch slots from wrong formats (B12, carton 4, pallet X)
--    item_id=10 → fmt2 B12; item_id=16 → fmt3 carton; item_id=28 → fmt6 X pallet
DELETE FROM ref_packaging_items WHERE id IN (10, 16, 28);

-- 2. Add scotch slot on 24-pack can box C (format_id=7)
--    Mirrors item_id=4 (fmt1 B scotch) except is_default_checked=0 and display_order=40
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, effective_until, slot_scope)
VALUES
  (7, 'scotch', 0.0009, 'PKG_SCOTCH_(TRANSP|{beer})%', 179, 0, 40, NULL, NULL, 'we_supply_only');

-- 3. Add scotch slot on 24-pack can box BC (format_id=8)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, effective_until, slot_scope)
VALUES
  (8, 'scotch', 0.0009, 'PKG_SCOTCH_(TRANSP|{beer})%', 179, 0, 40, NULL, NULL, 'we_supply_only');

-- 4. Add box-sticker slot on 24-pack can box C (format_id=7)
--    scope we_supply_only: included regardless of decoration_integral (box sticker is
--    orthogonal to the can's pre-printed/labelled state — config B always needs it)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, effective_until, slot_scope)
VALUES
  (7, 'sticker', 24.0000, 'PKG_STICKER_{beer}%', NULL, 0, 50, NULL, NULL, 'we_supply_only');

-- 5. Add box-sticker slot on 24-pack can box BC (format_id=8)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, effective_until, slot_scope)
VALUES
  (8, 'sticker', 24.0000, 'PKG_STICKER_{beer}%', NULL, 0, 50, NULL, NULL, 'we_supply_only');

-- 6. Fix fmt1 B box-sticker (item_id=6) scope: labelled_only → we_supply_only
--    Rationale: the box sticker on a 24-box is orthogonal to the bottle's decoration_integral
--    (pre-printed-can box still gets config A or B); must survive the recompute's we_supply filter.
--    NB: item_id=52 (33C on-can sticker) stays labelled_only — that IS decoration-dependent.
UPDATE ref_packaging_items SET slot_scope = 'we_supply_only' WHERE id = 6;
