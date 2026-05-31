-- ============================================================================
-- Migration 243: historical in-tank dimension backfill (Job 1 of the deferred
--                v1→v2 CO₂/O₂ backfill) — natural-key, conflict-aware
--
-- WHAT:
--   1. Derive bd_tank_readings dimension rows from v1 bd_packaging.tank_co2/tank_o2
--      for every CONSISTENT neb-lane lot-day that has a v2 RawDB main row needing
--      an in-tank link. Lot-day key = (recipe via neb_beer→ref_skus, neb_batch,
--      DATE(submitted_at)).
--   2. Link v2 RawDB rows (main + parallel) to their lot-day dimension row via the
--      natural-key JOIN (recipe_id_fk, neb_batch, event_date).
--
-- WHY:
--   In-tank reads (a property of a beer+batch+day lot-day, taken from the BBT/CCT
--   before filling) lived only in v1 bd_packaging. The dimension bd_tank_readings
--   (mig 241) + bd_packaging_v2.tank_read_id_fk (mig 242) gave them a v2 home, and
--   the web form populates them going forward; this migration backfills the
--   historical RawDB runs. mig 248 already did this for 3 recent lot-days by hand —
--   this is that pattern generalised to the full history.
--
-- WHY NOT the sheet_row_index bridge:
--   bd_packaging.sheet_row_index → bd_packaging_v2.source_sheet_row_index is
--   SEMANTICALLY BROKEN (only ~43% agree on neb_beer — two independent numbering
--   schemes with a row-shift). This migration NEVER uses it. The in-tank dimension
--   is keyed by the business lot-day, so it sidesteps any per-row v1↔v2 bridge:
--   the dimension is derived directly from v1, and v2 is linked by its own
--   (recipe_id_fk, neb_batch, event_date) natural key. (Job 2 — per-row in-filling
--   linkage — genuinely needs the bridge and is deferred to mig 244 after a
--   uniqueness proof + senior-DBA review.)
--
-- CONFLICT-AWARE (never guess):
--   A lot-day is materialised ONLY when all its v1 reads agree (HAVING
--   COUNT(DISTINCT ROUND(co2,3))<=1 AND COUNT(DISTINCT ROUND(o2,2))<=1). The 35
--   conflicting neb-lane lot-days get NO dimension row — their v2 rows stay
--   tank_read_id_fk = NULL (semantic NULL, data preserved in v1), per the operator
--   decision to "leave their respective in-tank reads as is". Likewise the contract
--   lane (178 v1 lot-days, no neb-keyed v2 target), the 4 legacy EPH{1-4}BC SKUs
--   (absent from ref_skus), and any v1 lot-day with no v2 counterpart are left
--   unlinked. No ambiguous link is ever written.
--
-- DATE AXIS: v2.event_date was derived from DATE(v1.submitted_at) during
--   normalization (98.2% agreement on bridge rows) — so the date leg is exact.
--
-- SCOPE GUARDS: only RawDB-origin, unlinked, non-reuse, live rows are touched
--   (source_sheet_row_index IS NOT NULL on mains; tank_read_id_fk IS NULL). This
--   automatically excludes web-entered runs (6709-6712), the mig-248 trio, and any
--   future web run — they cannot be double-linked.
--
-- DRY-RUN VERIFIED (rolled-back txn, 2026-06-01): 1437 dimension rows created,
--   1711 v2 main + 50 v2 parallel rows linked, 471 main rows left as residue.
--
-- ROLLBACK:
--   UPDATE bd_packaging_v2 SET tank_read_id_fk = NULL
--     WHERE tank_read_id_fk IN (SELECT id FROM bd_tank_readings WHERE created_by='mig243');
--   DELETE FROM bd_tank_readings WHERE created_by='mig243';
--   (the 3 mig-248 dimension rows are created_by='mig248-...' and untouched.)
-- ============================================================================

-- 1. Materialise dimension rows for consistent neb-lane lot-days that have a
--    linkable v2 RawDB main row. Idempotent via the lot-day UNIQUE.
INSERT INTO bd_tank_readings (recipe_id_fk, neb_batch, read_date, co2_gl, o2_ppb, source, created_by)
SELECT s.recipe_id, p.neb_batch, DATE(p.submitted_at),
       ROUND(MIN(p.tank_co2), 3), ROUND(MIN(p.tank_o2), 2), 'rawdb_v1', 'mig243'
FROM bd_packaging p
JOIN ref_skus s ON s.sku_code = p.neb_beer AND s.recipe_id IS NOT NULL
WHERE (p.tank_co2 IS NOT NULL OR p.tank_o2 IS NOT NULL)
  AND p.neb_beer <> '' AND p.neb_batch IS NOT NULL AND p.neb_batch <> ''
  AND EXISTS (
     SELECT 1 FROM bd_packaging_v2 v
     WHERE v.row_origin = 'main' AND v.is_tombstoned = 0
       AND v.source_sheet_row_index IS NOT NULL
       AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL
       AND v.recipe_id_fk = s.recipe_id
       AND v.neb_batch    = p.neb_batch
       AND v.event_date   = DATE(p.submitted_at)
  )
GROUP BY s.recipe_id, p.neb_batch, DATE(p.submitted_at)
HAVING COUNT(DISTINCT ROUND(p.tank_co2, 3)) <= 1
   AND COUNT(DISTINCT ROUND(p.tank_o2, 2)) <= 1
ON DUPLICATE KEY UPDATE id = id;

-- 2a. Link v2 RawDB MAIN rows to their lot-day dimension row.
UPDATE bd_packaging_v2 v
  JOIN bd_tank_readings t
    ON t.recipe_id_fk = v.recipe_id_fk
   AND t.neb_batch    = v.neb_batch
   AND t.read_date    = v.event_date
   SET v.tank_read_id_fk = t.id
 WHERE v.row_origin = 'main' AND v.is_tombstoned = 0
   AND v.source_sheet_row_index IS NOT NULL
   AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL;

-- 2b. Link v2 parallel rows of the same lot-day (parallels carry no
--     source_sheet_row_index; the tank_read_id_fk IS NULL guard already excludes
--     any web-linked parallel).
UPDATE bd_packaging_v2 v
  JOIN bd_tank_readings t
    ON t.recipe_id_fk = v.recipe_id_fk
   AND t.neb_batch    = v.neb_batch
   AND t.read_date    = v.event_date
   SET v.tank_read_id_fk = t.id
 WHERE v.row_origin = 'parallel' AND v.is_tombstoned = 0
   AND v.tank_read_id_fk IS NULL AND v.reuses_packaging_id_fk IS NULL;
