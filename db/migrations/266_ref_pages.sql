-- 266_ref_pages.sql
-- Single source of truth for the nav page registry.
-- Seeds all 18 pages (14 main + 4 admin) from topbar.php with sort, min_role, domain, is_active.
-- ============================================================

CREATE TABLE `ref_pages` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `page_key`   VARCHAR(48)     NOT NULL,
  `label`      VARCHAR(64)     NOT NULL,
  `icon`       VARCHAR(8)      NULL DEFAULT NULL,
  `href`       VARCHAR(128)    NOT NULL,
  `min_role`   ENUM('viewer','operator','manager','admin')
                               NOT NULL DEFAULT 'viewer',
  `domain`     ENUM('production','logistics','admin','general')
                               NULL DEFAULT NULL,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `sort`       INT             NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page_key` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 14 main nav entries (sort 10..140) + 4 admin-overflow entries (sort 150..180)
-- min_role: viewer for all main modules (require_login today = any role incl. viewer)
--           manager for settings, admin for ingest/charges-bc/db-browser
-- domain:   production = {zeppelin,wort,fermentation,packaging,qa}
--           logistics   = {approvisionnement,warehouse,fulfilment,rm-comparison}
--           admin       = {settings,ingest,charges-bc,db-browser}
--           general     = {sb-board,sb-guerre,triage,saisies,sku-costs}
-- is_active: 0 for fulfilment and qa (href='#', not yet built)

INSERT INTO `ref_pages`
    (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
    ('sb-board',        'Lots en cours',      '⊞',  '/modules/sb-board.php',              'viewer', 'general',    1,  10),
    ('sb-guerre',       'Salle de Guerre',    '⚑',  '/modules/sb-salle-de-guerre.php',    'viewer', 'general',    1,  20),
    ('zeppelin',        'Le Zeppelin',        '✦',  '/modules/le-zeppelin.php',            'viewer', 'production', 1,  30),
    ('triage',          'Triage',             '00', '/modules/triage.php',                 'viewer', 'general',    1,  40),
    ('saisies',         'Saisies',            '✎',  '/modules/saisies.php',                'viewer', 'general',    1,  50),
    ('approvisionnement','Approvisionnement', '01', '/modules/approvisionnement.php',      'viewer', 'logistics',  1,  60),
    ('wort',            'Wort Production',    '02', '/modules/wort.php',                   'viewer', 'production', 1,  70),
    ('fermentation',    'Fermentation',       '03', '/modules/tanks.php',                  'viewer', 'production', 1,  80),
    ('packaging',       'Packaging',          '04', '/modules/packaging.php',              'viewer', 'production', 1,  90),
    ('fulfilment',      'Fulfilment',         '05', '#',                                   'viewer', 'logistics',  0, 100),
    ('qa',              'QA / QC',            '06', '#',                                   'viewer', 'production', 0, 110),
    ('sku-costs',       'SKU Costs',          '07', '/modules/sku-costs.php',              'viewer', 'general',    1, 120),
    ('warehouse',       'Warehouse',          '08', '/modules/warehouse.php',              'viewer', 'logistics',  1, 130),
    ('rm-comparison',   'Bilan MP',           '09', '/modules/rm-stock-comparison.php',   'viewer', 'logistics',  1, 140),
    ('settings',        'Paramètres',         NULL, '/admin/settings.php',                 'manager','admin',      1, 150),
    ('ingest',          'Ingest',             NULL, '/admin/ingest.php',                   'admin',  'admin',      1, 160),
    ('charges-bc',      'ChargesBC',          NULL, '/modules/charges-bc.php',             'admin',  'admin',      1, 170),
    ('db-browser',      'DB Browser',         NULL, '/admin/db-browser.php',               'admin',  'admin',      1, 180);

-- schema_meta classification.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('ref_pages', 'reference', 'allowed',
     'db/migrations/266_ref_pages.sql (seed); admin UI (future)',
     'Nav page registry. Edit label/icon/href/min_role/domain/is_active via admin UI or direct MySQL edit. Do not delete rows — set is_active=0.');
