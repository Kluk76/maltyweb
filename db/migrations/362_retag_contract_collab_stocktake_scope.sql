-- db/migrations/362_retag_contract_collab_stocktake_scope.sql
--
-- What:  Fix master-data pollution: 34 non-cage SKUs were wrongly stamped
--        stocktake_scope='cage' by a claude-cli session on 2026-06-09
--        (audit_row_revisions ids 12077-12080, 12175-12176 and subsequent
--        bulk-import rows).  The 8 real cages (sku_code LIKE '%-X') are
--        untouched by both UPDATEs (guard: sku_code NOT LIKE '%-X').
--
-- Operator decision (2026-06-15, taken — apply exactly):
--   • DOCB, DOCF  (classification='Neb', recipe_id=29, CollabIn) → 'base'
--     Néb counts them; restores Nausikraft/consignment visibility.
--   • 32 classification='Contract' rows (client-owned product)    → 'none'
--     Néb does NOT stocktake client product; must vanish from count form.
--
-- Pre-flight snapshot:
--   data/snapshots/ref_skus-stocktake_scope-mig362-pre-retag-20260615_124626.json
--   (34 rows, taken 2026-06-15 12:46:26)
--
-- Idempotency:
--   Re-running is safe: WHERE stocktake_scope='cage' is already false
--   for the updated rows after first apply, so both UPDATEs become no-ops.
--
-- Rollback:
--   UPDATE ref_skus rs
--     JOIN audit_row_revisions a ON a.target_table='ref_skus'
--       AND a.target_pk = rs.id
--       AND a.comment = 'mig362_retag_stocktake_scope'
--    SET rs.stocktake_scope = JSON_UNQUOTE(JSON_EXTRACT(a.before_json,'$.stocktake_scope'))
--   WHERE 1;
--   DELETE FROM audit_row_revisions WHERE comment='mig362_retag_stocktake_scope';
--
-- Date   : 2026-06-15
-- Author : migration_362

-- ── STEP 1: CollabIn (Neb classification) → 'base' ──────────────────────────
-- Audit BEFORE the update (INSERT…SELECT — no bare SELECT per house rule)
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_362', 'ref_skus', rs.id, 'update',
  JSON_OBJECT('stocktake_scope', rs.stocktake_scope),
  JSON_OBJECT('stocktake_scope', 'base'),
  'mig362_retag_stocktake_scope'
FROM ref_skus rs
JOIN ref_recipes rc ON rc.id = rs.recipe_id
WHERE rs.stocktake_scope = 'cage'
  AND rs.sku_code NOT LIKE '%-X'
  AND rc.classification = 'Neb'
  AND NOT EXISTS (
    SELECT 1 FROM audit_row_revisions a
     WHERE a.target_table = 'ref_skus'
       AND a.target_pk    = rs.id
       AND a.comment      = 'mig362_retag_stocktake_scope'
  );

UPDATE ref_skus rs
  JOIN ref_recipes rc ON rc.id = rs.recipe_id
   SET rs.stocktake_scope = 'base'
 WHERE rs.stocktake_scope = 'cage'
   AND rs.sku_code NOT LIKE '%-X'
   AND rc.classification = 'Neb';

-- ── STEP 2: Contract (client-owned) → 'none' ────────────────────────────────
-- Audit BEFORE the update
INSERT INTO audit_row_revisions
  (user_id, username, target_table, target_pk, action, before_json, after_json, comment)
SELECT 0, 'migration_362', 'ref_skus', rs.id, 'update',
  JSON_OBJECT('stocktake_scope', rs.stocktake_scope),
  JSON_OBJECT('stocktake_scope', 'none'),
  'mig362_retag_stocktake_scope'
FROM ref_skus rs
JOIN ref_recipes rc ON rc.id = rs.recipe_id
WHERE rs.stocktake_scope = 'cage'
  AND rs.sku_code NOT LIKE '%-X'
  AND rc.classification = 'Contract'
  AND NOT EXISTS (
    SELECT 1 FROM audit_row_revisions a
     WHERE a.target_table = 'ref_skus'
       AND a.target_pk    = rs.id
       AND a.comment      = 'mig362_retag_stocktake_scope'
  );

UPDATE ref_skus rs
  JOIN ref_recipes rc ON rc.id = rs.recipe_id
   SET rs.stocktake_scope = 'none'
 WHERE rs.stocktake_scope = 'cage'
   AND rs.sku_code NOT LIKE '%-X'
   AND rc.classification = 'Contract';
