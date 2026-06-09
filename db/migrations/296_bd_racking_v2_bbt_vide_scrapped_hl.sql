-- Migration 296: Add bbt_vide_scrapped_hl column to bd_racking_v2
--
-- Captures the magnitude of the "BBT vide" override: when an operator
-- asserts that the tracked residual in the destination BBT is phantom
-- (derivation discrepancy), this column records how many HL were scrapped.
--
-- Semantics:
--   NULL  = normal event (genuine fresh-fill or real blend)
--   > 0   = BBT-vide override applied; value = phantom HL discarded
--
-- ZERO CHF: this is a volumetric reconciliation cause only.
-- It does NOT enter loss_dest_hl, loss_source_hl, or rack_loss_pct.
-- It is booked as a separate cause in the Pertes report.
--
-- Type matches all sibling HL columns (racked_vol_hl, blend_hl, loss_source_hl,
-- loss_dest_hl). INSTANT ADD COLUMN (MySQL 8, column appended at table end).
-- No IF NOT EXISTS — idempotency is provided by schema_migrations tracking.

ALTER TABLE bd_racking_v2
  ADD COLUMN bbt_vide_scrapped_hl DECIMAL(8,3) NULL
  CHECK (bbt_vide_scrapped_hl IS NULL OR bbt_vide_scrapped_hl >= 0);
