-- db/migrations/100_reserved_noop.sql
-- What: No-op placeholder. Migration 100 was reserved during Phase 1.C
--       (bd_fermenting_v2) and abandoned — the work landed in 101-105 instead,
--       leaving a 099 -> 101 gap in the applied sequence.
-- Why:  Recorded here so the migration sequence is contiguous and a future
--       auditor doesn't hunt for a missing 100 file (DBA Phase-1-boundary N4).
-- Risk: Zero — no schema or data change.
-- Rollback: DELETE FROM schema_migrations WHERE filename='100_reserved_noop.sql';

SET @migration_100_reserved = 1;
