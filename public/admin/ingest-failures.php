<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/csrf.php";

require_admin();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "ingest-failures";
$crumbs        = ["Accueil", "Admin", "Ingest Failures"];

// ── POST: mark a failure resolved ────────────────────────────────────────────
$flashMsg  = null;
$flashType = "ok";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_GET["action"] ?? "") === "resolve") {
    $id   = isset($_POST["id"]) ? (int) $_POST["id"] : 0;
    $note = trim($_POST["resolution_note"] ?? "");
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        http_response_code(400);
        $flashMsg  = "CSRF token invalide.";
        $flashType = "err";
    } elseif ($id <= 0) {
        $flashMsg  = "ID invalide.";
        $flashType = "err";
    } else {
        try {
            $pdo = maltytask_pdo();
            $stmt = $pdo->prepare(
                "UPDATE ingest_failures
                    SET resolved_at      = CURRENT_TIMESTAMP,
                        resolution_note  = :note
                  WHERE id = :id AND resolved_at IS NULL"
            );
            $stmt->execute([":note" => $note === "" ? "marked resolved by operator" : $note, ":id" => $id]);
            $affected = $stmt->rowCount();
            $qs = http_build_query(array_filter([
                "source_tab" => $_POST["source_tab"] ?? "",
                "status"     => $_POST["status_filter"] ?? "",
                "q"          => $_POST["q"] ?? "",
                "resolved"   => $affected > 0 ? "1" : null,
            ]));
            header("Location: /admin/ingest-failures.php?" . $qs, true, 303);
            exit;
        } catch (Throwable $e) {
            $flashMsg  = "Erreur DB : " . htmlspecialchars($e->getMessage());
            $flashType = "err";
        }
    }
}

// ── Flash from redirect ───────────────────────────────────────────────────────
if ($flashMsg === null && ($_GET["resolved"] ?? "") === "1") {
    $flashMsg  = "Failure marquée résolue.";
    $flashType = "ok";
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterTab    = $_GET["source_tab"] ?? "";
$filterStatus = $_GET["status"]     ?? "unresolved"; // unresolved | all | resolved
$filterQ      = $_GET["q"]          ?? "";
if (!in_array($filterStatus, ["unresolved", "all", "resolved"], true)) {
    $filterStatus = "unresolved";
}

// ── Query ─────────────────────────────────────────────────────────────────────
$dbError   = null;
$rows      = [];
$kpi       = ["total_unresolved" => 0, "by_tab" => []];

const IF_PAGE_SIZE = 50;
$page   = max(0, (int) ($_GET["page"] ?? 0));
$offset = $page * IF_PAGE_SIZE;
$totalRows = 0;

try {
    $pdo = maltytask_pdo();

    // KPI: total unresolved
    $kpiStmt = $pdo->query(
        "SELECT source_tab, COUNT(*) AS cnt
           FROM ingest_failures
          WHERE resolved_at IS NULL
          GROUP BY source_tab
          ORDER BY cnt DESC"
    );
    $kpiRows = $kpiStmt->fetchAll();
    foreach ($kpiRows as $kr) {
        $kpi["total_unresolved"] += (int) $kr["cnt"];
        $kpi["by_tab"][$kr["source_tab"]] = (int) $kr["cnt"];
    }

    // Build WHERE clause
    $where  = [];
    $params = [];

    if ($filterStatus === "unresolved") {
        $where[] = "resolved_at IS NULL";
    } elseif ($filterStatus === "resolved") {
        $where[] = "resolved_at IS NOT NULL";
    }

    if ($filterTab !== "") {
        $where[] = "source_tab = :tab";
        $params[":tab"] = $filterTab;
    }

    if ($filterQ !== "") {
        $where[] = "reason_text LIKE :q";
        $params[":q"] = "%" . $filterQ . "%";
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    // Count
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM ingest_failures $whereSql");
    $cntStmt->execute($params);
    $totalRows = (int) $cntStmt->fetchColumn();

    // Rows
    $stmt = $pdo->prepare(
        "SELECT id, detected_at, last_seen_at, source_tab, target_table,
                sheet_row_index, row_hash, reason_code, reason_text,
                raw_row, resolved_at, resolution_note
           FROM ingest_failures $whereSql
          ORDER BY resolved_at IS NULL DESC, detected_at DESC
          LIMIT " . IF_PAGE_SIZE . " OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Distinct source tabs for filter dropdown
    $tabsStmt = $pdo->query("SELECT DISTINCT source_tab FROM ingest_failures ORDER BY source_tab");
    $allTabs  = $tabsStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$lastPage = $totalRows > 0 ? (int) floor(($totalRows - 1) / IF_PAGE_SIZE) : 0;

// ── Helpers ───────────────────────────────────────────────────────────────────
function if_qs(array $extra): string {
    $base = [];
    foreach (["source_tab", "status", "q", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") {
            $base[$k] = $_GET[$k];
        }
    }
    return http_build_query(array_merge($base, $extra));
}

$bsfBase = "https://docs.google.com/spreadsheets/d/1zTgfTJrLd_kQfwQxfS9SjQ5MLkUYK-CyXX13TKRMJiE/edit";

function bsf_row_url(string $base, int $row): string {
    // No gid lookup in v1 — link to base sheet; row index is still surfaced as text.
    return $base . "#gid=0&range=A" . $row;
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingest Failures — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home admin ingest-failures-page">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main">

  <?php if ($flashMsg !== null): ?>
    <div class="db-flash db-flash--<?= $flashType === "ok" ? "ok" : "err" ?>">
      <?= htmlspecialchars($flashMsg) ?>
    </div>
  <?php endif ?>

  <?php if ($dbError !== null): ?>
    <div class="wort-error">Erreur base de données : <?= htmlspecialchars($dbError) ?></div>
  <?php endif ?>

  <!-- ── KPI bar ── -->
  <div class="if-kpi-bar">
    <div class="if-kpi if-kpi--<?= $kpi["total_unresolved"] > 0 ? "warn" : "ok" ?>">
      <span class="if-kpi__value"><?= $kpi["total_unresolved"] ?></span>
      <span class="if-kpi__label">non résolues</span>
    </div>
    <?php foreach ($kpi["by_tab"] as $tab => $cnt): ?>
      <div class="if-kpi if-kpi--tab">
        <span class="if-kpi__value"><?= $cnt ?></span>
        <span class="if-kpi__label"><?= htmlspecialchars($tab) ?></span>
      </div>
    <?php endforeach ?>
    <?php if (empty($kpi["by_tab"])): ?>
      <div class="if-kpi if-kpi--ok">
        <span class="if-kpi__value">0</span>
        <span class="if-kpi__label">aucune failure — parfait</span>
      </div>
    <?php endif ?>
  </div>

  <!-- ── Filter form ── -->
  <form class="admin-filters" method="get" action="">
    <label class="admin-filters__field">
      <span class="admin-filters__label">Source tab</span>
      <select name="source_tab">
        <option value="">— toutes —</option>
        <?php foreach ($allTabs as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"<?= $filterTab === $t ? " selected" : "" ?>>
            <?= htmlspecialchars($t) ?>
          </option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="admin-filters__field">
      <span class="admin-filters__label">Statut</span>
      <select name="status">
        <option value="unresolved"<?= $filterStatus === "unresolved" ? " selected" : "" ?>>Non résolues</option>
        <option value="all"<?=       $filterStatus === "all"        ? " selected" : "" ?>>Toutes</option>
        <option value="resolved"<?=  $filterStatus === "resolved"   ? " selected" : "" ?>>Résolues</option>
      </select>
    </label>
    <label class="admin-filters__field admin-filters__field--wide">
      <span class="admin-filters__label">Recherche (reason_text)</span>
      <input type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="FK, apinnacle…">
    </label>
    <button type="submit" class="admin-filters__submit">Filtrer</button>
  </form>

  <!-- ── Results count ── -->
  <div class="if-results-meta">
    <?= number_format($totalRows, 0, ",", " ") ?> ligne<?= $totalRows !== 1 ? "s" : "" ?>
    <?= $filterStatus !== "all" ? "(" . htmlspecialchars($filterStatus) . ")" : "" ?>
  </div>

  <?php if (empty($rows) && $dbError === null): ?>
    <div class="empty">Aucune failure.</div>
  <?php elseif (!empty($rows)): ?>

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
          <?php foreach ($rows as $r): ?>
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
                   href="<?= htmlspecialchars(bsf_row_url($bsfBase, (int)$r["sheet_row_index"])) ?>"
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
                  <form class="if-resolve-form" method="post"
                        action="?<?= htmlspecialchars(http_build_query(["action" => "resolve"])) ?>">
                    <input type="hidden" name="csrf"          value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="id"            value="<?= (int)$r["id"] ?>">
                    <input type="hidden" name="source_tab"    value="<?= htmlspecialchars($filterTab) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($filterStatus) ?>">
                    <input type="hidden" name="q"             value="<?= htmlspecialchars($filterQ) ?>">
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
    <nav class="db-pagination" aria-label="Pagination">
      <?php if ($page > 0): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(if_qs(["page" => $page - 1])) ?>">← précédent</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← précédent</span>
      <?php endif ?>
      <span class="db-pagination__pos">page <?= $page + 1 ?> / <?= $lastPage + 1 ?></span>
      <?php if ($page < $lastPage): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(if_qs(["page" => $page + 1])) ?>">suivant →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suivant →</span>
      <?php endif ?>
    </nav>

  <?php endif ?>

</main>

</body>
</html>
