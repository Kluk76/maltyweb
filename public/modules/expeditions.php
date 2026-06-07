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

// ── Commandes view: period + filter params ────────────────────────────────────
// Parsed here (before any query) so available in both GET load and template.

// ---- Helpers ----------------------------------------------------------------
/**
 * Parse an ISO week string "YYYY-Wnn" and return [date_start, date_end] as
 * 'YYYY-MM-DD' strings (Monday–Sunday of that ISO week).
 * Returns null on parse failure.
 */
function exp_parse_isoweek(string $kw): ?array
{
    if (!preg_match('/^(\d{4})-W(\d{1,2})$/', $kw, $m)) return null;
    $year = (int) $m[1];
    $week = (int) $m[2];
    if ($week < 1 || $week > 53) return null;
    $dto = new DateTimeImmutable();
    $dto = $dto->setISODate($year, $week, 1); // Monday
    $start = $dto->format('Y-m-d');
    $end   = $dto->modify('+6 days')->format('Y-m-d');
    return [$start, $end];
}

/**
 * Return the ISO week string "YYYY-Wnn" for a given 'YYYY-MM-DD' date.
 */
function exp_date_to_isoweek(string $date): string
{
    $dto = new DateTimeImmutable($date);
    return $dto->format('Y-\WW');
}

/**
 * Format an ISO week for display: "KW23 (01–07 juin)".
 */
function exp_isoweek_label(string $kw): string
{
    $parsed = exp_parse_isoweek($kw);
    if ($parsed === null) return htmlspecialchars($kw);
    [$start, $end] = $parsed;
    $weekNum = (int) (new DateTimeImmutable($start))->format('W');
    $months  = ['', 'jan', 'fév', 'mar', 'avr', 'mai', 'juin',
                'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
    $sm = (int) (new DateTimeImmutable($start))->format('m');
    $em = (int) (new DateTimeImmutable($end))->format('m');
    $sd = (int) (new DateTimeImmutable($start))->format('d');
    $ed = (int) (new DateTimeImmutable($end))->format('d');
    $rangeStr = sprintf('%02d', $sd);
    if ($sm !== $em) {
        $rangeStr .= ' ' . $months[$sm] . '–' . sprintf('%02d', $ed) . ' ' . $months[$em];
    } else {
        $rangeStr .= '–' . sprintf('%02d', $ed) . ' ' . $months[$sm];
    }
    return 'KW' . $weekNum . ' (' . $rangeStr . ')';
}

/**
 * French long day-name from a 'YYYY-MM-DD' date string.
 */
function exp_day_name(string $date): string
{
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $dow  = (int) (new DateTimeImmutable($date))->format('w');
    return strtoupper($days[$dow]);
}

// ---- Determine mode and period bounds ---------------------------------------
$cmdMode = 'week'; // 'week' | 'range'
$cmdKw   = ''; // "YYYY-Wnn" — only for mode=week
$cmdDu   = ''; // 'YYYY-MM-DD'
$cmdAu   = ''; // 'YYYY-MM-DD'
$cmdRangeNotice = ''; // non-empty when range was clamped
$kwPrev  = '';
$kwNext  = '';
// Filter params (only populated for commandes view)
$filterClient  = '';
$filterSku     = '';
$filterStatus  = '';
$filterChannel = '';

if ($view === 'commandes') {
    $rawMode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'week';
    $cmdMode = in_array($rawMode, ['week', 'range'], true) ? $rawMode : 'week';

    if ($cmdMode === 'range') {
        $rawDu = isset($_GET['du']) ? trim((string) $_GET['du']) : '';
        $rawAu = isset($_GET['au']) ? trim((string) $_GET['au']) : '';
        // Validate and coerce dates
        $parseDu = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDu) && $rawDu >= '2020-01-01') ? $rawDu : date('Y-m-d');
        $parseAu = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawAu) && $rawAu >= '2020-01-01') ? $rawAu : $parseDu;
        if ($parseAu < $parseDu) $parseAu = $parseDu;
        // Clamp to 92 days max
        $diffDays = (int) round((strtotime($parseAu) - strtotime($parseDu)) / 86400);
        if ($diffDays > 92) {
            $parseAu = date('Y-m-d', strtotime($parseDu . ' +92 days'));
            $cmdRangeNotice = 'Plage limitée à 92 jours.';
        }
        $cmdDu = $parseDu;
        $cmdAu = $parseAu;
    } else {
        // Week mode
        $rawKw = isset($_GET['kw']) ? trim((string) $_GET['kw']) : '';
        if ($rawKw === '' || exp_parse_isoweek($rawKw) === null) {
            $rawKw = exp_date_to_isoweek(date('Y-m-d'));
        }
        $cmdKw = $rawKw;
        $parsed = exp_parse_isoweek($cmdKw);
        $cmdDu = $parsed[0];
        $cmdAu = $parsed[1];
    }

    // ---- Filter params (GET, sanitised) -------------------------------------
    $filterClient  = isset($_GET['client'])  ? trim((string) $_GET['client'])  : '';
    $filterSku     = isset($_GET['sku'])     ? strtoupper(trim((string) $_GET['sku'])) : '';
    $filterStatus  = isset($_GET['statut'])  ? (string) $_GET['statut']  : '';
    $filterChannel = isset($_GET['canal'])   ? (string) $_GET['canal']   : '';

    // Validate filter enums against whitelist
    $allowedStatutFilters  = ['ouvertes', 'entered', 'confirmed', 'picked', 'bl_printed', 'shipped', 'cancelled'];
    $allowedChannelFilters = ['on_trade', 'off_trade', 'interne'];
    if (!in_array($filterStatus, $allowedStatutFilters, true))  $filterStatus  = '';
    if (!in_array($filterChannel, $allowedChannelFilters, true)) $filterChannel = '';

    // ---- ISO week nav: prev/next --------------------------------------------
    if ($cmdMode === 'week') {
        $dto     = new DateTimeImmutable($cmdDu);
        $kwPrev  = exp_date_to_isoweek($dto->modify('-7 days')->format('Y-m-d'));
        $kwNext  = exp_date_to_isoweek($dto->modify('+7 days')->format('Y-m-d'));
    }
}

// ── GET — load data ───────────────────────────────────────────────────────────
$pdo     = maltytask_pdo();
$loadErr = null;

// Data common to multiple views
$customers    = [];
$transporters = [];
$skus         = [];

// Commandes view — orders + lines + eshop aggregates
$cmdOrders       = []; // header rows keyed by id
$cmdLines        = []; // keyed by order_id => [line, …]
$cmdByDay        = []; // keyed by date => [order_id, …]
$cmdEshopByDay   = []; // keyed by date => [channel => {orders, hl}]
$cmdSummary      = [
    'total_hl' => 0.0, 'orders' => 0, 'open' => 0,
    'on_trade' => 0, 'off_trade' => 0, 'internal' => 0,
    'distinct_clients' => 0,
]; // totals for the period band
$cmdFilteredCusts= []; // distinct customer names in result (for datalist)
$cmdFilteredSkus = []; // distinct SKU codes in result (for datalist)

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
        // ── Query 1: order headers + customer + transporter ───────────────────
        // Build WHERE dynamically (all via bound params — no interpolation)
        $where  = ['o.requested_date BETWEEN ? AND ?'];
        $params = [$cmdDu, $cmdAu];

        // Filter: client name (substring match against customer name)
        if ($filterClient !== '') {
            $where[]  = 'c.name LIKE ?';
            $params[] = '%' . $filterClient . '%';
        }

        // Filter: channel (on_trade / off_trade uses ref_customers.trade_channel;
        //   'interne' uses order_type='internal')
        if ($filterChannel === 'interne') {
            $where[] = "o.order_type = 'internal'";
        } elseif ($filterChannel !== '') {
            $where[] = "o.order_type = 'customer'";
            $where[] = 'c.trade_channel = ?';
            $params[] = $filterChannel;
        }

        // Filter: statut (pseudo 'ouvertes' = not shipped and not cancelled)
        if ($filterStatus === 'ouvertes') {
            $where[] = "o.status NOT IN ('shipped', 'cancelled')";
        } elseif ($filterStatus !== '') {
            $where[] = 'o.status = ?';
            $params[] = $filterStatus;
        }

        $whereSQL = implode(' AND ', $where);
        $ordStmt  = $pdo->prepare(
            "SELECT o.id, o.order_type, o.internal_channel, o.requested_date,
                    o.status, o.comment, o.created_at,
                    c.name AS customer_name, c.trade_channel,
                    t.name AS transporter_name
               FROM ord_orders o
               LEFT JOIN ref_customers  c ON c.id = o.customer_id_fk
               LEFT JOIN ref_transporters t ON t.id = o.transporter_id_fk
              WHERE {$whereSQL}
              ORDER BY o.requested_date ASC, o.id ASC"
        );
        $ordStmt->execute($params);
        $allOrders = $ordStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group headers into a map and build day index
        $orderIds = [];
        foreach ($allOrders as $ord) {
            $oid = (int) $ord['id'];
            $cmdOrders[$oid] = $ord;
            $orderIds[]      = $oid;
            $d = (string) $ord['requested_date'];
            if (!isset($cmdByDay[$d])) $cmdByDay[$d] = [];
            $cmdByDay[$d][] = $oid;
            // Collect distinct customer names for datalist
            if ($ord['customer_name'] !== null) {
                $cmdFilteredCusts[$ord['customer_name']] = true;
            }
        }
        ksort($cmdByDay);

        // ── Query 2: lines for all matched orders (no N+1) ───────────────────
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $lineStmt = $pdo->prepare(
                "SELECT l.order_id_fk, l.sku_id_fk, l.qty,
                        s.sku_code, s.hl_per_unit
                   FROM ord_order_lines l
                   JOIN ref_skus s ON s.id = l.sku_id_fk
                  WHERE l.order_id_fk IN ({$placeholders})
                  ORDER BY l.order_id_fk ASC, l.id ASC"
            );
            $lineStmt->execute($orderIds);
            foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $oid = (int) $ln['order_id_fk'];
                if (!isset($cmdLines[$oid])) $cmdLines[$oid] = [];
                $cmdLines[$oid][] = $ln;
                // Apply SKU filter post-load (client-side would also work, but
                // filtering here keeps renderig simple — re-run if sku filter active)
                $cmdFilteredSkus[$ln['sku_code']] = true;
            }

            // If sku filter is active, remove orders that have no matching SKU line
            if ($filterSku !== '') {
                $passIds = [];
                foreach ($cmdLines as $oid => $lines) {
                    foreach ($lines as $ln) {
                        if (stripos($ln['sku_code'], $filterSku) !== false) {
                            $passIds[$oid] = true;
                            break;
                        }
                    }
                }
                foreach (array_keys($cmdOrders) as $oid) {
                    if (!isset($passIds[$oid])) {
                        unset($cmdOrders[$oid]);
                        // Remove from day index
                        foreach ($cmdByDay as $d => &$dayIds) {
                            $dayIds = array_values(array_filter($dayIds, fn($x) => $x !== $oid));
                        }
                        unset($dayIds);
                    }
                }
                // Prune empty days
                $cmdByDay = array_filter($cmdByDay, fn($ids) => !empty($ids));
            }
        }

        // ── Query 3: eshop/taproom auto-rows — aggregate by date + channel ───
        $eshopStmt = $pdo->prepare(
            "SELECT DATE(iso.created_at) AS sale_date,
                    iso.channel,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(isol.hl_resolved), 0) AS total_hl
               FROM inv_sales_orders iso
               LEFT JOIN inv_sales_order_lines isol ON isol.order_id_fk = iso.id
              WHERE DATE(iso.created_at) BETWEEN ? AND ?
              GROUP BY DATE(iso.created_at), iso.channel"
        );
        $eshopStmt->execute([$cmdDu, $cmdAu]);
        foreach ($eshopStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $d = (string) $er['sale_date'];
            if (!isset($cmdEshopByDay[$d])) $cmdEshopByDay[$d] = [];
            $cmdEshopByDay[$d][$er['channel']] = [
                'orders' => (int) $er['order_count'],
                'hl'     => (float) $er['total_hl'],
            ];
        }

        // ── Period summary ────────────────────────────────────────────────────
        $sumTotalHl    = 0.0;
        $sumOrders     = 0;
        $sumOpen       = 0;
        $sumOnTrade    = 0;
        $sumOffTrade   = 0;
        $sumInternal   = 0;
        $distinctClients = [];
        foreach ($cmdOrders as $ord) {
            $isCancelled = ($ord['status'] === 'cancelled');
            $isShipped   = ($ord['status'] === 'shipped');
            $oid = (int) $ord['id'];
            $sumOrders++;
            if (!$isCancelled && !$isShipped) $sumOpen++;
            if ($ord['order_type'] === 'internal') {
                $sumInternal++;
            } elseif ($ord['trade_channel'] === 'on_trade') {
                $sumOnTrade++;
            } elseif ($ord['trade_channel'] === 'off_trade') {
                $sumOffTrade++;
            }
            if ($ord['customer_name'] !== null) {
                $distinctClients[$ord['customer_name']] = true;
            }
            if (!$isCancelled && isset($cmdLines[$oid])) {
                foreach ($cmdLines[$oid] as $ln) {
                    $sumTotalHl += (float) $ln['qty'] * (float) $ln['hl_per_unit'];
                }
            }
        }
        $cmdSummary = [
            'total_hl'        => $sumTotalHl,
            'orders'          => $sumOrders,
            'open'            => $sumOpen,
            'on_trade'        => $sumOnTrade,
            'off_trade'       => $sumOffTrade,
            'internal'        => $sumInternal,
            'distinct_clients'=> count($distinctClients),
        ];
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

  <?php
  // ── Helper: render the status progress chips (shared by commandes view) ────
  function exp_render_chips(array $ord): void
  {
      $status = (string) ($ord['status'] ?? 'entered');
      $oid    = (int) $ord['id'];
      $isCanc = $status === 'cancelled';

      if ($isCanc) {
          echo '<span class="exp-chip exp-chip--cancelled">Annulée</span>';
          return;
      }
      foreach (['confirmed', 'picked', 'bl_printed', 'shipped'] as $stage) {
          $stageRank = EXP_STATUS_RANK[$stage] ?? 0;
          $curRank   = EXP_STATUS_RANK[$status] ?? 0;
          $isDone    = $curRank >= $stageRank;
          $isNext    = !$isDone && $curRank === ($stageRank - 1);
          $cls       = 'exp-chip exp-chip--' . $stage;
          if ($isDone) $cls .= ' exp-chip--done';
          if ($isNext) $cls .= ' exp-chip--next';
          $lbl = EXP_STATUS_LABELS[$stage] ?? $stage;
          $aria = $isDone
              ? ('✓ ' . $lbl . ' — fait')
              : ($isNext ? ('Marquer : ' . $lbl) : $lbl);
          $dis = ($isDone || !$isNext) ? ' disabled aria-disabled="true"' : '';
          printf(
              '<button class="%s" data-order-id="%d" data-action="advance" data-status="%s"%s aria-label="%s">%s</button>',
              htmlspecialchars($cls),
              $oid,
              htmlspecialchars($stage),
              $dis,
              htmlspecialchars($aria),
              ($isDone ? '✓ ' : '') . htmlspecialchars($lbl)
          );
      }
  }
  ?>

  <!-- ── Range notice ──────────────────────────────────────────────────────── -->
  <?php if ($cmdRangeNotice !== ''): ?>
    <div class="db-flash db-flash--warn"><?= htmlspecialchars($cmdRangeNotice) ?></div>
  <?php endif ?>

  <!-- ── Period toolbar ────────────────────────────────────────────────────── -->
  <?php
  $baseUrl    = '/modules/expeditions.php?view=commandes';
  $filterQS   = '';
  if ($filterClient  !== '') $filterQS .= '&amp;client='  . urlencode($filterClient);
  if ($filterSku     !== '') $filterQS .= '&amp;sku='     . urlencode($filterSku);
  if ($filterStatus  !== '') $filterQS .= '&amp;statut='  . urlencode($filterStatus);
  if ($filterChannel !== '') $filterQS .= '&amp;canal='   . urlencode($filterChannel);
  $weekBaseUrl  = $baseUrl . '&amp;mode=week';
  $rangeBaseUrl = $baseUrl . '&amp;mode=range';
  ?>
  <form class="exp-toolbar" method="GET" action="/modules/expeditions.php" id="exp-toolbar-form">
    <input type="hidden" name="view" value="commandes">

    <!-- Mode toggle -->
    <div class="exp-toolbar__mode" role="group" aria-label="Mode de période">
      <a href="<?= $weekBaseUrl ?><?= $filterQS ?>"
         class="exp-toolbar__mode-btn<?= $cmdMode === 'week' ? ' exp-toolbar__mode-btn--active' : '' ?>"
         aria-pressed="<?= $cmdMode === 'week' ? 'true' : 'false' ?>">Semaine</a>
      <a href="<?= $rangeBaseUrl ?><?= ($cmdDu ? '&amp;du=' . $cmdDu . '&amp;au=' . $cmdAu : '') ?><?= $filterQS ?>"
         class="exp-toolbar__mode-btn<?= $cmdMode === 'range' ? ' exp-toolbar__mode-btn--active' : '' ?>"
         aria-pressed="<?= $cmdMode === 'range' ? 'true' : 'false' ?>">Plage de dates</a>
    </div>

    <?php if ($cmdMode === 'week'): ?>
    <!-- Week navigation (links only — no form submit for nav arrows) -->
    <div class="exp-toolbar__week-nav" aria-label="Navigation semaine">
      <a href="<?= $weekBaseUrl ?>&amp;kw=<?= urlencode($kwPrev) ?><?= $filterQS ?>"
         class="exp-toolbar__nav-arrow" aria-label="Semaine précédente">◂</a>
      <span class="exp-toolbar__week-label"><?= exp_isoweek_label($cmdKw) ?></span>
      <a href="<?= $weekBaseUrl ?>&amp;kw=<?= urlencode($kwNext) ?><?= $filterQS ?>"
         class="exp-toolbar__nav-arrow" aria-label="Semaine suivante">▸</a>
      <a href="<?= $weekBaseUrl ?><?= $filterQS ?>"
         class="exp-toolbar__today-btn">Aujourd'hui</a>
    </div>
    <?php else: ?>
    <!-- Range inputs — submit button carries mode=range -->
    <div class="exp-toolbar__range-inputs" id="exp-range-inputs">
      <label class="exp-toolbar__range-label" for="exp-range-du">Du</label>
      <input type="date" id="exp-range-du" name="du" class="exp-toolbar__date-input"
             value="<?= htmlspecialchars($cmdDu) ?>"
             min="2020-01-01" max="2030-12-31">
      <label class="exp-toolbar__range-label" for="exp-range-au">Au</label>
      <input type="date" id="exp-range-au" name="au" class="exp-toolbar__date-input"
             value="<?= htmlspecialchars($cmdAu) ?>"
             min="2020-01-01" max="2030-12-31">
      <button type="submit" class="exp-toolbar__range-submit" name="mode" value="range">Afficher</button>
    </div>
    <?php endif ?>

    <!-- Filters — hidden period inputs + filter fields + Appliquer -->
    <div class="exp-toolbar__filters">
      <input type="hidden" name="mode"  value="<?= htmlspecialchars($cmdMode) ?>">
      <?php if ($cmdMode === 'week'): ?>
        <input type="hidden" name="kw" value="<?= htmlspecialchars($cmdKw) ?>">
      <?php else: ?>
        <input type="hidden" name="du" value="<?= htmlspecialchars($cmdDu) ?>">
        <input type="hidden" name="au" value="<?= htmlspecialchars($cmdAu) ?>">
      <?php endif ?>

      <!-- Client datalist filter -->
      <div class="exp-toolbar__filter-group">
        <label class="exp-toolbar__filter-label" for="exp-filter-client">Client</label>
        <input type="text" id="exp-filter-client" name="client"
               class="exp-toolbar__filter-input"
               value="<?= htmlspecialchars($filterClient) ?>"
               list="exp-clients-datalist"
               placeholder="Tous…" autocomplete="off">
        <datalist id="exp-clients-datalist">
          <?php foreach (array_keys($cmdFilteredCusts) as $cn): ?>
            <option value="<?= htmlspecialchars($cn) ?>">
          <?php endforeach ?>
        </datalist>
      </div>

      <!-- SKU filter -->
      <div class="exp-toolbar__filter-group">
        <label class="exp-toolbar__filter-label" for="exp-filter-sku">SKU</label>
        <input type="text" id="exp-filter-sku" name="sku"
               class="exp-toolbar__filter-input exp-toolbar__filter-input--narrow"
               value="<?= htmlspecialchars($filterSku) ?>"
               list="exp-skus-datalist"
               placeholder="Tous…" autocomplete="off">
        <datalist id="exp-skus-datalist">
          <?php foreach (array_keys($cmdFilteredSkus) as $sc): ?>
            <option value="<?= htmlspecialchars($sc) ?>">
          <?php endforeach ?>
        </datalist>
      </div>

      <!-- Statut filter -->
      <div class="exp-toolbar__filter-group">
        <label class="exp-toolbar__filter-label" for="exp-filter-statut">Statut</label>
        <select id="exp-filter-statut" name="statut" class="exp-toolbar__filter-select">
          <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Tous</option>
          <option value="ouvertes"   <?= $filterStatus === 'ouvertes'   ? 'selected' : '' ?>>Ouvertes</option>
          <option value="entered"    <?= $filterStatus === 'entered'    ? 'selected' : '' ?>>Saisie</option>
          <option value="confirmed"  <?= $filterStatus === 'confirmed'  ? 'selected' : '' ?>>Confirmée</option>
          <option value="picked"     <?= $filterStatus === 'picked'     ? 'selected' : '' ?>>Préparée</option>
          <option value="bl_printed" <?= $filterStatus === 'bl_printed' ? 'selected' : '' ?>>BL imprimé</option>
          <option value="shipped"    <?= $filterStatus === 'shipped'    ? 'selected' : '' ?>>Livrée</option>
          <option value="cancelled"  <?= $filterStatus === 'cancelled'  ? 'selected' : '' ?>>Annulée</option>
        </select>
      </div>

      <!-- Canal filter -->
      <div class="exp-toolbar__filter-group">
        <label class="exp-toolbar__filter-label" for="exp-filter-canal">Canal</label>
        <select id="exp-filter-canal" name="canal" class="exp-toolbar__filter-select">
          <option value="" <?= $filterChannel === '' ? 'selected' : '' ?>>Tous</option>
          <option value="on_trade"  <?= $filterChannel === 'on_trade'  ? 'selected' : '' ?>>On-trade</option>
          <option value="off_trade" <?= $filterChannel === 'off_trade' ? 'selected' : '' ?>>Off-trade</option>
          <option value="interne"   <?= $filterChannel === 'interne'   ? 'selected' : '' ?>>Interne</option>
        </select>
      </div>

      <button type="submit" class="exp-toolbar__filter-apply">Appliquer</button>

      <?php if ($filterClient !== '' || $filterSku !== '' || $filterStatus !== '' || $filterChannel !== ''): ?>
        <a href="<?= $baseUrl ?>&amp;mode=<?= htmlspecialchars($cmdMode) ?><?= $cmdMode === 'week' ? '&amp;kw=' . urlencode($cmdKw) : '&amp;du=' . $cmdDu . '&amp;au=' . $cmdAu ?>"
           class="exp-toolbar__reset">Réinitialiser</a>
      <?php endif ?>
    </div>
  </form>

  <!-- ── Period summary band ───────────────────────────────────────────────── -->
  <?php if ($cmdSummary['orders'] > 0 || !empty($cmdEshopByDay)): ?>
  <div class="exp-summary-band" role="region" aria-label="Résumé de la période">
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= number_format($cmdSummary['total_hl'], 2) ?></span>
      <span class="exp-summary-kpi__label">HL</span>
    </div>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $cmdSummary['orders'] ?></span>
      <span class="exp-summary-kpi__label">commande<?= $cmdSummary['orders'] !== 1 ? 's' : '' ?></span>
    </div>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $cmdSummary['open'] ?></span>
      <span class="exp-summary-kpi__label">ouverte<?= $cmdSummary['open'] !== 1 ? 's' : '' ?></span>
    </div>
    <div class="exp-summary-sep"></div>
    <?php
    $tradeTotal = $cmdSummary['on_trade'] + $cmdSummary['off_trade'];
    $onPct  = $tradeTotal > 0 ? round(100 * $cmdSummary['on_trade']  / $tradeTotal) : 0;
    $offPct = $tradeTotal > 0 ? round(100 * $cmdSummary['off_trade'] / $tradeTotal) : 0;
    ?>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $onPct ?>%</span>
      <span class="exp-summary-kpi__label">on-trade</span>
    </div>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $offPct ?>%</span>
      <span class="exp-summary-kpi__label">off-trade</span>
    </div>
    <?php if ($cmdSummary['internal'] > 0): ?>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $cmdSummary['internal'] ?></span>
      <span class="exp-summary-kpi__label">interne</span>
    </div>
    <?php endif ?>
    <div class="exp-summary-sep"></div>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $cmdSummary['distinct_clients'] ?></span>
      <span class="exp-summary-kpi__label">client<?= $cmdSummary['distinct_clients'] !== 1 ? 's' : '' ?></span>
    </div>
  </div>
  <?php endif ?>

  <!-- ── Cancelled toggle ──────────────────────────────────────────────────── -->
  <?php
  $hasCancelled = false;
  foreach ($cmdOrders as $ord) {
      if ($ord['status'] === 'cancelled') { $hasCancelled = true; break; }
  }
  ?>
  <?php if ($hasCancelled): ?>
  <div class="exp-cancelled-toggle">
    <button type="button" class="exp-cancelled-toggle__btn" id="exp-toggle-cancelled"
            aria-pressed="false">
      Afficher annulées
    </button>
  </div>
  <?php endif ?>

  <!-- ── Day blocks ────────────────────────────────────────────────────────── -->
  <div class="exp-section" id="exp-days-container">

    <?php
    // Collect all dates that have either ord_orders or eshop rows
    $allDates = array_unique(array_merge(
        array_keys($cmdByDay),
        array_keys($cmdEshopByDay)
    ));
    sort($allDates);
    ?>

    <?php if (empty($allDates)): ?>
      <div class="op-form__card exp-empty-state">
        <p class="exp-empty">
          <?php if ($filterClient !== '' || $filterSku !== '' || $filterStatus !== '' || $filterChannel !== ''): ?>
            Aucune commande ne correspond aux filtres sélectionnés.
          <?php else: ?>
            Aucune commande pour cette période.
          <?php endif ?>
        </p>
      </div>
    <?php endif ?>

    <?php foreach ($allDates as $date): ?>
      <?php
      $dayOrderIds  = $cmdByDay[$date]  ?? [];
      $dayEshop     = $cmdEshopByDay[$date] ?? [];

      // Per-day metrics (exclude cancelled from HL)
      $dayHl     = 0.0;
      $dayCount  = 0;
      $dayOpen   = 0;
      foreach ($dayOrderIds as $oid) {
          $ord = $cmdOrders[$oid] ?? null;
          if ($ord === null) continue;
          $dayCount++;
          if (!in_array($ord['status'], ['shipped', 'cancelled'], true)) $dayOpen++;
          if ($ord['status'] !== 'cancelled' && isset($cmdLines[$oid])) {
              foreach ($cmdLines[$oid] as $ln) {
                  $dayHl += (float) $ln['qty'] * (float) $ln['hl_per_unit'];
              }
          }
      }
      ?>
      <div class="exp-day-block">
        <!-- Day header -->
        <div class="exp-day-header">
          <div class="exp-day-header__date">
            <span class="exp-day-header__name"><?= exp_day_name($date) ?></span>
            <span class="exp-day-header__fmt"><?= exp_fmt_date($date) ?></span>
          </div>
          <div class="exp-day-header__metrics">
            <?php if ($dayCount > 0): ?>
              <span class="exp-day-metric">
                <span class="exp-day-metric__val"><?= number_format($dayHl, 2) ?></span>
                <span class="exp-day-metric__unit">HL</span>
              </span>
              <span class="exp-day-metric">
                <span class="exp-day-metric__val"><?= $dayCount ?></span>
                <span class="exp-day-metric__unit">cmd</span>
              </span>
              <?php if ($dayOpen > 0): ?>
              <span class="exp-day-metric exp-day-metric--open">
                <span class="exp-day-metric__val"><?= $dayOpen ?></span>
                <span class="exp-day-metric__unit">ouverte<?= $dayOpen > 1 ? 's' : '' ?></span>
              </span>
              <?php endif ?>
            <?php endif ?>
          </div>
        </div>

        <!-- Orders for this day -->
        <?php foreach ($dayOrderIds as $oid):
            $ord     = $cmdOrders[$oid] ?? null;
            if ($ord === null) continue;
            $status  = (string) ($ord['status'] ?? 'entered');
            $isCanc  = $status === 'cancelled';
            $isShip  = $status === 'shipped';
            $lines   = $cmdLines[$oid] ?? [];

            // SKU pills: up to 6 visible, +N expand
            $pillsHtml = '';
            $pillCount = count($lines);
            $visLines  = array_slice($lines, 0, 6);
            $hidLines  = array_slice($lines, 6);
            foreach ($visLines as $ln) {
                $qty     = (float) $ln['qty'];
                $qtyFmt  = (floor($qty) == $qty) ? (int)$qty : $qty;
                $pillsHtml .= '<span class="exp-sku-pill">'
                    . htmlspecialchars($ln['sku_code']) . '×' . $qtyFmt
                    . '</span>';
            }
            if (!empty($hidLines)) {
                $hidHtml = '';
                foreach ($hidLines as $ln) {
                    $qty = (float) $ln['qty'];
                    $qtyFmt = (floor($qty) == $qty) ? (int)$qty : $qty;
                    $hidHtml .= '<span class="exp-sku-pill">'
                        . htmlspecialchars($ln['sku_code']) . '×' . $qtyFmt
                        . '</span>';
                }
                $pillsHtml .= '<button type="button" class="exp-sku-more" '
                    . 'data-hidden-pills="' . htmlspecialchars($hidHtml, ENT_QUOTES) . '"'
                    . ' aria-expanded="false">+' . count($hidLines) . '</button>';
            }
        ?>
          <div class="exp-order-row<?= $isCanc ? ' exp-order-row--cancelled' : '' ?>"
               data-order-id="<?= $oid ?>"
               data-status="<?= htmlspecialchars($status) ?>">

            <span class="exp-order-id">#<?= $oid ?></span>

            <!-- Party -->
            <?php if ($ord['order_type'] === 'customer'): ?>
              <?php
              $channel = $ord['trade_channel'] ?? '';
              $chanDot = $channel === 'on_trade'
                  ? '<span class="exp-trade-dot exp-trade-dot--on" title="On-trade"></span>'
                  : ($channel === 'off_trade'
                      ? '<span class="exp-trade-dot exp-trade-dot--off" title="Off-trade"></span>'
                      : '');
              ?>
              <span class="exp-order-party">
                <?= $chanDot ?>
                <?= htmlspecialchars($ord['customer_name'] ?? '—') ?>
              </span>
            <?php else: ?>
              <span class="exp-order-party exp-order-party--internal">
                <span class="exp-internal-badge"><?= htmlspecialchars(EXP_INTERNAL_LABELS[$ord['internal_channel'] ?? ''] ?? ($ord['internal_channel'] ?? '—')) ?></span>
              </span>
            <?php endif ?>

            <!-- SKU pills -->
            <div class="exp-sku-pills">
              <?= $pillsHtml ?>
            </div>

            <!-- Comment -->
            <?php if (!empty($ord['comment'])): ?>
              <span class="exp-order-comment" title="<?= htmlspecialchars($ord['comment']) ?>">
                💬 <span class="exp-order-comment__text"><?= htmlspecialchars(mb_substr($ord['comment'], 0, 40)) ?><?= mb_strlen($ord['comment']) > 40 ? '…' : '' ?></span>
              </span>
            <?php endif ?>

            <!-- Transporter tag -->
            <?php if (!empty($ord['transporter_name'])): ?>
              <span class="exp-transporter-tag"><?= htmlspecialchars($ord['transporter_name']) ?></span>
            <?php endif ?>

            <!-- Status chips -->
            <div class="exp-progress" aria-label="Statut : <?= htmlspecialchars(EXP_STATUS_LABELS[$status] ?? $status) ?>">
              <?php exp_render_chips($ord) ?>
            </div>

            <!-- Actions -->
            <div class="exp-order-actions">
              <?php if (!$isShip && !$isCanc): ?>
                <a class="exp-action-btn exp-action-btn--edit"
                   href="/modules/expeditions.php?view=form&amp;edit=<?= $oid ?>"
                   aria-label="Modifier commande #<?= $oid ?>">✎</a>
                <button class="exp-action-btn exp-action-btn--cancel"
                        data-order-id="<?= $oid ?>"
                        data-action="cancel"
                        aria-label="Annuler la commande #<?= $oid ?>">Annuler</button>
              <?php endif ?>
            </div>

          </div>
        <?php endforeach ?>

        <!-- Eshop/taproom auto rows for this day -->
        <?php foreach ($dayEshop as $channel => $aggr): ?>
          <?php
          $chanLabel = $channel === 'eshop' ? 'Eshop' : 'Taproom';
          $chanIcon  = $channel === 'eshop' ? '🛒' : '🍺';
          ?>
          <div class="exp-auto-row">
            <span class="exp-auto-row__icon"><?= $chanIcon ?></span>
            <span class="exp-auto-row__label">
              <?= $chanLabel ?> <span class="exp-auto-badge">(auto)</span>
            </span>
            <span class="exp-auto-row__meta">
              <?= $aggr['orders'] ?> commande<?= $aggr['orders'] > 1 ? 's' : '' ?>
              · <?= number_format($aggr['hl'], 2) ?> HL
            </span>
            <div class="exp-progress">
              <span class="exp-chip exp-chip--auto">auto</span>
            </div>
          </div>
        <?php endforeach ?>

      </div>
    <?php endforeach ?>

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
<script>
  window.EXP_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/expeditions.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions.js') ?: time() ?>"></script>
<?php endif ?>

</body>
</html>
