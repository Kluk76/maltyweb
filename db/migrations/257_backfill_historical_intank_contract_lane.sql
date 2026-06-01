-- ============================================================================
-- Migration 257: historical in-tank dimension backfill — CONTRACT lane
--                (sibling of mig 243, which did the neb lane)
--
-- WHAT:
--   Populate bd_tank_readings' CONTRACT lane from v1 bd_packaging.tank_co2/tank_o2
--   for every CONSISTENT contract lot-day with a linkable v2 contract row, then
--   link the v2 contract rows via tank_read_id_fk.
--   Lot-day key = (contract_beer, contract_batch, DATE(submitted_at)); recipe_id_fk
--   and neb_batch are NULL on these rows (the uq_tank_read_contract lane).
--
-- WHY:
--   mig 243 backfilled only the NEB lane (linked on recipe_id_fk+neb_batch). Contract
--   v2 rows carry neb_batch NULL, so they were correctly skipped and left in the
--   residue. This fills the second schema lane. PM-framed: the contract lane is keyed
--   by the (contract_beer, contract_batch, read_date) string trio per
--   uq_tank_read_contract — recipe_id_fk MUST be NULL here (do not key contract reads
--   by recipe_id_fk; that would violate the two-lane model).
--
-- NAMING: v1 vs v2 contract_beer verified to have ZERO divergence (41 distinct v1
--   names all byte-match v2; the mig-217 QDG/DIG consolidation was neb-side and does
--   not touch contract_beer). Raw string join is safe; both sides are bd_* tables
--   (same collation), no COLLATE needed.
--
-- CONFLICT-AWARE: 178 v1 contract lot-days, 4 conflicting (differing reads same
--   key) → skipped by the HAVING, left semantic-NULL (refuse, don't guess). The
--   in-tank read is shared per lot-day by design, so multiple contract runs of the
--   same lot-day legitimately share one reading (no collision — verified 0 v2 rows
--   match >1 dimension row).
--
-- SCOPE GUARDS: RawDB-origin only (source_sheet_row_index NOT NULL on mains),
--   unlinked (tank_read_id_fk NULL), non-reuse, live. Excludes web rows.
--
-- DRY-RUN VERIFIED (rolled-back txn 2026-06-01): 170 dimension rows, 226 v2 main
--   rows linked, 0 parallel, 0 multi-match.
--
-- ROLLBACK:
--   UPDATE bd_packaging_v2 SET tank_read_id_fk = NULL
--     WHERE tank_read_id_fk IN (SELECT id FROM bd_tank_readings WHERE created_by='mig257');
--   DELETE FROM bd_tank_readings WHERE created_by='mig257';
-- ============================================================================

-- 1. Materialise contract-lane dimension rows for consistent lot-days with a
--    linkable v2 contract row. Idempotent via uq_tank_read_contract.
INSERT INTO bd_tank_readings
  (recipe_id_fk, neb_batch, contract_beer, contract_batch, read_date, co2_gl, o2_ppb, source, created_by)
SELECT NULL, NULL, p.contract_beer, p.contract_batch, DATE(p.submitted_at),
       ROUND(MIN(p.tank_co2), 3), ROUND(MIN(p.tank_o2), 2), 'rawdb_v1', 'mig257'
FROM bd_packaging p
WHERE (p.tank_co2 IS NOT NULL OR p.tank_o2 IS NOT NULL)
  AND p.contract_beer IS NOT NULL AND p.contract_beer <> ''
  AND EXISTS (
     SELECT 1 FROM bd_packaging_v2 v
     WHERE v.row_origin = 'main' AND v.is_tombstoned = 0
       AND v.source_sheet_row_index IS NOT NULL
       AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL
       AND v.contract_beer  = p.contract_beer
       AND v.contract_batch <=> p.contract_batch
       AND v.event_date     = DATE(p.submitted_at)
  )
GROUP BY p.contract_beer, p.contract_batch, DATE(p.submitted_at)
HAVING COUNT(DISTINCT ROUND(p.tank_co2, 3)) <= 1
   AND COUNT(DISTINCT ROUND(p.tank_o2, 2)) <= 1
ON DUPLICATE KEY UPDATE id = id;

-- 2a. Link v2 contract MAIN rows to their lot-day reading.
UPDATE bd_packaging_v2 v
  JOIN bd_tank_readings t
    ON t.recipe_id_fk IS NULL
   AND t.contract_beer  = v.contract_beer
   AND t.contract_batch <=> v.contract_batch
   AND t.read_date      = v.event_date
   SET v.tank_read_id_fk = t.id
 WHERE v.row_origin = 'main' AND v.is_tombstoned = 0
   AND v.source_sheet_row_index IS NOT NULL
   AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL
   AND v.contract_beer IS NOT NULL AND v.contract_beer <> '';

-- 2b. Link v2 contract parallel rows of the same lot-day.
UPDATE bd_packaging_v2 v
  JOIN bd_tank_readings t
    ON t.recipe_id_fk IS NULL
   AND t.contract_beer  = v.contract_beer
   AND t.contract_batch <=> v.contract_batch
   AND t.read_date      = v.event_date
   SET v.tank_read_id_fk = t.id
 WHERE v.row_origin = 'parallel' AND v.is_tombstoned = 0
   AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL
   AND v.contract_beer IS NOT NULL AND v.contract_beer <> '';
