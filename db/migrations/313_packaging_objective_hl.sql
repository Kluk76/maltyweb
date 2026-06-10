-- Migration 313 — Add objective_hl planning annotation to bd_packaging_v2
-- Purpose: per-run HL volume objective (nullable, planning-only annotation).
-- IMPORTANT: This column is a PLANNING ANNOTATION — it MUST NOT feed
-- compute_packaging_vendable_hl(), v_bd_packaging_v2_vendable, beer_tax_base_hl,
-- loss_kpi_hl, FG, or any COGS/COP/beer-tax path. Read only in the recap handler.
-- MySQL 8: no IF NOT EXISTS on ADD COLUMN. INSTANT DDL (nullable add).

ALTER TABLE bd_packaging_v2
  ADD COLUMN objective_hl DECIMAL(8,3) NULL DEFAULT NULL
    COMMENT 'Planning annotation: per-run HL volume objective. NULL = no target. NOT fiscal.';
