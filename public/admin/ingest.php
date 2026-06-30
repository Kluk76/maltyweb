<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/csrf.php";

require_admin();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "ingest";
$crumbs        = ["Accueil", "Admin", "Ingest"];

// ── Pagination ────────────────────────────────────────────────────────────────
const INGEST_FAIL_PAGE_SIZE = 50;
const INGEST_RUN_PAGE_SIZE  = 20;

$failPage   = max(0, (int) ($_GET["fp"] ?? 0));
$failOffset = $failPage * INGEST_FAIL_PAGE_SIZE;

$runPage    = max(0, (int) ($_GET["rp"] ?? 0));
$runOffset  = $runPage * INGEST_RUN_PAGE_SIZE;

// ── Flash ─────────────────────────────────────────────────────────────────────
$flashMsg  = null;
$flashType = "ok";

if (($_GET["rerun"] ?? "") === "started") {
    $flashMsg  = "Re-run lancé en arrière-plan.";
    $flashType = "ok";
} elseif (($_GET["rerun"] ?? "") === "fail") {
    $flashMsg  = "Impossible de lancer le re-run.";
    $flashType = "err";
}

// ── DB queries ────────────────────────────────────────────────────────────────
$dbError  = null;
$latestRun = null;
$failRows  = [];
$failTotal = 0;
$runRows   = [];
$runTotal  = 0;

// run_id filter (show failures for a specific run)
$filterRunId = isset($_GET["run_id"]) && ctype_digit($_GET["run_id"])
    ? (int) $_GET["run_id"]
    : null;

try {
    $pdo = maltytask_pdo();

    // ── Latest run ────────────────────────────────────────────────────────────
    $latestRun = $pdo->query(
        "SELECT ir.*,
                TIMESTAMPDIFF(SECOND, ir.started_at, COALESCE(ir.finished_at, NOW())) AS duration_sec,
                TIMESTAMPDIFF(SECOND, ir.started_at, NOW()) AS age_sec,
                (SELECT COUNT(*) FROM ingest_failures f WHERE f.run_id = ir.id) AS failure_count
           FROM ingest_runs ir
          ORDER BY ir.started_at DESC
          LIMIT 1"
    )->fetch() ?: null;

    // ── Recent failures (last 50, optionally filtered by run) ─────────────────
    if ($filterRunId !== null) {
        $whereClause = "WHERE f.run_id = :run_id";
        $failParams  = [":run_id" => $filterRunId];
    } else {
        $whereClause = "";
        $failParams  = [];
    }

    $cntStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ingest_failures f $whereClause"
    );
    $cntStmt->execute($failParams);
    $failTotal = (int) $cntStmt->fetchColumn();

    $failStmt = $pdo->prepare(
        "SELECT f.id, f.run_id, f.source_tab, f.target_table,
                f.sheet_row_index, f.row_hash, f.reason_code,
                f.reason_text, f.raw_row, f.detected_at, f.last_seen_at,
                f.resolved_at, f.resolution_note
           FROM ingest_failures f
          $whereClause
          ORDER BY f.detected_at DESC
          LIMIT " . INGEST_FAIL_PAGE_SIZE . " OFFSET $failOffset"
    );
    $failStmt->execute($failParams);
    $failRows = $failStmt->fetchAll();

    // ── Run history (last 20) ─────────────────────────────────────────────────
    $runTotal = (int) $pdo->query("SELECT COUNT(*) FROM ingest_runs")->fetchColumn();
    $runStmt  = $pdo->prepare(
        "SELECT ir.*,
                TIMESTAMPDIFF(SECOND, ir.started_at, COALESCE(ir.finished_at, NOW())) AS duration_sec,
                (SELECT COUNT(*) FROM ingest_failures f WHERE f.run_id = ir.id) AS failure_count
           FROM ingest_runs ir
          ORDER BY ir.started_at DESC
          LIMIT " . INGEST_RUN_PAGE_SIZE . " OFFSET $runOffset"
    );
    $runStmt->execute();
    $runRows = $runStmt->fetchAll();

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function ia_status_pill(string $status): string
{
    $map = [
        'ok'      => ['ok',      'OK'],
        'partial' => ['partial', 'PARTIEL'],
        'failed'  => ['failed',  'ERREUR'],
        'running' => ['running', 'EN COURS'],
    ];
    [$cls, $label] = $map[$status] ?? ['unknown', strtoupper($status)];
    return '<span class="ia-pill ia-pill--' . htmlspecialchars($cls) . '">' . $label . '</span>';
}

function ia_duration(int $sec): string
{
    if ($sec < 60)  return "{$sec}s";
    $m = (int)($sec / 60);
    $s = $sec % 60;
    return "{$m}m {$s}s";
}

function ia_age(int $ageSeconds): string
{
    if ($ageSeconds < 60)    return "il y a " . $ageSeconds . "s";
    if ($ageSeconds < 3600)  return "il y a " . (int)($ageSeconds / 60) . "m";
    if ($ageSeconds < 86400) return "il y a " . (int)($ageSeconds / 3600) . "h";
    return "il y a " . (int)($ageSeconds / 86400) . "j";
}

function ia_summary_table(?string $jsonStr): string
{
    if ($jsonStr === null || $jsonStr === '') return '<span class="ia-muted">—</span>';
    $data = json_decode($jsonStr, true);
    if (!is_array($data)) return '<span class="ia-muted">—</span>';

    // 'updated' replaced 'duplicates' after migration 046b (UPSERT pattern).
    // Legacy run rows in summary_json may still carry 'duplicates' — fall back
    // gracefully so old run history remains readable.
    $cols = ['fetched','parsed','inserted','updated','failed'];
    $out  = '<table class="ia-summary-table">';
    $out .= '<thead><tr><th>tab</th>';
    foreach ($cols as $c) $out .= '<th>' . htmlspecialchars($c) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($data as $tab => $counts) {
        $out .= '<tr><td class="ia-summary-tab">' . htmlspecialchars($tab) . '</td>';
        foreach ($cols as $c) {
            // Backward-compat: if 'updated' is absent, try 'duplicates' (pre-046b runs).
            if ($c === 'updated' && !array_key_exists('updated', $counts) && array_key_exists('duplicates', $counts)) {
                $v = (int)$counts['duplicates'];
            } else {
                $v = (int)($counts[$c] ?? 0);
            }
            $cls = ($c === 'failed' && $v > 0) ? ' class="ia-cell--bad"' : '';
            $out .= "<td{$cls}>" . $v . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table>';
    return $out;
}

function ia_qs(array $extra): string
{
    $base = [];
    foreach (['fp', 'rp', 'run_id'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    }
    return '?' . http_build_query(array_merge($base, $extra));
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingest — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/admin-ingest.css?v=<?= @filemtime(__DIR__ . '/../css/admin-ingest.css') ?: time() ?>">
</head>
<body class="home admin ingest-page">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>
<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main ia-main">

  <!-- ── Page header ── -->
  <header class="ia-head">
    <div class="ia-head__left">
      <span class="ia-head__eyebrow">— admin · ingest</span>
      <h1 class="ia-head__title">Observabilité BSF → MySQL</h1>
    </div>
    <?php if (is_admin($me)): ?>
    <div class="ia-head__right">
      <a class="ia-rerun-btn" href="/admin/ingest/run.php?csrf=<?= htmlspecialchars(csrf_token()) ?>">
        Relancer l'ingest
      </a>
    </div>
    <?php else: ?>
    <div class="ia-head__right">
      <span class="ia-rerun-note">Exécuter via SSH pour relancer manuellement.</span>
    </div>
    <?php endif ?>
  </header>

  <?php if ($flashMsg !== null): ?>
    <div class="db-flash db-flash--<?= $flashType === "ok" ? "ok" : "err" ?>">
      <span class="db-flash__msg"><?= htmlspecialchars($flashMsg) ?></span>
    </div>
  <?php endif ?>

  <?php if ($dbError !== null): ?>
    <div class="db-flash db-flash--err">
      <span class="db-flash__msg">Erreur DB : <?= htmlspecialchars($dbError) ?></span>
    </div>
  <?php else: ?>

  <!-- ══ Section 1: Latest run stripe ══ -->
  <section class="ia-section">
    <div class="ia-section__head">
      <span class="ia-section__label">Dernier run</span>
    </div>

    <?php if ($latestRun === null): ?>
      <div class="ia-empty">Aucune exécution enregistrée.</div>
    <?php else:
      $lr = $latestRun;
      $finishedAt = $lr['finished_at'] ? display_local($lr['finished_at'], 'd/m/Y H:i:s') : '—';
      $startedAt  = display_local($lr['started_at'], 'd/m/Y H:i:s');
      $dur        = ia_duration((int)$lr['duration_sec']);
    ?>
    <div class="ia-run-stripe">
      <div class="ia-run-stripe__kpis">
        <div class="ia-kpi">
          <span class="ia-kpi__label">Statut</span>
          <span class="ia-kpi__val"><?= ia_status_pill($lr['status']) ?></span>
        </div>
        <div class="ia-kpi">
          <span class="ia-kpi__label">Démarré</span>
          <span class="ia-kpi__val ia-mono"><?= htmlspecialchars($startedAt) ?></span>
        </div>
        <div class="ia-kpi">
          <span class="ia-kpi__label">Terminé</span>
          <span class="ia-kpi__val ia-mono"><?= htmlspecialchars($finishedAt) ?></span>
        </div>
        <div class="ia-kpi">
          <span class="ia-kpi__label">Durée</span>
          <span class="ia-kpi__val ia-mono"><?= htmlspecialchars($dur) ?></span>
        </div>
        <div class="ia-kpi">
          <span class="ia-kpi__label">Source</span>
          <span class="ia-kpi__val ia-mono"><?= htmlspecialchars($lr['trigger_source']) ?></span>
        </div>
        <div class="ia-kpi">
          <span class="ia-kpi__label">Rejets</span>
          <span class="ia-kpi__val <?= ((int)$lr['failure_count'] > 0) ? 'ia-bad' : 'ia-ok' ?>">
            <?= (int)$lr['failure_count'] ?>
          </span>
        </div>
      </div>

      <?php if ($lr['error_message']): ?>
      <div class="ia-run-error">
        <span class="ia-run-error__label">Erreur fatale :</span>
        <?= htmlspecialchars($lr['error_message']) ?>
      </div>
      <?php endif ?>

      <div class="ia-run-stripe__summary">
        <span class="ia-section__label">Compteurs par tab</span>
        <?= ia_summary_table($lr['summary_json']) ?>
      </div>
    </div>
    <?php endif ?>
  </section>

  <!-- ══ Section 2: Recent failures ══ -->
  <section class="ia-section">
    <div class="ia-section__head">
      <span class="ia-section__label">
        Rejets récents
        <?php if ($filterRunId !== null): ?>
          <span class="ia-section__filter-tag">run #<?= $filterRunId ?>
            <a href="/admin/ingest.php" class="ia-section__filter-clear" title="Effacer le filtre">×</a>
          </span>
        <?php endif ?>
      </span>
      <span class="ia-section__count"><?= $failTotal ?> total</span>
    </div>

    <?php if (empty($failRows)): ?>
      <div class="ia-empty">Aucun rejet<?= $filterRunId !== null ? " pour ce run" : "" ?>.</div>
    <?php else: ?>
    <div class="ia-table-wrap">
      <table class="ia-table">
        <thead>
          <tr>
            <th>Run</th>
            <th>Tab</th>
            <th>BSF row</th>
            <th>Code</th>
            <th>Message</th>
            <th>Détecté</th>
            <th>Données</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($failRows as $f): ?>
          <?php
            $truncMsg = mb_strlen($f['reason_text']) > 200
              ? mb_substr($f['reason_text'], 0, 200) . '…'
              : $f['reason_text'];
            $rowClass = $f['resolved_at'] ? ' ia-tr--resolved' : '';
          ?>
          <tr class="ia-tr<?= $rowClass ?>">
            <td class="ia-td ia-mono">
              <?php if ($f['run_id']): ?>
                <a href="<?= ia_qs(['run_id' => $f['run_id'], 'fp' => 0]) ?>">#<?= (int)$f['run_id'] ?></a>
              <?php else: ?>
                <span class="ia-muted">—</span>
              <?php endif ?>
            </td>
            <td class="ia-td ia-mono"><?= htmlspecialchars($f['source_tab'] ?? '—') ?></td>
            <td class="ia-td ia-mono"><?= $f['sheet_row_index'] ? (int)$f['sheet_row_index'] : '<span class="ia-muted">—</span>' ?></td>
            <td class="ia-td ia-mono"><?= htmlspecialchars($f['reason_code'] ?? '—') ?></td>
            <td class="ia-td ia-msg" title="<?= htmlspecialchars($f['reason_text']) ?>">
              <?= htmlspecialchars($truncMsg) ?>
            </td>
            <td class="ia-td ia-mono ia-nowrap"><?= htmlspecialchars(display_local($f['detected_at'], 'd/m H:i')) ?></td>
            <td class="ia-td">
              <?php if ($f['raw_row']): ?>
              <button class="ia-raw-btn"
                      data-raw="<?= htmlspecialchars($f['raw_row'], ENT_QUOTES) ?>"
                      aria-label="Voir données brutes">JSON</button>
              <?php else: ?>
              <span class="ia-muted">—</span>
              <?php endif ?>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($failTotal > INGEST_FAIL_PAGE_SIZE): ?>
    <div class="db-pagination">
      <?php if ($failPage > 0): ?>
        <a class="db-pagination__link" href="<?= ia_qs(['fp' => $failPage - 1]) ?>">← préc.</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← préc.</span>
      <?php endif ?>
      <span class="db-pagination__pos">
        <?= $failPage * INGEST_FAIL_PAGE_SIZE + 1 ?>–<?= min(($failPage + 1) * INGEST_FAIL_PAGE_SIZE, $failTotal) ?>
        / <?= $failTotal ?>
      </span>
      <?php $failLastPage = (int)floor(($failTotal - 1) / INGEST_FAIL_PAGE_SIZE); ?>
      <?php if ($failPage < $failLastPage): ?>
        <a class="db-pagination__link" href="<?= ia_qs(['fp' => $failPage + 1]) ?>">suiv. →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suiv. →</span>
      <?php endif ?>
    </div>
    <?php endif ?>
    <?php endif ?>
  </section>

  <!-- ══ Section 3: Run history ══ -->
  <section class="ia-section">
    <div class="ia-section__head">
      <span class="ia-section__label">Historique des runs</span>
      <span class="ia-section__count"><?= $runTotal ?> total</span>
    </div>

    <?php if (empty($runRows)): ?>
      <div class="ia-empty">Aucun run enregistré.</div>
    <?php else: ?>
    <div class="ia-table-wrap">
      <table class="ia-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Démarré</th>
            <th>Durée</th>
            <th>Source</th>
            <th>Statut</th>
            <th>Rejets</th>
            <th>Compteurs</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($runRows as $r): ?>
          <tr class="ia-tr ia-tr--run">
            <td class="ia-td ia-mono">
              <a href="<?= ia_qs(['run_id' => $r['id'], 'fp' => 0]) ?>">#<?= (int)$r['id'] ?></a>
            </td>
            <td class="ia-td ia-mono ia-nowrap"><?= htmlspecialchars(display_local($r['started_at'], 'd/m/Y H:i')) ?></td>
            <td class="ia-td ia-mono"><?= ia_duration((int)$r['duration_sec']) ?></td>
            <td class="ia-td ia-mono"><?= htmlspecialchars($r['trigger_source']) ?></td>
            <td class="ia-td"><?= ia_status_pill($r['status']) ?></td>
            <td class="ia-td ia-mono <?= ((int)$r['failure_count'] > 0) ? 'ia-bad' : 'ia-ok' ?>">
              <?= (int)$r['failure_count'] ?>
            </td>
            <td class="ia-td ia-summary-cell">
              <details class="ia-details">
                <summary class="ia-details__summary">voir</summary>
                <div class="ia-details__body">
                  <?= ia_summary_table($r['summary_json']) ?>
                </div>
              </details>
            </td>
          </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($runTotal > INGEST_RUN_PAGE_SIZE): ?>
    <div class="db-pagination">
      <?php if ($runPage > 0): ?>
        <a class="db-pagination__link" href="<?= ia_qs(['rp' => $runPage - 1]) ?>">← préc.</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← préc.</span>
      <?php endif ?>
      <span class="db-pagination__pos">
        <?= $runPage * INGEST_RUN_PAGE_SIZE + 1 ?>–<?= min(($runPage + 1) * INGEST_RUN_PAGE_SIZE, $runTotal) ?>
        / <?= $runTotal ?>
      </span>
      <?php $runLastPage = (int)floor(($runTotal - 1) / INGEST_RUN_PAGE_SIZE); ?>
      <?php if ($runPage < $runLastPage): ?>
        <a class="db-pagination__link" href="<?= ia_qs(['rp' => $runPage + 1]) ?>">suiv. →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suiv. →</span>
      <?php endif ?>
    </div>
    <?php endif ?>
    <?php endif ?>
  </section>

  <?php endif /* !$dbError */ ?>

</main>

<!-- ── Raw row modal ── -->
<div class="ia-modal" id="ia-modal" hidden aria-modal="true" role="dialog" aria-label="Données brutes">
  <div class="ia-modal__backdrop" id="ia-modal-backdrop"></div>
  <div class="ia-modal__box">
    <div class="ia-modal__head">
      <span class="ia-modal__title">Données brutes</span>
      <button class="ia-modal__close" id="ia-modal-close" aria-label="Fermer">×</button>
    </div>
    <pre class="ia-modal__body" id="ia-modal-body"></pre>
  </div>
</div>

<script>
(function () {
  // Raw row modal
  const modal    = document.getElementById('ia-modal');
  const body     = document.getElementById('ia-modal-body');
  const closeBtn = document.getElementById('ia-modal-close');
  const backdrop = document.getElementById('ia-modal-backdrop');

  document.querySelectorAll('.ia-raw-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      try {
        var raw  = JSON.parse(btn.getAttribute('data-raw'));
        body.textContent = JSON.stringify(raw, null, 2);
      } catch (e) {
        body.textContent = btn.getAttribute('data-raw');
      }
      modal.hidden = false;
      closeBtn.focus();
    });
  });

  function closeModal() { modal.hidden = true; body.textContent = ''; }

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
</script>

</body>
</html>
