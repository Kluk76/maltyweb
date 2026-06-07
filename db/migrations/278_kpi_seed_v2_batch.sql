-- 278_kpi_seed_v2_batch.sql
--
-- What: KPI tracker seed batch — Production Tranche (Agent C).
--       Data-only: idempotent UPDATEs to ref_kpi_trackers.
--       NO schema changes. NO SELECT statements (migrate.php PDO::exec() limitation).
--
-- Sections:
--   1. Agent B honesty relabels (verbatim from Agent B spec)
--   2. Stub-mismatch cleanup (trackers data_ready=1 but handler=stub)
--   3. Production tranche flips (wort/racking/packaging/tanks handlers implemented)
--   4. Viewer seed-tuning proposal (COMMENTED OUT — operator must bless)
--
-- Idempotency: all UPDATEs are conditional on current state (no harm to re-run).
--
-- Date   : 2026-06-07
-- Author : migration_278 (kpi-production-tranche Agent C)
-- Risk   : LOW — ref_kpi_trackers metadata only; no COGS/inventory writes.


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 1: Agent B honesty relabels (verbatim)
-- ══════════════════════════════════════════════════════════════════════════════

-- #111: label was "Livraisons ce mois (nbre + CHF)" — value returned is CHF amount,
-- not a count. Relabel to be honest about what the tile shows.
UPDATE ref_kpi_trackers
   SET label = 'Dépenses livraisons ce mois (CHF)'
 WHERE id = 111;

-- #266: label was "Valeur stock MP (CHF)" — handler computes days-of-supply,
-- not a CHF stock value. Relabel + flip live.
UPDATE ref_kpi_trackers
   SET label = 'Jours de couverture stock MP',
       readiness = 'live'
 WHERE id = 266;


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 2: Stub-mismatch cleanup
-- Trackers with data_ready=1 but handlers that are stubs in kpi-handlers.php.
-- For each: flip data_ready=0 + readiness='compute' unless implemented this tranche.
-- ══════════════════════════════════════════════════════════════════════════════

-- #5 production_by_beer_yoy: IMPLEMENTED this tranche — stays data_ready=1 (flipped in §3).

-- #6 brewhouse_yield: stub — ref_recipes has no target_hl column; no yield denominator.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 6;

-- #170 cogs_per_unit_sku: stub (kpi_handler_cogs default branch) — no per-SKU COGS in pipeline.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 170;

-- #174 full_cost_breakdown_beer: stub — no per-beer full-cost breakdown in pipeline output.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 174;

-- #175 beer_tax_hl_liability: stub — beer-tax tables not accessible from kpi-handlers.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 175;

-- #176 beer_tax_by_category: stub — same as above.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 176;

-- #177 indirect_cost_categorization: stub — no pipeline output consumed by handler.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 177;

-- #179 rd_qa_spend: stub — inv_charges_bc source for GL 4500/4510 (same pattern as
-- maintenance_opex) could be added; leaving compute for a future cogs tranche.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 179;

-- #181 total_inventory_valuation: stub — requires WIP + FG valuations not available.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 181;

-- #192 peak_demand_kw: source is BSF EnergyData tab, not MySQL. Stays compute.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 192;

-- #193 reactive_power_kvarch: source is BSF EnergyData tab, not MySQL. Stays compute.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 193;

-- #194 electricity_cost_month: source is BSF pipeline output, not MySQL. Stays compute.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 194;

-- #202 co2_purchased_cost: source is BSF / inv_charges_bc (not yet wired). Stays compute.
UPDATE ref_kpi_trackers
   SET data_ready = 0, readiness = 'compute'
 WHERE id = 202;


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 3: Production tranche flips — handlers implemented this session
-- ══════════════════════════════════════════════════════════════════════════════

-- WORT domain (tracker 5 only — tracker 6 stays stub, see §2)

-- #5 production_by_beer_yoy: per-recipe YTD HL sparklines YoY from bd_brewing_gravity_v2.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 5;


-- RACKING domain (7 of 9 trackers implemented; 2 remain stub)

-- #37 avg_racking_time_beer: weighted avg hours/mise en fût per beer (rolling 12m).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 37;

-- #38 avg_racking_time_hl: avg min/HL across all rackings.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 38;

-- #39 rackings_month: count + HL mises en fût this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 39;

-- #40 racking_loss_pct: avg fermentation+racking loss % (wort vs racked, rolling 6m).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 40;

-- #41 brew_to_rack_cycle: avg days brassin to mise en fût (rolling 6m).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 41;

-- #42 blend_rackings_count: blends / mises en fût multi-cuves this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 42;

-- #46 rack_to_packaging_lag: avg days mise en fût to first packaging (rolling 6m).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 46;

-- #43 racking_yield_vs_target: stays compute (no target_hl in ref_recipes).
-- #47 tank_emptying_efficiency: stays compute (no flowmeter data in bd_racking_v2).


-- PACKAGING domain (11 of ~17 trackers implemented; 6 remain stub/gap)

-- #48 units_packaged_month: total vendable units by run_type this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 48;

-- #49 hl_packaged_month: total vendable HL this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 49;

-- #50 packaging_runs_count: run count + days since last run.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 50;

-- #51 top_skus_packaged: top N SKUs by vendable HL.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 51;

-- #52 format_mix_pct: % HL by run_type (keg/bot/can/cuv).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 52;

-- #53 parallel_white_label_volume: white label HL vs Nébuleuse HL.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 53;

-- #54 packaging_yield_pct: vendable / (vendable + loss_kpi_hl).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 54;

-- #56 contract_packaging_volume: HL per client (white_label_name breakdown).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 56;

-- #58 avg_throughput_packaging: avg vendable HL per run.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 58;

-- #62 volume_per_sku_month: HL + units per SKU this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 62;

-- #68 fg_added_inventory_month: total vendable units + HL added to FG this period.
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 68;

-- Stays stub/compute: #55 fill_efficiency, #59 avg_losses_per_category,
--   #60 avg_losses_per_sku, #57 packaging_material_consumption (gap),
--   #63 suggested_packaging_events, #64 packaging_deviations, #65 packaging_cost_per_unit.


-- TANKS domain (readings subset only — 2 of ~15 implemented; occupancy stubs remain)

-- #34 o2_in_bbt: avg O2 ppb from bd_tank_readings (last 30 days).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 34;

-- #35 o2_deviations: count readings > 50 ppb threshold (last 30 days).
UPDATE ref_kpi_trackers
   SET data_ready = 1, readiness = 'live'
 WHERE id = 35;

-- Tank occupancy trackers (13-33 excl. 34/35): remain compute=0 — need tank-sim port.


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 4: Viewer seed-tuning proposal (COMMENTED OUT — operator must bless)
--
-- Rationale: viewers currently see an empty Mon tableau. Opening a curated subset
-- of production/ops trackers to min_role='viewer' would let taproom staff and
-- non-admin users see operational pulse without finance/procurement data.
--
-- Proposed viewer-eligible trackers (all live, no finance/RQ/admin sensitivity):
--
-- #1  hl_brewed_month         — HL brassés ce mois (production volume, public-safe)
-- #2  hl_brewed_ytd           — HL brassés YTD vs N-1 (annual context, public-safe)
-- #3  brew_count_month        — Nombre brassins (count, no value data)
-- #8  days_since_last_brew    — Jours depuis dernier brassin (ops pulse)
-- #39 rackings_month          — Mises en fût ce mois (count + HL, no cost)
-- #49 hl_packaged_month       — HL packagés ce mois (output volume)
-- #50 packaging_runs_count    — Runs packaging (ops pulse)
-- #52 format_mix_pct          — Répartition formats % (portfolio mix, not cost)
--
-- NOT proposed for viewer:
-- #5  production_by_beer_yoy  — Contains full recipe breakdown (competitive sensitivity)
-- #34 o2_in_bbt               — QA metric (operator/manager context needed)
-- #40 racking_loss_pct        — Process efficiency (manager context needed)
-- #111 deliveries_month       — Cost data (operator+ only)
-- All cogs/utilities/rm_procurement trackers — finance-sensitive
--
-- Activate by uncommenting and running via migrate.php:
-- UPDATE ref_kpi_trackers SET min_role = 'viewer' WHERE id IN (1, 2, 3, 8, 39, 49, 50, 52);
-- ══════════════════════════════════════════════════════════════════════════════


-- ══════════════════════════════════════════════════════════════════════════════
-- Schema meta: no new tables. ref_kpi_trackers corrections_policy = 'allowed'.
-- NOTE: no schema_migrations INSERT here — migrate.php does the bookkeeping.
-- ══════════════════════════════════════════════════════════════════════════════
