<?php
/**
 * WeeklyOrders → ord_orders operational import
 *
 * Loads /tmp/wo-parsed.json (produced by /tmp/wo-extract.js),
 * resolves customers + SKUs, and emits a match report.
 *
 * Default mode: DRY-RUN (no writes).
 * --apply : write matched rows to ord_orders / ord_order_lines / ord_order_status_events.
 *
 * Usage (on VPS):
 *   sudo -u www-data php /var/www/maltytask/scripts/import_weeklyorders.php
 *   sudo -u www-data php /var/www/maltytask/scripts/import_weeklyorders.php --apply
 */

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('CLI_ACTOR',   'import-weeklyorders');
define('CLI_USER_ID', 0);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/db-write-helpers.php';
require_once __DIR__ . '/../app/fulfilment-site.php';

$applyMode = in_array('--apply', $argv ?? [], true);
$inputPath = '/tmp/wo-parsed.json';
$reportPath = '/tmp/wo-match-report.md';

// ── Load input ────────────────────────────────────────────────────────────────
if (!file_exists($inputPath)) {
    fwrite(STDERR, "ERROR: $inputPath not found. Run /tmp/wo-extract.js first.\n");
    exit(1);
}
$raw = json_decode(file_get_contents($inputPath), true);
if (!is_array($raw)) {
    fwrite(STDERR, "ERROR: Failed to parse $inputPath as JSON.\n");
    exit(1);
}

echo "Loaded " . count($raw) . " rows from $inputPath\n";
echo "Mode: " . ($applyMode ? "** APPLY **" : "DRY-RUN") . "\n\n";

$pdo = maltytask_pdo();
$me  = ['id' => CLI_USER_ID, 'username' => CLI_ACTOR];

// ── Load ref tables ───────────────────────────────────────────────────────────

// Customers: index by bc_customer_no and by normalized name.
// Load ALL rows (including is_active=0) so we can follow merged_into chains.
// The lookup indexes (bc, name) include tombstoned rows — resolve_customer
// will call follow_merge_chain() after any match to arrive at the canonical
// active record (or declare unmatched if the chain dead-ends).
$customersByBc   = [];   // bc_customer_no (string) → row (any is_active)
$customersByName = [];   // normalized name → row (any is_active)
$allCustomers    = [];   // id → row (for fuzzy + chain-following)

$stmt = $pdo->query(
    "SELECT id, name, bc_customer_no, trade_channel, is_active, notes,
            default_transporter_id_fk
     FROM ref_customers"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $allCustomers[(int)$c['id']] = $c;
    if ($c['bc_customer_no'] !== null && $c['bc_customer_no'] !== '') {
        // BC# is unique per canonical record; stubs have no bc — safe to index all
        $customersByBc[$c['bc_customer_no']] = $c;
    }
    $nk = normalize_str($c['name']);
    // Last-write wins for name collisions; tombstoned stubs often share names
    // with canonicals — we handle the is_active=0 case via follow_merge_chain()
    $customersByName[$nk] = $c;
}

// SKUs: by sku_code exact and by alias
$skusByCode  = [];   // sku_code → {id, sku_code}
$skusByAlias = [];   // alias → {id, sku_code}

$stmt = $pdo->query("SELECT id, sku_code FROM ref_skus");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $skusByCode[$s['sku_code']] = $s;
}
$stmt = $pdo->query("SELECT alias, canonical_sku_id FROM ref_sku_aliases");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    // Look up the canonical SKU
    foreach ($skusByCode as $skuCode => $skuRow) {
        if ((int)$skuRow['id'] === (int)$a['canonical_sku_id']) {
            $skusByAlias[$a['alias']] = $skuRow;
            break;
        }
    }
}

// Existing web orders (id 3-14, source='web') — for web-overlap-collision detection
// Key: "{customer_id_fk}:{requested_date}"
$existingWebOrders = [];
$stmt = $pdo->query(
    "SELECT id, customer_id_fk, requested_date FROM ord_orders WHERE source = 'web'"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $key = $o['customer_id_fk'] . ':' . $o['requested_date'];
    if (!isset($existingWebOrders[$key])) $existingWebOrders[$key] = [];
    $existingWebOrders[$key][] = (int)$o['id'];
}

// ── Status stage ordering ─────────────────────────────────────────────────────
const STATUS_STAGES = ['confirmed', 'picked', 'bl_printed', 'shipped'];
const STATUS_ENUM   = ['entered', 'confirmed', 'picked', 'bl_printed', 'shipped'];

// Rightmost reached stage wins for ord_orders.status
function resolve_status(array $marks): string {
    $stage = 'entered';
    if ($marks['confirmed'])  $stage = 'confirmed';
    if ($marks['picked'])     $stage = 'picked';
    if ($marks['bl_printed']) $stage = 'bl_printed';
    if ($marks['shipped'])    $stage = 'shipped';
    return $stage;
}

// All reached stages (for status events)
function reached_stages(array $marks): array {
    $stages = ['entered'];  // always
    if ($marks['confirmed'])  $stages[] = 'confirmed';
    if ($marks['picked'])     $stages[] = 'picked';
    if ($marks['bl_printed']) $stages[] = 'bl_printed';
    if ($marks['shipped'])    $stages[] = 'shipped';
    return $stages;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function normalize_str(string $s): string {
    $s = mb_strtolower(trim($s));
    // Strip leading/trailing non-alnum
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

/**
 * Follow merged_into chains to the canonical active record.
 *
 * If the matched row has is_active=0 and notes contains "merged_into: N",
 * fetch row N and repeat (cap at depth 5 to prevent loops).
 *
 * Returns:
 *   ['ok' => true,  'customer' => row]            — chain resolved to is_active=1
 *   ['ok' => false, 'reason'  => string]           — chain dead-ended on is_active=0
 *                                                     with no merged_into pointer
 */
function follow_merge_chain(array $row, array $allCustomers, int $depth = 0): array {
    if ((int)$row['is_active'] === 1) {
        return ['ok' => true, 'customer' => $row];
    }
    if ($depth >= 5) {
        return ['ok' => false, 'reason' => "merge chain depth exceeded for id={$row['id']}"];
    }
    $notes = $row['notes'] ?? '';
    if (preg_match('/merged_into:\s*(\d+)/', $notes, $m)) {
        $targetId = (int)$m[1];
        if (!isset($allCustomers[$targetId])) {
            return ['ok' => false, 'reason' => "merged_into target id=$targetId not found in ref_customers"];
        }
        return follow_merge_chain($allCustomers[$targetId], $allCustomers, $depth + 1);
    }
    // is_active=0 with no merged_into pointer — geographic/variant stub, never merged
    return ['ok' => false, 'reason' => "is_active=0 with no merged_into pointer (id={$row['id']}, name=\"{$row['name']}\")"];
}

/**
 * Resolve customer by (a) BC#, (b) exact name, (c) fuzzy.
 * After any initial match, follow_merge_chain() is called to arrive at the
 * canonical is_active=1 record.  If the chain dead-ends, the row is treated
 * as UNMATCHED (surfaces in unmatched-customer bucket — operator must clarify).
 *
 * Returns:
 *   ['resolved' => true,  'customer' => row, 'method' => string,
 *    'chain_note' => string|null]          — chain_note set when merge-followed
 *   ['resolved' => false, 'fuzzy' => row|null, 'fuzzy_score' => float|null,
 *    'dead_end_reason' => string|null]     — dead_end_reason set when chain failed
 */
function resolve_customer(
    string $clientRaw,
    ?string $bcInParens,
    array $customersByBc,
    array $customersByName,
    array $allCustomers
): array {
    // (a) BC number exact
    if ($bcInParens !== null && isset($customersByBc[$bcInParens])) {
        $hit = $customersByBc[$bcInParens];
        $chain = follow_merge_chain($hit, $allCustomers);
        if (!$chain['ok']) {
            return ['resolved' => false, 'fuzzy' => null, 'fuzzy_score' => null,
                    'dead_end_reason' => "bc:{$bcInParens} → " . $chain['reason']];
        }
        $chainNote = ((int)$chain['customer']['id'] !== (int)$hit['id'])
            ? "bc:{$bcInParens} stub#{$hit['id']} → canonical#{$chain['customer']['id']}"
            : null;
        return ['resolved' => true, 'customer' => $chain['customer'],
                'method' => "bc:{$bcInParens}", 'chain_note' => $chainNote];
    }

    // (b) Exact name match (normalized)
    // Strip parenthesised notes for matching: "De Sieb" → try as-is then stripped
    $stripped = preg_replace('/\s*\([^)]*\)\s*/', ' ', $clientRaw);
    $stripped = trim($stripped);

    $nk = normalize_str($clientRaw);
    if (isset($customersByName[$nk])) {
        $hit = $customersByName[$nk];
        $chain = follow_merge_chain($hit, $allCustomers);
        if (!$chain['ok']) {
            return ['resolved' => false, 'fuzzy' => null, 'fuzzy_score' => null,
                    'dead_end_reason' => "name-exact stub#{$hit['id']} → " . $chain['reason']];
        }
        $chainNote = ((int)$chain['customer']['id'] !== (int)$hit['id'])
            ? "name-exact stub#{$hit['id']} \"{$hit['name']}\" → canonical#{$chain['customer']['id']} \"{$chain['customer']['name']}\""
            : null;
        return ['resolved' => true, 'customer' => $chain['customer'],
                'method' => "name-exact", 'chain_note' => $chainNote];
    }
    $nkStripped = normalize_str($stripped);
    if ($nkStripped !== $nk && isset($customersByName[$nkStripped])) {
        $hit = $customersByName[$nkStripped];
        $chain = follow_merge_chain($hit, $allCustomers);
        if (!$chain['ok']) {
            return ['resolved' => false, 'fuzzy' => null, 'fuzzy_score' => null,
                    'dead_end_reason' => "name-exact-stripped stub#{$hit['id']} → " . $chain['reason']];
        }
        $chainNote = ((int)$chain['customer']['id'] !== (int)$hit['id'])
            ? "name-exact-stripped stub#{$hit['id']} \"{$hit['name']}\" → canonical#{$chain['customer']['id']} \"{$chain['customer']['name']}\""
            : null;
        return ['resolved' => true, 'customer' => $chain['customer'],
                'method' => "name-exact-stripped", 'chain_note' => $chainNote];
    }

    // (c) Fuzzy — token-sort similarity (simple word-overlap score)
    // Only suggest is_active=1 customers in fuzzy (avoid suggesting tombstones)
    $best = null;
    $bestScore = 0.0;
    foreach ($allCustomers as $c) {
        if ((int)$c['is_active'] !== 1) continue;
        $score = simple_similarity($nk, normalize_str($c['name']));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $c;
        }
    }
    if ($bestScore >= 0.85) {
        return [
            'resolved'       => false,
            'fuzzy'          => $best,
            'fuzzy_score'    => round($bestScore, 3),
            'dead_end_reason'=> null,
        ];
    }
    return ['resolved' => false, 'fuzzy' => null, 'fuzzy_score' => null, 'dead_end_reason' => null];
}

/**
 * Simple token-overlap similarity (both ways, Jaccard of token sets).
 * Not perfect but good enough for the suggestion column in the match report.
 */
function simple_similarity(string $a, string $b): float {
    // Remove parens + content
    $a = preg_replace('/\([^)]*\)/', '', $a);
    $b = preg_replace('/\([^)]*\)/', '', $b);
    $tokA = array_filter(preg_split('/\s+/', $a));
    $tokB = array_filter(preg_split('/\s+/', $b));
    if (empty($tokA) || empty($tokB)) return 0.0;
    $inter = count(array_intersect($tokA, $tokB));
    $union = count(array_unique(array_merge($tokA, $tokB)));
    return $union > 0 ? $inter / $union : 0.0;
}

/**
 * Resolve a SKU code to ref_skus.id.
 * Returns ['id' => int, 'sku_code' => string, 'via' => string] or null.
 */
function resolve_sku(string $code, array $skusByCode, array $skusByAlias): ?array {
    if (isset($skusByCode[$code])) {
        return array_merge($skusByCode[$code], ['via' => 'exact']);
    }
    if (isset($skusByAlias[$code])) {
        return array_merge($skusByAlias[$code], ['via' => 'alias']);
    }
    return null;
}

// ── Process rows ──────────────────────────────────────────────────────────────

$buckets = [
    'matched'              => [],
    'web-overlap-collision'=> [],
    'fuzzy-suggested'      => [],
    'unmatched-customer'   => [],
    'unmatched-sku'        => [],
    'internal-or-ambiguous'=> [],
    'empty-order'          => [],
    'trade-channel-mismatch'=> [],
];

foreach ($raw as $row) {
    $bucket  = $row['_bucket'] ?? 'pending-resolution';
    $sheetRow = (int)$row['sheet_row'];
    $clientRaw = $row['client_raw'] ?? '';
    $bcInParens = $row['bc_in_parens'] ?? null;
    $reqDate   = $row['requested_date'] ?? null;
    $offTrade  = (bool)($row['off_trade'] ?? false);
    $comment   = $row['comment'] ?? '';
    $statusMarks = $row['status_marks'] ?? ['confirmed'=>false,'picked'=>false,'bl_printed'=>false,'shipped'=>false];
    $lines     = $row['lines'] ?? [];
    $sourceRef = $row['source_ref'] ?? "weeklyorders:1955899648:r{$sheetRow}";

    // Pass through pre-classified buckets
    if ($bucket === 'internal-or-ambiguous') {
        $buckets['internal-or-ambiguous'][] = [
            'sheet_row'  => $sheetRow,
            'date'       => $reqDate,
            'client_raw' => $clientRaw,
            'lines'      => count($lines),
            'status'     => resolve_status($statusMarks),
            'comment'    => $comment,
            'source_ref' => $sourceRef,
        ];
        continue;
    }
    if ($bucket === 'empty-order') {
        $buckets['empty-order'][] = [
            'sheet_row'  => $sheetRow,
            'date'       => $reqDate,
            'client_raw' => $clientRaw,
            'comment'    => $comment,
            'source_ref' => $sourceRef,
        ];
        continue;
    }

    // Resolve customer
    $custRes = resolve_customer($clientRaw, $bcInParens, $customersByBc, $customersByName, $allCustomers);

    if (!$custRes['resolved']) {
        if ($custRes['fuzzy'] !== null) {
            $buckets['fuzzy-suggested'][] = [
                'sheet_row'     => $sheetRow,
                'date'          => $reqDate,
                'client_raw'    => $clientRaw,
                'suggested'     => $custRes['fuzzy']['name'],
                'suggested_id'  => (int)$custRes['fuzzy']['id'],
                'suggested_bc'  => $custRes['fuzzy']['bc_customer_no'],
                'score'         => $custRes['fuzzy_score'],
                'lines_count'   => count($lines),
                'status'        => resolve_status($statusMarks),
                'comment'       => $comment,
                'source_ref'    => $sourceRef,
            ];
        } else {
            $buckets['unmatched-customer'][] = [
                'sheet_row'      => $sheetRow,
                'date'           => $reqDate,
                'client_raw'     => $clientRaw,
                'lines_count'    => count($lines),
                'status'         => resolve_status($statusMarks),
                'comment'        => $comment,
                'source_ref'     => $sourceRef,
                'dead_end_reason'=> $custRes['dead_end_reason'] ?? null,
            ];
        }
        continue;
    }

    $customer = $custRes['customer'];
    $customerId = (int)$customer['id'];
    $resolveMethod = $custRes['method'];

    // Safety assertion: final resolved customer must be is_active=1
    if ((int)$customer['is_active'] !== 1) {
        // This should never happen if follow_merge_chain() is correct, but guard anyway
        $buckets['unmatched-customer'][] = [
            'sheet_row'      => $sheetRow,
            'date'           => $reqDate,
            'client_raw'     => $clientRaw,
            'lines_count'    => count($lines),
            'status'         => resolve_status($statusMarks),
            'comment'        => $comment,
            'source_ref'     => $sourceRef,
            'dead_end_reason'=> "BUG: resolved to is_active=0 customer id={$customerId} — should not happen",
        ];
        continue;
    }

    // Resolve all SKUs
    $resolvedLines = [];
    $unresolvedSkus = [];
    foreach ($lines as $line) {
        $skuCode = $line['sku_code'];
        $qty = (int)$line['qty'];
        $resolved = resolve_sku($skuCode, $skusByCode, $skusByAlias);
        if ($resolved === null) {
            $unresolvedSkus[] = $skuCode;
        } else {
            $resolvedLines[] = [
                'sku_code'   => $skuCode,
                'sku_id'     => (int)$resolved['id'],
                'qty'        => $qty,
                'via'        => $resolved['via'],
            ];
        }
    }

    if (!empty($unresolvedSkus)) {
        $buckets['unmatched-sku'][] = [
            'sheet_row'      => $sheetRow,
            'date'           => $reqDate,
            'client_raw'     => $clientRaw,
            'customer_id'    => $customerId,
            'customer_name'  => $customer['name'],
            'unresolved_skus'=> $unresolvedSkus,
            'resolved_lines' => count($resolvedLines),
            'status'         => resolve_status($statusMarks),
            'comment'        => $comment,
            'source_ref'     => $sourceRef,
        ];
        continue;
    }

    // Trade-channel mismatch check (note, not a blocker)
    $tcMismatch = null;
    if ($offTrade && ($customer['trade_channel'] ?? '') !== 'off_trade') {
        $tcMismatch = "sheet says off-trade; customer stored as " . ($customer['trade_channel'] ?: 'NULL');
    } elseif (!$offTrade && ($customer['trade_channel'] ?? '') === 'off_trade') {
        $tcMismatch = "sheet has no off-trade flag; customer stored as off_trade";
    }

    // Web-overlap-collision check
    $collisionKey = "{$customerId}:{$reqDate}";
    $collisionIds = $existingWebOrders[$collisionKey] ?? [];

    $entry = [
        'sheet_row'      => $sheetRow,
        'date'           => $reqDate,
        'client_raw'     => $clientRaw,
        'customer_id'    => $customerId,
        'customer_name'  => $customer['name'],
        'customer_bc'    => $customer['bc_customer_no'],
        'resolve_method' => $resolveMethod,
        'chain_note'     => $custRes['chain_note'] ?? null,
        'status_final'   => resolve_status($statusMarks),
        'stages_reached' => reached_stages($statusMarks),
        'lines'          => $resolvedLines,
        'comment'        => $comment,
        'source_ref'     => $sourceRef,
        'tc_mismatch'    => $tcMismatch,
    ];

    if (!empty($collisionIds)) {
        $entry['collision_order_ids'] = $collisionIds;
        $buckets['web-overlap-collision'][] = $entry;
    } else {
        $buckets['matched'][] = $entry;
        if ($tcMismatch !== null) {
            $buckets['trade-channel-mismatch'][] = [
                'sheet_row'     => $sheetRow,
                'date'          => $reqDate,
                'client_raw'    => $clientRaw,
                'customer_name' => $customer['name'],
                'mismatch'      => $tcMismatch,
            ];
        }
    }
}

// ── Build match report ────────────────────────────────────────────────────────

$report = [];
$report[] = "# WeeklyOrders → ord_orders Match Report";
$report[] = "";
$report[] = "Generated: " . date('Y-m-d H:i:s') . " (dry-run=" . ($applyMode ? "NO (APPLY MODE)" : "YES") . ")";
$report[] = "Input: $inputPath";
$report[] = "";

$report[] = "## Summary";
$report[] = "";
$total = array_sum(array_map('count', $buckets));
foreach ($buckets as $name => $rows) {
    $report[] = sprintf("- **%s**: %d", $name, count($rows));
}
$report[] = sprintf("- **TOTAL**: %d", $total);
$report[] = "";

// ── matched ──────────────────────────────────────────────────────────────────
$report[] = "## matched (" . count($buckets['matched']) . " — ready to import on --apply)";
$report[] = "";
if (empty($buckets['matched'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['matched'] as $e) {
        $linesSummary = implode(', ', array_map(fn($l)=>$l['sku_code'].'×'.$l['qty'], $e['lines']));
        $tc = $e['tc_mismatch'] ? " ⚠ tc-mismatch" : "";
        $report[] = sprintf(
            "- r%d | %s | → cust#%d %s (BC:%s) | %s | status=%s | lines=[%s]%s",
            $e['sheet_row'], $e['date'], $e['customer_id'], $e['customer_name'],
            $e['customer_bc'] ?? '—', $e['resolve_method'], $e['status_final'],
            $linesSummary, $tc
        );
        if ($e['chain_note']) $report[] = "  _chain: " . $e['chain_note'] . "_";
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── web-overlap-collision ─────────────────────────────────────────────────────
$report[] = "## web-overlap-collision (" . count($buckets['web-overlap-collision']) . " — operator must decide)";
$report[] = "";
if (empty($buckets['web-overlap-collision'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['web-overlap-collision'] as $e) {
        $linesSummary = implode(', ', array_map(fn($l)=>$l['sku_code'].'×'.$l['qty'], $e['lines']));
        $webIds = implode(', ', array_map(fn($id)=>"#$id", $e['collision_order_ids']));
        $report[] = sprintf(
            "- r%d | %s | cust#%d %s | status=%s | lines=[%s]",
            $e['sheet_row'], $e['date'], $e['customer_id'], $e['customer_name'],
            $e['status_final'], $linesSummary
        );
        $report[] = "  **⚠ possible dup of web order(s) $webIds — operator decides**";
        if ($e['chain_note']) $report[] = "  _chain: " . $e['chain_note'] . "_";
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── fuzzy-suggested ───────────────────────────────────────────────────────────
$report[] = "## fuzzy-suggested (" . count($buckets['fuzzy-suggested']) . " — NOT auto-linked, human must confirm)";
$report[] = "";
if (empty($buckets['fuzzy-suggested'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['fuzzy-suggested'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | **\"%s\"** → suggestion: cust#%d \"%s\" (BC:%s) score=%.3f | %d line(s) | status=%s",
            $e['sheet_row'], $e['date'], $e['client_raw'],
            $e['suggested_id'], $e['suggested'], $e['suggested_bc'] ?? '—',
            $e['score'], $e['lines_count'], $e['status']
        );
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── unmatched-customer ────────────────────────────────────────────────────────
$report[] = "## unmatched-customer (" . count($buckets['unmatched-customer']) . " — no exact or fuzzy match)";
$report[] = "";
if (empty($buckets['unmatched-customer'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['unmatched-customer'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | **\"%s\"** | %d line(s) | status=%s",
            $e['sheet_row'], $e['date'], $e['client_raw'], $e['lines_count'], $e['status']
        );
        if (!empty($e['dead_end_reason'])) $report[] = "  _reason: " . $e['dead_end_reason'] . "_";
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── unmatched-sku ─────────────────────────────────────────────────────────────
$report[] = "## unmatched-sku (" . count($buckets['unmatched-sku']) . " — customer resolved but ≥1 SKU unresolved)";
$report[] = "";
if (empty($buckets['unmatched-sku'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['unmatched-sku'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | %s → cust#%d %s | unresolved SKUs: [%s] | %d resolved line(s)",
            $e['sheet_row'], $e['date'], $e['client_raw'],
            $e['customer_id'], $e['customer_name'],
            implode(', ', $e['unresolved_skus']),
            $e['resolved_lines']
        );
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── internal-or-ambiguous ─────────────────────────────────────────────────────
$report[] = "## internal-or-ambiguous (" . count($buckets['internal-or-ambiguous']) . " — confirm skip)";
$report[] = "";
if (empty($buckets['internal-or-ambiguous'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['internal-or-ambiguous'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | **\"%s\"** | %d SKU line(s) | status=%s",
            $e['sheet_row'], $e['date'], $e['client_raw'], $e['lines'], $e['status']
        );
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── empty-order ───────────────────────────────────────────────────────────────
$report[] = "## empty-order (" . count($buckets['empty-order']) . " — client row with no SKU quantities)";
$report[] = "";
if (empty($buckets['empty-order'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['empty-order'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | **\"%s\"**",
            $e['sheet_row'], $e['date'], $e['client_raw']
        );
        if ($e['comment']) $report[] = "  _comment: " . $e['comment'] . "_";
    }
}
$report[] = "";

// ── trade-channel-mismatch ────────────────────────────────────────────────────
$report[] = "## trade-channel-mismatch (" . count($buckets['trade-channel-mismatch']) . " — note only, not a blocker)";
$report[] = "";
if (empty($buckets['trade-channel-mismatch'])) {
    $report[] = "_none_";
} else {
    foreach ($buckets['trade-channel-mismatch'] as $e) {
        $report[] = sprintf(
            "- r%d | %s | %s | %s",
            $e['sheet_row'], $e['date'], $e['customer_name'], $e['mismatch']
        );
    }
}
$report[] = "";

$reportText = implode("\n", $report) . "\n";
file_put_contents($reportPath, $reportText);
echo $reportText;
echo "\nMatch report written to: $reportPath\n";

// ── Apply mode ────────────────────────────────────────────────────────────────
if (!$applyMode) {
    echo "\n[DRY-RUN] No writes performed. Run with --apply to import matched rows.\n";
    echo "Command: sudo -u www-data php /var/www/maltytask/scripts/import_weeklyorders.php --apply\n";
    exit(0);
}

// APPLY: import matched rows only
echo "\n[APPLY] Importing " . count($buckets['matched']) . " matched orders…\n";

$stmtOrder = $pdo->prepare(
    "INSERT INTO ord_orders
        (order_type, customer_id_fk, requested_date, status, source, source_ref,
         comment, review_status, fulfilment_site_id_fk)
     VALUES ('customer', ?, ?, ?, 'import', ?, ?, 'none', ?)
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        comment = VALUES(comment),
        fulfilment_site_id_fk = VALUES(fulfilment_site_id_fk),
        updated_at = CURRENT_TIMESTAMP"
);

$stmtLine = $pdo->prepare(
    "INSERT INTO ord_order_lines (order_id_fk, sku_id_fk, qty)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE qty = VALUES(qty)"
);

$stmtEvent = $pdo->prepare(
    "INSERT INTO ord_order_status_events (order_id_fk, status, occurred_at, user_id_fk, comment)
     VALUES (?, ?, ?, NULL, 'import:weeklyorders')"
);

$stmtGetOrder = $pdo->prepare(
    "SELECT id FROM ord_orders WHERE source_ref = ?"
);

$imported = 0;
$skipped  = 0;

foreach ($buckets['matched'] as $e) {
    $pdo->beginTransaction();
    try {
        $finalStatus = $e['status_final'];
        $comment     = $e['comment'] ?: null;
        $sourceRef   = $e['source_ref'];
        $customerId  = $e['customer_id'];
        $reqDate     = $e['date'];

        // Resolve fulfilment site for this order
        $resolvedSiteId = null;
        try {
            $resolvedSiteId = resolve_fulfilment_site($pdo, [
                'customer_id_fk' => $customerId,
                'channel'        => $e['channel'] ?? null,
            ]);
        } catch (Throwable $siteEx) {
            error_log('[import-weeklyorders] resolve_fulfilment_site failed: ' . $siteEx->getMessage());
        }
        $fulfilSiteIdFk = ($resolvedSiteId > 0) ? $resolvedSiteId : null;

        // Upsert ord_orders (idempotent via source_ref UNIQUE)
        $stmtOrder->execute([
            $customerId,
            $reqDate,
            $finalStatus,
            $sourceRef,
            $comment,
            $fulfilSiteIdFk,
        ]);

        // Fetch the order id (existing or newly inserted)
        $stmtGetOrder->execute([$sourceRef]);
        $orderId = (int)$stmtGetOrder->fetchColumn();
        if (!$orderId) throw new RuntimeException("Could not fetch order id for source_ref=$sourceRef");

        // Before-state for log_revision (null = insert; fetch existing for update)
        $before = null;
        if ($pdo->lastInsertId() == 0) {
            // Row already existed — fetch current state
            $s2 = $pdo->prepare("SELECT * FROM ord_orders WHERE id = ?");
            $s2->execute([$orderId]);
            $before = $s2->fetch(PDO::FETCH_ASSOC);
        }
        $after = [
            'order_type'            => 'customer',
            'customer_id_fk'        => $customerId,
            'requested_date'        => $reqDate,
            'status'                => $finalStatus,
            'source'                => 'import',
            'source_ref'            => $sourceRef,
            'comment'               => $comment,
            'fulfilment_site_id_fk' => $fulfilSiteIdFk,
        ];
        log_revision($pdo, $me, 'ord_orders', $orderId, $before, $after, 'normal', 'import:weeklyorders');

        // Upsert ord_order_lines
        foreach ($e['lines'] as $line) {
            $stmtLine->execute([$orderId, $line['sku_id'], $line['qty']]);
        }

        // Insert status events (idempotent check by order+status)
        $stmtExistEvents = $pdo->prepare(
            "SELECT status FROM ord_order_status_events WHERE order_id_fk = ?"
        );
        $stmtExistEvents->execute([$orderId]);
        $existingEvtStatuses = $stmtExistEvents->fetchAll(PDO::FETCH_COLUMN);

        foreach ($e['stages_reached'] as $stage) {
            if (!in_array($stage, $existingEvtStatuses, true)) {
                $stmtEvent->execute([$orderId, $stage, $e['date'] . ' 00:00:00']);
            }
        }

        $pdo->commit();
        $imported++;
        echo "  ✓ r{$e['sheet_row']} → order#$orderId ({$e['customer_name']}, {$e['date']}, status={$finalStatus})\n";
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $skipped++;
        echo "  ✗ r{$e['sheet_row']} FAILED: " . $ex->getMessage() . "\n";
    }
}

echo "\n[APPLY] Done. imported=$imported, skipped=$skipped\n";
