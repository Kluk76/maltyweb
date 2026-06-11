-- 324_sales_manager_preset.sql
-- Purpose: Create the `sales_manager` access preset for La Nébuleuse's Head of Sales
--          (Louis Maechler). Manager-floor, logistics-scoped, no production, no admin.
--
-- Pages granted (8):
--   mon-tableau       (viewer)   — universal landing; omitting it locks the user out
--   financier         (manager)  — P&L / financial overview
--   settings          (manager)  — Paramètres (read + limited edits under manager ACL)
--   approvisionnement (operator) — supply/receiving (sales needs inbound visibility)
--   expeditions       (operator) — fulfilment / outbound logistics
--   tap-shop          (operator) — direct sales / Tap & Shop
--   warehouse         (operator) — stock PF view
--   rm-comparison     (operator) — RM comparison (logistics context)
--
-- Admin trio DELIBERATELY EXCLUDED:
--   charges-bc   — require_admin()-gated, un-grantable to a manager role
--   ingest       — require_admin()-gated, un-grantable to a manager role
--   db-browser   — require_admin()-gated, un-grantable to a manager role
--
-- Production pages DELIBERATELY EXCLUDED (operator decision):
--   zeppelin, wort, fermentation, packaging, qa (the production-domain ref_pages rows).
--   Sales has no production mandate; read-only / no-production policy accepted 2026-06-11.
--
-- Intended user configuration for Louis Maechler:
--   role                = 'manager'
--   manager_scope       = 'logistics'
--   access_preset_id_fk = <id of this preset>
--   (Fully settable from the UI: Paramètres → Utilisateurs → create/edit user form.)
--
-- NOTE: this file contains the substring "ref_pages" inside the INSERT…SELECT that
-- resolves page_id_fk via a JOIN on ref_pages. migrate.php's post-apply tour-gap
-- advisory scans for "ref_pages" in migration files to detect new page additions.
-- This is a FALSE POSITIVE — no ref_pages row is added, altered, or activated here.
-- Ignore the advisory if it fires.
--
-- Idempotency (MySQL 8, no "IF NOT EXISTS" on inserts):
--   * ref_access_presets.preset_key UNIQUE → ON DUPLICATE KEY UPDATE (row-alias form;
--     VALUES() deprecated since 8.0.20).
--   * ref_access_preset_pages UNIQUE(preset_id_fk, page_id_fk) → ON DUPLICATE KEY
--     UPDATE no-ops (col = its own stored value).
-- No schema_meta INSERT needed: ref_access_presets / ref_access_preset_pages are
-- already classified in migration 267.

INSERT INTO ref_access_presets (preset_key, label, description)
VALUES ('sales_manager', 'Responsable des ventes', 'Ventes & marketing — finance + logistique (écriture) + Paramètres; pas de production') AS new_preset
ON DUPLICATE KEY UPDATE label = new_preset.label, description = new_preset.description;

INSERT INTO ref_access_preset_pages (preset_id_fk, page_id_fk)
SELECT (SELECT id FROM ref_access_presets WHERE preset_key = 'sales_manager'), p.id
  FROM ref_pages p
 WHERE p.page_key IN (
   'mon-tableau',
   'financier',
   'settings',
   'approvisionnement',
   'expeditions',
   'tap-shop',
   'warehouse',
   'rm-comparison'
 )
ON DUPLICATE KEY UPDATE page_id_fk = ref_access_preset_pages.page_id_fk;
