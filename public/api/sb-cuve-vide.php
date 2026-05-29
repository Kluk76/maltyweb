<?php
declare(strict_types=1);
/**
 * GET  /api/sb-cuve-vide.php  — dry-run preview: which mothers would close
 * POST /api/sb-cuve-vide.php  — apply: close mothers + record reason
 *
 * Operator-level (no admin required — PM ruling: only force-close is admin-only).
 *
 * GET params:  vessel_kind, vessel_number
 * POST params: csrf, vessel_kind, vessel_number, reason, note (optional)
 *
 * GET response:
 *   { "ok": true, "mode": "dry-run",
 *     "mothers": […], "tanksim_empty": bool, "tanksim_note": "…" }
 *
 * POST response:
 *   { "ok": true, "mode": "apply",
 *     "closed_mothers": […], "kept_open": […], "errors": […] }
 *
 * Error responses:
 *   302  (require_login redirect — not logged in)
 *   400  { "ok": false, "error": "csrf-invalid" | "missing-param" | "invalid-vessel-kind"
 *                                | "invalid-vessel-number" | "invalid-reason" }
 *   405  { "ok": false, "error": "method-not-allowed" }
 *   500  { "ok": false, "error": "internal" }
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/mother-shell.php';
require_once __DIR__ . '/../../app/sessions.php'; // for SESSION_VESSEL_KINDS

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_login();

$pdo    = maltytask_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// CV_VESSEL_KINDS reuses SESSION_VESSEL_KINDS from app/sessions.php (single source of truth).
// Do NOT redefine a divergent whitelist here — FIX 2.

/** Allowed close reasons. */
const CV_REASONS = ['emballe', 'jete', 'vendu_mout', 'encore_en_cuve'];

/**
 * Validate and parse vessel_kind + vessel_number from a param array (GET or POST).
 * Returns [vessel_kind, vessel_number] or calls http_response_code + exit on error.
 */
function cv_parse_vessel(array $params): array
{
    $rawKind   = $params['vessel_kind']   ?? null;
    $rawNumber = $params['vessel_number'] ?? null;

    if ($rawKind === null || $rawKind === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing-param',
                          'detail' => 'vessel_kind requis'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($rawKind, SESSION_VESSEL_KINDS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid-vessel-kind',
                          'detail' => "vessel_kind '{$rawKind}' non reconnu. Valeurs: "
                                      . implode(', ', SESSION_VESSEL_KINDS)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($rawNumber === null || $rawNumber === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing-param',
                          'detail' => 'vessel_number requis'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $vesselNumber = filter_var($rawNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($vesselNumber === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid-vessel-number',
                          'detail' => "vessel_number doit être un entier > 0"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return [$rawKind, (int)$vesselNumber];
}

// ── GET — dry-run preview (always safe, no mutation) ──────────────────────────
if ($method === 'GET') {
    [$vesselKind, $vesselNumber] = cv_parse_vessel($_GET);

    try {
        $preview = cuve_vide_pressed($pdo, $vesselKind, $vesselNumber);
        echo json_encode(
            array_merge(['ok' => true, 'mode' => 'dry-run'], $preview),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log('[sb-cuve-vide GET] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── POST — apply (operator-confirmed, CSRF required) ──────────────────────────
if ($method === 'POST') {
    // CSRF must be verified before any other param parsing.
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'csrf-invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    [$vesselKind, $vesselNumber] = cv_parse_vessel($_POST);

    $reason = $_POST['reason'] ?? '';
    if (!in_array($reason, CV_REASONS, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid-reason',
                          'detail' => "reason '{$reason}' invalide. Valeurs: "
                                      . implode(', ', CV_REASONS)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }

    $note = isset($_POST['note']) && $_POST['note'] !== '' ? trim($_POST['note']) : null;

    try {
        $result = cuve_vide_apply($pdo, $vesselKind, $vesselNumber, $reason, $note);
        echo json_encode(
            array_merge(['ok' => true, 'mode' => 'apply'], $result),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log('[sb-cuve-vide POST] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── Anything else ─────────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method-not-allowed'], JSON_UNESCAPED_UNICODE);
