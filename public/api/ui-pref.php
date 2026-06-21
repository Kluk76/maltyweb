<?php
declare(strict_types=1);
/**
 * POST /api/ui-pref.php — Per-user UI preference writer.
 *
 * Accepts form POST: { csrf, key, value }
 *   key   ∈ { sku_class_filter }
 *   value ∈ { all, Neb, Contract }   (for sku_class_filter)
 *
 * Persists the preference via user_ui_prefs (mig 430); no audit log needed
 * (user self-service pref, not a business entity).
 *
 * Response: { ok:true }
 *         | { ok:false, error:'…' }
 *         | { ok:false, reason:'expired', csrf:'…' }  — CSRF retry hint
 *
 * HTTP: 200 success, 400 bad input/CSRF, 403 unauth, 405 wrong method, 500 error.
 *
 * Auth: any logged-in user (current_user() check — no redirect, returns 403).
 * CSRF: read from $_POST['csrf'] (form data, not JSON body).
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/user-prefs.php';

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

// ── CSRF — first gate; on fail return fresh token for one retry ───────────────
$postedCsrf = $_POST['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
    exit;
}

// ── Read + whitelist key ──────────────────────────────────────────────────────
$key         = isset($_POST['key']) ? (string) $_POST['key'] : '';
$allowedKeys = ['sku_class_filter'];

if (!in_array($key, $allowedKeys, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Clé non autorisée.']);
    exit;
}

// ── Whitelist value for sku_class_filter ──────────────────────────────────────
$value         = isset($_POST['value']) ? (string) $_POST['value'] : '';
$allowedValues = ['all', 'Neb', 'Contract'];

if (!in_array($value, $allowedValues, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Valeur non autorisée.']);
    exit;
}

// ── DB write ──────────────────────────────────────────────────────────────────
$userId = (int) $me['id'];
$pdo    = maltytask_pdo();

try {
    user_pref_set($pdo, $userId, $key, $value);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[ui-pref] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => pdo_friendly_error($e, 'ui-pref')]);
}
