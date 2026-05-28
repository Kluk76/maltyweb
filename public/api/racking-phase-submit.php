<?php
declare(strict_types=1);
/**
 * POST /api/racking-phase-submit.php
 *
 * Phase-split write endpoint for racking sessions (P-C).
 *
 * Accepts a JSON body:
 *   {
 *     "session_id": int,
 *     "csrf":       string,
 *     "phase":      "in_progress" | "end",
 *     "fields":     { …phase-specific fields… }
 *   }
 *
 * Phase behaviours:
 *   in_progress  — INSERT into bd_racking_v2 (header + measurements; loss/interrupted/
 *                  safety_cip_done/fw_comment/dest_bbt_still_clean default to empty).
 *                  Writes session_id_fk. Links event via session_link_event().
 *   end          — UPDATE the linked bd_racking_v2 row (loss, interrupted, safety_cip_done,
 *                  comments, fw_comment, dest_bbt_still_clean). Recomputes row_hash.
 *
 * Response:
 *   { "ok": true, "racking_id": int }
 *   { "ok": false, "error": "…" }
 *
 * HTTP codes:
 *   200 — success
 *   400 — validation error / bad CSRF / bad phase / missing linked event
 *   403 — not authenticated
 *   405 — wrong method
 *   500 — unexpected server error
 *
 * §READ-POLICY: downstream COGS consumers MUST join op_sessions and filter
 *   status='closed' before treating bd_racking_v2 rows as authoritative.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/sessions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$me = current_user();
if ($me === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Authentification requise.']);
    exit;
}

// ── Decode JSON body ──────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$postedCsrf = $data['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
    exit;
}

// ── Extract top-level fields ──────────────────────────────────────────────────
$sid    = isset($data['session_id']) ? (int)$data['session_id'] : 0;
$phase  = isset($data['phase'])      ? (string)$data['phase']   : '';
$fields = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];

if ($sid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'session_id manquant ou invalide.']);
    exit;
}
if (!in_array($phase, ['in_progress', 'end'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "phase '{$phase}' invalide. Valeurs acceptées: in_progress, end."]);
    exit;
}

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Coerce a field value to float|null. Empty string → null.
 */
function _rps_decimal(?string $v): ?float
{
    if ($v === null || $v === '') return null;
    $clean = str_replace([' ', ','], ['', '.'], $v);
    return is_numeric($clean) ? (float)$clean : null;
}

/**
 * Coerce a field value to int|null. Empty string / 0 → null.
 */
function _rps_int(?string $v): ?int
{
    if ($v === null || $v === '') return null;
    $i = (int)$v;
    return $i > 0 ? $i : null;
}

// ── Allowed enum constants (mirror form-racking.php) ─────────────────────────
// Guard against redeclaration if form-racking.php was somehow included upstream.
if (!defined('_RPS_DEST_TYPES')) {
    define('_RPS_DEST_TYPES',        ['BBT', 'CCT', 'YT']);
}
if (!defined('_RPS_YN')) {
    define('_RPS_YN',                ['Oui', 'Non']);
}
if (!defined('_RPS_LOSS_CAUSES')) {
    define('_RPS_LOSS_CAUSES',       ['produit', 'machine', 'humain']);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// ── Session phase guard ───────────────────────────────────────────────────────
// Prevent replay attacks from stale browser tabs: a closed/abandoned session
// must NEVER accept a new in_progress INSERT (phantom row), and end-phase
// UPDATEs only fire when phase=end. The form-side UI prevents this; this is
// the authoritative server-side gate.
$sessionRow = $pdo->prepare(
    "SELECT phase, status FROM op_sessions WHERE id = ? AND is_tombstoned = 0 LIMIT 1"
);
$sessionRow->execute([$sid]);
$sess = $sessionRow->fetch(PDO::FETCH_ASSOC);
if (!$sess) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Session introuvable.']);
    exit;
}
if ($sess['status'] !== 'open') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Session déjà {$sess['status']} — toute saisie est verrouillée.",
    ]);
    exit;
}
if ($sess['phase'] !== $phase) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Phase session '{$sess['phase']}' ≠ phase soumise '{$phase}'. Rechargez la page.",
    ]);
    exit;
}

try {
    if ($phase === 'in_progress') {
        // ── IN_PROGRESS: INSERT bd_racking_v2 row ─────────────────────────────

        // -- Lot identity (required; JS populates from the attested candidate card) --
        $nebBeer      = isset($fields['neb_beer'])      ? trim((string)$fields['neb_beer'])      : '';
        $nebBatch     = isset($fields['neb_batch'])     ? trim((string)$fields['neb_batch'])     : '';
        $contractBeer  = isset($fields['contract_beer'])  ? trim((string)$fields['contract_beer'])  : '';
        $contractBatch = isset($fields['contract_batch']) ? trim((string)$fields['contract_batch']) : '';

        if ($nebBeer === '' && $contractBeer === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'in_progress: neb_beer ou contract_beer est requis.']);
            exit;
        }
        if (($nebBeer !== '' && $nebBatch === '') || ($contractBeer !== '' && $contractBatch === '')) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'in_progress: batch manquant pour la bière sélectionnée.']);
            exit;
        }

        $nebRecipeId      = _rps_int(isset($fields['neb_recipe_id_fk'])      ? (string)$fields['neb_recipe_id_fk']      : null);
        $contractRecipeId = _rps_int(isset($fields['contract_recipe_id_fk']) ? (string)$fields['contract_recipe_id_fk'] : null);
        $sourceCct        = _rps_int(isset($fields['source_cct_number'])      ? (string)$fields['source_cct_number']      : null);

        // -- Operation --
        $eventDateRaw = isset($fields['event_date']) ? trim((string)$fields['event_date']) : '';
        $eventDate    = ($eventDateRaw !== '') ? $eventDateRaw : date('Y-m-d');
        $startTimeRaw = isset($fields['start_time']) ? trim((string)$fields['start_time']) : '';
        $endTimeRaw   = isset($fields['end_time'])   ? trim((string)$fields['end_time'])   : '';
        // Combine event_date + time into DATETIME for start_time / end_time columns.
        $startTime = ($startTimeRaw !== '') ? ($eventDate . ' ' . $startTimeRaw . ':00') : null;
        $endTime   = ($endTimeRaw   !== '') ? ($eventDate . ' ' . $endTimeRaw   . ':00') : null;

        // -- Destination --
        $destType  = isset($fields['racking_destination_type']) ? trim((string)$fields['racking_destination_type']) : null;
        if ($destType !== null && $destType !== '') {
            if (!in_array($destType, _RPS_DEST_TYPES, true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "in_progress: racking_destination_type '{$destType}' invalide."]);
                exit;
            }
        } else {
            $destType = null;
        }

        $bbtNumber = _rps_int(isset($fields['bbt_number']) ? (string)$fields['bbt_number'] : null);
        $cctNumber = _rps_int(isset($fields['cct_number']) ? (string)$fields['cct_number'] : null);
        $ytNumber  = _rps_int(isset($fields['yt_number'])  ? (string)$fields['yt_number']  : null);

        // Build target_tank_raw (mirror of form-racking.php derivation logic)
        $targetTankRaw = null;
        if ($destType === 'BBT' && $bbtNumber !== null) {
            $targetTankRaw = 'BBT ' . $bbtNumber;
        } elseif ($destType === 'CCT' && $cctNumber !== null) {
            $targetTankRaw = 'CCT ' . $cctNumber;
        } elseif ($destType === 'YT' && $ytNumber !== null) {
            $targetTankRaw = 'YT ' . $ytNumber;
        }

        // -- Measurements --
        $rackedVolHl  = _rps_decimal(isset($fields['racked_vol_hl'])  ? (string)$fields['racked_vol_hl']  : null);
        $blendHl      = _rps_decimal(isset($fields['blend_hl'])       ? (string)$fields['blend_hl']       : null);
        $bbtCo2       = _rps_decimal(isset($fields['bbt_co2'])        ? (string)$fields['bbt_co2']        : null);
        $bbtO2        = _rps_decimal(isset($fields['bbt_o2'])         ? (string)$fields['bbt_o2']         : null);
        $bbtPressure  = _rps_decimal(isset($fields['bbt_pressure'])   ? (string)$fields['bbt_pressure']   : null);
        $avgTurbidity = _rps_decimal(isset($fields['avg_turbidity'])  ? (string)$fields['avg_turbidity']  : null);

        $kzeTargetPu = _rps_decimal(isset($fields['kze_target_pu']) ? (string)$fields['kze_target_pu'] : null);
        $kzeAvgPu    = _rps_decimal(isset($fields['kze_avg_pu'])    ? (string)$fields['kze_avg_pu']    : null);

        $centriRinsedRaw = isset($fields['centri_rinsed']) ? trim((string)$fields['centri_rinsed']) : null;
        $centriRinsed    = ($centriRinsedRaw !== null && in_array($centriRinsedRaw, _RPS_YN, true)) ? $centriRinsedRaw : null;

        // -- Hors-process flag (from attested eligibility payload) --
        $horsProcessFlag   = (!empty($fields['hors_process']) && (int)$fields['hors_process'] === 1) ? 1 : 0;
        $horsProcessReason = ($horsProcessFlag === 1 && isset($fields['hors_process_reason']))
            ? trim((string)$fields['hors_process_reason'])
            : null;

        // -- submitted_at: new unique timestamp for each session-driven write --
        $submittedAt = date('Y-m-d H:i:s.u');
        $actorEmail  = $me['username'] ?? '';
        $meId        = (int)$me['id'];

        // -- QC flag (simplified: no per-recipe thresholds at endpoint; normal for now) --
        $qcFlag = 'normal';

        // -- Client: server-side resolve (mirror form-racking.php §2 rack_type logic) --
        // rack_type is derived from CIP events at session_link_event time — use null for now.
        $rackType = null;  // Derived from CIP events; set here to null (no CIP events available at this endpoint)
        $client   = null;  // Contract clients resolved in form-racking.php from contract_beer; skip for session path

        // If contractBeer is set, attempt a quick client lookup.
        // Form-racking.php resolves $client from ref_recipes; mirror minimal version.
        if ($contractBeer !== '' && $contractRecipeId !== null) {
            $cliStmt = $pdo->prepare(
                "SELECT rc.name FROM ref_recipes r
                   JOIN ref_clients rc ON rc.id = r.client_id_fk
                  WHERE r.id = ? AND rc.id IS NOT NULL LIMIT 1"
            );
            $cliStmt->execute([$contractRecipeId]);
            $cliRow  = $cliStmt->fetch(PDO::FETCH_ASSOC);
            $client  = $cliRow ? (string)$cliRow['name'] : null;
        }

        // -- rack_type: derived from bd_cip_events for this session --
        // Mirrors form-racking.php §2 which checks cip events for 'kze'.
        // CIP events for a session are stored with session_id_fk on bd_cip_events
        // (set by cip_upsert when called from the start-phase attestation flow).
        $rackType = null;
        try {
            $cipMachineStmt = $pdo->prepare(
                "SELECT target_code FROM bd_cip_events
                  WHERE session_id_fk = ? AND target_kind = 'machine' AND is_tombstoned = 0"
            );
            $cipMachineStmt->execute([$sid]);
            $cipMachineCodes = $cipMachineStmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('kze', $cipMachineCodes, true)) {
                $rackType = 'filtration';
            } elseif (!empty($cipMachineCodes)) {
                $rackType = 'soutirage';
            }
        } catch (Throwable $_cipErr) {
            // Non-fatal: rack_type stays null.
        }

        // -- Row hash (must use same canonical order as form-racking.php $hashCols) --
        // End-phase fields (loss, interrupted, safety_cip_done, etc.) default to '' / 0.
        $hashCols = [
            $nebBeer, $nebBatch, $nebRecipeId ?? '',
            $contractBeer, $contractBatch, $contractRecipeId ?? '',
            $rackType ?? '', $client ?? '',
            $startTime ?? '', $endTime ?? '',
            $destType ?? '', $bbtNumber ?? '', $cctNumber ?? '', $ytNumber ?? '',
            $targetTankRaw ?? '',
            $bbtCo2 ?? '', $bbtO2 ?? '', $rackedVolHl ?? '', $blendHl ?? '',
            $avgTurbidity ?? '', $bbtPressure ?? '',
            $centriRinsed ?? '', '',  // safety_cip_done: end-phase field → '' at INSERT time
            $kzeTargetPu ?? '', $kzeAvgPu ?? '',
            '',              // comments: end-phase field → '' at INSERT time
            $horsProcessFlag,
            // C3 — Pertes: end-phase fields → '' / 0
            '', '', '', '',
            // C4 — Interruption: end-phase fields → 0 / '' / ''
            0, '', '',
        ];
        $rowHash = bd_row_hash($hashCols);

        $auditTokens = ['web_entry', 'session_in_progress'];
        if ($horsProcessFlag === 1) $auditTokens[] = 'hors_process_override';
        $auditFlags = implode(',', $auditTokens);

        $row = [
            'row_hash'                 => $rowHash,
            'session_id_fk'            => $sid,
            'audit_flags'              => $auditFlags,
            'submitted_at'             => $submittedAt,
            'email'                    => $actorEmail,
            'event_date'               => $eventDate,
            'seq'                      => 0,
            'neb_beer'                 => $nebBeer,
            'neb_batch'                => $nebBatch,
            'neb_recipe_id_fk'         => $nebRecipeId,
            'contract_beer'            => $contractBeer,
            'contract_batch'           => $contractBatch,
            'contract_recipe_id_fk'    => $contractRecipeId,
            'rack_type'                => $rackType,
            'client'                   => $client,
            'start_time'               => $startTime,
            'end_time'                 => $endTime,
            'racking_destination_type' => $destType,
            'bbt_number'               => $bbtNumber,
            'cct_number'               => $cctNumber,
            'yt_number'                => $ytNumber,
            'target_tank_raw'          => $targetTankRaw,
            'bbt_co2'                  => $bbtCo2,
            'bbt_o2'                   => $bbtO2,
            'racked_vol_hl'            => $rackedVolHl,
            'blend_hl'                 => $blendHl,
            'avg_turbidity'            => $avgTurbidity,
            'bbt_pressure'             => $bbtPressure,
            'centri_rinsed'            => $centriRinsed,
            'safety_cip_done'          => null,   // end-phase field — not written at INSERT
            'kze_target_pu'            => $kzeTargetPu,
            'kze_avg_pu'               => $kzeAvgPu,
            'comments'                 => null,   // end-phase field
            'hors_process_flag'        => $horsProcessFlag,
            'hors_process_reason'      => $horsProcessReason,
            'loss_source_hl'           => null,   // end-phase
            'loss_dest_hl'             => null,   // end-phase
            'loss_cause'               => null,   // end-phase
            'loss_note'                => null,   // end-phase
            'interrupted_flag'         => 0,      // end-phase
            'interrupted_reason'       => null,   // end-phase
            'dest_bbt_still_clean'     => null,   // end-phase
        ];

        $nkCols = ['submitted_at', 'neb_beer', 'neb_batch', 'contract_beer', 'contract_batch', 'seq'];

        $ownTx = !$pdo->inTransaction();
        $ownTx ? $pdo->beginTransaction() : null;

        try {
            $result    = bd_upsert($pdo, 'bd_racking_v2', $row, $nkCols);
            $rackingId = (int)$result['id'];

            // Link event to session audit trail.
            session_link_event($pdo, $sid, 'bd_racking_v2', $rackingId, $meId);

            // Audit revision.
            log_revision($pdo, $me, 'bd_racking_v2', $rackingId, null, $row, $qcFlag, null);

            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'racking_id' => $rackingId], JSON_UNESCAPED_UNICODE);

    } elseif ($phase === 'end') {
        // ── END: UPDATE the linked bd_racking_v2 row ──────────────────────────

        // Resolve the linked racking row from op_session_steps.
        $linkedStmt = $pdo->prepare(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.event_id')) AS event_id
               FROM op_session_steps
              WHERE session_id_fk = ?
                AND step_type = 'event_linked'
                AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.event_table')) = 'bd_racking_v2'
              ORDER BY id DESC
              LIMIT 1"
        );
        $linkedStmt->execute([$sid]);
        $linkedRow = $linkedStmt->fetch(PDO::FETCH_ASSOC);

        if (!$linkedRow || !isset($linkedRow['event_id'])) {
            http_response_code(400);
            echo json_encode([
                'ok'    => false,
                'error' => 'Aucun événement bd_racking_v2 lié à cette session. Enregistrez d\'abord la phase en cours.',
            ]);
            exit;
        }
        $rackingId = (int)$linkedRow['event_id'];
        if ($rackingId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Identifiant bd_racking_v2 lié invalide.']);
            exit;
        }

        // Load existing row for before-snapshot + hash recompute.
        $existingStmt = $pdo->prepare("SELECT * FROM bd_racking_v2 WHERE id = ? LIMIT 1");
        $existingStmt->execute([$rackingId]);
        $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingRow) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "bd_racking_v2 id={$rackingId} introuvable."]);
            exit;
        }

        // -- Parse end-phase fields --
        $safetyCipDoneRaw = isset($fields['safety_cip_done']) ? trim((string)$fields['safety_cip_done']) : null;
        $safetyCipDone    = ($safetyCipDoneRaw !== null && in_array($safetyCipDoneRaw, _RPS_YN, true)) ? $safetyCipDoneRaw : null;

        $comments  = isset($fields['comments']) ? trim((string)$fields['comments']) : null;
        $fwComment = isset($fields['fw_comment']) ? trim((string)$fields['fw_comment']) : null;

        $perteToggle     = !empty($fields['perte_toggle']);
        $lossSourceHl    = $perteToggle ? _rps_decimal(isset($fields['loss_source_hl']) ? (string)$fields['loss_source_hl'] : null) : null;
        $lossDestHl      = $perteToggle ? _rps_decimal(isset($fields['loss_dest_hl'])   ? (string)$fields['loss_dest_hl']   : null) : null;
        $lossCauseRaw    = $perteToggle && isset($fields['loss_cause']) ? trim((string)$fields['loss_cause']) : null;
        $lossCause       = ($lossCauseRaw !== null && in_array($lossCauseRaw, _RPS_LOSS_CAUSES, true)) ? $lossCauseRaw : null;
        $lossNote        = $perteToggle && isset($fields['loss_note']) ? trim((string)$fields['loss_note']) : null;

        $interruptedFlag   = (!empty($fields['interrupted_flag']) && (int)$fields['interrupted_flag'] === 1) ? 1 : 0;
        $interruptedReason = ($interruptedFlag === 1 && isset($fields['interrupted_reason']))
            ? trim((string)$fields['interrupted_reason'])
            : null;

        $destBbtStillCleanRaw = isset($fields['dest_bbt_still_clean']) ? (string)$fields['dest_bbt_still_clean'] : null;
        $destBbtStillClean    = ($destBbtStillCleanRaw !== null && $destBbtStillCleanRaw !== '')
            ? (int)$destBbtStillCleanRaw   // 0 or 1
            : null;

        // -- Recompute row_hash with all fields now known --
        // Must use same canonical order as form-racking.php $hashCols.
        $rackType   = (string)($existingRow['rack_type']   ?? '');
        $client     = (string)($existingRow['client']      ?? '');
        $startTime  = (string)($existingRow['start_time']  ?? '');
        $endTime    = (string)($existingRow['end_time']    ?? '');
        $destType   = (string)($existingRow['racking_destination_type'] ?? '');
        $bbtNumber  = $existingRow['bbt_number']  !== null ? (string)$existingRow['bbt_number']  : '';
        $cctNumber  = $existingRow['cct_number']  !== null ? (string)$existingRow['cct_number']  : '';
        $ytNumber   = $existingRow['yt_number']   !== null ? (string)$existingRow['yt_number']   : '';
        $targetRaw  = (string)($existingRow['target_tank_raw'] ?? '');
        $bbtCo2     = $existingRow['bbt_co2']       !== null ? (string)$existingRow['bbt_co2']       : '';
        $bbtO2      = $existingRow['bbt_o2']        !== null ? (string)$existingRow['bbt_o2']        : '';
        $rackedVol  = $existingRow['racked_vol_hl'] !== null ? (string)$existingRow['racked_vol_hl'] : '';
        $blendHl    = $existingRow['blend_hl']      !== null ? (string)$existingRow['blend_hl']      : '';
        $avgTurb    = $existingRow['avg_turbidity'] !== null ? (string)$existingRow['avg_turbidity'] : '';
        $bbtPres    = $existingRow['bbt_pressure']  !== null ? (string)$existingRow['bbt_pressure']  : '';
        $centriRins = (string)($existingRow['centri_rinsed'] ?? '');
        $kzeTarget  = $existingRow['kze_target_pu'] !== null ? (string)$existingRow['kze_target_pu'] : '';
        $kzeAvg     = $existingRow['kze_avg_pu']    !== null ? (string)$existingRow['kze_avg_pu']    : '';
        $hpFlag     = (int)($existingRow['hors_process_flag'] ?? 0);

        $hashCols = [
            (string)($existingRow['neb_beer']      ?? ''),
            (string)($existingRow['neb_batch']     ?? ''),
            $existingRow['neb_recipe_id_fk']      !== null ? (string)$existingRow['neb_recipe_id_fk']      : '',
            (string)($existingRow['contract_beer'] ?? ''),
            (string)($existingRow['contract_batch']?? ''),
            $existingRow['contract_recipe_id_fk'] !== null ? (string)$existingRow['contract_recipe_id_fk'] : '',
            $rackType, $client,
            $startTime, $endTime,
            $destType, $bbtNumber, $cctNumber, $ytNumber,
            $targetRaw,
            $bbtCo2, $bbtO2, $rackedVol, $blendHl,
            $avgTurb, $bbtPres,
            $centriRins, $safetyCipDone ?? '',
            $kzeTarget, $kzeAvg,
            $comments ?? '',
            $hpFlag,
            $lossSourceHl ?? '', $lossDestHl ?? '', $lossCause ?? '', $lossNote ?? '',
            $interruptedFlag,
            $interruptedReason ?? '',
            $destBbtStillClean ?? '',
        ];
        $newRowHash = bd_row_hash($hashCols);

        $auditTokens = ['web_entry', 'session_end'];
        if ($hpFlag === 1) $auditTokens[] = 'hors_process_override';
        $newAuditFlags = implode(',', $auditTokens);

        // -- UPDATE --
        $meId  = (int)$me['id'];
        $ownTx = !$pdo->inTransaction();
        $ownTx ? $pdo->beginTransaction() : null;

        try {
            $updStmt = $pdo->prepare(
                "UPDATE bd_racking_v2 SET
                   row_hash         = ?,
                   audit_flags      = ?,
                   safety_cip_done  = ?,
                   comments         = ?,
                   loss_source_hl   = ?,
                   loss_dest_hl     = ?,
                   loss_cause       = ?,
                   loss_note        = ?,
                   interrupted_flag    = ?,
                   interrupted_reason  = ?,
                   dest_bbt_still_clean = ?,
                   updated_at       = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $updStmt->execute([
                $newRowHash,
                $newAuditFlags,
                $safetyCipDone,
                $comments !== '' ? $comments : null,
                $lossSourceHl,
                $lossDestHl,
                $lossCause,
                $lossNote !== '' ? $lossNote : null,
                $interruptedFlag,
                $interruptedReason !== '' ? $interruptedReason : null,
                $destBbtStillClean,
                $rackingId,
            ]);

            log_revision(
                $pdo,
                $me,
                'bd_racking_v2',
                $rackingId,
                $existingRow,
                array_merge($existingRow, [
                    'safety_cip_done'     => $safetyCipDone,
                    'comments'            => $comments,
                    'loss_source_hl'      => $lossSourceHl,
                    'loss_dest_hl'        => $lossDestHl,
                    'loss_cause'          => $lossCause,
                    'loss_note'           => $lossNote,
                    'interrupted_flag'    => $interruptedFlag,
                    'interrupted_reason'  => $interruptedReason,
                    'dest_bbt_still_clean'=> $destBbtStillClean,
                ]),
                'normal',
                $fwComment !== '' ? $fwComment : null
            );

            if ($ownTx) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'racking_id' => $rackingId], JSON_UNESCAPED_UNICODE);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur inattendue : ' . $e->getMessage()]);
}
