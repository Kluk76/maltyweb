-- 370_ord_email_ingestion.sql
-- Raw email ingestion lane for commandes@lanebuleuse.ch.
-- doc_email_messages: append-only raw lane keyed on RFC822 Message-ID (idempotency key).
-- Deterministic per-sender parsers write parsed orders directly into ord_orders/ord_order_lines.
-- Validation-first: parsed orders land with review_status='pending' (existing column on ord_orders).
-- NOTE: ord_orders.source already contains 'email' (added in original ord_orders migration).
--       No ENUM ALTER required; confirmed via SHOW COLUMNS 2026-06-15.


-- 1. Raw email message store
CREATE TABLE IF NOT EXISTS `doc_email_messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`       VARCHAR(512)    NOT NULL COMMENT 'RFC822 Message-ID; idempotency key',
  `from_address`     VARCHAR(320)    NULL,
  `to_address`       VARCHAR(320)    NULL,
  `subject`          VARCHAR(998)    NULL,
  `received_at`      DATETIME        NULL,
  `body_format`      ENUM('text','html') NOT NULL DEFAULT 'text',
  `raw_body`         MEDIUMTEXT      NULL,
  `attachments_json` JSON            NULL,
  `parser_matched`   VARCHAR(120)    NULL COMMENT 'Sender-parser that fired; NULL = none matched',
  `parse_status`     ENUM('unparsed','parsed','no_match','error') NOT NULL DEFAULT 'unparsed',
  `parse_error`      TEXT            NULL COMMENT 'Surfaced to operator UI on parse_status=error',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_message_id` (`message_id`),
  KEY `idx_doc_email_parse_status` (`parse_status`),
  KEY `idx_doc_email_from_address` (`from_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Raw append-only lane for inbound email orders (commandes@lanebuleuse.ch). Idempotency via message_id UNIQUE.';


-- 2. FK column on ord_orders: tie a parsed order back to the email that produced it
ALTER TABLE `ord_orders`
  ADD COLUMN `source_email_id_fk` BIGINT UNSIGNED NULL
    COMMENT 'FK→doc_email_messages(id); set when source=email'
    AFTER `source_file_id_fk`,
  ADD CONSTRAINT `fk_ord_orders_email`
    FOREIGN KEY (`source_email_id_fk`)
    REFERENCES `doc_email_messages` (`id`)
    ON DELETE SET NULL;


-- 3. schema_meta row for doc_email_messages
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
    'doc_email_messages',
    'source',
    'allowed',
    'email-ingest (TBD)',
    'Raw RFC822 email lane. Append-only; corrections_policy=allowed for status/error backfill only. Re-pull email from IMAP to re-ingest.'
)
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
