-- 345: Re-flip the 3 margin-adjacent all-sales tiles live after Opus verification
-- of their repoint bc -> inv_sales_ledger (mig 344 dropped them to data_ready=0).
-- Independently verified 2026-06-12:
--   * #86 revenue_month  = June 239 354 CHF (ledger SUM(sales_amount_chf), matched)
--   * #92 top_customers  = real names via ref_customers.name, no NULLs (Bevanar/Dorga 28 574…)
--   * #187 cogs_pct_revenue = 11.41% (period-matched: June COP 27 306 / June revenue 239 354;
--     larger ledger denominator moved % the correct direction; repoint also fixed the
--     bc-had-no-June-revenue gap).
UPDATE ref_kpi_trackers
   SET data_ready = 1,
       readiness  = 'live'
 WHERE tracker_no IN (86, 92, 187);
