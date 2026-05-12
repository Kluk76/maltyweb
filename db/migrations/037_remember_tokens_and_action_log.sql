-- Migration 037: remember-me tokens + action log for rate limiting
-- Applied: 2026-05-12

CREATE TABLE IF NOT EXISTS user_remember_tokens (
    id            BIGINT       NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  TIMESTAMP    NULL,
    last_ip       VARBINARY(16) NULL,
    last_ua       VARCHAR(255)  NULL,
    expires_at    TIMESTAMP    NOT NULL,
    revoked_at    TIMESTAMP    NULL,
    device_label  VARCHAR(80)  NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_user_revoked (user_id, revoked_at),
    KEY idx_expires (expires_at),

    CONSTRAINT fk_urt_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_action_log (
    id         BIGINT        NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED  NOT NULL,
    action     VARCHAR(40)   NOT NULL,
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip         VARBINARY(16) NULL,

    PRIMARY KEY (id),
    KEY idx_ual_window (user_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
