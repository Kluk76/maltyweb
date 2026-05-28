-- db/migrations/192_op_sessions_framework.sql
--
-- What:  Session-framework infra — two new tables (op_sessions, op_session_steps)
--        plus a nullable session_id_fk added to 8 existing event tables, and a
--        one-value ENUM extension on bd_cip_events.source_form ('fermenting').
--
-- Why:   Establishes the operator-session envelope that all form-driven data-capture
--        flows (racking, fermenting, brewing, packaging) will attach to.  The session
--        row is the workflow PARENT; it owns who/when/phase; measured facts continue
--        to live in bd_*_v2 event rows.  The additive session_id_fk on event tables
--        is NULL for all historical rows (non-breaking overlay).  Detailed rationale
--        in ~/.claude/agents/maltyweb-pm-memory/op-sessions-schema-spec.md and its
--        companion session-model-arc.md.
--
-- Risk:  LOW.
--        - CREATE TABLE is net-new; no existing tables touched for the CREATE phase.
--        - ADD COLUMN session_id_fk on 8 event tables: NULLABLE + INSTANT-eligible
--          (no default expression; MySQL 8.0.12+ metadata-only).  ADD FK + ADD KEY
--          fall back to COPY but row counts are ≤ 9000.
--        - ENUM extension on bd_cip_events.source_form: append-only value, INSTANT.
--        - INSERT IGNORE into schema_meta: idempotent via PK on table_name.
--
-- Rollback (manual — MySQL 8 DDL is not transactional):
--   -- 1. Drop additive FKs + columns on event tables (FK-safe order: child tables first)
--   ALTER TABLE bd_cip_events           DROP FOREIGN KEY fk_bd_cip_events_session,           DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_timings_v2   DROP FOREIGN KEY fk_bd_brewing_timings_v2_session,   DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_ingredients_v2 DROP FOREIGN KEY fk_bd_brewing_ingredients_v2_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_gravity_v2   DROP FOREIGN KEY fk_bd_brewing_gravity_v2_session,   DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_brewday_v2   DROP FOREIGN KEY fk_bd_brewing_brewday_v2_session,   DROP COLUMN session_id_fk;
--   ALTER TABLE bd_fermenting_v2        DROP FOREIGN KEY fk_bd_fermenting_v2_session,        DROP COLUMN session_id_fk;
--   ALTER TABLE bd_packaging_v2         DROP FOREIGN KEY fk_bd_packaging_v2_session,         DROP COLUMN session_id_fk;
--   ALTER TABLE bd_racking_v2          DROP FOREIGN KEY fk_bd_racking_v2_session,            DROP COLUMN session_id_fk;
--   -- 2. Narrow ENUM back (only safe if no rows have source_form='fermenting')
--   ALTER TABLE bd_cip_events MODIFY COLUMN source_form ENUM('racking','brewing','packaging') NOT NULL;
--   -- 3. Drop audit spine first (FK to op_sessions), then envelope
--   DROP TABLE op_session_steps;
--   DROP TABLE op_sessions;
--   -- 4. Remove schema_meta seed rows
--   DELETE FROM schema_meta WHERE table_name IN ('op_sessions','op_session_steps');
--   -- 5. Remove migration tracker row
--   DELETE FROM schema_migrations WHERE filename = '192_op_sessions_framework.sql';

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. op_sessions — lifecycle envelope (NEW TABLE)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS op_sessions (
  id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,

  -- WHICH FORM
  form_type            ENUM('racking','fermenting','brewing','packaging') NOT NULL,

  -- VESSEL CONTEXT (polymorphic by vessel_kind; both NULL allowed at open-time
  --   for forms where vessel is not yet assigned.  Eligibility firewall may
  --   require them before phase advance — enforced in app layer, not DB CHECK,
  --   because "required-at-advance" is a workflow predicate, not a row invariant.)
  vessel_kind          ENUM('cct','bbt','yt','fermenter','brewhouse','machine') NULL,
  vessel_number        INT UNSIGNED     NULL,

  -- OPERATIONAL CONTEXT (optional at open; firewall populates before phase advance)
  recipe_id_fk         INT UNSIGNED     NULL,
  batch                VARCHAR(32)      NULL,  -- batch counter, free string; matches bd_*_v2 batch col shape
  client_id_fk         INT UNSIGNED     NULL,  -- contract runs (packaging contract_run=1; wort-contract later); NULL for Neb

  -- LIFECYCLE (phase = current workflow position; status = terminal outcome — ORTHOGONAL)
  phase                ENUM('start','in_progress','end','closed') NOT NULL DEFAULT 'start',
  status               ENUM('open','closed','abandoned')          NOT NULL DEFAULT 'open',

  opened_by_fk         INT UNSIGNED     NOT NULL,  -- users.id; session MUST have a real opener
  opened_at            DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  closed_by_fk         INT UNSIGNED     NULL,      -- users.id; NULL until status <> 'open'
  closed_at            DATETIME(6)      NULL,
  abandon_reason       VARCHAR(255)     NULL,       -- populated iff status='abandoned'

  -- CHAIN (dormant self-link for racking→packaging carryover; NULL today)
  parent_session_id_fk BIGINT UNSIGNED  NULL,

  -- HOUSE bd_* ENVELOPE (matches every bd_*_v2)
  row_hash             CHAR(64)         NOT NULL,   -- sha256(form_type|vessel_kind|vessel_number|opened_by_fk|opened_at) at create
  is_tombstoned        TINYINT(1)       NOT NULL DEFAULT 0,
  audit_flags          JSON             NULL,
  imported_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_op_sessions_row_hash (row_hash),

  KEY ix_op_sessions_status_form  (status, form_type),
  KEY ix_op_sessions_vessel       (vessel_kind, vessel_number, status),
  KEY ix_op_sessions_opened_by    (opened_by_fk, opened_at),
  KEY ix_op_sessions_opened_at    (opened_at),
  KEY ix_op_sessions_recipe       (recipe_id_fk),
  KEY ix_op_sessions_parent       (parent_session_id_fk),

  CONSTRAINT fk_op_sessions_recipe  FOREIGN KEY (recipe_id_fk)         REFERENCES ref_recipes(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_opener  FOREIGN KEY (opened_by_fk)         REFERENCES users(id)        ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_closer  FOREIGN KEY (closed_by_fk)         REFERENCES users(id)        ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_parent  FOREIGN KEY (parent_session_id_fk) REFERENCES op_sessions(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_client  FOREIGN KEY (client_id_fk)         REFERENCES ref_clients(id)  ON DELETE RESTRICT,

  -- CHECK constraint names are table-prefixed (schema-unique per MySQL-8 requirement)
  CONSTRAINT chk_op_sessions_vessel_xor   CHECK (
    (vessel_kind IS NULL AND vessel_number IS NULL) OR
    (vessel_kind IS NOT NULL AND vessel_number IS NOT NULL)
  ),
  CONSTRAINT chk_op_sessions_closed_pair  CHECK (
    (status = 'open'  AND closed_at IS NULL    AND closed_by_fk IS NULL) OR
    (status <> 'open' AND closed_at IS NOT NULL)
  ),
  CONSTRAINT chk_op_sessions_abandon_pair CHECK (
    (status = 'abandoned' AND abandon_reason IS NOT NULL) OR
    (status <> 'abandoned')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. op_session_steps — multi-actor audit spine (NEW TABLE, append-only)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS op_session_steps (
  id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  session_id_fk    BIGINT UNSIGNED  NOT NULL,

  phase            ENUM('start','in_progress','end','closed') NOT NULL,
  step_type        ENUM(
    'firewall_qc_passed',
    'cip_attested',
    'eligibility_attested',
    'phase_advanced',
    'event_linked',
    'handover',
    'note',
    'abandon',
    'recap_acknowledged'
  ) NOT NULL,

  actor_user_id_fk INT UNSIGNED     NOT NULL,  -- users.id; who performed this step (may differ from opener)
  acted_at         DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  payload          JSON             NULL,       -- type-specific context (see spec §2 for per-step-type schema)

  row_hash         CHAR(64)         NOT NULL,   -- sha256(session_id_fk|step_type|actor_user_id_fk|acted_at|payload-canonical)
  imported_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_op_session_steps_row_hash (row_hash),

  KEY ix_op_session_steps_session_acted (session_id_fk, acted_at),
  KEY ix_op_session_steps_actor         (actor_user_id_fk, acted_at),
  KEY ix_op_session_steps_type          (step_type, acted_at),

  CONSTRAINT fk_op_session_steps_session FOREIGN KEY (session_id_fk)    REFERENCES op_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_op_session_steps_actor   FOREIGN KEY (actor_user_id_fk) REFERENCES users(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. schema_meta seed rows for the two new tables
--    INSERT IGNORE: PK on table_name makes re-run a safe no-op.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
  ('op_sessions',
   'source',
   'app/sessions.php',
   'allowed',
   NULL,
   'Session lifecycle envelope (workflow PARENT). Owns who/when/phase only — no derived/measured facts (volume/composition/loss/cadence-count DERIVE from grouped events + sim, per session-model-arc.md). Corrections allowed: operators legitimately fix wrong vessel/recipe via tombstone+resync.'),
  ('op_session_steps',
   'audit',
   'app/sessions.php',
   'blocked',
   'op_sessions',
   'Multi-actor append-only audit spine. Wrong steps are corrected by a NEXT step (note/abandon/etc), never UPDATEd. policy=blocked enforces append-only contract. step_type=event_linked enables reverse traversal session→events without scanning every event table.');

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Widen bd_cip_events.source_form ENUM — add 'fermenting'
--    Required so fermenting sessions can attest CIP events.
--    ENUM append-only extension is INSTANT-compatible.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE bd_cip_events
  MODIFY COLUMN source_form ENUM('racking','brewing','packaging','fermenting') NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Add nullable session_id_fk to 8 event tables (one ALTER per table)
--    Column is BIGINT UNSIGNED NULL.
--    NOTE: ALGORITHM=INSTANT is NOT specified here — MySQL 8 rejects INSTANT when
--    the same ALTER also adds a FOREIGN KEY (error 1846: "Adding foreign keys needs
--    foreign_key_checks=OFF").  MySQL chooses INPLACE automatically for nullable
--    ADD COLUMN, which is online (no table rebuild); row counts ≤ 9000 → fast.
--    NULL = pre-session-model row (historical data stays NULL forever; valid by design).
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE bd_racking_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_racking_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_racking_v2_session (session_id_fk);

ALTER TABLE bd_packaging_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_packaging_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_packaging_v2_session (session_id_fk);

ALTER TABLE bd_fermenting_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_fermenting_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_fermenting_v2_session (session_id_fk);

ALTER TABLE bd_brewing_brewday_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_brewing_brewday_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_brewing_brewday_v2_session (session_id_fk);

ALTER TABLE bd_brewing_gravity_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_brewing_gravity_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_brewing_gravity_v2_session (session_id_fk);

ALTER TABLE bd_brewing_ingredients_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_brewing_ingredients_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_brewing_ingredients_v2_session (session_id_fk);

ALTER TABLE bd_brewing_timings_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_brewing_timings_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_brewing_timings_v2_session (session_id_fk);

ALTER TABLE bd_cip_events
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_cip_events_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_cip_events_session (session_id_fk);

SET @noop = 1;
