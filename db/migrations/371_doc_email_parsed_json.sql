-- Migration 371: doc_email_messages — add parsed_json column + extend parse_status ENUM
-- Author  : email-ingest arc (Model B ratification)
-- Purpose : (1) Move ParsedOrder hints out of the parse_error hack into a proper
--               parsed_json JSON column.  parse_error is now exclusively for real
--               error messages (parse_status='error').
--           (2) Add terminal 'order_created' parse_status value that the future
--               logistics-validation UI sets when it promotes a candidate to an
--               ord_orders row.  This prevents re-promotion and filters the queue.
--
-- Idempotency: schema_migrations INSERT at the bottom prevents re-application.
-- MySQL 8 strict: no ADD COLUMN IF NOT EXISTS, no ADD KEY IF NOT EXISTS.
-- No bare SELECT statements (migrate.php uses PDO::exec which chokes on result sets).

-- ── (1) Add parsed_json column ────────────────────────────────────────────────
-- Stores the serialised ParsedOrder hints (customer_hint, lines, requested_date,
-- notes) when parse_status='parsed'.  NULL otherwise.  JSON type so the logistics
-- UI can extract fields without string parsing.
ALTER TABLE `doc_email_messages`
    ADD COLUMN `parsed_json` JSON NULL
        COMMENT 'Serialised ParsedOrder hints (customer_hint, lines, requested_date, notes) when parse_status=''parsed''. NULL for all other statuses. Populated by ingest_email_orders.py; consumed by logistics validation UI.'
        AFTER `parse_error`;

-- ── (2) Extend parse_status ENUM ─────────────────────────────────────────────
-- Adds terminal 'order_created' value.  Existing rows all carry valid values
-- (unparsed / parsed / no_match / error) and are unaffected.
-- DEFAULT and NOT NULL are preserved.
ALTER TABLE `doc_email_messages`
    MODIFY COLUMN `parse_status`
        ENUM('unparsed','parsed','no_match','error','order_created')
        NOT NULL
        DEFAULT 'unparsed'
        COMMENT 'Parse lifecycle: unparsed=not yet dispatched; parsed=ParsedOrder produced (see parsed_json); no_match=no sender parser fired; error=parser raised; order_created=logistics validation promoted to ord_orders (terminal, prevents re-promotion).';

-- ── Record migration ──────────────────────────────────────────────────────────
INSERT INTO schema_migrations (filename, applied_at)
VALUES ('371_doc_email_parsed_json.sql', NOW());
