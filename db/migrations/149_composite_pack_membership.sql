-- db/migrations/149_composite_pack_membership.sql
-- What: Populate ref_sku_composite_slots with the operator-confirmed recipe
--       membership of the mixed/discovery packs, and fold PACKDEC (sku 287)
--       into an alias of PD8 (sku 137).
-- Why:  Composite packs (PD8 "Pack Découverte 8", PAL "Pack Louis", XMASPACK,
--       PAC "Pack du Chef") decompose into single-bottle (BU, format 5) members
--       of several recipes. Their per-recipe HL share and ingredient attribution
--       derive from these slots (the step-③ compiler-fold consumes them).
--       Membership operator-confirmed 2026-05-26 — not inferred. PACKDEC
--       ("Pack Découverte") is the same 8-beer sampler as PD8 ("Pack Découverte
--       8"); it had 0 BOM rows and FRPACKDEC already chained to it, so it folds
--       to an alias of PD8. PAD (sku 277) is intentionally NOT touched: its
--       format claims 80×33cl, which contradicts "alias of PD8"; held for
--       operator reconciliation.
-- Recipe ids (verified live 2026-05-26, all is_active=1, single row each):
--   ALT=6 DIV=25 DIB=26 DOA=30 EMB=32 MOO=44 SPY=51 STI=52 ZEP=57. BU format=5.
-- Risk: LOW — INSERT into an empty table + one SKU deactivation + one alias
--       insert + one alias repoint. Nothing reads composite_slots until the
--       step-③ compiler-fold lands, so no COGS movement from this migration.
-- Rollback:
--   DELETE FROM ref_sku_composite_slots WHERE sku_id IN (137,138,278,295);
--   DELETE FROM ref_sku_aliases WHERE alias='PACKDEC';
--   UPDATE ref_sku_aliases SET canonical_sku_id=287 WHERE alias='FRPACKDEC';
--   UPDATE ref_skus SET is_active=1 WHERE id=287;

INSERT IGNORE INTO ref_sku_composite_slots
  (sku_id, recipe_id, units_per_recipe, slot_order, member_format_id)
VALUES
-- PD8 (137) Pack Découverte 8 — 1x single bottle of each of 8 beers
(137,  6, 1, 1, 5),   -- ALT Alternative
(137, 25, 1, 2, 5),   -- DIV Diversion
(137, 30, 1, 3, 5),   -- DOA Double Oat
(137, 32, 1, 4, 5),   -- EMB Embuscade
(137, 44, 1, 5, 5),   -- MOO Moonshine
(137, 51, 1, 6, 5),   -- SPY Speakeasy
(137, 52, 1, 7, 5),   -- STI Stirling
(137, 57, 1, 8, 5),   -- ZEP Zepp
-- PAL (278) Pack Louis — 2x single bottle of each of 6 beers (12 bottles)
(278,  6, 2, 1, 5),   -- ALT
(278, 26, 2, 2, 5),   -- DIB Diversion Blanche
(278, 30, 2, 3, 5),   -- DOA
(278, 32, 2, 4, 5),   -- EMB
(278, 44, 2, 5, 5),   -- MOO
(278, 51, 2, 6, 5),   -- SPY
-- XMASPACK (138) — 1x single bottle of each of 3 beers
(138, 30, 1, 1, 5),   -- DOA
(138, 32, 1, 2, 5),   -- EMB
(138, 44, 1, 3, 5),   -- MOO
-- PAC (295) Pack du Chef — 6x SPY + 6x DIB (12 bottles)
(295, 51, 6, 1, 5),   -- SPY
(295, 26, 6, 2, 5);   -- DIB

UPDATE ref_skus SET is_active = 0 WHERE id = 287;

INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes) VALUES
  ('PACKDEC', 137, 'Legacy/discontinued code for the 8-beer sampler. Canonical: PD8 (id 137, composite). Folded 2026-05-26.');

UPDATE ref_sku_aliases SET canonical_sku_id = 137 WHERE alias = 'FRPACKDEC';
