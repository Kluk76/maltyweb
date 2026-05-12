<?php
declare(strict_types=1);

/**
 * POST /api/triage/classify.php
 *
 * Classify an ambiguous document as invoice or delivery note.
 *
 * Payload:
 *   csrf    — session token (required)
 *   rq_id   — doc_review_queue.id (required)
 *   target  — 'invoice' | 'dn' (required)
 *
 * NOTE: doc_classify_overrides table does NOT exist in this schema.
 * The decision is recorded in doc_review_queue.decision only.
 * The pipeline will need to check this table when re-processing ambiguous files.
 * A future migration (037+) should add doc_classify_overrides if automated
 * re-processing from the UI is needed.
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

$rqId   = isset($_POST['rq_id']) ? (int)$_POST['rq_id'] : 0;
$target = trim($_POST['target'] ?? '');

if ($rqId <= 0) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'rq_id invalide.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

if (!in_array($target, ['invoice', 'dn'], true)) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Cible invalide — doit être "invoice" ou "dn".'];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
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

    if ($rqRow['type'] !== 'doc-classify-ambiguous') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Action classify non applicable au type «{$rqRow['type']}»."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $pdo->beginTransaction();

    // Set decision to 'invoice' or 'dn' — both are valid freeform decision values
    // (doc_review_queue.decision is VARCHAR(32), not enum-constrained for applied variants)
    try {
        $stmt = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status          = 'resolved',
                    decision        = ?,
                    decided_at      = NOW(),
                    decided_by      = ?,
                    resolution_note = ?,
                    updated_at      = NOW()
              WHERE id = ?"
        );
        $note = $target === 'invoice'
            ? 'Reclassifié comme facture par opérateur'
            : 'Reclassifié comme bon de livraison par opérateur';
        $stmt->execute([$target, $me['username'], $note, $rqId]);
    } catch (PDOException $colEx) {
        $stmt2 = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status     = 'resolved',
                    decision   = ?,
                    decided_at = NOW(),
                    decided_by = ?,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $stmt2->execute([$target, $me['username'], $rqId]);
    }

    $pdo->commit();

    $label = $target === 'invoice' ? 'Facture' : 'Bon de livraison';
    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "Reclassifié → {$label}. "
                  . "NOTE : doc_classify_overrides n'existe pas encore — "
                  . "relancez manuellement ingest-documents.js --force {fileId} sur le VPS.",
    ];
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
