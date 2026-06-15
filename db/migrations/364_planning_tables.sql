-- Migration 364 — Planning tables (Phase 1: manual intent only)
-- Date: 2026-06-15
-- Author: maltyweb auto-migration
--
-- CARDINAL RULE: Planning is INTENT, not fact. pl_* tables read canonical state to anticipate;
-- they NEVER supply data to COGS/COP/WAC/BOM/beer-tax/inventory consumers.

-- ── pl_plan_days ─────────────────────────────────────────────────────────────
-- One row per planning date. Acts as the header / anchor for that day's items.
-- plan_date is UNIQUE: one planning day record per calendar date.

CREATE TABLE IF NOT EXISTS `pl_plan_days` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_date`               DATE            NOT NULL,
  `site_id_fk`              INT UNSIGNED    NULL DEFAULT NULL,
  `note`                    VARCHAR(500)    NULL DEFAULT NULL,
  `created_by_user_id_fk`   INT UNSIGNED    NULL DEFAULT NULL,
  `created_at`              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plan_date` (`plan_date`),
  -- NOTE: no separate idx_pdays_date — the UNIQUE key already serves as a B-tree index.
  CONSTRAINT `fk_pdays_site`    FOREIGN KEY (`site_id_fk`)            REFERENCES `ref_sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pdays_creator` FOREIGN KEY (`created_by_user_id_fk`) REFERENCES `users`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── pl_plan_items ─────────────────────────────────────────────────────────────
-- Individual planning entries per day × section (wort / packaging / logistics).
-- seq orders items within a day+section. is_active=0 = soft-deleted.
-- source='predictive' reserved for future automation; Phase 1 = 'manual' only.
-- linked_event_* reserved for future canonical event back-link; NULL in Phase 1.

CREATE TABLE IF NOT EXISTS `pl_plan_items` (
  `id`                      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `plan_date`               DATE              NOT NULL,
  `section`                 ENUM('wort','packaging','logistics') NOT NULL,
  `seq`                     INT               NOT NULL DEFAULT 0,
  `wort_process`            ENUM('brewing','racking','kze','dry_hopping') NULL DEFAULT NULL,
  `recipe_id_fk`            INT UNSIGNED      NULL DEFAULT NULL,
  `batch`                   VARCHAR(32)       NULL DEFAULT NULL,
  `beer_free_text`          VARCHAR(120)      NULL DEFAULT NULL,
  `cct_number`              INT               NULL DEFAULT NULL,
  `bbt_number`              INT               NULL DEFAULT NULL,
  `pkg_type`                ENUM('bottling','canning','kegging','serving_tank') NULL DEFAULT NULL,
  `target_volume_hl`        DECIMAL(8,2)      NULL DEFAULT NULL,
  `logistics_text`          VARCHAR(1000)     NULL DEFAULT NULL,
  `hors_process`            TINYINT(1)        NOT NULL DEFAULT 0,
  `hors_process_reason`     VARCHAR(255)      NULL DEFAULT NULL,
  `source`                  ENUM('manual','predictive') NOT NULL DEFAULT 'manual',
  `status`                  ENUM('proposed','planned','done','cancelled') NOT NULL DEFAULT 'planned',
  `linked_event_table`      VARCHAR(40)       NULL DEFAULT NULL,
  `linked_event_id`         BIGINT UNSIGNED   NULL DEFAULT NULL,
  `is_active`               TINYINT(1)        NOT NULL DEFAULT 1,
  `created_by_user_id_fk`   INT UNSIGNED      NULL DEFAULT NULL,
  `created_at`              TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pitems_date_section_seq` (`plan_date`, `section`, `seq`),
  KEY `idx_pitems_date` (`plan_date`),
  CONSTRAINT `fk_pitems_recipe`  FOREIGN KEY (`recipe_id_fk`)          REFERENCES `ref_recipes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pitems_creator` FOREIGN KEY (`created_by_user_id_fk`) REFERENCES `users`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── schema_meta rows ─────────────────────────────────────────────────────────

INSERT INTO `schema_meta`
    (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`)
VALUES
    ('pl_plan_days', 'source', 'public/modules/planning.php', 'allowed',
     'INTENT layer — reads bd_*_v2/tank-sim to anticipate; writes nothing fiscal; NEVER read by COGS/COP/WAC/BOM/beer-tax/inventory',
     'Planning page (Phase 1)')
ON DUPLICATE KEY UPDATE
    `table_class`        = VALUES(`table_class`),
    `writer_script`      = VALUES(`writer_script`),
    `corrections_policy` = VALUES(`corrections_policy`),
    `upstream_hint`      = VALUES(`upstream_hint`),
    `notes`              = VALUES(`notes`);

INSERT INTO `schema_meta`
    (`table_name`, `table_class`, `writer_script`, `corrections_policy`, `upstream_hint`, `notes`)
VALUES
    ('pl_plan_items', 'source', 'public/modules/planning.php', 'allowed',
     'INTENT layer — reads bd_*_v2/tank-sim to anticipate; writes nothing fiscal; NEVER read by COGS/COP/WAC/BOM/beer-tax/inventory',
     'Planning page (Phase 1)')
ON DUPLICATE KEY UPDATE
    `table_class`        = VALUES(`table_class`),
    `writer_script`      = VALUES(`writer_script`),
    `corrections_policy` = VALUES(`corrections_policy`),
    `upstream_hint`      = VALUES(`upstream_hint`),
    `notes`              = VALUES(`notes`);

-- ── ref_pages row ─────────────────────────────────────────────────────────────
-- INSERT IGNORE: idempotent — if page_key='planning' already exists, no-op.

INSERT IGNORE INTO `ref_pages`
    (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
    ('planning', 'Planning', '🗓', '/modules/planning.php', 'operator', 'general', 1, 25);
