-- Migration 052 — inv_deliveries UNIQUE (date_received, supplier_fk, ingredient_fk, qty_delivered)
--
-- Date: 2026-05-18
-- Author: pre-reingest QC (Phase G)
-- Scope: prevent cross-parser duplicate delivery lines from being inserted
--
-- Background: the existing uq_dedup_key UNIQUE (migration 048) covers
-- file_id_fk:line_index — identical (file, line-position) pairs. It does NOT
-- catch the case where the same physical delivery line emerges from two
-- different parsers or Drive uploads (e.g. llm-fallback AND the dedicated
-- supplier parser both resolving the same line). This constraint closes
-- that gap at the business-identity level.
--
-- Reference: feedback_dedup_key_delivery_date_supplier_mi_qty.md establishes
-- (date_received, supplier_fk, ingredient_fk, qty_delivered) as the canonical
-- line-business-identity tuple.
--
-- Complements existing uq_dedup_key (file_id_fk:line_index) UNIQUE.
--
-- NULL behaviour: MySQL UNIQUE allows multiple NULL combinations, so legacy
-- bsf-mirror rows (which may have NULL supplier_fk or ingredient_fk) will
-- not collide with each other. This is the intended behaviour — the guard
-- targets fully-resolved parser output, not partial bsf-mirror imports.
--
-- Caller (mysql-deliveries.ts) already does a SELECT FOR UPDATE before
-- INSERT; an IntegrityError here is a genuine duplicate and should be
-- handled as a graceful skip.
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'

-- ── UP ───────────────────────────────────────────────────────────────────────────

ALTER TABLE inv_deliveries
  ADD UNIQUE KEY uniq_line_business_key (date_received, supplier_fk, ingredient_fk, qty_delivered);

-- ── DOWN ─────────────────────────────────────────────────────────────────────────

-- ALTER TABLE inv_deliveries DROP INDEX uniq_line_business_key;
