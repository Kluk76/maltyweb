-- Migration 233: Add loss_untaxed_full_units to bd_packaging_v2 + rebuild view
--
-- What: Adds a new bottle/can-only loss category "Perte liquide – autre"
--       (loss_untaxed_full_units INT UNSIGNED NULL) to bd_packaging_v2.
--       Full unit lost = full liquid + full packaging consumed (incl. crown cork),
--       NOT in the beer-tax base, NOT in FG, counts as a loss.
--       Identical to invendable EXCEPT untaxed (invendable stays in beer_tax_base_hl;
--       this new category is subtracted from it).
--
-- Why:  invendable (unsaleable_units) is taxed. Operators need a bucket for units
--       that are lost/destroyed but should not be taxed (e.g. spillage, breakage
--       after capping that is not "sold" even in a taxable sense).
--
-- Rollback: DROP COLUMN loss_untaxed_full_units FROM bd_packaging_v2;
--           then restore the view to its mig-231/mig-232 form (remove the four
--           COALESCE(p.loss_untaxed_full_units, 0) references below).

ALTER TABLE bd_packaging_v2
  ADD COLUMN loss_untaxed_full_units INT UNSIGNED NULL
  AFTER loss_half_filled_units;

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id,
  p.run_type,
  p.sku_id_fk,
  s.hl_per_unit,
  s.units_per_pack,

  -- vendable_units -------------------------------------------------------
  -- keg/cuv: prod + special (no unit-level dispositions for kegs)
  -- bot/can: prod + special - unsaleable - uncapped - half_filled*0.5
  --          - qa_library - qa_analyses - untaxed_full
  CASE
    WHEN p.run_type IN ('keg','cuv')
      THEN (
        COALESCE(p.prod_total_units, 0)
        + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
               ELSE COALESCE(p.special_qty_units, 0) END
      )
    ELSE (
        COALESCE(p.prod_total_units, 0)
        + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
               ELSE COALESCE(p.special_qty_units, 0) END
        - COALESCE(p.unsaleable_units, 0)
        - COALESCE(p.loss_uncapped_units, 0)
        - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
        - COALESCE(p.loss_untaxed_full_units, 0)
        - COALESCE(p.qa_library_units, 0)
        - COALESCE(p.qa_analyses_units, 0)
    )
  END AS vendable_units,

  -- vendable_hl ----------------------------------------------------------
  CASE
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0)
      THEN NULL
    WHEN p.run_type IN ('keg','cuv')
      THEN CAST((
        ((
          COALESCE(p.prod_total_units, 0)
          + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                 ELSE COALESCE(p.special_qty_units, 0) END
        ) / s.units_per_pack) * s.hl_per_unit
        - (COALESCE(p.loss_keg_liquid_l, 0) / 100)
        - (COALESCE(p.taproom_keg_l, 0) / 100)
        - (COALESCE(p.loss_liquid_other_units, 0) / 100)
      ) AS DECIMAL(14,4))
    ELSE CAST((
        ((
          COALESCE(p.prod_total_units, 0)
          + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                 ELSE COALESCE(p.special_qty_units, 0) END
          - COALESCE(p.unsaleable_units, 0)
          - COALESCE(p.loss_uncapped_units, 0)
          - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
          - COALESCE(p.loss_untaxed_full_units, 0)
          - COALESCE(p.qa_library_units, 0)
          - COALESCE(p.qa_analyses_units, 0)
        ) / s.units_per_pack) * s.hl_per_unit
        - (COALESCE(p.loss_liquid_other_units, 0) / 100)
    ) AS DECIMAL(14,4))
  END AS vendable_hl,

  -- beer_tax_base_hl -----------------------------------------------------
  -- keg/cuv: vendable + taproom (taproom is taxed)
  -- bot/can: starts from prod+special (taxed base), then subtracts EVERY
  --          untaxed category. invendable (unsaleable) is NOT subtracted here
  --          (it IS taxed — omitting it is intentional). All other dispositions
  --          ARE subtracted: uncapped, half_filled, untaxed_full, qa_*, liquid.
  --          WARNING: omitting loss_untaxed_full_units here would silently TAX it.
  CASE
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0)
      THEN NULL
    WHEN p.run_type IN ('keg','cuv')
      THEN CAST((
        ((
          COALESCE(p.prod_total_units, 0)
          + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                 ELSE COALESCE(p.special_qty_units, 0) END
        ) / s.units_per_pack) * s.hl_per_unit
        - (COALESCE(p.loss_keg_liquid_l, 0) / 100)
        - (COALESCE(p.loss_liquid_other_units, 0) / 100)
      ) AS DECIMAL(14,4))
    ELSE CAST((
        ((
          COALESCE(p.prod_total_units, 0)
          + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                 ELSE COALESCE(p.special_qty_units, 0) END
          - COALESCE(p.loss_uncapped_units, 0)
          - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
          - COALESCE(p.loss_untaxed_full_units, 0)
          - COALESCE(p.qa_library_units, 0)
          - COALESCE(p.qa_analyses_units, 0)
        ) / s.units_per_pack) * s.hl_per_unit
        - (COALESCE(p.loss_liquid_other_units, 0) / 100)
    ) AS DECIMAL(14,4))
  END AS beer_tax_base_hl,

  -- loss_kpi_hl ----------------------------------------------------------
  -- keg/cuv: liquid lost from vessel (taproom is intentional, not a loss)
  -- bot/can: all beer-disposition losses (unsaleable + uncapped + half_filled*0.5
  --          + untaxed_full). Full unit = full volume.
  CASE
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0)
      THEN NULL
    WHEN p.run_type IN ('keg','cuv')
      THEN CAST((COALESCE(p.loss_keg_liquid_l, 0) / 100) AS DECIMAL(14,4))
    ELSE CAST((
        ((
          COALESCE(p.unsaleable_units, 0)
          + COALESCE(p.loss_uncapped_units, 0)
          + CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14,6))
          + COALESCE(p.loss_untaxed_full_units, 0)
        ) / s.units_per_pack) * s.hl_per_unit
    ) AS DECIMAL(14,4))
  END AS loss_kpi_hl

FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk;
