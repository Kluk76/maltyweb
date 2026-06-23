<?php
declare(strict_types=1);
/**
 * POST /api/expeditions-status.php — Status-advance endpoint for ord_orders.
 *
 * Accepts JSON POST: { csrf, order_id, action }
 *   action ∈ { advance, cancel, revert }
 *
 * advance: entered→confirmed→picked→bl_printed→shipped (refuse past shipped).
 * revert:  one step back (refuse below entered).
 * cancel:  from any non-shipped state; terminal.
 *
 * ONE transaction: insert ord_order_status_events + UPDATE ord_orders.status
 * cache + log_revision on ord_orders.
 *
 * Response: { ok:true, status:'…', label:'…' }
 *         | { ok:false, error:'…' }
 *         | { ok:false, reason:'expired', csrf:'…' }  — CSRF retry hint
 *
 * HTTP: 200 success, 400 bad input/CSRF, 403 unauth, 405 wrong method, 500 error.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$me = current_user();
if ($me === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Authentification requise.']);
    exit;
}

// ── Write-role gate ───────────────────────────────────────────────────────────
if (!can_write_expeditions($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Droits insuffisants pour modifier une commande.']);
    exit;
}

// ── Decode JSON body ──────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

// ── CSRF — first gate; on fail return fresh token for one retry ───────────────
$postedCsrf = $data['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    // Regenerate a fresh token so the JS can retry once
    $freshCsrf = csrf_token();
    echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => $freshCsrf]);
    exit;
}

// ── Status rank map — NEVER compare by ENUM ordinal ──────────────────────────
$statusRank = [
    'entered'    => 0,
    'confirmed'  => 1,
    'picked'     => 2,
    'bl_printed' => 3,
    'shipped'    => 4,
    'cancelled'  => -1,
];
$statusLabels = [
    'entered'    => 'Saisie',
    'confirmed'  => 'Confirmée',
    'picked'     => 'Préparée',
    'bl_printed' => 'BL imprimé',
    'shipped'    => 'Livrée',
    'cancelled'  => 'Annulée',
];
$advanceMap = [
    'entered'    => 'confirmed',
    'confirmed'  => 'picked',
    'picked'     => 'bl_printed',
    'bl_printed' => 'shipped',
];
$revertMap = [
    'confirmed'  => 'entered',
    'picked'     => 'confirmed',
    'bl_printed' => 'picked',
    'shipped'    => 'bl_printed',
];

// ── Read input ────────────────────────────────────────────────────────────────
$orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
$action  = isset($data['action'])   ? (string) $data['action'] : '';

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order_id invalide.']);
    exit;
}
if (!in_array($action, ['advance', 'cancel', 'revert', 'set_comment'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action invalide.']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

try {
    // Fetch current order
    $ordStmt = $pdo->prepare(
        'SELECT id, status FROM ord_orders WHERE id = ? LIMIT 1'
    );
    $ordStmt->execute([$orderId]);
    $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

    if ($order === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Commande introuvable.']);
        exit;
    }

    $currentStatus = (string) $order['status'];

    // Determine new status
    $newStatus = null;
    $comment   = null;

    if ($action === 'advance') {
        if ($currentStatus === 'shipped') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà livrée — impossible d\'avancer.']);
            exit;
        }
        if ($currentStatus === 'cancelled') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande annulée — impossible d\'avancer.']);
            exit;
        }
        $newStatus = $advanceMap[$currentStatus] ?? null;
        if ($newStatus === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Avancement impossible depuis ce statut.']);
            exit;
        }
        $comment = 'Avancement depuis ' . ($statusLabels[$currentStatus] ?? $currentStatus);

    } elseif ($action === 'cancel') {
        if ($currentStatus === 'shipped') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà livrée — annulation impossible.']);
            exit;
        }
        if ($currentStatus === 'cancelled') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande déjà annulée.']);
            exit;
        }
        $newStatus = 'cancelled';
        $comment   = 'Annulée depuis ' . ($statusLabels[$currentStatus] ?? $currentStatus);

    } elseif ($action === 'revert') {
        if ($currentStatus === 'entered') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Impossible de revenir en arrière depuis Saisie.']);
            exit;
        }
        if ($currentStatus === 'cancelled') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande annulée — impossible de revenir en arrière.']);
            exit;
        }
        $newStatus = $revertMap[$currentStatus] ?? null;
        if ($newStatus === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Retour impossible depuis ce statut.']);
            exit;
        }
        $comment = 'Retour depuis ' . ($statusLabels[$currentStatus] ?? $currentStatus);
    }

    // ── set_comment: update order comment (no status change) ─────────────
    if ($action === 'set_comment') {
        $newComment = trim((string) ($data['comment'] ?? ''));
        if (mb_strlen($newComment) > 2000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commentaire trop long (max 2000 caractères).']);
            exit;
        }

        // Fetch current order (comment + status for before-value and event)
        $ordStmt2 = $pdo->prepare(
            'SELECT id, status, comment FROM ord_orders WHERE id = ? LIMIT 1'
        );
        $ordStmt2->execute([$orderId]);
        $order2 = $ordStmt2->fetch(PDO::FETCH_ASSOC);

        if ($order2 === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Commande introuvable.']);
            exit;
        }

        $beforeComment  = $order2['comment'];
        $currentStatus2 = (string) $order2['status'];
        $storeComment   = $newComment !== '' ? $newComment : null;

        $pdo->beginTransaction();

        $updCom = $pdo->prepare(
            'UPDATE ord_orders SET comment = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $updCom->execute([$storeComment, $orderId]);

        $insEv2 = $pdo->prepare(
            'INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
             VALUES (?, ?, NOW(), ?, ?)'
        );
        $insEv2->execute([$orderId, $currentStatus2, (int) $me['id'], 'Commentaire modifié']);
        $evId2 = (int) $pdo->lastInsertId();

        log_revision($pdo, $me, 'ord_orders', $orderId,
            ['comment' => $beforeComment],
            ['comment' => $storeComment],
            'normal',
            'Commentaire modifié'
        );
        log_revision($pdo, $me, 'ord_order_status_events', $evId2, null,
            ['order_id_fk' => $orderId, 'status' => $currentStatus2, 'comment' => 'Commentaire modifié'],
            'normal'
        );

        $pdo->commit();

        $freshCsrf = csrf_token();
        echo json_encode([
            'ok'      => true,
            'comment' => $storeComment ?? '',
            'csrf'    => $freshCsrf,
        ]);
        exit;
    }

    // ── ONE transaction: status event + cache update + audit ─────────────
    $pdo->beginTransaction();

    $insEv = $pdo->prepare(
        'INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
         VALUES (?, ?, NOW(), ?, ?)'
    );
    $insEv->execute([$orderId, $newStatus, (int) $me['id'], $comment]);
    $evId = (int) $pdo->lastInsertId();

    $updOrd = $pdo->prepare(
        'UPDATE ord_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    );
    $updOrd->execute([$newStatus, $orderId]);

    log_revision($pdo, $me, 'ord_orders', $orderId,
        ['status' => $currentStatus],
        ['status' => $newStatus],
        'normal',
        $comment
    );
    log_revision($pdo, $me, 'ord_order_status_events', $evId, null,
        ['order_id_fk' => $orderId, 'status' => $newStatus, 'comment' => $comment],
        'normal'
    );

    $pdo->commit();

    // ── Confirmation email (entered→confirmed only, best-effort) ────────────
    $emailSent   = false;
    $emailReason = null;
    if ($action === 'advance' && $currentStatus === 'entered' && $newStatus === 'confirmed') {
        require_once __DIR__ . '/../../app/services/mailer.php';
        $mode = (string) system_setting('confirmation_email_mode', 'fulfilment', 'off');
        if ($mode === 'off') {
            $emailReason = 'mode_off';
        } else {
            // Idempotence: only send once
            $dupChk = $pdo->prepare(
                "SELECT COUNT(*) FROM ord_order_status_events
                  WHERE order_id_fk = ? AND comment LIKE '%[email:confirmation:sent]%'"
            );
            $dupChk->execute([$orderId]);
            if ((int) $dupChk->fetchColumn() > 0) {
                $emailReason = 'already_sent';
            } else {
                // Load order + lines
                $ordRow = $pdo->prepare(
                    'SELECT o.id, o.bc_no, o.source_ref, o.requested_date,
                            c.name AS customer_name
                       FROM ord_orders o
                  LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
                      WHERE o.id = ? LIMIT 1'
                );
                $ordRow->execute([$orderId]);
                $ord = $ordRow->fetch(PDO::FETCH_ASSOC);

                require_once __DIR__ . '/../../app/sku_catalog.php';

                $linesStmt = $pdo->prepare(
                    'SELECT s.sku_code AS ref, s.beer_raw AS beer_raw, s.unit_label AS unit_label,
                            p.run_type AS run_type, l.qty AS qty
                       FROM ord_order_lines l
                       JOIN ref_skus s ON s.id = l.sku_id_fk
                  LEFT JOIN ref_packaging_formats p ON p.id = s.format_id
                      WHERE l.order_id_fk = ? ORDER BY l.id'
                );
                $linesStmt->execute([$orderId]);
                $rawLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

                $linesData = [];
                foreach ($rawLines as $row) {
                    $container = run_type_container_label($row['run_type'] ?? null);
                    $designation = trim((string)$row['beer_raw'])
                        . ' – ' . ($container !== '' ? $container . ' – ' : '')
                        . (string)$row['unit_label'];
                    $linesData[] = [
                        'ref'         => $row['ref'],
                        'designation' => $designation,
                        'qty'         => $row['qty'],
                    ];
                }

                // Format order data for template
                $bcNo    = trim((string)($ord['bc_no']      ?? ''));
                $srcRef  = trim((string)($ord['source_ref'] ?? ''));
                $orderNo = $bcNo ?: $srcRef ?: ('CMD-' . (string) $orderId);

                $rawDate = trim((string)($ord['requested_date'] ?? ''));
                $dateFr  = '';
                if ($rawDate !== '' && $rawDate !== '0000-00-00') {
                    $dt = DateTime::createFromFormat('Y-m-d', substr($rawDate, 0, 10));
                    if ($dt !== false) $dateFr = $dt->format('d/m/Y');
                }

                $orderData = [
                    'order_no'          => $orderNo,
                    'customer_name'     => (string)($ord['customer_name'] ?? ''),
                    'requested_date_fr' => $dateFr,
                ];

                $address = null;

                $tpl = mail_order_confirmation_template($orderData, $linesData, $address);

                // Recipients
                $fromAddr = (string) system_setting('confirmation_email_from', 'fulfilment', 'commandes@lanebuleuse.ch');
                $fromName = 'La Nébuleuse';
                if ($mode === 'test') {
                    $testTo = (string) system_setting('confirmation_email_test_to', 'fulfilment', 'kouros@lanebuleuse.ch');
                    $recipients = array_filter(explode(';', $testTo), fn($e) => filter_var(trim($e), FILTER_VALIDATE_EMAIL));
                    $recipients = array_map('trim', $recipients);
                } else {
                    $rawEmail = '';
                    try {
                        $cStmt = $pdo->prepare('SELECT c.email FROM ref_customers c JOIN ord_orders o ON o.customer_id_fk=c.id WHERE o.id=? LIMIT 1');
                        $cStmt->execute([$orderId]);
                        $rawEmail = trim((string)($cStmt->fetchColumn() ?: ''));
                    } catch (Throwable $ce) { $rawEmail = ''; }
                    $recipients = ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL))
                        ? [$rawEmail]
                        : [];
                    if (empty($recipients)) {
                        $emailReason = 'no_email';
                    }
                }

                if ($emailReason === null && !empty($recipients)) {
                    $logoPath = __DIR__ . '/../../app/services/email-assets/nebuleuse-logo.png';
                    $inlineImages = is_readable($logoPath)
                        ? [['path' => $logoPath, 'cid' => 'nebuleuse-logo', 'mime' => 'image/png']]
                        : [];

                    $sentCount = 0;
                    foreach ($recipients as $recipAddr) {
                        $r = send_mail(
                            $recipAddr,
                            $tpl['subject'],
                            $tpl['html'],
                            $tpl['text'],
                            $fromAddr,
                            $fromName,
                            $fromAddr,
                            $inlineImages ?: null
                        );
                        if ($r) $sentCount++;
                    }

                    if ($sentCount > 0) {
                        $emailSent = true;
                        // Tag idempotence sentinel in the status-events log
                        $tagEv = $pdo->prepare(
                            'INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
                             VALUES (?, ?, NOW(), ?, ?)'
                        );
                        $tagEv->execute([$orderId, 'confirmed', (int) $me['id'], '[email:confirmation:sent]']);
                        log_revision($pdo, $me, 'ord_orders', $orderId, [], ['email_confirmation_sent' => true], 'normal', '[email:confirmation:sent]');
                        $emailReason = 'sent';
                    } else {
                        $emailReason = 'send_failed';
                    }
                }
            }
        }
    }

    // Return fresh CSRF token so client stays hot
    $freshCsrf = csrf_token();
    echo json_encode([
        'ok'           => true,
        'status'       => $newStatus,
        'label'        => $statusLabels[$newStatus] ?? $newStatus,
        'csrf'         => $freshCsrf,
        'email_sent'   => $emailSent,
        'email_reason' => $emailReason,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[expeditions-status] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne.']);
}
