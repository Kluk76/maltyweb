<?php
declare(strict_types=1);
/**
 * POST /api/qa-cleaning-efficacy.php
 *
 * PRG handler — records one QA cleaning-efficacy check into qa_cleaning_efficacy_checks.
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
    flash_set('err', 'Date de contrôle invalide (format YYYY-MM-DD requis).');
    redirect_to('/?page=qa');
}
$checkDate = $checkDateRaw;

try {
    $method = must_be_one_of('method', $methodRaw, ['atp', 'swab', 'visual', 'rinse_water']);
} catch (RuntimeException $e) {
    flash_set('err', 'Méthode invalide (atp, swab, visual, rinse_water).');
    redirect_to('/?page=qa');
}

if ($surfaceLabelRaw === '') {
    flash_set('err', 'Le libellé de surface est obligatoire.');
    redirect_to('/?page=qa');
}
if (mb_strlen($surfaceLabelRaw) > 128) {
    flash_set('err', 'Libellé de surface trop long (max 128 caractères).');
    redirect_to('/?page=qa');
}
$surfaceLabel = $surfaceLabelRaw;

$cipEventIdFk = null;
if ($cipEventIdFkRaw !== '' && (int) $cipEventIdFkRaw > 0) {
    $cipEventIdFk = (int) $cipEventIdFkRaw;
}

$resultValue    = parse_nullable_decimal((string) $resultValueRaw);
$resultUnit     = ($resultUnitRaw !== '') ? $resultUnitRaw : null;
if ($resultUnit !== null && mb_strlen($resultUnit) > 16) {
    flash_set('err', 'Unité de résultat trop longue (max 16 caractères).');
    redirect_to('/?page=qa');
}
$thresholdValue = parse_nullable_decimal((string) $thresholdValueRaw);

try {
    $outcome = must_be_one_of('outcome', $outcomeRaw, ['pass', 'fail', 'marginal', 'pending']);
} catch (RuntimeException $e) {
    flash_set('err', 'Résultat invalide (pass, fail, marginal, pending).');
    redirect_to('/?page=qa');
}

$correctiveAction = ($correctiveActionRaw !== null && trim((string) $correctiveActionRaw) !== '')
    ? trim((string) $correctiveActionRaw)
    : null;

$measuredAt = null;
if ($measuredAtRaw !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $measuredAtRaw)) {
        flash_set('err', 'Date/heure de mesure invalide (format YYYY-MM-DD HH:MM requis).');
        redirect_to('/?page=qa');
    }
    $ts = strtotime($measuredAtRaw);
    if ($ts === false) {
        flash_set('err', 'Date/heure de mesure invalide.');
        redirect_to('/?page=qa');
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
$pdo = maltytask_pdo();

// Validate cip_event_id_fk if provided
if ($cipEventIdFk !== null) {
    $chkStmt = $pdo->prepare('SELECT id FROM bd_cip_events WHERE id = ? LIMIT 1');
    $chkStmt->execute([$cipEventIdFk]);
    if ($chkStmt->fetch() === false) {
        flash_set('err', 'cip_event_id_fk introuvable dans bd_cip_events.');
        redirect_to('/?page=qa');
    }
}

// ── INSERT ────────────────────────────────────────────────────────────────────
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

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate row_hash — idempotent re-submit, treat as success.
        flash_set('ok', 'Observation enregistrée.');
        redirect_to('/?page=qa');
    }
    flash_set('err', 'Erreur : ' . pdo_friendly_error($e, 'qa-cleaning-efficacy'));
    redirect_to('/?page=qa');
}

flash_set('ok', 'Observation enregistrée.');
redirect_to('/?page=qa');
