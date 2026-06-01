-- db/migrations/251_backfill_parallel_double_count.sql
-- Backfill for the parallel-run fix (companion to mig 250 view recreate).
-- Split from 250 because migrate.php exec()s the whole file at once and a
-- CREATE OR REPLACE VIEW followed by DML in one exec triggers PDO error 2014
-- (pending result sets). View-only in 250, DML-only here.
--   POP-1: clear special_qty_units on MAIN rows (old-form stamp).
--   POP-2: set prod_total_units = special_qty_units on PARALLEL rows where NULL.
--   Recompute stored HL columns from the corrected view (mig 250).

-- ============================================================================
-- STEP 2: POP-1 — clear special_qty_units on MAIN rows.
--         Old normalize-rawdb stamped special=qty on the main row when emitting
--         a parallel counterpart. With the new model, main rows must have
--         special_qty_units = NULL.
--         Guard: only clear when prod_total_units IS NOT NULL (safety check).
-- ============================================================================

UPDATE bd_packaging_v2
   SET special_qty_units = NULL
 WHERE row_origin = 'main'
   AND special_qty_units IS NOT NULL
   AND prod_total_units IS NOT NULL;

-- ============================================================================
-- STEP 3: POP-2 — give new-form parallel rows their prod_total.
--         New form wrote prod_total_units=NULL; qty only in special_qty_units.
--         After this step: prod_total_units = special_qty_units = qty. ✓
--         Scoped to non-tombstoned rows; tombstoned parallels are archived and
--         excluded from all reports regardless of prod_total state.
-- ============================================================================

UPDATE bd_packaging_v2
   SET prod_total_units = special_qty_units
 WHERE row_origin = 'parallel'
   AND prod_total_units IS NULL
   AND special_qty_units IS NOT NULL
   AND is_tombstoned = 0;

-- ============================================================================
-- STEP 4: Recompute stored HL columns from the corrected view (all non-tombstoned rows).
--         View must be recreated BEFORE this step (done in STEP 1 above).
--         Covers: vendable_hl, beer_tax_base_hl, loss_kpi_hl.
-- ============================================================================

UPDATE bd_packaging_v2 p
  JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
   SET p.vendable_hl      = v.vendable_hl,
       p.beer_tax_base_hl = v.beer_tax_base_hl,
       p.loss_kpi_hl      = v.loss_kpi_hl
 WHERE p.is_tombstoned = 0;

-- end migration 250
