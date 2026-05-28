-- db/migrations/202_ref_skus_units_per_pack.sql
--
-- What: Add ref_skus.units_per_pack (DECIMAL(10,4) NOT NULL DEFAULT 1) and
--       backfill from v_sku_volume.units_per_format (which JOINs through
--       ref_packaging_formats → dbc_packaging_format_templates).
--
-- Why:  ref_skus.hl_per_unit is silently bi-modal: per-individual-item for
--       BU/CU/F/V/33C/X SKUs, per-pack-as-sold for B/C/4/4C/6C/12C/B12/4PB/4PC
--       SKUs. bd_packaging_v2.prod_total_units is uniformly counted in
--       individual items (single bottles/cans/kegs, litres for V) per operator
--       ruling 2026-05-28. The bi-modal hl_per_unit semantics combined with
--       uniform prod_total semantics produced a 7-24× over-compute on box
--       SKUs in the previous backfill (mig 200, rolled back by mig 201).
--
--       Adding units_per_pack lets the vendable_hl formula become uniform:
--           vendable_hl = (vendable_units / units_per_pack) × hl_per_unit
--       For BU/CU/F/V/33C/X (units_per_pack=1): identical to current behavior.
--       For box SKUs: correctly divides by the pack size before multiplying
--       by per-pack HL. Validated live: EMB4 batch 129 — legacy operator
--       vendable_hl=76.060 HL; corrected formula prediction (23052/24)×0.0792
--       = 76.07 HL. Exact match.
--
-- Coverage (live-verified 2026-05-28):
--   ref_skus active rows:                            155
--   v_sku_volume.units_per_format populated:         122 (78%)
--   NULL units_per_format (catalog-only formats):    33
--     - P25/P50 draft pours (26 rows)                : zero v2 usage, default=1 safe
--     - PD8/PAC/PAL/XMASPACK composites (4 rows)     : zero v2 usage, default=1 safe
--     - V cuv (3 rows: ZEPV, EMBV, MOOV)             : 244 v2 rows; ruling = 1
--       (V's prod_total is in litres; hl_per_unit_stored = 0.01 HL/L so
--        formula (prod/1)×0.01 = prod×0.01 = correct HL conversion).
--
-- Strategy:
--   1. ADD COLUMN units_per_pack DECIMAL(10,4) NOT NULL DEFAULT 1 — defaults
--      all rows to 1 (correct for the 33 NULL-units_per_format SKUs).
--   2. UPDATE ref_skus FROM v_sku_volume.units_per_format JOIN — overrides
--      the default to the canonical pack size for the 122 resolved SKUs.
--   3. schema_meta note on ref_skus updated to document the column.
--
-- Risk: LOW — additive column with default; no row-shape changes; no FK
--   touched; no downstream consumer breaks. The 1-row column scan in
--   the UPDATE is bounded (~150 active SKUs). View v_bd_packaging_v2_vendable
--   (mig 193) is NOT updated here — mig 203 follows.
--
-- Rollback:
--   ALTER TABLE ref_skus DROP COLUMN units_per_pack;
--   DELETE FROM schema_meta WHERE table_name='ref_skus' AND notes LIKE '%units_per_pack%';
--
-- NOTE: All DDL/DML migrate.php-safe. No standalone SELECT statements.

-- ============================================================================
-- STEP 1: Add the column with a safe default.
-- ============================================================================

ALTER TABLE ref_skus
  ADD COLUMN units_per_pack DECIMAL(10,4) NOT NULL DEFAULT 1
    COMMENT 'Individual items per pack-as-sold. 1 for BU/CU/F/V/33C/X (per-unit SKUs); 24 for C/B/4/4C/6C; 12 for B12/12C; 4 for 4PB/4PC; 1027 for X-pallet. Backfilled from v_sku_volume.units_per_format via mig 202.';

-- ============================================================================
-- STEP 2: Backfill from v_sku_volume.units_per_format.
-- ============================================================================
-- The view JOINs ref_skus → ref_packaging_formats (format_id) →
-- dbc_packaging_format_templates (catalog_id), so units_per_format reflects
-- the canonical pack size as declared in the format templates catalog.

UPDATE ref_skus s
  JOIN v_sku_volume v ON v.sku_id = s.id
   SET s.units_per_pack = v.units_per_format
 WHERE v.units_per_format IS NOT NULL
   AND v.units_per_format <> s.units_per_pack;

-- ============================================================================
-- STEP 3: schema_meta documentation refresh.
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'ref_skus',
  'reference',
  'scripts/python/refresh_ref_skus.py + admin web edits',
  'allowed',
  'SKU master. hl_per_unit is HL per pack-as-sold (bi-modal: per-individual for BU/CU/F/V/33C/X where pack=1, per-box for B/C/4/4C/6C/12C). units_per_pack (added mig 202) disambiguates: vendable_hl formula = (input_units / units_per_pack) × hl_per_unit. Backfilled from v_sku_volume.units_per_format.'
)
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);

-- end migration 202
