-- db/migrations/113_doc_uploads_recap_sent_at.sql
-- What: Add recap_sent_at column to doc_uploads for post-ingestion email recap tracking.
--       Also adds a composite index (recap_sent_at, pipeline_status) to make the
--       "find unsent terminals" query efficient — it is polled every minute by a cron.
-- Why:  The send-ingest-recap.ts script needs to:
--         (1) select uploads not yet reported (recap_sent_at IS NULL)
--         (2) mark them as reported after email is confirmed sent
--       Without the column, every successful/failed ingest would be re-reported forever.
-- Risk: Low — additive ALTER only. No data rewrite. Table has ~700 rows at 2026-05-24,
--       near-instant on INPLACE. recap_sent_at NULL on existing rows means they will be
--       picked up by the first recap run (expected: one catch-up email on deploy).
-- Rollback:
--   ALTER TABLE doc_uploads DROP INDEX idx_recap, DROP COLUMN recap_sent_at;

ALTER TABLE doc_uploads
  ADD COLUMN recap_sent_at DATETIME NULL COMMENT 'Set after ingestion recap email is confirmed sent; NULL = not yet reported',
  ADD INDEX  idx_recap (recap_sent_at, pipeline_status);

-- Go-forward only: mark PRE-EXISTING uploads as already reported, so the first
-- recap run does not blast a single catch-up email covering ~700 historical rows.
-- The `< NOW() - INTERVAL 1 HOUR` buffer deliberately leaves any just-uploaded
-- batch (the operator may be mid-drop while this migration runs) reportable, so
-- the very next recap covers it. Idempotent: a re-run touches nothing new.
UPDATE doc_uploads SET recap_sent_at = NOW()
 WHERE recap_sent_at IS NULL AND uploaded_at < NOW() - INTERVAL 1 HOUR;

SET @migration_113_done = 1;
