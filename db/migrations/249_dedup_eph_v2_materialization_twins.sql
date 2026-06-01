-- ============================================================================
-- Migration 249: remove the 4 EPH v2-internal materialization-duplicate rows
--
-- WHAT:
--   Hard-DELETE 4 duplicate bd_packaging_v2 rows that the RawDB→v2 normalization
--   materialised twice from the same source sheet row, with an audit_row_revisions
--   tombstone record for each.
--
--   source_row  kept (canonical)  deleted (twin)   sku  batch  date        vend_hl
--   104         103               4575             32   21     2021-06-07  12.410
--   267         266               4738             35   21     2021-10-28  10.010
--   276         275               4747             38   21     2021-11-03   9.715
--   400         399               4871             29   22     2022-04-01   9.555
--                                                              total double-count = 41.690 HL
--
-- WHY:
--   Each pair is byte-identical (same source_sheet_row_index, sku_id_fk, neb_batch,
--   event_date, run_type, prod_total_units, vendable_hl, submitted_at — only id and
--   row_hash differ). Both rows are live (is_tombstoned=0) and carry non-zero
--   vendable_hl, so production / vendable-HL / beer-tax are double-counted by
--   +41.69 HL. The twins have ZERO downstream references (no bd_packaging_readings,
--   no reuse self-FK, tank_read_id_fk NULL) — verified — so removal is safe.
--
-- WHY hard-DELETE not tombstone:
--   The vendable view + packaging-stats + beer-tax loaders do NOT currently filter
--   is_tombstoned (a separate enforcement-gap arc), so tombstoning would NOT remove
--   the double-count. These rows are normalization artifacts that should never have
--   existed — hard-DELETE is the correct, enforcement-independent fix. PM-ruled.
--
-- AUDIT: audit_row_revisions has no 'delete' action value (ENUM insert|update), so
--   per house convention each deletion is recorded as action='update' with an
--   after_json _tombstone marker naming the kept canonical id.
--
-- ROLLBACK: re-INSERT the 4 rows from their canonical twins (data identical to
--   103/266/275/399 except id/row_hash) — but they are pure duplicates, so rollback
--   is only meaningful to restore the (erroneous) double-count; not recommended.
-- ============================================================================

-- 1. Audit-tombstone the 4 twins (action='update' per ENUM convention).
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
VALUES
  (1, 'mig249', 'bd_packaging_v2', 4575, 'update', NULL,
   '{"_tombstone":"deleted_by_mig249_eph_materialization_dup","kept_canonical_id":103,"source_sheet_row_index":104}',
   'EPH v2-internal materialization duplicate (twin of #103) hard-deleted', 'normal'),
  (1, 'mig249', 'bd_packaging_v2', 4738, 'update', NULL,
   '{"_tombstone":"deleted_by_mig249_eph_materialization_dup","kept_canonical_id":266,"source_sheet_row_index":267}',
   'EPH v2-internal materialization duplicate (twin of #266) hard-deleted', 'normal'),
  (1, 'mig249', 'bd_packaging_v2', 4747, 'update', NULL,
   '{"_tombstone":"deleted_by_mig249_eph_materialization_dup","kept_canonical_id":275,"source_sheet_row_index":276}',
   'EPH v2-internal materialization duplicate (twin of #275) hard-deleted', 'normal'),
  (1, 'mig249', 'bd_packaging_v2', 4871, 'update', NULL,
   '{"_tombstone":"deleted_by_mig249_eph_materialization_dup","kept_canonical_id":399,"source_sheet_row_index":400}',
   'EPH v2-internal materialization duplicate (twin of #399) hard-deleted', 'normal');

-- 2. Hard-delete the 4 twins (PK-targeted, source-row guarded; zero refs).
DELETE FROM bd_packaging_v2
 WHERE id IN (4575, 4738, 4747, 4871)
   AND source_sheet_row_index IN (104, 267, 276, 400)
   AND row_origin = 'main' AND is_tombstoned = 0;
