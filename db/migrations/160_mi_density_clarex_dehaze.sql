-- db/migrations/160_mi_density_clarex_dehaze.sql
-- What: Set PROC_CLAREX + PROC_DEHAZE density.
-- Why:  CLAREX and DEHAZE are the SAME product (prolyl endopeptidase enzyme;
--       Brewers Clarex = Murphy DeHaze) — operator-confirmed 2026-05-26. No
--       published density exists anywhere (DeHaze SDS "Not available"; Murphy
--       product page + DSM/Murphy Brewers Clarex sheets give none). Operator
--       instruction (2026-05-26): when no published density is found, use WATER
--       density (1.000) as the fallback. Applies to both. These are small-volume,
--       low-cost aids (~150 ml lines), so the fallback error is negligible vs the
--       phosphoric/AMS lines which carry real SDS densities.
-- Risk: LOW — two additive density values (operator-chosen fallback).
-- Rollback: UPDATE ref_mi SET density_g_per_ml = NULL WHERE mi_id IN ('PROC_CLAREX','PROC_DEHAZE');

UPDATE ref_mi SET density_g_per_ml = 1.0000 WHERE mi_id IN ('PROC_CLAREX', 'PROC_DEHAZE');
