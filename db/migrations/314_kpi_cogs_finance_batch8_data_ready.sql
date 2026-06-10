-- Migration 314: Phase 2b Batch 8 — cogs_finance compute handlers data_ready
-- Flips data_ready=1 for the 12 cogs_finance handlers built in Batch 8 and
-- independently verified by the orchestrator against the COP feed + inv_sales_bc:
--   #171 cogs_per_unit_sku          305.42 CHF/u (ref_sku_bom liquid+packaging)
--   #174 gross_margin_pct           85.63% (material-only, sales-cogs-data.json)
--   #175 full_cost_breakdown_beer   81100.38 CHF (sales-cogs bySKU)
--   #176 beer_tax_hl_liability      17574.34 CHF (sales-cogs totals.beerTax_CHF)
--   #177 beer_tax_by_category       17574.34 CHF (by cat 1/2/3/mixed)
--   #178 indirect_cost_categorization 6822.11 CHF (inv_charges_bc GL 43xx/4600)
--   #180 rd_qa_spend                3182.22 CHF (inv_charges_bc GL 4500+4510)
--   #181 wip_value                  1133.80 CHF (inv_tank_balances, as-of 2026-03)
--   #182 total_inventory_valuation  307645.71 CHF (RM 263161.80 + FG 43350.11 + WIP)
--   #183 cost_variance_prior_month  +4048.94 CHF (2026-04 vs 2026-03)
--   #184 cost_per_hl_trend          88.76 CHF/HL latest (cogs-report-data.json series)
--   #187 cogs_pct_revenue           13.56% (77169.37 / 569300.12)
-- Stubs (no source) stay data_ready=0: #185 break-even, #186 contribution margin,
-- #188 price-realisation, #189 cash-tied, #190 cost-of-quality, #191 budget,
-- #268 cash-conversion. WIP (#181) value is as-of the latest month with a
-- populated brew_cost_per_hl in inv_tank_balances; refreshes when the tank-sim
-- writes Apr/May balances. tracker_no is globally UNIQUE so no domain filter needed.
SET @noop = 1;

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE tracker_no IN (171, 174, 175, 176, 177, 178, 180, 181, 182, 183, 184, 187)
   AND data_ready = 0;

SET @noop = 1; -- migrate.php uses PDO exec(); must not end on a result-set stmt
