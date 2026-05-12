<?php
declare(strict_types=1);

/**
 * POST /api/triage/accept.php
 *
 * Accept (acknowledge) a triage row without further action needed.
 * Used for invoice-no-dn, dn-no-invoice, photonote-audit rows.
 *
 * Payload:
 *   csrf   — session token (required)
 *   rq_id  — doc_review_queue.id (required)
 *   note   — optional operator note
 */

require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/csrf.php';
require __DIR__ . '/../../../app/services/rate_limit.php';
require __DIR__ . '/../../../app/services/triage_actions.php';

require_login();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /modules/triage.php?tab=docs');
    exit;
}

if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Token CSRF invalide.'];
    $back = '/modules/triage.php?tab=docs' . (isset($_POST['rq_id']) ? '&rq_id=' . (int)$_POST['rq_id'] : '');
    header('Location: ' . $back, true, 303);
    exit;
}

$pdo = maltytask_pdo();

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
if (!rl_check_and_log((int)$me['id'], 'triage_action', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Limite de requêtes atteinte (200/h).'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

$rqId = isset($_POST['rq_id']) ? (int)$_POST['rq_id'] : 0;
$note = trim($_POST['note'] ?? '');

if ($rqId <= 0) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'rq_id invalide.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

try {
    $rqStmt = $pdo->prepare(
        "SELECT id, type, status FROM doc_review_queue WHERE id = ? LIMIT 1"
    );
    $rqStmt->execute([$rqId]);
    $rqRow = $rqStmt->fetch();

    if (!$rqRow || $rqRow['status'] !== 'open') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Ligne introuvable ou déjà résolue.'];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status          = 'resolved',
                    decision        = 'accept',
                    decided_at      = NOW(),
                    decided_by      = ?,
                    resolution_note = ?,
                    updated_at      = NOW()
              WHERE id = ?"
        );
        $stmt->execute([$me['username'], $note !== '' ? $note : null, $rqId]);
    } catch (PDOException $colEx) {
        // resolution_note column may not exist in older schema
        $stmt2 = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status     = 'resolved',
                    decision   = 'accept',
                    decided_at = NOW(),
                    decided_by = ?,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $stmt2->execute([$me['username'], $rqId]);
    }

    $pdo->commit();

    $_SESSION['triage_flash'] = ['type' => 'ok', 'msg' => 'Accepté. Ligne fermée.'];
    $redirectUrl = ta_redirect_url($rqId, true, $pdo);
    header('Location: ' . $redirectUrl, true, 303);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Erreur : ' . $e->getMessage()];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}
