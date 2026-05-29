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
 *   ferm_fw_cip_override        int (0|1)
 *   ferm_fw_cip_override_reason text
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
 *   Gate 1 (CCT presence) and Gate 2 (CIP cadence) are re-evaluated server-side.
 *   RED → 409 firewall-blocked. YELLOW + override reason → accept + log.
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
require_once __DIR__ . '/../../app/cct-cip-cadence.php';
require_once __DIR__ . '/../../app/yeast-eligibility.php';

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

// Build redirect target early (used for all error flashes)
$redirectBase = '/modules/form-fermenting.php'
    . ($beerRaw !== '' ? '?beer=' . urlencode($beerRaw) : '')
    . ($batch   !== '' ? ($beerRaw !== '' ? '&' : '?') . 'batch=' . urlencode($batch) : '');

// ── Input validation ──────────────────────────────────────────────────────────
if ($sid <= 0) {
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
    define('DH_UNITS', ['g', 'kg']);
}

// ── Connect ───────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// ── Session phase guard ───────────────────────────────────────────────────────
// Prevents stale-tab replays and out-of-order submits.
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

// Phase compatibility: submitted phase must match session phase, with one exception:
// when phase=start and this is the first event, we treat it as in_progress.
// Both 'start' and 'in_progress' are valid when session is at phase=start or in_progress.
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
    flash_set('err',
        "Phase session '{$sessionPhase}' ≠ phase soumise '{$submittedPhase}'. Rechargez la page."
    );
    redirect_to($redirectBase);
}

// ── Resolve recipe_id_fk server-side ─────────────────────────────────────────
// Trust session record; POST value is advisory only.
$recipeId = $sess['recipe_id_fk'] !== null ? (int)$sess['recipe_id_fk'] : null;
if ($recipeId === null) {
    // Fallback: use POST value if session doesn't have it yet.
    $postRecipeId = isset($_POST['recipe_id_fk']) && $_POST['recipe_id_fk'] !== '' ? (int)$_POST['recipe_id_fk'] : null;
    $recipeId = $postRecipeId;
}

// ── Event input validation ────────────────────────────────────────────────────
$eventType = isset($_POST['event_type']) ? trim((string)$_POST['event_type']) : 'Reads';
if (!in_array($eventType, FERM_EVENT_TYPES, true)) {
    flash_set('err', "Type d'évènement invalide : {$eventType}");
    redirect_to($redirectBase);
}

$eventDateRaw = isset($_POST['event_date']) ? trim((string)$_POST['event_date']) : '';
$eventDate    = ($eventDateRaw !== '') ? $eventDateRaw : date('Y-m-d');

// ── Firewall recheck on first event (phase=start or session phase=start) ──────
// Mirrors server-side gate from P-B. RED = reject. YELLOW + override reason = accept.
if ($sessionPhase === 'start') {
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

    // Gate 2: CIP cadence
    $cipStatus = cct_cip_status($pdo, $cctNumber);
    $cipSeverity = $cipStatus['severity'] ?? 'ok';

    if ($cipSeverity === 'red') {
        flash_set('err', 'CIP requis avant de démarrer la fermentation : ' . ($cipStatus['verdict_label'] ?? ''));
        redirect_to($redirectBase);
    }

    if ($cipSeverity === 'warn') {
        $cipOverride = isset($_POST['ferm_fw_cip_override']) && (int)$_POST['ferm_fw_cip_override'] === 1;
        $cipOverrideReason = isset($_POST['ferm_fw_cip_override_reason'])
            ? trim((string)$_POST['ferm_fw_cip_override_reason'])
            : '';
        if (!$cipOverride || $cipOverrideReason === '') {
            flash_set('err', 'CIP : dépassement du seuil de vigilance — justification de contournement obligatoire.');
            redirect_to($redirectBase);
        }
        // YELLOW accepted with override — will log in step_payload below.
    }
}

// ── Purge cadence enforcement ─────────────────────────────────────────────────
if ($eventType === 'Purge') {
    // Read purge_after_days from commissioning_settings.
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

    // Query last Purge submitted_at for this (beer_raw, batch)
    $lastPurgeStmt = $pdo->prepare(
        "SELECT MAX(submitted_at) AS last_purge
           FROM bd_fermenting_v2
          WHERE beer_raw      = ?
            AND batch         = ?
            AND event_type    = 'Purge'
            AND is_tombstoned = 0"
    );
    $lastPurgeStmt->execute([$beerRaw, $batch]);
    $lastPurgeRow = $lastPurgeStmt->fetch(PDO::FETCH_ASSOC);
    $lastPurge    = $lastPurgeRow['last_purge'] ?? null;

    if ($lastPurge !== null) {
        $daysSince = (int)floor((time() - strtotime($lastPurge)) / 86400);
        if ($daysSince < $purgeAfterDays) {
            // Check for override
            $purgeOverride = isset($_POST['purge_override']) && (int)$_POST['purge_override'] === 1;
            $purgeOverrideReason = isset($_POST['purge_override_reason'])
                ? trim((string)$_POST['purge_override_reason'])
                : '';
            if (!$purgeOverride || $purgeOverrideReason === '') {
                $rackableIn = $purgeAfterDays - $daysSince;
                flash_set('err',
                    "Purge trop récente — dernière purge il y a {$daysSince}j "
                    . "(seuil : {$purgeAfterDays}j). Réessayez dans {$rackableIn}j "
                    . "ou cochez l'option de contournement avec justification."
                );
                redirect_to($redirectBase);
            }
            // Override accepted — purgeOverrideReason will be logged in step_payload.
        }
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

$finalComments   = isset($_POST['final_comments'])    ? trim((string)$_POST['final_comments'])    : null;
$commentPurge    = ($eventType === 'Purge')      ? (isset($_POST['comment_purge'])      ? trim((string)$_POST['comment_purge'])      : null) : null;
$commentColdCrash = ($eventType === 'ColdCrash') ? (isset($_POST['comment_cold_crash']) ? trim((string)$_POST['comment_cold_crash']) : null) : null;
$fwComment       = isset($_POST['fw_comment'])    ? trim((string)$_POST['fw_comment'])    : null;

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

// ── Resolve dh_mi_id_fk for DryHop lines ─────────────────────────────────────
$miIdFkMap = [];
if (!empty($dhLines)) {
    $miIds = array_filter(array_column($dhLines, 'mi_id'));
    if (!empty($miIds)) {
        $placeholders = implode(',', array_fill(0, count($miIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT mi_id, id FROM ref_mi WHERE mi_id IN ({$placeholders}) AND is_active = 1"
        );
        $stmt->execute(array_values($miIds));
        foreach ($stmt->fetchAll() as $r) {
            $miIdFkMap[$r['mi_id']] = (int)$r['id'];
        }
    }
}

// ── Build rows to insert ──────────────────────────────────────────────────────
// Mirrors form-fermenting.php §7 exactly — same hashCols computation.
// CRITICAL: $hashCols must be byte-for-byte identical to form-fermenting.php.
$submittedAt = date('Y-m-d H:i:s.u');
$auditFlags  = 'web_entry,session_event';

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
                'dh_category'        => 'hops_dry',
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
                'comment_purge'      => null,
                'comment_cold_crash' => null,
                'final_comments'     => ($lineIdx === 0) ? $finalComments : null,
            ],
            'line_idx' => $lineIdx,
        ];
    }
} else {
    // Reads / Purge / ColdCrash — single row, line_idx=0
    // CRITICAL: must match form-fermenting.php non-DryHop hashCols exactly.
    $hashCols = [
        $eventType, $beerRaw, $batch, '0',
        $recipeId ?? '',
        $eventDate,
        $gravity ?? '',
        $ph ?? '',
        $temperature ?? '',
        $commentPurge ?? '',
        $commentColdCrash ?? '',
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
            'line_idx'           => 0,
            'recipe_id_fk'       => $recipeId,
            'dh_category'        => null,
            'dh_mi_id_fk'        => null,
            'dh_raw_name'        => null,
            'dh_qty'             => null,
            'dh_unit'            => null,
            'dh_lot'             => null,
            'dh_confidence'      => null,
            'dh_parse_note'      => null,
            'dh_source_row'      => null,
            'gravity'            => $gravity,
            'ph'                 => $ph,
            'temperature'        => $temperature,
            'comment_purge'      => $commentPurge,
            'comment_cold_crash' => $commentColdCrash,
            'final_comments'     => $finalComments,
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
        $result = bd_upsert($pdo, 'bd_fermenting_v2', $entry['row'], $nkCols);
        if ($firstId === null) $firstId = $result['id'];

        // Link event to session audit trail.
        session_link_event($pdo, $sid, 'bd_fermenting_v2', (int)$result['id'], $meId);

        log_revision(
            $pdo,
            $me,
            'bd_fermenting_v2',
            (int)$result['id'],
            null,
            $entry['row'],
            'normal',
            $fwComment ?: null
        );
    }

    // ── Phase advance ─────────────────────────────────────────────────────────
    //
    // ColdCrash → advance session to 'end'.
    // start → in_progress (first event of any type from the start form).
    // All other in_progress events: phase stays in_progress.
    // Phase=end events (post-ColdCrash temperature monitoring): stays end.
    //
    $phaseAfter = $sessionPhase;

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

    // ── Log override reasons in a dedicated step ───────────────────────────────
    // CIP override (first event / start phase)
    if ($sessionPhase === 'start' && isset($cipSeverity) && $cipSeverity === 'warn') {
        $cipOverride = isset($_POST['ferm_fw_cip_override']) && (int)$_POST['ferm_fw_cip_override'] === 1;
        if ($cipOverride) {
            $cipOverrideReason = isset($_POST['ferm_fw_cip_override_reason'])
                ? trim((string)$_POST['ferm_fw_cip_override_reason'])
                : '';
            session_log_step($pdo, $sid, 'override_note', $meId, [
                'override_kind'   => 'cip_cadence',
                'override_reason' => $cipOverrideReason,
            ]);
        }
    }

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

flash_set('ok', "{$eventLabel} enregistré — {$beerRaw} (B{$batch})");
redirect_to($redirectBase);
