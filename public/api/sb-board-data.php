<?php
declare(strict_types=1);
/**
 * GET /api/sb-board-data.php
 *
 * JSON polling endpoint for the mother-shell board (Atom 6 JS driver).
 * Returns the same data shape as sb_open_mothers() + sb_recent_closed_mothers().
 *
 * Response shape:
 *   { "ok": true, "mothers": {…zones…}, "closed_strip": […], "fetched_at": "YYYY-MM-DD HH:MM:SS" }
 *   { "ok": false, "error": "internal" }   ← on server error (no stack trace leaked)
 *
 * Auth: session only (require_login → 302 to /login.php).
 * Cache: no-store — live data, 15 s polling interval from JS driver.
 * CSRF: none — GET is read-only.
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/sb-board.php';

require_login();

$pdo = maltytask_pdo();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $payload = [
        'mothers'      => sb_open_mothers($pdo),
        'closed_strip' => sb_recent_closed_mothers($pdo, 3),
        'fetched_at'   => date('Y-m-d H:i:s'),
        'ok'           => true,
    ];
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[sb-board-data] ' . $e->getMessage());
    $payload = ['ok' => false, 'error' => 'internal'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
