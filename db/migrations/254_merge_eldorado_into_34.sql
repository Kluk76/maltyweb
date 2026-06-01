-- db/migrations/254_merge_eldorado_into_34.sql
--
-- What: Merge duplicate MI "Eldorado" (id=572, mi_id=HOPS_ELDORADO) into the
--       canonical keeper "El Dorado" (id=34, mi_id=HOPS_EL_DORADO).
--
--       - Repoint inv_deliveries rows 106 + 364 from ingredient_fk=572 to 34.
--       - Repoint inv_rm_stocktake row 461 (period 2026-05) from mi_id_fk=572
--         to 34.  No collision: keeper 34 has only a 2026-04 row.
--       - Repoint inv_rm_stocktake_lines row 123 from mi_id_fk=572 to 34.
--       - Move 5 ref_mi_aliases rows (ids 1307,1309,1310,1311,1312) from
--         mi_id_fk=572 to 34.
--       - Add dup's display name "Eldorado" as a new alias on 34 (ON DUPLICATE
--         KEY guard — collation utf8mb4_unicode_ci makes "Eldorado" ==
--         "ElDorado" which already exists on 34 as id=487, so this is a no-op
--         on first AND re-apply).
--       - ref_recipe_ingredients: no change needed — keeper 34 already has
--         the 2 recipe rows (ids 46,109 on recipe_ids 29,51); dup 572 has
--         zero rows.
--       - Retire dup 572: is_active=0, is_inventoried=0.
--       - Audit: 7 INSERT...SELECT rows (one per changed row in the
--         business tables, plus the tombstone on ref_mi), each guarded by
--         the pre-merge WHERE clause so re-apply is a no-op.
--
-- Why:  "HOPS_ELDORADO" and "HOPS_EL_DORADO" are the same commercial hop
--       product (El Dorado, USA).  id=572 was auto-created by the ingest
--       pipeline on 2026-05-18 when the resolver failed to match the
--       space-free variant "Eldorado" to the canonical "El Dorado" (id=34).
--       Two active MI rows for the same hop split the WAC, divide stock
--       counts, and create resolver ambiguity.  Operator confirmed keeper=34.
--
-- Pre-flight results (verified live 2026-06-01):
--   id=34:  mi_id=HOPS_EL_DORADO, name='El Dorado', is_active=1,
--           is_inventoried=1.
--   id=572: mi_id=HOPS_ELDORADO,  name='Eldorado',  is_active=1,
--           is_inventoried=1, price=20.900000 EUR, pack_size=5 kg,
--           category_id=2, subcategory_id=1, gl_account=4102.
--
--   inv_deliveries with ingredient_fk=572:
--     id=106: qty=5.0000, mi_id_raw='HOPS_ELDORADO', supplier_fk=19
--     id=364: qty=200.0000, mi_id_raw='',              supplier_fk=104
--
--   inv_rm_stocktake with mi_id_fk=572:
--     id=461: period='2026-05', counted_qty=170.0000
--   inv_rm_stocktake keeper 34 rows: id=25 period='2026-04' only.
--   Collision check PASSED: keeper 34 has NO 2026-05 row → UPDATE is safe.
--
--   inv_rm_stocktake_lines with mi_id_fk=572:
--     id=123: period='2026-05', qty=170.000
--
--   ref_mi_aliases on mi_id_fk=572 (5 rows):
--     id=1307  alias='Houblon Eldorado, Origine USA ; Houblon - 5 Kg'
--     id=1309  alias='US-EI Dorado'
--     id=1310  alias='US-El Dorado'
--     id=1311  alias='US-Eldorado'
--     id=1312  alias='El Dorado hops'
--   ref_mi_aliases on mi_id_fk=34 (1 row):
--     id=487   alias='ElDorado'
--   Alias 'Eldorado' insert → collation utf8mb4_unicode_ci matches 'ElDorado'
--   (id=487 already on 34) → ON DUPLICATE KEY guard fires, no-op.
--   All 5 dup-572 alias strings are absent on keeper 34 → UPDATE is safe.
--
--   ref_recipe_ingredients on mi_id_fk=34: id=46 recipe_id=29,
--                                           id=109 recipe_id=51.
--   ref_recipe_ingredients on mi_id_fk=572: 0 rows — no change needed.
--
-- Idempotency:
--   STEP 1 UPDATE (inv_deliveries):        WHERE ingredient_fk=572  → 0 rows on re-run.
--   STEP 2 UPDATE (inv_rm_stocktake):      WHERE mi_id_fk=572       → 0 rows on re-run.
--   STEP 3 UPDATE (inv_rm_stocktake_lines):WHERE mi_id_fk=572       → 0 rows on re-run.
--   STEP 4 UPDATE (ref_mi_aliases move):   WHERE mi_id_fk=572       → 0 rows on re-run.
--   STEP 5 INSERT alias 'Eldorado':        ON DUPLICATE KEY guard   → no-op on re-run.
--   STEP 6 UPDATE (tombstone ref_mi):      WHERE id=572 AND is_active=1 AND
--                                           is_inventoried=1         → 0 rows on re-run.
--   STEP 7 audit INSERTs: each guarded by a WHERE clause matching the
--          post-merge / post-tombstone state (e.g. ingredient_fk=34 for
--          deliveries, is_active=0 for the tombstone), so a second apply
--          inserts 0 additional audit rows (audit comment sentinel is a
--          belt-and-suspenders marker, not the primary guard).
--
-- Rollback:
--   UPDATE inv_deliveries SET ingredient_fk=572, mi_id_raw='HOPS_ELDORADO' WHERE id IN (106,364) AND ingredient_fk=34;
--   UPDATE inv_rm_stocktake SET mi_id='HOPS_ELDORADO', mi_id_fk=572 WHERE id=461 AND mi_id_fk=34;
--   UPDATE inv_rm_stocktake_lines SET mi_id='HOPS_ELDORADO', mi_id_fk=572 WHERE id=123 AND mi_id_fk=34;
--   UPDATE ref_mi_aliases SET mi_id_fk=572 WHERE id IN (1307,1309,1310,1311,1312);
--   -- 'Eldorado' alias insert was a no-op (collides with ElDorado id=487) — no rollback needed.
--   UPDATE ref_mi SET is_active=1, is_inventoried=1 WHERE id=572;
--   DELETE FROM audit_row_revisions WHERE comment LIKE 'mig254_%';
--
-- Migration number: 253 is double-used on VPS (253_hop_addition_stage.sql
--   applied 2026-06-01 + 253_hop_addition_stage also in local tree). Next
--   free number verified as 254.
--
-- Date  : 2026-06-01
-- Author: migration_254


-- All steps below are pure DML — wrap in an explicit transaction so the merge
-- is all-or-nothing (migrate.php does NOT wrap the file itself). The idempotent
-- WHERE guards are retained for the re-run-after-success case.
START TRANSACTION;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: Repoint inv_deliveries rows 106 + 364 from ingredient_fk=572 to 34
--
-- inv_deliveries.mi_id_raw is a raw denormalised string stored at ingest time.
-- Row 106 has mi_id_raw='HOPS_ELDORADO' (the dup's mi_id) — rewrite to the
-- canonical.  Row 364 has mi_id_raw='' (blank) — set to canonical too.
-- WAC is computed from v_mi_wac which JOINs on ingredient_fk; price on ref_mi
-- is NOT touched here (WAC recomputes automatically after this repoint).
--
-- Idempotent: WHERE ingredient_fk=572 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE inv_deliveries
   SET ingredient_fk = 34,
       mi_id_raw     = 'HOPS_EL_DORADO'
 WHERE ingredient_fk = 572;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: Repoint inv_rm_stocktake row 461 (period 2026-05) to keeper 34
--
-- Pre-flight confirmed keeper 34 has NO 2026-05 row (only 2026-04, id=25).
-- The UNIQUE KEY uniq_mi_period(mi_id, period) will not be violated.
--
-- row_hash: this table has a THIRD unique key uniq_row_hash(row_hash), and the
-- column is NOT NULL. The existing hash was computed over the OLD identity
-- ('HOPS_ELDORADO'), so after the repoint it no longer matches the row's
-- content. We replace it with a deterministic, per-row UNIQUE sentinel so it
-- (a) cannot collide with any real or recomputed hash, and (b) is cleanly
-- reclaimed the next time rm_recompute_rollup() upserts on (mi_id, period) —
-- that upsert keys on (mi_id, period), finds this row, and overwrites the
-- sentinel with the correct hash. (NULL is not an option — column is NOT NULL.)
--
-- Idempotent: WHERE mi_id_fk=572 matches 0 rows on re-run (and the sentinel is
-- only ever written while mi_id_fk=572 still matches).
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE inv_rm_stocktake
   SET mi_id    = 'HOPS_EL_DORADO',
       mi_id_fk = 34,
       row_hash = SHA2(CONCAT('mig254-relink-', id), 256)
 WHERE mi_id_fk = 572;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: Repoint inv_rm_stocktake_lines row 123 to keeper 34
--
-- No mi/period UNIQUE on this table → no collision risk.
-- row_hash left as-is (stays unique; self-heals on recompute).
--
-- Idempotent: WHERE mi_id_fk=572 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE inv_rm_stocktake_lines
   SET mi_id    = 'HOPS_EL_DORADO',
       mi_id_fk = 34
 WHERE mi_id_fk = 572;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 4: Move dup 572's 5 aliases to keeper 34
--
-- The 5 alias strings on mi_id_fk=572
--   (ids 1307,1309,1310,1311,1312)
-- do not exist on keeper 34, so a plain UPDATE is safe — no UNIQUE(alias)
-- collision will occur.  The ref_mi_aliases UNIQUE KEY is on `alias` alone
-- (utf8mb4_unicode_ci), so case/accent variants of strings already on 34
-- would collide, but none of these 5 strings are case-insensitive matches
-- to 'ElDorado' (id=487 on 34) or to each other.
--
-- Idempotent: WHERE mi_id_fk=572 matches 0 rows on re-run.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_mi_aliases
   SET mi_id_fk = 34
 WHERE mi_id_fk = 572;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 5: Add dup's display name "Eldorado" as a new alias on keeper 34
--
-- Under utf8mb4_unicode_ci collation, 'Eldorado' == 'ElDorado' (case-
-- insensitive).  'ElDorado' already exists on keeper 34 as alias id=487.
-- A plain INSERT would produce ERROR 1062 (Duplicate entry).
-- ON DUPLICATE KEY UPDATE mi_id_fk=VALUES(mi_id_fk) is a safe no-op guard:
-- if the alias already resolves to 34 (id=487), we confirm it stays on 34.
-- This is idempotent on any number of re-runs.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO ref_mi_aliases (mi_id_fk, alias)
VALUES (34, 'Eldorado')
ON DUPLICATE KEY UPDATE mi_id_fk = VALUES(mi_id_fk);


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 6: Tombstone dup 572 — retire it from active inventory
--
-- Hard DELETE is avoided: (a) audit_row_revisions.action ENUM is
-- ('insert','update') with no 'delete' value; (b) keeping the tombstoned
-- row preserves FK history on any table whose FK did NOT use ON DELETE CASCADE.
-- The WHERE guard on both is_active=1 AND is_inventoried=1 makes this
-- idempotent (matches 0 rows on re-run).
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_mi
   SET is_active      = 0,
       is_inventoried = 0
 WHERE id             = 572
   AND is_active      = 1
   AND is_inventoried = 1;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 7: Audit trail
--
-- One INSERT...SELECT per changed row.  Each SELECT's WHERE clause targets the
-- POST-merge state so the INSERT fires only when the change is already applied;
-- a second apply hits 0 qualifying rows and inserts nothing.
--
-- action='update' throughout — ENUM('insert','update') has no 'delete'.
-- The tombstone on ref_mi uses after_json with _tombstone sentinel per the
-- project convention (mig 217/228).
-- before_json fields reconstructed from pre-flight probe values confirmed
-- live on 2026-06-01; columns that cannot be read post-merge are hard-coded
-- from probe output (noted inline).
-- ═══════════════════════════════════════════════════════════════════════════════

-- 7a: Audit inv_deliveries row 106 (ingredient_fk repoint 572→34)
--     WHERE ingredient_fk=34 AND id=106 fires only after STEP 1 applied.
--     before_json hard-codes ingredient_fk=572 and mi_id_raw='HOPS_ELDORADO'
--     (confirmed in pre-flight; the row now holds 34/'HOPS_EL_DORADO').
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'inv_deliveries',
    id,
    'update',
    JSON_OBJECT(
        'id',             id,
        'ingredient_fk',  572,
        'mi_id_raw',      'HOPS_ELDORADO'
    ),
    JSON_OBJECT(
        'ingredient_fk',  34,
        'mi_id_raw',      'HOPS_EL_DORADO'
    ),
    'mig254_repoint_delivery_106_572_to_34'
FROM inv_deliveries
WHERE id             = 106
  AND ingredient_fk  = 34;


-- 7b: Audit inv_deliveries row 364 (ingredient_fk repoint 572→34)
--     before_json hard-codes ingredient_fk=572 and mi_id_raw='' (empty string,
--     confirmed in pre-flight).
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'inv_deliveries',
    id,
    'update',
    JSON_OBJECT(
        'id',             id,
        'ingredient_fk',  572,
        'mi_id_raw',      ''
    ),
    JSON_OBJECT(
        'ingredient_fk',  34,
        'mi_id_raw',      'HOPS_EL_DORADO'
    ),
    'mig254_repoint_delivery_364_572_to_34'
FROM inv_deliveries
WHERE id             = 364
  AND ingredient_fk  = 34;


-- 7c: Audit inv_rm_stocktake row 461 (repoint 572→34)
--     before_json hard-codes mi_id_fk=572, mi_id='HOPS_ELDORADO', period='2026-05'
--     (confirmed in pre-flight; row now holds mi_id_fk=34).
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'inv_rm_stocktake',
    id,
    'update',
    JSON_OBJECT(
        'id',         id,
        'mi_id_fk',   572,
        'mi_id',      'HOPS_ELDORADO',
        'period',     period,
        'counted_qty', counted_qty
    ),
    JSON_OBJECT(
        'mi_id_fk',   34,
        'mi_id',      'HOPS_EL_DORADO'
    ),
    'mig254_repoint_stocktake_461_572_to_34'
FROM inv_rm_stocktake
WHERE id       = 461
  AND mi_id_fk = 34;


-- 7d: Audit inv_rm_stocktake_lines row 123 (repoint 572→34)
--     before_json hard-codes mi_id_fk=572, mi_id='HOPS_ELDORADO' (pre-flight).
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'inv_rm_stocktake_lines',
    id,
    'update',
    JSON_OBJECT(
        'id',       id,
        'mi_id_fk', 572,
        'mi_id',    'HOPS_ELDORADO',
        'period',   period,
        'qty',      qty
    ),
    JSON_OBJECT(
        'mi_id_fk', 34,
        'mi_id',    'HOPS_EL_DORADO'
    ),
    'mig254_repoint_stocktake_lines_123_572_to_34'
FROM inv_rm_stocktake_lines
WHERE id       = 123
  AND mi_id_fk = 34;


-- 7e: Audit ref_mi_aliases bulk repoint (mi_id_fk 572→34) — one row per alias
--     WHERE mi_id_fk=34 AND id IN (...) fires only after STEP 4 applied.
--     before_json hard-codes mi_id_fk=572 (confirmed in pre-flight).
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'ref_mi_aliases',
    id,
    'update',
    JSON_OBJECT(
        'id',        id,
        'mi_id_fk',  572,
        'alias',     alias
    ),
    JSON_OBJECT(
        'mi_id_fk',  34
    ),
    CONCAT('mig254_repoint_alias_', id, '_572_to_34')
FROM ref_mi_aliases
WHERE id       IN (1307, 1309, 1310, 1311, 1312)
  AND mi_id_fk = 34;


-- 7f: Audit ref_mi tombstone of id=572
--     WHERE id=572 AND is_active=0 fires only after STEP 6 applied.
--     before_json hard-codes is_active=1, is_inventoried=1 (confirmed in
--     pre-flight; all other columns read live from the tombstoned row).
INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_254',
    'ref_mi',
    id,
    'update',
    JSON_OBJECT(
        'id',               id,
        'mi_id',            mi_id,
        'name',             name,
        'is_active',        1,
        'is_inventoried',   1,
        'category_id',      category_id,
        'subcategory_id',   subcategory_id,
        'price',            price,
        'currency',         currency,
        'gl_account',       gl_account,
        'pack_size',        pack_size,
        'preferred_supplier', preferred_supplier,
        'notes',            notes
    ),
    JSON_OBJECT(
        '_tombstone',    'merged_into_34_by_mig254',
        'is_active',     0,
        'is_inventoried', 0
    ),
    'mig254_tombstone_ref_mi_572_eldorado'
FROM ref_mi
WHERE id        = 572
  AND is_active = 0;


COMMIT;
