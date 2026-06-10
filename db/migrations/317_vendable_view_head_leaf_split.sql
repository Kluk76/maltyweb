-- Migration 317: head/leaf vendable split on v_bd_packaging_v2_vendable
--
-- WHAT: Changes vendable_units and vendable_hl to return 0 when the row is a
--       HEAD (i.e. has at least one non-tombstoned child pointing at it via
--       reuses_packaging_id_fk), rather than when the row IS a leaf/reassignment
--       (reuses_packaging_id_fk IS NOT NULL). beer_tax_base_hl and loss_kpi_hl
--       are UNCHANGED — they continue to key off reuses_packaging_id_fk IS NOT NULL
--       (tax and loss liability stays on the production-time head, never on the
--       reassignment leaf).
--
-- WHY: Serving-tank reassignment records the final client as a new bd_packaging_v2
--      row with reuses_packaging_id_fk pointing at the source fill. The correct
--      rule is: vendable HL/units follow the FINAL client = the leaf of the chain.
--      A fill that has been reassigned (has a child) → vendable 0; a leaf with no
--      child (whether original or reassignment) → computes normally from its own
--      prod_total_units / sku_id_fk.
--
-- NO-OP on current data: zero rows with reuses_packaging_id_fk IS NOT NULL exist
-- today, so EXISTS(child) is always false and outputs are identical to before.
--
-- NOTE: No schema_meta row needed — this is a view, not a base table.

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

  /* loss_kpi_hl — UNCHANGED: reassignment leaf (reuses IS NOT NULL) → 0 */
  CASE
    WHEN (p.reuses_packaging_id_fk IS NOT NULL) THEN 0
    WHEN (s.hl_per_unit IS NULL OR s.units_per_pack IS NULL OR s.units_per_pack <= 0) THEN NULL
    WHEN p.run_type IN ('keg', 'cuv') THEN
      CAST(
        (COALESCE(p.loss_keg_liquid_l, 0) / 100)
        + (COALESCE(p.loss_liquid_other_units, 0) / 100)
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
        ) + (COALESCE(p.loss_liquid_other_units, 0) / 100)
        AS DECIMAL(14, 4)
      )
  END AS loss_kpi_hl

FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk
WHERE p.is_tombstoned = 0;
