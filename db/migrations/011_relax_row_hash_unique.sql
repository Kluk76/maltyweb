-- 011 — relax row_hash UNIQUE on all bd_* tables.
-- Reason: byte-identical Sheets rows can represent legitimately distinct
-- physical events (e.g. 6 cuv fills of 500 L each for the same packaging
-- session yield 6 byte-identical rows). UNIQUE(row_hash) alone collapses them.
-- The new constraint UNIQUE(sheet_row_index, row_hash) preserves them.
--
-- Behaviour matrix:
--   re-import unchanged data            → no-op  (same idx + same hash)
--   distinct positions, same content    → both inserted (DIFFERENT idx)
--   row edited in Sheets at same idx    → new row appended (different hash)

ALTER TABLE bd_brewing_brewday
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_brewing_gravity
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_brewing_cooling
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_brewing_timings
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_brewing_ingredients
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_fermenting
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_racking
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);

ALTER TABLE bd_packaging
  DROP INDEX uniq_row_hash,
  ADD UNIQUE KEY uniq_idx_hash (sheet_row_index, row_hash);
