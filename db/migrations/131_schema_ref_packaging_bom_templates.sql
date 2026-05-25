-- db/migrations/131_schema_ref_packaging_bom_templates.sql
-- What: Create ref_packaging_bom_templates — the "generic BOM variant catalog"
--       One row per (format, decoration_integral, supply) combination.
--       decoration_integral=0 → labelled (generic container + label consumable)
--       decoration_integral=1 → pre-printed OR pre-sleeved (beer-specific container, no label line)
--       supply ∈ {we_supply, client_supply}
-- Why: Phase 1 of SKU-BOM rework. Formalises the template-variant dimension so the
--      recompute service can filter ref_packaging_items by (decoration_integral, supply)
--      rather than hard-coding slot exclusions in PHP.
-- Risk: CREATE TABLE only — no data transform. LOW risk.
-- Rollback: DROP TABLE ref_packaging_bom_templates;

CREATE TABLE IF NOT EXISTS ref_packaging_bom_templates (
  id              INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  format_id       BIGINT UNSIGNED     NOT NULL COMMENT 'FK to ref_packaging_formats.id (BIGINT UNSIGNED — matches parent type)',
  decoration_integral TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '0=labelled (separate label consumable); 1=pre-printed or pre-sleeved (beer-specific container, no label line)',
  supply          ENUM('we_supply','client_supply') NOT NULL DEFAULT 'we_supply' COMMENT 'we_supply=normal; client_supply strips caps/labels/box (contract/white-label)',
  name            VARCHAR(96)         NULL COMMENT 'Human-readable variant name (e.g. "Bouteille étiquetée (La Neb)"); NULL = unnamed variant',
  is_active       TINYINT(1)          NOT NULL DEFAULT 1,
  created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rpbt_format_dec_supply (format_id, decoration_integral, supply),
  KEY idx_rpbt_format (format_id),
  CONSTRAINT fk_rpbt_format FOREIGN KEY (format_id) REFERENCES ref_packaging_formats(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Generic BOM variant catalog: one template per (format × decoration × supply). Reuse existing — do NOT create parallel surfaces.';

-- schema_meta row
INSERT INTO schema_meta (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES (
  'ref_packaging_bom_templates',
  'reference',
  'allowed',
  'manual/web',
  'Commissioning UI (Salle des Machines / Conditionnement). Seed via migration 132.'
);
