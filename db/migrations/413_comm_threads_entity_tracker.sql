-- =============================================================================
-- Migration 413: Entity Discussion Tracker — comm_threads, comm_messages,
--                comm_message_docs, comm_address_pins
-- CARDINAL RULE — NON-FISCAL: this is a CRM/correspondence layer hanging off
-- ref_suppliers and ref_customers. NOTHING in this migration feeds or derives
-- from COGS, COP, WAC, BOM, beer-tax, stock, or any financial computation.
-- schema_meta: 4 rows (3 source, 1 reference)
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. comm_threads (source/allowed)
--    One row per conversation thread, anchored to either a supplier OR a
--    customer (or NULL = "review bucket" for unresolved threads).
--    gmail_thread_id: nullable, UNIQUE — maps to a Gmail thread when known.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE comm_threads (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id_fk   INT UNSIGNED NULL          COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
  customer_id_fk   INT UNSIGNED NULL          COMMENT 'FK → ref_customers.id (INT UNSIGNED)',
  subject          VARCHAR(998) NOT NULL DEFAULT '',
  gmail_thread_id  VARCHAR(255) NULL          COMMENT 'Gmail thread ID — unique when set; NULL = not yet linked',
  last_message_at  DATETIME NULL,
  is_active        TINYINT NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_threads_supplier FOREIGN KEY (supplier_id_fk) REFERENCES ref_suppliers(id),
  CONSTRAINT fk_comm_threads_customer FOREIGN KEY (customer_id_fk) REFERENCES ref_customers(id),
  CONSTRAINT comm_threads_chk_one_party
    CHECK ( (supplier_id_fk IS NOT NULL AND customer_id_fk IS NULL)
         OR (supplier_id_fk IS NULL     AND customer_id_fk IS NOT NULL)
         OR (supplier_id_fk IS NULL     AND customer_id_fk IS NULL) ),
  UNIQUE KEY uniq_comm_gmail_thread (gmail_thread_id),
  KEY idx_comm_threads_supplier (supplier_id_fk, last_message_at),
  KEY idx_comm_threads_customer (customer_id_fk, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. comm_messages (source/allowed)
--    Individual messages within a thread. message_id is the RFC 2822
--    Message-ID header (unique per message across all sources).
--    gmail_message_id: Gmail-specific ID when source='gmail'.
--    source_email_id_fk: FK → doc_email_messages.id (BIGINT UNSIGNED) when
--    the message was ingested via the email pipeline; SET NULL on delete.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE comm_messages (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id_fk        BIGINT UNSIGNED NOT NULL               COMMENT 'FK → comm_threads.id',
  direction           ENUM('in','out') NOT NULL,
  from_address        VARCHAR(320) NOT NULL DEFAULT '',
  to_address          VARCHAR(998) NOT NULL DEFAULT '',
  cc_address          VARCHAR(998) NULL,
  subject             VARCHAR(998) NOT NULL DEFAULT '',
  body_format         ENUM('text','html') NOT NULL DEFAULT 'text',
  body                MEDIUMTEXT NULL,
  body_snippet        VARCHAR(512) NULL,
  sent_at             DATETIME NOT NULL,
  message_id          VARCHAR(512) NOT NULL                  COMMENT 'RFC 2822 Message-ID; globally unique; UNIQUE enforced',
  gmail_message_id    VARCHAR(255) NULL                      COMMENT 'Gmail message ID (source=gmail only)',
  source              ENUM('gmail','manual') NOT NULL DEFAULT 'gmail',
  source_email_id_fk  BIGINT UNSIGNED NULL                   COMMENT 'FK → doc_email_messages.id (BIGINT UNSIGNED); NULL = manual entry',
  created_by_user_id  INT UNSIGNED NULL                      COMMENT 'Soft ref to users.id',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_messages_thread FOREIGN KEY (thread_id_fk) REFERENCES comm_threads(id),
  CONSTRAINT fk_comm_messages_email  FOREIGN KEY (source_email_id_fk) REFERENCES doc_email_messages(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_comm_message_id (message_id),
  KEY idx_comm_messages_thread_sent (thread_id_fk, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. comm_message_docs (source/allowed)
--    Attachments: links messages to doc_files. Reuses the canonical doc store
--    (doc_files) — NO parallel document storage.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE comm_message_docs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id_fk       BIGINT UNSIGNED NOT NULL               COMMENT 'FK → comm_messages.id',
  doc_file_id_fk      BIGINT UNSIGNED NOT NULL               COMMENT 'FK → doc_files.id (BIGINT UNSIGNED — .id PK, not UUID)',
  attachment_filename VARCHAR(512) NOT NULL DEFAULT '',
  mime_type           VARCHAR(128) NULL,
  direction           ENUM('in','out') NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_docs_message FOREIGN KEY (message_id_fk) REFERENCES comm_messages(id),
  CONSTRAINT fk_comm_docs_file    FOREIGN KEY (doc_file_id_fk) REFERENCES doc_files(id),
  KEY idx_comm_docs_message (message_id_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. comm_address_pins (reference/allowed)
--    Maps known email addresses to a supplier OR customer (exactly one;
--    both-NULL is forbidden here — use the CHECK constraint).
--    UNIQUE on email ensures one canonical pin per address.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE comm_address_pins (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(320) NOT NULL                      COMMENT 'Normalised lowercase email address',
  supplier_id_fk  INT UNSIGNED NULL                          COMMENT 'FK → ref_suppliers.id (INT UNSIGNED)',
  customer_id_fk  INT UNSIGNED NULL                          COMMENT 'FK → ref_customers.id (INT UNSIGNED)',
  created_by_user_id INT UNSIGNED NULL                       COMMENT 'Soft ref to users.id',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comm_pins_supplier FOREIGN KEY (supplier_id_fk) REFERENCES ref_suppliers(id),
  CONSTRAINT fk_comm_pins_customer FOREIGN KEY (customer_id_fk) REFERENCES ref_customers(id),
  CONSTRAINT comm_pins_chk_one_party
    CHECK ( (supplier_id_fk IS NOT NULL AND customer_id_fk IS NULL)
         OR (supplier_id_fk IS NULL     AND customer_id_fk IS NOT NULL) ),
  UNIQUE KEY uniq_comm_pin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. schema_meta — 4 rows (3 source, 1 reference)
--    NON-FISCAL note mandatory on all four — none feeds COGS/COP/WAC/BOM/
--    beer-tax/stock.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES
    ('comm_threads',
     'source', 'allowed',
     NULL,
     NULL,
     'Entity discussion tracker — thread header anchored to ref_suppliers or ref_customers (or NULL review bucket). NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.'),

    ('comm_messages',
     'source', 'allowed',
     NULL,
     'source_email_id_fk → doc_email_messages.id (BIGINT UNSIGNED, the .id PK not the UUID). message_id is the RFC 2822 Message-ID; globally unique.',
     'Individual messages within a comm_thread. direction in/out; source gmail/manual. NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.'),

    ('comm_message_docs',
     'source', 'allowed',
     NULL,
     'doc_file_id_fk → doc_files.id (BIGINT UNSIGNED, the .id PK not the UUID). Reuses canonical doc store — no parallel document storage.',
     'Message attachment links. Joins comm_messages to doc_files. NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.'),

    ('comm_address_pins',
     'reference', 'allowed',
     NULL,
     'UNIQUE on email — one canonical pin per address. CHECK enforces exactly one of supplier_id_fk / customer_id_fk is set (both-NULL forbidden here).',
     'Maps email addresses to a supplier or customer entity. Used by ingest pipeline for auto-thread routing. NON-FISCAL correspondence layer — never feeds COGS/COP/WAC/BOM/beer-tax/stock.');
