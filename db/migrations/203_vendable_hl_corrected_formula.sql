-- db/migrations/203_vendable_hl_corrected_formula.sql
--
-- What: Three steps:
--       1. Patch ref_skus.units_per_pack for two inactive SKUs (BLA4, ZEP6C)
--          that v_sku_volume excluded — both are 24-pack formats.
--       2. Re-apply v_bd_packaging_v2_vendable with the corrected formula
--          (divides vendable_units by units_per_pack before multiplying by
--          hl_per_unit; preserves the echo-row guard from mig 193).
--       3. Re-backfill bd_packaging_v2.vendable_hl from the corrected view.
--          Audit every row touched.
--
-- Why:  Mig 200 backfill used `vendable_units × hl_per_unit` which silently
--       multiplied by per-pack HL while prod_total_units counted individual
--       items — 7-24× over-compute. Mig 201 rolled it back. Mig 202 added
--       units_per_pack via v_sku_volume.units_per_format (122/155 active SKUs
--       resolved automatically; 33 NULL kept at default=1 for BU/CU/F/V/draft-
--       pours/composites where pack=1 is correct).
--
--       Live verification of the corrected formula (`(prod / units_per_pack)
--       × hl_per_unit`) against operator-trusted legacy bd_packaging values:
--         EMB4 batch 129: legacy 76.060 HL — corrected 76.058 HL ✓
--         ZEPF batch 209: legacy 69.000 HL — corrected 68.990 HL ✓ (−0.01 liq loss)
--         MOOF batch 120: legacy 66.200 HL — corrected 66.200 HL ✓
--         ZEP6C batch 126: legacy 214.560 HL — corrected 214.560 HL ✓ (after step 1 patch)
--
-- Inactive-SKU patch rationale (PM-approved 2026-05-28, Kouros-confirmed):
--   BLA4 (deactivated, 6 historical v2 rows): hl_per_unit=0.0792 = 24×0.0033
--     (33cl bottle) → units_per_pack=24.
--   ZEP6C (deactivated by mig 191, 1 historical v2 row): hl_per_unit=0.12 =
--     24×0.005 (50cl can) → units_per_pack=24. Confirmed by legacy operator
--     value for batch 126 (42912/24 × 0.12 = 214.56 HL).
--   No other inactive SKU has bd_packaging_v2 rows (live-verified).
--
-- Risk: MEDIUM — touches ~1996 rows (same scope as mig 200 backfill). Mitigations:
--   - CREATE OR REPLACE VIEW is INSTANT.
--   - UPDATE scoped to vendable_hl IS NULL.
--   - audit_row_revisions captures before_json (NULL) and after_json (new value).
--   - Inactive-SKU patch limited to 2 rows.
--
-- Rollback:
--   UPDATE bd_packaging_v2 SET vendable_hl = NULL
--    WHERE id IN (SELECT target_pk FROM audit_row_revisions
--                  WHERE comment='vendable_hl_corrected_formula_mig203');
--   DELETE FROM audit_row_revisions WHERE comment='vendable_hl_corrected_formula_mig203';
--   UPDATE ref_skus SET units_per_pack = 1 WHERE sku_code IN ('BLA4','ZEP6C');
--
-- NOTE: All DDL/DML migrate.php-safe.

-- ============================================================================
-- STEP 1: Patch inactive SKUs with derivable units_per_pack.
-- ============================================================================

UPDATE ref_skus SET units_per_pack = 24
 WHERE sku_code IN ('BLA4', 'ZEP6C')
   AND units_per_pack <> 24;

-- ============================================================================
-- STEP 2: Re-apply view with corrected formula.
-- ============================================================================
-- Echo-row guard preserved (special_qty = prod_total → effective_special=0).
-- New: divide vendable_units by units_per_pack before multiplying by hl_per_unit.

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id,
  p.sku_id_fk,
  s.hl_per_unit,
  s.units_per_pack,
  (
    COALESCE(p.prod_total_units, 0)
  + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
         ELSE COALESCE(p.special_qty_units, 0) END
  - COALESCE(p.qa_analyses_units,       0)
  - COALESCE(p.qa_library_units,        0)
  - COALESCE(p.unsaleable_units,        0)
  - COALESCE(p.loss_4pack_btl_units,    0)
  - COALESCE(p.loss_4pack_can_units,    0)
  - COALESCE(p.loss_wrap_btl_units,     0)
  - COALESCE(p.loss_wrap_can_units,     0)
  - COALESCE(p.loss_label_btl_units,    0)
  - COALESCE(p.loss_keg_collar_units,   0)
  - COALESCE(p.loss_crown_cork_units,   0)
  - COALESCE(p.loss_can_lid_units,      0)
  - COALESCE(p.loss_keg_save_units,     0)
  - COALESCE(p.loss_container_btl_units,0)
  - COALESCE(p.loss_container_can_units,0)
  ) AS vendable_units,
  CASE WHEN s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0 THEN NULL
       ELSE CAST(
         (
           (
             COALESCE(p.prod_total_units, 0)
           + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                  ELSE COALESCE(p.special_qty_units, 0) END
           - COALESCE(p.qa_analyses_units,       0)
           - COALESCE(p.qa_library_units,        0)
           - COALESCE(p.unsaleable_units,        0)
           - COALESCE(p.loss_4pack_btl_units,    0)
           - COALESCE(p.loss_4pack_can_units,    0)
           - COALESCE(p.loss_wrap_btl_units,     0)
           - COALESCE(p.loss_wrap_can_units,     0)
           - COALESCE(p.loss_label_btl_units,    0)
           - COALESCE(p.loss_keg_collar_units,   0)
           - COALESCE(p.loss_crown_cork_units,   0)
           - COALESCE(p.loss_can_lid_units,      0)
           - COALESCE(p.loss_keg_save_units,     0)
           - COALESCE(p.loss_container_btl_units,0)
           - COALESCE(p.loss_container_can_units,0)
           ) / s.units_per_pack
         ) * s.hl_per_unit
         - COALESCE(p.loss_liquid_other_units, 0) / 100
         AS DECIMAL(14,4))
  END AS vendable_hl
FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk;

-- ============================================================================
-- STEP 3: Audit + re-backfill bd_packaging_v2.vendable_hl.
-- ============================================================================

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0                                                AS user_id,
  'migration_203'                                  AS username,
  'bd_packaging_v2'                                AS target_table,
  p.id                                             AS target_pk,
  'update'                                         AS action,
  JSON_OBJECT('vendable_hl', p.vendable_hl)        AS before_json,
  JSON_OBJECT('vendable_hl', v.vendable_hl)        AS after_json,
  'vendable_hl_corrected_formula_mig203'           AS comment
FROM bd_packaging_v2 p
JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
WHERE p.vendable_hl IS NULL
  AND p.sku_id_fk IS NOT NULL
  AND p.is_tombstoned = 0
  AND v.vendable_hl IS NOT NULL;

UPDATE bd_packaging_v2 p
JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
   SET p.vendable_hl = v.vendable_hl
 WHERE p.vendable_hl IS NULL
   AND p.sku_id_fk IS NOT NULL
   AND p.is_tombstoned = 0
   AND v.vendable_hl IS NOT NULL;

-- ============================================================================
-- STEP 4: schema_meta refresh on bd_packaging_v2.
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'bd_packaging_v2',
  'source',
  'public/modules/form-packaging.php + python/ingest_*.py',
  'allowed',
  'Web-form packaging events. vendable_hl computed via v_bd_packaging_v2_vendable: (vendable_units / ref_skus.units_per_pack) × ref_skus.hl_per_unit − loss_liquid_other_units/100. Echo-row guard: rows with special_qty_units=prod_total_units treat special as 0 (normalizer-emitted parallel-SKU mirrors). Backfilled by mig 203 (validated against legacy operator values: EMB4/129 76.06, ZEP6C/126 214.56, ZEPF/209 69.00).'
)
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);

-- end migration 203
