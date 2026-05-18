-- Migration 054 — DROP UNIQUE KEY uniq_line_business_key on inv_deliveries
--
-- Date: 2026-05-18
-- Author: Phase G fix A
-- Scope: remove over-strict DB-level UNIQUE constraint; replace with application-layer soft check
--
-- Background: migration 052 added UNIQUE (date_received, supplier_fk, ingredient_fk,
-- qty_delivered) to prevent cross-parser duplicate lines. Phase G re-ingest revealed
-- this constraint is too strict: 32 of 184 invoices (17%) contain at least one
-- legitimate pair of same-shape lines that collide on this tuple. Examples:
--   - Carbagas invoices with 2 × PROC_CO2_TANK_RENTAL qty=1 (two rental periods in
--     the same billing month, one line per period)
--   - OBI invoices with 2 × same office-supply line (e.g. 2 packs of the same item
--     received and invoiced individually)
-- All 32/184 cases represent genuine separate deliveries that should be written as
-- distinct inv_deliveries rows. The DB-level UNIQUE was blocking them silently and
-- preventing correct COGS/WAC computation for those suppliers.
--
-- Memory ref: see [[feedback_dedup_key_delivery_date_supplier_mi_qty]] — the
-- line-business tuple is a heuristic proxy for physical-document identity, not a
-- guaranteed business key. Exact physical identity is captured by
-- uq_dedup_key (file_id_fk:line_index) which remains in place.
--
-- Replaced by: an application-layer soft check in mysql-deliveries.ts
-- (_appendDeliveryCore) that SELECTs for an existing row matching the 4-tuple
-- (date_received, supplier_fk, ingredient_fk, qty_delivered) when all four are
-- non-NULL. A collision emits a console.log informational line but ALWAYS proceeds
-- with the INSERT. The result also carries an optional softDedupMatch field so
-- callers can route to ReviewQueue if desired.
--
-- uq_dedup_key (file_id_fk:line_index, migration 048) is NOT touched — it remains
-- as the authoritative idempotency guard for Invoice-OCR rows with a document anchor.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

-- ── UP ───────────────────────────────────────────────────────────────────────────

ALTER TABLE inv_deliveries
  DROP INDEX uniq_line_business_key;

-- ── DOWN ─────────────────────────────────────────────────────────────────────────

-- ALTER TABLE inv_deliveries
--   ADD UNIQUE KEY uniq_line_business_key (date_received, supplier_fk, ingredient_fk, qty_delivered);
