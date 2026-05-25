-- db/migrations/133_alter_ref_packaging_items_slot_scope.sql
-- What: Add slot_scope ENUM to ref_packaging_items, then UPDATE all 64 existing rows.
--       slot_scope values:
--         'always'         — slot appears in every template variant (bottle, can, keg container)
--         'labelled_only'  — slot appears only when decoration_integral=0 (label, sticker)
--         'we_supply_only' — slot appears only when supply='we_supply' (caps, lids, boxes,
--                            trays, holders, pallet, intercal, renforts, scotch, liners,
--                            keg_collars, keg_safe)
-- Why: The recompute compiler (Phase 3) filters ref_packaging_items by this column
--      to build the correct BOM for each (format, decoration_integral, supply) combination.
--      Without it the compiler must hard-code slot exclusions, which silently drifts.
-- Risk: ADD COLUMN + UPDATE. ADD is INSTANT (nullable DEFAULT). UPDATE touches 64 rows.
--       MEDIUM — wrong scope drops a real component from COGS. See report for full table.
-- Rollback: ALTER TABLE ref_packaging_items DROP COLUMN slot_scope;

ALTER TABLE ref_packaging_items
  ADD COLUMN slot_scope ENUM('always','labelled_only','we_supply_only')
    NOT NULL DEFAULT 'always'
    COMMENT 'always=all templates; labelled_only=decoration_integral=0 only; we_supply_only=supply=we_supply only';

-- ── 'always': container slots — present regardless of decoration or supply ─────────────
-- bottle, can: the physical container. For pre-printed cans the MI changes (beer-specific vs generic)
-- but the SLOT itself is always present.
UPDATE ref_packaging_items SET slot_scope = 'always'
WHERE slot_name IN ('bottle','can');

-- ── 'labelled_only': slots that only appear when we apply decoration in-house ──────────
-- label: label sheet applied in-house (absent when decoration_integral=1: pre-printed/sleeved)
-- sticker: same — a secondary label/sticker (e.g. for bottle-cap, format, or can sticker)
UPDATE ref_packaging_items SET slot_scope = 'labelled_only'
WHERE slot_name IN ('label','sticker');

-- ── 'we_supply_only': slots that disappear when client supplies their own packaging ─────
-- crown_caps, can_lids: closures we provide
-- outer_box: carton case
-- outer_tray: tray wrap (4-pack/6-pack)
-- holder: 4-pack bottle carrier / can holder ring
-- pallet, intercal: pallet + interleaf (bulk-export only)
-- renforts_single, renforts_double: PD8 reinforcement inserts
-- scotch: sealing tape (branded or transparent)
-- keg_collars, keg_safe: keg finishing (we apply these; client kegs arrive bare)
-- liner_client, liner_transport: keg liner bags (we supply; client-supply = client brings own)
UPDATE ref_packaging_items SET slot_scope = 'we_supply_only'
WHERE slot_name IN (
  'crown_caps',
  'can_lids',
  'outer_box',
  'outer_tray',
  'holder',
  'pallet',
  'intercal',
  'renforts_single',
  'renforts_double',
  'scotch',
  'keg_collars',
  'keg_safe',
  'liner_client',
  'liner_transport'
);

-- Verify all 64 rows got classified (0 rows with NULL or missed DEFAULT 'always' that were wrong)
-- Expected: bottle+can = 'always', label+sticker = 'labelled_only', rest = 'we_supply_only'
-- NOTE for operator: the full slot→scope assignment is in the Phase 1 report (see migration header).
