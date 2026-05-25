-- db/migrations/132_seed_ref_packaging_bom_templates.sql
-- What: Seed ref_packaging_bom_templates with current reality:
--       • All active non-composite BOT formats → labelled / we_supply template
--       • All active non-composite CAN+CAN33 formats → decoration_integral=1 / we_supply
--           (one variant covers BOTH pre-printed core-range cans AND pre-sleeved BLO/EPH cans —
--            structurally identical: beer-specific container MI, no label line). No generic-can+
--            we-label variant: operator confirms no Neb can is we-supply-labelled. Contract
--            labelled cans are client_supply / per-client — assigned in the recette UI, not seeded.
--       • KEG format (F) → labelled / we_supply (keg collar is the "label")
--       • CUV format (V) → labelled / we_supply (same: liner + collar)
--       • NULL run_type formats (P25, P50, PAD, PAL) → skip for now (sampler/pallet,
--         no standard packaging BOM).
--       Client-supply and sleeved variants NOT seeded yet — structure supports them.
-- Why: Establishes the seed data the Phase 3 compiler reads to determine which
--      ref_packaging_items slots to include/exclude per SKU.
-- Risk: INSERT IGNORE on UNIQUE(format_id, decoration_integral, supply) — fully idempotent. LOW.
-- Rollback: DELETE FROM ref_packaging_bom_templates; (leaves table intact)

-- BOT formats: labelled / we_supply
INSERT IGNORE INTO ref_packaging_bom_templates (format_id, decoration_integral, supply, name)
SELECT id, 0, 'we_supply',
  CONCAT(format_code, ' — bouteille étiquetée (La Neb)')
FROM ref_packaging_formats
WHERE run_type = 'bot' AND is_composite = 0 AND is_active = 1;

-- CAN + CAN33 formats: decoration_integral=1 / we_supply
-- Covers BOTH pre-printed core-range cans (EMB/MOO/SPY/STI/ZEP) AND pre-sleeved BLO/EPH cans.
-- Both are structurally identical (beer-specific container MI, no separate label line); the
-- difference (printed vs sleeved can MI) is resolved at the recipe binding, not the template.
-- No generic-can + we-supply-label variant is seeded (operator: no Neb can is we-labelled).
INSERT IGNORE INTO ref_packaging_bom_templates (format_id, decoration_integral, supply, name)
SELECT id, 1, 'we_supply',
  CONCAT(format_code, ' — canette pré-imprimée / pré-sleevée (La Neb)')
FROM ref_packaging_formats
WHERE run_type IN ('can','can33') AND is_composite = 0 AND is_active = 1;

-- KEG format: labelled / we_supply (collar = "label")
INSERT IGNORE INTO ref_packaging_bom_templates (format_id, decoration_integral, supply, name)
SELECT id, 0, 'we_supply',
  CONCAT(format_code, ' — fût (La Neb)')
FROM ref_packaging_formats
WHERE run_type = 'keg' AND is_composite = 0 AND is_active = 1;

-- CUV format: labelled / we_supply (liner + collar)
INSERT IGNORE INTO ref_packaging_bom_templates (format_id, decoration_integral, supply, name)
SELECT id, 0, 'we_supply',
  CONCAT(format_code, ' — cuve (La Neb)')
FROM ref_packaging_formats
WHERE run_type = 'cuv' AND is_composite = 0 AND is_active = 1;
