<?php
/**
 * scripts/bootstrap_ref_customers.php  — v2 (CRM-primary)
 *
 * ONE-SHOT bootstrap for ref_customers.
 *
 * Precedence: CRM = roster truth.  BC invoices = validation.  Sheet = enrichment.
 *
 * Algorithm:
 *  1. Parse data/crm-clients-2026-06-07.json (converted from xlsx locally).
 *     INSERT all 2,678 CRM rows:
 *       • name            = Nom stripped of "NE PLUS UTILISER" prefix  (is_active=0 flagged)
 *       • bc_customer_no  = N°
 *       • email / address_line1 / address_line2 / postal_code / city / canton / country_code
 *       • needs_review=0, updated_by='bootstrap-crm'
 *
 *  2. Sheet enrichment (WeeklyOrders 2026 tab):
 *       Match CRM rows by:
 *         (a) parens-ID → bc_customer_no  (exact)
 *         (b) exact normalised name        (no fuzzy — wrong bc link is revenue-bearing)
 *       On match: set trade_channel; append 'sheet_name: X' to notes when
 *       sheet name meaningfully differs from CRM name.
 *
 *  3. Sheet-only leftovers (WeeklyOrders 2026 ONLY, no CRM match):
 *       INSERT bc_customer_no=NULL, needs_review=1.
 *       Internal channels, artifacts, day-names excluded.
 *       Name collision with an already-inserted CRM row → SKIP + report.
 *
 *  4. Validation pass (report only):
 *       Every DISTINCT inv_sales_bc.customer_no must exist among CRM bc_customer_nos.
 *       List any missing. Also report count of parens-IDs now resolved by CRM.
 *
 * Usage (VPS):
 *   sudo php scripts/bootstrap_ref_customers.php             # dry-run (default)
 *   sudo php scripts/bootstrap_ref_customers.php --apply     # live — ONE-SHOT GUARD
 */

define('SCRIPT_VERSION', '2.0.0');
define('UPDATED_BY', 'bootstrap-crm');

$apply = in_array('--apply', $argv ?? []);
$dry   = !$apply;

$vpsDataDir   = '/var/www/maltytask/data';
$localDataDir = dirname(__DIR__) . '/data';
$dataDir      = is_dir($vpsDataDir) ? $vpsDataDir : $localDataDir;

$crmJsonPath   = $dataDir . '/crm-clients-2026-06-07.json';
$woJsonPath    = $dataDir . '/weeklyorders-clients-raw.json';
$reportPath    = is_dir($vpsDataDir)
    ? '/tmp/bootstrap-ref-customers-report-v2.md'
    : ($localDataDir . '/bootstrap-ref-customers-report-v2.md');

require_once dirname(__DIR__) . '/app/db.php';
$pdo = maltytask_pdo();

// ── ONE-SHOT GUARD ────────────────────────────────────────────────────────────
if ($apply) {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ref_customers')->fetchColumn();
    if ($count > 0) {
        echo "ABORT: ref_customers already has {$count} rows. One-shot bootstrap.\n";
        echo "To re-run, manually TRUNCATE ref_customers first.\n";
        exit(1);
    }
}

// ── LOAD CRM JSON ─────────────────────────────────────────────────────────────
if (!file_exists($crmJsonPath)) {
    echo "ERROR: CRM JSON not found at {$crmJsonPath}\n";
    echo "Run: python3 scripts/convert_crm_xlsx.py\n";
    exit(1);
}
$crmData    = json_decode(file_get_contents($crmJsonPath), true);
$crmClients = $crmData['clients'] ?? [];

// ── LOAD WEEKLYORDERS JSON ────────────────────────────────────────────────────
if (!file_exists($woJsonPath)) {
    echo "ERROR: WeeklyOrders JSON not found at {$woJsonPath}\n";
    exit(1);
}
$woRaw      = json_decode(file_get_contents($woJsonPath), true);
$woTabs     = $woRaw['tabs'] ?? [];
$wo2026     = $woTabs['WeeklyOrders']['clients']  ?? [];
$wo2025     = $woTabs['2025WO']['clients']         ?? [];

// ── HELPERS ───────────────────────────────────────────────────────────────────

function normalizeStr(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = normalizer_normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}/u', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function escMd(string $s): string
{
    return str_replace(['|', "\n", "\r"], ['\|', ' ', ''], $s);
}

function extractBcId(string $name): ?string
{
    if (preg_match('/\((\d{3,5})\)\s*$/', $name, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Detect and strip "NE PLUS UTILISER" prefix variants.
 * Returns [cleaned_name, original_name] or null if not flagged.
 */
function detectNpu(string $name): ?array
{
    // Prefix forms: "NE PLUS UTILISER - X", "!! Ne plus utiliser !! X", "Ne plus utiliser X"
    if (preg_match('/^[!*\s]*ne\s+plus\s+utiliser[!*\s\-:]+(.+)/iu', $name, $m)) {
        return [trim($m[1]), $name];
    }
    // Suffix form: "X - NE PLUS UTILISER"
    if (preg_match('/^(.+?)\s*[\-–]\s*ne\s+plus\s+utiliser\s*$/iu', $name, $m)) {
        return [trim($m[1]), $name];
    }
    // Bare "NE PLUS UTILISER" with no other content
    if (preg_match('/^[!*\s]*ne\s+plus\s+utiliser\s*$/iu', $name)) {
        return ['(décommissionné)', $name];
    }
    return null;
}

function isExcluded(string $name): string|false
{
    $t = trim($name);
    $normalized = normalizeStr($t);

    if (preg_match('/^\d+\s*$/', $t))             return 'numeric-only';
    if (preg_match('/^#/', $t))                   return 'header-artifact';
    if (mb_strlen($t, 'UTF-8') <= 2)              return 'too-short';

    $internals = ['taproom','taprroom','eshop','e-shop','shop','cage',
                  'shop neb','shop mon repos'];
    if (in_array($normalized, $internals, true))  return 'internal-channel';
    foreach (['taproom','taprroom','eshop','e-shop'] as $pfx) {
        if (str_starts_with($normalized, $pfx))   return 'internal-channel';
    }
    if ($normalized === 'cage')                   return 'internal-channel';

    $days = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche',
             'monday','tuesday','wednesday','thursday','friday','saturday','sunday',
             'montag','dienstag','mittwoch','donnerstag','freitag','samstag','sonntag'];
    if (in_array($normalized, $days, true))       return 'day-name';

    if (preg_match('/^x\s+off\s*trade/i', $t))   return 'off-trade-header';

    return false;
}

// ── BUILD CRM ROWS ────────────────────────────────────────────────────────────
// Index by bc_customer_no for fast sheet-enrichment lookup.
// Also keep a norm-name → bc_no index for exact-name match.

$crmByBcNo   = [];  // bc_no(string) → prepared row array
$crmByNorm   = [];  // normalizeStr(clean_name) → bc_no  (for sheet T2 match)
$npuCount    = 0;

foreach ($crmClients as $c) {
    $bcNo    = trim($c['bc_customer_no'] ?? '');
    $rawName = trim($c['name'] ?? '');
    if ($bcNo === '' || $rawName === '') {
        continue;
    }

    $isActive = 1;
    $notes    = null;

    $npuResult = detectNpu($rawName);
    if ($npuResult !== null) {
        [$cleanedName, $origName] = $npuResult;
        $isActive = 0;
        $notes    = 'original_name: ' . $origName;
        $npuCount++;
        $insertName = $cleanedName;
    } else {
        $insertName = $rawName;
    }

    $row = [
        'bc_customer_no' => $bcNo,
        'insert_name'    => $insertName,
        'email'          => $c['email']         ?? null,
        'address_line1'  => $c['address_line1'] ?? null,
        'address_line2'  => $c['address_line2'] ?? null,
        'postal_code'    => $c['postal_code']   ?? null,
        'city'           => $c['city']          ?? null,
        'canton'         => $c['canton']        ?? null,
        'country_code'   => $c['country_code']  ?? null,
        'is_active'      => $isActive,
        'notes'          => $notes,
        'trade_channel'  => null,
        'is_private'     => 0,
        'needs_review'   => 0,
    ];

    $crmByBcNo[$bcNo] = $row;
    $norm = normalizeStr($insertName);
    // Store first encountered (bc_no is unique in CRM)
    if (!isset($crmByNorm[$norm])) {
        $crmByNorm[$norm] = $bcNo;
    }
}

// ── SHEET ENRICHMENT (WeeklyOrders 2026) ─────────────────────────────────────
// off_trade/has_qty signals from both 2026 and 2025 tabs merged:
$offTradeMap = [];
$hasQtyMap   = [];
foreach (array_merge($wo2026, $wo2025) as $c) {
    $n = normalizeStr(trim($c['name'] ?? ''));
    if (!isset($offTradeMap[$n])) {
        $offTradeMap[$n] = 0;
        $hasQtyMap[$n]   = 0;
    }
    $offTradeMap[$n] += (int) ($c['off_trade_marks'] ?? 0);
    $hasQtyMap[$n]   += (int) ($c['has_qty_rows']    ?? 0);
}

$sheetEnrichCount    = 0;
$sheetEnrichMatched  = [];  // bc_no → match_type for reporting
$sheetUnmatched      = [];  // name → [off_trade, has_qty, is_private] — sheet-only 2026 leftovers
$sheetExclusions     = [];  // name → reason
$tier1UnmatchedSheet = [];  // name → extracted bc_id (parens-ID not in CRM)

// Track bc_nos already enriched (avoid double-enrichment from multiple sheet aliases)
$enrichedBcNos = [];

foreach ($wo2026 as $c) {
    $name = trim($c['name'] ?? '');
    if ($name === '') {
        continue;
    }

    $excReason = isExcluded($name);
    if ($excReason !== false) {
        $sheetExclusions[$name] = $excReason;
        continue;
    }

    $normName = normalizeStr($name);
    $offTrade = ($offTradeMap[$normName] ?? 0) >= 1;
    $hasQty   = ($hasQtyMap[$normName]   ?? 0) >= 1;
    $isPriv   = (bool) preg_match('/priv[ée]e?/i', $name);
    $tradeChannel = $offTrade ? 'off_trade' : ($hasQty ? 'on_trade' : null);

    // (a) Parens-ID match → bc_customer_no
    $bcId = extractBcId($name);
    if ($bcId !== null) {
        if (isset($crmByBcNo[$bcId])) {
            if (!isset($enrichedBcNos[$bcId])) {
                $sheetDisplayName = trim(preg_replace('/\s*\(\d{3,5}\)\s*$/', '', $name));
                if ($tradeChannel !== null) {
                    $crmByBcNo[$bcId]['trade_channel'] = $tradeChannel;
                }
                $crmName = $crmByBcNo[$bcId]['insert_name'];
                if (normalizeStr($sheetDisplayName) !== normalizeStr($crmName)) {
                    $existingNotes = $crmByBcNo[$bcId]['notes'] ?? null;
                    $sheetNote = 'sheet_name: ' . $sheetDisplayName;
                    $crmByBcNo[$bcId]['notes'] = $existingNotes
                        ? $existingNotes . '; ' . $sheetNote
                        : $sheetNote;
                }
                $enrichedBcNos[$bcId] = 'parens';
                $sheetEnrichMatched[$bcId] = 'parens';
                $sheetEnrichCount++;
            } else {
                // Accumulate trade_channel from additional aliases
                if ($tradeChannel !== null && $crmByBcNo[$bcId]['trade_channel'] === null) {
                    $crmByBcNo[$bcId]['trade_channel'] = $tradeChannel;
                }
            }
        } else {
            $tier1UnmatchedSheet[$name] = $bcId;
        }
        continue;
    }

    // (b) Exact normalised name match
    if (isset($crmByNorm[$normName])) {
        $bcNo = $crmByNorm[$normName];
        if (!isset($enrichedBcNos[$bcNo])) {
            if ($tradeChannel !== null) {
                $crmByBcNo[$bcNo]['trade_channel'] = $tradeChannel;
            }
            $crmName = $crmByBcNo[$bcNo]['insert_name'];
            if (normalizeStr($name) !== normalizeStr($crmName)) {
                $existingNotes = $crmByBcNo[$bcNo]['notes'] ?? null;
                $sheetNote = 'sheet_name: ' . $name;
                $crmByBcNo[$bcNo]['notes'] = $existingNotes
                    ? $existingNotes . '; ' . $sheetNote
                    : $sheetNote;
            }
            $enrichedBcNos[$bcNo] = 'exact_norm';
            $sheetEnrichMatched[$bcNo] = 'exact_norm';
            $sheetEnrichCount++;
        } else {
            if ($tradeChannel !== null && $crmByBcNo[$bcNo]['trade_channel'] === null) {
                $crmByBcNo[$bcNo]['trade_channel'] = $tradeChannel;
            }
        }
        continue;
    }

    // Sheet-only leftover
    $sheetUnmatched[$name] = [
        'off_trade'  => $offTrade,
        'has_qty'    => $hasQty,
        'is_private' => $isPriv,
    ];
}

// ── COLLISION CHECK: sheet-only name vs CRM names already being inserted ─────
// Since name is no longer UNIQUE, we don't hard-block, but we report and skip
// to avoid confusing duplicate identities. A collision means the normalization
// missed a match — list it for review.
$nameSetNorm = []; // normalizeStr(insert_name) → bc_no
foreach ($crmByBcNo as $bcNo => $row) {
    $nameSetNorm[normalizeStr($row['insert_name'])] = $bcNo;
}

$sheetCollisions    = [];  // name → conflicting_bc_no
$sheetOnlyFinal     = [];  // name → row (deduplicated, collisions removed)
foreach ($sheetUnmatched as $name => $info) {
    $norm = normalizeStr($name);
    if (isset($nameSetNorm[$norm])) {
        $sheetCollisions[$name] = $nameSetNorm[$norm];
    } else {
        $sheetOnlyFinal[$name] = $info;
        // Register to prevent duplicate sheet-only inserts
        $nameSetNorm[$norm] = 'sheet-only:' . $name;
    }
}

// ── VALIDATION: inv_sales_bc vs CRM bc_customer_nos ──────────────────────────
$bcRows = $pdo->query(
    'SELECT DISTINCT customer_no FROM inv_sales_bc
     WHERE customer_no IS NOT NULL AND customer_no != \'\'
     ORDER BY customer_no'
)->fetchAll(PDO::FETCH_COLUMN);

$crmBcNosSet = array_flip(array_keys($crmByBcNo));
$missingFromCrm = [];
foreach ($bcRows as $bcNo) {
    if (!isset($crmBcNosSet[$bcNo])) {
        $missingFromCrm[] = $bcNo;
    }
}

// Count parens-IDs in sheet that now resolved via CRM (any T1 parens match)
$parensResolvedCount = count(array_filter(
    $sheetEnrichMatched,
    fn($t) => $t === 'parens'
));

// ── REPORT ────────────────────────────────────────────────────────────────────
ob_start();

$totalCrm      = count($crmByBcNo);
$totalSheetOnly = count($sheetOnlyFinal);
$totalInserts  = $totalCrm + $totalSheetOnly;
$withEmail     = count(array_filter($crmByBcNo, fn($r) => $r['email'] !== null));
$withChannel   = count(array_filter($crmByBcNo, fn($r) => $r['trade_channel'] !== null));
$offTradeCount = count(array_filter($crmByBcNo, fn($r) => $r['trade_channel'] === 'off_trade'));
$onTradeCount  = count(array_filter($crmByBcNo, fn($r) => $r['trade_channel'] === 'on_trade'));

echo "# bootstrap_ref_customers v2 — Report\n";
echo "Generated: " . date('Y-m-d H:i:s') . "  |  Mode: " . ($apply ? '**--apply**' : 'dry-run') . "\n\n";

echo "## Summary\n\n";
echo "| Category | Count |\n";
echo "|----------|-------|\n";
echo "| CRM rows (ref = bc_customer_no) | {$totalCrm} |\n";
echo "| — of which NE PLUS UTILISER (is_active=0) | {$npuCount} |\n";
echo "| — with email | {$withEmail} |\n";
echo "| — with trade_channel (from sheet enrichment) | {$withChannel} |\n";
echo "| — off_trade | {$offTradeCount} |\n";
echo "| — on_trade | {$onTradeCount} |\n";
echo "| Sheet enrichment matches (trade_channel set) | {$sheetEnrichCount} |\n";
echo "| — parens-ID resolved by CRM | {$parensResolvedCount} |\n";
echo "| Sheet-only inserts (needs_review=1) | {$totalSheetOnly} |\n";
echo "| Sheet-only collisions SKIPPED | " . count($sheetCollisions) . " |\n";
echo "| Sheet exclusions | " . count($sheetExclusions) . " |\n";
echo "| Sheet parens-ID unmatched (not in CRM) | " . count($tier1UnmatchedSheet) . " |\n";
echo "| **Total rows to INSERT** | **{$totalInserts}** |\n";
echo "\n";

echo "## Validation — inv_sales_bc vs CRM\n\n";
echo "inv_sales_bc distinct customer_no: " . count($bcRows) . "\n";
echo "Missing from CRM export: " . count($missingFromCrm) . "\n\n";
if ($missingFromCrm) {
    echo "| BC No (in inv_sales_bc, absent from CRM) |\n";
    echo "|------------------------------------------|\n";
    foreach ($missingFromCrm as $no) {
        echo "| {$no} |\n";
    }
} else {
    echo "_All inv_sales_bc customer_nos are present in CRM export._\n";
}
echo "\n";

echo "## Sheet Enrichment — Matched by Parens-ID\n\n";
echo "| Sheet Name → BC No | BC/CRM Name | trade_channel |\n";
echo "|--------------------|-------------|---------------|\n";
foreach ($sheetEnrichMatched as $bcNo => $matchType) {
    if ($matchType !== 'parens') {
        continue;
    }
    $row = $crmByBcNo[$bcNo];
    $ch  = $row['trade_channel'] ?? 'NULL';
    echo "| {$bcNo} | " . escMd($row['insert_name']) . " | {$ch} |\n";
}
echo "\n";

echo "## Sheet Enrichment — Matched by Exact Normalised Name\n\n";
echo "Count: " . count(array_filter($sheetEnrichMatched, fn($t) => $t === 'exact_norm')) . "\n\n";

echo "## Sheet-Only Inserts (top 50 / total={$totalSheetOnly})\n\n";
echo "| Sheet Name | is_private | trade_channel |\n";
echo "|------------|------------|---------------|\n";
$shown = 0;
foreach ($sheetOnlyFinal as $name => $info) {
    if ($shown >= 50) {
        break;
    }
    $ch   = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : 'NULL');
    $priv = $info['is_private'] ? '1' : '0';
    echo "| " . escMd($name) . " | {$priv} | {$ch} |\n";
    $shown++;
}
echo "\n";

if ($sheetCollisions) {
    echo "## Sheet-Only Collisions (SKIPPED — likely missed normalisation)\n\n";
    echo "| Sheet Name | Conflicting CRM BC No |\n";
    echo "|------------|----------------------|\n";
    foreach ($sheetCollisions as $name => $bcNo) {
        echo "| " . escMd($name) . " | {$bcNo} |\n";
    }
    echo "\n";
}

if ($tier1UnmatchedSheet) {
    echo "## Sheet Parens-IDs NOT in CRM (needs manual review)\n\n";
    echo "| Sheet Name | Extracted ID |\n";
    echo "|------------|-------------|\n";
    foreach ($tier1UnmatchedSheet as $name => $id) {
        echo "| " . escMd($name) . " | {$id} |\n";
    }
    echo "\n";
}

echo "## Sheet Exclusions\n\n";
echo "| Sheet Name | Reason |\n";
echo "|------------|--------|\n";
foreach ($sheetExclusions as $name => $reason) {
    echo "| " . escMd($name) . " | {$reason} |\n";
}
echo "\n";

$reportBody = ob_get_clean();

file_put_contents($reportPath, $reportBody);
echo $reportBody;
echo "\n---\nReport written to: {$reportPath}\n";

if ($dry) {
    echo "Mode: DRY-RUN — no DB writes. Pass --apply to insert.\n";
    exit(0);
}

// ── --apply: TRANSACTIONAL INSERT ─────────────────────────────────────────────
echo "\n=== APPLYING — inserting rows into ref_customers ===\n";

$insertSql = <<<SQL
INSERT INTO ref_customers
    (name, bc_customer_no, trade_channel, is_private, is_active,
     needs_review, email, address_line1, address_line2,
     postal_code, city, canton, country_code, notes, updated_by)
VALUES
    (:name, :bc_no, :trade_channel, :is_private, :is_active,
     :needs_review, :email, :address_line1, :address_line2,
     :postal_code, :city, :canton, :country_code, :notes, :updated_by)
SQL;

$stmt = $pdo->prepare($insertSql);
$counts = ['crm' => 0, 'sheet_only' => 0];

$pdo->beginTransaction();
try {
    // CRM rows (2,678)
    foreach ($crmByBcNo as $bcNo => $row) {
        $ok = $stmt->execute([
            ':name'          => $row['insert_name'],
            ':bc_no'         => $row['bc_customer_no'],
            ':trade_channel' => $row['trade_channel'],
            ':is_private'    => (int) $row['is_private'],
            ':is_active'     => (int) $row['is_active'],
            ':needs_review'  => (int) $row['needs_review'],
            ':email'         => $row['email'],
            ':address_line1' => $row['address_line1'],
            ':address_line2' => $row['address_line2'],
            ':postal_code'   => $row['postal_code'],
            ':city'          => $row['city'],
            ':canton'        => $row['canton'],
            ':country_code'  => $row['country_code'],
            ':notes'         => $row['notes'],
            ':updated_by'    => UPDATED_BY,
        ]);
        $counts['crm'] += $ok ? 1 : 0;
    }

    // Sheet-only rows
    foreach ($sheetOnlyFinal as $name => $info) {
        $ch = $info['off_trade'] ? 'off_trade' : ($info['has_qty'] ? 'on_trade' : null);
        $ok = $stmt->execute([
            ':name'          => $name,
            ':bc_no'         => null,
            ':trade_channel' => $ch,
            ':is_private'    => (int) $info['is_private'],
            ':is_active'     => 1,
            ':needs_review'  => 1,
            ':email'         => null,
            ':address_line1' => null,
            ':address_line2' => null,
            ':postal_code'   => null,
            ':city'          => null,
            ':canton'        => null,
            ':country_code'  => null,
            ':notes'         => null,
            ':updated_by'    => UPDATED_BY,
        ]);
        $counts['sheet_only'] += $ok ? 1 : 0;
    }

    $pdo->commit();
    $total = array_sum($counts);
    echo "COMMITTED.\n";
    echo "  CRM rows inserted:        {$counts['crm']}\n";
    echo "  Sheet-only inserted:      {$counts['sheet_only']}\n";
    echo "  TOTAL:                    {$total}\n";

    // Final counts summary
    $sql = 'SELECT
        COUNT(*)                                              AS total,
        SUM(needs_review = 1)                                AS needs_review,
        SUM(is_active = 0)                                   AS inactive,
        SUM(email IS NOT NULL)                               AS with_email,
        SUM(trade_channel IS NOT NULL)                       AS with_channel
      FROM ref_customers';
    $stats = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    echo "\nFinal table counts:\n";
    echo "  total:         {$stats['total']}\n";
    echo "  needs_review:  {$stats['needs_review']}\n";
    echo "  inactive:      {$stats['inactive']}\n";
    echo "  with_email:    {$stats['with_email']}\n";
    echo "  with_channel:  {$stats['with_channel']}\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ROLLBACK — error: " . $e->getMessage() . "\n";
    exit(1);
}
