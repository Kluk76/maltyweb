<?php
declare(strict_types=1);
/**
 * POST /api/fermenting-phase-submit.php
 *
 * Phase-split write endpoint for fermenting sessions (P-C).
 *
 * Accepts a form-encoded POST body (non-AJAX, server-redirect on success):
 *   session_id  int
 *   csrf        string
 *   phase       "start" | "in_progress" | "end"   — the phase the client believes it's in
 *   event_type  "Reads" | "DryHop" | "Purge" | "ColdCrash"
 *   beer_select string     — raw beer identifier
 *   batch       string     — batch number
 *   event_date  date Y-m-d
 *   recipe_id_fk int       — optional; resolved server-side from session when absent
 *   gravity     decimal    — °Plato
 *   ph          decimal
 *   temperature decimal    — °C
 *   comment_purge     text — Purge rows
 *   comment_cold_crash text— ColdCrash rows
 *   final_comments    text — all rows
 *   fw_comment  text       — optional operator diff note
 *   dh_mi_id[]  string[]   — DryHop: MI id strings
 *   dh_qty[]    string[]
 *   dh_unit[]   string[]
 *   dh_lot[]    string[]
 *   purge_override        int (0|1) — override purge-too-recent cadence
 *   purge_override_reason text
 *
 * Phase behaviours:
 *   start / in_progress — INSERT one or more rows into bd_fermenting_v2 via bd_upsert.
 *                         Sets session_id_fk on each row. Links event(s) via session_link_event.
 *                         Advances session phase: start → in_progress; ColdCrash → end.
 *                         Purge cadence enforced (commissioning_settings.fermenting_cadence.purge_after_days).
 *   end         — ColdCrash has already been recorded; operator submits an optional
 *                 post-ColdCrash Reads event. Session stays at phase=end (racking auto-closes it).
 *
 * Phase guard (HARD — server-side):
 *   status ≠ 'open'  → 409 session-locked
 *   submitted phase ∉ {start, in_progress, end}  → 400
 *   submitted phase ≠ session.phase (except: start submitting in_progress is valid on first event)
 *   → 409 session-phase-mismatch
 *
 * Firewall recheck on first event (phase=start only):
 *   Gate 1 (CCT presence) is re-evaluated server-side.
 *   RED → 409 firewall-blocked. Gate 2 (CIP cadence) is not checked for fermenting.
 *
 * Purge cadence:
 *   Read commissioning_settings.fermenting_cadence.purge_after_days (default 3).
 *   Query last Purge submitted_at for this (beer_raw, batch). If within cadence AND no override
 *   → 409 purge-too-recent. With override_flag + reason → accept + log reason in step_payload.
 *
 * Response (success):
 *   HTTP 302 redirect to /modules/form-fermenting.php?beer={beer}&batch={batch}
 *   Flash message set in $_SESSION via flash_set().
 *
 * Response (error):
 *   HTTP 302 redirect to /modules/form-fermenting.php?beer={beer}&batch={batch}&err=1
 *   Flash error set via flash_set().
 *
 * §READ-POLICY: downstream COGS consumers MUST join op_sessions and filter
 *   status IN ('open','closed') (never abandoned) for bd_fermenting_v2 rows with session_id_fk.
 *   Historical rows (session_id_fk IS NULL) remain valid — they predate session introduction.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/sessions.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';
require_once __DIR__ . '/../../app/settings-helpers.php';  // flash_set(), pdo_friendly_error(), redirect_to() — PRG helpers

// All responses redirect back (PRG pattern). No JSON responses.
// Error messages go via flash_set('err', ...) then redirect.

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Méthode non autorisée.';
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
require_login();
$me   = current_user();
$meId = (int)$me['id'];

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    flash_set('err', 'Session expirée — recharge la page.');
    redirect_to('/modules/form-fermenting.php');
}

// ── Extract top-level fields ──────────────────────────────────────────────────
$sid           = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$submittedPhase = isset($_POST['phase']) ? trim((string)$_POST['phase']) : '';
$beerRaw       = isset($_POST['beer_select']) ? trim((string)$_POST['beer_select']) : '';
$batch         = isset($_POST['batch'])       ? trim((string)$_POST['batch'])       : '';

// ── Edit-mode detection ───────────────────────────────────────────────────────
// edit_submitted_at is written by fermenting-phase-start.php only in edit mode.
// Validate strictly: must match datetime(6) format (YYYY-MM-DD HH:MM:SS[.microseconds]).
// CRITICAL: when present and valid, we REUSE this value as $submittedAt so bd_upsert
// hits the existing NK (submitted_at, event_type, beer_raw, batch, line_idx) and
// UPDATEs the row in place instead of INSERTing a duplicate.
$editSubmittedAtRaw = isset($_POST['edit_submitted_at']) && $_POST['edit_submitted_at'] !== ''
    ? trim((string)$_POST['edit_submitted_at']) : null;
$editSubmittedAtValid = ($editSubmittedAtRaw !== null
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $editSubmittedAtRaw));
$editSubmittedAt = $editSubmittedAtValid ? $editSubmittedAtRaw : null;
$isEditMode = ($editSubmittedAt !== null);

// edit_id is the PK of the row being corrected — used by the cold-crash conversion
// path (direct UPDATE WHERE id=edit_id; bd_upsert cannot be used for conversion because
// changing event_type changes the NK and would INSERT a second row).
$editIdFromPost = (isset($_POST['edit_id']) && ctype_digit((string)$_POST['edit_id']))
    ? (int)$_POST['edit_id'] : null;
if ($editIdFromPost !== null && $editIdFromPost <= 0) $editIdFromPost = null;

// Build redirect target early (used for all error flashes)
$redirectBase = '/modules/form-fermenting.php'
    . ($beerRaw !== '' ? '?beer=' . urlencode($beerRaw) : '')
    . ($batch   !== '' ? ($beerRaw !== '' ? '&' : '?') . 'batch=' . urlencode($batch) : '');

// On SUCCESS we return to the bare cards page (ff_phase='none') so the operator
// can immediately pick the next lot — not back to this lot's form. Errors keep
// $redirectBase (stay on the lot form with the flash so the input can be fixed).
$redirectSuccess = '/modules/form-fermenting.php';

// ── Input validation ──────────────────────────────────────────────────────────
// In edit mode, session_id may be 0 for legacy rows with session_id_fk IS NULL — skip
// the session_id > 0 guard; session status will be resolved from the original row below.
if (!$isEditMode && $sid <= 0) {
    flash_set('err', 'session_id manquant ou invalide.');
    redirect_to($redirectBase);
}
if ($beerRaw === '') {
    flash_set('err', 'La bière est obligatoire.');
    redirect_to($redirectBase);
}
if ($batch === '') {
    flash_set('err', 'Le numéro de brassin est obligatoire.');
    redirect_to($redirectBase);
}
if (!in_array($submittedPhase, ['start', 'in_progress', 'end'], true)) {
    flash_set('err', "Phase '{$submittedPhase}' invalide.");
    redirect_to($redirectBase);
}

// ── Allowed enum constants ────────────────────────────────────────────────────
if (!defined('FERM_EVENT_TYPES')) {
    define('FERM_EVENT_TYPES', ['Reads', 'DryHop', 'Purge', 'ColdCrash']);
}
if (!defined('DH_UNITS')) {
    define('DH_UNITS', ['g', 'kg', 'ml']);
}

// ── Connect ───────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// ── Session phase guard ───────────────────────────────────────────────────────
// Prevents stale-tab replays and out-of-order submits.
//
// EDIT MODE VARIANT: when $isEditMode, load session from the original row's
// session_id_fk rather than from the POST session_id (which is 0 for legacy rows).
//   - If session_id_fk IS NULL (legacy backfill row): no session = no status lock → allow.
//   - If session_id_fk IS SET: look up that session; if status != 'open' → locked.
//
$sessionPhase = null;
$recipeId     = null;

if ($isEditMode) {
    // Resolve session from the original row's session_id_fk.
    // Also fetch id and event_type for the cold-crash conversion path.
    $origRowStmt = $pdo->prepare(
        "SELECT id, session_id_fk, recipe_id_fk, event_type, audit_flags
           FROM bd_fermenting_v2
          WHERE submitted_at = ? AND beer_raw = ? AND batch = ? AND is_tombstoned = 0
          LIMIT 1"
    );
    $origRowStmt->execute([$editSubmittedAt, $beerRaw, $batch]);
    $origRow = $origRowStmt->fetch(PDO::FETCH_ASSOC);

    if ($origRow === false) {
        flash_set('err', 'Évènement original introuvable — correction impossible.');
        redirect_to($redirectBase);
    }

    $recipeId        = $origRow['recipe_id_fk'] !== null ? (int)$origRow['recipe_id_fk'] : null;
    $origSessionIdFk = $origRow['session_id_fk'];
    $origEventType   = (string)$origRow['event_type'];
    $origRowId       = (int)$origRow['id'];

    if ($origSessionIdFk !== null) {
        // Row belongs to a session — enforce status lock.
        $sessEditStmt = $pdo->prepare(
            "SELECT phase, status FROM op_sessions
              WHERE id = ? AND is_tombstoned = 0
              LIMIT 1"
        );
        $sessEditStmt->execute([(int)$origSessionIdFk]);
        $sessEdit = $sessEditStmt->fetch(PDO::FETCH_ASSOC);

        if ($sessEdit && $sessEdit['status'] !== 'open') {
            flash_set('err', "Session déjà {$sessEdit['status']} — correction verrouillée.");
            redirect_to($redirectBase);
        }
        $sessionPhase = $sessEdit['phase'] ?? 'start';
        // Update sid for potential use by session_link_event (no phase advance in edit mode)
        $sid = (int)$origSessionIdFk;
    }
    // session_id_fk IS NULL: legacy row, no session constraint — proceed.

} else {
    $sessStmt = $pdo->prepare(
        "SELECT phase, status, recipe_id_fk, batch AS session_batch
           FROM op_sessions
          WHERE id = ? AND is_tombstoned = 0
          LIMIT 1"
    );
    $sessStmt->execute([$sid]);
    $sess = $sessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        flash_set('err', 'Session introuvable.');
        redirect_to($redirectBase);
    }
    if ($sess['status'] !== 'open') {
        flash_set('err', "Session déjà {$sess['status']} — toute saisie est verrouillée.");
        redirect_to($redirectBase);
    }

    $sessionPhase = $sess['phase'];
    $recipeId     = $sess['recipe_id_fk'] !== null ? (int)$sess['recipe_id_fk'] : null;
}

// Phase compatibility: submitted phase must match session phase, with one exception:
// when phase=start and this is the first event, we treat it as in_progress.
// Both 'start' and 'in_progress' are valid when session is at phase=start or in_progress.
// In edit mode, phase checking is skipped — corrections don't advance the session phase.
if (!$isEditMode) {
    $phaseOk = false;
    if ($submittedPhase === $sessionPhase) {
        $phaseOk = true;
    } elseif ($submittedPhase === 'in_progress' && $sessionPhase === 'start') {
        // First event from the start form — acceptable (session will advance to in_progress)
        $phaseOk = true;
    } elseif ($submittedPhase === 'start' && $sessionPhase === 'start') {
        $phaseOk = true;
    } elseif ($submittedPhase === 'end' && $sessionPhase === 'end') {
        $phaseOk = true;
    }

    if (!$phaseOk) {
        // ── Belt-and-suspenders: stale cache recovery ─────────────────────────
        // op_sessions.phase is a derived cache and can be stale for NULL-linked
        // batches (historical/backfill events with session_id_fk=NULL never
        // advanced the session). Before rejecting, compute the event-derived phase
        // from bd_fermenting_v2 directly. If the submitted phase matches the
        // event-derived truth, sync the session and ACCEPT the submit instead.
        //
        // Only attempt if recipe_id is resolved (don't guess on a NULL recipeId).
        // CRITICAL: status='open' guard on the UPDATE — never mutate a closed session.
        // Do NOT call session_advance_phase() — strict single-step would throw on jumps.
        $_accepted = false;
        if ($recipeId !== null) {
            $_evStmt = $pdo->prepare(
                "SELECT MAX(event_type = 'ColdCrash') AS has_cc,
                        COUNT(*) > 0                  AS has_events
                   FROM bd_fermenting_v2
                  WHERE recipe_id_fk = ?
                    AND batch        = ?
                    AND is_tombstoned = 0"
            );
            $_evStmt->execute([$recipeId, $batch]);
            $_evRow = $_evStmt->fetch(PDO::FETCH_ASSOC);
            $_evDerivedPhase = (!empty($_evRow) && (bool)$_evRow['has_cc'])
                ? 'end'
                : ((!empty($_evRow) && (bool)$_evRow['has_events']) ? 'in_progress' : 'start');

            if ($submittedPhase === $_evDerivedPhase) {
                // Submitted phase matches event-derived truth — stale cache case.
                // Sync the session record and proceed with the write.
                $_syncSubmitStmt = $pdo->prepare(
                    "UPDATE op_sessions
                        SET phase      = ?,
                            updated_at = CURRENT_TIMESTAMP
                      WHERE id            = ?
                        AND status        = 'open'
                        AND is_tombstoned = 0"
                );
                $_syncSubmitStmt->execute([$_evDerivedPhase, $sid]);

                if ($_syncSubmitStmt->rowCount() > 0) {
                    log_revision(
                        $pdo,
                        $me,
                        'op_sessions',
                        $sid,
                        ['phase' => $sessionPhase],
                        ['phase' => $_evDerivedPhase, '_resync' => 'event_derived'],
                        'normal',
                        null
                    );
                }

                // Update the in-memory $sessionPhase so the phase-advance logic
                // below (ColdCrash → end, start → in_progress) uses the correct value.
                $sessionPhase = $_evDerivedPhase;
                $_accepted    = true;
            }
        }

        if (!$_accepted) {
            // Genuine mismatch — neither cache nor event-derived matches submitted.
            // Reject: stale tab or invalid submission.
            flash_set('err',
                "Phase session '{$sessionPhase}' ≠ phase soumise '{$submittedPhase}'. Rechargez la page."
            );
            redirect_to($redirectBase);
        }
    }
}

// ── Resolve recipe_id_fk server-side ─────────────────────────────────────────
// In edit mode, $recipeId is already resolved from the original row.
// In normal mode, trust session record; POST value is advisory only.
if (!$isEditMode) {
    $recipeId = $sess['recipe_id_fk'] !== null ? (int)$sess['recipe_id_fk'] : null;
}
if ($recipeId === null) {
    // Fallback: use POST value if session/row doesn't have it yet.
    $postRecipeId = isset($_POST['recipe_id_fk']) && $_POST['recipe_id_fk'] !== '' ? (int)$_POST['recipe_id_fk'] : null;
    $recipeId = $postRecipeId;
}

// ── Event input validation ────────────────────────────────────────────────────
// cold_crash_flag=1 from the checkbox overrides the dropdown to 'ColdCrash'.
// This is the ONLY place the mapping happens; all downstream logic keys on $eventType.
$coldCrashFlag = isset($_POST['cold_crash_flag']) && $_POST['cold_crash_flag'] === '1';
$eventType = isset($_POST['event_type']) ? trim((string)$_POST['event_type']) : 'Reads';
if ($coldCrashFlag && !$isEditMode) {
    $eventType = 'ColdCrash';
}
// In edit mode, if cold_crash_flag is set AND the original row is not already ColdCrash,
// set $eventType to ColdCrash so validation passes; the actual write is handled by the
// conversion path below (direct UPDATE, not bd_upsert).
if ($coldCrashFlag && $isEditMode && isset($origEventType) && $origEventType !== 'ColdCrash') {
    $eventType = 'ColdCrash';
}
// In edit mode with cold_crash_flag and an already-ColdCrash row: event_type stays
// 'ColdCrash' (the hidden field carries it); the normal bd_upsert edit path handles it.
if (!in_array($eventType, FERM_EVENT_TYPES, true)) {
    flash_set('err', "Type d'évènement invalide : {$eventType}");
    redirect_to($redirectBase);
}

// ── COLD-CRASH CONVERSION (edit mode only) ────────────────────────────────────
// Operator ticked cold_crash_flag on an existing Reads/DryHop/Purge row.
// Changing event_type changes the NK → bd_upsert would INSERT a 2nd row.
// Instead: UPDATE in-place (same id, same submitted_at/beer_raw/batch/line_idx).
// Recompute row_hash for the converted content. Advance session to 'end' if open.
// A duplicate-key error (ColdCrash with that NK already exists, or row_hash clash)
// is caught and flashed; no partial write.
if ($coldCrashFlag && $isEditMode && isset($origEventType) && $origEventType !== 'ColdCrash') {
    // Resolve edit_id: prefer the PK from the DB lookup; fall back to POST value.
    $convRowId = isset($origRowId) ? $origRowId : $editIdFromPost;
    if (!$convRowId) {
        flash_set('err', 'ID de l\'évènement manquant pour la conversion — correction impossible.');
        redirect_to($redirectBase);
    }

    // Read submitted measurements (already parsed below, but we need them here for hash).
    $convGravity    = _fps_decimal($_POST['gravity']          ?? null);
    $convPh         = _fps_decimal($_POST['ph']               ?? null);
    $convTemp       = _fps_decimal($_POST['temperature']      ?? null);
    $convCcComment  = isset($_POST['comment_cold_crash']) ? trim((string)$_POST['comment_cold_crash']) : null;
    $convFinalCmt   = isset($_POST['final_comments'])     ? trim((string)$_POST['final_comments'])     : null;
    $convFwComment  = isset($_POST['fw_comment'])         ? trim((string)$_POST['fw_comment'])         : null;
    $convEventDate  = (isset($_POST['event_date']) && $_POST['event_date'] !== '') ? trim((string)$_POST['event_date']) : date('Y-m-d');

    // Recompute row_hash for the converted ColdCrash content.
    // MUST mirror the non-DryHop hashCols order in the normal write path (frozen layout).
    $convHashCols = [
        'ColdCrash', $beerRaw, $batch, '0',
        $recipeId ?? '',
        $convEventDate,
        $convGravity  ?? '',
        $convPh       ?? '',
        $convTemp     ?? '',
        '',           // slot 9: comment_purge cleared on ColdCrash
        '',           // slot 10: purge_pressure_bar cleared on ColdCrash
        $convCcComment ?? '',
        $convFinalCmt  ?? '',
        $editSubmittedAt,  // submitted_at unchanged
    ];
    $convRowHash = bd_row_hash($convHashCols);
    // Preserve the row's ORIGINAL provenance flags; append the correction markers
    // (dedup so the origin — web_entry/session_event for native rows, or migration
    // flags for backfilled rows — is never overwritten). PM guardrail.
    $convOrigFlags = isset($origRow['audit_flags']) ? trim((string)$origRow['audit_flags']) : '';
    $convFlagSet   = array_filter(array_map('trim', explode(',', $convOrigFlags)), 'strlen');
    foreach (['correction', 'cold_crash_conversion'] as $convMark) {
        if (!in_array($convMark, $convFlagSet, true)) $convFlagSet[] = $convMark;
    }
    $convAuditFlags = implode(',', $convFlagSet);

    $convOwnTx = !$pdo->inTransaction();
    if ($convOwnTx) $pdo->beginTransaction();
    try {
        // Fetch before-snapshot for log_revision.
        $convBefore = bd_fetch_before($pdo, 'bd_fermenting_v2', $convRowId);

        // Direct UPDATE — changes event_type in-place, preserves NK columns
        // (submitted_at, beer_raw, batch, line_idx) so no duplicate row is created.
        $convStmt = $pdo->prepare(
            "UPDATE bd_fermenting_v2
                SET event_type          = 'ColdCrash',
                    event_date          = ?,
                    gravity             = ?,
                    ph                  = ?,
                    temperature         = ?,
                    comment_cold_crash  = ?,
                    comment_purge       = NULL,
                    purge_pressure_bar  = NULL,
                    final_comments      = ?,
                    row_hash            = ?,
                    audit_flags         = ?,
                    updated_at          = CURRENT_TIMESTAMP
              WHERE id            = ?
                AND is_tombstoned = 0"
        );
        $convStmt->execute([
            $convEventDate,
            $convGravity,
            $convPh,
            $convTemp,
            $convCcComment,
            $convFinalCmt,
            $convRowHash,
            $convAuditFlags,
            $convRowId,
        ]);

        if ($convStmt->rowCount() === 0) {
            throw new RuntimeException('La conversion n\'a modifié aucune ligne — l\'évènement est peut-être archivé.');
        }

        $convAfter = [
            'event_type'         => 'ColdCrash',
            'event_date'         => $convEventDate,
            'gravity'            => $convGravity,
            'ph'                 => $convPh,
            'temperature'        => $convTemp,
            'comment_cold_crash' => $convCcComment,
            'comment_purge'      => null,
            'purge_pressure_bar' => null,
            'final_comments'     => $convFinalCmt,
            'row_hash'           => $convRowHash,
            'audit_flags'        => $convAuditFlags,
        ];

        log_revision($pdo, $me, 'bd_fermenting_v2', $convRowId, $convBefore, $convAfter, 'normal', $convFwComment ?: null);

        // Advance session to 'end' if the row is session-linked and the session is open.
        // ColdCrash is terminal: lot becomes racking-eligible after this.
        if (isset($origSessionIdFk) && $origSessionIdFk !== null && $sid > 0) {
            // $sessionPhase was resolved above from the session record.
            if (in_array($sessionPhase, ['start', 'in_progress'], true)) {
                session_advance_phase($pdo, $sid, 'end', $meId, [
                    'event_type' => 'ColdCrash',
                    'via'        => 'correction_conversion',
                ]);
            }
            // If session is already at 'end' or beyond: no re-advance needed.
        }

        if ($convOwnTx) $pdo->commit();

    } catch (Throwable $eConv) {
        if ($convOwnTx && $pdo->inTransaction()) $pdo->rollBack();
        // Duplicate-key = 1062: a ColdCrash with the same NK (or same row_hash) already exists.
        $errMsg = $eConv->getCode() == 1062
            ? 'Un cold crash existe déjà pour ce lot/date — conversion impossible.'
            : pdo_friendly_error($eConv, 'fermenting-phase-submit:conversion');
        flash_set('err', $errMsg);
        redirect_to($redirectBase);
    }

    flash_set('ok', "Cold Crash enregistré (conversion) — {$beerRaw} (B{$batch})");
    redirect_to($redirectSuccess);
}

$eventDateRaw = isset($_POST['event_date']) ? trim((string)$_POST['event_date']) : '';
$eventDate    = ($eventDateRaw !== '') ? $eventDateRaw : date('Y-m-d');

// ── Firewall recheck on first event (phase=start or session phase=start) ──────
// Mirrors server-side gate from P-B. RED = reject. YELLOW + override reason = accept.
// In edit mode: firewall bypassed — event was already accepted at original submission.
if (!$isEditMode && $sessionPhase === 'start') {
    // Resolve CCT for this (beer, batch)
    $cctCheckStmt = $pdo->prepare(
        "SELECT cct FROM bd_brewing_brewday_v2
          WHERE beer = ? AND batch = ? AND is_tombstoned = 0
          ORDER BY id DESC LIMIT 1"
    );
    $cctCheckStmt->execute([$beerRaw, $batch]);
    $cctCheckRow  = $cctCheckStmt->fetch(PDO::FETCH_ASSOC);
    $cctNumber    = ($cctCheckRow !== false && $cctCheckRow['cct'] !== null) ? (int)$cctCheckRow['cct'] : null;

    if ($cctNumber === null) {
        // Gate 1 RED: no CCT — block.
        flash_set('err', 'Aucune CCT trouvée pour ce brassin — vérifier la saisie brewday avant de démarrer.');
        redirect_to($redirectBase);
    }
}

// ── Purge cadence enforcement ─────────────────────────────────────────────────
// A1: skip entirely in edit mode — a correction is not a new purge event.
if ($eventType === 'Purge' && !$isEditMode) {
    // Read purge_after_days from commissioning_settings (kept as-is per spec).
    $cadenceStmt = $pdo->prepare(
        "SELECT value_num
           FROM commissioning_settings
          WHERE section = 'fermenting_cadence'
            AND key_name = 'purge_after_days'
            AND is_active = 1
          LIMIT 1"
    );
    $cadenceStmt->execute();
    $cadenceRow     = $cadenceStmt->fetch(PDO::FETCH_ASSOC);
    $purgeAfterDays = ($cadenceRow !== false && $cadenceRow['value_num'] !== null)
        ? (int)$cadenceRow['value_num']
        : 3;  // default per spec

    // A2+A3: find the nearest OTHER purge for this batch by event_date (not submitted_at),
    // keyed on recipe_id_fk when available, falling back to beer_raw only when NULL.
    // Defense-in-depth: if an edit_id is in play, exclude that row too (A3).
    if ($recipeId !== null) {
        $nearestPurgeParams = [$recipeId, $batch];
        $selfExclude = '';
        if ($editIdFromPost !== null) {
            $selfExclude = ' AND id <> ?';
            $nearestPurgeParams[] = $editIdFromPost;
        }
        $nearestPurgeStmt = $pdo->prepare(
            "SELECT event_date
               FROM bd_fermenting_v2
              WHERE recipe_id_fk = ?
                AND batch        = ?
                AND event_type   = 'Purge'
                AND is_tombstoned = 0"
            . $selfExclude
        );
    } else {
        $nearestPurgeParams = [$beerRaw, $batch];
        $selfExclude = '';
        if ($editIdFromPost !== null) {
            $selfExclude = ' AND id <> ?';
            $nearestPurgeParams[] = $editIdFromPost;
        }
        $nearestPurgeStmt = $pdo->prepare(
            "SELECT event_date
               FROM bd_fermenting_v2
              WHERE beer_raw     = ?
                AND batch        = ?
                AND event_type   = 'Purge'
                AND is_tombstoned = 0"
            . $selfExclude
        );
    }
    $nearestPurgeStmt->execute($nearestPurgeParams);
    $otherPurgeDates = $nearestPurgeStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // A2: compute absolute day-gap to nearest other purge by event_date.
    $newEventDateTs = strtotime($eventDate);
    $minDayGap = null;
    $nearestDate = null;
    foreach ($otherPurgeDates as $otherDate) {
        if ($otherDate === null) continue;
        $gap = (int)abs(($newEventDateTs - strtotime($otherDate)) / 86400);
        if ($minDayGap === null || $gap < $minDayGap) {
            $minDayGap   = $gap;
            $nearestDate = $otherDate;
        }
    }

    if ($minDayGap !== null && $minDayGap < $purgeAfterDays) {
        // A4: override path — only production managers/admins may bypass cadence.
        $purgeOverrideAllowed = manager_can('production', $me);
        $purgeOverride = $purgeOverrideAllowed
            && isset($_POST['purge_override']) && (int)$_POST['purge_override'] === 1;
        $purgeOverrideReason = isset($_POST['purge_override_reason'])
            ? trim((string)$_POST['purge_override_reason'])
            : '';
        if (!$purgeOverride || $purgeOverrideReason === '') {
            $rackableIn = $purgeAfterDays - $minDayGap;
            flash_set('err',
                "Purge trop proche — {$minDayGap}j depuis la purge du {$nearestDate} "
                . "(seuil : {$purgeAfterDays}j). Réessayez dans {$rackableIn}j "
                . "ou cochez l'option de contournement avec justification."
            );
            redirect_to($redirectBase);
        }
        // Override accepted — purgeOverrideReason will be logged in step_payload.
    }
}

// ── Coerce shared measurements ────────────────────────────────────────────────
function _fps_decimal(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    $clean = str_replace(',', '.', $v);
    return is_numeric($clean) ? $clean : null;
}

$gravity      = _fps_decimal($_POST['gravity']     ?? null);
$ph           = _fps_decimal($_POST['ph']           ?? null);
$temperature  = _fps_decimal($_POST['temperature'] ?? null);

// DryHop: temperature comes from the dedicated dh_temperature field (section-readings is hidden).
// Overwrite $temperature so hashCols and the line_idx=0 write use the correct value.
if ($eventType === 'DryHop') {
    $temperature = isset($_POST['dh_temperature']) && trim((string)$_POST['dh_temperature']) !== ''
        ? _fps_decimal(trim((string)$_POST['dh_temperature']))
        : null;
}

$finalComments    = isset($_POST['final_comments'])    ? trim((string)$_POST['final_comments'])    : null;
$commentPurge     = ($eventType === 'Purge')      ? (isset($_POST['comment_purge'])      ? trim((string)$_POST['comment_purge'])      : null) : null;
$purgePressureBar = ($eventType === 'Purge')      ? _fps_decimal($_POST['purge_pressure_bar'] ?? null) : null;
$commentColdCrash = ($eventType === 'ColdCrash')  ? (isset($_POST['comment_cold_crash']) ? trim((string)$_POST['comment_cold_crash']) : null) : null;
$fwComment        = isset($_POST['fw_comment'])    ? trim((string)$_POST['fw_comment'])    : null;

// ── DryHop lines ─────────────────────────────────────────────────────────────
// RULE-2 followup #2 — guard against crafted POST sending scalars instead of arrays.
// Without the is_array check, array_keys() below throws TypeError on scalar input;
// the outer Throwable catch absorbs it but emits a misleading pdo_friendly_error.
$dhMiIds = is_array($_POST['dh_mi_id'] ?? null) ? $_POST['dh_mi_id'] : [];
$dhQtys  = is_array($_POST['dh_qty']   ?? null) ? $_POST['dh_qty']   : [];
$dhUnits = is_array($_POST['dh_unit']  ?? null) ? $_POST['dh_unit']  : [];
$dhLots  = is_array($_POST['dh_lot']   ?? null) ? $_POST['dh_lot']   : [];

$dhLines = [];
if ($eventType === 'DryHop') {
    foreach (array_keys($dhMiIds) as $i) {
        $miId   = isset($dhMiIds[$i]) ? trim((string)$dhMiIds[$i]) : '';
        $qtyRaw = isset($dhQtys[$i])  ? trim((string)$dhQtys[$i])  : '';
        $unit   = isset($dhUnits[$i]) ? trim((string)$dhUnits[$i]) : 'g';
        $lot    = isset($dhLots[$i])  ? trim((string)$dhLots[$i])  : '';

        if ($miId === '' && $qtyRaw === '') continue;

        if ($unit !== '' && !in_array($unit, DH_UNITS, true)) {
            flash_set('err', "Unité dry-hop invalide : {$unit}");
            redirect_to($redirectBase);
        }

        $qty = _fps_decimal($qtyRaw !== '' ? $qtyRaw : null);

        $dhLines[] = [
            'mi_id' => $miId,
            'qty'   => $qty,
            'unit'  => $unit !== '' ? $unit : 'g',
            'lot'   => $lot !== '' ? $lot : null,
        ];
    }

    if (empty($dhLines)) {
        flash_set('err', 'Un houblonnage à froid doit contenir au moins une ligne.');
        redirect_to($redirectBase);
    }
}

// ── Resolve dh_mi_id_fk + dh_category for DryHop lines ──────────────────────
// Category name → dh_category slug (mirrors form-brewing.php $catToSlug).
// These are the four categories allowed in the dry-hop picker.
const DH_CAT_SLUGS = [
    'Hops'             => 'hops_dry',
    'Brewing Adjunct'  => 'adjunct',
    'Brewing Mineral'  => 'mineral',
    'Process Chemical' => 'process',
];
const DH_CAT_ALLOWLIST = ['hops_dry', 'adjunct', 'mineral', 'process'];

$miIdFkMap  = [];  // mi_id => INT id FK
$miCatMap   = [];  // mi_id => dh_category slug
if (!empty($dhLines)) {
    $miIds = array_filter(array_column($dhLines, 'mi_id'));
    if (!empty($miIds)) {
        $placeholders = implode(',', array_fill(0, count($miIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT m.mi_id, m.id, c.name AS category
               FROM ref_mi m
               JOIN ref_mi_categories c ON m.category_id = c.id
              WHERE m.mi_id IN ({$placeholders}) AND m.is_active = 1"
        );
        $stmt->execute(array_values($miIds));
        foreach ($stmt->fetchAll() as $r) {
            $miIdFkMap[$r['mi_id']] = (int)$r['id'];
            $miCatMap[$r['mi_id']]  = DH_CAT_SLUGS[$r['category']] ?? null;
        }
    }

    // PM guardrail: refuse any line whose MI resolves to an unknown/unsupported category.
    foreach ($dhLines as $line) {
        $miIdStr = $line['mi_id'] ?? '';
        if ($miIdStr === '') continue;
        if (!isset($miIdFkMap[$miIdStr])) {
            // MI did not resolve to an active ref_mi row — reject whole submit.
            flash_set('err', "Ingrédient dry-hop introuvable : {$miIdStr}");
            redirect_to($redirectBase);
        }
        $slug = $miCatMap[$miIdStr] ?? null;
        if ($slug === null || !in_array($slug, DH_CAT_ALLOWLIST, true)) {
            flash_set('err', "Catégorie dry-hop non autorisée pour l'ingrédient : {$miIdStr}");
            redirect_to($redirectBase);
        }
    }
}

// ── Build rows to insert ──────────────────────────────────────────────────────
// Mirrors form-fermenting.php §7 exactly — same hashCols computation.
// CRITICAL: $hashCols must be byte-for-byte identical to form-fermenting.php.
//
// EDIT MODE — preserve original submitted_at (NK guard):
// $submittedAt is the NK component. Reusing it causes bd_upsert to hit
// the uq_natural_key and UPDATE the existing row rather than INSERT a duplicate.
// A fresh date('Y-m-d H:i:s.u') would shift the NK → corrupt duplication.
$submittedAt = $isEditMode ? $editSubmittedAt : date('Y-m-d H:i:s.u');
$auditFlags  = $isEditMode ? 'web_entry,session_event,correction' : 'web_entry,session_event';

$rowsToInsert = [];

if ($eventType === 'DryHop' && !empty($dhLines)) {
    foreach ($dhLines as $lineIdx => $line) {
        $miIdStr = $line['mi_id'] ?? '';
        $miFk    = $miIdStr !== '' ? ($miIdFkMap[$miIdStr] ?? null) : null;

        // CRITICAL: must match form-fermenting.php DryHop hashCols exactly.
        $hashCols = [
            $eventType, $beerRaw, $batch, (string)$lineIdx,
            $recipeId ?? '',
            $eventDate, $miIdStr,
            $line['qty'] ?? '',
            $line['unit'],
            $line['lot'] ?? '',
            $gravity ?? '',
            $ph ?? '',
            $temperature ?? '',
            $finalComments ?? '',
            $submittedAt,
        ];

        $rowsToInsert[] = [
            'row'      => [
                'row_hash'           => bd_row_hash($hashCols),
                'session_id_fk'      => $sid,
                'audit_flags'        => $auditFlags,
                'submitted_at'       => $submittedAt,
                'email'              => $me['username'],
                'event_date'         => $eventDate,
                'event_type'         => $eventType,
                'beer_raw'           => $beerRaw,
                'batch'              => $batch,
                'line_idx'           => $lineIdx,
                'recipe_id_fk'       => $recipeId,
                'dh_category'        => $miCatMap[$miIdStr] ?? null,
                'dh_mi_id_fk'        => $miFk,
                'dh_raw_name'        => $miIdStr !== '' ? $miIdStr : null,
                'dh_qty'             => $line['qty'],
                'dh_unit'            => $line['unit'],
                'dh_lot'             => $line['lot'],
                'dh_confidence'      => 'web_entry',
                'dh_parse_note'      => $miFk !== null ? 'direct-mi-pick' : 'unresolved-mi-id',
                'dh_source_row'      => null,
                'gravity'            => ($lineIdx === 0) ? $gravity : null,
                'ph'                 => ($lineIdx === 0) ? $ph      : null,
                'temperature'        => ($lineIdx === 0) ? $temperature : null,
                'comment_purge'       => null,
                'purge_pressure_bar'  => null,
                'comment_cold_crash'  => null,
                'final_comments'      => ($lineIdx === 0) ? $finalComments : null,
            ],
            'line_idx' => $lineIdx,
        ];
    }
} else {
    // Reads / Purge / ColdCrash — single row, line_idx=0
    // CRITICAL: must match form-fermenting.php non-DryHop hashCols exactly.
    // hashCols column order is FROZEN — insert/remove requires updating BOTH
    // this array AND the ColdCrash conversion $convHashCols below. Must match.
    $hashCols = [
        $eventType, $beerRaw, $batch, '0',
        $recipeId ?? '',
        $eventDate,
        $gravity ?? '',
        $ph ?? '',
        $temperature ?? '',
        $commentPurge ?? '',
        $purgePressureBar ?? '',  // slot 10: purge_pressure_bar ('' on non-Purge rows)
        $commentColdCrash ?? '',
        $finalComments ?? '',
        $submittedAt,
    ];

    $rowsToInsert[] = [
        'row'      => [
            'row_hash'            => bd_row_hash($hashCols),
            'session_id_fk'       => $sid,
            'audit_flags'         => $auditFlags,
            'submitted_at'        => $submittedAt,
            'email'               => $me['username'],
            'event_date'          => $eventDate,
            'event_type'          => $eventType,
            'beer_raw'            => $beerRaw,
            'batch'               => $batch,
            'line_idx'            => 0,
            'recipe_id_fk'        => $recipeId,
            'dh_category'         => null,
            'dh_mi_id_fk'         => null,
            'dh_raw_name'         => null,
            'dh_qty'              => null,
            'dh_unit'             => null,
            'dh_lot'              => null,
            'dh_confidence'       => null,
            'dh_parse_note'       => null,
            'dh_source_row'       => null,
            'gravity'             => $gravity,
            'ph'                  => $ph,
            'temperature'         => $temperature,
            'comment_purge'       => $commentPurge,
            'purge_pressure_bar'  => $purgePressureBar,
            'comment_cold_crash'  => $commentColdCrash,
            'final_comments'      => $finalComments,
        ],
        'line_idx' => 0,
    ];
}

$nkCols = ['submitted_at', 'event_type', 'beer_raw', 'batch', 'line_idx'];

// ── Transaction: write rows + link events + advance phase ─────────────────────
$ownTx = !$pdo->inTransaction();
if ($ownTx) $pdo->beginTransaction();

try {
    $firstId = null;
    foreach ($rowsToInsert as $entry) {
        // Before-snapshot in edit mode: capture the pre-image so log_revision emits
        // action='update' with real before_json. null for new submissions → action='insert'.
        $rowBeforeFerm = null;
        if ($isEditMode) {
            $existingFermPk = bd_lookup_pk_by_nk($pdo, 'bd_fermenting_v2', $nkCols, $entry['row']);
            if ($existingFermPk !== null) {
                $rowBeforeFerm = bd_fetch_before($pdo, 'bd_fermenting_v2', $existingFermPk);
            }
        }

        // Operator identity: insert sets FK, edit preserves original.
        // Mutate a local copy so the before/after audit log is accurate.
        $fermRow = $entry['row'];
        if ($isEditMode && $rowBeforeFerm !== null) {
            // Editing an existing row: restore original operator, leave FK untouched.
            $fermRow['email'] = $rowBeforeFerm['email'] ?? $fermRow['email'];
            // submitted_by_user_id_fk intentionally absent — bd_upsert leaves existing FK untouched.
        } else {
            // Fresh insert (new submission OR new line added in edit session): stamp current user.
            $fermRow['submitted_by_user_id_fk'] = (int)$me['id'];
        }

        $result = bd_upsert($pdo, 'bd_fermenting_v2', $fermRow, $nkCols);
        if ($firstId === null) $firstId = $result['id'];

        // Link event to session audit trail.
        session_link_event($pdo, $sid, 'bd_fermenting_v2', (int)$result['id'], $meId);

        // audit_flags already includes 'correction' when $isEditMode (set above in $auditFlags).
        // $rowBeforeFerm non-null in edit mode → action='update' with real before_json.
        log_revision(
            $pdo,
            $me,
            'bd_fermenting_v2',
            (int)$result['id'],
            $rowBeforeFerm,
            $fermRow,
            'normal',
            $fwComment ?: null
        );
    }

    // ── Shrink-tombstone (edit mode + DryHop only) ────────────────────────────
    // If the operator reduced the line count (e.g. 2 → 1), rows with
    // line_idx >= newLineCount are now orphaned. Tombstone them in this same
    // transaction rather than leaving stale data live.
    // GUARD: strictly only in edit mode + DryHop. Never runs on fresh submit
    // (newLineCount would equal the inserted count; belt-and-suspenders guard anyway).
    if ($isEditMode && $eventType === 'DryHop') {
        $newLineCount = count($rowsToInsert);  // M = number of lines just written

        // Fetch before-snapshots of any rows to be tombstoned (line_idx >= M).
        $orphanFetchStmt = $pdo->prepare(
            "SELECT *
               FROM bd_fermenting_v2
              WHERE submitted_at  = ?
                AND beer_raw      = ?
                AND batch         = ?
                AND event_type    = 'DryHop'
                AND line_idx      >= ?
                AND is_tombstoned = 0"
        );
        $orphanFetchStmt->execute([$submittedAt, $beerRaw, $batch, $newLineCount]);
        $orphanRows = $orphanFetchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($orphanRows)) {
            // Tombstone: set is_tombstoned=1 + append audit flag.
            // audit_flags is a comma-separated string; append 'dryhop_edit_shrink' if absent.
            foreach ($orphanRows as $orphanRow) {
                $oid = (int)$orphanRow['id'];
                $origFlagSet = array_filter(
                    array_map('trim', explode(',', (string)($orphanRow['audit_flags'] ?? ''))),
                    'strlen'
                );
                if (!in_array('dryhop_edit_shrink', $origFlagSet, true)) {
                    $origFlagSet[] = 'dryhop_edit_shrink';
                }
                $newAuditFlags = implode(',', $origFlagSet);

                $tombStmt = $pdo->prepare(
                    "UPDATE bd_fermenting_v2
                        SET is_tombstoned = 1,
                            updated_at    = CURRENT_TIMESTAMP,
                            audit_flags   = ?
                      WHERE id = ?
                        AND is_tombstoned = 0"
                );
                $tombStmt->execute([$newAuditFlags, $oid]);

                // Emit log_revision with action='update' + tombstone marker in after_json.
                // The audit_row_revisions.action ENUM has no 'delete' value — house rule.
                $tombAfter = [
                    'is_tombstoned' => 1,
                    'audit_flags'   => $newAuditFlags,
                    '_tombstone'    => 'shrunk_by_dryhop_edit',
                ];
                log_revision(
                    $pdo,
                    $me,
                    'bd_fermenting_v2',
                    $oid,
                    $orphanRow,
                    $tombAfter,
                    'normal',
                    $fwComment ?: null
                );
            }
        }
    }

    // ── Phase advance ─────────────────────────────────────────────────────────
    //
    // ColdCrash → advance session to 'end'.
    // start → in_progress (first event of any type from the start form).
    // All other in_progress events: phase stays in_progress.
    // Phase=end events (post-ColdCrash temperature monitoring): stays end.
    //
    // EDIT MODE: skip phase advance entirely — a correction does not constitute
    // a new event in the session's lifecycle; do NOT advance start→in_progress
    // or in_progress→end for a corrected row.
    $phaseAfter = $sessionPhase;

    if (!$isEditMode) {
        if ($eventType === 'ColdCrash' && in_array($sessionPhase, ['start', 'in_progress'], true)) {
            $phaseAfter = 'end';
            session_advance_phase($pdo, $sid, 'end', $meId, ['event_type' => 'ColdCrash']);
        } elseif ($sessionPhase === 'start') {
            // Any event on a start-phase session advances it to in_progress.
            $phaseAfter = 'in_progress';
            session_advance_phase($pdo, $sid, 'in_progress', $meId, ['event_type' => $eventType]);
        }
        // in_progress: Reads / DryHop / Purge — no phase advance needed.
        // end: Reads post-ColdCrash — session stays end.
    }

    // ── Log override reasons in a dedicated step ───────────────────────────────
    // Purge override
    if ($eventType === 'Purge' && isset($purgeOverride) && $purgeOverride) {
        session_log_step($pdo, $sid, 'override_note', $meId, [
            'override_kind'   => 'purge_cadence',
            'override_reason' => $purgeOverrideReason ?? '',
        ]);
    }

    if ($ownTx) $pdo->commit();

} catch (Throwable $e) {
    if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('err', pdo_friendly_error($e, 'fermenting-phase-submit'));
    redirect_to($redirectBase);
}

// ── Success flash + redirect ──────────────────────────────────────────────────
$eventLabel = match($eventType) {
    'Reads'     => 'Mesures',
    'DryHop'    => 'Houblonnage à froid (' . count($rowsToInsert) . ' addition' . (count($rowsToInsert) > 1 ? 's' : '') . ')',
    'Purge'     => 'Purge',
    'ColdCrash' => 'Cold Crash',
    default     => $eventType,
};

$actionLabel = $isEditMode ? 'Correction enregistrée' : "{$eventLabel} enregistré";
flash_set('ok', "{$actionLabel} — {$beerRaw} (B{$batch})");
redirect_to($redirectSuccess);
