-- ============================================================================
-- Migration 244: historical in-filling → v2 link (Job 2 of the deferred v1→v2
--                CO₂/O₂ backfill) — deterministic 1:1 subset only
--
-- WHAT:
--   Set bd_packaging_readings.packaging_v2_id on the historical (v1-keyed)
--   in-filling reads, for the DETERMINISTIC subset where the parent v1 packaging
--   row maps to EXACTLY ONE v2 main run under the natural key
--   (sku via neb_beer→ref_skus.sku_code, neb_batch, DATE(submitted_at)).
--
-- WHY:
--   In-filling CO₂/O₂ reads (multiple pairs off finished units during a fill) were
--   loaded from RawDB keyed to v1 bd_packaging.id (packaging_id). bd_packaging_readings
--   gained packaging_v2_id (mig 242) so the v2 dashboards / packaging-stats can read
--   them. This links the historical reads to their v2 run.
--
-- WHY NOT the sheet_row_index bridge: it's semantically broken (~43%). This uses
--   the natural business key, which the uniqueness proof showed is a clean 1:1 map
--   on the deterministic subset.
--
-- UNIQUENESS PROOF (frozen 2026-06-01, the gate for this write):
--   991 historical in-filling parents →
--     852  map to EXACTLY 1 v2 main run   ← linked here (this migration)
--     130  map to 0 v2 runs (no counterpart) → left unlinked (semantic NULL)
--       9  map to 2 v2 runs (8 cuv same-day serving-tank runs + 1 EST4 AM/PM
--          bottle split) → AMBIGUOUS, left unlinked (refuse, never guess)
--   Reverse check: the 852 parents map to 852 DISTINCT v2 rows — 0 collisions
--   (true bijection; no two v1 runs merge onto one v2 row).
--   → 0 ambiguous links written. Unlinked rows keep their v1 packaging_id and lose
--     nothing (data lives in v1); they simply have no v2 attribution.
--
-- GUARDS: only readings with packaging_v2_id IS NULL are touched (the 11 web-folded
--   reads already carry packaging_v2_id and are skipped). The `= 1 candidate` count
--   guard excludes the 9 ambiguous parents; the JOIN itself excludes the 130 no-match.
--
-- NOT IN SCOPE: the 4 EPH v2-internal duplicate rows (source rows 104/267/276/400,
--   each materialised twice) are a separate v2-normalization data-quality bug; they
--   do NOT intersect any in-filling parent, so they don't affect this link. Flagged
--   for a separate cleanup.
--
-- DRY-RUN VERIFIED (rolled-back txn 2026-06-01): see apply log.
--
-- ROLLBACK:
--   UPDATE bd_packaging_readings SET packaging_v2_id = NULL
--     WHERE packaging_v2_id IS NOT NULL AND packaging_id IS NOT NULL
--       AND id IN (/* the rows this set */);
--   (simplest: re-NULL all packaging_v2_id where packaging_id IS NOT NULL — the only
--    non-NULL-packaging_id rows with a v2 link are the ones this migration created;
--    the 11 web reads have packaging_id NULL.)
-- ============================================================================

UPDATE bd_packaging_readings r
  JOIN bd_packaging p   ON p.id = r.packaging_id
  JOIN ref_skus     sku ON sku.sku_code = p.neb_beer
  JOIN bd_packaging_v2 v
    ON v.row_origin = 'main' AND v.is_tombstoned = 0
   AND v.source_sheet_row_index IS NOT NULL
   AND v.sku_id_fk   = sku.id
   AND v.neb_batch   = p.neb_batch
   AND v.event_date  = DATE(p.submitted_at)
   SET r.packaging_v2_id = v.id
 WHERE r.packaging_v2_id IS NULL
   AND 1 = (
        SELECT COUNT(*) FROM bd_packaging_v2 v2c
         WHERE v2c.row_origin = 'main' AND v2c.is_tombstoned = 0
           AND v2c.source_sheet_row_index IS NOT NULL
           AND v2c.sku_id_fk  = sku.id
           AND v2c.neb_batch  = p.neb_batch
           AND v2c.event_date = DATE(p.submitted_at)
   );
