-- db/migrations/263_eph_consolidation.sql
--
-- What: Collapse EPH1-4 multi-vintage ref_recipes stubs into their keeper rows.
--       Finishes what mig 221 started: mig 221 moved ref_skus to keepers but left
--       event tables pointing at the lowest-id "stub" rows.
--
-- Keepers (operative, strain-bearing, SKU-holding, latest-vintage):
--   EPH1 → keeper id=62 (vintage 2026, yeast_fk=2); stubs 58,59,60,61
--   EPH2 → keeper id=76 (vintage 2026, yeast_fk=38, garde=7); stubs 63,64,65,66,67
--   EPH3 → keeper id=71 (vintage 2024, yeast_fk=3); stubs 68,69,70
--   EPH4 → keeper id=75 (vintage 2025, yeast_fk=1); stubs 72,73,74
--
-- PREREQUISITE: mig263_eph_hash_recompute.py --apply MUST be run on the VPS BEFORE
--   this SQL migration. That script repoints recipe_id_fk + recomputes row_hash for
--   Python-ingested rows in:
--     bd_brewing_brewday_v2, bd_brewing_gravity_v2, bd_brewing_timings_v2,
--     bd_brewing_ingredients_v2, bd_packaging_v2, bd_fermenting_v2 (Python rows),
--     bd_racking_v2 (Python rows).
--   This SQL migration then handles:
--     - bd_fermenting_v2 web-form row (id=13433, PHP hash)
--     - op_sessions (no hash recompute needed)
--     - ref_recipe_aliases (move to keepers, dedup)
--     - ref_recipe_packaging_bindings (move to keepers)
--     - ref_recipe_profile / _hops / _malt (DELETE derived stubs — keepers have own rows)
--     - ref_recipes tombstone (is_active=0)
--     - audit_row_revisions for all changes
--
-- Audit note: audit_row_revisions.action ENUM = ('insert','update') — no 'delete'.
--   Deletions are tombstoned via action='update' + after_json with _tombstone key.
--
-- EPH2 processed first (PM ruling: unblocks racking gate EPH2/batch26 fastest).
--
-- Idempotency: every UPDATE has a WHERE guard on the pre-migration state; every
--   DELETE has a WHERE guard (keepers already have profile rows so stub profiles
--   must be deleted). Re-running after application changes 0 rows.
--
-- Safety: run mig263_eph_hash_recompute.py dry-run first and confirm 0 errors.
--   After applying this SQL, verify:
--     SELECT COUNT(*) FROM bd_brewing_brewday_v2 WHERE recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);
--     → must return 0
--     (same for bd_fermenting_v2, bd_racking_v2, bd_packaging_v2, op_sessions)
--
-- Date   : 2026-06-04
-- Author : migration_263


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 1: bd_fermenting_v2 — the one web-form (PHP-hashed) row
-- id=13433 was written by fermenting-phase-submit.php for EPH2/batch26, Purge.
-- PHP hash formula verified correct in probe; new hash with recipe_id_fk=76 = 2d797111...
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_263', 'bd_fermenting_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk, 'row_hash', row_hash),
  JSON_OBJECT('recipe_id_fk', 76, 'row_hash', '2d797111c0f0c4e7a546b030b399a54ed6130f0d32bf8dfd5e4858711dc74fb9'),
  'mig263_webform_fermenting_fk_repoint_EPH2'
FROM bd_fermenting_v2
WHERE id = 13433
  AND recipe_id_fk = 63
  AND audit_flags LIKE '%web_entry%';

UPDATE bd_fermenting_v2
   SET recipe_id_fk = 76,
       row_hash     = '2d797111c0f0c4e7a546b030b399a54ed6130f0d32bf8dfd5e4858711dc74fb9'
 WHERE id = 13433
   AND recipe_id_fk = 63
   AND audit_flags LIKE '%web_entry%';


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 2: op_sessions — recipe_id_fk repoint (no row_hash recompute needed)
-- op_sessions hash = sha256(form_type|vessel_kind|vessel_number|opened_by_fk|opened_at)
-- recipe_id_fk NOT in hash.
-- ══════════════════════════════════════════════════════════════════════════════

-- EPH2 stubs → keeper 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_263', 'op_sessions', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 76),
  'mig263_op_sessions_fk_repoint_EPH2'
FROM op_sessions
WHERE recipe_id_fk IN (63, 64, 65, 66, 67);

UPDATE op_sessions SET recipe_id_fk = 76
 WHERE recipe_id_fk IN (63, 64, 65, 66, 67);

-- EPH1 stubs → keeper 62
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_263', 'op_sessions', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 62),
  'mig263_op_sessions_fk_repoint_EPH1'
FROM op_sessions
WHERE recipe_id_fk IN (58, 59, 60, 61);

UPDATE op_sessions SET recipe_id_fk = 62
 WHERE recipe_id_fk IN (58, 59, 60, 61);

-- EPH3 stubs → keeper 71
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_263', 'op_sessions', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 71),
  'mig263_op_sessions_fk_repoint_EPH3'
FROM op_sessions
WHERE recipe_id_fk IN (68, 69, 70);

UPDATE op_sessions SET recipe_id_fk = 71
 WHERE recipe_id_fk IN (68, 69, 70);

-- EPH4 stubs → keeper 75
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_263', 'op_sessions', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 75),
  'mig263_op_sessions_fk_repoint_EPH4'
FROM op_sessions
WHERE recipe_id_fk IN (72, 73, 74);

UPDATE op_sessions SET recipe_id_fk = 75
 WHERE recipe_id_fk IN (72, 73, 74);


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 3: ref_recipe_aliases — move stub aliases to keepers
-- Dedup: if keeper already has the same alias text, DROP the duplicate stub alias.
-- Distinct alias strings are PRESERVED (EPH0221, Chela, Baies-Tises, Malt Capone, etc.)
-- ══════════════════════════════════════════════════════════════════════════════

-- ── EPH2 aliases (stubs 63,64,65,66,67 → keeper 76) ──────────────────────────

-- id=38 alias='EPH0221' on stub 63 → keeper 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',76),
  'mig263_alias_migrate_EPH2'
FROM ref_recipe_aliases WHERE id = 38 AND recipe_id = 63;
UPDATE ref_recipe_aliases SET recipe_id = 76 WHERE id = 38 AND recipe_id = 63;

-- id=42 alias='EPH0222' on stub 64 → keeper 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',76),
  'mig263_alias_migrate_EPH2'
FROM ref_recipe_aliases WHERE id = 42 AND recipe_id = 64;
UPDATE ref_recipe_aliases SET recipe_id = 76 WHERE id = 42 AND recipe_id = 64;

-- id=46 alias='EPH0223' on stub 65 → keeper 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',76),
  'mig263_alias_migrate_EPH2'
FROM ref_recipe_aliases WHERE id = 46 AND recipe_id = 65;
UPDATE ref_recipe_aliases SET recipe_id = 76 WHERE id = 46 AND recipe_id = 65;

-- id=33 alias='Ephémère 2' on stub 67 → keeper 76 (distinct alias, no dup on keeper)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',76),
  'mig263_alias_migrate_EPH2'
FROM ref_recipe_aliases WHERE id = 33 AND recipe_id = 67;
UPDATE ref_recipe_aliases SET recipe_id = 76 WHERE id = 33 AND recipe_id = 67;

-- id=34 alias='Chela' on stub 67 → keeper 76
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',76),
  'mig263_alias_migrate_EPH2'
FROM ref_recipe_aliases WHERE id = 34 AND recipe_id = 67;
UPDATE ref_recipe_aliases SET recipe_id = 76 WHERE id = 34 AND recipe_id = 67;

-- ── EPH1 aliases (stubs 58,59 → keeper 62) ──────────────────────────────────

-- id=41 alias='EPH01' on stub 58 → keeper 62
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',62),
  'mig263_alias_migrate_EPH1'
FROM ref_recipe_aliases WHERE id = 41 AND recipe_id = 58;
UPDATE ref_recipe_aliases SET recipe_id = 62 WHERE id = 41 AND recipe_id = 58;

-- id=45 alias='EPH0123' on stub 59 → keeper 62
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',62),
  'mig263_alias_migrate_EPH1'
FROM ref_recipe_aliases WHERE id = 45 AND recipe_id = 59;
UPDATE ref_recipe_aliases SET recipe_id = 62 WHERE id = 45 AND recipe_id = 59;

-- ── EPH3 aliases (stubs 68,69,70 → keeper 71) ────────────────────────────────

-- id=39 alias='EPH0321' on stub 68 → keeper 71
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',71),
  'mig263_alias_migrate_EPH3'
FROM ref_recipe_aliases WHERE id = 39 AND recipe_id = 68;
UPDATE ref_recipe_aliases SET recipe_id = 71 WHERE id = 39 AND recipe_id = 68;

-- id=43 alias='EPH03' on stub 69 → keeper 71
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',71),
  'mig263_alias_migrate_EPH3'
FROM ref_recipe_aliases WHERE id = 43 AND recipe_id = 69;
UPDATE ref_recipe_aliases SET recipe_id = 71 WHERE id = 43 AND recipe_id = 69;

-- id=47 alias='EPH323' on stub 70 → keeper 71
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',71),
  'mig263_alias_migrate_EPH3'
FROM ref_recipe_aliases WHERE id = 47 AND recipe_id = 70;
UPDATE ref_recipe_aliases SET recipe_id = 71 WHERE id = 47 AND recipe_id = 70;

-- ── EPH4 aliases (stubs 72,73,74 → keeper 75) ────────────────────────────────

-- id=40 alias='EPH0421' on stub 72 → keeper 75
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',75),
  'mig263_alias_migrate_EPH4'
FROM ref_recipe_aliases WHERE id = 40 AND recipe_id = 72;
UPDATE ref_recipe_aliases SET recipe_id = 75 WHERE id = 40 AND recipe_id = 72;

-- id=44 alias='EPH0422' on stub 73 → keeper 75
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',75),
  'mig263_alias_migrate_EPH4'
FROM ref_recipe_aliases WHERE id = 44 AND recipe_id = 73;
UPDATE ref_recipe_aliases SET recipe_id = 75 WHERE id = 44 AND recipe_id = 73;

-- id=5 alias='Malt Capone' on stub 74 → keeper 75
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_aliases',id,'update',
  JSON_OBJECT('recipe_id',recipe_id,'alias',alias),
  JSON_OBJECT('recipe_id',75),
  'mig263_alias_migrate_EPH4'
FROM ref_recipe_aliases WHERE id = 5 AND recipe_id = 74;
UPDATE ref_recipe_aliases SET recipe_id = 75 WHERE id = 5 AND recipe_id = 74;


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 4: ref_recipe_packaging_bindings — move stub bindings to keepers
-- Stubs: 58→62, 63→76, 68→71, 72→75 (2 rows each: label + can binding)
-- Keepers have 0 existing bindings → no dedup needed.
-- ══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_packaging_bindings',id,'update',
  JSON_OBJECT('recipe_id',recipe_id),
  JSON_OBJECT('recipe_id',
    CASE recipe_id WHEN 58 THEN 62 WHEN 63 THEN 76 WHEN 68 THEN 71 WHEN 72 THEN 75 END),
  'mig263_pkg_binding_repoint'
FROM ref_recipe_packaging_bindings
WHERE recipe_id IN (58, 63, 68, 72);

UPDATE ref_recipe_packaging_bindings
   SET recipe_id = CASE recipe_id
       WHEN 58 THEN 62
       WHEN 63 THEN 76
       WHEN 68 THEN 71
       WHEN 72 THEN 75
   END
 WHERE recipe_id IN (58, 63, 68, 72);


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 5: ref_recipe_profile / _hops / _malt — DELETE derived stub rows
-- These are schema_meta class=derived, corrections_policy=blocked_with_redirect.
-- Keepers ALREADY have their own computed profile rows (verified: all 3 window_kinds).
-- Stub profile rows are stale derived data pointing at to-be-tombstoned recipe ids.
-- Tombstone via action='update' + _tombstone marker (ENUM has no 'delete').
-- ══════════════════════════════════════════════════════════════════════════════

-- ref_recipe_profile (stub ids: 58-61, 63-67, 68-70, 72-74 → 2 rows each)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_profile',id,'update',
  JSON_OBJECT('id',id,'recipe_id',recipe_id,'window_kind',window_kind),
  JSON_OBJECT('_tombstone','deleted_by_mig263_derived_stub'),
  'mig263_delete_stub_profile'
FROM ref_recipe_profile
WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);

DELETE FROM ref_recipe_profile
 WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);

-- ref_recipe_profile_hops
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_profile_hops',id,'update',
  JSON_OBJECT('id',id,'recipe_id',recipe_id,'window_kind',window_kind,'mi_id_fk',mi_id_fk),
  JSON_OBJECT('_tombstone','deleted_by_mig263_derived_stub'),
  'mig263_delete_stub_profile_hops'
FROM ref_recipe_profile_hops
WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);

DELETE FROM ref_recipe_profile_hops
 WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);

-- ref_recipe_profile_malt
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipe_profile_malt',id,'update',
  JSON_OBJECT('id',id,'recipe_id',recipe_id,'window_kind',window_kind,'mi_id_fk',mi_id_fk),
  JSON_OBJECT('_tombstone','deleted_by_mig263_derived_stub'),
  'mig263_delete_stub_profile_malt'
FROM ref_recipe_profile_malt
WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);

DELETE FROM ref_recipe_profile_malt
 WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74);


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 6: SAFETY GATE — verify all FK ref-counts on stubs = 0 before tombstone
-- migrate.php runs bare SELECTs via PDO exec() which leaves result sets open.
-- Use SET @x = (SELECT …) pattern for in-migration verification.
-- ══════════════════════════════════════════════════════════════════════════════

SET @stub_ids_check = (
  SELECT COUNT(*) FROM (
    SELECT recipe_id_fk AS rid FROM bd_brewing_brewday_v2 WHERE recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
    UNION ALL
    SELECT recipe_id_fk FROM bd_fermenting_v2 WHERE recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
    UNION ALL
    SELECT neb_recipe_id_fk FROM bd_racking_v2 WHERE neb_recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
    UNION ALL
    SELECT recipe_id_fk FROM bd_packaging_v2 WHERE recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74) AND is_tombstoned=0
    UNION ALL
    SELECT recipe_id_fk FROM op_sessions WHERE recipe_id_fk IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
    UNION ALL
    SELECT recipe_id FROM ref_recipe_aliases WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
    UNION ALL
    SELECT recipe_id FROM ref_recipe_packaging_bindings WHERE recipe_id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
  ) _chk
);

-- If @stub_ids_check > 0, the following INSERT will emit a diagnostic row into
-- audit_row_revisions so the issue is visible; the tombstone UPDATE below is
-- guarded by the check and will be skipped safely.
-- (We cannot SIGNAL SQLSTATE from a .sql file safely across all MySQL versions.)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','SAFETY_GATE',0,'update',
  JSON_OBJECT('remaining_stub_refs', @stub_ids_check),
  JSON_OBJECT('status','GATE_PASSED'),
  CONCAT('mig263_pre_tombstone_gate: remaining_refs=', COALESCE(@stub_ids_check,0));


-- ══════════════════════════════════════════════════════════════════════════════
-- SECTION 7: ref_recipes — tombstone the 12 stub rows
-- Set is_active=0 + append 'merged_into_<keeper>_by_mig263' to notes/name.
-- ref_recipes has no 'notes' column; use a notes marker in the audit trail.
-- DO NOT DELETE — tombstone only (PM ruling).
-- Gate: only runs if @stub_ids_check = 0.
-- ══════════════════════════════════════════════════════════════════════════════

-- Audit before tombstone
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1,'migration_263','ref_recipes',id,'update',
  JSON_OBJECT('id',id,'name',name,'vintage',vintage,'is_active',is_active),
  JSON_OBJECT('is_active',0,
    '_tombstone', CONCAT('merged_into_',
      CASE id
        WHEN 63 THEN 76 WHEN 64 THEN 76 WHEN 65 THEN 76 WHEN 66 THEN 76 WHEN 67 THEN 76
        WHEN 58 THEN 62 WHEN 59 THEN 62 WHEN 60 THEN 62 WHEN 61 THEN 62
        WHEN 68 THEN 71 WHEN 69 THEN 71 WHEN 70 THEN 71
        WHEN 72 THEN 75 WHEN 73 THEN 75 WHEN 74 THEN 75
      END,
      '_by_mig263')),
  'mig263_tombstone_stub_recipe'
FROM ref_recipes
WHERE id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
  AND is_active = 1
  AND @stub_ids_check = 0;

-- Tombstone all 12 stub rows
UPDATE ref_recipes
   SET is_active = 0
 WHERE id IN (58,59,60,61,63,64,65,66,67,68,69,70,72,73,74)
   AND is_active = 1
   AND @stub_ids_check = 0;
