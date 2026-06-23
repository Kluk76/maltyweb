-- 442_db_consolidation_tier_c.sql
-- What: DB consolidation Tier C — Pack Explorer composite format rows, SKU
--       format_id back-fill, alias format inheritance, and C.11 reconciliation
--       guard view.
-- Why:  Operator-approved non-COGS batch hygiene (PM-gated 2026-06-23).
--       Mints the two missing composite packaging-format rows for EXP12C/EXP24C,
--       wires those SKUs + two existing aliases (EPH24P, PACKDECX8) to their
--       canonical format rows, and adds the ref_recipes/ref_beer_types drift view.
-- Risk: LOW. Pure INSERT + UPDATE; no column drops, no ENUM changes, no COGS
--       tables touched. UPDATEs are deterministic JOINs — wrong format_id was
--       NULL, not a stale non-NULL, so there is no risk of silently overwriting
--       a human-set value.
-- Rollback:
--   DELETE FROM ref_packaging_formats WHERE format_code IN ('EXP12C','EXP24C');
--   UPDATE ref_skus SET format_id = NULL WHERE sku_code IN ('EXP12C','EXP24C','EPH24P','PACKDECX8');
--   DROP VIEW IF EXISTS v_recipe_beertype_classification_drift;
--
-- DEFERRED decisions documented here (C.10 / C.11 header audit trail):
--   C.10 — run_type canonical source: ref_packaging_formats.run_type is the
--           canonical value set for run_type across the system. No DDL change
--           needed — documented here + schema_meta note added below.
--   C.11 — reconciliation guard view v_recipe_beertype_classification_drift
--           created inline below (CREATE OR REPLACE VIEW is DDL, returns no
--           result set — applies cleanly under migrate.php exec(), same as
--           migs 387/417/326). Registered in schema_meta.
--   ref_customers.default_transporter_id_fk / default_delivery_site_id_fk:
--           KEPT — planned per-customer-default feature, not dropped.
--   5 unlinked Contract recipes (Nylo, Brasserie 28 Blonde/IPA/Triple,
--           Toccalmatto-Stria): known master-data gap, deferred.
--   C.9 ref_cogs_targets re-key: DEFERRED — Node-coupled COGS-mover,
--           high blast-radius. Separate arc.
--   PAD and PBD: discontinued SKUs — no alias parent, format_id intentionally
--           left NULL. Do not wire them.

SET lock_wait_timeout = 10;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Mint 2 composite packaging-format rows for Pack Explorer
--    Convention matched from PD8 (id=19), PAL (id=21), PAC (id=23),
--    XMASPACK (id=20): catalog_id=16, is_composite=1, run_type=NULL,
--    display_family='multipack', is_active=1, derived_from_format_id=NULL.
--    hl_per_unit sourced directly from ref_skus.hl_per_unit (probe 2026-06-23):
--      EXP12C → 0.060000  (12 × 50cl cans)
--      EXP24C → 0.120000  (24 × 50cl cans)
--    INSERT IGNORE on format_code UNIQUE → idempotent re-run.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `ref_packaging_formats`
  (format_code, display_name,                            hl_per_unit, run_type, is_composite, is_active, catalog_id, derived_from_format_id, display_family)
VALUES
  ('EXP12C',   'Pack Explorer (12×50cl canettes composite)', 0.060000,    NULL,     1,            1,         16,         NULL,                   'multipack'),
  ('EXP24C',   'Pack Explorer (24×50cl canettes composite)', 0.120000,    NULL,     1,            1,         16,         NULL,                   'multipack');

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Point SKUs at their canonical format rows
--    (a) EXP12C / EXP24C → their newly-created composite format row
--        (JOIN on format_code = sku_code — deterministic 1:1)
--    (b) EPH24P  → inherits format_id from its alias parent EPH24PB (id=4)
--    (c) PACKDECX8 → inherits format_id from its alias parent PD8 (id=19)
--    PAD and PBD: discontinued, no alias parent → format_id left NULL (see header).
-- ─────────────────────────────────────────────────────────────────────────────

UPDATE `ref_skus` s
  JOIN `ref_packaging_formats` f ON f.format_code = s.sku_code
   SET s.format_id = f.id
 WHERE s.sku_code IN ('EXP12C', 'EXP24C');

UPDATE `ref_skus` a
  JOIN `ref_skus` b ON b.sku_code = 'EPH24PB'
   SET a.format_id = b.format_id
 WHERE a.sku_code = 'EPH24P';

UPDATE `ref_skus` a
  JOIN `ref_skus` b ON b.sku_code = 'PD8'
   SET a.format_id = b.format_id
 WHERE a.sku_code = 'PACKDECX8';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. schema_meta note: ref_packaging_formats.run_type is the canonical source
--    for run-type classification across the system (C.10 decision).
--    We prefer a schema_meta comment over an ENUM MODIFY (which would require
--    restating the full value list and risks anti-pattern #9 gotchas).
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `schema_meta`
  (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
  ('ref_packaging_formats', 'reference', 'allowed', 'manual/triage', 'run_type column is the canonical run-type value set for the system (C.10, mig 442). NULL = composite or draft-pour; enum values: bot/can/can33/keg/cuv.')
ON DUPLICATE KEY UPDATE
  upstream_hint = VALUES(upstream_hint);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. C.11 reconciliation guard — drift view between the canonical
--    ref_beer_types classifier and the ref_recipes denormalized cache.
--    Empty today; flags any FUTURE divergence so the cache can be re-synced.
--    CREATE OR REPLACE VIEW is DDL (no result set) — applies inline under
--    migrate.php exec(), same as migs 387/417/326.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE OR REPLACE VIEW v_recipe_beertype_classification_drift AS
SELECT
  r.id          AS recipe_id,
  r.name,
  r.classification AS recipe_classification,
  b.type        AS beertype_type,
  r.subtype     AS recipe_subtype,
  b.subtype     AS beertype_subtype
FROM ref_recipes r
JOIN ref_beer_types b ON b.beer_name = r.name
WHERE r.classification <> b.type
   OR NOT (r.subtype <=> b.subtype);

-- 5. Register the view in schema_meta (matches mig 387's pattern).
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes, updated_at)
VALUES ('v_recipe_beertype_classification_drift', 'derived', 'CREATE OR REPLACE VIEW (DDL only)', 'blocked',
        'Reconciliation guard — rows present iff ref_recipes classification/subtype cache drifts from the canonical ref_beer_types. Redefine via a new migration to change the comparison.',
        'Created by migration 442. Empty when ref_recipes and ref_beer_types agree. Re-sync the ref_recipes cache from ref_beer_types when rows appear.',
        NOW())
ON DUPLICATE KEY UPDATE
  upstream_hint = VALUES(upstream_hint),
  notes         = VALUES(notes),
  updated_at    = NOW();
