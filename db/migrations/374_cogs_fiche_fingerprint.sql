-- 374_cogs_fiche_fingerprint.sql
-- Add source_fingerprint column to cogs_fiche_monthly for compute-cache invalidation.
-- NULL on existing rows → treated as stale (triggers recompute on next resolve call).
-- Idempotency: schema_migrations enforces one-time execution.

ALTER TABLE cogs_fiche_monthly
    ADD COLUMN source_fingerprint VARCHAR(64) NULL
        COMMENT 'SHA1 hash of source-table state (counts + MAX updated_at). NULL = stale.';
