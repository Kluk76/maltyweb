-- 061_ref_mi_consumption_unit.sql
--
-- Adds consumption_unit column to ref_mi: the unit operators record consumption
-- in (brewsheets / forms). NULL = same as pricing_unit (no conversion needed).
-- FK references ref_units.code so only registered units are accepted.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

ALTER TABLE ref_mi
  ADD COLUMN consumption_unit VARCHAR(16) NULL
    COMMENT 'Unit operators report consumption in (brewsheets / forms). NULL = same as pricing_unit.',
  ADD CONSTRAINT fk_ref_mi_cons_unit FOREIGN KEY (consumption_unit) REFERENCES ref_units(code);
