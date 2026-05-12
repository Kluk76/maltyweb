-- 038 — Add resolution_note column to doc_review_queue.
--
-- Stores the operator's free-text note when accepting or rejecting a
-- triage row from the web UI. Separate from top_match (which is used
-- by the reconciler for its own metadata) and decided_by (username only).
--
-- Added as part of Step 4 (action submission) of the triage UI.
--
-- Down-migration:
--   ALTER TABLE doc_review_queue DROP COLUMN resolution_note;

ALTER TABLE doc_review_queue
  ADD COLUMN resolution_note TEXT NULL
    COMMENT 'Operator free-text reason set by triage UI actions'
    AFTER decided_by;
