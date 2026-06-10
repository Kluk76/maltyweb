-- =============================================================================
-- Migration 303 — inv_side_stock_ledger
-- Packaging loose-unit remainder ledger.
-- Source: bd_packaging_v2.prod_total_units MOD ref_skus.units_per_pack
--
-- NOT COGS — never read by any COGS/COP path; never priced in CHF; never JOINs
-- ref_sku_bom; never writes inv_consumption. Unit counts only.
--
-- NOT sale_class — wholly distinct from sales-side giveaway (migs 300/301).
--
-- Production-site-homed — accruals belong to the production site; derive from
-- ref_sites WHERE site_type='production'. Never hardcode a site id.
--
-- Out of fg_stock_compute() FG legs — loose remainder is NOT finished goods
-- (full-box flooring already excludes it). fg_stock_compute() and
-- fg_stock_location_snapshot() in app/fg-stock.php must stay byte-unchanged.
--
-- Year-end beer-tax hook (cron NOT built — documented only):
--   JOIN shape: SELECT sku_id_fk, SUM(qty_units) AS units_offered
--               FROM inv_side_stock_ledger
--              WHERE movement_type = 'year_end_offered'
--                AND fiscal_year   = ?
--                AND is_tombstoned = 0
--              GROUP BY sku_id_fk
--   Units → HL via ref_skus.hl_per_unit.
--   NO DOUBLE COUNT: only year_end_offered rows feed beer-tax.
--   Accruals are never taxed (never FG).
--   complete_box → FG, taxed via normal sales path.
--   Giveaway draws net into the year-end residual → taxed once at year-end.
--   The remainder HL was carved OUT of the production base by full-box flooring;
--   adding it back only at year-end cannot double-count.
--
-- FK types verified (2026-06-10, mig 303):
--   ref_skus.id        → INT UNSIGNED
--   bd_packaging_v2.id → BIGINT UNSIGNED
--   users.id           → INT UNSIGNED
-- =============================================================================

CREATE TABLE IF NOT EXISTS inv_side_stock_ledger (
    id                   BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    sku_id_fk            INT UNSIGNED         NOT NULL,
    movement_type        ENUM(
                           'accrual',
                           'complete_box',
                           'giveaway',
                           'year_end_offered',
                           'adjustment'
                         )                    NOT NULL,
    qty_units            INT                  NOT NULL
        COMMENT 'SIGNED: accrual>0; complete_box/giveaway/year_end_offered<0; adjustment≠0',
    bd_packaging_id_fk   BIGINT UNSIGNED      NULL
        COMMENT 'Provenance of accrual or the run consuming a draw; NULL for manual draws',
    fiscal_year          CHAR(4)              NOT NULL
        COMMENT 'Year-end-settle cohort key (YYYY)',
    note                 VARCHAR(255)         NULL,
    created_at           DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_by_user_fk INT UNSIGNED         NULL
        COMMENT 'Mirror mig-295 convention; NULL for system-generated rows',
    is_tombstoned        TINYINT(1)           NOT NULL DEFAULT 0,
    tombstoned_at        DATETIME             NULL,

    PRIMARY KEY (id),

    -- Per-SKU live balance: SUM(qty_units) WHERE is_tombstoned=0
    KEY idx_ssl_sku_live   (sku_id_fk, is_tombstoned),
    -- Year-end beer-tax sweep
    KEY idx_ssl_year       (fiscal_year, is_tombstoned),
    -- Provenance back-link
    KEY idx_ssl_bdpkg      (bd_packaging_id_fk),

    -- Sign CHECK — enforced, not advisory
    CONSTRAINT chk_ssl_sign CHECK (
        (movement_type = 'accrual'           AND qty_units > 0)
     OR (movement_type = 'complete_box'      AND qty_units < 0)
     OR (movement_type = 'giveaway'          AND qty_units < 0)
     OR (movement_type = 'year_end_offered'  AND qty_units < 0)
     OR (movement_type = 'adjustment'        AND qty_units <> 0)
    ),

    -- FK constraints (types verified against parent PKs above)
    CONSTRAINT fk_ssl_sku   FOREIGN KEY (sku_id_fk)
        REFERENCES ref_skus (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ssl_bdpkg FOREIGN KEY (bd_packaging_id_fk)
        REFERENCES bd_packaging_v2 (id) ON DELETE SET NULL,
    CONSTRAINT fk_ssl_user  FOREIGN KEY (submitted_by_user_fk)
        REFERENCES users (id) ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

-- ── Idempotent accrual guard ──────────────────────────────────────────────────
-- Plain nullable column written by PHP: CONCAT(bd_packaging_id_fk, ':', sku_id_fk)
-- for accrual rows; NULL for all draws/adjustments/tombstoned rows.
-- UNIQUE key: NULLs are not considered equal in MySQL UNIQUE indexes, so only
-- live accrual rows are uniqued — draws and adjustments can have NULL freely.
--
-- NOTE: GENERATED ALWAYS AS ... STORED was attempted but MySQL 8.0.46 returns
-- error 1215 when adding a STORED generated column via ALTER TABLE if the
-- expression references a column that is also a FK target. VIRTUAL works but
-- cannot be UNIQUE reliably. Plain column + PHP write is the proven path.
--
-- Mig-265 discipline: sku_id_fk is the per-format resolve_packaging_sku_id()
-- result, so a parallel -4/-B run produces a distinct accrual_key.
ALTER TABLE inv_side_stock_ledger
    ADD COLUMN accrual_key VARCHAR(48) NULL
        COMMENT 'PHP-set idempotency key for accruals: CONCAT(bd_packaging_id_fk,":",sku_id_fk). NULL for all other rows.';

ALTER TABLE inv_side_stock_ledger
    ADD UNIQUE KEY uq_ssl_accrual (accrual_key);

-- ── schema_meta registration ──────────────────────────────────────────────────
-- Confirms: table_class='source', corrections_policy='allowed',
-- out of fg_stock_compute, NOT COGS, NOT sale_class.
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES (
    'inv_side_stock_ledger',
    'source',
    'public/modules/form-packaging.php',
    'allowed',
    'bd_packaging_v2.prod_total_units MOD ref_skus.units_per_pack',
    'Packaging loose-unit remainder ledger. NOT COGS, NOT sale_class. '
    'Production-site homed. Out of fg_stock_compute FG legs. '
    'Balance = SUM(qty_units) WHERE is_tombstoned=0. '
    'Year-end-settle writes year_end_offered draws → beer-offered/beer-tax. '
    'Writer: form-packaging.php (accrual + complete_box); '
    'expeditions.php?view=side-stock (giveaway + tombstone).'
);
