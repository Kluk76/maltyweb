-- db/migrations/291_tap_shop_page.sql
-- What: Add 'Tap&Shop' page to ref_pages + ref_access_preset_pages.
--
--   1. INSERT ref_pages row (page_key='tap-shop', domain='logistics',
--      min_role='manager', sort=105, icon='10', is_active=1)
--      via INSERT … ON DUPLICATE KEY UPDATE (idempotent).
--   2. INSERT ref_access_preset_pages rows for presets 1 (manager),
--      3 (logistics_operator), 8 (marketing) — mirrors expeditions.
--      All via INSERT IGNORE to be idempotent.
--
-- Rollback:
--   DELETE FROM ref_access_preset_pages
--     WHERE page_id_fk = (SELECT id FROM ref_pages WHERE page_key='tap-shop');
--   DELETE FROM ref_pages WHERE page_key='tap-shop';
--
-- Applied via: ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'
-- ============================================================================

-- ── 1. ref_pages row ─────────────────────────────────────────────────────────
INSERT INTO `ref_pages`
  (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
  ('tap-shop', 'Tap&Shop', '10', '/modules/tap-shop.php', 'manager', 'logistics', 1, 105)
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `href`      = VALUES(`href`),
  `min_role`  = VALUES(`min_role`),
  `domain`    = VALUES(`domain`),
  `is_active` = VALUES(`is_active`),
  `sort`      = VALUES(`sort`);

-- ── 2. Access preset mappings ────────────────────────────────────────────────
-- Preset 1 = manager, 3 = logistics_operator, 8 = marketing
INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT p.id AS preset_id_fk, rp.id AS page_id_fk
FROM `ref_pages` rp
CROSS JOIN `ref_access_presets` p
WHERE rp.page_key = 'tap-shop'
  AND p.id IN (1, 3, 8);
