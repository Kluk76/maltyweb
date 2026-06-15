-- 350: Revive the discount-rate tile (tracker_no 100, slug discount_rebate_rate)
-- after Opus verification of the new canonical BC discount ingest (mig 348).
-- Verified 2026-06-15: May net discount (incl. credit memos) = 17 954.71 CHF
--   (legacy xlsx 17 957.83, Δ 3.12 = 0.02%, date-basis noise); May gross = 492 770.68
--   (ledger all-sales); rate = 3.64%. Definition LOCKED = net incl. credits.
-- Tile becomes a per-month rate trend (viz=line), manager-role.
UPDATE ref_kpi_trackers
   SET is_active  = 1,
       data_ready = 1,
       readiness  = 'live',
       viz_type   = 'line'
 WHERE tracker_no = 100;
