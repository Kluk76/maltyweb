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

    // Extract quoted raw text
    if (preg_match('/"([^"]+)"/', $line, $m)) {
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
 * Line index is 0-based over the unresolved-line array only.
 */
function ta_mark_line_resolved(string $context, int $lineIndex): string
{
    $lines    = explode("\n", $context);
    $uIdx     = 0;
    $modified = false;

    foreach ($lines as &$line) {
        $trimmed = trim($line);
        // Match unresolved line format (NOT already marked resolved)
        if (preg_match('/^\s*-\s*"[^"]+"\s*\(/', $line) && !str_contains($line, '[RESOLVED]')) {
            if ($uIdx === $lineIndex) {
                // Prefix with [RESOLVED] marker inside the "- " list item
                $line     = preg_replace('/^(\s*-\s*)/', '$1[RESOLVED] ', $line);
                $modified = true;
                break;
            }
            $uIdx++;
        }
    }
    unset($line);

    return implode("\n", $lines);
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
