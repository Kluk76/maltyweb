<?php
declare(strict_types=1);
/**
 * /modules/expeditions.php — Expéditions module (orders + dispatch).
 *
 * Three views via ?view=:
 *   (default)  Commandes  — recent orders list + status chips
 *   form       Saisie     — order entry / edit form
 *   stock      Stock PF   — placeholder
 *
 * POST handler (Saisie view only): CSRF gate → validate → transaction:
 *   optional new customer INSERT, ord_orders INSERT/UPDATE, ord_order_lines
 *   DELETE+INSERT, ord_order_status_events INSERT (on create only) → log_revision
 *   → flash_set → PRG redirect.
 *
 * Edit mode: ?view=form&edit=<id> — read-only when status ∈ shipped|cancelled.
 *
 * Auth: require_page_access('expeditions').
 * Dates display as jj/mm/aaaa (DMY system-wide).
 * CSS: /css/expeditions.css   JS: /js/expeditions-form.js  /js/expeditions.js
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/csrf.php';

require_page_access('expeditions');
$me = current_user();

// ── Status rank map — NEVER compare by ENUM ordinal position ─────────────────
const EXP_STATUS_RANK = [
    'entered'    => 0,
    'confirmed'  => 1,
    'picked'     => 2,
    'bl_printed' => 3,
    'shipped'    => 4,
    'cancelled'  => -1, // terminal
];
const EXP_STATUS_LABELS = [
    'entered'    => 'Saisie',
    'confirmed'  => 'Confirmée',
    'picked'     => 'Préparée',
    'bl_printed' => 'BL imprimé',
    'shipped'    => 'Livrée',
    'cancelled'  => 'Annulée',
];
const EXP_STATUS_ADVANCE = [
    'entered'    => 'confirmed',
    'confirmed'  => 'picked',
    'picked'     => 'bl_printed',
    'bl_printed' => 'shipped',
];
const EXP_STATUS_REVERT = [
    'confirmed'  => 'entered',
    'picked'     => 'confirmed',
    'bl_printed' => 'picked',
    'shipped'    => 'bl_printed',
];

// ── Allowed enum values (whitelists) ─────────────────────────────────────────
const EXP_ORDER_TYPES       = ['customer', 'internal'];
const EXP_INTERNAL_CHANNELS = ['taproom', 'eshop', 'cage', 'shop'];
const EXP_INTERNAL_LABELS   = [
    'taproom' => 'Taproom',
    'eshop'   => 'Boutique en ligne',
    'cage'    => 'Cage',
    'shop'    => 'Shop',
];

// ── View routing ──────────────────────────────────────────────────────────────
$view    = isset($_GET['view']) ? (string) $_GET['view'] : 'commandes';
$allowedViews = ['commandes', 'form', 'stock'];
if (!in_array($view, $allowedViews, true)) $view = 'commandes';

$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
if ($editId < 0) $editId = 0;

// ── POST handler (Saisie view only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'form') {

    // CSRF — must be first
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=form' . ($editId ? '&edit=' . $editId : ''));
    }

    $pdo = maltytask_pdo();

    // ── Build allowed-sets INSIDE the POST path ───────────────────────────
    // (anti-pattern: building allowed-sets only in GET render path leaves them
    //  undefined when the POST handler runs — caught in form-packaging.php review)

    // Active customer IDs (for validation)
    $activeCustIds = [];
    $csRows = $pdo->query(
        'SELECT id FROM ref_customers WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($csRows as $cid) $activeCustIds[(int)$cid] = true;

    // Active transporter IDs
    $activeTransIds = [];
    $trRows = $pdo->query(
        'SELECT id FROM ref_transporters WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($trRows as $tid) $activeTransIds[(int)$tid] = true;

    // Active SKU IDs (id → hl_per_unit)
    $activeSkus = [];
    $skuRows = $pdo->query(
        'SELECT id, sku_code, hl_per_unit FROM ref_skus WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($skuRows as $sr) {
        $activeSkus[(int)$sr['id']] = [
            'sku_code'   => $sr['sku_code'],
            'hl_per_unit'=> (float) $sr['hl_per_unit'],
        ];
    }

    // ── Coerce inputs ─────────────────────────────────────────────────────
    $isEdit      = ($editId > 0);
    $orderType   = isset($_POST['order_type']) ? (string) $_POST['order_type'] : '';
    $custIdRaw   = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
    $newCustName = isset($_POST['new_customer_name']) ? trim((string) $_POST['new_customer_name']) : '';
    $intChannel  = isset($_POST['internal_channel']) ? (string) $_POST['internal_channel'] : '';
    $reqDate     = isset($_POST['requested_date']) ? trim((string) $_POST['requested_date']) : '';
    $transIdRaw  = isset($_POST['transporter_id']) ? (int) $_POST['transporter_id'] : 0;
    $comment     = isset($_POST['comment']) ? trim((string) $_POST['comment']) : '';

    // Lines: parallel arrays from the form
    $lineSkuIds  = $_POST['line_sku_id']      ?? [];
    $lineQtys    = $_POST['line_qty']          ?? [];
    $lineComments= $_POST['line_comment']      ?? [];

    // ── Validation ────────────────────────────────────────────────────────
    $errors = [];

    // Order type
    if (!in_array($orderType, EXP_ORDER_TYPES, true)) {
        $errors[] = 'Type de commande invalide.';
    }

    // Party: exactly one
    if ($orderType === 'customer') {
        // Either existing customer_id or a new customer name
        if ($custIdRaw <= 0 && $newCustName === '') {
            $errors[] = 'Sélectionne ou saisis un client.';
        }
        if ($custIdRaw > 0 && !isset($activeCustIds[$custIdRaw])) {
            $errors[] = 'Client introuvable.';
        }
        $intChannel = null; // enforce mutual exclusion
    } elseif ($orderType === 'internal') {
        if (!in_array($intChannel, EXP_INTERNAL_CHANNELS, true)) {
            $errors[] = 'Canal interne invalide.';
        }
        $custIdRaw = 0;
        $newCustName = '';
    }

    // Date
    if ($reqDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
        $errors[] = 'Date de livraison requise (format AAAA-MM-JJ).';
    }

    // Transporter (optional)
    $transIdFk = null;
    if ($transIdRaw > 0) {
        if (!isset($activeTransIds[$transIdRaw])) {
            $errors[] = 'Transporteur introuvable.';
        } else {
            $transIdFk = $transIdRaw;
        }
    }

    // Lines — at least 1 valid line
    $validLines = [];
    if (!is_array($lineSkuIds) || count($lineSkuIds) === 0) {
        $errors[] = 'Au moins une ligne article est requise.';
    } else {
        foreach ($lineSkuIds as $idx => $rawSkuId) {
            $skuId  = (int) ($rawSkuId ?? 0);
            $qty    = isset($lineQtys[$idx]) ? (float) $lineQtys[$idx] : 0.0;
            $lcomm  = isset($lineComments[$idx]) ? trim((string) $lineComments[$idx]) : '';

            if ($skuId <= 0) continue; // blank row — skip
            if (!isset($activeSkus[$skuId])) {
                $errors[] = "Ligne " . ((int)$idx + 1) . " : SKU introuvable.";
                continue;
            }
            if ($qty <= 0) {
                $errors[] = "Ligne " . ((int)$idx + 1) . " : quantité doit être > 0.";
                continue;
            }
            $validLines[] = ['sku_id' => $skuId, 'qty' => $qty, 'comment' => $lcomm];
        }
        if (count($validLines) === 0 && count($errors) === 0) {
            $errors[] = 'Au moins une ligne article valide est requise.';
        }
    }

    // Edit mode: guard shipped/cancelled
    if ($isEdit && empty($errors)) {
        $existingRow = bd_fetch_before($pdo, 'ord_orders', $editId);
        if ($existingRow === null) {
            $errors[] = 'Commande introuvable.';
        } elseif (in_array((string)$existingRow['status'], ['shipped', 'cancelled'], true)) {
            $errors[] = 'Cette commande est clôturée — aucune modification possible.';
        }
    }

    if (!empty($errors)) {
        flash_set('err', implode(' — ', $errors));
        redirect_to('/modules/expeditions.php?view=form' . ($editId ? '&edit=' . $editId : ''));
    }

    // ── Write transaction ─────────────────────────────────────────────────
    try {
        $pdo->beginTransaction();

        // Optional: create new customer inline (needs_review=1)
        $customerId = $custIdRaw > 0 ? $custIdRaw : null;
        if ($orderType === 'customer' && $newCustName !== '') {
            $insCs = $pdo->prepare(
                'INSERT INTO ref_customers (name, needs_review, is_active, updated_by)
                 VALUES (?, 1, 1, ?)'
            );
            $insCs->execute([$newCustName, $me['username']]);
            $customerId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_customers', $customerId, null,
                ['name' => $newCustName, 'needs_review' => 1, 'is_active' => 1],
                'normal', 'Nouveau client créé inline depuis Expéditions');
        }

        if ($isEdit) {
            // ── UPDATE ord_orders header ──────────────────────────────────
            $beforeOrder = bd_fetch_before($pdo, 'ord_orders', $editId);

            $updOrd = $pdo->prepare(
                'UPDATE ord_orders
                    SET order_type          = ?,
                        customer_id_fk      = ?,
                        internal_channel    = ?,
                        requested_date      = ?,
                        transporter_id_fk   = ?,
                        comment             = ?,
                        updated_at          = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $updOrd->execute([
                $orderType,
                $customerId,
                ($orderType === 'internal') ? $intChannel : null,
                $reqDate,
                $transIdFk,
                $comment ?: null,
                $editId,
            ]);
            log_revision($pdo, $me, 'ord_orders', $editId, $beforeOrder, [
                'order_type'       => $orderType,
                'customer_id_fk'   => $customerId,
                'internal_channel' => ($orderType === 'internal') ? $intChannel : null,
                'requested_date'   => $reqDate,
                'transporter_id_fk'=> $transIdFk,
                'comment'          => $comment ?: null,
            ], 'normal');

            // ── REPLACE lines: delete existing, reinsert ──────────────────
            $delLines = $pdo->prepare('DELETE FROM ord_order_lines WHERE order_id_fk = ?');
            $delLines->execute([$editId]);

            $insLine = $pdo->prepare(
                'INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty, line_comment)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($validLines as $line) {
                $insLine->execute([
                    $editId,
                    $line['sku_id'],
                    $line['qty'],
                    $line['comment'] ?: null,
                ]);
                $lineId = (int) $pdo->lastInsertId();
                log_revision($pdo, $me, 'ord_order_lines', $lineId, null, $line, 'normal');
            }

            $pdo->commit();
            flash_set('ok', 'Commande #' . $editId . ' mise à jour.');
            redirect_to('/modules/expeditions.php?view=form&edit=' . $editId);

        } else {
            // ── INSERT ord_orders ─────────────────────────────────────────
            $insOrd = $pdo->prepare(
                'INSERT INTO ord_orders
                    (order_type, customer_id_fk, internal_channel, requested_date,
                     status, transporter_id_fk, comment, source, created_by_user_id)
                 VALUES (?, ?, ?, ?, "entered", ?, ?, "web", ?)'
            );
            $insOrd->execute([
                $orderType,
                $customerId,
                ($orderType === 'internal') ? $intChannel : null,
                $reqDate,
                $transIdFk,
                $comment ?: null,
                (int) $me['id'],
            ]);
            $newOrderId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ord_orders', $newOrderId, null, [
                'order_type'       => $orderType,
                'customer_id_fk'   => $customerId,
                'internal_channel' => ($orderType === 'internal') ? $intChannel : null,
                'requested_date'   => $reqDate,
                'status'           => 'entered',
                'transporter_id_fk'=> $transIdFk,
                'comment'          => $comment ?: null,
                'source'           => 'web',
                'created_by_user_id' => (int) $me['id'],
            ], 'normal');

            // ── INSERT lines ──────────────────────────────────────────────
            $insLine = $pdo->prepare(
                'INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty, line_comment)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($validLines as $line) {
                $insLine->execute([
                    $newOrderId,
                    $line['sku_id'],
                    $line['qty'],
                    $line['comment'] ?: null,
                ]);
                $lineId = (int) $pdo->lastInsertId();
                log_revision($pdo, $me, 'ord_order_lines', $lineId, null, $line, 'normal');
            }

            // ── INSERT status event (status cache already 'entered' by default) ──
            $insEv = $pdo->prepare(
                'INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
                 VALUES (?, "entered", NOW(), ?, ?)'
            );
            $insEv->execute([$newOrderId, (int) $me['id'], 'Commande saisie']);
            $evId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ord_order_status_events', $evId, null,
                ['order_id_fk' => $newOrderId, 'status' => 'entered'], 'normal');

            $pdo->commit();
            flash_set('ok', 'Commande #' . $newOrderId . ' créée avec succès.');
            redirect_to('/modules/expeditions.php?view=form&edit=' . $newOrderId);
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[expeditions POST] ' . $e->getMessage());
        flash_set('err', 'Erreur lors de l\'enregistrement : ' . pdo_friendly_error($e));
        redirect_to('/modules/expeditions.php?view=form' . ($editId ? '&edit=' . $editId : ''));
    }
}

// ── GET — load data ───────────────────────────────────────────────────────────
$pdo     = maltytask_pdo();
$loadErr = null;

// Data common to multiple views
$customers    = [];
$transporters = [];
$skus         = [];

// Commandes view
$recentOrders = [];

// Saisie view (edit prefill)
$editOrder    = null;
$editLines    = [];

try {
    // Active customers (for typeahead)
    $custStmt = $pdo->query(
        'SELECT id, name, bc_customer_no, trade_channel, default_transporter_id_fk
           FROM ref_customers
          WHERE is_active = 1
          ORDER BY name ASC'
    );
    $customers = $custStmt->fetchAll(PDO::FETCH_ASSOC);

    // Active transporters (ordered)
    $transStmt = $pdo->query(
        'SELECT id, name FROM ref_transporters
          WHERE is_active = 1
          ORDER BY sort_order ASC, name ASC'
    );
    $transporters = $transStmt->fetchAll(PDO::FETCH_ASSOC);

    // Active SKUs
    $skuStmt = $pdo->query(
        'SELECT s.id, s.sku_code, s.format, s.hl_per_unit
           FROM ref_skus s
          WHERE s.is_active = 1
          ORDER BY s.format ASC, s.sku_code ASC'
    );
    $skus = $skuStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($view === 'commandes') {
        // 20 most recent orders grouped conceptually by requested_date
        $ordStmt = $pdo->query(
            'SELECT o.id, o.order_type, o.internal_channel, o.requested_date,
                    o.status, o.comment, o.created_at,
                    c.name AS customer_name
               FROM ord_orders o
               LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
              ORDER BY o.requested_date DESC, o.id DESC
              LIMIT 20'
        );
        $recentOrders = $ordStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($view === 'form' && $editId > 0) {
        $ordRow = $pdo->prepare(
            'SELECT o.id, o.order_type, o.internal_channel, o.requested_date,
                    o.status, o.comment, o.customer_id_fk, o.transporter_id_fk,
                    c.name AS customer_name
               FROM ord_orders o
               LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
              WHERE o.id = ?
              LIMIT 1'
        );
        $ordRow->execute([$editId]);
        $editOrder = $ordRow->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editOrder !== null) {
            $lineStmt = $pdo->prepare(
                'SELECT l.id, l.sku_id_fk, l.qty, l.line_comment,
                        s.sku_code, s.format, s.hl_per_unit
                   FROM ord_order_lines l
                   JOIN ref_skus s ON s.id = l.sku_id_fk
                  WHERE l.order_id_fk = ?
                  ORDER BY l.id ASC'
            );
            $lineStmt->execute([$editId]);
            $editLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (Throwable $e) {
    $loadErr = $e->getMessage();
    error_log('[expeditions GET] ' . $e->getMessage());
}

// ── Build JSON payloads for JS (XSS-safe) ────────────────────────────────────
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

// Customer typeahead data + default_transporter_id_fk map (for JS auto-fill)
$expCustomers = array_map(fn($c) => [
    'id'            => (int) $c['id'],
    'name'          => $c['name'],
    'bc_no'         => $c['bc_customer_no'] ?? '',
    'channel'       => $c['trade_channel'] ?? '',
    'default_trans' => $c['default_transporter_id_fk']
                           ? (int) $c['default_transporter_id_fk'] : null,
], $customers);

// SKU typeahead data
$expSkus = array_map(fn($s) => [
    'id'         => (int) $s['id'],
    'sku_code'   => $s['sku_code'],
    'format'     => $s['format'] ?? '',
    'hl_per_unit'=> (float) $s['hl_per_unit'],
], $skus);

// Transporters list
$expTransporters = array_map(fn($t) => [
    'id'   => (int) $t['id'],
    'name' => $t['name'],
], $transporters);

$customersJson    = json_encode($expCustomers, $jsonFlags);
$skusJson         = json_encode($expSkus, $jsonFlags);
$transportersJson = json_encode($expTransporters, $jsonFlags);
$editOrderJson    = $editOrder !== null
    ? json_encode($editOrder, $jsonFlags) : 'null';
$editLinesJson    = $editLines
    ? json_encode(array_map(fn($l) => [
        'sku_id'      => (int) $l['sku_id_fk'],
        'sku_code'    => $l['sku_code'],
        'format'      => $l['format'] ?? '',
        'hl_per_unit' => (float) $l['hl_per_unit'],
        'qty'         => (float) $l['qty'],
        'comment'     => $l['line_comment'] ?? '',
    ], $editLines), $jsonFlags) : '[]';

$csrf          = csrf_token();
$active_module = 'expeditions';

/**
 * Format a date string (YYYY-MM-DD) as dd/mm/yyyy (DMY system-wide).
 */
function exp_fmt_date(?string $d): string
{
    if ($d === null || $d === '') return '—';
    $parts = explode('-', $d);
    if (count($parts) !== 3) return htmlspecialchars($d);
    return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
}

$isReadOnly = $editOrder !== null
    && in_array((string) $editOrder['status'], ['shipped', 'cancelled'], true);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Expéditions — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/expeditions.css?v=<?= @filemtime(__DIR__ . '/../css/expeditions.css') ?: time() ?>">
</head>
<body class="home op-form-page expeditions">

<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="op-form__header exp-page-header">
    <div class="op-form__eyebrow">Logistique · Dispatch</div>
    <h1 class="op-form__title">Expé<em>ditions</em></h1>
    <div class="exp-header-actions">
      <a href="/modules/expeditions.php?view=form"
         class="exp-new-btn<?= ($view === 'form' && $editId === 0) ? ' exp-new-btn--active' : '' ?>"
         aria-label="Nouvelle commande">+ Nouvelle commande</a>
    </div>
  </div>

  <!-- ── Tab nav ──────────────────────────────────────────────────────────── -->
  <nav class="exp-tabs" aria-label="Vues Expéditions">
    <a href="/modules/expeditions.php"
       class="exp-tab<?= $view === 'commandes' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'commandes' ? 'aria-current="page"' : '' ?>>Commandes</a>
    <a href="/modules/expeditions.php?view=form"
       class="exp-tab<?= $view === 'form' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'form' ? 'aria-current="page"' : '' ?>>Saisie</a>
    <a href="/modules/expeditions.php?view=stock"
       class="exp-tab<?= $view === 'stock' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'stock' ? 'aria-current="page"' : '' ?>>Stock PF</a>
  </nav>

  <!-- ══════════════════════════════════════════════════════════════════════
       COMMANDES VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'commandes'): ?>

  <div class="exp-section">
    <div class="op-form__card exp-orders-card">
      <div class="op-form__card-title">20 commandes les plus récentes</div>

      <?php if (empty($recentOrders)): ?>
        <p class="exp-empty">Tableau de bord en construction — aucune commande enregistrée.</p>
      <?php else: ?>
        <?php
        // Group by requested_date for display
        $grouped = [];
        foreach ($recentOrders as $ord) {
            $d = (string) ($ord['requested_date'] ?? '');
            if (!isset($grouped[$d])) $grouped[$d] = [];
            $grouped[$d][] = $ord;
        }
        ?>
        <?php foreach ($grouped as $date => $dayOrders): ?>
          <div class="exp-date-group">
            <div class="exp-date-label"><?= exp_fmt_date($date) ?></div>
            <?php foreach ($dayOrders as $ord): ?>
              <?php
              $status = (string) ($ord['status'] ?? 'entered');
              $isCanc = $status === 'cancelled';
              $isShip = $status === 'shipped';
              ?>
              <div class="exp-order-row" data-order-id="<?= (int) $ord['id'] ?>">
                <span class="exp-order-id">#<?= (int) $ord['id'] ?></span>

                <?php if ($ord['order_type'] === 'customer'): ?>
                  <span class="exp-order-party">
                    <?= htmlspecialchars($ord['customer_name'] ?? '—') ?>
                  </span>
                <?php else: ?>
                  <span class="exp-order-party exp-order-party--internal">
                    <?= htmlspecialchars(EXP_INTERNAL_LABELS[$ord['internal_channel'] ?? ''] ?? ($ord['internal_channel'] ?? '—')) ?>
                  </span>
                <?php endif ?>

                <!-- Progress chips -->
                <div class="exp-progress" aria-label="Statut : <?= htmlspecialchars(EXP_STATUS_LABELS[$status] ?? $status) ?>">
                  <?php if ($isCanc): ?>
                    <span class="exp-chip exp-chip--cancelled">Annulée</span>
                  <?php else: ?>
                    <?php foreach (['confirmed', 'picked', 'bl_printed', 'shipped'] as $stage): ?>
                      <?php
                      $stageRank  = EXP_STATUS_RANK[$stage] ?? 0;
                      $curRank    = EXP_STATUS_RANK[$status] ?? 0;
                      $isDone     = $curRank >= $stageRank;
                      $isNext     = !$isDone && $curRank === ($stageRank - 1);
                      $chipClass  = 'exp-chip exp-chip--' . $stage;
                      if ($isDone)  $chipClass .= ' exp-chip--done';
                      if ($isNext)  $chipClass .= ' exp-chip--next';
                      $stageLabel = EXP_STATUS_LABELS[$stage] ?? $stage;
                      ?>
                      <button
                        class="<?= $chipClass ?>"
                        data-order-id="<?= (int) $ord['id'] ?>"
                        data-action="advance"
                        data-status="<?= htmlspecialchars($stage) ?>"
                        <?= ($isDone || !$isNext) ? 'disabled aria-disabled="true"' : '' ?>
                        aria-label="<?= $isDone ? '✓ ' . $stageLabel . ' — fait' : ($isNext ? 'Marquer : ' . $stageLabel : $stageLabel) ?>">
                        <?= $isDone ? '✓' : '' ?> <?= htmlspecialchars($stageLabel) ?>
                      </button>
                    <?php endforeach ?>
                  <?php endif ?>
                </div>

                <div class="exp-order-actions">
                  <?php if (!$isShip && !$isCanc): ?>
                    <button class="exp-action-btn exp-action-btn--cancel"
                            data-order-id="<?= (int) $ord['id'] ?>"
                            data-action="cancel"
                            aria-label="Annuler la commande #<?= (int) $ord['id'] ?>">Annuler</button>
                  <?php endif ?>
                  <a class="exp-action-btn exp-action-btn--edit"
                     href="/modules/expeditions.php?view=form&edit=<?= (int) $ord['id'] ?>">✎</a>
                </div>
              </div>
            <?php endforeach ?>
          </div>
        <?php endforeach ?>
      <?php endif ?>
    </div>
  </div>

  <?php endif ?>


  <!-- ══════════════════════════════════════════════════════════════════════
       SAISIE VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'form'): ?>

  <!-- Window globals for JS — injected safely via json_encode (JSON_HEX_TAG|JSON_HEX_AMP) -->
  <script>
    window.EXP_CUSTOMERS    = <?= $customersJson ?>;
    window.EXP_SKUS         = <?= $skusJson ?>;
    window.EXP_TRANSPORTERS = <?= $transportersJson ?>;
    window.EXP_EDIT_ORDER   = <?= $editOrderJson ?>;
    window.EXP_EDIT_LINES   = <?= $editLinesJson ?>;
  </script>

  <?php if ($isReadOnly): ?>
    <div class="db-flash db-flash--warn">
      ⚠ Commande <?= htmlspecialchars(EXP_STATUS_LABELS[$editOrder['status']] ?? $editOrder['status']) ?> — lecture seule. Aucune modification possible.
    </div>
  <?php endif ?>

  <form method="POST"
        action="/modules/expeditions.php?view=form<?= $editId ? '&edit=' . $editId : '' ?>"
        class="exp-form"
        id="exp-order-form"
        novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- ── Card 1: Type + Partie ───────────────────────────────────────── -->
    <div class="op-form__card exp-card">
      <div class="op-form__card-title">
        <?= $editId ? 'Modifier commande #' . $editId : 'Nouvelle commande' ?>
      </div>

      <div class="exp-type-toggle">
        <label class="exp-toggle-label">Type de commande</label>
        <div class="exp-toggle-group" role="radiogroup" aria-label="Type de commande">
          <?php $selType = $editOrder ? $editOrder['order_type'] : 'customer'; ?>
          <button type="button" class="exp-toggle-btn <?= $selType === 'customer' ? 'exp-toggle-btn--active' : '' ?>"
                  id="exp-type-customer" role="radio"
                  aria-checked="<?= $selType === 'customer' ? 'true' : 'false' ?>"
                  <?= $isReadOnly ? 'disabled' : '' ?>>
            Client
          </button>
          <button type="button" class="exp-toggle-btn <?= $selType === 'internal' ? 'exp-toggle-btn--active' : '' ?>"
                  id="exp-type-internal" role="radio"
                  aria-checked="<?= $selType === 'internal' ? 'true' : 'false' ?>"
                  <?= $isReadOnly ? 'disabled' : '' ?>>
            Canal interne
          </button>
        </div>
        <input type="hidden" name="order_type" id="exp-order-type" value="<?= htmlspecialchars($selType) ?>">
      </div>

      <!-- Client mode -->
      <div id="exp-customer-panel" class="exp-party-panel <?= $selType !== 'customer' ? 'exp-party-panel--hidden' : '' ?>">
        <div class="op-form__grid">

          <div class="op-form__field op-form__field--full">
            <label class="op-form__label" for="exp-cust-search">Client</label>
            <div class="exp-typeahead-wrap" id="exp-cust-wrap">
              <input type="text"
                     id="exp-cust-search"
                     class="op-form__input exp-typeahead-input"
                     placeholder="Rechercher un client…"
                     autocomplete="off"
                     autocorrect="off"
                     spellcheck="false"
                     value="<?= $editOrder && $editOrder['order_type'] === 'customer'
                         ? htmlspecialchars($editOrder['customer_name'] ?? '') : '' ?>"
                     <?= $isReadOnly ? 'disabled' : '' ?>>
              <ul id="exp-cust-dropdown" class="exp-typeahead-dropdown" role="listbox"
                  aria-label="Clients" hidden></ul>
            </div>
            <input type="hidden" name="customer_id" id="exp-customer-id"
                   value="<?= $editOrder && $editOrder['order_type'] === 'customer'
                       ? (int) $editOrder['customer_id_fk'] : 0 ?>">
          </div>

          <!-- Inline new customer -->
          <div class="op-form__field op-form__field--full" id="exp-new-cust-panel" hidden>
            <label class="op-form__label" for="exp-new-cust-name">
              Nouveau client
              <span class="op-form__unit">sera créé avec needs_review=1</span>
            </label>
            <input type="text"
                   id="exp-new-cust-name"
                   name="new_customer_name"
                   class="op-form__input"
                   placeholder="Nom du client…"
                   maxlength="200"
                   <?= $isReadOnly ? 'disabled' : '' ?>>
          </div>

        </div>
      </div>

      <!-- Internal channel mode -->
      <?php $selChan = $editOrder ? ($editOrder['internal_channel'] ?? '') : ''; ?>
      <div id="exp-internal-panel" class="exp-party-panel <?= $selType !== 'internal' ? 'exp-party-panel--hidden' : '' ?>">
        <div class="op-form__field">
          <label class="op-form__label" for="exp-internal-channel">Canal</label>
          <select name="internal_channel"
                  id="exp-internal-channel"
                  class="op-form__select"
                  <?= $isReadOnly ? 'disabled' : '' ?>>
            <option value="">— choisir —</option>
            <?php foreach (EXP_INTERNAL_LABELS as $val => $lbl): ?>
              <option value="<?= htmlspecialchars($val) ?>"
                      <?= $selChan === $val ? 'selected' : '' ?>>
                <?= htmlspecialchars($lbl) ?>
              </option>
            <?php endforeach ?>
          </select>
        </div>
      </div>

    </div><!-- /card 1 -->

    <!-- ── Card 2: Date + Transporteur ────────────────────────────────── -->
    <div class="op-form__card exp-card">
      <div class="op-form__card-title">Livraison</div>
      <div class="op-form__grid">

        <div class="op-form__field">
          <label class="op-form__label" for="exp-req-date">Date de livraison <span class="exp-required">*</span></label>
          <input type="date"
                 id="exp-req-date"
                 name="requested_date"
                 class="op-form__input"
                 value="<?= $editOrder ? htmlspecialchars($editOrder['requested_date'] ?? date('Y-m-d')) : date('Y-m-d') ?>"
                 required
                 <?= $isReadOnly ? 'disabled' : '' ?>>
        </div>

        <div class="op-form__field">
          <label class="op-form__label" for="exp-transporter">Transporteur
            <span class="op-form__unit">optionnel</span>
          </label>
          <select name="transporter_id"
                  id="exp-transporter"
                  class="op-form__select"
                  <?= $isReadOnly ? 'disabled' : '' ?>>
            <option value="0">— aucun —</option>
            <?php foreach ($transporters as $t): ?>
              <?php $selTrans = $editOrder ? (int)($editOrder['transporter_id_fk'] ?? 0) : 0; ?>
              <option value="<?= (int) $t['id'] ?>"
                      <?= $selTrans === (int)$t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['name']) ?>
              </option>
            <?php endforeach ?>
          </select>
          <span class="exp-trans-hint" id="exp-trans-hint" hidden>
            Transporteur par défaut du client pré-sélectionné.
          </span>
        </div>

      </div>
    </div><!-- /card 2 -->

    <!-- ── Card 3: Lignes article ──────────────────────────────────────── -->
    <div class="op-form__card exp-card">
      <div class="op-form__card-title">
        Articles
        <span class="exp-lines-recap" id="exp-lines-recap" hidden>
          — <span id="exp-recap-count">0</span> ligne<span id="exp-recap-s"></span>
          · <span id="exp-recap-hl">0.00</span> HL
        </span>
      </div>

      <div id="exp-lines-container" class="exp-lines-container">
        <!-- Lines are rendered by JS from EXP_EDIT_LINES or empty on create -->
      </div>

      <button type="button" id="exp-add-line"
              class="exp-add-line-btn"
              <?= $isReadOnly ? 'disabled' : '' ?>>
        + Ajouter une ligne
      </button>
    </div><!-- /card 3 -->

    <!-- ── Card 4: Commentaire ─────────────────────────────────────────── -->
    <div class="op-form__card exp-card">
      <div class="op-form__card-title">Commentaire</div>
      <textarea name="comment"
                id="exp-comment"
                class="op-form__textarea"
                rows="3"
                placeholder="Notes optionnelles pour cette commande…"
                <?= $isReadOnly ? 'disabled' : '' ?>><?= htmlspecialchars($editOrder ? ($editOrder['comment'] ?? '') : '') ?></textarea>
    </div><!-- /card 4 -->

    <!-- ── Submit bar ──────────────────────────────────────────────────── -->
    <?php if (!$isReadOnly): ?>
    <div class="op-form__submit-bar exp-submit-bar">
      <button type="submit" class="op-form__btn op-form__btn--primary" id="exp-submit-btn">
        <?= $editId ? 'Enregistrer les modifications' : 'Créer la commande' ?>
      </button>
      <?php if ($editId): ?>
        <a href="/modules/expeditions.php?view=form" class="op-form__btn op-form__btn--secondary">
          + Nouvelle commande
        </a>
      <?php endif ?>
      <a href="/modules/expeditions.php" class="exp-cancel-link">Annuler</a>
    </div>
    <?php else: ?>
    <div class="exp-readonly-bar">
      <a href="/modules/expeditions.php" class="op-form__btn op-form__btn--secondary">← Retour aux commandes</a>
    </div>
    <?php endif ?>

  </form>

  <script src="/js/expeditions-form.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-form.js') ?: time() ?>"></script>

  <?php endif ?>


  <!-- ══════════════════════════════════════════════════════════════════════
       STOCK PF VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'stock'): ?>
  <div class="exp-section">
    <div class="op-form__card exp-placeholder-card">
      <div class="op-form__card-title">Stock Produits Finis</div>
      <p class="exp-placeholder-text">Bientôt disponible — tableau de stock PF en temps réel.</p>
    </div>
  </div>
  <?php endif ?>

</main>

<?php if ($view === 'commandes'): ?>
<script src="/js/expeditions.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions.js') ?: time() ?>"></script>
<script>
  window.EXP_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php endif ?>

</body>
</html>
