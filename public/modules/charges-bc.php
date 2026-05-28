<?php
declare(strict_types=1);
/**
 * /modules/charges-bc.php — ChargesBC CSV upload (Le Cockpit / admin).
 *
 * Bookkeeper uploads the Business Central GL journal export (CSV) here each
 * month. Supersedes the BSF ChargesBC tab paste workflow (BSF-exit Phase 7).
 *
 * Layout:
 *   1. Upload zone  — CSV file input + Téléverser button (POST → dry-run first,
 *                     then confirm commit).
 *   2. Recent uploads — last 20 upload events from audit_row_revisions.
 *   3. Summary panel  — current / selected month inv_charges_bc totals by GL.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

require_admin();
$me = current_user();

$active_module = 'charges-bc';

// ── Month selector (default = current month) ──────────────────────────────────
$rawMonth = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $rawMonth)) {
    $rawMonth = date('Y-m');
}
$selectedMonth = $rawMonth;                        // safe: validated above

// ── DB ────────────────────────────────────────────────────────────────────────
$pdo = maltytask_pdo();

// ── Summary: GL totals for the selected month ─────────────────────────────────
$summaryRows = [];
try {
    $stmt = $pdo->prepare(
        "SELECT gl_account_no, ANY_VALUE(gl_account_name) AS gl_account_name,
                SUM(debit_amount)  AS total_debit,
                SUM(credit_amount) AS total_credit,
                COUNT(*) AS row_count
           FROM inv_charges_bc
          WHERE period_text = :period
            AND is_summary = 0
          GROUP BY gl_account_no
          ORDER BY gl_account_no"
    );
    $stmt->execute([':period' => $selectedMonth]);
    $summaryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Degrade gracefully — show empty summary with a warning
    $summaryRows = [];
    $summaryError = htmlspecialchars($e->getMessage());
}

// ── Recent uploads from audit_row_revisions ────────────────────────────────────
$recentUploads = [];
try {
    // Upload-event sentinels are flagged by target_pk=0 on inv_charges_bc rows
    $uploadStmt = $pdo->prepare(
        "SELECT id, username, created_at, comment
           FROM audit_row_revisions
          WHERE target_table = 'inv_charges_bc'
            AND action       = 'insert'
            AND target_pk    = 0
          ORDER BY created_at DESC
          LIMIT 20"
    );
    $uploadStmt->execute();
    $recentUploads = $uploadStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // upload rows may not exist yet — degrade silently
    $recentUploads = [];
}

// ── Available months for the month selector ────────────────────────────────────
$availableMonths = [];
try {
    $mStmt = $pdo->query(
        "SELECT DISTINCT period_text FROM inv_charges_bc
          WHERE period_text REGEXP '^[0-9]{4}-[0-9]{2}$'
          ORDER BY period_text DESC
          LIMIT 48"
    );
    foreach ($mStmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $availableMonths[] = $m;
    }
} catch (Throwable $e) {
    // empty is fine
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChargesBC — MaltyTask</title>
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/charges-bc.css?v=<?= @filemtime(__DIR__ . '/../../public/css/charges-bc.css') ?: time() ?>">
</head>
<body class="home charges-bc">
  <?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

  <main class="main">
    <div class="cbc-wrap">

      <!-- ── Page header ──────────────────────────────────────────────────── -->
      <header class="cbc-page-head">
        <h1 class="cbc-title">
          <span class="cbc-title__label">ChargesBC</span>
          <span class="cbc-title__sub">Journal général Business Central</span>
        </h1>
        <p class="cbc-desc">
          Téléversez l'export CSV mensuel de Business Central.
          Les lignes déjà présentes (même <code>row_hash</code>) sont ignorées.
        </p>
      </header>

      <!-- ── Upload zone ──────────────────────────────────────────────────── -->
      <section class="cbc-section cbc-upload-section" aria-labelledby="cbc-upload-heading">
        <h2 id="cbc-upload-heading" class="cbc-section-title">Téléverser un export</h2>

        <div class="cbc-upload-card" id="cbc-upload-card">
          <form class="cbc-upload-form" id="cbc-upload-form"
                method="post" enctype="multipart/form-data"
                action="/api/charges-bc-upload.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="confirm" value="0" id="cbc-confirm-flag">

            <div class="cbc-dropzone" id="cbc-dropzone" role="region" aria-label="Zone de dépôt de fichier">
              <svg class="cbc-dropzone__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <p class="cbc-dropzone__text">Glissez un fichier CSV ici ou</p>
              <label class="cbc-dropzone__btn" for="cbc-file-input">
                Choisir un fichier
              </label>
              <input type="file" id="cbc-file-input" name="csv_file"
                     accept=".csv,text/csv" class="cbc-file-hidden">
              <p class="cbc-dropzone__hint" id="cbc-file-hint">Aucun fichier sélectionné</p>
            </div>

            <div class="cbc-upload-actions">
              <button type="submit" class="cbc-btn cbc-btn--primary" id="cbc-upload-btn" disabled>
                <span class="cbc-btn__icon" aria-hidden="true">↑</span>
                Prévisualiser
              </button>
            </div>
          </form>
        </div>

        <!-- Preview panel (shown after dry-run response) -->
        <div class="cbc-preview-panel" id="cbc-preview-panel" hidden>
          <div class="cbc-preview-head">
            <h3 class="cbc-preview-title" id="cbc-preview-title">Prévisualisation</h3>
            <button class="cbc-preview-close" id="cbc-preview-close" aria-label="Fermer la prévisualisation">✕</button>
          </div>
          <div class="cbc-preview-stats" id="cbc-preview-stats"></div>
          <div class="cbc-preview-table-wrap" id="cbc-preview-table-wrap"></div>
          <div class="cbc-preview-errors" id="cbc-preview-errors" hidden></div>
          <div class="cbc-preview-actions">
            <button class="cbc-btn cbc-btn--commit" id="cbc-commit-btn" disabled>
              Confirmer l'import
            </button>
            <button class="cbc-btn cbc-btn--cancel" id="cbc-cancel-btn">
              Annuler
            </button>
          </div>
        </div>

        <!-- Result notice -->
        <div class="cbc-result" id="cbc-result" hidden role="status"></div>
      </section>

      <!-- ── Summary panel ────────────────────────────────────────────────── -->
      <section class="cbc-section" aria-labelledby="cbc-summary-heading">
        <div class="cbc-section-header">
          <h2 id="cbc-summary-heading" class="cbc-section-title">Totaux par compte GL</h2>
          <form class="cbc-month-form" method="get" action="">
            <label class="cbc-month-label" for="cbc-month-select">Période :</label>
            <select id="cbc-month-select" name="month" class="cbc-month-select"
                    onchange="this.form.submit()">
              <?php if (!in_array($selectedMonth, $availableMonths, true)): ?>
                <option value="<?= htmlspecialchars($selectedMonth) ?>" selected>
                  <?= htmlspecialchars($selectedMonth) ?>
                </option>
              <?php endif ?>
              <?php foreach ($availableMonths as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>"
                        <?= $m === $selectedMonth ? 'selected' : '' ?>>
                  <?= htmlspecialchars($m) ?>
                </option>
              <?php endforeach ?>
            </select>
          </form>
        </div>

        <?php if (!empty($summaryError ?? null)): ?>
          <p class="cbc-db-error">Erreur base de données : <?= $summaryError ?></p>
        <?php elseif (empty($summaryRows)): ?>
          <p class="cbc-empty">Aucune donnée transactionnelle pour la période <strong><?= htmlspecialchars($selectedMonth) ?></strong>.</p>
        <?php else: ?>
          <div class="cbc-summary-table-wrap">
            <table class="cbc-summary-table" role="grid">
              <thead>
                <tr>
                  <th scope="col">N° GL</th>
                  <th scope="col">Libellé</th>
                  <th scope="col" class="num">Débit (CHF)</th>
                  <th scope="col" class="num">Crédit (CHF)</th>
                  <th scope="col" class="num">Lignes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($summaryRows as $r): ?>
                  <tr>
                    <td class="cbc-gl-no"><?= htmlspecialchars((string)($r['gl_account_no'] ?? '')) ?></td>
                    <td class="cbc-gl-name"><?= htmlspecialchars((string)($r['gl_account_name'] ?? '')) ?></td>
                    <td class="num"><?= number_format((float)($r['total_debit']  ?? 0), 2, '.', "'") ?></td>
                    <td class="num"><?= number_format((float)($r['total_credit'] ?? 0), 2, '.', "'") ?></td>
                    <td class="num"><?= (int)($r['row_count'] ?? 0) ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

      <!-- ── Recent uploads ────────────────────────────────────────────────── -->
      <section class="cbc-section" aria-labelledby="cbc-history-heading">
        <h2 id="cbc-history-heading" class="cbc-section-title">Historique des imports</h2>

        <?php if (empty($recentUploads)): ?>
          <p class="cbc-empty">Aucun import enregistré.</p>
        <?php else: ?>
          <div class="cbc-history-table-wrap">
            <table class="cbc-history-table" role="grid">
              <thead>
                <tr>
                  <th scope="col">Date</th>
                  <th scope="col">Utilisateur</th>
                  <th scope="col">Détails</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentUploads as $u): ?>
                  <tr>
                    <td class="cbc-ts"><?= htmlspecialchars((string)($u['created_at'] ?? '')) ?></td>
                    <td class="cbc-user"><?= htmlspecialchars((string)($u['username'] ?? '')) ?></td>
                    <td class="cbc-comment"><?= htmlspecialchars((string)($u['comment'] ?? '')) ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </section>

    </div><!-- /.cbc-wrap -->
  </main>

  <script src="/js/charges-bc.js?v=<?= @filemtime(__DIR__ . '/../../public/js/charges-bc.js') ?: time() ?>"></script>
</body>
</html>
