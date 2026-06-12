-- 343: Flip the 4 per-month stacked-columns sales trackers live after Opus verification.
-- Trackers 277-280 (HL by channel/recipe/SKU per month, units by SKU per month) seeded
-- data_ready=0 in mig 342. Independently verified 2026-06-12:
--   * each tracker's 12 monthly columns reconcile (segments sum to the month total)
--   * 2026-05 HL trackers = 1097.38 (canonical production reference); units = 21340.92
--   * channel split verified per-month (non_classé 0.7-5.6% by HL, never dropped)
UPDATE ref_kpi_trackers
   SET data_ready = 1,
       readiness  = 'live'
 WHERE tracker_no IN (277, 278, 279, 280);
