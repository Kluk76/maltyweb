-- db/migrations/176_bd_cip_events.sql
-- What: Create bd_cip_events — the shared CIP child table for all process forms
--       (racking, brewing, packaging).
-- Why:  CIP module convergence Wave 1. The flat CIP columns on bd_racking_v2,
--       bd_brewing_brewday_v2, and bd_packaging_v2 will be normalised here.
--       One row per CIP event, linked back to its parent form row via a single
--       non-NULL source FK (enforced by chk_cip_parent_xor). Migration 177
--       backfills historical flat-column data into this table.
-- Risk: LOW — new table with no existing dependents.
-- Parent PK types verified (all BIGINT UNSIGNED):
--   bd_racking_v2.id         BIGINT UNSIGNED AUTO_INCREMENT
--   bd_brewing_brewday_v2.id BIGINT UNSIGNED AUTO_INCREMENT
--   bd_packaging_v2.id       BIGINT UNSIGNED AUTO_INCREMENT
--   ref_cip_types.id         INT UNSIGNED (FK col cip_type_id_fk = INT UNSIGNED — matches)
-- Rollback:
--   DROP TABLE IF EXISTS bd_cip_events;

CREATE TABLE IF NOT EXISTS `bd_cip_events` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `source_form`   ENUM('racking','brewing','packaging') COLLATE utf8mb4_unicode_ci NOT NULL,
  `racking_id`    BIGINT UNSIGNED  NULL,
  `brewing_id`    BIGINT UNSIGNED  NULL,
  `packaging_id`  BIGINT UNSIGNED  NULL,
  `target_kind`   ENUM('machine','vessel') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_code`   ENUM('centri','kze','pump','cct','yt','bbt','tank','unspecified') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_number` INT UNSIGNED     NULL,
  `cip_type_id_fk` INT UNSIGNED   NULL,
  `cip_date`      VARCHAR(32)      NULL COLLATE utf8mb4_unicode_ci,
  `cip_started_at` TIME            NULL,
  `cip_ended_at`   TIME            NULL,
  `inline_group`  TINYINT UNSIGNED NULL,
  `notes`         VARCHAR(255)     NULL COLLATE utf8mb4_unicode_ci,
  `row_hash`      CHAR(64)         NOT NULL COLLATE utf8mb4_unicode_ci,
  `submitted_at`  VARCHAR(32)      NULL COLLATE utf8mb4_unicode_ci,
  `email`         VARCHAR(255)     NULL COLLATE utf8mb4_unicode_ci,
  `is_tombstoned` TINYINT(1)       NOT NULL DEFAULT 0,
  `imported_at`   TIMESTAMP        NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_row_hash` (`row_hash`),
  KEY `idx_cip_racking`   (`racking_id`),
  KEY `idx_cip_brewing`   (`brewing_id`),
  KEY `idx_cip_packaging` (`packaging_id`),
  CONSTRAINT `fk_cip_racking`
    FOREIGN KEY (`racking_id`)   REFERENCES `bd_racking_v2`(`id`)         ON DELETE RESTRICT,
  CONSTRAINT `fk_cip_brewing`
    FOREIGN KEY (`brewing_id`)   REFERENCES `bd_brewing_brewday_v2`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cip_packaging`
    FOREIGN KEY (`packaging_id`) REFERENCES `bd_packaging_v2`(`id`)       ON DELETE RESTRICT,
  CONSTRAINT `fk_cip_type`
    FOREIGN KEY (`cip_type_id_fk`) REFERENCES `ref_cip_types`(`id`)       ON DELETE RESTRICT,
  CONSTRAINT `chk_cip_parent_xor` CHECK (
    (`source_form` = 'racking'   AND `racking_id`   IS NOT NULL AND `brewing_id`   IS NULL AND `packaging_id` IS NULL) OR
    (`source_form` = 'brewing'   AND `brewing_id`   IS NOT NULL AND `racking_id`   IS NULL AND `packaging_id` IS NULL) OR
    (`source_form` = 'packaging' AND `packaging_id` IS NOT NULL AND `racking_id`   IS NULL AND `brewing_id`   IS NULL)
  ),
  CONSTRAINT `chk_cip_target` CHECK (
    (`target_kind` = 'machine' AND `target_code` IN ('centri','kze','pump','unspecified')) OR
    (`target_kind` = 'vessel'  AND `target_code` IN ('cct','yt','bbt','tank'))
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification row.
-- table_class = 'source': raw event data ingested from process forms (same class as other bd_*_v2 tables).
-- corrections_policy = 'allowed': operator corrections via admin surface are permitted.
-- writer_script = 'ingest_bd_cip.py': future form ingest writer (Wave 2+).
INSERT IGNORE INTO `schema_meta`
  (`table_name`, `table_class`, `corrections_policy`, `writer_script`, `upstream_hint`)
VALUES
  ('bd_cip_events', 'source', 'allowed', 'ingest_bd_cip.py', NULL);
