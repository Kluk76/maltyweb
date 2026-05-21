-- db/migrations/080_schema_meta.sql
-- What: New metadata table classifying each DB table by write-path semantic
--       (reference / lookup / source / derived / audit / config / system) and
--       declaring a corrections_policy that the DB Browser must respect.
-- Why:  CTO architecture review §2.3, 3.1. Today's "correction wiped" bug class:
--       operator edits a derived-table column (bd_brewing_ingredients_parsed.mi_id_fk),
--       next pipeline run silently rewrites it. The DB Browser had no signal to
--       distinguish derived tables from source/reference. schema_meta codifies
--       that distinction so the UI can block / redirect / log-and-allow per class.
--
--       Population covers all 64 tables present in prod 2026-05-22. New tables
--       added by future migrations should INSERT their row in the same migration.
--
-- Risk: New table. No existing data affected. Db-browser.php wiring is a separate
--       follow-up (Phase 1.3 in the CTO plan).
-- Rollback: DROP TABLE schema_meta;

START TRANSACTION;

CREATE TABLE schema_meta (
  table_name          VARCHAR(64) NOT NULL PRIMARY KEY,
  table_class         ENUM('reference','lookup','source','derived','audit','config','system') NOT NULL,
  writer_script       VARCHAR(128) NULL
    COMMENT 'Canonical writer (e.g. parse_bd_ingredients.py, manual-only, build-sku-bom.js)',
  corrections_policy  ENUM('allowed','allowed_with_side_effect','blocked','blocked_with_redirect') NOT NULL,
  upstream_hint       TEXT NULL
    COMMENT 'Free text shown in DB Browser when edits are blocked or redirected',
  notes               TEXT NULL,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Reference tables — canonical data, editable via dedicated UIs (or DB Browser
-- with audit logging until those UIs ship).
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('ref_mi',                          'reference', 'ingest_mi.py (legacy, disabled) + manual/web', 'allowed',                  NULL, 'Central MI catalog. last_modified_by=web protects from re-ingest.'),
  ('ref_mi_aliases',                  'reference', 'manual/web + db-correct.php (side-effect)',    'allowed',                  NULL, 'Raw-name → mi_id mapping. Curated.'),
  ('ref_mi_invoicing_units',          'reference', 'manual/web',                                   'allowed',                  NULL, 'Per-MI per-supplier unit mapping for invoice ingest normalization.'),
  ('ref_suppliers',                   'reference', 'ingest_suppliers.py (legacy, disabled) + web', 'allowed',                  NULL, NULL),
  ('ref_supplier_aliases',            'reference', 'manual/web',                                   'allowed',                  NULL, NULL),
  ('ref_recipes',                     'reference', 'manual/web',                                   'allowed',                  NULL, NULL),
  ('ref_recipe_aliases',              'reference', 'manual/web + migration 065',                   'allowed',                  NULL, NULL),
  ('ref_recipe_ingredients',          'reference', 'scripts/_seed-ref-recipe-ingredients.ts + web', 'allowed',                  NULL, 'Per-recipe ingredient quantities, per-HL basis. Seeded 2026-05-21.'),
  ('ref_skus',                        'reference', 'build-sku-bom.js + manual/web',                'allowed',                  NULL, 'SKU catalog. Composites (PD8, PAD, PAL) manual.'),
  ('ref_packaging_items',             'reference', 'migration 070 seed + SKU builder UI',          'allowed',                  NULL, 'Slot templates per packaging format.'),
  ('ref_sku_composite_slots',         'reference', 'SKU builder UI (not yet shipped)',             'allowed',                  NULL, 'Constituent recipes for composite SKUs.'),
  ('ref_sku_packaging_choices',       'reference', 'SKU builder UI (not yet shipped)',             'allowed',                  NULL, 'Per-SKU MI overrides for packaging slots.'),
  ('ref_clients',                     'reference', 'manual/web',                                   'allowed',                  NULL, NULL),
  ('ref_yeast_strains',               'reference', 'manual/web',                                   'allowed',                  NULL, NULL),
  ('ref_yeast_strain_aliases',        'reference', 'manual/web',                                   'allowed',                  NULL, NULL),
  ('users',                           'reference', 'create-user.php (admin)',                      'allowed',                  NULL, NULL);

-- ─────────────────────────────────────────────────────────────────────────────
-- Lookup tables — small, mostly static, admin-only edits.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('ref_mi_categories',               'lookup',    'manual/web (admin)',                           'allowed',                  NULL, '22 rows.'),
  ('ref_mi_subcategories',            'lookup',    'manual/web (admin)',                           'allowed',                  NULL, '45 rows.'),
  ('ref_units',                       'lookup',    'migration 060 + manual',                       'allowed',                  NULL, '21 units with dimension/factor.'),
  ('ref_packaging_formats',           'lookup',    'migration 069 seed',                           'allowed',                  NULL, '22 format codes.'),
  ('ref_cct',                         'lookup',    'manual/web (admin)',                           'allowed',                  NULL, 'Conical fermenter definitions.'),
  ('ref_bbt',                         'lookup',    'manual/web (admin)',                           'allowed',                  NULL, 'Bright beer tank definitions.'),
  ('ref_yt',                          'lookup',    'manual/web (admin)',                           'allowed',                  NULL, 'Yeast tank definitions.');

-- ─────────────────────────────────────────────────────────────────────────────
-- Source tables — append-only ingest output from Forms / OCR pipelines.
-- Corrections allowed; alias-upsert side-effect on mi_id_fk where applicable.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('bd_brewing_brewday',              'source',    'tab_brewing.py',                               'allowed',                  NULL, 'Google Form → ingest.'),
  ('bd_brewing_cooling',              'source',    'tab_brewing.py',                               'allowed',                  NULL, NULL),
  ('bd_brewing_gravity',              'source',    'tab_brewing.py',                               'allowed',                  NULL, NULL),
  ('bd_brewing_ingredients',          'source',    'tab_brewing.py',                               'allowed',                  NULL, 'Raw operator-entered brewing ingredients.'),
  ('bd_brewing_timings',              'source',    'tab_brewing.py',                               'allowed',                  NULL, NULL),
  ('bd_fermenting',                   'source',    'tab_fermenting.py (or similar)',               'allowed',                  NULL, NULL),
  ('bd_racking',                      'source',    'tab_racking.py',                               'allowed',                  NULL, NULL),
  ('bd_packaging',                    'source',    'tab_packaging.py',                             'allowed',                  NULL, '58-column wide table; CTO §3.7 candidate for split.'),
  ('bd_packaging_readings',           'source',    'tab_packaging.py',                             'allowed',                  NULL, 'O2/CO2/pH readings.'),
  ('doc_files',                       'source',    'ingest-documents.js',                          'allowed',                  NULL, 'PDF file metadata.'),
  ('doc_uploads',                     'source',    'maltyweb upload form',                         'allowed',                  NULL, NULL),
  ('doc_invoices',                    'source',    'ingest-documents.js (per-supplier parsers)',   'allowed',                  NULL, 'Operator corrects header fields with audit.'),
  ('doc_invoice_lines',               'source',    'per-supplier parsers',                         'allowed_with_side_effect', 'mi_id_fk correction upserts (raw_name → mi_id) into ref_mi_aliases', 'Side-effect: alias durable across re-parses.'),
  ('doc_delivery_notes',              'source',    'ingest-documents.js',                          'allowed',                  NULL, NULL),
  ('doc_dn_lines',                    'source',    'ingest-documents.js',                          'allowed_with_side_effect', 'mi_id_fk correction upserts (raw_name → mi_id) into ref_mi_aliases', 'Side-effect: alias durable across re-parses.'),
  ('doc_ambiguous',                   'source',    'ingest-documents.js classifier',               'allowed',                  NULL, 'Unclassified OCR cache, keyed by Drive fileId.'),
  ('doc_review_queue',                'source',    'reconciler + ingest pipelines',                'allowed',                  NULL, 'Decision col edited by operator to trigger downstream actions.'),
  ('inv_deliveries',                  'source',    'ingest-documents.js + bsf-mirror legacy',      'allowed',                  NULL, 'Mixed source: node-ingest (new) + bsf-mirror (legacy frozen).'),
  ('inv_rm_stocktake',                'source',    'sync-rm-stocktake.js (form → DB)',             'allowed',                  NULL, 'Long-format: one row per MI_ID × Period.'),
  ('inv_fg_stocktake',                'source',    'sync-stocktake.js (form → DB)',                'allowed',                  NULL, 'FG cage/loose-bottle in fractional crates.'),
  ('user_remember_tokens',            'source',    'auth.php',                                     'blocked',                  NULL, 'Session ephemera; never edit.');

-- ─────────────────────────────────────────────────────────────────────────────
-- Derived tables — recomputable. UI must block edits and redirect to upstream.
-- This is the class that produced today's "correction wiped" bug.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('bd_brewing_ingredients_parsed',   'derived',   'parse_bd_ingredients.py',                      'allowed_with_side_effect', 'mi_id_fk correction upserts (raw_name → mi_id) into ref_mi_aliases so parse re-runs respect the correction (migration 079 added side_effects audit col)', 'Today (2026-05-21) bug class instance. Side-effect now active in db-correct.php.'),
  ('inv_consumption',                 'derived',   'build-*-consumption.js / .ts',                 'blocked_with_redirect',    'Recompute via build-{brewing,fermenting,racking,packaging}-consumption pipeline. Fix the upstream bd_* row instead.', NULL),
  ('inv_tank_balances',               'derived',   'parse-tank-simulation.ts',                     'blocked_with_redirect',    'Recompute via scripts/parse-tank-simulation.ts. Fix bd_brewing_brewday / bd_racking / bd_packaging upstream.', NULL),
  ('wac_snapshots',                   'derived',   'compute-weighted-prices.ts',                   'blocked_with_redirect',    'Recompute via compute-weighted-prices.ts. Fix inv_deliveries upstream.', NULL),
  ('mi_weighted_prices_monthly',      'derived',   'compute-weighted-prices.ts',                   'blocked_with_redirect',    'Recompute via compute-weighted-prices.ts. Fix inv_deliveries upstream.', NULL),
  ('cop_monthly',                     'derived',   'build-month-closure.js',                       'blocked_with_redirect',    'Recompute via run-month-close.js. Fix sources upstream.', NULL),
  ('cogs_monthly',                    'derived',   'build-cogs-report.js',                         'blocked_with_redirect',    'Recompute via build-cogs-report.js. Fix sources upstream.', NULL),
  ('ref_sku_bom',                     'derived',   'build-sku-bom.js',                             'blocked_with_redirect',    'Recompute via build-sku-bom.js. Edit ref_packaging_items / ref_sku_packaging_choices / ref_recipe_ingredients upstream.', 'Composites (PD8, PAD, PAL) are manual exception lines.'),
  ('ref_recipe_profile',              'derived',   'refresh_recipe_profile.py (or similar)',       'blocked_with_redirect',    'Recompute via refresh_recipe_profile.py. Fix bd_brewing_* upstream.', NULL),
  ('ref_recipe_profile_hops',         'derived',   'refresh_recipe_profile.py',                    'blocked_with_redirect',    'Recompute via refresh_recipe_profile.py.', NULL),
  ('ref_recipe_profile_malt',         'derived',   'refresh_recipe_profile.py',                    'blocked_with_redirect',    'Recompute via refresh_recipe_profile.py.', NULL),
  ('ref_supplier_summary',            'derived',   '(unknown — possibly a removed cron)',          'blocked_with_redirect',    'Recomputable from ref_suppliers. CTO §3.3.4 candidate for DROP or VIEW conversion.', 'No active writer identified 2026-05-22.');

-- ─────────────────────────────────────────────────────────────────────────────
-- Audit tables — append-only logs. Never edit or delete from the UI.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('debug_corrections',               'audit',     'db-correct-apply.php',                         'blocked',                  NULL, 'DB Browser correction log.'),
  ('mi_proposals_audit',              'audit',     'reconciler (proposes MIs from RQ)',            'blocked',                  NULL, NULL),
  ('phantom_cleanup_log',             'audit',     'maintenance scripts',                          'blocked',                  NULL, 'Records rows removed by phantom-cleanup ops.'),
  ('ingest_runs',                     'audit',     'every ingest pipeline (Python + Node)',        'blocked',                  NULL, NULL),
  ('ingest_failures',                 'audit',     'every ingest pipeline',                        'blocked',                  NULL, NULL),
  ('user_action_log',                 'audit',     'auth.php',                                     'blocked',                  NULL, 'Login/logout/CSRF events.');

-- ─────────────────────────────────────────────────────────────────────────────
-- Config tables — single-row or version-tracking config. Admin-only.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('ref_brewhouse_size',              'config',    'manual/web (admin)',                           'allowed',                  NULL, 'SCD2 versioned; 30 HL active row.'),
  ('schema_meta',                     'config',    'manual/web (admin) + this migration',          'allowed',                  NULL, 'This table. Self-referential bootstrap.');

-- ─────────────────────────────────────────────────────────────────────────────
-- System tables — managed by infra, never UI-edited.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes) VALUES
  ('schema_migrations',               'system',    'scripts/migrate.php',                          'blocked',                  NULL, 'Migration tracking. Managed by migrate.php only.');

COMMIT;
