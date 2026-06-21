-- 427_ref_suppliers_bc_vendor_fields.sql
-- Add BC-vendor enrichment columns to ref_suppliers.
-- Applied by: scripts/migrate.php
-- No schema_meta row needed (column adds, no new table).
--
-- Field ownership contract:
--   BC-OWNED   : bc_vendor_no, email, phone, address_line1/2, postal_code,
--                city, bc_last_synced_at, country (existing column, COALESCE-only).
--   CURATED    : name, gl_account, category, currency, vat_regime,
--                hors_perimetre_cogs, commissioning_state, criticality,
--                parser_key, notes, supplier_id, sporadique, row_hash.
--   (Curated fields are NEVER overwritten by sync_bc_vendors.py.)
--
-- Note: ref_suppliers already has a `country CHAR(2) NULL` column.
-- No country column is added here. The sync writes to the existing `country`
-- column via COALESCE (only fills if currently NULL).

ALTER TABLE ref_suppliers
  ADD COLUMN `bc_vendor_no`      VARCHAR(40)  NULL COMMENT 'BC/ERP vendor number (e.g. FOURN-000001); NULL = not yet linked to BC' AFTER `commissioning_state`,
  ADD COLUMN `email`             VARCHAR(320) NULL COMMENT 'Primary contact email (BC-owned; sync_bc_vendors.py writes this)' AFTER `bc_vendor_no`,
  ADD COLUMN `phone`             VARCHAR(64)  NULL COMMENT 'Primary phone number (BC-owned; sync_bc_vendors.py writes this)' AFTER `email`,
  ADD COLUMN `address_line1`     VARCHAR(255) NULL COMMENT 'Street address line 1 (BC-owned)' AFTER `phone`,
  ADD COLUMN `address_line2`     VARCHAR(255) NULL COMMENT 'Street address line 2 (BC-owned)' AFTER `address_line1`,
  ADD COLUMN `postal_code`       VARCHAR(32)  NULL COMMENT 'Postal code (BC-owned)' AFTER `address_line2`,
  ADD COLUMN `city`              VARCHAR(128) NULL COMMENT 'City (BC-owned)' AFTER `postal_code`,
  ADD COLUMN `bc_last_synced_at` DATETIME     NULL COMMENT 'UTC timestamp of last successful BC-vendor sync; NULL = never synced' AFTER `city`;

-- UNIQUE on bc_vendor_no: NULLs are permitted (multiple unlinked suppliers),
-- duplicates on non-NULL values are blocked (bijection once linked).
ALTER TABLE ref_suppliers
  ADD UNIQUE KEY `uq_bc_vendor_no` (`bc_vendor_no`);
