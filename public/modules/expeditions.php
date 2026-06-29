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
require_once __DIR__ . '/../../app/settings.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/fg-stock.php';
require_once __DIR__ . '/../../app/repack.php';
require_once __DIR__ . '/../../app/stocktake-helpers.php';

require_page_access('expeditions');
$me = current_user();

// ── Resolve preset_key for home-site derivation ───────────────────────────────
// current_user() returns access_preset_id_fk (INT|null) but not the preset_key string.
// One prepared SELECT here — used by exp_user_home_site_type() below.
// Result is null when the user has no preset or the join finds nothing.
$_mePresetKey = null;
if (!empty($me['access_preset_id_fk'])) {
    try {
        $_presetPdo  = maltytask_pdo();
        $_presetStmt = $_presetPdo->prepare(
            'SELECT preset_key FROM ref_access_presets WHERE id = ? LIMIT 1'
        );
        $_presetStmt->execute([(int) $me['access_preset_id_fk']]);
        $_row = $_presetStmt->fetch(PDO::FETCH_ASSOC);
        $_mePresetKey = $_row ? (string) $_row['preset_key'] : null;
    } catch (Throwable $e) {
        error_log('[expeditions preset_key] ' . $e->getMessage());
    }
}

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

// ── Eshop fulfilment workflow constants (Phase 2A) ────────────────────────────
// Mirrors ESHOP_FULFIL_* in public/api/eshop-fulfilment-status.php — NEVER duplicate values here.
// Use ONLY these constants in PHP rendering; JS mirrors them in eshop-fulfilment.js.
const EXP_ESHOP_STATUS_LABELS = [
    'new'              => 'Nouveau',
    'picking'          => 'En préparation',
    'picked'           => 'Préparé',
    'ready_for_pickup' => 'Prêt au retrait',
    'fulfilled'        => 'Expédié',
    'picked_up'        => 'Remis',
    'cancelled'        => 'Annulé',
];
// Numeric ranks for stage comparison (NEVER compare ENUM ordinals)
const EXP_ESHOP_STATUS_RANK_DELIVERY = [
    'new' => 0, 'picking' => 1, 'picked' => 2, 'fulfilled' => 3, 'cancelled' => -1,
];
const EXP_ESHOP_STATUS_RANK_PICKUP = [
    'new' => 0, 'picking' => 1, 'picked' => 2, 'ready_for_pickup' => 3, 'picked_up' => 4, 'cancelled' => -1,
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

// ── Line-status label map — operator labels; never expose DB enum literals in UI ─
const EXP_LINE_STATUS_LABELS = [
    'to_fulfil'  => 'À livrer',
    'non_livre'  => 'Non livré',
    'rupture'    => 'Rupture',
];

// ── View routing ──────────────────────────────────────────────────────────────
$view    = isset($_GET['view']) ? (string) $_GET['view'] : 'commandes';
$allowedViews = ['commandes', 'shopify', 'form', 'stock', 'stocktake', 'clients', 'mouvements', 'side-stock', 'historique', 'repack', 'retours'];
if (!in_array($view, $allowedViews, true)) $view = 'commandes';

$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
if ($editId < 0) $editId = 0;

// ── POST handler (Clients view) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'clients') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=clients');
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
        redirect_to('/modules/expeditions.php?view=clients');
    }

    $pdo    = maltytask_pdo();
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    // Whitelist allowed actions
    $allowedClientActions = ['client_merge', 'client_validate', 'client_deactivate', 'client_update'];
    if (!in_array($action, $allowedClientActions, true)) {
        flash_set('err', 'Action non reconnue.');
        redirect_to('/modules/expeditions.php?view=clients');
    }

    $clientId = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
    if ($clientId <= 0) {
        flash_set('err', 'Identifiant client invalide.');
        redirect_to('/modules/expeditions.php?view=clients');
    }

    try {
        // Verify client exists
        $clientRow = bd_fetch_before($pdo, 'ref_customers', $clientId);
        if ($clientRow === null) {
            flash_set('err', 'Client #' . $clientId . ' introuvable.');
            redirect_to('/modules/expeditions.php?view=clients');
        }

        // ── action: client_merge ─────────────────────────────────────────────
        if ($action === 'client_merge') {
            $targetId = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
            if ($targetId <= 0 || $targetId === $clientId) {
                flash_set('err', 'Cible de fusion invalide.');
                redirect_to('/modules/expeditions.php?view=clients');
            }

            $targetRow = bd_fetch_before($pdo, 'ref_customers', $targetId);
            if ($targetRow === null || !(bool)$targetRow['is_active'] || $targetRow['bc_customer_no'] === null) {
                flash_set('err', 'Client cible introuvable ou non éligible (doit être actif avec n° BC).');
                redirect_to('/modules/expeditions.php?view=clients');
            }

            $pdo->beginTransaction();

            // 1. Reassign orders from dup → target
            $countOrdStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM ord_orders WHERE customer_id_fk = ?'
            );
            $countOrdStmt->execute([$clientId]);
            $ordCount = (int) $countOrdStmt->fetchColumn();

            if ($ordCount > 0) {
                $updOrd = $pdo->prepare(
                    'UPDATE ord_orders SET customer_id_fk = ?, updated_at = CURRENT_TIMESTAMP
                      WHERE customer_id_fk = ?'
                );
                $updOrd->execute([$targetId, $clientId]);
                log_revision($pdo, $me, 'ord_orders', $clientId, null,
                    ['reassigned_to' => $targetId, 'count' => $ordCount],
                    'normal', 'Fusion client: commandes réaffectées vers #' . $targetId);
            }

            // 2. Copy missing fields onto target (trade_channel, default_transporter_id_fk)
            $targetUpdates = [];
            $targetBefore  = $targetRow;
            if ($targetRow['trade_channel'] === null && $clientRow['trade_channel'] !== null) {
                $targetUpdates['trade_channel'] = $clientRow['trade_channel'];
            }
            if ($targetRow['default_transporter_id_fk'] === null && $clientRow['default_transporter_id_fk'] !== null) {
                $targetUpdates['default_transporter_id_fk'] = $clientRow['default_transporter_id_fk'];
            }
            // Append alias to target notes
            $aliasNote   = 'alias: ' . $clientRow['name'];
            $targetNotes = trim(($targetRow['notes'] ?? '') . "\n" . $aliasNote);
            $targetUpdates['notes'] = $targetNotes;
            $targetUpdates['updated_by'] = $me['username'];

            $setClauses = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($targetUpdates)));
            $updTarget  = $pdo->prepare(
                "UPDATE ref_customers SET {$setClauses}, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updTarget->execute([...array_values($targetUpdates), $targetId]);
            log_revision($pdo, $me, 'ref_customers', $targetId, $targetBefore,
                array_merge($targetUpdates, ['id' => $targetId]),
                'normal', 'Fusion client: champs copiés depuis #' . $clientId);

            // 3. Tombstone the dup
            $dupNotes = trim(($clientRow['notes'] ?? '') . "\nmerged_into: {$targetId}");
            $updDup = $pdo->prepare(
                'UPDATE ref_customers
                    SET is_active = 0, needs_review = 0, notes = ?, updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $updDup->execute([$dupNotes, $me['username'], $clientId]);
            log_revision($pdo, $me, 'ref_customers', $clientId, $clientRow,
                ['is_active' => 0, 'needs_review' => 0, 'notes' => $dupNotes],
                'normal', 'Fusion client: fusionné dans #' . $targetId);

            $pdo->commit();
            flash_set('ok', '« ' . $clientRow['name'] . ' » fusionné dans « ' . $targetRow['name'] . ' ». '
                . ($ordCount > 0 ? $ordCount . ' commande(s) réaffectée(s).' : ''));
            redirect_to('/modules/expeditions.php?view=clients');
        }

        // ── action: client_validate ──────────────────────────────────────────
        if ($action === 'client_validate') {
            $before = $clientRow;
            $updStmt = $pdo->prepare(
                'UPDATE ref_customers SET needs_review = 0, updated_by = ?,
                         updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $updStmt->execute([$me['username'], $clientId]);
            log_revision($pdo, $me, 'ref_customers', $clientId, $before,
                ['needs_review' => 0], 'normal', 'Client validé tel quel');
            flash_set('ok', '« ' . $clientRow['name'] . ' » validé.');
            redirect_to('/modules/expeditions.php?view=clients');
        }

        // ── action: client_deactivate ────────────────────────────────────────
        if ($action === 'client_deactivate') {
            $before = $clientRow;
            $updStmt = $pdo->prepare(
                'UPDATE ref_customers SET is_active = 0, needs_review = 0, updated_by = ?,
                         updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $updStmt->execute([$me['username'], $clientId]);
            log_revision($pdo, $me, 'ref_customers', $clientId, $before,
                ['is_active' => 0, 'needs_review' => 0], 'normal', 'Client désactivé');
            flash_set('ok', '« ' . $clientRow['name'] . ' » désactivé.');
            redirect_to('/modules/expeditions.php?view=clients');
        }

        // ── action: client_update (inline field edit) ────────────────────────
        if ($action === 'client_update') {
            // Editable fields whitelist
            $editableFields = [
                'trade_channel', 'default_transporter_id_fk', 'is_active',
                'is_private', 'email', 'notes',
                'is_serving_tank_client', 'serving_tank_count',
                'serving_tank_size_hl', 'serving_tank_budget_hl',
            ];
            $field = isset($_POST['field']) ? (string) $_POST['field'] : '';
            if (!in_array($field, $editableFields, true)) {
                flash_set('err', 'Champ non modifiable : ' . htmlspecialchars($field));
                redirect_to('/modules/expeditions.php?view=clients');
            }

            $rawVal = isset($_POST['value']) ? $_POST['value'] : null;

            // Per-field validation
            $coerced = null;
            if ($field === 'trade_channel') {
                if ($rawVal === '' || $rawVal === null) {
                    $coerced = null;
                } elseif (in_array($rawVal, ['on_trade', 'off_trade'], true)) {
                    $coerced = $rawVal;
                } else {
                    flash_set('err', 'Canal invalide.');
                    redirect_to('/modules/expeditions.php?view=clients');
                }
            } elseif ($field === 'default_transporter_id_fk') {
                $tid = (int) ($rawVal ?? 0);
                if ($tid <= 0) {
                    $coerced = null;
                } else {
                    // Verify transporter exists and is active
                    $tStmt = $pdo->prepare('SELECT id FROM ref_transporters WHERE id = ? AND is_active = 1 LIMIT 1');
                    $tStmt->execute([$tid]);
                    if (!$tStmt->fetchColumn()) {
                        flash_set('err', 'Transporteur introuvable.');
                        redirect_to('/modules/expeditions.php?view=clients');
                    }
                    $coerced = $tid;
                }
            } elseif ($field === 'is_active') {
                $coerced = in_array($rawVal, ['0', '1', 0, 1], true) ? (int) $rawVal : null;
                if ($coerced === null) {
                    flash_set('err', 'Valeur invalide pour is_active.');
                    redirect_to('/modules/expeditions.php?view=clients');
                }
            } elseif ($field === 'is_private') {
                $coerced = in_array($rawVal, ['0', '1', 0, 1], true) ? (int) $rawVal : null;
                if ($coerced === null) {
                    flash_set('err', 'Valeur invalide pour is_private.');
                    redirect_to('/modules/expeditions.php?view=clients');
                }
            } elseif ($field === 'email') {
                $coerced = ($rawVal === '' || $rawVal === null) ? null : substr(trim((string) $rawVal), 0, 255);
                if ($coerced !== null && !filter_var($coerced, FILTER_VALIDATE_EMAIL)) {
                    flash_set('err', 'Adresse e-mail invalide.');
                    redirect_to('/modules/expeditions.php?view=clients');
                }
            } elseif ($field === 'notes') {
                $coerced = ($rawVal === '' || $rawVal === null) ? null : substr(trim((string) $rawVal), 0, 2000);
            } elseif ($field === 'is_serving_tank_client') {
                $coerced = in_array($rawVal, ['0', '1', 0, 1], true) ? (int) $rawVal : null;
                if ($coerced === null) {
                    flash_set('err', 'Valeur invalide pour is_serving_tank_client.');
                    redirect_to('/modules/expeditions.php?view=clients');
                }
            } elseif ($field === 'serving_tank_count') {
                if ($rawVal === '' || $rawVal === null) {
                    $coerced = null;
                } else {
                    $iv = (int) $rawVal;
                    if ($iv < 0 || $iv > 255) {
                        flash_set('err', 'Nombre de cuves invalide (0–255).');
                        redirect_to('/modules/expeditions.php?view=clients');
                    }
                    $coerced = $iv;
                }
            } elseif ($field === 'serving_tank_size_hl') {
                if ($rawVal === '' || $rawVal === null) {
                    $coerced = null;
                } else {
                    $fv = (float) str_replace(',', '.', (string) $rawVal);
                    if ($fv < 0) {
                        flash_set('err', 'Taille de cuve invalide (valeur positive requise).');
                        redirect_to('/modules/expeditions.php?view=clients');
                    }
                    $coerced = $fv;
                }
            } elseif ($field === 'serving_tank_budget_hl') {
                if ($rawVal === '' || $rawVal === null) {
                    $coerced = null;
                } else {
                    $fv = (float) str_replace(',', '.', (string) $rawVal);
                    if ($fv < 0) {
                        flash_set('err', 'Budget HL invalide (valeur positive requise).');
                        redirect_to('/modules/expeditions.php?view=clients');
                    }
                    $coerced = $fv;
                }
            }

            $before = $clientRow;
            $updStmt = $pdo->prepare(
                "UPDATE ref_customers SET `{$field}` = ?, updated_by = ?,
                         updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updStmt->execute([$coerced, $me['username'], $clientId]);
            log_revision($pdo, $me, 'ref_customers', $clientId, $before,
                [$field => $coerced], 'normal', 'Modification inline champ: ' . $field);

            // Return to the clients view preserving any filter params
            $returnPage = isset($_POST['return_page']) ? max(1, (int) $_POST['return_page']) : 1;
            $returnSearch = isset($_POST['return_search']) ? trim((string) $_POST['return_search']) : '';
            $returnFilter = isset($_POST['return_filter']) ? (string) $_POST['return_filter'] : '';
            $qs = '?view=clients&page=' . $returnPage;
            if ($returnSearch !== '') $qs .= '&search=' . urlencode($returnSearch);
            if ($returnFilter !== '') $qs .= '&filter=' . urlencode($returnFilter);
            flash_set('ok', 'Client mis à jour.');
            redirect_to('/modules/expeditions.php' . $qs);
        }

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[expeditions clients POST] ' . $e->getMessage());
        flash_set('err', 'Erreur : ' . pdo_friendly_error($e));
        redirect_to('/modules/expeditions.php?view=clients');
    }
}

// ── POST handler (Saisie view only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'form') {

    // CSRF — must be first
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=form' . ($editId ? '&edit=' . $editId : ''));
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
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

    // Active holds_fg_stock site IDs (whitelist for fulfilment_site_id_fk)
    $activeFulfilmentSiteIds = [];
    $fsRows = $pdo->query(
        'SELECT id FROM ref_sites WHERE holds_fg_stock = 1 AND is_active = 1'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fsRows as $fsid) $activeFulfilmentSiteIds[(int)$fsid] = true;

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
    $fulfilSiteRaw = $_POST['fulfilment_site_id_fk'] ?? '';

    // Lines: parallel arrays from the form
    $lineSkuIds  = $_POST['line_sku_id']      ?? [];
    $lineQtys    = $_POST['line_qty']          ?? [];
    $lineComments= $_POST['line_comment']      ?? [];

    // ── Validation ────────────────────────────────────────────────────────
    $errors = [];

    // B2B-create block (Phase 1 live — BC is the sole source for customer orders).
    // Only NEW customer orders are blocked; editing an existing order (any source,
    // incl. bc-sourced) is still allowed. Internal-channel creation is never blocked.
    if (!$isEdit && $orderType === 'customer') {
        flash_set('err', 'Les commandes clients proviennent désormais de Business Central. La création manuelle de commandes B2B est désactivée.');
        redirect_to('/modules/expeditions.php?view=form');
    }

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

    // Fulfilment site (optional — empty string → NULL means "automatic")
    $fulfilSiteIdFk = null;
    if ($fulfilSiteRaw !== '') {
        $fulfilSiteInt = (int) $fulfilSiteRaw;
        if (!isset($activeFulfilmentSiteIds[$fulfilSiteInt])) {
            // Submitted id is not a valid holds_fg_stock site — reject silently to NULL
            $fulfilSiteIdFk = null;
        } else {
            $fulfilSiteIdFk = $fulfilSiteInt;
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
                    SET order_type               = ?,
                        customer_id_fk           = ?,
                        internal_channel         = ?,
                        requested_date           = ?,
                        transporter_id_fk        = ?,
                        fulfilment_site_id_fk    = ?,
                        comment                  = ?,
                        updated_at               = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $updOrd->execute([
                $orderType,
                $customerId,
                ($orderType === 'internal') ? $intChannel : null,
                $reqDate,
                $transIdFk,
                $fulfilSiteIdFk,
                $comment ?: null,
                $editId,
            ]);
            log_revision($pdo, $me, 'ord_orders', $editId, $beforeOrder, [
                'order_type'            => $orderType,
                'customer_id_fk'        => $customerId,
                'internal_channel'      => ($orderType === 'internal') ? $intChannel : null,
                'requested_date'        => $reqDate,
                'transporter_id_fk'     => $transIdFk,
                'fulfilment_site_id_fk' => $fulfilSiteIdFk,
                'comment'               => $comment ?: null,
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
                     status, transporter_id_fk, fulfilment_site_id_fk, comment, source, created_by_user_id)
                 VALUES (?, ?, ?, ?, "entered", ?, ?, ?, "web", ?)'
            );
            $insOrd->execute([
                $orderType,
                $customerId,
                ($orderType === 'internal') ? $intChannel : null,
                $reqDate,
                $transIdFk,
                $fulfilSiteIdFk,
                $comment ?: null,
                (int) $me['id'],
            ]);
            $newOrderId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ord_orders', $newOrderId, null, [
                'order_type'            => $orderType,
                'customer_id_fk'        => $customerId,
                'internal_channel'      => ($orderType === 'internal') ? $intChannel : null,
                'requested_date'        => $reqDate,
                'status'                => 'entered',
                'transporter_id_fk'     => $transIdFk,
                'fulfilment_site_id_fk' => $fulfilSiteIdFk,
                'comment'               => $comment ?: null,
                'source'                => 'web',
                'created_by_user_id'    => (int) $me['id'],
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

// ── POST handler (Stocktake view) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'stocktake') {

    // CSRF — must be first
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=stocktake');
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
        redirect_to('/modules/expeditions.php?view=stocktake');
    }

    $pdo = maltytask_pdo();

    // ── Role gate: only managers/admins may back-date ─────────────────────
    // Server-side enforcement — the date picker visibility is also role-gated
    // in the render, but this is the real enforcement layer.
    $canBackdate = is_manager() || is_admin();

    // ── Build allowed-sets INSIDE the POST path ───────────────────────────
    // (Anti-pattern: building allowed-sets only in the GET render path leaves
    //  them undefined when the POST handler runs.)

    // Load holds_fg_stock sites
    $stSitesRows = $pdo->query(
        'SELECT id, name, site_type, sort_order, notes
           FROM ref_sites
          WHERE holds_fg_stock = 1 AND is_active = 1
          ORDER BY sort_order ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $allowedLocationIds = [];
    foreach ($stSitesRows as $sr) {
        $allowedLocationIds[(int) $sr['id']] = $sr;
    }

    // Active SKUs (id → {sku_code, hl_per_unit, is_cage, stocktake_scope})
    // Cage SKUs (stocktake_scope='cage'): units_per_pack=1, hl_per_unit=0.00330/btl.
    // Operator enters integer bottle count; stored directly as bottles.
    $stSkuRows = $pdo->query(
        'SELECT id, sku_code, hl_per_unit, units_per_pack, stocktake_scope FROM ref_skus WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_ASSOC);
    $allowedSkuIds = [];
    foreach ($stSkuRows as $sr) {
        $isCage = ($sr['stocktake_scope'] === 'cage');
        $allowedSkuIds[(int) $sr['id']] = [
            'sku_code'        => $sr['sku_code'],
            'hl_per_unit'     => (float) $sr['hl_per_unit'],
            'is_cage'         => $isCage,
            'stocktake_scope' => $sr['stocktake_scope'],
        ];
    }

    // ── Coerce + validate inputs ─────────────────────────────────────────
    $stLocId     = isset($_POST['location_id'])  ? (int) $_POST['location_id']   : 0;
    $stCountedAt = isset($_POST['counted_at'])  ? trim((string) $_POST['counted_at']) : '';
    $stCountType = isset($_POST['month_end_census']) ? 'month_end' : 'operational';

    // ── Back-date coercion: operators limited to 30-day window ───────────
    // A forged POST from an operator with a date outside the 30-day window
    // is silently coerced to today. Within the window, the date is accepted.
    if (!$canBackdate) {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        if ($stCountedAt < $thirtyDaysAgo) {
            $stCountedAt = date('Y-m-d');
        }
    }

    // ── Motif extraction (for past-date corrections) ──────────────────────
    $stMotif     = trim((string) ($_POST['correction_motif'] ?? ''));
    $stMotifNote = trim((string) ($_POST['correction_note']  ?? ''));
    $stAuditNote = 'Saisie inventaire FG multi-site';
    if ($stMotif !== '') {
        $validMotifs = ['erreur-saisie', 'casse', 'perte', 'retrouve', 'autre'];
        if (in_array($stMotif, $validMotifs, true)) {
            $stAuditNote = 'Correction (' . $stMotif . ')'
                . ($stMotifNote !== '' ? ': ' . mb_substr($stMotifNote, 0, 200) : '');
        }
    }

    $stErrors = [];

    // Location must be one of the 4 holds_fg_stock sites
    if ($stLocId <= 0 || !isset($allowedLocationIds[$stLocId])) {
        $stErrors[] = 'Site invalide.';
    }

    // counted_at must be a valid date
    if ($stCountedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $stCountedAt)) {
        $stErrors[] = 'Date de comptage invalide.';
    } else {
        // Further validate: not a future date, not absurdly old
        if ($stCountedAt > date('Y-m-d')) {
            $stErrors[] = 'La date de comptage ne peut pas être dans le futur.';
        } elseif ($stCountedAt < '2020-01-01') {
            $stErrors[] = 'Date de comptage trop ancienne.';
        }
    }

    // Derive the site_type for the selected location (used for scope×site_type enforcement below).
    $stSiteType = ($stLocId > 0 && isset($allowedLocationIds[$stLocId]))
        ? (string) $allowedLocationIds[$stLocId]['site_type']
        : '';

    // Parse SKU qty entries — parallel arrays from the form
    $stSkuIds = $_POST['sku_qty_id']  ?? [];
    $stQtys   = $_POST['sku_qty_val'] ?? [];

    $stValidLines = [];
    if (is_array($stSkuIds) && empty($stErrors)) {
        foreach ($stSkuIds as $idx => $rawId) {
            $sid = (int) ($rawId ?? 0);
            $rawQty = isset($stQtys[$idx]) ? trim((string) $stQtys[$idx]) : '';
            if ($rawQty === '') continue; // blank = not counted — skip (leaves any existing row untouched)
            // NOTE: '0' !== '' so an explicit zero DOES write, which is correct (real stock-out).
            if ($sid <= 0 || !isset($allowedSkuIds[$sid])) continue; // safety
            // Defense-in-depth: enforce scope × site_type server-side.
            // Rejects any SKU not permitted at the selected location even if
            // a malformed POST tries to submit it.
            $scope = $allowedSkuIds[$sid]['stocktake_scope'];
            if (!exp_st_scope_allowed($scope, $stSiteType)) continue;
            $qty = (float) $rawQty;
            if ($qty < 0) continue; // negative qty not accepted

            // Cage SKUs (stocktake_scope='cage'): operator enters integer bottle count.
            // units_per_pack=1 post-redenomination — store qty as-entered (bottles).
            // Negative already rejected above.

            $stValidLines[] = ['sku_id' => $sid, 'qty' => $qty, 'sku_code' => $allowedSkuIds[$sid]['sku_code']];
        }
    }

    if (empty($stErrors) && empty($stValidLines)) {
        $stErrors[] = 'Aucune quantité saisie — au moins un SKU est requis.';
    }

    if (!empty($stErrors)) {
        flash_set('err', implode(' — ', $stErrors));
        $locQs = $stLocId > 0 ? '&loc=' . $stLocId : '';
        redirect_to('/modules/expeditions.php?view=stocktake' . $locQs);
    }

    // ── Derive month_closed from counted_at ───────────────────────────────
    $stMonthClosed = substr($stCountedAt, 0, 7); // 'YYYY-MM'
    $stLocName     = $allowedLocationIds[$stLocId]['name'];

    // ── Guardrail: operators cannot edit sealed months or month_end rows ──
    if (!$canBackdate && $stCountedAt !== date('Y-m-d')) {
        if (exp_st_month_is_sealed($pdo, $stMonthClosed)) {
            flash_set('err', 'Mois clôturé — contactez la finance pour corriger cet inventaire.');
            redirect_to('/modules/expeditions.php?view=stocktake&loc=' . $stLocId);
        }
        if (exp_st_has_month_end_row($pdo, $stLocId, $stCountedAt)) {
            flash_set('err', 'Cet inventaire est un inventaire de clôture mensuelle — modification réservée à la gestion.');
            redirect_to('/modules/expeditions.php?view=stocktake&loc=' . $stLocId);
        }
    }
    // Operators cannot set count_type to month_end
    if (!$canBackdate) {
        $stCountType = 'operational';
    }

    // ── COGS/seal acknowledge gate for managers on month_end ─────────────
    // Managers may correct sealed months but must explicitly acknowledge the
    // COGS impact. Re-validates seal state even when seal_ack=1 is present.
    if ($canBackdate && $stCountType === 'month_end') {
        if (exp_st_month_is_sealed($pdo, $stMonthClosed)) {
            if (!isset($_POST['seal_ack'])) {
                flash_set('err', 'Ce mois est scellé. La correction NE modifiera PAS la fiche COGS scellée tant que le responsable financier ne la re-scelle pas.');
                $locQs  = $stLocId > 0 ? '&loc=' . $stLocId : '';
                $dateQs = ($stCountedAt !== date('Y-m-d')) ? '&date=' . urlencode($stCountedAt) : '';
                redirect_to('/modules/expeditions.php?view=stocktake' . $locQs . $dateQs . '&seal_ack_required=1');
            }
            // seal_ack=1 present — seal state confirmed above, proceed to upsert
        }
    }

    // ── Snapshot: dump current rows for this location to a JSON file ──────
    // Best-effort — log_revision gives the real audit trail; this is belt-and-suspenders.
    exp_st_snapshot($pdo, $stLocId);

    // ── Write transaction ─────────────────────────────────────────────────
    try {
        $pdo->beginTransaction();

        $upsertCount = 0;

        foreach ($stValidLines as $line) {
            $res = exp_st_do_upsert($pdo, $me, $stLocId, $stCountedAt, $stCountType, (int)$line['sku_id'], (float)$line['qty'], (string)$line['sku_code'], $stAuditNote);
            if ($res['ok']) {
                $upsertCount++;
            }
        }

        $pdo->commit();

        // Total HL summary for flash
        $totalHl = 0.0;
        foreach ($stValidLines as $line) {
            $totalHl += $line['qty'] * ($allowedSkuIds[$line['sku_id']]['hl_per_unit'] ?? 0.0);
        }
        $hlFormatted = number_format($totalHl, 2);

        flash_set('ok', 'Inventaire ' . $stLocName . ' enregistré — '
            . $upsertCount . ' SKU' . ($upsertCount !== 1 ? 's' : '') . ', '
            . $hlFormatted . ' HL au ' . exp_fmt_date($stCountedAt) . '.');
        redirect_to('/modules/expeditions.php?view=stocktake&loc=' . $stLocId);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[expeditions stocktake POST] ' . $e->getMessage());
        flash_set('err', 'Erreur lors de l\'enregistrement : ' . pdo_friendly_error($e));
        redirect_to('/modules/expeditions.php?view=stocktake&loc=' . $stLocId);
    }
}

// ── POST handler (Mouvements view — add_movement) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'mouvements') {

    // CSRF — must be first
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=mouvements');
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
        redirect_to('/modules/expeditions.php?view=mouvements');
    }

    $pdo    = maltytask_pdo();
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    $allowedMovActions = ['add_movement', 'tombstone_movement'];
    if (!in_array($action, $allowedMovActions, true)) {
        flash_set('err', 'Action non reconnue.');
        redirect_to('/modules/expeditions.php?view=mouvements');
    }

    // ── action: tombstone_movement (ADMIN ONLY) ───────────────────────────────
    if ($action === 'tombstone_movement') {
        // Defense in depth: re-check admin even if the button was hidden from non-admins.
        if (!is_admin($me)) {
            flash_set('err', 'Action réservée aux administrateurs.');
            redirect_to('/modules/expeditions.php?view=mouvements');
        }

        $movId = isset($_POST['movement_id']) ? (int) $_POST['movement_id'] : 0;
        if ($movId <= 0) {
            flash_set('err', 'Identifiant de mouvement invalide.');
            redirect_to('/modules/expeditions.php?view=mouvements');
        }

        try {
            $beforeMov = bd_fetch_before($pdo, 'inv_stock_movements', $movId);
            if ($beforeMov === null) {
                flash_set('err', 'Mouvement #' . $movId . ' introuvable.');
                redirect_to('/modules/expeditions.php?view=mouvements');
            }
            if ((int) $beforeMov['is_tombstoned'] === 1) {
                flash_set('err', 'Mouvement #' . $movId . ' déjà annulé.');
                redirect_to('/modules/expeditions.php?view=mouvements');
            }

            $pdo->beginTransaction();

            $updMov = $pdo->prepare(
                'UPDATE inv_stock_movements SET is_tombstoned = 1, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $updMov->execute([$movId]);

            log_revision($pdo, $me, 'inv_stock_movements', $movId, $beforeMov,
                ['is_tombstoned' => 1], 'normal',
                'Mouvement annulé (tombstone) par admin');

            $pdo->commit();
            flash_set('ok', 'Mouvement #' . $movId . ' annulé.');
            redirect_to('/modules/expeditions.php?view=mouvements');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[expeditions mouvements tombstone] ' . $e->getMessage());
            flash_set('err', 'Erreur : ' . pdo_friendly_error($e));
            redirect_to('/modules/expeditions.php?view=mouvements');
        }
    }

    // ── action: add_movement ──────────────────────────────────────────────────
    if ($action === 'add_movement') {

        // ── Build allowed-sets INSIDE the POST path ───────────────────────────
        // Active holds_fg_stock site IDs
        $movAllowedSiteIds = [];
        $movSiteRows = $pdo->query(
            'SELECT id FROM ref_sites WHERE holds_fg_stock = 1 AND is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($movSiteRows as $sid) {
            $movAllowedSiteIds[(int) $sid] = true;
        }

        // Active SKU IDs
        $movAllowedSkuIds = [];
        $movSkuRows = $pdo->query(
            'SELECT id FROM ref_skus WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($movSkuRows as $sid) {
            $movAllowedSkuIds[(int) $sid] = true;
        }

        // ── Read shared header params ─────────────────────────────────────────
        $movFromSiteId  = isset($_POST['from_site_id']) ? (int) $_POST['from_site_id']       : 0;
        $movToSiteId    = isset($_POST['to_site_id'])   ? (int) $_POST['to_site_id']         : 0;
        $movMovedOn     = isset($_POST['moved_on'])     ? trim((string) $_POST['moved_on'])  : '';
        $movComment     = isset($_POST['comment'])      ? trim((string) $_POST['comment'])   : '';

        // ── Read parallel line arrays ─────────────────────────────────────────
        $movLineSkuIds  = $_POST['mov_sku_id'] ?? [];
        $movLineQtys    = $_POST['mov_qty']    ?? [];

        $movErrors = [];

        // ── Header validation (once) ──────────────────────────────────────────
        if ($movFromSiteId <= 0 || !isset($movAllowedSiteIds[$movFromSiteId])) {
            $movErrors[] = 'Site d\'origine invalide.';
        }
        if ($movToSiteId <= 0 || !isset($movAllowedSiteIds[$movToSiteId])) {
            $movErrors[] = 'Site de destination invalide.';
        }
        if (empty($movErrors) && $movFromSiteId === $movToSiteId) {
            $movErrors[] = 'Le site d\'origine et le site de destination doivent être différents.';
        }
        if ($movMovedOn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $movMovedOn)) {
            $movErrors[] = 'Date de mouvement invalide (format AAAA-MM-JJ).';
        } elseif ($movMovedOn > date('Y-m-d')) {
            $movErrors[] = 'La date de mouvement ne peut pas être dans le futur.';
        } elseif ($movMovedOn < '2020-01-01') {
            $movErrors[] = 'Date de mouvement trop ancienne.';
        }

        // ── Line validation (independent per line) ────────────────────────────
        // Reject empty submit (zero lines).
        if (!is_array($movLineSkuIds) || count($movLineSkuIds) === 0) {
            $movErrors[] = 'Au moins une ligne SKU est requise.';
        }

        $movValidLines = [];
        if (empty($movErrors) && is_array($movLineSkuIds)) {
            foreach ($movLineSkuIds as $idx => $rawSkuId) {
                $lineNum   = (int) $idx + 1;
                $lineSkuId = (int) ($rawSkuId ?? 0);
                $lineQtyRaw = isset($movLineQtys[$idx]) ? trim((string) $movLineQtys[$idx]) : '';

                if ($lineSkuId <= 0) continue; // blank row — skip silently

                if (!isset($movAllowedSkuIds[$lineSkuId])) {
                    $movErrors[] = 'Ligne ' . $lineNum . ' : SKU invalide ou inactif.';
                    continue;
                }
                $lineQty = 0;
                if ($lineQtyRaw === '' || !preg_match('/^\d+$/', $lineQtyRaw) || ($lineQty = (int) $lineQtyRaw) <= 0) {
                    $movErrors[] = 'Ligne ' . $lineNum . ' : quantité doit être un entier > 0.';
                    continue;
                }
                $movValidLines[] = ['sku_id' => $lineSkuId, 'qty' => $lineQty];
            }
            if (empty($movErrors) && count($movValidLines) === 0) {
                $movErrors[] = 'Au moins une ligne SKU valide est requise.';
            }
        }

        if (!empty($movErrors)) {
            flash_set('err', implode(' — ', $movErrors));
            redirect_to('/modules/expeditions.php?view=mouvements');
        }

        // ── All-or-nothing transaction: N inserts + N log_revision calls ──────
        try {
            $pdo->beginTransaction();

            $insMov = $pdo->prepare(
                'INSERT INTO inv_stock_movements
                    (sku_id_fk, from_site_id_fk, to_site_id_fk, qty, moved_on, comment, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $insertedIds = [];
            foreach ($movValidLines as $line) {
                $insMov->execute([
                    $line['sku_id'],
                    $movFromSiteId,
                    $movToSiteId,
                    $line['qty'],
                    $movMovedOn,
                    $movComment !== '' ? $movComment : null,
                    (int) $me['id'],
                ]);
                $newMovId = (int) $pdo->lastInsertId();
                $insertedIds[] = $newMovId;

                // Per-row audit entry — one per inserted inv_stock_movements row.
                log_revision($pdo, $me, 'inv_stock_movements', $newMovId, null, [
                    'sku_id_fk'          => $line['sku_id'],
                    'from_site_id_fk'    => $movFromSiteId,
                    'to_site_id_fk'      => $movToSiteId,
                    'qty'                => $line['qty'],
                    'moved_on'           => $movMovedOn,
                    'comment'            => $movComment !== '' ? $movComment : null,
                    'created_by_user_id' => (int) $me['id'],
                ], 'normal', 'Mouvement inter-sites enregistré');
            }

            $pdo->commit();

            $n = count($insertedIds);
            flash_set('ok', $n . ' mouvement' . ($n > 1 ? 's' : '') . ' enregistré' . ($n > 1 ? 's' : '') . '.');
            redirect_to('/modules/expeditions.php?view=mouvements');

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[expeditions mouvements add] ' . $e->getMessage());
            flash_set('err', 'Erreur lors de l\'enregistrement : ' . pdo_friendly_error($e));
            redirect_to('/modules/expeditions.php?view=mouvements');
        }
    }
}

// ── Clients view: pagination + filter params ─────────────────────────────────
$clientsPage      = 1;
$clientsSearch    = '';
$clientsFilter    = 'active'; // 'active'|'review'|'inactive'|'nochannel'|'all'
$clientsPerPage   = 50;
$clientsTotalRows = 0;
$clientsRows      = [];
$clientsCrmRows   = []; // for the merge typeahead: CRM rows (bc_customer_no NOT NULL, is_active=1)
$clientsReviewCount = 0; // count of needs_review=1 rows

if ($view === 'clients') {
    $rawPage   = isset($_GET['page'])   ? (int) $_GET['page']         : 1;
    $rawSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $rawFilter = isset($_GET['filter']) ? (string) $_GET['filter']    : 'active';
    $allowedClientFilters = ['active', 'review', 'inactive', 'nochannel', 'all'];
    if (!in_array($rawFilter, $allowedClientFilters, true)) $rawFilter = 'active';
    $clientsPage   = max(1, $rawPage);
    $clientsSearch = $rawSearch;
    $clientsFilter = $rawFilter;
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

/** French weekday abbreviation ("lun.", "mar.", …) for a date.
 *  Top-level (compile-time hoisted) so it is available to every call site
 *  regardless of render order — previously nested inside the stocktake view,
 *  which made it undefined for the earlier "✓ compté" fresh-chip call. */
function exp_dow_fr(DateTimeImmutable $d): string
{
    static $days = ['dim.','lun.','mar.','mer.','jeu.','ven.','sam.'];
    return $days[(int) $d->format('w')];
}

// ---- Determine mode and period bounds ---------------------------------------
$cmdMode = 'week'; // 'week' | 'range'
$cmdKw   = ''; // "YYYY-Wnn" — only for mode=week
$cmdDu   = ''; // 'YYYY-MM-DD'
$cmdAu   = ''; // 'YYYY-MM-DD'
$cmdRangeNotice = ''; // non-empty when range was clamped
$kwPrev  = '';
$kwNext  = '';
// Filter params (commandes + shopify views share date/client; commandes has extra status/channel)
$filterClient  = '';
$filterSku     = '';
$filterStatus  = '';
$filterChannel = '';
$findIntent    = false; // true when cross-date search active (client name search)
$cmdFindLimited = false; // true when find-intent result was capped at 500

if ($view === 'commandes' || $view === 'shopify') {
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

    // Client filter — shared by both commandes and shopify (tab-local search)
    $filterClient = isset($_GET['client']) ? trim((string) $_GET['client']) : '';

    // Find-intent: when true, the date BETWEEN clause is dropped and the query spans ALL dates.
    // On both tabs: triggered by non-empty client name search.
    $findIntent = ($filterClient !== '');

    if ($view === 'commandes') {
        // ---- Commandes-only filter params (GET, sanitised) ------------------
        $filterSku     = isset($_GET['sku'])    ? strtoupper(trim((string) $_GET['sku'])) : '';
        $filterStatus  = isset($_GET['statut']) ? (string) $_GET['statut']  : '';
        $filterChannel = isset($_GET['canal'])  ? (string) $_GET['canal']   : '';

        // Validate filter enums against whitelist
        // 'expediees' = cross-date shortcut for all shipped orders (alias for status=shipped, spans all dates)
        $allowedStatutFilters  = ['ouvertes', 'expediees', 'entered', 'confirmed', 'picked', 'bl_printed', 'shipped', 'cancelled'];
        $allowedChannelFilters = ['on_trade', 'off_trade', 'interne'];
        if (!in_array($filterStatus, $allowedStatutFilters, true))  $filterStatus  = '';
        if (!in_array($filterChannel, $allowedChannelFilters, true)) $filterChannel = '';

        // Commandes also enters find-intent for cross-date status shortcuts
        $findIntent = $findIntent
                   || ($filterStatus === 'ouvertes')
                   || ($filterStatus === 'expediees');
    }

    // ---- ISO week nav: prev/next --------------------------------------------
    if ($cmdMode === 'week') {
        $dto     = new DateTimeImmutable($cmdDu);
        $kwPrev  = exp_date_to_isoweek($dto->modify('-7 days')->format('Y-m-d'));
        $kwNext  = exp_date_to_isoweek($dto->modify('+7 days')->format('Y-m-d'));
    }
}

// ── Historique view: week/range filter — reuses the same ISO helpers and
//    $cmdMode/$cmdKw/$cmdDu/$cmdAu/$kwPrev/$kwNext variables ──────────────────
// Default: current ISO week, stepping back through historical data.
// Cutover cap: all data is < 2026-06-08 (enforced by the view itself).
if ($view === 'historique') {
    $rawMode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'week';
    $cmdMode = in_array($rawMode, ['week', 'range'], true) ? $rawMode : 'week';

    if ($cmdMode === 'range') {
        $rawDu = isset($_GET['du']) ? trim((string) $_GET['du']) : '';
        $rawAu = isset($_GET['au']) ? trim((string) $_GET['au']) : '';
        $parseDu = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDu) && $rawDu >= '2020-01-01') ? $rawDu : date('Y-m-d');
        $parseAu = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawAu) && $rawAu >= '2020-01-01') ? $rawAu : $parseDu;
        if ($parseAu < $parseDu) $parseAu = $parseDu;
        // Clamp to 92 days (≈ 13 weeks) — same policy as commandes
        $diffDays = (int) round((strtotime($parseAu) - strtotime($parseDu)) / 86400);
        if ($diffDays > 92) {
            $parseAu = date('Y-m-d', strtotime($parseDu . ' +92 days'));
            $cmdRangeNotice = 'Plage limitée à 92 jours.';
        }
        $cmdDu = $parseDu;
        $cmdAu = $parseAu;
    } else {
        // Default: most-recent week that has historical data (last week before cutover)
        $rawKw = isset($_GET['kw']) ? trim((string) $_GET['kw']) : '';
        if ($rawKw === '' || exp_parse_isoweek($rawKw) === null) {
            // Default to the week containing the cutover minus one (last BC week)
            $rawKw = exp_date_to_isoweek('2026-06-07');
        }
        $cmdKw = $rawKw;
        $parsed = exp_parse_isoweek($cmdKw);
        $cmdDu = $parsed[0];
        $cmdAu = $parsed[1];
    }

    if ($cmdMode === 'week') {
        $dto    = new DateTimeImmutable($cmdDu);
        $kwPrev = exp_date_to_isoweek($dto->modify('-7 days')->format('Y-m-d'));
        $kwNext = exp_date_to_isoweek($dto->modify('+7 days')->format('Y-m-d'));
    }
}

// ── POST: side-stock giveaway + tombstone (view=side-stock) ──────────────────
// NOT COGS — unit counts only. NOT sale_class (distinct from migs 300/301).
// Actions: record_giveaway, tombstone_ssl_row.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'side-stock') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=side-stock');
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
        redirect_to('/modules/expeditions.php?view=side-stock');
    }

    $pdo    = maltytask_pdo();
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $allowedSslActions = ['record_giveaway', 'tombstone_ssl_row'];
    if (!in_array($action, $allowedSslActions, true)) {
        flash_set('err', 'Action non reconnue.');
        redirect_to('/modules/expeditions.php?view=side-stock');
    }

    // ── action: tombstone_ssl_row (ADMIN ONLY) ───────────────────────────────
    if ($action === 'tombstone_ssl_row') {
        if (!is_admin($me)) {
            flash_set('err', 'Action réservée aux administrateurs.');
            redirect_to('/modules/expeditions.php?view=side-stock');
        }
        $sslId = isset($_POST['ssl_id']) ? (int) $_POST['ssl_id'] : 0;
        if ($sslId <= 0) {
            flash_set('err', 'Identifiant invalide.');
            redirect_to('/modules/expeditions.php?view=side-stock');
        }
        try {
            $rowBefTomb = bd_fetch_before($pdo, 'inv_side_stock_ledger', $sslId);
            if ($rowBefTomb === null) {
                flash_set('err', 'Ligne #' . $sslId . ' introuvable.');
                redirect_to('/modules/expeditions.php?view=side-stock');
            }
            if ((int) $rowBefTomb['is_tombstoned'] === 1) {
                flash_set('err', 'Ligne #' . $sslId . ' déjà annulée.');
                redirect_to('/modules/expeditions.php?view=side-stock');
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE inv_side_stock_ledger
                    SET is_tombstoned = 1, tombstoned_at = NOW(), accrual_key = NULL
                  WHERE id = ?'
            )->execute([$sslId]);
            log_revision($pdo, $me, 'inv_side_stock_ledger', $sslId, $rowBefTomb,
                ['is_tombstoned' => 1, 'tombstoned_at' => date('Y-m-d H:i:s'), 'accrual_key' => null],
                'normal', 'Tombstone side-stock par admin');
            $pdo->commit();
            flash_set('ok', 'Ligne #' . $sslId . ' annulée (tombstone).');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[expeditions ssl tombstone] ' . $e->getMessage());
            flash_set('err', 'Erreur : ' . pdo_friendly_error($e));
        }
        redirect_to('/modules/expeditions.php?view=side-stock');
    }

    // ── action: record_giveaway ──────────────────────────────────────────────
    if ($action === 'record_giveaway') {
        // Read with ?? '' FIRST (PHP 8 NULL safety), then validate.
        $sslSkuIdRaw  = $_POST['sku_id'] ?? '';
        $sslQtyRaw    = $_POST['qty_units'] ?? '';
        $sslNote      = trim((string)($_POST['note'] ?? ''));
        $sslYear      = date('Y');  // current fiscal year (giveaway draw)

        $sslSkuId = is_numeric($sslSkuIdRaw) && (int)$sslSkuIdRaw > 0
                      ? (int)$sslSkuIdRaw : 0;
        $sslQty   = is_numeric($sslQtyRaw) && (int)$sslQtyRaw > 0
                      ? (int)$sslQtyRaw : 0;

        if ($sslSkuId <= 0 || $sslQty <= 0) {
            flash_set('err', 'SKU et quantité requis (quantité > 0).');
            redirect_to('/modules/expeditions.php?view=side-stock');
        }

        // Validate sku_id against active ref_skus (whitelist — don't trust raw POST).
        $skuCheckRow = $pdo->prepare(
            'SELECT id FROM ref_skus WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $skuCheckRow->execute([$sslSkuId]);
        if ($skuCheckRow->fetch() === false) {
            flash_set('err', 'SKU invalide ou inactif.');
            redirect_to('/modules/expeditions.php?view=side-stock');
        }

        // Refuse-don't-NULL: check live balance before writing.
        $stBal = $pdo->prepare(
            'SELECT COALESCE(SUM(qty_units), 0) AS bal
               FROM inv_side_stock_ledger
              WHERE sku_id_fk = ? AND is_tombstoned = 0'
        );
        $stBal->execute([$sslSkuId]);
        $liveBal = (int) $stBal->fetchColumn();

        if ($sslQty > $liveBal) {
            flash_set('err',
                'Solde side-stock insuffisant pour ce giveaway '
                . '(disponible : ' . $liveBal . ' unité(s), demandé : ' . $sslQty . ').'
            );
            redirect_to('/modules/expeditions.php?view=side-stock');
        }

        try {
            $pdo->beginTransaction();
            $stGive = $pdo->prepare(
                'INSERT INTO inv_side_stock_ledger
                    (sku_id_fk, movement_type, qty_units, bd_packaging_id_fk,
                     fiscal_year, note, submitted_by_user_fk)
                 VALUES (?, \'giveaway\', ?, NULL, ?, ?, ?)'
            );
            $stGive->execute([
                $sslSkuId,
                -$sslQty,
                $sslYear,
                $sslNote !== '' ? $sslNote : null,
                (int) $me['id'],
            ]);
            $newSslId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'inv_side_stock_ledger', $newSslId, null,
                ['sku_id_fk' => $sslSkuId, 'movement_type' => 'giveaway',
                 'qty_units' => -$sslQty, 'fiscal_year' => $sslYear, 'note' => $sslNote],
                'normal', 'Giveaway side-stock enregistré');
            $pdo->commit();
            flash_set('ok', 'Giveaway de ' . $sslQty . ' unité(s) enregistré.');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[expeditions ssl giveaway] ' . $e->getMessage());
            flash_set('err', 'Erreur : ' . pdo_friendly_error($e));
        }
        redirect_to('/modules/expeditions.php?view=side-stock');
    }
}

// ── POST handler (Retours view) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'retours') {
    // Step 1: CSRF check
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/expeditions.php?view=retours');
    }

    if (!can_write_expeditions($me)) {
        flash_set('err', 'Accès en lecture seule — modifications non autorisées.');
        redirect_to('/modules/expeditions.php?view=retours');
    }

    $pdo = null;
    $pdo = maltytask_pdo();

    // Step 2: Read + validate bc_document_no (two-step: ?? default then validate)
    $bcDocNo = trim($_POST['bc_document_no'] ?? '');
    if ($bcDocNo === '') {
        flash_set('err', 'Numéro d\'avoir BC manquant.');
        redirect_to('/modules/expeditions.php?view=retours');
    }

    try {
        // Step 3: Re-validate against ledger (bc_document_no must exist as beer-SKU return)
        // and derive posting_date from ledger (never trust POST)
        $ledgerChkStmt = $pdo->prepare(
            'SELECT l.sku_id_fk, l.sku_code_raw, l.qty_signed, l.posting_date
               FROM inv_sales_ledger l
               JOIN ref_skus rs ON rs.id = l.sku_id_fk
              WHERE l.bc_document_no = ?
                AND l.doc_type IN (\'credit\', \'return_receipt\')
                AND l.qty_signed > 0
                AND rs.recipe_id IS NOT NULL
                AND l.sku_id_fk IS NOT NULL'
        );
        $ledgerChkStmt->execute([$bcDocNo]);
        $ledgerLines = $ledgerChkStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($ledgerLines)) {
            flash_set('err', 'Avoir ' . htmlspecialchars($bcDocNo) . ' introuvable dans le grand livre BC.');
            redirect_to('/modules/expeditions.php?view=retours');
        }

        // Derive posting_date from ledger (first row — all rows share same posting_date for a bc_document_no)
        $ledgerPostingDate = $ledgerLines[0]['posting_date'];

        // Build a map: sku_id_fk => {qty_signed, sku_code_raw} from ledger (server-authoritative)
        $ledgerMap = [];
        foreach ($ledgerLines as $ll) {
            $skuId = (int) $ll['sku_id_fk'];
            $ledgerMap[$skuId] = [
                'qty'          => (float) $ll['qty_signed'],
                'sku_code_raw' => (string) $ll['sku_code_raw'],
            ];
        }

        // Step 4: Idempotency check — does an ord_returns row already exist for this bc_document_no?
        $existStmt = $pdo->prepare(
            'SELECT id FROM ord_returns WHERE origin_bc_document_no = ? LIMIT 1'
        );
        $existStmt->execute([$bcDocNo]);
        $existingReturn = $existStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingReturn !== false) {
            // Check if ALL lines are already dispositioned
            $existingReturnId = (int) $existingReturn['id'];
            $existingLineStmt = $pdo->prepare(
                'SELECT sku_id_fk FROM ord_return_lines WHERE return_id_fk = ?'
            );
            $existingLineStmt->execute([$existingReturnId]);
            $dispositionedSkus = [];
            foreach ($existingLineStmt->fetchAll(PDO::FETCH_ASSOC) as $el) {
                $dispositionedSkus[(int) $el['sku_id_fk']] = true;
            }
            // Filter ledgerMap to only not-yet-dispositioned lines
            $ledgerMap = array_filter($ledgerMap, fn($_, $skuId) => !isset($dispositionedSkus[$skuId]), ARRAY_FILTER_USE_BOTH);
            if (empty($ledgerMap)) {
                flash_set('err', 'Avoir ' . htmlspecialchars($bcDocNo) . ' déjà entièrement traité.');
                redirect_to('/modules/expeditions.php?view=retours');
            }
        }

        // Step 5: Parse submitted dispositions — validate each line
        // POST fields: ret_sku_id[] (hidden), ret_disp[] (select), ret_line_comment[] (text)
        $retSkuIds       = array_values((array) ($_POST['ret_sku_id']       ?? []));
        $retDisps        = array_values((array) ($_POST['ret_disp']         ?? []));
        $retLineComments = array_values((array) ($_POST['ret_line_comment'] ?? []));

        $validLines = [];
        foreach ($retSkuIds as $idx => $rawSkuId) {
            $skuId      = (int) $rawSkuId;
            $disp       = trim($retDisps[$idx] ?? '');
            $lineComment= trim($retLineComments[$idx] ?? '');
            $lineComment= $lineComment !== '' ? mb_substr($lineComment, 0, 500) : null;

            // Must exist in ledger (server-authoritative whitelist)
            if (!isset($ledgerMap[$skuId])) continue;
            // Validate disposition
            if (!in_array($disp, ['restock', 'scrap', 'quarantine', 'rebate'], true)) continue;

            $validLines[] = [
                'sku_id_fk'             => $skuId,
                'qty'                   => $ledgerMap[$skuId]['qty'],   // from ledger — never POST
                'disposition'           => $disp,
                'line_comment'          => $lineComment,
                'origin_ledger_sku_code'=> $ledgerMap[$skuId]['sku_code_raw'],
            ];
        }

        if (empty($validLines)) {
            flash_set('err', 'Aucune ligne valide soumise.');
            redirect_to('/modules/expeditions.php?view=retours');
        }

        $pdo->beginTransaction();

        // Step 6a: Insert ord_returns header (or reuse existing)
        if ($existingReturn !== false) {
            $newReturnId = (int) $existingReturn['id'];
        } else {
            $insRetStmt = $pdo->prepare(
                'INSERT INTO ord_returns
                    (origin_bc_document_no, origin_posting_date, returned_on, comment, created_by_user_id)
                 VALUES (?, ?, ?, NULL, ?)'
            );
            $insRetStmt->execute([$bcDocNo, $ledgerPostingDate, $ledgerPostingDate, (int) $me['id']]);
            $newReturnId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ord_returns', $newReturnId, null, [
                'origin_bc_document_no' => $bcDocNo,
                'origin_posting_date'   => $ledgerPostingDate,
                'returned_on'           => $ledgerPostingDate,
                'created_by_user_id'    => (int) $me['id'],
            ], 'normal');
        }

        // Step 6b: Insert ord_return_lines
        $insLineStmt = $pdo->prepare(
            'INSERT INTO ord_return_lines
                (return_id_fk, sku_id_fk, qty, disposition, line_comment, origin_ledger_sku_code)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($validLines as $vl) {
            $insLineStmt->execute([
                $newReturnId,
                $vl['sku_id_fk'],
                $vl['qty'],
                $vl['disposition'],
                $vl['line_comment'],
                $vl['origin_ledger_sku_code'],
            ]);
            $newLineId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ord_return_lines', $newLineId, null, [
                'return_id_fk'              => $newReturnId,
                'sku_id_fk'                 => $vl['sku_id_fk'],
                'qty'                       => $vl['qty'],
                'disposition'               => $vl['disposition'],
                'origin_ledger_sku_code'    => $vl['origin_ledger_sku_code'],
            ], 'normal');
        }

        $pdo->commit();
        $lineCount = count($validLines);
        flash_set('ok', 'Retour ' . htmlspecialchars($bcDocNo) . ' traité (' . $lineCount . ' ligne' . ($lineCount !== 1 ? 's' : '') . ').');
        redirect_to('/modules/expeditions.php?view=retours');

    } catch (Throwable $e) {
        if ($pdo !== null && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[expeditions retours POST] ' . $e->getMessage());
        flash_set('err', 'Erreur lors de l\'enregistrement : ' . pdo_friendly_error($e));
        redirect_to('/modules/expeditions.php?view=retours');
    }
}

// ── GET — load data ───────────────────────────────────────────────────────────
$pdo     = maltytask_pdo();
$loadErr = null;

// Data common to multiple views
$customers    = [];
$transporters = [];
$fgStockSites = [];
$skus         = [];

// Commandes view — orders + lines + eshop aggregates
$cmdOrders       = []; // header rows keyed by id
$cmdLines        = []; // keyed by order_id => [line, …]
$cmdByDay        = []; // keyed by date => [order_id, …]
$cmdEshopByDay   = []; // keyed by date => [channel => {orders, hl}] — taproom aggregate only
$cmdEshopOrdersByDay = []; // keyed by date => [eshop per-order rows] (Phase 1: eshop per-order)
$cmdEshopLinesByOrder= []; // keyed by inv_sales_orders.id => [line rows for pills]
$eshopLastImport = null; // DateTime|null — MAX(imported_at) for channel='eshop'
$cmdSummary      = [
    'total_hl' => 0.0, 'orders' => 0, 'open' => 0,
    'on_trade' => 0, 'off_trade' => 0, 'internal' => 0,
    'distinct_clients' => 0,
]; // totals for the B2B + taproom period band (eshop on Shopify tab)
$cmdFilteredCusts= []; // distinct customer names in result (for datalist)
$cmdFilteredSkus = []; // distinct SKU codes in result (for datalist)

// Saisie view (edit prefill)
$editOrder    = null;
$editLines    = [];

// Stock PF view
$fgStock            = null; // filled below if view=stock
$fgLocationSnapshot = null; // filled below if view=stock
$fgStockForCmds     = null; // filled below if view=commandes (for pinned summary)

// Inventaire FG multi-site view
$stSitesRows = []; // filled below if view=stocktake
$stAllSkus   = [];
$stPriorMap  = [];
$stLastCounted = [];

// Mouvements view
$movRows       = []; // filled below if view=mouvements
$movFgSites    = []; // holds_fg_stock sites for the form selects
$movSkuList    = []; // active SKUs for the form select

// Restes d'emballage (side-stock) view (mig 303) — NOT COGS, NOT sale_class
$sslBalanceRows = []; // per-SKU balance rows (non-zero balances)
$sslLedgerRows  = []; // recent ledger entries
$sslSkuDropdown = []; // active SKUs for the giveaway form select

// Historique BC view (mig 329) — read-only, no status chips, no CHF
// Populated only when view=historique.
$histWeekRows      = []; // rows from v_sales_ledger_weekly_client for the period
$histByWeek        = []; // [iso_yearweek => [row, ...]] keyed for week-blocks
$histLinesByKey    = []; // ["{iso_yearweek}:{customer_id_fk}" => [{sku_code,qty,hl}, ...]]
$histUnresolved    = []; // v_sales_ledger_unresolved rows for the footnote

// Retours view
$retPendingGroups = []; // bc_document_no => ['customer_name', 'posting_date', 'lines' => [[sku_id_fk, sku_code, format, qty_signed, sku_code_raw], ...]]
$retProcessed     = []; // last 30 dispositioned returns: [returned_on, origin_bc_document_no, customer_name, lines_summary]
$retSynth         = null; // returns_synthese() result; null if query failed or view != retours

try {
    // Active customers (for typeahead)
    $custStmt = $pdo->query(
        'SELECT id, name, bc_customer_no, trade_channel, default_transporter_id_fk,
                needs_review
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

    // holds_fg_stock sites for fulfilment site selector
    $fgSiteStmt = $pdo->query(
        'SELECT id, name FROM ref_sites
          WHERE holds_fg_stock = 1 AND is_active = 1
          ORDER BY sort_order ASC, id ASC'
    );
    $fgStockSites = $fgSiteStmt->fetchAll(PDO::FETCH_ASSOC);

    // Site id → name map for fulfilment chip rendering
    $fgSiteNameMap = [];
    foreach ($fgStockSites as $fs) {
        $fgSiteNameMap[(int)$fs['id']] = $fs['name'];
    }

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
        // Build WHERE dynamically (all via bound params — no interpolation).
        // When $findIntent is true the date BETWEEN clause is omitted so the
        // query spans all dates; sort is DESC (most-recent first) for cross-date
        // results, ASC (chronological) for normal week/range browse.
        $where  = [];
        $params = [];

        if (!$findIntent) {
            // Browse mode: restrict to the selected week or range
            $where[]  = 'o.requested_date BETWEEN ? AND ?';
            $params[] = $cmdDu;
            $params[] = $cmdAu;
        }

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

        // Filter: statut
        // 'ouvertes' = cross-date: all not-shipped/not-cancelled orders
        // 'expediees' = cross-date alias for shipped (past orders, most-recent first)
        // specific status = exact match
        if ($filterStatus === 'ouvertes') {
            $where[] = "o.status NOT IN ('shipped', 'cancelled')";
        } elseif ($filterStatus === 'expediees') {
            $where[] = "o.status = 'shipped'";
        } elseif ($filterStatus !== '') {
            $where[] = 'o.status = ?';
            $params[] = $filterStatus;
        }

        $whereSQL  = !empty($where) ? implode(' AND ', $where) : '1=1';
        $orderDir  = $findIntent ? 'DESC' : 'ASC';
        $limitSQL  = $findIntent ? 'LIMIT 500' : '';
        $ordStmt  = $pdo->prepare(
            "SELECT o.id, o.order_type, o.internal_channel, o.requested_date,
                    o.status, o.comment, o.created_at,
                    o.source,
                    o.bc_completely_shipped,
                    o.external_document_no,
                    o.divergence_status,
                    o.order_created_date,
                    o.fulfilment_site_id_fk,
                    c.name AS customer_name, c.trade_channel,
                    c.default_delivery_site_id_fk AS customer_default_site_id,
                    t.name AS transporter_name
               FROM ord_orders o
               LEFT JOIN ref_customers  c ON c.id = o.customer_id_fk
               LEFT JOIN ref_transporters t ON t.id = o.transporter_id_fk
              WHERE {$whereSQL}
              ORDER BY o.requested_date {$orderDir}, o.id {$orderDir}
              {$limitSQL}"
        );
        $ordStmt->execute($params);
        $allOrders = $ordStmt->fetchAll(PDO::FETCH_ASSOC);

        // Lead-time badge thresholds (hors-process flag)
        $leadCritical = (float) system_setting('order_lead_time_critical_days', 'fulfilment', 1);
        $leadWarn     = (float) system_setting('order_lead_time_warn_days', 'fulfilment', 2);

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
        // For find-intent we want DESC (most-recent day first)
        if ($findIntent) {
            krsort($cmdByDay);
        } else {
            ksort($cmdByDay);
        }
        // Track whether the result was hard-limited (surfaced in UI)
        $cmdFindLimited = $findIntent && (count($allOrders) >= 500);

        // ── Query 2: lines for all matched orders (no N+1) ───────────────────
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $lineStmt = $pdo->prepare(
                "SELECT l.order_id_fk, l.sku_id_fk, l.qty,
                        s.sku_code, s.format, s.hl_per_unit,
                        l.line_status
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

        // ── Query 3c: taproom aggregate (kept as-is for non-eshop channels) ──
        // Skipped in find-intent mode (taproom has no per-order display yet).
        if (!$findIntent) {
            $tapStmt = $pdo->prepare(
                "SELECT DATE(iso.created_at) AS sale_date,
                        iso.channel,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(isol.hl_resolved), 0) AS total_hl
                   FROM inv_sales_orders iso
                   LEFT JOIN inv_sales_order_lines isol ON isol.order_id_fk = iso.id
                  WHERE DATE(iso.created_at) BETWEEN ? AND ?
                    AND iso.channel != 'eshop'
                  GROUP BY DATE(iso.created_at), iso.channel"
            );
            $tapStmt->execute([$cmdDu, $cmdAu]);
            foreach ($tapStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                $d = (string) $er['sale_date'];
                if (!isset($cmdEshopByDay[$d])) $cmdEshopByDay[$d] = [];
                $cmdEshopByDay[$d][$er['channel']] = [
                    'orders' => (int) $er['order_count'],
                    'hl'     => (float) $er['total_hl'],
                ];
            }
        }

        // ── Period summary (B2B + taproom only — eshop is on the Shopify tab) ─
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

    if ($view === 'shopify') {
        // ── Shopify tab: eshop per-order rows (Query 3a) ─────────────────────
        // READ-ONLY view of inv_sales_orders WHERE channel='eshop'. Never writes
        // to ord_orders, never calls fg_stock_compute / depletion legs.
        {
            $eshopWhere  = ["iso.channel = 'eshop'"];
            $eshopParams = [];
            if (!$findIntent) {
                $eshopWhere[]  = 'COALESCE(iso.fulfilment_date, DATE(iso.created_at)) BETWEEN ? AND ?';
                $eshopParams[] = $cmdDu;
                $eshopParams[] = $cmdAu;
            } else {
                // In find-intent mode apply the client-name filter
                if ($filterClient !== '') {
                    $eshopWhere[]  = "(iso.customer_first_name LIKE ? OR iso.customer_last_name LIKE ? OR iso.order_name LIKE ? OR CONCAT(iso.customer_first_name,' ',iso.customer_last_name) LIKE ?)";
                    $likeVal = '%' . str_replace(['%', '_'], ['\%', '\_'], $filterClient) . '%';
                    $eshopParams[] = $likeVal;
                    $eshopParams[] = $likeVal;
                    $eshopParams[] = $likeVal;
                    $eshopParams[] = $likeVal;
                }
            }
            $eshopOrderSql = 'SELECT iso.id, iso.order_name, COALESCE(iso.fulfilment_date, DATE(iso.created_at)) AS sale_date,
                        iso.customer_first_name, iso.customer_last_name, iso.customer_email,
                        iso.fulfilment_mode, iso.financial_status, iso.fulfillment_status,
                        iso.channel, iso.created_at, iso.fulfilment_date, iso.fulfilment_date_end,
                        f.status AS fulfil_status,
                        f.shopify_sync_state
                   FROM inv_sales_orders iso
                   LEFT JOIN inv_sales_fulfilment f ON f.order_id_fk = iso.id
                  WHERE ' . implode(' AND ', $eshopWhere) . '
                  ORDER BY COALESCE(iso.fulfilment_date, DATE(iso.created_at)) ' . ($findIntent ? 'DESC' : 'ASC');
            $eshopOrderStmt = $pdo->prepare($eshopOrderSql);
            $eshopOrderStmt->execute($eshopParams);
            $eshopOrders = $eshopOrderStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($eshopOrders)) {
                // Query 3b: lines for those eshop orders (2 queries, no N+1)
                $eshopIds = array_column($eshopOrders, 'id');
                $placeholders = implode(',', array_fill(0, count($eshopIds), '?'));
                $eshopLineStmt = $pdo->prepare(
                    "SELECT isol.order_id_fk, isol.sku_code, isol.title, isol.qty, isol.hl_resolved,
                            s.format
                       FROM inv_sales_order_lines isol
                       LEFT JOIN ref_skus s ON s.id = isol.sku_id_fk
                      WHERE isol.order_id_fk IN ($placeholders)
                      ORDER BY isol.order_id_fk ASC, isol.line_index ASC"
                );
                $eshopLineStmt->execute($eshopIds);
                foreach ($eshopLineStmt->fetchAll(PDO::FETCH_ASSOC) as $el) {
                    $eid = (int) $el['order_id_fk'];
                    $cmdEshopLinesByOrder[$eid][] = $el;
                }

                // Group orders by date
                foreach ($eshopOrders as $eo) {
                    $d   = (string) $eo['sale_date'];
                    $eid = (int) $eo['id'];
                    $eoHl = 0.0;
                    foreach ($cmdEshopLinesByOrder[$eid] ?? [] as $el) {
                        $eoHl += (float) ($el['hl_resolved'] ?? 0);
                    }
                    $eo['total_hl'] = $eoHl;
                    $cmdEshopOrdersByDay[$d][] = $eo;
                }
            }
        }

        // ── Shopify freshness: MAX(imported_at) for channel='eshop' ──────────
        try {
            $fiStmt = $pdo->prepare(
                "SELECT MAX(imported_at) AS last_import FROM inv_sales_orders WHERE channel='eshop'"
            );
            $fiStmt->execute();
            $fiRow = $fiStmt->fetch(PDO::FETCH_ASSOC);
            if ($fiRow && $fiRow['last_import'] !== null) {
                $eshopLastImport = new DateTime($fiRow['last_import']);
            }
        } catch (Throwable $e) {
            error_log('[expeditions freshness] ' . $e->getMessage());
        }
    }

    if ($view === 'form' && $editId > 0) {
        $ordRow = $pdo->prepare(
            'SELECT o.id, o.order_type, o.internal_channel, o.requested_date,
                    o.status, o.comment, o.customer_id_fk, o.transporter_id_fk,
                    o.fulfilment_site_id_fk,
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
                'SELECT l.id, l.sku_id_fk, l.qty, l.line_comment, l.line_status,
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

    if ($view === 'stock') {
        $fgStock            = fg_stock_compute($pdo);
        $fgLocationSnapshot = fg_stock_location_snapshot($pdo);

        // ── "Activité récente" timeline: 3 bulk queries (one per event type) ────
        // Pattern: bulk-load all SKUs via ROW_NUMBER window + 90-day cap, bucket
        // into $timelineBySkuId, merge/sort/cap at render time. 3 queries total,
        // regardless of SKU count (no per-SKU WHERE, no AJAX).
        $timelineBySkuId = []; // [sku_id_fk => [{date, type, label, qty, sign}]]

        // ── Query TL-1: customer orders (shipped + open, excl. cancelled) ────────
        // order_type='customer' excludes internal channels (taproom/eshop/cage/shop)
        // which are booked separately in the ledger above.
        // Aggregate SUM(l.qty) per order so multi-line orders collapse to one event.
        $tlOrderStmt = $pdo->prepare(
            'SELECT l.sku_id_fk,
                    o.requested_date AS evt_date,
                    COALESCE(NULLIF(c.name, \'\'), \'—\') AS client_name,
                    SUM(l.qty) AS qty
               FROM (
                   SELECT sku_id_fk,
                          order_id_fk,
                          SUM(qty) AS qty,
                          ROW_NUMBER() OVER (PARTITION BY sku_id_fk ORDER BY order_id_fk DESC) AS rn
                     FROM ord_order_lines
                    GROUP BY sku_id_fk, order_id_fk
               ) l
               JOIN ord_orders o
                 ON o.id = l.order_id_fk
                AND o.status <> \'cancelled\'
                AND o.order_type = \'customer\'
                AND o.requested_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
                AND o.requested_date <= CURDATE()
               LEFT JOIN ref_customers c ON c.id = o.customer_id_fk
              WHERE l.rn <= 50
              GROUP BY l.sku_id_fk, o.id, o.requested_date, c.name
              ORDER BY l.sku_id_fk ASC, o.requested_date DESC'
        );
        $tlOrderStmt->execute();
        foreach ($tlOrderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) $row['sku_id_fk'];
            if (!isset($timelineBySkuId[$sid])) $timelineBySkuId[$sid] = [];
            $timelineBySkuId[$sid][] = [
                'date'  => (string) $row['evt_date'],
                'type'  => 'order',
                'label' => $row['client_name'],
                'qty'   => (int) round((float) $row['qty']),
                'sign'  => 'out',
            ];
        }

        // ── Query TL-2: packaging / conditionnement resupply runs ─────────────
        // Mirrors fg_prod_since_anchor() predicates EXACTLY:
        //   p.is_tombstoned = 0
        //   p.is_white_label = 0
        //   p.sku_id_fk IS NOT NULL
        //   p.run_type <> 'cuv'      ← LOAD-BEARING: cuv SKUs are excluded from
        //                               physique ledger; showing resupply for them
        //                               here would create a visible contradiction.
        // INTENDED DIVERGENCE (vs fg_prod_since_anchor):
        //   The anchor-cutoff clause (event_date > COALESCE(pa.prod_anchor, globalAnchor))
        //   is dropped here. The timeline shows ALL recent runs in the 90-day window,
        //   not only those after the stocktake anchor. This is correct for a history
        //   panel — operators want to see actual recent production activity.
        // Qty formula mirrors fg_prod_since_anchor FLOOR-per-event logic.
        $tlPkgStmt = $pdo->prepare(
            'SELECT p.sku_id_fk,
                    p.event_date AS evt_date,
                    FLOOR(p.prod_total_units / COALESCE(NULLIF(r.units_per_pack, 0), 1)) AS qty
               FROM (
                   SELECT sku_id_fk,
                          event_date,
                          prod_total_units,
                          ROW_NUMBER() OVER (PARTITION BY sku_id_fk ORDER BY event_date DESC, id DESC) AS rn
                     FROM bd_packaging_v2
                    WHERE is_tombstoned = 0
                      AND is_white_label = 0
                      AND sku_id_fk IS NOT NULL
                      AND run_type <> \'cuv\'
                      AND event_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
                      AND event_date <= CURDATE()
               ) p
               JOIN ref_skus r ON r.id = p.sku_id_fk
              WHERE p.rn <= 50
              ORDER BY p.sku_id_fk ASC, p.event_date DESC'
        );
        $tlPkgStmt->execute();
        foreach ($tlPkgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) $row['sku_id_fk'];
            if (!isset($timelineBySkuId[$sid])) $timelineBySkuId[$sid] = [];
            $timelineBySkuId[$sid][] = [
                'date'  => (string) $row['evt_date'],
                'type'  => 'pkg',
                'label' => 'Conditionnement',
                'qty'   => (int) $row['qty'],
                'sign'  => 'in',
            ];
        }

        // ── Query TL-3: stock movements ───────────────────────────────────────
        // m.is_tombstoned = 0, date window on m.moved_on.
        // label = "{from_site} → {to_site}". sign='neutral' (↔, no +/−).
        $tlMoveStmt = $pdo->prepare(
            'SELECT m.sku_id_fk,
                    m.moved_on AS evt_date,
                    m.qty,
                    COALESCE(sf.name, \'?\') AS from_name,
                    COALESCE(st.name, \'?\') AS to_name
               FROM (
                   SELECT sku_id_fk, moved_on, qty, from_site_id_fk, to_site_id_fk,
                          ROW_NUMBER() OVER (PARTITION BY sku_id_fk ORDER BY moved_on DESC, id DESC) AS rn
                     FROM inv_stock_movements
                    WHERE is_tombstoned = 0
                      AND moved_on >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
                      AND moved_on <= CURDATE()
               ) m
               LEFT JOIN ref_sites sf ON sf.id = m.from_site_id_fk
               LEFT JOIN ref_sites st ON st.id = m.to_site_id_fk
              WHERE m.rn <= 50
              ORDER BY m.sku_id_fk ASC, m.moved_on DESC'
        );
        $tlMoveStmt->execute();
        foreach ($tlMoveStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) $row['sku_id_fk'];
            if (!isset($timelineBySkuId[$sid])) $timelineBySkuId[$sid] = [];
            $timelineBySkuId[$sid][] = [
                'date'  => (string) $row['evt_date'],
                'type'  => 'move',
                'label' => $row['from_name'] . ' → ' . $row['to_name'],
                'qty'   => (int) round((float) $row['qty']),
                'sign'  => 'neutral',
            ];
        }
    }

    if ($view === 'stocktake') {
        // ── Load the 4 holds_fg_stock sites ──────────────────────────────────
        $stSitesRows = $pdo->query(
            'SELECT id, name, site_type, sort_order, customer_id_fk, notes
               FROM ref_sites
              WHERE holds_fg_stock = 1 AND is_active = 1
              ORDER BY sort_order ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // ── Active SKUs ordered by format family then code ────────────────────
        // hl_per_unit on cage SKUs = 0.00330 (per bottle, post-redenomination).
        // stocktake_scope is fetched for per-location visibility filtering.
        $stAllSkus = $pdo->query(
            'SELECT s.id, s.sku_code, s.format, s.hl_per_unit, s.units_per_pack, s.stocktake_scope,
                    COALESCE(f.display_family, s.format) AS display_family
               FROM ref_skus s
               LEFT JOIN ref_packaging_formats f ON s.format_id = f.id
              WHERE s.is_active = 1
              ORDER BY display_family ASC, s.sku_code ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // ── Most-recent prior count per (sku_id, location_id) for all locations ─
        // One query: latest row per sku_id_fk × location_id_fk using correlated MAX(id).
        $stPriorStmt = $pdo->query(
            'SELECT t1.sku_id_fk, t1.location_id_fk, t1.qty, t1.counted_at, t1.month_closed
               FROM inv_fg_stocktake t1
              WHERE t1.is_active = 1
                AND t1.id = (
                    SELECT MAX(t2.id)
                      FROM inv_fg_stocktake t2
                     WHERE t2.sku_id_fk    = t1.sku_id_fk
                       AND t2.location_id_fk = t1.location_id_fk
                       AND t2.is_active = 1
                )'
        );
        // Build prior map: [location_id][sku_id] → {qty, counted_at, month_closed}
        $stPriorMap = []; // keyed location_id → sku_id → row
        foreach ($stPriorStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $lid = (int) $pr['location_id_fk'];
            $sid = (int) $pr['sku_id_fk'];
            if (!isset($stPriorMap[$lid])) $stPriorMap[$lid] = [];
            $stPriorMap[$lid][$sid] = [
                'qty'          => (float) $pr['qty'],
                'counted_at'   => $pr['counted_at'],
                'month_closed' => $pr['month_closed'],
            ];
        }

        // ── Monday-cadence freshness: MAX(counted_at) per location ─────────────
        $stFreshStmt = $pdo->query(
            'SELECT location_id_fk, MAX(counted_at) AS last_counted
               FROM inv_fg_stocktake
              WHERE is_active = 1
                AND counted_at IS NOT NULL
              GROUP BY location_id_fk'
        );
        $stLastCounted = []; // location_id → last_counted date string
        foreach ($stFreshStmt->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $stLastCounted[(int) $fr['location_id_fk']] = $fr['last_counted'];
        }
    }

    if ($view === 'clients') {
        // Count of needs_review rows (always, for the header badge)
        $rvCnt = $pdo->query('SELECT COUNT(*) FROM ref_customers WHERE needs_review = 1');
        $clientsReviewCount = (int) $rvCnt->fetchColumn();

        // CRM rows for merge typeahead (bc_customer_no NOT NULL, is_active=1)
        $crmStmt = $pdo->query(
            'SELECT id, name, bc_customer_no
               FROM ref_customers
              WHERE bc_customer_no IS NOT NULL AND is_active = 1
              ORDER BY name ASC'
        );
        $clientsCrmRows = $crmStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build WHERE for directory query
        $dirWhere  = [];
        $dirParams = [];
        if ($clientsSearch !== '') {
            $dirWhere[]  = '(c.name LIKE ? OR c.bc_customer_no LIKE ? OR c.city LIKE ?)';
            $likeVal     = '%' . $clientsSearch . '%';
            $dirParams[] = $likeVal;
            $dirParams[] = $likeVal;
            $dirParams[] = $likeVal;
        }
        if ($clientsFilter === 'active') {
            $dirWhere[] = 'c.is_active = 1 AND c.needs_review = 0';
        } elseif ($clientsFilter === 'review') {
            $dirWhere[] = 'c.needs_review = 1';
        } elseif ($clientsFilter === 'inactive') {
            $dirWhere[] = 'c.is_active = 0';
        } elseif ($clientsFilter === 'nochannel') {
            $dirWhere[] = 'c.is_active = 1 AND c.trade_channel IS NULL AND c.is_private = 0';
        }
        // 'all' → no extra filter

        $whereSql = !empty($dirWhere) ? ('WHERE ' . implode(' AND ', $dirWhere)) : '';

        // Count
        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ref_customers c {$whereSql}"
        );
        $cntStmt->execute($dirParams);
        $clientsTotalRows = (int) $cntStmt->fetchColumn();

        // Page bounds
        $totalPages     = max(1, (int) ceil($clientsTotalRows / $clientsPerPage));
        $clientsPage    = min($clientsPage, $totalPages);
        $offset         = ($clientsPage - 1) * $clientsPerPage;

        // Fetch page
        $dirStmt = $pdo->prepare(
            "SELECT c.id, c.name, c.bc_customer_no, c.trade_channel,
                    c.is_private, c.default_transporter_id_fk,
                    c.needs_review, c.is_active, c.notes,
                    c.email, c.city, c.canton,
                    c.is_serving_tank_client, c.serving_tank_count,
                    c.serving_tank_size_hl, c.serving_tank_budget_hl,
                    t.name AS transporter_name
               FROM ref_customers c
               LEFT JOIN ref_transporters t ON t.id = c.default_transporter_id_fk
               {$whereSql}
              ORDER BY c.needs_review DESC, c.is_active DESC, c.name ASC
              LIMIT ? OFFSET ?"
        );
        $dirStmt->execute([...$dirParams, $clientsPerPage, $offset]);
        $clientsRows = $dirStmt->fetchAll(PDO::FETCH_ASSOC);

        // Réel du mois: actual Cuve-de-service HL consumed by each client in the current calendar month.
        // 1 ledger unit = 1 L → /100 = HL. qty_signed<0 = sales; returns positive excluded.
        // Computed local calendar month (app_timezone()).
        $servingTankRealMonth = [];
        try {
            $tzZ   = new DateTimeZone(app_timezone());
            $now   = new DateTime('now', $tzZ);
            $mstart = $now->format('Y-m-01');
            $mnext  = (new DateTime('first day of next month', $tzZ))->format('Y-m-d');
            $stRealStmt = $pdo->prepare(
                'SELECT l.customer_id_fk,
                        ROUND(SUM(ABS(l.qty_signed)) / 100, 2) AS hl_month
                   FROM inv_sales_ledger l
                   JOIN ref_skus s ON s.id = l.sku_id_fk AND s.format = \'Cuve de service\'
                  WHERE l.qty_signed < 0
                    AND l.posting_date >= :mstart
                    AND l.posting_date <  :mnext
                  GROUP BY l.customer_id_fk'
            );
            $stRealStmt->execute([':mstart' => $mstart, ':mnext' => $mnext]);
            foreach ($stRealStmt->fetchAll(PDO::FETCH_ASSOC) as $stRow) {
                $servingTankRealMonth[(int) $stRow['customer_id_fk']] = (float) $stRow['hl_month'];
            }
        } catch (Throwable $stEx) {
            // Non-fatal: réel display falls back to 0 if the query fails
            error_log('[expeditions serving_tank_real] ' . $stEx->getMessage());
        }
    }

    if ($view === 'side-stock') {
        // ── Per-SKU balance (live, non-tombstoned) ────────────────────────────
        // Groups by SKU with display_family ordering (mirrors stock/mouvements pattern).
        // NOT COGS — unit counts only. Out of fg_stock_compute FG legs.
        $sslBalanceRows = $pdo->query(
            'SELECT s.id AS sku_id, s.sku_code, s.format, s.units_per_pack,
                    COALESCE(pf.display_family, s.format, s.sku_code) AS display_family,
                    COALESCE(SUM(l.qty_units), 0) AS balance_units
               FROM ref_skus s
               LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
               LEFT JOIN inv_side_stock_ledger  l
                      ON l.sku_id_fk = s.id AND l.is_tombstoned = 0
              WHERE s.is_active = 1
                AND s.stocktake_scope != \'cage\'
              GROUP BY s.id, s.sku_code, s.format, s.units_per_pack,
                       COALESCE(pf.display_family, s.format, s.sku_code)
             HAVING balance_units != 0
              ORDER BY COALESCE(pf.display_family, s.format, s.sku_code) ASC,
                       s.sku_code ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // ── Recent ledger rows (last 150) ─────────────────────────────────────
        // For giveaway tombstone: tombstoned rows shown last.
        $sslLedgerRows = $pdo->query(
            'SELECT l.id, l.sku_id_fk, l.movement_type, l.qty_units,
                    l.bd_packaging_id_fk, l.fiscal_year, l.note,
                    l.created_at, l.is_tombstoned, l.tombstoned_at,
                    s.sku_code,
                    COALESCE(pf.display_family, s.format, s.sku_code) AS display_family,
                    u.display_name AS submitted_by_name
               FROM inv_side_stock_ledger l
               JOIN ref_skus s ON s.id = l.sku_id_fk
               LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
               LEFT JOIN users u ON u.id = l.submitted_by_user_fk
              ORDER BY l.is_tombstoned ASC, l.id DESC
              LIMIT 150'
        )->fetchAll(PDO::FETCH_ASSOC);

        // ── Active SKU list for the giveaway form dropdown ────────────────────
        $sslSkuDropdown = $pdo->query(
            'SELECT s.id, s.sku_code,
                    COALESCE(pf.display_family, s.format, s.sku_code) AS display_family
               FROM ref_skus s
               LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
              WHERE s.is_active = 1 AND s.stocktake_scope != \'cage\'
              ORDER BY COALESCE(pf.display_family, s.format, s.sku_code) ASC,
                       s.sku_code ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Repack view: advisory eshop repacking proposals ──────────────────────
    // Calls repack_decompose_orders() for the chosen day (default today).
    // Loads existing logged events for the same day (advisory display only).
    // No writes here — writes go through /api/expeditions-repack.php.
    $rkpDate          = date('Y-m-d');
    if (isset($_GET['rkp_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['rkp_date'])) {
        $rkpDate = (string) $_GET['rkp_date'];
    }
    $rkpProposals         = [];
    $rkpLoggedEvents      = [];
    $rkpOrderMeta         = [];  // order_id => {id, fulfilment_mode, order_name}
    $rkpFlagLive          = false;
    if ($view === 'repack') {
        $rkpFlagLive  = repack_depletion_live();
        $rkpProposals = repack_decompose_orders($pdo, $rkpDate);

        // Group proposals by order_id; collect order metadata
        $rkpByOrder = []; // order_id => rows
        foreach ($rkpProposals as $pr) {
            $oid = (int) $pr['source_order_id'];
            if (!isset($rkpByOrder[$oid])) $rkpByOrder[$oid] = [];
            $rkpByOrder[$oid][] = $pr;
        }

        // Fetch order metadata (name + mode) for all order ids
        if (!empty($rkpByOrder)) {
            $oidList = array_keys($rkpByOrder);
            $oidPlaceholders = implode(',', array_fill(0, count($oidList), '?'));
            $rkpOrderMetaStmt = $pdo->prepare(
                "SELECT id, fulfilment_mode,
                        COALESCE(order_name, CONCAT('#', id)) AS order_name
                   FROM inv_sales_orders
                  WHERE id IN ($oidPlaceholders)"
            );
            $rkpOrderMetaStmt->execute($oidList);
            foreach ($rkpOrderMetaStmt->fetchAll(PDO::FETCH_ASSOC) as $om) {
                $rkpOrderMeta[(int) $om['id']] = $om;
            }
        }

        // Fetch logged events for the day (advisory: show what has already been logged)
        $rkpLoggedStmt = $pdo->prepare(
            "SELECT rk.id, rk.from_sku_id_fk, rk.from_qty, rk.to_sku_id_fk,
                    rk.to_qty, rk.loose_units, rk.to_kind, rk.source_order_id_fk,
                    rk.moved_on, rk.is_tombstoned, rk.repack_key, rk.created_at,
                    fs.sku_code AS from_sku_code,
                    COALESCE(ts.sku_code, '(loose)') AS to_sku_code,
                    u.display_name AS submitted_by_name
               FROM inv_repack_events rk
               JOIN ref_skus fs ON fs.id = rk.from_sku_id_fk
               LEFT JOIN ref_skus ts ON ts.id = rk.to_sku_id_fk
               LEFT JOIN users u  ON u.id = rk.submitted_by_user_fk
              WHERE rk.moved_on = ?
              ORDER BY rk.id ASC"
        );
        $rkpLoggedStmt->execute([$rkpDate]);
        $rkpLoggedEvents = $rkpLoggedStmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Assembly panel data ───────────────────────────────────────────────────
        // Load composite packs + their member beers + per-beer source SKUs with live physique.

        // 1. Composite pack list (only scope='base' packs enter physique when assembled)
        $rkpaPacksStmt = $pdo->query(
            "SELECT rs.id, rs.sku_code, rs.unit_label, rs.units_per_pack
               FROM ref_skus rs
              WHERE rs.is_active = 1
                AND rs.stocktake_scope = 'base'
                AND EXISTS (SELECT 1 FROM ref_sku_composite_slots cs WHERE cs.sku_id = rs.id)
              ORDER BY rs.id ASC"
        );
        $rkpaPacks = $rkpaPacksStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. For each pack, load slots + source SKUs
        $rkpaPackData = [];
        foreach ($rkpaPacks as $pk) {
            $pkId = (int) $pk['id'];

            $slotsStmt = $pdo->prepare(
                "SELECT cs.slot_order, cs.recipe_id, cs.units_per_recipe, rr.name AS beer_name
                   FROM ref_sku_composite_slots cs
                   JOIN ref_recipes rr ON rr.id = cs.recipe_id
                  WHERE cs.sku_id = ?
                  ORDER BY cs.slot_order ASC"
            );
            $slotsStmt->execute([$pkId]);
            $slots = $slotsStmt->fetchAll(PDO::FETCH_ASSOC);

            $allSourceSkuIds = [];
            $slotSources = [];
            foreach ($slots as $sl) {
                $srcStmt = $pdo->prepare(
                    "SELECT rs.id AS sku_id, rs.sku_code, rs.stocktake_scope, rs.units_per_pack, rs.unit_label
                       FROM ref_skus rs
                      WHERE rs.recipe_id = ?
                        AND rs.format = 'Bot'
                        AND rs.stocktake_scope IN ('cage','base','single')
                        AND rs.is_active = 1
                      ORDER BY FIELD(rs.stocktake_scope, 'cage', 'base', 'single'), rs.id ASC"
                );
                $srcStmt->execute([(int) $sl['recipe_id']]);
                $srcs = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
                $slotSources[(int) $sl['recipe_id']] = $srcs;
                foreach ($srcs as $s) { $allSourceSkuIds[] = (int) $s['sku_id']; }
            }

            // live physique for all source SKUs in this pack
            // fg_stock_for_skus() is already available (fg-stock.php is required earlier in expeditions.php)
            $stockResult = fg_stock_for_skus($pdo, array_unique($allSourceSkuIds));
            $stockBySkuId = [];
            foreach ($stockResult['rows'] as $sr) {
                $stockBySkuId[(int) $sr['sku_id']] = (int) round((float) $sr['physique']);
            }

            $memberBeers = [];
            foreach ($slots as $sl) {
                $recipeId = (int) $sl['recipe_id'];
                $srcs = $slotSources[$recipeId] ?? [];
                $srcsWithStock = [];
                foreach ($srcs as $s) {
                    $sid = (int) $s['sku_id'];
                    $srcsWithStock[] = [
                        'sku_id'         => $sid,
                        'sku_code'       => $s['sku_code'],
                        'scope'          => $s['stocktake_scope'],
                        'units_per_pack' => (float) $s['units_per_pack'],
                        'unit_label'     => $s['unit_label'],
                        'dispo'          => $stockBySkuId[$sid] ?? 0,
                    ];
                }
                $memberBeers[] = [
                    'slot_order'       => (int) $sl['slot_order'],
                    'beer_name'        => $sl['beer_name'],
                    'units_per_recipe' => (int) $sl['units_per_recipe'],
                    'recipe_id'        => $recipeId,
                    'sources'          => $srcsWithStock,
                ];
            }

            $rkpaPackData[] = [
                'pack_sku_id'  => $pkId,
                'sku_code'     => $pk['sku_code'],
                'unit_label'   => $pk['unit_label'],
                'member_beers' => $memberBeers,
            ];
        }

        // 3. Sites (holds_fg_stock=1)
        $rkpaSitesStmt = $pdo->query(
            "SELECT id, name FROM ref_sites WHERE holds_fg_stock = 1 AND is_active = 1 ORDER BY sort_order, id"
        );
        $rkpaSites = $rkpaSitesStmt->fetchAll(PDO::FETCH_ASSOC);

        $rkpaDefaultSiteId = 0;
        foreach ($rkpaSites as $st) {
            if (stripos((string) $st['name'], 'logistique') !== false) {
                $rkpaDefaultSiteId = (int) $st['id'];
                break;
            }
        }
        if ($rkpaDefaultSiteId === 0 && !empty($rkpaSites)) {
            $rkpaDefaultSiteId = (int) $rkpaSites[0]['id'];
        }

        $rkpaData = [
            'packs'           => $rkpaPackData,
            'sites'           => $rkpaSites,
            'default_site_id' => $rkpaDefaultSiteId,
            'today'           => $rkpDate,
        ];
    }

    // ── Historique view: per-week-per-client BC shipment history (mig 329) ────
    // Read-only. No writes, no status chips, no CHF. Source: v_sales_ledger_weekly_client
    // (shipment grain, resolved-FG, B2B scope, posting_date < 2026-06-08).
    // Queries:
    //   1. Weekly client rows for the selected period (week or range).
    //   2. Per-SKU lines for drill-down, keyed "{iso_yearweek}:{customer_id_fk}".
    //   3. Unresolved footnote total (always; one query, no filter).
    if ($view === 'historique') {

        // ── 1. Weekly aggregate rows for the period ───────────────────────────
        // Filter by iso_yearweek integer range (YEARWEEK mode 3) to stay consistent
        // with how the view buckets rows. Filtering by week_start DATE is unreliable
        // because STR_TO_DATE(CONCAT(YEARWEEK(d,3),' Monday'),'%X%V %W') can map the
        // same yearweek integer to the WRONG Monday in some MySQL builds — using the
        // integer directly bypasses that mismatch.
        // Rows sorted most-recent ISO week first, then by total_hl desc within each week.
        $histWeekStmt = $pdo->prepare(
            'SELECT iso_yearweek, iso_year, iso_week, week_start,
                    customer_id_fk, customer_name, trade_channel,
                    doc_count, total_units, total_hl
               FROM v_sales_ledger_weekly_client
              WHERE iso_yearweek BETWEEN YEARWEEK(?, 3) AND YEARWEEK(?, 3)
              ORDER BY iso_yearweek DESC, total_hl DESC'
        );
        $histWeekStmt->execute([$cmdDu, $cmdAu]);
        $histWeekRows = $histWeekStmt->fetchAll(PDO::FETCH_ASSOC);

        // Index by week for rendering; also collect yw set + cid set for drill pre-fetch
        $histVisibleKeys = []; // array of "iso_yearweek:customer_id_fk"
        $histYwSet       = []; // distinct iso_yearweek integers seen in this period
        foreach ($histWeekRows as $wr) {
            $yw  = (int) $wr['iso_yearweek'];
            $cid = (int) $wr['customer_id_fk'];
            if (!isset($histByWeek[$yw])) $histByWeek[$yw] = [];
            $histByWeek[$yw][] = $wr;
            $histVisibleKeys[] = (string) $yw . ':' . (string) $cid;
            $histYwSet[$yw]    = $yw;
        }
        $histYwSet = array_values($histYwSet); // dense int array for placeholders

        // ── 2. Per-SKU drill lines for all visible (week × client) pairs ──────
        // Filter by YEARWEEK integer IN (...) — exact same bucket logic as the view.
        // This guarantees drill key "{iso_yearweek}:{cid}" matches $histByWeek keys.
        if (!empty($histWeekRows)) {
            $histCidSet = array_unique(array_map('intval', array_column($histWeekRows, 'customer_id_fk')));
            $histCidPlaceholders = implode(',', array_fill(0, count($histCidSet), '?'));
            $histYwPlaceholders  = implode(',', array_fill(0, count($histYwSet),  '?'));

            $histLineStmt = $pdo->prepare(
                "SELECT YEARWEEK(l.posting_date, 3)   AS iso_yearweek,
                        l.customer_id_fk,
                        s.sku_code,
                        ROUND(-SUM(l.qty_signed))      AS qty,
                        ROUND(-SUM(l.hl_resolved),2)   AS hl
                   FROM inv_sales_ledger l
                   JOIN ref_skus s ON s.id = l.sku_id_fk
                   JOIN ref_customers c ON c.id = l.customer_id_fk
                  WHERE l.doc_type       = 'shipment'
                    AND l.sku_id_fk      IS NOT NULL
                    AND YEARWEEK(l.posting_date, 3) IN ($histYwPlaceholders)
                    AND l.customer_id_fk IN ($histCidPlaceholders)
                    AND c.sale_class NOT IN ('eshop','taproom','customs_artifact','transfer','sample')
                  GROUP BY iso_yearweek, l.customer_id_fk, s.sku_code
                  ORDER BY iso_yearweek DESC, l.customer_id_fk ASC, hl DESC"
            );
            $histLineStmt->execute([...$histYwSet, ...$histCidSet]);
            foreach ($histLineStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                $key = (string) $ln['iso_yearweek'] . ':' . (string) $ln['customer_id_fk'];
                if (!isset($histLinesByKey[$key])) $histLinesByKey[$key] = [];
                $histLinesByKey[$key][] = $ln;
            }
        }

        // ── 3. Unresolved footnote (always; summarised by sku_code_raw + yr) ──
        $histUnresolved = $pdo->query(
            'SELECT sku_code_raw, yr, line_count, units
               FROM v_sales_ledger_unresolved
              ORDER BY yr DESC, units DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($view === 'mouvements') {
        // holds_fg_stock sites (for form selects + list JOINs)
        $movFgSites = $pdo->query(
            'SELECT id, name FROM ref_sites
              WHERE holds_fg_stock = 1 AND is_active = 1
              ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Active SKUs (sku_code + label for the select)
        $movSkuList = $pdo->query(
            'SELECT s.id, s.sku_code, s.format,
                    COALESCE(pf.display_family, s.format, s.sku_code) AS display_family
               FROM ref_skus s
               LEFT JOIN ref_packaging_formats pf ON pf.id = s.format_id
              WHERE s.is_active = 1
              ORDER BY display_family ASC, s.sku_code ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Recent movements (last 100): non-tombstoned first, then tombstoned
        $movRows = $pdo->query(
            'SELECT m.id, m.sku_id_fk, m.from_site_id_fk, m.to_site_id_fk,
                    m.qty, m.moved_on, m.comment, m.is_tombstoned,
                    m.created_at, m.updated_at,
                    sk.sku_code,
                    sf.name AS from_site_name,
                    st.name AS to_site_name,
                    u.display_name AS created_by_name
               FROM inv_stock_movements m
               JOIN ref_skus  sk ON sk.id = m.sku_id_fk
               JOIN ref_sites sf ON sf.id = m.from_site_id_fk
               JOIN ref_sites st ON st.id = m.to_site_id_fk
               LEFT JOIN users u  ON u.id  = m.created_by_user_id
              ORDER BY m.is_tombstoned ASC, m.id DESC
              LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Retours view ─────────────────────────────────────────────────────────
    if ($view === 'retours') {
        // A. Pending queue: BC return lines awaiting disposition
        //    Group by bc_document_no; exclude already-dispositioned (bc_document_no+sku_id_fk pair in ord_return_lines)
        $pendingStmt = $pdo->prepare(
            'SELECT l.bc_document_no, l.posting_date, l.sku_id_fk, l.sku_code_raw, l.qty_signed,
                    rs.sku_code, rs.format,
                    COALESCE(c.name, l.sku_code_raw) AS customer_name
               FROM inv_sales_ledger l
               JOIN ref_skus rs ON rs.id = l.sku_id_fk
               LEFT JOIN ref_customers c ON c.id = l.customer_id_fk
              WHERE l.doc_type IN (\'credit\', \'return_receipt\')
                AND l.qty_signed > 0
                AND rs.recipe_id IS NOT NULL
                AND l.sku_id_fk IS NOT NULL
                AND l.posting_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM ord_returns r
                    JOIN ord_return_lines rl ON rl.return_id_fk = r.id
                    WHERE r.origin_bc_document_no = l.bc_document_no
                      AND rl.sku_id_fk = l.sku_id_fk
                )
              ORDER BY l.posting_date DESC, l.bc_document_no'
        );
        $pendingStmt->execute();
        $pendingRows = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by bc_document_no
        $retPendingGroups = [];
        foreach ($pendingRows as $pr) {
            $key = (string) $pr['bc_document_no'];
            if (!isset($retPendingGroups[$key])) {
                $retPendingGroups[$key] = [
                    'bc_document_no' => $key,
                    'posting_date'   => $pr['posting_date'],
                    'customer_name'  => $pr['customer_name'],
                    'lines'          => [],
                ];
            }
            $retPendingGroups[$key]['lines'][] = [
                'sku_id_fk'   => (int) $pr['sku_id_fk'],
                'sku_code'    => $pr['sku_code'],
                'sku_code_raw'=> $pr['sku_code_raw'],
                'format'      => $pr['format'] ?? '',
                'qty'         => (float) $pr['qty_signed'],
            ];
        }

        // B. Processed list: last 30 dispositioned returns
        $processedStmt = $pdo->query(
            'SELECT r.id, r.returned_on, r.origin_bc_document_no,
                    (SELECT COALESCE(MIN(c2.name), MIN(l2.sku_code_raw))
                       FROM inv_sales_ledger l2
                       LEFT JOIN ref_customers c2 ON c2.id = l2.customer_id_fk
                      WHERE l2.bc_document_no = r.origin_bc_document_no
                        AND l2.doc_type IN (\'credit\', \'return_receipt\')
                    ) AS customer_name,
                    GROUP_CONCAT(
                        CONCAT(s.sku_code, \'×\', CAST(rl.qty AS CHAR), \' [\', rl.disposition, \']\')
                        ORDER BY rl.id SEPARATOR \' · \'
                    ) AS lines_summary
               FROM ord_returns r
               LEFT JOIN ord_return_lines rl ON rl.return_id_fk = r.id
               LEFT JOIN ref_skus s ON s.id = rl.sku_id_fk
              WHERE r.origin_bc_document_no IS NOT NULL
              GROUP BY r.id, r.returned_on, r.origin_bc_document_no
              ORDER BY r.returned_on DESC, r.id DESC
              LIMIT 30'
        );
        $retProcessed = $processedStmt->fetchAll(PDO::FETCH_ASSOC);

        // C. Synthèse (90 j) — shared compute, single source of truth
        require_once __DIR__ . '/../../app/returns-synthese.php';
        $retSynth = returns_synthese($pdo, 90);
    }

} catch (Throwable $e) {
    $loadErr = $e->getMessage();
    error_log('[expeditions GET] ' . $e->getMessage());
}

// ── Build JSON payloads for JS (XSS-safe) ────────────────────────────────────
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

// Customer typeahead data + default_transporter_id_fk map (for JS auto-fill).
// Ranking: bc-linked (bc_customer_no NOT NULL) come first, needs_review rows last.
// rank: 0 = CRM-linked (bc_no set), 1 = normal active, 2 = needs_review
$expCustomers = array_map(fn($c) => [
    'id'            => (int) $c['id'],
    'name'          => $c['name'],
    'bc_no'         => $c['bc_customer_no'] ?? '',
    'channel'       => $c['trade_channel'] ?? '',
    'default_trans' => $c['default_transporter_id_fk']
                           ? (int) $c['default_transporter_id_fk'] : null,
    'rank'          => ($c['bc_customer_no'] !== null && $c['bc_customer_no'] !== '')
                           ? 0
                           : ((bool)($c['needs_review'] ?? 0) ? 2 : 1),
    'needs_review'  => (bool) ($c['needs_review'] ?? 0),
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

// Clients view: CRM rows for merge typeahead
$crmRowsJson = json_encode(array_map(fn($r) => [
    'id'    => (int) $r['id'],
    'name'  => $r['name'],
    'bc_no' => $r['bc_customer_no'] ?? '',
], $clientsCrmRows), $jsonFlags);
$editOrderJson    = $editOrder !== null
    ? json_encode($editOrder, $jsonFlags) : 'null';
$editLinesJson    = $editLines
    ? json_encode(array_map(fn($l) => [
        'line_id'     => (int) $l['id'],
        'sku_id'      => (int) $l['sku_id_fk'],
        'sku_code'    => $l['sku_code'],
        'format'      => $l['format'] ?? '',
        'hl_per_unit' => (float) $l['hl_per_unit'],
        'qty'         => (float) $l['qty'],
        'comment'     => $l['line_comment'] ?? '',
        'line_status' => $l['line_status'] ?? 'to_fulfil',
    ], $editLines), $jsonFlags) : '[]';

// ── Line-status data for expeditions-line-status.js ──────────────────────────
// Separate from EXP_EDIT_LINES to keep form JS and status JS decoupled.
$lineStatusDataJson = $editLines
    ? json_encode(array_map(fn($l) => [
        'line_id' => (int) $l['id'],
        'status'  => $l['line_status'] ?? 'to_fulfil',
    ], $editLines), $jsonFlags) : '[]';

// ── Live stock map for Saisie form (injected only when view=form) ─────────────
// Keys: sku_id (int) → {live_futur, physique, anchor_month}
// fg_stock_compute runs several queries; acceptable for operator-frequency form load.
// On failure the map stays empty — hints silently absent, form still works.
$fgStockMapJson   = '{}';
$fgStockAnchorJson = 'null';
if ($view === 'form') {
    try {
        $fgStockData = fg_stock_compute($pdo);
        $fgStockMap  = [];
        foreach ($fgStockData['rows'] as $sr) {
            $fgStockMap[(int) $sr['sku_id']] = [
                'live_futur' => (int) round($sr['live_futur']),
                'physique'   => (int) round($sr['physique']),
            ];
        }
        $fgStockMapJson    = json_encode($fgStockMap, $jsonFlags);
        $fgStockAnchorJson = json_encode($fgStockData['anchor_month'], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[expeditions form stock] ' . $e->getMessage());
        // Degrade silently — $fgStockMapJson stays '{}'
    }
}

// ── Live stock map for Commandes view — injected for per-order risk flags ────
// Reuses fg_stock_compute exactly like the form view. On failure map stays '{}'.
// Also powers the pinned summary strip ($fgStockForCmds, set here to avoid a
// second fg_stock_compute call on the commandes view).
// Also fetches fg_stock_location_snapshot for the home-site KPI in the strip.
$cmdStockMapJson     = '{}';
$cmdStockDetailJson  = '{}'; // per-order short-list: {oid: [{sku_code,requested,available,physique,short_by}]}
$fgLocationSnapshotForCmds = null; // per-location breakdown for commandes view home-site KPI
if ($view === 'commandes') {
    try {
        $fgStockData2 = fg_stock_compute($pdo);
        // Share with the pinned summary strip (avoids a duplicate call)
        $fgStockForCmds = $fgStockData2;
        // Location snapshot for home-site KPI in the strip (pure SELECT, operator-frequency)
        $fgLocationSnapshotForCmds = fg_stock_location_snapshot($pdo);
        $cmdStockMap  = [];
        foreach ($fgStockData2['rows'] as $sr) {
            $cmdStockMap[(int) $sr['sku_id']] = [
                'live_futur' => (int) round($sr['live_futur']),
                'physique'   => (int) round($sr['physique']),
            ];
        }
        $cmdStockMapJson = json_encode($cmdStockMap, $jsonFlags);
    } catch (Throwable $e) {
        error_log('[expeditions cmd stock] ' . $e->getMessage());
    }
}

// ── Live stock map for Mouvements form — per-line advisory hints ─────────────
// Keyed by sku_id → {physique} (physical on-hand across all sites, for the hint).
// Uses fg_stock_compute. On failure map stays '{}' — hints silently absent, form works.
$movStockMapJson    = '{}';
$movStockAnchorJson = 'null';
if ($view === 'mouvements') {
    try {
        $fgStockDataMov = fg_stock_compute($pdo);
        $movStockMap    = [];
        foreach ($fgStockDataMov['rows'] as $sr) {
            $movStockMap[(int) $sr['sku_id']] = [
                'physique' => (int) round($sr['physique']),
            ];
        }
        $movStockMapJson    = json_encode($movStockMap, $jsonFlags);
        $movStockAnchorJson = json_encode($fgStockDataMov['anchor_month'], $jsonFlags);
    } catch (Throwable $e) {
        error_log('[expeditions mov stock] ' . $e->getMessage());
    }
}

// ── Pull-list aggregation: total qty per SKU across all OPEN orders ────────
// Computed server-side from already-fetched $cmdLines.
// OPEN = status NOT IN ('shipped', 'cancelled').
// Output: [ { sku_id, sku_code, format, family, total_qty, order_count, hl_each }, … ]
// Grouped by family in JS for rendering.
$cmdPullList     = [];
$cmdPullTotalHl  = 0.0;
if ($view === 'commandes') {
    // Map format string → family key (covers all 5 distinct values on this DB)
    $formatFamilyMap = [
        'Keg'            => 'keg',
        'Bot'            => 'bot',
        'Can'            => 'can',
        'Can33'          => 'can33',
        'Cuve de service'=> 'cuv',
    ];
    $pullAgg = []; // keyed by sku_id_fk
    foreach ($cmdOrders as $ord) {
        $status = (string) ($ord['status'] ?? 'entered');
        if (in_array($status, ['shipped', 'cancelled'], true)) continue;
        $oid = (int) $ord['id'];
        foreach ($cmdLines[$oid] ?? [] as $ln) {
            $sid = (int) $ln['sku_id_fk'];
            if (!isset($pullAgg[$sid])) {
                $fmt    = (string) ($ln['format'] ?? '');
                $family = $formatFamilyMap[$fmt] ?? 'other';
                $pullAgg[$sid] = [
                    'sku_id'      => $sid,
                    'sku_code'    => (string) $ln['sku_code'],
                    'format'      => $fmt,
                    'family'      => $family,
                    'hl_each'     => (float) $ln['hl_per_unit'],
                    'total_qty'   => 0,
                    'order_count' => 0,
                ];
            }
            $pullAgg[$sid]['total_qty']   += (float) $ln['qty'];
            $pullAgg[$sid]['order_count'] += 1;
        }
    }
    // Sort: family then sku_code
    uasort($pullAgg, fn($a, $b) =>
        strcmp($a['family'], $b['family']) ?: strcmp($a['sku_code'], $b['sku_code']));
    $cmdPullList = array_values($pullAgg);
    foreach ($cmdPullList as $pr) {
        $cmdPullTotalHl += $pr['total_qty'] * $pr['hl_each'];
    }
}
$cmdPullListJson    = json_encode($cmdPullList, $jsonFlags);
$cmdPullTotalHlJson = json_encode(round($cmdPullTotalHl, 2), $jsonFlags);

// ── Edit-mode: original line qtys keyed by sku_id so JS can add them back
// to live_futur (the line's own qty is already counted in open_total_qty,
// so live_futur is understated by exactly that qty for this order).
$editOrigQtyJson = '{}';
if ($view === 'form' && !empty($editLines)) {
    $origQty = [];
    foreach ($editLines as $l) {
        $sid = (int) $l['sku_id_fk'];
        // Multiple lines for same SKU in the same order are possible — sum them.
        $origQty[$sid] = ($origQty[$sid] ?? 0) + (float) $l['qty'];
    }
    $editOrigQtyJson = json_encode($origQty, $jsonFlags);
}

// ── Stocktake view: build JS-injectable data ──────────────────────────────────
$stSkusJson      = '[]';
$stPriorJson     = '{}';
$stSitesJson     = '[]';
$stFreshnessJson = '{}';
if ($view === 'stocktake') {
    // SKU list for JS: [{id, sku_code, format, hl_per_unit, is_cage, stocktake_scope}]
    // Cage SKUs (stocktake_scope='cage'): is_cage=true; hl_per_unit=0.00330/btl post-redenomination.
    // stocktake_scope is included so JS can reflect server-side visibility logic if needed.
    $stSkusForJs = array_map(function ($s) {
        $isCage = (($s['stocktake_scope'] ?? '') === 'cage');
        return [
            'id'              => (int) $s['id'],
            'sku_code'        => $s['sku_code'],
            'format'          => $s['format'] ?? '',
            'hl_per_unit'     => (float) $s['hl_per_unit'],
            'is_cage'         => $isCage,
            'stocktake_scope' => $s['stocktake_scope'],
        ];
    }, $stAllSkus ?? []);
    $stSkusJson = json_encode($stSkusForJs, $jsonFlags);

    // Prior counts: {loc_id: {sku_id: {qty, counted_at, month_closed}}}
    // Convert int keys to strings for JSON (JS accesses them as string keys)
    $stPriorForJs = [];
    foreach ($stPriorMap ?? [] as $locId => $bySkuId) {
        $locKey = (string) $locId;
        $stPriorForJs[$locKey] = [];
        foreach ($bySkuId as $skuId => $pr) {
            $stPriorForJs[$locKey][(string) $skuId] = $pr;
        }
    }
    $stPriorJson = json_encode($stPriorForJs, $jsonFlags);

    // Sites list (for location chips)
    $stSitesForJs = array_map(fn($s) => [
        'id'          => (int) $s['id'],
        'name'        => $s['name'],
        'site_type'   => $s['site_type'],
        'sort_order'  => (int) $s['sort_order'],
        'notes'       => $s['notes'] ?? null,
        'is_consignment' => ($s['customer_id_fk'] !== null),
    ], $stSitesRows ?? []);
    $stSitesJson = json_encode($stSitesForJs, $jsonFlags);

    // Freshness: {loc_id: last_counted_date_or_null}
    $stFreshForJs = [];
    foreach ($stSitesRows ?? [] as $s) {
        $lid = (int) $s['id'];
        $stFreshForJs[(string) $lid] = $stLastCounted[$lid] ?? null;
    }
    $stFreshnessJson = json_encode($stFreshForJs, $jsonFlags);
}

$csrf          = csrf_token();
$active_module = 'expeditions';
$todayDate     = date('Y-m-d'); // used for overdue + today emphasis in commandes view

// exp_st_scope_allowed() is defined in app/stocktake-helpers.php (included above).

// ── Stock-health thresholds — tunable presentation constants ─────────────────
// Kouros may adjust these without touching the state model logic.
const WEEKS_CRITICAL = 1;   // semaines_stock < 1 → Critique
const WEEKS_LOW      = 3;   // semaines_stock < 3 → Bas
const WEEKS_HIGH     = 10;  // semaines_stock >= 10 → Bien fourni

/**
 * Stock-health state for a single fg_stock_compute row.
 *
 * Priority (first match wins):
 *   0 survendu      live_futur < 0
 *   1 epuise        physique <= 0  (and not survendu)
 *   2 critique      physique > 0 && semaines_stock !== null && < WEEKS_CRITICAL
 *   3 bas           semaines_stock !== null && < WEEKS_LOW
 *   4 suffisant     semaines_stock !== null && < WEEKS_HIGH
 *   5 eleve         semaines_stock !== null && >= WEEKS_HIGH
 *   6 sans_rotation physique > 0 && semaines_stock === null
 *
 * Returns: ['key','label','icon','class','rank']
 * Colour-blind safe: every state carries a distinct icon shape + FR label,
 * so shape + text disambiguate independent of hue.
 */
function exp_stock_level(array $row): array
{
    $physique      = (float) $row['physique'];
    $liveFutur     = (float) $row['live_futur'];
    $semaines      = $row['semaines_stock'] !== null ? (float) $row['semaines_stock'] : null;

    // 0 — survendu (committed orders exceed available stock)
    if ($liveFutur < 0) {
        return ['key' => 'survendu',      'label' => 'Survendu',      'icon' => '⛔', 'class' => 'exp-lvl--survendu', 'rank' => 0];
    }
    // 1 — épuisé (nothing on hand)
    if ($physique <= 0) {
        return ['key' => 'epuise',        'label' => 'Épuisé',        'icon' => '○',  'class' => 'exp-lvl--epuise',   'rank' => 1];
    }
    // 2 — critique (very low cover)
    if ($semaines !== null && $semaines < WEEKS_CRITICAL) {
        return ['key' => 'critique',      'label' => 'Critique',      'icon' => '⚠',  'class' => 'exp-lvl--critique',  'rank' => 2];
    }
    // 3 — bas (low cover)
    if ($semaines !== null && $semaines < WEEKS_LOW) {
        return ['key' => 'bas',           'label' => 'Bas',           'icon' => '▼',  'class' => 'exp-lvl--bas',       'rank' => 3];
    }
    // 4 — suffisant
    if ($semaines !== null && $semaines < WEEKS_HIGH) {
        return ['key' => 'suffisant',     'label' => 'Suffisant',     'icon' => '✓',  'class' => 'exp-lvl--ok',        'rank' => 4];
    }
    // 5 — élevé (well stocked)
    if ($semaines !== null && $semaines >= WEEKS_HIGH) {
        return ['key' => 'eleve',         'label' => 'Bien fourni',   'icon' => '▲',  'class' => 'exp-lvl--eleve',     'rank' => 5];
    }
    // 6 — sans_rotation (stock but no velocity → can't compute cover)
    return     ['key' => 'sans_rotation', 'label' => 'Sans rotation', 'icon' => '—',  'class' => 'exp-lvl--dormant',   'rank' => 6];
}

/**
 * Map ref_skus.format to a CSS family slug.
 * Covers all 5 distinct format values on this DB (+ fallback).
 */
function exp_format_family(string $format): string
{
    return match ($format) {
        'Keg'             => 'keg',
        'Bot'             => 'bot',
        'Can'             => 'can',
        'Can33'           => 'can33',
        'Cuve de service' => 'cuv',
        'multipack'       => 'multipack',
        default           => 'other',
    };
}

/**
 * Human label for format family (UI — no DB nomenclature in operator text).
 */
function exp_family_label(string $family): string
{
    return match ($family) {
        'keg'        => 'Fût',
        'bot'        => 'Bouteille',
        'can'        => 'Canette',
        'can33'      => 'Can 33',
        'cuv'        => 'Cuve',
        'multipack'  => 'Multipacks',
        default      => 'Autre',
    };
}

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

/**
 * Format a stock quantity for display.
 * All SKU types (including cages) render as integer units.
 * Cage SKUs store integer bottles post-redenomination (units_per_pack=1).
 */
function exp_fmt_stock_qty(float $qty, bool $isCage): string
{
    return number_format((int) round($qty), 0, '.', ' ');
}

/**
 * Human label for site_type — used by both stock and stocktake views.
 */
function exp_site_type_label(string $siteType): string
{
    return match ($siteType) {
        'production'  => 'Production',
        'warehouse'   => 'Logistique',
        'pos'         => 'Taproom',
        'consignment' => 'Consignation',
        default       => ucfirst($siteType),
    };
}

/**
 * Returns the ref_sites.site_type for the logged-in user's home site, or null
 * when the user spans all sites (admin/manager-all) and has no single home.
 *
 * Precedence 1 — manager_scope (set for managers, null for operators):
 *   'production' → 'production'
 *   'logistics'  → 'warehouse'
 *   'all'        → null (all-scope manager: no single home site)
 *
 * Precedence 2 — access_preset_id_fk resolved to preset_key:
 *   'production_operator' → 'production'
 *   'logistics_operator'  → 'warehouse'
 *   'marketing'           → 'pos'
 *   anything else         → null
 *
 * $user must carry 'manager_scope' and 'preset_key' keys.
 * Top-level (compile-time hoisted) — NEVER nest inside a conditional or render block.
 */
function exp_user_home_site_type(array $user): ?string
{
    // Precedence 1: manager_scope
    $scope = $user['manager_scope'] ?? null;
    if ($scope !== null) {
        if ($scope === 'production') return 'production';
        if ($scope === 'logistics')  return 'warehouse';
        // 'all' or any future value → no single home
        return null;
    }

    // Precedence 2: preset_key
    $preset = $user['preset_key'] ?? null;
    if ($preset === 'production_operator') return 'production';
    if ($preset === 'logistics_operator')  return 'warehouse';
    if ($preset === 'marketing')           return 'pos';

    // admin / viewer / manager (no manager_scope) / unmapped → no home site
    return null;
}

$isReadOnly = $editOrder !== null
    && in_array((string) $editOrder['status'], ['shipped', 'cancelled'], true);

// ── Home-site resolution (pure render-layer, no DB write, no migration) ───────
// Build the user array that exp_user_home_site_type() needs, merging preset_key
// resolved once at the top of this request (see $_mePresetKey above).
$_meForHomeSite = array_merge($me ?? [], ['preset_key' => $_mePresetKey]);
$_homeSiteType  = exp_user_home_site_type($_meForHomeSite);

/**
 * Given a location-snapshot array and a site_type string, returns
 * ['units' => int, 'name' => string] for the matching location, or null.
 * Pure function — no side effects.
 */
function exp_resolve_home_site(array $snapshot, ?string $siteType): ?array
{
    if ($siteType === null) return null;
    foreach ($snapshot['locations'] as $loc) {
        if ($loc['site_type'] === $siteType) {
            return ['units' => (float) $loc['total_units'], 'name' => (string) $loc['name']];
        }
    }
    return null;
}

// Resolve for view=stock (uses $fgLocationSnapshot)
$fgHomeSiteStock = ($_homeSiteType !== null && $fgLocationSnapshot !== null)
    ? exp_resolve_home_site($fgLocationSnapshot, $_homeSiteType)
    : null;

// Resolve for view=commandes (uses $fgLocationSnapshotForCmds)
$fgHomeSiteCmds = ($_homeSiteType !== null && !empty($fgLocationSnapshotForCmds))
    ? exp_resolve_home_site($fgLocationSnapshotForCmds, $_homeSiteType)
    : null;

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
  <link rel="stylesheet" href="/css/eshop-fulfilment.css?v=<?= @filemtime(__DIR__ . '/../css/eshop-fulfilment.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/repack.css?v=<?= @filemtime(__DIR__ . '/../css/repack.css') ?: time() ?>">
</head>
<body class="home op-form-page expeditions <?= in_array($view, ['form', 'clients'], true) ? 'exp-view--form' : 'exp-view--board' ?>">

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
    <a href="/modules/expeditions.php?view=shopify"
       class="exp-tab<?= $view === 'shopify' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'shopify' ? 'aria-current="page"' : '' ?>><?= EXP_INTERNAL_LABELS['eshop'] ?></a>
    <a href="/modules/expeditions.php?view=historique"
       class="exp-tab<?= $view === 'historique' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'historique' ? 'aria-current="page"' : '' ?>>Historique</a>
    <a href="/modules/expeditions.php?view=form"
       class="exp-tab<?= $view === 'form' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'form' ? 'aria-current="page"' : '' ?>>Saisie</a>
    <a href="/modules/expeditions.php?view=stock"
       class="exp-tab<?= $view === 'stock' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'stock' ? 'aria-current="page"' : '' ?>>Stock PF</a>
    <a href="/modules/expeditions.php?view=stocktake"
       class="exp-tab<?= $view === 'stocktake' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'stocktake' ? 'aria-current="page"' : '' ?>>Inventaire</a>
    <a href="/modules/expeditions.php?view=clients"
       class="exp-tab<?= $view === 'clients' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'clients' ? 'aria-current="page"' : '' ?>>
      Carnet clients<?= $clientsReviewCount > 0
          ? ' <span class="exp-tab-badge">' . $clientsReviewCount . '</span>' : '' ?>
    </a>
    <a href="/modules/expeditions.php?view=mouvements"
       class="exp-tab<?= $view === 'mouvements' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'mouvements' ? 'aria-current="page"' : '' ?>>Mouvements</a>
    <a href="/modules/expeditions.php?view=side-stock"
       class="exp-tab<?= $view === 'side-stock' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'side-stock' ? 'aria-current="page"' : '' ?>>Restes d'emballage</a>
    <a href="/modules/expeditions.php?view=repack"
       class="exp-tab<?= $view === 'repack' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'repack' ? 'aria-current="page"' : '' ?>>Reconditionnement</a>
    <a href="/modules/expeditions.php?view=retours"
       class="exp-tab<?= $view === 'retours' ? ' exp-tab--active' : '' ?>"
       <?= $view === 'retours' ? 'aria-current="page"' : '' ?>>&#8629; Retours</a>
  </nav>

  <?php
  /* ── Repack render helpers — FILE SCOPE (hoisted, available to every view) ──
     These MUST live outside any `if ($view === ...)` block: the ?view=repack
     render calls exp_render_repack_order_block(), but PHP only defines a
     function nested inside a conditional when that conditional runs. Defining
     them here (top level) guarantees they exist regardless of $view. */

  /**
   * Render a fulfilment mode badge for a repack proposal row.
   * Mirrors the mode badges used in exp_render_eshop_row.
   */
  function exp_render_repack_mode_badge(string $mode): void
  {
      if ($mode === 'pickup') {
          echo '<span class="exp-eshop-mode-badge exp-eshop-mode-badge--pickup" title="Retrait boutique">🏬</span>';
      } else {
          echo '<span class="exp-eshop-mode-badge exp-eshop-mode-badge--delivery" title="Livraison">🚚</span>';
      }
  }

  /**
   * Render a single repack proposal block for one order.
   * Groups all proposal rows by order_id; called once per order.
   *
   * @param array  $orderMeta  {id, fulfilment_mode, order_name}
   * @param array  $rows       Proposal rows for this order (from repack_decompose_orders)
   * @param string $csrf       CSRF token for the log-event form
   */
  function exp_render_repack_order_block(array $orderMeta, array $rows, string $csrf): void
  {
      $oid      = (int) $orderMeta['id'];
      $mode     = (string) ($orderMeta['fulfilment_mode'] ?? 'delivery');
      $label    = htmlspecialchars((string) ($orderMeta['order_name'] ?? "#$oid"));
      ?>
      <div class="rkp-order-block" data-order-id="<?= $oid ?>" role="group"
           aria-label="Commande <?= $label ?>">
        <div class="rkp-order-header">
          <?php exp_render_repack_mode_badge($mode) ?>
          <span class="rkp-order-name"><?= $label ?></span>
          <button class="rkp-confirm-btn ef-chip ef-chip--next"
                  data-order-id="<?= $oid ?>"
                  data-mode="<?= htmlspecialchars($mode) ?>"
                  aria-label="Confirmer le reconditionnement pour la commande <?= $label ?>">
            Confirmer la décomposition
          </button>
        </div>
        <table class="rkp-proposal-table" role="table" aria-label="Décomposition commande <?= $label ?>">
          <thead>
            <tr>
              <th scope="col">Boîte source</th>
              <th scope="col">Qté ouverte</th>
              <th scope="col">Résultat</th>
              <th scope="col">Type</th>
              <th scope="col">Loose</th>
              <th scope="col" class="rkp-col-bal">Équilibre</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $toKind = (string) $row['to_kind'];
              $kindLabel = match($toKind) {
                  'bundle' => 'Bundle',
                  'pd8'    => 'PD8',
                  'loose'  => 'Loose',
                  default  => htmlspecialchars($toKind),
              };
              $balanced = (bool) ($row['balanced'] ?? false);
            ?>
            <tr class="rkp-proposal-row<?= $balanced ? '' : ' rkp-proposal-row--unbalanced' ?>"
                data-from-sku-id="<?= (int) $row['from_sku_id'] ?>"
                data-from-qty="<?= (int) $row['from_qty'] ?>"
                data-to-sku-id="<?= (int) $row['to_sku_id'] ?>"
                data-to-qty="<?= (int) $row['to_qty'] ?>"
                data-component-bottles="<?= (int) $row['component_bottles'] ?>"
                data-loose-units="<?= (int) $row['loose_units'] ?>"
                data-to-kind="<?= htmlspecialchars($toKind) ?>"
                data-site-id="<?= (int) $row['site_id'] ?>">
              <td class="rkp-sku-from"><span class="rkp-sku-code"><?= htmlspecialchars($row['from_sku_code']) ?></span></td>
              <td class="rkp-qty-from"><?= (int) $row['from_qty'] ?></td>
              <td class="rkp-sku-to"><span class="rkp-sku-code"><?= htmlspecialchars($row['to_sku_code']) ?></span>
                  <span class="rkp-qty-to">×<?= (int) $row['to_qty'] ?></span></td>
              <td class="rkp-to-kind"><span class="rkp-kind-badge rkp-kind-badge--<?= htmlspecialchars($toKind) ?>"><?= $kindLabel ?></span></td>
              <td class="rkp-loose"><?= (int) $row['loose_units'] > 0 ? ('+' . (int)$row['loose_units'] . '&nbsp;u.') : '—' ?></td>
              <td class="rkp-col-bal"><?= $balanced ? '<span class="rkp-bal-ok" aria-label="Équilibré">✓</span>' : '<span class="rkp-bal-warn" aria-label="Reste loose">≠</span>' ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php
  }

  /* ── Eshop render helpers — FILE SCOPE (hoisted, available to every view) ──
     Same rule as the repack helpers above: ?view=shopify (boutique en ligne)
     calls exp_render_eshop_row(), but PHP only defines a function nested inside
     an `if ($view === 'commandes')` block when that block runs. Defining the
     three eshop helpers here (top level) guarantees they exist for every view. */
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
          $dis = ($isDone || !$isNext || !can_write_expeditions()) ? ' disabled aria-disabled="true"' : '';
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

  /**
   * Render Phase 2A action chips for an eshop order.
   * Mode-aware: delivery / pickup lifecycle, review → no chips.
   * JS in eshop-fulfilment.js replaces innerHTML on update; must stay in sync.
   *
   * @param array $eo  Row from Query 3a: must include fulfilment_mode, fulfil_status, shopify_sync_state.
   */
  function exp_render_eshop_chips(array $eo): void
  {
      $eid       = (int) $eo['id'];
      $mode      = (string) ($eo['fulfilment_mode'] ?? 'review');
      $status    = (string) ($eo['fulfil_status']   ?? 'new');
      $syncState = (string) ($eo['shopify_sync_state'] ?? 'idle');

      // Sync badge (idle stays invisible via CSS visibility:hidden)
      $syncCls   = 'ef-sync-badge ef-sync-badge--' . htmlspecialchars($syncState);
      $syncLabels = [
          'idle'    => '',
          'pending' => '⟳ à pousser',
          'pushed'  => '✓ Shopify',
          'failed'  => '⚠ erreur',
      ];
      $syncText  = $syncLabels[$syncState] ?? '';

      printf('<span class="%s" data-eshop-order-id="%d">%s</span>',
          $syncCls, $eid, htmlspecialchars($syncText));

      // review-mode: classify chips (operator sets pickup vs delivery)
      if ($mode === 'review') {
          printf('<div class="ef-chips ef-chips--classify" data-eshop-order-id="%d">', $eid);
          echo '<span class="ef-classify-hint">à classer :</span>';
          printf('<button class="ef-chip ef-chip--classify" data-eshop-order-id="%d" data-action="classify" data-mode="pickup" aria-label="Classer en retrait">🏬 Retrait</button>', $eid);
          printf('<button class="ef-chip ef-chip--classify" data-eshop-order-id="%d" data-action="classify" data-mode="delivery" aria-label="Classer en livraison">🚚 Livraison</button>', $eid);
          echo '</div>';
          return;
      }

      $rankMap  = ($mode === 'pickup') ? EXP_ESHOP_STATUS_RANK_PICKUP : EXP_ESHOP_STATUS_RANK_DELIVERY;
      $stages   = ($mode === 'pickup')
          ? ['picking', 'picked', 'ready_for_pickup', 'picked_up']
          : ['picking', 'picked', 'fulfilled'];
      $advanceMap = ($mode === 'pickup')
          ? ['new' => 'picking', 'picking' => 'picked', 'picked' => 'ready_for_pickup', 'ready_for_pickup' => 'picked_up']
          : ['new' => 'picking', 'picking' => 'picked', 'picked' => 'fulfilled'];
      $revertMap = [
          'picking' => 'new', 'picked' => 'picking',
          'ready_for_pickup' => 'picked', 'fulfilled' => 'picked', 'picked_up' => 'ready_for_pickup',
      ];
      $terminals = ['fulfilled', 'picked_up', 'cancelled'];

      printf('<div class="ef-chips" data-eshop-order-id="%d" data-mode="%s">',
          $eid, htmlspecialchars($mode));

      if ($status === 'cancelled') {
          echo '<span class="ef-chip ef-chip--cancelled">Annulé</span>';
          echo '</div>';
          return;
      }

      $curRank = $rankMap[$status] ?? 0;

      foreach ($stages as $stage) {
          $stageRank = $rankMap[$stage] ?? 0;
          $isPast    = $curRank >= $stageRank;
          $isNext    = ($advanceMap[$status] ?? null) === $stage;
          $lbl       = EXP_ESHOP_STATUS_LABELS[$stage] ?? $stage;
          $aria      = $isPast
              ? ('✓ ' . $lbl . ' — fait')
              : ($isNext ? ('Marquer : ' . $lbl) : $lbl);

          if ($isNext) {
              printf('<button class="ef-chip ef-chip--next" data-eshop-order-id="%d"'
                  . ' data-action="advance" data-target-status="%s" aria-label="%s">%s</button>',
                  $eid, htmlspecialchars($stage), htmlspecialchars($aria), htmlspecialchars($lbl));
          } else {
              $cls = 'ef-chip ef-chip--' . $stage . ($isPast ? ' ef-chip--done' : '');
              printf('<span class="%s" aria-label="%s">%s</span>',
                  htmlspecialchars($cls), htmlspecialchars($aria),
                  ($isPast ? '✓ ' : '') . htmlspecialchars($lbl));
          }
      }

      // Revert chip (if not at start or terminal)
      if ($status !== 'new' && !in_array($status, $terminals, true) && isset($revertMap[$status])) {
          $prevSt  = $revertMap[$status];
          $prevLbl = EXP_ESHOP_STATUS_LABELS[$prevSt] ?? $prevSt;
          printf('<button class="ef-chip ef-chip--revert" data-eshop-order-id="%d"'
              . ' data-action="revert" aria-label="%s">↩ %s</button>',
              $eid, htmlspecialchars('Retour à ' . $prevLbl), htmlspecialchars($prevLbl));
      }

      // Cancel chip (if not terminal)
      if (!in_array($status, $terminals, true)) {
          printf('<button class="ef-chip ef-chip--cancel" data-eshop-order-id="%d"'
              . ' data-action="cancel" aria-label="Annuler la commande">✕</button>',
              $eid);
      }

      echo '</div>';
  }

  /**
   * Render a single eshop order row (Phase 2A: includes workflow chips).
   * Uses data-eshop-order-id (NOT data-order-id) so existing status JS never targets it.
   */
  function exp_render_eshop_row(array $eo, array $lines): void
  {
      $eid   = (int) $eo['id'];
      $mode  = (string) ($eo['fulfilment_mode'] ?? 'review');
      [$modeIcon, $modeLabel, $modeCls] = match ($mode) {
          'pickup'   => ['🏬', 'Retrait',    'exp-fulfil-badge--pickup'],
          'delivery' => ['🚚', 'Livraison',  'exp-fulfil-badge--delivery'],
          default    => ['⚠', 'À classer',  'exp-fulfil-badge--review'],
      };

      // Customer display
      $first = trim((string) ($eo['customer_first_name'] ?? ''));
      $last  = trim((string) ($eo['customer_last_name']  ?? ''));
      $name  = trim("$first $last");
      if ($name === '') $name = (string) ($eo['customer_email'] ?? '—');

      // SKU pills (up to 6 visible, +N expand) — reuse exp_format_family/exp_family_label
      // Lines with sku_id_fk=NULL (tap machines, Caution, gift cards …) fall back
      // to isol.title for the label and get a greyed "matériel" badge instead of a
      // family colour, so they no longer render as "?×1".
      $pillsHtml = '';
      $visLines  = array_slice($lines, 0, 6);
      $hidLines  = array_slice($lines, 6);
      foreach ($visLines as $ln) {
          $qty      = (float) ($ln['qty'] ?? 0);
          $qtyFmt   = (floor($qty) == $qty) ? (int)$qty : $qty;
          $isMat    = empty($ln['sku_code']);
          if ($isMat) {
              $label = htmlspecialchars(trim((string) ($ln['title'] ?? '')) ?: '?');
              $pillsHtml .= '<span class="exp-sku-pill exp-sku-pill--materiel">'
                  . $label . '×' . $qtyFmt
                  . '<span class="exp-sku-materiel-badge">matériel</span>'
                  . '</span>';
          } else {
              $fam = exp_format_family((string) ($ln['format'] ?? ''));
              $pillsHtml .= '<span class="exp-sku-pill exp-sku-pill--' . $fam
                  . '" title="' . htmlspecialchars(exp_family_label($fam)) . '">'
                  . htmlspecialchars((string) $ln['sku_code']) . '×' . $qtyFmt
                  . '</span>';
          }
      }
      if (!empty($hidLines)) {
          $hidHtml = '';
          foreach ($hidLines as $ln) {
              $qty    = (float) ($ln['qty'] ?? 0);
              $qtyFmt = (floor($qty) == $qty) ? (int)$qty : $qty;
              $isMat  = empty($ln['sku_code']);
              if ($isMat) {
                  $label = htmlspecialchars(trim((string) ($ln['title'] ?? '')) ?: '?');
                  $hidHtml .= '<span class="exp-sku-pill exp-sku-pill--materiel">'
                      . $label . '×' . $qtyFmt
                      . '<span class="exp-sku-materiel-badge">matériel</span>'
                      . '</span>';
              } else {
                  $fam = exp_format_family((string) ($ln['format'] ?? ''));
                  $hidHtml .= '<span class="exp-sku-pill exp-sku-pill--' . $fam
                      . '" title="' . htmlspecialchars(exp_family_label($fam)) . '">'
                      . htmlspecialchars((string) $ln['sku_code']) . '×' . $qtyFmt
                      . '</span>';
              }
          }
          $pillsHtml .= '<button type="button" class="exp-sku-more" '
              . 'data-hidden-pills="' . htmlspecialchars($hidHtml, ENT_QUOTES) . '"'
              . ' aria-expanded="false">+' . count($hidLines) . '</button>';
      }

      printf(
          '<div class="exp-order-row exp-order-row--eshop" data-eshop-order-id="%d">',
          $eid
      );
      echo '<span class="exp-eshop-order-name">' . htmlspecialchars((string) ($eo['order_name'] ?? "#$eid")) . '</span>';
      $fd  = trim((string) ($eo['fulfilment_date'] ?? ''));
      $fde = trim((string) ($eo['fulfilment_date_end'] ?? ''));
      if ($fd !== '') {
          $moisFr = [1=>'janv.',2=>'févr.',3=>'mars',4=>'avr.',5=>'mai',6=>'juin',7=>'juil.',8=>'août',9=>'sept.',10=>'oct.',11=>'nov.',12=>'déc.'];
          $fmtDay = static function (string $iso) use ($moisFr): string {
              $ts = strtotime($iso);
              if ($ts === false) return $iso;
              $d = (int) date('j', $ts); $m = (int) date('n', $ts);
              return $d . ' ' . ($moisFr[$m] ?? '');
          };
          if ($fde !== '' && $fde !== $fd) {
              $rangeLabel = $fmtDay($fd) . '→' . $fmtDay($fde);
          } else {
              $rangeLabel = $fmtDay($fd);
          }
          echo '<span class="exp-fulfil-badge exp-fulfil-badge--rental" title="Date de location (tireuse portative)">📅 Tireuse ' . htmlspecialchars($rangeLabel) . '</span>';
      }
      echo '<span class="exp-order-party">' . htmlspecialchars($name) . '</span>';
      echo '<div class="exp-sku-pills">' . $pillsHtml . '</div>';
      printf('<span class="exp-fulfil-badge %s">%s %s</span>',
          $modeCls, $modeIcon, htmlspecialchars($modeLabel));
      echo '<span class="exp-eshop-source-tag" aria-label="Shopify">Shopify</span>';
      // Phase 2A: workflow chips
      exp_render_eshop_chips($eo);
      echo '</div>';
  }

  ?>

  <!-- ══════════════════════════════════════════════════════════════════════
       COMMANDES VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'commandes'): ?>

  <?php

  /* repack AND eshop render helpers relocated to file scope (see top of file,
     before the COMMANDES view) — exp_render_chips / exp_render_eshop_chips /
     exp_render_eshop_row must be defined for the ?view=shopify render too, and
     this block only runs when $view === 'commandes'. */
  ?>

  <!-- ── Range notice ──────────────────────────────────────────────────────── -->
  <?php if ($cmdRangeNotice !== ''): ?>
    <div class="db-flash db-flash--warn"><?= htmlspecialchars($cmdRangeNotice) ?></div>
  <?php endif ?>

  <!-- ── FG stock pinned summary strip ───────────────────────────────────── -->
  <?php
  if ($fgStockForCmds !== null && !empty($fgStockForCmds['rows'])):
      $cmdFgPhysique = 0;
      $cmdFgWeekQty  = 0;
      $cmdFg2wkQty   = 0;
      $cmdFgFutQty   = 0;
      foreach ($fgStockForCmds['rows'] as $fsr) {
          $cmdFgPhysique += $fsr['physique'];
          $cmdFgWeekQty  += $fsr['open_week_qty'];
          $cmdFg2wkQty   += $fsr['open_2wk_qty'];
          $cmdFgFutQty   += $fsr['open_total_qty'];
      }
      $cmdFgDiSem  = $cmdFgPhysique - $cmdFgWeekQty;
      $cmdFgDi2sem = $cmdFgPhysique - $cmdFg2wkQty;
      $cmdFgDiFut  = $cmdFgPhysique - $cmdFgFutQty;
  ?>
  <div class="exp-cmd-stock-strip" role="region" aria-label="Aperçu stock PF">
    <a href="/modules/expeditions.php?view=stock" class="exp-cmd-stock-strip__link"
       title="Voir le tableau Stock PF complet" aria-label="Voir Stock PF">
      <span class="exp-cmd-stock-strip__icon" aria-hidden="true">📦</span>
      <span class="exp-cmd-stock-strip__label">Stock PF</span>
    </a>
    <div class="exp-cmd-stock-strip__kpi">
      <span class="exp-cmd-stock-strip__val"><?= number_format($cmdFgPhysique) ?></span>
      <span class="exp-cmd-stock-strip__sub">physique</span>
    </div>
    <?php if ($fgHomeSiteCmds !== null): ?>
    <div class="exp-cmd-stock-strip__kpi exp-cmd-stock-strip__kpi--home"
         title="Stock physique à : <?= htmlspecialchars($fgHomeSiteCmds['name']) ?>">
      <span class="exp-cmd-stock-strip__val"><?= number_format($fgHomeSiteCmds['units']) ?></span>
      <span class="exp-cmd-stock-strip__sub exp-cmd-stock-strip__sub--home"><?= htmlspecialchars($fgHomeSiteCmds['name']) ?></span>
    </div>
    <?php endif ?>
    <div class="exp-cmd-stock-strip__sep" aria-hidden="true"></div>
    <div class="exp-cmd-stock-strip__kpi" title="Stock physique restant après commandes ouvertes dues cette semaine">
      <span class="exp-cmd-stock-strip__val<?= $cmdFgDiSem < 0 ? ' exp-cmd-stock-strip__val--neg' : '' ?>"><?= number_format($cmdFgDiSem) ?></span>
      <span class="exp-cmd-stock-strip__sub">dispo sem. courante<?= $cmdFgDiSem < 0 ? ' ⚠' : '' ?></span>
    </div>
    <div class="exp-cmd-stock-strip__sep" aria-hidden="true"></div>
    <div class="exp-cmd-stock-strip__kpi" title="Stock physique restant après commandes ouvertes dues d'ici la fin de la semaine prochaine">
      <span class="exp-cmd-stock-strip__val<?= $cmdFgDi2sem < 0 ? ' exp-cmd-stock-strip__val--neg' : '' ?>"><?= number_format($cmdFgDi2sem) ?></span>
      <span class="exp-cmd-stock-strip__sub">dispo 2 sem.<?= $cmdFgDi2sem < 0 ? ' ⚠' : '' ?></span>
    </div>
    <div class="exp-cmd-stock-strip__sep" aria-hidden="true"></div>
    <div class="exp-cmd-stock-strip__kpi" title="Stock physique restant après toutes les commandes ouvertes">
      <span class="exp-cmd-stock-strip__val<?= $cmdFgDiFut < 0 ? ' exp-cmd-stock-strip__val--neg' : '' ?>"><?= number_format($cmdFgDiFut) ?></span>
      <span class="exp-cmd-stock-strip__sub">dispo toutes cmdes<?= $cmdFgDiFut < 0 ? ' ⚠' : '' ?></span>
    </div>
  </div>
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
    <!-- Week navigation (links only — no form submit for nav arrows).
         When find-intent is active, de-emphasize with --muted modifier so the
         operator sees clearly that the week window is not in effect. -->
    <div class="exp-toolbar__week-nav<?= $findIntent ? ' exp-toolbar__week-nav--muted' : '' ?>"
         aria-label="Navigation semaine">
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

    <!-- Filters — hidden period inputs + filter fields + Appliquer.
         When find-intent is active (client search or cross-date status) the
         mode/kw/du/au hidden inputs are omitted so the query spans all dates.
         The server defaults to week mode when those params are absent, but
         $findIntent suppresses the BETWEEN clause before mode matters. -->
    <div class="exp-toolbar__filters">
      <?php if (!$findIntent): ?>
        <input type="hidden" name="mode" value="<?= htmlspecialchars($cmdMode) ?>">
        <?php if ($cmdMode === 'week'): ?>
          <input type="hidden" name="kw" value="<?= htmlspecialchars($cmdKw) ?>">
        <?php else: ?>
          <input type="hidden" name="du" value="<?= htmlspecialchars($cmdDu) ?>">
          <input type="hidden" name="au" value="<?= htmlspecialchars($cmdAu) ?>">
        <?php endif ?>
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
          <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Toutes périodes</option>
          <optgroup label="— Toutes dates —">
            <option value="ouvertes"   <?= $filterStatus === 'ouvertes'   ? 'selected' : '' ?>>Ouvertes (toutes dates)</option>
            <option value="expediees"  <?= $filterStatus === 'expediees'  ? 'selected' : '' ?>>Expédiées (toutes dates)</option>
          </optgroup>
          <optgroup label="— Cette période —">
            <option value="entered"    <?= $filterStatus === 'entered'    ? 'selected' : '' ?>>Saisie</option>
            <option value="confirmed"  <?= $filterStatus === 'confirmed'  ? 'selected' : '' ?>>Confirmée</option>
            <option value="picked"     <?= $filterStatus === 'picked'     ? 'selected' : '' ?>>Préparée</option>
            <option value="bl_printed" <?= $filterStatus === 'bl_printed' ? 'selected' : '' ?>>BL imprimé</option>
            <option value="shipped"    <?= $filterStatus === 'shipped'    ? 'selected' : '' ?>>Livrée</option>
            <option value="cancelled"  <?= $filterStatus === 'cancelled'  ? 'selected' : '' ?>>Annulée</option>
          </optgroup>
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
        <?php
        // Reset always goes back to the current ISO week (no filters)
        $resetKw  = exp_date_to_isoweek(date('Y-m-d'));
        $resetUrl = $baseUrl . '&amp;mode=week&amp;kw=' . urlencode($resetKw);
        ?>
        <a href="<?= $resetUrl ?>" class="exp-toolbar__reset">Réinitialiser</a>
      <?php endif ?>
    </div>
  </form>

  <!-- ── Cross-date indicator (shown only when find-intent is active) ──────── -->
  <?php if ($findIntent): ?>
  <div class="exp-find-banner" role="status" aria-live="polite">
    <span class="exp-find-banner__icon" aria-hidden="true">⊙</span>
    <span class="exp-find-banner__text">
      Résultats — toutes dates
      <?php if ($cmdSummary['orders'] > 0): ?>
        · <strong><?= $cmdSummary['orders'] ?> commande<?= $cmdSummary['orders'] !== 1 ? 's' : '' ?></strong>
      <?php endif ?>
      <?php if ($cmdFindLimited): ?>
        · <em>affichage limité à 500</em>
      <?php endif ?>
    </span>
    <?php
    $resetKwBanner = exp_date_to_isoweek(date('Y-m-d'));
    $resetUrlBanner = '/modules/expeditions.php?view=commandes&amp;mode=week&amp;kw=' . urlencode($resetKwBanner);
    ?>
    <a href="<?= $resetUrlBanner ?>" class="exp-find-banner__back">← Semaine courante</a>
  </div>
  <?php endif ?>

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

  <!-- ── Pull-list panel (Liste de préparation) ──────────────────────────── -->
  <?php if (!empty($cmdPullList)): ?>
  <div class="exp-pull-panel" id="exp-pull-panel" aria-label="Liste de préparation">
    <button type="button" class="exp-pull-toggle" id="exp-pull-toggle"
            aria-expanded="false" aria-controls="exp-pull-body">
      <span class="exp-pull-toggle__icon" aria-hidden="true">▸</span>
      <span class="exp-pull-toggle__title">Liste de préparation</span>
      <span class="exp-pull-toggle__meta">
        <?= count($cmdPullList) ?> SKU<?= count($cmdPullList) > 1 ? 's' : '' ?> ·
        <?= number_format($cmdPullTotalHl, 2) ?> HL à préparer
      </span>
    </button>
    <div class="exp-pull-body" id="exp-pull-body" hidden>
      <?php
      // Group by family for the pull-list display
      $pullByFamily = [];
      $pullMaxQty   = 0;
      foreach ($cmdPullList as $pr) {
          $pullByFamily[$pr['family']][] = $pr;
          if ($pr['total_qty'] > $pullMaxQty) $pullMaxQty = $pr['total_qty'];
      }
      // Family display order (matching the token order)
      $familyOrder = ['keg', 'bot', 'can', 'can33', 'cuv', 'other'];
      ?>
      <?php foreach ($familyOrder as $fam):
          if (!isset($pullByFamily[$fam])) continue;
          $famRows = $pullByFamily[$fam];
      ?>
      <div class="exp-pull-family">
        <div class="exp-pull-family__header exp-pull-family--<?= $fam ?>">
          <span class="exp-pull-family__label"><?= htmlspecialchars(exp_family_label($fam)) ?></span>
          <span class="exp-pull-family__count"><?= count($famRows) ?> SKU<?= count($famRows) > 1 ? 's' : '' ?></span>
        </div>
        <?php foreach ($famRows as $pr): ?>
        <div class="exp-pull-row">
          <span class="exp-sku-pill exp-sku-pill--<?= $pr['family'] ?>" title="<?= htmlspecialchars(exp_family_label($pr['family'])) ?>">
            <?= htmlspecialchars($pr['sku_code']) ?>
          </span>
          <span class="exp-pull-row__fmt"><?= htmlspecialchars(exp_family_label($pr['family'])) ?></span>
          <span class="exp-pull-row__qty" tabular-nums>
            <?= number_format($pr['total_qty'], 0) ?> <span class="exp-pull-row__unit">unités</span>
          </span>
          <span class="exp-pull-row__orders">(<?= $pr['order_count'] ?> cmd)</span>
          <span class="exp-pull-bar" role="presentation" aria-hidden="true">
            <span class="exp-pull-bar__fill exp-pull-bar__fill--<?= $pr['family'] ?>"
                  style="width:<?= $pullMaxQty > 0 ? round(100 * $pr['total_qty'] / $pullMaxQty) : 0 ?>%"></span>
          </span>
        </div>
        <?php endforeach ?>
      </div>
      <?php endforeach ?>
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
    // Collect all dates that have ord_orders or taproom aggregates (eshop is on Shopify tab)
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

    <?php $cmdStockDetailByOid = []; // per-order short-list, populated per order below ?>

    <?php foreach ($allDates as $date): ?>
      <?php
      $dayOrderIds      = $cmdByDay[$date]  ?? [];
      $dayEshop         = $cmdEshopByDay[$date] ?? [];   // taproom aggregate
      $isToday          = ($date === $todayDate);

      // Per-day metrics (exclude cancelled from HL)
      $dayHl     = 0.0;
      $dayCount  = 0;
      $dayOpen   = 0;
      // Per-day action summary counts
      $dayActCounts = [
          'entered'    => 0,
          'confirmed'  => 0,
          'picked'     => 0,
          'bl_printed' => 0,
          'shipped'    => 0,
          'cancelled'  => 0,
      ];
      foreach ($dayOrderIds as $oid) {
          $ord = $cmdOrders[$oid] ?? null;
          if ($ord === null) continue;
          $s = (string) ($ord['status'] ?? 'entered');
          $dayCount++;
          if (!in_array($s, ['shipped', 'cancelled'], true)) $dayOpen++;
          if (isset($dayActCounts[$s])) $dayActCounts[$s]++;
          if ($s !== 'cancelled' && isset($cmdLines[$oid])) {
              foreach ($cmdLines[$oid] as $ln) {
                  $dayHl += (float) $ln['qty'] * (float) $ln['hl_per_unit'];
              }
          }
      }
      // Labels used in action summary strip
      $actSummaryDefs = [
          'entered'    => ['à confirmer',    false],
          'confirmed'  => ['à préparer',     false],
          'picked'     => ['à charger (BL)', false],
          'bl_printed' => ['à livrer',       false],
          'shipped'    => ['livrées',         true],
          'cancelled'  => ['annulées',        true],
      ];
      ?>
      <div class="exp-day-block<?= $isToday ? ' exp-day-block--today' : '' ?>">
        <!-- Day header -->
        <div class="exp-day-header<?= $isToday ? ' exp-day-header--today' : '' ?>">
          <div class="exp-day-header__date">
            <span class="exp-day-header__name"><?= exp_day_name($date) ?></span>
            <span class="exp-day-header__fmt"><?= exp_fmt_date($date) ?></span>
            <?php if ($isToday): ?>
              <span class="exp-day-today-badge" aria-label="Aujourd'hui">Aujourd'hui</span>
            <?php endif ?>
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

        <!-- Action summary strip -->
        <?php if ($dayCount > 0): ?>
        <div class="exp-day-summary" role="group" aria-label="Résumé des actions du jour">
          <?php foreach ($actSummaryDefs as $actStatus => [$actLabel, $actTerminal]):
              $n = $dayActCounts[$actStatus] ?? 0;
              if ($n === 0) continue;
              // Build filter URL: click → reload with statut filter.
              // In find-intent mode omit the week/range anchor so the result
              // stays cross-date (only the status changes).
              if ($findIntent) {
                  $actUrl = $baseUrl
                      . '&amp;statut=' . urlencode($actStatus)
                      . ($filterClient !== '' ? '&amp;client=' . urlencode($filterClient) : '')
                      . ($filterSku !== '' ? '&amp;sku=' . urlencode($filterSku) : '')
                      . ($filterChannel !== '' ? '&amp;canal=' . urlencode($filterChannel) : '');
              } else {
                  $actUrl = $baseUrl . '&amp;mode=' . htmlspecialchars($cmdMode)
                      . ($cmdMode === 'week' ? '&amp;kw=' . urlencode($cmdKw) : '&amp;du=' . $cmdDu . '&amp;au=' . $cmdAu)
                      . '&amp;statut=' . urlencode($actStatus)
                      . ($filterClient !== '' ? '&amp;client=' . urlencode($filterClient) : '')
                      . ($filterSku !== '' ? '&amp;sku=' . urlencode($filterSku) : '')
                      . ($filterChannel !== '' ? '&amp;canal=' . urlencode($filterChannel) : '');
              }
          ?>
          <a href="<?= $actUrl ?>"
             class="exp-day-act-chip exp-day-act-chip--<?= $actStatus ?><?= $actTerminal ? ' exp-day-act-chip--terminal' : ' exp-day-act-chip--action' ?>"
             aria-label="Filtrer : <?= $n ?> <?= htmlspecialchars($actLabel) ?>">
            <?= $n ?> <?= htmlspecialchars($actLabel) ?>
          </a>
          <?php endforeach ?>
        </div>
        <?php endif ?>

        <!-- Orders for this day -->
        <?php foreach ($dayOrderIds as $oid):
            $ord     = $cmdOrders[$oid] ?? null;
            if ($ord === null) continue;
            $status  = (string) ($ord['status'] ?? 'entered');
            $isCanc  = $status === 'cancelled';
            $isShip  = $status === 'shipped';
            $lines   = $cmdLines[$oid] ?? [];

            // Overdue: open order with requested_date < today
            $isOverdue = !$isCanc && !$isShip && $date < $todayDate;

            // Per-order stock-risk: collect ALL lines where qty > live_futur
            $shortList    = [];
            // Decode stock map from JSON string once (lazy)
            static $cmdStockMapDecoded = null;
            if ($cmdStockMapDecoded === null) {
                $cmdStockMapDecoded = json_decode($cmdStockMapJson, true) ?? [];
            }
            if (!$isCanc && !empty($cmdStockMapDecoded)) {
                foreach ($lines as $ln) {
                    $sid = (int) ($ln['sku_id_fk'] ?? 0);
                    $lf  = $cmdStockMapDecoded[$sid]['live_futur'] ?? null;
                    $ph  = $cmdStockMapDecoded[$sid]['physique']   ?? null;
                    if ($lf !== null && (float) $ln['qty'] > $lf) {
                        $shortList[] = [
                            'sku_code'  => (string) ($ln['sku_code'] ?? ''),
                            'requested' => (float) $ln['qty'],
                            'available' => $lf,
                            'physique'  => $ph,
                            'short_by'  => round((float) $ln['qty'] - $lf, 2),
                        ];
                    }
                }
            }
            $hasStockRisk = !empty($shortList);
            if ($hasStockRisk) {
                $cmdStockDetailByOid[$oid] = $shortList;
            }

            // Backorder detection: collect lines with line_status ≠ 'to_fulfil'
            // Board read is UNGATED — these lines are always shown (flagged, not hidden).
            // Depletion (fg-stock.php) already excludes them; this surface is informational.
            $backorderLines = [];
            foreach ($lines as $ln) {
                $ls = (string) ($ln['line_status'] ?? 'to_fulfil');
                if ($ls !== 'to_fulfil') {
                    $qty    = (float) $ln['qty'];
                    $qtyFmt = (floor($qty) == $qty) ? (int) $qty : $qty;
                    $backorderLines[] = [
                        'sku_code' => (string) ($ln['sku_code'] ?? ''),
                        'qty'      => $qtyFmt,
                        'status'   => $ls,
                        'label'    => EXP_LINE_STATUS_LABELS[$ls] ?? $ls,
                    ];
                }
            }
            $hasBackorder = !empty($backorderLines);

            // Lead-time badge: hors-process flag
            // lead_days = requested_date − order_created_date (signed day count).
            // NULL order_created_date → no badge (never fabricate a lead from today).
            $leadDays = null;
            if (!empty($ord['order_created_date']) && !empty($ord['requested_date'])) {
                $created  = new DateTime($ord['order_created_date']);
                $deliver  = new DateTime($ord['requested_date']);
                $leadDays = (int) $created->diff($deliver)->format('%r%a');
            }
            $leadTier = null; // 'critical' | 'warn' | null
            if ($leadDays !== null) {
                if ($leadDays < $leadCritical)      $leadTier = 'critical';
                elseif ($leadDays < $leadWarn)      $leadTier = 'warn';
            }

            // Fulfilment site chip — ALWAYS call the resolver, never inline precedence
            $resolvedSiteId   = resolve_fulfilment_site($pdo, [
                'fulfilment_site_id_fk'     => $ord['fulfilment_site_id_fk'] ?? null,
                '_customer_default_site_id' => $ord['customer_default_site_id'] ?? null,
                'channel'                   => $ord['internal_channel'] ?? null,
            ]);
            $resolvedSiteName  = $fgSiteNameMap[$resolvedSiteId] ?? '—';
            $isSiteOverride    = !empty($ord['fulfilment_site_id_fk']);
            $isSiteUnassigned  = ($ord['order_type'] ?? '') === 'customer'
                && empty($ord['fulfilment_site_id_fk'])
                && ($ord['customer_default_site_id'] === null || $ord['customer_default_site_id'] === '');

            // SKU pills: up to 6 visible, +N expand — with family colour class
            $pillsHtml = '';
            $pillCount = count($lines);
            $visLines  = array_slice($lines, 0, 6);
            $hidLines  = array_slice($lines, 6);
            foreach ($visLines as $ln) {
                $qty     = (float) $ln['qty'];
                $qtyFmt  = (floor($qty) == $qty) ? (int)$qty : $qty;
                $fam     = exp_format_family((string) ($ln['format'] ?? ''));
                $pillsHtml .= '<span class="exp-sku-pill exp-sku-pill--' . $fam . '" title="' . htmlspecialchars(exp_family_label($fam)) . '">'
                    . htmlspecialchars($ln['sku_code']) . '×' . $qtyFmt
                    . '</span>';
            }
            if (!empty($hidLines)) {
                $hidHtml = '';
                foreach ($hidLines as $ln) {
                    $qty = (float) $ln['qty'];
                    $qtyFmt = (floor($qty) == $qty) ? (int)$qty : $qty;
                    $fam = exp_format_family((string) ($ln['format'] ?? ''));
                    $hidHtml .= '<span class="exp-sku-pill exp-sku-pill--' . $fam . '" title="' . htmlspecialchars(exp_family_label($fam)) . '">'
                        . htmlspecialchars($ln['sku_code']) . '×' . $qtyFmt
                        . '</span>';
                }
                $pillsHtml .= '<button type="button" class="exp-sku-more" '
                    . 'data-hidden-pills="' . htmlspecialchars($hidHtml, ENT_QUOTES) . '"'
                    . ' aria-expanded="false">+' . count($hidLines) . '</button>';
            }
        ?>
          <div class="exp-order-row exp-order-row--status-<?= htmlspecialchars($status) ?><?= $isCanc ? ' exp-order-row--cancelled' : '' ?><?= $isOverdue ? ' exp-order-row--overdue' : '' ?>"
               data-order-id="<?= $oid ?>"
               data-status="<?= htmlspecialchars($status) ?>">

            <span class="exp-order-id">#<?= $oid ?></span>

            <!-- Overdue badge -->
            <?php if ($isOverdue): ?>
              <span class="exp-overdue-badge" aria-label="Commande en retard">⚠ en retard</span>
            <?php endif ?>

            <!-- Stock risk advisory chip — clickable to show detail modal -->
            <?php if ($hasStockRisk): ?>
              <button type="button" class="exp-stock-risk-chip" data-order-id="<?= $oid ?>"
                      aria-label="Voir le détail du risque de stock (advisory)">⚠ stock</button>
            <?php endif ?>

            <!-- Backorder / lost-sales badge — lines flagged non_livre or rupture.
                 Board read is UNGATED: lines always show here (flagged, not dropped).
                 Depletion is gated (fg-stock.php) — these lines don't burn stock. -->
            <?php if ($hasBackorder): ?>
              <?php
              $ruptureCnt   = count(array_filter($backorderLines, fn($bl) => $bl['status'] === 'rupture'));
              $nonLivreCnt  = count($backorderLines) - $ruptureCnt;
              $boBadgeTitle = implode('; ', array_map(
                  fn($bl) => $bl['sku_code'] . '×' . $bl['qty'] . ' — ' . $bl['label'],
                  $backorderLines
              ));
              ?>
              <span class="exp-backorder-badge<?= $ruptureCnt > 0 ? ' exp-backorder-badge--rupture' : '' ?>"
                    title="<?= htmlspecialchars($boBadgeTitle) ?>"
                    aria-label="<?= $ruptureCnt > 0 ? 'Rupture de stock' : 'Non livré' ?> : <?= count($backorderLines) ?> ligne<?= count($backorderLines) > 1 ? 's' : '' ?>">
                <?= $ruptureCnt > 0 ? '⊘ rupture' : '↷ non livré' ?>
                (<?= count($backorderLines) ?>)
              </span>
            <?php endif ?>

            <!-- BC divergence badge: operator corrected lines after BC BL lock.
                 Shown for any source (bc = own lines differ; web/import = collision-twin
                 BC order carries different SKUs → credit-note + re-invoice required). -->
            <?php if (($ord['divergence_status'] ?? '') === 'correction_compta_requise'): ?>
              <span class="exp-bc-divergence-badge"
                    title="Les lignes ont été corrigées après verrouillage BC — émettre un avoir + nouvelle facture dans BC">
                ⚠ correction compta requise
              </span>
            <?php endif ?>

            <!-- Lead-time badge: hors-process (short processing window) -->
            <?php if ($leadTier === 'critical'): ?>
              <span class="exp-lead-badge exp-lead-badge--critical"
                    title="<?= $leadDays < 0 ? 'Anomalie : date de livraison antérieure à la création' : 'Commande sous 24h / jour même — délai hors-process' ?>"
                    aria-label="Commande critique sous 24h">&#9889; &lt;24h</span>
            <?php elseif ($leadTier === 'warn'): ?>
              <span class="exp-lead-badge exp-lead-badge--warn"
                    title="Commande sous 48h — délai de traitement court"
                    aria-label="Commande tardive sous 48h">&#8987; 24-48h</span>
            <?php endif ?>

            <!-- Fulfilment site chip — ship from -->
            <div class="exp-site-chip-wrap"
                 data-order-id="<?= $oid ?>"
                 data-current-site-id="<?= (int)($ord['fulfilment_site_id_fk'] ?? 0) ?>">
              <?php if ($isSiteOverride): ?>
                <span class="exp-site-chip exp-site-chip--override"
                      title="Site forcé manuellement — cliquer pour modifier">
                  ✎ <?= htmlspecialchars($resolvedSiteName) ?>
                </span>
              <?php elseif ($isSiteUnassigned): ?>
                <span class="exp-site-chip exp-site-chip--unassigned"
                      title="Aucun lieu de départ — renseigner pour l'enregistrer comme défaut du client">
                  ⚠ à renseigner
                </span>
              <?php else: ?>
                <span class="exp-site-chip exp-site-chip--auto"
                      title="Site résolu automatiquement — cliquer pour forcer">
                  📍 <?= htmlspecialchars($resolvedSiteName) ?>
                </span>
              <?php endif ?>
              <!-- Inline override select — shown on chip click, JS-driven -->
              <select class="exp-site-select" aria-label="Site d'expédition" hidden>
                <option value="">Automatique</option>
                <?php foreach ($fgStockSites as $fs): ?>
                  <option value="<?= (int)$fs['id'] ?>"
                          <?= ((int)($ord['fulfilment_site_id_fk'] ?? 0) === (int)$fs['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fs['name']) ?>
                  </option>
                <?php endforeach ?>
              </select>
            </div>

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

            <!-- Comment + edit affordance -->
            <span class="exp-order-comment<?= empty($ord['comment']) ? ' exp-order-comment--empty' : '' ?>"
                  data-order-id="<?= $oid ?>">
              <?php if (!empty($ord['comment'])): ?>
                <span class="exp-order-comment__icon" aria-hidden="true">💬</span>
                <span class="exp-order-comment__text"
                      title="<?= htmlspecialchars($ord['comment']) ?>"><?= htmlspecialchars(mb_substr($ord['comment'], 0, 40)) ?><?= mb_strlen($ord['comment']) > 40 ? '…' : '' ?></span>
              <?php else: ?>
                <span class="exp-order-comment__icon exp-order-comment__icon--placeholder" aria-hidden="true">💬</span>
              <?php endif ?>
              <?php if (!$isCanc && can_write_expeditions()): ?>
                <button type="button"
                        class="exp-comment-edit-btn"
                        data-action="set_comment"
                        data-order-id="<?= $oid ?>"
                        data-current-comment="<?= htmlspecialchars($ord['comment'] ?? '') ?>"
                        aria-label="Modifier le commentaire de la commande #<?= $oid ?>"
                        title="Modifier le commentaire">✎</button>
              <?php endif ?>
            </span>

            <!-- Transporter tag -->
            <?php if (!empty($ord['transporter_name'])): ?>
              <span class="exp-transporter-tag"><?= htmlspecialchars($ord['transporter_name']) ?></span>
            <?php endif ?>

            <!-- External document number (BC mirror) -->
            <?php if (!empty($ord['external_document_no'])): ?>
              <span class="exp-transporter-tag"
                    title="Numéro de document externe BC"
              >Doc externe&nbsp;: <?= htmlspecialchars($ord['external_document_no']) ?></span>
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

        <!-- Taproom/other channel auto rows (aggregate — unchanged) -->
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
       SHOPIFY VIEW — read-only; renders inv_sales_orders WHERE channel='eshop'
       XOR: never writes to ord_orders, never calls fg_stock_compute/depletion.
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'shopify'): ?>

  <?php
  $sfBaseUrl   = '/modules/expeditions.php?view=shopify';
  $sfFilterQS  = '';
  if ($filterClient !== '') $sfFilterQS .= '&amp;client=' . urlencode($filterClient);
  $sfWeekBaseUrl  = $sfBaseUrl . '&amp;mode=week';
  $sfRangeBaseUrl = $sfBaseUrl . '&amp;mode=range';
  ?>

  <!-- ── Toolbar (date navigation + client search) ───────────────────────── -->
  <form class="exp-toolbar" method="GET" action="/modules/expeditions.php" id="exp-shopify-toolbar-form">
    <input type="hidden" name="view" value="shopify">

    <div class="exp-toolbar__mode" role="group" aria-label="Mode de période">
      <a href="<?= $sfWeekBaseUrl ?><?= $sfFilterQS ?>"
         class="exp-toolbar__mode-btn<?= $cmdMode === 'week' ? ' exp-toolbar__mode-btn--active' : '' ?>"
         aria-pressed="<?= $cmdMode === 'week' ? 'true' : 'false' ?>">Semaine</a>
      <a href="<?= $sfRangeBaseUrl ?><?= ($cmdDu ? '&amp;du=' . $cmdDu . '&amp;au=' . $cmdAu : '') ?><?= $sfFilterQS ?>"
         class="exp-toolbar__mode-btn<?= $cmdMode === 'range' ? ' exp-toolbar__mode-btn--active' : '' ?>"
         aria-pressed="<?= $cmdMode === 'range' ? 'true' : 'false' ?>">Plage de dates</a>
    </div>

    <?php if ($cmdMode === 'week'): ?>
    <div class="exp-toolbar__week-nav<?= $findIntent ? ' exp-toolbar__week-nav--muted' : '' ?>"
         aria-label="Navigation semaine">
      <a href="<?= $sfWeekBaseUrl ?>&amp;kw=<?= urlencode($kwPrev) ?><?= $sfFilterQS ?>"
         class="exp-toolbar__nav-arrow" aria-label="Semaine précédente">◂</a>
      <span class="exp-toolbar__week-label"><?= exp_isoweek_label($cmdKw) ?></span>
      <a href="<?= $sfWeekBaseUrl ?>&amp;kw=<?= urlencode($kwNext) ?><?= $sfFilterQS ?>"
         class="exp-toolbar__nav-arrow" aria-label="Semaine suivante">▸</a>
      <a href="<?= $sfWeekBaseUrl ?><?= $sfFilterQS ?>"
         class="exp-toolbar__today-btn">Aujourd'hui</a>
    </div>
    <?php else: ?>
    <div class="exp-toolbar__range-inputs" id="exp-shopify-range-inputs">
      <label class="exp-toolbar__range-label" for="exp-sf-range-du">Du</label>
      <input type="date" id="exp-sf-range-du" name="du" class="exp-toolbar__date-input"
             value="<?= htmlspecialchars($cmdDu) ?>"
             min="2020-01-01" max="2030-12-31">
      <label class="exp-toolbar__range-label" for="exp-sf-range-au">Au</label>
      <input type="date" id="exp-sf-range-au" name="au" class="exp-toolbar__date-input"
             value="<?= htmlspecialchars($cmdAu) ?>"
             min="2020-01-01" max="2030-12-31">
      <button type="submit" class="exp-toolbar__range-submit" name="mode" value="range">Afficher</button>
    </div>
    <?php endif ?>

    <!-- Client / order name search -->
    <div class="exp-toolbar__filters">
      <?php if (!$findIntent): ?>
        <input type="hidden" name="mode" value="<?= htmlspecialchars($cmdMode) ?>">
        <?php if ($cmdMode === 'week'): ?>
          <input type="hidden" name="kw" value="<?= htmlspecialchars($cmdKw) ?>">
        <?php else: ?>
          <input type="hidden" name="du" value="<?= htmlspecialchars($cmdDu) ?>">
          <input type="hidden" name="au" value="<?= htmlspecialchars($cmdAu) ?>">
        <?php endif ?>
      <?php endif ?>

      <div class="exp-toolbar__filter-group">
        <label class="exp-toolbar__filter-label" for="exp-sf-filter-client">Client / commande</label>
        <input type="text" id="exp-sf-filter-client" name="client"
               class="exp-toolbar__filter-input"
               value="<?= htmlspecialchars($filterClient) ?>"
               placeholder="Tous…" autocomplete="off">
      </div>
      <button type="submit" class="exp-toolbar__apply-btn">Appliquer</button>
      <?php if ($filterClient !== ''): ?>
        <a href="<?= $sfWeekBaseUrl ?>" class="exp-toolbar__clear-btn" aria-label="Effacer les filtres">✕</a>
      <?php endif ?>
    </div>
  </form>

  <!-- ── Find-intent banner ──────────────────────────────────────────────── -->
  <?php if ($findIntent): ?>
  <div class="exp-find-banner" role="status">
    <span class="exp-find-banner__text">Résultats — toutes dates</span>
    <?php
    $sfResetUrl = $sfWeekBaseUrl . '&amp;mode=week&amp;kw=' . urlencode(exp_date_to_isoweek(date('Y-m-d')));
    ?>
    <a href="<?= $sfResetUrl ?>" class="exp-find-banner__back">← Semaine courante</a>
  </div>
  <?php endif ?>

  <!-- ── Shopify freshness chip ────────────────────────────────────────────
       Shown when $eshopLastImport (MAX imported_at) is known.
       Stale alarm (amber + ⚠) is driven by the ingest HEARTBEAT from system_settings
       ('fulfilment', 'shopify_ingest_last_run_at') compared to UTC now, NOT by order recency.
       Threshold: system_settings 'shopify_ingest_stale_minutes' (default 10 min).
       NULL/missing/unparseable heartbeat → neutral (green/normal chip).
  ──────────────────────────────────────────────────────────────────────────── -->
  <?php if ($eshopLastImport !== null):
      $hbStr       = system_setting('shopify_ingest_last_run_at', 'fulfilment', null);
      $staleMin    = (float) system_setting('shopify_ingest_stale_minutes', 'fulfilment', 10);
      $hb          = ($hbStr !== null && $hbStr !== '')
          ? DateTime::createFromFormat('Y-m-d H:i:s', $hbStr, new DateTimeZone('UTC'))
          : null;
      $nowUtc      = new DateTime('now', new DateTimeZone('UTC'));
      $isStale     = ($hb !== false && $hb !== null)
          && (($nowUtc->getTimestamp() - $hb->getTimestamp()) > $staleMin * 60);
      $chipCls     = 'exp-shopify-freshness-chip' . ($isStale ? ' exp-shopify-freshness-chip--stale' : '');
      $timeStr     = $eshopLastImport->format('H:i');
      $dateStr     = $eshopLastImport->format('d/m');
      $todayStr    = (new DateTime())->format('d/m');
      $importLabel = ($dateStr === $todayStr)
          ? 'Import Shopify ' . $timeStr
          : 'Import Shopify ' . $dateStr . ' ' . $timeStr;
      if ($isStale && $hb !== false && $hb !== null) {
          $hbMinutesAgo = (int) round(($nowUtc->getTimestamp() - $hb->getTimestamp()) / 60);
          $titleAttr    = 'Heartbeat ingestion il y a ' . $hbMinutesAgo . ' min — sync en retard';
      } else {
          $titleAttr    = 'Dernier import ' . $importLabel;
      }
  ?>
  <div class="<?= htmlspecialchars($chipCls) ?>" title="<?= htmlspecialchars($titleAttr) ?>"
       aria-label="<?= htmlspecialchars($importLabel) ?>">
    <?= $isStale ? '<span aria-hidden="true">⚠</span> ' : '' ?><?= htmlspecialchars($importLabel) ?>
  </div>
  <?php endif ?>

  <!-- ── Shopify period summary ────────────────────────────────────────────── -->
  <?php
  $sfPickup   = 0;
  $sfDelivery = 0;
  $sfReview   = 0;
  $sfTotalHl  = 0.0;
  foreach ($cmdEshopOrdersByDay as $dayOrders) {
      foreach ($dayOrders as $eo) {
          $mode = (string) ($eo['fulfilment_mode'] ?? 'review');
          if ($mode === 'pickup')        $sfPickup++;
          elseif ($mode === 'delivery')  $sfDelivery++;
          else                           $sfReview++;
          $sfTotalHl += (float) ($eo['total_hl'] ?? 0);
      }
  }
  $sfTotal = $sfPickup + $sfDelivery + $sfReview;
  ?>
  <?php if ($sfTotal > 0): ?>
  <div class="exp-summary-band exp-summary-band--shopify" role="region" aria-label="Résumé Boutique en ligne">
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= number_format($sfTotalHl, 2) ?></span>
      <span class="exp-summary-kpi__label">HL</span>
    </div>
    <div class="exp-summary-kpi">
      <span class="exp-summary-kpi__value"><?= $sfTotal ?></span>
      <span class="exp-summary-kpi__label">commande<?= $sfTotal !== 1 ? 's' : '' ?></span>
    </div>
    <div class="exp-summary-sep"></div>
    <?php if ($sfPickup > 0): ?>
    <div class="exp-summary-kpi exp-summary-kpi--eshop" title="Retraits boutique">
      <span class="exp-summary-kpi__value">🏬 <?= $sfPickup ?></span>
      <span class="exp-summary-kpi__label">retrait<?= $sfPickup !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif ?>
    <?php if ($sfDelivery > 0): ?>
    <div class="exp-summary-kpi exp-summary-kpi--eshop" title="Livraisons">
      <span class="exp-summary-kpi__value">🚚 <?= $sfDelivery ?></span>
      <span class="exp-summary-kpi__label">livraison<?= $sfDelivery !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif ?>
    <?php if ($sfReview > 0): ?>
    <div class="exp-summary-kpi exp-summary-kpi--eshop exp-summary-kpi--eshop-warn" title="À classer">
      <span class="exp-summary-kpi__value">⚠ <?= $sfReview ?></span>
      <span class="exp-summary-kpi__label">à classer</span>
    </div>
    <?php endif ?>
  </div>
  <?php endif ?>

  <!-- ── Day blocks ────────────────────────────────────────────────────────── -->
  <div class="exp-section" id="exp-shopify-days">

    <?php
    $sfDates = array_keys($cmdEshopOrdersByDay);
    if ($findIntent) { krsort($cmdEshopOrdersByDay); rsort($sfDates); }
    else             { ksort($cmdEshopOrdersByDay);  sort($sfDates); }
    ?>

    <?php if (empty($sfDates)): ?>
      <div class="op-form__card exp-empty-state">
        <p class="exp-empty">
          <?php if ($filterClient !== ''): ?>
            Aucune commande <?= htmlspecialchars(EXP_INTERNAL_LABELS['eshop']) ?> ne correspond à la recherche.
          <?php else: ?>
            Aucune commande <?= htmlspecialchars(EXP_INTERNAL_LABELS['eshop']) ?> pour cette période.
          <?php endif ?>
        </p>
      </div>
    <?php endif ?>

    <?php foreach ($sfDates as $sfDate): ?>
      <?php
      $sfDayOrders = $cmdEshopOrdersByDay[$sfDate] ?? [];
      $sfIsToday   = ($sfDate === $todayDate);

      // Per-day bucket counts
      $sfDayPickup   = 0;
      $sfDayDelivery = 0;
      $sfDayReview   = 0;
      $sfDayHl       = 0.0;
      foreach ($sfDayOrders as $eo) {
          $m = (string) ($eo['fulfilment_mode'] ?? 'review');
          if ($m === 'pickup')       $sfDayPickup++;
          elseif ($m === 'delivery') $sfDayDelivery++;
          else                       $sfDayReview++;
          $sfDayHl += (float) ($eo['total_hl'] ?? 0);
      }
      $sfDayTotal = $sfDayPickup + $sfDayDelivery + $sfDayReview;
      ?>
      <div class="exp-day-block<?= $sfIsToday ? ' exp-day-block--today' : '' ?>">
        <!-- Day header -->
        <div class="exp-day-header<?= $sfIsToday ? ' exp-day-header--today' : '' ?>">
          <div class="exp-day-header__date">
            <span class="exp-day-header__name"><?= exp_day_name($sfDate) ?></span>
            <span class="exp-day-header__fmt"><?= exp_fmt_date($sfDate) ?></span>
            <?php if ($sfIsToday): ?>
              <span class="exp-day-today-badge" aria-label="Aujourd'hui">Aujourd'hui</span>
            <?php endif ?>
          </div>
          <div class="exp-day-header__metrics">
            <?php if ($sfDayHl > 0): ?>
              <span class="exp-day-metric">
                <span class="exp-day-metric__val"><?= number_format($sfDayHl, 2) ?></span>
                <span class="exp-day-metric__unit">HL</span>
              </span>
            <?php endif ?>
            <?php if ($sfDayPickup > 0): ?>
              <span class="exp-day-metric exp-day-metric--eshop-pickup" title="Retraits boutique">
                <span class="exp-day-metric__val">🏬 <?= $sfDayPickup ?></span>
                <span class="exp-day-metric__unit">retrait<?= $sfDayPickup > 1 ? 's' : '' ?></span>
              </span>
            <?php endif ?>
            <?php if ($sfDayDelivery > 0): ?>
              <span class="exp-day-metric exp-day-metric--eshop-delivery" title="Livraisons">
                <span class="exp-day-metric__val">🚚 <?= $sfDayDelivery ?></span>
                <span class="exp-day-metric__unit">livraison<?= $sfDayDelivery > 1 ? 's' : '' ?></span>
              </span>
            <?php endif ?>
            <?php if ($sfDayReview > 0): ?>
              <span class="exp-day-metric exp-day-metric--eshop-review" title="À classer">
                <span class="exp-day-metric__val">⚠ <?= $sfDayReview ?></span>
                <span class="exp-day-metric__unit">à classer</span>
              </span>
            <?php endif ?>
          </div>
        </div>

        <!-- Eshop per-order rows — reuses exp_render_eshop_row() helper -->
        <?php foreach ($sfDayOrders as $eo): ?>
          <?php exp_render_eshop_row($eo, $cmdEshopLinesByOrder[(int) $eo['id']] ?? []) ?>
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
    // Live stock hints: {sku_id: {live_futur, physique}} + anchor_month string.
    // Empty object = fg_stock_compute failed; hints silently absent.
    window.EXP_STOCK_MAP    = <?= $fgStockMapJson ?>;
    window.EXP_STOCK_ANCHOR = <?= $fgStockAnchorJson ?>;
    // Edit-mode: {sku_id: original_qty} for this order's own lines.
    // Lets JS add back the original qty to live_futur so editing doesn't
    // false-flag its own lines as over-stock.
    window.EXP_EDIT_ORIG_QTY = <?= $editOrigQtyJson ?>;
    // Line-status data for expeditions-line-status.js: [{line_id, status}, …]
    window.EXP_LINE_STATUS_DATA = <?= $lineStatusDataJson ?>;
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

      <?php
        // In create mode (no editId): customer/B2B creation is blocked — BC is the
        // sole source for customer orders since Phase 1 went live. We hide the
        // "Client" toggle and lock order_type to 'internal'.
        // In edit mode: the full toggle is shown so existing customer orders (incl.
        // bc-sourced) can still be corrected by the logistics team.
        $selType = $editOrder ? $editOrder['order_type'] : 'internal';
        $createMode = ($editId === 0);
      ?>

      <?php if ($createMode): ?>
        <!-- BC notice — customer-order create is disabled; only internal channels -->
        <div class="exp-bc-notice">
          Les commandes clients sont désormais importées automatiquement depuis Business Central.
          La création manuelle est réservée aux canaux internes.
        </div>
        <input type="hidden" name="order_type" id="exp-order-type" value="internal">

      <?php else: ?>
        <!-- Edit mode: full toggle (allows editing customer orders from any source) -->
        <div class="exp-type-toggle">
          <label class="exp-toggle-label">Type de commande</label>
          <div class="exp-toggle-group" role="radiogroup" aria-label="Type de commande">
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

        <!-- Client mode (edit only) -->
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
      <?php endif ?>

      <!-- Internal channel mode (always rendered — create uses this exclusively) -->
      <?php $selChan = $editOrder ? ($editOrder['internal_channel'] ?? '') : ''; ?>
      <div id="exp-internal-panel" class="exp-party-panel <?= ($selType !== 'internal' && !$createMode) ? 'exp-party-panel--hidden' : '' ?>">
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

        <div class="op-form__field">
          <label class="op-form__label" for="exp-fulfilment-site">Site d'expédition
            <span class="op-form__unit">optionnel</span>
          </label>
          <select name="fulfilment_site_id_fk"
                  id="exp-fulfilment-site"
                  class="op-form__select"
                  <?= $isReadOnly ? 'disabled' : '' ?>>
            <option value="">Automatique (selon client / canal)</option>
            <?php foreach ($fgStockSites as $fs): ?>
              <?php $selSite = $editOrder ? ($editOrder['fulfilment_site_id_fk'] ?? null) : null; ?>
              <option value="<?= (int) $fs['id'] ?>"
                      <?= ($selSite !== null && (int)$selSite === (int)$fs['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($fs['name']) ?>
              </option>
            <?php endforeach ?>
          </select>
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
        <span class="exp-stock-recap-warn" id="exp-stock-recap-warn" hidden
              aria-live="polite">⚠ stock insuffisant sur certaines lignes</span>
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
    <?php if (!$isReadOnly && can_write_expeditions($me)): ?>
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
  <?php if (!empty($editLines)): ?>
  <script src="/js/expeditions-line-status.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-line-status.js') ?: time() ?>"></script>
  <?php endif ?>

  <?php endif ?>


  <!-- ══════════════════════════════════════════════════════════════════════
       STOCK PF VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'stock'): ?>
  <?php
  // ── Helper: format semaines_stock for display ────────────────────────────
  function exp_fmt_semaines(?float $s, int $physique): string
  {
      if ($physique <= 0)   return '—';
      if ($s === null)      return '∞';
      if ($s > 99)          return '>99';
      return number_format($s, 1);
  }

  // ── Family grouping order — display_family (multipack aware) ─────────────
  // Hardcoding display ORDER is acceptable per spec; hardcoding SKU names is not.
  // BU/CU = single-unit convention (SKU naming: BU/CU suffix = individual bottle/can,
  // taproom-only, not full packs). Detected by sku_code suffix, NOT by name.
  $stockFamilyOrder = [
      'Keg'            => 0,
      'Bot'            => 1,
      'Can'            => 2,
      'Can33'          => 3,
      'multipack'      => 4,
      'Cuve de service'=> 5,
      "À l'unité"      => 98,  // synthetic: SKUs with sku_code ending in BU or CU
  ];

  $stockRows   = $fgStock['rows'] ?? [];
  $anchorMonth = $fgStock['anchor_month'] ?? null;
  $anchorDate  = $fgStock['anchor_date']  ?? null;

  // Collect distinct display_family values for filter chips.
  // BU/CU suffix → singles group (taproom-only SKUs, pushed to bottom).
  $presentFamilies = [];
  $hasAlerts       = false;
  foreach ($stockRows as $sr) {
      $fam = preg_match('/(BU|CU)$/', $sr['sku_code'] ?? '') ? "À l'unité" : ($sr['display_family'] ?? $sr['format']);
      $presentFamilies[$fam] = true;
      if ($sr['flag_survendu'] || $sr['flag_low_stock']) $hasAlerts = true;
  }
  uksort($presentFamilies, function ($a, $b) use ($stockFamilyOrder) {
      $oa = $stockFamilyOrder[$a] ?? 99;
      $ob = $stockFamilyOrder[$b] ?? 99;
      return $oa <=> $ob;
  });

  // ── Totals for the TOTAL strip (computed from all rows) ───────────────────
  $stockTotalPhysique = 0.0;
  $stockTotalWeekQty  = 0;
  $stockTotal2wkQty   = 0;
  $stockTotalFutQty   = 0;
  foreach ($stockRows as $sr) {
      $stockTotalPhysique += $sr['physique'];
      $stockTotalWeekQty  += $sr['open_week_qty'];
      $stockTotal2wkQty   += $sr['open_2wk_qty'];
      $stockTotalFutQty   += $sr['open_total_qty'];
  }
  $stockDiSemaine = $stockTotalPhysique - $stockTotalWeekQty;
  $stockDi2sem    = $stockTotalPhysique - $stockTotal2wkQty;
  $stockDiFutur   = $stockTotalPhysique - $stockTotalFutQty;

  // ── Pre-compute stock-health levels for ALL rows ─────────────────────────
  // Used for: alert banner counts, row data-stock-rank, badge cells, gauge cells.
  $stockLevels = [];           // sku_id → exp_stock_level() result
  $alertCounts = [             // key → count (dormant/sans_rotation excluded from banner)
      'survendu'  => 0,
      'epuise'    => 0,
      'critique'  => 0,
      'bas'       => 0,
      'suffisant' => 0,
      'eleve'     => 0,
  ];
  foreach ($stockRows as $sr) {
      $level = exp_stock_level($sr);
      $stockLevels[(int) $sr['sku_id']] = $level;
      if (isset($alertCounts[$level['key']])) {
          $alertCounts[$level['key']]++;
      }
  }

  // ── Per-location health tally for card badges ─────────────────────────────
  // Maps loc_id → ['survendu'=>N, 'critique'=>N, 'bas'=>N]
  // Built from the TOTAL view stock levels (velocity is global, not per-site).
  $locHealthTally = []; // loc_id (int) → array of counts
  if ($fgLocationSnapshot !== null) {
      foreach ($fgLocationSnapshot['locations'] as $lc) {
          $tally = ['survendu' => 0, 'critique' => 0, 'bas' => 0, 'epuise' => 0];
          foreach ($lc['rows'] as $lr) {
              $sid = (int) $lr['sku_id'];
              if (isset($stockLevels[$sid])) {
                  $key = $stockLevels[$sid]['key'];
                  if (isset($tally[$key])) $tally[$key]++;
              }
          }
          $locHealthTally[(int) $lc['id']] = $tally;
      }
  }
  ?>

  <!-- ── Stock header ────────────────────────────────────────────────────── -->
  <?php if ($anchorMonth !== null): ?>
  <div class="exp-stock-header">
    <div class="exp-stock-header__left">
      <div class="exp-stock-header__title">
        STOCK PF <span class="exp-stock-header__live">live</span>
      </div>
      <div class="exp-stock-header__anchor">
        ancre&nbsp;: comptage du
        <strong><?= exp_fmt_date($anchorDate) ?></strong>
        (<?= htmlspecialchars($anchorMonth) ?>)
        <span class="exp-stock-header__anchor-note">
          — production et expéditions depuis l'ancre
        </span>
      </div>
    </div>
  </div>

  <!-- ── 4 Location cards (clickable filters) ────────────────────────────── -->
  <?php
  $locSnap    = $fgLocationSnapshot ?? ['anchor_date' => $anchorDate, 'locations' => []];
  $locByIdArr = [];
  foreach ($locSnap['locations'] as $lc) {
      $locByIdArr[$lc['id']] = $lc;
  }
  // Date-of-week helper for freshness label
  $stNowForStock = new DateTimeImmutable(date('Y-m-d'));
  $stDowForStock = (int) $stNowForStock->format('N');
  $stMondayStock = $stNowForStock->modify('-' . ($stDowForStock - 1) . ' days')->format('Y-m-d');
  // Census-staleness threshold (days) — read from system_settings; default 7 if key absent (non-breaking)
  $stCensusStale   = max(1, (int) system_setting('fg_census_stale_days', 'fulfilment', 7));
  $stTodayForFresh = $stNowForStock; // DateTimeImmutable('today') — reused for exact-age computation
  ?>

  <!-- Live region: announces current view to screen readers -->
  <p id="exp-stock-view-label" class="exp-stock-view-label" aria-live="polite" aria-atomic="true">
    Stock — Tous les sites
  </p>

  <!-- ── D: Alert summary banner (above location cards) ──────────────────── -->
  <?php
  $bannerParts = [];
  if ($alertCounts['survendu']  > 0) $bannerParts[] = ['icon' => '⛔', 'n' => $alertCounts['survendu'],  'label' => 'survendu',  'cls' => 'exp-alert-banner__item--survendu'];
  if ($alertCounts['epuise']    > 0) $bannerParts[] = ['icon' => '○',  'n' => $alertCounts['epuise'],    'label' => 'épuisé',    'cls' => 'exp-alert-banner__item--epuise'];
  if ($alertCounts['critique']  > 0) $bannerParts[] = ['icon' => '⚠',  'n' => $alertCounts['critique'],  'label' => 'critique',  'cls' => 'exp-alert-banner__item--critique'];
  if ($alertCounts['bas']       > 0) $bannerParts[] = ['icon' => '▼',  'n' => $alertCounts['bas'],       'label' => 'bas',       'cls' => 'exp-alert-banner__item--bas'];
  if ($alertCounts['suffisant'] > 0) $bannerParts[] = ['icon' => '✓',  'n' => $alertCounts['suffisant'], 'label' => 'suffisant', 'cls' => 'exp-alert-banner__item--ok'];
  if ($alertCounts['eleve']     > 0) $bannerParts[] = ['icon' => '▲',  'n' => $alertCounts['eleve'],     'label' => 'bien fourni','cls' => 'exp-alert-banner__item--eleve'];
  ?>
  <?php if (!empty($bannerParts)): ?>
  <div class="exp-alert-banner" role="status" aria-label="Résumé santé du stock">
    <?php foreach ($bannerParts as $bp): ?>
    <span class="exp-alert-banner__item <?= $bp['cls'] ?>">
      <span aria-hidden="true"><?= $bp['icon'] ?></span>
      <strong><?= $bp['n'] ?></strong>
      <span class="exp-alert-banner__lbl"><?= htmlspecialchars($bp['label']) ?></span>
    </span>
    <?php if ($bp !== end($bannerParts)): ?>
    <span class="exp-alert-banner__sep" aria-hidden="true">·</span>
    <?php endif ?>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <div class="exp-loc-cards" role="group" aria-label="Filtre par site">
    <!-- "Tous les sites" is the default-active selector -->
    <button type="button"
            class="exp-loc-card exp-loc-card--total exp-loc-card--active"
            data-loc-id="total"
            aria-pressed="true">
      <div class="exp-loc-card__header">
        <span class="exp-loc-card__name">Tous les sites</span>
        <span class="exp-loc-card__type">Total combiné</span>
      </div>
      <div class="exp-loc-card__fresh">
        <span class="exp-st-fresh-chip exp-st-fresh-chip--ok">Vue complète</span>
      </div>
      <div class="exp-loc-card__totals">
        <div class="exp-loc-card__hl"><?= number_format(array_sum(array_column($locSnap['locations'], 'total_hl')), 1) ?> <span class="exp-loc-card__hl-unit">HL</span></div>
        <div class="exp-loc-card__units"><?= number_format(array_sum(array_column($locSnap['locations'], 'total_units'))) ?> <span class="exp-loc-card__units-label">unités</span></div>
      </div>
    </button>

    <?php foreach ($locSnap['locations'] as $lc): ?>
    <?php
      $lcId          = (int) $lc['id'];
      $lcLastCounted = $lc['last_counted'];
      $isUncounted   = $lcLastCounted === null;

      // Freshness chip — exact age vs configurable threshold ($stCensusStale days)
      if ($lcLastCounted === null) {
          $freshChip = '<span class="exp-st-fresh-chip exp-st-fresh-chip--missing">⚠ pas encore compté</span>';
      } else {
          $daysAgo = (int) $stTodayForFresh->diff(new DateTimeImmutable($lcLastCounted))->days;
          if ($daysAgo <= $stCensusStale) {
              $agoLabel  = $daysAgo === 0 ? "aujourd'hui" : ('il y a ' . $daysAgo . ' jour' . ($daysAgo > 1 ? 's' : ''));
              $freshChip = '<span class="exp-st-fresh-chip exp-st-fresh-chip--ok">✓ compté ' . $agoLabel . '</span>';
          } else {
              // Stale — combined CTA if the site also has survendu/negatives
              $lcSurvendu = (int) (($locHealthTally[$lcId] ?? [])['survendu'] ?? 0);
              if ($lcSurvendu > 0) {
                  $freshChip = '<a href="/modules/expeditions.php?view=stocktake&amp;loc=' . $lcId
                      . '" class="exp-st-fresh-chip exp-st-fresh-chip--cta">'
                      . '⚠ à recompter — ' . $lcSurvendu . ' SKU en négatif</a>';
              } else {
                  $freshChip = '<span class="exp-st-fresh-chip exp-st-fresh-chip--warn">'
                      . 'à recompter il y a ' . $daysAgo . ' jour' . ($daysAgo > 1 ? 's' : '') . '</span>';
              }
          }
      }

      $lcTally      = $locHealthTally[$lcId] ?? ['survendu' => 0, 'critique' => 0, 'bas' => 0, 'epuise' => 0];
      $lcTallyParts = [];
      if ($lcTally['survendu'] > 0) $lcTallyParts[] = '<span class="exp-loc-health-dot exp-loc-health-dot--survendu" title="' . $lcTally['survendu'] . ' survendu">⛔' . $lcTally['survendu'] . '</span>';
      if ($lcTally['critique'] > 0) $lcTallyParts[] = '<span class="exp-loc-health-dot exp-loc-health-dot--critique" title="' . $lcTally['critique'] . ' critique">⚠' . $lcTally['critique'] . '</span>';
      if ($lcTally['bas']      > 0) $lcTallyParts[] = '<span class="exp-loc-health-dot exp-loc-health-dot--bas" title="' . $lcTally['bas'] . ' bas">▼' . $lcTally['bas'] . '</span>';
    ?>
    <button type="button"
            class="exp-loc-card<?= $isUncounted ? ' exp-loc-card--uncounted' : '' ?>"
            data-loc-id="<?= $lcId ?>"
            data-loc-name="<?= htmlspecialchars($lc['name']) ?>"
            data-loc-type="<?= htmlspecialchars(exp_site_type_label($lc['site_type'])) ?>"
            aria-pressed="false">
      <div class="exp-loc-card__header">
        <span class="exp-loc-card__name"><?= htmlspecialchars($lc['name']) ?></span>
        <span class="exp-loc-card__type"><?= htmlspecialchars(exp_site_type_label($lc['site_type'])) ?></span>
      </div>
      <div class="exp-loc-card__fresh">
        <?= $freshChip ?>
        <?php if (!empty($lcTallyParts)): ?>
        <span class="exp-loc-health-tally" aria-label="Alertes stock à ce site"><?= implode(' ', $lcTallyParts) ?></span>
        <?php endif ?>
      </div>
      <?php if (!$isUncounted): ?>
      <div class="exp-loc-card__totals">
        <div class="exp-loc-card__hl"><?= number_format($lc['total_hl'], 1) ?> <span class="exp-loc-card__hl-unit">HL</span></div>
        <div class="exp-loc-card__units"><?= number_format($lc['total_units']) ?> <span class="exp-loc-card__units-label">unités</span></div>
      </div>
      <?php else: ?>
      <div class="exp-loc-card__totals exp-loc-card__totals--empty">
        <div class="exp-loc-card__hl exp-loc-card__hl--dash">—</div>
        <div class="exp-loc-card__units exp-loc-card__units--empty">0 unités</div>
      </div>
      <?php endif ?>
    </button>
    <?php endforeach ?>
  </div>

  <!-- ── Total view (default): TOTAL strip + family filters + full table ── -->
  <div id="exp-loc-table-total" class="exp-loc-view">

  <!-- ── TOTAL strip ─────────────────────────────────────────────────────── -->
  <div class="exp-stock-total-strip" role="region" aria-label="Totaux stock PF">
    <div class="exp-stock-total-kpi">
      <span class="exp-stock-total-kpi__label">Physique total</span>
      <span class="exp-stock-total-kpi__val"><?= number_format($stockTotalPhysique) ?></span>
    </div>
    <?php if ($fgHomeSiteStock !== null): ?>
    <div class="exp-stock-total-kpi exp-st-home-site"
         title="Stock physique à : <?= htmlspecialchars($fgHomeSiteStock['name']) ?>">
      <span class="exp-stock-total-kpi__label exp-st-home-site__label"><?= htmlspecialchars($fgHomeSiteStock['name']) ?></span>
      <span class="exp-stock-total-kpi__val exp-st-home-site__val"><?= number_format($fgHomeSiteStock['units']) ?></span>
    </div>
    <?php endif ?>
    <div class="exp-stock-total-sep" aria-hidden="true"></div>
    <div class="exp-stock-total-kpi<?= $stockDiSemaine < 0 ? ' exp-stock-total-kpi--neg' : '' ?>">
      <span class="exp-stock-total-kpi__label">
        − commandes sem. courante
        <?php if ($stockDiSemaine < 0): ?>
          <span class="exp-stock-total-kpi__flag" aria-label="Survendu">⚠ survendu</span>
        <?php endif ?>
      </span>
      <span class="exp-stock-total-kpi__val"><?= number_format($stockDiSemaine) ?></span>
    </div>
    <div class="exp-stock-total-sep" aria-hidden="true"></div>
    <div class="exp-stock-total-kpi<?= $stockDi2sem < 0 ? ' exp-stock-total-kpi--neg' : '' ?>">
      <span class="exp-stock-total-kpi__label">
        − commandes 2 semaines
        <?php if ($stockDi2sem < 0): ?>
          <span class="exp-stock-total-kpi__flag" aria-label="Survendu">⚠ survendu</span>
        <?php endif ?>
      </span>
      <span class="exp-stock-total-kpi__val"><?= number_format($stockDi2sem) ?></span>
    </div>
    <div class="exp-stock-total-sep" aria-hidden="true"></div>
    <div class="exp-stock-total-kpi<?= $stockDiFutur < 0 ? ' exp-stock-total-kpi--neg' : '' ?>">
      <span class="exp-stock-total-kpi__label">
        − toutes commandes ouvertes
        <?php if ($stockDiFutur < 0): ?>
          <span class="exp-stock-total-kpi__flag" aria-label="Survendu">⚠ survendu</span>
        <?php endif ?>
      </span>
      <span class="exp-stock-total-kpi__val"><?= number_format($stockDiFutur) ?></span>
    </div>
  </div>

  <!-- ── Family filter chips + alerts toggle ─────────────────────────────── -->
  <div class="exp-stock-filters" role="toolbar" aria-label="Filtres famille">
    <button type="button"
            class="exp-stock-chip exp-stock-chip--active"
            data-filter-family="all"
            aria-pressed="true">Tous</button>
    <?php foreach (array_keys($presentFamilies) as $fam): ?>
    <button type="button"
            class="exp-stock-chip"
            data-filter-family="<?= htmlspecialchars($fam) ?>"
            aria-pressed="false">
      <?= htmlspecialchars(exp_family_label(exp_format_family($fam))) ?>
    </button>
    <?php endforeach ?>
    <?php if ($hasAlerts): ?>
    <button type="button"
            class="exp-stock-chip exp-stock-chip--alert"
            data-filter-family="alerts"
            aria-pressed="false">
      <span aria-hidden="true">⚠</span> alertes
    </button>
    <?php endif ?>
    <label class="exp-stock-dormant-toggle">
      <input type="checkbox" id="exp-stock-show-dormant" aria-label="Afficher SKUs dormants">
      Afficher SKUs dormants
    </label>
    <label class="exp-stock-sort-toggle">
      <input type="checkbox" id="exp-stock-sort-alerts" aria-label="Tri alertes d'abord">
      Alertes d'abord
    </label>
  </div>

  <!-- ── E: Legend — maps 6 states to icon + label ─────────────────────────── -->
  <details class="exp-stock-legend" aria-label="Légende niveaux de stock">
    <summary class="exp-stock-legend__toggle">Légende niveaux de stock</summary>
    <div class="exp-stock-legend__body" role="list">
      <span class="exp-stock-legend__item exp-lvl--survendu" role="listitem"><span aria-hidden="true">⛔</span> Survendu — commandes > stock disponible</span>
      <span class="exp-stock-legend__item exp-lvl--epuise"   role="listitem"><span aria-hidden="true">○</span> Épuisé — stock physique à 0</span>
      <span class="exp-stock-legend__item exp-lvl--critique" role="listitem"><span aria-hidden="true">⚠</span> Critique — moins de <?= WEEKS_CRITICAL ?> sem. de stock</span>
      <span class="exp-stock-legend__item exp-lvl--bas"      role="listitem"><span aria-hidden="true">▼</span> Bas — moins de <?= WEEKS_LOW ?> sem. de stock</span>
      <span class="exp-stock-legend__item exp-lvl--ok"       role="listitem"><span aria-hidden="true">✓</span> Suffisant — moins de <?= WEEKS_HIGH ?> sem. de stock</span>
      <span class="exp-stock-legend__item exp-lvl--eleve"    role="listitem"><span aria-hidden="true">▲</span> Bien fourni — <?= WEEKS_HIGH ?>+ sem. de stock</span>
      <span class="exp-stock-legend__item exp-lvl--dormant"  role="listitem"><span aria-hidden="true">—</span> Sans rotation — stock sans vélocité calculée</span>
    </div>
  </details>

  <?php
  // ── Seasonal freshness note ───────────────────────────────────────────────
  // Read burn_cache_cold and burn_index_computed_at from the first stock row.
  // Both fields share the same value across all rows (index is global, not per-SKU).
  $firstStockRow         = $stockRows[0] ?? null;
  $burnCacheCold         = !empty($firstStockRow) && !empty($firstStockRow['burn_cache_cold']);
  $burnIndexComputedAt   = $firstStockRow['burn_index_computed_at'] ?? null;
  // Format the timestamp day-first JJ/MM (house convention: day-first fr-CH).
  $burnIndexDateLabel = null;
  if ($burnIndexComputedAt !== null) {
      $burnIndexTs = strtotime((string) $burnIndexComputedAt);
      if ($burnIndexTs !== false) {
          $burnIndexDateLabel = date('d/m', $burnIndexTs);
      }
  }
  ?>
  <?php if (!empty($stockRows)): ?>
  <p class="exp-burn-freshness<?= $burnCacheCold ? ' exp-burn-freshness--cold' : '' ?>">
    <?php if ($burnCacheCold): ?>
      <span aria-hidden="true">⏳</span>
      Courbe saisonnière en cours de préparation — estimations provisoires
    <?php else: ?>
      <span aria-hidden="true">〜</span>
      Projection saisonnière<?= $burnIndexDateLabel !== null ? ' · indice au&nbsp;' . htmlspecialchars($burnIndexDateLabel) : '' ?>
    <?php endif ?>
  </p>
  <?php endif ?>

  <!-- ── Stock table (grouped by display_family) ────────────────────────── -->
  <?php
  // Resolve effective family for a stock row — BU/CU suffix → singles group.
  // BU/CU = single-unit SKUs (taproom-only), pushed last regardless of display_family.
  $eff_stock_family = function (array $sr) use ($stockFamilyOrder): string {
      return preg_match('/(BU|CU)$/', $sr['sku_code'] ?? '') ? "À l'unité" : ($sr['display_family'] ?? $sr['format']);
  };

  // Sort rows: by health rank ASC (worst first), then family order, then sku_code.
  // This gives worst-first order across all families by default — Kouros asked
  // for alerts surfaced at top. The "Alertes d'abord" JS toggle (sort by rank only,
  // ignoring family) remains available for flat rank-only ordering.
  usort($stockRows, function ($a, $b) use ($stockFamilyOrder, $stockLevels, $eff_stock_family) {
      $rankA = $stockLevels[(int) $a['sku_id']]['rank'] ?? 99;
      $rankB = $stockLevels[(int) $b['sku_id']]['rank'] ?? 99;
      if ($rankA !== $rankB) return $rankA <=> $rankB;
      $famA = $eff_stock_family($a);
      $famB = $eff_stock_family($b);
      $oa   = $stockFamilyOrder[$famA] ?? 99;
      $ob   = $stockFamilyOrder[$famB] ?? 99;
      if ($oa !== $ob) return $oa <=> $ob;
      return strcmp($a['sku_code'], $b['sku_code']);
  });

  // Group into families for section headers.
  // BU/CU suffix routes to the synthetic singles group, not their display_family.
  $stockByFamily = [];
  foreach ($stockRows as $sr) {
      $fam = $eff_stock_family($sr);
      $stockByFamily[$fam][] = $sr;
  }
  // Family order for grouped display
  $stockFamilyKeys = array_unique(array_merge(
      array_intersect(array_keys($stockFamilyOrder), array_keys($stockByFamily)),
      array_diff(array_keys($stockByFamily), array_keys($stockFamilyOrder))
  ));
  ?>

  <div class="exp-stock-table-wrap">
    <table class="exp-stock-table" id="exp-stock-table">
      <thead>
        <!-- Top row: group super-headers. Identity + physique columns use rowspan="2". -->
        <tr>
          <th class="exp-st-col-badge" rowspan="2" aria-label="Niveau de stock"></th>
          <th class="exp-st-col-sku"   rowspan="2">SKU</th>
          <th class="exp-st-col-gauge" rowspan="2" title="Semaines de couverture — projetée sur la saisonnalité des 24 derniers mois (barre = niveau de stock)">Couverture</th>
          <th class="exp-st-th--sep"   rowspan="2" aria-hidden="true"></th>
          <th class="exp-st-col-physique exp-st-th-actuel" rowspan="2" title="Stock physique actuel = ancre + production − ventes depuis l'ancre">Physique</th>
          <th class="exp-st-th--sep"   rowspan="2" aria-hidden="true"></th>
          <th class="exp-st-th-group"  scope="colgroup" colspan="3">Dispo après commandes</th>
          <th class="exp-st-th--sep"   rowspan="2" aria-hidden="true"></th>
          <th class="exp-st-col-flag"  rowspan="2"></th>
        </tr>
        <!-- Bottom row: individual column labels under the prévisionnel band. -->
        <tr>
          <th class="exp-st-col-semcur" scope="col"
              title="Stock restant après les commandes ouvertes dues cette semaine (physique − open_week_qty)">Sem. courante</th>
          <th class="exp-st-col-semcur" scope="col"
              title="Stock restant après les commandes ouvertes dues d'ici la fin de la semaine prochaine (physique − open_2wk_qty)">+ sem. suivante</th>
          <th class="exp-st-col-futur"  scope="col"
              title="Stock restant après toutes les commandes ouvertes (physique − open_total_qty)">Toutes cmdes</th>
        </tr>
      </thead>
      <tbody id="exp-stock-tbody">
      <?php foreach ($stockFamilyKeys as $groupFam):
        if (empty($stockByFamily[$groupFam])) continue;
        // Synthetic singles group gets its own label; real families use the shared helper.
        $groupLabel = ($groupFam === "À l'unité")
            ? "À l'unité (taproom)"
            : exp_family_label(exp_format_family($groupFam));
        $groupCount = count($stockByFamily[$groupFam]);
      ?>
        <tr class="exp-stock-family-header" data-family-group="<?= htmlspecialchars($groupFam) ?>" aria-hidden="true">
          <td colspan="11" class="exp-stock-family-header__cell">
            <span class="exp-stock-family-header__label"><?= htmlspecialchars($groupLabel) ?></span>
            <span class="exp-stock-family-header__count"><?= $groupCount ?> SKU<?= $groupCount !== 1 ? 's' : '' ?></span>
          </td>
        </tr>
      <?php
        foreach ($stockByFamily[$groupFam] as $sr):
          $isDormant   = $sr['flag_dormant'];
          $isSurvendu  = $sr['flag_survendu'];
          $isLowStock  = $sr['flag_low_stock'];
          $hasFlag     = $isSurvendu || $isLowStock;
          $physique    = $sr['physique'];
          $isCage      = (($sr['stocktake_scope'] ?? '') === 'cage');
          $hlPhysique  = round($physique * $sr['hl_per_unit'], 2);
          $rowFam      = $sr['display_family'] ?? $sr['format'];

          // ── Stock-health level (centralised helper) ───────────────────────
          $slevel    = $stockLevels[(int) $sr['sku_id']] ?? exp_stock_level($sr);
          $slKey     = $slevel['key'];
          $slLabel   = $slevel['label'];
          $slIcon    = $slevel['icon'];
          $slClass   = $slevel['class'];
          $slRank    = $slevel['rank'];

          // ── Row CSS classes ───────────────────────────────────────────────
          // C: critical-row emphasis for survendu/epuise/critique
          $accentRow = in_array($slKey, ['survendu', 'epuise', 'critique'], true);
          $rowClass  = 'exp-stock-row';
          if ($isDormant)   $rowClass .= ' exp-stock-row--dormant';
          if ($accentRow)   $rowClass .= ' exp-stock-row--accent';
          if ($isSurvendu)  $rowClass .= ' exp-stock-row--survendu';
          if ($hasFlag)     $rowClass .= ' exp-stock-row--flagged';

          // ── B: Weeks-of-cover gauge ───────────────────────────────────────
          // Width proportional to semaines_stock capped at WEEKS_HIGH (= 100%)
          $semaines     = $sr['semaines_stock'];
          $semVal       = exp_fmt_semaines($semaines, (int) round((float) $physique));
          $gaugeWidthPct = 0;
          if ($semaines !== null && $physique > 0) {
              $gaugeWidthPct = min(100, round(($semaines / WEEKS_HIGH) * 100));
          } elseif ($physique > 0 && $semaines === null) {
              $gaugeWidthPct = 0; // no velocity — muted display
          }
          // Gauge text fallback for a11y: screenreader sees "X.X sem" via aria-label on cell
          // Live semaine / 2sem / futur: color coding
          $semClass    = $sr['live_semaine'] < 0  ? ' exp-st-neg' : '';
          $twoSemClass = $sr['live_2sem']    < 0  ? ' exp-st-neg' : '';
          $futClass    = $sr['live_futur']   < 0  ? ' exp-st-neg' : '';

          // Per-location mini-breakdown from snapshot (nice-to-have)
          $locBreakdown = '';
          if ($fgLocationSnapshot !== null) {
              $parts = [];
              foreach ($fgLocationSnapshot['locations'] as $lc) {
                  foreach ($lc['rows'] as $lr) {
                      if ($lr['sku_id'] === (int) $sr['sku_id'] && $lr['qty'] > 0) {
                          $parts[] = htmlspecialchars(exp_site_type_label($lc['site_type'])[0]) . ':' . number_format($lr['qty']);
                      }
                  }
              }
              if (!empty($parts)) {
                  $locBreakdown = '<span class="exp-st-loc-breakdown">' . implode(' ', $parts) . '</span>';
              }
          }
      ?>
          <tr class="<?= $rowClass ?>"
              data-family="<?= htmlspecialchars($rowFam) ?>"
              data-format="<?= htmlspecialchars($sr['format']) ?>"
              data-has-flag="<?= $hasFlag ? '1' : '0' ?>"
              data-dormant="<?= $isDormant ? '1' : '0' ?>"
              data-sku-id="<?= (int) $sr['sku_id'] ?>"
              data-stock-rank="<?= $slRank ?>"
              aria-expanded="false">
            <!-- A: Status badge -->
            <td class="exp-st-col-badge" aria-label="<?= htmlspecialchars($slLabel) ?>">
              <span class="exp-stock-badge <?= $slClass ?>"
                    title="<?= htmlspecialchars($slLabel) ?>">
                <span class="exp-stock-badge__icon" aria-hidden="true"><?= $slIcon ?></span>
                <span class="exp-stock-badge__label"><?= htmlspecialchars($slLabel) ?></span>
              </span>
            </td>
            <td class="exp-st-col-sku">
              <span class="exp-st-sku-code"><?= htmlspecialchars($sr['sku_code']) ?></span>
              <span class="exp-st-sku-hl"><?= number_format($hlPhysique, 2) ?> HL</span>
              <?= $locBreakdown ?>
            </td>
            <!-- B: Weeks-of-cover gauge -->
            <td class="exp-st-col-gauge"
                aria-label="<?= htmlspecialchars($slLabel . ($semaines !== null ? ', ' . $semVal . ' sem.' : '')) ?>">
              <div class="exp-gauge">
                <div class="exp-gauge__track">
                  <div class="exp-gauge__fill <?= $slClass ?>"
                       style="width:<?= $gaugeWidthPct ?>%"
                       aria-hidden="true"></div>
                </div>
                <span class="exp-gauge__label" aria-hidden="true">
                  <?php if ($physique <= 0): ?>
                    <span class="exp-gauge__label--muted">— sem</span>
                  <?php elseif ($semaines === null): ?>
                    <span class="exp-gauge__label--muted">∞</span>
                  <?php else: ?>
                    <?= htmlspecialchars($semVal) ?> sem
                  <?php endif ?>
                </span>
              </div>
              <?php if (($sr['burn_status'] ?? null) === 'provisoire'): ?>
              <span class="exp-burn-provisoire"
                    aria-label="Estimation provisoire"
                    title="Historique insuffisant — estimation provisoire (SKU récent ou peu de ventes)">≈ provisoire</span>
              <?php endif ?>
            </td>
            <td class="exp-st--sep" aria-hidden="true"></td>
            <td class="exp-st-col-physique">
              <span class="exp-st-num"><?= exp_fmt_stock_qty($physique, $isCage) ?></span>
            </td>
            <td class="exp-st--sep" aria-hidden="true"></td>
            <td class="exp-st-col-semcur">
              <span class="exp-st-num<?= $semClass ?>"><?= number_format($sr['live_semaine']) ?></span>
            </td>
            <td class="exp-st-col-semcur">
              <span class="exp-st-num<?= $twoSemClass ?>"><?= number_format($sr['live_2sem']) ?></span>
            </td>
            <td class="exp-st-col-futur">
              <span class="exp-st-num<?= $futClass ?>"><?= number_format($sr['live_futur']) ?></span>
            </td>
            <td class="exp-st--sep" aria-hidden="true"></td>
            <td class="exp-st-col-flag"></td>
          </tr>
          <!-- Drill-down ledger (hidden by default, toggled by JS) -->
          <tr class="exp-stock-drill" id="exp-drill-<?= (int) $sr['sku_id'] ?>" hidden>
            <td colspan="11">
              <div class="exp-stock-ledger">
                <div class="exp-stock-ledger__title">
                  Détail — <?= htmlspecialchars($sr['sku_code']) ?>
                </div>
                <!-- Segmented toggle: Stock | Activité -->
                <div class="exp-evt-tabs" role="tablist" aria-label="Vues du détail">
                  <button class="exp-evt-tab" role="tab"
                    id="exp-tab-stock-<?= (int) $sr['sku_id'] ?>"
                    aria-selected="true"
                    aria-controls="exp-pane-stock-<?= (int) $sr['sku_id'] ?>"
                    data-evt-tab="stock"
                    tabindex="0">Stock</button>
                  <button class="exp-evt-tab" role="tab"
                    id="exp-tab-activite-<?= (int) $sr['sku_id'] ?>"
                    aria-selected="false"
                    aria-controls="exp-pane-activite-<?= (int) $sr['sku_id'] ?>"
                    data-evt-tab="activite"
                    tabindex="-1">Activité</button>
                  <button class="exp-evt-tab" role="tab"
                    id="exp-tab-couverture-<?= (int) $sr['sku_id'] ?>"
                    aria-selected="false"
                    aria-controls="exp-pane-couverture-<?= (int) $sr['sku_id'] ?>"
                    data-evt-tab="couverture"
                    tabindex="-1">Couverture</button>
                </div>
                <!-- Stock pane: anchor/prod/sales/physique/répartition/open-orders -->
                <div class="exp-evt-pane"
                  id="exp-pane-stock-<?= (int) $sr['sku_id'] ?>"
                  role="tabpanel"
                  aria-labelledby="exp-tab-stock-<?= (int) $sr['sku_id'] ?>">
                <table class="exp-ledger-table">
                  <tbody>
                    <tr class="exp-ledger-anchor">
                      <td class="exp-ledger-label">Inventaire <?= htmlspecialchars($anchorMonth) ?> (<?= exp_fmt_date($anchorDate) ?>)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--anchor">
                        <?= exp_fmt_stock_qty((float) $sr['anchor_qty'], $isCage) ?>
                      </td>
                      <td class="exp-ledger-meta">ancre Σ toutes locations</td>
                    </tr>
                    <tr class="exp-ledger-prod">
                      <td class="exp-ledger-label">Production (unités SKU)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--plus">
                        +<?= number_format((int) $sr['prod_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">
                        <?= $sr['prod_events'] ?> run<?= $sr['prod_events'] !== 1 ? 's' : '' ?>
                        · event_date > <?= exp_fmt_date($anchorDate) ?>
                      </td>
                    </tr>
                    <?php if ($sr['expedie_qty'] > 0 || $sr['expedie_orders'] > 0): ?>
                    <tr class="exp-ledger-exp">
                      <td class="exp-ledger-label">Expédié (commandes)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--minus">
                        −<?= number_format($sr['expedie_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">
                        <?= $sr['expedie_orders'] ?> commande<?= $sr['expedie_orders'] !== 1 ? 's' : '' ?> livrée<?= $sr['expedie_orders'] !== 1 ? 's' : '' ?>
                      </td>
                    </tr>
                    <?php endif ?>
                    <?php if ($sr['eshop_qty'] > 0 || $sr['eshop_orders'] > 0): ?>
                    <tr class="exp-ledger-eshop">
                      <td class="exp-ledger-label">Eshop (auto)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--minus">
                        −<?= number_format($sr['eshop_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">
                        <?= $sr['eshop_orders'] ?> ligne<?= $sr['eshop_orders'] !== 1 ? 's' : '' ?>
                      </td>
                    </tr>
                    <?php endif ?>
                    <?php if ($sr['taproom_qty'] > 0 || $sr['taproom_rows'] > 0): ?>
                    <tr class="exp-ledger-tap">
                      <td class="exp-ledger-label">Taproom (auto)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--minus">
                        −<?= number_format($sr['taproom_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">
                        <?= $sr['taproom_rows'] ?> ligne<?= $sr['taproom_rows'] !== 1 ? 's' : '' ?> inv_sales_bc
                      </td>
                    </tr>
                    <?php endif ?>
                    <tr class="exp-ledger-physique">
                      <td class="exp-ledger-label exp-ledger-label--total">Physique</td>
                      <td class="exp-ledger-qty exp-ledger-qty--total" colspan="2">
                        = <?= exp_fmt_stock_qty($physique, $isCage) ?>
                        <span class="exp-ledger-hl">(<?= number_format($hlPhysique, 2) ?> HL)</span>
                      </td>
                    </tr>
                    <?php
                    // ── Per-location physique breakdown ───────────────────────────────────────
                    // Decomposes the physique total by ref_sites location.
                    // Gate: qty != 0 (NOT qty > 0) — deliberately surfaces negative per-location
                    // qty as an honest oversold signal, using exp-st-neg for visual flagging.
                    // NOTE: this is an intentional asymmetry vs the inline cell $locBreakdown above
                    // (which gates qty > 0 for glance-only display). Do NOT "fix" this to >0 —
                    // a future reader who does so would silently hide oversold-per-location signals.
                    if ($fgLocationSnapshot !== null):
                        $locPhysiqueRows = []; // [{name, qty, hl}]
                        foreach ($fgLocationSnapshot['locations'] as $lc) {
                            foreach ($lc['rows'] as $lr) {
                                if ((int) $lr['sku_id'] === (int) $sr['sku_id'] && (float) $lr['qty'] != 0) {
                                    $locPhysiqueRows[] = [
                                        'name'      => $lc['name'],
                                        'site_type' => $lc['site_type'],
                                        'qty'       => (float) $lr['qty'],
                                        'hl'        => round((float) $lr['qty'] * (float) $sr['hl_per_unit'], 2),
                                    ];
                                }
                            }
                        }
                        // Residual check: if per-location sum ≠ physique total, show a muted
                        // écart line (same-day-transfer residual is an accepted case — do NOT assert).
                        $locSum = array_sum(array_column($locPhysiqueRows, 'qty'));
                    ?>
                    <?php if (!empty($locPhysiqueRows)): ?>
                    <tr class="exp-ledger-loc-header">
                      <td class="exp-ledger-label exp-ledger-meta" colspan="3">Répartition par site</td>
                    </tr>
                    <?php foreach ($locPhysiqueRows as $locRow): ?>
                    <tr class="exp-ledger-loc-row">
                      <td class="exp-ledger-label exp-ledger-meta">
                        <?= htmlspecialchars($locRow['name']) ?>
                        <span class="exp-ledger-meta exp-ledger-loc-qualifier"> · <?= htmlspecialchars(exp_site_type_label($locRow['site_type'])) ?></span>
                      </td>
                      <td class="exp-ledger-qty<?= $locRow['qty'] < 0 ? ' exp-st-neg' : '' ?>">
                        <?= exp_fmt_stock_qty($locRow['qty'], $isCage) ?>
                        <span class="exp-ledger-hl">(<?= number_format($locRow['hl'], 2) ?> HL)</span>
                      </td>
                      <td class="exp-ledger-meta"></td>
                    </tr>
                    <?php endforeach ?>
                    <?php if (abs($locSum - $physique) > 0.001): ?>
                    <tr class="exp-ledger-loc-ecart">
                      <td class="exp-ledger-label exp-ledger-meta">écart de comptage / en transit</td>
                      <td class="exp-ledger-qty exp-ledger-meta">
                        <?= (($physique - $locSum) >= 0 ? '+' : '') . exp_fmt_stock_qty(abs($physique - $locSum), $isCage) ?>
                      </td>
                      <td class="exp-ledger-meta"></td>
                    </tr>
                    <?php endif ?>
                    <?php endif ?>
                    <?php endif ?>
                    <?php
                    // ── "Activité récente" — merge 3 type-buckets, sort DESC, cap 15 ──
                    // $timelineBySkuId was bulk-loaded above (3 queries, no per-SKU WHERE).
                    $skuTimeline = [];
                    if (!empty($timelineBySkuId[(int) $sr['sku_id']])) {
                        $skuTimeline = $timelineBySkuId[(int) $sr['sku_id']];
                        // Sort all events for this SKU by date DESC, then by type for stable order
                        usort($skuTimeline, function (array $a, array $b): int {
                            $cmp = strcmp($b['date'], $a['date']);
                            return $cmp !== 0 ? $cmp : strcmp($a['type'], $b['type']);
                        });
                        // Cap at 50 most-recent events across all types
                        if (count($skuTimeline) > 50) {
                            $skuTimeline = array_slice($skuTimeline, 0, 50);
                        }
                    }
                    ?>
                    <?php if ($sr['open_week_qty'] > 0 || $sr['open_total_qty'] > 0): ?>
                    <tr class="exp-ledger-sep"><td colspan="3"></td></tr>
                    <?php if ($sr['open_week_qty'] > 0): ?>
                    <tr class="exp-ledger-open">
                      <td class="exp-ledger-label">Commandes ouvertes (sem. courante)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--open">
                        −<?= number_format($sr['open_week_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">→ sem. courante : <?= number_format($sr['live_semaine']) ?></td>
                    </tr>
                    <?php endif ?>
                    <?php $totalWeekDemand = ($sr['open_week_qty'] ?? 0) + ($sr['shipped_week_qty'] ?? 0); ?>
                    <?php if ($totalWeekDemand > 0): ?>
                    <tr class="exp-ledger-week-total">
                      <td class="exp-ledger-label">Commandé cette semaine (total)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--open">
                        <?= number_format($totalWeekDemand) ?>
                      </td>
                      <td class="exp-ledger-meta">= commandes ouvertes + déjà expédiées cette semaine</td>
                    </tr>
                    <?php endif ?>
                    <?php if ($sr['open_2wk_qty'] > $sr['open_week_qty']): ?>
                    <tr class="exp-ledger-open">
                      <td class="exp-ledger-label">Commandes ouvertes (sem. courante + suivante)</td>
                      <td class="exp-ledger-qty exp-ledger-qty--open">
                        −<?= number_format($sr['open_2wk_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">→ 2 sem. : <?= number_format($sr['live_2sem']) ?></td>
                    </tr>
                    <?php endif ?>
                    <?php if ($sr['open_total_qty'] !== $sr['open_week_qty']): ?>
                    <tr class="exp-ledger-open">
                      <td class="exp-ledger-label">Toutes commandes ouvertes</td>
                      <td class="exp-ledger-qty exp-ledger-qty--open">
                        −<?= number_format($sr['open_total_qty']) ?>
                      </td>
                      <td class="exp-ledger-meta">→ avec futur : <?= number_format($sr['live_futur']) ?></td>
                    </tr>
                    <?php endif ?>
                    <?php endif ?>
                    <?php
                    // rythme_base = deseasonalized weekly run-rate; same value as velocity_weekly (back-compat alias).
                    $rythmBase = $sr['rythme_base'] ?? $sr['velocity_weekly'] ?? null;
                    ?>
                    <?php if ($rythmBase !== null): ?>
                    <tr class="exp-ledger-sep"><td colspan="3"></td></tr>
                    <tr class="exp-ledger-vel">
                      <td class="exp-ledger-label exp-ledger-label--rythme">rythme de base</td>
                      <td class="exp-ledger-qty exp-ledger-qty--rythme">
                        <?= number_format((float) $rythmBase, 1) ?>/sem
                      </td>
                      <td class="exp-ledger-meta">→ <?= htmlspecialchars(exp_fmt_semaines($sr['semaines_stock'], (int) round((float) $physique))) ?> sem. de stock (saisonnier)</td>
                    </tr>
                    <?php endif ?>
                  </tbody>
                </table>
                </div><!-- /exp-evt-pane stock -->
                <!-- Activité pane: timeline events in own scrollable table -->
                <div class="exp-evt-pane exp-evt-pane--activite"
                  id="exp-pane-activite-<?= (int) $sr['sku_id'] ?>"
                  role="region"
                  aria-label="Activité récente"
                  aria-labelledby="exp-tab-activite-<?= (int) $sr['sku_id'] ?>"
                  tabindex="0"
                  hidden>
                <table class="exp-ledger-table">
                  <tbody>
                    <tr class="exp-ledger-loc-header exp-evt-section-header">
                      <td class="exp-ledger-label exp-ledger-meta" colspan="3">Activité récente</td>
                    </tr>
                    <?php if (empty($skuTimeline)): ?>
                    <tr class="exp-ledger-evt exp-ledger-evt--empty">
                      <td class="exp-ledger-label exp-ledger-meta exp-evt-empty" colspan="3">Aucune activité récente</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($skuTimeline as $evt): ?>
                    <tr class="exp-ledger-evt exp-ledger-evt--<?= htmlspecialchars($evt['type']) ?>">
                      <td class="exp-ledger-label exp-evt-label">
                        <span class="exp-evt-glyph exp-evt-glyph--<?= htmlspecialchars($evt['sign']) ?>"><?= match($evt['sign']) {
                            'in'      => '↑',
                            'out'     => '↓',
                            'neutral' => '↔',
                            default   => '·',
                        } ?></span>
                        <span class="exp-evt-date"><?= exp_fmt_date($evt['date']) ?></span>
                        <span class="exp-evt-desc"><?= htmlspecialchars($evt['label']) ?></span>
                      </td>
                      <td class="exp-ledger-qty exp-evt-qty">
                        <?= number_format($evt['qty']) ?>
                      </td>
                      <td class="exp-ledger-meta exp-evt-type-label"><?= match($evt['type']) {
                          'order' => 'Commande',
                          'pkg'   => 'Production',
                          'move'  => 'Mouvement',
                          default => '',
                      } ?></td>
                    </tr>
                    <?php endforeach ?>
                    <?php endif ?>
                  </tbody>
                </table>
                </div><!-- /exp-evt-pane activite -->
                <!-- Couverture pane: order-aware stock projection -->
                <?php
                $cov          = $sr['couverture'] ?? [];
                $covPhysique  = isset($cov['physique'])    ? (float) $cov['physique']    : null;
                $covAnchor    = isset($cov['anchor_qty'])  ? (int)   $cov['anchor_qty']  : null;
                $covProd      = isset($cov['prod_qty'])    ? (int)   $cov['prod_qty']    : 0;
                $covExp       = isset($cov['expedie_qty']) ? (int)   $cov['expedie_qty'] : 0;
                $covEshop     = isset($cov['eshop_qty'])   ? (int)   $cov['eshop_qty']   : 0;
                $covTaproom   = isset($cov['taproom_qty']) ? (int)   $cov['taproom_qty'] : 0;
                $covOpenTotal = isset($cov['open_total'])  ? (int)   $cov['open_total']  : 0;
                $covOpenBook  = $cov['open_book']  ?? [];
                $covLiveFutur = isset($cov['live_futur'])  ? (int)   $cov['live_futur']  : null;
                $covRythme    = isset($cov['rythme_base']) ? (float) $cov['rythme_base'] : null;
                $covNonzero   = $cov['nonzero_weeks']  ?? null;
                $covWeeks     = $cov['weeks_present']  ?? null;
                $covSpike     = isset($cov['recent_spike']) ? (float) $cov['recent_spike'] : null;
                $covSeasonal  = $cov['seasonal']    ?? [];
                $covProj      = $cov['projection']  ?? [];
                $covBurnSt    = $cov['burn_status'] ?? null;

                // Status-label map (order statuses → French)
                $covStatusLabels = [
                    'entered'    => 'Saisie',
                    'confirmed'  => 'Confirmée',
                    'picked'     => 'Préparée',
                    'bl_printed' => 'BL',
                ];
                ?>
                <div class="exp-evt-pane exp-evt-pane--couverture"
                  id="exp-pane-couverture-<?= (int) $sr['sku_id'] ?>"
                  role="tabpanel"
                  aria-labelledby="exp-tab-couverture-<?= (int) $sr['sku_id'] ?>"
                  hidden>

                  <!-- ① Explainer -->
                  <p class="exp-cov-explainer">
                    Couverture = stock physique − commandes engagées, projetée sur la saisonnalité
                  </p>

                  <?php if (empty($cov)): ?>
                  <p class="exp-cov-empty">Données de couverture non disponibles pour ce SKU.</p>
                  <?php else: ?>

                  <!-- ② Stock physique block -->
                  <table class="exp-ledger-table exp-cov-block">
                    <tbody>
                      <?php if ($covAnchor !== null): ?>
                      <tr class="exp-ledger-anchor">
                        <td class="exp-ledger-label">Ancre inventaire</td>
                        <td class="exp-ledger-qty exp-ledger-qty--anchor"><?= number_format($covAnchor) ?></td>
                        <td class="exp-ledger-meta"></td>
                      </tr>
                      <?php if ($covProd > 0): ?>
                      <tr class="exp-ledger-prod">
                        <td class="exp-ledger-label">+ Production</td>
                        <td class="exp-ledger-qty exp-ledger-qty--plus">+<?= number_format($covProd) ?></td>
                        <td class="exp-ledger-meta"></td>
                      </tr>
                      <?php endif ?>
                      <?php if ($covExp > 0): ?>
                      <tr class="exp-ledger-exp">
                        <td class="exp-ledger-label">− Expédié</td>
                        <td class="exp-ledger-qty exp-ledger-qty--minus">−<?= number_format($covExp) ?></td>
                        <td class="exp-ledger-meta"></td>
                      </tr>
                      <?php endif ?>
                      <?php if ($covEshop > 0): ?>
                      <tr class="exp-ledger-eshop">
                        <td class="exp-ledger-label">− Eshop</td>
                        <td class="exp-ledger-qty exp-ledger-qty--minus">−<?= number_format($covEshop) ?></td>
                        <td class="exp-ledger-meta"></td>
                      </tr>
                      <?php endif ?>
                      <?php if ($covTaproom > 0): ?>
                      <tr class="exp-ledger-tap">
                        <td class="exp-ledger-label">− Taproom</td>
                        <td class="exp-ledger-qty exp-ledger-qty--minus">−<?= number_format($covTaproom) ?></td>
                        <td class="exp-ledger-meta"></td>
                      </tr>
                      <?php endif ?>
                      <?php endif ?>
                      <?php if ($covPhysique !== null): ?>
                      <tr class="exp-ledger-physique">
                        <td class="exp-ledger-label exp-ledger-label--total">Physique</td>
                        <td class="exp-ledger-qty exp-ledger-qty--total" colspan="2">
                          = <?= number_format($covPhysique) ?>
                        </td>
                      </tr>
                      <?php endif ?>
                    </tbody>
                  </table>

                  <!-- ③ Carnet de commandes engagées -->
                  <div class="exp-cov-section">
                    <div class="exp-cov-section-title">Commandes engagées</div>
                    <?php if (empty($covOpenBook)): ?>
                    <p class="exp-evt-empty">Aucune commande ouverte.</p>
                    <?php else: ?>
                    <table class="exp-ledger-table exp-cov-open-table">
                      <thead>
                        <tr>
                          <th class="exp-cov-th">Échéance</th>
                          <th class="exp-cov-th exp-cov-th--num">Quantité</th>
                          <th class="exp-cov-th">Statut</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($covOpenBook as $obRow): ?>
                        <?php
                        $obDate   = isset($obRow['date'])   ? exp_fmt_date((string) $obRow['date']) : '—';
                        $obQty    = isset($obRow['qty'])    ? (int) $obRow['qty']                   : 0;
                        $obStatus = isset($obRow['status']) ? (string) $obRow['status']             : '';
                        $obLabel  = $covStatusLabels[$obStatus] ?? htmlspecialchars($obStatus);
                        ?>
                        <tr class="exp-cov-open-row">
                          <td class="exp-cov-td"><?= htmlspecialchars($obDate) ?></td>
                          <td class="exp-cov-td exp-cov-td--num"><?= number_format($obQty) ?></td>
                          <td class="exp-cov-td exp-cov-status"><?= htmlspecialchars($obLabel) ?></td>
                        </tr>
                        <?php endforeach ?>
                      </tbody>
                      <tfoot>
                        <tr class="exp-cov-open-total">
                          <td class="exp-cov-td exp-cov-td--foot">Total engagé</td>
                          <td class="exp-cov-td exp-cov-td--num exp-cov-td--foot"><?= number_format($covOpenTotal) ?></td>
                          <td class="exp-cov-td exp-cov-td--foot"></td>
                        </tr>
                      </tfoot>
                    </table>
                    <?php endif ?>

                    <!-- live_futur indicator -->
                    <?php if ($covLiveFutur !== null): ?>
                    <?php if ($covLiveFutur < 0): ?>
                    <div class="exp-cov-futur exp-cov-futur--survendu" role="status">
                      <span aria-hidden="true">⚠</span>
                      Survendu (−<?= number_format(abs($covLiveFutur)) ?>)
                    </div>
                    <?php else: ?>
                    <div class="exp-cov-futur exp-cov-futur--ok" role="status">
                      <span aria-hidden="true">✓</span>
                      Disponible après commandes&nbsp;: <?= number_format($covLiveFutur) ?>
                    </div>
                    <?php endif ?>
                    <?php endif ?>
                  </div>

                  <!-- ④ Rythme & régularité -->
                  <?php if ($covRythme !== null || $covNonzero !== null): ?>
                  <div class="exp-cov-section">
                    <div class="exp-cov-section-title">Rythme &amp; régularité</div>
                    <?php if ($covRythme !== null): ?>
                    <div class="exp-cov-rythme">
                      Rythme de base&nbsp;: <span class="exp-cov-rythme-val"><?= number_format($covRythme, 1) ?>/sem</span>
                    </div>
                    <?php endif ?>
                    <?php if ($covNonzero !== null && $covWeeks !== null): ?>
                    <div class="exp-cov-lumpiness">
                      Ventes sur <?= (int) $covNonzero ?>/<?= (int) $covWeeks ?> semaines
                    </div>
                    <?php endif ?>
                    <?php
                    // Show amber spike note when recent_spike > rythme_base × 3 (and both exist)
                    $spikeThreshold = ($covRythme !== null) ? $covRythme * 3 : null;
                    $showSpike      = ($covSpike !== null && $covSpike > 0
                                       && $spikeThreshold !== null && $covSpike > $spikeThreshold);
                    ?>
                    <?php if ($showSpike): ?>
                    <div class="exp-cov-spike" role="status">
                      <span aria-hidden="true">⚡</span>
                      <?= number_format($covSpike, 1) ?> unités vendues ces 3 dernières semaines
                    </div>
                    <?php endif ?>
                  </div>
                  <?php endif ?>

                  <!-- ⑤ Saisonnalité -->
                  <?php if (!empty($covSeasonal)): ?>
                  <div class="exp-cov-section exp-cov-seasonal">
                    <span class="exp-cov-seasonal-label">Indice saisonnier actuel&nbsp;: </span>
                    <span class="exp-cov-seasonal-val">×<?= number_format((float) ($covSeasonal['now_index'] ?? 0), 2) ?></span>
                    <span class="exp-cov-seasonal-muted"> (semaine <?= (int) ($covSeasonal['now_week'] ?? 0) ?>)</span>
                    <span class="exp-cov-seasonal-sep"> · </span>
                    <span class="exp-cov-seasonal-muted">pic ×<?= number_format((float) ($covSeasonal['peak_index'] ?? 0), 2) ?> (semaine <?= (int) ($covSeasonal['peak_week'] ?? 0) ?>)</span>
                  </div>
                  <?php endif ?>

                  <!-- ⑥ Projection semaine par semaine -->
                  <div class="exp-cov-section">
                    <div class="exp-cov-section-title">Projection</div>
                    <?php if (empty($covProj)): ?>
                    <p class="exp-evt-empty">Pas de projection — SKU sans rotation.</p>
                    <?php else: ?>
                    <?php
                    // Find the first stockout row index (stock_after <= 0)
                    $stockoutIdx = null;
                    foreach ($covProj as $pi => $pw) {
                        if (isset($pw['stock_after']) && (float) $pw['stock_after'] <= 0) {
                            $stockoutIdx = $pi;
                            break;
                        }
                    }
                    ?>
                    <div class="exp-cov-proj-wrap">
                    <table class="exp-ledger-table exp-cov-proj-table">
                      <thead>
                        <tr>
                          <th class="exp-cov-th">Semaine</th>
                          <th class="exp-cov-th exp-cov-th--num">Burn prévu</th>
                          <th class="exp-cov-th exp-cov-th--num">Commandes</th>
                          <th class="exp-cov-th exp-cov-th--num">Demande</th>
                          <th class="exp-cov-th exp-cov-th--num">Stock après</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($covProj as $pi => $pw): ?>
                        <?php
                        $pwStart  = isset($pw['week_start'])    ? exp_fmt_date(substr((string) $pw['week_start'], 0, 10)) : '—';
                        $pwIso    = isset($pw['iso_week'])      ? (int) $pw['iso_week']                                   : null;
                        $pwBurn   = isset($pw['expected_burn']) ? (float) $pw['expected_burn']                            : 0.0;
                        $pwOrders = isset($pw['open_orders'])   ? (float) $pw['open_orders']                             : 0.0;
                        $pwDemand = isset($pw['demand'])        ? (float) $pw['demand']                                  : 0.0;
                        $pwAfter  = isset($pw['stock_after'])   ? (float) $pw['stock_after']                             : null;
                        $isStockout = ($pi === $stockoutIdx);
                        $rowClass   = $isStockout ? ' exp-cov-proj-stockout' : '';
                        ?>
                        <tr class="exp-cov-proj-row<?= $rowClass ?>">
                          <td class="exp-cov-td">
                            <?php if ($isStockout): ?><span class="exp-cov-stockout-glyph" aria-hidden="true">⚠</span><?php endif ?>
                            <?= htmlspecialchars($pwStart) ?>
                            <?php if ($pwIso !== null): ?><span class="exp-cov-week-iso"> S<?= $pwIso ?></span><?php endif ?>
                          </td>
                          <td class="exp-cov-td exp-cov-td--num"><?= number_format($pwBurn, 1) ?></td>
                          <td class="exp-cov-td exp-cov-td--num"><?= number_format($pwOrders, 1) ?></td>
                          <td class="exp-cov-td exp-cov-td--num"><?= number_format($pwDemand, 1) ?></td>
                          <td class="exp-cov-td exp-cov-td--num<?= ($pwAfter !== null && $pwAfter <= 0) ? ' exp-cov-td--neg' : '' ?>">
                            <?= $pwAfter !== null ? number_format($pwAfter, 1) : '—' ?>
                          </td>
                        </tr>
                        <?php endforeach ?>
                      </tbody>
                    </table>
                    </div><!-- /exp-cov-proj-wrap -->
                    <?php endif ?>
                  </div>

                  <?php endif ?><!-- /couverture non-empty -->

                </div><!-- /exp-evt-pane couverture -->
              </div><!-- /exp-stock-ledger -->
            </td>
          </tr>
      <?php endforeach ?>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>

  </div><!-- /exp-loc-table-total -->

  <!-- ── Per-location tables (hidden by default, toggled by JS) ────────── -->
  <?php foreach ($locSnap['locations'] as $lc):
    // Group this location's snapshot rows by display_family.
    // BU/CU suffix routes to the synthetic singles group (same logic as total view).
    $lcFamilyOrder = $stockFamilyOrder; // reuse the same order
    $lcByFamily    = [];
    foreach ($lc['rows'] as $lr) {
        $fam = preg_match('/(BU|CU)$/', $lr['sku_code'] ?? '') ? "À l'unité" : ($lr['display_family'] ?? $lr['format']);
        $lcByFamily[$fam][] = $lr;
    }
    // Sort families in canonical order
    $lcFamilyKeys = array_unique(array_merge(
        array_intersect(array_keys($lcFamilyOrder), array_keys($lcByFamily)),
        array_diff(array_keys($lcByFamily), array_keys($lcFamilyOrder))
    ));
    // Sort SKUs within each family by sku_code
    foreach ($lcByFamily as &$lcFamRows) {
        usort($lcFamRows, fn($a, $b) => strcmp($a['sku_code'], $b['sku_code']));
    }
    unset($lcFamRows);
    $hasLocRows = !empty($lc['rows']);
    $locCaption = $lc['last_counted'] !== null
        ? 'Stock physique compté au ' . exp_fmt_date($lc['last_counted']) . ' — les disponibilités (− commandes) sont sur la vue Total.'
        : null;
  ?>
  <div id="exp-loc-table-<?= (int) $lc['id'] ?>"
       class="exp-loc-view exp-loc-view--single"
       hidden
       data-loc-id="<?= (int) $lc['id'] ?>">

    <?php if (!$hasLocRows): ?>
    <!-- Empty state (e.g. Taproom not yet counted) -->
    <div class="exp-loc-empty-state">
      <p class="exp-loc-empty-state__msg">Pas encore compté</p>
      <p class="exp-loc-empty-state__sub">Aucune entrée d'inventaire pour ce site.</p>
    </div>

    <?php else: ?>
    <?php if ($locCaption !== null): ?>
    <p class="exp-loc-caption"><?= htmlspecialchars($locCaption) ?></p>
    <?php endif ?>

    <div class="exp-stock-table-wrap">
      <table class="exp-stock-table exp-stock-table--loc">
        <thead>
          <tr>
            <th class="exp-st-col-sku">SKU</th>
            <th class="exp-st-col-units">Unités</th>
            <th class="exp-st-col-hl">HL</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($lcFamilyKeys as $lcGroupFam):
          if (empty($lcByFamily[$lcGroupFam])) continue;
          $lcGroupLabel = ($lcGroupFam === "À l'unité")
              ? "À l'unité (taproom)"
              : exp_family_label(exp_format_family($lcGroupFam));
          $lcGroupCount = count($lcByFamily[$lcGroupFam]);
          $lcFamUnits   = 0;
          $lcFamHl      = 0.0;
          foreach ($lcByFamily[$lcGroupFam] as $lr) {
              $lcFamUnits += (float) $lr['qty'];
              $lcFamHl    += $lr['hl'];
          }
        ?>
          <tr class="exp-stock-family-header" aria-hidden="true">
            <td colspan="3" class="exp-stock-family-header__cell">
              <span class="exp-stock-family-header__label"><?= htmlspecialchars($lcGroupLabel) ?></span>
              <span class="exp-stock-family-header__count"><?= $lcGroupCount ?> SKU<?= $lcGroupCount !== 1 ? 's' : '' ?></span>
            </td>
          </tr>
          <?php foreach ($lcByFamily[$lcGroupFam] as $lr):
            // Lighter single-location treatment: has-stock dot vs empty dot only.
            // No weeks-of-cover gauge or 6-state badge (no per-site velocity).
            $lrQty     = (float) $lr['qty'];
            $lrHasStock = $lrQty > 0;
            $lrIsCage   = (($lr['stocktake_scope'] ?? '') === 'cage');
            $lrDotClass = $lrHasStock ? 'exp-loc-dot--stock' : 'exp-loc-dot--empty';
            $lrDotTitle = $lrHasStock ? 'En stock' : 'Épuisé à ce site';
          ?>
          <tr class="exp-stock-row exp-loc-row<?= !$lrHasStock ? ' exp-loc-row--empty' : '' ?>">
            <td class="exp-st-col-sku">
              <span class="exp-loc-dot <?= $lrDotClass ?>" title="<?= htmlspecialchars($lrDotTitle) ?>" aria-label="<?= htmlspecialchars($lrDotTitle) ?>"></span>
              <span class="exp-st-sku-code"><?= htmlspecialchars($lr['sku_code']) ?></span>
              <span class="exp-st-sku-hl"><?= number_format($lr['hl'], 2) ?> HL</span>
            </td>
            <td class="exp-st-col-units">
              <span class="exp-st-num<?= !$lrHasStock ? ' exp-st-muted' : '' ?>"><?= exp_fmt_stock_qty($lrQty, $lrIsCage) ?></span>
            </td>
            <td class="exp-st-col-hl">
              <span class="exp-st-num<?= !$lrHasStock ? ' exp-st-muted' : '' ?>"><?= number_format($lr['hl'], 2) ?></span>
            </td>
          </tr>
          <?php endforeach ?>
          <!-- Family subtotal -->
          <tr class="exp-loc-family-subtotal">
            <td class="exp-loc-subtotal-label">Sous-total <?= htmlspecialchars($lcGroupLabel) ?></td>
            <td class="exp-st-col-units exp-loc-subtotal-val"><?= number_format($lcFamUnits) ?></td>
            <td class="exp-st-col-hl exp-loc-subtotal-val"><?= number_format($lcFamHl, 2) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot>
          <tr class="exp-loc-total-row">
            <td class="exp-loc-total-label">Total</td>
            <td class="exp-st-col-units exp-loc-total-val"><?= number_format($lc['total_units']) ?></td>
            <td class="exp-st-col-hl exp-loc-total-val"><?= number_format($lc['total_hl'], 2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif ?>

  </div><!-- /exp-loc-table-<?= (int) $lc['id'] ?> -->
  <?php endforeach ?>

  <?php else: ?>
  <!-- No anchor found yet -->
  <div class="exp-section">
    <div class="op-form__card exp-empty-state">
      <p class="exp-empty">
        Aucun inventaire de stock PF trouvé. Importez un inventaire pour initialiser l'ancre.
      </p>
    </div>
  </div>
  <?php endif ?>

  <?php endif ?>
  <!-- /STOCK PF -->


  <!-- ══════════════════════════════════════════════════════════════════════
       INVENTAIRE FG — SAISIE MULTI-SITE (stocktake view)
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'stocktake'): ?>

  <?php
  // ── Location selector: selected location from ?loc= ──────────────────────
  $stSelLocId = isset($_GET['loc']) ? (int) $_GET['loc'] : 0;
  // Default to first site if none set
  if ($stSelLocId <= 0 && !empty($stSitesRows)) {
      $stSelLocId = (int) $stSitesRows[0]['id'];
  }
  // Validate: must be in the holds_fg_stock set
  $stSelLoc = null;
  foreach ($stSitesRows as $sr) {
      if ((int) $sr['id'] === $stSelLocId) {
          $stSelLoc = $sr;
          break;
      }
  }
  if ($stSelLoc === null && !empty($stSitesRows)) {
      $stSelLoc   = $stSitesRows[0];
      $stSelLocId = (int) $stSelLoc['id'];
  }

  // exp_site_type_label() is defined globally above (used by both stock + stocktake views).

  // ── Role-gated date selection ─────────────────────────────────────────────
  // Managers/admins can pass ?date=YYYY-MM-DD for any date back to 2020-01-01.
  // Operators can pass ?date= within a 30-day window; $stRenderCanBackdate mirrors POST gate.
  $stRenderCanBackdate = is_manager() || is_admin(); // full date range
  $stOperatorBackdate  = !$stRenderCanBackdate;       // operators: 30-day window only
  $stToday    = date('Y-m-d');
  $stMinDate  = $stOperatorBackdate ? date('Y-m-d', strtotime('-30 days')) : '2020-01-01';

  if (isset($_GET['date']) && ($stRenderCanBackdate || $stOperatorBackdate)) {
      $stDateRaw = trim((string) $_GET['date']);
      // Validate: YYYY-MM-DD, not future, not before min date
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $stDateRaw)
          && $stDateRaw <= $stToday
          && $stDateRaw >= $stMinDate) {
          $stSelDate = $stDateRaw;
      } else {
          $stSelDate = $stToday; // invalid ?date → silently fall back to today
      }
  } else {
      $stSelDate = $stToday;
  }
  $stIsEditingPastDate = ($stSelDate !== $stToday);

  // ── Prefill map for the selected (location, date) ─────────────────────────
  // When a manager picks a past date that already has counts, we show those
  // as prefilled values (a real edit, not blind re-entry).
  // Uses is_active=1 so tombstoned anchor rows are not surfaced.
  // DISTINCT from $stPriorMap which shows latest-ever count for the "précéd." hint.
  $stPrefillMap = []; // sku_id → qty (bottles for cage SKUs post-redenomination, raw qty for others)
  if ($stSelLocId > 0) {
      $prefillStmt = $pdo->prepare(
          'SELECT sku_id_fk, qty
             FROM inv_fg_stocktake
            WHERE location_id_fk = :loc
              AND counted_at     = :sel_date
              AND is_active      = 1'
      );
      $prefillStmt->execute([':loc' => $stSelLocId, ':sel_date' => $stSelDate]);
      foreach ($prefillStmt->fetchAll(PDO::FETCH_ASSOC) as $pf) {
          $stPrefillMap[(int) $pf['sku_id_fk']] = (float) $pf['qty'];
      }
  }
  // $stIsEditingPastDate is set above in the date-selection block.

  // ── Monday freshness computation ──────────────────────────────────────────
  // Current ISO week's Monday (kept — other views may reference $stThisMonday)
  $stNow       = new DateTimeImmutable(date('Y-m-d'));
  $stDow       = (int) $stNow->format('N'); // 1=Mon, 7=Sun
  $stThisMonday = $stNow->modify('-' . ($stDow - 1) . ' days')->format('Y-m-d');
  // Census-staleness threshold (configurable; default 7 days — non-breaking if key absent)
  $stCensusStaleCount = max(1, (int) system_setting('fg_census_stale_days', 'fulfilment', 7));

  function exp_st_freshness_chip(int $locId, array $lastCounted, int $threshold = 7): string
  {
      $last = $lastCounted[$locId] ?? null;
      if ($last === null) {
          return '<span class="exp-st-fresh-chip exp-st-fresh-chip--missing" title="Jamais compté">⚠ jamais compté</span>';
      }
      $today   = new DateTimeImmutable('today');
      $daysAgo = (int) $today->diff(new DateTimeImmutable($last))->days;
      if ($daysAgo <= $threshold) {
          $ago = $daysAgo === 0 ? "aujourd'hui" : ('il y a ' . $daysAgo . ' jour' . ($daysAgo > 1 ? 's' : ''));
          return '<span class="exp-st-fresh-chip exp-st-fresh-chip--ok" title="Compté ' . $ago . '">✓ compté ' . $ago . '</span>';
      }
      return '<span class="exp-st-fresh-chip exp-st-fresh-chip--warn" title="Pas compté depuis ' . $daysAgo . ' jours">⚠ il y a ' . $daysAgo . ' jour' . ($daysAgo > 1 ? 's' : '') . '</span>';
  }

  // ── Group SKUs by family for the count grid ───────────────────────────────
  // Cage SKUs (suffix -X) are extracted from their format family and rendered
  // in their own "Cages" section at the bottom with bottle-unit inputs.
  $stFamilyOrder = ['Bot', 'Can', 'Can33', 'Keg', 'multipack', 'Cuve de service'];
  $stFamilyLabels = [
      'Bot'            => 'Bouteille',
      'Can'            => 'Canette',
      'Can33'          => 'Can 33',
      'Keg'            => 'Fût',
      'multipack'      => 'Multipacks',
      'Cuve de service'=> 'Cuve de service',
  ];
  // Determine which scopes are visible at the selected location.
  // exp_st_scope_allowed() is defined at the top of the helper functions section.
  $stSelSiteType = $stSelLoc !== null ? (string) $stSelLoc['site_type'] : '';

  $stSkusByFamily = [];
  $stCageSkus     = []; // cage SKUs (scope='cage') — only at production+warehouse
  foreach ($stAllSkus as $sk) {
      $scope = $sk['stocktake_scope'] ?? 'none';
      // Skip SKUs not permitted at the selected location.
      if (!exp_st_scope_allowed($scope, $stSelSiteType)) {
          continue;
      }
      if (($sk['stocktake_scope'] ?? '') === 'cage') {
          $stCageSkus[] = $sk;
      } else {
          $fmt = $sk['display_family'] ?? $sk['format'] ?? 'Autre';
          $stSkusByFamily[$fmt][] = $sk;
      }
  }
  // Sort families in canonical order (unknown families appended at end)
  $stFamiliesSorted = array_unique(array_merge(
      array_intersect($stFamilyOrder, array_keys($stSkusByFamily)),
      array_diff(array_keys($stSkusByFamily), $stFamilyOrder)
  ));

  // Default counted_at: for managers use $stSelDate (may be past date); operators always today.
  $stDefaultCountedAt = $stSelDate;
  ?>

  <!-- Window globals: SKU list, prior counts, sites, freshness, role -->
  <script>
    window.EXP_ST_SKUS        = <?= $stSkusJson ?>;
    window.EXP_ST_PRIOR       = <?= $stPriorJson ?>;
    window.EXP_ST_SITES       = <?= $stSitesJson ?>;
    window.EXP_ST_FRESHNESS   = <?= $stFreshnessJson ?>;
    window.EXP_ST_TODAY       = <?= json_encode($stToday, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_CSRF           = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_IS_MANAGER  = <?= json_encode($stRenderCanBackdate, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_SEL_LOC_ID  = <?= json_encode($stSelLocId, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_SEL_DATE     = <?= json_encode($stSelDate, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_SEL_LOC_NAME = <?= json_encode($stSelLoc ? $stSelLoc['name'] : '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_LATEST_DATE  = <?= json_encode($stLastCounted[$stSelLocId] ?? null, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_MIN_DATE     = <?= json_encode($stMinDate, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.EXP_ST_DATE_NAVIGATE = <?= json_encode($stRenderCanBackdate || $stOperatorBackdate, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>
<?php if ($view === 'stocktake'): ?>
<link rel="stylesheet"
      href="/css/expeditions-guided.css?v=<?= filemtime(__DIR__ . '/../../public/css/expeditions-guided.css') ?>">
<?php endif ?>


  <?php
  // Build date query-string suffix used in navigation links.
  // Any role editing a past date: preserve ?date= so location-chip clicks
  // stay on the same date. Operators are now allowed 30-day backdating, so
  // this applies to them too (not just managers).
  $stDateQs = ($stIsEditingPastDate)
      ? '&amp;date=' . htmlspecialchars($stSelDate)
      : '';
  ?>

  <!-- ── Section: freshness strips (all 4 locations) ──────────────────────── -->
  <div class="exp-st-freshness-bar" role="region" aria-label="Fraîcheur des inventaires par site">
    <?php foreach ($stSitesRows as $sr): ?>
    <?php $locId = (int) $sr['id']; ?>
    <div class="exp-st-fresh-item<?= $locId === $stSelLocId ? ' exp-st-fresh-item--active' : '' ?>">
      <a href="/modules/expeditions.php?view=stocktake&amp;loc=<?= $locId ?><?= $stDateQs ?>"
         class="exp-st-fresh-link"
         aria-label="Sélectionner <?= htmlspecialchars($sr['name']) ?>">
        <span class="exp-st-fresh-site"><?= htmlspecialchars($sr['name']) ?></span>
        <span class="exp-st-fresh-type"><?= htmlspecialchars(exp_site_type_label($sr['site_type'])) ?></span>
      </a>
      <?= exp_st_freshness_chip($locId, $stLastCounted, $stCensusStaleCount) ?>
      <?php
      $_stcLast = $stLastCounted[$locId] ?? null;
      $_stcAgo  = ($_stcLast !== null) ? (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($_stcLast))->days : null;
      if ($_stcAgo !== null && $_stcAgo > $stCensusStaleCount): ?>
      <span class="exp-st-fresh-overdue-badge" aria-label="Ce site est en retard de comptage">à recompter</span>
      <?php endif; unset($_stcLast, $_stcAgo); ?>
    </div>
    <?php endforeach ?>
  </div>

  <!-- ── Location selector ─────────────────────────────────────────────────── -->
  <div class="exp-st-location-selector" role="group" aria-label="Sélection du site">
    <?php foreach ($stSitesRows as $sr): ?>
    <?php $locId = (int) $sr['id']; $isConsign = ($sr['customer_id_fk'] !== null); ?>
    <a href="/modules/expeditions.php?view=stocktake&amp;loc=<?= $locId ?><?= $stDateQs ?>"
       class="exp-st-loc-chip<?= $locId === $stSelLocId ? ' exp-st-loc-chip--active' : '' ?>"
       aria-pressed="<?= $locId === $stSelLocId ? 'true' : 'false' ?>"
       title="<?= $isConsign && !empty($sr['notes']) ? htmlspecialchars($sr['notes']) : htmlspecialchars($sr['name']) ?>">
      <span class="exp-st-loc-name"><?= htmlspecialchars($sr['name']) ?></span>
      <span class="exp-st-loc-type"><?= htmlspecialchars(exp_site_type_label($sr['site_type'])) ?></span>
      <?php if ($isConsign): ?>
        <span class="exp-st-loc-badge exp-st-loc-badge--consign">consig.</span>
      <?php endif ?>
    </a>
    <?php endforeach ?>
  </div>

  <?php if ($stSelLoc !== null && ($stSelLoc['customer_id_fk'] !== null) && !empty($stSelLoc['notes'])): ?>
  <div class="exp-st-consign-note">
    <span class="exp-st-consign-note__icon" aria-hidden="true">ℹ</span>
    <span class="exp-st-consign-note__text"><?= htmlspecialchars($stSelLoc['notes']) ?></span>
  </div>
  <?php endif ?>

  <?php if ($stRenderCanBackdate): ?>
  <!-- Manager mode note -->
  <div class="exp-st-manager-note" id="exp-st-manager-note">
    <span class="exp-st-manager-note__icon" aria-hidden="true">✎</span>
    <span class="exp-st-manager-note__text">
      Mode édition — vous pouvez choisir une date antérieure pour corriger ou compléter un inventaire.
    </span>
  </div>
  <?php endif ?>

  <?php if ($stIsEditingPastDate): ?>
  <!-- Editing-past-date banner: visible when manager is on a past date with existing data -->
  <div class="exp-st-edit-banner" id="exp-st-edit-banner" role="alert" aria-live="polite">
    <span class="exp-st-edit-banner__icon" aria-hidden="true">📋</span>
    <span class="exp-st-edit-banner__text">
      Édition de l'inventaire du <strong><?= exp_fmt_date($stSelDate) ?></strong>
      <?php if (!empty($stPrefillMap)): ?>
        — <span class="exp-st-edit-banner__count"><?= count($stPrefillMap) ?> SKU<?= count($stPrefillMap) !== 1 ? 's' : '' ?> existant<?= count($stPrefillMap) !== 1 ? 's' : '' ?> prérempli<?= count($stPrefillMap) !== 1 ? 's' : '' ?></span>
      <?php else: ?>
        — <span class="exp-st-edit-banner__count">aucun inventaire pour cette date — nouvelle saisie</span>
      <?php endif ?>
    </span>
  </div>
  <?php endif ?>

  <?php
  $stLatestDate = $stLastCounted[$stSelLocId] ?? null;
  $stShowLatestWarning = $stIsEditingPastDate
      && !$stRenderCanBackdate
      && $stLatestDate !== null
      && $stSelDate !== $stLatestDate;
  ?>
  <?php if ($stShowLatestWarning): ?>
  <div class="exp-st-past-warning" role="alert">
    <span class="exp-st-past-warning__icon" aria-hidden="true">⚠</span>
    <span class="exp-st-past-warning__text">
      Vous corrigez un inventaire <strong>passé</strong>
      (du <?= exp_fmt_date($stSelDate) ?>).
      Le tableau s'appuie sur l'inventaire du
      <strong><?= exp_fmt_date($stLatestDate) ?></strong> (plus récent)
      et <strong>NE changera PAS</strong>.
      Pour corriger le stock actuel, modifiez le dernier inventaire.
    </span>
  </div>
  <?php endif ?>

  <!-- ── Count form ─────────────────────────────────────────────────────────── -->
  <form method="POST"
        action="/modules/expeditions.php?view=stocktake"
        class="exp-st-form"
        id="exp-st-form"
        novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="location_id" value="<?= $stSelLocId ?>">

    <!-- ── Header controls row ───────────────────────────────────────────── -->
    <div class="exp-st-controls">
      <div class="exp-st-date-field">
        <label class="exp-st-label" for="exp-st-counted-at">Date de comptage</label>
        <?php if ($stRenderCanBackdate): ?>
        <input type="date" id="exp-st-counted-at" name="counted_at"
               class="exp-st-date-input"
               value="<?= htmlspecialchars($stDefaultCountedAt) ?>"
               max="<?= $stToday ?>"
               min="2020-01-01"
               data-base-url="/modules/expeditions.php?view=stocktake&amp;loc=<?= $stSelLocId ?>">
        <span class="exp-st-date-hint">Chaque lundi de préférence · date modifiable</span>
        <?php else: // Operator: editable within 30-day window ?>
        <input type="date" id="exp-st-counted-at" name="counted_at"
               class="exp-st-date-input"
               value="<?= htmlspecialchars($stDefaultCountedAt) ?>"
               max="<?= $stToday ?>"
               min="<?= htmlspecialchars($stMinDate) ?>"
               data-base-url="/modules/expeditions.php?view=stocktake&amp;loc=<?= $stSelLocId ?>">
        <span class="exp-st-date-hint">Chaque lundi · correction possible jusqu'à 30 j</span>
        <?php endif ?>
      </div>

      <div class="exp-st-search-field">
        <label class="exp-st-label" for="exp-st-search">Filtrer</label>
        <input type="text" id="exp-st-search" class="exp-st-search-input"
               placeholder="Rechercher un SKU…"
               autocomplete="off" autocorrect="off" spellcheck="false">
      </div>

      <!-- Running summary (updated by JS) -->
      <div class="exp-st-summary" id="exp-st-summary" role="status" aria-live="polite">
        <span class="exp-st-summary__skus" id="exp-st-count">0 SKU saisi</span>
        <span class="exp-st-summary__sep">·</span>
        <span class="exp-st-summary__hl" id="exp-st-hl">0,00 HL</span>
      </div>
    </div>

    <!-- ── SKU count grid, grouped by format family ───────────────────────── -->
    <div class="exp-st-grid" id="exp-st-grid">
    <?php foreach ($stFamiliesSorted as $fmtKey): ?>
      <?php
      $familySkus = $stSkusByFamily[$fmtKey] ?? [];
      if (empty($familySkus)) continue;
      $familyLabel = $stFamilyLabels[$fmtKey] ?? $fmtKey;
      $familySlug  = exp_format_family($fmtKey);
      ?>
      <div class="exp-st-family" data-family="<?= htmlspecialchars($familySlug) ?>">
        <div class="exp-st-family-header" id="exp-st-fh-<?= htmlspecialchars($familySlug) ?>">
          <span class="exp-st-family-label"><?= htmlspecialchars($familyLabel) ?></span>
          <span class="exp-st-family-count" id="exp-st-fc-<?= htmlspecialchars($familySlug) ?>"></span>
        </div>
        <div class="exp-st-family-rows">
        <?php foreach ($familySkus as $sk): ?>
          <?php
          $sid  = (int) $sk['id'];
          $code = $sk['sku_code'];
          $hpu  = (float) $sk['hl_per_unit'];
          $prior = $stPriorMap[$stSelLocId][$sid] ?? null;
          $priorQty  = $prior !== null ? (float) $prior['qty'] : null;
          $priorDate = $prior !== null ? ($prior['counted_at'] ?? $prior['month_closed'] ?? null) : null;
          // Format prior hint date
          $priorHint = '';
          if ($priorDate !== null) {
              $priorHint = strlen($priorDate) === 7
                  ? $priorDate
                  : exp_fmt_date($priorDate);
          }
          // Prefill: for the selected (location, date), load the existing stored qty.
          // Regular (non-cage) SKUs: stored qty is the direct unit count.
          // The prefill supersedes the prior hint when editing a past date.
          $prefillQty = isset($stPrefillMap[$sid]) ? (int) round($stPrefillMap[$sid]) : null;
          ?>
          <div class="exp-st-row<?= $prefillQty !== null ? ' exp-st-row--prefilled' : '' ?>"
               data-sku-id="<?= $sid ?>" data-sku-code="<?= htmlspecialchars($code) ?>" data-hl="<?= $hpu ?>">
            <label class="exp-st-row__label" for="exp-st-qty-<?= $sid ?>">
              <span class="exp-st-row__code"><?= htmlspecialchars($code) ?></span>
            </label>
            <!-- Hidden parallel-array id field -->
            <input type="hidden" name="sku_qty_id[]" value="<?= $sid ?>">
            <div class="exp-st-row__qty-wrap">
              <input type="number"
                     id="exp-st-qty-<?= $sid ?>"
                     name="sku_qty_val[]"
                     class="exp-st-qty-input"
                     min="0"
                     step="1"
                     placeholder=""
                     autocomplete="off"
                     <?php if ($prefillQty !== null): ?>
                     value="<?= $prefillQty ?>"
                     <?php elseif ($priorQty !== null): ?>
                     data-prior="<?= $priorQty ?>"
                     <?php endif ?>
                     aria-label="Quantité <?= htmlspecialchars($code) ?>"
                     inputmode="numeric">
              <?php if ($prefillQty === null && $priorQty !== null && !$stIsEditingPastDate): ?>
              <?php /* "précéd." hint suppressed when editing a past date — the latest-ever
                       prior count may post-date the edited date and would mislead. */ ?>
              <span class="exp-st-prior-hint">
                précéd.&nbsp;: <strong><?= number_format($priorQty, 0) ?></strong>
                <?= $priorHint !== '' ? '<span class="exp-st-prior-date">(' . htmlspecialchars($priorHint) . ')</span>' : '' ?>
              </span>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>
        </div>
      </div>
    <?php endforeach ?>

    <?php if (!empty($stCageSkus)): ?>
      <!-- ── Cage SKUs section (bottle-unit entry, no prefill) ─────────────── -->
      <div class="exp-st-family exp-st-family--cage" data-family="cage">
        <div class="exp-st-family-header exp-st-family-header--cage" id="exp-st-fh-cage">
          <span class="exp-st-family-label">Cages</span>
          <span class="exp-st-family-count" id="exp-st-fc-cage"></span>
        </div>
        <div class="exp-st-cage-note" role="note">
          Saisie en <strong>bouteilles</strong> — entiers, 0 = rupture.
        </div>
        <div class="exp-st-family-rows">
        <?php foreach ($stCageSkus as $sk): ?>
          <?php
          $sid            = (int) $sk['id'];
          $code           = $sk['sku_code'];
          $hpu            = (float) $sk['hl_per_unit'];
          // Cage prefill: stored qty is now integer bottles (post-redenomination).
          $cagePrefillBottles = isset($stPrefillMap[$sid]) ? (int) round((float) $stPrefillMap[$sid]) : null;
          ?>
          <!-- Cage row: input in whole bottles, stored as-entered (integer). -->
          <div class="exp-st-row exp-st-row--cage<?= $cagePrefillBottles !== null ? ' exp-st-row--prefilled' : '' ?>"
               data-sku-id="<?= $sid ?>"
               data-sku-code="<?= htmlspecialchars($code) ?>"
               data-hl="<?= $hpu ?>"
               data-is-cage="1">
            <label class="exp-st-row__label" for="exp-st-qty-<?= $sid ?>">
              <span class="exp-st-row__code"><?= htmlspecialchars($code) ?></span>
              <span class="exp-st-cage-ref">
                <?= number_format($hpu, 5) ?>&nbsp;hl/btl
              </span>
            </label>
            <!-- Hidden parallel-array id field (always submitted so POST knows this SKU exists) -->
            <input type="hidden" name="sku_qty_id[]" value="<?= $sid ?>">
            <div class="exp-st-row__qty-wrap">
              <div class="exp-st-cage-input-wrap">
                <input type="number"
                       id="exp-st-qty-<?= $sid ?>"
                       name="sku_qty_val[]"
                       class="exp-st-qty-input exp-st-cage-input"
                       min="0"
                       step="1"
                       placeholder=""
                       autocomplete="off"
                       <?php if ($cagePrefillBottles !== null): ?>
                       value="<?= $cagePrefillBottles ?>"
                       <?php endif ?>
                       aria-label="Bouteilles <?= htmlspecialchars($code) ?>"
                       inputmode="numeric">
                <span class="exp-st-cage-unit">btl</span>
              </div>
              <!-- Live hint: JS writes this from input value -->
              <span class="exp-st-cage-live" id="exp-st-cage-live-<?= $sid ?>" aria-live="polite"></span>
            </div>
          </div>
        <?php endforeach ?>
        </div>
      </div>
    <?php endif ?>
    </div>

    <!-- ── Clôture mensuelle ─────────────────────────────────────────────── -->
    <div class="exp-st-month-end">
      <label class="exp-st-month-end__label">
        <input type="checkbox" name="month_end_census" value="1" class="exp-st-month-end__checkbox">
        <span class="exp-st-month-end__text">
          Inventaire de clôture mensuelle (COGS)
          <span class="exp-st-month-end__hint">— cocher uniquement pour le comptage de fin de mois · laisser décoché pour les relevés hebdomadaires habituels</span>
        </span>
      </label>
    </div>

    <?php if ($stIsEditingPastDate): ?>
    <div class="exp-st-correction-motif">
      <label class="exp-st-label" for="exp-st-motif">Motif de la correction</label>
      <select id="exp-st-motif" name="correction_motif" class="exp-st-motif-select" required>
        <option value="">— Choisir un motif —</option>
        <option value="erreur-saisie">Erreur de saisie</option>
        <option value="casse">Casse / produit endommagé</option>
        <option value="perte">Perte / vol</option>
        <option value="retrouve">Produit retrouvé</option>
        <option value="autre">Autre</option>
      </select>
      <input type="text" id="exp-st-motif-note" name="correction_note"
             class="exp-st-motif-note" maxlength="200"
             placeholder="Note optionnelle (200 car. max)"
             autocomplete="off">
    </div>
    <?php endif ?>

    <!-- ── Mode guidé ──────────────────────────────────────────────────────── -->
    <div class="exp-st-guided-trigger">
      <button type="button" class="exp-st-guided-btn" id="exp-st-guided-open"
              aria-expanded="false" aria-controls="exp-st-guided-overlay">
        <span class="exp-st-guided-btn__icon" aria-hidden="true">▶</span>
        Comptage guidé (1 SKU à la fois)
      </button>
      <span class="exp-st-guided-hint">
        Force un comptage complet — chaque SKU demandé individuellement
      </span>
    </div>

    <?php if (!empty($_GET['seal_ack_required']) && $stRenderCanBackdate): ?>
    <!-- ── Seal acknowledgement (manager, month_end, sealed month) ───────── -->
    <div class="exp-st-seal-warn" role="alert" aria-live="polite">
      <span class="exp-st-seal-warn__icon" aria-hidden="true">⚠</span>
      <span class="exp-st-seal-warn__text">
        Ce mois est scellé. La correction <strong>NE modifiera PAS</strong> la fiche COGS scellée tant que le responsable financier ne la re-scelle pas.
      </span>
      <label class="exp-st-seal-warn__ack">
        <input type="checkbox" name="seal_ack" value="1" required
               aria-required="true">
        <span>Je confirme</span>
      </label>
    </div>
    <?php endif ?>

    <!-- ── Submit bar ─────────────────────────────────────────────────────── -->
    <?php if (can_write_expeditions($me)): ?>
    <div class="exp-st-submit-bar">
      <button type="submit" class="exp-st-submit-btn" id="exp-st-submit">
        Enregistrer l'inventaire —
        <?= htmlspecialchars($stSelLoc !== null ? $stSelLoc['name'] : '') ?>
        au <span id="exp-st-submit-date"><?= exp_fmt_date($stDefaultCountedAt) ?></span>
      </button>
    </div>
    <?php endif ?>

  </form>

  <!-- ── Guided census overlay ────────────────────────────────────────────── -->
  <div class="exp-st-guided-overlay" id="exp-st-guided-overlay" hidden
       aria-label="Comptage guidé">
    <div class="exp-st-guided-card">
      <div class="exp-st-guided-progress" id="exp-st-guided-progress">
        <div class="exp-st-guided-progress-bar">
          <div class="exp-st-guided-progress-fill" id="exp-st-guided-fill"></div>
        </div>
        <span class="exp-st-guided-progress-label" id="exp-st-guided-label">0 / 0 comptés</span>
      </div>
      <div class="exp-st-guided-sku" id="exp-st-guided-sku">
        <span class="exp-st-guided-sku-family" id="exp-st-guided-family"></span>
        <span class="exp-st-guided-sku-code" id="exp-st-guided-code"></span>
        <span class="exp-st-guided-sku-unit" id="exp-st-guided-unit"></span>
      </div>
      <div class="exp-st-guided-input-wrap">
        <input type="number" id="exp-st-guided-qty" class="exp-st-guided-qty"
               min="0" step="1" inputmode="numeric" autocomplete="off"
               aria-label="Quantité comptée" placeholder="0">
      </div>
      <p class="exp-st-guided-status" id="exp-st-guided-status" role="status" aria-live="polite"></p>
      <div class="exp-st-guided-actions">
        <button type="button" class="exp-st-guided-zero" id="exp-st-guided-zero">0 et suivant</button>
        <button type="button" class="exp-st-guided-next" id="exp-st-guided-next">Valider et suivant</button>
      </div>
      <div class="exp-st-guided-footer">
        <button type="button" class="exp-st-guided-pause" id="exp-st-guided-pause">Pause (reprendre plus tard)</button>
        <button type="button" class="exp-st-guided-quit" id="exp-st-guided-quit">Quitter le mode guidé</button>
      </div>
    </div>
  </div>

  <!-- ── Final gate dialog ─────────────────────────────────────────────────── -->
  <dialog class="exp-st-guided-dialog" id="exp-st-guided-dialog" aria-modal="true">
    <div class="exp-st-guided-dialog__inner">
      <p class="exp-st-guided-dialog__msg" id="exp-st-guided-dialog-msg"></p>
      <div class="exp-st-guided-dialog__actions">
        <button type="button" class="exp-st-guided-dialog__confirm" id="exp-st-guided-dialog-confirm">Passer à 0 et terminer</button>
        <button type="button" class="exp-st-guided-dialog__cancel" id="exp-st-guided-dialog-cancel">Retour au comptage</button>
      </div>
    </div>
  </dialog>

  <!-- ── Seal-ack dialog (guided mode, manager, sealed month) ─────────────── -->
  <dialog class="exp-st-seal-dialog" id="exp-st-seal-dialog" aria-modal="true">
    <div class="exp-st-seal-dialog__inner">
      <p class="exp-st-seal-dialog__msg">
        Ce mois est scellé. La correction <strong>NE modifiera PAS</strong> la fiche COGS scellée tant que le responsable financier ne la re-scelle pas.
      </p>
      <div class="exp-st-seal-dialog__actions">
        <button type="button" class="exp-st-seal-dialog__confirm" id="exp-st-seal-dialog-confirm">J'ai compris, continuer</button>
      </div>
    </div>
  </dialog>

  <?php endif ?>
  <!-- /INVENTAIRE FG -->


  <!-- ══════════════════════════════════════════════════════════════════════
       CARNET CLIENTS VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'clients'): ?>

  <!-- Window globals for merge typeahead -->
  <script>
    window.EXP_CRM_ROWS = <?= $crmRowsJson ?>;
    window.EXP_CSRF     = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>

  <?php
  // ── Helper: render trade_channel chip ────────────────────────────────────
  function exp_channel_chip(?string $ch, bool $isPrivate): string
  {
      if ($isPrivate) {
          return '<span class="exp-clients-chan exp-clients-chan--private">Privé</span>';
      }
      if ($ch === 'on_trade') {
          return '<span class="exp-clients-chan exp-clients-chan--on">On-trade</span>';
      }
      if ($ch === 'off_trade') {
          return '<span class="exp-clients-chan exp-clients-chan--off">Off-trade</span>';
      }
      return '<span class="exp-clients-chan exp-clients-chan--none">—</span>';
  }

  // Compute pagination values (used below; already set by data loader above)
  $totalPagesClients = max(1, (int) ceil($clientsTotalRows / $clientsPerPage));

  // Build base URL for pagination + filter links
  $clientsBase = '/modules/expeditions.php?view=clients';
  $clientsQs   = '';
  if ($clientsSearch !== '') $clientsQs .= '&search=' . urlencode($clientsSearch);
  if ($clientsFilter !== 'active') $clientsQs .= '&filter=' . urlencode($clientsFilter);
  ?>

  <!-- ── Review queue section ───────────────────────────────────────────────── -->
  <?php
  // Fetch needs_review rows separately (always show them at top when filter=review or if we're on the review section)
  // We show the review section at the top only when clientsFilter !== another category that hides them,
  // or when there are any to show. We always show it as a standalone alert block.
  $reviewRows = [];
  if ($clientsReviewCount > 0 && $view === 'clients') {
      try {
          $rvStmt = $pdo->prepare(
              'SELECT id, name, bc_customer_no, trade_channel, is_private, notes
                 FROM ref_customers
                WHERE needs_review = 1
                ORDER BY name ASC
                LIMIT 400'
          );
          $rvStmt->execute();
          $reviewRows = $rvStmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
          error_log('[expeditions clients review] ' . $e->getMessage());
      }
  }
  ?>

  <?php if (!empty($reviewRows)): ?>
  <div class="exp-clients-review-section" id="exp-clients-review">
    <div class="exp-clients-review-header">
      <span class="exp-clients-review-title">
        À valider
        <span class="exp-clients-review-count"><?= count($reviewRows) ?></span>
      </span>
      <span class="exp-clients-review-hint">
        Ces clients proviennent de la feuille BSF et n'ont pas encore été liés à un compte CRM.
        Fusionner, valider, ou désactiver chaque ligne.
      </span>
      <button type="button" class="exp-clients-review-toggle" id="exp-review-toggle"
              aria-expanded="true" aria-controls="exp-review-list">
        Réduire ▲
      </button>
    </div>

    <div id="exp-review-list">
    <?php foreach ($reviewRows as $rv): ?>
      <?php $rvId = (int) $rv['id']; ?>
      <div class="exp-clients-review-row" id="exp-rvrow-<?= $rvId ?>">

        <!-- Name + meta -->
        <div class="exp-clients-review-info">
          <span class="exp-clients-review-name"><?= htmlspecialchars($rv['name']) ?></span>
          <?php if (!empty($rv['notes'])): ?>
            <span class="exp-clients-review-note"><?= htmlspecialchars(mb_substr($rv['notes'], 0, 80)) ?></span>
          <?php endif ?>
        </div>

        <!-- Channel chip -->
        <div class="exp-clients-review-chan">
          <?= exp_channel_chip($rv['trade_channel'] ?? null, (bool) $rv['is_private']) ?>
        </div>

        <!-- Actions -->
        <?php if (can_write_expeditions($me)): ?>
        <div class="exp-clients-review-actions">

          <!-- Fusionner button → opens inline merge panel -->
          <button type="button"
                  class="exp-clients-action-btn exp-clients-action-btn--merge"
                  data-rv-id="<?= $rvId ?>"
                  data-rv-name="<?= htmlspecialchars($rv['name'], ENT_QUOTES) ?>"
                  aria-expanded="false"
                  aria-controls="exp-merge-panel-<?= $rvId ?>">
            Fusionner
          </button>

          <!-- Valider tel quel -->
          <form method="POST" action="/modules/expeditions.php?view=clients"
                class="exp-clients-inline-form">
            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action"    value="client_validate">
            <input type="hidden" name="client_id" value="<?= $rvId ?>">
            <button type="submit" class="exp-clients-action-btn exp-clients-action-btn--validate"
                    title="Ce client est réel et non-BC (privé, nouveau)">
              Valider tel quel
            </button>
          </form>

          <!-- Désactiver -->
          <form method="POST" action="/modules/expeditions.php?view=clients"
                class="exp-clients-inline-form">
            <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action"    value="client_deactivate">
            <input type="hidden" name="client_id" value="<?= $rvId ?>">
            <button type="submit" class="exp-clients-action-btn exp-clients-action-btn--deactivate"
                    title="Doublon ou artefact — désactiver">
              Désactiver
            </button>
          </form>

        </div>
        <?php endif ?>

        <!-- Inline merge panel (hidden by default, opened by JS) -->
        <?php if (can_write_expeditions($me)): ?>
        <div class="exp-merge-panel" id="exp-merge-panel-<?= $rvId ?>" hidden>
          <div class="exp-merge-panel__inner">
            <div class="exp-merge-panel__title">
              Fusionner « <?= htmlspecialchars($rv['name']) ?> » dans →
            </div>

            <!-- Typeahead search for target CRM client -->
            <div class="exp-merge-typeahead-wrap">
              <input type="text"
                     class="op-form__input exp-merge-search"
                     id="exp-merge-search-<?= $rvId ?>"
                     placeholder="Rechercher le client CRM cible…"
                     autocomplete="off"
                     autocorrect="off"
                     spellcheck="false"
                     data-dup-id="<?= $rvId ?>"
                     data-dup-name="<?= htmlspecialchars($rv['name'], ENT_QUOTES) ?>">
              <ul class="exp-merge-dropdown" id="exp-merge-drop-<?= $rvId ?>"
                  role="listbox" aria-label="Client CRM cible" hidden></ul>
            </div>

            <!-- Preview line (filled by JS when a target is selected) -->
            <div class="exp-merge-preview" id="exp-merge-preview-<?= $rvId ?>" hidden>
              <span class="exp-merge-preview__text" id="exp-merge-preview-text-<?= $rvId ?>"></span>
            </div>

            <!-- Confirm form (POSTs when operator clicks Confirmer) -->
            <form method="POST" action="/modules/expeditions.php?view=clients"
                  class="exp-merge-confirm-form" id="exp-merge-form-<?= $rvId ?>" hidden>
              <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action"    value="client_merge">
              <input type="hidden" name="client_id" value="<?= $rvId ?>">
              <input type="hidden" name="target_id" class="exp-merge-target-id" value="">
              <div class="exp-merge-confirm-bar">
                <button type="submit" class="exp-clients-action-btn exp-clients-action-btn--confirm">
                  ✓ Confirmer la fusion
                </button>
                <button type="button" class="exp-clients-action-btn exp-clients-action-btn--cancel-merge"
                        data-rv-id="<?= $rvId ?>">
                  Annuler
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php endif ?>

      </div>
    <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>

  <!-- ── Directory section ──────────────────────────────────────────────────── -->
  <div class="exp-clients-dir">

    <!-- Search + filter bar -->
    <form class="exp-clients-filter-bar" method="GET" action="/modules/expeditions.php">
      <input type="hidden" name="view"  value="clients">
      <div class="exp-clients-search-wrap">
        <input type="text"
               id="exp-clients-search"
               name="search"
               class="exp-clients-search-input"
               value="<?= htmlspecialchars($clientsSearch) ?>"
               placeholder="Nom, n° BC, ville…"
               autocomplete="off">
      </div>
      <div class="exp-clients-filter-chips" role="group" aria-label="Filtre">
        <?php
        $filterChips = [
            'active'    => 'Actifs',
            'review'    => 'À valider' . ($clientsReviewCount > 0 ? ' (' . $clientsReviewCount . ')' : ''),
            'inactive'  => 'Inactifs',
            'nochannel' => 'Sans canal',
            'all'       => 'Tous',
        ];
        foreach ($filterChips as $fv => $fl):
        ?>
          <a href="<?= $clientsBase ?>&amp;filter=<?= urlencode($fv) ?><?= $clientsSearch !== '' ? '&amp;search=' . urlencode($clientsSearch) : '' ?>"
             class="exp-clients-filter-chip<?= $clientsFilter === $fv ? ' exp-clients-filter-chip--active' : '' ?>"
             aria-pressed="<?= $clientsFilter === $fv ? 'true' : 'false' ?>">
            <?= htmlspecialchars($fl) ?>
          </a>
        <?php endforeach ?>
      </div>
      <button type="submit" class="exp-clients-search-btn">Rechercher</button>
    </form>

    <!-- Results count + pagination top -->
    <div class="exp-clients-pagination-bar">
      <span class="exp-clients-count">
        <?= number_format($clientsTotalRows) ?> client<?= $clientsTotalRows !== 1 ? 's' : '' ?>
        <?php if ($clientsSearch !== ''): ?>
          pour « <?= htmlspecialchars($clientsSearch) ?> »
        <?php endif ?>
      </span>
      <?php if ($totalPagesClients > 1): ?>
      <div class="exp-clients-pagination">
        <?php if ($clientsPage > 1): ?>
          <a href="<?= $clientsBase . $clientsQs ?>&amp;page=<?= $clientsPage - 1 ?>"
             class="exp-clients-page-btn">◂ Préc.</a>
        <?php endif ?>
        <span class="exp-clients-page-info">Page <?= $clientsPage ?> / <?= $totalPagesClients ?></span>
        <?php if ($clientsPage < $totalPagesClients): ?>
          <a href="<?= $clientsBase . $clientsQs ?>&amp;page=<?= $clientsPage + 1 ?>"
             class="exp-clients-page-btn">Suiv. ▸</a>
        <?php endif ?>
      </div>
      <?php endif ?>
    </div>

    <!-- Directory table -->
    <?php if (empty($clientsRows)): ?>
    <div class="op-form__card exp-empty-state">
      <p class="exp-empty">
        <?= $clientsSearch !== '' ? 'Aucun client ne correspond à « ' . htmlspecialchars($clientsSearch) . ' ».' : 'Aucun client à afficher.' ?>
      </p>
    </div>
    <?php else: ?>
    <div class="exp-clients-table-wrap">
      <table class="exp-clients-table" id="exp-clients-table">
        <thead>
          <tr>
            <th class="exp-ct-col-name">Nom</th>
            <th class="exp-ct-col-bc">N° BC</th>
            <th class="exp-ct-col-chan">Canal</th>
            <th class="exp-ct-col-city">Ville</th>
            <th class="exp-ct-col-email">Email</th>
            <th class="exp-ct-col-trans">Transporteur</th>
            <th class="exp-ct-col-actions"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($clientsRows as $cr):
            $crid    = (int) $cr['id'];
            $inactive = !(bool) $cr['is_active'];
            $review   = (bool) $cr['needs_review'];
            $rowCls   = 'exp-ct-row';
            if ($inactive) $rowCls .= ' exp-ct-row--inactive';
            if ($review)   $rowCls .= ' exp-ct-row--review';
        ?>
          <tr class="<?= $rowCls ?>" data-client-id="<?= $crid ?>">

            <!-- Name cell -->
            <td class="exp-ct-col-name">
              <span class="exp-ct-name"><?= htmlspecialchars($cr['name']) ?></span>
              <?php if ($review): ?>
                <span class="exp-ct-badge exp-ct-badge--review" title="À valider">⚠</span>
              <?php endif ?>
              <?php if ($inactive): ?>
                <span class="exp-ct-badge exp-ct-badge--inactive" title="Inactif">🚫</span>
              <?php endif ?>
            </td>

            <!-- BC number -->
            <td class="exp-ct-col-bc">
              <span class="exp-ct-mono"><?= htmlspecialchars($cr['bc_customer_no'] ?? '—') ?></span>
            </td>

            <!-- Canal — inline editable -->
            <td class="exp-ct-col-chan">
              <?php if (can_write_expeditions($me)): ?>
              <form method="POST" action="/modules/expeditions.php?view=clients"
                    class="exp-ct-inline-form">
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="client_update">
                <input type="hidden" name="client_id" value="<?= $crid ?>">
                <input type="hidden" name="field"     value="trade_channel">
                <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                <select name="value"
                        class="exp-ct-inline-select"
                        onchange="this.form.submit()"
                        aria-label="Canal pour <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?>">
                  <option value=""         <?= ($cr['trade_channel'] === null) ? 'selected' : '' ?>>—</option>
                  <option value="on_trade" <?= ($cr['trade_channel'] === 'on_trade')  ? 'selected' : '' ?>>On-trade</option>
                  <option value="off_trade"<?= ($cr['trade_channel'] === 'off_trade') ? 'selected' : '' ?>>Off-trade</option>
                </select>
              </form>
              <?php else: ?>
              <span class="exp-ct-muted"><?= htmlspecialchars($cr['trade_channel'] ?? '—') ?></span>
              <?php endif ?>
            </td>

            <!-- City -->
            <td class="exp-ct-col-city">
              <span class="exp-ct-city"><?= htmlspecialchars($cr['city'] ?? '—') ?></span>
            </td>

            <!-- Email -->
            <td class="exp-ct-col-email">
              <?php if (!empty($cr['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($cr['email']) ?>" class="exp-ct-email">
                  <?= htmlspecialchars($cr['email']) ?>
                </a>
              <?php else: ?>
                <span class="exp-ct-muted">—</span>
              <?php endif ?>
            </td>

            <!-- Transporter — inline editable -->
            <td class="exp-ct-col-trans">
              <?php if (can_write_expeditions($me)): ?>
              <form method="POST" action="/modules/expeditions.php?view=clients"
                    class="exp-ct-inline-form">
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="client_update">
                <input type="hidden" name="client_id" value="<?= $crid ?>">
                <input type="hidden" name="field"     value="default_transporter_id_fk">
                <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                <select name="value"
                        class="exp-ct-inline-select"
                        onchange="this.form.submit()"
                        aria-label="Transporteur par défaut pour <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?>">
                  <option value="0" <?= ($cr['default_transporter_id_fk'] === null) ? 'selected' : '' ?>>—</option>
                  <?php foreach ($transporters as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"
                            <?= ((int) $cr['default_transporter_id_fk'] === (int) $t['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($t['name']) ?>
                    </option>
                  <?php endforeach ?>
                </select>
              </form>
              <?php else: ?>
              <?php
                $trName = '—';
                foreach ($transporters as $t) {
                    if ((int) $t['id'] === (int) $cr['default_transporter_id_fk']) {
                        $trName = $t['name'];
                        break;
                    }
                }
              ?>
              <span class="exp-ct-muted"><?= htmlspecialchars($trName) ?></span>
              <?php endif ?>
            </td>

            <!-- Actions -->
            <td class="exp-ct-col-actions">
              <?php if (can_write_expeditions($me)): ?>
              <?php if (!$inactive): ?>
              <form method="POST" action="/modules/expeditions.php?view=clients"
                    class="exp-ct-inline-form">
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="client_deactivate">
                <input type="hidden" name="client_id" value="<?= $crid ?>">
                <button type="submit"
                        class="exp-clients-action-btn exp-clients-action-btn--deactivate exp-ct-deactivate-btn"
                        title="Désactiver ce client"
                        onclick="return confirm('Désactiver « <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?> » ?')">
                  🚫
                </button>
              </form>
              <?php else: ?>
              <form method="POST" action="/modules/expeditions.php?view=clients"
                    class="exp-ct-inline-form">
                <input type="hidden" name="csrf"      value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"    value="client_update">
                <input type="hidden" name="client_id" value="<?= $crid ?>">
                <input type="hidden" name="field"     value="is_active">
                <input type="hidden" name="value"     value="1">
                <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                <button type="submit"
                        class="exp-clients-action-btn exp-clients-action-btn--validate exp-ct-reactivate-btn"
                        title="Réactiver ce client">
                  ✓ Réactiver
                </button>
              </form>
              <?php endif ?>
              <?php endif ?>
            </td>

          </tr>
          <?php
          // Serving-tank fiche sub-row (always rendered for write users; hidden for read-only)
          $stIsClient  = (bool) $cr['is_serving_tank_client'];
          $stCount     = $cr['serving_tank_count']     !== null ? (int) $cr['serving_tank_count'] : null;
          $stSizeHl    = $cr['serving_tank_size_hl']   !== null ? (float) $cr['serving_tank_size_hl'] : null;
          $stBudgetHl  = $cr['serving_tank_budget_hl'] !== null ? (float) $cr['serving_tank_budget_hl'] : null;
          $stRealHl    = $servingTankRealMonth[$crid] ?? 0.0;
          if (can_write_expeditions($me)):
          ?>
          <tr class="exp-ct-row exp-ct-row--serving-tank-fiche<?= !$stIsClient ? ' exp-ct-st-hidden' : '' ?>"
              data-client-st-row="<?= $crid ?>">
            <td colspan="7" class="exp-ct-st-cell">
              <div class="exp-ct-st-block">
                <!-- Toggle: Cuve de service -->
                <span class="exp-ct-st-label">Cuve de service :</span>
                <form method="POST" action="/modules/expeditions.php?view=clients"
                      class="exp-ct-inline-form exp-ct-st-toggle-form">
                  <input type="hidden" name="csrf"         value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"       value="client_update">
                  <input type="hidden" name="client_id"    value="<?= $crid ?>">
                  <input type="hidden" name="field"        value="is_serving_tank_client">
                  <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                  <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                  <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                  <button type="submit" name="value" value="<?= $stIsClient ? '0' : '1' ?>"
                          class="exp-ct-st-toggle<?= $stIsClient ? ' exp-ct-st-toggle--on' : '' ?>"
                          title="<?= $stIsClient ? 'Désactiver client cuve de service' : 'Activer client cuve de service' ?>"
                          aria-label="Client cuve de service : <?= $stIsClient ? 'Oui' : 'Non' ?>">
                    <?= $stIsClient ? 'Oui' : 'Non' ?>
                  </button>
                </form>
                <?php if ($stIsClient): ?>
                <!-- Count: Nb de cuves -->
                <span class="exp-ct-st-sep">·</span>
                <span class="exp-ct-st-label">Nb cuves :</span>
                <form method="POST" action="/modules/expeditions.php?view=clients"
                      class="exp-ct-inline-form exp-ct-st-num-form">
                  <input type="hidden" name="csrf"         value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"       value="client_update">
                  <input type="hidden" name="client_id"    value="<?= $crid ?>">
                  <input type="hidden" name="field"        value="serving_tank_count">
                  <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                  <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                  <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                  <input type="number" name="value"
                         class="exp-ct-st-num-input"
                         value="<?= $stCount !== null ? htmlspecialchars((string) $stCount) : '' ?>"
                         min="0" max="255" step="1"
                         placeholder="—"
                         aria-label="Nombre de cuves pour <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?>">
                  <button type="submit" class="exp-ct-st-num-save" aria-label="Enregistrer">✓</button>
                </form>
                <!-- Size: Taille HL -->
                <span class="exp-ct-st-sep">·</span>
                <span class="exp-ct-st-label">Taille :</span>
                <form method="POST" action="/modules/expeditions.php?view=clients"
                      class="exp-ct-inline-form exp-ct-st-num-form">
                  <input type="hidden" name="csrf"         value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"       value="client_update">
                  <input type="hidden" name="client_id"    value="<?= $crid ?>">
                  <input type="hidden" name="field"        value="serving_tank_size_hl">
                  <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                  <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                  <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                  <input type="number" name="value"
                         class="exp-ct-st-num-input"
                         value="<?= $stSizeHl !== null ? htmlspecialchars(number_format($stSizeHl, 2, '.', '')) : '' ?>"
                         min="0" step="0.01"
                         placeholder="—"
                         aria-label="Taille de cuve en HL pour <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?>">
                  <span class="exp-ct-st-unit">hl</span>
                  <button type="submit" class="exp-ct-st-num-save" aria-label="Enregistrer">✓</button>
                </form>
                <!-- Budget: HL/mois -->
                <span class="exp-ct-st-sep">·</span>
                <span class="exp-ct-st-label">Budget :</span>
                <form method="POST" action="/modules/expeditions.php?view=clients"
                      class="exp-ct-inline-form exp-ct-st-num-form">
                  <input type="hidden" name="csrf"         value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"       value="client_update">
                  <input type="hidden" name="client_id"    value="<?= $crid ?>">
                  <input type="hidden" name="field"        value="serving_tank_budget_hl">
                  <input type="hidden" name="return_page"   value="<?= $clientsPage ?>">
                  <input type="hidden" name="return_search" value="<?= htmlspecialchars($clientsSearch) ?>">
                  <input type="hidden" name="return_filter" value="<?= htmlspecialchars($clientsFilter) ?>">
                  <input type="number" name="value"
                         class="exp-ct-st-num-input"
                         value="<?= $stBudgetHl !== null ? htmlspecialchars(number_format($stBudgetHl, 2, '.', '')) : '' ?>"
                         min="0" step="0.01"
                         placeholder="—"
                         aria-label="Budget mensuel en HL pour <?= htmlspecialchars($cr['name'], ENT_QUOTES) ?>">
                  <span class="exp-ct-st-unit">hl/mois</span>
                  <button type="submit" class="exp-ct-st-num-save" aria-label="Enregistrer">✓</button>
                </form>
                <!-- Réel du mois (read-only) -->
                <span class="exp-ct-st-sep">·</span>
                <span class="exp-ct-st-label">Réel mois :</span>
                <span class="exp-ct-st-reel<?= ($stBudgetHl !== null && $stBudgetHl > 0 && $stRealHl > $stBudgetHl) ? ' exp-ct-st-reel--over' : '' ?>">
                  <?= htmlspecialchars(number_format($stRealHl, 1, '.', '')) ?> hl
                  <?php if ($stBudgetHl !== null && $stBudgetHl > 0): ?>
                    / <?= htmlspecialchars(number_format($stBudgetHl, 1, '.', '')) ?> hl
                    · <?= htmlspecialchars(number_format(($stRealHl / $stBudgetHl) * 100, 0)) ?>%
                  <?php endif ?>
                </span>
                <?php endif ?>
              </div>
            </td>
          </tr>
          <?php endif ?>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination bottom -->
    <?php if ($totalPagesClients > 1): ?>
    <div class="exp-clients-pagination-bar exp-clients-pagination-bar--bottom">
      <?php if ($clientsPage > 1): ?>
        <a href="<?= $clientsBase . $clientsQs ?>&amp;page=<?= $clientsPage - 1 ?>"
           class="exp-clients-page-btn">◂ Préc.</a>
      <?php endif ?>
      <span class="exp-clients-page-info">Page <?= $clientsPage ?> / <?= $totalPagesClients ?></span>
      <?php if ($clientsPage < $totalPagesClients): ?>
        <a href="<?= $clientsBase . $clientsQs ?>&amp;page=<?= $clientsPage + 1 ?>"
           class="exp-clients-page-btn">Suiv. ▸</a>
      <?php endif ?>
    </div>
    <?php endif ?>
    <?php endif ?>

  </div>
  <!-- /directory -->

  <script src="/js/expeditions-clients.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-clients.js') ?: time() ?>"></script>

  <?php endif ?>
  <!-- /CARNET CLIENTS -->

  <!-- ══════════════════════════════════════════════════════════════════════
       MOUVEMENTS VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'mouvements'): ?>

  <!-- Window globals for mouvement JS — SKU list + advisory stock map. -->
  <script>
    window.EXP_MOV_SKUS = <?= json_encode(array_map(fn($ms) => [
        'id'       => (int) $ms['id'],
        'sku_code' => $ms['sku_code'],
        'format'   => $ms['display_family'] ?? $ms['format'] ?? '',
    ], $movSkuList), $jsonFlags) ?>;
    // Advisory stock map: {sku_id: {physique}} — empty = compute failed, hints absent.
    window.EXP_MOV_STOCK_MAP    = <?= $movStockMapJson ?>;
    window.EXP_MOV_STOCK_ANCHOR = <?= $movStockAnchorJson ?>;
  </script>

  <!-- Hidden SKU select template — cloned by JS for each line row. -->
  <template id="exp-mov-line-template">
    <div class="exp-mov-line-row" role="group" aria-label="Ligne SKU">
      <div class="op-form__field exp-mov-line-sku-field">
        <label class="op-form__label">Article</label>
        <select name="mov_sku_id[]" class="op-form__select exp-mov-line-sku" required>
          <option value="">— choisir un SKU —</option>
          <?php
          $movPrevFamily = null;
          foreach ($movSkuList as $ms):
              $msFam = (string) ($ms['display_family'] ?? $ms['format'] ?? '');
              if ($msFam !== $movPrevFamily):
                  if ($movPrevFamily !== null) echo '</optgroup>';
                  echo '<optgroup label="' . htmlspecialchars($msFam) . '">';
                  $movPrevFamily = $msFam;
              endif;
          ?>
            <option value="<?= (int) $ms['id'] ?>">
              <?= htmlspecialchars($ms['sku_code']) ?>
            </option>
          <?php
          endforeach;
          if ($movPrevFamily !== null) echo '</optgroup>';
          ?>
        </select>
      </div>
      <div class="op-form__field exp-mov-line-qty-field">
        <label class="op-form__label">Quantité</label>
        <input type="number" name="mov_qty[]" class="op-form__input exp-mov-line-qty"
               min="1" step="1" placeholder="ex: 12">
        <span class="exp-mov-stock-hint" hidden></span>
      </div>
      <button type="button" class="exp-line-remove exp-mov-line-remove"
              aria-label="Supprimer cette ligne">×</button>
    </div>
  </template>

  <div class="exp-mov-wrap" id="exp-mov-wrap">

    <!-- ── Record form ────────────────────────────────────────────────────── -->
    <?php if (can_write_expeditions($me)): ?>
    <div class="op-form__card exp-card exp-mov-card" id="exp-mov-form-card">
      <div class="op-form__card-title">Nouveau mouvement inter-sites</div>

      <form method="POST" action="/modules/expeditions.php?view=mouvements"
            class="exp-mov-form" id="exp-mov-form" novalidate>
        <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="add_movement">

        <!-- ── Shared header ────────────────────────────────────────────── -->
        <div class="op-form__grid exp-mov-header-grid">

          <!-- From site -->
          <div class="op-form__field">
            <label class="op-form__label" for="exp-mov-from">Site d'origine</label>
            <select id="exp-mov-from" name="from_site_id" class="op-form__select" required>
              <option value="">— choisir —</option>
              <?php foreach ($movFgSites as $mst): ?>
                <option value="<?= (int) $mst['id'] ?>">
                  <?= htmlspecialchars($mst['name']) ?>
                </option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- To site -->
          <div class="op-form__field">
            <label class="op-form__label" for="exp-mov-to">Site de destination</label>
            <select id="exp-mov-to" name="to_site_id" class="op-form__select" required>
              <option value="">— choisir —</option>
              <?php foreach ($movFgSites as $mst): ?>
                <option value="<?= (int) $mst['id'] ?>">
                  <?= htmlspecialchars($mst['name']) ?>
                </option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- moved_on -->
          <div class="op-form__field">
            <label class="op-form__label" for="exp-mov-date">Date du mouvement</label>
            <input type="date" id="exp-mov-date" name="moved_on"
                   class="op-form__input"
                   value="<?= htmlspecialchars(date('Y-m-d')) ?>"
                   max="<?= htmlspecialchars(date('Y-m-d')) ?>"
                   min="2020-01-01" required>
          </div>

          <!-- Comment -->
          <div class="op-form__field op-form__field--full">
            <label class="op-form__label" for="exp-mov-comment">
              Commentaire
              <span class="op-form__unit">optionnel</span>
            </label>
            <input type="text" id="exp-mov-comment" name="comment"
                   class="op-form__input"
                   placeholder="Motif, numéro de bon, remarque…"
                   maxlength="500">
          </div>

        </div>
        <!-- /header grid -->

        <!-- ── SKU line grid ─────────────────────────────────────────────── -->
        <div class="exp-mov-lines-section">
          <div class="exp-mov-lines-label">
            Articles à transférer
            <span class="exp-mov-lines-hint" id="exp-mov-stock-warn" hidden>
              ⚠ stock insuffisant sur une ou plusieurs lignes
            </span>
          </div>
          <div class="exp-mov-lines-container" id="exp-mov-lines-container"
               role="group" aria-label="Lignes SKU">
            <!-- Rows injected by JS -->
          </div>
          <button type="button" class="exp-add-line-btn" id="exp-mov-add-line"
                  aria-label="Ajouter une ligne SKU">
            + Ajouter une ligne
          </button>
        </div>
        <!-- /line grid -->

        <div class="op-form__actions">
          <button type="submit" class="op-form__submit" id="exp-mov-submit">
            Enregistrer le mouvement
          </button>
        </div>

      </form>
    </div>
    <?php endif ?>
    <!-- /form card -->

    <!-- ── Movements list ─────────────────────────────────────────────────── -->
    <div class="exp-mov-list-wrap" id="exp-mov-list-wrap">
      <div class="exp-mov-list-header">
        <span class="exp-mov-list-title">Derniers mouvements</span>
        <?php if (!empty($movRows)): ?>
          <span class="exp-mov-list-count"><?= count($movRows) ?> entrée<?= count($movRows) > 1 ? 's' : '' ?></span>
        <?php endif ?>
      </div>

      <?php if (empty($movRows)): ?>
        <p class="exp-empty">Aucun mouvement enregistré.</p>
      <?php else: ?>
      <div class="exp-mov-table-wrap">
        <table class="exp-mov-table" id="exp-mov-table">
          <thead>
            <tr>
              <th class="exp-mov-col-date">Date</th>
              <th class="exp-mov-col-sku">SKU</th>
              <th class="exp-mov-col-from">Origine</th>
              <th class="exp-mov-col-arrow" aria-hidden="true"></th>
              <th class="exp-mov-col-to">Destination</th>
              <th class="exp-mov-col-qty">Qté</th>
              <th class="exp-mov-col-by">Par</th>
              <th class="exp-mov-col-comment">Commentaire</th>
              <?php if (is_admin($me)): ?>
              <th class="exp-mov-col-actions"></th>
              <?php endif ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($movRows as $mv):
              $mvTombed  = (bool) $mv['is_tombstoned'];
              $mvRowCls  = 'exp-mov-row' . ($mvTombed ? ' exp-mov-row--tombstoned' : '');
          ?>
            <tr class="<?= $mvRowCls ?>" data-mov-id="<?= (int) $mv['id'] ?>">
              <td class="exp-mov-col-date">
                <span class="exp-mov-date"><?= htmlspecialchars(exp_fmt_date($mv['moved_on'])) ?></span>
              </td>
              <td class="exp-mov-col-sku">
                <span class="exp-sku-pill"><?= htmlspecialchars($mv['sku_code']) ?></span>
              </td>
              <td class="exp-mov-col-from"><?= htmlspecialchars($mv['from_site_name']) ?></td>
              <td class="exp-mov-col-arrow" aria-hidden="true">→</td>
              <td class="exp-mov-col-to"><?= htmlspecialchars($mv['to_site_name']) ?></td>
              <td class="exp-mov-col-qty">
                <span class="exp-mov-qty"><?= number_format((float) $mv['qty'], 0) ?></span>
              </td>
              <td class="exp-mov-col-by">
                <span class="exp-mov-by"><?= htmlspecialchars($mv['created_by_name'] ?? '—') ?></span>
              </td>
              <td class="exp-mov-col-comment">
                <?= $mv['comment'] !== null ? htmlspecialchars($mv['comment']) : '<span class="exp-mov-empty-comment">—</span>' ?>
                <?php if ($mvTombed): ?>
                  <span class="exp-mov-tombstone-badge" title="Annulé">annulé</span>
                <?php endif ?>
              </td>
              <?php if (is_admin($me)): ?>
              <td class="exp-mov-col-actions">
                <?php if (!$mvTombed): ?>
                <form method="POST" action="/modules/expeditions.php?view=mouvements"
                      class="exp-ct-inline-form">
                  <input type="hidden" name="csrf"        value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"      value="tombstone_movement">
                  <input type="hidden" name="movement_id" value="<?= (int) $mv['id'] ?>">
                  <?php
                  $mvConfirmMsg = 'Annuler le mouvement #' . (int) $mv['id']
                      . ' (' . $mv['sku_code']
                      . ', ' . number_format((float) $mv['qty'], 0)
                      . ' unité' . ((float) $mv['qty'] > 1 ? 's' : '') . ') ?';
                  ?>
                  <button type="submit"
                          class="exp-clients-action-btn exp-clients-action-btn--deactivate exp-mov-cancel-btn"
                          title="Annuler ce mouvement"
                          onclick="return confirm(<?= json_encode($mvConfirmMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                    🚫
                  </button>
                </form>
                <?php endif ?>
              </td>
              <?php endif ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
    <!-- /list -->

  </div>
  <!-- /exp-mov-wrap -->

  <script src="/js/expeditions-form.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-form.js') ?: time() ?>"></script>

  <?php endif ?>
  <!-- /MOUVEMENTS -->

  <!-- ══════════════════════════════════════════════════════════════════════
       RESTES D'EMBALLAGE (SIDE-STOCK) VIEW
       NOT COGS — unit counts only. NOT sale_class (distinct from migs 300/301).
       Out of fg_stock_compute() FG legs.
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'side-stock'): ?>

  <div class="exp-ssl-wrap" id="exp-ssl-wrap">

    <!-- ── Current balances ─────────────────────────────────────────────── -->
    <section class="exp-ssl-balances" aria-label="Soldes par SKU">
      <h2 class="exp-ssl-section-title">Soldes actuels (unités restantes)</h2>
      <?php if (empty($sslBalanceRows)): ?>
        <p class="exp-ssl-empty">Aucun reste d'emballage en cours.</p>
      <?php else: ?>
        <table class="exp-ssl-table" role="table" aria-label="Soldes side-stock par SKU">
          <thead>
            <tr>
              <th scope="col">Famille</th>
              <th scope="col">SKU</th>
              <th scope="col" class="exp-ssl-th-num">Unités / boîte</th>
              <th scope="col" class="exp-ssl-th-num">Solde (unités)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sslBalanceRows as $br): ?>
              <tr class="<?= (int)$br['balance_units'] < 0 ? 'exp-ssl-row--negative' : '' ?>">
                <td><?= htmlspecialchars($br['display_family']) ?></td>
                <td class="exp-ssl-sku-code"><?= htmlspecialchars($br['sku_code']) ?></td>
                <td class="exp-ssl-num"><?= (int)$br['units_per_pack'] ?></td>
                <td class="exp-ssl-num exp-ssl-balance"
                    data-sku-id="<?= (int)$br['sku_id'] ?>"
                    data-balance="<?= (int)$br['balance_units'] ?>">
                  <?= (int)$br['balance_units'] ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>

    <!-- ── Giveaway form ────────────────────────────────────────────────── -->
    <?php if (can_write_expeditions($me)): ?>
    <section class="exp-ssl-giveaway-section" aria-label="Enregistrer un giveaway">
      <h2 class="exp-ssl-section-title">Enregistrer un giveaway</h2>
      <p class="exp-ssl-note">
        Un giveaway débite le solde loose-unit du SKU concerné.
        Les giveaways de l'année sont poolés et taxés à la clôture annuelle
        (le cron de clôture n'est pas encore construit — hook documenté).
      </p>
      <form method="POST" action="/modules/expeditions.php?view=side-stock"
            class="exp-ssl-form" id="exp-ssl-give-form" novalidate>
        <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="record_giveaway">

        <div class="op-form__grid">

          <!-- SKU -->
          <div class="op-form__field">
            <label class="op-form__label" for="ssl-give-sku">SKU</label>
            <select id="ssl-give-sku" name="sku_id" class="op-form__select" required>
              <option value="">— choisir un SKU —</option>
              <?php
              $sslPrevFam = null;
              foreach ($sslSkuDropdown as $sdRow):
                  $sdFam = (string)($sdRow['display_family'] ?? '');
                  if ($sdFam !== $sslPrevFam):
                      if ($sslPrevFam !== null) echo '</optgroup>';
                      echo '<optgroup label="' . htmlspecialchars($sdFam) . '">';
                      $sslPrevFam = $sdFam;
                  endif;
              ?>
                <option value="<?= (int)$sdRow['id'] ?>">
                  <?= htmlspecialchars($sdRow['sku_code']) ?>
                </option>
              <?php
              endforeach;
              if ($sslPrevFam !== null) echo '</optgroup>';
              ?>
            </select>
          </div>

          <!-- Qty -->
          <div class="op-form__field">
            <label class="op-form__label" for="ssl-give-qty">Quantité (unités)</label>
            <input type="number" id="ssl-give-qty" name="qty_units"
                   class="op-form__input"
                   min="1" step="1" required
                   placeholder="ex: 3">
          </div>

          <!-- Note / bénéficiaire -->
          <div class="op-form__field op-form__field--full">
            <label class="op-form__label" for="ssl-give-note">Note / bénéficiaire</label>
            <input type="text" id="ssl-give-note" name="note"
                   class="op-form__input"
                   maxlength="255"
                   placeholder="ex: dégustation presse, Brasserie XY">
          </div>

        </div>

        <div class="op-form__actions">
          <button type="submit" class="op-form__btn op-form__btn--primary"
                  id="ssl-give-submit">
            Enregistrer le giveaway
          </button>
        </div>
      </form>
    </section>
    <?php endif ?>

    <!-- ── Movement ledger ──────────────────────────────────────────────── -->
    <section class="exp-ssl-ledger-section" aria-label="Historique des mouvements">
      <h2 class="exp-ssl-section-title">Historique des mouvements</h2>
      <?php if (empty($sslLedgerRows)): ?>
        <p class="exp-ssl-empty">Aucun mouvement enregistré.</p>
      <?php else: ?>
        <table class="exp-ssl-table exp-ssl-ledger" role="table"
               aria-label="Historique side-stock">
          <thead>
            <tr>
              <th scope="col">#</th>
              <th scope="col">SKU</th>
              <th scope="col">Type</th>
              <th scope="col" class="exp-ssl-th-num">Qté</th>
              <th scope="col">Année</th>
              <th scope="col">Note</th>
              <th scope="col">Saisi par</th>
              <th scope="col">Date</th>
              <?php if (is_admin($me)): ?>
              <th scope="col">Action</th>
              <?php endif ?>
            </tr>
          </thead>
          <tbody>
            <?php
            $sslTypeLbl = [
                'accrual'          => 'Reste banqué',
                'complete_box'     => 'Compléter boîte',
                'giveaway'         => 'Giveaway',
                'year_end_offered' => 'Clôture annuelle',
                'adjustment'       => 'Ajustement',
            ];
            foreach ($sslLedgerRows as $lr):
                $lrTombstoned = (int)$lr['is_tombstoned'] === 1;
                $lrClass = $lrTombstoned ? ' exp-ssl-row--tombstoned' : '';
                $lrQty   = (int)$lr['qty_units'];
                $lrDate  = $lr['created_at'] ? (new DateTimeImmutable($lr['created_at']))->format('d/m/Y H:i') : '—';
            ?>
            <tr class="<?= ltrim($lrClass) ?>" aria-disabled="<?= $lrTombstoned ? 'true' : 'false' ?>">
              <td class="exp-ssl-id"><?= (int)$lr['id'] ?></td>
              <td class="exp-ssl-sku-code"><?= htmlspecialchars($lr['sku_code']) ?></td>
              <td>
                <span class="exp-ssl-type-chip exp-ssl-type-chip--<?= htmlspecialchars($lr['movement_type']) ?>">
                  <?= htmlspecialchars($sslTypeLbl[$lr['movement_type']] ?? $lr['movement_type']) ?>
                </span>
                <?php if ($lrTombstoned): ?>
                  <span class="exp-ssl-tombstoned-badge">Annulé</span>
                <?php endif ?>
              </td>
              <td class="exp-ssl-num <?= $lrQty >= 0 ? 'exp-ssl-qty--pos' : 'exp-ssl-qty--neg' ?>">
                <?= $lrQty >= 0 ? '+' . $lrQty : $lrQty ?>
              </td>
              <td><?= htmlspecialchars($lr['fiscal_year']) ?></td>
              <td><?= htmlspecialchars($lr['note'] ?? '—') ?></td>
              <td><?= htmlspecialchars($lr['submitted_by_name'] ?? '—') ?></td>
              <td class="exp-ssl-date"><?= $lrDate ?></td>
              <?php if (is_admin($me)): ?>
              <td>
                <?php if (!$lrTombstoned): ?>
                <form method="POST" action="/modules/expeditions.php?view=side-stock"
                      class="exp-ssl-tomb-form" onsubmit="return confirm('Annuler la ligne #<?= (int)$lr['id'] ?> ?')">
                  <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"  value="tombstone_ssl_row">
                  <input type="hidden" name="ssl_id"  value="<?= (int)$lr['id'] ?>">
                  <button type="submit" class="exp-ssl-tomb-btn" aria-label="Annuler la ligne #<?= (int)$lr['id'] ?>">
                    Annuler
                  </button>
                </form>
                <?php endif ?>
              </td>
              <?php endif ?>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>

  </div>
  <!-- /exp-ssl-wrap -->

  <?php endif ?>
  <!-- /RESTES D'EMBALLAGE -->

  <!-- ══════════════════════════════════════════════════════════════════════
       HISTORIQUE VIEW — per-week × per-client BC shipment history
       Read-only (BC canonical source). No status chips. No CHF amounts.
       Source: v_sales_ledger_weekly_client (mig 329) over inv_sales_ledger.
       Cutover: posting_date < 2026-06-08.
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'historique'): ?>

  <?php
  // ── Period toolbar base URLs (mirrors commandes toolbar idiom) ───────────
  $histBaseUrl      = '/modules/expeditions.php?view=historique';
  $histWeekBaseUrl  = $histBaseUrl . '&amp;mode=week';
  $histRangeBaseUrl = $histBaseUrl . '&amp;mode=range';
  ?>

  <div class="exp-hist-wrap" id="exp-hist-wrap">

    <!-- ── Period toolbar ──────────────────────────────────────────────────── -->
    <form class="exp-toolbar" method="GET" action="/modules/expeditions.php" id="exp-hist-toolbar-form">
      <input type="hidden" name="view" value="historique">

      <!-- Mode toggle -->
      <div class="exp-toolbar__mode" role="group" aria-label="Mode de période">
        <a href="<?= $histWeekBaseUrl ?>"
           class="exp-toolbar__mode-btn<?= $cmdMode === 'week' ? ' exp-toolbar__mode-btn--active' : '' ?>"
           aria-pressed="<?= $cmdMode === 'week' ? 'true' : 'false' ?>">Semaine</a>
        <a href="<?= $histRangeBaseUrl . ($cmdDu ? '&amp;du=' . $cmdDu . '&amp;au=' . $cmdAu : '') ?>"
           class="exp-toolbar__mode-btn<?= $cmdMode === 'range' ? ' exp-toolbar__mode-btn--active' : '' ?>"
           aria-pressed="<?= $cmdMode === 'range' ? 'true' : 'false' ?>">Plage de dates</a>
      </div>

      <?php if ($cmdMode === 'week'): ?>
      <!-- Week navigation: arrows step through historical ISO weeks -->
      <div class="exp-toolbar__week-nav" aria-label="Navigation semaine">
        <a href="<?= $histWeekBaseUrl ?>&amp;kw=<?= urlencode($kwPrev) ?>"
           class="exp-toolbar__nav-arrow" aria-label="Semaine précédente">◂</a>
        <span class="exp-toolbar__week-label"><?= exp_isoweek_label($cmdKw) ?></span>
        <a href="<?= $histWeekBaseUrl ?>&amp;kw=<?= urlencode($kwNext) ?>"
           class="exp-toolbar__nav-arrow" aria-label="Semaine suivante">▸</a>
        <a href="<?= $histWeekBaseUrl ?>&amp;kw=<?= urlencode(exp_date_to_isoweek('2026-06-07')) ?>"
           class="exp-toolbar__today-btn">Dernière semaine BC</a>
      </div>
      <?php else: ?>
      <!-- Range inputs -->
      <div class="exp-toolbar__range-inputs" id="exp-hist-range-inputs">
        <label class="exp-toolbar__range-label" for="exp-hist-range-du">Du</label>
        <input type="date" id="exp-hist-range-du" name="du" class="exp-toolbar__date-input"
               value="<?= htmlspecialchars($cmdDu) ?>"
               min="2021-01-01" max="2026-06-07">
        <label class="exp-toolbar__range-label" for="exp-hist-range-au">Au</label>
        <input type="date" id="exp-hist-range-au" name="au" class="exp-toolbar__date-input"
               value="<?= htmlspecialchars($cmdAu) ?>"
               min="2021-01-01" max="2026-06-07">
        <button type="submit" class="exp-toolbar__range-submit" name="mode" value="range">Afficher</button>
      </div>
      <?php if ($cmdRangeNotice !== ''): ?>
      <span class="exp-toolbar__range-notice"><?= htmlspecialchars($cmdRangeNotice) ?></span>
      <?php endif ?>
      <?php endif ?>
    </form>

    <!-- ── Week blocks ─────────────────────────────────────────────────────── -->
    <?php if (empty($histByWeek)): ?>
      <div class="exp-hist-empty">
        <p>Aucune livraison BC pour cette période.</p>
        <p class="exp-hist-empty__sub">Les données historiques couvrent la période 2021-W01 — 2026-W22 (avant le 8 juin 2026).</p>
      </div>
    <?php else: ?>

    <?php foreach ($histByWeek as $yw => $weekClientRows):
        // Derive display label directly from the iso_yearweek integer (YEARWEEK mode 3).
        // Do NOT use $wr['week_start'] from the view — its STR_TO_DATE computation can
        // map the same yearweek integer to the wrong Monday in some MySQL builds.
        // YEARWEEK integer format is YYYYWW (e.g. 202623 → 2026-W23).
        $ywStr     = str_pad((string) $yw, 6, '0', STR_PAD_LEFT);
        $weekKw    = substr($ywStr, 0, 4) . '-W' . substr($ywStr, 4, 2);
        $weekLabel = exp_isoweek_label($weekKw);

        // Week-level totals
        $weekTotalHl    = 0.0;
        $weekTotalUnits = 0;
        $weekDocCount   = 0;
        foreach ($weekClientRows as $wr) {
            $weekTotalHl    += (float) $wr['total_hl'];
            $weekTotalUnits += (int)   $wr['total_units'];
            $weekDocCount   += (int)   $wr['doc_count'];
        }
    ?>

    <section class="exp-hist-week" aria-label="<?= htmlspecialchars($weekLabel) ?>">
      <h3 class="exp-hist-week__header">
        <span class="exp-hist-week__label"><?= htmlspecialchars($weekLabel) ?></span>
        <span class="exp-hist-week__meta">
          <span class="exp-hist-week__stat"><?= number_format($weekTotalHl, 2) ?> HL</span>
          <span class="exp-hist-week__sep" aria-hidden="true">·</span>
          <span class="exp-hist-week__stat"><?= number_format($weekTotalUnits) ?> unités</span>
          <span class="exp-hist-week__sep" aria-hidden="true">·</span>
          <span class="exp-hist-week__stat"><?= $weekDocCount ?> doc<?= $weekDocCount > 1 ? 's' : '' ?> BC</span>
        </span>
      </h3>

      <!-- Client rows -->
      <div class="exp-hist-clients" role="list">
        <?php foreach ($weekClientRows as $idx => $wr):
            $cid        = (int) $wr['customer_id_fk'];
            $drillKey   = (string) $yw . ':' . (string) $cid;
            $drillId    = 'exp-hist-drill-' . $yw . '-' . $cid;
            $toggleId   = 'exp-hist-toggle-' . $yw . '-' . $cid;
            $clientLines = $histLinesByKey[$drillKey] ?? [];
            $channelLabel = match ($wr['trade_channel'] ?? '') {
                'on_trade'  => 'On trade',
                'off_trade' => 'Off trade',
                default     => '',
            };
        ?>
        <div class="exp-hist-client-row" role="listitem"
             data-yw="<?= (int) $yw ?>" data-cid="<?= $cid ?>">
          <button type="button"
                  class="exp-hist-client-btn"
                  id="<?= $toggleId ?>"
                  aria-expanded="false"
                  aria-controls="<?= $drillId ?>">
            <span class="exp-hist-client-name"><?= htmlspecialchars((string) $wr['customer_name']) ?></span>
            <?php if ($channelLabel !== ''): ?>
              <span class="exp-hist-channel-badge"><?= htmlspecialchars($channelLabel) ?></span>
            <?php endif ?>
            <span class="exp-hist-bc-badge" aria-label="Source : Historique BC">Historique BC</span>
            <span class="exp-hist-client-metrics" aria-label="<?= number_format((float) $wr['total_hl'], 2) ?> HL, <?= (int) $wr['total_units'] ?> unités, <?= (int) $wr['doc_count'] ?> doc(s) BC">
              <span class="exp-hist-metric"><?= number_format((float) $wr['total_hl'], 2) ?> HL</span>
              <span class="exp-hist-metric__sep" aria-hidden="true">·</span>
              <span class="exp-hist-metric"><?= number_format((int) $wr['total_units']) ?> u.</span>
              <span class="exp-hist-metric__sep" aria-hidden="true">·</span>
              <span class="exp-hist-metric"><?= (int) $wr['doc_count'] ?> doc<?= $wr['doc_count'] > 1 ? 's' : '' ?></span>
            </span>
            <span class="exp-hist-toggle-icon" aria-hidden="true">▸</span>
          </button>

          <!-- SKU drill-down panel (hidden by default) -->
          <div class="exp-hist-drill" id="<?= $drillId ?>" hidden>
            <?php if (empty($clientLines)): ?>
              <p class="exp-hist-drill__empty">Aucune ligne SKU trouvée pour cette semaine.</p>
            <?php else: ?>
              <table class="exp-hist-drill__table" role="table"
                     aria-label="Détail SKU — <?= htmlspecialchars((string) $wr['customer_name']) ?>">
                <thead>
                  <tr>
                    <th scope="col">SKU</th>
                    <th scope="col" class="exp-hist-th-num">Unités</th>
                    <th scope="col" class="exp-hist-th-num">HL</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($clientLines as $cl): ?>
                  <tr>
                    <td class="exp-hist-sku-code"><?= htmlspecialchars((string) $cl['sku_code']) ?></td>
                    <td class="exp-hist-num"><?= number_format((int) $cl['qty']) ?></td>
                    <td class="exp-hist-num"><?= number_format((float) $cl['hl'], 2) ?></td>
                  </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            <?php endif ?>
          </div>
        </div>
        <?php endforeach ?>
      </div>
    </section>

    <?php endforeach ?>
    <?php endif ?>

    <!-- ── Unresolved footnote ──────────────────────────────────────────────── -->
    <?php if (!empty($histUnresolved)):
        $histUnresTotal = array_sum(array_column($histUnresolved, 'line_count'));
    ?>
    <details class="exp-hist-unresolved">
      <summary class="exp-hist-unresolved__summary">
        <?= $histUnresTotal ?> ligne<?= $histUnresTotal !== 1 ? 's' : '' ?> BC non rattachée<?= $histUnresTotal !== 1 ? 's' : '' ?> à un SKU — codes retirés / dépôts / CO₂
      </summary>
      <table class="exp-hist-unresolved__table" role="table" aria-label="Lignes BC non rattachées">
        <thead>
          <tr>
            <th scope="col">Code brut BC</th>
            <th scope="col" class="exp-hist-th-num">Année</th>
            <th scope="col" class="exp-hist-th-num">Lignes</th>
            <th scope="col" class="exp-hist-th-num">Unités</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($histUnresolved as $ur): ?>
          <tr>
            <td class="exp-hist-raw-code"><?= htmlspecialchars((string) $ur['sku_code_raw']) ?></td>
            <td class="exp-hist-num"><?= (int) $ur['yr'] ?></td>
            <td class="exp-hist-num"><?= (int) $ur['line_count'] ?></td>
            <td class="exp-hist-num"><?= number_format((int) $ur['units']) ?></td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </details>
    <?php endif ?>

  </div>
  <!-- /exp-hist-wrap -->

  <?php endif ?>
  <!-- /HISTORIQUE -->

  <!-- ══════════════════════════════════════════════════════════════════════
       RECONDITIONNEMENT VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'repack'): ?>

  <!-- ── Advisory banner — depletion gated until 2026-06-15 ─────────────── -->
  <?php if (!$rkpFlagLive): ?>
  <div class="rkp-advisory-banner" role="status" aria-live="polite">
    <span class="rkp-advisory-banner__icon" aria-hidden="true">⚠</span>
    <span class="rkp-advisory-banner__text">
      <strong>Pré-lancement — Mode consultatif</strong><br>
      Le reconditionnement n'affecte pas encore le stock physique.
      Activation prévue au comptage des cages, <strong>le 15.06</strong>.
    </span>
  </div>
  <?php endif ?>

  <!-- ── Day selector ──────────────────────────────────────────────────── -->
  <div class="rkp-day-bar">
    <form method="GET" action="" class="rkp-day-form">
      <input type="hidden" name="view" value="repack">
      <label for="rkp-date-input" class="rkp-day-label">Journée :</label>
      <input id="rkp-date-input" type="date" name="rkp_date"
             value="<?= htmlspecialchars($rkpDate) ?>"
             class="rkp-date-input op-form__input">
      <button type="submit" class="rkp-day-btn ef-chip ef-chip--next">Afficher</button>
    </form>
    <span class="rkp-day-count">
      <?= count($rkpByOrder ?? []) ?> commande<?= count($rkpByOrder ?? []) !== 1 ? 's' : '' ?>
      · <?= count($rkpProposals) ?> ligne<?= count($rkpProposals) !== 1 ? 's' : '' ?>
    </span>
  </div>

  <!-- ── Assembly panel ───────────────────────────────────────────────── -->
  <button class="rkpa-toggle-btn ef-chip ef-chip--next" id="rkpa-toggle" type="button">
    + Assembler un pack
  </button>
  <div class="rkpa-panel" id="rkpa-panel" hidden>
    <!-- form injected by JS from window.RKPA_DATA -->
  </div>

  <!-- ── Proposals ─────────────────────────────────────────────────────── -->
  <?php if (!empty($rkpByOrder)): ?>
  <div class="rkp-proposals-wrap" id="rkp-proposals" aria-label="Propositions de reconditionnement">
    <?php foreach ($rkpByOrder as $orderId => $orderRows):
        $meta = $rkpOrderMeta[$orderId] ?? ['id' => $orderId, 'fulfilment_mode' => 'delivery', 'order_name' => "#$orderId"];
        exp_render_repack_order_block($meta, $orderRows, $csrf);
    endforeach ?>
  </div>
  <?php else: ?>
  <div class="rkp-empty-state">
    <span class="rkp-empty-state__icon" aria-hidden="true">📦</span>
    <p>Aucune commande eshop à reconditionner pour le <strong><?= htmlspecialchars($rkpDate) ?></strong>.</p>
  </div>
  <?php endif ?>

  <!-- ── Already-logged events for the day ────────────────────────────── -->
  <?php if (!empty($rkpLoggedEvents)): ?>
  <section class="rkp-logged-section" aria-label="Événements déjà enregistrés">
    <h2 class="rkp-section-title">Événements journaliers enregistrés</h2>
    <table class="rkp-logged-table" role="table">
      <thead>
        <tr>
          <th scope="col">Source</th>
          <th scope="col">Boîte ouverte</th>
          <th scope="col">Qté</th>
          <th scope="col">Résultat</th>
          <th scope="col">Type</th>
          <th scope="col">Loose</th>
          <th scope="col">Par</th>
          <th scope="col">Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rkpLoggedEvents as $ev): ?>
        <?php
          $evTombstoned = (bool) (int) $ev['is_tombstoned'];
          $evToKind     = (string) $ev['to_kind'];
          $evKindLabel  = match($evToKind) {
              'bundle' => 'Bundle',
              'pd8'    => 'PD8',
              'loose'  => 'Loose',
              default  => htmlspecialchars($evToKind),
          };
        ?>
        <tr class="rkp-logged-row<?= $evTombstoned ? ' rkp-logged-row--tombstoned' : '' ?>">
          <td><?= $ev['source_order_id_fk'] !== null ? '#' . (int) $ev['source_order_id_fk'] : '—' ?></td>
          <td><span class="rkp-sku-code"><?= htmlspecialchars($ev['from_sku_code']) ?></span></td>
          <td><?= (int) $ev['from_qty'] ?></td>
          <td><span class="rkp-sku-code"><?= htmlspecialchars($ev['to_sku_code']) ?></span></td>
          <td><span class="rkp-kind-badge rkp-kind-badge--<?= htmlspecialchars($evToKind) ?>"><?= $evKindLabel ?></span></td>
          <td><?= (int) $ev['loose_units'] > 0 ? ('+' . (int) $ev['loose_units']) : '—' ?></td>
          <td><?= htmlspecialchars((string) ($ev['submitted_by_name'] ?? '—')) ?></td>
          <td><?= $evTombstoned ? '<span class="rkp-tombstone-badge">Annulé</span>' : '<span class="rkp-active-badge">Actif</span>' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </section>
  <?php endif ?>

  <?php endif ?>
  <!-- /RECONDITIONNEMENT -->

  <!-- ══════════════════════════════════════════════════════════════════════
       RETOURS VIEW
       ══════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'retours'): ?>
  <div class="exp-section exp-ret-section">

  <?php if (!empty($retPendingGroups)): ?>
    <h2 class="exp-ret-title">Retours à traiter</h2>

    <?php foreach ($retPendingGroups as $bcGroup): ?>
    <div class="exp-ret-card">
      <div class="exp-ret-card-header">
        <span class="exp-ret-card-date"><?= htmlspecialchars(exp_fmt_date($bcGroup['posting_date'])) ?></span>
        <span class="exp-ret-card-customer"><?= htmlspecialchars((string) $bcGroup['customer_name']) ?></span>
        <span class="exp-ret-card-docno"><?= htmlspecialchars((string) $bcGroup['bc_document_no']) ?></span>
      </div>
      <?php if (can_write_expeditions($me)): ?>
      <form method="POST" action="/modules/expeditions.php?view=retours" class="exp-ret-form">
        <input type="hidden" name="csrf"           value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="bc_document_no" value="<?= htmlspecialchars((string) $bcGroup['bc_document_no']) ?>">
        <table class="exp-ret-lines-table" role="table">
          <thead>
            <tr>
              <th scope="col">SKU</th>
              <th scope="col">Format</th>
              <th scope="col">Qté retournée</th>
              <th scope="col">Disposition</th>
              <th scope="col">Note ligne</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($bcGroup['lines'] as $bl): ?>
            <tr class="exp-ret-line-row">
              <td>
                <span class="exp-ret-sku-code"><?= htmlspecialchars($bl['sku_code']) ?></span>
                <input type="hidden" name="ret_sku_id[]" value="<?= (int) $bl['sku_id_fk'] ?>">
              </td>
              <td><?= htmlspecialchars((string) $bl['format']) ?></td>
              <td class="exp-ret-qty-bc"><?= number_format($bl['qty'], fmod((float) $bl['qty'], 1.0) === 0.0 ? 0 : 2) ?></td>
              <td>
                <select name="ret_disp[]" class="op-form__input exp-ret-disp-select"
                        aria-label="Disposition pour <?= htmlspecialchars($bl['sku_code']) ?>">
                  <option value="quarantine" selected>Quarantaine</option>
                  <option value="restock">Remise en stock</option>
                  <option value="scrap">Rebut</option>
                  <option value="rebate">Pas un retour physique (avoir/rabais)</option>
                </select>
              </td>
              <td>
                <input type="text" name="ret_line_comment[]" value=""
                       class="op-form__input exp-ret-line-comment"
                       maxlength="500"
                       aria-label="Note pour <?= htmlspecialchars($bl['sku_code']) ?>">
              </td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <div class="exp-ret-actions">
          <button type="submit" class="op-form__btn op-form__btn--primary">Enregistrer</button>
        </div>
      </form>
      <?php endif ?>
    </div>
    <?php endforeach ?>

  <?php else: ?>
    <p class="exp-ret-empty">Aucun retour en attente de traitement.</p>
  <?php endif ?>

  <?php if (!empty($retProcessed)): ?>
    <h2 class="exp-ret-title exp-ret-title--sub">Retours traités</h2>
    <table class="exp-ret-list-table" role="table">
      <thead>
        <tr>
          <th scope="col">Date retour</th>
          <th scope="col">N° avoir BC</th>
          <th scope="col">Client</th>
          <th scope="col">Lignes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($retProcessed as $rp): ?>
        <tr>
          <td><?= htmlspecialchars(exp_fmt_date($rp['returned_on'])) ?></td>
          <td><span class="exp-ret-sku-code"><?= htmlspecialchars((string) ($rp['origin_bc_document_no'] ?? '—')) ?></span></td>
          <td><?= htmlspecialchars((string) ($rp['customer_name'] ?? '—')) ?></td>
          <td><span class="exp-ret-lines-summary"><?= htmlspecialchars((string) ($rp['lines_summary'] ?? '—')) ?></span></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>

  <?php if ($retSynth !== null): ?>
  <?php /* Synthèse (90 j) */ ?>
  <h2 class="exp-ret-title exp-ret-title--sub">Synthèse — 90 derniers jours</h2>

  <?php if (empty($retSynth['by_client']) && $retSynth['pending_count'] === 0): ?>
    <p class="exp-ret-empty">Aucun retour enregistré sur les 90 derniers jours.</p>
  <?php else: ?>

  <?php if (!empty($retSynth['by_client'])): ?>
  <div class="exp-ret-synth-block">
    <h3 class="exp-ret-synth-subtitle">Par client</h3>
    <table class="exp-ret-synth-table" role="table">
      <thead>
        <tr>
          <th scope="col">Client</th>
          <th scope="col" class="exp-ret-synth-num">Unités</th>
          <th scope="col" class="exp-ret-synth-num">Remise en stock</th>
          <th scope="col" class="exp-ret-synth-num">Rebut</th>
          <th scope="col" class="exp-ret-synth-num">Quarantaine</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($retSynth['by_client'] as $sc): ?>
        <tr>
          <td><?= htmlspecialchars((string) $sc['customer_name']) ?></td>
          <td class="exp-ret-synth-num"><?= number_format($sc['total_units'], 0) ?></td>
          <td class="exp-ret-synth-num"><?= number_format($sc['restock_units'], 0) ?></td>
          <td class="exp-ret-synth-num"><?= number_format($sc['scrap_units'], 0) ?></td>
          <td class="exp-ret-synth-num"><?= number_format($sc['quarantine_units'], 0) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <?php if (!empty($retSynth['by_beer'])): ?>
  <div class="exp-ret-synth-block">
    <h3 class="exp-ret-synth-subtitle">Par bière</h3>
    <table class="exp-ret-synth-table exp-ret-synth-table--narrow" role="table">
      <thead>
        <tr>
          <th scope="col">Bière</th>
          <th scope="col" class="exp-ret-synth-num">Unités</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($retSynth['by_beer'] as $sb): ?>
        <tr>
          <td><?= htmlspecialchars((string) $sb['beer_label']) ?></td>
          <td class="exp-ret-synth-num"><?= number_format($sb['total_units'], 0) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <?php if ($retSynth['mix']['total_units'] > 0): ?>
  <p class="exp-ret-synth-mix">
    Mix disposition :
    <strong><?= $retSynth['mix']['restock_pct'] ?>%</strong> remise en stock
    · <strong><?= $retSynth['mix']['scrap_pct'] ?>%</strong> rebut
    · <strong><?= $retSynth['mix']['quarantine_pct'] ?>%</strong> quarantaine
  </p>
  <?php endif ?>

  <?php if ($retSynth['pending_count'] > 0): ?>
  <p class="exp-ret-synth-pending">
    <?= $retSynth['pending_count'] ?> retour<?= $retSynth['pending_count'] > 1 ? 's' : '' ?>
    en attente de disposition (180 j).
  </p>
  <?php endif ?>

  <?php endif /* else: not empty */ ?>
  <?php endif /* $retSynth !== null */ ?>

  </div>
  <?php endif ?>
  <!-- /RETOURS -->

</main>

<?php if ($view === 'commandes'): ?>
<dialog id="exp-stock-detail-modal"></dialog>
<script>
  window.EXP_CSRF         = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  // Advisory stock map for per-order risk flags: {sku_id: {live_futur, physique}}
  window.EXP_CMD_STOCK_MAP = <?= $cmdStockMapJson ?>;
  // Per-order stock short-list: {oid: [{sku_code,requested,available,physique,short_by}]}
  window.EXP_CMD_STOCK_DETAIL = <?= json_encode($cmdStockDetailByOid ?? [], $jsonFlags) ?>;
  // Pull-list aggregation: [{sku_id,sku_code,format,family,total_qty,order_count,hl_each}]
  window.EXP_CMD_PULL     = <?= $cmdPullListJson ?>;
  window.EXP_CMD_PULL_HL  = <?= $cmdPullTotalHlJson ?>;
  window.EXP_FG_SITES     = <?= json_encode(array_values($fgStockSites), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/expeditions.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions.js') ?: time() ?>"></script>
<script src="/js/expeditions-set-site.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-set-site.js') ?: time() ?>"></script>
<script src="/js/eshop-fulfilment.js?v=<?= @filemtime(__DIR__ . '/../js/eshop-fulfilment.js') ?: time() ?>"></script>
<?php endif ?>

<?php if ($view === 'stock'): ?>
<script src="/js/expeditions-stock.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-stock.js') ?: time() ?>"></script>
<?php endif ?>

<?php if ($view === 'stocktake'): ?>
<script src="/js/expeditions-stocktake.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-stocktake.js') ?: time() ?>"></script>
<script defer src="/js/expeditions-stocktake-guided.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-stocktake-guided.js') ?: time() ?>"></script>
<?php endif ?>

<?php if ($view === 'side-stock'): ?>
<script>
  window.SSL_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/expeditions-side-stock.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-side-stock.js') ?: time() ?>"></script>
<?php endif ?>

<?php if ($view === 'historique'): ?>
<script src="/js/expeditions-historique.js?v=<?= @filemtime(__DIR__ . '/../js/expeditions-historique.js') ?: time() ?>"></script>
<?php endif ?>

<?php if ($view === 'repack'): ?>
<script>
  window.RKP_CSRF      = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  window.RKP_DATE      = <?= json_encode($rkpDate, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  window.RKP_FLAG_LIVE = <?= json_encode($rkpFlagLive) ?>;
  window.RKPA_DATA     = <?= json_encode($rkpaData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/repack.js?v=<?= @filemtime(__DIR__ . '/../js/repack.js') ?: time() ?>"></script>
<?php endif ?>

</body>
</html>
