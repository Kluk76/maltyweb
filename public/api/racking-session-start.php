<?php
declare(strict_types=1);
/**
 * POST /api/racking-session-start.php
 *
 * Opens a new racking session and returns the redirect URL.
 *
 * Used by the "Démarrer (session)" button on saisies.php.
 * Calls session_open() then redirects to session-shell.php?id={new_id}.
 *
 * Request body (JSON):
 *   {
 *     "csrf":       string,
 *     "vessel_kind":   string|null,   (optional, e.g. "cct")
 *     "vessel_number": int|null       (optional, the CCT number if known at start)
 *   }
 *
 * Response:
 *   { "ok": true, "redirect": "/modules/session-shell.php?id=N" }
 *   { "ok": false, "error": "…" }
 *
 * HTTP codes:
 *   200 — session opened, redirect URL returned
 *   400 — CSRF invalid / bad input
 *   403 — not authenticated
 *   405 — wrong method
 *   500 — unexpected error
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sessions.php';
require_once __DIR__ . '/../../app/mother-shell.php';

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

// ── Decode JSON body ──────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$postedCsrf = $data['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
    exit;
}

// ── Build session context ─────────────────────────────────────────────────────
$ctx = ['form_type' => 'racking'];

// Optional vessel pre-selection (operator may choose CCT before entering the session shell).
$vesselKind   = isset($data['vessel_kind'])   ? trim((string)$data['vessel_kind'])   : null;
$vesselNumber = isset($data['vessel_number']) ? (int)$data['vessel_number']          : null;

if ($vesselKind !== null && $vesselKind !== '' && $vesselNumber !== null && $vesselNumber > 0) {
    $ctx['vessel_kind']   = $vesselKind;
    $ctx['vessel_number'] = $vesselNumber;
}

// ── Open session ──────────────────────────────────────────────────────────────
try {
    $pdo       = maltytask_pdo();
    $sessionId = session_open($pdo, $ctx, (int)$me['id']);

    // ── Auto-link to mother (Phase 1) ─────────────────────────────────────────
    // Racking session-start does not carry recipe_id_fk/batch — link fires only
    // if the caller includes them in the request (future pilot 6 path). For now
    // this is always a graceful no-op. Failure must NOT block session open.
    $linkRecipeId = isset($data['recipe_id_fk']) ? (int)$data['recipe_id_fk'] : 0;
    $linkBatch    = isset($data['batch']) ? trim((string)$data['batch']) : '';
    if ($linkRecipeId > 0 && $linkBatch !== '') {
        try {
            link_daily_to_mother($pdo, $sessionId, $linkRecipeId, $linkBatch);
        } catch (Throwable $_linkErr) {
            // Non-fatal — log warning; session already open.
            error_log('[mother-shell] link_daily_to_mother (racking session=' . $sessionId
                . '): ' . $_linkErr->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode([
        'ok'       => true,
        'redirect' => '/modules/session-shell.php?id=' . $sessionId,
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur inattendue : ' . $e->getMessage()]);
}
