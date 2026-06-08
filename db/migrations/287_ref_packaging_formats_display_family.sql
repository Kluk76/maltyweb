-- =============================================================================
-- Migration 287: Add display_family to ref_packaging_formats
-- =============================================================================
-- Purpose: Enables per-format display grouping in the Expéditions stocktake
-- form (view=stocktake) without hardcoding SKU lists in PHP.
-- The form groups SKUs by COALESCE(f.display_family, s.format).
-- Seeded for the 4 multipack formats (PD8/XMASPACK/PAL/PAC → 'multipack').
-- NULL for all other formats → COALESCE falls back to ref_skus.format.
-- =============================================================================

ALTER TABLE ref_packaging_formats
    ADD COLUMN display_family VARCHAR(32) NULL AFTER derived_from_format_id;

UPDATE ref_packaging_formats
   SET display_family = 'multipack'
 WHERE id IN (19, 20, 21, 23);
