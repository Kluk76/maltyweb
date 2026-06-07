<?php
/**
 * bootstrap_ref_customers.php
 *
 * One-shot bootstrap for ref_customers from:
 *  1. WeeklyOrders sheet JSON  (data/weeklyorders-clients-raw.json)
 *  2. inv_sales_bc fiscal source (270 distinct customer_no / customer_name)
 *
 * Usage (VPS):
 *   sudo php scripts/bootstrap_ref_customers.php            # dry-run (default)
 *   sudo php scripts/bootstrap_ref_customers.php --apply    # live — ONE-SHOT GUARD: refuses if table non-empty
 *
 * Tier logic (strict):
 *   T0  Exclusions — internal channels, artifacts, day-names, numeric-only, x-OFF-TRADE headers
 *   T1  BC ID in parens  — "Célébration Food Service (3662)" → extract 3662 → match inv_sales_bc.customer_no
 *                          BC name becomes ref_customers.name; sheet name stored in notes as sheet_name:
 *   T2  Exact normalized match — normalize(sheet_name) == normalize(bc_name) → link; NO fuzzy
 *   T3  Sheet-only (2026 tab ONLY) — insert with bc_customer_no=NULL, needs_review=1
 *       2025WO names: used ONLY to enrich off_trade flags of T1/T2/T3 inserts — NOT inserted themselves
 *   BC-only — every inv_sales_bc distinct customer not hit by T1/T2 → INSERT needs_review=0
 *
 * trade_channel:
 *   off_trade_marks >= 1 in either tab → 'off_trade'
 *   else seen with has_qty_rows >= 1   → 'on_trade'
 *   BC-only                            → NULL
 *
 * Idempotency guard: refuses --apply if ref_customers already has rows.
 */

define('SCRIPT_VERSION', '1.0.0');
define('UPDATED_BY', 'bootstrap');

$apply = in_array('--apply', $argv ?? []);
$dry   = !$apply;

$vpsDataDir   = '/var/www/maltytask/data';
$localDataDir = dirname(__DIR__) . '/data';
$dataDir      = is_dir($vpsDataDir) ? $vpsDataDir : $localDataDir;

$jsonPath   = $dataDir . '/weeklyorders-clients-raw.json';
$reportPath = is_dir($vpsDataDir) ? '/tmp/bootstrap-ref-customers-report.md' : ($localDataDir . '/bootstrap-ref-customers-report.md');

require_once dirname(__DIR__) . '/app/db.php';
$pdo = maltytask_pdo();

// ── ONE-SHOT GUARD ────────────────────────────────────────────────────────────
if ($apply) {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ref_customers')->fetchColumn();
    if ($count > 0) {
        echo "ABORT: ref_customers already has {$count} rows. This is a one-shot bootstrap.\n";
        echo "To re-run, manually TRUNCATE ref_customers first.\n";
        exit(1);
    }
}

// ── LOAD JSON ─────────────────────────────────────────────────────────────────
if (!file_exists($jsonPath)) {
    echo "ERROR: JSON not found at {$jsonPath}\n";
    exit(1);
}
$raw  = json_decode(file_get_contents($jsonPath), true);
$tabs = $raw['tabs'] ?? [];

$wo2026clients  = $tabs['WeeklyOrders']['clients']  ?? [];
$wo2026comments = $tabs['WeeklyOrders']['comments'] ?? [];
$wo2025clients  = $tabs['2025WO']['clients']         ?? [];
$wo2025comments = $tabs['2025WO']['comments']        ?? [];
$allComments    = array_merge($wo2026comments, $wo2025comments);

// ── LOAD BC CUSTOMERS ─────────────────────────────────────────────────────────
$bcRows = $pdo->query(
    'SELECT DISTINCT customer_no, customer_name FROM inv_sales_bc ORDER BY customer_no'
)->fetchAll(PDO::FETCH_ASSOC);

$bcByNo   = [];  // customer_no (string) → customer_name
$bcByNorm = [];  // normalize(customer_name) → customer_no
foreach ($bcRows as $r) {
    $no   = trim($r['customer_no']);
    $name = trim($r['customer_name'] ?? '');
    $bcByNo[$no]               = $name;
    $norm                      = normalizeStr($name);
    $bcByNorm[$norm]           = $no;
}

// ── HELPERS ───────────────────────────────────────────────────────────────────

function normalizeStr(string $s): string
{
    // lowercase, trim, collapse whitespace, strip accents (transliterate)
    $s = mb_strtolower(trim($s), 'UTF-8');
    // Decompose + strip combining marks (accent removal)
    $s = normalizer_normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}/u', '', $s);
    // collapse internal whitespace
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function isExcluded(string $name): string|false
{
    $t = trim($name);
    $l = mb_strtolower($t, 'UTF-8');

    // Numeric-only  (bare BC numbers used as shortcuts in the sheet)
    if (preg_match('/^\d+\s*$/', $t)) {
        return 'numeric-only';
    }
    // Starts with # (header artifacts like "# Semaines de Stock")
    if (preg_match('/^#/', $t)) {
        return 'header-artifact';
    }
    // Very short (≤ 2 chars, e.g. "KP")
    if (mb_strlen($t, 'UTF-8') <= 2) {
        return 'too-short';
    }
    // Internal channels
    $normalized = normalizeStr($t);
    $internals = ['taproom','taprroom','eshop','e-shop','shop','cage','shop neb','shop mon repos'];
    if (in_array($normalized, $internals, true)) {
        return 'internal-channel';
    }
    // Broader: starts with taproom/eshop/shop/cage (e.g. "eshop privé")
    foreach (['taproom','taprroom','eshop','e-shop'] as $prefix) {
        if (str_starts_with($normalized, $prefix)) {
            return 'internal-channel';
        }
    }
    if (in_array($normalized, ['cage'], true)) {
        return 'internal-channel';
    }
    // Day names (fr/de/en) — solo
    $days = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche',
             'monday','tuesday','wednesday','thursday','friday','saturday','sunday',
             'montag','dienstag','mittwoch','donnerstag','freitag','samstag','sonntag'];
    if (in_array($normalized, $days, true)) {
        return 'day-name';
    }
    // "x OFF TRADE" / "X OFF TRADE" headers
    if (preg_match('/^x\s+off\s*trade/i', $t)) {
        return 'off-trade-header';
    }

    return false;
}

function extractBcId(string $name): ?string
{
    // Match trailing "(NNNN)" or "(NNNNN)" — 3-5 digit BC customer number
    if (preg_match('/\((\d{3,5})\)\s*$/', $name, $m)) {
        return $m[1];
    }
    return null;
}

function buildOffTradeMap(array $clients2026, array $clients2025): array
{
    // Returns [normalizedName → off_trade_marks, ...]
    $map = [];
    foreach (array_merge($clients2026, $clients2025) as $c) {
        $n = normalizeStr($c['name']);
        if (!isset($map[$n])) {
            $map[$n] = 0;
        }
        $map[$n] += (int) ($c['off_trade_marks'] ?? 0);
    }
    return $map;
}

function buildQtyMap(array $clients2026, array $clients2025): array
{
    $map = [];
    foreach (array_merge($clients2026, $clients2025) as $c) {
        $n = normalizeStr($c['name']);
        if (!isset($map[$n])) {
            $map[$n] = 0;
        }
        $map[$n] += (int) ($c['has_qty_rows'] ?? 0);
    }
    return $map;
}

// ── CLASSIFY ALL 2026 CLIENTS ─────────────────────────────────────────────────

$offTradeMap = buildOffTradeMap($wo2026clients, $wo2025clients);
$qtyMap      = buildQtyMap($wo2026clients, $wo2025clients);

$exclusions        = [];  // [name → reason]
$tier1             = [];  // [bc_no → [bc_no, bc_name, sheet_display_name, first_sheet_name, …]] — keyed by bc_no (deduplicated)
$tier1AllSheets    = [];  // [bc_no → [sheet_names…]] — for reporting all aliases
$tier1Unmatched    = [];  // sheet_name → extracted BC id not found in inv_sales_bc
$tier2             = [];  // [bc_no → [bc_no, bc_name, first_sheet_name, …]] — keyed by bc_no (deduplicated)
$tier2AllSheets    = [];  // [bc_no → [sheet_names…]]
$tier3             = [];  // [name → [off_trade_marks, has_qty_rows, is_private]]

$usedBcNos = []; // bc_no → which tier claimed it

foreach ($wo2026clients as $c) {
    $name = trim($c['name']);

    // T0: exclusions
    $excReason = isExcluded($name);
    if ($excReason !== false) {
        $exclusions[$name] = $excReason;
        continue;
    }

    $normName = normalizeStr($name);
    $offTrade = ($offTradeMap[$normName] ?? 0) >= 1;
    $hasQty   = ($qtyMap[$normName] ?? 0) >= 1;
    $isPriv   = (bool) preg_match('/priv[ée]e?/i', $name);

    // T1: BC ID in parens
    $bcId = extractBcId($name);
    if ($bcId !== null) {
        if (isset($bcByNo[$bcId])) {
            $bcName = $bcByNo[$bcId];
            $sheetDisplayName = trim(preg_replace('/\s*\(\d{3,5}\)\s*$/', '', $name));
            // Track all sheet aliases for this bc_no
            $tier1AllSheets[$bcId][] = $name;
            if (!isset($tier1[$bcId])) {
                // First encounter: claim this bc_no
                $tier1[$bcId] = [
                    'bc_no'              => $bcId,
                    'bc_name'            => $bcName,
                    'sheet_display_name' => $sheetDisplayName,
                    'first_sheet_name'   => $name,
                    'off_trade'          => $offTrade,
                    'has_qty'            => $hasQty,
                    'is_private'         => $isPriv,
                ];
                $usedBcNos[$bcId] = 'tier1';
            } else {
                // Subsequent encounter: accumulate off_trade/has_qty signal, don't overwrite
                if ($offTrade) {
                    $tier1[$bcId]['off_trade'] = true;
                }
                if ($hasQty) {
                    $tier1[$bcId]['has_qty'] = true;
                }
            }
        } else {
            // Parens ID not in BC — don't guess, flag for review
            $tier1Unmatched[$name] = $bcId;
        }
        continue;
    }

    // T2: exact normalized match
    if (isset($bcByNorm[$normName])) {
        $bcNo   = $bcByNorm[$normName];
        $bcName = $bcByNo[$bcNo];
        $tier2AllSheets[$bcNo][] = $name;
        if (!isset($tier2[$bcNo])) {
            $tier2[$bcNo] = [
                'bc_no'           => $bcNo,
                'bc_name'         => $bcName,
                'first_sheet_name'=> $name,
                'off_trade'       => $offTrade,
                'has_qty'         => $hasQty,
                'is_private'      => $isPriv,
            ];
            $usedBcNos[$bcNo] = 'tier2';
        } else {
            if ($offTrade) {
                $tier2[$bcNo]['off_trade'] = true;
            }
            if ($hasQty) {
                $tier2[$bcNo]['has_qty'] = true;
            }
        }
        continue;
    }

    // T3: sheet-only (keyed by name — each distinct sheet name is unique here)
    // T3 dedup: if this normalized name already in tier3, just accumulate signals
    if (isset($tier3[$name])) {
        if ($offTrade) {
            $tier3[$name]['off_trade'] = true;
        }
        if ($hasQty) {
            $tier3[$name]['has_qty'] = true;
        }
    } else {
        $tier3[$name] = [
            'off_trade'  => $offTrade,
            'has_qty'    => $hasQty,
            'is_private' => $isPriv,
        ];
    }
}

// ── 2025WO ENRICHMENT — enrich off_trade flags for names already in T1/T2/T3 ─
// (2025-only names are NOT inserted — they only add off_trade signal above via buildOffTradeMap)
$only2025Names = [];
$wo2026normSet = [];
foreach ($wo2026clients as $c) {
    if (isExcluded(trim($c['name'])) === false) {
        $wo2026normSet[normalizeStr(trim($c['name']))] = true;
    }
}
foreach ($wo2025clients as $c) {
    $name = trim($c['name']);
    if (isExcluded($name) !== false) {
        continue;
    }
    $norm = normalizeStr($name);
    if (!isset($wo2026normSet[$norm]) && extractBcId($name) === null) {
        $only2025Names[] = $name;
    }
}
$only2025Count = count(array_unique($only2025Names));

// ── BC-ONLY CUSTOMERS ─────────────────────────────────────────────────────────
$bcOnly = [];
foreach ($bcByNo as $no => $name) {
    if (!isset($usedBcNos[$no])) {
        $bcOnly[$no] = $name;
    }
}

// ── COLLISION DETECTION (T3 name conflicts with a BC name already inserted) ───
// T1/T2 are now keyed by bc_no; BC-only adds remaining
$reservedBcNames = []; // norm → bc_no
foreach ($tier1 as $bcNo => $info) {
    $reservedBcNames[normalizeStr($info['bc_name'])] = $info['bc_no'];
}
foreach ($tier2 as $bcNo => $info) {
    $reservedBcNames[normalizeStr($info['bc_name'])] = $info['bc_no'];
}
foreach ($bcOnly as $no => $name) {
    $reservedBcNames[normalizeStr($name)] = $no;
}

$tier3Collisions = [];
foreach ($tier3 as $name => $info) {
    $norm = normalizeStr($name);
    if (isset($reservedBcNames[$norm])) {
        $tier3Collisions[$name] = $reservedBcNames[$norm];
        unset($tier3[$name]);
    }
}

// ── TRANSPORTER SWEEP ─────────────────────────────────────────────────────────
$transporterCandidates = [];
$transporterSamples    = [];
$transporterPattern    = '/\b(Galliker|Loxya|Planzer|Transport\s+Express|Camion|livraison par|Dachser|DHL|FedEx|Post|TNT|DPD|UPS|SBB|CFF|Camionnette|Colis\s+Priv[ée]s?|La\s+Poste)\b/iu';
foreach ($allComments as $comment) {
    $text = $comment['comment'] ?? '';
    if (preg_match_all($transporterPattern, $text, $matches)) {
        foreach ($matches[1] as $m) {
            $key = ucwords(mb_strtolower(trim($m), 'UTF-8'));
            $transporterCandidates[$key] = ($transporterCandidates[$key] ?? 0) + 1;
            if (!isset($transporterSamples[$key])) {
                $transporterSamples[$key] = [];
            }
            if (count($transporterSamples[$key]) < 3) {
                $transporterSamples[$key][] = trim(($comment['client'] ?? '') . ': ' . $text);
            }
        }
    }
}
// Broader: any capitalized word ≥ 4 chars not already captured — frequency top tokens
$broadTokens = [];
foreach ($allComments as $comment) {
    $text = $comment['comment'] ?? '';
    // Capitalized tokens only (heuristic for proper nouns / company names)
    preg_match_all('/\b([A-ZÜÄÖÉÈÀÂÊ][a-züäöéèàâê]{3,}(?:\s+[A-ZÜÄÖÉÈÀÂÊ][a-züäöéèàâê]{2,})?)\b/', $text, $mm);
    foreach ($mm[1] as $tok) {
        if (!preg_match('/^(Voir|Aligal|Pack|Gratuit|Zepc?|Canette|Fûts?|Mail|Cde|Alig|Caisse|Verres|Becs?)$/i', $tok)) {
            $broadTokens[$tok] = ($broadTokens[$tok] ?? 0) + 1;
        }
    }
}
arsort($broadTokens);
arsort($transporterCandidates);

// ── BUILD REPORT ──────────────────────────────────────────────────────────────
ob_start();

echo "# bootstrap_ref_customers — Dry-Run Report\n";
echo "Generated: " . date('Y-m-d H:i:s') . "  |  Mode: " . ($apply ? '**--apply**' : 'dry-run') . "\n\n";

echo "## Summary\n\n";
echo "| Tier | Count |\n";
echo "|------|-------|\n";
echo "| T0 Exclusions | " . count($exclusions) . " |\n";
echo "| T1 BC ID in parens (matched) | " . count($tier1) . " |\n";
echo "| T1 BC ID in parens (unmatched → review) | " . count($tier1Unmatched) . " |\n";
echo "| T2 Exact normalized match | " . count($tier2) . " |\n";
echo "| T3 Sheet-only 2026 (needs_review=1) | " . count($tier3) . " |\n";
echo "| T3 Collisions skipped | " . count($tier3Collisions) . " |\n";
echo "| BC-only (needs_review=0) | " . count($bcOnly) . " |\n";
echo "| 2025WO-only names (NOT inserted) | {$only2025Count} |\n";
echo "| **Total rows to INSERT** | " . (count($tier1) + count($tier2) + count($tier3) + count($bcOnly)) . " |\n";
echo "\n";

echo "## T0 — Exclusions\n\n";
echo "| Sheet Name | Reason |\n";
echo "|------------|--------|\n";
foreach ($exclusions as $name => $reason) {
    echo "| " . escMd($name) . " | {$reason} |\n";
}
echo "\n";

echo "## T1 — BC ID in Parens (matched, deduplicated by BC no)\n\n";
echo "| All Sheet Names | BC No | BC Name (inserted) | Sheet Display Name | trade_channel |\n";
echo "|-----------------|-------|-------------------|-------------------|---------------|\n";
foreach ($tier1 as $bcNo => $info) {
    $ch       = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : 'NULL');
    $allNames = implode(' / ', array_map('escMd', $tier1AllSheets[$bcNo] ?? [$info['first_sheet_name']]));
    echo "| {$allNames} | {$info['bc_no']} | " . escMd($info['bc_name']) . " | " . escMd($info['sheet_display_name']) . " | {$ch} |\n";
}
echo "\n";

if ($tier1Unmatched) {
    echo "## T1 — BC ID in Parens (UNMATCHED — needs review)\n\n";
    echo "These sheet names had a numeric BC ID in parens that does NOT exist in inv_sales_bc:\n\n";
    echo "| Sheet Name | Extracted BC ID |\n";
    echo "|------------|----------------|\n";
    foreach ($tier1Unmatched as $sName => $bcId) {
        echo "| " . escMd($sName) . " | {$bcId} |\n";
    }
    echo "\n";
}

echo "## T2 — Exact Normalized Match (deduplicated by BC no)\n\n";
echo "| All Sheet Names | BC No | BC Name (inserted) | trade_channel |\n";
echo "|-----------------|-------|-------------------|---------------|\n";
foreach ($tier2 as $bcNo => $info) {
    $ch       = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : 'NULL');
    $allNames = implode(' / ', array_map('escMd', $tier2AllSheets[$bcNo] ?? [$info['first_sheet_name']]));
    echo "| {$allNames} | {$info['bc_no']} | " . escMd($info['bc_name']) . " | {$ch} |\n";
}
echo "\n";

echo "## T3 — Sheet-Only 2026 (top 50 by 2026 count, total=" . count($tier3) . ")\n\n";
// Sort by count descending (use original data)
$tier3WithCount = [];
foreach ($wo2026clients as $c) {
    $name = trim($c['name']);
    if (isset($tier3[$name])) {
        $tier3WithCount[$name] = (int) $c['count'];
    }
}
arsort($tier3WithCount);
echo "| Sheet Name | is_private | trade_channel |\n";
echo "|------------|------------|---------------|\n";
$shown = 0;
foreach ($tier3WithCount as $name => $cnt) {
    if ($shown >= 50) {
        break;
    }
    $info = $tier3[$name];
    $ch   = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : 'NULL');
    $priv = $info['is_private'] ? '1' : '0';
    echo "| " . escMd($name) . " | {$priv} | {$ch} |\n";
    $shown++;
}
echo "\n";

if ($tier3Collisions) {
    echo "## T3 — Collisions (skipped — name conflicts with BC or T1/T2 name)\n\n";
    echo "| Sheet Name | Conflicting BC No |\n";
    echo "|------------|------------------|\n";
    foreach ($tier3Collisions as $name => $bcNo) {
        echo "| " . escMd($name) . " | {$bcNo} |\n";
    }
    echo "\n";
}

echo "## BC-Only Customers (not matched in any tier)\n\n";
echo "Count: " . count($bcOnly) . "\n\n";
echo "| BC No | BC Name |\n";
echo "|-------|--------|\n";
foreach ($bcOnly as $no => $name) {
    echo "| {$no} | " . escMd($name) . " |\n";
}
echo "\n";

echo "## Transporter Candidates (report-only, no ref_transporters writes)\n\n";
echo "### Explicit carrier mentions (top 15)\n\n";
echo "| Carrier | Count | Sample Comments |\n";
echo "|---------|-------|-----------------|\n";
$tTop = array_slice($transporterCandidates, 0, 15, true);
foreach ($tTop as $carrier => $cnt) {
    $samples = implode('; ', array_map(fn($s) => escMd(mb_substr($s, 0, 80, 'UTF-8')), $transporterSamples[$carrier] ?? []));
    echo "| " . escMd($carrier) . " | {$cnt} | {$samples} |\n";
}
echo "\n";

echo "### Top broad capitalized tokens in comments (top 20)\n\n";
$broadTop = array_slice($broadTokens, 0, 20, true);
echo "| Token | Count |\n|-------|-------|\n";
foreach ($broadTop as $tok => $cnt) {
    echo "| " . escMd($tok) . " | {$cnt} |\n";
}
echo "\n";

$reportBody = ob_get_clean();

// ── WRITE REPORT ──────────────────────────────────────────────────────────────
file_put_contents($reportPath, $reportBody);
echo $reportBody;
echo "\n---\nReport written to: {$reportPath}\n";

// If dry-run, stop here
if ($dry) {
    echo "Mode: DRY-RUN — no DB writes. Pass --apply to insert.\n";
    exit(0);
}

// ── --apply: TRANSACTIONAL INSERT ─────────────────────────────────────────────
echo "\n=== APPLYING — inserting rows into ref_customers ===\n";

$insertSql = <<<SQL
INSERT INTO ref_customers
    (name, bc_customer_no, trade_channel, is_private, needs_review, is_active, notes, updated_by)
VALUES
    (:name, :bc_no, :trade_channel, :is_private, :needs_review, 1, :notes, 'bootstrap')
SQL;

$stmt = $pdo->prepare($insertSql);

$counts = ['tier1' => 0, 'tier2' => 0, 'tier3' => 0, 'bc_only' => 0, 'skipped' => 0];

$pdo->beginTransaction();
try {
    // T1 — keyed by bc_no, one INSERT per unique BC customer
    foreach ($tier1 as $bcNo => $info) {
        $ch    = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : null);
        $notes = null;
        // Store sheet_name aliases in notes when display name meaningfully differs from BC name
        if (normalizeStr($info['sheet_display_name']) !== normalizeStr($info['bc_name'])) {
            $allAliases = $tier1AllSheets[$bcNo] ?? [];
            $notes = 'sheet_name: ' . $info['sheet_display_name'];
            if (count($allAliases) > 1) {
                $notes .= ' (aliases: ' . implode(', ', $allAliases) . ')';
            }
        }
        $ok = $stmt->execute([
            ':name'         => $info['bc_name'],
            ':bc_no'        => $info['bc_no'],
            ':trade_channel'=> $ch,
            ':is_private'   => (int) $info['is_private'],
            ':needs_review' => 0,
            ':notes'        => $notes,
        ]);
        $counts['tier1'] += $ok ? 1 : 0;
    }

    // T2 — keyed by bc_no, one INSERT per unique BC customer
    foreach ($tier2 as $bcNo => $info) {
        $ch = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : null);
        $ok = $stmt->execute([
            ':name'         => $info['bc_name'],
            ':bc_no'        => $info['bc_no'],
            ':trade_channel'=> $ch,
            ':is_private'   => (int) $info['is_private'],
            ':needs_review' => 0,
            ':notes'        => null,
        ]);
        $counts['tier2'] += $ok ? 1 : 0;
    }

    // T3
    foreach ($tier3 as $name => $info) {
        $ch = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : null);
        $ok = $stmt->execute([
            ':name'         => $name,
            ':bc_no'        => null,
            ':trade_channel'=> $ch,
            ':is_private'   => (int) $info['is_private'],
            ':needs_review' => 1,
            ':notes'        => null,
        ]);
        $counts['tier3'] += $ok ? 1 : 0;
    }

    // BC-only
    foreach ($bcOnly as $no => $name) {
        $ok = $stmt->execute([
            ':name'         => $name,
            ':bc_no'        => $no,
            ':trade_channel'=> null,
            ':is_private'   => 0,
            ':needs_review' => 0,
            ':notes'        => null,
        ]);
        $counts['bc_only'] += $ok ? 1 : 0;
    }

    $pdo->commit();
    echo "COMMITTED.\n";
    echo "  T1 inserted:       {$counts['tier1']}\n";
    echo "  T2 inserted:       {$counts['tier2']}\n";
    echo "  T3 inserted:       {$counts['tier3']}\n";
    echo "  BC-only inserted:  {$counts['bc_only']}\n";
    $total = array_sum($counts);
    echo "  TOTAL:             {$total}\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ROLLBACK — error: " . $e->getMessage() . "\n";
    exit(1);
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function escMd(string $s): string
{
    return str_replace(['|', "\n", "\r"], ['\|', ' ', ''], $s);
}
