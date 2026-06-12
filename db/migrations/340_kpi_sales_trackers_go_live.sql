-- 340: Flip the 5 production-beer sales trackers live after Opus fiscal verification.
-- Trackers 272-276 (HL/month series, HL/SKU, units/SKU MoM, trade-channel, HL/recipe)
-- were seeded data_ready=0 in mig 338. Independently verified 2026-06-12:
--   * 2026-05 production HL = 1097.38 (matches the canonical reference)
--   * #273 (HL/SKU) / #275 (trade-channel) / #276 (HL/recipe) each sum to
--     #272's latest-month total (2026-06 = 458.15 HL) — all reconcile to one
--     production-beer universe; #275 carries the mandatory non_classé bucket.
-- Safe/idempotent UPDATE on existing rows.
UPDATE ref_kpi_trackers
   SET data_ready = 1,
       readiness  = 'live'
 WHERE tracker_no IN (272, 273, 274, 275, 276);
