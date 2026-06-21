-- 423_doc_email_bc_matched_reconciled.sql
-- Adds bc_matched_order_id FK + 'reconciled' parse_status value for Slice 3a BC dedup gate.

ALTER TABLE doc_email_messages
  ADD COLUMN bc_matched_order_id BIGINT UNSIGNED NULL AFTER parse_error,
  ADD CONSTRAINT fk_doc_email_bc_order
    FOREIGN KEY (bc_matched_order_id) REFERENCES ord_orders(id) ON DELETE SET NULL;

ALTER TABLE doc_email_messages
  MODIFY COLUMN parse_status
    ENUM('unparsed','parsed','no_match','error','order_created','reconciled')
    NOT NULL DEFAULT 'unparsed';
