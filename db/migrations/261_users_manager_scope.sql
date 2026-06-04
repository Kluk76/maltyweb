-- Migration 261: add manager_scope to users
--
-- PURPOSE
-- -------
-- Introduces an orthogonal scope dimension for the 'manager' role. Existing
-- roles (admin, operator, viewer, manager) are NOT changed. The new column
-- distinguishes:
--   'production'  — production + supply-chain scope
--   'logistics'   — supply-chain only
--   'all'         — unrestricted manager scope (reserved; not currently assigned)
-- NULL on all non-manager roles (admin is treated as scope 'all' in PHP code,
-- not stored here). NULL on a manager row means "unclassified" — add a row to
-- this backfill section as new managers are created.
--
-- MySQL 8 — no ADD COLUMN IF NOT EXISTS (idempotency via schema_migrations).
-- schema_meta: no new row required — this is an ALTER on the already-classified
--              `users` table; no SELECT in this file (PDO exec() constraint).

ALTER TABLE users
    ADD COLUMN manager_scope ENUM('production','logistics','all') NULL
        COMMENT 'Scope for manager role only; NULL on all other roles'
        AFTER role;

-- Backfill the three known managers (matched on username for safety, not just id).
UPDATE users SET manager_scope = 'production' WHERE username = 'Gonzalo';
UPDATE users SET manager_scope = 'production' WHERE username = 'Yves';
UPDATE users SET manager_scope = 'logistics'  WHERE username = 'Nathan';
