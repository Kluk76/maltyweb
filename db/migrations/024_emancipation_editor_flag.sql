-- 024 — Emancipation: add last_modified_by flag.
--
-- Hybrid emancipation pattern. Maltyweb's MySQL is the canonical surface
-- for operator edits, but the BSF→MySQL ingest pipeline still runs to
-- absorb new rows added via the maltytask sister project (add-ingredient.js,
-- add-delivery.js, reconciler etc.).
--
-- Flag semantics:
--   'ingest' (default) — row is whatever the last ingest run produced.
--                        Future ingest runs will overwrite it.
--   'web'              — row was edited via Maltyweb. Future ingest runs
--                        will preserve it (every column kept) but still
--                        refresh `last_seen_at` if applicable.
--
-- Applied to the four master-data tables (Phase 4 + Phase 5 scope).
-- ref_sku_bom is intentionally excluded — BOM lines are computed entirely
-- from BSF SKU_BOM and Maltyweb does not edit them.
-- inv_deliveries and bd_* tables are out of scope for v0.

ALTER TABLE ref_recipes
  ADD COLUMN last_modified_by ENUM('ingest','web') NOT NULL DEFAULT 'ingest'
  AFTER updated_at;

ALTER TABLE ref_suppliers
  ADD COLUMN last_modified_by ENUM('ingest','web') NOT NULL DEFAULT 'ingest'
  AFTER imported_at;

ALTER TABLE ref_mi
  ADD COLUMN last_modified_by ENUM('ingest','web') NOT NULL DEFAULT 'ingest'
  AFTER imported_at;

ALTER TABLE ref_skus
  ADD COLUMN last_modified_by ENUM('ingest','web') NOT NULL DEFAULT 'ingest'
  AFTER imported_at;
