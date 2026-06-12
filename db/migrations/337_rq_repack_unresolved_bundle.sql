-- 337_rq_repack_unresolved_bundle.sql
-- Add 'repack-unresolved-bundle' to doc_review_queue.type ENUM.
-- Emitted by app/repack.php when a bundle SKU cannot be resolved to
-- a single unambiguous base box (no composite slots, no recipe_id, or
-- multiple base box candidates with no disambiguating prefix match).
ALTER TABLE `doc_review_queue`
  MODIFY COLUMN `type` ENUM(
    'supplier-unknown','ingredient-unknown','gl-drift',
    'archive-candidate','inactive-candidate',
    'dynamic-vs-take-drift','rm-stale','rm-negative','rm-orphan-mi',
    'invoice-no-dn','dn-no-invoice','photonote-audit','sales-sku-unknown',
    'doc-classify-ambiguous','invoice-line-items-needed',
    'dn-invoice-duplicate','dn-low-confidence-line','sku-bom-unresolved',
    'garde_seuil_overdue','contamination_flagged','mother_abandoned',
    'packaged_volume_anomaly','repack-unresolved-bundle'
  ) NOT NULL;
