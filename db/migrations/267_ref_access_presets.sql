-- 267_ref_access_presets.sql
-- Access preset tables: ref_access_presets (3 named presets),
-- ref_access_preset_pages (preset ↔ page membership),
-- and ALTER users to add access_preset_id_fk.
-- Preset membership is seeded by page_key via SELECT-JOIN (no hardcoded ids).
-- ============================================================

-- 1. Preset catalogue
CREATE TABLE `ref_access_presets` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `preset_key`  VARCHAR(48)   NOT NULL,
  `label`       VARCHAR(64)   NOT NULL,
  `description` VARCHAR(255)  NULL DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_preset_key` (`preset_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ref_access_presets` (`preset_key`, `label`, `description`)
VALUES
    ('manager',              'Manager',               'Manager — production + logistique'),
    ('production_operator',  'Opérateur production',  'Opérateur production'),
    ('logistics_operator',   'Opérateur logistique',  'Opérateur logistique');

-- 2. Preset ↔ page membership bridge
CREATE TABLE `ref_access_preset_pages` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `preset_id_fk`  INT UNSIGNED NOT NULL,
  `page_id_fk`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_preset_page` (`preset_id_fk`, `page_id_fk`),
  KEY `idx_preset_id` (`preset_id_fk`),
  KEY `idx_page_id` (`page_id_fk`),
  CONSTRAINT `fk_app_preset_fk` FOREIGN KEY (`preset_id_fk`)
      REFERENCES `ref_access_presets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_page_fk` FOREIGN KEY (`page_id_fk`)
      REFERENCES `ref_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- manager: all 14 main pages + settings + ingest (NOT charges-bc/db-browser)
INSERT INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id, pg.id
FROM `ref_access_presets` p
JOIN `ref_pages` pg ON pg.page_key IN (
    'sb-board','sb-guerre','zeppelin','triage','saisies',
    'approvisionnement','wort','fermentation','packaging','fulfilment',
    'qa','sku-costs','warehouse','rm-comparison',
    'settings','ingest'
)
WHERE p.preset_key = 'manager';

-- production_operator: sb-board, sb-guerre, zeppelin, saisies, wort, fermentation, packaging, qa
INSERT INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id, pg.id
FROM `ref_access_presets` p
JOIN `ref_pages` pg ON pg.page_key IN (
    'sb-board','sb-guerre','zeppelin','saisies',
    'wort','fermentation','packaging','qa'
)
WHERE p.preset_key = 'production_operator';

-- logistics_operator: sb-board, saisies, approvisionnement, warehouse, fulfilment, rm-comparison
INSERT INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id, pg.id
FROM `ref_access_presets` p
JOIN `ref_pages` pg ON pg.page_key IN (
    'sb-board','saisies','approvisionnement',
    'warehouse','fulfilment','rm-comparison'
)
WHERE p.preset_key = 'logistics_operator';

-- 3. Add access_preset_id_fk to users (nullable — NULL = no preset)
ALTER TABLE `users`
    ADD COLUMN `access_preset_id_fk` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'NULL = no preset; admin role bypasses preset check; others fall through to min_role'
        AFTER `manager_scope`,
    ADD CONSTRAINT `fk_users_access_preset`
        FOREIGN KEY (`access_preset_id_fk`)
        REFERENCES `ref_access_presets` (`id`)
        ON DELETE SET NULL;

-- schema_meta classifications
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_access_presets', 'reference', 'allowed',
     'db/migrations/267_ref_access_presets.sql (seed); admin UI (future)',
     'Named presets for page access bundles. Add/rename via admin UI or direct edit. Do not delete presets with assigned users — reassign first.'),
    ('ref_access_preset_pages', 'reference', 'allowed',
     'db/migrations/267_ref_access_presets.sql (seed); admin UI (future)',
     'Preset ↔ page membership. Edit via preset admin UI. Rows cascade-deleted when a preset or page is removed.');
