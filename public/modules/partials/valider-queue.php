<?php
declare(strict_types=1);
/**
 * partials/valider-queue.php — « À valider » queue rendering partial.
 *
 * Shared between:
 *   - public/modules/invoice-validate.php  (standalone page, body.invoice-validate)
 *   - public/modules/triage.php            (tab=valider, body.triage.invoice-validate)
 *
 * HARD CONSTRAINT: this is the ONE source of truth for the À-valider list query,
 * per-line rendering, window.INVOICES JSON injection, and card HTML.
 * Do NOT duplicate any of this in the host pages.
 *
 * Contract:
 *   $pdo          PDO — active maltytask connection (caller sets it up)
 *   $csrfToken    string — CSRF token for JS injection
 *
 * Outputs:
 *   - HTML markup (cards + empty state + reject dialog)
 *   - <script> block setting window.IV_CSRF and window.IV_COUNT
 *   - NOTE: does NOT emit the <script src> for invoice-validate.js;
 *     each host page does that at the appropriate place for its load order.
 *
 * Dual-key join note (doc_files):
 *   doc_uploads.drive_file_id (UUID) = doc_files.file_id (UUID)
 *   doc_files.id (BIGINT)            = doc_invoices.file_id (BIGINT)
 *
 * The delivery_write_plan IS NOT NULL filter is CRITICAL — excludes ~192
 * pre-existing invoices written under the old auto-write code.
 *
 * The NOT EXISTS inv_deliveries guard (Phase 1) excludes already-committed
 * invoices so they don't reappear after a race commit.
 */

// ── List query ────────────────────────────────────────────────────────────────
$_ivDbError = null;
$_ivInvoices = [];

try {
    $headerStmt = $pdo->query(
        "SELECT
             du.id                               AS upload_id,
             du.original_filename,
             di.id                               AS invoice_id,
             di.supplier_name,
             di.invoice_ref,
             di.invoice_date,
             di.total_ht,
             di.currency,
             di.parser_name,
             di.delivery_write_plan,
             COUNT(il.id)                        AS line_count,
             SUM(CASE WHEN il.mi_id_fk IS NULL
                           AND il.accounting_class IS NULL  THEN 1 ELSE 0 END) AS lines_unresolved,
             SUM(CASE WHEN il.name_confidence IS NOT NULL
                           AND il.name_confidence < 0.95    THEN 1 ELSE 0 END) AS lines_low_name_conf,
             SUM(CASE WHEN il.price_confidence IS NOT NULL
                           AND il.price_confidence < 0.95   THEN 1 ELSE 0 END) AS lines_low_price_conf,
             SUM(CASE WHEN il.gate_failures IS NOT NULL
                           AND JSON_LENGTH(il.gate_failures) > 0 THEN 1 ELSE 0 END) AS lines_with_gate_failures
           FROM doc_uploads du
           JOIN doc_files   df ON df.file_id = du.drive_file_id
           JOIN doc_invoices di ON di.file_id = df.id
           LEFT JOIN doc_invoice_lines il ON il.invoice_id = di.id
          WHERE du.pipeline_status = 'processed'
            AND di.validated_at IS NULL
            AND di.skipped_at   IS NULL
            AND di.delivery_write_plan IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM inv_deliveries d WHERE d.file_id_fk = df.id
            )
          GROUP BY du.id, di.id
          ORDER BY di.invoice_date DESC, di.id DESC"
    );
    $headerRows = $headerStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($headerRows)) {
        $invoiceIds   = array_column($headerRows, 'invoice_id');
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));

        $lineStmt = $pdo->prepare(
            "SELECT
                 il.invoice_id,
                 il.line_index,
                 il.ingredient_name,
                 il.description,
                 il.mi_id_fk,
                 rm.mi_id                     AS mi_code,
                 rm.name                      AS mi_name,
                 CAST(il.qty AS CHAR)         AS qty,
                 il.unit,
                 CAST(il.unit_price AS CHAR)  AS unit_price,
                 CAST(il.line_total AS CHAR)  AS line_total,
                 CAST(il.vat_rate AS CHAR)    AS vat_rate,
                 CAST(il.name_confidence AS CHAR)  AS name_confidence,
                 CAST(il.price_confidence AS CHAR) AS price_confidence,
                 il.accounting_class,
                 il.gate_failures
               FROM doc_invoice_lines il
               LEFT JOIN ref_mi rm ON rm.id = il.mi_id_fk
              WHERE il.invoice_id IN ($placeholders)
              ORDER BY il.invoice_id, il.line_index"
        );
        $lineStmt->execute($invoiceIds);
        $allLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        $linesByInvoice = [];
        foreach ($allLines as $line) {
            $linesByInvoice[(int)$line['invoice_id']][] = $line;
        }

        foreach ($headerRows as $row) {
            $invId = (int)$row['invoice_id'];
            $upId  = (int)$row['upload_id'];
            $lines = $linesByInvoice[$invId] ?? [];

            $plan = null;
            if ($row['delivery_write_plan'] !== null) {
                $decoded = json_decode((string)$row['delivery_write_plan'], true);
                if (is_array($decoded)) {
                    $plan = [
                        'gateDecision' => $decoded['header']['gateDecision'] ?? null,
                        'parserLabel'  => $decoded['header']['parserLabel']  ?? null,
                        'lineCount'    => count($decoded['lines'] ?? []),
                    ];
                }
            }

            $jsLines = [];
            foreach ($lines as $l) {
                $gateFailures    = $l['gate_failures'] !== null
                                   ? json_decode((string)$l['gate_failures'], true)
                                   : null;
                $hasGateFailures = is_array($gateFailures) && count($gateFailures) > 0;

                $jsLines[] = [
                    'lineIndex'       => (int)$l['line_index'],
                    'ingredientName'  => $l['ingredient_name'],
                    'description'     => $l['description'],
                    'miIdFk'          => $l['mi_id_fk'] !== null ? (int)$l['mi_id_fk'] : null,
                    'miDisplay'       => ($l['mi_code'] !== null)
                                        ? ($l['mi_code'] . ' — ' . $l['mi_name'])
                                        : null,
                    'qty'             => $l['qty'] !== null ? (float)$l['qty'] : null,
                    'unit'            => $l['unit'],
                    'unitPrice'       => $l['unit_price'] !== null ? (float)$l['unit_price'] : null,
                    'lineTotal'       => $l['line_total'] !== null ? (float)$l['line_total'] : null,
                    'vatRate'         => $l['vat_rate'] !== null ? (float)$l['vat_rate'] : null,
                    'nameConfidence'  => $l['name_confidence'] !== null ? (float)$l['name_confidence'] : null,
                    'priceConfidence' => $l['price_confidence'] !== null ? (float)$l['price_confidence'] : null,
                    'accountingClass' => $l['accounting_class'],
                    'hasGateFailures' => $hasGateFailures,
                    'gateFailures'    => $gateFailures,
                ];
            }

            $_ivInvoices[] = [
                'uploadId'              => $upId,
                'invoiceId'             => $invId,
                'supplierName'          => $row['supplier_name'],
                'invoiceRef'            => $row['invoice_ref'],
                'invoiceDate'           => $row['invoice_date'],
                'totalHt'               => $row['total_ht'] !== null ? (float)$row['total_ht'] : null,
                'currency'              => $row['currency'] ?? 'CHF',
                'parserName'            => $row['parser_name'],
                'originalFilename'      => $row['original_filename'],
                'lineCount'             => (int)$row['line_count'],
                'linesUnresolved'       => (int)$row['lines_unresolved'],
                'linesLowNameConf'      => (int)$row['lines_low_name_conf'],
                'linesLowPriceConf'     => (int)$row['lines_low_price_conf'],
                'linesWithGateFailures' => (int)$row['lines_with_gate_failures'],
                'plan'                  => $plan,
                'lines'                 => $jsLines,
            ];
        }
    }

} catch (Throwable $_ivEx) {
    $_ivDbError = htmlspecialchars($_ivEx->getMessage());
}

// ── HTML output ───────────────────────────────────────────────────────────────
?>

<?php if ($_ivDbError !== null): ?>
  <div class="iv-db-error" role="alert">
    Erreur base de données : <?= $_ivDbError ?>
  </div>
<?php endif ?>

<!-- Page header -->
<div class="iv-header">
  <div class="iv-header-left">
    <div class="iv-eyebrow">Pipeline factures · Validation</div>
    <?php /* Demote to h2 when embedded in triage (which already owns the page <h1>) — avoids duplicate top-level heading (WCAG 1.3.1) */ ?>
    <?php if (!empty($valider_embedded)): ?>
    <h2 class="iv-title">À <em>valider</em></h2>
    <?php else: ?>
    <h1 class="iv-title">À <em>valider</em></h1>
    <?php endif ?>
  </div>
  <div class="iv-header-right">
    <?php if (!empty($_ivInvoices)): ?>
      <button type="button" class="iv-btn-bulk" id="iv-btn-bulk-validate"
              aria-label="Valider toutes les factures listées">
        Tout valider
        <span class="iv-bulk-count">(<?= count($_ivInvoices) ?>)</span>
      </button>
    <?php endif ?>
  </div>
</div>

<!-- Status region (screen reader live) -->
<div class="sr-only" role="status" aria-live="polite" id="iv-sr-status"></div>

<?php if (empty($_ivInvoices) && $_ivDbError === null): ?>
  <!-- Empty state -->
  <div class="iv-empty" role="status">
    <div class="iv-empty-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>
    </div>
    <div class="iv-empty-title">Aucune facture en attente</div>
    <div class="iv-empty-sub">Les nouvelles factures parsées apparaîtront ici pour validation avant écriture en base.</div>
  </div>
<?php else: ?>

  <!-- Invoice card list -->
  <div class="iv-card-list" id="iv-card-list" role="list"
       aria-label="Factures à valider">
    <?php foreach ($_ivInvoices as $inv): ?>
      <?php
        $uploadId  = (int)$inv['uploadId'];
        $hasIssues = ($inv['linesUnresolved'] > 0)
                  || ($inv['linesLowNameConf'] > 0)
                  || ($inv['linesLowPriceConf'] > 0)
                  || ($inv['linesWithGateFailures'] > 0);
      ?>
      <article class="iv-card<?= $hasIssues ? ' iv-card--warn' : '' ?>"
               id="iv-card-<?= $uploadId ?>"
               data-upload-id="<?= $uploadId ?>"
               role="listitem"
               aria-label="Facture <?= htmlspecialchars((string)($inv['invoiceRef'] ?? $inv['originalFilename'] ?? '')) ?>">

        <!-- Card header row -->
        <div class="iv-card-header">
          <div class="iv-card-meta">
            <span class="iv-supplier"><?= htmlspecialchars((string)($inv['supplierName'] ?? '—')) ?></span>
            <?php if ($inv['invoiceRef']): ?>
              <span class="iv-ref"><?= htmlspecialchars((string)$inv['invoiceRef']) ?></span>
            <?php endif ?>
            <?php if ($inv['invoiceDate']): ?>
              <span class="iv-date"><?= htmlspecialchars((string)$inv['invoiceDate']) ?></span>
            <?php endif ?>
          </div>
          <div class="iv-card-total">
            <?php if ($inv['totalHt'] !== null): ?>
              <span class="iv-amount">
                <?= htmlspecialchars(
                  number_format((float)$inv['totalHt'], 2, ',', "\u{202F}")
                  . ' ' . ($inv['currency'] ?? 'CHF')
                ) ?> HT
              </span>
            <?php endif ?>
          </div>
        </div>

        <!-- Parser info + warning chips -->
        <div class="iv-card-chips">
          <?php if ($inv['parserName']): ?>
            <span class="iv-chip iv-chip--parser"><?= htmlspecialchars((string)$inv['parserName']) ?></span>
          <?php endif ?>
          <?php if ($inv['plan']['gateDecision'] ?? null): ?>
            <?php
              $gd = (string)$inv['plan']['gateDecision'];
              $gdClass = match(true) {
                  str_starts_with($gd, 'pass') => 'iv-chip--ok',
                  str_starts_with($gd, 'warn') => 'iv-chip--warn',
                  default                       => 'iv-chip--err',
              };
            ?>
            <span class="iv-chip <?= $gdClass ?>"><?= htmlspecialchars($gd) ?></span>
          <?php endif ?>
          <?php if ($inv['linesUnresolved'] > 0): ?>
            <span class="iv-chip iv-chip--err" title="Lignes sans MI résolu">
              <?= $inv['linesUnresolved'] ?> non résolu<?= $inv['linesUnresolved'] > 1 ? 's' : '' ?>
            </span>
          <?php endif ?>
          <?php if ($inv['linesLowNameConf'] > 0): ?>
            <span class="iv-chip iv-chip--warn" title="Confiance nom < 0.95">
              <?= $inv['linesLowNameConf'] ?> conf. faible
            </span>
          <?php endif ?>
          <?php if ($inv['linesWithGateFailures'] > 0): ?>
            <span class="iv-chip iv-chip--err" title="Lignes avec gate_failures">
              <?= $inv['linesWithGateFailures'] ?> gate failure<?= $inv['linesWithGateFailures'] > 1 ? 's' : '' ?>
            </span>
          <?php endif ?>
        </div>

        <?php
          // Reconciliation banner for parked-totals invoices
          $_ivGd = $inv['plan']['gateDecision'] ?? null;
          if (in_array($_ivGd, ['write_pending_all', 'write_pending_partial'], true)
              && $inv['lineCount'] > 0
          ) {
              // Σ(line_total) — sum over already-loaded lines; null line_total = skip
              $_ivLinesSum = 0.0;
              foreach ($inv['lines'] as $_ivL) {
                  if ($_ivL['lineTotal'] !== null) {
                      $_ivLinesSum += (float)$_ivL['lineTotal'];
                  }
              }
              $_ivHt    = $inv['totalHt'];   // canonical doc_invoices.total_ht
              $_ivDelta = $_ivHt !== null ? ($_ivLinesSum - $_ivHt) : null;
              $_ivErr   = $_ivDelta !== null && abs($_ivDelta) > 0.01;
              ?>
              <div class="iv-recon-banner<?= $_ivErr ? ' iv-recon-banner--mismatch' : '' ?>">
                <span class="iv-recon__label">Totaux à vérifier</span>
                <span class="iv-recon__item">
                  <span class="iv-recon__key">Σ lignes</span>
                  <span class="iv-recon__val"><?= htmlspecialchars(number_format($_ivLinesSum, 2, ',', "\u{202F}")) ?></span>
                </span>
                <span class="iv-recon__sep" aria-hidden="true">vs</span>
                <span class="iv-recon__item">
                  <span class="iv-recon__key">Total HT</span>
                  <span class="iv-recon__val"><?= $_ivHt !== null ? htmlspecialchars(number_format($_ivHt, 2, ',', "\u{202F}")) : '—' ?></span>
                </span>
                <?php if ($_ivDelta !== null): ?>
                  <span class="iv-recon__delta<?= $_ivErr ? ' iv-recon__delta--err' : ' iv-recon__delta--ok' ?>">
                    <?= htmlspecialchars(($_ivDelta >= 0 ? '+' : '') . number_format($_ivDelta, 2, ',', "\u{202F}")) ?>
                  </span>
                <?php endif ?>
              </div>
              <?php
          }
        ?>

        <!-- Per-line recap table -->
        <div class="iv-lines-wrap">
          <table class="iv-lines" aria-label="Lignes de la facture">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">MI</th>
                <th scope="col">Ingrédient / description</th>
                <th scope="col" class="iv-col-num">Qté</th>
                <th scope="col" class="iv-col-num">PU</th>
                <th scope="col" class="iv-col-num">Total</th>
                <th scope="col">Flags</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inv['lines'] as $line): ?>
                <?php
                  $lineWarn = ($line['miIdFk'] === null && $line['accountingClass'] === null)
                           || ($line['nameConfidence'] !== null && $line['nameConfidence'] < 0.95)
                           || ($line['priceConfidence'] !== null && $line['priceConfidence'] < 0.95)
                           || $line['hasGateFailures'];
                ?>
                <tr class="<?= $lineWarn ? 'iv-line--warn' : '' ?>">
                  <td class="iv-col-idx"><?= (int)$line['lineIndex'] ?></td>
                  <td class="iv-col-mi">
                    <?php if ($line['miDisplay'] !== null): ?>
                      <code class="iv-mi-code"><?= htmlspecialchars((string)$line['miDisplay']) ?></code>
                    <?php elseif ($line['accountingClass'] !== null): ?>
                      <span class="iv-accounting-class"><?= htmlspecialchars((string)$line['accountingClass']) ?></span>
                    <?php else: ?>
                      <span class="iv-unresolved" title="MI non résolu">⚠ non résolu</span>
                    <?php endif ?>
                  </td>
                  <td class="iv-col-desc">
                    <?php
                      $desc = $line['ingredientName'] ?? $line['description'] ?? '';
                      echo htmlspecialchars((string)$desc);
                    ?>
                  </td>
                  <td class="iv-col-num">
                    <?php if ($line['qty'] !== null): ?>
                      <?= htmlspecialchars(rtrim(rtrim(number_format((float)$line['qty'], 4, ',', ''), '0'), ',')) ?>
                      <?= $line['unit'] ? ' ' . htmlspecialchars((string)$line['unit']) : '' ?>
                    <?php else: ?>—<?php endif ?>
                  </td>
                  <td class="iv-col-num">
                    <?php if ($line['unitPrice'] !== null): ?>
                      <?= htmlspecialchars(number_format((float)$line['unitPrice'], 4, ',', "\u{202F}")) ?>
                    <?php else: ?>—<?php endif ?>
                  </td>
                  <td class="iv-col-num">
                    <?php if ($line['lineTotal'] !== null): ?>
                      <?= htmlspecialchars(number_format((float)$line['lineTotal'], 2, ',', "\u{202F}")) ?>
                    <?php else: ?>—<?php endif ?>
                  </td>
                  <td class="iv-col-flags">
                    <?php if ($line['nameConfidence'] !== null && $line['nameConfidence'] < 0.95): ?>
                      <span class="iv-flag iv-flag--warn" title="Confiance nom: <?= number_format((float)$line['nameConfidence'], 2) ?>">N</span>
                    <?php endif ?>
                    <?php if ($line['priceConfidence'] !== null && $line['priceConfidence'] < 0.95): ?>
                      <span class="iv-flag iv-flag--warn" title="Confiance prix: <?= number_format((float)$line['priceConfidence'], 2) ?>">P</span>
                    <?php endif ?>
                    <?php if ($line['hasGateFailures']): ?>
                      <?php
                        $gfArr = is_array($line['gateFailures']) ? $line['gateFailures'] : [];
                        $gfTip = implode(', ', array_keys($gfArr));
                      ?>
                      <span class="iv-flag iv-flag--err" title="Gate failures: <?= htmlspecialchars($gfTip) ?>">G</span>
                    <?php endif ?>
                    <?php if ($line['miIdFk'] === null && $line['accountingClass'] === null): ?>
                      <span class="iv-flag iv-flag--err" title="MI non résolu">?</span>
                    <?php endif ?>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>

        <!-- Card actions -->
        <div class="iv-card-actions">
          <button type="button"
                  class="iv-btn iv-btn-validate"
                  data-upload-id="<?= $uploadId ?>"
                  aria-label="Valider cette facture et écrire en base">
            ✓ Valider
          </button>
          <button type="button"
                  class="iv-btn iv-btn-reject"
                  data-upload-id="<?= $uploadId ?>"
                  aria-label="Refuser cette facture">
            ✗ Refuser
          </button>
        </div>

        <!-- Per-card state overlay (spinner / result) — hidden initially -->
        <div class="iv-card-overlay" id="iv-overlay-<?= $uploadId ?>" hidden aria-live="polite">
          <div class="iv-overlay-inner"></div>
        </div>

      </article>
    <?php endforeach ?>
  </div><!-- /.iv-card-list -->

<?php endif ?>

<!-- Reject reason modal — rendered once per page; JS looks for id="iv-reject-dialog" -->
<dialog class="iv-reject-dialog" id="iv-reject-dialog" aria-labelledby="iv-reject-dialog-title">
  <div class="iv-reject-dialog-inner">
    <h2 class="iv-reject-dialog-title" id="iv-reject-dialog-title">Refuser la facture</h2>
    <p class="iv-reject-dialog-desc">La facture sera marquée refusée et un signal d'amélioration du parser sera envoyé au fil de triage.</p>
    <div class="iv-reject-field">
      <label for="iv-reject-reason">Raison (optionnelle)</label>
      <input type="text" id="iv-reject-reason" class="iv-reject-reason-input"
             placeholder="Ex : mauvais fournisseur, ligne manquante…"
             maxlength="128" autocomplete="off">
    </div>
    <div class="iv-reject-dialog-actions">
      <button type="button" class="iv-btn iv-btn-reject-confirm" id="iv-reject-confirm">
        ✗ Confirmer le refus
      </button>
      <button type="button" class="iv-btn iv-btn-cancel" id="iv-reject-cancel">
        Annuler
      </button>
    </div>
  </div>
</dialog>

<!-- JS globals for invoice-validate.js — must precede the script include -->
<script>
window.IV_CSRF  = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.IV_COUNT = <?= count($_ivInvoices) ?>;
</script>
