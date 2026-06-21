<?php
declare(strict_types=1);

/**
 * email-order-promote.php — Promote a parsed email into a canonical ord_orders row.
 *
 * Public surface:
 *   email_order_promote(PDO, array $me, int $emailId, int $customerId,
 *                       string $requestedDate, array $lines, ?string $comment,
 *                       bool $allowTwin = false): int
 *
 * Dependencies: app/db-write-helpers.php (log_revision).
 * The caller (harness / maltyweb handler) owns the PDO and requires app/db.php itself.
 * This file must NOT require app/db.php — the PDO is injected.
 */

require_once __DIR__ . '/db-write-helpers.php';

// ──────────────────────────────────────────────────────────────────────────────
// Exception hierarchy
// ──────────────────────────────────────────────────────────────────────────────

class EmailOrderPromoteException extends RuntimeException {}

/** The email row does not exist, or its parse_status is not 'parsed'. */
class EmailOrderNotParsedException extends EmailOrderPromoteException {}

/** An ord_orders row already exists for this email's source_ref. */
class EmailOrderAlreadyPromotedException extends EmailOrderPromoteException {}

/**
 * A Shopify pickup order from the same customer email + overlapping SKUs was found
 * within ±7 days of the requested date — possible manual/eshop double-entry.
 */
class EmailOrderTwinException extends EmailOrderPromoteException {}

/** A line entry has an invalid or missing sku_id_fk or qty. */
class EmailOrderInvalidLineException extends EmailOrderPromoteException {}

/** The supplied customerId is invalid (≤ 0). */
class EmailOrderNoCustomerException extends EmailOrderPromoteException {}

// ──────────────────────────────────────────────────────────────────────────────
// Main function
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Promote a parsed doc_email_messages row into a new ord_orders + lines.
 *
 * @param PDO     $pdo           Active PDO connection (caller owns it).
 * @param array   $me            Current user: ['id' => int, 'username' => string].
 * @param int     $emailId       doc_email_messages.id to promote.
 * @param int     $customerId    ref_customers.id (must be > 0; refuse-don't-NULL).
 * @param string  $requestedDate Requested delivery date ('YYYY-MM-DD').
 * @param array   $lines         [['sku_id' => int, 'qty' => float, 'comment' => ?string], ...]
 * @param ?string $comment       Optional order-level comment.
 * @param bool    $allowTwin    If true, skip the Shopify-pickup twin-check.
 * @return int                   New ord_orders.id.
 *
 * @throws EmailOrderNotParsedException       Email not found or parse_status ≠ 'parsed'.
 * @throws EmailOrderAlreadyPromotedException Already promoted (idempotency guard).
 * @throws EmailOrderTwinException            Candidate Shopify-pickup twin found.
 * @throws EmailOrderInvalidLineException     A line has sku_id ≤ 0 or qty ≤ 0.
 * @throws EmailOrderNoCustomerException      customerId ≤ 0.
 */
function email_order_promote(
    PDO     $pdo,
    array   $me,
    int     $emailId,
    int     $customerId,
    string  $requestedDate,
    array   $lines,
    ?string $comment,
    bool    $allowTwin = false
): int {

    // ── Guard: customer must be a positive FK ─────────────────────────────────
    // Checked before the transaction so the error is immediate and cheap.
    if ($customerId <= 0) {
        throw new EmailOrderNoCustomerException(
            "customerId invalide ({$customerId}) — refuse-don't-NULL."
        );
    }

    // ── Guard: $me must carry a positive integer user id ──────────────────────
    // Refuse early rather than write created_by_user_id=0 (FK→users) on a malformed $me.
    if (empty($me['id']) || (int) $me['id'] <= 0) {
        throw new \InvalidArgumentException('email_order_promote: $me[id] manquant ou invalide.');
    }

    // ── Collect valid sku_ids from $lines early (needed for twin-check) ───────
    // We do NOT write yet — collect and validate the set, throw on first bad line.
    $resolvedSkuIds = [];
    $resolvedLines  = [];
    foreach ($lines as $i => $line) {
        $skuId = (int) ($line['sku_id'] ?? 0);
        if ($skuId <= 0) {
            throw new EmailOrderInvalidLineException(
                "Ligne #{$i}: sku_id manquant ou invalide (sku_id={$skuId})."
            );
        }
        $qty = (float) ($line['qty'] ?? 0);
        if ($qty <= 0) {
            throw new EmailOrderInvalidLineException(
                "Ligne #{$i}: qty doit être > 0 (qty={$qty})."
            );
        }
        $lineComment = (isset($line['comment']) && $line['comment'] !== '')
            ? (string) $line['comment']
            : null;
        $resolvedSkuIds[] = $skuId;
        $resolvedLines[]  = ['sku_id' => $skuId, 'qty' => $qty, 'comment' => $lineComment];
    }

    $pdo->beginTransaction();

    try {

        // ── Step 1: Lock the email row ────────────────────────────────────────
        $stmtEmail = $pdo->prepare(
            'SELECT id, message_id, parse_status FROM doc_email_messages WHERE id = ? FOR UPDATE'
        );
        $stmtEmail->execute([$emailId]);
        $emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC);

        if ($emailRow === false) {
            throw new EmailOrderNotParsedException(
                "Email introuvable (id={$emailId})."
            );
        }

        if ($emailRow['parse_status'] === 'order_created') {
            throw new EmailOrderAlreadyPromotedException(
                "Email id={$emailId} déjà promu (parse_status='order_created')."
            );
        }

        if ($emailRow['parse_status'] !== 'parsed') {
            throw new EmailOrderNotParsedException(
                "Email id={$emailId} non parsé (parse_status='{$emailRow['parse_status']}') — "
                . "attendu 'parsed'."
            );
        }

        // ── Step 2: Idempotency pre-check ─────────────────────────────────────
        // Keyed on source_email_id_fk + source='maltytask' (source_ref is assigned
        // post-INSERT as 'mt:<id>' so it cannot be the idempotency key here).
        $stmtExist = $pdo->prepare(
            "SELECT id FROM ord_orders WHERE source_email_id_fk = ? AND source = 'maltytask' LIMIT 1"
        );
        $stmtExist->execute([$emailId]);
        $existingId = $stmtExist->fetchColumn();
        if ($existingId !== false) {
            throw new EmailOrderAlreadyPromotedException(
                "Une commande existe déjà pour cet e-mail (source_email_id_fk={$emailId}, "
                . "ord_orders.id={$existingId})."
            );
        }

        // ── Step 4: Twin-check (best-effort defensive detector) ───────────────
        //
        // Twin-check is best-effort (email-equality + pickup flag + SKU overlap).
        // Precise customer-FK/date match is impossible: inv_sales_orders has no
        // ref_customers FK and no requested-date column. The real XOR guard is
        // structural — email orders only ever write the ord_orders leg.
        //
        // Algorithm:
        //   1. Resolve the customer's email from ref_customers.
        //   2. If email is NULL/empty → no twin possible → skip entirely.
        //   3. If the resolved sku_id set is empty → skip (line-validation already threw).
        //   4. Otherwise query inv_sales_orders JOIN inv_sales_order_lines on email
        //      + source='shopify' + fulfilment_mode='pickup' + created_at ±7d of
        //      requestedDate + SKU overlap.
        if (!$allowTwin && !empty($resolvedSkuIds)) {
            $stmtEmail2 = $pdo->prepare(
                'SELECT email FROM ref_customers WHERE id = ? LIMIT 1'
            );
            $stmtEmail2->execute([$customerId]);
            $customerEmail = $stmtEmail2->fetchColumn();

            if ($customerEmail !== false && $customerEmail !== null && $customerEmail !== '') {
                // Build dynamic IN-list from positive sku_ids (already validated above).
                $inPlaceholders = implode(', ', array_fill(0, count($resolvedSkuIds), '?'));

                $twinSql =
                    'SELECT so.id'
                    . ' FROM inv_sales_orders so'
                    . ' JOIN inv_sales_order_lines sol ON sol.order_id_fk = so.id'
                    . ' WHERE so.source = ?'
                    . '   AND so.fulfilment_mode = ?'
                    . '   AND so.customer_email = ?'
                    . '   AND so.created_at BETWEEN DATE_SUB(?, INTERVAL 7 DAY)'
                    . '                          AND DATE_ADD(?, INTERVAL 7 DAY)'
                    . "   AND sol.sku_id_fk IN ({$inPlaceholders})"
                    . ' LIMIT 1';

                $twinParams = array_merge(
                    ['shopify', 'pickup', $customerEmail, $requestedDate, $requestedDate],
                    $resolvedSkuIds
                );

                $stmtTwin = $pdo->prepare($twinSql);
                $stmtTwin->execute($twinParams);
                $twinRow = $stmtTwin->fetchColumn();

                if ($twinRow !== false) {
                    throw new EmailOrderTwinException(
                        "Possible doublon eshop/Shopify — inv_sales_orders.id={$twinRow} "
                        . "correspond à cet email client + SKU dans la fenêtre ±7 jours."
                    );
                }
            }
        }

        // ── Step 5: INSERT ord_orders ─────────────────────────────────────────
        // Mirrors expeditions.php lines 599-655 exactly; deltas vs that pattern:
        //   source='maltytask', source_email_id_fk set, source_ref='mt:<id>' (set post-INSERT), review_status='accepted'.
        // transporter_id_fk and fulfilment_site_id_fk are left NULL (email orders
        // don't have these at promotion time — they can be set at the confirm step).
        $insOrd = $pdo->prepare(
            'INSERT INTO ord_orders
                (order_type, customer_id_fk, internal_channel, requested_date,
                 status, transporter_id_fk, fulfilment_site_id_fk, comment,
                 source, source_email_id_fk, source_ref, review_status, created_by_user_id)
             VALUES (?, ?, ?, ?, "entered", ?, ?, ?, "maltytask", ?, ?, "accepted", ?)'
        );
        $insOrd->execute([
            'customer',
            $customerId,
            null,                   // internal_channel — NULL for order_type='customer'
            $requestedDate,
            null,                   // transporter_id_fk
            null,                   // fulfilment_site_id_fk
            $comment ?: null,
            $emailId,
            null,                   // source_ref — set below after INSERT to 'mt:<id>'
            (int) $me['id'],
        ]);
        $newOrderId = (int) $pdo->lastInsertId();

        // Assign source_ref = 'mt:<id>' now that we have the PK.
        $sourceRef = 'mt:' . $newOrderId;
        $pdo->prepare('UPDATE ord_orders SET source_ref = ? WHERE id = ?')
            ->execute([$sourceRef, $newOrderId]);

        $afterOrder = [
            'order_type'            => 'customer',
            'customer_id_fk'        => $customerId,
            'internal_channel'      => null,
            'requested_date'        => $requestedDate,
            'status'                => 'entered',
            'transporter_id_fk'     => null,
            'fulfilment_site_id_fk' => null,
            'comment'               => $comment ?: null,
            'source'                => 'maltytask',
            'source_email_id_fk'    => $emailId,
            'source_ref'            => $sourceRef,
            'review_status'         => 'accepted',
            'created_by_user_id'    => (int) $me['id'],
        ];
        log_revision($pdo, $me, 'ord_orders', $newOrderId, null, $afterOrder, 'normal');

        // ── Step 6: INSERT ord_order_lines ────────────────────────────────────
        // $resolvedLines was built and validated before the transaction;
        // sku_id and qty guards already threw. We iterate the resolved structure.
        $insLine = $pdo->prepare(
            'INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty, line_comment)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($resolvedLines as $i => $line) {
            $skuId       = $line['sku_id'];
            $qty         = $line['qty'];
            $lineComment = $line['comment'];

            // Defensive guard (belt-and-suspenders; pre-loop already validated).
            if ($skuId <= 0) {
                throw new EmailOrderInvalidLineException(
                    "Ligne #{$i}: sku_id invalide à l'écriture (sku_id={$skuId})."
                );
            }
            if ($qty <= 0) {
                throw new EmailOrderInvalidLineException(
                    "Ligne #{$i}: qty invalide à l'écriture (qty={$qty})."
                );
            }

            $insLine->execute([$newOrderId, $skuId, $qty, $lineComment]);
            $lineId = (int) $pdo->lastInsertId();

            $afterLine = [
                'order_id_fk'  => $newOrderId,
                'sku_id_fk'    => $skuId,
                'qty'          => $qty,
                'line_comment' => $lineComment,
            ];
            log_revision($pdo, $me, 'ord_order_lines', $lineId, null, $afterLine, 'normal');
        }

        // ── Step 7: INSERT status event ───────────────────────────────────────
        $insEv = $pdo->prepare(
            'INSERT INTO ord_order_status_events
                (order_id_fk, status, occurred_at, user_id_fk, comment)
             VALUES (?, "entered", NOW(), ?, "Commande validée depuis email")'
        );
        $insEv->execute([$newOrderId, (int) $me['id']]);
        $evId = (int) $pdo->lastInsertId();
        log_revision($pdo, $me, 'ord_order_status_events', $evId, null, [
            'order_id_fk' => $newOrderId,
            'status'      => 'entered',
            'user_id_fk'  => (int) $me['id'],
            'comment'     => 'Commande validée depuis email',
        ], 'normal');

        // ── Step 8: Mark email as order_created ───────────────────────────────
        $updEmail = $pdo->prepare(
            'UPDATE doc_email_messages SET parse_status = "order_created" WHERE id = ?'
        );
        $updEmail->execute([$emailId]);
        log_revision($pdo, $me, 'doc_email_messages', $emailId,
            ['parse_status' => 'parsed'],
            ['parse_status' => 'order_created'],
            'normal'
        );

        // ── Step 9: Commit ────────────────────────────────────────────────────
        $pdo->commit();
        return $newOrderId;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Promote a parsed doc_email_messages row into MULTIPLE ord_orders rows — one per sub-order.
 * All sub-orders are committed atomically in a single transaction.
 * The email row is flipped to parse_status='order_created' ONCE after all sub-orders.
 *
 * @param PDO   $pdo        Active PDO connection (caller owns it).
 * @param array $me         Current user: ['id' => int, 'username' => string].
 * @param array $subOrders  [ ['customer_id'=>int, 'requested_date'=>'YYYY-MM-DD',
 *                             'lines'=>[['sku_id'=>int,'qty'=>float,'comment'=>?str],...],
 *                             'comment'=>?str], ... ]
 * @param int   $emailId    doc_email_messages.id to promote.
 * @param bool  $allowTwin  If true, skip the Shopify-pickup twin-check per sub-order.
 * @return int[]            New ord_orders.id values, one per sub-order.
 *
 * @throws EmailOrderNotParsedException       Email not found or parse_status ≠ 'parsed'.
 * @throws EmailOrderAlreadyPromotedException Already promoted (idempotency guard).
 * @throws EmailOrderTwinException            Candidate Shopify-pickup twin found (first hit).
 * @throws EmailOrderInvalidLineException     A line has sku_id ≤ 0 or qty ≤ 0.
 * @throws EmailOrderNoCustomerException      A sub-order has customer_id ≤ 0.
 */
function email_order_promote_multi(
    PDO   $pdo,
    array $me,
    array $subOrders,
    int   $emailId,
    bool  $allowTwin = false
): array {

    // ── Pre-transaction validation ────────────────────────────────────────────
    if (empty($me['id']) || (int) $me['id'] <= 0) {
        throw new \InvalidArgumentException('email_order_promote_multi: $me[id] manquant ou invalide.');
    }
    if (count($subOrders) < 1) {
        throw new \InvalidArgumentException('email_order_promote_multi: au moins une sous-commande requise.');
    }

    $resolvedSubOrders = [];
    foreach ($subOrders as $si => $sub) {
        $custId   = (int) ($sub['customer_id'] ?? 0);
        $reqDate  = trim((string) ($sub['requested_date'] ?? ''));
        $rawLines = $sub['lines'] ?? [];

        if ($custId <= 0) {
            throw new EmailOrderNoCustomerException(
                "Sous-commande #{$si}: customer_id invalide ({$custId}) — refuse-don't-NULL."
            );
        }
        if ($reqDate === '') {
            throw new \InvalidArgumentException(
                "Sous-commande #{$si}: requested_date manquante."
            );
        }
        if (count($rawLines) < 1) {
            throw new EmailOrderInvalidLineException(
                "Sous-commande #{$si}: au moins une ligne requise."
            );
        }

        $resolvedLines  = [];
        $resolvedSkuIds = [];
        foreach ($rawLines as $li => $line) {
            $skuId = (int) ($line['sku_id'] ?? 0);
            if ($skuId <= 0) {
                throw new EmailOrderInvalidLineException(
                    "Sous-commande #{$si} ligne #{$li}: sku_id manquant ou invalide (sku_id={$skuId})."
                );
            }
            $qty = (float) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                throw new EmailOrderInvalidLineException(
                    "Sous-commande #{$si} ligne #{$li}: qty doit être > 0 (qty={$qty})."
                );
            }
            $lineComment = (isset($line['comment']) && $line['comment'] !== '')
                ? (string) $line['comment']
                : null;
            $resolvedSkuIds[] = $skuId;
            $resolvedLines[]  = ['sku_id' => $skuId, 'qty' => $qty, 'comment' => $lineComment];
        }

        $resolvedSubOrders[] = [
            'customer_id'    => $custId,
            'requested_date' => $reqDate,
            'comment'        => isset($sub['comment']) && $sub['comment'] !== '' ? (string) $sub['comment'] : null,
            'lines'          => $resolvedLines,
            'sku_ids'        => $resolvedSkuIds,
        ];
    }

    $pdo->beginTransaction();

    try {

        // ── Lock the email row ────────────────────────────────────────────────
        $stmtEmail = $pdo->prepare(
            'SELECT id, message_id, parse_status FROM doc_email_messages WHERE id = ? FOR UPDATE'
        );
        $stmtEmail->execute([$emailId]);
        $emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC);

        if ($emailRow === false) {
            throw new EmailOrderNotParsedException(
                "Email introuvable (id={$emailId})."
            );
        }
        if ($emailRow['parse_status'] === 'order_created') {
            throw new EmailOrderAlreadyPromotedException(
                "Email id={$emailId} déjà promu (parse_status='order_created')."
            );
        }
        if ($emailRow['parse_status'] !== 'parsed') {
            throw new EmailOrderNotParsedException(
                "Email id={$emailId} non parsé (parse_status='{$emailRow['parse_status']}') — attendu 'parsed'."
            );
        }

        // ── Idempotency: check if any orders already exist for this email ─────
        $stmtExist = $pdo->prepare(
            "SELECT id FROM ord_orders WHERE source_email_id_fk = ? AND source = 'maltytask' LIMIT 1"
        );
        $stmtExist->execute([$emailId]);
        $existingId = $stmtExist->fetchColumn();
        if ($existingId !== false) {
            throw new EmailOrderAlreadyPromotedException(
                "Une commande existe déjà pour cet e-mail (source_email_id_fk={$emailId}, ord_orders.id={$existingId})."
            );
        }

        // ── Per sub-order: twin-check + INSERT ───────────────────────────────
        $newOrderIds = [];
        $insOrd = $pdo->prepare(
            'INSERT INTO ord_orders
                (order_type, customer_id_fk, internal_channel, requested_date,
                 status, transporter_id_fk, fulfilment_site_id_fk, comment,
                 source, source_email_id_fk, source_ref, review_status, created_by_user_id)
             VALUES (?, ?, ?, ?, "entered", ?, ?, ?, "maltytask", ?, ?, "accepted", ?)'
        );
        $insLine = $pdo->prepare(
            'INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty, line_comment)
             VALUES (?, ?, ?, ?)'
        );
        $insEv = $pdo->prepare(
            'INSERT INTO ord_order_status_events
                (order_id_fk, status, occurred_at, user_id_fk, comment)
             VALUES (?, "entered", NOW(), ?, "Commande validée depuis email (multi)")'
        );

        foreach ($resolvedSubOrders as $si => $sub) {
            $custId         = $sub['customer_id'];
            $reqDate        = $sub['requested_date'];
            $resolvedSkuIds = $sub['sku_ids'];
            $resolvedLines  = $sub['lines'];
            $subComment     = $sub['comment'];

            // Twin-check (per sub-order, same algorithm as single-order)
            if (!$allowTwin && !empty($resolvedSkuIds)) {
                $stmtCustEmail = $pdo->prepare(
                    'SELECT email FROM ref_customers WHERE id = ? LIMIT 1'
                );
                $stmtCustEmail->execute([$custId]);
                $customerEmail = $stmtCustEmail->fetchColumn();

                if ($customerEmail !== false && $customerEmail !== null && $customerEmail !== '') {
                    $inPlaceholders = implode(', ', array_fill(0, count($resolvedSkuIds), '?'));
                    $twinSql =
                        'SELECT so.id'
                        . ' FROM inv_sales_orders so'
                        . ' JOIN inv_sales_order_lines sol ON sol.order_id_fk = so.id'
                        . ' WHERE so.source = ?'
                        . '   AND so.fulfilment_mode = ?'
                        . '   AND so.customer_email = ?'
                        . '   AND so.created_at BETWEEN DATE_SUB(?, INTERVAL 7 DAY)'
                        . '                          AND DATE_ADD(?, INTERVAL 7 DAY)'
                        . "   AND sol.sku_id_fk IN ({$inPlaceholders})"
                        . ' LIMIT 1';
                    $twinParams = array_merge(
                        ['shopify', 'pickup', $customerEmail, $reqDate, $reqDate],
                        $resolvedSkuIds
                    );
                    $stmtTwin = $pdo->prepare($twinSql);
                    $stmtTwin->execute($twinParams);
                    $twinRow = $stmtTwin->fetchColumn();
                    if ($twinRow !== false) {
                        throw new EmailOrderTwinException(
                            "Sous-commande #{$si}: possible doublon eshop/Shopify — inv_sales_orders.id={$twinRow} "
                            . "correspond à cet email client + SKU dans la fenêtre ±7 jours."
                        );
                    }
                }
            }

            // INSERT ord_orders
            $insOrd->execute([
                'customer',
                $custId,
                null,
                $reqDate,
                null,
                null,
                $subComment,
                $emailId,
                null,
                (int) $me['id'],
            ]);
            $newOrderId = (int) $pdo->lastInsertId();

            // Set source_ref = 'mt:<id>'
            $sourceRef = 'mt:' . $newOrderId;
            $pdo->prepare('UPDATE ord_orders SET source_ref = ? WHERE id = ?')
                ->execute([$sourceRef, $newOrderId]);

            $afterOrder = [
                'order_type'            => 'customer',
                'customer_id_fk'        => $custId,
                'internal_channel'      => null,
                'requested_date'        => $reqDate,
                'status'                => 'entered',
                'transporter_id_fk'     => null,
                'fulfilment_site_id_fk' => null,
                'comment'               => $subComment,
                'source'                => 'maltytask',
                'source_email_id_fk'    => $emailId,
                'source_ref'            => $sourceRef,
                'review_status'         => 'accepted',
                'created_by_user_id'    => (int) $me['id'],
            ];
            log_revision($pdo, $me, 'ord_orders', $newOrderId, null, $afterOrder, 'normal');

            // INSERT ord_order_lines
            foreach ($resolvedLines as $li => $line) {
                $skuId       = $line['sku_id'];
                $qty         = $line['qty'];
                $lineComment = $line['comment'];
                $insLine->execute([$newOrderId, $skuId, $qty, $lineComment]);
                $lineId = (int) $pdo->lastInsertId();
                log_revision($pdo, $me, 'ord_order_lines', $lineId, null, [
                    'order_id_fk'  => $newOrderId,
                    'sku_id_fk'    => $skuId,
                    'qty'          => $qty,
                    'line_comment' => $lineComment,
                ], 'normal');
            }

            // INSERT status event
            $insEv->execute([$newOrderId, (int) $me['id']]);
            $evId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ord_order_status_events', $evId, null, [
                'order_id_fk' => $newOrderId,
                'status'      => 'entered',
                'user_id_fk'  => (int) $me['id'],
                'comment'     => 'Commande validée depuis email (multi)',
            ], 'normal');

            $newOrderIds[] = $newOrderId;
        }

        // ── Mark email as order_created (ONCE, after all sub-orders) ──────────
        $pdo->prepare('UPDATE doc_email_messages SET parse_status = "order_created" WHERE id = ?')
            ->execute([$emailId]);
        log_revision($pdo, $me, 'doc_email_messages', $emailId,
            ['parse_status' => 'parsed'],
            ['parse_status' => 'order_created'],
            'normal'
        );

        $pdo->commit();
        return $newOrderIds;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
