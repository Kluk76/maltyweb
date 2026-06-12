-- 339_inv_fg_stocktake_count_type.sql
-- Add count_type discriminator to inv_fg_stocktake: separates weekly OPERATIONAL
-- counts (interim warehouse snapshots, not COGS-grade) from MONTH-END accounting
-- censuses (closing balance consumed by the COGS fiche engine / basis adjustment).
--
-- Context: the COGS fiche engine (cogs-monthly-compile.ts) reads inv_fg_stocktake
-- to build opening/closing FG inventory values. Operational weekly counts must NOT
-- be picked up as closing censuses; month_end rows are the only ones with a stable,
-- audited FG balance. COGS reads should filter WHERE count_type = 'month_end'.
--
-- NOT NULL + DEFAULT 'operational': minimize NULL per house convention; the
-- high-frequency weekly case is the default; month_end rows are the exception
-- explicitly stamped during month-close.
--
-- Backfill rationale:
--   April 2026 (month_closed='2026-04'): 41 rows — is_active=0 seed/closing census,
--   already read by the COGS basis adjustment for the April→May opening stock.
--   May 2026  (month_closed='2026-05'): 34 rows — is_active=1 May closing census,
--   consumed by the May COGS fiche computation (Σtotal 351 884.58 CHF).
--   June 2026 (month_closed='2026-06'): weekly operational counts — left as
--   DEFAULT 'operational'; NOT touched here.
--
-- No schema_meta row: this is an ALTER on an existing table, not a new table.

ALTER TABLE `inv_fg_stocktake`
  ADD COLUMN `count_type` ENUM('operational','month_end') NOT NULL DEFAULT 'operational'
  AFTER `source`;

UPDATE `inv_fg_stocktake`
   SET `count_type` = 'month_end'
 WHERE `month_closed` IN ('2026-04', '2026-05');
