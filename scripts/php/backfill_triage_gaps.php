<?php
declare(strict_types=1);

/**
 * backfill_triage_gaps.php — Close the data gap left by the alias/create triage path.
 *
 * When operators resolved invoice-line-items-needed RQ rows via the alias or create
 * endpoint, those endpoints wrote ref_mi_aliases (and ref_mi for create) but did NOT:
 *   - update doc_invoice_lines.mi_id_fk, and
 *   - insert inv_deliveries rows.
 *
 * This script closes that gap for all resolved rows where decision IN ('alias','create').
 *
 * Steps per RQ row:
 *   1. Load the linked doc_invoices row via file_id_fk.
 *   2. For each doc_invoice_lines row with mi_id_fk IS NULL:
 *      - Try to resolve via ref_mi_aliases.alias = ingredient_name (case-insensitive).
 *      - If miss, try alias = description.
 *      - If resolved: UPDATE doc_invoice_lines.mi_id_fk (--apply only).
 *   3. For each doc_invoice_lines row with mi_id_fk IS NOT NULL (including just-resolved):
 *      - Skip if qty IS NULL or qty = 0 or unit_price IS NULL.
 *      - Dedup check against inv_deliveries (invoice_ref + mi_id_raw + qty + unit_price + status='Active').
 *      - If no match: INSERT inv_deliveries (--apply only).
 *
 * Usage:
 *   php scripts/php/backfill_triage_gaps.php              # dry-run (default)
 *   php scripts/php/backfill_triage_gaps.php --apply      # write to DB
 *   php scripts/php/backfill_triage_gaps.php --rq <id>    # limit to one RQ row
 *
 * Run on VPS:
 *   sudo -u www-data php /var/www/maltytask/scripts/php/backfill_triage_gaps.php
 *   sudo -u www-data php /var/www/maltytask/scripts/php/backfill_triage_gaps.php --apply
 */

require __DIR__ . '/../../app/db.php';

// ── Parse CLI flags ─────────────────────────────────────────────────────────
$apply     = false;
$limitRqId = null;
$args      = $argv ?? [];
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--apply') {
        $apply = true;
    } elseif ($args[$i] === '--rq' && isset($args[$i + 1])) {
        $limitRqId = (int)$args[$i + 1];
        $i++;
    } elseif ($args[$i] === '--help' || $args[$i] === '-h') {
        echo "Usage: php backfill_triage_gaps.php [--apply] [--rq <id>]\n";
        echo "  Default: dry-run. --apply writes to DB.\n";
        exit(0);
    }
}

if (!$apply) {
    echo "=== DRY-RUN mode (no writes). Pass --apply to execute. ===\n\n";
} else {
    echo "=== APPLY mode — writing to DB ===\n\n";
}

// ── Connect ─────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();
// ERRMODE_EXCEPTION is already set in maltytask_pdo(); exceptions bubble freely.

// ── Load RQ rows ─────────────────────────────────────────────────────────────
$rqSql = "
    SELECT id, type, status, decision, file_id_fk, invoice_ref AS rq_invoice_ref
      FROM doc_review_queue
     WHERE type    = 'invoice-line-items-needed'
       AND status  = 'resolved'
       AND decision IN ('alias', 'create')
";
if ($limitRqId !== null) {
    $rqSql .= " AND id = " . (int)$limitRqId;
}
$rqSql .= " ORDER BY id";

$rqRows = $pdo->query($rqSql)->fetchAll();

if (empty($rqRows)) {
    echo "No matching RQ rows found.\n";
    exit(0);
}

echo "Found " . count($rqRows) . " RQ row(s) to process.\n\n";

// ── Reusable helper: look up alias (case-insensitive) ────────────────────────
/**
 * Returns ['mi_id_fk' => int, 'mi_id_str' => string] or null.
 *
 * @param PDO    $pdo
 * @param string $text  The alias text to look up.
 */
function resolveAlias(PDO $pdo, string $text): ?array
{
    $stmt = $pdo->prepare("
        SELECT a.mi_id_fk, rm.mi_id AS mi_id_str
          FROM ref_mi_aliases a
          JOIN ref_mi rm ON rm.id = a.mi_id_fk
         WHERE LOWER(a.alias) = LOWER(?)
           AND rm.is_active = 1
         LIMIT 1
    ");
    $stmt->execute([$text]);
    $row = $stmt->fetch() ?: null;
    if ($row === null) {
        return null;
    }
    return [
        'mi_id_fk'  => (int)$row['mi_id_fk'],
        'mi_id_str' => (string)$row['mi_id_str'],
    ];
}

// ── Counters ─────────────────────────────────────────────────────────────────
$totalRqsScanned  = 0;
$totalMiFkUpdates = 0;
$totalDeliveries  = 0;
$totalSkipped     = 0;
$skipReasons      = [];

// ── Per-RQ processing ────────────────────────────────────────────────────────
foreach ($rqRows as $rq) {
    $rqId = (int)$rq['id'];
    $totalRqsScanned++;

    // ── 1. Load doc_invoices row via doc_files join ───────────────────────────
    $invStmt = $pdo->prepare("
        SELECT di.id         AS inv_id,
               di.supplier_name,
               di.supplier_fk,
               di.invoice_ref,
               di.invoice_date,
               di.currency,
               di.total_ht
          FROM doc_invoices di
          JOIN doc_files df ON df.id = di.file_id
         WHERE df.id = ?
         LIMIT 1
    ");
    $invStmt->execute([(int)$rq['file_id_fk']]);
    $inv = $invStmt->fetch() ?: null;

    if ($inv === null) {
        $msg = "RQ#{$rqId}: SKIP — no doc_invoices row found for file_id_fk={$rq['file_id_fk']}\n\n";
        echo $msg;
        $totalSkipped++;
        $skipReasons[] = "RQ#{$rqId}: no doc_invoices row";
        continue;
    }

    $invId       = (int)$inv['inv_id'];
    $invRef      = (string)($inv['invoice_ref'] ?? '');
    $supplierRaw = (string)($inv['supplier_name'] ?? '');
    $supplierFk  = $inv['supplier_fk'] !== null ? (int)$inv['supplier_fk'] : null;
    $invDate     = $inv['invoice_date'] ?? null;
    $currency    = (string)($inv['currency'] ?? 'CHF');

    echo "RQ#{$rqId} [{$supplierRaw}] invoice_ref={$invRef}\n";
    echo str_repeat('-', 80) . "\n";
    printf("  %-5s  %-24s  %-28s  %9s  %10s  %s\n",
        'line', 'mi_id', 'ingredient_name', 'qty', 'unit_price', 'action');
    echo "  " . str_repeat('-', 74) . "\n";

    // ── 2. Load all lines for this invoice ────────────────────────────────────
    $linesStmt = $pdo->prepare("
        SELECT id, line_index, ingredient_name, description, mi_id_fk, qty, unit_price, line_total
          FROM doc_invoice_lines
         WHERE invoice_id = ?
         ORDER BY line_index
    ");
    $linesStmt->execute([$invId]);
    $lines = $linesStmt->fetchAll();

    // ── Accumulate writes for this invoice (committed in one transaction) ─────
    /** @var list<array{line_id:int, mi_internal_id:int, mi_id_str:string, lookup_text:string}> */
    $miFkUpdates  = [];

    /** @var list<array<string, mixed>> */
    $delivInserts = [];

    foreach ($lines as $line) {
        $lineId    = (int)$line['id'];
        $lineIdx   = (int)$line['line_index'];
        $ingName   = $line['ingredient_name'] !== null ? trim((string)$line['ingredient_name']) : null;
        $desc      = $line['description']     !== null ? trim((string)$line['description'])     : null;
        $qty       = $line['qty']       !== null ? (float)$line['qty']       : null;
        $unitPrice = $line['unit_price'] !== null ? (float)$line['unit_price'] : null;

        // Current state of mi_id_fk (may be null)
        $miFkCurrent = $line['mi_id_fk'] !== null ? (int)$line['mi_id_fk'] : null;

        $miFkResolved    = $miFkCurrent;  // will be updated if alias found
        $miIdStrResolved = null;
        $aliasWasUsed    = false;

        // ── Step 2: attempt alias resolution if mi_id_fk is currently null ───
        if ($miFkResolved === null) {
            // Try ingredient_name first, then description
            $lookupTexts = [];
            if ($ingName !== null && $ingName !== '') {
                $lookupTexts[] = $ingName;
            }
            if ($desc !== null && $desc !== '' && $desc !== $ingName) {
                $lookupTexts[] = $desc;
            }

            foreach ($lookupTexts as $lookupText) {
                $aliasRow = resolveAlias($pdo, $lookupText);
                if ($aliasRow !== null) {
                    $miFkResolved    = $aliasRow['mi_id_fk'];
                    $miIdStrResolved = $aliasRow['mi_id_str'];
                    $aliasWasUsed    = true;
                    $miFkUpdates[]   = [
                        'line_id'        => $lineId,
                        'mi_internal_id' => $miFkResolved,
                        'mi_id_str'      => $miIdStrResolved,
                        'lookup_text'    => $lookupText,
                    ];
                    break;
                }
            }
        } else {
            // Already resolved — look up the mi_id string for display and delivery INSERT
            $miStrStmt = $pdo->prepare("SELECT mi_id FROM ref_mi WHERE id = ? AND is_active = 1 LIMIT 1");
            $miStrStmt->execute([$miFkResolved]);
            $miIdStrResolved = $miStrStmt->fetchColumn() ?: null;
        }

        // Display labels (shown in all actions)
        $ingLabel = ($ingName !== null && $ingName !== '') ? $ingName
                  : (($desc !== null && $desc !== '') ? $desc : '(null)');
        $miLabel  = $miIdStrResolved ?? '?';

        // ── Could not resolve → skip ──────────────────────────────────────────
        if ($miFkResolved === null || $miIdStrResolved === null) {
            $reason = 'unresolved: no alias match';
            printf("  %-5d  %-24s  %-28s  %9s  %10s  SKIP (%s)\n",
                $lineIdx, '?', mb_substr($ingLabel, 0, 28),
                $qty       !== null ? number_format($qty, 4, '.', '') : 'null',
                $unitPrice !== null ? number_format($unitPrice, 4, '.', '') : 'null',
                $reason);
            $totalSkipped++;
            $skipReasons[] = "RQ#{$rqId} line{$lineIdx}: {$reason}";
            continue;
        }

        // ── Step 3: skip rule for missing qty/price ───────────────────────────
        if ($qty === null || $qty == 0.0 || $unitPrice === null) {
            $reason = 'missing qty/price — needs UI fix';
            printf("  %-5d  %-24s  %-28s  %9s  %10s  SKIP (%s)\n",
                $lineIdx, $miLabel, mb_substr($ingLabel, 0, 28),
                $qty       !== null ? number_format($qty, 4, '.', '') : 'null',
                $unitPrice !== null ? number_format($unitPrice, 4, '.', '') : 'null',
                $reason);
            $totalSkipped++;
            $skipReasons[] = "RQ#{$rqId} line{$lineIdx}: {$reason}";
            continue;
        }

        // ── Dedup check ───────────────────────────────────────────────────────
        $dupFound = false;
        if ($invRef !== '') {
            $dedupStmt = $pdo->prepare("
                SELECT 1
                  FROM inv_deliveries
                 WHERE invoice_ref        = ?
                   AND mi_id_raw          = ?
                   AND ABS(qty_delivered  - ?) < 0.001
                   AND ABS(unit_price     - ?) < 0.001
                   AND status             = 'Active'
                 LIMIT 1
            ");
            $dedupStmt->execute([$invRef, $miIdStrResolved, $qty, $unitPrice]);
            $dupFound = ($dedupStmt->fetchColumn() !== false);
        }

        if ($dupFound) {
            $actionNote = 'skip-existing (dedup: existing delivery)';
            if ($aliasWasUsed) {
                $actionNote .= ' + resolve-fk';
            }
            printf("  %-5d  %-24s  %-28s  %9s  %10s  %s\n",
                $lineIdx, $miLabel, mb_substr($ingLabel, 0, 28),
                number_format($qty, 4, '.', ''),
                number_format($unitPrice, 4, '.', ''),
                $actionNote);
            continue;
        }

        // ── Queue delivery insert ─────────────────────────────────────────────
        $computedTotal = round($qty * $unitPrice, 2);
        $rowHashInput  = implode('|', [
            'triage-backfill',
            (string)$rqId,
            (string)$lineIdx,
            $miIdStrResolved,
            (string)$qty,
            (string)$unitPrice,
        ]);
        $rowHash = hash('sha256', $rowHashInput);
        $details = "Triage backfill — RQ #{$rqId} line {$lineIdx} — {$ingLabel}";

        $delivInserts[] = [
            'row_hash'       => $rowHash,
            'date_received'  => $invDate,
            'supplier_raw'   => $supplierRaw,
            'ingredient_raw' => $ingLabel,
            'mi_id_raw'      => $miIdStrResolved,
            'qty_delivered'  => $qty,
            'qty_remaining'  => $qty,  // same as qty_delivered at creation
            'unit_price'     => $unitPrice,
            'currency'       => $currency,
            'total_original' => $computedTotal,
            'total_chf'      => $computedTotal,  // same as original when CHF; operator corrects EUR later
            'invoice_ref'    => $invRef !== '' ? $invRef : null,
            'details'        => $details,
            'supplier_fk'    => $supplierFk,
            'ingredient_fk'  => $miFkResolved,
        ];

        $actionNote = 'insert-delivery';
        if ($aliasWasUsed) {
            $actionNote .= ' + resolve-fk';
        }
        printf("  %-5d  %-24s  %-28s  %9s  %10s  %s\n",
            $lineIdx, $miLabel, mb_substr($ingLabel, 0, 28),
            number_format($qty, 4, '.', ''),
            number_format($unitPrice, 4, '.', ''),
            $actionNote);
    }

    echo "\n";

    // ── Dry-run summary (count but don't write) ───────────────────────────────
    if (!$apply) {
        if (!empty($miFkUpdates)) {
            echo "  [DRY-RUN] Would update mi_id_fk for " . count($miFkUpdates) . " line(s)\n";
        }
        if (!empty($delivInserts)) {
            echo "  [DRY-RUN] Would insert " . count($delivInserts) . " delivery row(s)\n";
        }
        echo "\n";
        $totalMiFkUpdates += count($miFkUpdates);
        $totalDeliveries  += count($delivInserts);
        continue;
    }

    // ── Apply: single transaction per invoice ─────────────────────────────────
    if (empty($miFkUpdates) && empty($delivInserts)) {
        echo "  [APPLIED] RQ#{$rqId}: nothing to write (all lines already covered)\n\n";
        continue;
    }

    try {
        $pdo->beginTransaction();

        // a) UPDATE doc_invoice_lines.mi_id_fk for newly-resolved lines
        foreach ($miFkUpdates as $upd) {
            $updStmt = $pdo->prepare("
                UPDATE doc_invoice_lines
                   SET mi_id_fk   = ?,
                       updated_at = NOW()
                 WHERE id         = ?
                   AND mi_id_fk IS NULL
            ");
            $updStmt->execute([$upd['mi_internal_id'], $upd['line_id']]);
            $totalMiFkUpdates += $updStmt->rowCount();
        }

        // b) INSERT inv_deliveries rows
        $delStmt = $pdo->prepare("
            INSERT IGNORE INTO inv_deliveries
                (row_hash, date_received, supplier_raw, ingredient_raw,
                 mi_id_raw, qty_delivered, qty_remaining, unit_price,
                 currency, total_original, total_chf,
                 status, invoice_ref, source, source_origin,
                 submitted_at, details, supplier_fk, ingredient_fk, resolution)
            VALUES
                (?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?,
                 'Active', ?, 'triage-backfill', 'backfill',
                 NOW(6), ?, ?, ?, 'resolved')
        ");

        foreach ($delivInserts as $di) {
            $delStmt->execute([
                $di['row_hash'],
                $di['date_received'],
                $di['supplier_raw'],
                $di['ingredient_raw'],
                $di['mi_id_raw'],
                $di['qty_delivered'],
                $di['qty_remaining'],
                $di['unit_price'],
                $di['currency'],
                $di['total_original'],
                $di['total_chf'],
                $di['invoice_ref'],
                $di['details'],
                $di['supplier_fk'],
                $di['ingredient_fk'],
            ]);
            $totalDeliveries += $delStmt->rowCount();
        }

        $pdo->commit();
        echo "  [APPLIED] RQ#{$rqId}: "
            . count($miFkUpdates) . " FK update(s), "
            . count($delivInserts) . " delivery insert(s)\n\n";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  [ERROR] RQ#{$rqId}: transaction rolled back — " . $e->getMessage() . "\n\n";
    }
}

// ── Summary footer ────────────────────────────────────────────────────────────
echo str_repeat('=', 80) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 80) . "\n";
printf("  RQs scanned:          %d\n",  $totalRqsScanned);
printf("  mi_id_fk updates:     %d%s\n", $totalMiFkUpdates, $apply ? '' : ' (dry-run)');
printf("  Deliveries inserted:  %d%s\n", $totalDeliveries,  $apply ? '' : ' (dry-run)');
printf("  Skipped:              %d\n",   $totalSkipped);
if (!empty($skipReasons)) {
    echo "  Skip details:\n";
    foreach ($skipReasons as $r) {
        echo "    · {$r}\n";
    }
}
echo str_repeat('=', 80) . "\n";
