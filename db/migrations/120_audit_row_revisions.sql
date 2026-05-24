-- db/migrations/120_audit_row_revisions.sql
-- What: Create audit_row_revisions — generic who/when/before/after revision log for
--       operator-form writes. Captures every UPSERT via the web form layer.
-- Why:  Supports the native operator-input form framework (Phase 6). Design principle
--       from project_maltyweb_native_form_inputting_design: "Audit log par changement
--       (who, when, before, after) → soft-delete, pas hard-delete".
--       user_action_log is login-only (5 cols, no before/after); it is not extended
--       because polluting the auth log with row-level diffs breaks separation of concerns.
--       This table serves web-form writes exclusively.
-- Decision: new table over reusing user_action_log — user_action_log stores auth events
--   (298 rows, varchar(40) action, no before/after). Adding JSON columns to it would
--   conflate login audit with data-entry audit. A dedicated table keeps both clean.
-- Rollback: DROP TABLE audit_row_revisions; DELETE FROM schema_meta WHERE table_name='audit_row_revisions';

CREATE TABLE IF NOT EXISTS audit_row_revisions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Who
  user_id     INT UNSIGNED NOT NULL COMMENT 'FK users.id',
  username    VARCHAR(64)  NOT NULL COMMENT 'Denormalised snapshot — stable even if user renamed',
  ip          VARCHAR(45)  NULL     COMMENT 'Client IP at submit time (IPv4 or IPv6)',

  -- What / where
  target_table VARCHAR(64)  NOT NULL COMMENT 'Table that was written (e.g. bd_racking_v2)',
  target_pk    BIGINT UNSIGNED NULL  COMMENT 'PK of the row that was inserted/updated',
  action       ENUM('insert','update') NOT NULL,

  -- Diff
  before_json  JSON NULL COMMENT 'Previous row snapshot (NULL on insert)',
  after_json   JSON NOT NULL COMMENT 'Submitted values after write',

  -- Context
  comment      TEXT NULL COMMENT 'Operator free-text comment (required when qc_flag=outlier)',
  qc_flag      ENUM('normal','elevated','outlier') NOT NULL DEFAULT 'normal',

  KEY idx_arr_table_pk (target_table, target_pk),
  KEY idx_arr_user     (user_id),
  KEY idx_arr_created  (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register in schema_meta
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, notes)
VALUES (
  'audit_row_revisions',
  'audit',
  'blocked',
  'app/db-write-helpers.php::log_revision()',
  'Generic row-level revision log for operator web-form writes. 1 row per UPSERT. before_json=NULL on insert. Replaces nothing — user_action_log is auth-only. Phase 6 form framework 2026-05-24.'
);
