-- 036 — MI proposal audit trail.
--
-- Records every MI-creation event that flows through the triage UI's
-- "create" action. Captures both the system's proposed values and the
-- operator's validated values so drift can be measured and inference
-- quality improved over time.
--
-- validated_* columns are required (NOT NULL) because the row is only
-- written once the operator has confirmed a choice — before that there
-- is nothing to persist.
--
-- Drift flags are GENERATED STORED columns (MySQL 5.7+; we are on 8.0):
-- computed once on INSERT, never updated, cheap to query in analytics.
-- They capture the two dimensions that matter most: did the operator
-- override the proposed ID, and did they override the GL account?
--
-- Down-migration:
--   DROP TABLE IF EXISTS mi_proposals_audit;

CREATE TABLE IF NOT EXISTS mi_proposals_audit (
  id                       BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  created_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Who validated (required — row written on operator action).
  user_id                  INT UNSIGNED     NOT NULL,

  -- Which review-queue row triggered this, if any.
  rq_id                    BIGINT UNSIGNED  NULL,

  -- Raw invoice text that needed an MI.
  raw_line_text            TEXT             NOT NULL,

  -- Supplier context (nullable — some proposals have no supplier signal).
  supplier_id              INT UNSIGNED     NULL,

  -- System proposition (all nullable — null means no signal was available).
  proposed_mi_id           VARCHAR(64)      NULL,
  proposed_category        VARCHAR(64)      NULL,
  proposed_subcategory     VARCHAR(64)      NULL,
  proposed_account         VARCHAR(8)       NULL,
  proposed_name            VARCHAR(255)     NULL,
  proposition_confidence   DECIMAL(4,3)     NULL,
  similar_mi_ids           JSON             NULL,   -- top-3 similar existing IDs

  -- Operator-validated values (required).
  validated_mi_id          VARCHAR(64)      NOT NULL,
  validated_category       VARCHAR(64)      NOT NULL,
  validated_subcategory    VARCHAR(64)      NOT NULL,
  validated_account        VARCHAR(8)       NOT NULL,
  validated_name           VARCHAR(255)     NOT NULL,

  -- Drift flags: STORED so they are precomputed and queryable without
  -- re-evaluating NULLs at report time.
  id_overridden      TINYINT(1) GENERATED ALWAYS AS (
    CASE WHEN proposed_mi_id IS NOT NULL
              AND proposed_mi_id <> validated_mi_id THEN 1 ELSE 0 END
  ) STORED,
  account_overridden TINYINT(1) GENERATED ALWAYS AS (
    CASE WHEN proposed_account IS NOT NULL
              AND proposed_account <> validated_account THEN 1 ELSE 0 END
  ) STORED,

  -- Operator free-text reason (only expected when they overrode something).
  notes                    TEXT             NULL,

  PRIMARY KEY (id),
  KEY idx_mpa_created_at       (created_at),
  KEY idx_mpa_supplier_id      (supplier_id),
  KEY idx_mpa_validated_mi_id  (validated_mi_id),

  CONSTRAINT fk_mpa_user
    FOREIGN KEY (user_id)    REFERENCES users(id)             ON DELETE RESTRICT,
  CONSTRAINT fk_mpa_rq
    FOREIGN KEY (rq_id)      REFERENCES doc_review_queue(id)  ON DELETE SET NULL,
  CONSTRAINT fk_mpa_supplier
    FOREIGN KEY (supplier_id) REFERENCES ref_suppliers(id)    ON DELETE RESTRICT

  -- No FK on validated_mi_id against ref_mi.id because the MI row may not
  -- exist yet at INSERT time — the triage action creates it in the same
  -- request. The mi_id string is the stable external key; the surrogate id
  -- is only needed if we later add ON UPDATE CASCADE tracking, handled via
  -- a separate migration when ref_mi gets a proper write-web path.

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
