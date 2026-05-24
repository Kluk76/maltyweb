-- db/migrations/105_bd_fermenting_v2_cleanup_orphans.sql
-- What: Delete 6 orphan rows in bd_fermenting_v2 that the FIXED normalize-rawdb.py parser
--       no longer produces, but which the upsert re-ingest cannot remove on its own
--       (the ingest script has no tombstone/full-sync mode yet).
--   (a) 2 phantom DryHop rows where a hop LOT number was mis-parsed as a separate
--       ingredient (dh_raw_name = "VA-21-0098165 DE" / "VA 22-0072 65 DE", dh_mi_id_fk NULL).
--       The parser now recognises a non-numeric, non-ingredient token after a hop name as
--       the lot (with optional swapped-order qty), so EMB 183 / EMB 212 Cascade now carry
--       their correct qty + lot and these phantom rows are obsolete.
--   (b) 4 blank-identity Purge/ColdCrash rows (empty beer_raw — accidental empty form
--       submissions). The parser now skips blank-identity purge/cold-crash events.
-- Why:  Phase 1.C residual cleanup, after the parser regression fix (the lot-recovery logic
--       had existed before but was overwritten during the PackagingData normalization phases).
--       Re-ingest from the corrected xlsx fixed the Cascade rows in place; this migration
--       removes the now-orphaned rows so the DB matches the corrected source and the fix is
--       NOT reverted on future re-ingest (the fixed parser never recreates these rows).
-- Risk: Low + idempotent. On a fresh DB built from the corrected xlsx these WHERE clauses
--       match nothing (no-op). On the current DB they delete exactly the 6 known orphans.
--       After the re-upload, the only DryHop rows with dh_mi_id_fk IS NULL are the 2 phantoms
--       (EMB 183/212 Cascade now resolve to HOPS_CASCADE), and the only blank-beer rows are
--       the 4 empty submissions — verified before applying.
-- Rollback: none practical (rows carried no recoverable data). Re-run ingest if needed.

-- (a) phantom lot-as-ingredient DryHop rows
DELETE FROM bd_fermenting_v2
WHERE event_type = 'DryHop'
  AND dh_mi_id_fk IS NULL;

-- (b) blank-identity Purge/ColdCrash submissions
DELETE FROM bd_fermenting_v2
WHERE beer_raw = '' OR beer_raw IS NULL;

SET @migration_105_cleanup_done = 1;
