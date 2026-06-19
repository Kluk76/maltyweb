-- 411_ref_customers_serving_tank_fiche.sql
-- Additive columns for serving-tank client planning (fiche display + budget capture).
-- serving_tank_budget_hl is a MONTHLY budget in HL.
-- All three columns are app-owned curated columns feeding the planning INTENT layer /
-- fiche only — never COGS/COP/WAC/BOM/beer-tax.
-- IMPORTANT: scripts/python/sync_bc_customers.py must NEVER include these in its UPDATE
-- allowlist — they are operator-curated, not BC-sourced.
-- MySQL-8 syntax: no IF NOT EXISTS on ALTER TABLE.
-- No schema_meta row — additive on a classified table (ref_customers).
-- No seed rows required.

ALTER TABLE `ref_customers`
  ADD COLUMN `serving_tank_count`     TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Number of serving tanks at this client (operator-curated)',
  ADD COLUMN `serving_tank_size_hl`   DECIMAL(6,2)     NULL DEFAULT NULL
    COMMENT 'Nominal tank size in HL per tank (operator-curated)',
  ADD COLUMN `serving_tank_budget_hl` DECIMAL(8,2)     NULL DEFAULT NULL
    COMMENT 'Budgeted HL per calendar month (operator-curated, planning-intent only)';
