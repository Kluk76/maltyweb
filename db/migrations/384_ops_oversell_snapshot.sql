-- Migration 384: create ops_oversell_snapshot — daily ATP-breach history table
-- Author: claude
-- Date: 2026-06-16

CREATE TABLE `ops_oversell_snapshot` (
    `id`                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `snapshot_date`       DATE             NOT NULL,
    `sku_id_fk`           INT UNSIGNED     NOT NULL,
    `live_futur`          DECIMAL(12,2)    NOT NULL,
    `units_short`         DECIMAL(12,2)    NOT NULL,
    `on_trade_short`      DECIMAL(12,2)    NOT NULL DEFAULT 0,
    `off_trade_short`     DECIMAL(12,2)    NOT NULL DEFAULT 0,
    `unclassified_short`  DECIMAL(12,2)    NOT NULL DEFAULT 0,
    `created_at`          TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_oversell_snapshot_date_sku` (`snapshot_date`, `sku_id_fk`),
    CONSTRAINT `fk_oversell_snapshot_sku` FOREIGN KEY (`sku_id_fk`) REFERENCES `ref_skus` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    (
        'ops_oversell_snapshot',
        'source',
        'allowed',
        'scripts/snapshot-oversell.php',
        'Run scripts/snapshot-oversell.php to regenerate; re-running is idempotent. One row per (snapshot_date, sku_id_fk); ON DUPLICATE KEY UPDATE keeps only the latest values for each date.'
    );

