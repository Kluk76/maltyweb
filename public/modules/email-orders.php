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
 *        INSERT ord_orders (source='email', review_status='accepted', …)
 *        INSERT ord_order_lines (per resolved line)
 *        INSERT ord_order_status_events (status='entered')
 *        UPDATE doc_email_messages SET parse_status='order_created'
 *      With log_revision() on each write.
 *      UNIQUE uniq_ord_source_ref is idempotency backstop (handled gracefully).
 *   5. Redirect with success flash (PRG).
 *
 * NO BC push (D1 stays triple-gated separately).
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
    'SELECT id, sku_code, hl_per_unit, units_per_pack, stocktake_scope
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
    if (!in_array($action, ['archive', 'validate', 'force_create'], true)) {
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

    // ── 5. Delegate to the canonical helper ──────────────────────────────────
    // email_order_promote() owns: FOR UPDATE row-locking, source_ref idempotency,
    // dup-key(1062/uniq_ord_source_ref) translation, the correct Shopify-pickup
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

        // Clear any pending twin state for this card.
        maltytask_session_start();
        unset($_SESSION['eo_twin_pending_id']);

        flash_set('ok', 'Commande #' . $newOrderId . ' créée avec succès (source e-mail).');
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

// ── JSON hydration for JS ─────────────────────────────────────────────────────
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

$customersForJs = array_values(array_map(fn($cr) => [
    'id'   => (int) $cr['id'],
    'name' => $cr['name'],
], $custRows));

$skusForJs = array_values(array_map(fn($sr) => [
    'id'              => (int) $sr['id'],
    'sku_code'        => $sr['sku_code'],
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
      <span class="eo-section__badge eo-badge--review"><?= count($parsedEmails) ?></span>
    </div>

    <?php if (empty($parsedEmails)): ?>
      <p class="eo-empty">Aucune commande parsée en attente de validation.</p>
    <?php else: ?>
      <?php foreach ($parsedEmails as $em): ?>
        <?php
          $hints        = $em['_hints'] ?? [];
          $custHint     = htmlspecialchars($hints['customer_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
          $dateHint     = htmlspecialchars($hints['requested_date'] ?? '', ENT_QUOTES | ENT_HTML5);
          $notesHint    = htmlspecialchars($hints['notes'] ?? '', ENT_QUOTES | ENT_HTML5);
          $lineHints    = $hints['lines'] ?? [];
          $fromAddr     = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
          $origSender   = htmlspecialchars($em['original_sender'] ?? '', ENT_QUOTES | ENT_HTML5);
          $subject      = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
          $receivedAt   = htmlspecialchars($em['received_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          $rawBody      = htmlspecialchars($em['raw_body'] ?? '', ENT_QUOTES | ENT_HTML5);
          // Internal-rep pre-fill: when _internal_rep=true in parsed_json (sender IS the customer)
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
        <?php $isTwinPending = ($twinPendingEmailId > 0 && (int)$em['id'] === $twinPendingEmailId); ?>
        <?php
        $bcInterstitial = $bcInterstitialStates[(int)$em['id']] ?? null;
        $bcCheckFailed  = $bcCheckFailedStates[(int)$em['id']] ?? null;
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

                <!-- Customer — MUST be picked from typeahead, never auto-resolved
                     Exception: internal-rep orders pre-fill customer_id + name from
                     ref_internal_order_accounts when _internal_rep=true in parsed_json.
                     Operator can still override by typing a new name and picking. -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required" for="eo-cust-<?= (int)$em['id'] ?>">
                    Client
                  </label>
                  <?php if ($prefilledCustId > 0): ?>
                    <div class="eo-field__hint eo-field__hint--prefilled">
                      Pré-rempli depuis le compte interne de l'expéditeur — confirme ou modifie.
                    </div>
                  <?php elseif ($custHint): ?>
                    <div class="eo-field__hint">Indice parsé : « <?= $custHint ?> » — confirme en sélectionnant dans la liste</div>
                  <?php endif ?>
                  <div class="eo-typeahead-wrap">
                    <input type="text"
                           id="eo-cust-<?= (int)$em['id'] ?>"
                           class="eo-input eo-cust-search"
                           placeholder="Rechercher un client…"
                           autocomplete="off"
                           value="<?= $prefilledCustId > 0 ? $prefilledCustName : '' ?>">
                    <ul class="eo-typeahead-dropdown eo-cust-dropdown" role="listbox" aria-label="Clients" hidden></ul>
                    <!-- customer_id: pre-filled for internal-rep orders; 0 until operator picks for external orders -->
                    <input type="hidden" name="customer_id" class="eo-customer-id" value="<?= $prefilledCustId ?>">
                  </div>
                </div>

                <!-- Requested date — pre-filled from hint but operator must confirm -->
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

                <!-- Lines — each SKU MUST be resolved via typeahead -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required">Lignes</label>
                  <div class="eo-lines">

                    <?php foreach ($lineHints as $li => $lineHint): ?>
                      <?php
                        $skuHintEsc = htmlspecialchars($lineHint['sku_hint'] ?? '', ENT_QUOTES | ENT_HTML5);
                        $rawEsc     = htmlspecialchars($lineHint['raw'] ?? '', ENT_QUOTES | ENT_HTML5);
                        $hintQty    = (float) ($lineHint['qty'] ?? 0);
                      ?>
                      <div class="eo-line-row" data-line-idx="<?= (int)$li ?>">
                        <!-- SKU picker — pre-filled text only, id=0 until picked -->
                        <div class="eo-typeahead-wrap">
                          <input type="text"
                                 class="eo-input eo-sku-search"
                                 placeholder="SKU…"
                                 autocomplete="off"
                                 title="Indice parsé : <?= $skuHintEsc ?>"
                                 value=""><!-- Always start empty — human must pick -->
                          <ul class="eo-typeahead-dropdown eo-sku-dropdown" role="listbox" hidden></ul>
                          <!-- sku_id starts at 0 — only set when operator picks -->
                          <input type="hidden" name="line_sku_id[]" class="eo-sku-id" value="0">
                        </div>
                        <!-- Qty — pre-filled from hint -->
                        <input type="number"
                               name="line_qty[]"
                               class="eo-input eo-qty-input"
                               min="0.01" step="0.5"
                               value="<?= $hintQty > 0 ? htmlspecialchars((string)$hintQty, ENT_QUOTES | ENT_HTML5) : '' ?>"
                               placeholder="Qté">
                        <!-- Remove button -->
                        <button type="button" class="eo-line-remove" aria-label="Supprimer la ligne">×</button>
                        <?php if ($skuHintEsc): ?>
                          <div class="eo-line-raw">Indice : <?= $skuHintEsc ?><?= $rawEsc ? ' — « ' . $rawEsc . ' »' : '' ?></div>
                        <?php endif ?>
                      </div>
                    <?php endforeach ?>

                    <!-- Add line button -->
                    <button type="button" class="eo-add-line-btn eo-add-line-btn">＋ Ajouter une ligne</button>

                  </div><!-- /.eo-lines -->
                </div>

                <!-- Twin confirmation affordance — visible only when a twin was flagged for this card -->
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
                <div class="eo-form-actions">
                  <button type="submit" class="eo-btn-validate" disabled>
                    ✓ Valider la commande
                  </button>
                  <span style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--ink-mute);">
                    Client + date + chaque SKU doivent être résolus
                  </span>
                </div>

              </form>
              <?php endif ?>
            </div><!-- /.eo-review-card__form -->
          </div><!-- /.eo-review-card__columns -->
        </div><!-- /.eo-review-card -->
      <?php endforeach ?>
    <?php endif ?>
  </section>

  <!-- ════════════════════════════════════════════════════════════════════════
       BUCKET 2 — Non parsé (parse_status = no_match / error / unparsed)
       ════════════════════════════════════════════════════════════════════════ -->
  <section class="eo-section" aria-label="E-mails non parsés">
    <div class="eo-section__header">
      <h2 class="eo-section__title">Non parsé</h2>
      <span class="eo-section__badge eo-badge--error"><?= count($unparsedEmails) ?></span>
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
      <span class="eo-section__badge eo-badge--done"><?= count($doneEmails) ?></span>
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
