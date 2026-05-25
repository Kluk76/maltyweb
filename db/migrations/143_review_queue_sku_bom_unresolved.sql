-- db/migrations/143_review_queue_sku_bom_unresolved.sql
-- What: Add 'sku-bom-unresolved' to doc_review_queue.type ENUM
-- Why:  The ref_sku_bom packaging-only recompute service emits RQ rows for any
--       slot it cannot resolve to a non-NULL MI (refuse-don't-NULL discipline).
--       Migration 143 lands before the service first writes such rows.
-- Risk: MODIFY COLUMN on an ENUM — MySQL rewrites the row format but the operation
--       is safe and idempotent (applying the identical same-plus-one list again is
--       a no-op at the metadata level; no data loss possible since 'sku-bom-unresolved'
--       only adds a value, never removes or renames one).
-- Rollback: ALTER TABLE doc_review_queue MODIFY type ENUM('supplier-unknown',
--   'ingredient-unknown','gl-drift','archive-candidate','inactive-candidate',
--   'dynamic-vs-take-drift','rm-stale','rm-negative','rm-orphan-mi','invoice-no-dn',
--   'dn-no-invoice','photonote-audit','sales-sku-unknown','doc-classify-ambiguous',
--   'invoice-line-items-needed','dn-invoice-duplicate','dn-low-confidence-line') NOT NULL;
--   (only safe if no 'sku-bom-unresolved' rows exist yet)
-- No schema_meta change: doc_review_queue already classified (existing table).

ALTER TABLE doc_review_queue
  MODIFY COLUMN type ENUM(
    'supplier-unknown',
    'ingredient-unknown',
    'gl-drift',
    'archive-candidate',
    'inactive-candidate',
    'dynamic-vs-take-drift',
    'rm-stale',
    'rm-negative',
    'rm-orphan-mi',
    'invoice-no-dn',
    'dn-no-invoice',
    'photonote-audit',
    'sales-sku-unknown',
    'doc-classify-ambiguous',
    'invoice-line-items-needed',
    'dn-invoice-duplicate',
    'dn-low-confidence-line',
    'sku-bom-unresolved'
  ) NOT NULL;
