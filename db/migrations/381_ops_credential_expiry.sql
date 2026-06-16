-- 381_ops_credential_expiry.sql
-- Credential-expiry watchdog: tracks external credentials (e.g. BC Entra client_secret)
-- and triggers email reminders before they expire so the operator rotates them in time.
-- Pure ops plumbing — no FK relationships, never feeds COGS or any derivation.
-- The watchdog NEVER stores or reads the actual secret value; only metadata about its expiry.

CREATE TABLE IF NOT EXISTS ops_credential_expiry (
    id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    label                VARCHAR(128)    NOT NULL COMMENT 'Human-readable label, e.g. "BC Entra client_secret"',
    cred_key             VARCHAR(255)    NULL     COMMENT 'Documentation only — where to find/rotate the secret, e.g. "BC_CLIENT_SECRET @ config/bc.env". Watchdog NEVER reads the actual secret.',
    expires_on           DATE            NOT NULL COMMENT 'Hard expiry date of the credential',
    lead_days            VARCHAR(64)     NOT NULL DEFAULT '30,14,7,1' COMMENT 'Comma-separated reminder thresholds (days before expiry). E.g. "30,14,7,1" fires at 30, 14, 7, and 1 day before.',
    last_reminded_stage  INT             NULL     COMMENT 'Smallest threshold already emailed (idempotency cursor). NULL = no reminder sent. Reset to NULL when expires_on changes.',
    recipient            VARCHAR(255)    NULL     COMMENT 'Override recipient email. NULL = fallback chain (ops system_setting → admin users).',
    notes                TEXT            NULL     COMMENT 'Free-form notes (rotation steps, who owns the secret, etc.)',
    is_active            TINYINT(1)      NOT NULL DEFAULT 1,
    created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_oce_active_expires (is_active, expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks expiry of external credentials. Watchdog sends tiered email reminders. Never stores secret values.';

-- schema_meta row: system table, app-only writes via send-credential-expiry-reminders.php
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    ('ops_credential_expiry',
     'system',
     'scripts/send-credential-expiry-reminders.php',
     'allowed',
     'Rows are seeded manually by the operator (no upstream script). The watchdog script updates last_reminded_stage only. To add a credential: INSERT a row with label, expires_on, lead_days. To retire: set is_active=0.',
     'Created mig 381. Pure ops plumbing — no FK, never feeds COGS/derivation. Watchdog sends tiered reminders before expires_on and once after. last_reminded_stage is the idempotency cursor; reset to NULL when expires_on is updated to a new rotation date.')
ON DUPLICATE KEY UPDATE
    notes      = VALUES(notes),
    updated_at = CURRENT_TIMESTAMP;

SET @noop = 1;
