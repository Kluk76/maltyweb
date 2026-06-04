-- 262_user_invites.sql
--
-- Single-use invite / password-reset tokens.
--
-- Raw token is NEVER stored; only its SHA-256 hex (64 chars) is kept at rest.
-- The caller generates bin2hex(random_bytes(32)), stores hash('sha256', raw),
-- and hands the raw token to the invited user via a URL.
--
-- purpose='invite'  — first-login / new account onboarding
-- purpose='reset'   — admin-initiated password reset (future use)
--
-- Prior unconsumed tokens for the same user+purpose are expired on re-invite
-- (see invite_create() in app/services/invite_token.php).
--
-- FK target: users.id is INT UNSIGNED — both FKs match exactly.
--
-- corrections_policy='blocked': tokens are security artefacts; never patch
-- them directly. Revoke and re-issue instead (invite_create re-revokes).
--
-- NO SELECT statements (migrate.php uses PDO::exec()).

CREATE TABLE IF NOT EXISTS user_invites (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED  NOT NULL,
    token_hash   CHAR(64)      NOT NULL,
    purpose      ENUM('invite','reset') NOT NULL DEFAULT 'invite',
    expires_at   DATETIME      NOT NULL,
    consumed_at  DATETIME      NULL,
    created_by   INT UNSIGNED  NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_invite_token_hash (token_hash),
    KEY idx_invite_user_id (user_id),

    CONSTRAINT fk_ui_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,

    CONSTRAINT fk_ui_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification row.
-- 'blocked': tokens are security artefacts; revoke-and-reissue, never patch.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('user_invites', 'source', 'blocked',
     'app/services/invite_token.php (invite_create / invite_consume)',
     'Revoke by calling invite_create() again (expires prior token). Never patch token_hash or consumed_at directly.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
