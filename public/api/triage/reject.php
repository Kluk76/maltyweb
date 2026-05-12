<?php
declare(strict_types=1);

/**
 * POST /api/triage/reject.php
 *
 * Reject a triage row (whole row or single line).
 *
 * Payload:
 *   csrf        — session token (required)
 *   rq_id       — doc_review_queue.id (required)
 *   line_index  — 0-based unresolved line index (optional;
 *                 if absent: reject the whole row)
 *   reason      — operator reason text (optional but encouraged)
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

$rqId      = isset($_POST['rq_id'])      ? (int)$_POST['rq_id']      : 0;
$lineIndex = isset($_POST['line_index']) ? (int)$_POST['line_index']  : -1;
$reason    = trim($_POST['reason']       ?? '');

if ($rqId <= 0) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'rq_id invalide.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

try {
    $rqStmt = $pdo->prepare(
        "SELECT id, type, context, status FROM doc_review_queue WHERE id = ? LIMIT 1"
    );
    $rqStmt->execute([$rqId]);
    $rqRow = $rqStmt->fetch();

    if (!$rqRow || $rqRow['status'] !== 'open') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Ligne introuvable ou déjà résolue.'];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $pdo->beginTransaction();

    $rowClosed = false;

    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed') {
        // Per-line reject: mark line resolved then check remaining
        $newContext = ta_mark_line_resolved((string)$rqRow['context'], $lineIndex);
        $updatedCtx = triage_parse_context($newContext);
        $openLeft   = ta_count_open_lines($updatedCtx['unresolved']);

        if ($openLeft === 0) {
            $stmt = $pdo->prepare(
                "UPDATE doc_review_queue
                    SET context    = ?,
                        status     = 'rejected',
                        decision   = 'reject',
                        decided_at = NOW(),
                        decided_by = ?,
                        updated_at = NOW()
                  WHERE id = ?"
            );
            $stmt->execute([$newContext, $me['username'], $rqId]);
            $rowClosed = true;
        } else {
            $stmt = $pdo->prepare(
                "UPDATE doc_review_queue SET context = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$newContext, $rqId]);
        }
    } else {
        // Whole-row reject
        $stmt = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status         = 'rejected',
                    decision       = 'reject',
                    decided_at     = NOW(),
                    decided_by     = ?,
                    resolution_note = ?,
                    updated_at     = NOW()
              WHERE id = ?"
        );
        // resolution_note column may not exist — check and fall back
        try {
            $stmt->execute([$me['username'], $reason !== '' ? $reason : null, $rqId]);
        } catch (PDOException $colEx) {
            // Column doesn't exist in older schema — retry without it
            $stmt2 = $pdo->prepare(
                "UPDATE doc_review_queue
                    SET status     = 'rejected',
                        decision   = 'reject',
                        decided_at = NOW(),
                        decided_by = ?,
                        updated_at = NOW()
                  WHERE id = ?"
            );
            $stmt2->execute([$me['username'], $rqId]);
        }
        $rowClosed = true;
    }

    $pdo->commit();

    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => 'Rejeté.' . ($reason !== '' ? " Raison : «{$reason}»." : '')
                  . ($rowClosed ? ' Ligne fermée.' : ''),
    ];
    $redirectUrl = ta_redirect_url($rqId, $rowClosed, $pdo);
    header('Location: ' . $redirectUrl, true, 303);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Erreur : ' . $e->getMessage()];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}
