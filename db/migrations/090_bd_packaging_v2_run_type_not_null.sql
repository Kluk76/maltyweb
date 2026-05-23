-- db/migrations/090_bd_packaging_v2_run_type_not_null.sql
-- What: Harden bd_packaging_v2.run_type to NOT NULL.
-- Why: Migration 088 confirmed 100% run_type coverage across all 2236 rows (2178 main + 58
--      parallel). The column was created as nullable to allow staged population. With coverage
--      confirmed, NOT NULL prevents regressions from future ingest code bugs.
-- Risk: Low. ALTER TABLE on run_type only; no data change. Any future INSERT without
--       run_type will fail explicitly rather than silently NULL-ing the column.
-- Rollback: ALTER TABLE bd_packaging_v2 MODIFY run_type ENUM('bot','can','can33','keg','cuv') NULL;
-- Constraint: Do NOT harden recipe_id_fk or sku_id_fk yet — legitimate NULLs exist
--             (parallel rows, contract rows, unresolved EPH/NULL-suffix rows).

-- Safety check (comment out if already verified by operator):
-- SELECT SUM(run_type IS NULL) AS null_count FROM bd_packaging_v2;
-- Expected: 0. If > 0, abort and investigate before applying.

ALTER TABLE bd_packaging_v2
  MODIFY COLUMN run_type
    ENUM('bot','can','can33','keg','cuv') NOT NULL
    COMMENT 'Hardened NOT NULL — 100% coverage confirmed post-migration 088';
