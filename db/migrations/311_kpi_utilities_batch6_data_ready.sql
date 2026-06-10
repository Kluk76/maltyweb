-- Migration 311: KPI utilities domain — Batch 6 wiring
-- Sets data_ready=1 for the 16 compute-backed utilities trackers.
-- Leaves data_ready=0 for gap stubs (#205-#209, #250) and voc_tax_exposure (#204)
-- which is stubbed per maltytask convention (VOC tax folded into chemical unit prices,
-- no standalone MI or delivery line exists).
--
-- Built trackers (data_ready=1):
--   #192 electricity_kwh_month        inv_energydata LAG delta
--   #193 peak_demand_kw               inv_energydata.peak_kw
--   #194 reactive_power_kvarch        inv_energydata.reactive_kvarh
--   #195 electricity_cost_month       inv_deliveries Utilities/Electricity
--   #196 water_consumption_cost       inv_energydata + inv_deliveries
--   #197 gas_consumption_cost         inv_energydata + inv_deliveries
--   #198 energy_cost_per_hl           COP feed utilities.total / hlPackaged
--   #199 water_to_beer_ratio          inv_energydata / bd_packaging_v2
--   #200 kwh_per_hl_trend             inv_energydata / bd_packaging_v2 sparkline
--   #201 predictive_vs_actual_utilities COP feed 6-month bar
--   #202 reactive_penalty_risk        kVArh/kWh ratio flag
--   #203 co2_purchased_cost           inv_deliveries Process Chemical/Gas
--   #210 peak_shaving_opportunity     peak_kw vs rolling mean
--   #211 utility_cost_pct_cogs        COP feed utilities.total / totalVariables.total
--   #212 seasonal_energy_curve        12-month gas+elec sparkline
--   #269 mass_energy_water_balance    waterfall HL/water/energy

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE source_domain = 'utilities'
   AND slug IN (
       'electricity_kwh_month',
       'peak_demand_kw',
       'reactive_power_kvarch',
       'electricity_cost_month',
       'water_consumption_cost',
       'gas_consumption_cost',
       'energy_cost_per_hl',
       'water_to_beer_ratio',
       'kwh_per_hl_trend',
       'predictive_vs_actual_utilities',
       'reactive_penalty_risk',
       'co2_purchased_cost',
       'peak_shaving_opportunity',
       'utility_cost_pct_cogs',
       'seasonal_energy_curve',
       'mass_energy_water_balance'
   );
