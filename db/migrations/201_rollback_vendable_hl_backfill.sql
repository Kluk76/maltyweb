-- db/migrations/201_rollback_vendable_hl_backfill.sql
--
-- What: Roll back the bd_packaging_v2.vendable_hl backfill applied by mig 200.
--       Reverts the 1996 rows back to NULL and updates schema_meta with the
--       rollback rationale.
--
-- Why:  The view formula `(prod_total + special - qa - losses) × hl_per_unit`
--       produces values 7-11× higher than physical brewery cast-out (e.g. for
--       year 2024: brewed 13,743 HL → my formula computed 100,998 HL of v2
--       vendable). Single-row exemplar: ZEP6C batch 126 — legacy operator-
--       entered vendable_hl = 214.56 HL; my formula gave 5,148.96 HL (24×).
--
--       Root cause (live-verified 2026-05-28): `ref_skus.hl_per_unit` for
--       multi-pack SKUs is HL per PACKAGING UNIT (a 24-can box = 12L = 0.12 HL,
--       a 6×4 carton = 7.92L = 0.0792 HL), but `bd_packaging_v2.prod_total_units`
--       from the operator form counts INDIVIDUAL CANS/BOTTLES, not pack units.
--       Multiplying gives an N× error where N is the pack size (24 for C-suffix,
--       6 for 6-packs, etc.). The pack-size resolver does not currently exist
--       on ref_skus.
--
--       This is a packaging-pre-framework-pass concern. The view (mig 193)
--       and the backfill (mig 200) are kept on file as a documented dead-end;
--       fixing it durably needs PM ruling on either (a) a `units_per_pack`
--       column on ref_skus, or (b) a v2-form schema change to enforce
--       pack-unit-only entry. The hourly sweep cron is REMOVED in this same
--       deploy.
--
-- Pre-rollback aggregate (verified live):
--   bd_packaging_v2 rows with vendable_hl populated: 1996
--   Sum vendable_hl: 607,332.57 HL (vs brewed cast-out 70k HL across the
--                                   same period — 8× over).
--
-- Audit posture: APPEND-ONLY. We do NOT delete the 1996 audit rows from
-- comment='backfill_vendable_hl_mig194'. We append one rollback audit row
-- per affected target_pk so the time series carries both events.
--
-- Risk: LOW — UPDATE p SET vendable_hl=NULL is reversible (re-apply mig 200
-- view computes the same wrong values). No downstream consumers were
-- materially impacted: the PPB resolver's legacy bd_packaging branch already
-- carried the same packaging events, dominating the cross-source dedup; the
-- v2 branch was contributing nothing meaningful (zero net effect on the
-- 226→190 DRAWN_SHORT improvement we already shipped). TankSimulator and
-- COGS read paths were not yet exercised on the bad values within this
-- session.
--
-- Rollback (of this rollback):
--   UPDATE bd_packaging_v2 p
--   JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
--      SET p.vendable_hl = v.vendable_hl
--    WHERE p.vendable_hl IS NULL
--      AND p.id IN (SELECT target_pk FROM audit_row_revisions
--                    WHERE comment='vendable_hl_rollback_mig201');

-- ============================================================================
-- STEP 1: Append rollback audit rows (one per backfilled row).
-- ============================================================================
-- Capture current populated value as before_json; new value (NULL) as after_json.

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0                                          AS user_id,
  'migration_201'                            AS username,
  'bd_packaging_v2'                          AS target_table,
  p.id                                       AS target_pk,
  'update'                                   AS action,
  JSON_OBJECT('vendable_hl', p.vendable_hl)  AS before_json,
  JSON_OBJECT('vendable_hl', NULL)           AS after_json,
  'vendable_hl_rollback_mig201'              AS comment
FROM bd_packaging_v2 p
JOIN audit_row_revisions a ON
     a.target_table = 'bd_packaging_v2'
 AND a.target_pk    = p.id
 AND a.comment      = 'backfill_vendable_hl_mig194'
WHERE p.vendable_hl IS NOT NULL
GROUP BY p.id;

-- ============================================================================
-- STEP 2: NULL out the backfilled column on the affected rows.
-- ============================================================================

UPDATE bd_packaging_v2 p
JOIN audit_row_revisions a ON
     a.target_table = 'bd_packaging_v2'
 AND a.target_pk    = p.id
 AND a.comment      = 'backfill_vendable_hl_mig194'
   SET p.vendable_hl = NULL
 WHERE p.vendable_hl IS NOT NULL;

-- ============================================================================
-- STEP 3: Refresh schema_meta note to reflect rolled-back state.
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'bd_packaging_v2',
  'source',
  'public/modules/form-packaging.php + python/ingest_*.py',
  'allowed',
  'Web-form packaging events. vendable_hl is currently UNPOPULATED (rolled back by mig 201 after the mig 200 formula was found to over-compute 7-11× due to pack-size unit mismatch between ref_skus.hl_per_unit (per-pack) and prod_total_units (per-individual-item)). PM-tracked in packaging-bom-model.md — awaiting durable model (units_per_pack column or form-schema change) before any further compute.'
)
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);

-- end migration 201
