-- 332_cogs_fiche_seed_and_monthly.sql
-- What: COGS Fiche de Calcul — immutable seed table (closed periods) +
--       engine-computed monthly table (May-forward); April-2026 seed from
--       EXT workbook; DROP superseded opening-anchor table.
-- Why:  CFO-critical permanent schema. April-2026 is a signed-off CLOSED
--       period; values are seeded verbatim from EXT 2026APRIL (CFO source
--       of truth). The engine computes May+.  cogs_fiche_opening_anchor
--       (mig 330) is superseded by cogs_fiche_seed.opening_chf.
-- Risk: DROP TABLE cogs_fiche_opening_anchor (contains only April openings
--       already replicated into cogs_fiche_seed). CREATE of two new tables.
--       No consumer PHP/TS reads cogs_fiche_opening_anchor yet (shipped same
--       sprint, no live consumer).
-- Rollback: DROP TABLE cogs_fiche_seed; DROP TABLE cogs_fiche_monthly;
--           then restore cogs_fiche_opening_anchor from migration 330.
--
-- Money precision: DECIMAL(14,4) — EXT raw values stored at 4dp so
--   ROUND(SUM(col),2) == EXT's own formatted totals (DECIMAL(14,2) loses
--   2 cents on total_chf due to per-row rounding accumulation — verified).
--
-- MySQL 8 — NO ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php uses exec() → result-set poison).
-- 2026-06-11

-- ── 1. cogs_fiche_seed — immutable seeded CLOSED periods ─────────────────────
CREATE TABLE IF NOT EXISTS cogs_fiche_seed (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    month_key       CHAR(7)          NOT NULL
                      COMMENT 'YYYY-MM; only closed/CFO-signed periods land here',
    category_key    VARCHAR(32)      NOT NULL
                      COMMENT 'FK → ref_cogs_fiche_categories.category_key',
    rm_chf          DECIMAL(14,4)    NOT NULL DEFAULT 0
                      COMMENT 'RM closing stock CHF — EXT col G',
    wip_chf         DECIMAL(14,4)    NOT NULL DEFAULT 0
                      COMMENT 'WIP (Bières en cours) closing stock CHF — EXT col H',
    fg_chf          DECIMAL(14,4)    NOT NULL DEFAULT 0
                      COMMENT 'FG closing stock CHF — EXT col I',
    total_chf       DECIMAL(14,4)    NOT NULL
                      COMMENT 'Total Inventaire CHF — EXT col J stored verbatim (may differ by rounding from G+H+I)',
    opening_chf     DECIMAL(14,4)    NOT NULL
                      COMMENT 'Opening inventory CHF — EXT col K (prior-month closing)',
    variation_chf   DECIMAL(14,4)    NOT NULL
                      COMMENT 'Variation de Stock CHF — EXT col L (J − K)',
    seed_source     VARCHAR(64)      NOT NULL DEFAULT 'EXT-April-signed'
                      COMMENT 'Provenance tag for auditability',
    seeded_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cogs_fiche_seed_month_cat (month_key, category_key),
    KEY idx_cogs_fiche_seed_month (month_key),
    CONSTRAINT fk_cogs_fiche_seed_category
        FOREIGN KEY (category_key) REFERENCES ref_cogs_fiche_categories (category_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable seeded closing-stock + variation values for CFO-signed CLOSED periods. Append-only; never UPDATE.';

-- ── 2. cogs_fiche_monthly — engine-computed open periods (May+) ──────────────
CREATE TABLE IF NOT EXISTS cogs_fiche_monthly (
    id                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    month_key             CHAR(7)         NOT NULL
                            COMMENT 'YYYY-MM of the computed period',
    category_key          VARCHAR(32)     NOT NULL
                            COMMENT 'FK → ref_cogs_fiche_categories.category_key',
    rm_chf                DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'RM closing stock CHF',
    wip_chf               DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'WIP (Bières en cours) closing stock CHF',
    fg_chf                DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'FG closing stock CHF',
    total_chf             DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'Total Inventaire CHF (rm+wip+fg, stored for idempotency)',
    opening_chf           DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'Opening inventory CHF (= prior-month closing total_chf)',
    variation_chf         DECIMAL(14,4)   NOT NULL DEFAULT 0
                            COMMENT 'Variation de Stock CHF (total_chf − opening_chf)',
    basis_adjustment_chf  DECIMAL(14,4)   NULL
                            COMMENT 'One-time WAC/F2 restatement; NULL = non isolable, base héritée — intentional semantic NULL, never fabricate',
    computed_at           DATETIME        NOT NULL
                            COMMENT 'Timestamp of the compute run',
    row_hash              CHAR(64)        NOT NULL
                            COMMENT 'SHA-256 of (month_key, category_key, rm_chf, wip_chf, fg_chf, total_chf, opening_chf, variation_chf) for idempotent upsert',

    PRIMARY KEY (id),
    UNIQUE KEY uq_cogs_fiche_monthly_hash (row_hash),
    UNIQUE KEY uq_cogs_fiche_monthly_month_cat (month_key, category_key),
    KEY idx_cogs_fiche_monthly_month (month_key),
    CONSTRAINT fk_cogs_fiche_monthly_category
        FOREIGN KEY (category_key) REFERENCES ref_cogs_fiche_categories (category_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Engine-computed closing stock + variation for open (non-closed) periods. Writer: cogs-monthly-compile.ts.';

-- ── 3. Seed April-2026 into cogs_fiche_seed (12 rows) ────────────────────────
-- Values from EXT workbook tab 2026APRIL, cols G/H/I/J/K/L, rows 4-15.
-- Stored at DECIMAL(14,4) so ROUND(SUM(total_chf),2) = 394367.74,
-- ROUND(SUM(opening_chf),2) = 431354.00, ROUND(SUM(variation_chf),2) = -36986.26
-- (verified against EXT formatted totals row — raw SUM 394367.73974...).
-- Cross-check run: 2026-06-11 — all 12 rows match EXT UNFORMATTED_VALUE
-- rounded to 4dp. One discrepancy resolved: Levures variation -356.625 stored
-- as -356.6250 (EXT raw); FORMATTED_VALUE = "-356.63" confirms -356.63 display.
INSERT INTO cogs_fiche_seed
    (month_key, category_key, rm_chf, wip_chf, fg_chf, total_chf, opening_chf, variation_chf, seed_source)
VALUES
    ('2026-04', 'Malt',        26441.2278, 14360.4300, 13550.2198,  54351.8777,  50604.0000,   3747.8777, 'EXT-April-signed'),
    ('2026-04', 'Hops',        35237.6797,  6490.5938,  2044.4506,  43772.7242,  67249.0000, -23476.2758, 'EXT-April-signed'),
    ('2026-04', 'Ingredients',  9527.0700,  1513.2447,    86.9271,  11127.2418,  10663.0000,    464.2418, 'EXT-April-signed'),
    ('2026-04', 'Yeast',        1488.3750,     0.0000,     0.0000,   1488.3750,   1845.0000,   -356.6250, 'EXT-April-signed'),
    ('2026-04', 'Cartons',    182352.7928,     0.0000,  7461.8511, 189814.6439, 211064.0000, -21249.3561, 'EXT-April-signed'),
    ('2026-04', 'Inliner',      6540.5340,     0.0000,     0.0000,   6540.5340,   6938.0000,   -397.4660, 'EXT-April-signed'),
    ('2026-04', 'Capsules',     8447.3550,     0.0000,   801.5779,   9248.9329,   2543.0000,   6705.9329, 'EXT-April-signed'),
    ('2026-04', 'Verres',      12600.2596,     0.0000,     0.0000,  12600.2596,  14213.0000,  -1612.7404, 'EXT-April-signed'),
    ('2026-04', 'Bouteilles',   8260.2548,     0.0000, 10938.0998,  19198.3546,  27068.0000,  -7869.6454, 'EXT-April-signed'),
    ('2026-04', 'Etiquettes',  24890.6973,     0.0000,  1939.6963,  26830.3937,  13866.0000,  12964.3937, 'EXT-April-signed'),
    ('2026-04', 'Canettes',     8386.5348,     0.0000,  5205.5136,  13592.0484,  19470.0000,  -5877.9516, 'EXT-April-signed'),
    ('2026-04', 'CapsFuts',     5679.3001,     0.0000,   123.0541,   5802.3542,   5831.0000,    -28.6458, 'EXT-April-signed')
AS new_row
ON DUPLICATE KEY UPDATE
    rm_chf          = new_row.rm_chf,
    wip_chf         = new_row.wip_chf,
    fg_chf          = new_row.fg_chf,
    total_chf       = new_row.total_chf,
    opening_chf     = new_row.opening_chf,
    variation_chf   = new_row.variation_chf,
    seed_source     = new_row.seed_source;

-- ── 4. DROP superseded opening-anchor table ───────────────────────────────────
-- cogs_fiche_opening_anchor (mig 330) held only opening_chf for April-2026.
-- That data is now in cogs_fiche_seed.opening_chf.  No PHP or TS consumer
-- reads this table (it was created and superseded in the same sprint).
-- Remove schema_meta row first (FK-free; schema_meta has no FK to table names).
DELETE FROM schema_meta WHERE table_name = 'cogs_fiche_opening_anchor';

DROP TABLE IF EXISTS cogs_fiche_opening_anchor;

-- ── 5. schema_meta ────────────────────────────────────────────────────────────
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    (
        'cogs_fiche_seed',
        'reference',
        'db/migrations/332_cogs_fiche_seed_and_monthly.sql (seed)',
        'allowed',
        'Immutable closed-period COGS fiche values seeded from EXT workbook (CFO-signed). Append-only — never UPDATE existing rows. New closed months: INSERT via a dedicated migration with CFO sign-off.',
        'Seeded 2026-06-11: 12 rows for 2026-04 from EXT 2026APRIL cols G/H/I/J/K/L. DECIMAL(14,4). ROUND(SUM(total_chf),2)=394367.74.'
    ),
    (
        'cogs_fiche_monthly',
        'derived',
        'cogs-monthly-compile.ts',
        'blocked_with_redirect',
        'Engine-computed by cogs-monthly-compile.ts. Do not edit manually — rerun the compute script for the relevant month_key to regenerate. Idempotent via row_hash UNIQUE.',
        'Created 2026-06-11. Writer: cogs-monthly-compile.ts. May-2026 is the first computed period.'
    )
AS new_meta
ON DUPLICATE KEY UPDATE
    table_class        = new_meta.table_class,
    writer_script      = new_meta.writer_script,
    corrections_policy = new_meta.corrections_policy,
    upstream_hint      = new_meta.upstream_hint,
    notes              = new_meta.notes;

SET @noop = 1;
