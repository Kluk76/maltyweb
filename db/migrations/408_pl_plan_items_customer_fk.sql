-- Migration 408 — pl_plan_items.customer_id_fk (serving-tank client identity)
--
-- CARDINAL RULE: Planning is INTENT, not fact. pl_plan_items reads canonical
-- state to anticipate; it is NEVER read by COGS/COP/WAC/BOM/beer-tax/inventory.
--
-- Serving-tank (cuve de service) fill suggestions are client-recurrence-driven:
-- the producer proposes one fill per due cuve-de-service client. Multiple clients
-- can be due the same week for the SAME beer (currently all ZEPV/Zepp), so the
-- proposal must carry the client identity — otherwise the per-(section,recipe)
-- dedup collapses them to one row and the operator can't tell which fill is for
-- which client.
--
-- customer_id_fk is NULL for every non-serving-tank row (semantic NULL: the row
-- has no client). FK → ref_customers.id (INT UNSIGNED), ON DELETE SET NULL.
-- No schema_meta row needed (additive column on an existing classified table).

ALTER TABLE `pl_plan_items`
  ADD COLUMN `customer_id_fk` INT UNSIGNED NULL DEFAULT NULL AFTER `recipe_id_fk`,
  ADD CONSTRAINT `fk_pitems_customer`
      FOREIGN KEY (`customer_id_fk`) REFERENCES `ref_customers` (`id`) ON DELETE SET NULL;
