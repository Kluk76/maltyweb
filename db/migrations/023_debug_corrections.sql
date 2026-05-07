-- 023 — debug_corrections audit log.
--
-- Every UPDATE / DELETE applied via the admin DB Browser correction tool
-- writes one row here BEFORE the change is committed. Captures who did
-- what, on which rows, with the old values for reversibility.
--
-- old_values is JSON keyed by primary-key value:
--   {"42":"Pending","43":"Active"}        for an UPDATE on column 'status'
--   {"42":{"col1":"v1","col2":"v2"},...}  for a DELETE (whole row snapshot)
--
-- No FK from affected_ids — affected rows may be deleted by the same
-- correction. The PK values are stored as text for forensic trace only.

CREATE TABLE IF NOT EXISTS debug_corrections (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED  NOT NULL,
  username        VARCHAR(64)   NOT NULL,
  table_name      VARCHAR(64)   NOT NULL,
  action          ENUM('update','delete') NOT NULL,
  pk_column       VARCHAR(64)   NOT NULL,
  affected_ids    TEXT          NOT NULL,
  column_name     VARCHAR(64)   NULL,
  new_value       TEXT          NULL,
  set_null        TINYINT(1)    NOT NULL DEFAULT 0,
  old_values      MEDIUMTEXT    NULL,
  rows_affected   INT UNSIGNED  NOT NULL,
  applied_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_table     (table_name),
  KEY idx_user      (user_id),
  KEY idx_applied   (applied_at),
  CONSTRAINT fk_correction_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
