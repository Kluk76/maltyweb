-- =============================================================================
-- Migration 429: Entity Discussion Tracker — email→entity registry + purge
--                bookkeeping
-- CARDINAL RULE — NON-FISCAL: this is a CRM/correspondence capture-gate layer.
-- NOTHING in this migration feeds or derives from COGS, COP, WAC, BOM,
-- beer-tax, stock, or any financial computation.
-- schema_meta: 2 rows (1 reference, 1 source)
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1a. ref_entity_email_domains (reference/allowed)
--     Polymorphic XOR registry: each row maps a domain or full email address
--     to exactly one entity (supplier OR customer — both-NULL is forbidden,
--     both-set is forbidden). Normalised match_value stored lowercase.
--     is_shared=1 marks consumer/shared domains (gmail, bluewin, …): these
--     rows capture a match_value for logging but NEVER auto-resolve the FK.
--     backfilled_at=NULL means the entity's historical threads still need
--     retrospective assignment.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE ref_entity_email_domains (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id_fk   INT UNSIGNED NULL     COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
  customer_id_fk   INT UNSIGNED NULL     COMMENT 'FK → ref_customers.id (INT UNSIGNED)',
  match_type       ENUM('domain','address') NOT NULL DEFAULT 'domain',
  match_value      VARCHAR(320) NOT NULL              COMMENT 'Bare lowercase domain (no @) when match_type=domain; full lowercase email when match_type=address',
  source           ENUM('validated','bc-vendor','manual') NOT NULL DEFAULT 'manual',
  is_shared        TINYINT NOT NULL DEFAULT 0         COMMENT '1 = consumer/shared domain (gmail/bluewin/…): capture-allow but NEVER auto-resolve FK',
  is_active        TINYINT NOT NULL DEFAULT 1,
  backfilled_at    DATETIME NULL                      COMMENT 'Retroactive-backfill watermark; NULL = entity historical threads still need backfill',
  notes            VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_hash         CHAR(64) NULL,
  CONSTRAINT fk_eed_supplier FOREIGN KEY (supplier_id_fk) REFERENCES ref_suppliers(id),
  CONSTRAINT fk_eed_customer FOREIGN KEY (customer_id_fk) REFERENCES ref_customers(id),
  CONSTRAINT eed_chk_one_party
    CHECK ( (supplier_id_fk IS NOT NULL AND customer_id_fk IS NULL)
         OR (supplier_id_fk IS NULL     AND customer_id_fk IS NOT NULL) ),
  UNIQUE KEY uniq_eed_match_value (match_value),
  KEY idx_eed_supplier (supplier_id_fk),
  KEY idx_eed_customer (customer_id_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1b. purge_status columns on existing comm tables
--     comm_threads: purge_status + purge_reason are ORTHOGONAL to is_active.
--       - 'live'             = normal operating row
--       - 'soft_purged'      = hidden from UI, data retained
--       - 'migrated_customer'= thread re-attributed to a customer entity
--     comm_messages: purge_status only (no reason column — message-level
--       purge is a binary decision with context captured at thread level).
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE comm_threads
  ADD COLUMN `purge_status` ENUM('live','soft_purged','migrated_customer') NOT NULL DEFAULT 'live'
    COMMENT 'Purge/migration state — orthogonal to is_active; never modify is_active via purge logic' AFTER `is_active`,
  ADD COLUMN `purge_reason` VARCHAR(255) NULL
    COMMENT 'Human-readable reason for soft_purge or migration; NULL when purge_status=live' AFTER `purge_status`;

ALTER TABLE comm_messages
  ADD COLUMN `purge_status` ENUM('live','soft_purged') NOT NULL DEFAULT 'live'
    COMMENT 'Purge state for individual messages; context captured at thread level' AFTER `updated_at`;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1c. comm_unknown_domain_seen (source/allowed)
--     Counts-only log for email domains that could not be resolved to any
--     entity in ref_entity_email_domains. NO body, subject, or attachment
--     content stored — only domain-level metadata plus ONE sample address.
--     is_dismissed=1 means operator has reviewed and chosen to leave
--     this domain unregistered (suppresses UI alert).
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE comm_unknown_domain_seen (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  domain          VARCHAR(255) NOT NULL              COMMENT 'Normalised lowercase domain observed in unresolved email traffic',
  sample_address  VARCHAR(320) NULL                  COMMENT 'ONE representative example address — the only content concession; no body/subject/attachment stored',
  first_seen_at   DATETIME NOT NULL,
  last_seen_at    DATETIME NOT NULL,
  hit_count       INT UNSIGNED NOT NULL DEFAULT 0,
  is_dismissed    TINYINT NOT NULL DEFAULT 0         COMMENT '1 = operator reviewed; suppresses UI alert without deleting the row',
  UNIQUE KEY uniq_cuds_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1d. schema_meta rows
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES
    ('ref_entity_email_domains',
     'reference', 'allowed',
     'seed_entity_email_domains.py + sync_bc_vendors.py (daily) + manual',
     'supplier_id_fk → ref_suppliers.id (INT UNSIGNED); customer_id_fk → ref_customers.id (INT UNSIGNED). UNIQUE on match_value. CHECK eed_chk_one_party enforces exactly-one-party (both-NULL and both-set both forbidden). is_shared=1 rows never auto-resolve FK.',
     'Email→entity capture-gate registry. Maps bare domains (match_type=domain) or full addresses (match_type=address) to exactly one supplier or customer. Source values: validated (from comm_address_pins), bc-vendor (from BC sync), manual. NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.'),

    ('comm_unknown_domain_seen',
     'source', 'allowed',
     'ingest_email_comm.py',
     'UNIQUE on domain. No message content stored — domain-level counts + one sample_address only. hit_count incremented on each sighting via ON DUPLICATE KEY UPDATE.',
     'Counts-only log of email domains unresolved against ref_entity_email_domains. Operator reviews via Le Cockpit to promote domains into the registry or dismiss. NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.');
