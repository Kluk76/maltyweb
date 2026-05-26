-- db/migrations/161_view_bip_canonical.sql
-- What: Create v_bip_canonical — a view over bd_brewing_ingredients_parsed_v2 that
--       exposes a density-aware qty_priced column (qty expressed in the MI pricing
--       unit, ready to multiply by price) and a factor_unresolved flag.
-- Why:  The inline IF(bip.unit='g', 0.001, 1) used across warehouse.php (7 spots)
--       and warehouse-export.php (1 spot) silently treats ml-volume rows as kg-mass,
--       overstating costs by the ~593–778× density ratio. 627 PROC_PHOSPHORIQUE +
--       15 PROC_CLAREX + 10 PROC_DEHAZE rows in bd_brewing_ingredients_parsed_v2
--       are in ml. All 4 PROC_ aids now carry density_g_per_ml on ref_mi. The view
--       centralises the conversion so warehouse.php + warehouse-export.php join once
--       and use qty_priced — one source of truth, no copy-paste drift.
--       Canonical logic mirrors unit_to_canonical_factor() in app/recipe-ingredients-loader.php.
-- Risk: LOW — CREATE OR REPLACE VIEW is metadata-only (INSTANT). Read-only view;
--       no data is modified. All 4 PROC_ MIs have density_g_per_ml set (migration
--       157–160) so factor_unresolved=0 for every live ml row.
-- Rollback: DROP VIEW IF EXISTS v_bip_canonical;
--           DELETE FROM schema_meta WHERE table_name = 'v_bip_canonical';

CREATE OR REPLACE VIEW v_bip_canonical AS
SELECT
    bip.id,
    bip.header_id,
    bip.line_idx,
    bip.category,
    bip.mi_id_fk,
    bip.raw_name,
    bip.qty,
    bip.unit,
    bip.lot,
    bip.confidence,
    bip.parse_note,
    bip.source_row,
    bip.imported_at,
    bip.updated_at,
    -- qty_priced: qty expressed in the MI pricing_unit, ready to × price.
    -- Mirrors unit_to_canonical_factor() in app/recipe-ingredients-loader.php.
    -- NULL (factor_unresolved=1) when a safe conversion cannot be determined —
    -- callers must surface this, never silently drop or treat NULL as 0.
    CASE
        WHEN bip.unit = 'g'  AND m.pricing_unit = 'kg' THEN bip.qty * 0.001
        WHEN bip.unit = 'kg' AND m.pricing_unit = 'kg' THEN bip.qty
        WHEN bip.unit = 'ml' AND m.pricing_unit = 'kg' AND m.density_g_per_ml IS NOT NULL
                                                        THEN bip.qty * m.density_g_per_ml * 0.001
        WHEN bip.unit = 'ml' AND m.pricing_unit = 'l'  THEN bip.qty * 0.001
        WHEN bip.unit = m.pricing_unit                  THEN bip.qty
        ELSE NULL
    END AS qty_priced,
    -- factor_unresolved=1 when qty_priced is NULL (no safe conversion available).
    -- A future ml aid without a confirmed density will land here — visible, not silent.
    CASE
        WHEN bip.unit = 'g'  AND m.pricing_unit = 'kg' THEN 0
        WHEN bip.unit = 'kg' AND m.pricing_unit = 'kg' THEN 0
        WHEN bip.unit = 'ml' AND m.pricing_unit = 'kg' AND m.density_g_per_ml IS NOT NULL
                                                        THEN 0
        WHEN bip.unit = 'ml' AND m.pricing_unit = 'l'  THEN 0
        WHEN bip.unit = m.pricing_unit                  THEN 0
        ELSE 1
    END AS factor_unresolved
FROM bd_brewing_ingredients_parsed_v2 bip
JOIN ref_mi m ON m.id = bip.mi_id_fk;

-- schema_meta row for v_bip_canonical
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('v_bip_canonical', 'derived', 'blocked_with_redirect',
     'migration 161 (CREATE OR REPLACE VIEW)',
     'View over bd_brewing_ingredients_parsed_v2 + ref_mi. Recomputed live on every query. To change conversion logic: update this view definition via a new migration.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
