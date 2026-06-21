-- db/migrations/417_vendable_view_loss_alloc.sql
--
-- What: Replace v_bd_packaging_v2_vendable so that loss_kpi_hl reads
--       loss_liquid_other_units_alloc (new derived column, mig 415) with a
--       COALESCE fallback to raw loss_liquid_other_units.
--
-- Only change vs migration 317: in the loss_kpi_hl CASE expression,
--   COALESCE(p.loss_liquid_other_units, 0)
-- is replaced with
--   COALESCE(p.loss_liquid_other_units_alloc, p.loss_liquid_other_units, 0)
-- in BOTH the keg/cuv arm and the bot/can arm.
--
-- All other view columns (vendable_units, vendable_hl, beer_tax_base_hl,
-- the HEAD-vs-leaf EXISTS subquery, the reassignment guard) are byte-for-byte
-- identical to migration 317.
--
-- Why COALESCE(_alloc, raw)?
--   Migration 416 backfills _alloc for all existing rows before this view
--   runs (416 < 417 apply order). For NEW rows submitted AFTER 416 but BEFORE
--   the form-packaging.php deploy, _alloc will be NULL until the next POST
--   triggers recompute_group_liquid_alloc(). COALESCE ensures those rows fall
--   back to raw and never silently produce NULL loss_kpi_hl.
--
-- Risk: LOW — CREATE OR REPLACE VIEW is metadata-only (no rows locked).
--       No change to vendable_hl or beer_tax_base_hl arms.
--
-- Rollback: re-apply migration 317 (restore COALESCE(p.loss_liquid_other_units,0)
--   in the two loss_kpi_hl arms).

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id,
  p.run_type,
  p.sku_id_fk,
  s.hl_per_unit,
  s.units_per_pack,

  /* vendable_units — HEAD with a child → 0; leaf with no child → normal calc */
  CASE
    WHEN EXISTS (
      SELECT 1 FROM bd_packaging_v2 c
      WHERE c.reuses_packaging_id_fk = p.id AND c.is_tombstoned = 0
    ) THEN 0
    WHEN p.run_type IN ('keg', 'cuv') THEN COALESCE(p.prod_total_units, 0)
    ELSE (
      (
        (
          (
            (
              COALESCE(p.prod_total_units, 0)
              - COALESCE(p.unsaleable_units, 0)
            )
            - COALESCE(p.loss_uncapped_units, 0)
          )
          - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14, 6))
        )
        - COALESCE(p.loss_untaxed_full_units, 0)
      )
      - COALESCE(p.qa_library_units, 0)
    ) - COALESCE(p.qa_analyses_units, 0)
  END AS vendable_units,

  /* vendable_hl — HEAD with a child → 0; leaf with no child → normal calc */
  CASE
    WHEN EXISTS (
      SELECT 1 FROM bd_packaging_v2 c
      WHERE c.reuses_packaging_id_fk = p.id AND c.is_tombstoned = 0
    ) THEN 0
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
    WHEN p.run_type IN ('keg', 'cuv') THEN
      CAST(
        (
          (COALESCE(p.prod_total_units, 0) / s.units_per_pack) * s.hl_per_unit
          - COALESCE(p.taproom_keg_l, 0) / 100
        ) AS DECIMAL(14, 4)
      )
    ELSE
      CAST(
        (
          (
            (
              (
                (
                  (
                    COALESCE(p.prod_total_units, 0)
                    - COALESCE(p.unsaleable_units, 0)
                  )
                  - COALESCE(p.loss_uncapped_units, 0)
                )
                - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14, 6))
              )
              - COALESCE(p.loss_untaxed_full_units, 0)
            )
            - COALESCE(p.qa_library_units, 0)
          )
          - COALESCE(p.qa_analyses_units, 0)
        ) / s.units_per_pack * s.hl_per_unit
        AS DECIMAL(14, 4)
      )
  END AS vendable_hl,

  /* beer_tax_base_hl — UNCHANGED: reassignment leaf (reuses IS NOT NULL) → 0 */
  CASE
    WHEN (p.reuses_packaging_id_fk IS NOT NULL) THEN 0
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
    WHEN p.run_type IN ('keg', 'cuv') THEN
      CAST(
        (COALESCE(p.prod_total_units, 0) / s.units_per_pack) * s.hl_per_unit
        AS DECIMAL(14, 4)
      )
    ELSE
      CAST(
        (
          (
            (
              (
                (
                  COALESCE(p.prod_total_units, 0)
                  - COALESCE(p.loss_uncapped_units, 0)
                )
                - CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14, 6))
              )
              - COALESCE(p.loss_untaxed_full_units, 0)
            )
            - COALESCE(p.qa_library_units, 0)
          )
          - COALESCE(p.qa_analyses_units, 0)
        ) / s.units_per_pack * s.hl_per_unit
        AS DECIMAL(14, 4)
      )
  END AS beer_tax_base_hl,

  /* loss_kpi_hl — CHANGED (mig 417): loss_liquid_other_units replaced with
     COALESCE(loss_liquid_other_units_alloc, loss_liquid_other_units) in both
     keg/cuv and bot/can arms. All other logic unchanged vs migration 317. */
  CASE
    WHEN (p.reuses_packaging_id_fk IS NOT NULL) THEN 0
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
    WHEN p.run_type IN ('keg', 'cuv') THEN
      CAST(
        (COALESCE(p.loss_keg_liquid_l, 0) / 100)
        + (COALESCE(p.loss_liquid_other_units_alloc, p.loss_liquid_other_units, 0) / 100)
        AS DECIMAL(14, 4)
      )
    ELSE
      CAST(
        (
          (
            (
              (
                (
                  COALESCE(p.unsaleable_units, 0)
                  + COALESCE(p.loss_uncapped_units, 0)
                )
                + CAST(COALESCE(p.loss_half_filled_units, 0) * 0.5 AS DECIMAL(14, 6))
              )
              + COALESCE(p.loss_untaxed_full_units, 0)
            ) / s.units_per_pack
          ) * s.hl_per_unit
        ) + (COALESCE(p.loss_liquid_other_units_alloc, p.loss_liquid_other_units, 0) / 100)
        AS DECIMAL(14, 4)
      )
  END AS loss_kpi_hl

FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
WHERE p.is_tombstoned = 0;

-- end migration 417
