-- Migration 308: KPI Phase 2b Batch 4 — flip data_ready for built wort + racking compute handlers
--
-- Wort built (4 handlers):
--   #6  brewhouse_yield          — actual cooling HL vs ref_brewhouse_size nominal
--   #10 avg_brew_duration        — from bd_brewing_timings_v2 brew_start/brew_end
--   #11 ingredient_consumption_period — from inv_consumption grouped by MI category
--   #252 beer_loss_cascade       — waterfall brewed→racked→packaged per period
--
-- Racking built (3 handlers):
--   #43 racking_yield_vs_target  — racked HL / brewed HL per (recipe_id_fk, batch)
--   #44 do_pickup_racking        — bbt_o2 ppb from bd_racking_v2 (406/406 rows populated)
--   #45 carbonation_achieved     — bbt_co2 g/L from bd_racking_v2 (405/406 rows populated)
--
-- Left stub (confirmed no source):
--   #7  og_attainment            — no target_og column in ref_recipes or any canonical table
--   #12 brewing_deviations       — no deviations table/schema exists
--   #47 tank_emptying_efficiency — flowmeter_start/end: 2/406 rows; bbt_vide_scrapped_hl: 0/406
--   #253 extract_efficiency_lab  — gap: no lab extract measurement data
--   #254 dryhop_absorption_loss  — gap: no dryhop volume tracking columns
--   #255 fv_trub_loss            — gap: no knock-out→FV delta columns

UPDATE ref_kpi_trackers
   SET data_ready = 1
 WHERE slug IN (
     'brewhouse_yield',
     'avg_brew_duration',
     'ingredient_consumption_month',
     'beer_loss_cascade',
     'racking_yield_vs_target',
     'do_pickup_racking',
     'carbonation_achieved'
 );
