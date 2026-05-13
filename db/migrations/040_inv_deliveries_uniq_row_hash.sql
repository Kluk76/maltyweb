-- Migration 040: Add unique index on inv_deliveries.row_hash
--
-- Prerequisite: 0 duplicate row_hash groups must exist before applying.
-- Verified clean before applying (see session context: ids 257/258 already deleted).
--
-- This makes INSERT IGNORE the correct idempotency primitive for delivery rows
-- created by triage paths (alias/create/manual-lines).

ALTER TABLE inv_deliveries
  ADD UNIQUE KEY uniq_row_hash (row_hash);
