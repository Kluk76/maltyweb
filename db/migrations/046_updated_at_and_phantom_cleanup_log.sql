-- 046 — Add updated_at to bd_* tables + phantom_cleanup_log for audit.
--
-- Context: chantier 2 (phantom rows). When an operator corrects a cell in BSF,
-- the row_hash changes. The existing UNIQUE KEY on (sheet_row_index, row_hash)
-- treated the corrected row as a new row, leaving the old (wrong-value) row
-- as a phantom. We are switching to UNIQUE KEY on sheet_row_index (alone) with
-- INSERT ... ON DUPLICATE KEY UPDATE so corrections update in place.
--
-- This migration (046) installs prerequisites WITHOUT adding the UNIQUE KEY yet:
--   1. updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP — so we can tell when
--      a row was last touched by ingest (vs imported_at = first-ever-import).
--   2. phantom_cleanup_log — audit table for the one-shot cleanup script, so
--      every phantom deletion is reversible.
--
-- DEPLOYMENT ORDER:
--   Step 1: Run this migration (046) — adds updated_at + cleanup log table
--   Step 2: Run cleanup_phantom_rows.py --apply — removes phantom duplicates
--   Step 3: Run migration 046b — adds UNIQUE KEY uq_sri on each bd_* table
--   Step 4: Deploy updated lib_db.py (UPSERT pattern)
--
-- NOTE: We DROP the old UNIQUE KEY (sheet_row_index, row_hash) — named
-- uniq_idx_hash — before adding the new constraint. This is safe because:
--   a) The row_hash column retains its own index via idx_sheet_row_index /
--      existing non-unique indexes.
--   b) The new UNIQUE KEY on sheet_row_index alone is strictly tighter
--      (prevents same-sri at any hash, not just same-hash).
--
-- Down-migration (conceptual):
--   ALTER TABLE bd_* DROP COLUMN updated_at;
--   DROP TABLE phantom_cleanup_log;

-- ── bd_brewing_brewday ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_brewday
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_brewing_gravity ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_gravity
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_brewing_cooling ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_cooling
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_brewing_timings ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_timings
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_brewing_ingredients ────────────────────────────────────────────────────
ALTER TABLE bd_brewing_ingredients
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_fermenting ─────────────────────────────────────────────────────────────
ALTER TABLE bd_fermenting
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_racking ────────────────────────────────────────────────────────────────
ALTER TABLE bd_racking
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── bd_packaging ─────────────────────────────────────────────────────────────
ALTER TABLE bd_packaging
  ADD COLUMN updated_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER imported_at;

-- ── phantom_cleanup_log ───────────────────────────────────────────────────────
-- Audit log for every phantom row deleted by cleanup_phantom_rows.py.
-- Stores the full row as JSON for reversibility.
CREATE TABLE IF NOT EXISTS phantom_cleanup_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deleted_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  table_name      VARCHAR(64)     NOT NULL,
  deleted_row_id  BIGINT UNSIGNED NOT NULL,   -- the id that was deleted
  kept_row_id     BIGINT UNSIGNED NOT NULL,   -- the id that was kept (winner)
  deleted_row_json JSON           NOT NULL,   -- full snapshot for reversibility
  KEY idx_pcl_table (table_name),
  KEY idx_pcl_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
