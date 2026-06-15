-- Migration 372 — Email-order validation page: ref_pages row + preset access grants
--
-- Creates the Commandes e-mail page and grants access to manager + logistics_operator
-- presets in ONE migration so the page is never live-but-invisible.
--
-- Grant scope:
--   manager (1)             — full read+write (validates and promotes to ord_orders)
--   logistics_operator (3)  — full read+write (primary user role for this workflow)
--   production_operator (2) — NOT granted (logistics-domain page)
--   marketing / sales_manager — NOT granted
--
-- Idempotent: INSERT IGNORE throughout (uniq_page_key, uniq_preset_page).
-- INSERT ... SELECT (no open result set) — safe for migrate.php exec().
-- MySQL 8: no ADD COLUMN IF NOT EXISTS, no bare SELECT.

-- ── 1. ref_pages row ─────────────────────────────────────────────────────────

INSERT IGNORE INTO `ref_pages`
    (`page_key`, `label`, `icon`, `href`, `min_role`, `domain`, `is_active`, `sort`)
VALUES
    ('email-orders', 'Commandes e-mail', '📧', '/modules/email-orders.php', 'operator', 'logistics', 1, 26);

-- ── 2. Preset grants ──────────────────────────────────────────────────────────

INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT ap.`id`, rp.`id`
  FROM `ref_access_presets` ap
  JOIN `ref_pages` rp ON rp.`page_key` = 'email-orders'
 WHERE ap.`preset_key` IN ('manager', 'logistics_operator');
