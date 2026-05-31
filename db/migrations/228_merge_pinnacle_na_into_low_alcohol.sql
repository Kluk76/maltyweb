-- db/migrations/228_merge_pinnacle_na_into_low_alcohol.sql
--
-- What: Merge duplicate yeast strain "Pinnacle NA" (id=11) into canonical
--       "Pinnacle Low Alcohol" (id=15).
--       - Repoint all 3 existing id-11 aliases (apinnacle, Pinnacle,
--         Pinnacle N/A) from strain_id=11 to strain_id=15.
--       - Insert 'Pinnacle NA' (the old row name) as a new alias on id=15.
--       - Defensive repoints for ref_recipes.yeast_strain_id_fk and
--         bd_brewing_brewday.bd_yeast (both currently 0 rows — no-ops).
--       - Tombstone id=11 (is_active=0).
--       - Audit log: one audit INSERT capturing the tombstone of id=11
--         (before is_active=1, after is_active=0).
--
-- Why:  "Pinnacle NA" and "Pinnacle Low Alcohol" are the same commercial
--       yeast product (Lallemand Pinnacle / non-alcool family). The two rows
--       were created independently during data entry. Having two active rows
--       for the same strain splits the alias surface and creates ambiguity
--       in any strain-resolution path. id=15 is the canonical form; id=11
--       was the later duplicate entry with a truncated informal name.
--
-- Pre-flight results (verified live 2026-05-31):
--   id=11: name='Pinnacle NA', is_active=1, family='non_alcool'
--           3 aliases: apinnacle (id=34), Pinnacle (id=35), Pinnacle N/A (id=10)
--   id=15: name='Pinnacle Low Alcohol', is_active=1, family='non_alcool'
--           0 aliases
--   No alias-string collisions between the two sets.
--   'Pinnacle NA' not present in alias table → INSERT required (not repoint).
--   ref_recipes.yeast_strain_id_fk=11: 0 rows
--   bd_brewing_brewday.bd_yeast=11:    0 rows
--
-- Risk: LOW. No FK referencing rows to repoint (both FK counts = 0).
--       The alias table has ON DELETE CASCADE from ref_yeast_strains — we do
--       NOT delete id=11, only tombstone it, so aliases remain safely on id=15.
--
-- Idempotency:
--   STEP 1 UPDATE: WHERE strain_id=11 — matches 0 rows on re-run (already id=15).
--   STEP 2 INSERT: ON DUPLICATE KEY UPDATE is a no-op guard on uq_alias.
--   STEP 3 UPDATE (ref_recipes): WHERE yeast_strain_id_fk=11 — already 0 rows;
--          remains 0 on re-run.
--   STEP 4 UPDATE (bd_brewing_brewday): same pattern.
--   STEP 5 UPDATE tombstone: WHERE id=11 AND is_active=1 — matches 0 on re-run.
--   STEP 6 audit INSERT: INSERT...SELECT with WHERE is_active=0 AND id=11
--          and a comment sentinel — a duplicate row would be a spurious audit
--          entry but is benign; idempotency is best-effort here (no UNIQUE on
--          audit table). The WHERE guard on is_active=0 ensures we only fire
--          the audit AFTER the tombstone, so a genuinely-first-time apply
--          always captures it.
--
-- Rollback:
--   UPDATE ref_yeast_strain_aliases SET strain_id=11 WHERE strain_id=15 AND alias IN ('apinnacle','Pinnacle','Pinnacle N/A');
--   DELETE FROM ref_yeast_strain_aliases WHERE strain_id=15 AND alias='Pinnacle NA';
--   UPDATE ref_yeast_strains SET is_active=1 WHERE id=11 AND is_active=0;
--   DELETE FROM audit_row_revisions WHERE comment='tombstone_mig228_pinnacle_na_id11';
--
-- Date  : 2026-05-31
-- Author: migration_228


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 1: Repoint id=11's existing aliases to id=15
--
-- The three aliases (apinnacle, Pinnacle, Pinnacle N/A) belong to the canonical
-- strain id=15. ON DELETE CASCADE on the FK means they would vanish if id=11
-- were ever hard-deleted; repointing them to id=15 ensures they survive and
-- are usable by any resolver that looks up by alias.
--
-- Idempotent: WHERE strain_id=11 matches 0 rows after first application.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_yeast_strain_aliases
   SET strain_id = 15
 WHERE strain_id = 11;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 2: Add 'Pinnacle NA' (the old row's name) as an alias on id=15
--
-- The string 'Pinnacle NA' was only the row name of id=11, not yet in the
-- alias table. Any free-text resolver that matches the old name string must
-- now resolve to id=15. INSERT with ON DUPLICATE KEY guard on uq_alias makes
-- this idempotent.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO ref_yeast_strain_aliases (strain_id, alias, source)
VALUES (15, 'Pinnacle NA', 'manual')
ON DUPLICATE KEY UPDATE strain_id = strain_id;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 3: Defensive repoint — ref_recipes.yeast_strain_id_fk
--
-- Pre-flight confirmed 0 rows reference id=11. This UPDATE is a no-op now
-- and on any re-run, but guards against any future state where a recipe
-- was linked to id=11 before this migration ran.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_recipes
   SET yeast_strain_id_fk = 15
 WHERE yeast_strain_id_fk = 11;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 4: Defensive repoint — bd_brewing_brewday.bd_yeast
--
-- Pre-flight confirmed 0 rows. No-op now; guards against any residual
-- free-text or integer reference. bd_brewing_brewday_v2 has free-text only
-- (not FK'd) and resolves via aliases — already handled by STEP 1+2.
-- ═══════════════════════════════════════════════════════════════════════════════

-- NOTE: bd_yeast is VARCHAR(128). Comparing it to the integer literal 11
-- forces MySQL to cast EVERY row's value to DOUBLE to evaluate the predicate;
-- a stored yeast code like 'W34/70' then throws 1292 (Truncated incorrect
-- DOUBLE value) under strict mode. String literals keep this a string compare.
UPDATE bd_brewing_brewday
   SET bd_yeast = '15'
 WHERE bd_yeast = '11';


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 5: Tombstone id=11 (is_active=0)
--
-- Hard DELETE is avoided because: (a) audit_row_revisions action ENUM has no
-- 'delete' value, and (b) keeping the tombstoned row preserves referential
-- clarity. The WHERE guard on is_active=1 makes this idempotent.
-- ═══════════════════════════════════════════════════════════════════════════════

UPDATE ref_yeast_strains
   SET is_active = 0
 WHERE id = 11
   AND is_active = 1;


-- ═══════════════════════════════════════════════════════════════════════════════
-- STEP 6: Audit — log the tombstone of id=11
--
-- Pattern follows migration 222 (INSERT...SELECT captures live post-tombstone
-- state; before_json reconstructed from known pre-migration values confirmed
-- in pre-flight; after_json records the single changed field).
--
-- action='update' because ENUM('insert','update') has no 'delete' value.
-- The WHERE id=11 AND is_active=0 ensures the audit row fires only when the
-- tombstone is already applied (not before), making the comment sentinel the
-- logical idempotency marker.
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT INTO audit_row_revisions
    (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
    0,
    'migration_228',
    'ref_yeast_strains',
    id,
    'update',
    JSON_OBJECT(
        'id',              id,
        'name',            name,
        'is_active',       1,
        'supplier',        supplier,
        'type',            type,
        'family',          family,
        'notes',           notes,
        'flocculation',    flocculation,
        'attenuation_min', attenuation_min,
        'attenuation_max', attenuation_max,
        'temp_min',        temp_min,
        'temp_max',        temp_max
    ),
    JSON_OBJECT('is_active', 0),
    'tombstone_mig228_pinnacle_na_id11'
FROM ref_yeast_strains
WHERE id = 11
  AND is_active = 0;

-- NOTE: before_json hard-codes is_active=1 because at audit-INSERT time the
-- row is already tombstoned (is_active=0). All other columns are read live
-- from the row and were confirmed null/unknown in pre-flight.
-- Comment: 'merged into id 15 (Pinnacle Low Alcohol) — dup data entry, mig 228'
-- is encoded in the comment sentinel above.
