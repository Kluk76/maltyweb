<?php
declare(strict_types=1);
/**
 * POST /api/qa-net-content.php
 *
 * PRG handler — records one QA net-content reading into qa_net_content_readings.
 * Idempotent: duplicate row_hash (SQLSTATE 23000) is treated as success.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

header('Cache-Control: no-store');

// ── Auth ──────────────────────────────────────────────────────────────────────
require_page_access('qa');
$me = current_user();

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Méthode non autorisée.';
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    flash_set('err', 'Session expirée — recharge la page.');
    redirect_to('/?page=qa');
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
    flash_set('err', 'packaging_id_fk invalide.');
    redirect_to('/?page=qa');
}

$readingSeq = (int) $readingSeqRaw;
if ($readingSeq < 1) {
    flash_set('err', 'reading_seq invalide (≥ 1 requis).');
    redirect_to('/?page=qa');
}

try {
    $measureType = must_be_one_of('measure_type', $measureTypeRaw, ['weight', 'volume']);
} catch (RuntimeException $e) {
    flash_set('err', 'Type de mesure invalide (weight ou volume).');
    redirect_to('/?page=qa');
}

$measuredValueStr = str_replace(',', '.', trim((string) $measuredValueRaw));
if (!is_numeric($measuredValueStr) || $measuredValueStr === '') {
    flash_set('err', 'Valeur mesurée invalide.');
    redirect_to('/?page=qa');
}
$measuredValue = $measuredValueStr;

$targetValue   = parse_nullable_decimal((string) $targetValueRaw);
$toleranceAbs  = parse_nullable_decimal((string) $toleranceAbsRaw);
$tareValue     = parse_nullable_decimal((string) $tareValueRaw);

if ($measuredAtRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $measuredAtRaw)) {
    flash_set('err', 'Date/heure de mesure invalide (format YYYY-MM-DD HH:MM requis).');
    redirect_to('/?page=qa');
}
$ts = strtotime($measuredAtRaw);
if ($ts === false) {
    flash_set('err', 'Date/heure de mesure invalide.');
    redirect_to('/?page=qa');
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
$pdo = maltytask_pdo();

// Validate packaging_id_fk exists
$chkStmt = $pdo->prepare('SELECT id FROM bd_packaging_v2 WHERE id = ? LIMIT 1');
$chkStmt->execute([$packagingIdFk]);
if ($chkStmt->fetch() === false) {
    flash_set('err', 'packaging_id_fk introuvable dans bd_packaging_v2.');
    redirect_to('/?page=qa');
}

// ── INSERT ────────────────────────────────────────────────────────────────────
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
        'packaging_id_fk'       => $packagingIdFk,
        'reading_seq'           => $readingSeq,
        'measure_type'          => $measureType,
        'measured_value'        => $measuredValue,
        'target_value'          => $targetValue,
        'tolerance_abs'         => $toleranceAbs,
        'is_conforming'         => $isConforming,
        'tare_value'            => $tareValue,
        'measured_at'           => $measuredAt,
        'submitted_by_user_id_fk' => $submittedByUserIdFk,
        'comments'              => $comments,
        'row_hash'              => $rowHash,
    ];

    log_revision($pdo, $me, 'qa_net_content_readings', $insertedId, null, $afterArr, 'normal', 'QA net content reading');

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate row_hash — idempotent re-submit, treat as success.
        flash_set('ok', 'Observation enregistrée.');
        redirect_to('/?page=qa');
    }
    flash_set('err', 'Erreur : ' . pdo_friendly_error($e, 'qa-net-content'));
    redirect_to('/?page=qa');
}

flash_set('ok', 'Observation enregistrée.');
redirect_to('/?page=qa');
