CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  username       VARCHAR(64)   NOT NULL,
  email          VARCHAR(255)  NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  display_name   VARCHAR(128)  NULL,
  role           ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  TIMESTAMP     NULL DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_username (username),
  UNIQUE KEY uniq_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  filename    VARCHAR(128) NOT NULL,
  applied_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
