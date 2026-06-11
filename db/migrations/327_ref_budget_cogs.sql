-- 327_ref_budget_cogs.sql
-- Per-GL-line annual budget rate (CHF/HL) for the COGS board P&L grid.
-- line_key matches the key values used in fin_cogs_board_lines() / fin_cop_board_lines()
-- so the PHP handler can JOIN budget rows to grid rows by key.
--
-- Seeded with 2026 board-sheet values. N-1 comparison deferred (no source yet).
--
-- MySQL 8 — NO ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php uses exec() which leaves result sets open).

-- ── 1. Table ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ref_budget_cogs (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fiscal_year         SMALLINT     NOT NULL,
    line_key            VARCHAR(40)  NOT NULL
                          COMMENT 'Matches fin_cogs_board_lines() / fin_cop_board_lines() key values',
    gl_accounts         VARCHAR(40)  NOT NULL
                          COMMENT 'GL account(s) for reference, e.g. 4200+4201',
    label               VARCHAR(80)  NOT NULL,
    budget_chf_per_hl   DECIMAL(10,4) NOT NULL,
    source              VARCHAR(40)  NOT NULL DEFAULT 'board-sheet-2026',
    last_modified_by    ENUM('ingest','web') NOT NULL DEFAULT 'ingest',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_year_line (fiscal_year, line_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Annual budget CHF/HL per board P&L line for the Financier COGS grid.';

-- ── 2. Seed — 2026 board values ───────────────────────────────────────────────
-- line_key values match fin_cogs_board_lines() keys exactly.
-- external (4208) is seeded for COP grid compatibility (budget=0.00).
INSERT INTO ref_budget_cogs
    (fiscal_year, line_key, gl_accounts, label, budget_chf_per_hl, source)
VALUES
    (2026, 'malts',          '4101',       'Malts',                           12.0700, 'board-sheet-2026'),
    (2026, 'hops',           '4102',       'Hops',                             7.6700, 'board-sheet-2026'),
    (2026, 'yeast',          '4103',       'Yeast',                            0.3000, 'board-sheet-2026'),
    (2026, 'other_ing',      '4104',       'Other Ingredients',                1.0800, 'board-sheet-2026'),
    (2026, 'bottles',        '4200+4201',  'Bottles (inc TEA)',               16.1700, 'board-sheet-2026'),
    (2026, 'cans',           '4202',       'Cans inc. lids',                   3.8700, 'board-sheet-2026'),
    (2026, 'reg_cardboard',  '4203+4207',  'Regular cardboard',                9.0100, 'board-sheet-2026'),
    (2026, 'spec_cardboard', '4204',       'Special order cardboard',          0.0000, 'board-sheet-2026'),
    (2026, 'plastic',        '4205',       'Plastic wrap',                     0.2100, 'board-sheet-2026'),
    (2026, 'labels',         '4206',       'Labels',                           2.8400, 'board-sheet-2026'),
    (2026, 'keg',            '4209',       'Keg packaging material',           0.6600, 'board-sheet-2026'),
    (2026, 'external',       '4208',       'External packing services',        0.0000, 'board-sheet-2026'),
    (2026, 'co2',            '4300',       'CO2',                              2.2300, 'board-sheet-2026'),
    (2026, 'chemical',       '4301',       'Chemical',                         2.2600, 'board-sheet-2026'),
    (2026, 'small_equip',    '4302',       'Small production equipment',       0.3400, 'board-sheet-2026'),
    (2026, 'transport',      '4600+4602',  'Transport costs',                  0.6400, 'board-sheet-2026'),
    (2026, 'gas_water',      '4700',       'Gaz & Water',                      9.1700, 'board-sheet-2026'),
    (2026, 'electricity',    '4702',       'Electricity',                      5.3800, 'board-sheet-2026'),
    (2026, 'waste',          '4701',       'Waste evacuation',                 1.6300, 'board-sheet-2026'),
    (2026, 'qa_qc',          '4500',       'QA / QC',                          0.0000, 'board-sheet-2026'),
    (2026, 'rd_purchases',   '4510',       'Purchases',                        0.2300, 'board-sheet-2026')
AS new_row
ON DUPLICATE KEY UPDATE
    gl_accounts       = new_row.gl_accounts,
    label             = new_row.label,
    budget_chf_per_hl = new_row.budget_chf_per_hl,
    source            = new_row.source;

-- ── 3. schema_meta ────────────────────────────────────────────────────────────
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    (
        'ref_budget_cogs',
        'reference',
        'db/migrations/325_ref_budget_cogs.sql (seed); web admin (future)',
        'allowed',
        'Annual budget CHF/HL per board line. Edit via direct DB write or future admin UI. Re-seed via new migration for fiscal-year updates.',
        'Seeded 2026-06-11 from operator board sheet (COGS & YIELDS BUDGET column).'
    )
AS new_meta
ON DUPLICATE KEY UPDATE
    writer_script      = new_meta.writer_script,
    corrections_policy = new_meta.corrections_policy,
    upstream_hint      = new_meta.upstream_hint,
    notes              = new_meta.notes;

SET @noop = 1;
