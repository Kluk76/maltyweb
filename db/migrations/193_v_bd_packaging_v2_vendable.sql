-- db/migrations/193_v_bd_packaging_v2_vendable.sql
--
-- What: View v_bd_packaging_v2_vendable that computes vendable_units and
--       vendable_hl per bd_packaging_v2 row, joining ref_skus.hl_per_unit
--       as the single source of truth for format → volume.
--
-- Why:  bd_packaging_v2.vendable_hl was added as a decimal column but never
--       populated by any write path. Live verify 2026-05-28: 2236/2236 rows
--       NULL. The PPB resolver (app/loss-metrics.php) and TankSimulator
--       (app/tank-simulator.php) read this column to UNION with legacy
--       bd_packaging.vendable_hl — so v2's NULL silently drops every form-
--       written packaging event from the loss + WIP calculus. Downstream:
--       226 stale DRAWN_SHORT batches on the C8 PPB surface as of post-fix
--       deploy 2026-05-28.
--
--       Legacy bd_packaging.vendable_hl is operator-entered (form text input,
--       not computed; verified live — MOO4 batch 122 holds 34.439 against no
--       clean prod_total × hl_per_unit reconciliation). The v2 form omits the
--       field. The fix is to derive vendable_hl from the structured fields
--       the v2 form already collects (units in / out per bucket) × the
--       canonical hl_per_unit on ref_skus.
--
-- Formula (per PM ruling, see racking-kpi-dashboard.md / packaging-bom-model.md):
--
--   effective_special =
--       CASE WHEN special_qty_units = prod_total_units THEN 0
--            ELSE COALESCE(special_qty_units, 0) END
--
--     Why: the normalizer that emits a standalone parallel-SKU row for Mode A
--     "Selection Recette" runs mirrors special_qty_units = prod_total_units on
--     that echo row. Without this guard the formula double-counts the parallel
--     volume across the main row and the echo row (PM ruling 2026-05-28; 59
--     such rows live, 16,162 HL phantom vendable). Rows where main carries
--     losses + special != prod (genuine main+parallel within one row) are
--     untouched.
--
--   vendable_units =
--       COALESCE(prod_total_units, 0)
--     + effective_special                          -- parallel runs ADD per memory
--     - COALESCE(qa_analyses_units, 0)
--     - COALESCE(qa_library_units, 0)
--     - COALESCE(unsaleable_units, 0)
--     - COALESCE(loss_4pack_btl_units, 0)
--     - COALESCE(loss_4pack_can_units, 0)
--     - COALESCE(loss_wrap_btl_units, 0)
--     - COALESCE(loss_wrap_can_units, 0)
--     - COALESCE(loss_label_btl_units, 0)
--     - COALESCE(loss_keg_collar_units, 0)
--     - COALESCE(loss_crown_cork_units, 0)
--     - COALESCE(loss_can_lid_units, 0)
--     - COALESCE(loss_keg_save_units, 0)
--     - COALESCE(loss_container_btl_units, 0)
--     - COALESCE(loss_container_can_units, 0)
--
--   vendable_hl =
--       (vendable_units * s.hl_per_unit)          -- units × HL/unit
--     - COALESCE(loss_liquid_other_units, 0) / 100 -- liquid loss in L → HL
--
-- Null contract: rows with sku_id_fk IS NULL (contract_run, 240/2236 today)
-- correctly resolve to vendable_hl = NULL via the LEFT JOIN — never coerce
-- contract rows to 0.
--
-- Live-verified facts (2026-05-28):
--   - ref_skus.hl_per_unit is decimal; populated on every sku_id_fk in scope
--     (sku_with_hl=1996, sku_null_hl=0, orphan_sku=0 across 1996 non-NULL rows).
--   - bd_packaging_v2 sku_id_fk = ref_skus.id (INT) — verified clean JOIN.
--   - 240 NULL sku_id_fk rows are contract_run-flagged by design (PM ruling).
--   - loss_liquid_other_units is decimal (L), same convention as legacy
--     bd_packaging.loss_liquid_l divided by 100 at app/loss-metrics.php:695.
--
-- Risk: NIL — single CREATE OR REPLACE VIEW (metadata-only, INSTANT), one
--   schema_meta upsert. No data writes. No table changes. The column
--   bd_packaging_v2.vendable_hl remains 100% NULL until migration 194
--   backfills it. Read-only objects.
--
-- Rollback:
--   DROP VIEW IF EXISTS v_bd_packaging_v2_vendable;
--   DELETE FROM schema_meta WHERE table_name='v_bd_packaging_v2_vendable';
--
-- NOTE: CREATE OR REPLACE VIEW is DDL via $pdo->exec(); INSERT ... ON DUPLICATE
--   KEY UPDATE is keyed DML via $pdo->exec(). No standalone SELECT statements.

-- ============================================================================
-- VIEW: v_bd_packaging_v2_vendable
-- ============================================================================

CREATE OR REPLACE VIEW v_bd_packaging_v2_vendable AS
SELECT
  p.id,
  p.sku_id_fk,
  s.hl_per_unit,
  (
    COALESCE(p.prod_total_units, 0)
  + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
         ELSE COALESCE(p.special_qty_units, 0) END
  - COALESCE(p.qa_analyses_units,       0)
  - COALESCE(p.qa_library_units,        0)
  - COALESCE(p.unsaleable_units,        0)
  - COALESCE(p.loss_4pack_btl_units,    0)
  - COALESCE(p.loss_4pack_can_units,    0)
  - COALESCE(p.loss_wrap_btl_units,     0)
  - COALESCE(p.loss_wrap_can_units,     0)
  - COALESCE(p.loss_label_btl_units,    0)
  - COALESCE(p.loss_keg_collar_units,   0)
  - COALESCE(p.loss_crown_cork_units,   0)
  - COALESCE(p.loss_can_lid_units,      0)
  - COALESCE(p.loss_keg_save_units,     0)
  - COALESCE(p.loss_container_btl_units,0)
  - COALESCE(p.loss_container_can_units,0)
  ) AS vendable_units,
  CASE WHEN s.hl_per_unit IS NULL THEN NULL
       ELSE CAST(
         (
           COALESCE(p.prod_total_units, 0)
         + CASE WHEN p.special_qty_units = p.prod_total_units THEN 0
                ELSE COALESCE(p.special_qty_units, 0) END
         - COALESCE(p.qa_analyses_units,       0)
         - COALESCE(p.qa_library_units,        0)
         - COALESCE(p.unsaleable_units,        0)
         - COALESCE(p.loss_4pack_btl_units,    0)
         - COALESCE(p.loss_4pack_can_units,    0)
         - COALESCE(p.loss_wrap_btl_units,     0)
         - COALESCE(p.loss_wrap_can_units,     0)
         - COALESCE(p.loss_label_btl_units,    0)
         - COALESCE(p.loss_keg_collar_units,   0)
         - COALESCE(p.loss_crown_cork_units,   0)
         - COALESCE(p.loss_can_lid_units,      0)
         - COALESCE(p.loss_keg_save_units,     0)
         - COALESCE(p.loss_container_btl_units,0)
         - COALESCE(p.loss_container_can_units,0)
         ) * s.hl_per_unit
         - COALESCE(p.loss_liquid_other_units, 0) / 100
         AS DECIMAL(14,4))
  END AS vendable_hl
FROM bd_packaging_v2 p
LEFT JOIN ref_skus s ON s.id = p.sku_id_fk;

-- ============================================================================
-- schema_meta row for v_bd_packaging_v2_vendable
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'v_bd_packaging_v2_vendable',
  'derived',
  'CREATE OR REPLACE VIEW (DDL only)',
  'blocked',
  'Computes vendable_units and vendable_hl per bd_packaging_v2 row from the structured form fields × ref_skus.hl_per_unit. Single source of truth for the v2 packaging vendable formula. Used by migration 194 to backfill the materialised bd_packaging_v2.vendable_hl column and by the on-submit write path in form-packaging.php to compute vendable_hl at write time. If buckets change (new loss column added to bd_packaging_v2), this view must be updated in lockstep with the form schema, and the materialised column re-backfilled. PM-tracked in packaging-bom-model.md.'
)
ON DUPLICATE KEY UPDATE
  table_class          = VALUES(table_class),
  writer_script        = VALUES(writer_script),
  corrections_policy   = VALUES(corrections_policy),
  notes                = VALUES(notes);

-- end migration 193
