-- 264_auth_shared_devices.sql
--
-- Device registry for shared-device policy.
--
-- A per-browser UUID (`mt_device_id` cookie) identifies a browser agent.
-- An admin can mark a device as "shared" (is_shared=1), which causes
-- login.php to refuse remember-me token creation for that browser.
--
-- is_shared=0 rows are kept for audit (unmark, not delete).
-- registered_by FK uses ON DELETE SET NULL — deleting an admin account
-- must not cascade-delete the device registry row.
--
-- FK target: users.id is INT UNSIGNED — registered_by matches exactly.
--
-- corrections_policy='managed': admins add/mark/unmark via the admin UI;
-- direct edits to device_id / is_shared must go through the service layer.
--
-- NO SELECT statements (migrate.php uses PDO::exec()).

CREATE TABLE IF NOT EXISTS auth_shared_devices (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    device_id       CHAR(36)      NOT NULL,
    label           VARCHAR(120)  NULL,
    is_shared       TINYINT(1)    NOT NULL DEFAULT 1,
    registered_by   INT UNSIGNED  NULL,
    registered_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    DATETIME      NULL,
    last_ip         VARCHAR(45)   NULL,
    last_ua         VARCHAR(255)  NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asd_device_id (device_id),
    KEY idx_asd_registered_by (registered_by),

    CONSTRAINT fk_asd_registered_by
        FOREIGN KEY (registered_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification row.
-- 'blocked': rows are created/updated by device_mark_shared() /
-- device_unmark_shared() in app/services/device.php; never patch
-- device_id or is_shared outside the service layer.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('auth_shared_devices', 'source', 'blocked',
     'app/services/device.php (device_mark_shared / device_unmark_shared)',
     'Mark/unmark via device_mark_shared() / device_unmark_shared(). Never patch device_id or is_shared directly.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
