<?php
declare(strict_types=1);
/**
 * POST /api/qa-bottle-reception.php
 *
 * Async JSON handler — records one QA bottle-reception check into qa_bottle_reception_checks.
 * Idempotent: duplicate row_hash (SQLSTATE 23000) is treated as success.
 *
 * Request (POST body):
 *   csrf             — session CSRF token
 *   delivery_id_fk   — INT > 0|'' (optional, must exist in inv_deliveries)
 *   mi_id_fk         — INT > 0|'' (optional, must exist in ref_mi)
 *   reception_date   — 'YYYY-MM-DD'
 *   lot_ref          — string (max 64)|'' (optional)
 *   measure_type     — 'weight'|'volume'
 *   sample_size      — INT > 0|'' (optional)
 *   measured_value   — numeric (comma→dot normalised)
 *   target_value     — numeric|'' (optional)
 *   tolerance_abs    — numeric|'' (optional)
 *   outcome          — 'pass'|'fail'|'marginal'
 *   comments         — string|'' (optional)
 *
 * Response 200 OK:
 *   { ok: true, id, reception_date, mi_id_fk, measure_type,
 *     measured_value, outcome, csrf: <fresh token> }
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
$deliveryIdFkRaw   = $_POST['delivery_id_fk']  ?? '';
$miIdFkRaw         = $_POST['mi_id_fk']         ?? '';
$receptionDateRaw  = trim($_POST['reception_date'] ?? '');
$lotRefRaw         = trim($_POST['lot_ref']     ?? '');
$measureTypeRaw    = $_POST['measure_type']     ?? '';
$sampleSizeRaw     = $_POST['sample_size']      ?? '';
$measuredValueRaw  = $_POST['measured_value']   ?? '';
$targetValueRaw    = $_POST['target_value']     ?? '';
$toleranceAbsRaw   = $_POST['tolerance_abs']    ?? '';
$outcomeRaw        = $_POST['outcome']          ?? '';
$commentsRaw       = $_POST['comments']         ?? null;

// ── Validate required fields ──────────────────────────────────────────────────
$deliveryIdFk = null;
if ($deliveryIdFkRaw !== '' && (int) $deliveryIdFkRaw > 0) {
    $deliveryIdFk = (int) $deliveryIdFkRaw;
}

$miIdFk = null;
if ($miIdFkRaw !== '' && (int) $miIdFkRaw > 0) {
    $miIdFk = (int) $miIdFkRaw;
}

if ($receptionDateRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receptionDateRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Date de réception invalide (format YYYY-MM-DD requis).']);
    exit;
}
$receptionDate = $receptionDateRaw;

$lotRef = ($lotRefRaw !== '') ? $lotRefRaw : null;
if ($lotRef !== null && mb_strlen($lotRef) > 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Référence de lot trop longue (max 64 caractères).']);
    exit;
}

try {
    $measureType = must_be_one_of('measure_type', $measureTypeRaw, ['weight', 'volume']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Type de mesure invalide (weight ou volume).']);
    exit;
}

$sampleSize = null;
if ($sampleSizeRaw !== '' && (int) $sampleSizeRaw > 0) {
    $sampleSize = (int) $sampleSizeRaw;
}

$measuredValueStr = str_replace(',', '.', trim((string) $measuredValueRaw));
if (!is_numeric($measuredValueStr) || $measuredValueStr === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Valeur mesurée invalide.']);
    exit;
}
$measuredValue = $measuredValueStr;

$targetValue  = parse_nullable_decimal((string) $targetValueRaw);
$toleranceAbs = parse_nullable_decimal((string) $toleranceAbsRaw);

try {
    $outcome = must_be_one_of('outcome', $outcomeRaw, ['pass', 'fail', 'marginal']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Résultat invalide (pass, fail, marginal).']);
    exit;
}

$comments = ($commentsRaw !== null && trim((string) $commentsRaw) !== '')
    ? trim((string) $commentsRaw)
    : null;

// ── Derive computed columns ───────────────────────────────────────────────────
$submittedByUserIdFk = (int) $me['id'];

$rowHash = hash('sha256',
    ($deliveryIdFk ?? '') . '|' .
    ($miIdFk ?? '') . '|' .
    $receptionDate . '|' .
    $measureType . '|' .
    $measuredValue
);

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $pdo = maltytask_pdo();

    // Validate delivery_id_fk if provided
    if ($deliveryIdFk !== null) {
        $chkStmt = $pdo->prepare('SELECT id FROM inv_deliveries WHERE id = ? LIMIT 1');
        $chkStmt->execute([$deliveryIdFk]);
        if ($chkStmt->fetch() === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'delivery_id_fk introuvable dans inv_deliveries.']);
            exit;
        }
    }

    // Validate mi_id_fk if provided
    if ($miIdFk !== null) {
        $chkStmt = $pdo->prepare('SELECT id FROM ref_mi WHERE id = ? LIMIT 1');
        $chkStmt->execute([$miIdFk]);
        if ($chkStmt->fetch() === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'mi_id_fk introuvable dans ref_mi.']);
            exit;
        }
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO qa_bottle_reception_checks
                 (delivery_id_fk, mi_id_fk, reception_date, lot_ref,
                  measure_type, sample_size, measured_value, target_value,
                  tolerance_abs, outcome, submitted_by_user_id_fk, comments, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deliveryIdFk,
            $miIdFk,
            $receptionDate,
            $lotRef,
            $measureType,
            $sampleSize,
            $measuredValue,
            $targetValue,
            $toleranceAbs,
            $outcome,
            $submittedByUserIdFk,
            $comments,
            $rowHash,
        ]);

        $insertedId = (int) $pdo->lastInsertId();

        $afterArr = [
            'delivery_id_fk'          => $deliveryIdFk,
            'mi_id_fk'                => $miIdFk,
            'reception_date'          => $receptionDate,
            'lot_ref'                 => $lotRef,
            'measure_type'            => $measureType,
            'sample_size'             => $sampleSize,
            'measured_value'          => $measuredValue,
            'target_value'            => $targetValue,
            'tolerance_abs'           => $toleranceAbs,
            'outcome'                 => $outcome,
            'submitted_by_user_id_fk' => $submittedByUserIdFk,
            'comments'                => $comments,
            'row_hash'                => $rowHash,
        ];

        log_revision($pdo, $me, 'qa_bottle_reception_checks', $insertedId, null, $afterArr, 'normal', 'QA bottle reception check');

        echo json_encode([
            'ok'             => true,
            'id'             => $insertedId,
            'reception_date' => $receptionDate,
            'mi_id_fk'       => $miIdFk,
            'measure_type'   => $measureType,
            'measured_value' => $measuredValue,
            'outcome'        => $outcome,
            'csrf'           => csrf_token(),
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Duplicate row_hash — idempotent re-submit, treat as success.
            $dupStmt = $pdo->prepare('SELECT id FROM qa_bottle_reception_checks WHERE row_hash = ? LIMIT 1');
            $dupStmt->execute([$rowHash]);
            $existingId = (int) $dupStmt->fetchColumn();
            echo json_encode(['ok' => true, 'duplicate' => true, 'id' => $existingId, 'csrf' => csrf_token()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'qa-bottle-reception')]);
}
