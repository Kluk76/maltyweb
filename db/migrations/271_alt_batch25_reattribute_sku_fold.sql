-- db/migrations/271_alt_batch25_reattribute_sku_fold.sql
--
-- What: Two surgical data fixes, operator-authorized 2026-06-07:
--
--   TASK 1 — Re-attribute (recipe_id_fk=76, batch='25') chain → recipe 6 (ALT)
--     "Alternative" was promoted from EPH2 seasonal. Its first brew is
--     batch='25' (event_date=2025-05-09), currently attributed to the EPH2
--     row (recipe 76). Re-point recipe_id_fk to ALT (recipe 6) across all
--     event tables. Raw batch labels preserved as-is. Packaging events
--     (bd_packaging_v2 ids 1730+1732) get recipe_id_fk=6; sku_id_fk stays
--     (EPH2B/EPH2F — physical packaging was EPH2-branded). EPH24PB
--     (ref_skus.id=75) recipe_id updated to 6; its BOM is recompiled
--     separately via sku-bom-compile-cli.php --apply --sku 75.
--
--   TASK 2 — Catalog folds (rotate-listing sku_code dedup):
--     EPHB12 (ref_skus.id=305)  → canonical EPH4B12 (id=67)
--     EPH4PB (ref_skus.id=304)  → canonical EPH44PB (id=76, ref_skus)
--     Alias inserts + deactivate folded rows + delete folded BOM rows.
--     inv_sales_order_lines left intact (blocked_with_redirect per schema_meta;
--     prior folds (mig150, mig185, mig187) all preserved historical sales rows).
--
-- Verified facts (dry-run 2026-06-07):
--   recipe_id_fk NOT in uq_natural_key nor in row_hash for any bd_* table
--   → pure recipe_id_fk UPDATE, no row_hash recompute required.
--   All target tables: schema_meta.corrections_policy = 'allowed'.
--   Snapshot pre-migration: data/snapshots/mig271-alt-reattribute-sku-fold-*.json
--
-- Row counts to be changed:
--   bd_brewing_brewday_v2     : 1 (id=1175)
--   bd_brewing_gravity_v2     : 4 (ids 1774,3946,6102,8334)
--   bd_brewing_timings_v2     : 1 (id=1820)
--   bd_brewing_ingredients_v2 : 1 (id=210)
--   bd_fermenting_v2          : 5 (ids 167,4675,4680,5759,6530)
--   bd_racking_v2             : 1 (id=255)
--   bd_packaging_v2           : 2 (ids 1730,1732) — recipe_id_fk only; sku_id_fk kept
--   ref_skus EPH24PB          : 1 (id=75) recipe_id 76→6
--   ref_skus EPHB12           : 1 (id=305) is_active 1→0
--   ref_skus EPH4PB           : 1 (id=304) is_active 1→0
--   ref_sku_aliases           : +2 (EPHB12→67, EPH4PB→76)
--   ref_sku_bom               : DELETE 9 rows (sku_id 304=4 rows, 305=5 rows)
--
-- NOT touched: op_sessions id=28 (recipe 76, batch='26' — different batch, leave);
--   other EPH2 vintages (batches 21-24,26); historical inv_sales_order_lines.
--
-- Idempotency: every UPDATE guarded on pre-migration state (recipe_id_fk value or
--   is_active value). Re-running after application changes 0 rows.
--
-- Rollback:
--   Re-point recipe_id_fk back to 76 on the same id lists.
--   UPDATE ref_skus SET recipe_id=76 WHERE id=75 AND recipe_id=6;
--   UPDATE ref_skus SET is_active=1 WHERE id IN (304,305) AND is_active=0;
--   DELETE FROM ref_sku_aliases WHERE alias IN ('EPHB12','EPH4PB');
--   Re-insert ref_sku_bom rows from snapshot.
--
-- Date   : 2026-06-07
-- Author : migration_271 (operator-authorized surgical fix)
-- Risk   : MEDIUM — COGS/beer-tax-impacting; approved and dry-run-verified


-- ══════════════════════════════════════════════════════════════════════════════
-- TASK 1 — Re-attribute EPH2 batch 25 → ALT (recipe 6)
-- ══════════════════════════════════════════════════════════════════════════════

-- ── SECTION 1a: bd_brewing_brewday_v2 ─────────────────────────────────────────
-- id=1175: EPH2 batch 25 (2025-05-09) → ALT recipe 6

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_brewing_brewday_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 6),
  'mig271_task1_brewday_EPH2batch25_to_ALT'
FROM bd_brewing_brewday_v2
WHERE id = 1175
  AND recipe_id_fk = 76
  AND batch = '25';

UPDATE bd_brewing_brewday_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE id = 1175
   AND recipe_id_fk = 76
   AND batch = '25';


-- ── SECTION 1b: bd_brewing_gravity_v2 ─────────────────────────────────────────
-- 4 rows: EPH2 batch 25 gravity readings

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_brewing_gravity_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 6),
  'mig271_task1_gravity_EPH2batch25_to_ALT'
FROM bd_brewing_gravity_v2
WHERE recipe_id_fk = 76
  AND batch = '25';

UPDATE bd_brewing_gravity_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE recipe_id_fk = 76
   AND batch = '25';


-- ── SECTION 1c: bd_brewing_timings_v2 ─────────────────────────────────────────
-- 1 row: EPH2 batch 25 timing

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_brewing_timings_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 6),
  'mig271_task1_timings_EPH2batch25_to_ALT'
FROM bd_brewing_timings_v2
WHERE recipe_id_fk = 76
  AND batch = '25';

UPDATE bd_brewing_timings_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE recipe_id_fk = 76
   AND batch = '25';


-- ── SECTION 1d: bd_brewing_ingredients_v2 ─────────────────────────────────────
-- 1 row: EPH2 batch 25 parsed ingredients blob

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_brewing_ingredients_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 6),
  'mig271_task1_ingredients_EPH2batch25_to_ALT'
FROM bd_brewing_ingredients_v2
WHERE recipe_id_fk = 76
  AND batch = '25';

UPDATE bd_brewing_ingredients_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE recipe_id_fk = 76
   AND batch = '25';


-- ── SECTION 1e: bd_fermenting_v2 ──────────────────────────────────────────────
-- 5 rows: EPH2 batch 25 fermenting events (Reads×2, DryHop, ColdCrash, Purge)

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_fermenting_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk),
  JSON_OBJECT('recipe_id_fk', 6),
  'mig271_task1_fermenting_EPH2batch25_to_ALT'
FROM bd_fermenting_v2
WHERE recipe_id_fk = 76
  AND batch = '25';

UPDATE bd_fermenting_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE recipe_id_fk = 76
   AND batch = '25';


-- ── SECTION 1f: bd_racking_v2 ─────────────────────────────────────────────────
-- 1 row: EPH2 batch 25 racking event (neb lane; id=255, 2025-05-27)

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_racking_v2', id, 'update',
  JSON_OBJECT('neb_recipe_id_fk', neb_recipe_id_fk),
  JSON_OBJECT('neb_recipe_id_fk', 6),
  'mig271_task1_racking_EPH2batch25_to_ALT'
FROM bd_racking_v2
WHERE neb_recipe_id_fk = 76
  AND neb_batch = '25';

UPDATE bd_racking_v2
   SET neb_recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute'))
 WHERE neb_recipe_id_fk = 76
   AND neb_batch = '25';


-- ── SECTION 1g: bd_packaging_v2 ───────────────────────────────────────────────
-- 2 rows: ids 1730 (EPH2B, sku 31), 1732 (EPH2F, sku 33) — event_date 2025-05-30
-- recipe_id_fk 76→6; sku_id_fk KEPT (physical packaging was EPH2-branded)

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'bd_packaging_v2', id, 'update',
  JSON_OBJECT('recipe_id_fk', recipe_id_fk, 'sku_id_fk', sku_id_fk),
  JSON_OBJECT('recipe_id_fk', 6, 'sku_id_fk_note', 'KEPT_EPH2_branded'),
  'mig271_task1_packaging_EPH2batch25_to_ALT_recipe_only'
FROM bd_packaging_v2
WHERE recipe_id_fk = 76
  AND neb_batch = '25'
  AND DATE(event_date) = '2025-05-30';

UPDATE bd_packaging_v2
   SET recipe_id_fk = 6,
       audit_flags = TRIM(BOTH ',' FROM CONCAT(COALESCE(audit_flags, ''), ',mig271_ALT_reattribute,sku_id_kept'))
 WHERE recipe_id_fk = 76
   AND neb_batch = '25'
   AND DATE(event_date) = '2025-05-30';


-- ── SECTION 1h: ref_skus EPH24PB (id=75) ──────────────────────────────────────
-- recipe_id 76→6 (ALT); BOM recompile via sku-bom-compile-cli.php --apply --sku 75

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'ref_skus', id, 'update',
  JSON_OBJECT('sku_code', sku_code, 'recipe_id', recipe_id),
  JSON_OBJECT('recipe_id', 6),
  'mig271_task1_ref_skus_EPH24PB_recipe_76_to_6'
FROM ref_skus
WHERE id = 75
  AND sku_code = 'EPH24PB'
  AND recipe_id = 76;

UPDATE ref_skus
   SET recipe_id = 6
 WHERE id = 75
   AND sku_code = 'EPH24PB'
   AND recipe_id = 76;


-- ══════════════════════════════════════════════════════════════════════════════
-- TASK 2 — Catalog folds: EPHB12 → EPH4B12, EPH4PB → EPH44PB
-- ══════════════════════════════════════════════════════════════════════════════
-- Rotating-listing caveat: if Shopify reuses EPHB12 or EPH4PB next season with
-- a new product title, convert to temporal mapping (ref_sku_collab_temporal
-- mechanism) rather than extending this alias.

-- ── SECTION 2a: EPHB12 (id=305) → canonical EPH4B12 (id=67) ──────────────────

-- Alias insert (idempotent: UNIQUE on alias col)
INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes) VALUES
  ('EPHB12', 67, 'Rotating-listing EPH4 B12 variant code. Canonical: EPH4B12 (id=67). Folded 2026-06-07. CAVEAT: if Shopify reuses code next season with new title, convert to temporal mapping (ref_sku_collab_temporal mechanism).');

-- Deactivate EPHB12
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'ref_skus', id, 'update',
  JSON_OBJECT('sku_code', sku_code, 'is_active', is_active),
  JSON_OBJECT('is_active', 0),
  'mig271_task2_deactivate_EPHB12_folded_to_EPH4B12'
FROM ref_skus
WHERE id = 305
  AND sku_code = 'EPHB12'
  AND is_active = 1;

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 305
   AND sku_code = 'EPHB12'
   AND is_active = 1;

-- Delete EPHB12 (305) BOM rows (derived table; canonical EPH4B12/67 has own BOM)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'ref_sku_bom', id, 'update',
  JSON_OBJECT('sku_id', sku_id, 'ingredient_raw', ingredient_raw),
  JSON_OBJECT('_tombstone', 'deleted_by_mig271_EPHB12_folded'),
  'mig271_task2_delete_EPHB12_bom_rows'
FROM ref_sku_bom
WHERE sku_id = 305;

DELETE FROM ref_sku_bom WHERE sku_id = 305;


-- ── SECTION 2b: EPH4PB (id=304) → canonical EPH44PB (id=76, ref_skus) ─────────
-- NB: ref_skus.id=76 is distinct from ref_recipes.id=76 (EPH2 recipe)

-- Alias insert (idempotent: UNIQUE on alias col)
INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes) VALUES
  ('EPH4PB', 76, 'Rotating-listing EPH4 4-pack variant code. Canonical: EPH44PB (ref_skus.id=76, distinct from ref_recipes.id=76 EPH2). Folded 2026-06-07. CAVEAT: if Shopify reuses code next season with new title, convert to temporal mapping (ref_sku_collab_temporal mechanism).');

-- Deactivate EPH4PB
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'ref_skus', id, 'update',
  JSON_OBJECT('sku_code', sku_code, 'is_active', is_active),
  JSON_OBJECT('is_active', 0),
  'mig271_task2_deactivate_EPH4PB_folded_to_EPH44PB'
FROM ref_skus
WHERE id = 304
  AND sku_code = 'EPH4PB'
  AND is_active = 1;

UPDATE ref_skus
   SET is_active = 0
 WHERE id = 304
   AND sku_code = 'EPH4PB'
   AND is_active = 1;

-- Delete EPH4PB (304) BOM rows
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'ref_sku_bom', id, 'update',
  JSON_OBJECT('sku_id', sku_id, 'ingredient_raw', ingredient_raw),
  JSON_OBJECT('_tombstone', 'deleted_by_mig271_EPH4PB_folded'),
  'mig271_task2_delete_EPH4PB_bom_rows'
FROM ref_sku_bom
WHERE sku_id = 304;

DELETE FROM ref_sku_bom WHERE sku_id = 304;


-- ══════════════════════════════════════════════════════════════════════════════
-- SAFETY GATE: verify post-conditions via @vars (SET avoids SELECT result-set)
-- ══════════════════════════════════════════════════════════════════════════════

-- Check: no batch-25 rows remain on recipe 76 in bd_* tables
SET @remaining_batch25_on_76 = (
  SELECT COUNT(*) FROM (
    SELECT recipe_id_fk FROM bd_brewing_brewday_v2 WHERE recipe_id_fk=76 AND batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_brewing_gravity_v2 WHERE recipe_id_fk=76 AND batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_brewing_timings_v2 WHERE recipe_id_fk=76 AND batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_brewing_ingredients_v2 WHERE recipe_id_fk=76 AND batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_fermenting_v2 WHERE recipe_id_fk=76 AND batch='25'
    UNION ALL
    SELECT neb_recipe_id_fk FROM bd_racking_v2 WHERE neb_recipe_id_fk=76 AND neb_batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_packaging_v2 WHERE recipe_id_fk=76 AND neb_batch='25'
  ) _chk
);

-- Check: batch-25 rows are now on recipe 6
SET @batch25_on_recipe6 = (
  SELECT COUNT(*) FROM (
    SELECT recipe_id_fk FROM bd_brewing_brewday_v2 WHERE recipe_id_fk=6 AND batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_fermenting_v2 WHERE recipe_id_fk=6 AND batch='25'
    UNION ALL
    SELECT neb_recipe_id_fk FROM bd_racking_v2 WHERE neb_recipe_id_fk=6 AND neb_batch='25'
    UNION ALL
    SELECT recipe_id_fk FROM bd_packaging_v2 WHERE recipe_id_fk=6 AND neb_batch='25'
  ) _chk2
);

-- Log safety gate result
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 1, 'migration_271', 'SAFETY_GATE', 0, 'update',
  JSON_OBJECT(
    'remaining_batch25_on_76', @remaining_batch25_on_76,
    'batch25_on_recipe6', @batch25_on_recipe6
  ),
  JSON_OBJECT('status', IF(@remaining_batch25_on_76 = 0, 'GATE_PASSED', 'GATE_FAILED')),
  CONCAT('mig271_post_check: remaining_on_76=', COALESCE(@remaining_batch25_on_76,0),
         ' on_recipe6=', COALESCE(@batch25_on_recipe6,0));
