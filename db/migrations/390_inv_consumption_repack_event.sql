-- 390_inv_consumption_repack_event.sql
--
-- Extends inv_consumption.source_event ENUM to include 'repack'.
-- Rationale: when logistics break a finished 24-box or 6×4 box to harvest
-- member bottles for PD8 assembly, the destroyed outer carton is a realized
-- packaging cost that must flow into inv_consumption. The new source_event
-- value 'repack' identifies rows written by derive-repack-carton-consumption.ts
-- (the sole writer for this event type).
--
-- Also extends doc_review_queue.type ENUM with 'repack-carton-unresolved'
-- so that the deriver can flag box-sourced repack events whose outer_box
-- BOM line is missing (requires operator attention — must not book zero silently).
--
-- Both are ENUM extensions on existing tables. No new tables, no new schema_meta
-- row needed (neither table class nor writer_script changes for these tables).
-- MySQL 8 syntax: no IF NOT EXISTS on ALTER TABLE clauses (idempotency comes
-- from schema_migrations).

ALTER TABLE inv_consumption
  MODIFY source_event ENUM(
    'brewing',
    'fermenting',
    'racking',
    'packaging',
    'manual',
    'sales_derived',
    'repack'
  ) NOT NULL;

ALTER TABLE doc_review_queue
  MODIFY type ENUM(
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
    'sku-bom-unresolved',
    'garde_seuil_overdue',
    'contamination_flagged',
    'mother_abandoned',
    'packaged_volume_anomaly',
    'repack-unresolved-bundle',
    'bc-customer-identity-drift',
    'bc-order-correction-required',
    'repack-carton-unresolved'
  ) NOT NULL;
