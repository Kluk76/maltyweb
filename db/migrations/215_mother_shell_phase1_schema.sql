-- Migration 215: mother shell Phase 1 schema
--
-- Foundational schema for the mother-shell production board.
-- Phases 2-5 (vessel SVG rework + board build) depend on this.
--
-- Pre-flight verified (2026-05-29):
--   - op_sessions.id is BIGINT UNSIGNED (all new FKs match type)
--   - parent_session_id_fk already exists as BIGINT UNSIGNED NULL with
--     KEY ix_op_sessions_parent and FK fk_op_sessions_parent (ON DELETE RESTRICT)
--     → already live; no ADD COLUMN needed. Self-FK is on op_sessions.id.
--   - Existing CHECK names: chk_op_sessions_abandon_pair, chk_op_sessions_closed_pair,
--     chk_op_sessions_vessel_xor — all schema-unique; new name does not collide.
--   - doc_review_queue.type current ENUM values preserved in full.
--   - MySQL 8.0.45 — no ADD COLUMN IF NOT EXISTS; idempotency via schema_migrations.
--
-- Changes:
--   1. op_sessions.form_type ENUM extended with 'batch' (additive; existing rows unaffected)
--   2. op_sessions.merged_into_session_id_fk BIGINT UNSIGNED NULL — self-FK for
--      surviving-mother merge model (PM-locked architecture)
--   3. op_sessions.blend_share_pct DECIMAL(5,2) NULL — % share of departing child
--      within survivor (e.g. 40.00); NULL for non-blended mothers
--   4. CHECK chk_op_sessions_blend_share_range: blend_share_pct NULL or 0..100
--   5. Generated column op_sessions.open_mother_key CHAR(1) — used for the filtered
--      UNIQUE index (one open mother per recipe+batch). NULL unless this is an open
--      mother batch session; UNIQUE on (recipe_id_fk, batch, open_mother_key) enforces
--      exactly one open mother per (recipe, batch).
--   6. doc_review_queue.type ENUM extended with 4 new values.
--   7. schema_meta rows for traceability.

-- ─── 1. op_sessions.form_type — add 'batch' ──────────────────────────────────
ALTER TABLE op_sessions
  MODIFY COLUMN form_type
    ENUM('racking','fermenting','brewing','packaging','batch')
    COLLATE utf8mb4_unicode_ci NOT NULL;

-- ─── 2. op_sessions.merged_into_session_id_fk ────────────────────────────────
ALTER TABLE op_sessions
  ADD COLUMN merged_into_session_id_fk BIGINT UNSIGNED NULL DEFAULT NULL
    AFTER parent_session_id_fk,
  ADD KEY ix_op_sessions_merged_into (merged_into_session_id_fk);

ALTER TABLE op_sessions
  ADD CONSTRAINT fk_op_sessions_merged_into
    FOREIGN KEY (merged_into_session_id_fk)
    REFERENCES op_sessions (id)
    ON DELETE SET NULL;

-- ─── 3. op_sessions.blend_share_pct ──────────────────────────────────────────
ALTER TABLE op_sessions
  ADD COLUMN blend_share_pct DECIMAL(5,2) NULL DEFAULT NULL
    AFTER merged_into_session_id_fk;

-- ─── 4. CHECK constraint: blend_share_pct 0..100 or NULL ─────────────────────
ALTER TABLE op_sessions
  ADD CONSTRAINT chk_op_sessions_blend_share_range
    CHECK (blend_share_pct IS NULL OR (blend_share_pct >= 0 AND blend_share_pct <= 100))
    ENFORCED;

-- ─── 5. Filtered UNIQUE: one open mother per (recipe, batch) ─────────────────
-- Strategy: generated column is '1' only when the row IS an open mother batch
-- session (form_type='batch' AND merged_into_session_id_fk IS NULL AND
-- status='open'). NULL otherwise. A UNIQUE index on (recipe_id_fk, batch,
-- open_mother_key) lets NULLs bypass (MySQL UNIQUE ignores NULL values),
-- so only the open-mother sentinel value '1' is constrained to be unique
-- per (recipe, batch).
ALTER TABLE op_sessions
  ADD COLUMN open_mother_key CHAR(1) COLLATE utf8mb4_unicode_ci
    GENERATED ALWAYS AS (
      CASE
        WHEN form_type = 'batch'
         AND merged_into_session_id_fk IS NULL
         AND status = 'open'
        THEN '1'
        ELSE NULL
      END
    ) VIRTUAL NULL;

CREATE UNIQUE INDEX uniq_active_mother
  ON op_sessions (recipe_id_fk, batch, open_mother_key);

-- ─── 6. doc_review_queue.type — add 4 new ENUM values ────────────────────────
-- Full current set preserved; new values appended.
ALTER TABLE doc_review_queue
  MODIFY COLUMN type
    ENUM(
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
      'packaged_volume_anomaly'
    ) NOT NULL;

-- ─── 7. schema_meta rows ─────────────────────────────────────────────────────
-- op_sessions already has a schema_meta row (classification = 'source',
-- corrections_policy = 'allowed'); no duplicate needed.
-- This SET @noop satisfies migrate.php's no-SELECT rule for the trailing statement.
SET @noop = 1;
