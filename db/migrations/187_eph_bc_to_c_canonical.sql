-- Migration 187: EPH BC→C canonical fold
-- Purpose: EPH{1-4}C is the canonical 24-can-bulk format (identical to ZEPC, format 7 = C).
--   BC was a legacy variant code; C is now canonical per operator (2026-05-28).
--   Renames sku_code EPH{n}BC → EPH{n}C and repoints format_id 8 (BC) → 7 (C).
--   Demotes old BC codes to ref_sku_aliases for backward-compatible resolution.
-- Author: web
-- Date: 2026-05-28

-- Rename EPH1BC → EPH1C, repoint format 8 → 7
UPDATE ref_skus SET sku_code = 'EPH1C', format_id = 7 WHERE id = 29 AND sku_code = 'EPH1BC';
UPDATE ref_skus SET sku_code = 'EPH2C', format_id = 7 WHERE id = 32 AND sku_code = 'EPH2BC';
UPDATE ref_skus SET sku_code = 'EPH3C', format_id = 7 WHERE id = 35 AND sku_code = 'EPH3BC';
UPDATE ref_skus SET sku_code = 'EPH4C', format_id = 7 WHERE id = 38 AND sku_code = 'EPH4BC';

-- Aliases: legacy BC codes resolve to the new canonical C ids
INSERT INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('EPH1BC', 29, 'Legacy BC 24-can-bulk code; C is canonical (=ZEPC format 7). BC→C fold 2026-05-28.');
INSERT INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('EPH2BC', 32, 'Legacy BC 24-can-bulk code; C is canonical (=ZEPC format 7). BC→C fold 2026-05-28.');
INSERT INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('EPH3BC', 35, 'Legacy BC 24-can-bulk code; C is canonical (=ZEPC format 7). BC→C fold 2026-05-28.');
INSERT INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('EPH4BC', 38, 'Legacy BC 24-can-bulk code; C is canonical (=ZEPC format 7). BC→C fold 2026-05-28.');
