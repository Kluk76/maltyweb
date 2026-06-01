-- db/migrations/256_gl6285_non_inventoried.sql
--
-- What: GL 6285 eShop/logistics packaging consumables are expensed on
--       purchase (direct charges) and must NOT be inventoried.
--
--       (A) Flip is_inventoried=0 on 8 ref_mi rows whose gl_account=6285
--           and which were incorrectly marked is_inventoried=1:
--             174 LOG_FILM_ETIRABLE
--             175 LOG_PAPIER_BULLE
--             201 LOG_SCOTCH_ESHOP
--             202 LOG_STRETCHFOLIE
--             204 LOG_RUBAN_ADHESIF
--             206 LOG_POCHETTE
--             245 LOG_LABEL_DYMO_SHIPPING
--             254 LOG_CERCLAGE_RAJAPACK
--           (172 LOG_CELLOPHANE and 205 LOG_FEUILLARD already is_inventoried=0 —
--            do NOT touch them.)
--
--       (B) Soft-delete 5 stocktake LINES (inv_rm_stocktake_lines) for
--           period 2026-05 that the operator just entered for these items:
--             id 281 LOG_FILM_ETIRABLE    qty=9   mi_id_fk=174
--             id 283 LOG_PAPIER_BULLE     qty=2   mi_id_fk=175
--             id 282 LOG_SCOTCH_ESHOP     qty=12  mi_id_fk=201
--             id  69 LOG_STRETCHFOLIE     qty=2.5 mi_id_fk=202
--             id 284 LOG_CERCLAGE_RAJAPACK qty=1  mi_id_fk=254
--           (204/206/245 had NO lines entered → no soft-delete needed there.)
--
--       (C) Soft-delete the 5 corresponding rollup rows (inv_rm_stocktake):
--             id 619 mi_id_fk=174 LOG_FILM_ETIRABLE    counted_qty=9.0000
--             id 621 mi_id_fk=175 LOG_PAPIER_BULLE     counted_qty=2.0000
--             id 620 mi_id_fk=201 LOG_SCOTCH_ESHOP     counted_qty=12.0000
--             id 406 mi_id_fk=202 LOG_STRETCHFOLIE     counted_qty=2.5000
--             id 622 mi_id_fk=254 LOG_CERCLAGE_RAJAPACK counted_qty=1.0000
--           (lib/rm-stock-mysql.js RM_SELECT filters s.is_active=1, so
--            deactivating these rollup rows cleanly removes them from all
--            RM reads without touching counted_qty or row_hash.)
--
-- Why:  These 8 MIs carry GL 6285 (eShop/logistics consumables), which
--       routes through COP as a direct expense.  The is_inventoried=1 flag
--       caused them to appear in the RM count form, the count dropdown, and
--       the WAC view — all wrong.  The 5 counts entered in 2026-05 must be
--       retracted before any RM valuation runs on that period.
--       Operator confirmed (PM-approved).  COP impact is intentional:
--       expensing is preserved (GL routing, not the flag, drives COP).
--
-- Pre-flight results (verified live 2026-06-01):
--   ref_mi: all 8 ids have gl_account=6285, is_inventoried=1, is_active=1.
--   inv_rm_stocktake_lines: ids 69/281/282/283/284 all is_active=1,
--     period=2026-05, mi_id_fks match the 8 list above.
--   inv_rm_stocktake rollup: exactly one is_active=1 row per mi_id_fk for
--     period=2026-05 (ids 619/621/620/406/622 as above).
--   204/206/245 confirmed absent from inv_rm_stocktake for period=2026-05.
--   Migration 255 (255_vendable_view_exclude_tombstoned.sql) is the last
--   applied; this is number 256.
--
-- Idempotency:
--   STEP 1 UPDATE (ref_mi flag flip):   WHERE id IN (...) AND is_inventoried=1
--     → 0 rows on re-run (rows already at 0).
--   STEP 2 UPDATE (lines soft-delete):  WHERE id IN (...) AND is_active=1
--     → 0 rows on re-run (rows already at 0).
--   STEP 3 UPDATE (rollup soft-delete): WHERE id IN (...) AND is_active=1
--     → 0 rows on re-run (rows already at 0).
--   STEP 4 audit INSERTs: each SELECT's WHERE targets the post-change state
--     (is_inventoried=0 / is_active=0) so a second apply inserts 0 new audit
--     rows.
--
-- Rollback:
--   UPDATE ref_mi SET is_inventoried=1
--     WHERE id IN (174,175,201,202,204,206,245,254) AND is_inventoried=0;
--   UPDATE inv_rm_stocktake_lines SET is_active=1
--     WHERE id IN (281,283,282,69,284) AND is_active=0;
--   UPDATE inv_rm_stocktake SET is_active=1
--     WHERE id IN (619,621,620,406,622) AND is_active=0;
--   DELETE FROM audit_row_revisions WHERE comment LIKE 'mig256_%';
--
-- Expected audit rows on first apply: 8 (flag flips) + 5 (lines) + 5 (rollups)
--   = 18 total.  SELECT COUNT(*) FROM audit_row_revisions
--     WHERE comment LIKE 'mig256_%' should return 18.
--
-- Date  : 2026-06-01
-- Author: migration_256

START TRANSACTION;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: Flip is_inventoried=0 on 8 ref_mi rows (gl_account=6285)
--
-- Only rows still at is_inventoried=1 are touched.
-- 172/205 (already 0) are intentionally excluded from the IN list.
-- Idempotent: WHERE ... AND is_inventoried=1 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_mi
   SET is_inventoried = 0
 WHERE id IN (174, 175, 201, 202, 204, 206, 245, 254)
   AND is_inventoried = 1;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: Soft-delete 5 stocktake line rows (inv_rm_stocktake_lines)
--
-- The operator entered these counts in 2026-05 before the is_inventoried flag
-- was corrected.  Setting is_active=0 removes them from the form, the dropdown,
-- and any line-level RM view that filters is_active=1.
-- Idempotent: WHERE ... AND is_active=1 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE inv_rm_stocktake_lines
   SET is_active = 0
 WHERE id IN (281, 283, 282, 69, 284)
   AND is_active = 1;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: Soft-delete 5 rollup rows (inv_rm_stocktake)
--
-- lib/rm-stock-mysql.js RM_SELECT filters s.is_active=1.  Deactivating these
-- rollup rows is the clean removal path — no counted_qty/row_hash recompute
-- required (the row simply disappears from all RM reads).
-- Idempotent: WHERE ... AND is_active=1 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE inv_rm_stocktake
   SET is_active = 0
 WHERE id IN (619, 621, 620, 406, 622)
   AND is_active = 1;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 4: Audit trail
--
-- One INSERT...SELECT per changed row (18 total on first apply).
-- Each SELECT's WHERE targets the POST-change state so the INSERT fires only
-- when the change is already applied.  A NOT EXISTS guard on the comment
-- sentinel (unique per row, includes PK) ensures a second apply inserts 0
-- additional audit rows.
-- action='update' throughout — ENUM('insert','update') has no 'delete'.
-- Soft-delete rows use after_json with _tombstone sentinel per project convention.
-- before_json fields reconstructed from pre-flight probe values confirmed live
-- on 2026-06-01.
-- ═══════════════════════════════════════════════════════════════════════════════

-- ─── 4a: Audit ref_mi flag flip — one row per id ─────────────────────────────
--   WHERE is_inventoried=0 fires only after STEP 1 applied to each row.
--   NOT EXISTS guard on comment (per-id, unique) ensures re-apply inserts 0 rows.

INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_256',
    'ref_mi',
    m.id,
    'update',
    JSON_OBJECT(
        'id',             m.id,
        'mi_id',          m.mi_id,
        'gl_account',     m.gl_account,
        'is_inventoried', 1
    ),
    JSON_OBJECT(
        'is_inventoried', 0
    ),
    CONCAT('mig256_flag_ref_mi_', m.id, '_is_inventoried_0')
FROM ref_mi m
WHERE m.id IN (174, 175, 201, 202, 204, 206, 245, 254)
  AND m.is_inventoried = 0
  AND NOT EXISTS (
      SELECT 1 FROM audit_row_revisions a
       WHERE a.comment = CONCAT('mig256_flag_ref_mi_', m.id, '_is_inventoried_0')
  );


-- ─── 4b: Audit inv_rm_stocktake_lines soft-delete — one row per id ───────────
--   WHERE is_active=0 fires only after STEP 2 applied to each row.
--   NOT EXISTS guard on comment (per-id, unique) ensures re-apply inserts 0 rows.

INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_256',
    'inv_rm_stocktake_lines',
    l.id,
    'update',
    JSON_OBJECT(
        'id',        l.id,
        'mi_id_fk',  l.mi_id_fk,
        'mi_id',     l.mi_id,
        'period',    l.period,
        'qty',       l.qty,
        'is_active', 1
    ),
    JSON_OBJECT(
        '_tombstone', 'deleted_by_mig256',
        'is_active',  0
    ),
    CONCAT('mig256_softdelete_line_', l.id, '_', l.mi_id)
FROM inv_rm_stocktake_lines l
WHERE l.id IN (281, 283, 282, 69, 284)
  AND l.is_active = 0
  AND NOT EXISTS (
      SELECT 1 FROM audit_row_revisions a
       WHERE a.comment = CONCAT('mig256_softdelete_line_', l.id, '_', l.mi_id)
  );


-- ─── 4c: Audit inv_rm_stocktake rollup soft-delete — one row per id ──────────
--   WHERE is_active=0 fires only after STEP 3 applied to each row.
--   NOT EXISTS guard on comment (per-id, unique) ensures re-apply inserts 0 rows.

INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_256',
    'inv_rm_stocktake',
    s.id,
    'update',
    JSON_OBJECT(
        'id',          s.id,
        'mi_id_fk',    s.mi_id_fk,
        'mi_id',       s.mi_id,
        'period',      s.period,
        'counted_qty', s.counted_qty,
        'is_active',   1
    ),
    JSON_OBJECT(
        '_tombstone', 'deleted_by_mig256',
        'is_active',  0
    ),
    CONCAT('mig256_softdelete_rollup_', s.id, '_', s.mi_id)
FROM inv_rm_stocktake s
WHERE s.id IN (619, 621, 620, 406, 622)
  AND s.is_active = 0
  AND NOT EXISTS (
      SELECT 1 FROM audit_row_revisions a
       WHERE a.comment = CONCAT('mig256_softdelete_rollup_', s.id, '_', s.mi_id)
  );


COMMIT;
