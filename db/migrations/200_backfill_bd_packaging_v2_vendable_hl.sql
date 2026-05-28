-- db/migrations/194_backfill_bd_packaging_v2_vendable_hl.sql
--
-- What: (1) Re-apply v_bd_packaging_v2_vendable with the echo-row guard.
--       (2) Backfill bd_packaging_v2.vendable_hl from the view for every
--           row where the column is currently NULL and sku_id_fk IS NOT NULL.
--       (3) Log every UPDATEd row to audit_row_revisions.
--
-- Why:  Migration 193 created the view; PM ruling 2026-05-28 added an
--       echo-row guard (zero special_qty when it equals prod_total — the
--       normalizer mirrors special on parallel-SKU rows; without the guard
--       16,162 HL of phantom vendable doubles-up). The view is rebuilt here
--       so this migration is the single carrier of the guarded formula and
--       the column materialisation. Forward consumers (loss-metrics, tank
--       simulator, COGS) read the materialised column.
--
-- Live-verified facts (2026-05-28, post-mig-193):
--   - 1996 rows have sku_id_fk IS NOT NULL and vendable_hl IS NULL — scope
--     of the backfill.
--   - 240 rows have sku_id_fk IS NULL (contract_run) — correctly left NULL.
--   - 59 echo rows where special_qty_units = prod_total_units exist; the
--     guard prevents 16,162 HL of phantom volume across them.
--   - audit_row_revisions table is the canonical audit destination
--     (verified schema_meta row 'allowed' policy on bd_packaging_v2;
--     audit_row_revisions writer = app/db-write-helpers.php::log_revision()).
--
-- Risk: MEDIUM — writes to ~1996 rows in a high-traffic table. Mitigations:
--   - CREATE OR REPLACE VIEW is INSTANT/metadata-only.
--   - UPDATE is scoped to vendable_hl IS NULL only (idempotent re-run is no-op).
--   - audit_row_revisions captures before_json (vendable_hl=NULL) and
--     after_json (computed value) for every change.
--   - sku_id_fk IS NULL rows skipped — no contract_run pollution.
--   - is_tombstoned = 0 — tombstoned rows skipped.
--
-- Rollback:
--   UPDATE bd_packaging_v2 SET vendable_hl = NULL
--    WHERE id IN (SELECT target_pk FROM audit_row_revisions
--                  WHERE target_table='bd_packaging_v2'
--                    AND comment='backfill_vendable_hl_mig194');
--   DELETE FROM audit_row_revisions
--    WHERE target_table='bd_packaging_v2'
--      AND comment='backfill_vendable_hl_mig194';
--
-- NOTE: CREATE OR REPLACE VIEW + UPDATE + INSERT all migrate.php-safe.
--   No standalone SELECT statements.

-- ============================================================================
-- STEP 1: Re-apply v_bd_packaging_v2_vendable WITH echo-row guard
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
-- STEP 2: Audit insert for every row about to change.
-- ============================================================================
-- INSERT first; UPDATE second. If the UPDATE fails the audit rows are still
-- recoverable as a paper trail of intent (they record vendable_hl=NULL → new).

INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT
  0                                                AS user_id,
  'migration_194'                                  AS username,
  'bd_packaging_v2'                                AS target_table,
  p.id                                             AS target_pk,
  'update'                                         AS action,
  JSON_OBJECT('vendable_hl', p.vendable_hl)        AS before_json,
  JSON_OBJECT('vendable_hl', v.vendable_hl)        AS after_json,
  'backfill_vendable_hl_mig194'                    AS comment
FROM bd_packaging_v2 p
JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
WHERE p.vendable_hl IS NULL
  AND p.sku_id_fk IS NOT NULL
  AND p.is_tombstoned = 0
  AND v.vendable_hl IS NOT NULL;

-- ============================================================================
-- STEP 3: Backfill UPDATE.
-- ============================================================================

UPDATE bd_packaging_v2 p
JOIN v_bd_packaging_v2_vendable v ON v.id = p.id
   SET p.vendable_hl = v.vendable_hl
 WHERE p.vendable_hl IS NULL
   AND p.sku_id_fk IS NOT NULL
   AND p.is_tombstoned = 0
   AND v.vendable_hl IS NOT NULL;

-- ============================================================================
-- STEP 4: schema_meta refresh — bd_packaging_v2 now carries a derived column.
-- ============================================================================

INSERT INTO schema_meta
  (table_name, table_class, writer_script, corrections_policy, notes)
VALUES (
  'bd_packaging_v2',
  'source',
  'public/modules/form-packaging.php + python/ingest_*.py',
  'allowed',
  'Web-form packaging events. vendable_hl is computed at write-time from prod_total/special_qty/QA/loss_* × ref_skus.hl_per_unit (see v_bd_packaging_v2_vendable). Echo-row guard: rows with special_qty_units=prod_total_units are normalizer-emitted parallel-SKU mirrors and treat special as 0 (PM ruling 2026-05-28). Backfilled mig 194.'
)
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);

-- end migration 194
