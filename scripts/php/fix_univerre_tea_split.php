<?php
declare(strict_types=1);

/**
 * fix_univerre_tea_split.php — Correct the Univerre TEA split for 3 historical invoices.
 *
 * Background:
 *   The ta_update_invoice_line() COALESCE bug (fixed separately) caused the triage action
 *   for TEA entry to overwrite the bottle line's qty/unit_price with TEA values.
 *   In addition, doc_invoice_lines L1 (TEA) has mi_id_fk=91 (PKG_BOT_PIVO) instead of
 *   561 (PKG_TEA_BOT_CH).
 *
 *   The 3 affected invoices:
 *     22511289 (doc_invoice id=16) — inv_deliveries id=105, doc_invoice_lines ids=55,56
 *     22600408 (doc_invoice id=35) — inv_deliveries id=106, doc_invoice_lines ids=107,108
 *     22600513 (doc_invoice id=41) — inv_deliveries id=107, doc_invoice_lines ids=121,122
 *
 * What this script does per invoice (inside one transaction):
 *   1. inv_deliveries bottle row: restore unit_price=0.1466 EUR,
 *      recalculate total_original = qty × 0.1466,
 *      recalculate total_chf = qty × 0.1466 × eur_to_chf.
 *   2. doc_invoice_lines L0 (bottle): restore qty to inv_deliveries.qty_delivered,
 *      unit_price=0.1466, line_total = qty × 0.1466.
 *   3. doc_invoice_lines L1 (TEA): set mi_id_fk=561 (PKG_TEA_BOT_CH); leave qty/price.
 *
 * Idempotency: if inv_deliveries.unit_price < 0.16 for the bottle row, it's already
 * been stripped — skip that invoice.
 *
 * Usage:
 *   php scripts/php/fix_univerre_tea_split.php          # dry-run (default)
 *   php scripts/php/fix_univerre_tea_split.php --apply  # write to DB
 *
 * Run on VPS:
 *   sudo -u www-data php /var/www/maltytask/scripts/php/fix_univerre_tea_split.php
 *   sudo -u www-data php /var/www/maltytask/scripts/php/fix_univerre_tea_split.php --apply
 */

require __DIR__ . '/../../app/db.php';

// ── CLI flags ────────────────────────────────────────────────────────────────
$apply = in_array('--apply', $argv ?? [], true);

if (!$apply) {
    echo "=== DRY-RUN mode (no writes). Pass --apply to execute. ===\n\n";
} else {
    echo "=== APPLY mode — writing to DB ===\n\n";
}

$pdo = maltytask_pdo();

// ── Lookup PKG_TEA_BOT_CH internal id ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id FROM ref_mi WHERE mi_id = 'PKG_TEA_BOT_CH' AND is_active = 1 LIMIT 1");
$stmt->execute();
$teaMiId = $stmt->fetchColumn();
if ($teaMiId === false) {
    echo "ERROR: ref_mi row for PKG_TEA_BOT_CH not found. Aborting.\n";
    exit(1);
}
$teaMiId = (int)$teaMiId;
echo "PKG_TEA_BOT_CH internal id = {$teaMiId}\n\n";

// ── Invoice config ────────────────────────────────────────────────────────────
const BOTTLE_UNIT_PRICE = 0.1466;

// Each entry: invoice_ref, doc_invoice id, inv_deliveries bottle row id,
//             doc_invoice_lines bottle line id, doc_invoice_lines TEA line id
$invoices = [
    [
        'invoice_ref'      => '22511289',
        'doc_invoice_id'   => 16,
        'inv_del_id'       => 105,   // bottle delivery row
        'dil_bottle_id'    => 55,    // doc_invoice_lines L0 (bottle)
        'dil_tea_id'       => 56,    // doc_invoice_lines L1 (TEA)
    ],
    [
        'invoice_ref'      => '22600408',
        'doc_invoice_id'   => 35,
        'inv_del_id'       => 106,
        'dil_bottle_id'    => 107,
        'dil_tea_id'       => 108,
    ],
    [
        'invoice_ref'      => '22600513',
        'doc_invoice_id'   => 41,
        'inv_del_id'       => 107,
        'dil_bottle_id'    => 121,
        'dil_tea_id'       => 122,
    ],
];

$totalFixed   = 0;
$totalSkipped = 0;

foreach ($invoices as $cfg) {
    $ref          = $cfg['invoice_ref'];
    $invDelId     = $cfg['inv_del_id'];
    $dilBottleId  = $cfg['dil_bottle_id'];
    $dilTeaId     = $cfg['dil_tea_id'];

    echo str_repeat('─', 72) . "\n";
    echo "Invoice {$ref} (doc_invoice id={$cfg['doc_invoice_id']})\n";

    // ── Load inv_deliveries bottle row ────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT id, mi_id_raw, qty_delivered, unit_price, eur_to_chf, total_original, total_chf, status
           FROM inv_deliveries WHERE id = ?"
    );
    $stmt->execute([$invDelId]);
    $delRow = $stmt->fetch();
    if (!$delRow) {
        echo "  SKIP: inv_deliveries id={$invDelId} not found.\n\n";
        $totalSkipped++;
        continue;
    }

    $currentQty   = (float)$delRow['qty_delivered'];
    $currentPrice = (float)$delRow['unit_price'];
    $eurToChf     = $delRow['eur_to_chf'] !== null ? (float)$delRow['eur_to_chf'] : null;

    // ── Idempotency check ─────────────────────────────────────────────────────
    if ($currentPrice < 0.16) {
        echo "  SKIP (already fixed): inv_deliveries unit_price={$currentPrice} < 0.16\n\n";
        $totalSkipped++;
        continue;
    }

    // ── Load doc_invoice_lines ────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT id, line_index, ingredient_name, mi_id_fk, qty, unit_price, line_total
           FROM doc_invoice_lines WHERE id IN (?, ?) ORDER BY line_index"
    );
    $stmt->execute([$dilBottleId, $dilTeaId]);
    $dilRows = [];
    foreach ($stmt->fetchAll() as $r) {
        $dilRows[$r['id']] = $r;
    }

    // ── Compute new values ────────────────────────────────────────────────────
    $newTotalOriginal = round($currentQty * BOTTLE_UNIT_PRICE, 2);
    $newTotalChf      = $eurToChf !== null
                        ? round($newTotalOriginal * $eurToChf, 4)
                        : $newTotalOriginal;
    $newLineTotal     = $newTotalOriginal;  // line_total is in EUR (invoice currency)

    // ── Print BEFORE ──────────────────────────────────────────────────────────
    printf("  inv_deliveries id=%d BEFORE: qty=%.0f unit_price=%.6f eur_to_chf=%s total_original=%.4f total_chf=%.4f\n",
        $invDelId, $currentQty, $currentPrice,
        $eurToChf !== null ? number_format($eurToChf, 5) : 'NULL',
        (float)$delRow['total_original'], (float)$delRow['total_chf']
    );
    printf("  inv_deliveries id=%d AFTER:  qty=%.0f unit_price=%.4f eur_to_chf=%s total_original=%.4f total_chf=%.4f\n",
        $invDelId, $currentQty, BOTTLE_UNIT_PRICE,
        $eurToChf !== null ? number_format($eurToChf, 5) : 'NULL',
        $newTotalOriginal, $newTotalChf
    );

    if (isset($dilRows[$dilBottleId])) {
        $dr = $dilRows[$dilBottleId];
        printf("  doc_invoice_lines id=%d (L%d bottle) BEFORE: qty=%s unit_price=%s line_total=%s mi_id_fk=%s\n",
            $dilBottleId, (int)$dr['line_index'],
            $dr['qty'] ?? 'NULL', $dr['unit_price'] ?? 'NULL',
            $dr['line_total'] ?? 'NULL', $dr['mi_id_fk'] ?? 'NULL'
        );
        printf("  doc_invoice_lines id=%d (L%d bottle) AFTER:  qty=%.0f unit_price=%.4f line_total=%.4f mi_id_fk=%d (unchanged)\n",
            $dilBottleId, (int)$dr['line_index'],
            $currentQty, BOTTLE_UNIT_PRICE, $newLineTotal, (int)$dr['mi_id_fk']
        );
    } else {
        echo "  WARNING: doc_invoice_lines id={$dilBottleId} not found.\n";
    }

    if (isset($dilRows[$dilTeaId])) {
        $tr = $dilRows[$dilTeaId];
        printf("  doc_invoice_lines id=%d (L%d TEA)    BEFORE: qty=%s unit_price=%s line_total=%s mi_id_fk=%s\n",
            $dilTeaId, (int)$tr['line_index'],
            $tr['qty'] ?? 'NULL', $tr['unit_price'] ?? 'NULL',
            $tr['line_total'] ?? 'NULL', $tr['mi_id_fk'] ?? 'NULL'
        );
        printf("  doc_invoice_lines id=%d (L%d TEA)    AFTER:  qty=%s unit_price=%s line_total=%s mi_id_fk=%d (PKG_TEA_BOT_CH)\n",
            $dilTeaId, (int)$tr['line_index'],
            $tr['qty'] ?? 'NULL', $tr['unit_price'] ?? 'NULL',
            $tr['line_total'] ?? 'NULL', $teaMiId
        );
    } else {
        echo "  WARNING: doc_invoice_lines id={$dilTeaId} not found.\n";
    }

    echo "\n";

    if (!$apply) {
        echo "  [DRY-RUN] Would update 1 inv_deliveries row, up to 2 doc_invoice_lines rows.\n\n";
        $totalFixed++;
        continue;
    }

    // ── Apply: single transaction per invoice ─────────────────────────────────
    try {
        $pdo->beginTransaction();

        // 1. inv_deliveries bottle row
        $pdo->prepare(
            "UPDATE inv_deliveries
                SET unit_price      = ?,
                    total_original  = ?,
                    total_chf       = ?,
                    last_seen_at    = NOW()
              WHERE id              = ?"
        )->execute([BOTTLE_UNIT_PRICE, $newTotalOriginal, $newTotalChf, $invDelId]);

        // 2. doc_invoice_lines L0 (bottle): restore qty + price
        if (isset($dilRows[$dilBottleId])) {
            $pdo->prepare(
                "UPDATE doc_invoice_lines
                    SET qty        = ?,
                        unit_price = ?,
                        line_total = ?
                  WHERE id         = ?"
            )->execute([$currentQty, BOTTLE_UNIT_PRICE, $newLineTotal, $dilBottleId]);
        }

        // 3. doc_invoice_lines L1 (TEA): fix mi_id_fk only
        if (isset($dilRows[$dilTeaId])) {
            $pdo->prepare(
                "UPDATE doc_invoice_lines
                    SET mi_id_fk   = ?
                  WHERE id         = ?"
            )->execute([$teaMiId, $dilTeaId]);
        }

        $pdo->commit();
        echo "  [APPLIED] Invoice {$ref}: inv_deliveries + doc_invoice_lines updated.\n\n";
        $totalFixed++;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  [ERROR] Invoice {$ref}: transaction rolled back — " . $e->getMessage() . "\n\n";
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo str_repeat('=', 72) . "\n";
printf("Fixed:   %d invoice(s)%s\n", $totalFixed,   $apply ? '' : ' (dry-run)');
printf("Skipped: %d invoice(s)\n",   $totalSkipped);
echo str_repeat('=', 72) . "\n";
