-- db/migrations/170_view_mi_wac_exclude_month_cadence.sql
--
-- What: Extend v_mi_wac with a NULL-safe exclusion of MIs whose pricing_unit='month'
--       (recurring monthly levies with no meaningful per-unit WAC).
--
-- Why:  PKG_TEA_BOT_CH (ref_mi.id=561, pricing_unit='month', GL 4201) is a recurring
--       monthly TEA bottle eco-tax levy. It has no per-unit WAC — the 'month' cadence
--       is deliberate accounting convention, not a data quality gap. Excluding it from
--       the v_mi_wac surface closes its wac_unresolved=1 flag (wac_unresolved 2→1 after
--       migration 169 removed ALIGAL2_ECO; this migration takes it to 0).
--       PKG_TEA_BOT_CH still reaches COP correctly via the GL-4201 Packaging sweep —
--       COP does NOT read v_mi_wac, so this is a WAC-surface-only change.
--
-- NULL-safe form rationale:
--   A bare `m.pricing_unit <> 'month'` would ALSO drop rows where pricing_unit IS NULL
--   (NULL <> 'month' evaluates to UNKNOWN → excluded). We WANT NULL-pricing-unit
--   inventoried rows to REMAIN in the view so they continue flagging as wac_unresolved=1
--   (that is a genuine data smell to surface). Only the literal 'month' value is excluded.
--   After migration 169, PROC_ALIGAL2_ECO (fk591) is the only NULL-pricing-unit
--   is_inventoried=1 row, and it is now is_inventoried=0 (already filtered by the
--   is_inventoried=1 predicate) — but the NULL-safe form is correct for robustness.
--
-- Verified live 2026-05-27: fk561 (PKG_TEA_BOT_CH) is the ONLY is_inventoried=1 row
--   with pricing_unit='month'. Count=1.
--
-- View body: EXACT reproduction of migration 163 v_mi_wac, with ONE addition to the
--   WHERE clause: AND (m.pricing_unit IS NULL OR m.pricing_unit <> 'month')
--   No other change to logic, columns, GROUP BY, or schema_meta row.
--
-- Expected effect (post-169): MI count 97→96; wac_unresolved 1→0.
--
-- Risk: LOW — CREATE OR REPLACE VIEW is metadata-only (INSTANT). No data modified.
--
-- Rollback (restore migration 163's body):
--   -- CREATE OR REPLACE VIEW v_mi_wac AS
--   -- SELECT
--   --     m.id                              AS mi_id_fk,
--   --     m.mi_id                           AS mi_id,
--   --     m.name                            AS mi_name,
--   --     m.pricing_unit                    AS pricing_unit,
--   --     COUNT(d.id)                       AS delivery_rows,
--   --     COUNT(DISTINCT d.supplier_fk)     AS supplier_count,
--   --     SUM(d.qty_remaining)              AS qty_remaining_total,
--   --     SUM(d.qty_remaining * (d.total_chf / d.qty_delivered))
--   --                                       AS total_value_chf,
--   --     SUM(d.qty_remaining * (d.total_chf / d.qty_delivered))
--   --     / NULLIF(SUM(d.qty_remaining), 0) AS wac_chf,
--   --     MAX(CASE
--   --           WHEN m.pricing_unit IS NULL THEN 1
--   --           WHEN m.pricing_unit NOT IN (
--   --                 'kg', 'g', 'L', 'mL', 'unit', 'hL', 'pack'
--   --                ) THEN 1
--   --           ELSE 0
--   --         END)                          AS wac_unresolved
--   -- FROM inv_deliveries d
--   -- JOIN ref_mi m ON m.id = d.ingredient_fk
--   -- WHERE d.status          = 'Active'
--   --   AND d.exclusion_class IS NULL
--   --   AND d.ingredient_fk   IS NOT NULL
--   --   AND d.qty_remaining   > 0
--   --   AND d.qty_delivered   > 0
--   --   AND m.is_inventoried  = 1
--   -- GROUP BY m.id, m.mi_id, m.name, m.pricing_unit;
--
-- NOTE: CREATE OR REPLACE VIEW ... AS SELECT is migrate.php-safe (DDL via exec(),
--   not a bare result-set SELECT). The "no bare SELECT" rule covers standalone
--   SELECT statements; DDL is fine.

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
  -- Exclude month-cadence levies: recurring monthly fees (e.g. PKG_TEA_BOT_CH TEA eco-tax)
  -- have no meaningful per-unit WAC. NULL-safe: keeps NULL-pricing-unit rows in scope
  -- so they continue surfacing as wac_unresolved=1 (genuine data smell).
  AND (m.pricing_unit IS NULL OR m.pricing_unit <> 'month')

GROUP BY m.id, m.mi_id, m.name, m.pricing_unit;

-- schema_meta: update writer_script to record this migration supersedes 163.
-- table_class, corrections_policy, upstream_hint unchanged from migration 163.
INSERT INTO schema_meta
    (table_name, table_class, corrections_policy, writer_script, upstream_hint)
VALUES
    ('v_mi_wac', 'derived', 'blocked_with_redirect',
     'migration 170 (CREATE OR REPLACE VIEW — adds month-cadence exclusion)',
     'Live view over inv_deliveries + ref_mi. WAC = SUM(qty_remaining × total_chf/qty_delivered) / SUM(qty_remaining). To change a WAC value: fix inv_deliveries (qty_delivered, total_chf) or ref_mi.pricing_unit upstream, then re-query the view. Stale wac_snapshots: recompute via npx tsx scripts/_phase2d-recompute-wac.ts --apply.')
ON DUPLICATE KEY UPDATE
    table_class        = VALUES(table_class),
    corrections_policy = VALUES(corrections_policy),
    writer_script      = VALUES(writer_script),
    upstream_hint      = VALUES(upstream_hint);
