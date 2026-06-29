-- 448_ref_sku_substitutions.sql
-- What: Effective-dated (SCD2) SKU substitution table.
--       Each row declares that demand for substitute_sku should resolve its
--       stock attribution and COGS valuation to target_sku within the active
--       date range. Identity is NOT folded — substitute_sku keeps its own
--       ref_skus row, Shopify tag, and physical census identity.
-- Why:  Eshop tags the 24×50cl can box as ZEP4C (dormant; BOM incomplete).
--       Production only stocks ZEPC. This layer redirects ZEP4C demand/COGS
--       to ZEPC at runtime, cleanly reversible by deleting or expiring the row.
-- Risk: LOW. New table + seed row only; no existing columns altered.
--       No COGS table touched directly — the substitution is applied by
--       app-layer resolvers in fg-stock.php and cogs-fiche-compute.php.
-- Rollback:
--   DELETE FROM ref_sku_substitutions WHERE substitute_sku_id_fk =
--     (SELECT id FROM ref_skus WHERE sku_code = 'ZEP4C');
--   DROP TABLE IF EXISTS ref_sku_substitutions;
--   DELETE FROM schema_meta WHERE table_name = 'ref_sku_substitutions';

SET lock_wait_timeout = 10;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Create table
--    effective_until = NULL means "open-ended" (currently active).
--    UNIQUE(substitute_sku_id_fk, effective_from): only one active substitution
--    per source SKU per start date; SCD2 history is supported via multiple rows
--    with non-overlapping date ranges (enforced by application, not DB).
--    FK ON DELETE RESTRICT on both sides: deleting a substituted or target SKU
--    is blocked while a substitution row references it, preventing silent orphans.
--    No CASCADE FKs: MySQL 8 prevents STORED generated columns from referencing
--    CASCADE FK columns (anti-pattern #30) — RESTRICT avoids the issue entirely.
--    CHECK name is schema-unique (table-prefixed) per MySQL-8 requirement.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE `ref_sku_substitutions` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `substitute_sku_id_fk`   INT UNSIGNED    NOT NULL COMMENT 'FK → ref_skus.id — the dormant/alias SKU (e.g. ZEP4C)',
  `target_sku_id_fk`       INT UNSIGNED    NOT NULL COMMENT 'FK → ref_skus.id — the produced SKU stock resolves to (e.g. ZEPC)',
  `effective_from`         DATE            NOT NULL,
  `effective_until`        DATE            NULL     COMMENT 'NULL = open-ended (currently active)',
  `reason`                 VARCHAR(255)    NULL,
  `created_by`             VARCHAR(64)     NULL,
  `created_at`             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rss_sub_from` (`substitute_sku_id_fk`, `effective_from`),
  KEY `idx_rss_substitute`  (`substitute_sku_id_fk`),
  KEY `idx_rss_target`      (`target_sku_id_fk`),
  CONSTRAINT `fk_rss_substitute` FOREIGN KEY (`substitute_sku_id_fk`) REFERENCES `ref_skus` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rss_target`     FOREIGN KEY (`target_sku_id_fk`)     REFERENCES `ref_skus` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rss_no_self_448` CHECK (`substitute_sku_id_fk` <> `target_sku_id_fk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. schema_meta registration
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint, notes)
VALUES (
  'ref_sku_substitutions',
  'reference',
  'allowed',
  'seed via migration 448; future: web UI / resolver admin page',
  NULL,
  'Effective-dated (SCD2) SKU substitution layer. Demand for substitute_sku_id_fk resolves stock attribution and COGS valuation to target_sku_id_fk within [effective_from, effective_until). Deleting or expiring a row restores standalone behaviour for the substitute SKU. Identity is NOT folded: substitute keeps its ref_skus row, Shopify tag, and physical census identity. One-hop only — chains (target itself having a substitution) are a configuration error detected by app/sku_catalog.php::sku_effective_fulfilment_id().'
)
ON DUPLICATE KEY UPDATE
  table_class        = VALUES(table_class),
  corrections_policy = VALUES(corrections_policy),
  writer_script      = VALUES(writer_script),
  notes              = VALUES(notes);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Seed: ZEP4C (id=56) → ZEPC (id=59), effective from today.
--    INSERT...SELECT is ID-drift-robust: resolves both SKU IDs by sku_code
--    at apply time, never hardcodes integer PKs.
--    The CROSS JOIN shape (Cartesian of two single-row subqueries) guarantees
--    exactly one row is inserted regardless of table auto-increment state.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO ref_sku_substitutions
  (substitute_sku_id_fk, target_sku_id_fk, effective_from, reason, created_by)
SELECT
  s.id,
  t.id,
  CURDATE(),
  'eshop tags 24x50cl can box as ZEP4C (dormant SKU); resolve stock+COGS to produced ZEPC. Reversible.',
  'migration-448'
FROM ref_skus s
JOIN ref_skus t ON t.sku_code = 'ZEPC'
WHERE s.sku_code = 'ZEP4C';
