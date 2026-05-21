-- 059_inv_consumption_row_hash_unique.sql
--
-- Adds UNIQUE constraint on inv_consumption.row_hash to make backfill scripts
-- truly idempotent. The original uk_dedup(mi_id_fk, consumed_at, source_event,
-- source_row_id, qty) allows multiple NULL source_row_id rows per MySQL spec,
-- defeating INSERT IGNORE on the backfill path where source_row_id is always NULL.
--
-- row_hash is computed deterministically from canonical fields by the writer
-- scripts (SHA-256 of mi_id_fk|consumed_at|qty_rounded|source_event|source_row_id_or_null).
-- A second run with the same input produces the same hash → INSERT IGNORE fires.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && sudo -u www-data php scripts/migrate.php'

ALTER TABLE inv_consumption
  ADD UNIQUE KEY uk_row_hash (row_hash);
