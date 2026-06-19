-- Migration 407: ref_customers — serving-tank client flag + cadence override
--
-- Purpose: Mark recurring cuve-de-service (serving-tank) clients on ref_customers
-- and provide an optional per-client cadence override for the planning suggestion engine.
--
-- CARDINAL NOTE: These two columns feed ONLY the planning INTENT engine
-- (fill-proposal / replenishment cadence suggestions). They MUST NOT be read
-- by, or allowed to influence, any COGS / COP / WAC / BOM / beer-tax /
-- inventory-valuation path. They are planning metadata, not financial data.
--
-- Seeded clients (IDs verified against live sales ledger by project PM):
--   id=6     Les Docks
--   id=845   Carte Blanche SA / Les Arches
--   id=1827  La Rincette Sàrl
--   id=1848  Jetée de la compagnie
--   id=2612  Association Les Jardins de Louis
--
-- serving_tank_cadence_days = NULL  →  derive cadence from delivery history (default)
-- serving_tank_cadence_days = N     →  operator-fixed override (planning engine uses N directly)
--
-- No schema_meta row needed: ref_customers is already a classified `reference` table;
-- we are adding columns, not creating a new table.

ALTER TABLE ref_customers
  ADD COLUMN is_serving_tank_client  TINYINT(1)        NOT NULL DEFAULT 0    COMMENT 'Flag: this customer receives recurring cuve-de-service fills. Feeds planning intent engine only — never COGS/COP/WAC/BOM.',
  ADD COLUMN serving_tank_cadence_days SMALLINT UNSIGNED NULL    DEFAULT NULL COMMENT 'Optional per-client cadence override in days. NULL = derive from delivery history.';

UPDATE ref_customers
   SET is_serving_tank_client = 1
 WHERE id IN (6, 845, 1827, 1848, 2612);
