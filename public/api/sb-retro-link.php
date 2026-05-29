<?php
declare(strict_types=1);
/**
 * GET  /api/sb-retro-link.php  — dry-run proposal list (admin only, no CSRF needed)
 * POST /api/sb-retro-link.php  — apply proposals (admin only, CSRF + apply=1 required)
 *
 * Admin-only endpoint for the one-shot retro-link resolver.
 *
 * GET response:
 *   { "ok": true, "mode": "dry-run", "proposals": […] }
 *
 * POST (apply=1) response:
 *   { "ok": true, "mode": "apply", "result": { "applied": N, "skipped": N, "errors": […] } }
 *
 * Error responses:
 *   403  { "ok": false, "error": "admin-required" }
 *   400  { "ok": false, "error": "csrf-invalid" | "apply-required-1" }
 *   405  { "ok": false, "error": "method-not-allowed" }
 *
 * GOVERNANCE: This endpoint proposes actions; the operator confirms via POST+apply=1.
 * Never auto-calls apply. Default GET is always safe (pure read).
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sb-board.php';
require_once __DIR__ . '/../../app/sb-retro-link.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_login();

$me = current_user();

// Admin-only gate — retro-link apply is a destructive action.
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'admin-required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo    = maltytask_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ── GET — dry-run preview (always safe) ───────────────────────────────────────
if ($method === 'GET') {
    try {
        $proposals = sb_retro_link_propose($pdo, []);
        echo json_encode(
            ['ok' => true, 'mode' => 'dry-run', 'proposals' => $proposals],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log('[sb-retro-link GET] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── POST — apply (operator-confirmed, CSRF required) ──────────────────────────
if ($method === 'POST') {
    // CSRF verification — must pass before any mutation.
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'csrf-invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Explicit opt-in: caller must post apply=1 to confirm intent.
    if (($_POST['apply'] ?? '') !== '1') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'apply-required-1'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $proposals = sb_retro_link_propose($pdo, []);
        $result    = sb_retro_link_apply($pdo, $proposals);
        echo json_encode(
            ['ok' => true, 'mode' => 'apply', 'result' => $result],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log('[sb-retro-link POST] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── Anything else ─────────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method-not-allowed'], JSON_UNESCAPED_UNICODE);
