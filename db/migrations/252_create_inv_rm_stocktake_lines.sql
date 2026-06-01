-- 252_create_inv_rm_stocktake_lines.sql
--
-- Per-pallet RM stocktake lines table.
--
-- Each row = one physical pallet/bag/container count.
-- The rollup trigger (via PHP rm_recompute_rollup()) re-sums active lines
-- into inv_rm_stocktake.counted_qty on every add/delete.
--
-- NULL-vs-0 invariant (enforced by the rollup function):
--   counted_qty = NULL  → no active lines (fall back to expected_qty)
--   counted_qty = 0     → operator explicitly counted a stock-out
--
-- FK: mi_id_fk → ref_mi(id)  — ref_mi.id is INT UNSIGNED, matched exactly.
-- Natural key used by rollup: (mi_id, period, is_active).
-- row_hash: unique per insert (includes microtime nonce), two equal-qty pallets
--           are two distinct lines — row_hash MUST NOT dedup them.
--
-- NO SELECT statements (migrate.php uses PDO::exec(), leaving result sets open).

CREATE TABLE IF NOT EXISTS inv_rm_stocktake_lines (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    mi_id_fk    INT UNSIGNED     NOT NULL,
    mi_id       VARCHAR(64)      NOT NULL,
    period      CHAR(7)          NOT NULL,
    qty         DECIMAL(14,3)    NOT NULL,
    label       VARCHAR(120)     NULL,
    source      VARCHAR(32)      NOT NULL DEFAULT 'web-form-line',
    counted_by  VARCHAR(64)      NULL,
    counted_at  DATETIME         NOT NULL,
    is_active   TINYINT(1)       NOT NULL DEFAULT 1,
    row_hash    CHAR(64)         NOT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_row_hash (row_hash),
    KEY idx_mi_period_active (mi_id, period, is_active),
    KEY idx_period_active (period, is_active),
    CONSTRAINT fk_rmlines_mi
        FOREIGN KEY (mi_id_fk) REFERENCES ref_mi (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema_meta classification row (source, allowed — same policy as inv_rm_stocktake)
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('inv_rm_stocktake_lines', 'source', 'allowed',
     'form-rm-stocktake.php (per-pallet API endpoints)',
     'Rollup to inv_rm_stocktake.counted_qty via rm_recompute_rollup() on every write.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
