-- db/migrations/265_parallel_run_recipe_fix.sql
--
-- What: Correct 4 bd_packaging_v2 rows where the normalizer mislabelled the
--       parallel leg of dual-format bottling sessions.  The normalizer trusted
--       the 'Selection Recette' free-text field, which the operator had filled
--       with the WRONG beer.  Ground truth is bd_packaging (v1) .second_packaging.
--
--       The parallel leg of a -4/-B run is ALWAYS the same beer as the main leg;
--       only the format suffix flips.  Volumes are CORRECT — only beer/recipe/
--       sku/format attribution is wrong.
--
-- Corrections (verified against v1 second_packaging):
--   id=2086  neb_beer SPYB→DIBB, recipe_id_fk 51→26 (Diversion Blanche), suffix B, sku 49→11
--   id=2119  neb_beer DIVB→SPYB, recipe_id_fk 25→51 (Speakeasy),          suffix B, sku 16→49
--   id=2158  neb_beer SPY4→STIB, recipe_id_fk 51→52 (Stirling),           suffix B, sku 47→53
--   id=2227  neb_beer SPYB→DIVB, recipe_id_fk 51→25 (Diversion),          suffix B, sku 49→16
--
-- PREREQUISITE: mig265_parallel_run_recipe_fix.py --apply MUST be run on the
--   VPS BEFORE this migration filename is applied by migrate.php.  The Python
--   script performs the actual field UPDATEs + row_hash recompute + audit writes.
--   This SQL file serves as the canonical migration record and registers the
--   filename in schema_migrations.
--
-- The Python script already inserts the schema_migrations row atomically with
-- the corrections.  migrate.php will INSERT IGNORE safely.
--
-- Idempotency: UPDATE guards on the pre-migration neb_beer/recipe_id_fk; a
--   re-run after Python has applied changes 0 rows (WHERE clause fails to match).
--
-- audit_flags: 'mode_a_extraction,correction' appended on each row.
--
-- Date   : 2026-06-05
-- Author : migration_265

-- Safety: these are no-ops if the Python script already applied the changes.
-- The WHERE guards on (neb_beer, recipe_id_fk) from the "wrong" side ensure
-- re-running is completely safe.

-- Audit entries are written by the Python script.
-- SQL UPDATEs here are kept for completeness and idempotency only.
-- row_hash is NOT recomputed here (Python scheme); the Python script handles it.

-- id=2086: SPYB/51 → DIBB/26
UPDATE bd_packaging_v2
   SET neb_beer = 'DIBB', recipe_id_fk = 26, nebuleuse_format_suffix = 'B',
       sku_id_fk = 11
 WHERE id = 2086
   AND neb_beer = 'SPYB'
   AND recipe_id_fk = 51;

-- id=2119: DIVB/25 → SPYB/51
UPDATE bd_packaging_v2
   SET neb_beer = 'SPYB', recipe_id_fk = 51, nebuleuse_format_suffix = 'B',
       sku_id_fk = 49
 WHERE id = 2119
   AND neb_beer = 'DIVB'
   AND recipe_id_fk = 25;

-- id=2158: SPY4/51 → STIB/52
UPDATE bd_packaging_v2
   SET neb_beer = 'STIB', recipe_id_fk = 52, nebuleuse_format_suffix = 'B',
       sku_id_fk = 53
 WHERE id = 2158
   AND neb_beer = 'SPY4'
   AND recipe_id_fk = 51;

-- id=2227: SPYB/51 → DIVB/25
UPDATE bd_packaging_v2
   SET neb_beer = 'DIVB', recipe_id_fk = 25, nebuleuse_format_suffix = 'B',
       sku_id_fk = 16
 WHERE id = 2227
   AND neb_beer = 'SPYB'
   AND recipe_id_fk = 51;
