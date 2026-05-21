<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/csrf.php";
require __DIR__ . "/../../app/db-correct.php";

require_admin();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "db-browser";
$crumbs        = ["Accueil", "Admin", "DB Browser"];

const PAGE_SIZE = 50;
const TEXT_TRUNCATE = 200;

$dbError = null;
$tables  = [];
$rowCounts = [];
$selected   = null;
$columns    = [];
$rows       = [];
$totalRows  = 0;
$page       = 0;
$filterCol  = null;
$filterVal  = null;
$pkColumn   = null;
$editableCols = [];
$writableTables = [];

// Flash message after a successful correction (set by db-correct-apply.php redirect).
$appliedAction    = $_GET["applied_action"]    ?? null;
$appliedRows      = $_GET["applied_rows"]      ?? null;
$appliedCol       = $_GET["applied_col"]       ?? null;
$appliedAliases   = $_GET["aliases_upserted"]  ?? null;
if ($appliedAction !== null && in_array($appliedAction, ["update", "delete"], true)
    && is_numeric($appliedRows)) {
    $appliedRows    = (int) $appliedRows;
    $appliedAliases = is_numeric($appliedAliases) ? (int) $appliedAliases : 0;
} else {
    $appliedAction  = null;
    $appliedRows    = null;
    $appliedAliases = null;
}

try {
    $pdo = maltytask_pdo();

    // List tables + counts (DB has ~25 tables, COUNT(*) on each is cheap enough).
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $countSql = "SELECT COUNT(*) FROM `" . str_replace("`", "``", $t) . "`";
        $rowCounts[$t] = (int) $pdo->query($countSql)->fetchColumn();
    }

    // Resolve ?table= against the whitelist.
    $reqTable = $_GET["table"] ?? null;
    if ($reqTable !== null && in_array($reqTable, $tables, true)) {
        $selected = $reqTable;
    }

    $writableTables = dbcorrect_writable_tables($pdo);

    if ($selected !== null) {
        $tableQuoted = "`" . str_replace("`", "``", $selected) . "`";

        // Discover columns + correction-tool metadata.
        $meta = dbcorrect_table_meta($pdo, $selected);
        $columns      = array_map(fn($c) => ["name" => $c["name"], "type" => $c["type"]], $meta["columns"]);
        $pkColumn     = $meta["pk_column"];
        $editableCols = $meta["editable_columns"];
        $columnNames  = array_column($columns, "name");

        // Filter (whitelisted column + parameterized value).
        $reqCol = $_GET["col"] ?? null;
        $reqVal = $_GET["val"] ?? null;
        if (is_string($reqCol) && in_array($reqCol, $columnNames, true)
            && is_string($reqVal) && $reqVal !== "") {
            $filterCol = $reqCol;
            $filterVal = $reqVal;
        }

        $whereSql = "";
        $params   = [];
        if ($filterCol !== null) {
            $colQuoted = "`" . str_replace("`", "``", $filterCol) . "`";
            $whereSql  = " WHERE {$colQuoted} LIKE :v";
            $params[":v"] = "%" . $filterVal . "%";
        }

        // Total (filtered).
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableQuoted}{$whereSql}");
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();

        // Pagination.
        $page   = max(0, (int) ($_GET["page"] ?? 0));
        $offset = $page * PAGE_SIZE;

        // Order: prefer primary key DESC; fall back to first column ASC.
        $pkRow  = $pdo->query("SHOW KEYS FROM {$tableQuoted} WHERE Key_name = 'PRIMARY'")->fetch();
        $orderCol = $pkRow ? $pkRow["Column_name"] : $columnNames[0];
        $orderDir = $pkRow ? "DESC" : "ASC";
        $orderQuoted = "`" . str_replace("`", "``", $orderCol) . "`";

        $sql = "SELECT * FROM {$tableQuoted}{$whereSql} ORDER BY {$orderQuoted} {$orderDir} "
             . "LIMIT " . PAGE_SIZE . " OFFSET " . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// Build query-string preserving filter for pagination links.
function build_qs(array $extra): string {
    $base = [];
    foreach (["table", "col", "val", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") {
            $base[$k] = $_GET[$k];
        }
    }
    return http_build_query(array_merge($base, $extra));
}

function fmt_cell($v): string {
    if ($v === null) return "<span class=\"db-cell--null\">NULL</span>";
    $s = (string) $v;
    if (strlen($s) > TEXT_TRUNCATE) {
        $s = substr($s, 0, TEXT_TRUNCATE) . "…";
    }
    return htmlspecialchars($s);
}

$lastPage = $selected !== null && $totalRows > 0
    ? (int) floor(($totalRows - 1) / PAGE_SIZE)
    : 0;
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DB Browser — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home admin db-browser">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main db-browser__main">

  <?php if ($dbError): ?>
    <div class="wort-error">
      Erreur base de données : <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <?php if ($appliedAction !== null): ?>
    <div class="db-flash db-flash--ok">
      <?php if ($appliedAction === "update"): ?>
        ✓ <?= (int) $appliedRows ?> ligne<?= $appliedRows > 1 ? "s" : "" ?> mise<?= $appliedRows > 1 ? "s" : "" ?> à jour
        <?php if (!empty($appliedCol)): ?>
          (colonne <code><?= htmlspecialchars($appliedCol) ?></code>)
        <?php endif ?>
      <?php else: ?>
        ✓ <?= (int) $appliedRows ?> ligne<?= $appliedRows > 1 ? "s" : "" ?> supprimée<?= $appliedRows > 1 ? "s" : "" ?>
      <?php endif ?>
      — audit log : <code>debug_corrections</code>.
    </div>
  <?php endif ?>

  <div class="db-browser__layout">

    <!-- LEFT: table list -->
    <aside class="db-browser__tables" aria-label="Tables">
      <div class="db-browser__tables-head">
        <span class="db-browser__tables-label">— tables</span>
        <span class="db-browser__tables-count"><?= count($tables) ?></span>
      </div>
      <ul class="db-browser__tables-list">
        <?php foreach ($tables as $t): ?>
          <?php $active = ($t === $selected); ?>
          <li>
            <a class="db-browser__table-link<?= $active ? ' db-browser__table-link--active' : '' ?>"
               href="?<?= htmlspecialchars(http_build_query(["table" => $t])) ?>">
              <span class="db-browser__table-name"><?= htmlspecialchars($t) ?></span>
              <span class="db-browser__table-count"><?= number_format($rowCounts[$t] ?? 0, 0, ',', ' ') ?></span>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    </aside>

    <!-- RIGHT: table view -->
    <section class="db-browser__view" aria-label="Vue table">

      <?php if ($selected === null): ?>

        <div class="db-browser__empty">
          <p class="db-browser__empty-headline">Sélectionne une table à gauche</p>
          <p class="db-browser__empty-sub">Affichage paginé de <?= PAGE_SIZE ?> lignes, recherche par colonne, troncature à <?= TEXT_TRUNCATE ?> caractères.</p>
        </div>

      <?php else: ?>

        <div class="db-browser__view-head">
          <h1 class="db-browser__view-title"><?= htmlspecialchars($selected) ?></h1>
          <div class="db-browser__view-meta">
            <span class="db-browser__view-total"><?= number_format($totalRows, 0, ',', ' ') ?> ligne<?= $totalRows > 1 ? 's' : '' ?></span>
            <?php if ($filterCol !== null): ?>
              <span class="db-browser__view-filter">
                filtre :
                <span class="wort-mono"><?= htmlspecialchars($filterCol) ?></span>
                ~
                <span class="wort-mono"><?= htmlspecialchars($filterVal) ?></span>
              </span>
              <a class="db-browser__view-clear" href="?<?= htmlspecialchars(http_build_query(["table" => $selected])) ?>">effacer</a>
            <?php endif ?>
          </div>
        </div>

        <!-- Filter form -->
        <form class="db-filter" method="get" action="">
          <input type="hidden" name="table" value="<?= htmlspecialchars($selected) ?>">
          <label class="db-filter__field">
            <span class="db-filter__label">Colonne</span>
            <select name="col">
              <option value="">—</option>
              <?php foreach ($columns as $c): ?>
                <option value="<?= htmlspecialchars($c["name"]) ?>"<?= ($filterCol === $c["name"]) ? ' selected' : '' ?>>
                  <?= htmlspecialchars($c["name"]) ?>
                </option>
              <?php endforeach ?>
            </select>
          </label>
          <label class="db-filter__field db-filter__field--wide">
            <span class="db-filter__label">Contient</span>
            <input type="text" name="val" value="<?= htmlspecialchars($filterVal ?? '') ?>" placeholder="LIKE %…%">
          </label>
          <button type="submit" class="db-filter__submit">Filtrer</button>
        </form>

        <?php
        $correctionEnabled = ($pkColumn !== null)
                          && in_array($selected, $writableTables, true)
                          && !empty($editableCols);
        ?>

        <?php if (empty($rows)): ?>
          <div class="empty">Aucune ligne.</div>
        <?php else: ?>
          <form class="db-correct" method="post" action="/admin/db-correct-propose.php"
                <?= $correctionEnabled ? '' : 'aria-disabled="true"' ?>>
            <input type="hidden" name="csrf"  value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="table" value="<?= htmlspecialchars($selected) ?>">

            <div class="db-table-wrap">
              <table class="db-table">
                <thead>
                  <tr>
                    <?php if ($correctionEnabled): ?>
                      <th class="db-th--check" scope="col" title="Sélection pour correction">✎</th>
                    <?php endif ?>
                    <?php foreach ($columns as $c): ?>
                      <th scope="col" title="<?= htmlspecialchars($c["type"]) ?>">
                        <?= htmlspecialchars($c["name"]) ?>
                      </th>
                    <?php endforeach ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <?php if ($correctionEnabled): ?>
                        <td class="db-td db-td--check">
                          <input type="checkbox" name="ids[]"
                                 value="<?= htmlspecialchars((string) ($r[$pkColumn] ?? '')) ?>">
                        </td>
                      <?php endif ?>
                      <?php foreach ($columns as $c): ?>
                        <td class="db-td"><?= fmt_cell($r[$c["name"]] ?? null) ?></td>
                      <?php endforeach ?>
                    </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>

            <?php if ($correctionEnabled): ?>
              <fieldset class="db-correct__panel">
                <legend class="db-correct__legend">— correction debug</legend>
                <div class="db-correct__row">
                  <label class="db-correct__field">
                    <span class="db-correct__label">Action</span>
                    <select name="action" required>
                      <option value="update">Modifier valeur</option>
                      <option value="delete">Supprimer ligne(s)</option>
                    </select>
                  </label>
                  <label class="db-correct__field">
                    <span class="db-correct__label">Colonne</span>
                    <select name="column">
                      <?php foreach ($editableCols as $col): ?>
                        <option value="<?= htmlspecialchars($col) ?>"><?= htmlspecialchars($col) ?></option>
                      <?php endforeach ?>
                    </select>
                  </label>
                  <label class="db-correct__field db-correct__field--wide">
                    <span class="db-correct__label">Nouvelle valeur</span>
                    <input type="text" name="new_value" placeholder="(ignoré si « valeur NULL » coché)">
                  </label>
                  <label class="db-correct__null">
                    <input type="checkbox" name="set_null" value="1">
                    <span>NULL</span>
                  </label>
                </div>
                <div class="db-correct__actions">
                  <button type="submit" class="db-correct__submit">Aperçu de la correction →</button>
                  <span class="db-correct__hint">
                    Coche les lignes à corriger ci-dessus. Aperçu → confirmation → exécution.
                    Toutes les modifications sont auditées dans <code>debug_corrections</code>.
                  </span>
                </div>
              </fieldset>
            <?php else: ?>
              <p class="db-correct__disabled">
                <?php if ($pkColumn === null): ?>
                  Correction non disponible : table sans clé primaire à colonne unique.
                <?php elseif (!in_array($selected, $writableTables, true)): ?>
                  Table système — modifications interdites.
                <?php else: ?>
                  Aucune colonne modifiable sur cette table.
                <?php endif ?>
              </p>
            <?php endif ?>
          </form>

          <!-- Pagination — outside the correction form -->
          <nav class="db-pagination" aria-label="Pagination">
            <?php if ($page > 0): ?>
              <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs(["page" => $page - 1])) ?>">← précédent</a>
            <?php else: ?>
              <span class="db-pagination__link db-pagination__link--off">← précédent</span>
            <?php endif ?>

            <span class="db-pagination__pos">
              page <?= $page + 1 ?> / <?= $lastPage + 1 ?>
            </span>

            <?php if ($page < $lastPage): ?>
              <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs(["page" => $page + 1])) ?>">suivant →</a>
            <?php else: ?>
              <span class="db-pagination__link db-pagination__link--off">suivant →</span>
            <?php endif ?>
          </nav>
        <?php endif ?>

      <?php endif ?>

    </section>
  </div>

</main>

</body>
</html>
