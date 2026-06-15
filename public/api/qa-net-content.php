<?php
declare(strict_types=1);
/**
 * POST /api/qa-net-content.php
 *
 * Async JSON handler — records one QA net-content reading into qa_net_content_readings.
 * Idempotent: duplicate row_hash (SQLSTATE 23000) is treated as success.
 *
 * Request (POST body):
 *   csrf             — session CSRF token
 *   packaging_id_fk  — INT > 0, must exist in bd_packaging_v2
 *   reading_seq      — INT ≥ 1
 *   measure_type     — 'weight'|'volume'
 *   measured_value   — numeric (comma→dot normalised)
 *   target_value     — numeric|'' (optional)
 *   tolerance_abs    — numeric|'' (optional)
 *   tare_value       — numeric|'' (optional)
 *   measured_at      — 'YYYY-MM-DD HH:MM' or 'YYYY-MM-DD HH:MM:SS'
 *   comments         — string|'' (optional)
 *
 * Response 200 OK:
 *   { ok: true, id, packaging_id_fk, reading_seq, measure_type,
 *     measured_value, target_value, tolerance_abs, is_conforming,
 *     tare_value, measured_at, csrf: <fresh token> }
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
$packagingIdFkRaw  = $_POST['packaging_id_fk']  ?? '';
$readingSeqRaw     = $_POST['reading_seq']       ?? '';
$measureTypeRaw    = $_POST['measure_type']      ?? '';
$measuredValueRaw  = $_POST['measured_value']    ?? '';
$targetValueRaw    = $_POST['target_value']      ?? '';
$toleranceAbsRaw   = $_POST['tolerance_abs']     ?? '';
$tareValueRaw      = $_POST['tare_value']        ?? '';
$measuredAtRaw     = trim($_POST['measured_at']  ?? '');
$commentsRaw       = $_POST['comments']          ?? null;

// ── Validate required fields ──────────────────────────────────────────────────
$packagingIdFk = (int) $packagingIdFkRaw;
if ($packagingIdFk < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'packaging_id_fk invalide.']);
    exit;
}

$readingSeq = (int) $readingSeqRaw;
if ($readingSeq < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'reading_seq invalide (≥ 1 requis).']);
    exit;
}

try {
    $measureType = must_be_one_of('measure_type', $measureTypeRaw, ['weight', 'volume']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Type de mesure invalide (weight ou volume).']);
    exit;
}

$measuredValueStr = str_replace(',', '.', trim((string) $measuredValueRaw));
if (!is_numeric($measuredValueStr) || $measuredValueStr === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Valeur mesurée invalide.']);
    exit;
}
$measuredValue = $measuredValueStr;

$targetValue   = parse_nullable_decimal((string) $targetValueRaw);
$toleranceAbs  = parse_nullable_decimal((string) $toleranceAbsRaw);
$tareValue     = parse_nullable_decimal((string) $tareValueRaw);

if ($measuredAtRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $measuredAtRaw)) {
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

$comments = ($commentsRaw !== null && trim((string) $commentsRaw) !== '')
    ? trim((string) $commentsRaw)
    : null;

// ── Derive computed columns ───────────────────────────────────────────────────
$isConforming = null;
if ($targetValue !== null && $toleranceAbs !== null) {
    $isConforming = (abs((float) $measuredValue - (float) $targetValue) <= (float) $toleranceAbs) ? 1 : 0;
}

$submittedByUserIdFk = (int) $me['id'];

$rowHash = hash('sha256', $packagingIdFk . '|' . $readingSeq . '|' . $measuredAt);

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $pdo = maltytask_pdo();

    // Validate packaging_id_fk exists
    $chkStmt = $pdo->prepare('SELECT id FROM bd_packaging_v2 WHERE id = ? LIMIT 1');
    $chkStmt->execute([$packagingIdFk]);
    if ($chkStmt->fetch() === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'packaging_id_fk introuvable dans bd_packaging_v2.']);
        exit;
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO qa_net_content_readings
                 (packaging_id_fk, reading_seq, measure_type, measured_value,
                  target_value, tolerance_abs, is_conforming, tare_value,
                  measured_at, submitted_by_user_id_fk, comments, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $packagingIdFk,
            $readingSeq,
            $measureType,
            $measuredValue,
            $targetValue,
            $toleranceAbs,
            $isConforming,
            $tareValue,
            $measuredAt,
            $submittedByUserIdFk,
            $comments,
            $rowHash,
        ]);

        $insertedId = (int) $pdo->lastInsertId();

        $afterArr = [
            'packaging_id_fk'         => $packagingIdFk,
            'reading_seq'             => $readingSeq,
            'measure_type'            => $measureType,
            'measured_value'          => $measuredValue,
            'target_value'            => $targetValue,
            'tolerance_abs'           => $toleranceAbs,
            'is_conforming'           => $isConforming,
            'tare_value'              => $tareValue,
            'measured_at'             => $measuredAt,
            'submitted_by_user_id_fk' => $submittedByUserIdFk,
            'comments'                => $comments,
            'row_hash'                => $rowHash,
        ];

        log_revision($pdo, $me, 'qa_net_content_readings', $insertedId, null, $afterArr, 'normal', 'QA net content reading');

        echo json_encode([
            'ok'             => true,
            'id'             => $insertedId,
            'packaging_id_fk' => $packagingIdFk,
            'reading_seq'    => $readingSeq,
            'measure_type'   => $measureType,
            'measured_value' => $measuredValue,
            'target_value'   => $targetValue,
            'tolerance_abs'  => $toleranceAbs,
            'is_conforming'  => $isConforming,
            'tare_value'     => $tareValue,
            'measured_at'    => $measuredAt,
            'csrf'           => csrf_token(),
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Duplicate row_hash — idempotent re-submit, treat as success.
            $dupStmt = $pdo->prepare('SELECT id FROM qa_net_content_readings WHERE row_hash = ? LIMIT 1');
            $dupStmt->execute([$rowHash]);
            $existingId = (int) $dupStmt->fetchColumn();
            echo json_encode(['ok' => true, 'duplicate' => true, 'id' => $existingId, 'csrf' => csrf_token()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'qa-net-content')]);
}
