<?php
declare(strict_types=1);
/**
 * /modules/email-orders.php — Commandes e-mail validation (Model B).
 *
 * Shows THREE buckets by doc_email_messages.parse_status:
 *   parsed       → REVIEW CARD: raw body + editable candidate form (human resolves
 *                  customer/SKU/date from hints — never auto-resolved to FK).
 *   no_match/error → "Non parsé" bucket: raw body + parse_error, no form.
 *   order_created  → Archived/done list.
 *
 * POST "Valider" action (PRG):
 *   1. CSRF check (first, always).
 *   2. Server whitelist: customer_id ∈ active ref_customers,
 *      each line_sku_id ∈ active ref_skus, requested_date present,
 *      each line qty > 0. Refuse-don't-NULL — any unresolved FK is a hard error.
 *   3. Twin-check: if a probable eshop/Shopify order exists for the same date,
 *      warn and do NOT auto-create.
 *   4. Atomic transaction:
 *        INSERT ord_orders (source='maltytask', review_status='accepted', …)
 *        INSERT ord_order_lines (per resolved line)
 *        INSERT ord_order_status_events (status='entered')
 *        UPDATE doc_email_messages SET parse_status='order_created'
 *      With log_revision() on each write.
 *      Idempotency guard: source_email_id_fk + source='maltytask' (source_ref set post-INSERT).
 *   5. BC push (disarmed — email_order_bc_push_mode=off by default; armed = live POST to BC).
 *   6. Redirect with success flash (PRG).
 *
 * NO COGS/fg_stock writes.
 *
 * Auth:  require_page_access('email-orders')
 * Write: is_admin($me) || manager_can('logistics', $me)
 * CSS:   /css/email-orders.css
 * JS:    /js/email-orders.js
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/email-order-promote.php';

require_page_access('email-orders');
$me            = current_user();

// ── ?counts=1 lightweight poll endpoint ──────────────────────────────────
if (isset($_GET['counts'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $pdo2 = maltytask_pdo();
    $counts = $pdo2->query(
        "SELECT
           SUM(parse_status='parsed') AS parsed,
           SUM(parse_status IN ('no_match','error','unparsed')) AS no_match,
           SUM(parse_status IN ('order_created','reconciled')) AS done
         FROM doc_email_messages"
    )->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'parsed'   => (int)($counts['parsed'] ?? 0),
        'no_match' => (int)($counts['no_match'] ?? 0),
        'done'     => (int)($counts['done'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$active_module = 'email-orders';

// ── Write-scope flag ──────────────────────────────────────────────────────────
// Built before the POST handler (used in both paths).
$canWrite = is_admin($me) || manager_can('logistics', $me);

$pdo = maltytask_pdo();

// ── Active customer set (whitelist — built before POST handler) ───────────────
$custRows = $pdo->query(
    'SELECT id, name FROM ref_customers WHERE is_active = 1 ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);
$activeCustIds = [];
foreach ($custRows as $cr) {
    $activeCustIds[(int) $cr['id']] = $cr['name'];
}

// ── Active SKU set (whitelist — built before POST handler) ────────────────────
$skuRows = $pdo->query(
    'SELECT id, sku_code, beer_raw, unit_label, hl_per_unit, units_per_pack, stocktake_scope
       FROM ref_skus
      WHERE is_active = 1
      ORDER BY sku_code'
)->fetchAll(PDO::FETCH_ASSOC);
$activeSkuIds = [];
foreach ($skuRows as $sr) {
    $activeSkuIds[(int) $sr['id']] = $sr;
}

// ── POST handler (validate and promote to ord_orders) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {

    // ── 1. CSRF — must be first ───────────────────────────────────────────────
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/email-orders.php');
    }

    $action     = trim((string) ($_POST['action'] ?? 'validate'));
    if (!in_array($action, ['archive', 'validate', 'force_create', 'validate_multi'], true)) {
        flash_set('err', 'Action non reconnue.');
        redirect_to('/modules/email-orders.php');
    }
    $emailMsgId = (int) ($_POST['email_msg_id'] ?? 0);

    // ── Archive handler (no line validation needed) ───────────────────────────
    if ($action === 'archive') {
        if ($emailMsgId <= 0) {
            flash_set('err', 'Identifiant de message invalide.');
            redirect_to('/modules/email-orders.php');
        }
        $bcId = (int) ($_POST['bc_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, parse_status, bc_matched_order_id FROM doc_email_messages WHERE id = ? FOR UPDATE');
            $stmt->execute([$emailMsgId]);
            $archRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$archRow) {
                $pdo->rollBack();
                flash_set('err', 'Message introuvable.');
                redirect_to('/modules/email-orders.php');
            }
            if (in_array($archRow['parse_status'], ['order_created', 'reconciled'], true)) {
                $pdo->rollBack();
                flash_set('ok', 'Message déjà traité.');
                redirect_to('/modules/email-orders.php');
            }
            $beforeState = ['parse_status' => $archRow['parse_status'], 'bc_matched_order_id' => $archRow['bc_matched_order_id']];
            $bcIdToStore = null;
            if ($bcId > 0) {
                $bcChk = $pdo->prepare("SELECT id FROM ord_orders WHERE id = ? AND source = 'bc' LIMIT 1");
                $bcChk->execute([$bcId]);
                if (!$bcChk->fetchColumn()) {
                    $pdo->rollBack();
                    flash_set('err', "Commande BC #{$bcId} introuvable — rapprochement annulé.");
                    redirect_to('/modules/email-orders.php');
                }
                $bcIdToStore = $bcId;
            }
            $updStmt = $pdo->prepare("UPDATE doc_email_messages SET parse_status = 'reconciled', bc_matched_order_id = ? WHERE id = ?");
            $updStmt->execute([$bcIdToStore, $emailMsgId]);
            log_revision($pdo, $me, 'doc_email_messages', $emailMsgId,
                $beforeState,
                ['parse_status' => 'reconciled', 'bc_matched_order_id' => $bcIdToStore],
                'normal'
            );
            $pdo->commit();
            $label = $bcId > 0 ? "Commande rapprochée avec BC #{$bcId}." : "Commande archivée sans correspondance BC.";
            flash_set('ok', $label);
            redirect_to('/modules/email-orders.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[email-orders archive] ' . $e->getMessage());
            flash_set('err', 'Erreur : ' . pdo_friendly_error($e));
            redirect_to('/modules/email-orders.php');
        }
    }

    // ── validate_multi handler ────────────────────────────────────────────────
    if ($action === 'validate_multi') {
        $rawSubs = $_POST['sub'] ?? [];
        if (!is_array($rawSubs) || count($rawSubs) < 1) {
            flash_set('err', 'Aucune sous-commande reçue.');
            redirect_to('/modules/email-orders.php');
        }

        $subOrders  = [];
        $errors     = [];
        $allowTwin  = !empty($_POST['confirm_twin']);

        if ($emailMsgId <= 0) {
            $errors[] = 'Identifiant de message invalide.';
        }

        foreach ($rawSubs as $si => $sub) {
            $custId  = (int) ($sub['customer_id'] ?? 0);
            $reqDate = trim((string) ($sub['requested_date'] ?? ''));
            $skuIds  = $sub['line_sku_id'] ?? [];
            $qtys    = $sub['line_qty']    ?? [];

            if ($custId <= 0 || !isset($activeCustIds[$custId])) {
                $errors[] = 'Commande ' . ((int)$si + 1) . ' : client non résolu.';
            }
            if ($reqDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
                $errors[] = 'Commande ' . ((int)$si + 1) . ' : date requise (AAAA-MM-JJ).';
            }
            $lines = [];
            if (!is_array($skuIds) || count($skuIds) === 0) {
                $errors[] = 'Commande ' . ((int)$si + 1) . ' : au moins une ligne requise.';
            } else {
                foreach ($skuIds as $li => $rawSkuId) {
                    $skuId = (int) ($rawSkuId ?? 0);
                    $qty   = (float) ($qtys[$li] ?? 0);
                    if ($skuId <= 0) continue;
                    if (!isset($activeSkuIds[$skuId])) {
                        $errors[] = 'Commande ' . ((int)$si + 1) . ' ligne ' . ((int)$li + 1) . ' : SKU introuvable.';
                        continue;
                    }
                    if ($qty <= 0) {
                        $errors[] = 'Commande ' . ((int)$si + 1) . ' ligne ' . ((int)$li + 1) . ' : quantité > 0 requise.';
                        continue;
                    }
                    $lines[] = ['sku_id' => $skuId, 'qty' => $qty, 'comment' => null];
                }
                if (count($lines) === 0 && count($errors) === 0) {
                    $errors[] = 'Commande ' . ((int)$si + 1) . ' : aucune ligne valide.';
                }
            }
            $subOrders[] = [
                'customer_id'    => $custId,
                'requested_date' => $reqDate,
                'lines'          => $lines,
                'comment'        => null,
            ];
        }

        if (!empty($errors)) {
            flash_set('err', implode(' — ', $errors));
            redirect_to('/modules/email-orders.php');
        }

        try {
            $newOrderIds = email_order_promote_multi($pdo, $me, $subOrders, $emailMsgId, $allowTwin);
            $idList = '#' . implode(', #', $newOrderIds);
            flash_set('ok', count($newOrderIds) . ' commande(s) créée(s) (' . $idList . ').');
            redirect_to('/modules/email-orders.php');
        } catch (EmailOrderAlreadyPromotedException $e) {
            flash_set('ok', 'Commandes déjà créées depuis cet e-mail (aucune action nécessaire).');
            redirect_to('/modules/email-orders.php');
        } catch (EmailOrderTwinException $e) {
            flash_set('err', '⚠ Doublon eshop possible : ' . $e->getMessage());
            redirect_to('/modules/email-orders.php');
        } catch (EmailOrderNoCustomerException | EmailOrderInvalidLineException | EmailOrderNotParsedException $e) {
            flash_set('err', 'Erreur de validation : ' . $e->getMessage());
            redirect_to('/modules/email-orders.php');
        } catch (Throwable $e) {
            error_log('[email-orders multi POST] ' . $e->getMessage());
            flash_set('err', 'Erreur lors de la création : ' . pdo_friendly_error($e));
            redirect_to('/modules/email-orders.php');
        }
    }
    // End validate_multi

    // ── 2. Coerce inputs ──────────────────────────────────────────────────────
    $custIdRaw   = (int) ($_POST['customer_id']  ?? 0);
    $reqDate     = trim((string) ($_POST['requested_date'] ?? ''));
    $lineSkuIds  = $_POST['line_sku_id'] ?? [];
    $lineQtys    = $_POST['line_qty']    ?? [];
    $allowTwin   = !empty($_POST['confirm_twin']);

    // ── 3. Module-layer whitelist validation (defense in depth) ──────────────
    // The helper also validates, but the module owns the active-whitelist gate
    // (customer ∈ active ref_customers, each SKU ∈ active ref_skus).
    $errors = [];

    if ($emailMsgId <= 0) {
        $errors[] = 'Identifiant de message invalide.';
    }

    if ($custIdRaw <= 0 || !isset($activeCustIds[$custIdRaw])) {
        $errors[] = 'Client non résolu — sélectionne un client dans la liste.';
    }

    if ($reqDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
        $errors[] = 'Date de livraison requise (format AAAA-MM-JJ).';
    }

    // Build $lines in the shape the helper expects.
    $lines = [];
    if (!is_array($lineSkuIds) || count($lineSkuIds) === 0) {
        $errors[] = 'Au moins une ligne article est requise.';
    } else {
        foreach ($lineSkuIds as $i => $rawSkuId) {
            $skuId = (int) ($rawSkuId ?? 0);
            $qty   = (float) ($lineQtys[$i] ?? 0);

            if ($skuId <= 0) continue; // blank picker row — skip
            if (!isset($activeSkuIds[$skuId])) {
                $errors[] = 'Ligne ' . ((int)$i + 1) . ' : SKU introuvable.';
                continue;
            }
            if ($qty <= 0) {
                $errors[] = 'Ligne ' . ((int)$i + 1) . ' : quantité doit être > 0.';
                continue;
            }
            $lines[] = ['sku_id' => $skuId, 'qty' => $qty];
        }
        if (count($lines) === 0 && count($errors) === 0) {
            $errors[] = 'Au moins une ligne article valide est requise.';
        }
    }

    if (!empty($errors)) {
        flash_set('err', implode(' — ', $errors));
        redirect_to('/modules/email-orders.php');
    }

    // ── 4. BC dedup gate (action=validate only) + promote ────────────────────
    if ($action === 'validate') {
        // Shell out to bc_order_match.py --match-one
        $pythonBin     = '/var/www/maltytask/.venv/bin/python';
        $matcherScript = '/var/www/maltytask/scripts/python/bc_order_match.py';
        $cmd = sprintf(
            '%s %s --match-one --email-id %d --customer-id %d 2>/dev/null',
            escapeshellcmd($pythonBin),
            escapeshellarg($matcherScript),
            $emailMsgId,
            $custIdRaw
        );
        $matchJson     = null;
        $matchExitCode = -1;
        $descriptors   = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            // Non-blocking reads so a silent/hung subprocess can't block past the
            // deadline (fail-closed: a hang must fall through to "indisponible").
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout   = '';
            $deadline = microtime(true) + 20;
            $timedOut = false;
            while (true) {
                if (microtime(true) >= $deadline) { $timedOut = true; break; }
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $stdout .= $chunk;
                    continue;
                }
                $st = proc_get_status($proc);
                if (!$st['running'] && feof($pipes[1])) break;
                usleep(50000); // 50ms
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            if ($timedOut) {
                proc_terminate($proc, 9);
                proc_close($proc);
                $matchExitCode = -1; // forces the fail-closed branch below
            } else {
                $matchExitCode = proc_close($proc);
                if ($matchExitCode === 0) {
                    $matchJson = json_decode($stdout, true);
                }
            }
        }

        if ($matchJson !== null && ($matchJson['status'] ?? '') === 'matched') {
            maltytask_session_start();
            $_SESSION['eo_bc_interstitial_' . $emailMsgId] = [
                'bc_id'           => (int) ($matchJson['bc_id'] ?? 0),
                'external_doc_no' => (string) ($matchJson['bc_order']['external_document_no'] ?? ''),
                'order_date'      => (string) ($matchJson['bc_order']['order_date'] ?? ''),
                'total'           => $matchJson['bc_order']['total'] ?? null,
                'customer_id'     => $custIdRaw,
                'requested_date'  => $reqDate,
                'line_sku_ids'    => $lineSkuIds,
                'line_qtys'       => $lineQtys,
            ];
            flash_set('warn', 'Correspondance BC détectée — confirme l\'action ci-dessous.');
            redirect_to('/modules/email-orders.php');
        } elseif ($matchJson === null || $matchExitCode !== 0) {
            maltytask_session_start();
            $_SESSION['eo_bc_check_failed_' . $emailMsgId] = [
                'customer_id'    => $custIdRaw,
                'requested_date' => $reqDate,
                'line_sku_ids'   => $lineSkuIds,
                'line_qtys'      => $lineQtys,
            ];
            flash_set('warn', 'Vérification BC indisponible — décision manuelle requise.');
            redirect_to('/modules/email-orders.php');
        }
        // status=unmatched → fall through to email_order_promote below
    }
    // action=force_create also falls through here

    // ── 4c. Final dedup gate for force_create (validate already ran step 4) ──
    if ($action === 'force_create') {
        $pythonBin     = '/var/www/maltytask/.venv/bin/python';
        $matcherScript = '/var/www/maltytask/scripts/python/bc_order_match.py';
        $fcCmd = sprintf(
            '%s %s --match-one --email-id %d --customer-id %d 2>/dev/null',
            escapeshellcmd($pythonBin),
            escapeshellarg($matcherScript),
            $emailMsgId,
            $custIdRaw
        );
        $fcMatchJson = null; $fcMatchExit = -1;
        $fcDesc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $fcProc = proc_open($fcCmd, $fcDesc, $fcPipes);
        if (is_resource($fcProc)) {
            fclose($fcPipes[0]);
            stream_set_blocking($fcPipes[1], false);
            stream_set_blocking($fcPipes[2], false);
            $fcOut = ''; $fcDeadline = microtime(true) + 20; $fcTimedOut = false;
            while (true) {
                if (microtime(true) >= $fcDeadline) { $fcTimedOut = true; break; }
                $fcChunk = fread($fcPipes[1], 8192);
                if ($fcChunk !== false && $fcChunk !== '') { $fcOut .= $fcChunk; continue; }
                $fcSt = proc_get_status($fcProc);
                if (!$fcSt['running'] && feof($fcPipes[1])) break;
                usleep(50000);
            }
            fclose($fcPipes[1]); fclose($fcPipes[2]);
            if ($fcTimedOut) { proc_terminate($fcProc, 9); proc_close($fcProc); $fcMatchExit = -1; }
            else { $fcMatchExit = proc_close($fcProc); if ($fcMatchExit === 0) $fcMatchJson = json_decode($fcOut, true); }
        }
        if ($fcMatchJson !== null && ($fcMatchJson['status'] ?? '') === 'matched') {
            $fcBcId = (int) ($fcMatchJson['bc_id'] ?? 0);
            maltytask_session_start();
            $_SESSION['eo_bc_interstitial_' . $emailMsgId] = [
                'bc_id'           => $fcBcId,
                'external_doc_no' => (string) ($fcMatchJson['bc_order']['external_document_no'] ?? ''),
                'order_date'      => (string) ($fcMatchJson['bc_order']['order_date'] ?? ''),
                'total'           => $fcMatchJson['bc_order']['total'] ?? null,
                'customer_id'     => $custIdRaw,
                'requested_date'  => $reqDate,
                'line_sku_ids'    => $lineSkuIds,
                'line_qtys'       => $lineQtys,
            ];
            flash_set('warn', 'Correspondance BC détectée au moment de la création — confirme l\'action ci-dessous.');
            redirect_to('/modules/email-orders.php');
        } elseif ($fcMatchJson === null || $fcMatchExit !== 0) {
            maltytask_session_start();
            $_SESSION['eo_bc_check_failed_' . $emailMsgId] = [
                'customer_id'    => $custIdRaw,
                'requested_date' => $reqDate,
                'line_sku_ids'   => $lineSkuIds,
                'line_qtys'      => $lineQtys,
            ];
            flash_set('warn', 'Vérification BC indisponible — décision manuelle requise.');
            redirect_to('/modules/email-orders.php');
        }
        // status=unmatched → fall through to promote
    }

    // ── 4b. bc_customer_no guard — customer must exist in BC before we push ──
    $bcCustNoStmt = $pdo->prepare('SELECT bc_customer_no FROM ref_customers WHERE id = ? LIMIT 1');
    $bcCustNoStmt->execute([$custIdRaw]);
    $bcCustNoRow = $bcCustNoStmt->fetch(PDO::FETCH_ASSOC);
    if (!$bcCustNoRow || empty($bcCustNoRow['bc_customer_no'])) {
        flash_set('err', 'Client sans compte BC — à créer dans BC d\'abord.');
        redirect_to('/modules/email-orders.php');
    }

    // ── 5. Delegate to the canonical helper ──────────────────────────────────
    // email_order_promote() owns: FOR UPDATE row-locking on doc_email_messages,
    // idempotency via source_email_id_fk + source='maltytask' check, the Shopify-pickup
    // twin-check (customer_email + SKU overlap + ±7d), and all ord_orders writes.
    try {
        $newOrderId = email_order_promote(
            $pdo,
            $me,
            $emailMsgId,
            $custIdRaw,
            $reqDate,
            $lines,
            null,       // order-level comment — no form field yet
            $allowTwin
        );

        // ── 6. BC push ────────────────────────────────────────────────────────
        $bcPushMode = (string) system_setting('email_order_bc_push_mode', 'logistics', 'off');
        $pythonBin  = '/var/www/maltytask/.venv/bin/python';
        $pushScript = '/var/www/maltytask/scripts/python/push_bc_sales_orders.py';
        if ($bcPushMode === 'armed') {
            $pushCmd = sprintf('%s %s --apply --i-have-kouros-go 2>&1',
                escapeshellcmd($pythonBin), escapeshellarg($pushScript));
        } else {
            $pushCmd = sprintf('%s %s 2>&1',
                escapeshellcmd($pythonBin), escapeshellarg($pushScript));
        }
        $pushOutput = ''; $pushExitCode = -1;
        // stderr merged into stdout via 2>&1 in $pushCmd; pipe[2] unused → /dev/null.
        $pushDesc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file', '/dev/null', 'w']];
        $pushProc = proc_open($pushCmd, $pushDesc, $pushPipes);
        if (is_resource($pushProc)) {
            fclose($pushPipes[0]);
            stream_set_blocking($pushPipes[1], false);
            $pushDeadline = microtime(true) + 20; $pushTimedOut = false;
            while (true) {
                if (microtime(true) >= $pushDeadline) { $pushTimedOut = true; break; }
                $pushChunk = fread($pushPipes[1], 8192);
                if ($pushChunk !== false && $pushChunk !== '') { $pushOutput .= $pushChunk; continue; }
                $pushSt = proc_get_status($pushProc);
                if (!$pushSt['running'] && feof($pushPipes[1])) break;
                usleep(50000);
            }
            fclose($pushPipes[1]);
            if ($pushTimedOut) { proc_terminate($pushProc, 9); proc_close($pushProc); $pushExitCode = -1; }
            else { $pushExitCode = proc_close($pushProc); }
        }
        error_log('[email-orders BC push] mode=' . $bcPushMode . ' order=' . $newOrderId
            . ' exit=' . $pushExitCode . ' output=' . substr($pushOutput, 0, 500));

        maltytask_session_start();
        unset($_SESSION['eo_twin_pending_id']);

        if ($bcPushMode === 'armed') {
            if ($pushExitCode === 0) {
                flash_set('ok', 'Commande #' . $newOrderId . ' créée et envoyée vers BC.');
            } else {
                flash_set('warn', 'Commande #' . $newOrderId . ' créée localement. Envoi BC échoué — voir les logs.');
            }
        } else {
            flash_set('ok', 'Commande #' . $newOrderId . ' créée localement (envoi BC en attente — mode désarmé).');
        }
        redirect_to('/modules/email-orders.php');

    } catch (EmailOrderTwinException $e) {
        // Store the email id so the GET render can reveal the confirm_twin affordance
        // on exactly the card that triggered the warning.
        maltytask_session_start();
        $_SESSION['eo_twin_pending_id'] = $emailMsgId;
        flash_set('err', '⚠ Doublon eshop possible — cochez "Confirmer malgré le doublon" sur cette commande pour forcer la création.');
        redirect_to('/modules/email-orders.php');

    } catch (EmailOrderAlreadyPromotedException $e) {
        // Idempotent: the order exists — treat as success.
        // Heal any partial-failure where the ord_orders committed but the email
        // status flip did not (e.g. old network/process kill between steps 8 and 9).
        try {
            $pdo->prepare(
                "UPDATE doc_email_messages SET parse_status = 'order_created' WHERE id = ? AND parse_status = 'parsed'"
            )->execute([$emailMsgId]);
        } catch (Throwable $_) { /* non-fatal heal attempt */ }
        maltytask_session_start();
        unset($_SESSION['eo_twin_pending_id']);
        flash_set('ok', 'Commande déjà créée depuis cet e-mail (aucune action nécessaire).');
        redirect_to('/modules/email-orders.php');

    } catch (EmailOrderNoCustomerException | EmailOrderInvalidLineException | EmailOrderNotParsedException $e) {
        flash_set('err', 'Erreur de validation : ' . $e->getMessage());
        redirect_to('/modules/email-orders.php');

    } catch (Throwable $e) {
        error_log('[email-orders POST] ' . $e->getMessage());
        flash_set('err', 'Erreur lors de la création : ' . pdo_friendly_error($e));
        redirect_to('/modules/email-orders.php');
    }
}

// ── Twin-pending state (survives the PRG; cleared on next successful submit) ──────
// When a submit throws EmailOrderTwinException, the POST handler stores the email id
// in the session so the GET render can reveal the confirm_twin affordance on that card.
maltytask_session_start();
$twinPendingEmailId = isset($_SESSION['eo_twin_pending_id'])
    ? (int) $_SESSION['eo_twin_pending_id']
    : 0;
// Pop it — if the operator does anything else (submits another card, refreshes),
// the affordance disappears naturally. The session key is re-set on each twin hit.
unset($_SESSION['eo_twin_pending_id']);

// Pop BC interstitial / check-failed states
$bcInterstitialStates = [];
$bcCheckFailedStates  = [];
foreach ($_SESSION as $key => $val) {
    if (str_starts_with($key, 'eo_bc_interstitial_')) {
        $id = (int) substr($key, strlen('eo_bc_interstitial_'));
        $bcInterstitialStates[$id] = $val;
        unset($_SESSION[$key]);
    } elseif (str_starts_with($key, 'eo_bc_check_failed_')) {
        $id = (int) substr($key, strlen('eo_bc_check_failed_'));
        $bcCheckFailedStates[$id] = $val;
        unset($_SESSION[$key]);
    }
}

// ── Load data for GET render ───────────────────────────────────────────────────

// Fetch all email messages grouped by parse_status, newest first
$allEmailsStmt = $pdo->query(
    "SELECT id, message_id, from_address, original_sender, subject, received_at,
            raw_body, parse_status, parse_error, bc_matched_order_id, parsed_json,
            created_at, updated_at
       FROM doc_email_messages
      ORDER BY created_at DESC"
);
$allEmails = $allEmailsStmt->fetchAll(PDO::FETCH_ASSOC);

$parsedEmails  = [];
$unparsedEmails = []; // no_match + error
$doneEmails    = [];

foreach ($allEmails as $em) {
    $status = $em['parse_status'] ?? 'unparsed';
    if ($status === 'parsed') {
        // Decode parsed_json
        $hints = null;
        if (!empty($em['parsed_json'])) {
            $hints = json_decode($em['parsed_json'], true);
        }
        $em['_hints'] = $hints;
        $parsedEmails[] = $em;
    } elseif (in_array($status, ['no_match', 'error', 'unparsed'], true)) {
        $unparsedEmails[] = $em;
    } elseif (in_array($status, ['order_created', 'reconciled'], true)) {
        $doneEmails[] = $em;
    }
}

// Load BC state (ord_orders.bc_no + source_ref) for done emails, keyed by email id.
$doneEmailIds = array_column($doneEmails, 'id');
$bcStateByEmailId = [];
if (!empty($doneEmailIds)) {
    $inPh = implode(',', array_fill(0, count($doneEmailIds), '?'));
    $bcStmt = $pdo->prepare(
        "SELECT source_email_id_fk, bc_no, source_ref FROM ord_orders
          WHERE source = 'maltytask' AND source_email_id_fk IN ({$inPh})"
    );
    $bcStmt->execute($doneEmailIds);
    foreach ($bcStmt->fetchAll(PDO::FETCH_ASSOC) as $bcRow) {
        $bcStateByEmailId[(int)$bcRow['source_email_id_fk']] = $bcRow;
    }
}

// ── SKU human-label builder ───────────────────────────────────────────────
function eo_make_sku_label(string $beerRaw, string $unitLabel, string $skuCode): string {
    $ul = strtolower($unitLabel);
    $beer = trim($beerRaw);

    // Derive French format string from unit_label
    $fmt = '';

    if (str_contains($ul, 'keg')) {
        // Extract size e.g. "1 keg (20L)" → "20 L"
        if (preg_match('/\((\d+)\s*l\)/i', $unitLabel, $m)) {
            $fmt = 'Fût ' . $m[1] . ' L';
        } else {
            $fmt = 'Fût';
        }
    } elseif (str_contains($ul, 'cuve') || str_contains($ul, 'cuv')) {
        $fmt = 'Cuve de service';
    } elseif (str_contains($ul, '6×4') || str_contains($ul, '6x4') || str_contains($ul, '6*4')) {
        $fmt = 'Pack 6×4 (24 × 33 cl)';
    } elseif (str_contains($ul, '24-pack') || (str_contains($ul, '24') && str_contains($ul, 'pack') && str_contains($ul, 'box'))) {
        $fmt = 'Boîte 24 × 33 cl';
    } elseif (str_contains($ul, '12-pack') || (str_contains($ul, '12') && str_contains($ul, 'pack') && str_contains($ul, 'box'))) {
        $fmt = 'Boîte 12 × 33 cl';
    } elseif (str_contains($ul, '4-pack') || (str_contains($ul, '4') && str_contains($ul, 'pack') && str_contains($ul, 'loose'))) {
        $fmt = 'Pack 4 × 33 cl';
    } elseif (str_contains($ul, 'pack') && preg_match('/(\d+)\s*[×x\*]\s*(\d+)\s*cl/i', $unitLabel, $m)) {
        $fmt = 'Pack ' . $m[1] . ' × ' . $m[2] . ' cl';
    } elseif (str_contains($ul, 'can')) {
        if (preg_match('/\(?\s*(\d+)\s*cl\s*\)?/i', $unitLabel, $m)) {
            $fmt = 'Canette ' . $m[1] . ' cl';
        } else {
            $fmt = 'Canette';
        }
    } elseif (str_contains($ul, 'bottle') || str_contains($ul, 'bot')) {
        if (preg_match('/(\d+)\s*cl/i', $unitLabel, $m)) {
            $fmt = 'Bouteille ' . $m[1] . ' cl';
        } else {
            $fmt = 'Bouteille';
        }
    } elseif (str_contains($ul, 'draft pour') || str_contains($ul, 'pour')) {
        if (preg_match('/(\d+)\s*cl/i', $unitLabel, $m)) {
            $fmt = 'Pression ' . $m[1] . ' cl';
        } else {
            $fmt = 'Pression';
        }
    } elseif (str_contains($ul, 'crate')) {
        if (preg_match('/(\d+)/i', $unitLabel, $m)) {
            $fmt = 'Caisse ' . $m[1];
        } else {
            $fmt = 'Caisse';
        }
    } else {
        // Fallback: use raw unit_label or sku_code
        $fmt = $unitLabel !== '' ? $unitLabel : $skuCode;
    }

    if ($beer !== '') {
        return $beer . ' — ' . $fmt;
    }
    return $fmt !== '' ? $fmt : $skuCode;
}

// ── JSON hydration for JS ─────────────────────────────────────────────────────
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

$customersForJs = array_values(array_map(fn($cr) => [
    'id'   => (int) $cr['id'],
    'name' => $cr['name'],
], $custRows));

$skusForJs = array_values(array_map(fn($sr) => [
    'id'              => (int) $sr['id'],
    'sku_code'        => $sr['sku_code'],
    'label'           => eo_make_sku_label((string)($sr['beer_raw'] ?? ''), (string)($sr['unit_label'] ?? ''), $sr['sku_code']),
    'hl_per_unit'     => (float) ($sr['hl_per_unit'] ?? 0),
    'units_per_pack'  => (int) ($sr['units_per_pack'] ?? 1),
    'stocktake_scope' => $sr['stocktake_scope'] ?? '',
], $skuRows));

// ── Flash pop for render ──────────────────────────────────────────────────────
$flash = flash_pop();
$flashType = $flash['type'] ?? null;
$flashMsg  = $flash['msg'] ?? '';

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Commandes e-mail — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/email-orders.css?v=<?= @filemtime(__DIR__ . '/../css/email-orders.css') ?: time() ?>">
  <script>
    window.EMAIL_CUSTOMERS = <?= json_encode($customersForJs, $jsonFlags) ?>;
    window.EMAIL_SKUS      = <?= json_encode($skusForJs, $jsonFlags) ?>;
  </script>
</head>
<body class="home email-orders-page">

<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <!-- ── New-orders poll banner ──────────────────────────────────────────────── -->
  <div id="eo-new-orders-banner" class="eo-new-orders-banner" hidden aria-live="polite"></div>

  <!-- ── Flash ──────────────────────────────────────────────────────────────── -->
  <?php if ($flashType !== null): ?>
    <div class="eo-flash eo-flash--<?= htmlspecialchars($flashType, ENT_QUOTES | ENT_HTML5) ?>">
      <?= $flashType === 'ok' ? '✓' : '⚠' ?>
      <?= htmlspecialchars((string)$flashMsg, ENT_QUOTES | ENT_HTML5) ?>
    </div>
  <?php endif ?>

  <!-- ── Page header ────────────────────────────────────────────────────────── -->
  <div class="eo-header">
    <div class="eo-header__eyebrow">Logistique · Commandes</div>
    <h1 class="eo-header__title">Commandes <em>e-mail</em></h1>
    <p class="eo-header__sub">Validation des commandes parsées depuis commandes@lanebuleuse.ch — toujours résoudre client / SKU / date avant de valider.</p>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════════
       BUCKET 1 — À valider (parse_status='parsed')
       ════════════════════════════════════════════════════════════════════════ -->
  <section class="eo-section" aria-label="Commandes à valider">
    <div class="eo-section__header">
      <h2 class="eo-section__title">À valider</h2>
      <span id="eo-badge-review" class="eo-section__badge eo-badge--review"><?= count($parsedEmails) ?></span>
    </div>

    <?php if (empty($parsedEmails)): ?>
      <p class="eo-empty">Aucune commande parsée en attente de validation.</p>
    <?php else: ?>
      <?php foreach ($parsedEmails as $em): ?>
        <?php
          $hints        = $em['_hints'] ?? [];
          $kind         = $hints['_kind'] ?? 'parsed_order_hints';
          $fromAddr     = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
          $origSender   = htmlspecialchars($em['original_sender'] ?? '', ENT_QUOTES | ENT_HTML5);
          $subject      = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
          $receivedAt   = htmlspecialchars($em['received_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          $rawBody      = htmlspecialchars($em['raw_body'] ?? '', ENT_QUOTES | ENT_HTML5);
          $isTwinPending  = ($twinPendingEmailId > 0 && (int)$em['id'] === $twinPendingEmailId);
          $bcInterstitial = $bcInterstitialStates[(int)$em['id']] ?? null;
          $bcCheckFailed  = $bcCheckFailedStates[(int)$em['id']] ?? null;
        ?>

        <?php if ($kind === 'parsed_order_hints_multi'): ?>

        <!-- ── MULTI-ORDER CARD ──────────────────────────────────────────── -->
        <div class="eo-review-card"
             id="eo-card-<?= (int)$em['id'] ?>">
          <div class="eo-review-card__meta">
            <span><strong>De :</strong> <?= $fromAddr ?>
              <?php if ($origSender !== '' && stripos($em['from_address'] ?? '', $em['original_sender'] ?? '') === false): ?>
                <span class="eo-original-sender">Expéditeur réel : <?= $origSender ?></span>
              <?php endif ?>
            </span>
            <span><strong>Objet :</strong> <?= $subject ?></span>
            <?php if ($receivedAt): ?><span><strong>Reçu :</strong> <?= $receivedAt ?></span><?php endif ?>
          </div>

          <div class="eo-review-card__columns">
            <!-- Left: raw email body -->
            <div class="eo-review-card__raw">
              <div class="eo-review-card__raw-label">Corps de l'e-mail (brut)</div>
              <pre class="eo-review-card__raw-body"><?= $rawBody ?></pre>
            </div>

            <!-- Right: multi-order form -->
            <div class="eo-review-card__form">
              <div class="eo-review-card__form-label">Candidat multi — vérifier et résoudre</div>
              <?php if (!$canWrite): ?>
                <p style="font-family:'DM Sans',sans-serif;font-size:.85rem;color:var(--ink-mute);">
                  Lecture seule — vous n'avez pas les droits pour valider.
                </p>
              <?php else: ?>
              <form method="POST" action="/modules/email-orders.php">
                <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                <input type="hidden" name="action"       value="validate_multi">
                <input type="hidden" name="email_msg_id" value="<?= (int)$em['id'] ?>">

                <?php foreach (($hints['orders'] ?? []) as $si => $sub): ?>
                  <?php
                    $subCustHint  = htmlspecialchars($sub['customer_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
                    $subDateHint  = htmlspecialchars($sub['requested_date'] ?? '', ENT_QUOTES | ENT_HTML5);
                    $subNotes     = $sub['notes'] ?? '';
                    $subLineHints = $sub['lines'] ?? [];
                  ?>
                  <div class="eo-suborder" data-sub-index="<?= (int)$si ?>">
                    <div class="eo-suborder__heading">Commande <?= $si + 1 ?><span class="eo-progress-badge" data-sub-index="<?= $si ?>">0 / <?= 1 + count($subLineHints) ?> résolu</span></div>
                    <?php if ($subNotes !== ''): ?>
                      <div class="eo-suborder__notes"><?= htmlspecialchars($subNotes, ENT_QUOTES | ENT_HTML5) ?></div>
                    <?php endif ?>

                    <!-- Customer -->
                    <div class="eo-field">
                      <label class="eo-field__label eo-field__label--required">Client</label>
                      <div class="eo-cust-chips" data-cust-hint="<?= $subCustHint ?>" aria-label="Suggestions client"></div>
                      <div class="eo-cust-manual" hidden>
                        <div class="eo-typeahead-wrap">
                          <input type="text" class="eo-input eo-cust-search"
                                 placeholder="Rechercher un client…" autocomplete="off" value="">
                          <ul class="eo-typeahead-dropdown eo-cust-dropdown" role="listbox" aria-label="Clients" hidden></ul>
                        </div>
                      </div>
                      <button type="button" class="eo-cust-autre-btn">Autre…</button>
                      <div class="eo-cust-resolved" hidden>
                        <span class="eo-cust-resolved__label"></span>
                        <button type="button" class="eo-cust-resolved__clear" aria-label="Changer">✎</button>
                      </div>
                      <input type="hidden" name="sub[<?= (int)$si ?>][customer_id]" class="eo-customer-id" value="0">
                    </div>

                    <!-- Date -->
                    <div class="eo-field">
                      <label class="eo-field__label eo-field__label--required">Date de livraison souhaitée</label>
                      <?php if ($subDateHint): ?>
                        <div class="eo-field__hint">Indice parsé : « <?= $subDateHint ?> »</div>
                      <?php endif ?>
                      <input type="date"
                             name="sub[<?= (int)$si ?>][requested_date]"
                             class="eo-input eo-requested-date"
                             value="<?= $subDateHint ?>"
                             required>
                    </div>

                    <!-- Lines -->
                    <div class="eo-field">
                      <label class="eo-field__label eo-field__label--required">Lignes</label>
                      <div class="eo-lines">
                        <?php foreach ($subLineHints as $li => $lineHint): ?>
                          <?php
                            $skuHintEsc = htmlspecialchars($lineHint['sku_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
                            $rawEsc     = htmlspecialchars($lineHint['raw'] ?? '', ENT_QUOTES | ENT_HTML5);
                            $hintQty    = (float) ($lineHint['qty'] ?? 0);
                          ?>
                          <div class="eo-line-row" data-line-idx="<?= (int)$li ?>" data-sku-hint="<?= $skuHintEsc ?>">
                            <?php if ($skuHintEsc): ?>
                            <div class="eo-line-hint-row" style="grid-column:1/-1">
                              <span class="eo-line-hint-label">Indice : <em>«&nbsp;<?= $skuHintEsc ?>&nbsp;»</em></span>
                              <?php if ($hintQty > 0): ?><span class="eo-line-hint-qty">Qté parsée : <?= htmlspecialchars((string)$hintQty, ENT_QUOTES | ENT_HTML5) ?></span><?php endif ?>
                            </div>
                            <?php endif ?>
                            <div class="eo-sku-chips" aria-label="Suggestions SKU"></div>
                            <div class="eo-sku-manual" hidden>
                              <div class="eo-typeahead-wrap">
                                <input type="text" class="eo-input eo-sku-search" placeholder="Rechercher un SKU…" autocomplete="off" value="">
                                <ul class="eo-typeahead-dropdown eo-sku-dropdown" role="listbox" hidden></ul>
                              </div>
                            </div>
                            <button type="button" class="eo-autre-btn">Autre…</button>
                            <div class="eo-sku-resolved" hidden>
                              <span class="eo-sku-resolved__label"></span>
                              <button type="button" class="eo-sku-resolved__clear" aria-label="Changer">✎</button>
                            </div>
                            <input type="hidden" name="sub[<?= (int)$si ?>][line_sku_id][]" class="eo-sku-id" value="0">
                            <input type="number"
                                   name="sub[<?= (int)$si ?>][line_qty][]"
                                   class="eo-input eo-qty-input"
                                   min="0.01" step="0.5"
                                   value="<?= $hintQty > 0 ? htmlspecialchars((string)$hintQty, ENT_QUOTES | ENT_HTML5) : '' ?>"
                                   placeholder="Qté">
                            <button type="button" class="eo-line-remove" aria-label="Supprimer la ligne">×</button>
                          </div>
                        <?php endforeach ?>
                        <button type="button" class="eo-add-line-btn">＋ Ajouter une ligne</button>
                      </div>
                    </div>
                  </div><!-- /.eo-suborder -->
                <?php endforeach ?>

                <div class="eo-sticky-validate">
                  <div class="eo-sticky-validate__blocker"></div>
                  <button type="submit" class="eo-btn-validate" disabled>
                    ✓ Valider les <?= count($hints['orders'] ?? []) ?> commandes
                  </button>
                </div>
              </form>
              <?php endif ?>
            </div><!-- /.eo-review-card__form -->
          </div><!-- /.eo-review-card__columns -->
        </div><!-- /.eo-review-card (multi) -->

        <?php else: /* SINGLE-ORDER CARD */ ?>

        <!-- ── SINGLE-ORDER CARD ─────────────────────────────────────────── -->
        <?php
          $custHint     = htmlspecialchars($hints['customer_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
          $dateHint     = htmlspecialchars($hints['requested_date'] ?? '', ENT_QUOTES | ENT_HTML5);
          $notesHint    = htmlspecialchars($hints['notes'] ?? '', ENT_QUOTES | ENT_HTML5);
          $lineHints    = $hints['lines'] ?? [];
          // Internal-rep pre-fill
          $prefilledCustId   = 0;
          $prefilledCustName = '';
          if (!empty($hints['_internal_rep'])) {
              $repEmail = strtolower(trim((string)($hints['_rep_email'] ?? '')));
              if ($repEmail !== '') {
                  $repStmt = $pdo->prepare(
                      'SELECT r.customer_id_fk, c.name
                         FROM ref_internal_order_accounts r
                         JOIN ref_customers c ON c.id = r.customer_id_fk
                        WHERE r.sender_email = ?
                          AND r.is_active = 1
                        LIMIT 1'
                  );
                  $repStmt->execute([$repEmail]);
                  $repRow = $repStmt->fetch(PDO::FETCH_ASSOC);
                  if ($repRow) {
                      $prefilledCustId   = (int) $repRow['customer_id_fk'];
                      $prefilledCustName = htmlspecialchars($repRow['name'], ENT_QUOTES | ENT_HTML5);
                  }
              }
          }
        ?>
        <div class="eo-review-card<?= $isTwinPending ? ' eo-review-card--twin-pending' : '' ?>"
             id="eo-card-<?= (int)$em['id'] ?>"
             <?= $isTwinPending ? 'data-twin-pending="1"' : '' ?>>
          <!-- Card meta bar -->
          <div class="eo-review-card__meta">
            <span><strong>De :</strong> <?= $fromAddr ?>
              <?php if ($origSender !== '' && stripos($em['from_address'] ?? '', $em['original_sender'] ?? '') === false): ?>
                <span class="eo-original-sender">Expéditeur réel : <?= $origSender ?></span>
              <?php endif ?>
            </span>
            <span><strong>Objet :</strong> <?= $subject ?></span>
            <?php if ($receivedAt): ?><span><strong>Reçu :</strong> <?= $receivedAt ?></span><?php endif ?>
          </div>

          <!-- Two-column: raw body | validation form -->
          <div class="eo-review-card__columns">

            <!-- Left: raw email body -->
            <div class="eo-review-card__raw">
              <div class="eo-review-card__raw-label">Corps de l'e-mail (brut)</div>
              <pre class="eo-review-card__raw-body"><?= $rawBody ?></pre>
              <?php if (!empty($notesHint)): ?>
                <div class="eo-review-card__raw-label" style="margin-top:.75rem;">Notes parsées</div>
                <pre class="eo-review-card__raw-body"><?= $notesHint ?></pre>
              <?php endif ?>
            </div>

            <!-- Right: validation form -->
            <div class="eo-review-card__form">
              <div class="eo-review-card__form-label">Candidat — vérifier et résoudre</div>

              <?php if (!$canWrite): ?>
                <p style="font-family:'DM Sans',sans-serif;font-size:.85rem;color:var(--ink-mute);">
                  Lecture seule — vous n'avez pas les droits pour valider.
                </p>
              <?php elseif ($bcInterstitial !== null): ?>
                <?php
                  $bcDocNo    = htmlspecialchars($bcInterstitial['external_doc_no'] ?? '', ENT_QUOTES | ENT_HTML5);
                  $bcDate     = htmlspecialchars($bcInterstitial['order_date'] ?? '', ENT_QUOTES | ENT_HTML5);
                  $bcTotal    = $bcInterstitial['total'];
                  $intBcId    = (int) ($bcInterstitial['bc_id'] ?? 0);
                  $intCustId  = (int) ($bcInterstitial['customer_id'] ?? 0);
                  $intReqDate = htmlspecialchars($bcInterstitial['requested_date'] ?? '', ENT_QUOTES | ENT_HTML5);
                  $intSkuIds  = $bcInterstitial['line_sku_ids'] ?? [];
                  $intQtys    = $bcInterstitial['line_qtys'] ?? [];
                ?>
                <div class="eo-bc-interstitial">
                  <div class="eo-bc-interstitial__banner">
                    Cette commande ressemble à la commande BC #<?= $intBcId ?>
                    <?php if ($bcDate): ?> (<?= $bcDate ?>)<?php endif ?>
                    <?php if ($bcTotal !== null): ?> — <?= htmlspecialchars((string)$bcTotal, ENT_QUOTES | ENT_HTML5) ?> CHF<?php endif ?>
                    — déjà saisie&nbsp;?
                  </div>
                  <form method="POST" action="/modules/email-orders.php">
                    <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="email_msg_id" value="<?= (int)$em['id'] ?>">
                    <input type="hidden" name="customer_id"  value="<?= $intCustId ?>">
                    <input type="hidden" name="bc_id"        value="<?= $intBcId ?>">
                    <input type="hidden" name="requested_date" value="<?= $intReqDate ?>">
                    <?php foreach ($intSkuIds as $idx => $skuId): ?>
                      <input type="hidden" name="line_sku_id[]" value="<?= (int)$skuId ?>">
                      <input type="hidden" name="line_qty[]"    value="<?= htmlspecialchars((string)($intQtys[$idx] ?? 0), ENT_QUOTES | ENT_HTML5) ?>">
                    <?php endforeach ?>
                    <div class="eo-bc-interstitial__actions">
                      <button type="submit" name="action" value="archive" class="eo-btn-archive">
                        Archiver — déjà dans BC&nbsp;#<?= $intBcId ?>
                      </button>
                      <button type="submit" name="action" value="force_create" class="eo-btn-force-create">
                        Créer quand même
                      </button>
                    </div>
                  </form>
                </div>
              <?php elseif ($bcCheckFailed !== null): ?>
                <?php
                  $fcCustId  = (int) ($bcCheckFailed['customer_id'] ?? 0);
                  $fcReqDate = htmlspecialchars($bcCheckFailed['requested_date'] ?? '', ENT_QUOTES | ENT_HTML5);
                  $fcSkuIds  = $bcCheckFailed['line_sku_ids'] ?? [];
                  $fcQtys    = $bcCheckFailed['line_qtys'] ?? [];
                ?>
                <div class="eo-bc-interstitial eo-bc-interstitial--warn">
                  <div class="eo-bc-interstitial__banner eo-bc-interstitial__banner--warn">
                    Vérification BC indisponible — décision manuelle requise
                  </div>
                  <form method="POST" action="/modules/email-orders.php">
                    <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                    <input type="hidden" name="email_msg_id" value="<?= (int)$em['id'] ?>">
                    <input type="hidden" name="customer_id"  value="<?= $fcCustId ?>">
                    <input type="hidden" name="bc_id"        value="0">
                    <input type="hidden" name="requested_date" value="<?= $fcReqDate ?>">
                    <?php foreach ($fcSkuIds as $idx => $skuId): ?>
                      <input type="hidden" name="line_sku_id[]" value="<?= (int)$skuId ?>">
                      <input type="hidden" name="line_qty[]"    value="<?= htmlspecialchars((string)($fcQtys[$idx] ?? 0), ENT_QUOTES | ENT_HTML5) ?>">
                    <?php endforeach ?>
                    <div class="eo-bc-interstitial__actions">
                      <button type="submit" name="action" value="archive" class="eo-btn-archive">
                        Archiver (sans correspondance BC)
                      </button>
                      <button type="submit" name="action" value="force_create" class="eo-btn-force-create">
                        Créer quand même
                      </button>
                    </div>
                  </form>
                </div>
              <?php else: ?>
              <form method="POST" action="/modules/email-orders.php">
                <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                <input type="hidden" name="action"       value="validate">
                <input type="hidden" name="email_msg_id" value="<?= (int)$em['id'] ?>">

                <div class="eo-suborder">

                <div class="eo-card-progress">
                  <span class="eo-progress-badge">0 / <?= 1 + count($lineHints) ?> résolu</span>
                </div>

                <!-- Customer -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required" for="eo-cust-<?= (int)$em['id'] ?>">
                    Client
                  </label>
                  <?php if ($prefilledCustId > 0): ?>
                    <!-- Pre-filled from internal rep: show resolved immediately -->
                    <div class="eo-cust-chips" data-cust-hint="" aria-label="Suggestions client" hidden></div>
                    <div class="eo-cust-manual" hidden>
                      <div class="eo-typeahead-wrap">
                        <input type="text" id="eo-cust-<?= (int)$em['id'] ?>" class="eo-input eo-cust-search"
                               placeholder="Rechercher un client…" autocomplete="off" value="<?= $prefilledCustName ?>">
                        <ul class="eo-typeahead-dropdown eo-cust-dropdown" role="listbox" aria-label="Clients" hidden></ul>
                      </div>
                    </div>
                    <div class="eo-cust-resolved">
                      <span class="eo-cust-resolved__label"><?= $prefilledCustName ?></span>
                      <button type="button" class="eo-cust-resolved__clear" aria-label="Changer">✎</button>
                    </div>
                  <?php else: ?>
                    <div class="eo-cust-chips" data-cust-hint="<?= $custHint ?>" aria-label="Suggestions client"></div>
                    <div class="eo-cust-manual" hidden>
                      <div class="eo-typeahead-wrap">
                        <input type="text" id="eo-cust-<?= (int)$em['id'] ?>" class="eo-input eo-cust-search"
                               placeholder="Rechercher un client…" autocomplete="off" value="">
                        <ul class="eo-typeahead-dropdown eo-cust-dropdown" role="listbox" aria-label="Clients" hidden></ul>
                      </div>
                    </div>
                    <button type="button" class="eo-cust-autre-btn">Autre…</button>
                    <div class="eo-cust-resolved" hidden>
                      <span class="eo-cust-resolved__label"></span>
                      <button type="button" class="eo-cust-resolved__clear" aria-label="Changer">✎</button>
                    </div>
                  <?php endif ?>
                  <input type="hidden" name="customer_id" class="eo-customer-id" value="<?= $prefilledCustId ?>">
                </div>

                <!-- Requested date -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required" for="eo-date-<?= (int)$em['id'] ?>">
                    Date de livraison souhaitée
                  </label>
                  <?php if ($dateHint): ?>
                    <div class="eo-field__hint">Indice parsé : « <?= $dateHint ?> »</div>
                  <?php endif ?>
                  <input type="date"
                         id="eo-date-<?= (int)$em['id'] ?>"
                         name="requested_date"
                         class="eo-input eo-requested-date"
                         value="<?= $dateHint ?>"
                         required>
                </div>

                <!-- Lines -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required">Lignes</label>
                  <div class="eo-lines">

                    <?php foreach ($lineHints as $li => $lineHint): ?>
                      <?php
                        $skuHintEsc = htmlspecialchars($lineHint['sku_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
                        $rawEsc     = htmlspecialchars($lineHint['raw'] ?? '', ENT_QUOTES | ENT_HTML5);
                        $hintQty    = (float) ($lineHint['qty'] ?? 0);
                      ?>
                      <div class="eo-line-row" data-line-idx="<?= (int)$li ?>" data-sku-hint="<?= $skuHintEsc ?>">
                        <?php if ($skuHintEsc): ?>
                        <div class="eo-line-hint-row" style="grid-column:1/-1">
                          <span class="eo-line-hint-label">Indice : <em>«&nbsp;<?= $skuHintEsc ?>&nbsp;»</em></span>
                          <?php if ($hintQty > 0): ?><span class="eo-line-hint-qty">Qté parsée : <?= htmlspecialchars((string)$hintQty, ENT_QUOTES | ENT_HTML5) ?></span><?php endif ?>
                        </div>
                        <?php endif ?>
                        <div class="eo-sku-chips" aria-label="Suggestions SKU"></div>
                        <div class="eo-sku-manual" hidden>
                          <div class="eo-typeahead-wrap">
                            <input type="text" class="eo-input eo-sku-search" placeholder="Rechercher un SKU…" autocomplete="off" value="">
                            <ul class="eo-typeahead-dropdown eo-sku-dropdown" role="listbox" hidden></ul>
                          </div>
                        </div>
                        <button type="button" class="eo-autre-btn">Autre…</button>
                        <div class="eo-sku-resolved" hidden>
                          <span class="eo-sku-resolved__label"></span>
                          <button type="button" class="eo-sku-resolved__clear" aria-label="Changer">✎</button>
                        </div>
                        <input type="hidden" name="line_sku_id[]" class="eo-sku-id" value="0">
                        <input type="number"
                               name="line_qty[]"
                               class="eo-input eo-qty-input"
                               min="0.01" step="0.5"
                               value="<?= $hintQty > 0 ? htmlspecialchars((string)$hintQty, ENT_QUOTES | ENT_HTML5) : '' ?>"
                               placeholder="Qté">
                        <button type="button" class="eo-line-remove" aria-label="Supprimer la ligne">×</button>
                      </div>
                    <?php endforeach ?>

                    <button type="button" class="eo-add-line-btn">＋ Ajouter une ligne</button>

                  </div><!-- /.eo-lines -->
                </div>

                </div><!-- /.eo-suborder -->

                <!-- Twin confirmation affordance -->
                <div class="eo-twin-confirm<?= $isTwinPending ? ' eo-twin-confirm--visible' : '' ?>"
                     id="eo-twin-confirm-<?= (int)$em['id'] ?>">
                  <div class="eo-twin-warn">
                    ⚠ Doublon eshop possible — une commande Shopify/pickup avec les mêmes SKUs existe dans la fenêtre ±7 jours.
                  </div>
                  <label class="eo-field__label">
                    <input type="checkbox" name="confirm_twin" value="1"<?= $isTwinPending ? ' checked' : '' ?>>
                    Confirmer malgré le doublon potentiel e-shop
                  </label>
                </div>

                <!-- Actions -->
                <div class="eo-sticky-validate">
                  <div class="eo-sticky-validate__blocker"></div>
                  <button type="submit" class="eo-btn-validate" disabled>
                    ✓ Valider la commande
                  </button>
                </div>

              </form>
              <?php endif ?>
            </div><!-- /.eo-review-card__form -->
          </div><!-- /.eo-review-card__columns -->
        </div><!-- /.eo-review-card (single) -->

        <?php endif /* multi vs single */ ?>

      <?php endforeach ?>
    <?php endif ?>
  </section>

  <!-- ════════════════════════════════════════════════════════════════════════
       BUCKET 2 — Non parsé (parse_status = no_match / error / unparsed)
       ════════════════════════════════════════════════════════════════════════ -->
  <section class="eo-section" aria-label="E-mails non parsés">
    <div class="eo-section__header">
      <h2 class="eo-section__title">Non parsé</h2>
      <span id="eo-badge-error" class="eo-section__badge eo-badge--error"><?= count($unparsedEmails) ?></span>
    </div>

    <?php if (empty($unparsedEmails)): ?>
      <p class="eo-empty">Aucun e-mail en attente de traitement manuel.</p>
    <?php else: ?>
      <?php foreach ($unparsedEmails as $em): ?>
        <?php
          $status     = htmlspecialchars($em['parse_status'] ?? 'unparsed', ENT_QUOTES | ENT_HTML5);
          $fromAddr   = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
          $origSender = htmlspecialchars($em['original_sender'] ?? '', ENT_QUOTES | ENT_HTML5);
          $subject    = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
          $received = htmlspecialchars($em['received_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          $errMsg   = htmlspecialchars($em['parse_error'] ?? '', ENT_QUOTES | ENT_HTML5);
          $rawBody  = htmlspecialchars($em['raw_body'] ?? '', ENT_QUOTES | ENT_HTML5);
        ?>
        <div class="eo-raw-card">
          <div class="eo-raw-card__meta">
            <span><strong>Statut :</strong> <?= $status ?></span>
            <span><strong>De :</strong> <?= $fromAddr ?>
              <?php if ($origSender !== '' && stripos($em['from_address'] ?? '', $em['original_sender'] ?? '') === false): ?>
                <span class="eo-original-sender">Expéditeur réel : <?= $origSender ?></span>
              <?php endif ?>
            </span>
            <span><strong>Objet :</strong> <?= $subject ?></span>
            <?php if ($received): ?><span><strong>Reçu :</strong> <?= $received ?></span><?php endif ?>
          </div>
          <div class="eo-raw-card__body">
            <pre class="eo-raw-card__text"><?= $rawBody ?></pre>
            <?php if ($errMsg): ?>
              <div class="eo-raw-card__error">⚠ Erreur : <?= $errMsg ?></div>
            <?php endif ?>
          </div>
        </div>
      <?php endforeach ?>
    <?php endif ?>
  </section>

  <!-- ════════════════════════════════════════════════════════════════════════
       BUCKET 3 — Traités (parse_status = order_created)
       ════════════════════════════════════════════════════════════════════════ -->
  <section class="eo-section" aria-label="Commandes traitées">
    <div class="eo-section__header">
      <h2 class="eo-section__title">Traités</h2>
      <span id="eo-badge-done" class="eo-section__badge eo-badge--done"><?= count($doneEmails) ?></span>
    </div>

    <?php if (empty($doneEmails)): ?>
      <p class="eo-empty">Aucune commande traitée.</p>
    <?php else: ?>
      <div class="eo-done-list">
        <?php foreach ($doneEmails as $em): ?>
          <?php
            $fromAddr   = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
            $origSender = htmlspecialchars($em['original_sender'] ?? '', ENT_QUOTES | ENT_HTML5);
            $subject    = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
            $updAt      = htmlspecialchars($em['updated_at'] ?? '', ENT_QUOTES | ENT_HTML5);
            $statusLabel = '';
            $statusClass = '';
            if (($em['parse_status'] ?? '') === 'reconciled') {
                $bcRef = $em['bc_matched_order_id'] ? ' #' . (int)$em['bc_matched_order_id'] : '';
                $statusLabel = 'Rapproché BC' . $bcRef;
                $statusClass = 'eo-done-item__status-badge--reconciled';
            } elseif (($em['parse_status'] ?? '') === 'order_created') {
                $bcOrdState = $bcStateByEmailId[(int)$em['id']] ?? null;
                if ($bcOrdState && !empty($bcOrdState['bc_no'])) {
                    $statusLabel = 'Créé — BC #' . htmlspecialchars((string)$bcOrdState['bc_no'], ENT_QUOTES | ENT_HTML5);
                    $statusClass = 'eo-done-item__status-badge--sent-bc';
                } else {
                    $statusLabel = 'En attente d\'envoi BC';
                    $statusClass = 'eo-done-item__status-badge--pending-bc';
                }
            } else {
                $statusLabel = 'Commande créée';
                $statusClass = 'eo-done-item__status-badge--created';
            }
          ?>
          <div class="eo-done-item">
            <span class="eo-done-item__subject"><?= $subject ?></span>
            <span class="eo-done-item__status-badge <?= $statusClass ?>">
              <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_HTML5) ?>
            </span>
            <span><?= $fromAddr ?>
              <?php if ($origSender !== '' && stripos($em['from_address'] ?? '', $em['original_sender'] ?? '') === false): ?>
                <span class="eo-original-sender">Expéditeur réel : <?= $origSender ?></span>
              <?php endif ?>
            </span>
            <span><?= $updAt ?></span>
          </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </section>

</main>

<script src="/js/email-orders.js?v=<?= @filemtime(__DIR__ . '/../js/email-orders.js') ?: time() ?>"></script>
</body>
</html>
