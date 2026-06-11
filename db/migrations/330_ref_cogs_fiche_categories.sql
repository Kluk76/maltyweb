-- 330_ref_cogs_fiche_categories.sql
-- Canonical master table for the COGS "Fiche de Calcul" inventory GL categories,
-- plus the externally-anchored April-2026 opening values ("Compta au 31.03.2026").
--
-- category_key is the join key used by the COGS compute; GL mappings sourced from
-- EXT tab 2026APRIL cols D/E/F (CFO source of truth — do not alter without CFO sign-off).
-- Opening values from EXT col K; sum = 431 354.00 CHF.
--
-- MySQL 8 — NO ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
-- NO bare SELECT statements (migrate.php uses exec() which leaves result sets open).
-- 2026-06-11

-- ── 1. Category master ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ref_cogs_fiche_categories (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    category_key    VARCHAR(32)     NOT NULL
                      COMMENT 'Join key used by the COGS compute; matches EXT Fiche de Calcul col D',
    label_fr        VARCHAR(64)     NOT NULL
                      COMMENT 'Display label as shown in the CFO Fiche de Calcul',
    inv_gl          VARCHAR(16)     NOT NULL
                      COMMENT 'Comptes Inv. (inventory GL account) — EXT col E',
    charge_gl       VARCHAR(16)     NOT NULL
                      COMMENT 'Cptes Charge (expense GL account) — EXT col F',
    display_order   SMALLINT UNSIGNED NOT NULL,
    is_brewing      TINYINT(1)      NOT NULL DEFAULT 0
                      COMMENT '1 = can carry WIP (Bières en cours): Malt/Hops/Ingredients/Yeast only',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ref_cogs_fiche_category_key (category_key),
    KEY idx_ref_cogs_fiche_display_order (display_order),
    CONSTRAINT chk_ref_cogs_fiche_is_brewing CHECK (is_brewing IN (0, 1)),
    CONSTRAINT chk_ref_cogs_fiche_is_active  CHECK (is_active  IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical GL/label/order master for the COGS Fiche de Calcul inventory categories.';

-- ── 2. Seed — 12 rows from EXT 2026APRIL, cols D/E/F ─────────────────────────
-- is_brewing=1: Malt, Hops, Ingredients, Yeast (can carry WIP).
-- Ingrédients uses charge GL 4104 and Levures uses 4103 — correct per EXT, do not change.
INSERT INTO ref_cogs_fiche_categories
    (display_order, category_key, label_fr, inv_gl, charge_gl, is_brewing)
VALUES
    ( 1, 'Malt',        'Malt',        '1200.1', '4101', 1),
    ( 2, 'Hops',        'Hops',        '1200.2', '4102', 1),
    ( 3, 'Ingredients', 'Ingrédients', '1200.3', '4104', 1),
    ( 4, 'Yeast',       'Levures',     '1200.4', '4103', 1),
    ( 5, 'Cartons',     'Cartons',     '1201.1', '4207', 0),
    ( 6, 'Inliner',     'Inliner',     '1201.2', '4205', 0),
    ( 7, 'Capsules',    'Capsules',    '1201.3', '4200', 0),
    ( 8, 'Verres',      'Verres',      '1203.0', '6607', 0),
    ( 9, 'Bouteilles',  'Bouteilles',  '1204',   '4200', 0),
    (10, 'Etiquettes',  'Étiquettes',  '1205',   '4206', 0),
    (11, 'Canettes',    'Canettes',    '1207',   '4202', 0),
    (12, 'CapsFuts',    'Caps Fûts',   '1221',   '4209', 0)
AS new_row
ON DUPLICATE KEY UPDATE
    label_fr      = new_row.label_fr,
    inv_gl        = new_row.inv_gl,
    charge_gl     = new_row.charge_gl,
    display_order = new_row.display_order,
    is_brewing    = new_row.is_brewing;

-- ── 3. Opening anchor table ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cogs_fiche_opening_anchor (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    month_key       CHAR(7)         NOT NULL
                      COMMENT 'YYYY-MM; only 2026-04 seeded — the sole month that cannot self-chain',
    category_key    VARCHAR(32)     NOT NULL
                      COMMENT 'FK to ref_cogs_fiche_categories.category_key',
    opening_chf     DECIMAL(14,2)   NOT NULL
                      COMMENT 'Opening inventory value CHF from EXT col K (Compta au 31.03.2026)',
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cogs_opening_month_cat (month_key, category_key),
    CONSTRAINT fk_cogs_opening_category
        FOREIGN KEY (category_key) REFERENCES ref_cogs_fiche_categories (category_key)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Externally-anchored opening inventory values (EXT col K) for the first computed COGS month.';

-- ── 4. Seed — April-2026 openings (Compta au 31.03.2026) ─────────────────────
-- Total = 431 354.00 CHF (verified against EXT source).
INSERT INTO cogs_fiche_opening_anchor
    (month_key, category_key, opening_chf)
VALUES
    ('2026-04', 'Malt',        50604.00),
    ('2026-04', 'Hops',        67249.00),
    ('2026-04', 'Ingredients', 10663.00),
    ('2026-04', 'Yeast',        1845.00),
    ('2026-04', 'Cartons',    211064.00),
    ('2026-04', 'Inliner',      6938.00),
    ('2026-04', 'Capsules',     2543.00),
    ('2026-04', 'Verres',      14213.00),
    ('2026-04', 'Bouteilles',  27068.00),
    ('2026-04', 'Etiquettes',  13866.00),
    ('2026-04', 'Canettes',    19470.00),
    ('2026-04', 'CapsFuts',     5831.00)
AS new_opening
ON DUPLICATE KEY UPDATE
    opening_chf = new_opening.opening_chf;

-- ── 5. schema_meta ────────────────────────────────────────────────────────────
INSERT INTO schema_meta
    (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
    (
        'ref_cogs_fiche_categories',
        'reference',
        'db/migrations/330_ref_cogs_fiche_categories.sql (seed)',
        'allowed',
        'GL/label/order master for COGS Fiche de Calcul. GL mappings sourced from EXT workbook (CFO source of truth). Edit via direct DB write or future admin UI; new migration for structural changes.',
        'Seeded 2026-06-11 from EXT 2026APRIL cols D/E/F. 12 rows.'
    ),
    (
        'cogs_fiche_opening_anchor',
        'reference',
        'db/migrations/330_ref_cogs_fiche_categories.sql (seed)',
        'allowed',
        'Opening inventory CHF values anchored from the EXT workbook (col K). Only 2026-04 row exists — first month cannot self-chain. Do not add rows without CFO-sourced EXT values.',
        'Seeded 2026-06-11 from EXT col K (Compta au 31.03.2026). Sum = 431354.00 CHF.'
    )
AS new_meta
ON DUPLICATE KEY UPDATE
    writer_script      = new_meta.writer_script,
    corrections_policy = new_meta.corrections_policy,
    upstream_hint      = new_meta.upstream_hint,
    notes              = new_meta.notes;

SET @noop = 1;
