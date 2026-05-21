-- 062_ref_mi_is_inventoried.sql
--
-- Adds is_inventoried flag to ref_mi.
-- When 0, the MI is excluded from the Warehouse inventory page
-- (services, rentals, maintenance, utilities, tax passthrough,
--  admin fees, immobilisations).
-- Default 1 = inventoried (no behaviour change for existing rows).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

ALTER TABLE ref_mi
  ADD COLUMN is_inventoried TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'When 0, MI is excluded from Warehouse inventory page (services, rentals, maintenance, utilities, tax passthrough, admin fees, immobilisations).';
