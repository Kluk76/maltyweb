-- 046b — Replace (sheet_row_index, row_hash) UNIQUE KEY with UNIQUE KEY on
--        sheet_row_index alone on all bd_* tables.
--
-- Run AFTER:
--   1. Migration 046 (adds updated_at + phantom_cleanup_log)
--   2. cleanup_phantom_rows.py --apply (removes phantom duplicates)
--
-- If any duplicate sheet_row_index values exist when this runs, the ALTER will
-- fail with ERROR 1062. The cleanup script must be run first.
--
-- bd_packaging_readings is excluded: its identity key is (packaging_id, reading_idx),
-- already enforced by the existing uniq_pkg_reading UNIQUE KEY. That table has no
-- sheet_row_index-based identity — readings are child rows of bd_packaging.
--
-- Down-migration (conceptual):
--   ALTER TABLE bd_* DROP INDEX uq_sri;
--   ALTER TABLE bd_* ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

-- ── bd_brewing_brewday ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_brewday
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_brewing_gravity ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_gravity
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_brewing_cooling ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_cooling
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_brewing_timings ────────────────────────────────────────────────────────
ALTER TABLE bd_brewing_timings
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_brewing_ingredients ────────────────────────────────────────────────────
ALTER TABLE bd_brewing_ingredients
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_fermenting ─────────────────────────────────────────────────────────────
ALTER TABLE bd_fermenting
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_racking ────────────────────────────────────────────────────────────────
ALTER TABLE bd_racking
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);

-- ── bd_packaging ─────────────────────────────────────────────────────────────
ALTER TABLE bd_packaging
  DROP INDEX uniq_idx_hash,
  ADD UNIQUE KEY uq_sri (sheet_row_index);
