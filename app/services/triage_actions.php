<?php
declare(strict_types=1);

/**
 * triage_actions.php — type-aware action map + shared context helpers.
 *
 * Also houses triage_parse_context() so it can be used by both the
 * triage.php page and the API endpoints (alias, create, reject, etc.)
 * without requiring a full page include.
 */

/**
 * Parse supplier name and invoice ref out of context text.
 * Context format is freeform "Key: Value" text lines.
 *
 * Keys parsed: Supplier, InvoiceRef, Date, TotalHT, Drive,
 *              Reason, Action, unresolved line items (two formats):
 *
 *   Legacy (ingest-documents.js):
 *     - "raw text" (qty=N, lineTotal=N, invoiceUnitPrice=N, MI.currentPrice=N)
 *     - [RESOLVED] "raw text" (...)
 *
 *   New (ingest-one-local.ts):
 *     [line N] "raw text" miId=null conf=0.00
 *     [RESOLVED][line N] "raw text" miId=null conf=0.00
 */
if (!function_exists('triage_parse_context')) {
function triage_parse_context(string $context): array
{
    $result = ["supplier" => null, "ref" => null, "date" => null,
               "total_ht" => null, "drive_url" => null,
               "unresolved" => [], "ocr_preview" => null,
               "reason" => null, "action" => null];

    foreach (explode("\n", $context) as $line) {
        $line = trim($line);
        if ($line === "") continue;

        if (str_starts_with($line, "Supplier:")) {
            $val = trim(substr($line, 9));
            // Treat literal "?" and "(none)" as not-extracted so the fallback can fire.
            $result["supplier"] = ($val === "" || $val === "?" || $val === "(none)") ? null : $val;
        } elseif (str_starts_with($line, "InvoiceRef:")) {
            $val = trim(substr($line, 11));
            $result["ref"] = ($val === "" || $val === "?" || $val === "(none)") ? null : $val;
        } elseif (str_starts_with($line, "Date:")) {
            $result["date"] = trim(substr($line, 5));
        } elseif (str_starts_with($line, "TotalHT:")) {
            $result["total_ht"] = trim(substr($line, 8));
        } elseif (str_starts_with($line, "Drive:")) {
            $result["drive_url"] = trim(substr($line, 6));
        } elseif (str_starts_with($line, "Reason:")) {
            $result["reason"] = trim(substr($line, 7));
        } elseif (str_starts_with($line, "Action:")) {
            $result["action"] = trim(substr($line, 7));
        } elseif (
            // Legacy format: - "text" (...) or - [RESOLVED] "text" (...)
            preg_match('/^\s*-\s*"(.+)"\s*[\(\[]/', $line)
            || preg_match('/^\s*-\s*\[RESOLVED\]\s*"/', $line)
            // New format (ingest-one-local.ts): [line N] "text" miId=...
            // or already resolved: [RESOLVED][line N] "text" miId=...
            || preg_match('/^\s*\[line\s*\d+\]\s*"/', $line)
            || preg_match('/^\s*\[RESOLVED\]\[line\s*\d+\]\s*"/', $line)
        ) {
            $result["unresolved"][] = $line;
        } elseif (str_starts_with($line, "OCR preview")) {
            $result["ocr_preview"] = "";
        } elseif ($result["ocr_preview"] !== null) {
            $result["ocr_preview"] .= ($result["ocr_preview"] === "" ? "" : "\n") . $line;
        }
    }
    return $result;
}
}

/**
 * triage_actions.php — type-aware action map for the triage UI.
 *
 * Each RQ row type has a fixed set of actions that make sense for it.
 * This file is the single source of truth for that mapping — template
 * code calls ta_actions_for_type() and renders whatever comes back.
 *
 * Action spec shape:
 *   key          => 'accept'|'alias'|'create'|'reject'|'invoice'|'dn'
 *   label        => display string (French)
 *   class        => CSS modifier class appended to .detail-btn
 *   payload_form => 'none'|'inline-note'|'alias-form'|'create-modal'|'confirm'
 *   endpoint     => relative URL of the POST handler
 *
 * invoice-line-items-needed is special: actions are rendered per-line
 * (not as a footer). ta_actions_for_type() still returns the per-line
 * action specs; the template renders them inline.
 */

/**
 * Returns the action map for a given RQ type.
 *
 * @return array{
 *   actions: list<array{key:string,label:string,class:string,payload_form:string,endpoint:string}>,
 *   primary: string,
 *   per_line: bool
 * }
 */
function ta_actions_for_type(string $rq_type): array
{
    return match ($rq_type) {

        'invoice-line-items-needed' => [
            'per_line' => true,
            'primary'  => 'alias',
            'actions'  => [
                [
                    'key'          => 'alias',
                    'label'        => 'Alias MI existant',
                    'class'        => 'detail-btn--alias',
                    'payload_form' => 'alias-form',
                    'endpoint'     => '/api/triage/alias.php',
                ],
                [
                    'key'          => 'create',
                    'label'        => 'Créer nouveau MI',
                    'class'        => 'detail-btn--create',
                    'payload_form' => 'create-modal',
                    'endpoint'     => '/api/triage/create.php',
                ],
                [
                    'key'          => 'reject',
                    'label'        => 'Ignorer cette ligne',
                    'class'        => 'detail-btn--reject',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/reject.php',
                ],
            ],
        ],

        'doc-classify-ambiguous' => [
            'per_line' => false,
            'primary'  => 'invoice',
            'actions'  => [
                [
                    'key'          => 'invoice',
                    'label'        => '→ Facture',
                    'class'        => 'detail-btn--classify',
                    'payload_form' => 'confirm',
                    'endpoint'     => '/api/triage/classify.php',
                ],
                [
                    'key'          => 'dn',
                    'label'        => '→ Bon de livraison',
                    'class'        => 'detail-btn--classify',
                    'payload_form' => 'confirm',
                    'endpoint'     => '/api/triage/classify.php',
                ],
                [
                    'key'          => 'reject',
                    'label'        => 'Rejeter',
                    'class'        => 'detail-btn--reject',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/reject.php',
                ],
            ],
        ],

        'invoice-no-dn',
        'dn-no-invoice' => [
            'per_line' => false,
            'primary'  => 'accept',
            'actions'  => [
                [
                    'key'          => 'accept',
                    'label'        => 'Accepter',
                    'class'        => 'detail-btn--accept',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/accept.php',
                ],
                [
                    'key'          => 'reject',
                    'label'        => 'Rejeter',
                    'class'        => 'detail-btn--reject',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/reject.php',
                ],
            ],
        ],

        'photonote-audit' => [
            'per_line' => false,
            'primary'  => 'accept',
            'actions'  => [
                [
                    'key'          => 'accept',
                    'label'        => 'Conserver',
                    'class'        => 'detail-btn--accept',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/accept.php',
                ],
                [
                    'key'          => 'reject',
                    'label'        => 'Rejeter',
                    'class'        => 'detail-btn--reject',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/reject.php',
                ],
            ],
        ],

        default => [
            'per_line' => false,
            'primary'  => 'reject',
            'actions'  => [
                [
                    'key'          => 'reject',
                    'label'        => 'Rejeter',
                    'class'        => 'detail-btn--reject',
                    'payload_form' => 'inline-note',
                    'endpoint'     => '/api/triage/reject.php',
                ],
            ],
        ],
    };
}

/**
 * Parse an unresolved line string from context into structured fields.
 *
 * Two supported formats:
 *
 *   Legacy (ingest-documents.js):
 *     - "raw text" (qty=N, lineTotal=N, invoiceUnitPrice=N, MI.currentPrice=N)
 *     - [RESOLVED] "raw text" (...)
 *
 *   New (ingest-one-local.ts):
 *     [line N] "raw text" miId=null conf=0.00
 *     [RESOLVED][line N] "raw text" miId=null conf=0.00
 *
 * Returns an array with keys:
 *   raw        — the quoted description text (string|null)
 *   qty        — decimal qty from legacy context (float|null); null for new format
 *   lineTotal  — decimal line total from legacy context (float|null); null for new format
 *   unitPrice  — decimal unit price from legacy context (float|null); null for new format
 *   resolved   — bool: true if [RESOLVED] prefix present
 *   db_line_index — int|null: the parser's original line_index embedded in new-format lines
 *                   ([line N] → N). Null for legacy format. Used for doc_invoice_lines lookups.
 */
function ta_parse_unresolved_line(string $line): array
{
    $raw          = null;
    $qty          = null;
    $lineTotal    = null;
    $unitPrice    = null;
    $resolved     = false;
    $dbLineIndex  = null;
    $droppedByLlm = false;

    // Detect and strip [RESOLVED] prefix (both legacy and new format)
    // Legacy resolved: "- [RESOLVED] ..."
    // New resolved:    "[RESOLVED][line N] ..."
    $trimmed = ltrim($line);

    if (preg_match('/^\[RESOLVED\]/', $trimmed)) {
        $resolved = true;
        $trimmed  = ltrim(substr($trimmed, 10));
    } elseif (preg_match('/^-\s*\[RESOLVED\]/', $trimmed)) {
        $resolved = true;
        $trimmed  = preg_replace('/^-\s*\[RESOLVED\]\s*/', '', $trimmed);
    }

    // LLM-dropped lines: [LLM-DROPPED] "sourceText" dropReason="..." suggestedMiId=...
    // These lines have no doc_invoice_lines.line_index (never written to that table).
    // ta_materialize_delivery will INSERT inv_deliveries when the operator resolves them.
    if (preg_match('/^\[LLM-DROPPED\]\s*/', $trimmed)) {
        $droppedByLlm = true;
        $trimmed      = preg_replace('/^\[LLM-DROPPED\]\s*/', '', $trimmed);
    }

    // New format: [line N] "text" miId=... conf=...
    // Extract embedded line index, then strip prefix.
    if (preg_match('/^\[line\s*(\d+)\]\s*/', $trimmed, $m)) {
        $dbLineIndex = (int) $m[1];
        $trimmed     = preg_replace('/^\[line\s*\d+\]\s*/', '', $trimmed);
    }

    // Legacy format: strip leading "- " marker
    $trimmed = preg_replace('/^-\s*/', '', $trimmed);

    // Extract quoted raw text. Greedy ".+" so embedded quotes survive.
    if (preg_match('/"(.+)"/', $trimmed, $m)) {
        $raw = $m[1];
    }

    // Extract numeric fields (legacy format only — new format has no qty/price in context)
    if (preg_match('/qty=([0-9.]+)/', $trimmed, $m)) {
        $qty = (float) $m[1];
    }
    if (preg_match('/lineTotal=([0-9.]+)/', $trimmed, $m)) {
        $lineTotal = (float) $m[1];
    }
    if (preg_match('/invoiceUnitPrice=([0-9.]+)/', $trimmed, $m)) {
        $unitPrice = (float) $m[1];
    }

    return compact('raw', 'qty', 'lineTotal', 'unitPrice', 'resolved', 'dbLineIndex', 'droppedByLlm');
}

/**
 * Count unresolved (not-yet-actioned) lines in a context unresolved array.
 */
function ta_count_open_lines(array $unresolvedLines): int
{
    $open = 0;
    foreach ($unresolvedLines as $line) {
        $parsed = ta_parse_unresolved_line($line);
        if (!$parsed['resolved']) {
            $open++;
        }
    }
    return $open;
}

/**
 * Mark a specific line index as resolved in the context string.
 * Returns the updated context string.
 *
 * Line index is 0-based over the FULL line array as built by
 * triage_parse_context()['unresolved'] — which includes both still-unresolved
 * lines AND lines already prefixed with [RESOLVED]. The UI iterates that array
 * with foreach($ctx["unresolved"] as $lineIdx => ...), so a stable array
 * index is the contract.
 *
 * Earlier version counted only still-unresolved lines, which drifted on
 * subsequent submissions: line N became (N - resolved-count) and the function
 * silently returned context unchanged — alias/reject/create endpoints
 * committed their DB writes but the RQ row stayed open with no marker.
 */
function ta_mark_line_resolved(string $context, int $lineIndex): string
{
    $lines    = explode("\n", $context);
    $arrayIdx = 0;
    $modified = false;

    foreach ($lines as &$line) {
        // Must stay aligned with triage_parse_context() so the array index
        // from the UI foreach matches the walk here.
        //
        // Legacy format (ingest-documents.js):
        //   - "text" (qty=...)        → unresolved
        //   - [RESOLVED] "text" (...)  → already resolved
        //
        // New format (ingest-one-local.ts):
        //   [line N] "text" miId=...        → unresolved
        //   [RESOLVED][line N] "text" ...   → already resolved
        $isLegacyUnresolved  = (bool) preg_match('/^\s*-\s*".+"\s*[\(\[]/', $line);
        $isLegacyResolved    = (bool) preg_match('/^\s*-\s*\[RESOLVED\]\s*"/', $line);
        $isNewFormatUnresolved = (bool) preg_match('/^\s*\[line\s*\d+\]\s*"/', $line);
        $isNewFormatResolved   = (bool) preg_match('/^\s*\[RESOLVED\]\[line\s*\d+\]\s*"/', $line);

        $isAny = $isLegacyUnresolved || $isLegacyResolved
               || $isNewFormatUnresolved || $isNewFormatResolved;

        if (!$isAny) {
            continue;
        }
        if ($arrayIdx === $lineIndex) {
            if ($isLegacyUnresolved) {
                // Prepend [RESOLVED] after the leading "- "
                $line     = preg_replace('/^(\s*-\s*)/', '$1[RESOLVED] ', $line);
                $modified = true;
            } elseif ($isNewFormatUnresolved) {
                // Prepend [RESOLVED] at the start of the trimmed line
                $line     = preg_replace('/^(\s*)(\[line\s*\d+\])/', '$1[RESOLVED]$2', $line);
                $modified = true;
            }
            break;
        }
        $arrayIdx++;
    }
    unset($line);

    return implode("\n", $lines);
}

/**
 * Insert one inv_deliveries row from a triage action.
 *
 * Single source of truth used by alias.php, create.php, and manual-lines.php.
 * INSERT IGNORE + unique index on row_hash guarantees idempotency.
 *
 * Required params:
 *   rq_id           int     — doc_review_queue.id
 *   line_index      int     — 0-based line index (use 0 for whole-row)
 *   mi_internal_id  int     — ref_mi.id FK
 *   mi_id_str       string  — ref_mi.mi_id (stored in mi_id_raw)
 *   description     string  — written to ingredient_raw + details suffix
 *   qty             float   — > 0
 *   unit_price      float   — ≥ 0
 *   source          string  — 'triage-alias'|'triage-create'|'manual-triage'
 *
 * Optional params (nullable):
 *   invoice_id      int|null
 *   invoice_ref     string|null
 *   invoice_date    string|null
 *   supplier_raw    string|null
 *   supplier_fk     int|null
 *   currency        string    (default 'CHF')
 *   source_origin   string    (default 'web')
 *
 * Returns: ['inserted' => bool, 'row_hash' => string, 'reason' => string|null]
 */
function ta_materialize_delivery(PDO $pdo, array $p): array
{
    $source      = $p['source']       ?? 'manual-triage';
    $rqId        = (int)($p['rq_id']  ?? 0);
    $lineIndex   = (int)($p['line_index'] ?? 0);
    $miIdStr     = (string)($p['mi_id_str'] ?? '');
    $qty         = (float)($p['qty']  ?? 0);
    $unitPrice   = (float)($p['unit_price'] ?? 0);

    $rowHash     = hash('sha256', implode('|', [
        $source, $rqId, $lineIndex, $miIdStr, $qty, $unitPrice,
    ]));

    $total       = round($qty * $unitPrice, 2);
    $description = (string)($p['description'] ?? '');
    $invoiceId   = isset($p['invoice_id'])  ? (int)$p['invoice_id']  : null;
    $invoiceRef  = $p['invoice_ref']  ?? null;
    $invoiceDate = $p['invoice_date'] ?? null;
    $supplierRaw = $p['supplier_raw'] ?? null;
    $supplierFk  = isset($p['supplier_fk']) && $p['supplier_fk'] !== null
                   ? (int)$p['supplier_fk'] : null;
    $fileIdFk    = isset($p['file_id_fk']) && $p['file_id_fk'] !== null
                   ? (int)$p['file_id_fk'] : null;
    $currency    = $p['currency']      ?? 'CHF';
    $srcOrigin   = $p['source_origin'] ?? 'web';
    $miIntId     = (int)($p['mi_internal_id'] ?? 0);

    $details = ucfirst(str_replace('-', ' ', $source)) . " — RQ #{$rqId} line "
             . ($lineIndex + 1) . " — {$description}";

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO inv_deliveries
            (row_hash, date_received, supplier_raw, ingredient_raw,
             mi_id_raw, qty_delivered, qty_remaining, unit_price,
             currency, total_original, total_chf,
             status, invoice_ref, source, source_origin,
             submitted_at, details, supplier_fk, ingredient_fk, resolution,
             file_id_fk)
         VALUES
            (?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             'Active', ?, ?, ?,
             NOW(6), ?, ?, ?, 'resolved',
             ?)"
    );
    $stmt->execute([
        $rowHash,
        $invoiceDate,
        $supplierRaw,
        $description,
        $miIdStr,
        $qty,
        $qty,        // qty_remaining = qty at creation
        $unitPrice,
        $currency,
        $total,
        $total,      // total_chf — same as original when CHF; operator corrects EUR later
        $invoiceRef,
        $source,
        $srcOrigin,
        $details,
        $supplierFk,
        $miIntId ?: null,
        $fileIdFk,
    ]);

    $inserted = $stmt->rowCount() === 1;
    return [
        'inserted'  => $inserted,
        'row_hash'  => $rowHash,
        'reason'    => $inserted ? null : 'duplicate (row_hash already exists)',
    ];
}

/**
 * Resolve (promote) an existing Pending inv_deliveries row to Active.
 *
 * This is the new triage write path for invoice-origin Pending rows.
 * Instead of INSERTing a new row (ta_materialize_delivery legacy path),
 * this UPDATEs the existing Pending row written at ingest time.
 *
 * Idempotent: the WHERE status='Pending' guard prevents double-promotion.
 * If the row is already Active (second alias submission, race), rowCount=0
 * and $result['updated']=false without an error.
 *
 * Required params:
 *   file_id_fk    int    — doc_files.id FK (the document anchor from migration 048)
 *   db_line_index int    — 0-based parser line_index (from [line N] context tag)
 *   mi_internal_id int   — ref_mi.id FK to set on the resolved row
 *   mi_id_str      string — ref_mi.mi_id string (stored in mi_id_raw)
 *
 * Optional params:
 *   unit_price  float|null  — override unit_price on the Pending row
 *   qty         float|null  — override qty_delivered on the Pending row
 *   alias_text  string      — human-readable alias for the details suffix
 *   (audit identity of the operator is captured in audit-log.jsonl, not on the row)
 *
 * Returns:
 *   ['updated' => bool, 'rows_affected' => int, 'reason' => string|null]
 *
 * If updated===false and rows_affected===0, the caller MUST fall back to
 * ta_materialize_delivery() (handles legacy rows with no Pending anchor).
 */
function ta_resolve_pending_delivery(PDO $pdo, array $p): array
{
    $fileIdFk     = isset($p['file_id_fk'])     ? (int)$p['file_id_fk']     : null;
    $dbLineIndex  = isset($p['db_line_index'])   ? (int)$p['db_line_index']  : null;
    $miInternalId = isset($p['mi_internal_id'])  ? (int)$p['mi_internal_id'] : null;
    $miIdStr      = (string)($p['mi_id_str']     ?? '');
    $unitPrice    = isset($p['unit_price'])  && $p['unit_price']  !== null ? (float)$p['unit_price']  : null;
    $qty          = isset($p['qty'])         && $p['qty']         !== null ? (float)$p['qty']         : null;
    $aliasText    = (string)($p['alias_text'] ?? '');

    if ($fileIdFk === null || $dbLineIndex === null || $miInternalId === null) {
        return [
            'updated'       => false,
            'rows_affected' => 0,
            'reason'        => 'missing required params (file_id_fk, db_line_index, mi_internal_id)',
        ];
    }

    // Build SET clause dynamically (only override qty/price when provided)
    // inv_deliveries has no last_modified_by column — actor identity lives in audit-log.jsonl
    $setClauses = [
        'ingredient_fk    = ?',
        'mi_id_raw        = ?',
        'status           = \'Active\'',
        'resolution       = \'resolved\'',
        'last_seen_at     = NOW()',
        'details          = CONCAT(COALESCE(details, \'\'), \' | resolved via triage: \', ?)',
    ];
    $params = [$miInternalId, $miIdStr, $aliasText ?: 'triage'];

    if ($unitPrice !== null) {
        $setClauses[] = 'unit_price = ?';
        $params[]     = $unitPrice;
        // Recompute totals when price is overridden
        $setClauses[] = 'total_original = qty_delivered * ?';
        $params[]     = $unitPrice;
        $setClauses[] = 'total_chf = qty_delivered * ? * COALESCE(eur_to_chf, 1.0)';
        $params[]     = $unitPrice;
    }
    if ($qty !== null) {
        $setClauses[] = 'qty_delivered = ?';
        $params[]     = $qty;
        $setClauses[] = 'qty_remaining = ?';
        $params[]     = $qty;
        if ($unitPrice !== null) {
            // Already added total_original/total_chf above — no duplicate
        } else {
            $setClauses[] = 'total_original = ? * COALESCE(unit_price, 0)';
            $params[]     = $qty;
            $setClauses[] = 'total_chf = ? * COALESCE(unit_price, 0) * COALESCE(eur_to_chf, 1.0)';
            $params[]     = $qty;
        }
    }

    // WHERE clause params
    $params[] = $fileIdFk;
    $params[] = $dbLineIndex;

    $sql = "UPDATE inv_deliveries
               SET " . implode(', ', $setClauses) . "
             WHERE file_id_fk = ?
               AND line_index = ?
               AND status = 'Pending'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rowsAffected = $stmt->rowCount();

    return [
        'updated'       => $rowsAffected > 0,
        'rows_affected' => $rowsAffected,
        'reason'        => $rowsAffected === 0
            ? 'no Pending row found at (file_id_fk, line_index) — may already be Active or row was never written'
            : null,
    ];
}

/**
 * Update doc_invoice_lines after a triage alias/create action.
 *
 * Sets mi_id_fk only when currently NULL (never overwrites a prior resolution).
 * qty / unit_price / line_total are preserved when already non-null — operator-
 * submitted values only fill gaps. To force an overwrite (e.g. operator
 * correction), update directly via SQL.
 * name_confidence and price_confidence always set to 1.000 (human-confirmed).
 */
function ta_update_invoice_line(
    PDO    $pdo,
    int    $invoiceId,
    int    $lineIndex,
    int    $miInternalId,
    ?float $qty,
    ?float $unitPrice
): void {
    $lineTotal = ($qty !== null && $unitPrice !== null)
                 ? round($qty * $unitPrice, 2)
                 : null;

    $pdo->prepare(
        "UPDATE doc_invoice_lines
            SET mi_id_fk        = COALESCE(mi_id_fk, ?),
                qty             = COALESCE(qty, ?),
                unit_price      = COALESCE(unit_price, ?),
                line_total      = CASE WHEN line_total IS NULL THEN ? * ? ELSE line_total END,
                name_confidence = 1.000,
                price_confidence = 1.000,
                updated_at      = NOW()
          WHERE invoice_id = ? AND line_index = ?"
    )->execute([
        $miInternalId,
        $qty,
        $unitPrice,
        $qty,
        $unitPrice,
        $invoiceId,
        $lineIndex,
    ]);
}

/**
 * Resolve the doc_invoice_lines.line_index when context parsing left dbLineIndex null
 * (legacy ingest-documents.js format, or malformed context). Tries 4 strategies in order:
 *
 *  1. Match an UNRESOLVED row (mi_id_fk IS NULL) whose ingredient_name or description
 *     matches the alias/description text the operator typed.
 *  2. Match ANY row (resolved or not) whose ingredient_name/description matches —
 *     handles pack-size variant case where ingest resolved a different MI.
 *  3. Match an UNRESOLVED row by line_total proximity (±0.05) against the parsed
 *     context's lineTotal hint.
 *  4. Fall back to the array-position lineIndex; log a warning via error_log.
 *
 * Returns the resolved int line_index. Never returns null — fallback always supplies a value.
 */
function ta_resolve_db_line_index(
    PDO    $pdo,
    int    $invoiceId,
    int    $arrayLineIndex,
    string $aliasText,
    ?float $contextLineTotal,
    int    $rqId
): int {
    $needle = trim($aliasText);

    // Strategy 1: unresolved row, exact-ish text match
    if ($needle !== '') {
        $stmt = $pdo->prepare(
            "SELECT line_index FROM doc_invoice_lines
              WHERE invoice_id = ? AND mi_id_fk IS NULL
                AND (ingredient_name = ? OR description = ?)
              ORDER BY line_index ASC LIMIT 1"
        );
        $stmt->execute([$invoiceId, $needle, $needle]);
        $r = $stmt->fetchColumn();
        if ($r !== false) return (int)$r;

        // Strategy 2: any row, exact-ish text match
        $stmt = $pdo->prepare(
            "SELECT line_index FROM doc_invoice_lines
              WHERE invoice_id = ?
                AND (ingredient_name = ? OR description = ?)
              ORDER BY line_index ASC LIMIT 1"
        );
        $stmt->execute([$invoiceId, $needle, $needle]);
        $r = $stmt->fetchColumn();
        if ($r !== false) return (int)$r;
    }

    // Strategy 3: unresolved row, line_total proximity ±0.05
    if ($contextLineTotal !== null) {
        $stmt = $pdo->prepare(
            "SELECT line_index FROM doc_invoice_lines
              WHERE invoice_id = ? AND mi_id_fk IS NULL
                AND ABS(line_total - ?) < 0.05
              ORDER BY ABS(line_total - ?) ASC, line_index ASC LIMIT 1"
        );
        $stmt->execute([$invoiceId, $contextLineTotal, $contextLineTotal]);
        $r = $stmt->fetchColumn();
        if ($r !== false) return (int)$r;
    }

    // Strategy 4: array-position fallback with warning
    error_log(sprintf(
        'TRIAGE_LINE_IDX_FALLBACK: rq_id=%d invoice_id=%d aliasText=%s contextLineTotal=%s — using arrayLineIndex=%d',
        $rqId, $invoiceId, json_encode($needle), $contextLineTotal === null ? 'null' : (string)$contextLineTotal, $arrayLineIndex
    ));
    return $arrayLineIndex;
}

/**
 * Build the return URL after a triage action.
 * If the current row was closed, tries to find the next open row.
 */
function ta_redirect_url(int $rqId, bool $rowClosed, PDO $pdo): string
{
    if (!$rowClosed) {
        return "/modules/triage.php?tab=docs&rq_id={$rqId}";
    }

    // Row closed — find the next open row with id > current (wrap to first if none)
    $docTypes = [
        'invoice-line-items-needed', 'doc-classify-ambiguous',
        'invoice-no-dn', 'dn-no-invoice', 'photonote-audit',
    ];
    $in  = implode(',', array_fill(0, count($docTypes), '?'));
    $stmt = $pdo->prepare(
        "SELECT id FROM doc_review_queue
          WHERE status = 'open' AND type IN ($in) AND id > ?
          ORDER BY priority DESC, created_at ASC
          LIMIT 1"
    );
    $stmt->execute(array_merge($docTypes, [$rqId]));
    $nextId = $stmt->fetchColumn();

    if ($nextId !== false) {
        return "/modules/triage.php?tab=docs&rq_id={$nextId}";
    }

    // Wrap to first
    $stmt2 = $pdo->prepare(
        "SELECT id FROM doc_review_queue
          WHERE status = 'open' AND type IN ($in)
          ORDER BY priority DESC, created_at ASC
          LIMIT 1"
    );
    $stmt2->execute($docTypes);
    $firstId = $stmt2->fetchColumn();

    if ($firstId !== false) {
        return "/modules/triage.php?tab=docs&rq_id={$firstId}";
    }

    return "/modules/triage.php?tab=docs";
}
