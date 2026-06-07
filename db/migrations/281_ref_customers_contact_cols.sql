-- db/migrations/281_ref_customers_contact_cols.sql
-- What: Extend ref_customers for CRM-primary bootstrap
--
--   1. DROP UNIQUE index on name — real identity is bc_customer_no; CRM has
--      legitimate duplicate trade names (e.g. two 'Lightspeed Commerce CH SA'
--      entries with different BC numbers). Replace with a plain KEY.
--   2. ADD contact/address columns: email, address_line1, address_line2,
--      postal_code, city, canton, country_code — sourced from CRM export.
--   3. INSERT ref_transporters row 'Transport Express' — confirmed gap:
--      17 sheet-comment mentions; sort_order=30 (after Galliker=10, Loxya=20).
--
-- Risk: Additive column additions + index change only. No existing row data
--       altered. ref_customers is empty at time of migration (bootstrap runs
--       after this migration).
-- Rollback:
--   ALTER TABLE ref_customers
--     DROP KEY `key_ref_customers_name`,
--     ADD UNIQUE KEY `uq_ref_customers_name` (`name`),
--     DROP COLUMN `email`,
--     DROP COLUMN `address_line1`,
--     DROP COLUMN `address_line2`,
--     DROP COLUMN `postal_code`,
--     DROP COLUMN `city`,
--     DROP COLUMN `canton`,
--     DROP COLUMN `country_code`;
--   DELETE FROM ref_transporters WHERE name = 'Transport Express';
-- Applied via: ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'
-- ============================================================================

-- ── 1. Relax UNIQUE on name → plain KEY ──────────────────────────────────────
ALTER TABLE `ref_customers`
  DROP INDEX `uq_ref_customers_name`,
  ADD KEY `key_ref_customers_name` (`name`);

-- ── 2. Add contact/address columns (after `notes`) ───────────────────────────
ALTER TABLE `ref_customers`
  ADD COLUMN `email`         VARCHAR(255) NULL COMMENT 'Primary contact email(s); multiple separated by ; (lowercased)' AFTER `notes`,
  ADD COLUMN `address_line1` VARCHAR(160) NULL COMMENT 'Street address line 1 (from CRM)' AFTER `email`,
  ADD COLUMN `address_line2` VARCHAR(160) NULL COMMENT 'Street address line 2 (from CRM)' AFTER `address_line1`,
  ADD COLUMN `postal_code`   VARCHAR(12)  NULL COMMENT 'Postal / ZIP code' AFTER `address_line2`,
  ADD COLUMN `city`          VARCHAR(96)  NULL COMMENT 'City' AFTER `postal_code`,
  ADD COLUMN `canton`        VARCHAR(16)  NULL COMMENT 'Swiss canton code (e.g. VD, GE) or region' AFTER `city`,
  ADD COLUMN `country_code`  CHAR(2)      NULL COMMENT 'ISO 3166-1 alpha-2 country code' AFTER `canton`;

-- ── 3. Insert Transport Express transporter ───────────────────────────────────
-- 17 sheet-comment mentions confirmed in weeklyorders-clients-raw.json analysis.
INSERT INTO `ref_transporters` (`name`, `is_active`, `sort_order`)
VALUES ('Transport Express', 1, 30);
