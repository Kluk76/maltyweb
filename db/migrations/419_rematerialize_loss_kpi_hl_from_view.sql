-- db/migrations/419_rematerialize_loss_kpi_hl_from_view.sql
--
-- What: Re-materialize stored bd_packaging_v2.loss_kpi_hl to match
--       v_bd_packaging_v2_vendable.loss_kpi_hl for rows where the two diverge
--       by more than 0.0005 HL.
--
-- Why: Mig 416 backfilled loss_liquid_other_units_alloc (diluted per-run share
--      of group liquid loss). Mig 417 rewrote the view so loss_kpi_hl reads
--      COALESCE(loss_liquid_other_units_alloc, ...) in both arms. However the
--      stored column bd_packaging_v2.loss_kpi_hl was NOT re-materialized by
--      416/417. For historical parallel-run (bot/can) rows the stored value
--      is the old lump-on-main distribution; the view now computes the new
--      diluted one. 242 rows diverge.
--
-- Conservation: This migration only redistributes loss_kpi_hl WITHIN parallel
--   groups — it does not create or destroy HL. Global Σ stored ≈ 632.56 HL
--   must be preserved (within ROUND(,3) rounding of ~0.01 HL).
--
-- Three consumers read the stored column directly (NOT the view):
--   app/tank-simulator.php:1020
--   app/packaging-stats.php:355
--   app/kpi-handlers.php:5311
--   => stored column must equal the view.
--
-- Idempotent: The ABS(...) > 0.0005 divergence predicate makes re-running a
--   no-op once rows are updated.
--
-- PDO-safe: no standalone SELECT statements.
--   CREATE TEMPORARY TABLE ... AS SELECT is allowed (does not leave an open
--   result set). No bare SELECT.
--
-- Audit: INSERT into audit_row_revisions BEFORE the UPDATE (per mig-200 /
--   mig-416 pattern). action='update' (ENUM has only 'insert'/'update').
--
-- Rollback:
--   UPDATE bd_packaging_v2 p
--   JOIN (
--     SELECT target_pk,
--            CAST(JSON_UNQUOTE(JSON_EXTRACT(before_json, '$.loss_kpi_hl')) AS DECIMAL(14,6))
--              AS old_val
--     FROM audit_row_revisions
--    WHERE target_table = 'bd_packaging_v2'
--      AND comment      = 'rematerialize_loss_kpi_hl_mig419'
--   ) r ON r.target_pk = p.id
--   SET p.loss_kpi_hl = r.old_val;
--   DELETE FROM audit_row_revisions
--    WHERE target_table = 'bd_packaging_v2'
--      AND comment      = 'rematerialize_loss_kpi_hl_mig419';

-- ============================================================================
-- STEP 1: Materialise divergent rows into a temporary table.
--   We read the view here (not during UPDATE) to sidestep MySQL error 1093
--   ("can't specify target table for update in FROM clause"):
--   v_bd_packaging_v2_vendable reads bd_packaging_v2 internally, so a direct
--   UPDATE bd_packaging_v2 JOIN v_bd_packaging_v2_vendable is forbidden.
--   A temporary table breaks the cycle.
-- ============================================================================

CREATE TEMPORARY TABLE tmp_kpi419 AS
  SELECT v.id,
         v.loss_kpi_hl AS new_loss_kpi_hl
    FROM v_bd_packaging_v2_vendable v
    JOIN bd_packaging_v2 p ON p.id = v.id
   WHERE p.is_tombstoned          = 0
     AND p.reuses_packaging_id_fk IS NULL
     AND ABS(p.loss_kpi_hl - v.loss_kpi_hl) > 0.0005;

-- ============================================================================
-- STEP 2: Audit INSERT — one row per about-to-change row, capturing the
--   stored before value and the incoming view value.
--   Join the base table to the temp table (NOT the view) to stay 1093-safe.
-- ============================================================================

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0                                                                      AS user_id,
  'migration_419'                                                        AS username,
  'bd_packaging_v2'                                                      AS target_table,
  p.id                                                                   AS target_pk,
  'update'                                                               AS action,
  JSON_OBJECT('loss_kpi_hl', p.loss_kpi_hl)                             AS before_json,
  JSON_OBJECT('loss_kpi_hl', t.new_loss_kpi_hl)                         AS after_json,
  'rematerialize_loss_kpi_hl_mig419'                                     AS comment
FROM bd_packaging_v2 p
JOIN tmp_kpi419 t ON t.id = p.id;

-- ============================================================================
-- STEP 3: UPDATE — apply new values from temp table to base table.
--   Divergence predicate repeated as defence-in-depth (idempotency).
-- ============================================================================

UPDATE bd_packaging_v2 p
JOIN tmp_kpi419 t ON t.id = p.id
SET p.loss_kpi_hl = t.new_loss_kpi_hl
WHERE p.is_tombstoned          = 0
  AND p.reuses_packaging_id_fk IS NULL
  AND ABS(p.loss_kpi_hl - t.new_loss_kpi_hl) > 0.0005;

-- ============================================================================
-- STEP 4: Clean up.
-- ============================================================================

DROP TEMPORARY TABLE tmp_kpi419;

-- end migration 419
