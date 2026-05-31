-- ============================================================================
-- Migration 245: bd_packaging_readings — make it the single in-filling store
--                (expand phase: allow v2-only rows)
--
-- WHAT:
--   1. Relax bd_packaging_readings.packaging_id from NOT NULL to NULL.
--      The FK fk_readings_packaging → bd_packaging(id) is KEPT (FKs permit NULL);
--      historical RawDB-imported rows keep their v1 packaging_id unchanged.
--   2. Add UNIQUE(packaging_v2_id, reading_idx).
--
-- WHY:
--   bd_packaging_readings holds IN-FILLING CO₂/O₂ reads (multiple pairs pulled
--   from finished units during a fill — these ARE losses, feed QA "Mesures").
--   Historically every row was keyed to a v1 bd_packaging row (packaging_id).
--   Going forward, web saisies create v2 rows (bd_packaging_v2) with NO v1
--   sibling, so their in-filling reads must key on packaging_v2_id alone
--   (added in mig 242).  For that, packaging_id must be nullable, and a
--   per-v2-run uniqueness guard (packaging_v2_id, reading_idx) is needed,
--   mirroring the existing (packaging_id, reading_idx) guard for v1 rows.
--
--   MySQL UNIQUE treats NULLs as distinct, so the 5391 historical rows (all
--   packaging_v2_id NULL until a future natural-key backfill) never collide
--   under the new constraint — only rows that actually carry a packaging_v2_id
--   are deduped by it.
--
--   This consolidates onto ONE in-filling table per operator request; the
--   stray bd_packaging_co2o2_measures (11 web rows) is folded in and dropped
--   in a later contract migration once the form + packaging-stats.php no longer
--   read or write it.
--
-- NOTE: additive/relaxing only — no data is modified.  packaging_v2_id stays
--   NULL on all historical rows (the v1↔v2 row bridge is unreliable; a validated
--   natural-key backfill is a separate deferred sub-project, migs 243/244).
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_readings
--     DROP KEY uq_pkg_readings_v2,
--     MODIFY packaging_id BIGINT UNSIGNED NOT NULL;
--   (rollback of the MODIFY only succeeds while no row has packaging_id NULL.)
-- ============================================================================

-- 1. Relax packaging_id to nullable (FK + existing UNIQUE/KEY are preserved).
ALTER TABLE bd_packaging_readings
  MODIFY COLUMN packaging_id BIGINT UNSIGNED NULL
    COMMENT 'FK to bd_packaging.id (v1). NULL for v2-only web saisies, which key on packaging_v2_id instead.';

-- 2. Per-v2-run uniqueness for in-filling reads (NULLs distinct → historical
--    packaging_v2_id-NULL rows never collide).
ALTER TABLE bd_packaging_readings
  ADD UNIQUE KEY uq_pkg_readings_v2 (packaging_v2_id, reading_idx);
