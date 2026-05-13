-- 044 — Ingest observability: run-level tracking + per-row failure enrichment.
--
-- Context: chantier 1 (migrations 041–043) fixed a DataError that had been
-- silently crashing the BSF→MySQL ingest cron for weeks. Nobody noticed
-- because failures only appeared in /var/log/maltytask/ingest.log on the VPS.
-- Operator principle (durable): data ingest failures must be visible in the
-- maltyweb UI.
--
-- This migration adds:
--
--   1. ingest_runs — one row per ingest.py invocation.
--      Tracks started_at, finished_at, status (running/ok/partial/failed),
--      per-tab counters in summary_json, and trigger source (cron/manual/cli).
--      Indexed on started_at DESC for cheap "latest run" badge queries.
--
--   2. ingest_failures extended — the existing table (migration 027) captures
--      FK violations only, keyed on (target_table, row_hash). We ADD a run_id
--      column so failures are linked to the run that produced them, and add
--      an error_code VARCHAR column (replacing reason_code SMALLINT) to
--      accommodate non-numeric codes (e.g. 'DataError', 'FK_FAIL').
--      The UNIQUE constraint on (target_table, row_hash) stays — re-runs
--      touching the same bad row UPDATE last_seen_at via ON DUPLICATE KEY.
--
--      We do NOT drop or rename ingest_failures — the existing page
--      (/admin/ingest-failures.php) continues to work unchanged.
--      run_id is nullable to keep pre-044 rows valid.
--
-- Down-migration (conceptual, no automated runner):
--   ALTER TABLE ingest_failures DROP FOREIGN KEY fk_ingest_failures_run;
--   ALTER TABLE ingest_failures DROP INDEX idx_ifail_run_id;
--   ALTER TABLE ingest_failures DROP COLUMN run_id;
--   DROP TABLE IF EXISTS ingest_runs;

CREATE TABLE IF NOT EXISTS ingest_runs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at      DATETIME(6)     NOT NULL,
  finished_at     DATETIME(6)     NULL,
  status          ENUM('running','ok','partial','failed') NOT NULL DEFAULT 'running',
  summary_json    JSON            NULL,
  -- Per-tab counters: {"brewing": {"fetched":N,"parsed":N,"inserted":N,"duplicates":N,"failed":N}, ...}
  error_message   TEXT            NULL,
  -- Set only when status='failed' (top-level uncaught exception in main()).
  trigger_source  VARCHAR(32)     NOT NULL DEFAULT 'cron',
  -- 'cron' | 'manual' | 'cli'
  KEY idx_ingest_runs_started_at (started_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add run_id to existing ingest_failures, nullable for backward compat.
ALTER TABLE ingest_failures
  ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER id;

-- Index to efficiently join / filter failures by run.
CREATE INDEX idx_ifail_run_id ON ingest_failures (run_id);

-- FK: deleting a run cascades to its failures (orphan cleanup).
ALTER TABLE ingest_failures
  ADD CONSTRAINT fk_ingest_failures_run
    FOREIGN KEY (run_id) REFERENCES ingest_runs (id) ON DELETE CASCADE;
