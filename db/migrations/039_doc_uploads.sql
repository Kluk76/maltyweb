-- 039 — Operator upload audit trail.
--
-- Tracks each file POSTed by an operator from the web UI.
-- Created independently of the pipeline-side doc_files row so we can:
--   a) detect "upload arrived but pipeline never ran" (orphan detection)
--   b) expose per-upload polling via pipeline_status without touching doc_files
--
-- Flow:
--   1. Row inserted with pipeline_status='uploaded'
--   2. After Drive upload: drive_file_id populated, pipeline_status='triggered'
--   3. Status endpoint polls doc_files / doc_invoices / doc_ambiguous:
--        → sets 'processed' when a downstream doc_* row exists
--        → sets 'timeout'   when pipeline_started_at > 600s old with no result
--
-- Down-migration:
--   DROP TABLE IF EXISTS doc_uploads;

CREATE TABLE IF NOT EXISTS doc_uploads (
  id                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  uploaded_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id               INT UNSIGNED     NOT NULL,
  source                ENUM('maltyweb-web','maltyweb-mobile') NOT NULL DEFAULT 'maltyweb-web',
  original_filename     VARCHAR(255)     NULL
    COMMENT 'Sanitized version of the operator-supplied filename',
  storage_filename      VARCHAR(120)     NULL
    COMMENT 'UUID-based name used as Drive filename',
  mime_type             VARCHAR(80)      NULL,
  byte_size             INT UNSIGNED     NULL,
  drive_file_id         VARCHAR(64)      NULL
    COMMENT 'Populated after successful Drive multipart upload',
  pipeline_status       ENUM('uploaded','triggered','processed','failed','timeout')
                        NOT NULL DEFAULT 'uploaded',
  pipeline_started_at   TIMESTAMP        NULL,
  pipeline_finished_at  TIMESTAMP        NULL,
  error_text            TEXT             NULL,
  client_ip             VARBINARY(16)    NULL
    COMMENT 'inet_pton() packed binary — supports both IPv4 and IPv6',
  client_ua             VARCHAR(255)     NULL,
  PRIMARY KEY (id),
  KEY idx_doc_uploads_uploaded_at        (uploaded_at),
  KEY idx_doc_uploads_user_uploaded      (user_id, uploaded_at),
  KEY idx_doc_uploads_drive_file_id      (drive_file_id),
  CONSTRAINT fk_doc_uploads_user         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
