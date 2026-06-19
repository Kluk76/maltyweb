<?php
declare(strict_types=1);
/**
 * POST /api/qa-water-analysis.php
 *
 * Async JSON handler — records one QA water-analysis measurement into qa_water_analysis.
 * Idempotent: duplicate row_hash (SQLSTATE 23000) is treated as success.
 * NON-FISCAL observation row — never feeds COGS/stock.
 *
 * Request (POST body):
 *   csrf               — session CSRF token
 *   sample_point_id_fk — INT > 0 (required; must exist and be is_active=1 in ref_water_sample_points)
 *   parameter_id_fk    — INT > 0 (required; must exist and be is_active=1 in ref_water_parameters)
 *   measured_value     — decimal string (optional for numeric operators; required for lte/gte/range)
 *   measured_text      — string (optional; required for presence_absence; expected 'absence'/'présence')
 *   sampled_at         — 'YYYY-MM-DDTHH:MM' or 'YYYY-MM-DD HH:MM:SS' (required)
 *   lab_name           — string|'' (optional)
 *   method             — string|'' (optional, measurement method)
 *   report_ref         — string|'' (optional)
 *   comments           — string|'' (optional, max 1000)
 *
 * Response 200 OK (success):
 *   { ok: true, id, is_conforming, action_limit, csrf: <fresh token> }
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
$samplePointIdFkRaw = $_POST['sample_point_id_fk'] ?? '';
$parameterIdFkRaw   = $_POST['parameter_id_fk']    ?? '';
$measuredValueRaw   = $_POST['measured_value']      ?? '';
$measuredTextRaw    = $_POST['measured_text']       ?? '';
$sampledAtRaw       = trim($_POST['sampled_at']     ?? '');
$labNameRaw         = trim($_POST['lab_name']       ?? '');
$methodRaw          = trim($_POST['method']         ?? '');
$reportRefRaw       = trim($_POST['report_ref']     ?? '');
$commentsRaw        = $_POST['comments']            ?? null;

// ── Validate required FK ids ──────────────────────────────────────────────────
$samplePointIdFk = (int) $samplePointIdFkRaw;
if ($samplePointIdFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'sample_point_id_fk invalide (entier positif requis).']);
    exit;
}

$parameterIdFk = (int) $parameterIdFkRaw;
if ($parameterIdFk <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'parameter_id_fk invalide (entier positif requis).']);
    exit;
}

// ── Validate sampled_at ───────────────────────────────────────────────────────
if ($sampledAtRaw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La date/heure de prélèvement (sampled_at) est obligatoire.']);
    exit;
}
// Accept 'YYYY-MM-DDTHH:MM' (datetime-local) or 'YYYY-MM-DD HH:MM:SS'
if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $sampledAtRaw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Format sampled_at invalide (YYYY-MM-DDTHH:MM ou YYYY-MM-DD HH:MM:SS requis).']);
    exit;
}
$ts = strtotime($sampledAtRaw);
if ($ts === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Date/heure de prélèvement invalide.']);
    exit;
}
$sampledAt = date('Y-m-d H:i:s', $ts);

// ── Optional string fields ────────────────────────────────────────────────────
$labName   = ($labNameRaw   !== '') ? $labNameRaw   : null;
$method    = ($methodRaw    !== '') ? $methodRaw    : null;
$reportRef = ($reportRefRaw !== '') ? $reportRefRaw : null;

$comments = null;
if ($commentsRaw !== null && trim((string) $commentsRaw) !== '') {
    $comments = trim((string) $commentsRaw);
    if (mb_strlen($comments) > 1000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Commentaires trop longs (max 1000 caractères).']);
        exit;
    }
}

// ── DB — load and validate parameter + sample_point ──────────────────────────
try {
    $pdo = maltytask_pdo();

    // Validate sample_point exists and is active
    $spStmt = $pdo->prepare(
        'SELECT id, code, label, is_active FROM ref_water_sample_points WHERE id = ? LIMIT 1'
    );
    $spStmt->execute([$samplePointIdFk]);
    $samplePoint = $spStmt->fetch(PDO::FETCH_ASSOC);
    if ($samplePoint === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'sample_point_id_fk introuvable dans ref_water_sample_points.']);
        exit;
    }
    if (!(bool) $samplePoint['is_active']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le point de prélèvement sélectionné est inactif.']);
        exit;
    }

    // Load parameter row (need limit_operator + limits + unit for derivation)
    $paramStmt = $pdo->prepare(
        'SELECT id, code, label, unit, limit_operator, limit_min, limit_max, limit_basis, is_active
           FROM ref_water_parameters WHERE id = ? LIMIT 1'
    );
    $paramStmt->execute([$parameterIdFk]);
    $param = $paramStmt->fetch(PDO::FETCH_ASSOC);
    if ($param === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'parameter_id_fk introuvable dans ref_water_parameters.']);
        exit;
    }
    if (!(bool) $param['is_active']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le paramètre sélectionné est inactif.']);
        exit;
    }

    // ── Derivation — unit, action_limit, is_conforming, measured_value/text ──
    $limitOperator = (string) $param['limit_operator'];
    $limitMin      = $param['limit_min'] !== null  ? (float) $param['limit_min']  : null;
    $limitMax      = $param['limit_max'] !== null  ? (float) $param['limit_max']  : null;
    $limitBasis    = $param['limit_basis'] !== null ? trim((string) $param['limit_basis']) : null;
    $paramUnit     = (string) $param['unit'];

    // Snapshot unit from parameter (stored alongside the measurement for audit stability)
    $unitSnapshot = $paramUnit;

    $measuredValue = null;
    $measuredText  = null;
    $isConforming  = null;
    $actionLimit   = null;

    if ($limitOperator === 'presence_absence') {
        // ── presence_absence branch ───────────────────────────────────────────
        $rawText = trim((string) $measuredTextRaw);
        if ($rawText === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'measured_text est requis pour un paramètre présence/absence.']);
            exit;
        }
        $measuredText = $rawText;
        $measuredValue = null; // stays NULL

        // Determine conformance: absence-denoting values → conforming
        $normalized = mb_strtolower($rawText);
        $absenceSet = ['absence', 'absent', 'conforme', 'nd', 'non détecté', 'non detecte', '<1', '0'];
        $presenceSet = ['présence', 'presence', 'présent', 'present', 'detecté', 'detecte', 'positif'];

        if (in_array($normalized, $absenceSet, true)) {
            $isConforming = 1;
        } elseif (in_array($normalized, $presenceSet, true) || $normalized !== '') {
            $isConforming = 0;
        }
        // (empty after normalization would be caught above, so isConforming is always set here)

        // action_limit: derive from limit_basis if it mentions a count, else "Absence"
        if ($limitBasis !== null && $limitBasis !== '') {
            $actionLimit = mb_substr($limitBasis, 0, 120);
        } else {
            $actionLimit = 'Absence';
        }

        $unitSnapshot = null; // presence/absence has no numeric unit

    } elseif ($limitOperator === 'lte') {
        // ── lte branch ────────────────────────────────────────────────────────
        $measuredValue = parse_nullable_decimal((string) $measuredValueRaw);
        if ($measuredValue === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'valeur mesurée requise pour un paramètre de type ≤.']);
            exit;
        }
        if ($limitMax !== null) {
            $isConforming = ((float) $measuredValue <= $limitMax) ? 1 : 0;
            $actionLimit  = '≤ ' . rtrim(rtrim(number_format($limitMax, 4), '0'), '.') . ' ' . $paramUnit;
        } else {
            $isConforming = null;
            $actionLimit  = ($limitBasis !== null && $limitBasis !== '')
                ? mb_substr($limitBasis, 0, 120)
                : null;
        }

    } elseif ($limitOperator === 'gte') {
        // ── gte branch ────────────────────────────────────────────────────────
        $measuredValue = parse_nullable_decimal((string) $measuredValueRaw);
        if ($measuredValue === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'valeur mesurée requise pour un paramètre de type ≥.']);
            exit;
        }
        if ($limitMin !== null) {
            $isConforming = ((float) $measuredValue >= $limitMin) ? 1 : 0;
            $actionLimit  = '≥ ' . rtrim(rtrim(number_format($limitMin, 4), '0'), '.') . ' ' . $paramUnit;
        } else {
            $isConforming = null;
            $actionLimit  = ($limitBasis !== null && $limitBasis !== '')
                ? mb_substr($limitBasis, 0, 120)
                : null;
        }

    } elseif ($limitOperator === 'range') {
        // ── range branch ──────────────────────────────────────────────────────
        $measuredValue = parse_nullable_decimal((string) $measuredValueRaw);
        if ($measuredValue === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'valeur mesurée requise pour un paramètre de type intervalle.']);
            exit;
        }
        if ($limitMin !== null && $limitMax !== null) {
            $v = (float) $measuredValue;
            $isConforming = ($v >= $limitMin && $v <= $limitMax) ? 1 : 0;
            $minFmt = rtrim(rtrim(number_format($limitMin, 4), '0'), '.');
            $maxFmt = rtrim(rtrim(number_format($limitMax, 4), '0'), '.');
            $actionLimit = $minFmt . '–' . $maxFmt . ' ' . $paramUnit;
        } else {
            $isConforming = null;
            $actionLimit  = ($limitBasis !== null && $limitBasis !== '')
                ? mb_substr($limitBasis, 0, 120)
                : null;
        }

    } else {
        // Unknown limit_operator — should never happen if DB ENUM is enforced
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'limit_operator inconnu : ' . $limitOperator]);
        exit;
    }

    // ── row_hash (idempotency) ────────────────────────────────────────────────
    $rowHash = hash('sha256', implode('|', [
        $samplePointIdFk,
        $parameterIdFk,
        $sampledAt,
        $measuredValue ?? ($measuredText ?? ''),
        $reportRef ?? '',
    ]));

    // ── INSERT ────────────────────────────────────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO qa_water_analysis
                 (sample_point_id_fk, parameter_id_fk, measured_value, measured_text,
                  unit, action_limit, is_conforming, lab_name, method, sampled_at,
                  report_ref, comments, row_hash, created_by_fk)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $samplePointIdFk,
            $parameterIdFk,
            $measuredValue,
            $measuredText,
            $unitSnapshot,
            $actionLimit,
            $isConforming,
            $labName,
            $method,
            $sampledAt,
            $reportRef,
            $comments,
            $rowHash,
            (int) $me['id'],
        ]);

        $insertedId = (int) $pdo->lastInsertId();

        $afterArr = [
            'sample_point_id_fk' => $samplePointIdFk,
            'parameter_id_fk'    => $parameterIdFk,
            'measured_value'     => $measuredValue,
            'measured_text'      => $measuredText,
            'unit'               => $unitSnapshot,
            'action_limit'       => $actionLimit,
            'is_conforming'      => $isConforming,
            'lab_name'           => $labName,
            'method'             => $method,
            'sampled_at'         => $sampledAt,
            'report_ref'         => $reportRef,
            'comments'           => $comments,
            'row_hash'           => $rowHash,
            'created_by_fk'      => (int) $me['id'],
        ];

        log_revision($pdo, $me, 'qa_water_analysis', $insertedId, null, $afterArr, 'normal', 'QA water analysis');

        echo json_encode([
            'ok'           => true,
            'id'           => $insertedId,
            'is_conforming' => $isConforming,
            'action_limit' => $actionLimit,
            'csrf'         => csrf_token(),
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Duplicate row_hash — idempotent re-submit, treat as success.
            $dupStmt = $pdo->prepare('SELECT id FROM qa_water_analysis WHERE row_hash = ? LIMIT 1');
            $dupStmt->execute([$rowHash]);
            $existingId = (int) $dupStmt->fetchColumn();
            echo json_encode(['ok' => true, 'duplicate' => true, 'id' => $existingId, 'csrf' => csrf_token()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . pdo_friendly_error($e, 'qa-water-analysis')]);
}
