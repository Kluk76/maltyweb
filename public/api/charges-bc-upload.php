<?php
declare(strict_types=1);
/**
 * POST /api/charges-bc-upload.php — ChargesBC CSV upload handler.
 *
 * Two-step flow:
 *   Step 1  confirm=0  Parse + validate CSV, return preview JSON (no DB writes).
 *   Step 2  confirm=1  Apply: INSERT IGNORE into inv_charges_bc, log audit.
 *
 * Multipart POST:
 *   csv_file   — uploaded CSV file (BC GL journal export)
 *   confirm    — "0" (preview) or "1" (commit)
 *   csrf       — CSRF token
 *   upload_id  — (step 2 only) temp file key from step 1
 *
 * Response JSON:
 *   {
 *     "ok":           bool,
 *     "upload_id":    string,        // step 1 only — temp file key for step 2
 *     "parsed":       int,
 *     "inserted":     int,
 *     "skipped_dupes":int,
 *     "errors":       string[],      // per-row validation warnings (non-fatal)
 *     "preview":      array,         // step 1 only — first 20 rows
 *     "error":        string         // fatal error message (ok=false)
 *   }
 *
 * ChargesBC CSV column mapping (0-based, matching seed-inv-charges-bc.ts):
 *   B=1  PeriodGlJourDateFilter   → period_text
 *   F=5  Name_GLAccount           → gl_account_name
 *   H=7  No_GLAccount             → gl_account_no
 *   Z=25 DebitAmount_GLEntry      → debit_amount
 *   AA=26 CreditAmount_GLEntry    → credit_amount
 *   AH=33 Description_GLEntry    → description
 *   AI=34 DocumentNo_GLEntry     → document_no
 *   AJ=35 PostingDateFormatted   → posting_date_txt
 *   AO=40 PostingDate_GLEntry    → posting_date
 *   AL=37 EntryNo_GLEntry        → entry_no
 *   AF=31 BalAccountNo_GLEntry   → bal_account_no
 *   AT=45 Exrate                 → exrate
 *
 * row_hash = SHA-256 of:
 *   period_text|gl_account_no|entry_no|description|debit_raw|credit_raw
 * (matches the TypeScript seed script exactly)
 *
 * Idempotency: INSERT IGNORE on row_hash UNIQUE — re-upload same month = 0 inserts.
 * Audit: one audit_row_revisions row per INSERT (action='insert') +
 *        one summary row per upload event (action='upload').
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────────
require_page_access('charges-bc');
$me = current_user();
if ($me === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Authentification requise.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$postedCsrf = $_POST['csrf'] ?? null;
if (!csrf_verify(is_string($postedCsrf) ? $postedCsrf : null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
    exit;
}

// ── Confirm flag ──────────────────────────────────────────────────────────────
$confirm  = isset($_POST['confirm']) && $_POST['confirm'] === '1';
$userId   = (int)($me['id'] ?? 0);
$username = (string)($me['username'] ?? 'unknown');

// ── Helpers ───────────────────────────────────────────────────────────────────

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
const COL_PERIOD_TEXT     = 1;   // B
const COL_GL_ACCT_NAME    = 5;   // F
const COL_GL_ACCT_NO      = 7;   // H
const COL_DEBIT           = 25;  // Z
const COL_CREDIT          = 26;  // AA
const COL_DESCRIPTION     = 33;  // AH
const COL_DOC_NO          = 34;  // AI
const COL_POSTING_DATE_TXT= 35;  // AJ
const COL_POSTING_DATE    = 40;  // AO
const COL_ENTRY_NO        = 37;  // AL
const COL_BAL_ACCT_NO     = 31;  // AF
const COL_EXRATE          = 45;  // AT

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

    $entryNo    = _cbc_str($cols[COL_ENTRY_NO]);
    $description= _cbc_str($cols[COL_DESCRIPTION]);
    $debitRaw   = _cbc_str($cols[COL_DEBIT])   ?? '0';
    $creditRaw  = _cbc_str($cols[COL_CREDIT])  ?? '0';
    $isSummary  = ($entryNo === null) ? 1 : 0;

    $hash = _cbc_row_hash(
        $periodText ?? '',
        $glAcctNo   ?? '',
        $entryNo    ?? '',
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
        'last_modified_by' => 'web',
        '_debit_raw'       => $debitRaw,   // internal for hash — not inserted
        '_credit_raw'      => $creditRaw,  // internal for hash — not inserted
        '_row_num'         => $rowNum,
    ];
}

// ── Resolve CSV file ──────────────────────────────────────────────────────────
$tmpDir    = sys_get_temp_dir();
$uploadId  = null;
$tmpPath   = null;
$origName  = '';

if ($confirm) {
    // Step 2: re-use temp file from step 1
    $rawUploadId = $_POST['upload_id'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $rawUploadId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'upload_id invalide.']);
        exit;
    }
    $uploadId = $rawUploadId;
    $tmpPath  = $tmpDir . '/cbc-upload-' . $uploadId . '.csv';
    if (!file_exists($tmpPath)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Fichier temporaire expiré. Veuillez re-téléverser le fichier.']);
        exit;
    }
    // Recover original filename stored in a companion meta file
    $metaPath = $tmpDir . '/cbc-upload-' . $uploadId . '.meta';
    $origName = file_exists($metaPath) ? (string)file_get_contents($metaPath) : 'inconnu';
} else {
    // Step 1: receive uploaded file
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['csv_file']['error'] ?? -1;
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Erreur de téléversement (code {$uploadErr})."]);
        exit;
    }

    $fileInfo = $_FILES['csv_file'];
    $origName = basename((string)($fileInfo['name'] ?? 'upload.csv'));

    // Validate MIME / extension (paranoid — CSV should not be executable)
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Seuls les fichiers CSV sont acceptés (extension reçue : .{$ext})."]);
        exit;
    }

    $uploadId = bin2hex(random_bytes(16));
    $tmpPath  = $tmpDir . '/cbc-upload-' . $uploadId . '.csv';
    $metaPath = $tmpDir . '/cbc-upload-' . $uploadId . '.meta';

    if (!move_uploaded_file($fileInfo['tmp_name'], $tmpPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Impossible de déplacer le fichier téléversé.']);
        exit;
    }
    file_put_contents($metaPath, $origName);
}

// ── Parse CSV ─────────────────────────────────────────────────────────────────
$fh = @fopen($tmpPath, 'r');
if ($fh === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Impossible d\'ouvrir le fichier CSV.']);
    exit;
}

$parsedRows = [];
$errors     = [];
$rowNum     = 0;
$headerSkipped = false;

while (($cols = fgetcsv($fh, 0, ';')) !== false) {
    $rowNum++;

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
            continue;
        }
    }

    if (!is_array($cols)) {
        continue;
    }

    $parsed = _cbc_parse_row($cols, $rowNum);
    if ($parsed === null) {
        continue; // blank row
    }
    if (isset($parsed['error'])) {
        $errors[] = "Ligne {$rowNum}: " . $parsed['error'];
        continue;
    }
    $parsedRows[] = $parsed;
}
fclose($fh);

// Clean up temp file and meta after step 2 commit
if ($confirm) {
    @unlink($tmpPath);
    @unlink($tmpDir . '/cbc-upload-' . $uploadId . '.meta');
}

if (empty($parsedRows) && empty($errors)) {
    echo json_encode([
        'ok'           => false,
        'error'        => 'Aucune ligne de données trouvée dans le fichier CSV. Vérifiez le format (séparateur point-virgule).',
        'upload_id'    => $uploadId,
        'parsed'       => 0,
        'inserted'     => 0,
        'skipped_dupes'=> 0,
        'errors'       => [],
    ]);
    exit;
}

// ── Preview (step 1) or Insert (step 2) ──────────────────────────────────────
$inserted     = 0;
$skippedDupes = 0;

if (!$confirm) {
    // Return preview — first 20 rows + stats
    $preview = array_slice($parsedRows, 0, 20);
    $previewOut = [];
    foreach ($preview as $r) {
        $previewOut[] = [
            'period_text'   => $r['period_text'],
            'gl_account_no' => $r['gl_account_no'],
            'gl_account_name'=> $r['gl_account_name'],
            'entry_no'      => $r['entry_no'],
            'description'   => $r['description'],
            'debit_amount'  => $r['debit_amount'],
            'credit_amount' => $r['credit_amount'],
            'posting_date'  => $r['posting_date'],
            'is_summary'    => $r['is_summary'],
        ];
    }
    echo json_encode([
        'ok'            => true,
        'upload_id'     => $uploadId,
        'parsed'        => count($parsedRows),
        'inserted'      => 0,
        'skipped_dupes' => 0,
        'errors'        => $errors,
        'preview'       => $previewOut,
        'filename'      => $origName,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Step 2: INSERT IGNORE
$pdo = maltytask_pdo();
$insertedIds = [];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO inv_charges_bc
           (gl_account_no, gl_account_name, period_text, debit_amount, credit_amount,
            description, document_no, posting_date_txt, posting_date, entry_no,
            bal_account_no, exrate, is_summary, raw_json, row_hash, last_modified_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
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
            'web',
        ]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            $newId = (int)$pdo->lastInsertId();
            $inserted++;
            $insertedIds[] = $newId;
            // Per-row audit
            log_revision(
                $pdo,
                $me,
                'inv_charges_bc',
                $newId,
                null,
                [
                    'period_text'   => $r['period_text'],
                    'gl_account_no' => $r['gl_account_no'],
                    'entry_no'      => $r['entry_no'],
                    'is_summary'    => $r['is_summary'],
                ],
                'normal',
                "Téléversement CSV : {$origName}"
            );
        } else {
            $skippedDupes++;
        }
    }

    // Upload event summary audit row — uses target_pk=0 as upload-event sentinel
    // (action ENUM only supports 'insert'/'update'; target_pk=0 identifies upload events)
    $summaryStmt = $pdo->prepare(
        "INSERT INTO audit_row_revisions
           (user_id, username, ip, target_table, target_pk, action, before_json, after_json, comment, qc_flag)
         VALUES (?, ?, ?, 'inv_charges_bc', 0, 'insert', NULL, ?, ?, 'normal')"
    );
    $summaryStmt->execute([
        (int)($me['id'] ?? 0),
        $username,
        substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        json_encode([
            'filename'       => $origName,
            'parsed'         => count($parsedRows),
            'inserted'       => $inserted,
            'skipped_dupes'  => $skippedDupes,
            'errors'         => count($errors),
        ], JSON_UNESCAPED_UNICODE),
        "Import CSV {$origName} : {$inserted} insérées, {$skippedDupes} doublons ignorés",
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Erreur base de données : ' . $e->getMessage(),
    ]);
    exit;
}

echo json_encode([
    'ok'            => true,
    'upload_id'     => $uploadId,
    'parsed'        => count($parsedRows),
    'inserted'      => $inserted,
    'skipped_dupes' => $skippedDupes,
    'errors'        => $errors,
], JSON_UNESCAPED_UNICODE);
