-- Migration 231: packaging disposition columns + corrected vendable view
--
-- What:  Adds four disposition columns to bd_packaging_v2 (new per-run-type
--        loss / taproom fields introduced by the confirmed packaging-loss convention).
--        Replaces v_bd_packaging_v2_vendable with run-type-aware formulas:
--        • material-scrap columns are NO LONGER subtracted from vendable_units
--        • bottle/can: unsaleable, loss_uncapped, loss_half_filled (*0.5), qa holds
--        • keg/cuv: loss_keg_liquid_l, taproom_keg_l
--        • new output columns: beer_tax_base_hl, loss_kpi_hl
--
-- Why:   The old view wrongly subtracted 11 material-scrap columns (label losses,
--        crown-cork losses, keg-collar losses, etc.) from vendable_units, causing
--        vendable_hl to be understated by every scrap unit ever recorded.
--        The new convention: material scraps are a separate material-waste tally
--        and must NOT affect the beer-volume accounting.
--
-- Rollback:
--   ALTER TABLE bd_packaging_v2
--     DROP COLUMN loss_uncapped_units,
--     DROP COLUMN loss_half_filled_units,
--     DROP COLUMN loss_keg_liquid_l,
--     DROP COLUMN taproom_keg_l;
--   Then re-create the old view (see migration 203 source).

ALTER TABLE bd_packaging_v2
  ADD COLUMN loss_uncapped_units    INT UNSIGNED  NULL AFTER loss_crown_cork_units,
  ADD COLUMN loss_half_filled_units INT UNSIGNED  NULL AFTER loss_uncapped_units,
  ADD COLUMN loss_keg_liquid_l      DECIMAL(10,3) NULL AFTER loss_keg_save_units,
  ADD COLUMN taproom_keg_l          DECIMAL(10,3) NULL AFTER loss_keg_liquid_l;

-- Also add computed result columns for beer_tax_base_hl and loss_kpi_hl
-- (stored alongside vendable_hl so dashboards/reports can read them directly).
ALTER TABLE bd_packaging_v2
  ADD COLUMN beer_tax_base_hl DECIMAL(8,3) NULL AFTER vendable_hl,
  ADD COLUMN loss_kpi_hl      DECIMAL(8,3) NULL AFTER beer_tax_base_hl;

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id,
  p.run_type,
  p.sku_id_fk,
  s.hl_per_unit,
  s.units_per_pack,

  -- vendable_units (run-type-aware; material scraps excluded)
  CASE
    WHEN p.run_type IN ('keg','cuv') THEN
      -- keg/cuv: prod + special_eff (no unit-level dispositions)
      COALESCE(p.prod_total_units, 0)
      + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
             ELSE COALESCE(p.special_qty_units, 0) END
    ELSE
      -- bottle/can: subtract beer-disposition unit counts (NOT material scraps)
      COALESCE(p.prod_total_units, 0)
      + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
             ELSE COALESCE(p.special_qty_units, 0) END
      - COALESCE(p.unsaleable_units,      0)
      - COALESCE(p.loss_uncapped_units,   0)
      - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
      - COALESCE(p.qa_library_units,      0)
      - COALESCE(p.qa_analyses_units,     0)
  END AS vendable_units,

  -- vendable_hl (NULL when SKU meta missing or units_per_pack <= 0)
  CASE
    WHEN s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0
    THEN NULL
    WHEN p.run_type IN ('keg','cuv') THEN
      CAST(
        (
          (COALESCE(p.prod_total_units, 0)
           + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                  ELSE COALESCE(p.special_qty_units, 0) END
          ) / s.units_per_pack * s.hl_per_unit
          - COALESCE(p.loss_keg_liquid_l, 0) / 100
          - COALESCE(p.taproom_keg_l,     0) / 100
          - COALESCE(p.loss_liquid_other_units, 0) / 100
        ) AS DECIMAL(14,4)
      )
    ELSE
      CAST(
        (
          (
            COALESCE(p.prod_total_units, 0)
            + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                   ELSE COALESCE(p.special_qty_units, 0) END
            - COALESCE(p.unsaleable_units,      0)
            - COALESCE(p.loss_uncapped_units,   0)
            - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
            - COALESCE(p.qa_library_units,      0)
            - COALESCE(p.qa_analyses_units,     0)
          ) / s.units_per_pack * s.hl_per_unit
          - COALESCE(p.loss_liquid_other_units, 0) / 100
        ) AS DECIMAL(14,4)
      )
  END AS vendable_hl,

  -- beer_tax_base_hl:
  --   bottle/can: vendable_hl + unsaleable converted to HL (invendable is taxed)
  --   keg/cuv:    vendable_hl + taproom_keg_l / 100 (taproom is taxed)
  CASE
    WHEN s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0
    THEN NULL
    WHEN p.run_type IN ('keg','cuv') THEN
      CAST(
        (
          (COALESCE(p.prod_total_units, 0)
           + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                  ELSE COALESCE(p.special_qty_units, 0) END
          ) / s.units_per_pack * s.hl_per_unit
          - COALESCE(p.loss_keg_liquid_l, 0) / 100
          -- taproom excluded from vendable but included in tax base (cancel out)
          - COALESCE(p.loss_liquid_other_units, 0) / 100
        ) AS DECIMAL(14,4)
      )
    ELSE
      CAST(
        (
          (
            COALESCE(p.prod_total_units, 0)
            + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                   ELSE COALESCE(p.special_qty_units, 0) END
            -- unsaleable back in for tax (taxed even if not sold)
            - COALESCE(p.loss_uncapped_units,   0)
            - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
            - COALESCE(p.qa_library_units,      0)
            - COALESCE(p.qa_analyses_units,     0)
          ) / s.units_per_pack * s.hl_per_unit
          - COALESCE(p.loss_liquid_other_units, 0) / 100
        ) AS DECIMAL(14,4)
      )
  END AS beer_tax_base_hl,

  -- loss_kpi_hl (beer-disposition losses only, in HL):
  --   bottle/can: (unsaleable + loss_uncapped + loss_half_filled*0.5) / units_per_pack * hl_per_unit
  --   keg/cuv:    loss_keg_liquid_l / 100
  CASE
    WHEN s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0
    THEN NULL
    WHEN p.run_type IN ('keg','cuv') THEN
      CAST(COALESCE(p.loss_keg_liquid_l, 0) / 100 AS DECIMAL(14,4))
    ELSE
      CAST(
        (
          COALESCE(p.unsaleable_units,      0)
          + COALESCE(p.loss_uncapped_units, 0)
          + CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
        ) / s.units_per_pack * s.hl_per_unit
        AS DECIMAL(14,4)
      )
  END AS loss_kpi_hl

FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk;
