-- ============================================================================
-- Migration 238: Cuve réutilisée (serving-tank re-allocation)
--
-- WHAT:
--   Adds a self-FK `reuses_packaging_id_fk` to bd_packaging_v2.
--   A non-NULL value means this row is a "cuve réutilisée" re-allocation:
--   the same physical serving tank changes ownership to a new client with
--   ZERO new volume packaged.  The source row keeps its original vendable_hl.
--
-- WHY:
--   Allows the packagingform to record a client-ownership transfer of an
--   already-filled serving tank without creating phantom packaged volume.
--   The view guard (below) forces vendable_hl = 0 / beer_tax_base_hl = 0 /
--   loss_kpi_hl = 0 for every reuse row so consumers (tank-simulator,
--   loss-metrics, sb-board) cannot double-count HL even if they read the
--   stored column directly.
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_v2 DROP FOREIGN KEY fk_bdpkg_v2_reuse,
--                                DROP COLUMN reuses_packaging_id_fk;
--   Then restore the prior view DDL from migration 233 source.
-- ============================================================================

-- 1. Add self-FK column (MySQL 8 syntax — no IF NOT EXISTS on ADD COLUMN)
ALTER TABLE bd_packaging_v2
  ADD COLUMN reuses_packaging_id_fk BIGINT UNSIGNED NULL
    COMMENT 'Self-FK: non-NULL = this row is a cuve-réutilisée re-allocation; ZERO volume packaged. Source row keeps its vendable_hl.'
    AFTER audit_flags,
  ADD CONSTRAINT fk_bdpkg_v2_reuse
    FOREIGN KEY (reuses_packaging_id_fk)
    REFERENCES bd_packaging_v2(id)
    ON DELETE RESTRICT;

-- 2. Index for the FK (MySQL requires it; also used by candidate-exclusion subquery)
ALTER TABLE bd_packaging_v2
  ADD KEY idx_bdpv2_reuse_fk (reuses_packaging_id_fk);

-- 3. Recreate v_bd_packaging_v2_vendable with reuse-row guard.
--    Every computed output (vendable_units, vendable_hl, beer_tax_base_hl,
--    loss_kpi_hl) returns 0 when reuses_packaging_id_fk IS NOT NULL.
--    All existing logic is preserved byte-for-byte inside the ELSE branches.
CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id          AS id,
  p.run_type    AS run_type,
  p.sku_id_fk   AS sku_id_fk,
  s.hl_per_unit     AS hl_per_unit,
  s.units_per_pack  AS units_per_pack,

  -- vendable_units: 0 for reuse rows; existing logic otherwise
  CASE WHEN p.reuses_packaging_id_fk IS NOT NULL THEN 0
       WHEN p.run_type IN ('keg','cuv') THEN
         (COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
       ELSE
         (((((((COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
             - COALESCE(p.unsaleable_units,0))
             - COALESCE(p.loss_uncapped_units,0))
             - CAST(COALESCE(p.loss_half_filled_units,0) * 0.5 AS DECIMAL(14,6)))
             - COALESCE(p.loss_untaxed_full_units,0))
             - COALESCE(p.qa_library_units,0))
             - COALESCE(p.qa_analyses_units,0))
  END AS vendable_units,

  -- vendable_hl: 0 for reuse rows; existing logic otherwise
  CASE WHEN p.reuses_packaging_id_fk IS NOT NULL THEN 0
       WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
       WHEN p.run_type IN ('keg','cuv') THEN
         CAST(
           ((((((COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
             / s.units_per_pack) * s.hl_per_unit)
             - (COALESCE(p.loss_keg_liquid_l,0) / 100))
             - (COALESCE(p.taproom_keg_l,0) / 100))
             - (COALESCE(p.loss_liquid_other_units,0) / 100))
           AS DECIMAL(14,4))
       ELSE
         CAST(
           ((((((((((COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
               - COALESCE(p.unsaleable_units,0))
               - COALESCE(p.loss_uncapped_units,0))
               - CAST(COALESCE(p.loss_half_filled_units,0) * 0.5 AS DECIMAL(14,6)))
               - COALESCE(p.loss_untaxed_full_units,0))
               - COALESCE(p.qa_library_units,0))
               - COALESCE(p.qa_analyses_units,0))
             / s.units_per_pack) * s.hl_per_unit)
             - (COALESCE(p.loss_liquid_other_units,0) / 100))
           AS DECIMAL(14,4))
  END AS vendable_hl,

  -- beer_tax_base_hl: 0 for reuse rows; existing logic otherwise
  CASE WHEN p.reuses_packaging_id_fk IS NOT NULL THEN 0
       WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
       WHEN p.run_type IN ('keg','cuv') THEN
         CAST(
           ((((((COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
             / s.units_per_pack) * s.hl_per_unit)
             - (COALESCE(p.loss_keg_liquid_l,0) / 100))
             - (COALESCE(p.loss_liquid_other_units,0) / 100)))
           AS DECIMAL(14,4))
       ELSE
         CAST(
           ((((((((((COALESCE(p.prod_total_units,0) + (CASE WHEN p.special_qty_units = p.prod_total_units THEN 0 ELSE COALESCE(p.special_qty_units,0) END))
               - COALESCE(p.loss_uncapped_units,0))
               - CAST(COALESCE(p.loss_half_filled_units,0) * 0.5 AS DECIMAL(14,6)))
               - COALESCE(p.loss_untaxed_full_units,0))
               - COALESCE(p.qa_library_units,0))
               - COALESCE(p.qa_analyses_units,0))
             / s.units_per_pack) * s.hl_per_unit)
             - (COALESCE(p.loss_liquid_other_units,0) / 100)))
           AS DECIMAL(14,4))
  END AS beer_tax_base_hl,

  -- loss_kpi_hl: 0 for reuse rows; existing logic otherwise
  CASE WHEN p.reuses_packaging_id_fk IS NOT NULL THEN 0
       WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
       WHEN p.run_type IN ('keg','cuv') THEN
         CAST(COALESCE(p.loss_keg_liquid_l,0) / 100 AS DECIMAL(14,4))
       ELSE
         CAST(
           ((((COALESCE(p.unsaleable_units,0)
             + COALESCE(p.loss_uncapped_units,0))
             + CAST(COALESCE(p.loss_half_filled_units,0) * 0.5 AS DECIMAL(14,6)))
             + COALESCE(p.loss_untaxed_full_units,0))
             / s.units_per_pack) * s.hl_per_unit
           AS DECIMAL(14,4))
  END AS loss_kpi_hl

FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk;
