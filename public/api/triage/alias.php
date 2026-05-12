<?php
declare(strict_types=1);

/**
 * POST /api/triage/alias.php
 *
 * Wire a raw invoice line string to an existing MI via ref_mi_aliases.
 *
 * Payload:
 *   csrf         — session token (required)
 *   rq_id        — doc_review_queue.id (required, int)
 *   line_index   — 0-based index into the unresolved-lines array (required for
 *                  invoice-line-items-needed rows; absent = whole-row alias)
 *   alias_text   — the text string to register as alias (defaults to raw line)
 *   target_mi_id — ref_mi.mi_id to map the alias to (required)
 *
 * Writes:
 *   1. INSERT IGNORE ref_mi_aliases (alias, mi_id_fk)
 *   2. UPDATE doc_review_queue context: mark line resolved
 *   3. If all lines resolved: close the RQ row (status=resolved, decision=alias)
 *   4. Redirect 303 → next open row or current row
 */

require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/csrf.php';
require __DIR__ . '/../../../app/services/rate_limit.php';
require __DIR__ . '/../../../app/services/triage_actions.php';

require_login();
$me = current_user();

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /modules/triage.php?tab=docs');
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Token CSRF invalide. Rechargez la page.'];
    $back = '/modules/triage.php?tab=docs' . (isset($_POST['rq_id']) ? '&rq_id=' . (int)$_POST['rq_id'] : '');
    header('Location: ' . $back, true, 303);
    exit;
}

$pdo = maltytask_pdo();

// ── Rate limit ────────────────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
if (!rl_check_and_log((int)$me['id'], 'triage_action', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Limite de requêtes atteinte (200/h). Réessayez dans quelques minutes.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$rqId       = isset($_POST['rq_id'])      ? (int)$_POST['rq_id']       : 0;
$lineIndex  = isset($_POST['line_index']) ? (int)$_POST['line_index']   : -1;
$aliasText  = trim($_POST['alias_text']  ?? '');
$targetMiId = trim($_POST['target_mi_id'] ?? '');

if ($rqId <= 0) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'rq_id manquant ou invalide.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}
if ($targetMiId === '') {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'MI cible non spécifié.'];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}

try {
    // ── Load the RQ row ───────────────────────────────────────────────────────
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

    // ── Resolve target MI internal ID ─────────────────────────────────────────
    $miStmt = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? AND is_active = 1 LIMIT 1");
    $miStmt->execute([$targetMiId]);
    $miInternalId = $miStmt->fetchColumn();

    if ($miInternalId === false) {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "MI «{$targetMiId}» introuvable ou inactif."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // ── Determine alias text ──────────────────────────────────────────────────
    $ctx = triage_parse_context((string)($rqRow['context'] ?? ''));

    if ($aliasText === '' && $lineIndex >= 0 && isset($ctx['unresolved'][$lineIndex])) {
        $parsed    = ta_parse_unresolved_line($ctx['unresolved'][$lineIndex]);
        $aliasText = $parsed['raw'] ?? '';
    }

    if ($aliasText === '') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Texte alias vide — rien à enregistrer.'];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // ── Transaction ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // 1. INSERT alias (ignore duplicate)
    $aliasStmt = $pdo->prepare(
        "INSERT IGNORE INTO ref_mi_aliases (alias, mi_id_fk) VALUES (?, ?)"
    );
    $aliasStmt->execute([$aliasText, (int)$miInternalId]);

    // 2. Mark the line resolved in context (if line_index supplied)
    $rowClosed = false;
    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed') {
        $newContext = ta_mark_line_resolved((string)$rqRow['context'], $lineIndex);

        // Re-parse to count remaining open lines
        $updatedCtx = triage_parse_context($newContext);
        $openLeft   = ta_count_open_lines($updatedCtx['unresolved']);

        if ($openLeft === 0) {
            // All lines resolved — close the row
            $closeStmt = $pdo->prepare(
                "UPDATE doc_review_queue
                    SET context     = ?,
                        status      = 'resolved',
                        decision    = 'alias',
                        decided_at  = NOW(),
                        decided_by  = ?,
                        updated_at  = NOW()
                  WHERE id = ?"
            );
            $closeStmt->execute([$newContext, $me['username'], $rqId]);
            $rowClosed = true;
        } else {
            $updCtx = $pdo->prepare(
                "UPDATE doc_review_queue SET context = ?, updated_at = NOW() WHERE id = ?"
            );
            $updCtx->execute([$newContext, $rqId]);
        }
    } else {
        // Whole-row alias (type without per-line breakdown)
        $closeStmt = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status     = 'resolved',
                    decision   = 'alias',
                    decided_at = NOW(),
                    decided_by = ?,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $closeStmt->execute([$me['username'], $rqId]);
        $rowClosed = true;
    }

    $pdo->commit();

    // ── Redirect ──────────────────────────────────────────────────────────────
    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "Alias «{$aliasText}» → {$targetMiId} enregistré."
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
