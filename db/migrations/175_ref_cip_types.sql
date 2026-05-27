-- db/migrations/175_ref_cip_types.sql
-- What: Create ref_cip_types — editable reference table for CIP cleaning-program types.
-- Why:  CIP module convergence Wave 1. bd_cip_events (migration 176) needs a FK target
--       for cip_type_id_fk. This table replaces the flat free-text cip_type / cip_bbt_type
--       / cct_cip columns with a governed, operator-editable list. Seeds the 4 canonical
--       types that appear in historical flat-column data (Soude, Acide, Full CIP,
--       Full CIP + rinser). Migration 177 maps those historical values to these rows.
-- Risk: LOW — CREATE TABLE IF NOT EXISTS; seed uses INSERT IGNORE.
-- Rollback:
--   DROP TABLE IF EXISTS ref_cip_types;

CREATE TABLE IF NOT EXISTS `ref_cip_types` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64)     NOT NULL COLLATE utf8mb4_unicode_ci,
  `sort_order` INT UNSIGNED    NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `notes`      VARCHAR(255)    NULL     COLLATE utf8mb4_unicode_ci,
  `created_at` TIMESTAMP       NULL     DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NULL     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(64)     NULL     COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cip_type_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 4 canonical types.
-- INSERT IGNORE: safe on re-run (uq_cip_type_name constraint prevents duplicates).
INSERT IGNORE INTO `ref_cip_types` (`name`, `sort_order`, `is_active`) VALUES
  ('Soude',            1, 1),
  ('Acide',            2, 1),
  ('Full CIP',         3, 1),
  ('Full CIP + rinser',4, 1);

-- schema_meta classification row.
-- table_class = 'reference': editable lookup with business semantics (like ref_mi_categories).
-- corrections_policy = 'allowed': operators edit via admin UI.
-- writer_script = 'manual/web (admin)': no automated writer; Salle des Machines UI manages rows.
INSERT IGNORE INTO `schema_meta`
  (`table_name`, `table_class`, `corrections_policy`, `writer_script`, `upstream_hint`)
VALUES
  ('ref_cip_types', 'reference', 'allowed', 'manual/web (admin)', NULL);
