<?php
declare(strict_types=1);

/**
 * POST /api/triage/manual-lines.php
 *
 * Write manually-entered invoice line items into doc_invoice_lines and
 * inv_deliveries, then close the doc_review_queue row.
 *
 * Payload:
 *   csrf                     — session token (required)
 *   rq_id                    — doc_review_queue.id (required, int)
 *   lines[i][description]    — line description, non-empty, ≤ 500 chars
 *   lines[i][mi_id]          — ref_mi.mi_id, must exist and be active
 *   lines[i][qty]            — numeric, > 0
 *   lines[i][unit_price]     — numeric, ≥ 0
 *   lines[i][line_total]     — optional client hint; server recomputes
 */

require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/csrf.php';
require __DIR__ . '/../../../app/services/rate_limit.php';
require __DIR__ . '/../../../app/services/triage_actions.php';

require_login();
$me = current_user();

// ── Method guard ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /modules/triage.php?tab=docs');
    exit;
}

// ── CSRF ───────────────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Token CSRF invalide. Rechargez la page.'];
    $back = '/modules/triage.php?tab=docs' . (isset($_POST['rq_id']) ? '&rq_id=' . (int)$_POST['rq_id'] : '');
    header('Location: ' . $back, true, 303);
    exit;
}

$pdo = maltytask_pdo();

// ── Rate limit ─────────────────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
if (!rl_check_and_log((int)$me['id'], 'triage_action', 200, 3600, $ip, $pdo)) {
    http_response_code(429);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Limite de requêtes atteinte (200/h). Réessayez dans quelques minutes.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

// ── Input validation ───────────────────────────────────────────────────────────
$rqId = isset($_POST['rq_id']) ? (int)$_POST['rq_id'] : 0;

if ($rqId <= 0) {
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'rq_id manquant ou invalide.'];
    header('Location: /modules/triage.php?tab=docs', true, 303);
    exit;
}

$rawLines = isset($_POST['lines']) && is_array($_POST['lines']) ? $_POST['lines'] : [];

if (count($rawLines) === 0) {
    http_response_code(400);
    $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Au moins une ligne est requise.'];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}

// Validate and sanitise each line
$validatedLines = [];
foreach ($rawLines as $i => $line) {
    $lineNum = (int)$i + 1;

    $description = trim((string)($line['description'] ?? ''));
    if ($description === '') {
        http_response_code(400);
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Ligne {$lineNum} : description obligatoire."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }
    if (mb_strlen($description) > 500) {
        http_response_code(400);
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Ligne {$lineNum} : description trop longue (max 500 caractères)."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $miId = trim((string)($line['mi_id'] ?? ''));
    if ($miId === '') {
        http_response_code(400);
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Ligne {$lineNum} : MI obligatoire."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $qtyRaw = filter_var($line['qty'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($qtyRaw === false || $qtyRaw <= 0) {
        http_response_code(400);
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Ligne {$lineNum} : quantité invalide (doit être > 0)."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    $unitPriceRaw = filter_var($line['unit_price'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($unitPriceRaw === false || $unitPriceRaw < 0) {
        http_response_code(400);
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => "Ligne {$lineNum} : prix unitaire invalide (doit être ≥ 0)."];
        header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
        exit;
    }

    // Server-side line total — never trust client
    $lineTotal = round($qtyRaw * $unitPriceRaw, 2);

    $validatedLines[] = [
        'description' => $description,
        'mi_id'       => $miId,
        'qty'         => $qtyRaw,
        'unit_price'  => $unitPriceRaw,
        'line_total'  => $lineTotal,
    ];
}

try {
    // ── Load the RQ row ────────────────────────────────────────────────────────
    $rqStmt = $pdo->prepare(
        "SELECT id, type, status, file_id_fk FROM doc_review_queue WHERE id = ? LIMIT 1"
    );
    $rqStmt->execute([$rqId]);
    $rqRow = $rqStmt->fetch();

    if (!$rqRow) {
        $_SESSION['triage_flash'] = ['type' => 'err', 'msg' => 'Entrée introuvable.'];
        header('Location: /modules/triage.php?tab=docs', true, 303);
        exit;
    }

    // Idempotency: already resolved → bail early, no writes
    if ($rqRow['status'] !== 'open') {
        $_SESSION['triage_flash'] = ['type' => 'warn', 'msg' => 'Cette entrée est déjà résolue (soumission dupliquée ignorée).'];
        header('Location: ' . ta_redirect_url($rqId, true, $pdo), true, 303);
        exit;
    }

    // ── Resolve each MI ID to its internal id and metadata ────────────────────
    foreach ($validatedLines as $idx => &$vl) {
        $miStmt = $pdo->prepare(
            "SELECT id, mi_id, name FROM ref_mi WHERE mi_id = ? AND is_active = 1 LIMIT 1"
        );
        $miStmt->execute([$vl['mi_id']]);
        $miRow = $miStmt->fetch();

        if (!$miRow) {
            http_response_code(400);
            $_SESSION['triage_flash'] = [
                'type' => 'err',
                'msg'  => "Ligne " . ($idx + 1) . " : MI «{$vl['mi_id']}» introuvable ou inactif.",
            ];
            header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
            exit;
        }
        $vl['mi_internal_id'] = (int)$miRow['id'];
        $vl['mi_name']        = (string)$miRow['name'];
    }
    unset($vl);

    // ── Load parent doc_invoices row via doc_files join ────────────────────────
    $invRow = null;
    if (!empty($rqRow['file_id_fk'])) {
        $invStmt = $pdo->prepare(
            "SELECT di.id         AS inv_id,
                    di.supplier_name,
                    di.supplier_fk,
                    di.invoice_ref,
                    di.invoice_date,
                    di.currency,
                    di.total_ht
               FROM doc_invoices di
               JOIN doc_files df ON df.id = di.file_id
              WHERE df.id = ?
              LIMIT 1"
        );
        $invStmt->execute([(int)$rqRow['file_id_fk']]);
        $invRow = $invStmt->fetch() ?: null;
    }

    // ── Soft validation: delta vs invoice total_ht ────────────────────────────
    $linesTotal  = array_sum(array_column($validatedLines, 'line_total'));
    $deltaNote   = '';
    $invoiceTotalHt = $invRow !== null && $invRow['total_ht'] !== null
                      ? (float)$invRow['total_ht'] : null;

    if ($invoiceTotalHt !== null) {
        $delta    = round($linesTotal - $invoiceTotalHt, 2);
        $deltaAbs = abs($delta);
        $pct      = $invoiceTotalHt > 0 ? $deltaAbs / $invoiceTotalHt : 0;
        if ($deltaAbs > 5 || $pct > 0.05) {
            $sign      = $delta >= 0 ? '+' : '';
            $deltaNote = " ⚠ delta={$sign}{$delta} CHF";
        }
    }

    // ── Build resolution note ─────────────────────────────────────────────────
    $lineCount      = count($validatedLines);
    $totalFormatted = number_format($linesTotal, 2, '.', '');
    $resolutionNote = "manual entry: {$lineCount} ligne" . ($lineCount > 1 ? 's' : '')
                      . ", total HT {$totalFormatted} CHF" . $deltaNote;

    // ── Transaction ────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $currency   = $invRow['currency']    ?? 'CHF';
    $invRef     = $invRow['invoice_ref'] ?? null;
    $invDate    = $invRow['invoice_date'] ?? null;
    $supplierRaw = $invRow['supplier_name'] ?? null;
    $supplierFk  = $invRow['supplier_fk']   ?? null;
    $invDbId     = $invRow['inv_id']        ?? null;

    foreach ($validatedLines as $lineIdx => $vl) {
        // 1. INSERT into doc_invoice_lines
        if ($invDbId !== null) {
            // Compute row_hash for doc_invoice_lines dedup
            $hashInput = implode('|', [
                (string)$invDbId,
                (string)$lineIdx,
                $vl['description'],
                (string)$vl['qty'],
                (string)$vl['unit_price'],
                'manual-triage',
            ]);
            $lineRowHash = hash('sha256', $hashInput);

            $ilStmt = $pdo->prepare(
                "INSERT IGNORE INTO doc_invoice_lines
                    (invoice_id, line_index, ingredient_name, description,
                     mi_id_fk, qty, unit, unit_price, line_total,
                     name_confidence, price_confidence, row_hash)
                 VALUES
                    (?, ?, ?, ?,
                     ?, ?, 'unit', ?, ?,
                     1.000, 1.000, ?)"
            );
            $ilStmt->execute([
                $invDbId,
                $lineIdx,
                $vl['description'],
                $vl['description'],
                $vl['mi_internal_id'],
                $vl['qty'],
                $vl['unit_price'],
                $vl['line_total'],
                $lineRowHash,
            ]);
        }

        // 2. INSERT into inv_deliveries via shared helper (idempotent)
        ta_materialize_delivery($pdo, [
            'rq_id'          => $rqId,
            'line_index'     => $lineIdx,
            'mi_internal_id' => $vl['mi_internal_id'],
            'mi_id_str'      => $vl['mi_id'],
            'description'    => $vl['description'],
            'qty'            => $vl['qty'],
            'unit_price'     => $vl['unit_price'],
            'invoice_id'     => $invDbId,
            'invoice_ref'    => $invRef,
            'invoice_date'   => $invDate,
            'supplier_raw'   => $supplierRaw,
            'supplier_fk'    => $supplierFk,
            'currency'       => $currency,
            'source'         => 'manual-triage',
            'source_origin'  => 'web',
            'file_id_fk'     => !empty($rqRow['file_id_fk']) ? (int)$rqRow['file_id_fk'] : null,
        ]);

        // 3. Register alias for future matching (idempotent — INSERT IGNORE)
        $aliasIns = $pdo->prepare(
            "INSERT IGNORE INTO ref_mi_aliases (alias, mi_id_fk) VALUES (?, ?)"
        );
        $aliasIns->execute([$vl['description'], $vl['mi_internal_id']]);
    }

    // 3. Close the RQ row
    $closeStmt = $pdo->prepare(
        "UPDATE doc_review_queue
            SET status          = 'resolved',
                decision        = 'manual',
                decided_at      = NOW(),
                decided_by      = ?,
                resolution_note = ?,
                updated_at      = NOW()
          WHERE id = ?"
    );
    $closeStmt->execute([$me['username'], $resolutionNote, $rqId]);

    // Stamp validated_at on the parent invoice so it drops out of the
    // À-valider list. manual-lines always closes the full RQ row in one step.
    // validated_by is INT UNSIGNED FK to users.id — the operator who
    // resolved the lines is the validator.
    if ($invRow !== null) {
        $pdo->prepare(
            "UPDATE doc_invoices
                SET validated_at = NOW(),
                    validated_by = ?
              WHERE id = ? AND validated_at IS NULL"
        )->execute([(int)$me['id'], (int)$invRow['inv_id']]);
    }

    $pdo->commit();

    $flashRef = ($invRef !== null && $invRef !== '') ? " · {$invRef}" : '';
    $_SESSION['triage_flash'] = [
        'type' => 'ok',
        'msg'  => "✓ Facture enregistrée{$flashRef} : {$lineCount} ligne" . ($lineCount > 1 ? 's' : '')
                  . ", total {$totalFormatted} CHF."
                  . ($deltaNote !== '' ? ' ⚠ Écart avec le total facture — vérifier.' : ''),
    ];

    $redirectUrl = ta_redirect_url($rqId, true, $pdo);
    header('Location: ' . $redirectUrl, true, 303);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    $_SESSION['triage_flash'] = [
        'type' => 'err',
        'msg'  => 'Erreur lors de la sauvegarde : ' . $e->getMessage(),
    ];
    header('Location: /modules/triage.php?tab=docs&rq_id=' . $rqId, true, 303);
    exit;
}
