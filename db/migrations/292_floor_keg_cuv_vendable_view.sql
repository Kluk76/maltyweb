-- Migration 292: Floor keg/cuv vendable_hl at 0 in v_bd_packaging_v2_vendable
-- A pure-loss row (prod_total_units=0, loss_keg_liquid_l>0) previously computed a
-- negative vendable_hl.  GREATEST(0,<expr>) mirrors the bccomp floor added in the
-- PHP compute function (form-packaging.php ~L298).  The beer_tax_base keg/cuv arm
-- is rewritten to derive from the floored vendable expression so stored == view.
-- loss_kpi_hl (= loss_keg_liquid_l/100) is independent and unchanged.
--
-- NOTE: migration 291 (291_tap_shop_page.sql) is present on disk but may not yet
-- be applied on the VPS.  This migration 292 is DDL-only (CREATE OR REPLACE VIEW)
-- and has no dependency on 291 — they do not touch the same objects.
-- Apply this one independently if 291 is still in-flight in a parallel session.

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

    -- vendable_hl: keg/cuv arm floored at 0 to match PHP compute fn
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST(GREATEST(0,
                       ((((COALESCE(`p`.`prod_total_units`, 0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                         - (COALESCE(`p`.`loss_keg_liquid_l`, 0) / 100))
                        - (COALESCE(`p`.`taproom_keg_l`, 0) / 100))
                       - (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                     ) AS DECIMAL(14,4))
        ELSE
            CAST(((((((((COALESCE(`p`.`prod_total_units`, 0)
                         - COALESCE(`p`.`unsaleable_units`, 0))
                        - COALESCE(`p`.`loss_uncapped_units`, 0))
                       - CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                      - COALESCE(`p`.`loss_untaxed_full_units`, 0))
                     - COALESCE(`p`.`qa_library_units`, 0))
                    - COALESCE(`p`.`qa_analyses_units`, 0))
                   / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                 - (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                 AS DECIMAL(14,4))
    END                                               AS `vendable_hl`,

    -- beer_tax_base_hl: keg/cuv arm uses FLOORED vendable + taproom
    -- (mirrors PHP: $beerTaxBaseHl = bcadd($vendableHl, taproom/100) after the floor)
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST(GREATEST(0,
                       ((((COALESCE(`p`.`prod_total_units`, 0) / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                         - (COALESCE(`p`.`loss_keg_liquid_l`, 0) / 100))
                        - (COALESCE(`p`.`taproom_keg_l`, 0) / 100))
                       - (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                     ) AS DECIMAL(14,4))
                 + CAST((COALESCE(`p`.`taproom_keg_l`, 0) / 100) AS DECIMAL(14,4))
        ELSE
            CAST((((((((COALESCE(`p`.`prod_total_units`, 0)
                        - COALESCE(`p`.`loss_uncapped_units`, 0))
                       - CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                      - COALESCE(`p`.`loss_untaxed_full_units`, 0))
                     - COALESCE(`p`.`qa_library_units`, 0))
                    - COALESCE(`p`.`qa_analyses_units`, 0))
                   / `s`.`units_per_pack`) * `s`.`hl_per_unit`)
                 - (COALESCE(`p`.`loss_liquid_other_units`, 0) / 100)
                 AS DECIMAL(14,4))
    END                                               AS `beer_tax_base_hl`,

    -- loss_kpi_hl: independent of the floor (liquid loss is real regardless)
    CASE
        WHEN `p`.`reuses_packaging_id_fk` IS NOT NULL
            THEN 0
        WHEN (`s`.`hl_per_unit` IS NULL OR `s`.`units_per_pack` IS NULL OR `s`.`units_per_pack` <= 0)
            THEN NULL
        WHEN `p`.`run_type` IN ('keg','cuv')
            THEN CAST((COALESCE(`p`.`loss_keg_liquid_l`, 0) / 100) AS DECIMAL(14,4))
        ELSE
            CAST(((((COALESCE(`p`.`unsaleable_units`, 0)
                     + COALESCE(`p`.`loss_uncapped_units`, 0))
                    + CAST((COALESCE(`p`.`loss_half_filled_units`, 0) * 0.5) AS DECIMAL(14,6)))
                   + COALESCE(`p`.`loss_untaxed_full_units`, 0))
                  / `s`.`units_per_pack`) * `s`.`hl_per_unit`
                 AS DECIMAL(14,4))
    END                                               AS `loss_kpi_hl`

FROM `bd_packaging_v2` `p`
LEFT JOIN `ref_skus` `s` ON (`s`.`id` = `p`.`sku_id_fk`)
WHERE `p`.`is_tombstoned` = 0;

-- Record in schema_migrations so tracker reflects reality
-- (migration applied directly via CREATE OR REPLACE VIEW, not via migrate.php apply-all,
--  because migration 291 is in-flight in a parallel session and must not be triggered)
INSERT IGNORE INTO `schema_migrations` (`filename`) VALUES ('292_floor_keg_cuv_vendable_view.sql');
