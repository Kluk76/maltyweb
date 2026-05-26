-- db/migrations/163_view_mi_wac.sql
--
-- What: Create v_mi_wac — live WAC view over inv_deliveries + ref_mi.
--       Computes per-MI weighted average cost in the canonical pricing_unit,
--       using the effective-price basis: eff_unit_price_chf = total_chf / qty_delivered.
--       Also exposes a wac_unresolved flag for MIs with a missing or non-canonical
--       pricing_unit that make the WAC uncomputable.
--
-- Why:  The old snapshot formula (Σ qty_remaining × unit_price × fx) is fragile
--       against rows where unit_price is internally inconsistent (unit_price split
--       wrong but total_chf correct). The total_chf/qty_delivered basis is immune
--       to that class of error, produces the same result when the row is clean,
--       and is the locked architectural decision (audit §6.1 + §10, 2026-05-26).
--
--       Columns exposed:
--         mi_id_fk            INT UNSIGNED — FK to ref_mi.id
--         mi_id               VARCHAR      — human-readable MI code
--         mi_name             VARCHAR      — display name
--         pricing_unit        VARCHAR      — canonical unit (from ref_mi)
--         delivery_rows       BIGINT       — count of qualifying delivery rows
--         supplier_count      BIGINT       — distinct supplier FKs in scope
--         qty_remaining_total DECIMAL      — Σ qty_remaining across qualifying rows
--         total_value_chf     DECIMAL      — Σ(qty_remaining × total_chf/qty_delivered)
--         wac_chf             DECIMAL      — total_value_chf / qty_remaining_total
--         wac_unresolved      TINYINT      — 1 if any qualifying row has NULL or a
--                                           pricing_unit not in the canonical set
--                                           ('kg','g','L','mL','unit','hL','pack')
--
--       Scope predicates (matching _warehouse-compute-wac-snapshot.ts):
--         status = 'Active'
--         AND exclusion_class IS NULL
--         AND ingredient_fk IS NOT NULL
--         AND qty_remaining > 0
--         AND m.is_inventoried = 1
--
--       wac_unresolved = 1 for:
--         • PROC_ALIGAL2_ECO (ref_mi.id=591): pricing_unit IS NULL — unclassified gas
--         • PKG_TEA_BOT_CH   (ref_mi.id=561): pricing_unit='month' — eco-tax, not a
--           real consumable with a per-unit WAC; price intentionally NULL by convention
--       Currently 2 MIs (98 in scope, 2 flagged) per live DB 2026-05-26.
--
-- Risk: LOW — CREATE OR REPLACE VIEW is metadata-only (INSTANT). No data modified.
--       The view is read-only; all writes go through inv_deliveries + ref_mi.
--
-- Expected gate values (hand-verified 2026-05-26, post-162):
--   MALT_MUNICH    (ref_mi.id=1):  wac_chf ≈ 0.4810 CHF/kg  (12506.13/26000 × qrem/qrem = 0.4810)
--   HOPS_SIMCOE    (ref_mi.id=46): wac_chf ≈ 24.997 CHF/kg
--   HOPS_GALAXY    (ref_mi.id=36): wac_chf ≈ 33.926 CHF/kg  (169.6275/5 = 33.9255)
--   YEAST_US05     (ref_mi.id=65): wac_chf ≈ 206.01 CHF/kg
--   YEAST_W3470    (ref_mi.id=66): wac_chf ≈ 339.07 CHF/kg
--   QA_WATER_DEMIN (ref_mi.id=277):wac_chf ≈ 0.6246 CHF/L
--   View row count: 98 (distinct MIs with Active, eligible, qty_remaining>0, is_inventoried=1)
--   wac_unresolved = 1 on exactly 2 MIs (ALIGAL2_ECO + PKG_TEA_BOT_CH)
--
-- SPEC NOTE — MUNICH wac 0.481 vs 481:
--   The spec §10 gate said "MUNICH wac≈481/kg". That is an arithmetic slip.
--   The correct value is 0.481 CHF/kg (= 12506.13 / 26000 × 0.945 correction already
--   in total_chf; eff_unit = total_chf/qty_delivered = 12506.13/26000 = 0.48100 CHF/kg).
--   The view DDL below is correct. The 481 figure comes from reading the pre-fix
--   unit_price (509 EUR/t) rather than the total_chf/qty_del basis.
--
-- Rollback:
--   DROP VIEW IF EXISTS v_mi_wac;
--   DELETE FROM schema_meta WHERE table_name = 'v_mi_wac';

CREATE OR REPLACE VIEW v_mi_wac AS
SELECT
    m.id                              AS mi_id_fk,
    m.mi_id                           AS mi_id,
    m.name                            AS mi_name,
    m.pricing_unit                    AS pricing_unit,

    -- Aggregate counts
    COUNT(d.id)                       AS delivery_rows,
    COUNT(DISTINCT d.supplier_fk)     AS supplier_count,

    -- Quantity basis: sum of qty_remaining across qualifying rows
    SUM(d.qty_remaining)              AS qty_remaining_total,

    -- Value basis: each row contributes qty_remaining × its effective CHF unit price.
    -- eff_unit_price_chf = total_chf / qty_delivered
    -- (total_chf already has EUR→CHF applied at ingest; no additional FX multiply needed)
    SUM(d.qty_remaining * (d.total_chf / d.qty_delivered))
                                      AS total_value_chf,

    -- WAC: weighted average cost in the MI's canonical pricing_unit
    -- NULL when qty_remaining_total=0 (guarded by scope predicate qty_remaining>0,
    -- but NULLIF added as defence-in-depth against floating-point edge cases)
    SUM(d.qty_remaining * (d.total_chf / d.qty_delivered))
    / NULLIF(SUM(d.qty_remaining), 0) AS wac_chf,

    -- wac_unresolved: 1 when any qualifying row has a NULL or non-standard pricing_unit
    -- that prevents a meaningful per-canonical-unit WAC interpretation.
    -- Callers MUST surface this flag; never treat a flagged WAC as reliable.
    MAX(CASE
          WHEN m.pricing_unit IS NULL THEN 1
          WHEN m.pricing_unit NOT IN (
                'kg', 'g', 'L', 'mL', 'unit', 'hL', 'pack'
               ) THEN 1
          ELSE 0
        END)                          AS wac_unresolved

FROM inv_deliveries d
JOIN ref_mi m ON m.id = d.ingredient_fk

WHERE d.status          = 'Active'
  AND d.exclusion_class IS NULL
  AND d.ingredient_fk   IS NOT NULL
  AND d.qty_remaining   > 0
  AND d.qty_delivered   > 0        -- guard: NULLIF above also protects, but be explicit
  AND m.is_inventoried  = 1

GROUP BY m.id, m.mi_id, m.name, m.pricing_unit;

-- schema_meta row for v_mi_wac
-- table_class='derived': the view is a computed read surface, not a canonical store.
-- corrections_policy='blocked_with_redirect': to change the WAC value for a MI,
--   fix the upstream inv_deliveries row (source) or ref_mi.pricing_unit (reference).
--   Never update the view output directly — it has no stored rows.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('v_mi_wac', 'derived', 'blocked_with_redirect',
     'migration 163 (CREATE OR REPLACE VIEW)',
     'Live view over inv_deliveries + ref_mi. WAC = SUM(qty_remaining × total_chf/qty_delivered) / SUM(qty_remaining). To change a WAC value: fix inv_deliveries (qty_delivered, total_chf) or ref_mi.pricing_unit upstream, then re-query the view. Stale wac_snapshots: recompute via npx tsx scripts/_phase2d-recompute-wac.ts --apply.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
