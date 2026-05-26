-- db/migrations/159_mi_density_ams.sql
-- What: Set PROC_AMS density (Murphy & Son AMS acidifying blend).
-- Why:  AMS is logged in ml on recipe lines but priced/canonical in kg; its SDS
--       Section 9 gives relative density 1.08-1.10. Operator-confirmed midpoint
--       1.090 g/ml (2026-05-26). Enables ml->kg costing via
--       unit_to_canonical_factor(); without it the helper refuses AMS ml lines.
-- Risk: LOW -- single additive density value.
-- Rollback: UPDATE ref_mi SET density_g_per_ml = NULL WHERE mi_id = 'PROC_AMS';

UPDATE ref_mi SET density_g_per_ml = 1.0900 WHERE mi_id = 'PROC_AMS';
