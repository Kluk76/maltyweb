<?php
declare(strict_types=1);

/**
 * triage_form_ingest_partial.php
 *
 * Reusable partial: Ingest Failures KPI bar + filter form + paginated table.
 * Included by:
 *   — /public/admin/ingest-failures.php (standalone page, light admin surface)
 *   — /public/modules/triage.php Form-ingest tab (dark triage surface)
 *
 * REQUIREMENTS for includer:
 *   - $pdo must be available, or the partial opens its own connection.
 *   - csrf_token() must be callable (app/csrf.php required by includer).
 *   - The POST resolve handler lives in /admin/ingest-failures.php — this
 *     partial always POSTs there, regardless of inclusion context.
 *
 * Outputs HTML only (no <html>/<body> wrapper — partial fragment).
 */

// ── POST redirect handled by /admin/ingest-failures.php (canonical handler) ──
// Nothing to do here on POST.

// ── Ensure we have a PDO connection ───────────────────────────────────────────
if (!isset($pdo)) {
    try {
        $pdo = maltytask_pdo();
    } catch (Throwable $e) {
        echo '<div class="db-flash db-flash--err">Erreur DB (partial) : '
             . htmlspecialchars($e->getMessage()) . '</div>';
        return;
    }
}

// ── Flash from redirect (resolve action) ─────────────────────────────────────
$ifFlash     = null;
$ifFlashType = "ok";
if (($_GET["resolved"] ?? "") === "1") {
    $ifFlash     = "Failure marquée résolue.";
    $ifFlashType = "ok";
}

// ── Filters ───────────────────────────────────────────────────────────────────
$ifFilterTab    = $_GET["source_tab"] ?? "";
$ifFilterStatus = $_GET["status"]     ?? "unresolved"; // unresolved | all | resolved
$ifFilterQ      = trim($_GET["q"]     ?? "");
if (!in_array($ifFilterStatus, ["unresolved", "all", "resolved"], true)) {
    $ifFilterStatus = "unresolved";
}

// ── Query ─────────────────────────────────────────────────────────────────────
$ifDbError   = null;
$ifRows      = [];
$ifKpi       = ["total_unresolved" => 0, "by_tab" => []];
$ifAllTabs   = [];

if (!defined('IF_PAGE_SIZE')) {
    define('IF_PAGE_SIZE', 50);
}
$ifPage   = max(0, (int) ($_GET["page"] ?? 0));
$ifOffset = $ifPage * IF_PAGE_SIZE;
$ifTotal  = 0;

try {
    // KPI: total unresolved + per-tab breakdown
    $kpiStmt = $pdo->query(
        "SELECT source_tab, COUNT(*) AS cnt
           FROM ingest_failures
          WHERE resolved_at IS NULL
          GROUP BY source_tab
          ORDER BY cnt DESC"
    );
    foreach ($kpiStmt->fetchAll() as $kr) {
        $ifKpi["total_unresolved"] += (int) $kr["cnt"];
        $ifKpi["by_tab"][$kr["source_tab"]] = (int) $kr["cnt"];
    }

    // Build WHERE clause
    $ifWhere  = [];
    $ifParams = [];

    if ($ifFilterStatus === "unresolved") {
        $ifWhere[] = "resolved_at IS NULL";
    } elseif ($ifFilterStatus === "resolved") {
        $ifWhere[] = "resolved_at IS NOT NULL";
    }

    if ($ifFilterTab !== "") {
        $ifWhere[] = "source_tab = :tab";
        $ifParams[":tab"] = $ifFilterTab;
    }

    if ($ifFilterQ !== "") {
        $ifWhere[] = "reason_text LIKE :q";
        $ifParams[":q"] = "%" . $ifFilterQ . "%";
    }

    $ifWhereSql = $ifWhere ? "WHERE " . implode(" AND ", $ifWhere) : "";

    // Count for pagination
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM ingest_failures $ifWhereSql");
    $cntStmt->execute($ifParams);
    $ifTotal = (int) $cntStmt->fetchColumn();

    // Rows
    $rowStmt = $pdo->prepare(
        "SELECT id, detected_at, last_seen_at, source_tab, target_table,
                sheet_row_index, row_hash, reason_code, reason_text,
                raw_row, resolved_at, resolution_note
           FROM ingest_failures $ifWhereSql
          ORDER BY resolved_at IS NULL DESC, detected_at DESC
          LIMIT " . IF_PAGE_SIZE . " OFFSET $ifOffset"
    );
    $rowStmt->execute($ifParams);
    $ifRows = $rowStmt->fetchAll();

    // Distinct source tabs for filter dropdown
    $tabsStmt = $pdo->query("SELECT DISTINCT source_tab FROM ingest_failures ORDER BY source_tab");
    $ifAllTabs = $tabsStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $ifDbError = $e->getMessage();
}

$ifLastPage = $ifTotal > 0 ? (int) floor(($ifTotal - 1) / IF_PAGE_SIZE) : 0;

// ── QS helper (scoped with if_ prefix to avoid collisions) ───────────────────
function if_partial_qs(array $extra): string
{
    $base = [];
    foreach (["source_tab", "status", "q", "page", "tab"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") {
            $base[$k] = $_GET[$k];
        }
    }
    return "?" . http_build_query(array_merge($base, $extra));
}

// BSF link helper
$ifBsfBase = "https://docs.google.com/spreadsheets/d/1zTgfTJrLd_kQfwQxfS9SjQ5MLkUYK-CyXX13TKRMJiE/edit";
function if_bsf_row_url(string $base, int $row): string {
    return $base . "#gid=0&range=A" . $row;
}

?>

<?php if ($ifFlash !== null): ?>
  <div class="db-flash db-flash--<?= $ifFlashType === "ok" ? "ok" : "err" ?>">
    <?= htmlspecialchars($ifFlash) ?>
  </div>
<?php endif ?>

<?php if ($ifDbError !== null): ?>
  <div class="db-flash db-flash--err">Erreur base de données : <?= htmlspecialchars($ifDbError) ?></div>
<?php endif ?>

<!-- ── KPI bar ── -->
<div class="if-kpi-bar">
  <div class="if-kpi if-kpi--<?= $ifKpi["total_unresolved"] > 0 ? "warn" : "ok" ?>">
    <span class="if-kpi__value"><?= $ifKpi["total_unresolved"] ?></span>
    <span class="if-kpi__label">non résolues</span>
  </div>
  <?php foreach ($ifKpi["by_tab"] as $tab => $cnt): ?>
    <div class="if-kpi if-kpi--tab">
      <span class="if-kpi__value"><?= $cnt ?></span>
      <span class="if-kpi__label"><?= htmlspecialchars($tab) ?></span>
    </div>
  <?php endforeach ?>
  <?php if (empty($ifKpi["by_tab"])): ?>
    <div class="if-kpi if-kpi--ok">
      <span class="if-kpi__value">0</span>
      <span class="if-kpi__label">aucune failure — parfait</span>
    </div>
  <?php endif ?>
</div>

<!-- ── Filter form — always POSTs/GETs to current URL to preserve tab context ── -->
<form class="admin-filters" method="get" action="">
  <?php
  // Preserve tab= when included from triage.php
  if (isset($_GET["tab"]) && $_GET["tab"] !== ""):
  ?>
    <input type="hidden" name="tab" value="<?= htmlspecialchars($_GET["tab"]) ?>">
  <?php endif ?>

  <label class="admin-filters__field">
    <span class="admin-filters__label">Source tab</span>
    <select name="source_tab">
      <option value="">— toutes —</option>
      <?php foreach ($ifAllTabs as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>"<?= $ifFilterTab === $t ? " selected" : "" ?>>
          <?= htmlspecialchars($t) ?>
        </option>
      <?php endforeach ?>
    </select>
  </label>
  <label class="admin-filters__field">
    <span class="admin-filters__label">Statut</span>
    <select name="status">
      <option value="unresolved"<?= $ifFilterStatus === "unresolved" ? " selected" : "" ?>>Non résolues</option>
      <option value="all"<?=       $ifFilterStatus === "all"        ? " selected" : "" ?>>Toutes</option>
      <option value="resolved"<?=  $ifFilterStatus === "resolved"   ? " selected" : "" ?>>Résolues</option>
    </select>
  </label>
  <label class="admin-filters__field admin-filters__field--wide">
    <span class="admin-filters__label">Recherche (reason_text)</span>
    <input type="text" name="q" value="<?= htmlspecialchars($ifFilterQ) ?>" placeholder="FK, apinnacle…">
  </label>
  <button type="submit" class="admin-filters__submit">Filtrer</button>
</form>

<!-- ── Results count ── -->
<div class="if-results-meta">
  <?= number_format($ifTotal, 0, ",", " ") ?> ligne<?= $ifTotal !== 1 ? "s" : "" ?>
  <?= $ifFilterStatus !== "all" ? "(" . htmlspecialchars($ifFilterStatus) . ")" : "" ?>
</div>

<?php if (empty($ifRows) && $ifDbError === null): ?>
  <div class="empty">Aucune failure.</div>

<?php elseif (!empty($ifRows)): ?>

  <div class="db-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Detected</th>
          <th>Source tab</th>
          <th>Target table</th>
          <th>Sheet row</th>
          <th>Reason</th>
          <th>Raw row</th>
          <th>Statut</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ifRows as $r): ?>
          <?php $resolved = $r["resolved_at"] !== null; ?>
          <tr class="<?= $resolved ? "if-row--resolved" : "if-row--open" ?>">
            <td class="if-td--mono">
              <?= htmlspecialchars(substr((string)$r["detected_at"], 0, 16)) ?>
              <?php if (!$resolved && $r["last_seen_at"] !== $r["detected_at"]): ?>
                <br><span class="if-last-seen">last seen <?= htmlspecialchars(substr((string)$r["last_seen_at"], 0, 16)) ?></span>
              <?php endif ?>
            </td>
            <td><span class="if-badge if-badge--<?= htmlspecialchars($r["source_tab"]) ?>"><?= htmlspecialchars($r["source_tab"]) ?></span></td>
            <td class="if-td--mono"><?= htmlspecialchars($r["target_table"]) ?></td>
            <td class="if-td--mono">
              <a class="if-row-link"
                 href="<?= htmlspecialchars(if_bsf_row_url($ifBsfBase, (int)$r["sheet_row_index"])) ?>"
                 target="_blank" rel="noopener">
                #<?= (int)$r["sheet_row_index"] ?>
              </a>
            </td>
            <td class="if-td--reason">
              <code class="if-reason-code"><?= (int)$r["reason_code"] ?></code>
              <?= htmlspecialchars((string)$r["reason_text"]) ?>
            </td>
            <td>
              <details class="if-raw">
                <summary class="if-raw__toggle">JSON</summary>
                <pre class="if-raw__body"><?php
                  $decoded = json_decode((string)$r["raw_row"], true);
                  echo htmlspecialchars(
                      $decoded !== null
                          ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                          : (string)$r["raw_row"]
                  );
                ?></pre>
              </details>
            </td>
            <td>
              <?php if ($resolved): ?>
                <span class="if-status if-status--resolved">
                  résolu <?= htmlspecialchars(substr((string)$r["resolved_at"], 0, 10)) ?>
                </span>
                <?php if (!empty($r["resolution_note"])): ?>
                  <br><span class="if-resolution-note"><?= htmlspecialchars((string)$r["resolution_note"]) ?></span>
                <?php endif ?>
              <?php else: ?>
                <span class="if-status if-status--open">ouvert</span>
              <?php endif ?>
            </td>
            <td>
              <?php if (!$resolved): ?>
                <!-- POST always targets /admin/ingest-failures.php?action=resolve
                     (canonical handler); redirect lands on /admin/ingest-failures.php.
                     Known limitation when accessed via triage tab — redirect lands
                     on standalone page, not back to ?tab=form. -->
                <form class="if-resolve-form" method="post"
                      action="/admin/ingest-failures.php?<?= htmlspecialchars(http_build_query(["action" => "resolve"])) ?>">
                  <input type="hidden" name="csrf"          value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="id"            value="<?= (int)$r["id"] ?>">
                  <input type="hidden" name="source_tab"    value="<?= htmlspecialchars($ifFilterTab) ?>">
                  <input type="hidden" name="status_filter" value="<?= htmlspecialchars($ifFilterStatus) ?>">
                  <input type="hidden" name="q"             value="<?= htmlspecialchars($ifFilterQ) ?>">
                  <input type="text"   name="resolution_note"
                         class="if-resolve-note"
                         placeholder="Note (optionnel)">
                  <button type="submit" class="if-resolve-btn">Marquer résolu</button>
                </form>
              <?php else: ?>
                <span class="if-action--none">—</span>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <nav class="db-pagination" aria-label="Pagination ingest failures">
    <?php if ($ifPage > 0): ?>
      <a class="db-pagination__link"
         href="<?= htmlspecialchars(if_partial_qs(["page" => $ifPage - 1])) ?>">← précédent</a>
    <?php else: ?>
      <span class="db-pagination__link db-pagination__link--off">← précédent</span>
    <?php endif ?>
    <span class="db-pagination__pos">page <?= $ifPage + 1 ?> / <?= $ifLastPage + 1 ?></span>
    <?php if ($ifPage < $ifLastPage): ?>
      <a class="db-pagination__link"
         href="<?= htmlspecialchars(if_partial_qs(["page" => $ifPage + 1])) ?>">suivant →</a>
    <?php else: ?>
      <span class="db-pagination__link db-pagination__link--off">suivant →</span>
    <?php endif ?>
  </nav>

<?php endif ?>
