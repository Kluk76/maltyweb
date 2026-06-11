# op_sessions / op_session_steps — migration-ready schema spec

> PM ruling 2026-05-28. The data-model SPEC for the session-framework infra (operator A-confirmed 2026-05-28). Companion to session-model-arc.md (which holds the strategic ruling + state machine + sequencing). This file is the migration-ready column-by-column spec — handed to the coding agent who builds the migration. Load when building migration 192 (the session-framework infra), or when reasoning about the precise shape of op_sessions / op_session_steps / the additive session_id_fk on event tables.

## VERIFIED LIVE GROUND TRUTH (PM-verified 2026-05-28)
- All v2 event tables: `id BIGINT UNSIGNED PRI AUTO_INCREMENT`; `row_hash CHAR(64) NOT NULL UNI` (UNI on all v2 incl. bd_packaging_v2 + bd_fermenting_v2 — CORRECTING the operator's brief, both ARE UNI on v2; the non-UNI bd_packaging is the LEGACY read-only table). `bd_brewing_ingredients_parsed_v2` is the ONE exception (no row_hash, it's `derived`/`allowed_with_side_effect` — recomputed from `bd_brewing_ingredients_v2`).
- Row counts: racking 400 | packaging_v2 2236 | fermenting_v2 6675 | fermenting (legacy) 6570 | brewday 803 | gravity 8618 | ingredients_v2 677 | ingredients_parsed_v2 4897 | timings_v2 2243 | cip_events 1093.
- `bd_cip_events.source_form` = `ENUM('racking','brewing','packaging')` (681 / 412 / 0 today — packaging form has not yet written CIP events live; 0 fermenting today).
- `bd_cip_events.submitted_at` = `varchar(32) NULL` (NOT datetime — operator-form ISO strings; do NOT copy this oddity into op_sessions, use proper DATETIME).
- `schema_meta` PK = `table_name` (UNIQUE by PK; no separate UNIQUE needed for ON DUPLICATE KEY UPDATE / WHERE NOT EXISTS).
- `users` table EXISTS: `id INT UNSIGNED PRI`, `username VARCHAR(64) UNI`, role ENUM, is_active. Currently 1 row. **FK target for operator attribution = `users.id INT UNSIGNED`** (not VARCHAR — real FK).
- `ref_recipes.id INT UNSIGNED PRI`.
- No existing `op_*` / `*session*` tables — green-field.
- Latest applied migration = `191_deactivate_zep6c_discontinued_sku.sql`; **NEXT FREE = 192** (re-check `migrate.php --status` at build).

## 1. `op_sessions` — lifecycle envelope (NEW TABLE)

```sql
CREATE TABLE op_sessions (
  id                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,

  -- WHICH FORM
  form_type           ENUM('racking','fermenting','brewing','packaging') NOT NULL,

  -- VESSEL CONTEXT (polymorphic by vessel_kind; both NULL allowed at open-time
  --   for forms where vessel is not yet assigned — packaging at start, brewing
  --   before vessel pick; eligibility firewall MAY require them populated
  --   before phase advance — enforced in app layer, not DB CHECK, because
  --   "required-at-advance" is a workflow predicate, not a row invariant)
  vessel_kind         ENUM('cct','bbt','yt','fermenter','brewhouse','machine') NULL,
  vessel_number       INT UNSIGNED      NULL,

  -- OPERATIONAL CONTEXT (optional at open; firewall fills before phase advance)
  recipe_id_fk        INT UNSIGNED      NULL,
  batch               VARCHAR(32)       NULL,                     -- batch counter, free string; matches existing bd_*_v2 batch col shape
  client_id_fk        INT UNSIGNED      NULL,                     -- contract runs (packaging w/ contract_run=1; wort-contract later); NULL for Neb

  -- LIFECYCLE — phase and status are ORTHOGONAL (phase=where we are in the workflow; status=terminal outcome)
  phase               ENUM('start','in_progress','end','closed') NOT NULL DEFAULT 'start',
  status              ENUM('open','closed','abandoned')           NOT NULL DEFAULT 'open',

  opened_by_fk        INT UNSIGNED      NOT NULL,                 -- users.id (FK below); a session MUST be opened by a real user
  opened_at           DATETIME(6)       NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  closed_by_fk        INT UNSIGNED      NULL,                     -- users.id; NULL until status<>'open'
  closed_at           DATETIME(6)       NULL,
  abandon_reason      VARCHAR(255)      NULL,                     -- populated iff status='abandoned'; free text + future ENUM if patterns emerge

  -- CHAIN (optional self-link for racking→packaging carryover if/when needed; NULL today)
  parent_session_id_fk BIGINT UNSIGNED  NULL,

  -- HOUSE bd_* ENVELOPE (matches every bd_*_v2)
  row_hash            CHAR(64)          NOT NULL,                 -- sha256(form_type|vessel_kind|vessel_number|opened_by_fk|opened_at) at create
  is_tombstoned       TINYINT(1)        NOT NULL DEFAULT 0,
  audit_flags         JSON              NULL,
  imported_at         TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_op_sessions_row_hash (row_hash),

  -- INDEXES — driven by dominant queries (see below)
  KEY ix_op_sessions_status_form  (status, form_type),                       -- "active sessions for this form on the dashboard"
  KEY ix_op_sessions_vessel       (vessel_kind, vessel_number, status),      -- "is this vessel currently in an open session"
  KEY ix_op_sessions_opened_by    (opened_by_fk, opened_at),                 -- "my open sessions"
  KEY ix_op_sessions_opened_at    (opened_at),                               -- "Journal de bord" chronological feed (Direction C primary UI)
  KEY ix_op_sessions_recipe       (recipe_id_fk),                            -- recipe-axis lookups
  KEY ix_op_sessions_parent       (parent_session_id_fk),                    -- chain traversal

  CONSTRAINT fk_op_sessions_recipe  FOREIGN KEY (recipe_id_fk)        REFERENCES ref_recipes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_opener  FOREIGN KEY (opened_by_fk)        REFERENCES users(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_closer  FOREIGN KEY (closed_by_fk)        REFERENCES users(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_parent  FOREIGN KEY (parent_session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_op_sessions_client  FOREIGN KEY (client_id_fk)        REFERENCES ref_clients(id) ON DELETE RESTRICT,

  -- CHECKS (table-prefixed per MySQL-8 schema-unique rule)
  CONSTRAINT chk_op_sessions_vessel_xor    CHECK ((vessel_kind IS NULL AND vessel_number IS NULL) OR (vessel_kind IS NOT NULL AND vessel_number IS NOT NULL)),
  CONSTRAINT chk_op_sessions_closed_pair   CHECK ((status = 'open' AND closed_at IS NULL AND closed_by_fk IS NULL) OR (status <> 'open' AND closed_at IS NOT NULL)),
  CONSTRAINT chk_op_sessions_abandon_pair  CHECK ((status = 'abandoned' AND abandon_reason IS NOT NULL) OR (status <> 'abandoned'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Decisions (explicit, no hand-waving)

- **`form_type` ENUM = `('racking','fermenting','brewing','packaging')`** — mirrors `bd_cip_events.source_form` + adds `fermenting`. `bd_cip_events.source_form` MUST be widened in the SAME migration to add `'fermenting'` (else sessions for fermenting can't write CIP events). ENUM only, no surprise members; the wort-contract is NOT a form_type (it's a recipe-process attribute under brewing — `ref_recipes.process_type='wort_contract'`, future work).
- **Vessel context = ENUM + INT (polymorphic by kind), NOT typed FK per vessel-class.** Justification: vessels live across `ref_cct`/`ref_bbt`/`ref_yt`/`ref_fermenters` (separate tables); a polymorphic FK to all four would mean 4 mutually-exclusive `*_id_fk` columns + a CHECK. The polymorphic ENUM+number is the CIP module's pattern (`bd_cip_events` does the same: vessel_kind+vessel_number) — house consistency wins. Application layer resolves `vessel_kind`+`vessel_number` to the right `ref_*` row at read time. Both NULL allowed because some forms select vessel mid-firewall (packaging picks a filler machine; brewing assigns CCT post-cooling). Concurrency rule (below) compensates.
- **`recipe_id_fk` / `batch` / `client_id_fk` NULLABLE at open.** RATIONALE: a packaging session at start may not yet know batch (operator scans). The start-firewall MUST populate them before phase advance; that's an APP-LAYER predicate, not a DB invariant (else we can't INSERT at phase='start'). The CHECK would over-constrain. `client_id_fk` is NULL for Neb sessions (today the majority), populated for contract_run / future wort-contract.
- **Phase ENUM = `('start','in_progress','end','closed')`** — the session-model-arc.md state machine, no finer/coarser. `start`=QC firewall in progress; `in_progress`=measured capture loop; `end`=closing checklist; `closed`=phase set advanced past `end` (terminal). FOUR values, no `closing` intermediate (collapses to end), no `paused` (handled via resume — anyone with rights picks up an `open` session of any phase).
- **Status ENUM = `('open','closed','abandoned')` — SEPARATE from phase.** Phase = current workflow position; status = terminal outcome. Three states: `open` (in-flight, any phase 0..3); `closed` (operator-completed the end checklist → phase='closed' AND status='closed'); `abandoned` (auto-expired after N hours OR operator-canceled → status='abandoned', phase stays wherever it died). CHECK pairs the two: if status='open' then closed_at IS NULL; if status≠'open' then closed_at IS NOT NULL.
- **`opened_by_fk` NOT NULL, FK→users(id).** A session has no meaning without an opener. ON DELETE RESTRICT — never orphan a session by deleting a user (audit). `closed_by_fk` nullable+FK→users(id) RESTRICT (different operator may close — multi-actor; see op_session_steps for per-step attribution).
- **No uniqueness on (vessel_kind, vessel_number) for active sessions** — operator may legitimately have a racking session AND a packaging session active on the same CCT at once (rare but valid: a tank being racked OUT while another beer is staged IN — actually NO, but for now we don't model the constraint at DB level; rule it in app layer if it becomes an issue). The CIP module didn't impose this constraint either. **Operator question Q3 below if a hard rule is wanted.** Without operator confirmation, do NOT add a UNIQUE here.
- **Self-FK `parent_session_id_fk`** — exists today as a NULL placeholder. Use case: a racking session that "carries over" into a downstream packaging session of the same batch (operator UX continuity). NOT WIRED today (no app code reads it yet); the column is dormant + cheap. ON DELETE RESTRICT (preserve chain integrity).
- **Indexes** — chosen against the dominant queries from session-model-arc.md §LIVE SESSION DASHBOARD:
  1. `(status, form_type)` — "all active racking sessions" (dashboard)
  2. `(vessel_kind, vessel_number, status)` — "is CCT-3 currently in a session"
  3. `(opened_by_fk, opened_at)` — "my open sessions"
  4. `(opened_at)` — Journal de bord chronological feed (Direction C UI confirmed)
  5. `(recipe_id_fk)` — recipe-axis lookups
  6. `(parent_session_id_fk)` — chain traversal
  No covering indexes — composites at 2-3 cols suffice for ~thousands of sessions/year.

## 2. `op_session_steps` — multi-actor audit spine (NEW TABLE, append-only)

```sql
CREATE TABLE op_session_steps (
  id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  session_id_fk   BIGINT UNSIGNED   NOT NULL,

  phase           ENUM('start','in_progress','end','closed') NOT NULL,   -- the phase this step happened IN (snapshot; sessions.phase may advance later)
  step_type       ENUM(
    'firewall_qc_passed',           -- start-phase: QC predicate evaluated + recorded; payload = passing-set / failing-set
    'cip_attested',                 -- start or end: CIP confirm recorded (the bd_cip_events row id is in payload)
    'eligibility_attested',         -- start: lots/recipes/vessels passed eligibility; payload = enumeration
    'phase_advanced',               -- workflow transition; payload = {from, to}
    'event_linked',                 -- in_progress: an event was written under this session; payload = {table, id}
    'handover',                     -- in_progress: operator A handed to operator B; payload = {to_user_fk, note}
    'note',                         -- in_progress: free annotation; payload = {text}
    'abandon',                      -- terminal: session abandoned; payload = {reason}
    'recap_acknowledged'            -- end: end-checklist signed off; payload = {fields}
  ) NOT NULL,

  actor_user_id_fk INT UNSIGNED     NOT NULL,                 -- users.id; WHO performed this step (may differ from session opener)
  acted_at         DATETIME(6)      NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

  payload          JSON             NULL,                     -- type-specific context; see schema-by-step below

  -- HOUSE envelope (lighter than op_sessions — append-only audit, no tombstone semantics)
  row_hash         CHAR(64)         NOT NULL,                 -- sha256(session_id_fk|step_type|actor_user_id_fk|acted_at|payload-canonical)
  imported_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_op_session_steps_row_hash (row_hash),
  KEY ix_op_session_steps_session_acted (session_id_fk, acted_at),    -- THE spine — "all steps for session X in time order"
  KEY ix_op_session_steps_actor         (actor_user_id_fk, acted_at), -- "what did operator A do today"
  KEY ix_op_session_steps_type          (step_type, acted_at),        -- audit by step type

  CONSTRAINT fk_op_session_steps_session FOREIGN KEY (session_id_fk)    REFERENCES op_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_op_session_steps_actor   FOREIGN KEY (actor_user_id_fk) REFERENCES users(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Decisions (explicit)

- **`session_id_fk` ON DELETE RESTRICT** — audit NEVER cascades away. Deleting a session must fail if steps exist (in practice sessions are closed/abandoned, never deleted).
- **`step_type` ENUM = closed set of 9** (firewall_qc_passed, cip_attested, eligibility_attested, phase_advanced, event_linked, handover, note, abandon, recap_acknowledged). Justification: any operator-meaningful action falls into one of these. The CIP confirm gets BOTH a `bd_cip_events` row (canonical CIP store) AND a `cip_attested` step (audit binding into the session). Same for eligibility (TankSimulator/qc-thresholds is the canonical predicate source; `eligibility_attested` is the session's recorded acknowledgment). New step_types added by schema change ONLY (no free-text → forces design discipline).
- **`actor_user_id_fk` SEPARATE from `op_sessions.opened_by_fk`** — handover is modeled as a STEP, not a column on op_sessions. Operator A opens → opener=A; operator B confirms a CIP mid-session → step row with actor=B. **Handover is NOT a separate table** — it's `step_type='handover'` with payload `{to_user_fk: <B>}` plus the actor=A. Both attribution paths satisfied.
- **`payload JSON`** — chosen over per-step-type cols. Justification: 9 step_types × the columns each would need (cip_event_id for cip_attested / from+to for phase_advanced / table+id for event_linked / to_user_fk+note for handover / text for note / reason for abandon …) = a sparse 20+ col table where each step row uses 1-2 cols. JSON keeps the table lean; queryability is fine for audit-grade (operator scrolls a session's steps in time order — no analytic OLAP over payload). The `ix_op_session_steps_type` index lets you scope to step_type cheaply if you ever need to scan one slice. Per-step-type payload schema documented in app layer (`app/sessions.php` validators), NOT in DB CHECK (MySQL 8 JSON CHECK is brittle and adds little here).
- **Payload schema BY step_type** (operator-facing contract, enforced in `app/sessions.php`):
  - `firewall_qc_passed` — `{ predicate: "racking_eligibility_v1", passed: [...], failed: [...], thresholds_snapshot: {...} }`
  - `cip_attested` — `{ cip_event_id: <bd_cip_events.id>, vessel: {kind,number}, cip_type_id_fk: <ref_cip_types.id> }`
  - `eligibility_attested` — `{ lots: [<bd_racking_v2.id list>], recipes: [<ids>] }` (what the operator confirmed PASSES)
  - `phase_advanced` — `{ from: "start", to: "in_progress" }`
  - `event_linked` — `{ table: "bd_racking_v2", id: 401 }` (allows reverse traversal session→events without querying every event table)
  - `handover` — `{ to_user_fk: <users.id>, note: "..." }`
  - `note` — `{ text: "..." }`
  - `abandon` — `{ reason: "...", auto_expired: false }`
  - `recap_acknowledged` — `{ fields: {volume_packaged: ..., loss_pct: ..., ...} }` (the recap snapshot)
- **Append-only — NO UPDATE.** Steps are never edited. A wrong step gets a CORRECTIVE new step (`note` or `abandon`). This is why `corrections_policy='blocked'` (see §4).
- **No tombstone column** (vs op_sessions which has one) — audit is permanent; mistakes get a corrective NEXT step.
- **Indexes** — `(session_id_fk, acted_at)` is the spine query ("show me this session's steps in order"). `(actor_user_id_fk, acted_at)` for per-operator audit. `(step_type, acted_at)` for audit-by-type.

## 3. Additive `session_id_fk` on event tables (NON-BREAKING)

**Target tables (9):**
- `bd_racking_v2`
- `bd_packaging_v2` (LIVE — note: the LEGACY `bd_packaging` is EXCLUDED, read-only)
- `bd_fermenting_v2` (LIVE — note: legacy `bd_fermenting` is EXCLUDED, read-only)
- `bd_brewing_brewday_v2`
- `bd_brewing_gravity_v2`
- `bd_brewing_ingredients_v2`
- `bd_brewing_timings_v2`
- `bd_cip_events`

**EXCLUDED — `bd_brewing_ingredients_parsed_v2`:** it's `derived` (schema_meta classification confirmed), recomputed from `bd_brewing_ingredients_v2`. Inherits session via its parent's `session_id_fk` at read-time (JOIN), never stamped directly. Stamping it independently would create a divergent-store risk if parsing recomputes and the parsed row's session ever diverges from the parent's.

**Column shape (applied to all 9 target tables identically):**
```sql
ALTER TABLE bd_racking_v2
  ADD COLUMN session_id_fk BIGINT UNSIGNED NULL AFTER id,
  ADD CONSTRAINT fk_bd_racking_v2_session FOREIGN KEY (session_id_fk) REFERENCES op_sessions(id) ON DELETE RESTRICT,
  ADD KEY ix_bd_racking_v2_session (session_id_fk),
  ALGORITHM=INSTANT;
```

- **NULL = pre-session-model event** (every historical / back-dated / legacy row keeps session_id_fk=NULL forever; valid by design — the session model is an OVERLAY).
- **FK ON DELETE RESTRICT** — deleting a session must not orphan its events (in practice sessions are validated/closed, not deleted; the RESTRICT enforces this).
- **Index always** — sessions-to-events lookups dominate (`SELECT * FROM bd_racking_v2 WHERE session_id_fk=?`).
- **ALGORITHM=INSTANT confirmed eligible** — all 9 ADD COLUMNs are nullable without a default expression (NULL default is metadata-only in MySQL 8.0.12+); ADD INDEX + ADD FK are not INSTANT but are fast on these row counts (≤ 9000). MySQL 8 auto-falls-back if INSTANT unavailable; explicit ALGORITHM=INSTANT on ADD COLUMN clauses is the right signal.
- **Naming convention:** column = `session_id_fk` everywhere; FK = `fk_<table>_session`; index = `ix_<table>_session`. House-consistent with `mi_id_fk`/`recipe_id_fk`/etc.
- **`bd_cip_events.source_form` ENUM widening in same migration:** `ALTER TABLE bd_cip_events MODIFY COLUMN source_form ENUM('racking','brewing','packaging','fermenting') NOT NULL;` — needed for fermenting sessions to write CIP events. INSTANT-compatible (ENUM extension append-only).

## 4. `schema_meta` rows for the two new tables

```sql
INSERT INTO schema_meta (table_name, table_class, writer_script, corrections_policy, upstream_hint, notes)
VALUES
  ('op_sessions',
   'source',
   'app/sessions.php',
   'allowed',
   NULL,
   'Session lifecycle envelope (workflow PARENT). Owns ONLY who/when/phase — no derived/measured facts (volume/composition/loss/cadence-count DERIVE from grouped events + sim, per session-model-arc.md cardinal verdict). Corrections allowed: operators legitimately fix opening on the wrong vessel/recipe and similar lifecycle mistakes; tombstone+resync is the mechanism. status=abandoned is a soft-close, not a delete.'),
  ('op_session_steps',
   'audit',
   'app/sessions.php',
   'blocked',
   'op_sessions',
   'Multi-actor append-only audit spine. Append-only by contract — wrong steps are corrected by a NEXT step (note/abandon/etc), never UPDATEd. Policy blocked enforces this: any UPDATE on this table is a smell. The session-to-events back-link via step_type=event_linked enables reverse traversal without querying every event table.');
```

- **`op_sessions` = `source` + `allowed`** — it IS a source of operator-generated lifecycle events; corrections allowed mirrors every `bd_*_v2` (operators make typos). Tombstone column makes correction visible+reversible.
- **`op_session_steps` = `audit` + `blocked`** — audit class because it's the append-only log of operator action, NOT a source of new domain facts (the domain facts live in event tables). Blocked policy is the structural enforcement of "append-only".
- **Why `allowed_with_side_effect` is NOT used for op_sessions:** correcting a session's vessel doesn't recompute downstream (the event tables hold the truth). It's a plain `allowed`.

## 5. Migration plan

**Layout decision: ONE migration file, not split.** Justification: the additive `session_id_fk` columns are MEANINGLESS without the parent `op_sessions` table existing; splitting into 192 (tables) + 193 (additives) creates a window where 192 is applied but the FKs don't exist yet — pointless ceremony for ALTERs that depend on the table created in the same migration. ONE atomic migration `192_op_sessions_framework.sql` is correct. If a rollback is ever needed, the ROLLBACK section in the file header makes it explicit.

**File: `db/migrations/192_op_sessions_framework.sql`**

Order of statements:
1. `CREATE TABLE op_sessions (...)`
2. `CREATE TABLE op_session_steps (...)`
3. `INSERT INTO schema_meta` for both — use `INSERT IGNORE` (PK on table_name handles idempotency natively — no WHERE NOT EXISTS needed; INSERT IGNORE is cleaner)
4. `ALTER TABLE bd_cip_events MODIFY COLUMN source_form ENUM('racking','brewing','packaging','fermenting') NOT NULL;` — widen the ENUM (required for fermenting sessions to attest CIP)
5. 9 × `ALTER TABLE <event_table> ADD COLUMN session_id_fk … ADD CONSTRAINT … ADD KEY …, ALGORITHM=INSTANT;` — one statement per target table, each ALTER carries its 3 clauses.

**MySQL-8 compliance:**
- NO `IF NOT EXISTS` on ADD COLUMN (forbidden — `feedback-mysql-8-vs-mariadb-syntax`). The migration tracker `schema_migrations` guarantees one-time application; no idempotency clause needed inside the SQL.
- NO bare `SELECT` (forbidden by `migrate.php` — `feedback-migrate-php-no-select`). No diagnostic SELECTs in the file.
- ALGORITHM=INSTANT on ADD COLUMN (nullable, no default expression → INSTANT eligible; ADD FK + ADD INDEX in the same ALTER falls back to COPY but row counts are small).
- CHECK constraint names schema-unique → table-prefixed (`chk_op_sessions_vessel_xor`, etc) per `feedback-mysql8-check-constraint-gotchas`.
- FK column types EXACT match: `op_sessions.opened_by_fk INT UNSIGNED` ↔ `users.id INT UNSIGNED`; `op_sessions.recipe_id_fk INT UNSIGNED` ↔ `ref_recipes.id INT UNSIGNED`; `session_id_fk BIGINT UNSIGNED` on event tables ↔ `op_sessions.id BIGINT UNSIGNED`.

**Idempotency for schema_meta INSERTs:**
- `INSERT IGNORE INTO schema_meta (...)` — PK on `table_name` makes IGNORE no-op on re-application (which won't happen via the migrate tracker, but defense-in-depth).

**ROLLBACK section in the header (PHP comment-block at top of file):**
```sql
-- 192_op_sessions_framework.sql
-- Creates op_sessions + op_session_steps + adds nullable session_id_fk to 9 event tables.
-- Widens bd_cip_events.source_form ENUM to include 'fermenting'.
--
-- ROLLBACK (manual, MySQL 8):
--   -- Drop additive FKs + columns
--   ALTER TABLE bd_cip_events                    DROP FOREIGN KEY fk_bd_cip_events_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_timings_v2            DROP FOREIGN KEY fk_bd_brewing_timings_v2_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_ingredients_v2        DROP FOREIGN KEY fk_bd_brewing_ingredients_v2_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_gravity_v2            DROP FOREIGN KEY fk_bd_brewing_gravity_v2_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_brewing_brewday_v2            DROP FOREIGN KEY fk_bd_brewing_brewday_v2_session, DROP COLUMN session_id_fk;
--   ALTER TABLE bd_fermenting_v2                 DROP FOREIGN KEY fk_bd_fermenting_v2_session,      DROP COLUMN session_id_fk;
--   ALTER TABLE bd_packaging_v2                  DROP FOREIGN KEY fk_bd_packaging_v2_session,       DROP COLUMN session_id_fk;
--   ALTER TABLE bd_racking_v2                    DROP FOREIGN KEY fk_bd_racking_v2_session,         DROP COLUMN session_id_fk;
--   -- Narrow ENUM back (only safe if no rows have source_form='fermenting')
--   ALTER TABLE bd_cip_events MODIFY COLUMN source_form ENUM('racking','brewing','packaging') NOT NULL;
--   -- Drop tables (steps first, FK)
--   DROP TABLE op_session_steps;
--   DROP TABLE op_sessions;
--   DELETE FROM schema_meta WHERE table_name IN ('op_sessions','op_session_steps');
--   DELETE FROM schema_migrations WHERE filename='192_op_sessions_framework.sql';
```

**Pre-flight (build agent runs these BEFORE writing the migration):**
1. `php scripts/migrate.php --status` — confirm 191 last applied, 192 free.
2. Confirm `users.id` type live = `INT UNSIGNED` (PM-verified above; re-verify just in case).
3. Confirm `ref_clients` table exists + `.id INT UNSIGNED PRI` (needed for the `client_id_fk` FK — if shape differs, drop the FK or adjust the type to match).
4. Confirm `bd_cip_events.source_form` still `ENUM('racking','brewing','packaging')` (PM-verified above).
5. Dry-run: `mysql --user=… -e "EXPLAIN ALTER TABLE bd_brewing_gravity_v2 ADD COLUMN session_id_fk BIGINT UNSIGNED NULL, ALGORITHM=INSTANT;"` — confirm INSTANT accepted on the largest target.

## 6. Open clarifying questions to put to the operator (3 max, prioritised)

Only the ones that GENUINELY change the SCHEMA. UI/lifecycle policy without schema impact is documented but not asked.

**Q1 (HIGH, schema-impact) — `client_id_fk` on op_sessions: include now or defer?**
> The session row carries an optional `client_id_fk INT UNSIGNED NULL` FK to `ref_clients(id)`. RATIONALE: a packaging session for a contract run (where `bd_packaging_v2.contract_run=1`) belongs to a specific client, and a future wort-contract brewing session ALSO has a client. Including it now avoids an ALTER later. But if `ref_clients` is still partly-modeled (the OPEN ITEMS note says 240 contract rows have unresolved `client_fk` pending materials_supplied_by + ref_clients gating), adding an FK now may create premature coupling. **Schema decision:** include the column now (NULL-default, no constraint pain) WITH the FK constraint (RESTRICT)? Or include the column WITHOUT the FK (defer FK to a later migration when ref_clients is ready)?
> **PM lean:** include the column WITH the FK now — `ref_clients` exists today and the FK is RESTRICT-NULL (no rows yet to break). Confirms or override.

**Q2 (MED, schema-impact) — Uniqueness of active sessions per vessel?**
> Today the schema allows TWO `op_sessions` rows for the same (vessel_kind, vessel_number) with status='open'. Is that a real-world possibility (e.g. a CCT being CIP'd while another team logs an end-checklist on a recently-emptied beer in the same vessel) or should the DB enforce ONE active session per vessel via a partial UNIQUE?
> **MySQL 8 limitation:** real "partial UNIQUE WHERE status='open'" requires a generated column trick (`active_vessel_key = CASE WHEN status='open' THEN CONCAT(vessel_kind,'#',vessel_number) ELSE NULL END` + UNIQUE on it). Cheap to add, but a wrong-direction lock if multiple-active is ever legitimate.
> **PM lean:** DO NOT add the UNIQUE — leave it to the app-layer firewall ("warn if opening a 2nd active session on the same vessel"). Easier to add later than remove. Operator can override.

**Q3 (MED, schema-impact) — `parent_session_id_fk` self-link: include the dormant column now, or add when first needed?**
> The use case is "a racking session that 'carries over' into a downstream packaging session of the same batch" (operator UX continuity / one-click chain). NOT wired anywhere today.
> **PM lean:** INCLUDE the dormant column now. Zero cost (NULL FK), avoids an ALTER on a table that will already be the busiest in the system. The chain UX is plausibly the next thing after the racking pilot proves the framework.

(Other operator concerns — abandonment N-hour threshold, the exact UI of the "Vue cuves" toggle, the per-form payload field-level shape, dashboard refresh cadence — are UI/lifecycle policy and do NOT change the schema. They go to the UI/UX expert + the per-form build briefs, not here.)

## 7. MIG-215 — BATCH MOTHER SHELL Phase 1 (LANDED 2026-05-29, commit `96ea0d4`)

> Operator-ruled 2026-05-29 in the UI/UX expert review session. The strategic + UX + behavioural spec lives in `mother-shell-architecture.md` (sibling file). **This section was the migration-ready schema spec; it has now landed AS mig 215 (not 204 — parallel arcs filled 200-214 first; standing `migrate.php --status` pre-flight discipline caught + corrected at build start).** Three deviations from the planned spec are recorded below at each affected sub-section. Phase 2-5 (UI + retro-link + auto-link extensions) is HARD-BLOCKED on task #21 (F1 live UNIQUE enforcement test).

**File: `db/migrations/215_mother_shell_phase1_schema.sql`** (applied + tracked in `schema_migrations`; PM-verified live 2026-05-29 night). Original planned filename `204_op_sessions_batch_mother.sql` was renumbered before build.

### 7.1 `op_sessions.form_type` ENUM extension (additive)
```sql
ALTER TABLE op_sessions
  MODIFY COLUMN form_type ENUM('racking','fermenting','brewing','packaging','batch') NOT NULL,
  ALGORITHM=INSTANT;
```
- ENUM append-only → INSTANT-eligible. Every existing daily row keeps its racking/fermenting/brewing/packaging value; the new `'batch'` value is reserved for mother rows.
- Mirrors the racking → packaging → fermenting → batch lineage. NO change to `bd_cip_events.source_form` (batch mothers do NOT write CIP events — children do).

### 7.2 NEW column `op_sessions.merged_into_session_id_fk` (blend/merge survivor pattern)
```sql
ALTER TABLE op_sessions
  ADD COLUMN merged_into_session_id_fk BIGINT UNSIGNED NULL AFTER parent_session_id_fk,
  ADD CONSTRAINT fk_op_sessions_merged_into FOREIGN KEY (merged_into_session_id_fk) REFERENCES op_sessions(id) ON DELETE SET NULL,
  ADD KEY ix_op_sessions_merged_into (merged_into_session_id_fk),
  ALGORITHM=INSTANT;
```
- Nullable + additive — every existing row stays at NULL.
- `ON DELETE SET NULL` (NOT RESTRICT): if a survivor mother is ever hard-deleted (unlikely in practice, but defensible), absorbed-source rows simply lose the back-pointer rather than block the delete. The mother's `status='closed'` history is preserved.
- Non-NULL = "this mother was absorbed into the survivor whose id this points to" (per `mother-shell-architecture.md` §BLEND / MERGE SEMANTICS).
- INSTANT-eligible (NULL default, no default expression).

### 7.3 NEW column `op_sessions.blend_share_pct` (OPTIONAL)
```sql
ALTER TABLE op_sessions
  ADD COLUMN blend_share_pct DECIMAL(5,2) NULL AFTER merged_into_session_id_fk,
  ALGORITHM=INSTANT;
```
- Records the % share of a source mother that joined the surviving mother at merge time. NULL by default; populated only when the operator captures the ratio.
- DECIMAL(5,2) = 0.00 to 999.99 (range > 100 deliberately allowed for any future "concentration" semantics; the UI clamps to ≤ 100 by default).
- INSTANT-eligible.

### 7.4 NEW filtered UNIQUE — one active mother per (recipe, batch) — **AS-BUILT (DEVIATION)**

**Planned:** generated VARCHAR(64) `active_batch_key` with a `CASE WHEN form_type='batch' AND status='open' THEN CONCAT(recipe_id_fk,'#',batch) ELSE NULL END` expression + UNIQUE on that single column.

**As-built (mig 215):** the CASE-in-functional-index/expression pattern is NOT supported in MySQL 8 for a generated column producing a NULL-bypass key — MySQL 8 rejects the CASE branch in a UNIQUE generated-col context. Switched to (VERIFIED via `SHOW CREATE TABLE op_sessions` on VPS 2026-05-29):
```sql
ALTER TABLE op_sessions
  ADD COLUMN open_mother_key CHAR(1) GENERATED ALWAYS AS (
    CASE WHEN form_type='batch'
              AND merged_into_session_id_fk IS NULL
              AND status='open'
         THEN '1' ELSE NULL END
  ) VIRTUAL,
  ADD UNIQUE KEY uniq_active_mother (recipe_id_fk, batch, open_mother_key);
```
- **3-condition AND — the expression nulls `open_mother_key` on ANY of: (a) `form_type` is not `'batch'`, (b) the mother was merged (`merged_into_session_id_fk IS NOT NULL`), (c) status is no longer `'open'`.** Self-enforcing for both Phase 2-5 lifecycle paths: merge-then-recreate AND close-then-recreate. No application-side workaround needed.
- Sentinel value is `'1'` (not `'Y'`); UNIQUE composite key value surfaces as e.g. `'6-TEST_UNIQ_99-1'` in 1062 errors.
- The composite UNIQUE `(recipe_id_fk, batch, open_mother_key)` enforces "one open mother per (recipe, batch)" via MySQL's UNIQUE-NULL-bypass: when `open_mother_key=NULL` (any non-batch row OR any merged/closed/abandoned mother), the UNIQUE constraint does NOT compete; when `open_mother_key='1'` (active, non-merged, open mother), the row competes on `(recipe_id_fk, batch, '1')` — only one row per (recipe, batch) can hold `'1'` at any moment.
- Cleaner than the planned VARCHAR pattern: CHAR(1) virtual col + composite UNIQUE replaces a CASE-with-CONCAT expression. Same semantic outcome.
- VIRTUAL column = re-evaluated on read, NO storage cost, NO UPDATE trigger needed when input columns change (status/merged_into/form_type flip → key auto-re-evaluates). Cheap for a high-write table like op_sessions. Composite UNIQUE storage marginally larger than the planned single-col VARCHAR, but negligible.
- NOT INSTANT (VIRTUAL + UNIQUE falls back to COPY). On a small op_sessions table this was fast; if scale grows, revisit.
- **✅ LIVE UNIQUE TEST PASSED on VPS 2026-05-29 (scrap #24 RESOLVED, F1 followup CLOSED) + RE-VERIFIED via direct `SHOW CREATE TABLE` (option (i) confirmed — the GENERATED expression DOES include `merged_into_session_id_fk IS NULL`; the earlier sub-finding "doc gap, not schema gap" — corrected above).** 3 scenarios all PASS. **(A) Direct UNIQUE rejection:** 2nd INSERT same `(recipe_id_fk=6, batch='TEST_UNIQ_99', form_type='batch', status='open', merged_into=NULL)` → `SQLSTATE[23000] 1062 Duplicate entry '6-TEST_UNIQ_99-1' for key 'op_sessions.uniq_active_mother'`. Composite key value `6-TEST_UNIQ_99-1` confirms the VIRTUAL `open_mother_key` evaluated to `'1'` correctly. **(B) Merge-then-recreate:** M1 inserted open → `UPDATE M1 SET merged_into_session_id_fk=2` → VIRTUAL `open_mother_key` re-evaluated to NULL (because the AND-clause `merged_into_session_id_fk IS NULL` failed) → M2 INSERT same (recipe, batch) succeeded. **(C) Close-then-recreate:** M3 inserted open → `UPDATE M3 SET status='closed'` → VIRTUAL → NULL → M4 INSERT same (recipe, batch) succeeded. Test fixtures self-cleaned per `feedback-test-fixtures-must-self-clean`. **Phase 2-5 UNBLOCKED — the schema is fully self-enforcing for BOTH merge-then-recreate AND close-then-recreate paths, no application-side guard needed.** PM-recorded data points captured during this test: (1) PDO bootstrap on VPS = `/var/www/maltytask/app/db.php → maltytask_pdo()` (NOT `config/bootstrap.php`); (2) `op_sessions.recipe_id_fk` IS FK-constrained to `ref_recipes.id` (already in §3 L70 + §FK type alignment L233; reinforced: sentinel id=9999 FK-violates, real id=6 used); (3) `op_sessions.row_hash` has UNIQUE (already in §3 L60; reinforced: fixtures vary hash per insert, e.g. `SHA2('T1',256)`).

### 7.5 NEW CHECK — **AS-BUILT (DEVIATION)**

**Planned:** `chk_op_sessions_batch_shape` enforcing mother-doesn't-pin-to-vessel.

**As-built (mig 215):** the CHECK that landed is `chk_op_sessions_blend_share_range` enforcing `blend_share_pct BETWEEN 0 AND 100 OR blend_share_pct IS NULL` (table-prefixed per MySQL-8 gotcha; schema-unique vs 3 existing `chk_op_sessions_*` — abandon_pair / closed_pair / vessel_xor). The planned batch-shape CHECK was DEFERRED — its invariant is structurally enforced by `app/mother-shell.php::create_mother()` (mother is never inserted with vessel_kind/number set, asserted at API entry). Promote to a CHECK in a follow-up mig if a non-resolver path ever attempts to write `form_type='batch'` rows (today: zero such paths — `create_mother` is the only writer; grep-verified).
- Why the deferral is acceptable: refuse-don't-NULL at the API entry covers Phase 1; the DB CHECK would be defense-in-depth, not the primary guard. RULE-2 reviewer noted the deviation but didn't block — the API single-source-of-truth grep-verification was sufficient evidence.
- **Backlog item for Phase 2 prep:** add `chk_op_sessions_batch_shape` as a sibling CHECK if any non-`mother-shell.php` writer is ever added.

### 7.6 Retro-link pass (data, NOT schema — runs after the ALTERs)
After the mig 204 ALTERs land, a one-shot SQL pass auto-creates the mother rows for already-existing daily children + back-fills their `parent_session_id_fk`. This is DATA-DRIVEN (not speculative) because every daily child carries the canonical `(recipe_id_fk, batch)` — refuse-don't-NULL discipline holds (rows with NULL recipe_id_fk or NULL batch stay orphaned per Q6 forward-only ruling in `mother-shell-architecture.md`).

```sql
-- For every distinct (recipe_id_fk, batch) appearing on existing daily children, INSERT a mother row.
-- (Pseudo-shape — exact INSERT-SELECT + UPDATE-JOIN landed at build time, run inside a TRANSACTION.)
INSERT INTO op_sessions (form_type, recipe_id_fk, batch, phase, status, opened_by_fk, opened_at, row_hash, imported_at)
SELECT 'batch',
       c.recipe_id_fk,
       c.batch,
       'closed',
       'closed',
       <system-user-id>,
       MIN(c.opened_at),
       SHA2(CONCAT_WS('|','batch', c.recipe_id_fk, c.batch, MIN(c.opened_at)), 256),
       CURRENT_TIMESTAMP
FROM op_sessions c
WHERE c.form_type IN ('racking','fermenting','brewing','packaging')
  AND c.recipe_id_fk IS NOT NULL
  AND c.batch IS NOT NULL
  AND c.parent_session_id_fk IS NULL
GROUP BY c.recipe_id_fk, c.batch;

-- Then back-fill the children's parent_session_id_fk.
UPDATE op_sessions c
JOIN op_sessions m
  ON m.form_type='batch'
 AND m.recipe_id_fk = c.recipe_id_fk
 AND m.batch = c.batch
SET c.parent_session_id_fk = m.id
WHERE c.form_type IN ('racking','fermenting','brewing','packaging')
  AND c.parent_session_id_fk IS NULL;
```
- Pre-existing mothers (mig 204 + retro-link) come up `phase='closed'`, `status='closed'` because they represent historical-only batches — no live cellar work to advance them. Future activity on a historical batch (e.g. operator opens a new packaging child for batch 245 three months later) is governed by §BLEND / MERGE SEMANTICS (operator either re-opens the existing closed mother via UI gesture OR — more likely — that's a different batch and creates its own mother).
- The retro-link is OPERATOR-CONFIRMED in form (a pre-flight diagnostic counts the (recipe, batch) groups, the operator approves before the INSERT runs). Forward-only ruling is preserved: no GUESSING of identities — the data IS the canonical join.

### 7.7 `schema_meta` — no NEW row
mig 204 only ALTERs `op_sessions` (existing schema_meta row covers it). The mother shell does NOT introduce a new table — the elegance is in the additive ENUM + self-FK + virtual UNIQUE; no parallel store, no bridge table.

### 7.8 MySQL-8 compliance recap
- ENUM extension INSTANT-eligible.
- Two ADD COLUMN ALTERs (`merged_into_session_id_fk` + `blend_share_pct`) INSTANT-eligible (NULL default).
- Generated VIRTUAL column + UNIQUE NOT INSTANT (falls back to COPY) — acceptable on small table.
- CHECK constraint name table-prefixed per `feedback-mysql8-check-constraint-gotchas`.
- No bare SELECT in the migration file (operator-confirm-then-run pattern lives in the build agent's pre-flight, not in the SQL).
- FK type EXACT: `merged_into_session_id_fk BIGINT UNSIGNED` ↔ `op_sessions.id BIGINT UNSIGNED`.

### 7.9 ROLLBACK plan (header comment block in the migration file)
```sql
-- ROLLBACK:
--   ALTER TABLE op_sessions
--     DROP CHECK chk_op_sessions_batch_shape,
--     DROP INDEX uq_op_sessions_active_batch,
--     DROP COLUMN active_batch_key,
--     DROP COLUMN blend_share_pct,
--     DROP FOREIGN KEY fk_op_sessions_merged_into,
--     DROP INDEX ix_op_sessions_merged_into,
--     DROP COLUMN merged_into_session_id_fk;
--   ALTER TABLE op_sessions MODIFY COLUMN form_type ENUM('racking','fermenting','brewing','packaging') NOT NULL;
--   -- + manual SET parent_session_id_fk=NULL on retro-linked children, + DELETE the auto-created mothers, before the form_type narrow succeeds.
--   DELETE FROM schema_migrations WHERE filename='204_op_sessions_batch_mother.sql';
```
The ENUM narrow-back requires zero `'batch'` rows surviving — the rollback first DELETEs the auto-spawned mothers + their child back-links, THEN narrows the enum. Practical reality: rollback is unlikely; mig 204 is additive + non-breaking by design.
