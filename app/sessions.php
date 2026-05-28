<?php
declare(strict_types=1);
/**
 * app/sessions.php — Session-framework lifecycle + audit resolver.
 *
 * Provides the PHP API layer over op_sessions (lifecycle envelope) and
 * op_session_steps (multi-actor audit spine), created by migration
 * 192_op_sessions_framework.sql.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * INTEGRATION NOTES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * CALLER CONTRACT for $openedByUserId / $actorUserId:
 *   Resolve via current_user() in the form handler, then pass users.id (int).
 *   Example:
 *     $me = current_user();
 *     if ($me === null) { require_login(); exit; }
 *     $sessionId = session_open($pdo, $ctx, (int)$me['id']);
 *
 *   Never pass 0 or a synthesised id — every action must be attributable to a
 *   real users.id (FK RESTRICT on op_sessions.opened_by_fk / actor_user_id_fk).
 *
 * WRITE PROTOCOL — all state-changing functions:
 *   • Wrap in a PDO transaction (own or nested SAVEPOINT).
 *   • Write the op_session_steps row via session_log_step() (which calls
 *     log_revision() from db-write-helpers.php).
 *   • Throw on validation failure BEFORE touching the DB.
 *
 * READ PROTOCOL:
 *   • Memoised per-request via static $cache keyed on serialised args.
 *   • No writes. No side-effects.
 *
 * STEP-TYPE ENUM (mirrors op_session_steps.step_type):
 *   firewall_qc_passed, cip_attested, eligibility_attested, phase_advanced,
 *   event_linked, handover, note, abandon, recap_acknowledged
 *
 * PHASE ENUM (forward only): start → in_progress → end → closed
 * STATUS ENUM:               open | closed | abandoned
 *
 * ══════════════════════════════════════════════════════════════════════════
 * OPEN POLICY QUESTIONS (flagged — not decided here)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * [PQ-1] session_open implicit phase step?
 *   This file does NOT write a phase_advanced step on open (no null→start
 *   transition is recorded as a step). The op_sessions row IS the open record.
 *   Rationale: a step requires actor + acted_at — the opened_by_fk + opened_at
 *   columns on op_sessions already carry that. A redundant step_type=phase_advanced
 *   with from=null/to=start adds no information. If future UX needs a step-level
 *   entry for "session opened", add it with step_type='phase_advanced' payload
 *   {from:null,to:"start"} — this can be done without a schema change.
 *   Operator-confirmed 2026-05-28: keep as implemented.
 *
 * [PQ-2] session_close + recap_acknowledged: separate (operator-confirmed 2026-05-28).
 *   session_close does NOT auto-write a recap_acknowledged step.
 *   The end-checklist sign-off must be an explicit prior call to
 *   session_recap_ack(). The recap payload is also the foundation for the
 *   planned daily recap email digest (downstream consumer; not built here).
 *
 * [PQ-3] Phase skipping: NONE allowed (operator-confirmed 2026-05-28).
 *   session_advance_phase requires step-by-step progression:
 *     start → in_progress → end → closed.
 *   Rationale: even apparently-trivial sessions (wort-contract brewing,
 *   short-format packaging runs) still capture in_progress measurements
 *   (gravity + pH at stages for wort-contracts, line readings for short
 *   runs). The in_progress phase is never operationally meaningless.
 *   If a legitimate skip case ever emerges, document the operator scenario
 *   FIRST, then add the targeted exception.
 *
 * [PQ-4] session_handover: step-only (current) vs also set a current_operator column?
 *   Handover is step-only. op_sessions has no current_operator_fk column.
 *   Rationale: the PM spec says step-only; scanning steps to find current operator
 *   is an acceptable cost at audit-grade. If a "current operator" column is added
 *   later for the UI's live-session dashboard, it goes on op_sessions.
 *   Operator-confirmed 2026-05-28: step-only, no column.
 *
 * [PQ-5] Abandon with linked events: events keep session_id_fk (current).
 *   FK is ON DELETE RESTRICT. Abandon is status-change, not delete. Events stay
 *   linked. If the operator later wants to dissociate, that is a corrective
 *   op_session_steps note, not a NULL-out.
 *
 * [PQ-6] Multi-active sessions on same vessel: warn via audit_flags.
 *   session_open checks for existing open sessions on the same vessel/form_type
 *   and appends a structured warning to the new session's audit_flags JSON when
 *   found. No hard block (per PM Q2 ruling). Warning is surfaced to caller via
 *   the returned $sessionId's audit_flags column (caller may surface in UI).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Dependencies: app/db-write-helpers.php (log_revision, bd_row_hash),
 *               app/auth.php (current_user — for audit; callers pass userId explicitly).
 */

require_once __DIR__ . '/db-write-helpers.php';  // log_revision, bd_row_hash
require_once __DIR__ . '/auth.php';               // current_user

// ─── Constants ────────────────────────────────────────────────────────────────

/** Valid form_type values (mirrors op_sessions.form_type ENUM). */
const SESSION_FORM_TYPES = ['racking', 'fermenting', 'brewing', 'packaging'];

/** Valid vessel_kind values (mirrors op_sessions.vessel_kind ENUM). */
const SESSION_VESSEL_KINDS = ['cct', 'bbt', 'yt', 'fermenter', 'brewhouse', 'machine'];

/** Valid phase values in forward order (mirrors op_sessions.phase ENUM). */
const SESSION_PHASES = ['start', 'in_progress', 'end', 'closed'];

/** Valid status values (mirrors op_sessions.status ENUM). */
const SESSION_STATUSES = ['open', 'closed', 'abandoned'];

/** Valid step_type values (mirrors op_session_steps.step_type ENUM). */
const SESSION_STEP_TYPES = [
    'firewall_qc_passed',
    'cip_attested',
    'eligibility_attested',
    'phase_advanced',
    'event_linked',
    'handover',
    'note',
    'abandon',
    'recap_acknowledged',
];

// ─── 1. Write API — lifecycle ─────────────────────────────────────────────────

/**
 * session_open — Open a new op_sessions row (phase='start', status='open').
 *
 * Purpose: INSERT a lifecycle envelope for a new operator session.
 *
 * @param PDO   $pdo             Active DB connection.
 * @param array $ctx {
 *   'form_type'           => string    (required; one of SESSION_FORM_TYPES)
 *   'vessel_kind'         => string    (optional; must be paired with vessel_number)
 *   'vessel_number'       => int       (optional; must be paired with vessel_kind)
 *   'recipe_id_fk'        => int       (optional; ref_recipes.id)
 *   'batch'               => string    (optional; matches bd_*_v2.batch shape)
 *   'client_id_fk'        => int       (optional; ref_clients.id; null for Neb sessions)
 *   'parent_session_id_fk'=> int       (optional; self-link for chain UX)
 * }
 * @param int   $openedByUserId  users.id of the operator opening the session.
 *
 * @return int  The new op_sessions.id (BIGINT auto-increment).
 *
 * Side effects:
 *   - INSERT into op_sessions.
 *   - log_revision() call (audit_row_revisions).
 *   - If another open session exists for the same vessel+form_type, a structured
 *     warning is added to audit_flags of the new row (PQ-6). No hard block.
 *
 * Does NOT write a phase_advanced step (see [PQ-1] above).
 *
 * @throws InvalidArgumentException on validation failure (French messages for operators).
 * @throws RuntimeException on DB error.
 */
function session_open(PDO $pdo, array $ctx, int $openedByUserId): int
{
    // ── Validate inputs ───────────────────────────────────────────────────────
    if ($openedByUserId <= 0) {
        throw new InvalidArgumentException('session_open: openedByUserId doit être un identifiant utilisateur valide (> 0).');
    }

    $formType = $ctx['form_type'] ?? null;
    if ($formType === null || !in_array($formType, SESSION_FORM_TYPES, true)) {
        throw new InvalidArgumentException(
            "session_open: form_type '{$formType}' invalide. Valeurs acceptées: " . implode(', ', SESSION_FORM_TYPES) . '.'
        );
    }

    // vessel XOR: both or neither
    $vesselKind   = isset($ctx['vessel_kind'])   ? (string)$ctx['vessel_kind']   : null;
    $vesselNumber = isset($ctx['vessel_number']) ? (int)$ctx['vessel_number']   : null;
    if (($vesselKind === null) !== ($vesselNumber === null)) {
        throw new InvalidArgumentException(
            'session_open: vessel_kind et vessel_number doivent être fournis ensemble ou tous les deux absents.'
        );
    }
    if ($vesselKind !== null && !in_array($vesselKind, SESSION_VESSEL_KINDS, true)) {
        throw new InvalidArgumentException(
            "session_open: vessel_kind '{$vesselKind}' invalide. Valeurs acceptées: " . implode(', ', SESSION_VESSEL_KINDS) . '.'
        );
    }
    if ($vesselNumber !== null && $vesselNumber <= 0) {
        throw new InvalidArgumentException('session_open: vessel_number doit être > 0.');
    }

    $recipeIdFk         = isset($ctx['recipe_id_fk'])         ? (int)$ctx['recipe_id_fk']         : null;
    $batch              = isset($ctx['batch'])                 ? (string)$ctx['batch']              : null;
    $clientIdFk         = isset($ctx['client_id_fk'])         ? (int)$ctx['client_id_fk']         : null;
    $parentSessionIdFk  = isset($ctx['parent_session_id_fk']) ? (int)$ctx['parent_session_id_fk'] : null;

    if ($recipeIdFk !== null && $recipeIdFk <= 0) {
        throw new InvalidArgumentException('session_open: recipe_id_fk doit être > 0.');
    }
    if ($clientIdFk !== null && $clientIdFk <= 0) {
        throw new InvalidArgumentException('session_open: client_id_fk doit être > 0.');
    }
    if ($parentSessionIdFk !== null && $parentSessionIdFk <= 0) {
        throw new InvalidArgumentException('session_open: parent_session_id_fk doit être > 0.');
    }

    $openedAt = date('Y-m-d H:i:s.u'); // microseconds for DATETIME(6)

    // row_hash: sha256(form_type|vessel_kind|vessel_number|opened_by_fk|opened_at)
    $rowHash = bd_row_hash([
        $formType,
        $vesselKind   ?? '',
        $vesselNumber !== null ? (string)$vesselNumber : '',
        (string)$openedByUserId,
        $openedAt,
    ]);

    // ── Multi-active vessel warn (PQ-6) ───────────────────────────────────────
    $auditFlags = null;
    if ($vesselKind !== null) {
        $warnRows = _session_open_sessions_for_vessel($pdo, $vesselKind, (int)$vesselNumber);
        if (!empty($warnRows)) {
            $existingIds = array_column($warnRows, 'id');
            $auditFlags  = json_encode([
                'multi_active_vessel_warn' => [
                    'message'      => "Une ou plusieurs sessions ouvertes existent déjà sur {$vesselKind} #{$vesselNumber}.",
                    'existing_ids' => $existingIds,
                ],
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    // ── Transaction ───────────────────────────────────────────────────────────
    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'sess_open_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO op_sessions
               (form_type, vessel_kind, vessel_number,
                recipe_id_fk, batch, client_id_fk,
                phase, status,
                opened_by_fk, opened_at,
                closed_by_fk, closed_at, abandon_reason,
                parent_session_id_fk,
                row_hash, is_tombstoned, audit_flags,
                imported_at, updated_at)
             VALUES
               (?, ?, ?,
                ?, ?, ?,
                'start', 'open',
                ?, ?,
                NULL, NULL, NULL,
                ?,
                ?, 0, ?,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $formType,
            $vesselKind,
            $vesselNumber,
            $recipeIdFk,
            $batch,
            $clientIdFk,
            $openedByUserId,
            $openedAt,
            $parentSessionIdFk,
            $rowHash,
            $auditFlags,
        ]);

        $sessionId = (int)$pdo->lastInsertId();

        // ── Audit ─────────────────────────────────────────────────────────────
        $me = _session_audit_user($pdo, $openedByUserId);
        log_revision(
            $pdo, $me, 'op_sessions', $sessionId,
            null,
            ['form_type' => $formType, 'vessel_kind' => $vesselKind, 'vessel_number' => $vesselNumber,
             'phase' => 'start', 'status' => 'open', 'opened_by_fk' => $openedByUserId, 'opened_at' => $openedAt],
            'normal', null
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    return $sessionId;
}

/**
 * session_advance_phase — Move the session phase forward in the state machine.
 *
 * Purpose: Transition phase forward (start→in_progress→end→closed).
 *          Backwards transitions are rejected with a French error.
 *          Closed phase also sets status='closed' + closed_by_fk + closed_at.
 *
 * Legal transitions (strict forward, exactly one step at a time — see [PQ-3]):
 *   start        → in_progress
 *   in_progress  → end
 *   end          → closed
 *
 * @param PDO         $pdo          Active DB connection.
 * @param int         $sessionId    op_sessions.id
 * @param string      $newPhase     Target phase (one of SESSION_PHASES).
 * @param int         $actorUserId  users.id of the actor.
 * @param array|null  $payload      Optional additional payload for the step (merged with {from,to}).
 *
 * Side effects:
 *   - UPDATE op_sessions.phase (and status/closed_at/closed_by_fk when phase='closed').
 *   - INSERT op_session_steps (step_type='phase_advanced').
 *   - log_revision().
 *
 * @throws InvalidArgumentException on invalid transition, unknown session, or already terminal.
 * @throws RuntimeException on DB error.
 */
function session_advance_phase(
    PDO    $pdo,
    int    $sessionId,
    string $newPhase,
    int    $actorUserId,
    ?array $payload = null
): void {
    if (!in_array($newPhase, SESSION_PHASES, true)) {
        throw new InvalidArgumentException(
            "session_advance_phase: phase '{$newPhase}' invalide. Valeurs acceptées: " . implode(', ', SESSION_PHASES) . '.'
        );
    }
    if ($actorUserId <= 0) {
        throw new InvalidArgumentException('session_advance_phase: actorUserId doit être > 0.');
    }

    $session = _session_load_or_throw($pdo, $sessionId);

    // Guard: cannot advance an abandoned or already-closed session.
    if ($session['status'] === 'abandoned') {
        throw new InvalidArgumentException(
            "session_advance_phase: la session #{$sessionId} est abandonnée — aucune transition de phase possible."
        );
    }
    if ($session['status'] === 'closed') {
        throw new InvalidArgumentException(
            "session_advance_phase: la session #{$sessionId} est déjà clôturée."
        );
    }

    $currentPhase = $session['phase'];
    $currentIdx   = array_search($currentPhase, SESSION_PHASES, true);
    $newIdx       = array_search($newPhase,     SESSION_PHASES, true);

    // Must advance EXACTLY one step (strict forward, no skips). See [PQ-3]:
    // even trivial-looking sessions (e.g. wort-contract brewing) capture
    // in_progress data (gravity/pH at stages), so the in_progress phase is
    // never operationally skippable.
    if ($newIdx !== $currentIdx + 1) {
        throw new InvalidArgumentException(
            "session_advance_phase: transition '{$currentPhase}' → '{$newPhase}' invalide — " .
            "la phase doit avancer d'EXACTEMENT un pas (ordre strict: start → in_progress → end → closed)."
        );
    }

    $isClosing = ($newPhase === 'closed');
    $now       = date('Y-m-d H:i:s.u');

    $stepPayload = array_merge(
        ['from' => $currentPhase, 'to' => $newPhase],
        $payload ?? []
    );

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'sess_adv_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        if ($isClosing) {
            $pdo->prepare(
                "UPDATE op_sessions
                    SET phase         = 'closed',
                        status        = 'closed',
                        closed_by_fk  = ?,
                        closed_at     = ?,
                        updated_at    = CURRENT_TIMESTAMP
                  WHERE id = ? AND is_tombstoned = 0"
            )->execute([$actorUserId, $now, $sessionId]);
        } else {
            $pdo->prepare(
                "UPDATE op_sessions
                    SET phase      = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND is_tombstoned = 0"
            )->execute([$newPhase, $sessionId]);
        }

        $stepId = session_log_step($pdo, $sessionId, 'phase_advanced', $actorUserId, $stepPayload);

        $me = _session_audit_user($pdo, $actorUserId);
        log_revision(
            $pdo, $me, 'op_sessions', $sessionId,
            ['phase' => $currentPhase, 'status' => $session['status']],
            ['phase' => $newPhase, 'status' => $isClosing ? 'closed' : $session['status'],
             'step_id' => $stepId],
            'normal', null
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }
}

/**
 * session_close — Convenience: advance to phase='closed' + set status='closed'.
 *
 * Purpose: One-call close for when the form handler has confirmed the end
 *          checklist was acknowledged separately.
 *
 * Equivalent to session_advance_phase($pdo, $sessionId, 'closed', …) but with
 * a dedicated signature for clarity at the call site.
 *
 * Does NOT auto-write a recap_acknowledged step (see [PQ-2]).
 *
 * @param PDO         $pdo             Active DB connection.
 * @param int         $sessionId       op_sessions.id
 * @param int         $closedByUserId  users.id of the closing operator.
 * @param array|null  $payload         Merged into the phase_advanced step payload.
 *
 * Side effects: same as session_advance_phase to 'closed'.
 *
 * @throws InvalidArgumentException | RuntimeException
 */
function session_close(PDO $pdo, int $sessionId, int $closedByUserId, ?array $payload = null): void
{
    session_advance_phase($pdo, $sessionId, 'closed', $closedByUserId, $payload);
}

/**
 * session_abandon — Mark a session as abandoned (status='abandoned').
 *
 * Purpose: Soft-close for auto-expired or operator-cancelled sessions.
 *          Phase is frozen at whatever value it had when abandoned.
 *
 * @param PDO    $pdo          Active DB connection.
 * @param int    $sessionId    op_sessions.id
 * @param string $reason       Free text, stored in abandon_reason + step payload.
 * @param int    $actorUserId  users.id of the actor.
 *
 * Side effects:
 *   - UPDATE op_sessions.status='abandoned', abandon_reason, closed_at.
 *   - INSERT op_session_steps (step_type='abandon').
 *   - log_revision().
 *
 * @throws InvalidArgumentException if already abandoned/closed or reason empty.
 * @throws RuntimeException on DB error.
 */
function session_abandon(PDO $pdo, int $sessionId, string $reason, int $actorUserId): void
{
    if ($actorUserId <= 0) {
        throw new InvalidArgumentException('session_abandon: actorUserId doit être > 0.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('session_abandon: la raison d\'abandon ne peut pas être vide.');
    }

    $session = _session_load_or_throw($pdo, $sessionId);

    if ($session['status'] === 'abandoned') {
        throw new InvalidArgumentException(
            "session_abandon: la session #{$sessionId} est déjà abandonnée."
        );
    }
    if ($session['status'] === 'closed') {
        throw new InvalidArgumentException(
            "session_abandon: la session #{$sessionId} est déjà clôturée — impossible de l'abandonner."
        );
    }

    $now = date('Y-m-d H:i:s.u');

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'sess_abn_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $pdo->prepare(
            "UPDATE op_sessions
                SET status         = 'abandoned',
                    abandon_reason = ?,
                    closed_at      = ?,
                    closed_by_fk   = ?,
                    updated_at     = CURRENT_TIMESTAMP
              WHERE id = ? AND is_tombstoned = 0"
        )->execute([$reason, $now, $actorUserId, $sessionId]);

        $stepId = session_log_step($pdo, $sessionId, 'abandon', $actorUserId, [
            'reason'       => $reason,
            'auto_expired' => false,
        ]);

        $me = _session_audit_user($pdo, $actorUserId);
        log_revision(
            $pdo, $me, 'op_sessions', $sessionId,
            ['status' => $session['status']],
            ['status' => 'abandoned', 'abandon_reason' => $reason, 'step_id' => $stepId],
            'normal', null
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }
}

/**
 * session_log_step — Generic step writer. The foundation all convenience attestation
 *                    helpers build on.
 *
 * Purpose: INSERT one row into op_session_steps with full row_hash + log_revision audit.
 *
 * @param PDO         $pdo          Active DB connection.
 * @param int         $sessionId    op_sessions.id
 * @param string      $stepType     One of SESSION_STEP_TYPES.
 * @param int         $actorUserId  users.id of the actor.
 * @param array|null  $payload      Type-specific JSON payload (see PM spec §2 for per-type schema).
 *
 * @return int  The new op_session_steps.id.
 *
 * Side effects:
 *   - INSERT into op_session_steps.
 *   - log_revision() call.
 *
 * @throws InvalidArgumentException on invalid step_type, unknown session, or actor <= 0.
 * @throws RuntimeException on DB error.
 */
function session_log_step(
    PDO    $pdo,
    int    $sessionId,
    string $stepType,
    int    $actorUserId,
    ?array $payload = null
): int {
    if (!in_array($stepType, SESSION_STEP_TYPES, true)) {
        throw new InvalidArgumentException(
            "session_log_step: step_type '{$stepType}' invalide. Valeurs acceptées: " . implode(', ', SESSION_STEP_TYPES) . '.'
        );
    }
    if ($actorUserId <= 0) {
        throw new InvalidArgumentException('session_log_step: actorUserId doit être > 0.');
    }

    $session = _session_load_or_throw($pdo, $sessionId);
    $currentPhase = $session['phase'];

    $actedAt      = date('Y-m-d H:i:s.u');
    $payloadJson  = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

    // row_hash: sha256(session_id_fk|step_type|actor_user_id_fk|acted_at|sha256(json_encode(payload)))
    $payloadHash = hash('sha256', $payloadJson ?? '');
    $rowHash = bd_row_hash([
        (string)$sessionId,
        $stepType,
        (string)$actorUserId,
        $actedAt,
        $payloadHash,
    ]);

    $ownTx     = !$pdo->inTransaction();
    $savepoint = 'sess_step_' . uniqid('', true);
    $ownTx ? $pdo->beginTransaction() : $pdo->exec("SAVEPOINT `{$savepoint}`");

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO op_session_steps
               (session_id_fk, phase, step_type,
                actor_user_id_fk, acted_at, payload,
                row_hash, imported_at)
             VALUES
               (?, ?, ?,
                ?, ?, ?,
                ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $sessionId,
            $currentPhase,
            $stepType,
            $actorUserId,
            $actedAt,
            $payloadJson,
            $rowHash,
        ]);

        $stepId = (int)$pdo->lastInsertId();

        $me = _session_audit_user($pdo, $actorUserId);
        log_revision(
            $pdo, $me, 'op_session_steps', $stepId,
            null,
            ['session_id_fk' => $sessionId, 'step_type' => $stepType,
             'phase' => $currentPhase, 'actor_user_id_fk' => $actorUserId,
             'acted_at' => $actedAt, 'payload' => $payload],
            'normal', null
        );

        $ownTx ? $pdo->commit() : $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");

    } catch (Throwable $e) {
        $ownTx ? $pdo->rollBack() : $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        throw $e;
    }

    return $stepId;
}

/**
 * session_attest_firewall — Record a firewall QC check passed.
 *
 * Purpose: Convenience wrapper for step_type='firewall_qc_passed'.
 *
 * Payload schema (required fields):
 *   predicate          string  — e.g. 'racking_eligibility_v1'
 *   passed             array   — list of check names that passed
 *   failed             array   — list of check names that failed (empty = all pass)
 *   thresholds_snapshot array  — the threshold values consulted during the check
 *
 * @param PDO   $pdo          Active DB connection.
 * @param int   $sessionId    op_sessions.id
 * @param int   $actorUserId  users.id of the operator who ran the firewall.
 * @param array $payload      Must include: predicate (string), passed (array), failed (array),
 *                             thresholds_snapshot (array).
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on missing required payload fields.
 * @throws RuntimeException on DB error.
 */
function session_attest_firewall(PDO $pdo, int $sessionId, int $actorUserId, array $payload): int
{
    foreach (['predicate', 'passed', 'failed', 'thresholds_snapshot'] as $required) {
        if (!array_key_exists($required, $payload)) {
            throw new InvalidArgumentException(
                "session_attest_firewall: payload manquant '{$required}'. " .
                "Champs requis: predicate, passed, failed, thresholds_snapshot."
            );
        }
    }
    if (!is_string($payload['predicate']) || $payload['predicate'] === '') {
        throw new InvalidArgumentException('session_attest_firewall: payload.predicate doit être une chaîne non vide.');
    }
    if (!is_array($payload['passed']) || !is_array($payload['failed']) || !is_array($payload['thresholds_snapshot'])) {
        throw new InvalidArgumentException('session_attest_firewall: payload.passed, failed et thresholds_snapshot doivent être des tableaux.');
    }

    return session_log_step($pdo, $sessionId, 'firewall_qc_passed', $actorUserId, $payload);
}

/**
 * session_attest_cip — Bind a bd_cip_events row into the session audit trail.
 *
 * Purpose: Convenience wrapper for step_type='cip_attested'. Writes the audit
 *          binding from this session to an existing bd_cip_events row.
 *          The bd_cip_events row itself must have been written separately via cip_upsert().
 *
 * Payload: { cip_event_id: int, vessel: {kind: string, number: int|null}, cip_type_id_fk: int|null }
 *
 * @param PDO $pdo
 * @param int $sessionId    op_sessions.id
 * @param int $cipEventId   bd_cip_events.id (must be a live, non-tombstoned row).
 * @param int $actorUserId  users.id
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on cipEventId <= 0.
 * @throws RuntimeException on DB error.
 */
function session_attest_cip(PDO $pdo, int $sessionId, int $cipEventId, int $actorUserId): int
{
    if ($cipEventId <= 0) {
        throw new InvalidArgumentException('session_attest_cip: cipEventId doit être > 0.');
    }

    return session_log_step($pdo, $sessionId, 'cip_attested', $actorUserId, [
        'cip_event_id' => $cipEventId,
    ]);
}

/**
 * session_attest_eligibility — Record lot/recipe/vessel eligibility attestation.
 *
 * Purpose: Operator confirms the lots/recipes are eligible for this session phase
 *          (e.g. "CCT cold-crashed long enough for racking").
 *
 * Payload schema (required fields):
 *   lots     array  — list of event row IDs that are attested eligible
 *                     (e.g. bd_racking_v2.id values for racking eligibility)
 *   recipes  array  — list of ref_recipes.id values confirmed eligible (may be empty)
 *
 * @param PDO   $pdo
 * @param int   $sessionId
 * @param int   $actorUserId
 * @param array $payload  Must include: lots (array), recipes (array).
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on missing required payload fields.
 * @throws RuntimeException on DB error.
 */
function session_attest_eligibility(PDO $pdo, int $sessionId, int $actorUserId, array $payload): int
{
    foreach (['lots', 'recipes'] as $required) {
        if (!array_key_exists($required, $payload)) {
            throw new InvalidArgumentException(
                "session_attest_eligibility: payload manquant '{$required}'. " .
                "Champs requis: lots (array), recipes (array)."
            );
        }
    }
    if (!is_array($payload['lots']) || !is_array($payload['recipes'])) {
        throw new InvalidArgumentException('session_attest_eligibility: lots et recipes doivent être des tableaux.');
    }

    return session_log_step($pdo, $sessionId, 'eligibility_attested', $actorUserId, $payload);
}

/**
 * session_link_event — Bind a domain event row into the session audit trail.
 *
 * Purpose: Audit binding between a session and a domain event row (bd_racking_v2,
 *          bd_packaging_v2, etc.). Enables reverse traversal session→events without
 *          querying every event table independently.
 *
 * The actual session_id_fk on the event table is written by the form handler;
 * this is the complementary step record for backward traversal.
 *
 * Payload: { event_table: string, event_id: int }
 *
 * @param PDO    $pdo
 * @param int    $sessionId
 * @param string $eventTable  e.g. 'bd_racking_v2', 'bd_packaging_v2'
 * @param int    $eventId     PK of the event row.
 * @param int    $actorUserId users.id
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on empty table name or id <= 0.
 * @throws RuntimeException on DB error.
 */
function session_link_event(
    PDO    $pdo,
    int    $sessionId,
    string $eventTable,
    int    $eventId,
    int    $actorUserId
): int {
    $eventTable = trim($eventTable);
    if ($eventTable === '') {
        throw new InvalidArgumentException('session_link_event: eventTable ne peut pas être vide.');
    }
    if ($eventId <= 0) {
        throw new InvalidArgumentException('session_link_event: eventId doit être > 0.');
    }

    return session_log_step($pdo, $sessionId, 'event_linked', $actorUserId, [
        'event_table' => $eventTable,
        'event_id'    => $eventId,
    ]);
}

/**
 * session_handover — Record an operator handover (A passes to B).
 *
 * Purpose: Step-only handover record (see [PQ-4]). The new operator's actions
 *          from this point forward will carry their own actorUserId in each step.
 *          op_sessions.opened_by_fk stays the original opener.
 *
 * Payload: { to_user_fk: int, note: string|null }
 *
 * @param PDO         $pdo          Active DB connection.
 * @param int         $sessionId    op_sessions.id
 * @param int         $fromUserId   users.id of the departing operator (the actor writing the step).
 * @param int         $toUserId     users.id of the incoming operator.
 * @param string|null $note         Optional free note.
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on invalid user ids or self-handover.
 * @throws RuntimeException on DB error.
 */
function session_handover(
    PDO     $pdo,
    int     $sessionId,
    int     $fromUserId,
    int     $toUserId,
    ?string $note = null
): int {
    if ($fromUserId <= 0) {
        throw new InvalidArgumentException('session_handover: fromUserId doit être > 0.');
    }
    if ($toUserId <= 0) {
        throw new InvalidArgumentException('session_handover: toUserId doit être > 0.');
    }
    if ($fromUserId === $toUserId) {
        throw new InvalidArgumentException(
            "session_handover: fromUserId et toUserId sont identiques ({$fromUserId}) — la passation doit être entre deux opérateurs distincts."
        );
    }

    return session_log_step($pdo, $sessionId, 'handover', $fromUserId, [
        'to_user_fk' => $toUserId,
        'note'       => $note,
    ]);
}

/**
 * session_recap_ack — Record the end-of-session recap acknowledgement.
 *
 * Purpose: Step for step_type='recap_acknowledged'. Written explicitly by the form
 *          handler after the operator signs off on the end-checklist.
 *          session_close() does NOT write this automatically (see [PQ-2]).
 *
 * Payload schema (required):
 *   fields  array  — The end-checklist snapshot: { volume_packaged, loss_pct, ... }
 *                    (exact keys are form-specific; all fields captured here for auditability)
 *
 * @param PDO   $pdo
 * @param int   $sessionId
 * @param int   $actorUserId
 * @param array $payload  Must include: fields (array).
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on missing payload.fields.
 * @throws RuntimeException on DB error.
 */
function session_recap_ack(PDO $pdo, int $sessionId, int $actorUserId, array $payload): int
{
    if (!array_key_exists('fields', $payload) || !is_array($payload['fields'])) {
        throw new InvalidArgumentException(
            'session_recap_ack: payload doit inclure "fields" (tableau des données du récapitulatif).'
        );
    }

    return session_log_step($pdo, $sessionId, 'recap_acknowledged', $actorUserId, $payload);
}

/**
 * session_note — Append a free-text annotation to the session audit trail.
 *
 * Purpose: Convenience wrapper for step_type='note'.
 *
 * @param PDO    $pdo
 * @param int    $sessionId
 * @param int    $actorUserId
 * @param string $noteText  Non-empty free text.
 *
 * @return int  The new op_session_steps.id.
 *
 * @throws InvalidArgumentException on empty note text.
 * @throws RuntimeException on DB error.
 */
function session_note(PDO $pdo, int $sessionId, int $actorUserId, string $noteText): int
{
    $noteText = trim($noteText);
    if ($noteText === '') {
        throw new InvalidArgumentException('session_note: le texte de la note ne peut pas être vide.');
    }

    return session_log_step($pdo, $sessionId, 'note', $actorUserId, [
        'text' => $noteText,
    ]);
}

// ─── 2. Read API ──────────────────────────────────────────────────────────────

/**
 * session_for_id — Fetch one op_sessions row by PK.
 *
 * @param PDO $pdo
 * @param int $sessionId  op_sessions.id
 *
 * @return array|null  Full row (all columns) or null if not found.
 *
 * Result is memoized per request (static cache, keyed by $sessionId).
 */
function session_for_id(PDO $pdo, int $sessionId): ?array
{
    static $cache = [];
    if (array_key_exists($sessionId, $cache)) {
        return $cache[$sessionId];
    }

    $stmt = $pdo->prepare(
        "SELECT s.*,
                u_open.username  AS opened_by_username,
                u_close.username AS closed_by_username
           FROM op_sessions s
           LEFT JOIN users u_open  ON u_open.id  = s.opened_by_fk
           LEFT JOIN users u_close ON u_close.id = s.closed_by_fk
          WHERE s.id = ?
          LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $cache[$sessionId] = ($row !== false) ? $row : null;
    return $cache[$sessionId];
}

/**
 * sessions_open_for_vessel — All open (status='open') sessions on a given vessel.
 *
 * Returns 0..N rows (multi-active allowed per PM Q2; this list surfaces them all
 * so the caller/UI can present a warning or selection).
 *
 * @param PDO    $pdo
 * @param string $vesselKind    One of SESSION_VESSEL_KINDS.
 * @param int    $vesselNumber  Tank number.
 *
 * @return array  List of op_sessions rows (all columns), ordered by opened_at ASC.
 */
function sessions_open_for_vessel(PDO $pdo, string $vesselKind, int $vesselNumber): array
{
    static $cache = [];
    $cacheKey = "vessel:{$vesselKind}:{$vesselNumber}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = _session_open_sessions_for_vessel($pdo, $vesselKind, $vesselNumber);
    return $cache[$cacheKey];
}

/**
 * sessions_open_for_operator — All open sessions where the operator is the opener.
 *
 * Used for the "Journal de bord — mes sessions en cours" widget at the top of
 * operator screens.
 *
 * @param PDO $pdo
 * @param int $userId  users.id
 *
 * @return array  List of op_sessions rows, ordered by opened_at DESC.
 */
function sessions_open_for_operator(PDO $pdo, int $userId): array
{
    static $cache = [];
    $cacheKey = "operator:{$userId}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        "SELECT s.*,
                u.username AS opened_by_username
           FROM op_sessions s
           JOIN users u ON u.id = s.opened_by_fk
          WHERE s.status       = 'open'
            AND s.opened_by_fk = ?
            AND s.is_tombstoned = 0
          ORDER BY s.opened_at DESC"
    );
    $stmt->execute([$userId]);
    $cache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $cache[$cacheKey];
}

/**
 * sessions_recent — Journal de bord feed (recent sessions, optional filters).
 *
 * @param PDO        $pdo
 * @param array|null $filter  Optional filter map. Supported keys:
 *   'form_type' => string         — filter by form type
 *   'status'    => string         — 'open'|'closed'|'abandoned'
 *   'vessel_kind'   => string     — combined with vessel_number
 *   'vessel_number' => int
 *   'recipe_id'     => int        — ref_recipes.id
 *   'date_from'     => string     — 'YYYY-MM-DD' (opened_at >=)
 *   'date_to'       => string     — 'YYYY-MM-DD' (opened_at <=)
 * @param int $limit  Max rows (default 50).
 *
 * @return array  List of op_sessions rows, ORDER BY opened_at DESC.
 */
function sessions_recent(PDO $pdo, ?array $filter = null, int $limit = 50): array
{
    static $cache = [];
    $cacheKey = serialize([$filter, $limit]);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $where  = ['s.is_tombstoned = 0'];
    $params = [];

    if (isset($filter['form_type']) && in_array($filter['form_type'], SESSION_FORM_TYPES, true)) {
        $where[]  = 's.form_type = ?';
        $params[] = $filter['form_type'];
    }
    if (isset($filter['status']) && in_array($filter['status'], SESSION_STATUSES, true)) {
        $where[]  = 's.status = ?';
        $params[] = $filter['status'];
    }
    if (isset($filter['vessel_kind']) && in_array($filter['vessel_kind'], SESSION_VESSEL_KINDS, true)) {
        $where[]  = 's.vessel_kind = ?';
        $params[] = $filter['vessel_kind'];
    }
    if (isset($filter['vessel_number'])) {
        $where[]  = 's.vessel_number = ?';
        $params[] = (int)$filter['vessel_number'];
    }
    if (isset($filter['recipe_id'])) {
        $where[]  = 's.recipe_id_fk = ?';
        $params[] = (int)$filter['recipe_id'];
    }
    if (isset($filter['date_from'])) {
        $where[]  = 'DATE(s.opened_at) >= ?';
        $params[] = $filter['date_from'];
    }
    if (isset($filter['date_to'])) {
        $where[]  = 'DATE(s.opened_at) <= ?';
        $params[] = $filter['date_to'];
    }

    $safeLimit  = max(1, min(500, $limit));
    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT s.*,
                u_open.username  AS opened_by_username,
                u_close.username AS closed_by_username
           FROM op_sessions s
           LEFT JOIN users u_open  ON u_open.id  = s.opened_by_fk
           LEFT JOIN users u_close ON u_close.id = s.closed_by_fk
          WHERE {$whereClause}
          ORDER BY s.opened_at DESC
          LIMIT {$safeLimit}"
    );
    $stmt->execute($params);
    $cache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $cache[$cacheKey];
}

/**
 * session_steps_for — Full audit trail for one session, ordered by acted_at ASC.
 *
 * @param PDO $pdo
 * @param int $sessionId  op_sessions.id
 *
 * @return array  List of op_session_steps rows (all columns + actor username), oldest first.
 */
function session_steps_for(PDO $pdo, int $sessionId): array
{
    static $cache = [];
    if (isset($cache[$sessionId])) {
        return $cache[$sessionId];
    }

    $stmt = $pdo->prepare(
        "SELECT ss.*,
                u.username AS actor_username
           FROM op_session_steps ss
           JOIN users u ON u.id = ss.actor_user_id_fk
          WHERE ss.session_id_fk = ?
          ORDER BY ss.acted_at ASC, ss.id ASC"
    );
    $stmt->execute([$sessionId]);
    $cache[$sessionId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $cache[$sessionId];
}

// ─── Private helpers ──────────────────────────────────────────────────────────

/**
 * Load and assert a session exists + is not tombstoned. Throws on failure.
 *
 * @internal
 * @throws InvalidArgumentException when session not found or tombstoned.
 */
function _session_load_or_throw(PDO $pdo, int $sessionId): array
{
    if ($sessionId <= 0) {
        throw new InvalidArgumentException("_session_load_or_throw: sessionId doit être > 0.");
    }

    $stmt = $pdo->prepare(
        "SELECT id, phase, status, opened_by_fk, vessel_kind, vessel_number, form_type
           FROM op_sessions
          WHERE id = ? AND is_tombstoned = 0
          LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new InvalidArgumentException(
            "Session #{$sessionId} introuvable ou marquée comme supprimée (is_tombstoned=1)."
        );
    }
    return $row;
}

/**
 * Fetch a minimal user array for log_revision() audit calls.
 * Looks up username from users table; falls back to synthetic row when not found
 * (avoids breaking audit on stale userId — log still records what we know).
 *
 * @internal
 */
function _session_audit_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return ['id' => (int)$row['id'], 'username' => (string)$row['username']];
    }
    return ['id' => $userId, 'username' => "user#{$userId}"];
}

/**
 * Load open sessions for a vessel (shared by session_open warn + sessions_open_for_vessel).
 *
 * @internal
 */
function _session_open_sessions_for_vessel(PDO $pdo, string $vesselKind, int $vesselNumber): array
{
    $stmt = $pdo->prepare(
        "SELECT id, form_type, phase, opened_by_fk, opened_at
           FROM op_sessions
          WHERE vessel_kind   = ?
            AND vessel_number = ?
            AND status        = 'open'
            AND is_tombstoned = 0
          ORDER BY opened_at ASC"
    );
    $stmt->execute([$vesselKind, $vesselNumber]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
