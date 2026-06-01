-- Migration 255: Exclude tombstoned rows from v_bd_packaging_v2_vendable
--
-- is_tombstoned = 1 rows are soft-deleted packaging events. The view had no WHERE clause,
-- so tombstoned rows were returning vendable_units/vendable_hl/beer_tax_base_hl/loss_kpi_hl
-- as if they were live — silently inflating vendable HL, tax base, and loss KPIs for every
-- consumer of this view.
--
-- Fix: add WHERE p.is_tombstoned = 0 at the end of the FROM/JOIN, after the LEFT JOIN.
-- No column expression is altered — semantics are fully preserved except tombstoned rows
-- are excluded.
--
-- A-LT2-must-preserve: the is_tombstoned=0 predicate must survive any future codegen
-- regeneration of this view (A-LT2 view-codegen arc, paused as of 2026-06-01).
--
-- ROLLBACK: re-create the view without the WHERE clause:
--   CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
--   select ... from (`bd_packaging_v2` `p` left join `ref_skus` `s` on((`s`.`id` = `p`.`sku_id_fk`)));
--   (paste the full column list from SHOW CREATE VIEW output captured 2026-06-01)

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
select `p`.`id` AS `id`,`p`.`run_type` AS `run_type`,`p`.`sku_id_fk` AS `sku_id_fk`,`s`.`hl_per_unit` AS `hl_per_unit`,`s`.`units_per_pack` AS `units_per_pack`,(case when (`p`.`reuses_packaging_id_fk` is not null) then 0 when (`p`.`run_type` in ('keg','cuv')) then coalesce(`p`.`prod_total_units`,0) else ((((((coalesce(`p`.`prod_total_units`,0) - coalesce(`p`.`unsaleable_units`,0)) - coalesce(`p`.`loss_uncapped_units`,0)) - cast((coalesce(`p`.`loss_half_filled_units`,0) * 0.5) as decimal(14,6))) - coalesce(`p`.`loss_untaxed_full_units`,0)) - coalesce(`p`.`qa_library_units`,0)) - coalesce(`p`.`qa_analyses_units`,0)) end) AS `vendable_units`,(case when (`p`.`reuses_packaging_id_fk` is not null) then 0 when ((`s`.`hl_per_unit` is null) or (`s`.`units_per_pack` is null) or (`s`.`units_per_pack` <= 0)) then NULL when (`p`.`run_type` in ('keg','cuv')) then cast((((((coalesce(`p`.`prod_total_units`,0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`) - (coalesce(`p`.`loss_keg_liquid_l`,0) / 100)) - (coalesce(`p`.`taproom_keg_l`,0) / 100)) - (coalesce(`p`.`loss_liquid_other_units`,0) / 100)) as decimal(14,4)) else cast((((((((((coalesce(`p`.`prod_total_units`,0) - coalesce(`p`.`unsaleable_units`,0)) - coalesce(`p`.`loss_uncapped_units`,0)) - cast((coalesce(`p`.`loss_half_filled_units`,0) * 0.5) as decimal(14,6))) - coalesce(`p`.`loss_untaxed_full_units`,0)) - coalesce(`p`.`qa_library_units`,0)) - coalesce(`p`.`qa_analyses_units`,0)) / `s`.`units_per_pack`) * `s`.`hl_per_unit`) - (coalesce(`p`.`loss_liquid_other_units`,0) / 100)) as decimal(14,4)) end) AS `vendable_hl`,(case when (`p`.`reuses_packaging_id_fk` is not null) then 0 when ((`s`.`hl_per_unit` is null) or (`s`.`units_per_pack` is null) or (`s`.`units_per_pack` <= 0)) then NULL when (`p`.`run_type` in ('keg','cuv')) then cast(((((coalesce(`p`.`prod_total_units`,0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`) - (coalesce(`p`.`loss_keg_liquid_l`,0) / 100)) - (coalesce(`p`.`loss_liquid_other_units`,0) / 100)) as decimal(14,4)) else cast(((((((((coalesce(`p`.`prod_total_units`,0) - coalesce(`p`.`loss_uncapped_units`,0)) - cast((coalesce(`p`.`loss_half_filled_units`,0) * 0.5) as decimal(14,6))) - coalesce(`p`.`loss_untaxed_full_units`,0)) - coalesce(`p`.`qa_library_units`,0)) - coalesce(`p`.`qa_analyses_units`,0)) / `s`.`units_per_pack`) * `s`.`hl_per_unit`) - (coalesce(`p`.`loss_liquid_other_units`,0) / 100)) as decimal(14,4)) end) AS `beer_tax_base_hl`,(case when (`p`.`reuses_packaging_id_fk` is not null) then 0 when ((`s`.`hl_per_unit` is null) or (`s`.`units_per_pack` is null) or (`s`.`units_per_pack` <= 0)) then NULL when (`p`.`run_type` in ('keg','cuv')) then cast((coalesce(`p`.`loss_keg_liquid_l`,0) / 100) as decimal(14,4)) else cast((((((coalesce(`p`.`unsaleable_units`,0) + coalesce(`p`.`loss_uncapped_units`,0)) + cast((coalesce(`p`.`loss_half_filled_units`,0) * 0.5) as decimal(14,6))) + coalesce(`p`.`loss_untaxed_full_units`,0)) / `s`.`units_per_pack`) * `s`.`hl_per_unit`) as decimal(14,4)) end) AS `loss_kpi_hl`
from (`bd_packaging_v2` `p` left join `ref_skus` `s` on((`s`.`id` = `p`.`sku_id_fk`)))
WHERE p.is_tombstoned = 0;
