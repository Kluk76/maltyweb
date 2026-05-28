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
 *   qty                    — optional decimal; operator-provided qty for delivery row
 *   unit_price             — optional decimal; operator-provided unit price
 *   skip_delivery          — optional bool (1/true); create MI + alias only, no inv_deliveries
 *
 * Writes (in a transaction):
 *   1. INSERT ref_mi (new MI row)
 *   2. INSERT mi_proposals_audit
 *   3. INSERT IGNORE ref_mi_aliases (raw_line → new mi_id)
 *   4. UPDATE doc_invoice_lines.mi_id_fk (if invoice_id resolvable and line_index ≥ 0)
 *   5. INSERT IGNORE inv_deliveries (unless skip_delivery or qty/price unresolvable)
 *   6. Mark line resolved in context / close row if all lines done
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
$categoryNew = trim($_POST['category_new'] ?? '');
// When operator chose "+ Nouvelle catégorie", $_POST['category'] is '' and
// category_new holds the free-text name. Merge them here so the rest of the
// file sees $category as the effective name (existing or new).
$categoryRaw = trim($_POST['category'] ?? '');
$category    = ($categoryRaw === '' && $categoryNew !== '') ? $categoryNew : $categoryRaw;
$subcategory = trim($_POST['subcategory'] ?? '');
$account    = trim($_POST['account']     ?? '');
$miName     = trim($_POST['name']        ?? '');
$notes      = trim($_POST['notes']       ?? '');
$rawLineText  = trim($_POST['raw_line_text'] ?? '');
$skipDelivery = !empty($_POST['skip_delivery']) && $_POST['skip_delivery'] !== '0';
$postQty      = isset($_POST['qty'])        && $_POST['qty'] !== ''
                ? filter_var($_POST['qty'],        FILTER_VALIDATE_FLOAT) : null;
$postPrice    = isset($_POST['unit_price']) && $_POST['unit_price'] !== ''
                ? filter_var($_POST['unit_price'], FILTER_VALIDATE_FLOAT) : null;

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

    // ── Resolve or bootstrap category FK ──────────────────────────────────────
    $catStmt = $pdo->prepare("SELECT id FROM ref_mi_categories WHERE name = ? LIMIT 1");
    $catStmt->execute([$category]);
    $catId = $catStmt->fetchColumn();
    if ($catId === false) {
        // Only auto-create when the operator explicitly typed a new name (via category_new path).
        // If they selected an existing category and it's gone, that's a real error.
        if ($categoryNew === '' || $category !== $categoryNew) {
            $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Catégorie «{$category}» introuvable."];
            header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
            exit;
        }
        // Duplicate check — category names must be unique
        $dupStmt = $pdo->prepare("SELECT id FROM ref_mi_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $dupStmt->execute([$category]);
        $existingId = $dupStmt->fetchColumn();
        if ($existingId !== false) {
            // Race: created between page load and submit — just use the existing row.
            $catId = $existingId;
        } else {
            // Bootstrap new category (equivalent of add-ingredient.js --allow-new-category)
            $insCat = $pdo->prepare("INSERT INTO ref_mi_categories (name) VALUES (?)");
            $insCat->execute([$category]);
            $catId = $pdo->lastInsertId();
        }
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

    // ── Resolve parent invoice ────────────────────────────────────────────────
    $invRow = null;
    if (!empty($rqRow['file_id_fk'])) {
        $invStmt = $pdo->prepare(
            "SELECT di.id         AS inv_id,
                    di.supplier_name,
                    di.supplier_fk,
                    di.invoice_ref,
                    di.invoice_date,
                    di.currency
               FROM doc_invoices di
               JOIN doc_files df ON df.id = di.file_id
              WHERE df.id = ?
              LIMIT 1"
        );
        $invStmt->execute([(int)$rqRow['file_id_fk']]);
        $invRow = $invStmt->fetch() ?: null;
    }

    // ── Resolve qty/price for delivery (pre-transaction validation) ───────────
    $delivQty   = null;
    $delivPrice = null;
    $delivError = null;
    $ctxParsedForDelivery = triage_parse_context((string)($rqRow['context'] ?? ''));

    // Determine the parser's original line_index for doc_invoice_lines lookups.
    // Prefer parser-embedded [line N] marker; when absent (legacy ingest-documents.js
    // format), fall back to smart resolver that matches by ingredient_name/description,
    // then line_total proximity.
    $dbLineIdx = $lineIndex;
    $lpCheck = null;
    if ($lineIndex >= 0 && isset($ctxParsedForDelivery['unresolved'][$lineIndex])) {
        $lpCheck = ta_parse_unresolved_line($ctxParsedForDelivery['unresolved'][$lineIndex]);
        if ($lpCheck['dbLineIndex'] !== null) {
            $dbLineIdx = $lpCheck['dbLineIndex'];
        }
    }

    if ($lpCheck !== null && $lpCheck['dbLineIndex'] === null && $invRow !== null && $lineIndex >= 0) {
        // Use the operator-typed MI name as the search needle (description on create.php).
        // Falls back to ingredient_name from context if no description was typed.
        $needle = $miName !== '' ? $miName : ($lpCheck['raw'] ?? '');
        $dbLineIdx = ta_resolve_db_line_index(
            $pdo,
            (int)$invRow['inv_id'],
            $lineIndex,
            $needle,
            $lpCheck['lineTotal'] ?? null,
            $rqId
        );
    }

    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed' && !$skipDelivery) {
        // Try: POST > doc_invoice_lines (using dbLineIdx) > context-parsed values
        $ilRow = null;
        if ($invRow !== null) {
            $ilStmt = $pdo->prepare(
                "SELECT qty, unit_price FROM doc_invoice_lines
                  WHERE invoice_id = ? AND line_index = ? LIMIT 1"
            );
            $ilStmt->execute([(int)$invRow['inv_id'], $dbLineIdx]);
            $ilRow = $ilStmt->fetch() ?: null;
        }

        $resolvedQty   = $postQty   ?? ($ilRow !== null && $ilRow['qty']        !== null ? (float)$ilRow['qty']        : null);
        $resolvedPrice = $postPrice ?? ($ilRow !== null && $ilRow['unit_price']  !== null ? (float)$ilRow['unit_price'] : null);

        if (($resolvedQty === null || $resolvedPrice === null) && isset($ctxParsedForDelivery['unresolved'][$lineIndex])) {
            $lp = ta_parse_unresolved_line($ctxParsedForDelivery['unresolved'][$lineIndex]);
            $resolvedQty   = $resolvedQty   ?? $lp['qty'];
            $resolvedPrice = $resolvedPrice ?? $lp['unitPrice'];
        }

        if ($resolvedQty === null || $resolvedQty <= 0 || $resolvedPrice === null) {
            $delivError = "Renseignez qty et prix unitaire pour cette ligne avant la création.";
        } else {
            $delivQty   = $resolvedQty;
            $delivPrice = $resolvedPrice;
        }
    }

    if ($delivError !== null) {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => $delivError];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId
               . '&action=create&line=' . $lineIndex, true, 303);
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
    $newMiInternalId = null;
    if ($rawLineText !== '' && $rawLineText !== $miId) {
        $newMiInternal = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
        $newMiInternal->execute([$miId]);
        $newMiInternalId = $newMiInternal->fetchColumn() ?: null;

        if ($newMiInternalId) {
            $aliasStmt = $pdo->prepare(
                "INSERT IGNORE INTO ref_mi_aliases (alias, mi_id_fk) VALUES (?, ?)"
            );
            $aliasStmt->execute([$rawLineText, (int)$newMiInternalId]);
        }
    }
    // Ensure we have the internal ID even when rawLineText === miId
    if ($newMiInternalId === null) {
        $fallbackStmt = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = ? LIMIT 1");
        $fallbackStmt->execute([$miId]);
        $newMiInternalId = $fallbackStmt->fetchColumn() ?: null;
    }

    // 4. Materialize delivery + update doc_invoice_lines
    // Use $dbLineIdx (parser's original line_index) for doc_invoice_lines;
    // use $lineIndex (array position) for inv_deliveries row_hash dedup.
    $delivInserted = false;
    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed' && $newMiInternalId !== null) {
        if (!$skipDelivery && $delivQty !== null && $delivPrice !== null) {
            // 4a. Update doc_invoice_lines (use dbLineIdx — parser's original index)
            if ($invRow !== null) {
                ta_update_invoice_line(
                    $pdo,
                    (int)$invRow['inv_id'],
                    $dbLineIdx,
                    (int)$newMiInternalId,
                    $delivQty,
                    $delivPrice
                );
            }

            // 4b. Resolve Pending row to Active (new "write-everything" path).
            // Try UPDATE first; INSERT-fallback for legacy rows without a Pending anchor.
            $delivInserted = false;
            $fileIdFkForRow = !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null;

            if ($fileIdFkForRow !== null) {
                $resolveResult = ta_resolve_pending_delivery($pdo, [
                    'file_id_fk'      => $fileIdFkForRow,
                    'db_line_index'   => $dbLineIdx,
                    'mi_internal_id'  => (int)$newMiInternalId,
                    'mi_id_str'       => $miId,
                    'unit_price'      => $delivPrice,
                    'qty'             => $delivQty,
                    'alias_text'      => $rawLineText !== '' ? $rawLineText : $miName,
                    'last_modified_by' => 'web',
                ]);
                $delivInserted = $resolveResult['updated'];
                if (!$delivInserted) {
                    error_log('TRIAGE_FALLBACK_INSERT: no pending row found for file_id_fk=' . $fileIdFkForRow . ' line_index=' . $dbLineIdx . ' (rq_id=' . $rqId . ')');
                    $result = ta_materialize_delivery($pdo, [
                        'rq_id'          => $rqId,
                        'line_index'     => $lineIndex,
                        'mi_internal_id' => (int)$newMiInternalId,
                        'mi_id_str'      => $miId,
                        'description'    => $rawLineText !== '' ? $rawLineText : $miName,
                        'qty'            => $delivQty,
                        'unit_price'     => $delivPrice,
                        'invoice_id'     => $invRow !== null ? (int)$invRow['inv_id'] : null,
                        'invoice_ref'    => $invRow['invoice_ref']   ?? null,
                        'invoice_date'   => $invRow['invoice_date']  ?? null,
                        'supplier_raw'   => $invRow['supplier_name'] ?? null,
                        'supplier_fk'    => $invRow !== null && !empty($invRow['supplier_fk'])
                                            ? (int)$invRow['supplier_fk'] : null,
                        'currency'       => $invRow['currency'] ?? 'CHF',
                        'source'         => 'triage-create',
                        'source_origin'  => 'web',
                        'file_id_fk'     => !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null,
                    ]);
                    $delivInserted = $result['inserted'];
                }
            } else {
                // No file_id_fk — pure legacy INSERT path
                $result = ta_materialize_delivery($pdo, [
                    'rq_id'          => $rqId,
                    'line_index'     => $lineIndex,
                    'mi_internal_id' => (int)$newMiInternalId,
                    'mi_id_str'      => $miId,
                    'description'    => $rawLineText !== '' ? $rawLineText : $miName,
                    'qty'            => $delivQty,
                    'unit_price'     => $delivPrice,
                    'invoice_id'     => $invRow !== null ? (int)$invRow['inv_id'] : null,
                    'invoice_ref'    => $invRow['invoice_ref']   ?? null,
                    'invoice_date'   => $invRow['invoice_date']  ?? null,
                    'supplier_raw'   => $invRow['supplier_name'] ?? null,
                    'supplier_fk'    => $invRow !== null && !empty($invRow['supplier_fk'])
                                        ? (int)$invRow['supplier_fk'] : null,
                    'currency'       => $invRow['currency'] ?? 'CHF',
                    'source'         => 'triage-create',
                    'source_origin'  => 'web',
                    'file_id_fk'     => !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null,
                ]);
                $delivInserted = $result['inserted'];
            }
        } elseif ($skipDelivery && $invRow !== null) {
            ta_update_invoice_line(
                $pdo,
                (int)$invRow['inv_id'],
                $dbLineIdx,
                (int)$newMiInternalId,
                null,
                null
            );
        }
    }

    // 5. Mark line resolved / close row
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

    $delivNote = $skipDelivery ? ' (livraison ignorée)' : ($delivInserted ? ' Livraison créée.' : '');
    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "MI «{$miId}» créé."
                  . ($rawLineText !== '' ? " Alias «{$rawLineText}» enregistré." : '')
                  . $delivNote
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
