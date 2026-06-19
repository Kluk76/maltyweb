-- =============================================================================
-- Migration 418: Internal Order Accounts — @lanebuleuse.ch sender → BC customer
-- Tables: ref_internal_order_accounts
-- Seed: 6 verified @lanebuleuse.ch sender → ref_customers.id mappings
-- schema_meta: 1 row (reference/allowed)
-- Purpose: maps internal @lanebuleuse.ch sender emails to their own BC customer
--   account so the email-orders review card can pre-fill the customer typeahead
--   when the sender IS the customer (internal sales-rep self-ordering).
--   Triggered when parsed_json._internal_rep=true + customer_hint=''.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. ref_internal_order_accounts (reference/allowed)
--    One row per @lanebuleuse.ch sender email that maps to their own BC account.
--    Lookup: sender_email (lowercase) → customer_id_fk.
--    is_active=0 disables the pre-fill without deleting the row.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE ref_internal_order_accounts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_email    VARCHAR(190) NOT NULL                   COMMENT 'Lowercase @lanebuleuse.ch address',
    customer_id_fk  INT UNSIGNED NOT NULL                   COMMENT 'FK → ref_customers.id (INT UNSIGNED)',
    is_active       TINYINT(1) NOT NULL DEFAULT 1           COMMENT '0 = disabled pre-fill; row retained for audit',
    notes           VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_rioa_email (sender_email),
    CONSTRAINT fk_rioa_customer FOREIGN KEY (customer_id_fk)
        REFERENCES ref_customers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Seed — 6 verified @lanebuleuse.ch sender → ref_customers.id mappings
--    Verified against live ref_customers before migration authoring.
--    dorian@  → 3050  Dorian Fairhall
--    john@    → 2377  John Penman
--    louis.cardis@ → 895   Louis Cardis
--    nicolas.berger@ → 1164  Nicolas Berger
--    nicolas.cerruela@ → 2233  Nicolas Cerruela
--    tania@   → 2379  Tania Schott
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO ref_internal_order_accounts (sender_email, customer_id_fk, notes)
VALUES
    ('dorian@lanebuleuse.ch',            3050, 'Dorian Fairhall'),
    ('john@lanebuleuse.ch',              2377, 'John Penman'),
    ('louis.cardis@lanebuleuse.ch',       895, 'Louis Cardis'),
    ('nicolas.berger@lanebuleuse.ch',    1164, 'Nicolas Berger'),
    ('nicolas.cerruela@lanebuleuse.ch',  2233, 'Nicolas Cerruela'),
    ('tania@lanebuleuse.ch',             2379, 'Tania Schott');

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. schema_meta — classification row
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES (
    'ref_internal_order_accounts',
    'reference',
    'allowed',
    'admin/manual',
    'Admin-managed via Données générales or direct MySQL; maps internal @lanebuleuse.ch sender emails to their own BC customer account for order pre-fill.',
    'Lookup table for email-orders internal-rep pre-fill. sender_email (UNIQUE, lowercase) → customer_id_fk. is_active=0 disables without deleting.'
);
