-- db/migrations/121_ref_suppliers_governance_cols.sql
-- What: Add governance columns to ref_suppliers — parser_key, country, vat_number,
--       vat_regime, hors_perimetre_cogs, sporadique, commissioning_state — plus
--       supporting indexes and a schema_meta update for ref_suppliers.
-- Why:  Phase R1 of the supplier-fiche dashboard (DBA report 2026-05-25 §7a).
--       ref_suppliers currently has 135 ingest-only rows with zero admin-curated
--       provenance. These columns enable the admin fiche UI to record fiscal metadata
--       (VAT régime, COGS exclusion), operational flags (sporadique), and the
--       parser-key bijection needed for direct parser→supplier_fk resolution without
--       entity-resolve. ref_supplier_field_pins (migration 123) governs which fields
--       ingest may not overwrite.
-- Decision: parser_key gets only KEY (not UNIQUE) here because ~60 backfill UPDATE
--           statements must run first (migration after this one). UNIQUE KEY
--           uk_parser_key will be added in a later migration once the backfill is
--           verified. commissioning_state defaults to 'active' for the 135 existing
--           rows (they are already operational — not drafts).
-- Rollback: ALTER TABLE ref_suppliers
--             DROP COLUMN parser_key,
--             DROP COLUMN country,
--             DROP COLUMN vat_number,
--             DROP COLUMN vat_regime,
--             DROP COLUMN hors_perimetre_cogs,
--             DROP COLUMN sporadique,
--             DROP COLUMN commissioning_state,
--             DROP KEY idx_parser_key,
--             DROP KEY idx_commissioning_state;
--           Then restore schema_meta writer_script/upstream_hint to previous values.

ALTER TABLE ref_suppliers
  ADD COLUMN parser_key VARCHAR(64) NULL
    COLLATE utf8mb4_unicode_ci
    COMMENT 'Bijection to lib/invoice-parsers/ key. NULL=no dedicated parser. UNIQUE deferred until backfill.',
  ADD COLUMN country CHAR(2) NULL
    COLLATE utf8mb4_unicode_ci
    COMMENT 'ISO-3166 alpha-2',
  ADD COLUMN vat_number VARCHAR(32) NULL
    COLLATE utf8mb4_unicode_ci
    COMMENT 'CHE-xxx.xxx.xxx or EU VAT number',
  ADD COLUMN vat_regime ENUM('ch_vat','intra_eu_vat','third_country_0vat','ch_reduced_vat','non_taxable') NULL,
  ADD COLUMN hors_perimetre_cogs TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=excluded from COP/COGS subtotals',
  ADD COLUMN sporadique TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=low-volume/one-off supplier',
  ADD COLUMN commissioning_state ENUM('draft','active','retired') NOT NULL DEFAULT 'active'
    COMMENT 'active=commissioned; draft=incomplete fiche; retired=unused',
  ADD KEY idx_parser_key (parser_key),
  ADD KEY idx_commissioning_state (commissioning_state);

UPDATE schema_meta
SET writer_script = 'reconciler (auto-observed fields) + web (pinned fields)',
    upstream_hint  = 'Use ref_supplier_field_pins to pin fields. entity-overrides.json is the legacy override surface (being migrated).'
WHERE table_name = 'ref_suppliers';
