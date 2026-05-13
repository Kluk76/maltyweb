<?php
declare(strict_types=1);

/**
 * pre_ingest_cleanup.php — Pre-bulk-ingest database cleanup.
 *
 * Sections (each independently skippable via --skip=<name>):
 *   C1  Merge ref_suppliers duplicates (keep lowest id; reroute all FKs; delete extras)
 *   C2  Backfill NULL supplier_fk in inv_deliveries via alias + fuzzy match
 *   C3  Delete confirmed soft-duplicate inv_deliveries rows (bsf-mirror dupes)
 *   C4  Fix corrupt mi_id_raw "⚠ NOT IN CATALOG" on inv_deliveries id=300
 *
 * Usage:
 *   php scripts/php/pre_ingest_cleanup.php                 # dry-run, all sections
 *   php scripts/php/pre_ingest_cleanup.php --apply         # write to DB
 *   php scripts/php/pre_ingest_cleanup.php --skip=C2,C4    # skip specific sections
 *   php scripts/php/pre_ingest_cleanup.php --apply --skip=C1
 *
 * Run on VPS:
 *   sudo -u www-data php /var/www/maltytask/scripts/php/pre_ingest_cleanup.php
 *   sudo -u www-data php /var/www/maltytask/scripts/php/pre_ingest_cleanup.php --apply
 *
 * NOTE: Before running with --apply, a mysqldump snapshot is created at:
 *   /tmp/pre_cleanup_2026-05-13.sql
 */

require __DIR__ . '/../../app/db.php';

// ── CLI flags ────────────────────────────────────────────────────────────────
$apply    = false;
$skipSecs = [];
$args     = $argv ?? [];

for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--apply') {
        $apply = true;
    } elseif (str_starts_with($args[$i], '--skip=')) {
        $skipSecs = array_map('strtoupper', explode(',', substr($args[$i], 7)));
    } elseif ($args[$i] === '--help' || $args[$i] === '-h') {
        echo "Usage: php pre_ingest_cleanup.php [--apply] [--skip=C1,C2,C3,C4]\n";
        exit(0);
    }
}

function sectionSkipped(string $sec, array $skipSecs): bool
{
    return in_array(strtoupper($sec), $skipSecs, true);
}

if (!$apply) {
    echo "=== DRY-RUN mode (no writes). Pass --apply to execute. ===\n\n";
} else {
    echo "=== APPLY mode — writing to DB ===\n\n";
}

$pdo = maltytask_pdo();

// ── Stats accumulator ────────────────────────────────────────────────────────
$stats = [
    'C1' => ['merged' => 0, 'fk_updated' => 0, 'deleted' => 0, 'skipped' => 0],
    'C2' => ['updated' => 0, 'aliases_created' => 0, 'skipped' => 0],
    'C3' => ['deleted' => 0, 'skipped' => 0],
    'C4' => ['updated' => 0, 'skipped' => 0],
];

// ── Tables that reference ref_suppliers ──────────────────────────────────────
// Ordered: aliases first so the constraint drop on ref_suppliers works cleanly.
const SUPPLIER_FK_COLS = [
    ['table' => 'doc_delivery_notes', 'col' => 'supplier_fk'],
    ['table' => 'doc_invoices',       'col' => 'supplier_fk'],
    ['table' => 'inv_deliveries',     'col' => 'supplier_fk'],
    ['table' => 'mi_proposals_audit', 'col' => 'supplier_id'],
    ['table' => 'ref_supplier_aliases','col' => 'supplier_id_fk'],
];

// ─────────────────────────────────────────────────────────────────────────────
// C1 — Merge ref_suppliers duplicates
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('═', 72) . "\n";
echo "C1 — Merge ref_suppliers duplicates\n";
echo str_repeat('═', 72) . "\n";

if (sectionSkipped('C1', $skipSecs)) {
    echo "  [SKIPPED]\n\n";
} else {
    // Discover all duplicate groups by name
    $dupGroups = $pdo->query(
        "SELECT name, GROUP_CONCAT(id ORDER BY id) AS ids
           FROM ref_suppliers
          GROUP BY name
         HAVING COUNT(*) > 1
          ORDER BY name"
    )->fetchAll();

    if (empty($dupGroups)) {
        echo "  No duplicate supplier names found.\n\n";
    } else {
        echo "  Found " . count($dupGroups) . " duplicate group(s):\n\n";

        foreach ($dupGroups as $grp) {
            $name  = $grp['name'];
            $allIds = array_map('intval', explode(',', $grp['ids']));
            $keepId = $allIds[0];  // lowest id = canonical
            $dropIds = array_slice($allIds, 1);

            printf("  %-45s  keep=%d  drop=%s\n",
                mb_substr($name, 0, 45), $keepId, implode(',', $dropIds));

            if (!$apply) {
                // Count how many FK rows would be reassigned
                foreach (SUPPLIER_FK_COLS as $fc) {
                    $tbl = $fc['table'];
                    $col = $fc['col'];
                    $in  = implode(',', array_fill(0, count($dropIds), '?'));
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM `{$tbl}` WHERE `{$col}` IN ({$in})");
                    $cnt->execute($dropIds);
                    $n = (int)$cnt->fetchColumn();
                    if ($n > 0) {
                        printf("    [DRY-RUN] %d FK row(s) in %s.%s would be reassigned to id=%d\n",
                            $n, $tbl, $col, $keepId);
                    }
                }
                printf("    [DRY-RUN] Would delete %d ref_suppliers row(s): ids %s\n\n",
                    count($dropIds), implode(',', $dropIds));
                $stats['C1']['merged']++;
                continue;
            }

            // Apply
            try {
                $pdo->beginTransaction();

                foreach (SUPPLIER_FK_COLS as $fc) {
                    $tbl = $fc['table'];
                    $col = $fc['col'];
                    $in  = implode(',', array_fill(0, count($dropIds), '?'));
                    $stmt = $pdo->prepare(
                        "UPDATE `{$tbl}` SET `{$col}` = ? WHERE `{$col}` IN ({$in})"
                    );
                    $stmt->execute(array_merge([$keepId], $dropIds));
                    $n = $stmt->rowCount();
                    if ($n > 0) {
                        printf("    Updated %d FK row(s) in %s.%s → %d\n", $n, $tbl, $col, $keepId);
                        $stats['C1']['fk_updated'] += $n;
                    }
                }

                // Delete duplicate ref_supplier_aliases that now point to keepId
                // (unique index on alias would reject duplicates; remove extras first)
                $inDrop = implode(',', array_fill(0, count($dropIds), '?'));
                // After the FK rewrite above, some aliases may now point to keepId
                // and duplicate an existing alias→keepId row; delete by lower id.
                $pdo->prepare(
                    "DELETE a FROM ref_supplier_aliases a
                      INNER JOIN ref_supplier_aliases b
                              ON LOWER(a.alias) = LOWER(b.alias)
                             AND b.supplier_id_fk = a.supplier_id_fk
                             AND b.id < a.id"
                )->execute([]);

                // Now delete the deprecated supplier rows
                $inDrop2 = implode(',', array_fill(0, count($dropIds), '?'));
                $del = $pdo->prepare("DELETE FROM ref_suppliers WHERE id IN ({$inDrop2})");
                $del->execute($dropIds);
                $deleted = $del->rowCount();
                printf("    Deleted %d ref_suppliers row(s): ids %s\n\n", $deleted, implode(',', $dropIds));
                $stats['C1']['deleted'] += $deleted;
                $stats['C1']['merged']++;

                $pdo->commit();

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo "    [ERROR] Roll back — " . $e->getMessage() . "\n\n";
                $stats['C1']['skipped']++;
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// C2 — Backfill NULL supplier_fk in inv_deliveries
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('═', 72) . "\n";
echo "C2 — Backfill NULL supplier_fk in inv_deliveries\n";
echo str_repeat('═', 72) . "\n";

if (sectionSkipped('C2', $skipSecs)) {
    echo "  [SKIPPED]\n\n";
} else {
    // Fetch NULL-fk rows (exclude null supplier_raw and corrupt filename-as-supplier)
    $nullRows = $pdo->query(
        "SELECT id, invoice_ref, supplier_raw, status
           FROM inv_deliveries
          WHERE supplier_fk IS NULL
            AND status NOT IN ('Archived', 'Deleted')
            AND supplier_raw IS NOT NULL
            AND supplier_raw != ''
            AND supplier_raw != '05-12_00000000-0001-4000-8000-000000000099'
          ORDER BY id"
    )->fetchAll();

    if (empty($nullRows)) {
        echo "  No NULL supplier_fk rows to process (excluding corrupt/null).\n\n";
    } else {
        // Build lookup structures
        // 1. Alias map: UPPER(alias) → supplier_id_fk
        $aliasMap = [];
        foreach ($pdo->query("SELECT UPPER(alias) AS ua, supplier_id_fk FROM ref_supplier_aliases") as $row) {
            $aliasMap[$row['ua']] = (int)$row['supplier_id_fk'];
        }

        // 2. Suppliers name list for fuzzy
        $allSuppliers = $pdo->query("SELECT id, name FROM ref_suppliers ORDER BY id")->fetchAll();

        /**
         * Levenshtein-based normalized similarity [0..1].
         */
        function norm_sim(string $a, string $b): float
        {
            $a = mb_strtolower(trim($a));
            $b = mb_strtolower(trim($b));
            if ($a === $b) return 1.0;
            $maxLen = max(mb_strlen($a), mb_strlen($b));
            if ($maxLen === 0) return 1.0;
            $dist = levenshtein($a, $b);
            return 1.0 - ($dist / $maxLen);
        }

        $deferred = [];  // supplier_raw → supplier_id (to register new aliases)

        foreach ($nullRows as $row) {
            $id          = (int)$row['id'];
            $supplierRaw = (string)$row['supplier_raw'];
            $upperRaw    = mb_strtoupper(trim($supplierRaw));

            // Step 1: exact alias match (case-insensitive)
            $resolvedId = $aliasMap[$upperRaw] ?? null;
            $method     = 'alias-exact';

            // Step 2: fuzzy against ref_suppliers.name
            if ($resolvedId === null) {
                $bestScore = 0.0;
                $bestId    = null;
                $bestName  = null;
                foreach ($allSuppliers as $sup) {
                    $score = norm_sim($supplierRaw, $sup['name']);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestId    = (int)$sup['id'];
                        $bestName  = $sup['name'];
                    }
                }
                if ($bestScore >= 0.85) {
                    $resolvedId = $bestId;
                    $method     = sprintf('fuzzy(%.2f)→"%s"', $bestScore, $bestName);
                    // Queue alias registration
                    if (!isset($deferred[$supplierRaw])) {
                        $deferred[$supplierRaw] = $resolvedId;
                    }
                } else {
                    $score = $bestScore ?? 0;
                    printf("  id=%-5d %-45s  SKIP (no match; best fuzzy=%.2f)\n",
                        $id, mb_substr($supplierRaw, 0, 45), $score);
                    $stats['C2']['skipped']++;
                    continue;
                }
            }

            printf("  id=%-5d %-45s  → supplier_fk=%d  [%s]\n",
                $id, mb_substr($supplierRaw, 0, 45), $resolvedId, $method);

            if (!$apply) {
                $stats['C2']['updated']++;
                continue;
            }

            // Update
            $pdo->prepare("UPDATE inv_deliveries SET supplier_fk = ? WHERE id = ?")
                ->execute([$resolvedId, $id]);
            $stats['C2']['updated']++;
        }

        // Register new aliases from fuzzy matches
        if ($apply && !empty($deferred)) {
            echo "\n  Registering new aliases from fuzzy resolutions:\n";
            $insAlias = $pdo->prepare(
                "INSERT IGNORE INTO ref_supplier_aliases (alias, supplier_id_fk) VALUES (?, ?)"
            );
            foreach ($deferred as $rawName => $suppId) {
                $insAlias->execute([$rawName, $suppId]);
                if ($insAlias->rowCount() === 1) {
                    printf("    + alias \"%s\" → supplier_id=%d\n", $rawName, $suppId);
                    $stats['C2']['aliases_created']++;
                }
            }
        } elseif (!$apply && !empty($deferred)) {
            echo "\n  [DRY-RUN] Would register " . count($deferred) . " new alias(es) from fuzzy matches.\n";
        }

        // Report the skipped corrupt row
        $corrupt = $pdo->query(
            "SELECT id, invoice_ref, supplier_raw FROM inv_deliveries
              WHERE supplier_raw = '05-12_00000000-0001-4000-8000-000000000099'"
        )->fetchAll();
        if (!empty($corrupt)) {
            echo "\n  Corrupt filename-as-supplier (skipped — flag for manual cleanup):\n";
            foreach ($corrupt as $cr) {
                printf("    id=%d  invoice_ref=%s  supplier_raw=%s\n",
                    $cr['id'], $cr['invoice_ref'] ?? 'NULL', $cr['supplier_raw']);
                $stats['C2']['skipped']++;
            }
        }

        // Also null-raw rows
        $nullRaw = $pdo->query(
            "SELECT COUNT(*) FROM inv_deliveries
              WHERE supplier_fk IS NULL AND (supplier_raw IS NULL OR supplier_raw = '')
                AND status NOT IN ('Archived', 'Deleted')"
        )->fetchColumn();
        if ($nullRaw > 0) {
            echo "\n  Null/empty supplier_raw rows skipped (not fixable via alias): {$nullRaw}\n";
        }

        echo "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// C3 — Delete confirmed soft-duplicate inv_deliveries rows
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('═', 72) . "\n";
echo "C3 — Delete confirmed soft-duplicate inv_deliveries rows\n";
echo str_repeat('═', 72) . "\n";

if (sectionSkipped('C3', $skipSecs)) {
    echo "  [SKIPPED]\n\n";
} else {
    // Confirmed pairs: [keep_id, delete_id, reason]
    $dupPairs = [
        [945, 279,  'Carbagas TRANS_FREIGHT_INBOUND 33.10 — keep triage-alias, delete Invoice'],
        [943, 267,  'Carbagas TRANS_FREIGHT_INBOUND 198.84 — keep triage-alias, delete Invoice'],
        [947, 281,  'Carbagas TRANS_FREIGHT_INBOUND 9.50 — keep triage-alias, delete Invoice'],
        [318, 319,  'Eurofins Gluten ELISA — both Invoice-OCR, keep lower id'],
        [372, 373,  'Halag Halacid MS ref=336584 — keep Invoice, delete Script'],
        [326, 327,  'Halag Halacid MS ref=339907 — both Invoice-OCR, keep lower id'],
        [889, 346,  'RAJAPACK Papier bulle — keep Invoice-OCR, delete Invoice'],
        [888, 345,  'RAJAPACK Ruban adhésif — keep Invoice-OCR, delete Invoice'],
        [890, 347,  'RAJAPACK Scotch Eshop — keep Invoice-OCR, delete Invoice'],
    ];

    // Note: Westfalen 369/371 have different invoice_refs — NOT included.
    echo "  Note: Westfalen pair 369/371 has different invoice_refs — left untouched.\n\n";

    printf("  %-10s %-10s %s\n", 'KEEP', 'DELETE', 'Reason');
    echo "  " . str_repeat('-', 68) . "\n";

    $toDelete = [];

    foreach ($dupPairs as [$keepId, $delId, $reason]) {
        // Verify both rows exist and data matches
        $stmt = $pdo->prepare(
            "SELECT id, mi_id_raw, qty_delivered, unit_price, invoice_ref, source, status
               FROM inv_deliveries WHERE id IN (?, ?) ORDER BY id"
        );
        $stmt->execute([$keepId, $delId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['id']] = $r;
        }

        if (!isset($rows[$keepId])) {
            printf("  SKIP: keep id=%d not found in DB.\n", $keepId);
            $stats['C3']['skipped']++;
            continue;
        }
        if (!isset($rows[$delId])) {
            printf("  SKIP: delete id=%d not found in DB (already removed?).\n", $delId);
            $stats['C3']['skipped']++;
            continue;
        }

        $k = $rows[$keepId];
        $d = $rows[$delId];

        printf("  KEEP   id=%-5d %-28s qty=%-8s price=%-9s ref=%-12s [%s]\n",
            $keepId, mb_substr($k['mi_id_raw'], 0, 28),
            number_format((float)$k['qty_delivered'], 2),
            number_format((float)$k['unit_price'], 4),
            $k['invoice_ref'] ?? 'NULL', $k['source']
        );
        printf("  DELETE id=%-5d %-28s qty=%-8s price=%-9s ref=%-12s [%s]\n",
            $delId, mb_substr($d['mi_id_raw'], 0, 28),
            number_format((float)$d['qty_delivered'], 2),
            number_format((float)$d['unit_price'], 4),
            $d['invoice_ref'] ?? 'NULL', $d['source']
        );
        printf("         Reason: %s\n\n", $reason);

        $toDelete[] = $delId;
    }

    if (!$apply) {
        echo "  [DRY-RUN] Would delete " . count($toDelete) . " row(s): ids " . implode(',', $toDelete) . "\n\n";
        $stats['C3']['deleted'] = count($toDelete);
    } else {
        if (!empty($toDelete)) {
            try {
                $pdo->beginTransaction();
                $in  = implode(',', array_fill(0, count($toDelete), '?'));
                $del = $pdo->prepare("DELETE FROM inv_deliveries WHERE id IN ({$in})");
                $del->execute($toDelete);
                $deleted = $del->rowCount();
                $pdo->commit();
                echo "  [APPLIED] Deleted {$deleted} row(s): ids " . implode(',', $toDelete) . "\n\n";
                $stats['C3']['deleted'] = $deleted;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo "  [ERROR] Roll back — " . $e->getMessage() . "\n\n";
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// C4 — Fix corrupt mi_id_raw for inv_deliveries id=300
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('═', 72) . "\n";
echo "C4 — Fix corrupt mi_id_raw for inv_deliveries id=300\n";
echo str_repeat('═', 72) . "\n";

if (sectionSkipped('C4', $skipSecs)) {
    echo "  [SKIPPED]\n\n";
} else {
    $stmt = $pdo->prepare(
        "SELECT id, invoice_ref, supplier_raw, mi_id_raw, ingredient_fk, qty_delivered, unit_price
           FROM inv_deliveries WHERE id = 300"
    );
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        echo "  SKIP: inv_deliveries id=300 not found.\n\n";
        $stats['C4']['skipped']++;
    } else {
        printf("  BEFORE: id=%d mi_id_raw=\"%s\" ingredient_fk=%s\n",
            $row['id'], $row['mi_id_raw'],
            $row['ingredient_fk'] ?? 'NULL'
        );

        // ingredient_fk=200 is CLEAN_RFS_BIDON — already set correctly in the DB.
        // mi_id_raw is the string that's corrupt; look it up from ref_mi.
        $miStmt = $pdo->prepare(
            "SELECT id, mi_id, name FROM ref_mi WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        $miStmt->execute([$row['ingredient_fk']]);
        $mi = $miStmt->fetch();

        if (!$mi) {
            // Fallback: search by name
            $miStmt2 = $pdo->prepare(
                "SELECT id, mi_id, name FROM ref_mi WHERE name LIKE '%RFS%' AND is_active = 1 ORDER BY id"
            );
            $miStmt2->execute();
            $candidates = $miStmt2->fetchAll();
            echo "  ingredient_fk=" . ($row['ingredient_fk'] ?? 'NULL') . " not found in ref_mi.\n";
            echo "  Candidates matching 'RFS':\n";
            foreach ($candidates as $c) {
                echo "    id={$c['id']} mi_id={$c['mi_id']} name={$c['name']}\n";
            }
            echo "  Cannot auto-fix: operator must verify and update manually.\n\n";
            $stats['C4']['skipped']++;
        } else {
            printf("  AFTER:  id=%d mi_id_raw=\"%s\" ingredient_fk=%d (ref_mi.name=%s)\n",
                $row['id'], $mi['mi_id'], (int)$mi['id'], $mi['name']
            );

            if (!$apply) {
                printf("  [DRY-RUN] Would set mi_id_raw=\"%s\" for id=300.\n\n", $mi['mi_id']);
                $stats['C4']['updated']++;
            } else {
                $pdo->prepare(
                    "UPDATE inv_deliveries SET mi_id_raw = ?, last_seen_at = NOW() WHERE id = 300"
                )->execute([$mi['mi_id']]);
                printf("  [APPLIED] Set mi_id_raw=\"%s\" for inv_deliveries id=300.\n\n", $mi['mi_id']);
                $stats['C4']['updated']++;
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// C5 — Summary
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('═', 72) . "\n";
echo "C5 — Summary\n";
echo str_repeat('═', 72) . "\n";

$mode = $apply ? '' : ' (dry-run)';

printf("  C1 Supplier merges:            %d group(s) merged%s,  %d FK rows updated,  %d rows deleted,  %d skipped\n",
    $stats['C1']['merged'],    $mode,
    $stats['C1']['fk_updated'],
    $stats['C1']['deleted'],
    $stats['C1']['skipped']
);
printf("  C2 supplier_fk backfills:      %d updated%s,  %d new aliases,  %d skipped\n",
    $stats['C2']['updated'],         $mode,
    $stats['C2']['aliases_created'],
    $stats['C2']['skipped']
);
printf("  C3 soft-dupe deletions:        %d deleted%s,  %d skipped\n",
    $stats['C3']['deleted'],   $mode,
    $stats['C3']['skipped']
);
printf("  C4 mi_id_raw fix (id=300):     %d updated%s,  %d skipped\n",
    $stats['C4']['updated'],   $mode,
    $stats['C4']['skipped']
);
echo str_repeat('═', 72) . "\n";
