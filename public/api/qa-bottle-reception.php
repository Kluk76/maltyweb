<?php
declare(strict_types=1);
/**
 * POST /api/qa-bottle-reception.php
 *
 * PRG handler — records one QA bottle-reception check into qa_bottle_reception_checks.
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
    flash_set('err', 'Date de réception invalide (format YYYY-MM-DD requis).');
    redirect_to('/?page=qa');
}
$receptionDate = $receptionDateRaw;

$lotRef = ($lotRefRaw !== '') ? $lotRefRaw : null;
if ($lotRef !== null && mb_strlen($lotRef) > 64) {
    flash_set('err', 'Référence de lot trop longue (max 64 caractères).');
    redirect_to('/?page=qa');
}

try {
    $measureType = must_be_one_of('measure_type', $measureTypeRaw, ['weight', 'volume']);
} catch (RuntimeException $e) {
    flash_set('err', 'Type de mesure invalide (weight ou volume).');
    redirect_to('/?page=qa');
}

$sampleSize = null;
if ($sampleSizeRaw !== '' && (int) $sampleSizeRaw > 0) {
    $sampleSize = (int) $sampleSizeRaw;
}

$measuredValueStr = str_replace(',', '.', trim((string) $measuredValueRaw));
if (!is_numeric($measuredValueStr) || $measuredValueStr === '') {
    flash_set('err', 'Valeur mesurée invalide.');
    redirect_to('/?page=qa');
}
$measuredValue = $measuredValueStr;

$targetValue  = parse_nullable_decimal((string) $targetValueRaw);
$toleranceAbs = parse_nullable_decimal((string) $toleranceAbsRaw);

try {
    $outcome = must_be_one_of('outcome', $outcomeRaw, ['pass', 'fail', 'marginal']);
} catch (RuntimeException $e) {
    flash_set('err', 'Résultat invalide (pass, fail, marginal).');
    redirect_to('/?page=qa');
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
$pdo = maltytask_pdo();

// Validate delivery_id_fk if provided
if ($deliveryIdFk !== null) {
    $chkStmt = $pdo->prepare('SELECT id FROM inv_deliveries WHERE id = ? LIMIT 1');
    $chkStmt->execute([$deliveryIdFk]);
    if ($chkStmt->fetch() === false) {
        flash_set('err', 'delivery_id_fk introuvable dans inv_deliveries.');
        redirect_to('/?page=qa');
    }
}

// Validate mi_id_fk if provided
if ($miIdFk !== null) {
    $chkStmt = $pdo->prepare('SELECT id FROM ref_mi WHERE id = ? LIMIT 1');
    $chkStmt->execute([$miIdFk]);
    if ($chkStmt->fetch() === false) {
        flash_set('err', 'mi_id_fk introuvable dans ref_mi.');
        redirect_to('/?page=qa');
    }
}

// ── INSERT ────────────────────────────────────────────────────────────────────
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

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate row_hash — idempotent re-submit, treat as success.
        flash_set('ok', 'Observation enregistrée.');
        redirect_to('/?page=qa');
    }
    flash_set('err', 'Erreur : ' . pdo_friendly_error($e, 'qa-bottle-reception'));
    redirect_to('/?page=qa');
}

flash_set('ok', 'Observation enregistrée.');
redirect_to('/?page=qa');
