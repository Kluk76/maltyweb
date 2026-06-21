<?php
declare(strict_types=1);
/**
 * /api/comm-unknown-domain.php
 * Action API for the comm_unknown_domain_seen triage screen.
 *
 * GET  ?action=list                       — undismissed rows, hit_count DESC
 * GET  ?action=search_supplier&q=TERM     — active supplier name search
 * GET  ?action=search_customer&q=TERM     — active customer name search
 * POST action=promote_supplier            — link domain+sample to a supplier
 * POST action=promote_customer            — link domain+sample to a customer
 * POST action=dismiss                     — mark a domain dismissed
 *
 * Auth: require_login + can_use_comm_tracker (manager or admin).
 * CSRF: verified on every POST before any data access.
 * All writes: single PDO transaction + log_revision for every touched row.
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

// ── GET routes ────────────────────────────────────────────────────────────────

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        $stmt = $pdo->query(
            'SELECT id, domain, hit_count, sample_address,
                    DATE_FORMAT(first_seen_at, \'%Y-%m-%d\') AS first_seen,
                    DATE_FORMAT(last_seen_at,  \'%Y-%m-%d\') AS last_seen
               FROM comm_unknown_domain_seen
              WHERE is_dismissed = 0
              ORDER BY hit_count DESC'
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id'             => (int) $row['id'],
                'domain'         => (string) $row['domain'],
                'hit_count'      => (int)    $row['hit_count'],
                'sample_address' => $row['sample_address'] !== null ? (string) $row['sample_address'] : null,
                'first_seen'     => (string) $row['first_seen'],
                'last_seen'      => (string) $row['last_seen'],
            ];
        }
        echo json_encode(['ok' => true, 'rows' => $rows, 'csrf' => csrf_token()]);
        exit;
    }

    if ($action === 'search_supplier') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            echo json_encode(['ok' => true, 'suppliers' => []]);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT id, name
               FROM ref_suppliers
              WHERE is_active = 1
                AND name LIKE ?
              ORDER BY name
              LIMIT 20'
        );
        $stmt->execute(['%' . $q . '%']);
        $suppliers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $suppliers[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        echo json_encode(['ok' => true, 'suppliers' => $suppliers]);
        exit;
    }

    if ($action === 'search_customer') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            echo json_encode(['ok' => true, 'customers' => []]);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT id, name
               FROM ref_customers
              WHERE is_active = 1
                AND name LIKE ?
              ORDER BY name
              LIMIT 20'
        );
        $stmt->execute(['%' . $q . '%']);
        $customers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $customers[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        echo json_encode(['ok' => true, 'customers' => $customers]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
    exit;
}

// ── POST routes ───────────────────────────────────────────────────────────────

if ($method === 'POST') {
    // CSRF check first — before any data read or write
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'reason' => 'expired', 'csrf' => csrf_token()]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── promote_supplier ──────────────────────────────────────────────────────
    if ($action === 'promote_supplier') {
        $domainId   = filter_input(INPUT_POST, 'domain_id',   FILTER_VALIDATE_INT);
        $supplierId = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);

        if (!$domainId || !$supplierId) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Paramètres invalides.']);
            exit;
        }

        // Verify domain row exists and is undismissed
        $domStmt = $pdo->prepare(
            'SELECT id, domain, sample_address FROM comm_unknown_domain_seen
              WHERE id = ? AND is_dismissed = 0 LIMIT 1'
        );
        $domStmt->execute([$domainId]);
        $domRow = $domStmt->fetch(PDO::FETCH_ASSOC);
        if (!$domRow) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Domaine introuvable ou déjà traité.']);
            exit;
        }

        // Verify supplier exists and is active
        $supStmt = $pdo->prepare(
            'SELECT id FROM ref_suppliers WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $supStmt->execute([$supplierId]);
        if (!$supStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable ou inactif.']);
            exit;
        }

        $domain        = strtolower((string) $domRow['domain']);
        $sampleAddress = $domRow['sample_address'] !== null ? strtolower((string) $domRow['sample_address']) : null;

        // Before-snapshot for the domain_seen row
        $beforeDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', (int) $domRow['id']);

        // Before-snapshot for ref_entity_email_domains (if exists).
        // NOTE: TOCTOU — read outside the transaction; under concurrent requests the audit
        // action label ('insert' vs 'update') may be wrong. Acceptable on a low-traffic admin screen.
        $eedStmt = $pdo->prepare(
            'SELECT id FROM ref_entity_email_domains WHERE match_value = ? LIMIT 1'
        );
        $eedStmt->execute([$domain]);
        $existingEed    = $eedStmt->fetch(PDO::FETCH_ASSOC);
        $beforeEed      = $existingEed ? bd_fetch_before($pdo, 'ref_entity_email_domains', (int) $existingEed['id']) : null;

        // Before-snapshot for comm_address_pins (if sample_address exists and valid email)
        $beforePin = null;
        $pinId     = null;
        if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
            $pinLookup = $pdo->prepare('SELECT id FROM comm_address_pins WHERE email = LOWER(?) LIMIT 1');
            $pinLookup->execute([$sampleAddress]);
            $pinRow    = $pinLookup->fetch(PDO::FETCH_ASSOC);
            if ($pinRow) {
                $pinId     = (int) $pinRow['id'];
                $beforePin = bd_fetch_before($pdo, 'comm_address_pins', $pinId);
            }
        }

        $pdo->beginTransaction();
        try {
            // 1. Upsert registry domain row
            $pdo->prepare(
                'INSERT INTO ref_entity_email_domains
                   (supplier_id_fk, customer_id_fk, match_type, match_value, source,
                    is_shared, is_active, backfilled_at, created_at, updated_at)
                 VALUES (?, NULL, \'domain\', LOWER(?), \'manual\', 0, 1, NULL, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   supplier_id_fk = VALUES(supplier_id_fk),
                   customer_id_fk = NULL,
                   source         = \'manual\',
                   is_active      = 1,
                   backfilled_at  = NULL,
                   updated_at     = NOW()'
            )->execute([$supplierId, $domain]);

            // Resolve the REED id (SELECT-after-upsert is reliable inside the same connection)
            $reedStmt = $pdo->prepare(
                'SELECT id FROM ref_entity_email_domains WHERE match_value = ? LIMIT 1'
            );
            $reedStmt->execute([$domain]);
            $reedId = (int) $reedStmt->fetchColumn();

            // 2. Insert address pin for sample_address (if set and valid email)
            if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
                $pdo->prepare(
                    'INSERT INTO comm_address_pins
                       (email, supplier_id_fk, customer_id_fk, created_by_user_id, created_at)
                     VALUES (LOWER(?), ?, NULL, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       supplier_id_fk = VALUES(supplier_id_fk),
                       customer_id_fk = NULL'
                )->execute([$sampleAddress, $supplierId, (int) $me['id']]);
            }

            // 3. Dismiss the domain_seen row
            $pdo->prepare(
                'UPDATE comm_unknown_domain_seen SET is_dismissed = 1 WHERE id = ?'
            )->execute([$domainId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Erreur base de données.']);
            exit;
        }

        // After-snapshots + audit logs (outside transaction — read-only)
        $afterEed    = bd_fetch_before($pdo, 'ref_entity_email_domains', $reedId);
        $afterDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', $domainId);

        if ($afterEed !== null) {
            log_revision($pdo, $me, 'ref_entity_email_domains', $reedId, $beforeEed, $afterEed,
                'normal', 'promote_supplier from domain triage');
        }
        if ($afterDomain !== null) {
            log_revision($pdo, $me, 'comm_unknown_domain_seen', $domainId, $beforeDomain, $afterDomain,
                'normal', null);
        }

        // Audit log for comm_address_pins (if a pin was written)
        if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
            $pinLookupAfter = $pdo->prepare('SELECT id FROM comm_address_pins WHERE email = LOWER(?) LIMIT 1');
            $pinLookupAfter->execute([$sampleAddress]);
            $pinRowAfter = $pinLookupAfter->fetch(PDO::FETCH_ASSOC);
            if ($pinRowAfter) {
                $afterPin = bd_fetch_before($pdo, 'comm_address_pins', (int) $pinRowAfter['id']);
                if ($afterPin !== null) {
                    log_revision($pdo, $me, 'comm_address_pins', (int) $pinRowAfter['id'],
                        $beforePin, $afterPin, 'normal', 'promote_supplier address pin from domain triage');
                }
            }
        }

        echo json_encode(['ok' => true, 'domain_id' => $domainId, 'reed_id' => $reedId, 'csrf' => csrf_token()]);
        exit;
    }

    // ── promote_customer ──────────────────────────────────────────────────────
    if ($action === 'promote_customer') {
        $domainId   = filter_input(INPUT_POST, 'domain_id',   FILTER_VALIDATE_INT);
        $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);

        if (!$domainId || !$customerId) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Paramètres invalides.']);
            exit;
        }

        // Verify domain row exists and is undismissed
        $domStmt = $pdo->prepare(
            'SELECT id, domain, sample_address FROM comm_unknown_domain_seen
              WHERE id = ? AND is_dismissed = 0 LIMIT 1'
        );
        $domStmt->execute([$domainId]);
        $domRow = $domStmt->fetch(PDO::FETCH_ASSOC);
        if (!$domRow) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Domaine introuvable ou déjà traité.']);
            exit;
        }

        // Verify customer exists and is active
        $custStmt = $pdo->prepare(
            'SELECT id FROM ref_customers WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $custStmt->execute([$customerId]);
        if (!$custStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Client introuvable ou inactif.']);
            exit;
        }

        $domain        = strtolower((string) $domRow['domain']);
        $sampleAddress = $domRow['sample_address'] !== null ? strtolower((string) $domRow['sample_address']) : null;

        // Before-snapshots
        $beforeDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', (int) $domRow['id']);

        // NOTE: TOCTOU — read outside the transaction; under concurrent requests the audit
        // action label ('insert' vs 'update') may be wrong. Acceptable on a low-traffic admin screen.
        $eedStmt = $pdo->prepare(
            'SELECT id FROM ref_entity_email_domains WHERE match_value = ? LIMIT 1'
        );
        $eedStmt->execute([$domain]);
        $existingEed = $eedStmt->fetch(PDO::FETCH_ASSOC);
        $beforeEed   = $existingEed ? bd_fetch_before($pdo, 'ref_entity_email_domains', (int) $existingEed['id']) : null;

        // Before-snapshot for comm_address_pins (if sample_address exists and valid email)
        $beforePin = null;
        $pinId     = null;
        if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
            $pinLookup = $pdo->prepare('SELECT id FROM comm_address_pins WHERE email = LOWER(?) LIMIT 1');
            $pinLookup->execute([$sampleAddress]);
            $pinRow    = $pinLookup->fetch(PDO::FETCH_ASSOC);
            if ($pinRow) {
                $pinId     = (int) $pinRow['id'];
                $beforePin = bd_fetch_before($pdo, 'comm_address_pins', $pinId);
            }
        }

        $pdo->beginTransaction();
        try {
            // 1. Upsert registry domain row
            $pdo->prepare(
                'INSERT INTO ref_entity_email_domains
                   (supplier_id_fk, customer_id_fk, match_type, match_value, source,
                    is_shared, is_active, backfilled_at, created_at, updated_at)
                 VALUES (NULL, ?, \'domain\', LOWER(?), \'manual\', 0, 1, NULL, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   supplier_id_fk = NULL,
                   customer_id_fk = VALUES(customer_id_fk),
                   source         = \'manual\',
                   is_active      = 1,
                   backfilled_at  = NULL,
                   updated_at     = NOW()'
            )->execute([$customerId, $domain]);

            // Resolve the REED id (SELECT-after-upsert is reliable inside the same connection)
            $reedStmt = $pdo->prepare(
                'SELECT id FROM ref_entity_email_domains WHERE match_value = ? LIMIT 1'
            );
            $reedStmt->execute([$domain]);
            $reedId = (int) $reedStmt->fetchColumn();

            // 2. Insert address pin for sample_address (if set and valid email)
            if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
                $pdo->prepare(
                    'INSERT INTO comm_address_pins
                       (email, supplier_id_fk, customer_id_fk, created_by_user_id, created_at)
                     VALUES (LOWER(?), NULL, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       supplier_id_fk = NULL,
                       customer_id_fk = VALUES(customer_id_fk)'
                )->execute([$sampleAddress, $customerId, (int) $me['id']]);
            }

            // 3. Dismiss the domain_seen row
            $pdo->prepare(
                'UPDATE comm_unknown_domain_seen SET is_dismissed = 1 WHERE id = ?'
            )->execute([$domainId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Erreur base de données.']);
            exit;
        }

        // After-snapshots + audit logs
        $afterEed    = bd_fetch_before($pdo, 'ref_entity_email_domains', $reedId);
        $afterDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', $domainId);

        if ($afterEed !== null) {
            log_revision($pdo, $me, 'ref_entity_email_domains', $reedId, $beforeEed, $afterEed,
                'normal', 'promote_customer from domain triage');
        }
        if ($afterDomain !== null) {
            log_revision($pdo, $me, 'comm_unknown_domain_seen', $domainId, $beforeDomain, $afterDomain,
                'normal', null);
        }

        // Audit log for comm_address_pins (if a pin was written)
        if ($sampleAddress !== null && filter_var($sampleAddress, FILTER_VALIDATE_EMAIL)) {
            $pinLookupAfter = $pdo->prepare('SELECT id FROM comm_address_pins WHERE email = LOWER(?) LIMIT 1');
            $pinLookupAfter->execute([$sampleAddress]);
            $pinRowAfter = $pinLookupAfter->fetch(PDO::FETCH_ASSOC);
            if ($pinRowAfter) {
                $afterPin = bd_fetch_before($pdo, 'comm_address_pins', (int) $pinRowAfter['id']);
                if ($afterPin !== null) {
                    log_revision($pdo, $me, 'comm_address_pins', (int) $pinRowAfter['id'],
                        $beforePin, $afterPin, 'normal', 'promote_customer address pin from domain triage');
                }
            }
        }

        echo json_encode(['ok' => true, 'domain_id' => $domainId, 'reed_id' => $reedId, 'csrf' => csrf_token()]);
        exit;
    }

    // ── dismiss ───────────────────────────────────────────────────────────────
    if ($action === 'dismiss') {
        $domainId = filter_input(INPUT_POST, 'domain_id', FILTER_VALIDATE_INT);

        if (!$domainId) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Paramètre domain_id invalide.']);
            exit;
        }

        $domStmt = $pdo->prepare(
            'SELECT id FROM comm_unknown_domain_seen WHERE id = ? AND is_dismissed = 0 LIMIT 1'
        );
        $domStmt->execute([$domainId]);
        if (!$domStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Domaine introuvable ou déjà traité.']);
            exit;
        }

        $beforeDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', $domainId);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE comm_unknown_domain_seen SET is_dismissed = 1 WHERE id = ?'
            )->execute([$domainId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Erreur base de données.']);
            exit;
        }

        $afterDomain = bd_fetch_before($pdo, 'comm_unknown_domain_seen', $domainId);
        if ($afterDomain !== null) {
            log_revision($pdo, $me, 'comm_unknown_domain_seen', $domainId, $beforeDomain, $afterDomain,
                'normal', 'dismiss from domain triage');
        }

        echo json_encode(['ok' => true, 'csrf' => csrf_token()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
