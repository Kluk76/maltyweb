-- db/migrations/158_mi_density_phosphoric.sql
-- What: Set density_g_per_ml = 1.6850 for PROC_PHOSPHORIQUE
-- Why: 85% food-grade phosphoric acid has a confirmed SDS density of 1.685 g/ml at 20°C
--      (operator-confirmed 2026-05-26). Recipe lines are in ml, pricing is per kg.
--      Without this, unit_to_canonical_factor() refuses the ml→kg conversion (returns null)
--      and logs a skip. With this, it computes the correct factor: 1.685 × 0.001 = 0.001685.
--      Other liquid PROC_ MIs (PROC_DEHAZE, PROC_AMS, PROC_CLAREX) are intentionally
--      left NULL — their SDS densities have not been supplied by the operator yet.
-- Risk: Single-row UPDATE on a reference table. PROC_PHOSPHORIQUE id = 80 (verified).
-- Rollback: UPDATE ref_mi SET density_g_per_ml = NULL WHERE mi_id = 'PROC_PHOSPHORIQUE';

UPDATE ref_mi
   SET density_g_per_ml = 1.6850
 WHERE mi_id = 'PROC_PHOSPHORIQUE';
