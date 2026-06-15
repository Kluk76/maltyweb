<?php
declare(strict_types=1);
/**
 * POST /api/qa-cleaning-efficacy.php
 *
 * Async JSON handler — records one QA cleaning-efficacy check into qa_cleaning_efficacy_checks.
 * Idempotent: duplicate row_hash (SQLSTATE 23000) is treated as success.
 *
 * Request (POST body):
 *   csrf              — session CSRF token
 *   check_date        — 'YYYY-MM-DD'
 *   method            — 'atp'|'swab'|'visual'|'rinse_water'
 *   surface_label     — string (max 128)
 *   cip_event_id_fk   — INT > 0|'' (optional, must exist in bd_cip_events)
 *   result_value      — numeric|'' (optional)
 *   result_unit       — string (max 16)|'' (optional)
 *   threshold_value   — numeric|'' (optional)
 *   outcome           — 'pass'|'fail'|'marginal'|'pending'
 *   corrective_action — string|'' (optional)
 *   measured_at       — 'YYYY-MM-DD HH:MM'|'' (optional)
 *   comments          — string|'' (optional)
 *
 * Response 200 OK:
 *   { ok: true, id, check_date, method, surface_label, outcome,
 *     result_value, result_unit, csrf: <fresh token> }
 *
 * Duplicate row_hash:
 *   { ok: true, duplicate: true, id: <existing id>, csrf: <fresh token> }
 *
 * CSRF expired:
 *   { ok: false, reason: 'expired', csrf: <fresh token> }
 *   HTTP 401
 *
 * Validation error:
 *   { ok: false, error: '...' }
 *   HTTP 400
 *
 * Server error:
 *   { ok: false, error: 'Erreur serveur : ...' }
 *   HTTP 500
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
require_page_access('qa');
$me = current_user();

// ── CSRF (must be first validation — return fresh token on fail so JS can retry) ─
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Read inputs ───────────────────────────────────────────────────────────────
$checkDateRaw        = trim($_POST['check_date']        ?? '');
$methodRaw           = $_POST['method']                 ?? '';
$surfaceLabelRaw     = trim($_POST['surface_label']     ?? '');
$cipEventIdFkRaw     = $_POST['cip_event_id_fk']        ?? '';
$resultValueRaw      = $_POST['result_value']           ?? '';
$resultUnitRaw       = trim($_POST['result_unit']       ?? '');
$thresholdValueRaw   = $_POST['threshold_value']        ?? '';
$outcomeRaw          = $_POST['outcome']                ?? '';
$correctiveActionRaw = $_POST['corrective_action']      ?? null;
$measuredAtRaw       = trim($_POST['measured_at']       ?? '');
$commentsRaw         = $_POST['comments']               ?? null;

// ── Validate required fields ──────────────────────────────────────────────────
if ($checkDateRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDateRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Date de contrôle invalide (format YYYY-MM-DD requis).']);
    exit;
}
$checkDate = $checkDateRaw;

try {
    $method = must_be_one_of('method', $methodRaw, ['atp', 'swab', 'visual', 'rinse_water']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide (atp, swab, visual, rinse_water).']);
    exit;
}

if ($surfaceLabelRaw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Le libellé de surface est obligatoire.']);
    exit;
}
if (mb_strlen($surfaceLabelRaw) > 128) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Libellé de surface trop long (max 128 caractères).']);
    exit;
}
$surfaceLabel = $surfaceLabelRaw;

$cipEventIdFk = null;
if ($cipEventIdFkRaw !== '' && (int) $cipEventIdFkRaw > 0) {
    $cipEventIdFk = (int) $cipEventIdFkRaw;
}

$resultValue    = parse_nullable_decimal((string) $resultValueRaw);
$resultUnit     = ($resultUnitRaw !== '') ? $resultUnitRaw : null;
if ($resultUnit !== null && mb_strlen($resultUnit) > 16) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unité de résultat trop longue (max 16 caractères).']);
    exit;
}
$thresholdValue = parse_nullable_decimal((string) $thresholdValueRaw);

try {
    $outcome = must_be_one_of('outcome', $outcomeRaw, ['pass', 'fail', 'marginal', 'pending']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Résultat invalide (pass, fail, marginal, pending).']);
    exit;
}

$correctiveAction = ($correctiveActionRaw !== null && trim((string) $correctiveActionRaw) !== '')
    ? trim((string) $correctiveActionRaw)
    : null;

$measuredAt = null;
if ($measuredAtRaw !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $measuredAtRaw)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Date/heure de mesure invalide (format YYYY-MM-DD HH:MM requis).']);
        exit;
    }
    $ts = strtotime($measuredAtRaw);
    if ($ts === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Date/heure de mesure invalide.']);
        exit;
    }
    $measuredAt = date('Y-m-d H:i:s', $ts);
}

$comments = ($commentsRaw !== null && trim((string) $commentsRaw) !== '')
    ? trim((string) $commentsRaw)
    : null;

// ── Derive computed columns ───────────────────────────────────────────────────
$submittedByUserIdFk = (int) $me['id'];

$rowHash = hash('sha256', $checkDate . '|' . $surfaceLabel . '|' . $method . '|' . ($measuredAt ?? ''));

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $pdo = maltytask_pdo();

    // Validate cip_event_id_fk if provided
    if ($cipEventIdFk !== null) {
        $chkStmt = $pdo->prepare('SELECT id FROM bd_cip_events WHERE id = ? LIMIT 1');
        $chkStmt->execute([$cipEventIdFk]);
        if ($chkStmt->fetch() === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'cip_event_id_fk introuvable dans bd_cip_events.']);
            exit;
        }
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO qa_cleaning_efficacy_checks
                 (check_date, method, surface_label, cip_event_id_fk,
                  result_value, result_unit, threshold_value, outcome,
                  corrective_action, measured_at, submitted_by_user_id_fk, comments, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $checkDate,
            $method,
            $surfaceLabel,
            $cipEventIdFk,
            $resultValue,
            $resultUnit,
            $thresholdValue,
            $outcome,
            $correctiveAction,
            $measuredAt,
            $submittedByUserIdFk,
            $comments,
            $rowHash,
        ]);

        $insertedId = (int) $pdo->lastInsertId();

        $afterArr = [
            'check_date'              => $checkDate,
            'method'                  => $method,
            'surface_label'           => $surfaceLabel,
            'cip_event_id_fk'         => $cipEventIdFk,
            'result_value'            => $resultValue,
            'result_unit'             => $resultUnit,
            'threshold_value'         => $thresholdValue,
            'outcome'                 => $outcome,
            'corrective_action'       => $correctiveAction,
            'measured_at'             => $measuredAt,
            'submitted_by_user_id_fk' => $submittedByUserIdFk,
            'comments'                => $comments,
            'row_hash'                => $rowHash,
        ];

        log_revision($pdo, $me, 'qa_cleaning_efficacy_checks', $insertedId, null, $afterArr, 'normal', 'QA cleaning efficacy check');

        echo json_encode([
            'ok'            => true,
            'id'            => $insertedId,
            'check_date'    => $checkDate,
            'method'        => $method,
            'surface_label' => $surfaceLabel,
            'outcome'       => $outcome,
            'result_value'  => $resultValue,
            'result_unit'   => $resultUnit,
            'csrf'          => csrf_token(),
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Duplicate row_hash — idempotent re-submit, treat as success.
            $dupStmt = $pdo->prepare('SELECT id FROM qa_cleaning_efficacy_checks WHERE row_hash = ? LIMIT 1');
            $dupStmt->execute([$rowHash]);
            $existingId = (int) $dupStmt->fetchColumn();
            echo json_encode(['ok' => true, 'duplicate' => true, 'id' => $existingId, 'csrf' => csrf_token()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'qa-cleaning-efficacy')]);
}
