-- Migration 367 — grant Planning page access to operator/manager presets
--
-- The Planning page (ref_pages.page_key='planning', added in mig 364) is a new
-- cross-cutting surface. Onboarded users are governed by access PRESETS: the
-- role-floor in user_can_access() only applies to preset-less users (admins),
-- so a brand-new page is invisible to everyone with a preset until it is
-- explicitly added to that preset's page list.
--
-- Grant per the agreed scope:
--   manager (1)             — managers (write; the section write-gate is enforced
--                             separately in planning.php via manager_can()).
--   production_operator (2) — production floor, read-only.
--   logistics_operator (3)  — logistics floor, read-only.
-- marketing / sales_manager are intentionally NOT granted (separate sales domain).
--
-- Idempotent: uniq_preset_page (preset_id_fk, page_id_fk) + INSERT IGNORE.
-- INSERT ... SELECT (no open result set) — safe for migrate.php exec().

INSERT IGNORE INTO `ref_access_preset_pages` (`preset_id_fk`, `page_id_fk`)
SELECT ap.`id`, rp.`id`
  FROM `ref_access_presets` ap
  JOIN `ref_pages` rp ON rp.`page_key` = 'planning'
 WHERE ap.`preset_key` IN ('manager', 'production_operator', 'logistics_operator');
