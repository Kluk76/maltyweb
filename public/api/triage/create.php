<?php
declare(strict_types=1);

/**
 * POST /api/triage/create.php
 *
 * Create a new MI from the triage UI's create modal.
 *
 * Payload:
 *   csrf                   — session token (required)
 *   rq_id                  — doc_review_queue.id (required, int)
 *   line_index             — 0-based unresolved line index (required for invoice-line-items-needed)
 *   mi_id                  — validated MI_ID (required)
 *   category               — category name (required)
 *   subcategory            — subcategory name (required)
 *   account                — GL account (required)
 *   name                   — MI name (required)
 *   notes                  — operator notes (optional)
 *   proposed_mi_id         — proposition values for audit (optional)
 *   proposed_category      — (optional)
 *   proposed_subcategory   — (optional)
 *   proposed_account       — (optional)
 *   proposed_name          — (optional)
 *   proposition_confidence — (optional, float)
 *   similar_mi_ids_json    — JSON-encoded list (optional)
 *   raw_line_text          — original invoice line text (for alias + audit)
 *
 * Writes (in a transaction):
 *   1. INSERT ref_mi (new MI row)
 *   2. INSERT mi_proposals_audit
 *   3. INSERT IGNORE ref_mi_aliases (raw_line → new mi_id)
 *   4. Mark line resolved in context / close row if all lines done
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

// ── Input validation ──────────────────────────────────────────────────────────
$rqId       = isset($_POST['rq_id'])      ? (int)$_POST['rq_id']      : 0;
$lineIndex  = isset($_POST['line_index']) ? (int)$_POST['line_index']  : -1;
$miId       = trim($_POST['mi_id']       ?? '');
$category   = trim($_POST['category']    ?? '');
$subcategory = trim($_POST['subcategory'] ?? '');
$account    = trim($_POST['account']     ?? '');
$miName     = trim($_POST['name']        ?? '');
$notes      = trim($_POST['notes']       ?? '');
$rawLineText = trim($_POST['raw_line_text'] ?? '');

// Proposition fields (for audit — may be empty)
$propMiId   = trim($_POST['proposed_mi_id']       ?? '') ?: null;
$propCat    = trim($_POST['proposed_category']     ?? '') ?: null;
$propSubcat = trim($_POST['proposed_subcategory']  ?? '') ?: null;
$propAcct   = trim($_POST['proposed_account']      ?? '') ?: null;
$propName   = trim($_POST['proposed_name']         ?? '') ?: null;
$propConf   = isset($_POST['proposition_confidence'])
              ? (float)$_POST['proposition_confidence']
              : null;
$propSimilarJson = trim($_POST['similar_mi_ids_json'] ?? '') ?: null;
// Validate JSON
if ($propSimilarJson !== null) {
    $decoded = json_decode($propSimilarJson, true);
    if (!is_array($decoded)) $propSimilarJson = null;
}

$errors = [];
if ($rqId <= 0)       $errors[] = 'rq_id invalide.';
if ($miId === '')     $errors[] = 'MI_ID requis.';
if ($category === '') $errors[] = 'Catégorie requise.';
if ($subcategory === '') $errors[] = 'Sous-catégorie requise.';
if ($account === '')  $errors[] = 'Compte GL requis.';
if ($miName === '')   $errors[] = 'Nom MI requis.';

// MI_ID format: only uppercase letters, digits, underscores
if ($miId !== '' && !preg_match('/^[A-Z0-9_]{2,64}$/', $miId)) {
    $errors[] = 'MI_ID invalide — lettres majuscules, chiffres, underscores uniquement.';
}

if (!empty($errors)) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => implode(' ', $errors)];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}

try {
    // ── Check MI_ID uniqueness ────────────────────────────────────────────────
    $chkStmt = $pdo->prepare("SELECT 1 FROM ref_mi WHERE mi_id = ? LIMIT 1");
    $chkStmt->execute([$miId]);
    if ($chkStmt->fetchColumn()) {
        $_SESSION['triage_flash'] = [
            'type' => 'err',
            'msg'  => "MI_ID «{$miId}» existe déjà. Essayez {$miId}_2 ou choisissez un alias.",
        ];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId
               . '&action=create&line=' . $lineIndex, true, 303);
        exit;
    }

    // ── Resolve category FK ───────────────────────────────────────────────────
    $catStmt = $pdo->prepare("SELECT id FROM ref_mi_categories WHERE name = ? LIMIT 1");
    $catStmt->execute([$category]);
    $catId = $catStmt->fetchColumn();
    if ($catId === false) {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Catégorie «{$category}» introuvable."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // ── Resolve or create subcategory FK ─────────────────────────────────────
    $subcatStmt = $pdo->prepare(
        "SELECT id FROM ref_mi_subcategories WHERE name = ? AND category_id = ? LIMIT 1"
    );
    $subcatStmt->execute([$subcategory, (int)$catId]);
    $subcatId = $subcatStmt->fetchColumn();
    if ($subcatId === false) {
        // Auto-create new subcategory (operator confirmed via form)
        $insSubcat = $pdo->prepare(
            "INSERT INTO ref_mi_subcategories (name, category_id, gl_account) VALUES (?, ?, ?)"
        );
        $insSubcat->execute([$subcategory, (int)$catId, $account !== '' ? $account : null]);
        $subcatId = $pdo->lastInsertId();
    }

    // ── Load the RQ row ───────────────────────────────────────────────────────
    $rqStmt = $pdo->prepare(
        "SELECT id, type, context, status, file_id_fk FROM doc_review_queue WHERE id = ? LIMIT 1"
    );
    $rqStmt->execute([$rqId]);
    $rqRow = $rqStmt->fetch();

    if (!$rqRow || $rqRow['status'] !== 'open') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Ligne introuvable ou déjà résolue.'];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // ── Transaction ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // 1. INSERT ref_mi
    $rowHash = hash('sha256', $miId . $miName . $category . $subcategory . $account);
    $insMi = $pdo->prepare(
        "INSERT INTO ref_mi
           (mi_id, name, category_id, subcategory_id, gl_account, is_active,
            last_modified_by, row_hash)
         VALUES (?, ?, ?, ?, ?, 1, 'web', ?)"
    );
    $insMi->execute([$miId, $miName, (int)$catId, (int)$subcatId, $account, $rowHash]);

    // 2. INSERT mi_proposals_audit
    // Resolve supplier_id from RQ context (best-effort)
    $ctxParsed  = triage_parse_context((string)($rqRow['context'] ?? ''));
    $supplierId = null;
    if (!empty($ctxParsed['supplier'])) {
        $supStmt = $pdo->prepare(
            "SELECT id FROM ref_suppliers WHERE name = ? AND is_active = 1 LIMIT 1"
        );
        $supStmt->execute([$ctxParsed['supplier']]);
        $supplierId = $supStmt->fetchColumn() ?: null;
    }

    $insMpa = $pdo->prepare(
        "INSERT INTO mi_proposals_audit
           (user_id, rq_id, raw_line_text, supplier_id,
            proposed_mi_id, proposed_category, proposed_subcategory, proposed_account, proposed_name,
            proposition_confidence, similar_mi_ids,
            validated_mi_id, validated_category, validated_subcategory, validated_account, validated_name,
            notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insMpa->execute([
        (int)$me['id'],
        $rqId,
        $rawLineText !== '' ? $rawLineText : $miId,
        $supplierId !== false ? $supplierId : null,
        $propMiId,
        $propCat,
        $propSubcat,
        $propAcct,
        $propName,
        $propConf !== null ? round($propConf, 3) : null,
        $propSimilarJson,
        $miId,
        $category,
        $subcategory,
        $account,
        $miName,
        $notes !== '' ? $notes : null,
    ]);

    // 3. INSERT alias: raw_line_text → new mi_id
    if ($rawLineText !== '' && $rawLineText !== $miId) {
        $newMiInternal = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
        $newMiInternal->execute([$miId]);
        $newMiInternalId = $newMiInternal->fetchColumn();

        if ($newMiInternalId) {
            $aliasStmt = $pdo->prepare(
                "INSERT IGNORE INTO ref_mi_aliases (alias, mi_id_fk) VALUES (?, ?)"
            );
            $aliasStmt->execute([$rawLineText, (int)$newMiInternalId]);
        }
    }

    // 4. Mark line resolved / close row
    $rowClosed = false;
    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed') {
        $newContext = ta_mark_line_resolved((string)$rqRow['context'], $lineIndex);
        $updatedCtx = triage_parse_context($newContext);
        $openLeft   = ta_count_open_lines($updatedCtx['unresolved']);

        if ($openLeft === 0) {
            $closeStmt = $pdo->prepare(
                "UPDATE doc_review_queue
                    SET context    = ?,
                        status     = 'resolved',
                        decision   = 'create',
                        decided_at = NOW(),
                        decided_by = ?,
                        updated_at = NOW()
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
        $closeStmt = $pdo->prepare(
            "UPDATE doc_review_queue
                SET status     = 'resolved',
                    decision   = 'create',
                    decided_at = NOW(),
                    decided_by = ?,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $closeStmt->execute([$me['username'], $rqId]);
        $rowClosed = true;
    }

    $pdo->commit();

    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "MI «{$miId}» créé."
                  . ($rawLineText !== '' ? " Alias «{$rawLineText}» enregistré." : '')
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
