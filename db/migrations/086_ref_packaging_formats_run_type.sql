-- db/migrations/086_ref_packaging_formats_run_type.sql
-- What: Add run_type ENUM('bot','can','can33','keg','cuv') column to
--       ref_packaging_formats and seed all 22 rows.
-- Why:  Anchors the format→run_type mapping in the DB.
--       Previously hardcoded in scripts/normalize-rawdb.py::_derive_run_type.
--       Phase 1.A of BSF→MySQL clean-break roadmap (2026-05-23).
-- Risk: Additive only (ADD COLUMN). No data loss. NULL default is safe.
-- Rollback: ALTER TABLE ref_packaging_formats DROP COLUMN run_type;

ALTER TABLE ref_packaging_formats
  ADD COLUMN run_type ENUM('bot','can','can33','keg','cuv') NULL
  COMMENT 'Physical-format classification; NULL for composites and draft-pour formats'
  AFTER hl_per_unit;

-- bot: bottle 33cl variants (6 rows)
UPDATE ref_packaging_formats SET run_type = 'bot'
  WHERE format_code IN ('4','B','4PB','B12','BU','X');

-- can: 50cl can variants (7 rows; BC folds into C)
UPDATE ref_packaging_formats SET run_type = 'can'
  WHERE format_code IN ('C','4C','6C','12C','4PC','CU','BC');

-- can33: distinct 33cl can (1 row)
UPDATE ref_packaging_formats SET run_type = 'can33'
  WHERE format_code IN ('33C');

-- keg: Fût 20L (1 row)
UPDATE ref_packaging_formats SET run_type = 'keg'
  WHERE format_code IN ('F');

-- cuv: Cuve de service 1L unit (1 row)
UPDATE ref_packaging_formats SET run_type = 'cuv'
  WHERE format_code IN ('V');

-- P25, P50, PD8, XMASPACK, PAL, PAD stay NULL (draft pours + composites; 6 rows)
