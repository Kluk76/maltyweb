-- db/migrations/415_bd_packaging_v2_loss_liquid_alloc_col.sql
--
-- What: Add loss_liquid_other_units_alloc DECIMAL(10,3) NULL to bd_packaging_v2.
--
-- This column is DERIVED — never operator-entered. It holds the per-run share
-- of loss_liquid_other_units after proportional redistribution across parallel
-- runs in the same format-family group (bot or can), keyed by
--   (recipe_id_fk, COALESCE(NULLIF(neb_batch,''), NULLIF(contract_batch,'')),
--    event_date, format_family).
--
-- Split basis: each run's gross-filled HL (prod_total_units / units_per_pack
-- × hl_per_unit). Equal-split fallback when Σ(gross HL) = 0.
--
-- For keg/cuv runs there is no pooling — _alloc = loss_liquid_other_units
-- (identity passthrough; NULL stays NULL).
--
-- Computed by:
--   • form-packaging.php on every POST (keg/cuv inline; bot/can via
--     recompute_group_liquid_alloc() INSIDE the write transaction, Step C).
--   • Backfill migration 416 for all existing rows.
--
-- Why:  When a batch packages DIV4 + DIVB bottles in parallel, the operator
--       enters total liquid loss once (usually on the 'main' row). Downstream
--       loss_kpi_hl in v_bd_packaging_v2_vendable must dilute this proportionally
--       across both runs, not count the full loss against each.
--
-- Risk: LOW — nullable ADD COLUMN, ALGORITHM=INSTANT, no existing consumers
--       affected. Column is NULL until backfill (migration 416) runs.
--
-- Rollback:
--   ALTER TABLE bd_packaging_v2
--     DROP COLUMN loss_liquid_other_units_alloc;

ALTER TABLE bd_packaging_v2
  ADD COLUMN loss_liquid_other_units_alloc DECIMAL(10,3) NULL
    COMMENT 'Derived. Per-run allocated share of loss_liquid_other_units after group-proportional split (bot/can groups keyed by recipe×batch×date×format_family). keg/cuv = identity passthrough. Computed by form-packaging.php on POST + backfilled by mig 416. Never operator-entered.',
  ALGORITHM=INSTANT;

-- schema_meta: bd_packaging_v2 is already classified (source / allowed).
-- A new derived column on an existing table does not need a schema_meta row.
-- Per migration 128 pattern.

-- end migration 415
