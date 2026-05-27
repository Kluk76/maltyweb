-- db/migrations/174_bd_racking_v2_hors_process.sql
-- What: Add hors_process_flag and hors_process_reason columns to bd_racking_v2.
-- Why:  The racking form (form-racking.php) adopts the "Choix Hors Process"
--       manager/admin override pattern (mirrors bd_packaging_v2, migration 128).
--       When a manager bypasses the cold-crash garde minimum to proceed with a
--       transfer, the row must record that fact (flag=1) and optionally why (reason).
--       Normal racking submissions always carry flag=0.
-- Risk: LOW — INSTANT DDL (nullable + default; MySQL 8 default algorithm for
--       ADD COLUMN at table end). No existing consumers to break (column is additive).
-- Note: These columns are SCD2 version-stamp candidates once the recipe-versioning
--       temporal model (SCD2 v2) activates per the recipe-versioning roadmap.
--       When v2 is activated, consider adding effective_from/effective_until here
--       so hors-process override audits are versioned alongside the recipe snapshot.
-- Rollback:
--   ALTER TABLE bd_racking_v2 DROP COLUMN hors_process_reason;
--   ALTER TABLE bd_racking_v2 DROP COLUMN hors_process_flag;

ALTER TABLE bd_racking_v2
  ADD COLUMN hors_process_flag   TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'Set to 1 when this row was created via the Choix Hors Process override (manager/admin only). Normal racking always 0.',
  ADD COLUMN hors_process_reason VARCHAR(255)  NULL COLLATE utf8mb4_unicode_ci
    COMMENT 'Optional free-text justification entered by manager/admin when using the override. NULL on all normal rows.';

-- Index for operational queries (find all override-sourced racking rows):
ALTER TABLE bd_racking_v2
  ADD KEY idx_bdrv2_hors_process (hors_process_flag);

-- Verify schema_meta row for bd_racking_v2 exists (source table — new columns
-- do not require a schema_meta update; row already classified):
SET @sm_count = (
  SELECT COUNT(*) FROM schema_meta WHERE table_name = 'bd_racking_v2'
);
-- The SET above is a no-op check (not a SELECT) — safe for migrate.php PDO exec().
