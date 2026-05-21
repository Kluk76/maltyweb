-- 049_inv_deliveries_exclusion_class.sql
--
-- Adds exclusion_class column to inv_deliveries.
-- NULL  = normal COGS line (production cost).
-- Non-NULL = excluded from COGS / WAC / stock depletion.
--
-- Column is positioned after line_index (the doc-anchor column from 048).
--
-- Apply:
--   ssh maltyweb 'cd /var/www/maltytask && php scripts/migrate.php'
--
-- Enum values:
--   recoverable_vat — import-VAT pass-through (PESA/Dachser); not a COGS cost
--   immobilisation  — CAPEX (printing plates, equipment); to fixed-asset account
--   taproom_opex    — taproom/bar operations; excluded from production COGS
--   office_opex     — back-office/admin/IT share; excluded from production COGS
--
-- NOT included (confirmed decisions):
--   eco_tax_glass   — per memory feedback_tea_dilution_into_bottle.md, TEA/eco-tax
--                     gets its own MI (PKG_TEA_BOT_CH, GL 4201) as a full COGS row.
--                     Do NOT add eco_tax_glass here.
--   maintenance     — per memory feedback_maintenance_category_account_6100.md,
--                     Maintenance IS COGS-visible (GL 6100). Not an exclusion.
--
-- Downstream guards required after migration:
--   loadDeliveriesForWac()  — already patched in compute-weighted-prices.ts / mysql-deliveries.ts
--   loadDeliveriesForDepletion() — filters status='Active' which is sufficient;
--                                   Active + exclusion_class IS NOT NULL rows should
--                                   not exist in normal operation (gate sets Pending
--                                   for excluded lines, not Active).

-- ── UP ───────────────────────────────────────────────────────────────────────────

ALTER TABLE inv_deliveries
  ADD COLUMN exclusion_class ENUM(
    'recoverable_vat',
    'immobilisation',
    'taproom_opex',
    'office_opex'
  ) NULL DEFAULT NULL
  COMMENT 'Non-null = line excluded from COGS/WAC/depletion. NULL = normal production cost.'
  AFTER line_index;

-- No index added: downstream consumers filter WHERE exclusion_class IS NULL on
-- result sets already narrowed by status='Active' — full-table scan not expected.
-- Add if query profiling shows benefit.

-- ── DOWN ─────────────────────────────────────────────────────────────────────────

-- ALTER TABLE inv_deliveries DROP COLUMN exclusion_class;
