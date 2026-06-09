-- Migration 293: Corrected loss model — liquid losses move to loss_kpi, not subtracted from vendable.
--
-- Supersedes migration 292 (292_floor_keg_cuv_vendable_view.sql):
-- The mig-292 GREATEST(0,…) floor was a workaround for negative vendable caused by
-- subtracting loss_keg_liquid_l + loss_liquid_other_units from vendable_hl.
-- Now that those losses are classified as first-class losses (not vendable deductions),
-- the floor is dead code and the view is rewritten to mirror the fixed PHP compute fn.
--
-- Changes vs mig-292 view:
--   bottle/can vendable arm: drop `− loss_liquid_other/100` subtraction.
--   bottle/can loss_kpi arm: add `+ loss_liquid_other/100` term.
--   keg/cuv vendable arm:    drop `− loss_keg_liquid_l/100` and `− loss_liquid_other/100`;
--                            keep `− taproom_keg_l/100`; remove GREATEST(0,…) floor.
--   keg/cuv beer_tax arm:    simplify to hlGross (vendable + taproom = prod/pack * hl_per_unit);
--                            remove GREATEST(0,…) floor.
--   keg/cuv loss_kpi arm:    add `+ loss_liquid_other/100` alongside loss_keg_liquid_l/100.
--
-- NOTE: this migration 293 is DDL-only (CREATE OR REPLACE VIEW). Apply directly;
-- do NOT trigger migrate.php apply-all (migration 291 is already live, no conflict).

CREATE OR REPLACE VIEW `v_bd_packaging_v2_vendable` AS
SELECT
    `p`.`id`                                          AS `id`,
    `p`.`run_type`                                    AS `run_type`,
    `p`.`sku_id_fk`                                   AS `sku_id_fk`,
    `s`.`hl_per_unit`                                 AS `hl_per_unit`,
    `s`.`units_per_pack`                              AS `units_per_pack`,

    -- vendable_units (unchanged — unit-count concept, not HL)
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN COALESCE(`p`.`prod_total_units`, 0)
        ELSE
            (((((COALESCE(`p`.`prod_total_units`, 0)
                 - COALESCE(`p`.`unsaleable_units`, 0))
                - COALESCE(`p`.`loss_uncapped_units`, 0))
               - CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
              - COALESCE(`p`.`loss_untaxed_full_units`, 0))
             - COALESCE(`p`.`qa_library_units`, 0))
            - COALESCE(`p`.`qa_analyses_units`, 0)
    END                                               AS `vendable_units`,

    -- vendable_hl:
    --   bottle/can: hlGross (loss_liquid_other is now a loss, not a vendable deduction)
    --   keg/cuv:    hlGross − taproom/100 (no floor needed; liquid losses not subtracted)
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST(
                     ((COALESCE(`p`.`prod_total_units`, 0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                     - (COALESCE(`p`.`taproom_keg_l`, 0) / 100)
                 AS DECIMAL(14,4))
        ELSE
            CAST(
                     (((((((COALESCE(`p`.`prod_total_units`, 0)
                             - COALESCE(`p`.`unsaleable_units`, 0))
                            - COALESCE(`p`.`loss_uncapped_units`, 0))
                           - CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                          - COALESCE(`p`.`loss_untaxed_full_units`, 0))
                         - COALESCE(`p`.`qa_library_units`, 0))
                        - COALESCE(`p`.`qa_analyses_units`, 0))
                       / `s`.`units_per_pack`) * `s`.`hl_per_unit`
                 AS DECIMAL(14,4))
    END                                               AS `vendable_hl`,

    -- beer_tax_base_hl:
    --   bottle/can: vendable + unsaleable HL (taxed even if not sold) — unchanged
    --   keg/cuv:    hlGross (= vendable + taproom; taproom is taxed) — no floor
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST(
                     ((COALESCE(`p`.`prod_total_units`, 0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                 AS DECIMAL(14,4))
        ELSE
            CAST(
                     ((((((COALESCE(`p`.`prod_total_units`, 0)
                            - COALESCE(`p`.`loss_uncapped_units`, 0))
                           - CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                          - COALESCE(`p`.`loss_untaxed_full_units`, 0))
                         - COALESCE(`p`.`qa_library_units`, 0))
                        - COALESCE(`p`.`qa_analyses_units`, 0))
                       / `s`.`units_per_pack`) * `s`.`hl_per_unit`
                 AS DECIMAL(14,4))
    END                                               AS `beer_tax_base_hl`,

    -- loss_kpi_hl:
    --   bottle/can: unit-based losses / pack * hl_per_unit + loss_liquid_other/100
    --   keg/cuv:    loss_keg_liquid_l/100 + loss_liquid_other/100
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST(
                     (COALESCE(`p`.`loss_keg_liquid_l`, 0) / 100)
                     + (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                 AS DECIMAL(14,4))
        ELSE
            CAST(
                     ((((COALESCE(`p`.`unsaleable_units`, 0)
                          + COALESCE(`p`.`loss_uncapped_units`, 0))
                         + CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                        + COALESCE(`p`.`loss_untaxed_full_units`, 0))
                       / `s`.`units_per_pack`) * `s`.`hl_per_unit`
                     + (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                 AS DECIMAL(14,4))
    END                                               AS `loss_kpi_hl`

FROM `bd_packaging_v2` `p`
LEFT JOIN `ref_skus` `s` ON (`s`.`id` = `p`.`sku_id_fk`)
WHERE `p`.`is_tombstoned` = 0;

-- Record in schema_migrations so tracker reflects reality.
-- Applied directly via CREATE OR REPLACE VIEW (not via migrate.php apply-all)
-- to avoid triggering migration 291 which is already live.
INSERT IGNORE INTO `schema_migrations` (`filename`) VALUES ('293_loss_model_no_liquid_subtraction.sql');
