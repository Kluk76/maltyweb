-- 320_idx_bd_packaging_v2_reuse_ts.sql
-- PERFORMANCE: add covering composite index (reuses_packaging_id_fk, is_tombstoned)
-- on bd_packaging_v2 to speed up the correlated EXISTS subqueries inside
-- v_bd_packaging_v2_vendable.  Without this index, materialising the view
-- requires 2 × N full table scans (N = total live rows ≈ 2271), costing ~4.3 s
-- per cold page load of mon-tableau.php when the packaging-recap tile is pinned.
-- With the composite covering index the two EXISTS lookups resolve in <1 ms each
-- → full cold-materialisation drops from ~5 s to ~22 ms (≈240× speedup).
--
-- Algorithm: INSTANT (InnoDB — metadata-only, no table rebuild).
-- The single-column idx_bdpv2_reuse_fk (reuses_packaging_id_fk only) already
-- exists; the composite supersedes it but both can coexist.  The composite is
-- a COVERING index for the EXISTS check WHERE reuses_packaging_id_fk=? AND
-- is_tombstoned=0 so the engine never touches the row data.
--
-- Verified 2026-06-10 via EXPLAIN ANALYZE on VPS before merge:
--   Before: Table scan on c … (actual time=1.13..1.13 rows=… loops=2271) × 2
--   After:  Covering index lookup on c using this index … (actual time=0.00164 rows=… loops=2271) × 2

ALTER TABLE bd_packaging_v2
    ADD INDEX idx_bdpv2_reuse_ts (reuses_packaging_id_fk, is_tombstoned);
