-- 050_doc_invoice_lines_accounting_class.sql
--
-- Date: 2026-05-18
-- Scope: doc_invoice_lines — line-level accounting classification
-- Rationale: parsers emit an `accounting` field (recoverable_input_vat,
--   immobilisation, taproom_opex, etc.) that classifies lines excluded from
--   normal COGS flow. This column persists that signal so downstream consumers
--   (WAC, depletion, COGS report) can filter without re-parsing OCR text.
--
-- Column placement: after `pack_converted`, before `gate_failures` — natural
--   locality with other line-classification fields.
--
-- NULL = normal COGS line (the common case; no default overhead on existing rows).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'
--
-- Enum values:
--   recoverable_input_vat     — import-VAT pass-through (forwarders like PESA/Dachser);
--                               not a COGS cost; supersedes legacy 'recoverable_vat'
--   immobilisation            — CAPEX (printing plates, clichés, equipment); routes to
--                               fixed-asset account, never a Deliveries row
--   taproom_opex              — taproom/bar operations; excluded from production COGS
--   office_opex               — back-office/admin/IT share; excluded from production COGS
--   eco_tax_glass             — Swiss TEA eco-tax on glass bottles; own MI PKG_TEA_BOT_CH
--                               (GL 4201); stored here for audit, IS a COGS row
--   excluded_taproom_or_maint — catch-all exclusion for taproom or maintenance items
--                               that don't fit a narrower category
--   diluted_into_freight      — unit-incidental cost folded into the freight MI via
--                               weighted-average (e.g. RPLP, admin fees diluted into
--                               TRANS_FREIGHT_INBOUND); never a standalone Deliveries row

-- ── UP ───────────────────────────────────────────────────────────────────────────

ALTER TABLE doc_invoice_lines
  ADD COLUMN accounting_class ENUM(
    'recoverable_input_vat',
    'immobilisation',
    'taproom_opex',
    'office_opex',
    'eco_tax_glass',
    'excluded_taproom_or_maint',
    'diluted_into_freight'
  ) NULL DEFAULT NULL
  COMMENT 'Line accounting classification. NULL = normal COGS line. Non-NULL = excluded or specially-routed.'
  AFTER pack_converted;

CREATE INDEX idx_doc_invoice_lines_accounting_class
  ON doc_invoice_lines (accounting_class);

-- ── DOWN ─────────────────────────────────────────────────────────────────────────

-- DROP INDEX idx_doc_invoice_lines_accounting_class ON doc_invoice_lines;
-- ALTER TABLE doc_invoice_lines DROP COLUMN accounting_class;
