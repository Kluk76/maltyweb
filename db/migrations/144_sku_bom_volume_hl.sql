-- db/migrations/144_sku_bom_volume_hl.sql
-- What: Add volume_hl column to ref_sku_bom + create v_sku_volume canonical view
-- Why:  Wire the VOLUME dimension into the BOM. Cost is already additive across BOM
--       lines; volume is NOT — it is OWNED by the container-role line only.
--       v_sku_volume exposes per-SKU volume + 1-HL normalization (cost/hl, containers/hl)
--       and doubles as a stored-vs-derived drift detector for Phase B.
-- Risk: ADD COLUMN (nullable, no default) = LOW / INSTANT; CREATE OR REPLACE VIEW = safe.
-- Rollback:
--   ALTER TABLE ref_sku_bom DROP COLUMN volume_hl;
--   DROP VIEW IF EXISTS v_sku_volume;

-- ── 1. Add volume_hl column to ref_sku_bom (IDEMPOTENT) ─────────────────────
-- Placed after `cost` for logical grouping with the cost fact.
-- MySQL 8 has no ADD COLUMN IF NOT EXISTS; guard via information_schema so the
-- migration is re-runnable (the column may already exist from a partial prior
-- run — this file first failed at the CREATE VIEW step below, after the ALTER
-- had already auto-committed). No bare SELECT (migrate.php execs the whole file
-- via PDO::exec; a standalone result set would block subsequent statements).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name   = 'ref_sku_bom'
     AND column_name  = 'volume_hl'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE ref_sku_bom ADD COLUMN volume_hl DECIMAL(10,6) NULL COMMENT ''Packaged liquid volume (HL). Set on the container-role packaging line ONLY = the SKU liquid volume; NULL on all other lines (closure, label, box, liquid-ingredient, etc.). NOT additive across BOM lines — volume is owned by the container line.'' AFTER cost',
  'SET @noop := 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── 2. Canonical per-SKU volume + 1-HL normalization view ──────────────────
-- Named v_sku_volume per the brief.
-- At most 2 levels deep (L1.5 per the sql skill): base tables only, no nested views.
-- LEFT JOINs so cuv / composite / NULL-catalog-id formats appear with NULL
-- derived volume rather than vanishing from the view.
--
-- Columns:
--   sku_id, sku_code, format_id, format_code, run_type
--   container_code   — the container type code (NULL for cuv/composite/P25/P50/PAD/6C)
--   container_hl     — dbc_container_types.hl_per_unit (NULL for cuv, composite)
--   units_per_format — dbc_packaging_format_templates.units_per_format (NULL for composite)
--   volume_hl_derived — units_per_format × container_hl (NULL where chain is incomplete)
--   hl_per_unit_stored — ref_skus.hl_per_unit (the legacy parallel-store value)
--   drift             — hl_per_unit_stored − volume_hl_derived
--                       (0 for standard SKUs, non-zero flags the 4 known drifters)
--   bom_cost_total   — SUM(ref_sku_bom.cost) for this SKU across all BOM lines
--   cost_per_hl      — bom_cost_total / volume_hl_derived (NULL when volume unavailable)
--   containers_per_hl — 1 / volume_hl_derived  (NULL when volume unavailable)
--                       = "how many units fill 1 HL" — the canonical 1-HL normalization

CREATE OR REPLACE VIEW v_sku_volume AS
SELECT
    s.id                                                AS sku_id,
    s.sku_code,
    s.format_id,
    f.format_code,
    f.run_type,
    -- Container chain (NULL for cuv/composite/P25/P50/PAD/6C)
    t.container_code,
    c.hl_per_unit                                       AS container_hl,
    t.units_per_format,
    -- Derived volume: units × container fill (NULL when chain incomplete)
    CASE
        WHEN t.units_per_format IS NOT NULL AND c.hl_per_unit IS NOT NULL
        THEN CAST(t.units_per_format * c.hl_per_unit AS DECIMAL(10,6))
        ELSE NULL
    END                                                 AS volume_hl_derived,
    -- Stored legacy parallel-store value
    s.hl_per_unit                                       AS hl_per_unit_stored,
    -- Drift detector: stored − derived (non-zero = drift alarm; NULL when derived unavailable)
    CASE
        WHEN t.units_per_format IS NOT NULL AND c.hl_per_unit IS NOT NULL
        THEN CAST(s.hl_per_unit - (t.units_per_format * c.hl_per_unit) AS DECIMAL(10,6))
        ELSE NULL
    END                                                 AS drift,
    -- BOM cost aggregate (SUM across all lines for this SKU)
    bom_agg.bom_cost_total,
    -- 1-HL normalization: cost ÷ derived volume
    CASE
        WHEN t.units_per_format IS NOT NULL AND c.hl_per_unit IS NOT NULL
             AND (t.units_per_format * c.hl_per_unit) > 0
        THEN CAST(bom_agg.bom_cost_total / (t.units_per_format * c.hl_per_unit) AS DECIMAL(14,4))
        ELSE NULL
    END                                                 AS cost_per_hl,
    -- containers per HL = 1 / volume (how many units make 1 HL)
    CASE
        WHEN t.units_per_format IS NOT NULL AND c.hl_per_unit IS NOT NULL
             AND (t.units_per_format * c.hl_per_unit) > 0
        THEN CAST(1.0 / (t.units_per_format * c.hl_per_unit) AS DECIMAL(14,4))
        ELSE NULL
    END                                                 AS containers_per_hl
FROM ref_skus s
JOIN ref_packaging_formats f
    ON f.id = s.format_id
LEFT JOIN dbc_packaging_format_templates t
    ON t.id = f.catalog_id
LEFT JOIN dbc_container_types c
    ON c.container_code = t.container_code
LEFT JOIN (
    SELECT sku_id, COALESCE(SUM(cost), 0) AS bom_cost_total
      FROM ref_sku_bom
     GROUP BY sku_id
) AS bom_agg
    ON bom_agg.sku_id = s.id
WHERE s.is_active = 1;

-- Note: views are NOT tracked in schema_meta (confirmed: no v_* rows exist in schema_meta).
-- schema_meta covers tables only. No schema_meta INSERT needed.
