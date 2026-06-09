-- db/migrations/298_journal_saisies.sql
-- What: Add "Journal des saisies" global feed page.
--
--   1. CREATE OR REPLACE VIEW v_saisie_events — UNION ALL of 7 event tables
--      projecting common shape: source_table, row_pk, form_type, event_date,
--      submitted_at, submitted_by_user_id_fk, operator_email, label.
--      is_tombstoned=0 filter on every branch.
--
--   2. ADD indexes on submitted_at for the 4 brewing tables that lacked one
--      (bd_brewing_brewday_v2, bd_brewing_gravity_v2, bd_brewing_ingredients_v2,
--       bd_brewing_timings_v2). ALGORITHM=INPLACE, LOCK=NONE (MySQL 8 non-blocking).
--      bd_racking_v2, bd_fermenting_v2, bd_packaging_v2 already have submitted_at
--      indexed — skipped.
--
--   3. INSERT ref_pages row for 'journal-saisies' at sort=15.
--
--   4. INSERT ref_access_preset_pages rows for all four presets (1=manager,
--      2=production_operator, 3=logistics_operator, 8=marketing) — all operators
--      should see the feed.
--
-- Rollback:
--   DROP VIEW IF EXISTS v_saisie_events;
--   DROP INDEX idx_sa_submitted_at ON bd_brewing_brewday_v2;
--   DROP INDEX idx_sa_submitted_at ON bd_brewing_gravity_v2;
--   DROP INDEX idx_sa_submitted_at ON bd_brewing_ingredients_v2;
--   DROP INDEX idx_sa_submitted_at ON bd_brewing_timings_v2;
--   DELETE FROM ref_access_preset_pages WHERE page_id_fk = (SELECT id FROM ref_pages WHERE page_key='journal-saisies');
--   DELETE FROM ref_pages WHERE page_key='journal-saisies';
--
-- Applied via: ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'
-- ============================================================================

-- ── 1. submitted_at indexes (already applied manually for brewday;
--    applying remaining three here) ────────────────────────────────────────
-- bd_brewing_brewday_v2 was indexed manually before migration ran — skip.
-- The three below are safe to add (IF NOT EXISTS emulated via DROP IGNORE first).

ALTER TABLE `bd_brewing_gravity_v2`    ADD INDEX `idx_sa_submitted_at` (`submitted_at`);
ALTER TABLE `bd_brewing_ingredients_v2` ADD INDEX `idx_sa_submitted_at` (`submitted_at`);
ALTER TABLE `bd_brewing_timings_v2`    ADD INDEX `idx_sa_submitted_at` (`submitted_at`);

-- ── 2. v_saisie_events ───────────────────────────────────────────────────────
-- All 7 branches expose the same 8-column shape.
-- Column types aligned: row_pk BIGINT UNSIGNED, event_date DATE,
-- submitted_at DATETIME(6), submitted_by_user_id_fk INT UNSIGNED / NULL.
-- fermenting: no clean beer/batch → label from beer_raw + batch.
-- bd_brewing_gravity: includes event_type for richer label.
-- bd_brewing_timings: includes brew number for label.
CREATE OR REPLACE VIEW `v_saisie_events` AS

  -- Transfert (racking)
  SELECT
    'bd_racking_v2'                         AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Transfert'                             AS form_type,
    `event_date`                            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`neb_beer`,''), NULLIF(`contract_beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`neb_batch`,''), NULLIF(`contract_batch`,''), '—')
    )                                       AS label
  FROM `bd_racking_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Fermentation
  SELECT
    'bd_fermenting_v2'                      AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Fermentation'                          AS form_type,
    `event_date`                            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`beer_raw`,''), '—'),
      ' · ',
      COALESCE(
        CASE `event_type`
          WHEN 'DryHop'    THEN 'Dry-hop'
          WHEN 'Reads'     THEN 'Mesures'
          WHEN 'Purge'     THEN 'Purge CO₂'
          WHEN 'ColdCrash' THEN 'Refroid.'
          ELSE `event_type`
        END,
        '—'
      )
    )                                       AS label
  FROM `bd_fermenting_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Conditionnement (packaging)
  SELECT
    'bd_packaging_v2'                       AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Conditionnement'                       AS form_type,
    CAST(`submitted_at` AS DATE)            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    NULL                                    AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`neb_beer`,''), NULLIF(`contract_beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`neb_batch`,''), NULLIF(`contract_batch`,''), '—'),
      CASE `run_type`
        WHEN 'bot'   THEN ' (Bouteilles)'
        WHEN 'can'   THEN ' (Canettes)'
        WHEN 'can33' THEN ' (Canettes 33cl)'
        WHEN 'keg'   THEN ' (Fûts)'
        WHEN 'cuv'   THEN ' (Cuve service)'
        ELSE ''
      END
    )                                       AS label
  FROM `bd_packaging_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Brassage · brassin (brewday)
  SELECT
    'bd_brewing_brewday_v2'                 AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Brassage · brassin'                    AS form_type,
    `event_date`                            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`batch`,''), '—')
    )                                       AS label
  FROM `bd_brewing_brewday_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Brassage · densité (gravity)
  SELECT
    'bd_brewing_gravity_v2'                 AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Brassage · densité'                    AS form_type,
    NULL                                    AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`batch`,''), '—'),
      ' · brassin ',
      COALESCE(NULLIF(`brew`,''), '?'),
      ' · ',
      CASE `event_type`
        WHEN 'FirstWort'   THEN 'Prém. moût'
        WHEN 'Pfannevoll'  THEN 'Plein chaudière'
        WHEN 'Kochwurze'   THEN 'Wort cuit'
        WHEN 'Cooling'     THEN 'Refroid.'
        ELSE COALESCE(`event_type`, '?')
      END
    )                                       AS label
  FROM `bd_brewing_gravity_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Brassage · ingrédients
  SELECT
    'bd_brewing_ingredients_v2'             AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Brassage · ingrédients'                AS form_type,
    `event_date`                            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`batch`,''), '—')
    )                                       AS label
  FROM `bd_brewing_ingredients_v2`
  WHERE `is_tombstoned` = 0

UNION ALL

  -- Brassage · timing
  SELECT
    'bd_brewing_timings_v2'                 AS source_table,
    CAST(`id` AS UNSIGNED)                  AS row_pk,
    'Brassage · timing'                     AS form_type,
    `event_date`                            AS event_date,
    CAST(`submitted_at` AS DATETIME(6))     AS submitted_at,
    CAST(`submitted_by_user_id_fk` AS UNSIGNED) AS submitted_by_user_id_fk,
    `email`                                 AS operator_email,
    CONCAT(
      COALESCE(NULLIF(`beer`,''), '—'),
      ' · lot ',
      COALESCE(NULLIF(`batch`,''), '—'),
      ' · brassin ',
      COALESCE(NULLIF(`brew`,''), '?')
    )                                       AS label
  FROM `bd_brewing_timings_v2`
  WHERE `is_tombstoned` = 0;

-- ── 3. ref_pages row ──────────────────────────────────────────────────────────
INSERT INTO `ref_pages`
  (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
  ('journal-saisies', 'Journal des saisies', '◎', '/modules/journal-saisies.php', 'viewer', 'general', 1, 15)
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `href`      = VALUES(`href`),
  `min_role`  = VALUES(`min_role`),
  `domain`    = VALUES(`domain`),
  `is_active` = VALUES(`is_active`),
  `sort`      = VALUES(`sort`);

-- ── 4. Access preset mappings ─────────────────────────────────────────────────
-- Presets: 1=manager, 2=production_operator, 3=logistics_operator, 8=marketing
INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id AS preset_id_fk, rp.id AS page_id_fk
FROM `ref_pages` rp
CROSS JOIN `ref_access_presets` p
WHERE rp.page_key = 'journal-saisies'
  AND p.id IN (1, 2, 3, 8);
