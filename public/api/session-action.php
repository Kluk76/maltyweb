<?php
declare(strict_types=1);
/**
 * POST /api/session-action.php
 *
 * JSON dispatch endpoint for session lifecycle actions.
 *
 * Request body (JSON):
 *   {
 *     "session_id": int,
 *     "action":     string,
 *     "csrf":       string,        // session CSRF token (embedded in JSON body)
 *     "payload":    object|null    // action-specific payload
 *   }
 *
 * Action → function dispatch table:
 *   advance_phase       → session_advance_phase($pdo, $sid, $payload['to_phase'], $me['id'], $payload)
 *   attest_cip          → session_attest_cip($pdo, $sid, $payload['cip_event_id'], $me['id'])
 *   attest_eligibility  → session_attest_eligibility($pdo, $sid, $me['id'], $payload)
 *   attest_firewall_qc  → session_attest_firewall($pdo, $sid, $me['id'], $payload)
 *   handover            → session_handover($pdo, $sid, $me['id'], $payload['to_user_id'], $payload['note'] ?? null)
 *   abandon             → session_abandon($pdo, $sid, $payload['reason'], $me['id'])
 *   add_note            → session_note($pdo, $sid, $me['id'], $payload['text'])
 *   recap_ack           → session_recap_ack($pdo, $sid, $me['id'], $payload['recap'] ?? [])
 *
 * Response:
 *   { "ok": true,  "session": { …op_sessions row… } }
 *   { "ok": false, "error": "…" }
 *
 * HTTP codes:
 *   200 — success
 *   400 — validation error / invalid action / bad CSRF
 *   403 — not authenticated
 *   405 — wrong method
 *   500 — unexpected server error
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sessions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
// JSON API: 403 not redirect — intentional. Do NOT use require_login() here
// (it issues a 302 that breaks fetch() callers). Pattern mirrors sf-*.php siblings.
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

// ── CSRF (token embedded in JSON body) ───────────────────────────────────────
// The session token lives in $_SESSION['csrf'] (set by csrf_token()).
// We verify the value submitted in the JSON body against the session value.
$postedCsrf = $data['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
    exit;
}

// ── Extract fields ────────────────────────────────────────────────────────────
// Read with ?? default FIRST, validate AFTER (feedback_php_query_param_validate_after_default).
$sid     = isset($data['session_id']) ? (int)$data['session_id'] : 0;
$action  = isset($data['action'])     ? (string)$data['action']  : '';
$payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

if ($sid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'session_id manquant ou invalide.']);
    exit;
}

// ── Action whitelist ──────────────────────────────────────────────────────────
const SA_ALLOWED_ACTIONS = [
    'advance_phase',
    'attest_cip',
    'attest_eligibility',
    'attest_firewall_qc',
    'handover',
    'abandon',
    'add_note',
    'recap_ack',
];

if ($action === '' || !in_array($action, SA_ALLOWED_ACTIONS, true)) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => "Action '{$action}' non reconnue. Actions valides : " . implode(', ', SA_ALLOWED_ACTIONS) . '.',
    ]);
    exit;
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {
    switch ($action) {

        case 'advance_phase':
            // payload: { to_phase: string, …additional… }
            $toPhase = isset($payload['to_phase']) ? (string)$payload['to_phase'] : '';
            if ($toPhase === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'advance_phase: payload.to_phase est requis.']);
                exit;
            }
            // Pass the full payload (minus to_phase) as optional extra payload.
            $extra = array_diff_key($payload, ['to_phase' => null]);
            session_advance_phase($pdo, $sid, $toPhase, (int)$me['id'], $extra ?: null);
            break;

        case 'attest_cip':
            // payload: { cip_event_id: int }
            $cipEventId = isset($payload['cip_event_id']) ? (int)$payload['cip_event_id'] : 0;
            if ($cipEventId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'attest_cip: payload.cip_event_id est requis (int > 0).']);
                exit;
            }
            session_attest_cip($pdo, $sid, $cipEventId, (int)$me['id']);
            break;

        case 'attest_eligibility':
            // payload: { lots: array, recipes: array }
            session_attest_eligibility($pdo, $sid, (int)$me['id'], $payload);
            break;

        case 'attest_firewall_qc':
            // payload: { predicate: string, passed: array, failed: array, thresholds_snapshot: array }
            session_attest_firewall($pdo, $sid, (int)$me['id'], $payload);
            break;

        case 'handover':
            // payload: { to_user_id: int, note?: string|null }
            $toUserId    = isset($payload['to_user_id']) ? (int)$payload['to_user_id'] : 0;
            $handoverNote = isset($payload['note']) && $payload['note'] !== null
                ? (string)$payload['note']
                : null;
            if ($toUserId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'handover: payload.to_user_id est requis (int > 0).']);
                exit;
            }
            session_handover($pdo, $sid, (int)$me['id'], $toUserId, $handoverNote);
            break;

        case 'abandon':
            // payload: { reason: string }
            $reason = isset($payload['reason']) ? trim((string)$payload['reason']) : '';
            if ($reason === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'abandon: payload.reason ne peut pas être vide.']);
                exit;
            }
            session_abandon($pdo, $sid, $reason, (int)$me['id']);
            break;

        case 'add_note':
            // payload: { text: string }
            $text = isset($payload['text']) ? trim((string)$payload['text']) : '';
            if ($text === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'add_note: payload.text ne peut pas être vide.']);
                exit;
            }
            session_note($pdo, $sid, (int)$me['id'], $text);
            break;

        case 'recap_ack':
            // payload: { recap?: object }
            $recapFields = isset($payload['recap']) && is_array($payload['recap']) ? $payload['recap'] : [];
            session_recap_ack($pdo, $sid, (int)$me['id'], ['fields' => $recapFields]);
            break;
    }

    // ── Success: return updated session row ───────────────────────────────────
    // Flush the session_for_id cache by calling with the real PDO (memoized per request).
    // Since this is the same request that performed the write, the cache still holds the
    // pre-write row. Bypass it by fetching directly.
    $stmt = $pdo->prepare(
        "SELECT s.*,
                u_open.username  AS opened_by_username,
                u_close.username AS closed_by_username
           FROM op_sessions s
           LEFT JOIN users u_open  ON u_open.id  = s.opened_by_fk
           LEFT JOIN users u_close ON u_close.id = s.closed_by_fk
          WHERE s.id = ?
          LIMIT 1"
    );
    $stmt->execute([$sid]);
    $updatedSession = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'ok'      => true,
        'session' => $updatedSession ?: null,
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur inattendue : ' . $e->getMessage()]);
}
