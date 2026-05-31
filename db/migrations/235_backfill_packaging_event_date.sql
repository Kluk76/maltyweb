-- Migration 235: Backfill bd_packaging_v2.event_date from submitted_at
--
-- What: Sets event_date = DATE(submitted_at) for all rows where event_date IS NULL.
--       2240 rows imported by the normalizer (row_origin = 'main'/'parallel') were
--       loaded without event_date because ingest_bd_packaging_v2.py did not populate
--       the column. submitted_at is 100% populated and clean on all rows.
--
-- Canonical rule: event_date = DATE(submitted_at) for the entire bd_*_v2 event
--       family. This mirrors the brewing and fermentation ingest paths which derive
--       event_date from the form submission timestamp. Do NOT use last_cip_date
--       (62% populated, contains future/garbage dates) as a source.
--
-- Idempotent: WHERE event_date IS NULL → 0 rows affected on second run.
--
-- The live form (form-packaging.php) already writes event_date correctly and is
-- NOT touched by this migration.
--
-- Rollback: UPDATE bd_packaging_v2 SET event_date = NULL WHERE event_date IS NOT NULL
--           AND row_origin IN ('main', 'parallel') AND imported_at < '<migration_ts>';
--           (scope by imported_at if rollback is ever needed)

UPDATE bd_packaging_v2
   SET event_date = DATE(submitted_at)
 WHERE event_date IS NULL;
