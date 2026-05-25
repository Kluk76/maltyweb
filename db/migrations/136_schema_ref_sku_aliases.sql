-- db/migrations/136_schema_ref_sku_aliases.sql
-- What: Create ref_sku_aliases (STATIC aliases only) + seed EPH24P→EPH24PB and
--       PACKDECX8→PD8. COLLAB* aliases are temporal → ref_sku_collab_temporal (existing,
--       DO NOT put them here).
-- Why: External systems (Shopify, BC, old invoices) use legacy codes that must resolve
--      to canonical ref_skus rows for BOM compilation and COGS. Static aliases never move.
--      Temporal aliases (collab re-pointing as collabs change) live in ref_sku_collab_temporal.
-- Risk: CREATE + seed (2 rows). LOW. INSERT IGNORE → idempotent re-run.
-- Rollback: DROP TABLE ref_sku_aliases;

CREATE TABLE IF NOT EXISTS ref_sku_aliases (
  id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  alias            VARCHAR(64)     NOT NULL COMMENT 'External/legacy SKU code (e.g. EPH24P)',
  canonical_sku_id INT UNSIGNED    NOT NULL COMMENT 'FK to ref_skus.id — the canonical SKU this alias resolves to',
  notes            VARCHAR(255)    NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rsa_alias (alias),
  KEY idx_rsa_canonical (canonical_sku_id),
  CONSTRAINT fk_rsa_canonical FOREIGN KEY (canonical_sku_id) REFERENCES ref_skus(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Static (non-temporal) SKU aliases: external/legacy code → canonical ref_skus.id. Temporal collab re-pointing → ref_sku_collab_temporal.';

-- schema_meta row
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
  'ref_sku_aliases',
  'reference',
  'allowed',
  'manual/web',
  'Maintained manually or via Salle des Machines SKU admin. Temporal (collab) aliases live in ref_sku_collab_temporal — do not add temporal bindings here.'
);

-- Seed: EPH24P → EPH24PB (id=75)
-- EPH24P is the legacy 24-pack bottle EPH2 code used in some external exports.
-- The canonical SKU is EPH24PB (format 4PB, recipe 63 = EPH2).
INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('EPH24P', 75, 'Legacy 24-bottle EPH2 code (no format suffix). Canonical: EPH24PB (format=4PB, recipe=EPH2 id=63).');

-- Seed: PACKDECX8 → PD8 (id=137)
-- PACKDECX8 is the Shopify/BC variant of the 8-beer sampler pack PD8.
INSERT IGNORE INTO ref_sku_aliases (alias, canonical_sku_id, notes)
VALUES ('PACKDECX8', 137, 'Shopify/BC code for the 8-beer sampler pack. Canonical: PD8 (format=PD8, composite, id=137).');

-- NOTE: COLLAB4PACK / COLLAB12 / COLLAB24 are NOT seeded here.
-- They are temporal aliases in ref_sku_collab_temporal (3 existing rows, effective 2025-12-01
-- pointing to recipe 31 DGD). The re-pointing changes as the active collab changes.
-- Putting them here as static aliases would prevent re-pointing → DO NOT add them here.
