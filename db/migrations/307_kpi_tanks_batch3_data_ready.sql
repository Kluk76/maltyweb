-- Migration 307: KPI tanks Phase 2b Batch 3 — flip data_ready=1 for built handlers
--
-- Built trackers (readiness='compute'):
--   #19 garde_vs_target         — garde CCT→BBT vs cible (14j défaut; ref_recipes override)
--   #21 cold_crash_dryhop       — cold crash / dry-hop en cours
--   #22 fermentation_deviations — déviations DFE / atténuation / durée
--   #23 suggested_next_brew     — prochaines bières à brasser (suggestion)
--   #24 temp_pressure_excursions — excursions température/pression
--
-- NOT built (occupancy — requires Node tank-sim port):
--   #13 cct_bbt_occupancy, #14 cct_capacity_utilization,
--   #15 cct_idle_days, #17 hl_in_tank
--
-- NOT built (gap — no source columns in bd_fermenting_v2):
--   #25 diacetyl_rest_tracking, #26 yeast_generation, #27 repitch_count,
--   #28 harvest_yield, #29 co2_recovery, #30 cip_cleaning_due,
--   #32 avg_yeast_generation, #33 yeast_gen_vs_ferment_time,
--   #36 turbidity_deviations

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE tracker_no IN (19, 21, 22, 23, 24)
   AND source_domain = 'tanks';
