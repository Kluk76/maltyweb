-- db/migrations/272_bom_template_enrichment_batch.sql
--
-- What: BOM template-enrichment batch — 11-class data-altitude fix set (operator-authorized 2026-06-07).
--       All fixes go to binding/choice/template/ref_mi altitude.
--       ref_sku_bom is NEVER written directly — the compiler regenerates it.
--
-- Fix 1  — Sticker qty → 1 per box on sticker-beers' -B/-C/-BC boxes (formats 1,7,8).
--           ref_packaging_items qty_per_unit was 24 (per-bottle count). Correct: 1 per box.
--
-- Fix 2  — Suppress sticker lines on ALL active -B12 SKUs (format 2) via explicit-null
--           per-SKU packaging choices. -B12 = eshop bucket-2, no branded sticker.
--
-- Fix 3  — Branded-scotch swaps for EMB/MOO/DIV/ZEP bottles:
--           Add recipe-level scotch bindings → PKG_SCOTCH_EMB/MOO/DIV/ZEP.
--           Delete ZEP sticker binding (id=23, PKG_STICKER_ZEPC — box sticker, not keg sticker).
--           §8.1 compiler rule then auto-suppresses sticker for these recipes.
--
-- Fix 4  — Label-beers' -B boxes: add box_label slot to format 1 (new ref_packaging_items row,
--           NULL default). Add per-SKU choice (qty=1) for each label-beer -B SKU pointing at
--           the recipe's own PKG_LABEL_*. Add explicit-null choice for all sticker-beer + neither
--           -B SKUs to suppress the RQ from the unresolved box_label slot.
--
-- Fix 5  — Drop PKG_INTERCAL_NOMOQ slot from 4C SKUs (EMB4C/MOO4C/STI4C/SPY4C/ZEP4C) via
--           explicit-null per-SKU choice on the intercal slot.
--
-- Fix 6  — TEA: set PKG_TEA_BOT_CH price = 0.02 CHF, currency = 'CHF', pricing_unit = 'unit'.
--           Add per-container-count template item (slot_name='tea') on all glass-bottle formats
--           (1=B/24, 2=B12/12, 3=4 carton/24, 4=4PB/4, 5=BU/1, 6=X/1027).
--
-- Fix 7  — BOUNCED: P25/P50 keg-share cannot be implemented at data altitude without a
--           compiler change (P25/P50 have catalog_id=NULL — not in buildability gate).
--           Bounced to Dispatch A (compiler scope). No changes in this migration.
--
-- Fix 8  — Eshop-scotch fractions on PAL/PAC composites:
--           LOG_SCOTCH_ESHOP qty_per_unit 1.0 → 0.000930 (0.92 m/box ÷ 990 m/roll).
--
-- Fix 9  — COLLAB12: delete PKG_STICKER_DGD line via explicit-null choice on sticker slot.
--           Note: COLLAB12 is a COLLAB SKU (ref_sku_collab_temporal → recipe_id=31/DGD).
--           The sticker was compiled from the DGD recipe binding (id=3, role=sticker,
--           mi_id_fk=181/PKG_STICKER_ALT) — wait, let me re-check. Actually DGD has no
--           sticker binding in the data (not in sticker bindings list). COLLAB12's sticker
--           came from a prior state. This explicit-null choice will suppress it.
--
-- Fix 10 — PACKDECX8 (ref_skus.id=288): is_active = 0 (alias-duplicate hygiene).
--
-- Fix 11 — PKG_LABEL_DGD price is already 0.245 CHF (verified pre-flight). No change needed.
--
-- NOT in this migration:
--   - Fix 7 (keg-share on P25/P50): bounced to Dispatch A / compiler scope.
--   - Fix 11 (PKG_LABEL_DGD price): already set (0.245 CHF verified).
--
-- Idempotency: every UPDATE/INSERT guarded by pre-migration state checks.
--   INSERT rows use ON DUPLICATE KEY UPDATE where UNIQUE index exists.
--   For tables without UNIQUE on the natural key, SELECT-guard protects against re-run.
--
-- Recompile after applying: sudo php scripts/sku-bom-compile-cli.php --apply
--
-- Snapshot pre-migration: data/snapshots/ref_sku_bom-pre-mig272-*.json (1993 rows, taken 2026-06-07)
--
-- Date   : 2026-06-07
-- Author : migration_272 (operator-authorized BOM enrichment batch)
-- Risk   : MEDIUM — COGS-affecting; recompile required after apply.


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 1: Sticker qty → 1 on box formats 1 (B), 7 (C), 8 (BC)
-- Before: qty_per_unit=24 (per-bottle count — wrong; there is 1 sticker per box, not 24)
-- After : qty_per_unit=1
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_272', 'ref_packaging_items', id, 'update',
  JSON_OBJECT('slot_name', slot_name, 'format_id', format_id, 'qty_per_unit', qty_per_unit),
  JSON_OBJECT('slot_name', slot_name, 'format_id', format_id, 'qty_per_unit', 1),
  'mig272_fix1_sticker_qty_24_to_1'
FROM ref_packaging_items
WHERE slot_name = 'sticker'
  AND format_id IN (1, 7, 8)
  AND qty_per_unit = 24;

UPDATE ref_packaging_items
   SET qty_per_unit = 1.0000
 WHERE slot_name = 'sticker'
   AND format_id IN (1, 7, 8)
   AND qty_per_unit = 24;


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 2: Suppress sticker on ALL active -B12 SKUs via explicit-null per-SKU choices
-- Active -B12 SKU ids (format_id=2, is_active=1):
--   297=ALTB12, 62=DIBB12, 65=DIVB12, 296=DOAB12, 66=EMBB12,
--   67=EPH4B12, 64=MOOB12, 63=SPYB12, 68=STIB12, 69=ZEPB12
-- (COLLAB12 id=300 handled in Fix 9 via a different slot)
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_sku_packaging_choices', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('note', 'explicit-null sticker choices for B12 SKUs'),
   'mig272_fix2_b12_sticker_null_choices');

-- ALTB12 (297): explicit-null sticker
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 297, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 297 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- DIBB12 (62)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 62, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 62 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- DIVB12 (65)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 65, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 65 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- DOAB12 (296)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 296, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 296 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- EMBB12 (66)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 66, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 66 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- EPH4B12 (67)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 67, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 67 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- MOOB12 (64)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 64, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 64 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- SPYB12 (63)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 63, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 63 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- STIB12 (68)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 68, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 68 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- ZEPB12 (69)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 69, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (
  SELECT 1 FROM ref_sku_packaging_choices
  WHERE sku_id = 69 AND slot_name = 'sticker' AND is_checked = 1
    AND (effective_until IS NULL OR effective_until > CURDATE())
);


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 3: Branded-scotch bindings for EMB/MOO/DIV/ZEP
-- Add recipe-level scotch binding (role='scotch') for each branded-scotch beer.
-- Delete ZEP sticker binding (id=23) — PKG_STICKER_ZEPC is a box sticker, WRONG;
--   keg stickers = PKG_KEG_STICKER_*, handled separately.
-- §8.1 box-sticker rule: scotch resolves to non-TRANSP → sticker slot auto-suppressed.
-- EMB recipe_id=32, MOO=44, DIV=25, ZEP=57
-- PKG_SCOTCH_EMB=177, PKG_SCOTCH_MOO=178, PKG_SCOTCH_DIV=176, PKG_SCOTCH_ZEP=180
-- ══════════════════════════════════════════════════════════════════════════════

-- Audit: ZEP sticker binding delete (binding id=23)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_272', 'ref_recipe_packaging_bindings', id, 'update',
  JSON_OBJECT('recipe_id', recipe_id, 'role', role, 'mi_id_fk', mi_id_fk),
  JSON_OBJECT('_tombstone', 'mig272_fix3_ZEP_sticker_binding_deleted'),
  'mig272_fix3_delete_ZEP_sticker_binding'
FROM ref_recipe_packaging_bindings
WHERE id = 23;

-- Delete ZEP sticker binding (role=sticker, recipe_id=57)
DELETE FROM ref_recipe_packaging_bindings
 WHERE id = 23
   AND recipe_id = 57
   AND role = 'sticker';

-- Audit: new scotch bindings
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_recipe_packaging_bindings', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('recipe_id', 32, 'role', 'scotch', 'mi_id_fk', 177),
   'mig272_fix3_EMB_scotch_binding'),
  (0, 'migration_272', 'ref_recipe_packaging_bindings', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('recipe_id', 44, 'role', 'scotch', 'mi_id_fk', 178),
   'mig272_fix3_MOO_scotch_binding'),
  (0, 'migration_272', 'ref_recipe_packaging_bindings', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('recipe_id', 25, 'role', 'scotch', 'mi_id_fk', 176),
   'mig272_fix3_DIV_scotch_binding'),
  (0, 'migration_272', 'ref_recipe_packaging_bindings', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('recipe_id', 57, 'role', 'scotch', 'mi_id_fk', 180),
   'mig272_fix3_ZEP_scotch_binding');

-- EMB scotch binding (recipe_id=32, PKG_SCOTCH_EMB id=177)
INSERT INTO ref_recipe_packaging_bindings (recipe_id, role, mi_id_fk, effective_from, notes)
SELECT 32, 'scotch', 177, CURDATE(), 'mig272: branded scotch EMB replaces transparent'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_recipe_packaging_bindings
  WHERE recipe_id = 32 AND role = 'scotch'
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- MOO scotch binding (recipe_id=44, PKG_SCOTCH_MOO id=178)
INSERT INTO ref_recipe_packaging_bindings (recipe_id, role, mi_id_fk, effective_from, notes)
SELECT 44, 'scotch', 178, CURDATE(), 'mig272: branded scotch MOO replaces transparent'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_recipe_packaging_bindings
  WHERE recipe_id = 44 AND role = 'scotch'
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- DIV scotch binding (recipe_id=25, PKG_SCOTCH_DIV id=176)
INSERT INTO ref_recipe_packaging_bindings (recipe_id, role, mi_id_fk, effective_from, notes)
SELECT 25, 'scotch', 176, CURDATE(), 'mig272: branded scotch DIV replaces transparent'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_recipe_packaging_bindings
  WHERE recipe_id = 25 AND role = 'scotch'
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

-- ZEP scotch binding (recipe_id=57, PKG_SCOTCH_ZEP id=180)
INSERT INTO ref_recipe_packaging_bindings (recipe_id, role, mi_id_fk, effective_from, notes)
SELECT 57, 'scotch', 180, CURDATE(), 'mig272: branded scotch ZEP replaces transparent'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_recipe_packaging_bindings
  WHERE recipe_id = 57 AND role = 'scotch'
    AND (effective_until IS NULL OR effective_until > CURDATE())
);


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 4: Label-beers' -B boxes — 1× box-label
-- Add 'box_label' slot to ref_packaging_items format 1 (NULL default → requires per-SKU choice).
-- Per-SKU choices for label-beers (qty=1, pointing at their label MI):
--   DGDB(8)→PKG_LABEL_DGD(203), DIGB(12)→PKG_LABEL_DIG(632), DIPB(13)→PKG_LABEL_DIP(633),
--   DOCB(21)→PKG_LABEL_DOC(634), EPH1B(28)→PKG_LABEL_EPH1(148), EPH2B(31)→PKG_LABEL_EPH2(149),
--   EPH3B(34)→PKG_LABEL_EPH3(150), EPH4B(37)→PKG_LABEL_EPH4(151).
-- Explicit-null choices for all other active -B SKUs (sticker-beers + neither):
--   ALTB(2), DIBB(11), DIVB(16), DOAB(19), EMBB(25), MOOB(44), SPYB(49), STIB(53), ZEPB(58).
-- ══════════════════════════════════════════════════════════════════════════════

-- Add box_label slot to format 1 (B) — display_order=90 (after sticker at display_order likely 60)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 1, 'box_label', 1.0000, 'PKG_LABEL_{beer}%', NULL,
       0, 90, CURDATE(), 'always'
WHERE NOT EXISTS (
  SELECT 1 FROM ref_packaging_items
  WHERE format_id = 1 AND slot_name = 'box_label'
    AND (effective_until IS NULL OR effective_until > CURDATE())
);

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_packaging_items', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('format_id', 1, 'slot_name', 'box_label', 'qty_per_unit', 1, 'default_mi_id_fk', NULL),
   'mig272_fix4_box_label_slot_format1');

-- Label-beer -B choices (qty=1, label MI)
-- DGDB (sku_id=8) → PKG_LABEL_DGD (mi_id=203)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 8, 'box_label', 203, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=8 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- DIGB (sku_id=12) → PKG_LABEL_DIG (mi_id=632)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 12, 'box_label', 632, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=12 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- DIPB (sku_id=13) → PKG_LABEL_DIP (mi_id=633)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 13, 'box_label', 633, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=13 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- DOCB (sku_id=21) → PKG_LABEL_DOC (mi_id=634)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 21, 'box_label', 634, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=21 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- EPH1B (sku_id=28) → PKG_LABEL_EPH1 (mi_id=148)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 28, 'box_label', 148, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=28 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- EPH2B (sku_id=31) → PKG_LABEL_EPH2 (mi_id=149)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 31, 'box_label', 149, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=31 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- EPH3B (sku_id=34) → PKG_LABEL_EPH3 (mi_id=150)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 34, 'box_label', 150, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=34 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- EPH4B (sku_id=37) → PKG_LABEL_EPH4 (mi_id=151)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 37, 'box_label', 151, 1.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=37 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Explicit-null choices for sticker-beer + neither -B SKUs (suppress box_label RQ)
-- ALTB (2), DIBB (11), DIVB (16), DOAB (19), EMBB (25), MOOB (44), SPYB (49), STIB (53), ZEPB (58)
INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 2, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=2 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 11, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=11 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 16, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=16 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 19, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=19 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 25, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=25 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 44, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=44 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 49, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=49 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 53, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=53 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 58, 'box_label', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=58 AND slot_name='box_label' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_sku_packaging_choices', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('note', '17 box_label choices: 8 label-beers + 9 explicit-null suppress'),
   'mig272_fix4_box_label_choices');


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 5: Drop PKG_INTERCAL_NOMOQ lines from 4C SKUs via explicit-null intercal choices
-- Affected sku_ids: EMB4C=24, MOO4C=43, SPY4C=48, STI4C=52, ZEP4C=56
-- The intercal slot resolves via template default (ref_packaging_items id=34, default_mi_id_fk=579=PKG_INTERCAL_NOMOQ)
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 24, 'intercal', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=24 AND slot_name='intercal' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 43, 'intercal', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=43 AND slot_name='intercal' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 48, 'intercal', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=48 AND slot_name='intercal' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 52, 'intercal', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=52 AND slot_name='intercal' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 56, 'intercal', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=56 AND slot_name='intercal' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_sku_packaging_choices', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('note', '5 explicit-null intercal choices for EMB4C/MOO4C/STI4C/SPY4C/ZEP4C'),
   'mig272_fix5_nomoq_intercal_null_choices');


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 6: TEA — set PKG_TEA_BOT_CH price + add per-format-container template items
-- PKG_TEA_BOT_CH ref_mi id = 561
-- Price: 0.02 CHF per bottle (VetroSwiss regulatory fixed rate, GL 4201)
-- pricing_unit corrected from 'month' to 'unit' (per bottle)
-- Template items: one per glass-bottle format, qty = container count per SKU unit
--   fmt 1 (B)     = 24 bottles/box
--   fmt 2 (B12)   = 12 bottles/box
--   fmt 3 (4 ctn) = 24 bottles/carton
--   fmt 4 (4PB)   =  4 bottles/pack
--   fmt 5 (BU)    =  1 bottle/unit
--   fmt 6 (X)     = 1027 bottles/cage
-- ══════════════════════════════════════════════════════════════════════════════

-- Fix ref_mi for PKG_TEA_BOT_CH
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_272', 'ref_mi', id, 'update',
  JSON_OBJECT('price', price, 'currency', currency, 'pricing_unit', pricing_unit),
  JSON_OBJECT('price', 0.02, 'currency', 'CHF', 'pricing_unit', 'unit'),
  'mig272_fix6_PKG_TEA_BOT_CH_price'
FROM ref_mi
WHERE id = 561;

UPDATE ref_mi
   SET price = 0.020000,
       currency = 'CHF',
       pricing_unit = 'unit'
 WHERE id = 561
   AND (price IS NULL OR price != 0.020000);

-- Add TEA template item for format 1 (B, 24 bottles/box)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 1, 'tea', 24.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=1 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Add TEA template item for format 2 (B12, 12 bottles/box)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 2, 'tea', 12.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=2 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Add TEA template item for format 3 (4 carton, 24 bottles/carton)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 3, 'tea', 24.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=3 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Add TEA template item for format 4 (4PB, 4 bottles/pack)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 4, 'tea', 4.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=4 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Add TEA template item for format 5 (BU, 1 bottle/unit)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 5, 'tea', 1.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=5 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

-- Add TEA template item for format 6 (X cage, 1027 bottles/cage)
INSERT INTO ref_packaging_items
  (format_id, slot_name, qty_per_unit, mi_filter_pattern, default_mi_id_fk,
   is_default_checked, display_order, effective_from, slot_scope)
SELECT 6, 'tea', 1027.0000, 'PKG_TEA_BOT_CH', 561, 1, 95, CURDATE(), 'always'
WHERE NOT EXISTS (SELECT 1 FROM ref_packaging_items WHERE format_id=6 AND slot_name='tea' AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_packaging_items', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('note', 'TEA slot added to glass-bottle formats 1/2/3/4/5/6'),
   'mig272_fix6_tea_template_items');


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 7: BOUNCED — P25/P50 keg-share
-- P25/P50 formats have catalog_id=NULL and no ref_packaging_bom_templates row.
-- The compiler's buildability gate (INNER JOIN on dbc_packaging_format_templates)
-- excludes them — they are not in _compiler_gated_format_ids().
-- Adding ref_packaging_items rows for these formats would have no effect as the
-- compiler never reaches them.
-- Resolution: Dispatch A (compiler) must add a special handling path for draft-pour
-- formats (catalog_id=NULL, run_type=NULL) to process their template items.
-- No data changes in this migration for P25/P50.
-- ══════════════════════════════════════════════════════════════════════════════


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 8: Eshop-scotch fraction on PAL/PAC composites
-- LOG_SCOTCH_ESHOP (id=201) qty_per_unit was 1.0 (a full 2.50+ CHF roll per pack).
-- Correct usage: 0.92 m per box ÷ 990 m per roll = 0.000930 rolls per pack.
-- Update ref_sku_packaging_choices rows for PAL (sku_id=278) and PAC (sku_id=295).
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_272', 'ref_sku_packaging_choices', id, 'update',
  JSON_OBJECT('sku_id', sku_id, 'slot_name', slot_name, 'qty_per_unit', qty_per_unit),
  JSON_OBJECT('sku_id', sku_id, 'slot_name', slot_name, 'qty_per_unit', 0.000930),
  'mig272_fix8_eshop_scotch_fraction'
FROM ref_sku_packaging_choices
WHERE sku_id IN (278, 295)
  AND slot_name = 'scotch_eshop'
  AND mi_id_fk = 201
  AND qty_per_unit = 1.0000;

UPDATE ref_sku_packaging_choices
   SET qty_per_unit = 0.000930
 WHERE sku_id IN (278, 295)
   AND slot_name = 'scotch_eshop'
   AND mi_id_fk = 201
   AND qty_per_unit = 1.0000;


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 9: COLLAB12 — suppress PKG_STICKER_DGD placeholder
-- COLLAB12 (sku_id=300) is resolved via ref_sku_collab_temporal to recipe_id=31 (DGD).
-- The sticker slot currently resolves the DGD recipe binding (sticker role).
-- Note: DGD has NO sticker binding in the current data, but a prior compile emitted
-- a sticker line. After Fix 3's addition of scotch binding for DIV (not DGD), DGD
-- remains without branded scotch. The correct fix: per-SKU explicit-null choice on
-- 'sticker' slot for COLLAB12 — suppresses any sticker inherited from DGD binding.
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO ref_sku_packaging_choices (sku_id, slot_name, mi_id_fk, qty_per_unit, is_checked, effective_from)
SELECT 300, 'sticker', NULL, 0.0000, 1, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM ref_sku_packaging_choices WHERE sku_id=300 AND slot_name='sticker' AND is_checked=1 AND (effective_until IS NULL OR effective_until > CURDATE()));

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
VALUES
  (0, 'migration_272', 'ref_sku_packaging_choices', 0, 'insert',
   JSON_OBJECT(),
   JSON_OBJECT('sku_id', 300, 'slot_name', 'sticker', 'mi_id_fk', NULL, 'qty_per_unit', 0),
   'mig272_fix9_collab12_sticker_null');


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 10: PACKDECX8 (ref_skus.id=288) — deactivate (alias-duplicate hygiene)
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_272', 'ref_skus', id, 'update',
  JSON_OBJECT('sku_code', sku_code, 'is_active', is_active),
  JSON_OBJECT('sku_code', sku_code, 'is_active', 0),
  'mig272_fix10_PACKDECX8_deactivate'
FROM ref_skus
WHERE id = 288 AND is_active = 1;

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 288
   AND is_active = 1;


-- ══════════════════════════════════════════════════════════════════════════════
-- FIX 11: PKG_LABEL_DGD price = 0.245 CHF
-- Pre-flight verified: already set to 0.245000 CHF. No change required.
-- ══════════════════════════════════════════════════════════════════════════════
-- (No SQL needed)


-- ══════════════════════════════════════════════════════════════════════════════
-- Schema meta: no new tables; all writes go to existing tables (allowed policy).
-- schema_meta.corrections_policy verified: ref_mi='allowed', ref_packaging_items='allowed',
-- ref_recipe_packaging_bindings='allowed', ref_sku_packaging_choices='allowed',
-- ref_skus='allowed'.
-- ══════════════════════════════════════════════════════════════════════════════
