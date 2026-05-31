-- ============================================================================
-- Migration 240: In-tank CO₂/O₂ lot-day anchor (self-FK + deterministic backfill)
--
-- WHAT:
--   Adds a self-FK column `tank_reading_session_id_fk` to bd_packaging_v2.
--   NULL   = this row IS the lot-day anchor; it owns the CO₂/O₂ read-set in
--            bd_packaging_co2o2_measures (i.e. COALESCE(tank_reading_session_id_fk, id)
--            points to itself).
--   Non-NULL = this row INHERITS its lot-day reads from the pointed-at anchor row.
--
--   Corresponding key idx_bdpv2_tank_reading_fk added (MySQL requires an index
--   backing every FK column).
--
--   A deterministic backfill UPDATE follows:
--   For every lot-day that has EXACTLY ONE read-bearing main session (deterministic,
--   no guessing), every other main row on that lot-day that currently carries no
--   co2o2 reads is pointed at that anchor.
--   Constraints on BOTH sides of the link:
--     - row_origin = 'main'
--     - is_tombstoned = 0
--     - reuses_packaging_id_fk IS NULL  (a reused cuve drew from no fresh tank)
--   "Read-bearing" = EXISTS in bd_packaging_co2o2_measures.
--   Lot-day identity key: (recipe_id_fk, neb_batch, contract_beer, contract_batch,
--   event_date) — all matched NULL-safe (<=> ) because the Neb lane has contract_*
--   NULL and the contract lane has recipe_id_fk NULL.
--
-- WHY:
--   Dissolved CO₂ and O₂ readings are taken once per tank-filling session for a
--   given lot and day, not once per packaging run.  When multiple runs draw from
--   the same tank on the same day (e.g. a bottle run at 08:00 and a can run at
--   13:00 for Embuscade batch 233 on 2026-05-26), each run should expose the same
--   in-tank reading rather than leave non-anchor rows with a NULL/phantom read.
--   COALESCE(tank_reading_session_id_fk, id) is the canonical resolver: it always
--   yields the anchor row's id regardless of whether you start from the anchor or
--   an inheritor.
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_v2
--     DROP FOREIGN KEY fk_bdpv2_tank_reading,
--     DROP KEY idx_bdpv2_tank_reading_fk,
--     DROP COLUMN tank_reading_session_id_fk;
-- ============================================================================

-- HISTORY NOTE: on the production VPS these three DDL statements were applied by
-- a partial first execution (MySQL DDL auto-commits; the original backfill UPDATE
-- then failed with error 1093 before migrate.php recorded the migration). They are
-- kept here for fresh-rebuild correctness — migrate.php is filename-keyed via
-- schema_migrations, so it will NOT re-run 240 on the VPS (already recorded), while
-- a clean rebuild creates the column/FK/index here and the literal backfill below
-- no-ops (rows 6710/6711 are data, absent from a schema-only rebuild). Plain
-- ADD COLUMN (no IF NOT EXISTS) is the house style — idempotency comes from the
-- schema_migrations ledger, not from the DDL (MySQL 8, not MariaDB).

-- 1. Schema: lot-day anchor self-FK + backing index.
ALTER TABLE bd_packaging_v2
  ADD COLUMN tank_reading_session_id_fk BIGINT UNSIGNED NULL
    COMMENT 'Lot-day in-tank CO2/O2 anchor: points to the bd_packaging_v2.id that OWNS the lot-day read-set (NULL = this row IS the anchor / owns its own reads). Resolve reads via COALESCE(tank_reading_session_id_fk, id).'
    AFTER reuses_packaging_id_fk,
  ADD CONSTRAINT fk_bdpv2_tank_reading
    FOREIGN KEY (tank_reading_session_id_fk)
    REFERENCES bd_packaging_v2(id)
    ON DELETE SET NULL;
ALTER TABLE bd_packaging_v2
  ADD KEY idx_bdpv2_tank_reading_fk (tank_reading_session_id_fk);

-- 2. Deterministic backfill: point the single known read-less lot-day sibling
--    at its anchor.
--
--    MySQL 8 error 1093 ("can't specify target table for update in FROM clause")
--    fires on ANY subquery that references the UPDATE target table — including
--    through derived tables (derived_merge merges them back into the outer scope
--    at the semantic analysis stage, before the optimizer runs, making all nesting
--    workarounds ineffective).  CREATE TEMPORARY TABLE is unavailable to the
--    app user (maltytask_app lacks that privilege).
--
--    Since the pre-apply SELECT probe confirmed EXACTLY ONE pair — target=6710,
--    anchor=6711 (Embuscade batch 233, recipe_id_fk=32, event_date=2026-05-26;
--    6711 holds 3 co2o2 reads, 6710 holds 0) — the correct encoding is a
--    precision literal UPDATE.  Guards reference only bd_packaging_co2o2_measures
--    (never bd_packaging_v2 in a subquery) to stay clear of error 1093.
--
--    Idempotent: tank_reading_session_id_fk IS NULL guard + NOT EXISTS c2 guard
--    ensure a second run is a no-op for this row.
--
--    Future lot-days with the same pattern will be linked by the PHP form at
--    submission time (the packaging form writes the FK directly when it detects
--    a same-lot-day prior run with co2o2 reads).  A new migration is required
--    only if historical rows are added that need retroactive anchoring.

UPDATE bd_packaging_v2
SET tank_reading_session_id_fk = 6711
WHERE id = 6710
  AND row_origin = 'main'
  AND is_tombstoned = 0
  AND reuses_packaging_id_fk IS NULL
  AND tank_reading_session_id_fk IS NULL
  -- target must be read-less (guard against re-run after a manual co2o2 insert)
  AND NOT EXISTS (
      SELECT 1 FROM bd_packaging_co2o2_measures c2
      WHERE c2.packaging_id_fk = 6710
  )
  -- anchor must still own reads (sanity: do not point at a row that lost its reads)
  AND EXISTS (
      SELECT 1 FROM bd_packaging_co2o2_measures ca
      WHERE ca.packaging_id_fk = 6711
  );
