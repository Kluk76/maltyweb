-- db/migrations/304_financier_page.sql
-- What: Register the Financier page in ref_pages (manager+, sort=125, domain=general)
--       and add it to the manager access preset (preset_id=1).
--
--   1. INSERT ref_pages row (page_key='financier', domain='general',
--      min_role='manager', sort=125, icon='₣', is_active=1)
--      via INSERT … ON DUPLICATE KEY UPDATE (idempotent).
--
--   2. INSERT ref_access_preset_pages for preset_id=1 (manager).
--      INSERT IGNORE — idempotent.
--
-- Rollback:
--   DELETE FROM ref_access_preset_pages
--     WHERE page_id_fk = (SELECT id FROM ref_pages WHERE page_key='financier');
--   DELETE FROM ref_pages WHERE page_key='financier';
--
-- Applied via: ssh ubuntu@83.228.215.243 'sudo php /var/www/maltytask/scripts/migrate.php'
-- ============================================================================

-- ── 1. ref_pages row ─────────────────────────────────────────────────────────
INSERT INTO `ref_pages`
  (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
  ('financier', 'Financier', '₣', '/modules/financier.php', 'manager', 'general', 1, 125)
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `href`      = VALUES(`href`),
  `min_role`  = VALUES(`min_role`),
  `domain`    = VALUES(`domain`),
  `is_active` = VALUES(`is_active`),
  `sort`      = VALUES(`sort`);

-- ── 2. Access preset — manager only (preset_id=1) ───────────────────────────
-- Manager preset gets Financier. Other presets (production_operator, logistics_operator,
-- marketing) do NOT get this page — fiscal data is manager-only.
INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id AS preset_id_fk, rp.id AS page_id_fk
FROM `ref_pages` rp
CROSS JOIN `ref_access_presets` p
WHERE rp.page_key = 'financier'
  AND p.id IN (1);
