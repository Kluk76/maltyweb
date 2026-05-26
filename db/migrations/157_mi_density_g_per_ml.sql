-- db/migrations/157_mi_density_g_per_ml.sql
-- What: Add density_g_per_ml column to ref_mi for liquid process aids
-- Why: The recipe-loader unit-conversion path uses a hardcoded ml→kg factor of 0.001
--      (water density), which is wrong for e.g. phosphoric acid (~1.685 g/ml).
--      This column holds the product-specific density so unit_to_canonical_factor()
--      can compute the correct ml→kg conversion factor (density × 0.001).
--      NULL = "no ml↔mass conversion needed" (correct for malt/hops/packaging MIs).
--      Populated selectively: only when the operator has confirmed the SDS value.
-- Risk: Additive — no existing data affected. INSTANT DDL (nullable column added).
-- Rollback: ALTER TABLE ref_mi DROP COLUMN density_g_per_ml;

ALTER TABLE ref_mi
  ADD COLUMN density_g_per_ml DECIMAL(8,4) NULL
    COMMENT 'Liquid process-aid density in g/ml at 20°C (SDS-confirmed). NULL = no ml↔mass conversion for this MI.'
  AFTER conversion_factor;
