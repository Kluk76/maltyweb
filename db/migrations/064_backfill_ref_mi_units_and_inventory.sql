-- 064_backfill_ref_mi_units_and_inventory.sql
--
-- Backfills the two new ref_mi columns added in 061 (consumption_unit)
-- and 062 (is_inventoried), and fixes utility MIs that have NULL pricing_unit.
--
-- Step 1: Backfill consumption_unit from the most-common unit observed in
--         inv_consumption per MI.
-- Step 2: Fix utility MIs with NULL pricing_unit → 'month'.
-- Step 3: Set is_inventoried=0 for non-inventory categories.
-- Step 4: Per-MI overrides for rentals / services within otherwise-inventory cats.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

-- 1) Backfill consumption_unit from most-common unit in inv_consumption
UPDATE ref_mi m
  JOIN (
    SELECT mi_id_fk,
           SUBSTRING_INDEX(
             GROUP_CONCAT(unit ORDER BY n DESC SEPARATOR '|'),
             '|', 1
           ) AS best_unit
      FROM (
        SELECT mi_id_fk, unit, COUNT(*) AS n
          FROM inv_consumption
         WHERE unit IS NOT NULL AND unit <> ''
         GROUP BY mi_id_fk, unit
      ) per_unit
     GROUP BY mi_id_fk
  ) winners ON winners.mi_id_fk = m.id
   SET m.consumption_unit = winners.best_unit
 WHERE m.consumption_unit IS NULL;

-- 2) Fix utility MIs with NULL pricing_unit → 'month'
UPDATE ref_mi
   SET pricing_unit = 'month'
 WHERE pricing_unit IS NULL
   AND mi_id IN (
     'UTIL_WATER_SEWAGE_SIL', 'UTIL_GAS_SIL',
     'UTIL_ELECTRICITY_SIE', 'UTIL_ELECTRICITY_SIE_PROD', 'UTIL_ELECTRICITY_SIE_LOG',
     'UTIL_WATER_METER_SIL', 'UTIL_EXCH_ALU_TINGUELY', 'UTIL_CREDIT_FER_TINGUELY',
     'TAX_VAT_IMPORT_PASSTHROUGH', 'TRANS_FREIGHT_OUTBOUND', 'PKG_TEA_BOT_CH'
   );

-- 3) Set is_inventoried=0 for non-inventory categories
UPDATE ref_mi m
  JOIN ref_mi_categories c ON c.id = m.category_id
   SET m.is_inventoried = 0
 WHERE c.name IN (
   'Maintenance', 'Utilities', 'Tax', 'Frais admin divers',
   'Transport', 'Immobilisation', 'Taproom & Foodtour',
   'Matériel de production', 'Cliches graphiques'
 );

-- 4) Per-MI overrides — rentals + services within otherwise-inventory categories
UPDATE ref_mi
   SET is_inventoried = 0
 WHERE mi_id IN (
   'PROC_CO2_TANK_RENTAL',
   'PROC_CO2_LIQUID_STORAGE_RENTAL',
   'PROC_CO2_MODULE_OPT_RENTAL',
   'QA_N2_BOTTLE_WESTFALEN',
   'RND_EUROFINS_GLUTEN_ELISA',
   'SALE_CO2_BOUT',
   'SALE_CO2_PANIER_RENTAL'
 );
