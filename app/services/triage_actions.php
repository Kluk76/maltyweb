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
 *              Reason, Action, unresolved line items (- "..." (...))
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
            $result["supplier"] = trim(substr($line, 9));
        } elseif (str_starts_with($line, "InvoiceRef:")) {
            $result["ref"] = trim(substr($line, 11));
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
        } elseif (preg_match('/^\s*-\s*"(.+)"\s*[\(\[]/', $line)
                  || preg_match('/^\s*-\s*\[RESOLVED\]\s*"/', $line)) {
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
 * Format: - "raw text" (qty=N, lineTotal=N, invoiceUnitPrice=N, MI.currentPrice=N)
 * Returns an array with 'raw', 'qty', 'line_total', 'unit_price' keys.
 * 'resolved' is true if the line has already been actioned in a previous submission.
 */
function ta_parse_unresolved_line(string $line): array
{
    $raw        = null;
    $qty        = null;
    $lineTotal  = null;
    $unitPrice  = null;
    $resolved   = false;

    // Strip leading "- " list marker if present (triage_parse_context trims spaces
    // but leaves the "- " prefix)
    $stripped = preg_replace('/^\s*-\s*/', '', $line);

    // Check resolved marker: lines prefixed with [RESOLVED] are done
    if (str_starts_with($stripped, '[RESOLVED]')) {
        $resolved = true;
        $line     = trim(substr($stripped, 10));
    }

    // Extract quoted raw text. Greedy ".+" so embedded quotes
    // (e.g. 1/2" inch marker) survive — non-greedy [^"]+ would
    // truncate at the first inner quote.
    if (preg_match('/"(.+)"/', $line, $m)) {
        $raw = $m[1];
    }

    // Extract numeric fields
    if (preg_match('/qty=([0-9.]+)/', $line, $m)) {
        $qty = (float) $m[1];
    }
    if (preg_match('/lineTotal=([0-9.]+)/', $line, $m)) {
        $lineTotal = (float) $m[1];
    }
    if (preg_match('/invoiceUnitPrice=([0-9.]+)/', $line, $m)) {
        $unitPrice = (float) $m[1];
    }

    return compact('raw', 'qty', 'lineTotal', 'unitPrice', 'resolved');
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
        // Greedy ".+" + "[" alternative — must stay aligned with
        // triage_parse_context() so the array index from the foreach
        // in the UI matches the walk here. Embedded quotes (e.g. 1/2"
        // for inch) require greedy; the "[" suffix appears on some
        // resolved-style lines.
        $isUnresolvedForm = (bool) preg_match('/^\s*-\s*".+"\s*[\(\[]/', $line);
        $isResolvedForm   = (bool) preg_match('/^\s*-\s*\[RESOLVED\]\s*"/', $line);
        if (!$isUnresolvedForm && !$isResolvedForm) {
            continue;
        }
        if ($arrayIdx === $lineIndex) {
            if ($isUnresolvedForm) {
                $line     = preg_replace('/^(\s*-\s*)/', '$1[RESOLVED] ', $line);
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
             submitted_at, details, supplier_fk, ingredient_fk, resolution)
         VALUES
            (?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?,
             'Active', ?, ?, ?,
             NOW(6), ?, ?, ?, 'resolved')"
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
    ]);

    $inserted = $stmt->rowCount() === 1;
    return [
        'inserted'  => $inserted,
        'row_hash'  => $rowHash,
        'reason'    => $inserted ? null : 'duplicate (row_hash already exists)',
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
