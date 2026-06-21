-- 421_doc_email_original_sender.sql
-- Add original_sender column to capture real external sender from via-Commandes rewrites.
-- X-Original-Sender / X-Original-From / Reply-To headers carry the true sender address
-- when Google Groups rewrites From: to "'Name' via Commandes <commandes@lanebuleuse.ch>".
ALTER TABLE doc_email_messages
    ADD COLUMN original_sender VARCHAR(320) NULL AFTER from_address;
