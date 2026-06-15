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

    // ── 2. Coerce inputs ──────────────────────────────────────────────────────
    $emailMsgId  = isset($_POST['email_msg_id']) ? (int) $_POST['email_msg_id'] : 0;
    $custIdRaw   = isset($_POST['customer_id'])  ? (int) $_POST['customer_id']  : 0;
    $reqDate     = isset($_POST['requested_date']) ? trim((string) $_POST['requested_date']) : '';
    $lineSkuIds  = $_POST['line_sku_id'] ?? [];
    $lineQtys    = $_POST['line_qty']    ?? [];

    // ── 3. Validate — refuse-don't-NULL ──────────────────────────────────────
    $errors = [];

    // email_msg_id must point to a real parsed row
    $emailRow = null;
    if ($emailMsgId <= 0) {
        $errors[] = 'Identifiant de message invalide.';
    } else {
        $stmtMsg = $pdo->prepare(
            "SELECT id, message_id, raw_body, parse_status
               FROM doc_email_messages
              WHERE id = ? AND parse_status = 'parsed'
              LIMIT 1"
        );
        $stmtMsg->execute([$emailMsgId]);
        $emailRow = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        if ($emailRow === false || $emailRow === null) {
            $errors[] = 'Message introuvable ou déjà traité.';
        }
    }

    // Customer — must be picked from active set
    if ($custIdRaw <= 0 || !isset($activeCustIds[$custIdRaw])) {
        $errors[] = 'Client non résolu — sélectionne un client dans la liste.';
    }

    // Date
    if ($reqDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
        $errors[] = 'Date de livraison requise (format AAAA-MM-JJ).';
    }

    // Lines — at least 1, every line fully resolved
    $validLines = [];
    if (!is_array($lineSkuIds) || count($lineSkuIds) === 0) {
        $errors[] = 'Au moins une ligne article est requise.';
    } else {
        foreach ($lineSkuIds as $i => $rawSkuId) {
            $skuId = (int) ($rawSkuId ?? 0);
            $qty   = isset($lineQtys[$i]) ? (float) $lineQtys[$i] : 0.0;

            if ($skuId <= 0) continue; // blank row — skip silently
            if (!isset($activeSkuIds[$skuId])) {
                $errors[] = 'Ligne ' . ((int)$i + 1) . ' : SKU introuvable.';
                continue;
            }
            if ($qty <= 0) {
                $errors[] = 'Ligne ' . ((int)$i + 1) . ' : quantité doit être > 0.';
                continue;
            }
            $validLines[] = ['sku_id' => $skuId, 'qty' => $qty];
        }
        if (count($validLines) === 0 && count($errors) === 0) {
            $errors[] = 'Au moins une ligne article valide est requise.';
        }
    }

    if (!empty($errors)) {
        flash_set('err', implode(' — ', $errors));
        redirect_to('/modules/email-orders.php');
    }

    // ── 4. Twin-check (XOR no-double-deplete) ────────────────────────────────
    // Check if a Shopify/eshop order already exists for the same requested_date.
    // inv_sales_orders has no customer_id_fk so we correlate on date only.
    $twinWarnMsg = null;
    try {
        $stmtTwin = $pdo->prepare(
            "SELECT COUNT(*) AS cnt
               FROM inv_sales_orders
              WHERE channel = 'eshop'
                AND DATE(created_at) = ?
              LIMIT 1"
        );
        $stmtTwin->execute([$reqDate]);
        $twinRow = $stmtTwin->fetch(PDO::FETCH_ASSOC);
        if ($twinRow && (int)$twinRow['cnt'] > 0) {
            $twinWarnMsg = 'Un ou plusieurs ordres e-shop Shopify existent pour la date '
                . htmlspecialchars($reqDate, ENT_QUOTES | ENT_HTML5)
                . ' — veuillez vérifier qu\'il ne s\'agit pas d\'un doublon avant de valider.';
        }
    } catch (Throwable $e) {
        error_log('[email-orders twin-check] ' . $e->getMessage());
        // Non-fatal: twin-check failure doesn't block creation, just means we skip the warning.
    }

    // If a twin candidate exists, store warning in session and redirect back to show it.
    // The operator must re-submit with a ?confirm_twin=1 flag to override.
    $confirmTwin = isset($_POST['confirm_twin']) && (int)$_POST['confirm_twin'] === 1;
    if ($twinWarnMsg !== null && !$confirmTwin) {
        flash_set('err', '⚠ Doublon potentiel e-shop : ' . strip_tags($twinWarnMsg)
            . ' — Resoumettez avec la case "Confirmer malgré le doublon" cochée pour forcer.');
        redirect_to('/modules/email-orders.php');
    }

    // ── 5. Build source_ref ───────────────────────────────────────────────────
    $messageId = $emailRow['message_id'] ?? '';
    $sourceRef = 'email:' . $messageId;

    // ── 6. Atomic transaction ─────────────────────────────────────────────────
    try {
        $pdo->beginTransaction();

        // INSERT ord_orders
        $insOrd = $pdo->prepare(
            'INSERT INTO ord_orders
                (order_type, customer_id_fk, internal_channel, requested_date,
                 status, source, source_email_id_fk, source_ref,
                 review_status, created_by_user_id)
             VALUES (?, ?, NULL, ?, "entered", "email", ?, ?, "accepted", ?)'
        );
        $insOrd->execute([
            'customer',
            $custIdRaw,
            $reqDate,
            $emailMsgId,
            $sourceRef,
            (int) $me['id'],
        ]);
        $newOrderId = (int) $pdo->lastInsertId();

        log_revision($pdo, $me, 'ord_orders', $newOrderId, null, [
            'order_type'          => 'customer',
            'customer_id_fk'      => $custIdRaw,
            'internal_channel'    => null,
            'requested_date'      => $reqDate,
            'status'              => 'entered',
            'source'              => 'email',
            'source_email_id_fk'  => $emailMsgId,
            'source_ref'          => $sourceRef,
            'review_status'       => 'accepted',
            'created_by_user_id'  => (int) $me['id'],
        ], 'normal', 'Commande créée via validation e-mail');

        // INSERT ord_order_lines
        $insLine = $pdo->prepare(
            'INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty)
             VALUES (?, ?, ?)'
        );
        foreach ($validLines as $line) {
            $insLine->execute([$newOrderId, $line['sku_id'], $line['qty']]);
            $lineId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ord_order_lines', $lineId, null, [
                'order_id_fk' => $newOrderId,
                'sku_id_fk'   => $line['sku_id'],
                'qty'         => $line['qty'],
            ], 'normal');
        }

        // INSERT ord_order_status_events
        $insEv = $pdo->prepare(
            'INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
             VALUES (?, "entered", NOW(), ?, ?)'
        );
        $insEv->execute([$newOrderId, (int) $me['id'], 'Commande saisie via validation e-mail']);
        $evId = (int) $pdo->lastInsertId();
        log_revision($pdo, $me, 'ord_order_status_events', $evId, null, [
            'order_id_fk' => $newOrderId,
            'status'      => 'entered',
            'comment'     => 'Commande saisie via validation e-mail',
        ], 'normal');

        // UPDATE doc_email_messages → order_created (atomic flip)
        $updEmail = $pdo->prepare(
            "UPDATE doc_email_messages SET parse_status = 'order_created', updated_at = NOW()
              WHERE id = ?"
        );
        $updEmail->execute([$emailMsgId]);
        log_revision($pdo, $me, 'doc_email_messages', $emailMsgId,
            ['parse_status' => 'parsed'],
            ['parse_status' => 'order_created'],
            'normal', 'Commande #' . $newOrderId . ' créée depuis cet e-mail');

        $pdo->commit();

        flash_set('ok', 'Commande #' . $newOrderId . ' créée avec succès (source e-mail).');
        redirect_to('/modules/email-orders.php');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[email-orders POST] ' . $e->getMessage());

        // Graceful duplicate-key: source_ref already exists → order already created
        if (str_contains($e->getMessage(), '1062')) {
            // Find the existing order to show a helpful message
            try {
                $dupStmt = $pdo->prepare(
                    "SELECT id FROM ord_orders WHERE source_ref = ? LIMIT 1"
                );
                $dupStmt->execute([$sourceRef]);
                $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dupRow) {
                    // Flip the email status if it didn't get flipped
                    try {
                        $pdo->prepare("UPDATE doc_email_messages SET parse_status='order_created' WHERE id=?")->execute([$emailMsgId]);
                    } catch (Throwable $_) { /* ignore */ }
                    flash_set('ok', 'Commande déjà créée (#' . (int)$dupRow['id'] . ') depuis cet e-mail.');
                    redirect_to('/modules/email-orders.php');
                }
            } catch (Throwable $_) { /* ignore nested */ }
        }

        flash_set('err', 'Erreur lors de la création : ' . pdo_friendly_error($e));
        redirect_to('/modules/email-orders.php');
    }
}

// ── Load data for GET render ───────────────────────────────────────────────────

// Fetch all email messages grouped by parse_status, newest first
$allEmailsStmt = $pdo->query(
    "SELECT id, message_id, from_address, subject, received_at,
            raw_body, parse_status, parse_error, parsed_json,
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
    } elseif ($status === 'order_created') {
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
          $subject      = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
          $receivedAt   = htmlspecialchars($em['received_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          $rawBody      = htmlspecialchars($em['raw_body'] ?? '', ENT_QUOTES | ENT_HTML5);
        ?>
        <div class="eo-review-card" id="eo-card-<?= (int)$em['id'] ?>">
          <!-- Card meta bar -->
          <div class="eo-review-card__meta">
            <span><strong>De :</strong> <?= $fromAddr ?></span>
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
              <?php else: ?>
              <form method="POST" action="/modules/email-orders.php">
                <input type="hidden" name="csrf"         value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_HTML5) ?>">
                <input type="hidden" name="action"       value="validate">
                <input type="hidden" name="email_msg_id" value="<?= (int)$em['id'] ?>">

                <!-- Customer — MUST be picked from typeahead, never auto-resolved -->
                <div class="eo-field">
                  <label class="eo-field__label eo-field__label--required" for="eo-cust-<?= (int)$em['id'] ?>">
                    Client
                  </label>
                  <?php if ($custHint): ?>
                    <div class="eo-field__hint">Indice parsé : « <?= $custHint ?> » — confirme en sélectionnant dans la liste</div>
                  <?php endif ?>
                  <div class="eo-typeahead-wrap">
                    <input type="text"
                           id="eo-cust-<?= (int)$em['id'] ?>"
                           class="eo-input eo-cust-search"
                           placeholder="Rechercher un client…"
                           autocomplete="off"
                           value=""><!-- ALWAYS start empty — human must pick -->
                    <ul class="eo-typeahead-dropdown" role="listbox" aria-label="Clients" hidden></ul>
                    <!-- customer_id starts at 0 — only set when operator picks from dropdown -->
                    <input type="hidden" name="customer_id" class="eo-customer-id" value="0">
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

                <!-- Twin confirmation checkbox (hidden by default; shown by flash when twin detected) -->
                <div class="eo-field" style="display:none;" id="eo-twin-confirm-<?= (int)$em['id'] ?>">
                  <label class="eo-field__label">
                    <input type="checkbox" name="confirm_twin" value="1">
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
          $status   = htmlspecialchars($em['parse_status'] ?? 'unparsed', ENT_QUOTES | ENT_HTML5);
          $fromAddr = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
          $subject  = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
          $received = htmlspecialchars($em['received_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          $errMsg   = htmlspecialchars($em['parse_error'] ?? '', ENT_QUOTES | ENT_HTML5);
          $rawBody  = htmlspecialchars($em['raw_body'] ?? '', ENT_QUOTES | ENT_HTML5);
        ?>
        <div class="eo-raw-card">
          <div class="eo-raw-card__meta">
            <span><strong>Statut :</strong> <?= $status ?></span>
            <span><strong>De :</strong> <?= $fromAddr ?></span>
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
            $fromAddr = htmlspecialchars($em['from_address'] ?? '', ENT_QUOTES | ENT_HTML5);
            $subject  = htmlspecialchars($em['subject'] ?? '(sans objet)', ENT_QUOTES | ENT_HTML5);
            $updAt    = htmlspecialchars($em['updated_at'] ?? '', ENT_QUOTES | ENT_HTML5);
          ?>
          <div class="eo-done-item">
            <span class="eo-done-item__subject"><?= $subject ?></span>
            <span><?= $fromAddr ?></span>
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
