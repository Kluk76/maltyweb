<?php
declare(strict_types=1);

/**
 * POST /api/triage/alias.php
 *
 * Wire a raw invoice line string to an existing MI via ref_mi_aliases.
 *
 * Payload:
 *   csrf          — session token (required)
 *   rq_id         — doc_review_queue.id (required, int)
 *   line_index    — 0-based index into the unresolved-lines array (required for
 *                   invoice-line-items-needed rows; absent = whole-row alias)
 *   alias_text    — the text string to register as alias (defaults to raw line)
 *   target_mi_id  — ref_mi.mi_id to map the alias to (required)
 *   qty           — optional decimal; operator-provided qty for delivery row
 *   unit_price    — optional decimal; operator-provided unit price
 *   skip_delivery — optional bool (1/true); alias only, no inv_deliveries insert
 *                   (use for immobilisation, recoverable import VAT, taproom, rebate-fold)
 *
 * Writes (in transaction):
 *   1. INSERT IGNORE ref_mi_aliases (alias, mi_id_fk)
 *   2. UPDATE doc_invoice_lines.mi_id_fk (if invoice_id resolvable and line_index ≥ 0)
 *   3. INSERT IGNORE inv_deliveries (unless skip_delivery or qty/price unresolvable)
 *   4. UPDATE doc_review_queue context: mark line resolved
 *   5. If all lines resolved: close the RQ row (status=resolved, decision=alias)
 *   6. Redirect 303 → next open row or current row
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
$rqId        = isset($_POST['rq_id'])      ? (int)$_POST['rq_id']       : 0;
$lineIndex   = isset($_POST['line_index']) ? (int)$_POST['line_index']   : -1;
$aliasText   = trim($_POST['alias_text']  ?? '');
$targetMiId  = trim($_POST['target_mi_id'] ?? '');
$skipDelivery = !empty($_POST['skip_delivery']) && $_POST['skip_delivery'] !== '0';
$postQty      = isset($_POST['qty'])        && $_POST['qty'] !== ''
                ? filter_var($_POST['qty'],        FILTER_VALIDATE_FLOAT) : null;
$postPrice    = isset($_POST['unit_price']) && $_POST['unit_price'] !== ''
                ? filter_var($_POST['unit_price'], FILTER_VALIDATE_FLOAT) : null;

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
        "SELECT id, type, context, status, file_id_fk FROM doc_review_queue WHERE id = ? LIMIT 1"
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

    $parsedLine = null;
    if ($lineIndex >= 0 && isset($ctx['unresolved'][$lineIndex])) {
        $parsedLine = ta_parse_unresolved_line($ctx['unresolved'][$lineIndex]);
        if ($aliasText === '') {
            $aliasText = $parsedLine['raw'] ?? '';
        }
    }

    if ($aliasText === '') {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Texte alias vide — rien à enregistrer.'];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // For doc_invoice_lines lookups: use the parser's original line_index (dbLineIndex)
    // when present (new ingest-one-local.ts format), otherwise fall back to the
    // array-position lineIndex (legacy ingest-documents.js format).
    $dbLineIdx = ($parsedLine !== null && $parsedLine['dbLineIndex'] !== null)
                 ? $parsedLine['dbLineIndex']
                 : $lineIndex;

    // ── Resolve parent invoice (needed for delivery + doc_invoice_lines update) ──
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

    // Smart fallback: when the parser-embedded [line N] marker is absent (legacy
    // ingest-documents.js format or malformed context), resolve the correct
    // doc_invoice_lines.line_index via ingredient_name/description match or
    // line_total proximity rather than silently using the array position.
    if ($parsedLine !== null && $parsedLine['dbLineIndex'] === null && $invRow !== null && $lineIndex >= 0) {
        $dbLineIdx = ta_resolve_db_line_index(
            $pdo,
            (int)$invRow['inv_id'],
            $lineIndex,
            $aliasText,
            $parsedLine['lineTotal'] ?? null,
            $rqId
        );
    }

    // ── Resolve qty and unit_price for delivery materialization ───────────────
    $delivQty   = null;
    $delivPrice = null;
    $delivError = null;

    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed' && !$skipDelivery) {
        // Try: POST > doc_invoice_lines (using dbLineIdx) > context parsed values
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

        // Fall back to context-parsed values when either field is still missing
        if (($resolvedQty === null || $resolvedPrice === null) && isset($ctx['unresolved'][$lineIndex])) {
            $lp = ta_parse_unresolved_line($ctx['unresolved'][$lineIndex]);
            $resolvedQty   = $resolvedQty   ?? $lp['qty'];
            $resolvedPrice = $resolvedPrice ?? $lp['unitPrice'];
        }

        if ($resolvedQty === null || $resolvedQty <= 0 || $resolvedPrice === null) {
            $delivError = "Renseignez qty et prix unitaire pour cette ligne avant l'alias.";
        } else {
            $delivQty   = $resolvedQty;
            $delivPrice = $resolvedPrice;
        }
    }

    if ($delivError !== null) {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => $delivError];
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

    // 2. Materialize delivery + update doc_invoice_lines (if applicable)
    // Use $dbLineIdx (parser's original line_index) for doc_invoice_lines lookups;
    // use $lineIndex (array position) for row_hash dedup in inv_deliveries.
    $delivInserted = false;
    if ($lineIndex >= 0 && $rqRow['type'] === 'invoice-line-items-needed') {
        if (!$skipDelivery && $delivQty !== null && $delivPrice !== null) {
            // 2a. Update doc_invoice_lines.mi_id_fk (idempotent — only sets when NULL)
            if ($invRow !== null) {
                ta_update_invoice_line(
                    $pdo,
                    (int)$invRow['inv_id'],
                    $dbLineIdx,
                    (int)$miInternalId,
                    $delivQty,
                    $delivPrice
                );
            }

            // 2b. Resolve Pending row to Active (new "write-everything" path).
            // Try UPDATE first; INSERT-fallback for legacy rows without a Pending anchor.
            $delivInserted = false;
            $fileIdFkForRow = !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null;

            if ($fileIdFkForRow !== null) {
                $resolveResult = ta_resolve_pending_delivery($pdo, [
                    'file_id_fk'      => $fileIdFkForRow,
                    'db_line_index'   => $dbLineIdx,
                    'mi_internal_id'  => (int)$miInternalId,
                    'mi_id_str'       => $targetMiId,
                    'unit_price'      => $delivPrice,
                    'qty'             => $delivQty,
                    'alias_text'      => $aliasText,
                    'last_modified_by' => 'web',
                ]);
                $delivInserted = $resolveResult['updated'];
                if (!$delivInserted) {
                    // Fallback: legacy row or race — INSERT via old path
                    error_log('TRIAGE_FALLBACK_INSERT: no pending row found for file_id_fk=' . $fileIdFkForRow . ' line_index=' . $dbLineIdx . ' (rq_id=' . $rqId . ')');
                    $result = ta_materialize_delivery($pdo, [
                        'rq_id'           => $rqId,
                        'line_index'      => $lineIndex,
                        'mi_internal_id'  => (int)$miInternalId,
                        'mi_id_str'       => $targetMiId,
                        'description'     => $aliasText,
                        'qty'             => $delivQty,
                        'unit_price'      => $delivPrice,
                        'invoice_id'      => $invRow !== null ? (int)$invRow['inv_id'] : null,
                        'invoice_ref'     => $invRow['invoice_ref']  ?? null,
                        'invoice_date'    => $invRow['invoice_date'] ?? null,
                        'supplier_raw'    => $invRow['supplier_name'] ?? null,
                        'supplier_fk'     => $invRow !== null && !empty($invRow['supplier_fk'])
                                             ? (int)$invRow['supplier_fk'] : null,
                        'currency'        => $invRow['currency'] ?? 'CHF',
                        'source'          => 'triage-alias',
                        'source_origin'   => 'web',
                        'file_id_fk'      => !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null,
                    ]);
                    $delivInserted = $result['inserted'];
                }
            } else {
                // No file_id_fk on RQ row — pure legacy INSERT path
                $result = ta_materialize_delivery($pdo, [
                    'rq_id'           => $rqId,
                    'line_index'      => $lineIndex,
                    'mi_internal_id'  => (int)$miInternalId,
                    'mi_id_str'       => $targetMiId,
                    'description'     => $aliasText,
                    'qty'             => $delivQty,
                    'unit_price'      => $delivPrice,
                    'invoice_id'      => $invRow !== null ? (int)$invRow['inv_id'] : null,
                    'invoice_ref'     => $invRow['invoice_ref']  ?? null,
                    'invoice_date'    => $invRow['invoice_date'] ?? null,
                    'supplier_raw'    => $invRow['supplier_name'] ?? null,
                    'supplier_fk'     => $invRow !== null && !empty($invRow['supplier_fk'])
                                         ? (int)$invRow['supplier_fk'] : null,
                    'currency'        => $invRow['currency'] ?? 'CHF',
                    'source'          => 'triage-alias',
                    'source_origin'   => 'web',
                    'file_id_fk'      => !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null,
                ]);
                $delivInserted = $result['inserted'];
            }
        } elseif ($skipDelivery && $invRow !== null) {
            // skip_delivery: still update doc_invoice_lines.mi_id_fk so the line
            // is considered resolved in downstream queries
            ta_update_invoice_line(
                $pdo,
                (int)$invRow['inv_id'],
                $dbLineIdx,
                (int)$miInternalId,
                null,
                null
            );
        }
    }

    // 3. Mark the line resolved in context (if line_index supplied)
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

            // Stamp validated_at on the parent invoice so it drops out of the
            // À-valider list. The triage path fully resolved every line — the
            // invoice must NOT appear as a pending validation target.
            // validated_by is INT UNSIGNED FK to users.id — the operator who
            // resolved the last line is the validator.
            if ($invRow !== null) {
                $pdo->prepare(
                    "UPDATE doc_invoices
                        SET validated_at = NOW(),
                            validated_by = ?
                      WHERE id = ? AND validated_at IS NULL"
                )->execute([(int)$me['id'], (int)$invRow['inv_id']]);
            }
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
    $delivNote = $skipDelivery ? ' (livraison ignorée)' : ($delivInserted ? ' Livraison créée.' : '');
    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "Alias «{$aliasText}» → {$targetMiId} enregistré.{$delivNote}"
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
