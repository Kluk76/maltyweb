<?php
declare(strict_types=1);

/**
 * GET/POST /api/sf-comm-thread.php
 *
 * Supplier communication timeline: messages, manual notes, thread assignment.
 *
 * GET ?supplier_id=X    — full timeline for a supplier (threads + messages + docs)
 *                         + review_threads (unassigned threads)
 * GET ?review=1         — review-bucket threads only (admin/manager)
 * GET ?supplier_docs=X  — attachable document corpus for supplier X (admin/manager)
 *
 * POST action=add_note        — add a manual note (manager or admin)
 * POST action=assign_thread   — assign a review thread to a supplier (admin only)
 * POST action=send_reply      — send outbound email reply on a thread (admin/manager)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me = current_user();
if (!can_use_comm_tracker($me)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Accès réservé aux managers et administrateurs.']);
    exit;
}

$pdo    = maltytask_pdo();
$method = $_SERVER['REQUEST_METHOD'];

// ── Email helper ──────────────────────────────────────────────────────────────
function extract_email_address(string $raw): ?string
{
    if (preg_match('/<([^>@]+@[^>]+)>/', $raw, $m)) {
        return $m[1];
    }
    $t = trim($raw);
    return filter_var($t, FILTER_VALIDATE_EMAIL) ? $t : null;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build the flat timeline for a supplier: threads + messages + docs.
 * HTML bodies are stripped; raw HTML is never returned.
 */
function build_supplier_timeline(PDO $pdo, int $supplierId): array
{
    // 1. Load all active threads for this supplier
    $stThreads = $pdo->prepare(
        'SELECT id, subject, gmail_thread_id, last_message_at, is_active, created_at
           FROM comm_threads
          WHERE supplier_id_fk = ? AND is_active = 1 AND purge_status = \'live\'
          ORDER BY last_message_at ASC'
    );
    $stThreads->execute([$supplierId]);
    $threads = $stThreads->fetchAll(PDO::FETCH_ASSOC);

    if (empty($threads)) {
        return [];
    }

    $threadIds    = array_column($threads, 'id');
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

    // 2. Load all messages for those threads
    $stMsgs = $pdo->prepare(
        "SELECT id, thread_id_fk, direction, from_address, to_address, cc_address,
                subject, body_format, body, body_snippet, sent_at, message_id,
                gmail_message_id, source, created_by_user_id, sent_by_user_id
           FROM comm_messages
          WHERE thread_id_fk IN ({$placeholders})
          ORDER BY sent_at ASC"
    );
    $stMsgs->execute($threadIds);
    $messages = $stMsgs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($messages)) {
        return [];
    }

    $messageIds       = array_column($messages, 'id');
    $msgPlaceholders  = implode(',', array_fill(0, count($messageIds), '?'));

    // 3. Load all docs for those messages
    $stDocs = $pdo->prepare(
        "SELECT cmd.id, cmd.message_id_fk, cmd.doc_file_id_fk, cmd.attachment_filename,
                cmd.mime_type, cmd.direction,
                df.file_id AS doc_file_uuid
           FROM comm_message_docs cmd
      LEFT JOIN doc_files df ON df.id = cmd.doc_file_id_fk
          WHERE cmd.message_id_fk IN ({$msgPlaceholders})"
    );
    $stDocs->execute($messageIds);
    $docs = $stDocs->fetchAll(PDO::FETCH_ASSOC);

    // Index docs by message_id_fk
    $docsByMsg = [];
    foreach ($docs as $doc) {
        $docsByMsg[(int) $doc['message_id_fk']][] = $doc;
    }

    // 4. Build timeline items
    $timeline = [];
    foreach ($messages as $msg) {
        $msgId = (int) $msg['id'];

        // Strip HTML bodies — never return raw HTML
        $body = $msg['body'] ?? '';
        if ($msg['body_format'] === 'html') {
            $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain   = preg_replace('/\s+/', ' ', trim(strip_tags($decoded)));
            $body    = mb_substr($plain, 0, 2000);
        }

        $timeline[] = [
            'type'            => 'message',
            'message_id'      => $msgId,
            'thread_id'       => (int) $msg['thread_id_fk'],
            'direction'       => $msg['direction'],
            'from_address'    => $msg['from_address'] ?? '',
            'to_address'      => $msg['to_address'] ?? '',
            'sent_at'         => $msg['sent_at'],
            'source'          => $msg['source'],
            'sent_by_user_id' => isset($msg['sent_by_user_id']) ? (int)$msg['sent_by_user_id'] : null,
            'body_plain'      => $body,
            'docs'         => array_map(
                static function (array $d): array {
                    return [
                        'id'                => (int) $d['id'],
                        'doc_file_id_fk'    => (int) $d['doc_file_id_fk'],
                        'doc_file_uuid'     => $d['doc_file_uuid'] ?? null,
                        'attachment_filename' => $d['attachment_filename'],
                        'mime_type'         => $d['mime_type'],
                    ];
                },
                $docsByMsg[$msgId] ?? []
            ),
        ];
    }

    // Sort flat by sent_at
    usort($timeline, static fn ($a, $b) => strcmp($a['sent_at'] ?? '', $b['sent_at'] ?? ''));

    // Resolve sent_by_user_id → display names for sent messages
    $sentByIds = array_values(array_unique(array_filter(
        array_map(fn ($item) => $item['sent_by_user_id'] ?? null, $timeline)
    )));
    $userNames = [];
    if (!empty($sentByIds)) {
        $userPlaceholders = implode(',', array_fill(0, count($sentByIds), '?'));
        $stUsers = $pdo->prepare(
            "SELECT id, COALESCE(display_name, email) AS display_name FROM users WHERE id IN ({$userPlaceholders})"
        );
        $stUsers->execute($sentByIds);
        foreach ($stUsers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userNames[(int) $row['id']] = (string) $row['display_name'];
        }
    }
    foreach ($timeline as &$tItem) {
        $uid = $tItem['sent_by_user_id'] ?? null;
        $tItem['sent_by_display'] = ($uid !== null && isset($userNames[$uid])) ? $userNames[$uid] : null;
    }
    unset($tItem);

    return $timeline;
}

/**
 * Load review-bucket threads (unassigned: supplier_id_fk IS NULL AND customer_id_fk IS NULL).
 */
function load_review_threads(PDO $pdo, int $limit = 50): array
{
    $stThreads = $pdo->prepare(
        'SELECT id, subject, last_message_at,
                (SELECT COUNT(*) FROM comm_messages WHERE thread_id_fk = t.id) AS message_count
           FROM comm_threads t
          WHERE supplier_id_fk IS NULL
            AND customer_id_fk IS NULL
            AND is_active = 1
            AND purge_status = \'live\'
          ORDER BY last_message_at DESC
          LIMIT ?'
    );
    $stThreads->execute([$limit]);
    $threads = $stThreads->fetchAll(PDO::FETCH_ASSOC);

    if (empty($threads)) {
        return [];
    }

    // Counterparty addresses: GROUP_CONCAT DISTINCT in-direction from_addresses per thread
    $threadIds    = array_column($threads, 'id');
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $stAddrs = $pdo->prepare(
        "SELECT thread_id_fk, GROUP_CONCAT(DISTINCT from_address ORDER BY from_address SEPARATOR ', ') AS counterparty_addresses
           FROM comm_messages
          WHERE thread_id_fk IN ({$placeholders})
            AND direction = 'in'
          GROUP BY thread_id_fk"
    );
    $stAddrs->execute($threadIds);
    $addrMap = [];
    foreach ($stAddrs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $addrMap[(int) $row['thread_id_fk']] = $row['counterparty_addresses'];
    }

    $result = [];
    foreach ($threads as $t) {
        $result[] = [
            'id'                     => (int) $t['id'],
            'subject'                => $t['subject'],
            'last_message_at'        => $t['last_message_at'],
            'counterparty_addresses' => $addrMap[(int) $t['id']] ?? '',
            'message_count'          => (int) $t['message_count'],
            'is_review_bucket'       => true,
        ];
    }

    return $result;
}

/**
 * Build grouped thread metadata for a supplier's active threads.
 * Returns one entry per thread, keyed by thread id.
 */
function build_supplier_threads(PDO $pdo, int $supplierId): array
{
    $stThreads = $pdo->prepare(
        'SELECT id, subject, last_message_at,
                (SELECT COUNT(*) FROM comm_messages WHERE thread_id_fk = t.id) AS message_count
           FROM comm_threads t
          WHERE supplier_id_fk = ? AND is_active = 1 AND purge_status = \'live\'
          ORDER BY last_message_at DESC'
    );
    $stThreads->execute([$supplierId]);
    $threads = $stThreads->fetchAll(PDO::FETCH_ASSOC);

    if (empty($threads)) {
        return [];
    }

    // Counterparty addresses per thread (in-direction from_addresses)
    $threadIds    = array_column($threads, 'id');
    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
    $stAddrs = $pdo->prepare(
        "SELECT thread_id_fk, GROUP_CONCAT(DISTINCT from_address ORDER BY from_address SEPARATOR ', ') AS counterparty_addresses
           FROM comm_messages
          WHERE thread_id_fk IN ({$placeholders})
            AND direction = 'in'
          GROUP BY thread_id_fk"
    );
    $stAddrs->execute($threadIds);
    $addrMap = [];
    foreach ($stAddrs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $addrMap[(int) $row['thread_id_fk']] = $row['counterparty_addresses'];
    }

    $result = [];
    foreach ($threads as $t) {
        $result[] = [
            'thread_id'              => (int) $t['id'],
            'subject'                => $t['subject'],
            'counterparty_addresses' => $addrMap[(int) $t['id']] ?? '',
            'last_message_at'        => $t['last_message_at'],
            'message_count'          => (int) $t['message_count'],
            'is_review_bucket'       => false,
        ];
    }

    return $result;
}

// ── Routing ───────────────────────────────────────────────────────────────────
try {
    if ($method === 'GET') {

        // GET ?supplier_docs=X — attachable document corpus for a supplier (admin/manager)
        if (isset($_GET['supplier_docs'])) {
            $supplierId = (int) $_GET['supplier_docs'];
            if ($supplierId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'supplier_docs: supplier_id invalide.']);
                exit;
            }

            $docsById = [];

            // (a) Supplier cert documents
            $stCerts = $pdo->prepare(
                'SELECT df.id AS doc_file_id, df.file_id AS file_id_uuid, df.file_name,
                        df.mime_type,
                        COALESCE(scd.expires_on, scd.issued_on) AS dated
                   FROM supplier_cert_documents scd
                   JOIN doc_files df ON df.id = scd.doc_file_id_fk
                  WHERE scd.supplier_id_fk = ?
                    AND scd.doc_file_id_fk IS NOT NULL
                    AND scd.is_active = 1'
            );
            $stCerts->execute([$supplierId]);
            foreach ($stCerts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $docsById[(int) $row['doc_file_id']] = [
                    'doc_file_id'   => (int) $row['doc_file_id'],
                    'file_id_uuid'  => $row['file_id_uuid'],
                    'file_name'     => $row['file_name'],
                    'mime_type'     => $row['mime_type'],
                    'source_label'  => 'Certificat',
                    'dated'         => $row['dated'],
                ];
            }

            // (b) Discussion docs (via comm_message_docs → comm_messages → comm_threads)
            $stDisc = $pdo->prepare(
                'SELECT df.id AS doc_file_id, df.file_id AS file_id_uuid, df.file_name,
                        df.mime_type,
                        DATE(cm.sent_at) AS dated
                   FROM comm_message_docs cmd
                   JOIN doc_files df ON df.id = cmd.doc_file_id_fk
                   JOIN comm_messages cm ON cm.id = cmd.message_id_fk
                   JOIN comm_threads ct ON ct.id = cm.thread_id_fk
                  WHERE ct.supplier_id_fk = ? AND ct.purge_status = \'live\''
            );
            $stDisc->execute([$supplierId]);
            foreach ($stDisc->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $docsById[(int) $row['doc_file_id']] = [
                    'doc_file_id'   => (int) $row['doc_file_id'],
                    'file_id_uuid'  => $row['file_id_uuid'],
                    'file_name'     => $row['file_name'],
                    'mime_type'     => $row['mime_type'],
                    'source_label'  => 'Discussion',
                    'dated'         => $row['dated'],
                ];
            }

            // (c) Delivery docs (invoices / delivery notes linked to this supplier)
            $stDel = $pdo->prepare(
                'SELECT df.id AS doc_file_id, df.file_id AS file_id_uuid, df.file_name,
                        df.mime_type,
                        DATE(d.date_received) AS dated
                   FROM inv_deliveries d
                   JOIN doc_files df ON df.id = d.file_id_fk
                  WHERE d.supplier_fk = ?
                    AND d.file_id_fk IS NOT NULL'
            );
            $stDel->execute([$supplierId]);
            foreach ($stDel->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $docsById[(int) $row['doc_file_id']] = [
                    'doc_file_id'   => (int) $row['doc_file_id'],
                    'file_id_uuid'  => $row['file_id_uuid'],
                    'file_name'     => $row['file_name'],
                    'mime_type'     => $row['mime_type'],
                    'source_label'  => 'Facture/BL',
                    'dated'         => $row['dated'],
                ];
            }

            echo json_encode(['ok' => true, 'docs' => array_values($docsById)]);
            exit;
        }

        // GET ?review=1 — review bucket only
        if (isset($_GET['review']) && (string) $_GET['review'] === '1') {
            $reviewThreads = load_review_threads($pdo);
            echo json_encode(['ok' => true, 'review_threads' => $reviewThreads]);
            exit;
        }

        // GET ?supplier_id=X — full supplier timeline
        $supplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;
        if ($supplierId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'supplier_id manquant ou invalide.']);
            exit;
        }

        $timeline      = build_supplier_timeline($pdo, $supplierId);
        $reviewThreads = load_review_threads($pdo);
        $threads       = build_supplier_threads($pdo, $supplierId);

        echo json_encode(['ok' => true, 'timeline' => $timeline, 'review_threads' => $reviewThreads, 'threads' => $threads]);
        exit;

    } elseif ($method === 'POST') {

        if (!csrf_verify($_POST['csrf'] ?? null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
            exit;
        }

        $action = trim($_POST['action'] ?? '');

        // ── POST action=add_note (manager or admin) ───────────────────────────
        if ($action === 'add_note') {
            $supplierId = isset($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : 0;
            if ($supplierId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'supplier_id invalide.']);
                exit;
            }

            $text = trim($_POST['text'] ?? '');
            if ($text === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Le texte de la note est requis.']);
                exit;
            }

            // note_date: optional YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
            $noteDateRaw = trim($_POST['note_date'] ?? '');
            $sentAt      = null;
            if ($noteDateRaw !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $noteDateRaw)) {
                    $sentAt = $noteDateRaw . ' 00:00:00';
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $noteDateRaw)) {
                    $sentAt = $noteDateRaw;
                } else {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'note_date invalide (YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS).']);
                    exit;
                }
            }
            if ($sentAt === null) {
                $sentAt = date('Y-m-d H:i:s');
            }

            // Resolve or create thread
            $threadIdRaw = trim($_POST['thread_id'] ?? '');
            $threadId    = null;

            if ($threadIdRaw !== '') {
                $threadId = (int) $threadIdRaw;
                // Verify thread belongs to supplier
                $stCheck = $pdo->prepare(
                    'SELECT id FROM comm_threads WHERE id = ? AND supplier_id_fk = ? LIMIT 1'
                );
                $stCheck->execute([$threadId, $supplierId]);
                if (!$stCheck->fetch(PDO::FETCH_ASSOC)) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'error' => 'Fil introuvable pour ce fournisseur.']);
                    exit;
                }
            } else {
                // Create new thread
                $stInsertThread = $pdo->prepare(
                    'INSERT INTO comm_threads
                        (supplier_id_fk, subject, last_message_at, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, 1, NOW(), NOW())'
                );
                $stInsertThread->execute([$supplierId, 'Note manuelle', $sentAt]);
                $threadId = (int) $pdo->lastInsertId();
            }

            // Generate unique message_id
            $uniqueId = 'manual-' . $supplierId . '-' . time() . '-' . bin2hex(random_bytes(4));

            // INSERT message
            $stInsertMsg = $pdo->prepare(
                "INSERT INTO comm_messages
                    (thread_id_fk, direction, from_address, to_address,
                     body_format, body, body_snippet, sent_at,
                     message_id, source, created_by_user_id, created_at, updated_at)
                 VALUES (?, 'out', ?, '', 'text', ?, ?, ?, 'manual', ?, NOW(), NOW())"
            );
            $fromAddr = $me['email'] ?? 'manual';
            $snippet  = mb_substr($text, 0, 200);
            $stInsertMsg->execute([
                $threadId,
                $fromAddr,
                $text,
                $snippet,
                $sentAt,
                $uniqueId,
                (int) $me['id'],
            ]);
            $newMsgId = (int) $pdo->lastInsertId();

            // Update thread last_message_at
            $stUpdateThread = $pdo->prepare(
                'UPDATE comm_threads SET last_message_at = ?, updated_at = NOW() WHERE id = ?'
            );
            $stUpdateThread->execute([$sentAt, $threadId]);

            // Audit log
            $newRow = bd_fetch_before($pdo, 'comm_messages', $newMsgId);
            log_revision($pdo, $me, 'comm_messages', $newMsgId, null, $newRow ?? [], 'normal', null);

            echo json_encode(['ok' => true, 'message_id' => $newMsgId]);
            exit;
        }

        // ── POST action=assign_thread (admin only) ────────────────────────────
        if ($action === 'assign_thread') {
            if (!is_admin($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin uniquement.']);
                exit;
            }

            $threadId   = isset($_POST['thread_id'])   ? (int) $_POST['thread_id']   : 0;
            $supplierId = isset($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : 0;

            if ($threadId <= 0 || $supplierId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'thread_id ou supplier_id invalide.']);
                exit;
            }

            $pdo->beginTransaction();

            // Update thread
            $stUpdate = $pdo->prepare(
                'UPDATE comm_threads SET supplier_id_fk = ?, updated_at = NOW() WHERE id = ?'
            );
            $stUpdate->execute([$supplierId, $threadId]);

            // Load unique in-direction from_addresses for this thread
            $stAddrs = $pdo->prepare(
                "SELECT DISTINCT from_address FROM comm_messages
                  WHERE thread_id_fk = ? AND direction = 'in' AND from_address IS NOT NULL AND from_address != ''"
            );
            $stAddrs->execute([$threadId]);
            $addrs = $stAddrs->fetchAll(PDO::FETCH_COLUMN);

            $pinsCreated = 0;
            foreach ($addrs as $raw) {
                $email = extract_email_address($raw);
                if ($email === null) {
                    continue;
                }
                $stPin = $pdo->prepare(
                    'INSERT INTO comm_address_pins
                        (email, supplier_id_fk, created_by_user_id, created_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        supplier_id_fk = VALUES(supplier_id_fk),
                        created_by_user_id = VALUES(created_by_user_id)'
                );
                $stPin->execute([$email, $supplierId, (int) $me['id']]);
                $pinsCreated++;
            }

            // Audit log
            log_revision(
                $pdo, $me,
                'comm_threads', $threadId,
                ['supplier_id_fk' => null],
                ['supplier_id_fk' => $supplierId],
                'normal', null
            );

            $pdo->commit();

            echo json_encode([
                'ok'           => true,
                'thread_id'    => $threadId,
                'supplier_id'  => $supplierId,
                'pins_created' => $pinsCreated,
            ]);
            exit;
        }

        // ── POST action=send_reply (admin or manager) ────────────────────────────
        if ($action === 'send_reply') {
            if (!is_admin($me) && !is_manager($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin ou manager uniquement.']);
                exit;
            }

            $threadId = isset($_POST['thread_id']) ? (int) $_POST['thread_id'] : 0;
            $body     = trim($_POST['body'] ?? '');

            if ($threadId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'thread_id invalide.']);
                exit;
            }
            if ($body === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Le corps du message est requis.']);
                exit;
            }

            // Email gate: sender must have a @lanebuleuse.ch address
            $senderEmail = $me['email'] ?? '';
            if ($senderEmail === '' || !str_ends_with($senderEmail, '@lanebuleuse.ch')) {
                http_response_code(422);
                echo json_encode([
                    'ok'    => false,
                    'error' => "Votre compte n'a pas d'adresse e-mail @lanebuleuse.ch configurée ; impossible d'envoyer.",
                ]);
                exit;
            }

            // Load thread
            $stThread = $pdo->prepare(
                'SELECT id, supplier_id_fk, subject FROM comm_threads WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $stThread->execute([$threadId]);
            $thread = $stThread->fetch(PDO::FETCH_ASSOC);
            if (!$thread) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Fil introuvable.']);
                exit;
            }

            // Most recent inbound message — determines recipient + In-Reply-To
            $stInbound = $pdo->prepare(
                "SELECT from_address, message_id, subject
                   FROM comm_messages
                  WHERE thread_id_fk = ? AND direction = 'in'
                  ORDER BY sent_at DESC LIMIT 1"
            );
            $stInbound->execute([$threadId]);
            $inbound = $stInbound->fetch(PDO::FETCH_ASSOC);
            if (!$inbound) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "Aucun destinataire : ce fil n'a pas de message entrant."]);
                exit;
            }

            $recipientEmail = extract_email_address($inbound['from_address'] ?? '');
            if ($recipientEmail === null) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "Impossible d'extraire l'adresse e-mail du destinataire : " . ($inbound['from_address'] ?? '(vide)')]);
                exit;
            }

            // Subject: prepend Re: if not already present
            $threadSubject = $thread['subject'] ?? '';
            if (!preg_match('/^re\s*:/i', $threadSubject)) {
                $replySubject = 'Re: ' . $threadSubject;
            } else {
                $replySubject = $threadSubject;
            }

            $parentMessageId = $inbound['message_id'] ?? null;

            // Parse and validate doc_file_ids
            $docFileIdsRaw = $_POST['doc_file_ids'] ?? '[]';
            $docFileIds    = json_decode($docFileIdsRaw, true);
            if (!is_array($docFileIds)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'doc_file_ids: tableau JSON invalide.']);
                exit;
            }
            $attachments = [];
            foreach ($docFileIds as $dfId) {
                $dfId = (int) $dfId;
                $stDoc = $pdo->prepare(
                    'SELECT id, local_path, file_name, mime_type FROM doc_files WHERE id = ? AND is_active = 1 LIMIT 1'
                );
                $stDoc->execute([$dfId]);
                $docRow = $stDoc->fetch(PDO::FETCH_ASSOC);
                if (!$docRow) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => "Pièce jointe introuvable (doc_file id={$dfId})."]);
                    exit;
                }
                $attachments[] = [
                    'local_path' => $docRow['local_path'],
                    'filename'   => $docRow['file_name'],
                    'mime_type'  => $docRow['mime_type'],
                    '_id'        => (int) $docRow['id'],
                ];
            }

            // Build Python payload
            $pyPayload = [
                'sender_email' => $senderEmail,
                'to'           => $recipientEmail,
                'subject'      => $replySubject,
                'body'         => $body,
                'body_format'  => 'text',
                'in_reply_to'  => $parentMessageId,
                'references'   => $parentMessageId,
                'attachments'  => array_map(
                    static fn ($a) => [
                        'local_path' => $a['local_path'],
                        'filename'   => $a['filename'],
                        'mime_type'  => $a['mime_type'],
                    ],
                    $attachments
                ),
            ];
            $payloadJson = json_encode($pyPayload);

            // Write payload to tmpfile and call Python sender
            $tmpPayload = tempnam(sys_get_temp_dir(), 'comm_send_');
            file_put_contents($tmpPayload, $payloadJson);
            $pythonBin  = '/usr/bin/python3';
            $senderPath = '/var/www/maltytask/scripts/python/send_email_comm.py';
            $cmd        = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($senderPath)
                        . ' --payload ' . escapeshellarg($tmpPayload) . ' 2>&1';
            $output = shell_exec($cmd);
            @unlink($tmpPayload);

            $sendResult = json_decode($output ?? '', true);
            if (!$sendResult || !isset($sendResult['ok'])) {
                http_response_code(500);
                echo json_encode([
                    'ok'    => false,
                    'error' => "Réponse invalide du service d'envoi.",
                    'raw'   => $output,
                ]);
                exit;
            }

            if ($sendResult['ok'] === false) {
                http_response_code(502);
                echo json_encode([
                    'ok'          => false,
                    'error'       => $sendResult['error'] ?? 'Envoi échoué.',
                    'send_status' => 'failed',
                ]);
                exit;
            }

            // Sender succeeded — persist to DB in a transaction
            $rfc2822MsgId  = $sendResult['message_id'];
            $gmailMsgId    = $sendResult['gmail_message_id'] ?? null;
            $bodySnippet   = mb_substr($body, 0, 200);
            $userId        = (int) $me['id'];

            $pdo->beginTransaction();

            // a) INSERT comm_messages
            $stInsert = $pdo->prepare(
                "INSERT INTO comm_messages
                    (thread_id_fk, direction, from_address, to_address, subject,
                     body_format, body, body_snippet, sent_at, message_id, gmail_message_id,
                     source, send_status, sent_by_user_id, created_by_user_id,
                     created_at, updated_at)
                 VALUES (?, 'out', ?, ?, ?, 'text', ?, ?, NOW(), ?, ?, 'sent', 'sent', ?, ?, NOW(), NOW())"
            );
            $stInsert->execute([
                $threadId,
                $senderEmail,
                $recipientEmail,
                $replySubject,
                $body,
                $bodySnippet,
                $rfc2822MsgId,
                $gmailMsgId,
                $userId,
                $userId,
            ]);
            $newCommMsgId = (int) $pdo->lastInsertId();

            // b) INSERT comm_message_docs for each attachment
            foreach ($attachments as $att) {
                $stAttach = $pdo->prepare(
                    "INSERT INTO comm_message_docs
                        (message_id_fk, doc_file_id_fk, attachment_filename, mime_type, direction)
                     VALUES (?, ?, ?, ?, 'out')"
                );
                $stAttach->execute([
                    $newCommMsgId,
                    $att['_id'],
                    $att['filename'],
                    $att['mime_type'],
                ]);
            }

            // c) UPDATE comm_threads last_message_at
            $stUpdateThread = $pdo->prepare(
                'UPDATE comm_threads SET last_message_at = NOW(), updated_at = NOW() WHERE id = ?'
            );
            $stUpdateThread->execute([$threadId]);

            // d) Audit log
            $newRow = bd_fetch_before($pdo, 'comm_messages', $newCommMsgId);
            log_revision($pdo, $me, 'comm_messages', $newCommMsgId, null, $newRow ?? [], 'normal', null);

            $pdo->commit();

            echo json_encode([
                'ok'             => true,
                'comm_message_id' => $newCommMsgId,
                'message_id'     => $rfc2822MsgId,
                'send_status'    => 'sent',
            ]);
            exit;
        }

        // ── POST action=attach_upload (admin or manager) ─────────────────────────
        if ($action === 'attach_upload') {
            if (!is_admin($me) && !is_manager($me)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Admin ou manager uniquement.']);
                exit;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErr = $_FILES['file']['error'] ?? -1;
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => "Erreur lors de la réception du fichier (code {$uploadErr})."]);
                exit;
            }

            $origName     = $_FILES['file']['name'];
            $fileSize     = (int) $_FILES['file']['size'];
            $mimeFromPost = (string) ($_FILES['file']['type'] ?? '');

            if ($fileSize <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Le fichier est vide.']);
                exit;
            }

            $maxBytes = 20 * 1024 * 1024;
            if ($fileSize > $maxBytes) {
                http_response_code(413);
                echo json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 20 Mo).']);
                exit;
            }

            $allowedExts  = ['pdf','jpg','jpeg','png','gif','webp','svg',
                             'doc','docx','xls','xlsx','ppt','pptx',
                             'odt','ods','odp','csv','txt','zip','eml','msg'];
            $allowedMimes = [
                'application/pdf','image/jpeg','image/png','image/gif',
                'image/webp','image/svg+xml',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.text',
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/vnd.oasis.opendocument.presentation',
                'text/csv','text/plain',
                'application/zip','application/x-zip-compressed',
                'message/rfc822','application/vnd.ms-outlook',
            ];

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "Extension de fichier non autorisée (${ext})."]);
                exit;
            }
            if (!in_array($mimeFromPost, $allowedMimes, true)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "Type MIME non autorisé ({$mimeFromPost})."]);
                exit;
            }

            $fileBytes = file_get_contents($_FILES['file']['tmp_name']);
            if ($fileBytes === false) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Impossible de lire le fichier temporaire.']);
                exit;
            }

            $fileHash   = hash('sha256', $fileBytes);
            $monthDir   = '/var/www/maltytask/data/email-attachments/' . date('Y-m');
            if (!is_dir($monthDir)) {
                mkdir($monthDir, 0755, true);
            }

            $safeName   = preg_replace('/[^A-Za-z0-9.\-_]/', '_', basename($origName));
            $fileId     = bin2hex(random_bytes(16));
            $localPath  = $monthDir . '/' . $fileId . '_' . $safeName;

            // Dedup: reuse existing doc_files row if same content already stored
            $stDedup = $pdo->prepare('SELECT id FROM doc_files WHERE file_hash = ? LIMIT 1');
            $stDedup->execute([$fileHash]);
            $existing = $stDedup->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode([
                    'ok'           => true,
                    'doc_file_id'  => (int) $existing['id'],
                    'file_name'    => $origName,
                    'file_id_uuid' => $fileId,
                ]);
                exit;
            }

            // Write real bytes to disk
            if (file_put_contents($localPath, $fileBytes) === false) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Impossible d\'écrire le fichier sur le disque.']);
                exit;
            }
            chmod($localPath, 0644);

            $rowHash = hash('sha256', "{$fileId}:{$origName}:{$fileHash}:email-comm-attach");

            $stIns = $pdo->prepare(
                "INSERT INTO doc_files
                    (file_id, file_name, local_path, file_hash, mime_type,
                     source_folder, file_size_bytes, downloaded_at, row_hash, is_active)
                 VALUES (?, ?, ?, ?, ?, 'email-comm-attach', ?, NOW(), ?, 1)"
            );
            $stIns->execute([
                $fileId,
                $origName,
                $localPath,
                $fileHash,
                $mimeFromPost,
                $fileSize,
                $rowHash,
            ]);
            $newDocFileId = (int) $pdo->lastInsertId();

            echo json_encode([
                'ok'           => true,
                'doc_file_id'  => $newDocFileId,
                'file_name'    => $origName,
                'file_id_uuid' => $fileId,
            ]);
            exit;
        }

        // Unknown action
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);

    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
}
