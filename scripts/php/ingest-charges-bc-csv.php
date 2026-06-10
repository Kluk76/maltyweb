<?php
declare(strict_types=1);

/**
 * ingest-charges-bc-csv.php — CLI mirror of the web ChargesBC uploader.
 *
 * Mirrors public/api/charges-bc-upload.php EXACTLY:
 *   - Same helper functions (_cbc_str, _cbc_amount, _cbc_date, _cbc_row_hash, _cbc_parse_row)
 *   - Same COL_* constants
 *   - Same semicolon CSV parsing + header-skip logic
 *   - Same INSERT IGNORE with the identical 16 columns
 *   - Same row_hash (SHA-256 of "periodText|glAcctNo|entryNo|description|debitRaw|creditRaw")
 *   - Same per-row audit_row_revisions insert (action='insert') + summary upload-event row
 *
 * Idempotent: a second --apply of the same file produces 0 inserts.
 *
 * Usage:
 *   php scripts/php/ingest-charges-bc-csv.php --file <path.csv>            # DRY-RUN
 *   php scripts/php/ingest-charges-bc-csv.php --file <path.csv> --apply    # WRITE TO DB
 *   php scripts/php/ingest-charges-bc-csv.php --file <path.csv> --period-label "April 2026"
 *
 * Run on VPS (www-data reads config/db.env):
 *   sudo -u www-data php /var/www/maltytask/scripts/php/ingest-charges-bc-csv.php \
 *       --file /tmp/ChargesBC_april_2026.csv
 *   sudo -u www-data php /var/www/maltytask/scripts/php/ingest-charges-bc-csv.php \
 *       --file /tmp/ChargesBC_april_2026.csv --apply
 *
 * Audit rows use user_id=0, username='cli-charges-bc' (matching the uploader's
 * upload-event sentinel pattern for non-session CLI writes).
 * last_modified_by='ingest' (the ENUM value for server-side ingest paths).
 *
 * Highlighted GL accounts in dry-run summary: 4302, 4500, 4510, 4600, 6100.
 */

require __DIR__ . '/../../app/db.php';

// ── Parse CLI flags ────────────────────────────────────────────────────────────
$apply       = false;
$filePath    = null;
$periodLabel = null;

$args = $argv ?? [];
for ($i = 1; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--apply':
            $apply = true;
            break;
        case '--file':
            $filePath = $args[++$i] ?? null;
            break;
        case '--period-label':
            $periodLabel = $args[++$i] ?? null;
            break;
        case '--help':
        case '-h':
            fwrite(STDOUT,
                "Usage: php ingest-charges-bc-csv.php --file <path> [--apply] [--period-label <txt>]\n" .
                "  --file <path>          Path to the semicolon-delimited ChargesBC CSV (required)\n" .
                "  --apply                Write to DB (default: dry-run, no writes)\n" .
                "  --period-label <txt>   Optional label for logging (e.g. 'April 2026')\n"
            );
            exit(0);
    }
}

if ($filePath === null) {
    fwrite(STDERR, "ERROR: --file <path> is required.\n");
    fwrite(STDERR, "Usage: php ingest-charges-bc-csv.php --file <path> [--apply]\n");
    exit(1);
}

if (!file_exists($filePath)) {
    fwrite(STDERR, "ERROR: File not found: {$filePath}\n");
    exit(1);
}

if (!is_readable($filePath)) {
    fwrite(STDERR, "ERROR: File not readable: {$filePath}\n");
    exit(1);
}

$origName = basename($filePath);

// ── Helpers (copied verbatim from public/api/charges-bc-upload.php) ───────────

function _cbc_str(?string $v): ?string
{
    if ($v === null || $v === '' || trim($v) === 'NULL') {
        return null;
    }
    return trim($v);
}

function _cbc_amount(?string $v): ?float
{
    $v = _cbc_str($v);
    if ($v === null) {
        return null;
    }
    // BC amounts may use comma thousands separator: "5,613.30"
    $cleaned = preg_replace('/,(?=\d{3})/', '', $v);
    $cleaned = str_replace(',', '.', $cleaned ?? '');
    $n = (float)$cleaned;
    return is_finite($n) ? $n : null;
}

function _cbc_date(?string $v): ?string
{
    $v = _cbc_str($v);
    if ($v === null) {
        return null;
    }
    // BC PostingDate_GLEntry: MM/DD/YYYY or M/D/YYYY
    $parts = explode('/', $v);
    if (count($parts) === 3 && strlen($parts[2]) === 4) {
        return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[0], (int)$parts[1]);
    }
    return null;
}

/**
 * Compute row_hash matching the TypeScript seed script exactly:
 *   SHA-256 of "period_text|gl_account_no|entry_no|description|debit_raw|credit_raw"
 */
function _cbc_row_hash(
    string $periodText,
    string $glAcctNo,
    string $entryNo,
    string $description,
    string $debitRaw,
    string $creditRaw
): string {
    $input = "{$periodText}|{$glAcctNo}|{$entryNo}|{$description}|{$debitRaw}|{$creditRaw}";
    return hash('sha256', $input);
}

/** Column indices (0-based) matching the seed script */
const COL_PERIOD_TEXT      = 1;   // B
const COL_GL_ACCT_NAME     = 5;   // F
const COL_GL_ACCT_NO       = 7;   // H
const COL_DEBIT            = 25;  // Z
const COL_CREDIT           = 26;  // AA
const COL_DESCRIPTION      = 33;  // AH
const COL_DOC_NO           = 34;  // AI
const COL_POSTING_DATE_TXT = 35;  // AJ
const COL_POSTING_DATE     = 40;  // AO
const COL_ENTRY_NO         = 37;  // AL
const COL_BAL_ACCT_NO      = 31;  // AF
const COL_EXRATE           = 45;  // AT

const MIN_COLS = 38;  // minimum columns required (AL=37 + 1)

/**
 * Parse a single CSV row into a structured array.
 * Returns null if the row is blank (no period_text and no gl_account_no).
 * Returns ['error' => string] if validation fails.
 */
function _cbc_parse_row(array $cols, int $rowNum): ?array
{
    // Pad short rows
    while (count($cols) < 50) {
        $cols[] = '';
    }

    $periodText = _cbc_str($cols[COL_PERIOD_TEXT]);
    $glAcctNo   = _cbc_str($cols[COL_GL_ACCT_NO]);

    // Skip entirely blank rows
    if ($periodText === null && $glAcctNo === null) {
        return null;
    }

    $entryNo     = _cbc_str($cols[COL_ENTRY_NO]);
    $description = _cbc_str($cols[COL_DESCRIPTION]);
    $debitRaw    = _cbc_str($cols[COL_DEBIT])  ?? '0';
    $creditRaw   = _cbc_str($cols[COL_CREDIT]) ?? '0';
    $isSummary   = ($entryNo === null) ? 1 : 0;

    $hash = _cbc_row_hash(
        $periodText  ?? '',
        $glAcctNo    ?? '',
        $entryNo     ?? '',
        $description ?? '',
        $debitRaw,
        $creditRaw,
    );

    return [
        'gl_account_no'    => $glAcctNo,
        'gl_account_name'  => _cbc_str($cols[COL_GL_ACCT_NAME]),
        'period_text'      => $periodText,
        'debit_amount'     => _cbc_amount($debitRaw),
        'credit_amount'    => _cbc_amount($creditRaw),
        'description'      => $description,
        'document_no'      => _cbc_str($cols[COL_DOC_NO]),
        'posting_date_txt' => _cbc_str($cols[COL_POSTING_DATE_TXT]),
        'posting_date'     => _cbc_date(_cbc_str($cols[COL_POSTING_DATE])),
        'entry_no'         => $entryNo,
        'bal_account_no'   => _cbc_str($cols[COL_BAL_ACCT_NO]),
        'exrate'           => _cbc_amount(_cbc_str($cols[COL_EXRATE])),
        'is_summary'       => $isSummary,
        'raw_json'         => json_encode($cols, JSON_UNESCAPED_UNICODE),
        'row_hash'         => $hash,
        'last_modified_by' => 'ingest',   // CLI uses 'ingest' (ENUM: 'ingest'|'web')
        '_debit_raw'       => $debitRaw,  // internal for hash — not inserted
        '_credit_raw'      => $creditRaw, // internal for hash — not inserted
        '_row_num'         => $rowNum,
    ];
}

// ── Parse CSV ──────────────────────────────────────────────────────────────────
$fh = @fopen($filePath, 'r');
if ($fh === false) {
    fwrite(STDERR, "ERROR: Cannot open file: {$filePath}\n");
    exit(1);
}

$parsedRows    = [];
$errors        = [];
$blankRows     = 0;
$rowNum        = 0;
$headerSkipped = false;
$totalLines    = 0;

while (($cols = fgetcsv($fh, 0, ';')) !== false) {
    $rowNum++;
    $totalLines++;

    // Skip the header row (first non-empty row with text in col B or H)
    if (!$headerSkipped) {
        $possibleHeader = _cbc_str($cols[COL_PERIOD_TEXT] ?? null) ?? '';
        // Header row will have "PeriodGlJourDateFilter" or similar text in B
        if (!is_numeric($possibleHeader) && str_contains(strtolower($possibleHeader), 'period')) {
            $headerSkipped = true;
            continue;
        }
        // Also skip if col H looks like a header label
        $colH = _cbc_str($cols[COL_GL_ACCT_NO] ?? null) ?? '';
        if (!is_numeric($colH) && strlen($colH) > 4 && !preg_match('/^\d/', $colH)) {
            // Likely header — skip
            $headerSkipped = true;
            continue;
        }
        $headerSkipped = true; // Always skip the first row
        if (!is_array($cols) || count($cols) < 2) {
            $blankRows++;
            continue;
        }
    }

    if (!is_array($cols)) {
        continue;
    }

    $parsed = _cbc_parse_row($cols, $rowNum);
    if ($parsed === null) {
        $blankRows++;
        continue; // blank row
    }
    if (isset($parsed['error'])) {
        $errors[] = "Ligne {$rowNum}: " . $parsed['error'];
        continue;
    }
    $parsedRows[] = $parsed;
}
fclose($fh);

// ── Build per-GL summary ───────────────────────────────────────────────────────
$glSummary    = [];
$postingDates = [];

foreach ($parsedRows as $r) {
    $gl = $r['gl_account_no'] ?? '(null)';
    if (!isset($glSummary[$gl])) {
        $glSummary[$gl] = ['count' => 0, 'debit' => 0.0, 'credit' => 0.0, 'name' => $r['gl_account_name'] ?? ''];
    }
    $glSummary[$gl]['count']++;
    $glSummary[$gl]['debit']  += $r['debit_amount']  ?? 0.0;
    $glSummary[$gl]['credit'] += $r['credit_amount'] ?? 0.0;
    if ($r['posting_date'] !== null) {
        $postingDates[] = $r['posting_date'];
    }
}
ksort($glSummary);

$minDate = $postingDates ? min($postingDates) : null;
$maxDate = $postingDates ? max($postingDates) : null;

// ── Print report header ────────────────────────────────────────────────────────
$mode = $apply ? 'APPLY MODE' : 'DRY-RUN MODE';
fwrite(STDOUT, "\n");
fwrite(STDOUT, "=============================================================\n");
fwrite(STDOUT, "  ChargesBC CSV Ingest — {$mode}\n");
fwrite(STDOUT, "=============================================================\n");
fwrite(STDOUT, "  File         : {$origName}\n");
if ($periodLabel !== null) {
    fwrite(STDOUT, "  Period label : {$periodLabel}\n");
}
fwrite(STDOUT, "  Total lines  : {$totalLines} (incl. header)\n");
fwrite(STDOUT, "  Parsed rows  : " . count($parsedRows) . "\n");
fwrite(STDOUT, "  Blank/skipped: {$blankRows}\n");
fwrite(STDOUT, "  Error rows   : " . count($errors) . "\n");
fwrite(STDOUT, "  Date range   : " . ($minDate ?? 'n/a') . " → " . ($maxDate ?? 'n/a') . "\n");
fwrite(STDOUT, "\n");

// ── Error details ─────────────────────────────────────────────────────────────
if (!empty($errors)) {
    fwrite(STDOUT, "  PARSE ERRORS:\n");
    foreach ($errors as $e) {
        fwrite(STDOUT, "    [ERR] {$e}\n");
    }
    fwrite(STDOUT, "\n");
}

// ── Per-GL summary table ───────────────────────────────────────────────────────
$highlightGls = ['4302', '4500', '4510', '4600', '6100'];

fwrite(STDOUT, "  GL ACCOUNT SUMMARY\n");
fwrite(STDOUT, "  " . str_repeat('-', 77) . "\n");
fwrite(STDOUT, sprintf("  %-12s %-35s %6s %14s %14s\n",
    'GL No', 'Name', 'Rows', 'Debit CHF', 'Credit CHF'));
fwrite(STDOUT, "  " . str_repeat('-', 77) . "\n");

foreach ($glSummary as $gl => $s) {
    $highlight = in_array($gl, $highlightGls, true) ? '*** ' : '    ';
    fwrite(STDOUT, sprintf("  %s%-12s %-35s %6d %14.2f %14.2f\n",
        $highlight,
        $gl,
        mb_strimwidth((string)$s['name'], 0, 34, '…'),
        $s['count'],
        $s['debit'],
        $s['credit']
    ));
}
fwrite(STDOUT, "  " . str_repeat('-', 77) . "\n");

// Totals
$totalCount  = array_sum(array_column($glSummary, 'count'));
$totalDebit  = array_sum(array_column($glSummary, 'debit'));
$totalCredit = array_sum(array_column($glSummary, 'credit'));
fwrite(STDOUT, sprintf("  %-48s %6d %14.2f %14.2f\n",
    '  TOTAL', $totalCount, $totalDebit, $totalCredit));
fwrite(STDOUT, "\n");

// ── Highlighted GL detail ─────────────────────────────────────────────────────
fwrite(STDOUT, "  HIGHLIGHTED ACCOUNTS (4302 / 4500 / 4510 / 4600 / 6100):\n");
foreach ($highlightGls as $hgl) {
    if (isset($glSummary[$hgl])) {
        $s = $glSummary[$hgl];
        fwrite(STDOUT, sprintf("    *** %-6s %-35s rows=%-5d debit=%10.2f  credit=%10.2f\n",
            $hgl, mb_strimwidth((string)$s['name'], 0, 34, '…'),
            $s['count'], $s['debit'], $s['credit']));
    } else {
        fwrite(STDOUT, "        {$hgl}  (no rows in this file)\n");
    }
}
fwrite(STDOUT, "\n");

// ── Sample rows ───────────────────────────────────────────────────────────────
fwrite(STDOUT, "  SAMPLE ROWS (first 5):\n");
$sample = array_slice($parsedRows, 0, 5);
foreach ($sample as $idx => $r) {
    fwrite(STDOUT, sprintf(
        "  [%d] gl=%-6s period=%-12s entry=%-8s date=%-12s debit=%10.2f credit=%10.2f summary=%d\n" .
        "       desc: %s\n",
        $idx + 1,
        (string)($r['gl_account_no'] ?? 'null'),
        (string)($r['period_text']   ?? 'null'),
        (string)($r['entry_no']      ?? 'null'),
        (string)($r['posting_date']  ?? 'null'),
        $r['debit_amount']  ?? 0.0,
        $r['credit_amount'] ?? 0.0,
        $r['is_summary'],
        mb_strimwidth((string)($r['description'] ?? ''), 0, 80, '…')
    ));
}
fwrite(STDOUT, "\n");

// ── Abort early if nothing to do ──────────────────────────────────────────────
if (empty($parsedRows)) {
    fwrite(STDOUT, "  WARNING: No parseable data rows found. Check delimiter (must be semicolon) and format.\n\n");
    if (!$apply) {
        fwrite(STDOUT, "  DRY RUN — rerun with --apply to write.\n\n");
    }
    exit(0);
}

// ── DRY-RUN: exit without writing ─────────────────────────────────────────────
if (!$apply) {
    fwrite(STDOUT, "  DRY RUN — rerun with --apply to write.\n\n");
    exit(0);
}

// ── APPLY: INSERT IGNORE with transaction + audit ─────────────────────────────
fwrite(STDOUT, "  Connecting to DB...\n");
$pdo = maltytask_pdo();

// CLI audit actor
$cliMe = ['id' => 0, 'username' => 'cli-charges-bc'];

$inserted     = 0;
$skippedDupes = 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO inv_charges_bc
           (gl_account_no, gl_account_name, period_text, debit_amount, credit_amount,
            description, document_no, posting_date_txt, posting_date, entry_no,
            bal_account_no, exrate, is_summary, raw_json, row_hash, last_modified_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $auditStmt = $pdo->prepare(
        "INSERT INTO audit_row_revisions
           (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
         VALUES (?, ?, NULL, 'inv_charges_bc', ?, 'insert', NULL, ?, ?, 'normal')"
    );

    foreach ($parsedRows as $r) {
        $stmt->execute([
            $r['gl_account_no'],
            $r['gl_account_name'],
            $r['period_text'],
            $r['debit_amount'],
            $r['credit_amount'],
            $r['description'],
            $r['document_no'],
            $r['posting_date_txt'],
            $r['posting_date'],
            $r['entry_no'],
            $r['bal_account_no'],
            $r['exrate'],
            $r['is_summary'],
            $r['raw_json'],
            $r['row_hash'],
            'ingest',
        ]);

        $affected = $stmt->rowCount();
        if ($affected > 0) {
            $newId = (int)$pdo->lastInsertId();
            $inserted++;
            // Per-row audit (mirrors the uploader's log_revision call)
            $auditStmt->execute([
                0,                        // user_id=0 (CLI sentinel, no session)
                'cli-charges-bc',         // username
                $newId,                   // target_pk
                json_encode([             // after_json
                    'period_text'   => $r['period_text'],
                    'gl_account_no' => $r['gl_account_no'],
                    'entry_no'      => $r['entry_no'],
                    'is_summary'    => $r['is_summary'],
                ], JSON_UNESCAPED_UNICODE),
                "CLI import CSV : {$origName}",
            ]);
        } else {
            $skippedDupes++;
        }
    }

    // Upload-event summary audit row (target_pk=0 = upload-event sentinel, same as web uploader)
    $summaryStmt = $pdo->prepare(
        "INSERT INTO audit_row_revisions
           (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
         VALUES (0, 'cli-charges-bc', NULL, 'inv_charges_bc', 0, 'insert', NULL, ?, ?, 'normal')"
    );
    $summaryLabel = $periodLabel !== null ? " [{$periodLabel}]" : '';
    $summaryStmt->execute([
        json_encode([
            'filename'      => $origName,
            'parsed'        => count($parsedRows),
            'inserted'      => $inserted,
            'skipped_dupes' => $skippedDupes,
            'errors'        => count($errors),
        ], JSON_UNESCAPED_UNICODE),
        "CLI import CSV{$summaryLabel} {$origName} : {$inserted} insérées, {$skippedDupes} doublons ignorés",
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nDB ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "  RESULT: {$inserted} rows inserted, {$skippedDupes} duplicate rows skipped.\n");
fwrite(STDOUT, "  Audit rows written: " . ($inserted + 1) . " ({$inserted} per-row + 1 upload-event summary)\n");
fwrite(STDOUT, "  DONE.\n\n");
exit(0);
