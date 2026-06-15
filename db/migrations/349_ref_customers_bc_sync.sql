-- 349_ref_customers_bc_sync.sql
--
-- Adds BC-sync support columns to ref_customers and extends doc_review_queue.type
-- with 'bc-customer-identity-drift' for the new customer-master sync connector.
--
-- Field-ownership contract (coded in sync_bc_customers.py):
--   BC-OWNED (sync may overwrite):    address_line1, address_line2, postal_code,
--                                     city, country_code, phone, email, is_active
--                                     (blocked→is_active), bc_last_synced_at.
--   MALTYTASK-CURATED (never touched): name, trade_channel, sale_class, is_private,
--                                     needs_review, notes, bc_customer_no (except via
--                                     the strict DRIFT-AUTO reconcile path).
--
-- Changes:
--   1. Add `phone` VARCHAR(32) — BC phoneNumber field; absent from current DDL.
--   2. Add `bc_last_synced_at` DATETIME NULL — timestamp of last successful BC sync
--      for this row; NULL = never synced / maltytask-only row.
--   3. Extend doc_review_queue.type ENUM with 'bc-customer-identity-drift'.
--   4. schema_meta: record the dual-writer contract on ref_customers.
--
-- Migration conventions (MySQL 8 — no IF NOT EXISTS on ALTER TABLE column,
-- no SELECT statements):

ALTER TABLE `ref_customers`
    ADD COLUMN `phone` VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Primary phone number (BC-owned; sync_bc_customers.py writes this)'
        AFTER `email`,
    ADD COLUMN `bc_last_synced_at` DATETIME DEFAULT NULL
        COMMENT 'UTC timestamp of last successful BC-customer sync for this row; NULL = never synced'
        AFTER `updated_at`;

-- Extend doc_review_queue.type ENUM.
-- MySQL requires listing ALL current values when modifying an ENUM.
-- Current ENUM as of mig 337 (repack-unresolved-bundle was the last addition):
ALTER TABLE `doc_review_queue`
    MODIFY COLUMN `type` ENUM(
        'supplier-unknown',
        'ingredient-unknown',
        'gl-drift',
        'archive-candidate',
        'inactive-candidate',
        'dynamic-vs-take-drift',
        'rm-stale',
        'rm-negative',
        'rm-orphan-mi',
        'invoice-no-dn',
        'dn-no-invoice',
        'photonote-audit',
        'sales-sku-unknown',
        'doc-classify-ambiguous',
        'invoice-line-items-needed',
        'dn-invoice-duplicate',
        'dn-low-confidence-line',
        'sku-bom-unresolved',
        'garde_seuil_overdue',
        'contamination_flagged',
        'mother_abandoned',
        'packaged_volume_anomaly',
        'repack-unresolved-bundle',
        'bc-customer-identity-drift'
    ) COLLATE utf8mb4_unicode_ci NOT NULL;

-- schema_meta: record the dual-writer BC-owned / curated contract.
INSERT INTO `schema_meta`
    (table_name, table_class, corrections_policy, notes)
VALUES
    ('ref_customers',
     'reference',
     'allowed_with_side_effect',
     'Dual-writer contract (mig 349): BC-owned cols (address_line1/2, postal_code, city, country_code, phone, email, is_active, bc_last_synced_at) are overwritten by sync_bc_customers.py on each daily sync. Curated cols (name, trade_channel, sale_class, is_private, needs_review, notes) are NEVER touched by the sync — operator curation wins. bc_customer_no is only corrected via the strict DRIFT-AUTO path (phantom-absence + name+city exact match). Tombstones: is_active=0 + notes contains merged_into: {id} written by expeditions.php client_merge action. Dead stubs must not be resurrected by the sync.')
ON DUPLICATE KEY UPDATE
    notes      = VALUES(notes),
    updated_at = NOW();
