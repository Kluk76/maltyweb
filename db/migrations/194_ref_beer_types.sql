-- db/migrations/194_ref_beer_types.sql
--
-- What: New canonical table ref_beer_types — replaces the BSF BeerTypes tab as
--       the authoritative source for beer type/subtype classification.
--
-- Why:  BeerTypes lives only in BSF today, yet 4+ maltytask scripts depend on
--       it for Neb/Contract routing, beer-tax attribution, and auto-discovery.
--       This is the Phase 1 (BSF-exit foundations) migration that makes the
--       data MySQL-canonical so downstream read-swap scripts can stop hitting
--       BSF.
--
-- Source: BSF BeerTypes tab (spreadsheet 1zTgfTJrLd_kQfwQxfS9SjQ5MLkUYK-CyXX13TKRMJiE)
--         range A2:S (19 cols: BeerName, Type, SubType, Notes, Vintage,
--         SkuPrefix, Notes(2), SkuCode, Aliases, AleOrLager, Style, DryHopped,
--         MedianOG_Plato, TaxCategory, TaxCategoryOverride, ABV, IBU,
--         BrewCostPerHL, ActiveVariant)
--         Live-inspected 2026-05-28: 76 rows; Types = Neb|Contract;
--         Subtypes = Core|EPH|CollabIn|Archive; SubType often blank (Contract
--         rows). Enum must allow empty-string subtype — modelled as NULL.
--
-- Design:
--   - beer_name UNIQUE — the natural key (matches BSF col A)
--   - type NOT NULL ENUM (only Neb|Contract observed — matches memory)
--   - subtype NULL ENUM (Core|EPH|CollabIn|CollabOut|WhiteLabel|Archive —
--     CollabOut|WhiteLabel from memory/CLAUDE.md, not in current BSF data but
--     must be in ENUM to avoid ENUM-truncation anti-pattern #4)
--   - Additional informational cols from BSF: sku_prefix, sku_code, aliases,
--     ale_or_lager, style, dry_hopped, median_og_plato, tax_category,
--     tax_category_override, abv, ibu, brew_cost_per_hl, active_variant
--   - auto_discovered TINYINT(1) — set by reconciler auto-discovery path
--   - row_hash CHAR(64) UNIQUE — SHA-256 of (beer_name, type, COALESCE(subtype,''))
--   - audit cols: last_modified_by, created_at, updated_at
--
-- FK note: No FK TO ref_beer_types yet — ref_recipes.id is the beer identity
--   root. ref_beer_types will eventually be joined by recipe_name or sku_prefix.
--   FK FROM ref_beer_types to ref_recipes is Phase 2 work (deferred: requires
--   stable recipe names to be pinned first).
--
-- Rollback:
--   DROP TABLE IF EXISTS ref_beer_types;
--   DELETE FROM schema_meta WHERE table_name = 'ref_beer_types';
--
-- NOTE: No bare SELECT statements — PDO exec() leaves result sets open.

-- ============================================================================
-- TABLE: ref_beer_types
-- ============================================================================

CREATE TABLE IF NOT EXISTS ref_beer_types (
  id                      INT UNSIGNED        NOT NULL AUTO_INCREMENT,

  -- ── IDENTITY (natural key) ─────────────────────────────────────────────
  beer_name               VARCHAR(128)        NOT NULL
                            COLLATE utf8mb4_unicode_ci,

  -- ── CLASSIFICATION ─────────────────────────────────────────────────────
  type                    ENUM('Neb','Contract')
                            NOT NULL,
  subtype                 ENUM('Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive')
                            NULL DEFAULT NULL,

  -- ── INFORMATIONAL (from BSF BeerTypes cols D-S) ────────────────────────
  notes                   TEXT                NULL
                            COLLATE utf8mb4_unicode_ci,
  vintage                 VARCHAR(32)         NULL
                            COLLATE utf8mb4_unicode_ci,
  sku_prefix              VARCHAR(16)         NULL
                            COLLATE utf8mb4_unicode_ci,
  sku_code                VARCHAR(32)         NULL
                            COLLATE utf8mb4_unicode_ci,
  aliases                 TEXT                NULL
                            COLLATE utf8mb4_unicode_ci,
  ale_or_lager            ENUM('Ale','Lager')
                            NULL DEFAULT NULL,
  style                   VARCHAR(64)         NULL
                            COLLATE utf8mb4_unicode_ci,
  dry_hopped              TINYINT(1)          NULL DEFAULT NULL,
  median_og_plato         DECIMAL(6,3)        NULL,
  tax_category            VARCHAR(64)         NULL
                            COLLATE utf8mb4_unicode_ci,
  tax_category_override   TINYINT(1)          NULL DEFAULT NULL,
  abv                     DECIMAL(5,2)        NULL,
  ibu                     DECIMAL(7,2)        NULL,
  brew_cost_per_hl        DECIMAL(12,4)       NULL,
  active_variant          TINYINT(1)          NULL DEFAULT NULL,

  -- ── DISCOVERY FLAG ─────────────────────────────────────────────────────
  auto_discovered         TINYINT(1)          NOT NULL DEFAULT 0,

  -- ── AUDIT ──────────────────────────────────────────────────────────────
  row_hash                CHAR(64)            NOT NULL
                            COLLATE utf8mb4_unicode_ci,
  last_modified_by        ENUM('ingest','web')
                            NOT NULL DEFAULT 'ingest',
  created_at              TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  -- ── CONSTRAINTS ────────────────────────────────────────────────────────
  PRIMARY KEY (id),
  UNIQUE KEY uq_ref_beer_types_beer_name (beer_name),
  UNIQUE KEY uq_ref_beer_types_row_hash  (row_hash),

  -- CHECK: Contract beers may have a NULL subtype; Neb beers should have one
  -- (soft rule — enforced via app layer, not DB, because legacy BSF data has
  --  Neb rows with blank subtype during the migration window)
  CONSTRAINT chk_ref_beer_types_type_valid
    CHECK (type IN ('Neb','Contract')),
  CONSTRAINT chk_ref_beer_types_subtype_valid
    CHECK (subtype IS NULL OR subtype IN ('Core','EPH','CollabIn','CollabOut','WhiteLabel','Archive'))

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical beer classification (Neb/Contract + subtype). BSF BeerTypes tab exit target. Phase 1 BSF-exit foundation migration 194.';

-- ============================================================================
-- schema_meta row for ref_beer_types
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'ref_beer_types',
  'reference',
  'scripts/build-sku-bom.js (auto-discovery) + maltyweb operator UI (Le Cockpit)',
  'allowed',
  'Phase 1 BSF-exit foundation. Beer type/subtype classification (Neb|Contract, Core|EPH|CollabIn|etc.). Seeded from BSF BeerTypes tab via scripts/_phase-bsf-exit/seed-ref-beer-types.ts. Operators classify new beers via the Type/SubType dropdowns; auto_discovered rows are written by the build-sku-bom reconciler path. Read by beer-tax routing, COGS/COP, ingest parsers.'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  writer_script      = VALUES(writer_script),
  corrections_policy = VALUES(corrections_policy),
  notes              = VALUES(notes);

-- end migration 194
