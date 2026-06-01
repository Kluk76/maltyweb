<?php
declare(strict_types=1);
/**
 * GET /api/session-ping.php
 *
 * Read-only keepalive endpoint. Touching any authenticated endpoint resets
 * the 30-min idle clock in current_user() via $_SESSION['last_activity'].
 * This endpoint does exactly that — no DB write, no log_revision, no mutation.
 *
 * Response (200):
 *   { "ok": true, "csrf": "<current_csrf_token>", "expires_in": 1800 }
 *
 * On auth failure: 401 JSON (never a 302 redirect — this is a fetch() target).
 * The mt_remember cookie can rebuild a destroyed session with a FRESH csrf token,
 * so the response always returns the current token and the client must rewrite
 * every input[name="csrf"] on the page from it.
 *
 * Cache-Control: no-store — proxies must never cache this.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// current_user() starts/touches the session — this is the keepalive mechanism.
// It also handles the mt_remember cookie path if the session was destroyed.
$me = current_user();

if ($me === null) {
    // Session truly expired AND no valid remember-me cookie.
    // Return 401 JSON so JS can show a non-disruptive warning.
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'expired']);
    exit;
}

// Session is alive (or just rebuilt from remember-me).
// csrf_token() mints a new token if the session was just rebuilt.
echo json_encode([
    'ok'         => true,
    'csrf'       => csrf_token(),
    'expires_in' => MALTYTASK_SESSION_IDLE_MAX,
], JSON_UNESCAPED_UNICODE);
