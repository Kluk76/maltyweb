<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Suppliers"];

const PAGE_SIZE = 50;

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/suppliers.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create" || $action === "update") {
            $supplierId = post_str("supplier_id");
            if ($supplierId === null) throw new RuntimeException("supplier_id requis (clé naturelle).");
            $name       = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $glAccount  = post_str("gl_account");
            $category   = post_str("category");
            $currency   = post_str("currency");
            $isActive   = !empty($_POST["is_active"]) ? 1 : 0;
            $notes      = post_str("notes");

            $hash = compute_row_hash([$supplierId, $name, $glAccount, $category, $currency, $isActive, $notes]);

            if ($action === "create") {
                $stmt = $pdo->prepare(
                    "INSERT INTO ref_suppliers
                     (supplier_id, name, gl_account, category, currency, is_active, notes, row_hash, last_modified_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'web')"
                );
                $stmt->execute([$supplierId, $name, $glAccount, $category, $currency, $isActive, $notes, $hash]);
                flash_set("ok", "Supplier « {$name} » ajouté et épinglé (web).");
            } else {
                $id = post_int("id");
                if ($id === null) throw new RuntimeException("ID manquant.");
                $stmt = $pdo->prepare(
                    "UPDATE ref_suppliers SET
                       supplier_id = ?, name = ?, gl_account = ?, category = ?,
                       currency = ?, is_active = ?, notes = ?, row_hash = ?,
                       last_modified_by = 'web'
                     WHERE id = ?"
                );
                $stmt->execute([$supplierId, $name, $glAccount, $category, $currency, $isActive, $notes, $hash, $id]);
                flash_set("ok", "Supplier mis à jour et épinglé (web).");
            }
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM ref_suppliers WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Supplier supprimé.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }

    $qs = http_build_query(array_filter([
        "gl"     => $_POST["filter_gl"]     ?? null,
        "active" => $_POST["filter_active"] ?? null,
        "q"      => $_POST["filter_q"]      ?? null,
    ], fn($v) => $v !== null && $v !== ""));
    redirect_to("/admin/settings/suppliers.php" . ($qs ? "?{$qs}" : ""));
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

$fGl     = trim((string) ($_GET["gl"] ?? ""));
$fActive = $_GET["active"] ?? null;
if (!in_array($fActive, ["0", "1"], true)) $fActive = null;
$fQ      = trim((string) ($_GET["q"] ?? ""));
$page    = max(0, (int) ($_GET["page"] ?? 0));

$where  = [];
$params = [];
if ($fGl !== "")       { $where[] = "gl_account = ?"; $params[] = $fGl; }
if ($fActive !== null) { $where[] = "is_active = ?";  $params[] = (int) $fActive; }
if ($fQ !== "")        { $where[] = "(name LIKE ? OR supplier_id LIKE ?)";
                          $params[] = "%{$fQ}%";
                          $params[] = "%{$fQ}%"; }
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $pdo = maltytask_pdo();

    $glOptions = $pdo->query(
        "SELECT DISTINCT gl_account FROM ref_suppliers WHERE gl_account IS NOT NULL "
      . "ORDER BY gl_account"
    )->fetchAll(PDO::FETCH_COLUMN);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ref_suppliers {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $lastPage  = $totalRows > 0 ? (int) floor(($totalRows - 1) / PAGE_SIZE) : 0;

    $sql = "SELECT * FROM ref_suppliers {$whereSql} "
         . "ORDER BY name, gl_account "
         . "LIMIT " . PAGE_SIZE . " OFFSET " . ($page * PAGE_SIZE);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = []; $glOptions = []; $totalRows = 0; $lastPage = 0;
    $loadErr = $e->getMessage();
}

$editId = isset($_GET["edit"]) && ctype_digit((string) $_GET["edit"]) ? (int) $_GET["edit"] : null;
$csrf   = csrf_token();

function build_qs_suppliers(array $extra): string {
    $base = [];
    foreach (["gl", "active", "q", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") $base[$k] = $_GET[$k];
    }
    return http_build_query(array_merge($base, $extra));
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suppliers — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-suppliers">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>
  <?php render_ingest_warning("ref_suppliers", "scripts/python/ingest_suppliers.py") ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · suppliers</span>
    <h1 class="settings__head-title">Suppliers</h1>
    <p class="settings__head-tag">
      Suppliers — un par paire <code>(supplier_id, GL)</code>.
      Les suppliers multi-GL ont plusieurs lignes avec le même nom.
      <strong><?= $totalRows ?></strong> ligne<?= $totalRows > 1 ? "s" : "" ?>
      au total<?= !empty($where) ? " (filtré)" : "" ?>.
    </p>
  </header>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <form class="db-filter" method="get" action="">
    <label class="db-filter__field db-filter__field--wide">
      <span class="db-filter__label">Recherche</span>
      <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="nom ou supplier_id">
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">GL</span>
      <select name="gl">
        <option value="">tous</option>
        <?php foreach ($glOptions as $gl): ?>
          <option value="<?= htmlspecialchars((string) $gl) ?>"<?= $fGl === $gl ? " selected" : "" ?>><?= htmlspecialchars((string) $gl) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">Actif</span>
      <select name="active">
        <option value="">tous</option>
        <option value="1"<?= $fActive === "1" ? " selected" : "" ?>>actifs</option>
        <option value="0"<?= $fActive === "0" ? " selected" : "" ?>>inactifs</option>
      </select>
    </label>
    <button type="submit" class="db-filter__submit">Filtrer</button>
    <?php if (!empty($where)): ?>
      <a href="/admin/settings/suppliers.php" class="settings-btn settings-btn--cancel">Réinitialiser</a>
    <?php endif ?>
  </form>

  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="">
      <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>"            form="edit-row">
      <input type="hidden" name="action"        value="update"                                     form="edit-row">
      <input type="hidden" name="id"            value="<?= (int) $editId ?>"                       form="edit-row">
      <input type="hidden" name="filter_gl"     value="<?= htmlspecialchars($fGl) ?>"              form="edit-row">
      <input type="hidden" name="filter_active" value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>" form="edit-row">
      <input type="hidden" name="filter_q"      value="<?= htmlspecialchars($fQ) ?>"               form="edit-row">
    </form>
  <?php endif ?>

  <div class="settings-table-wrap">
    <table class="settings-table">
      <thead>
        <tr>
          <th>supplier_id</th>
          <th>Nom</th>
          <th>GL</th>
          <th>Catégorie</th>
          <th>Devise</th>
          <th>Actif</th>
          <th>Notes</th>
          <th class="settings-table__actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="settings-table__empty">Aucun supplier.</td></tr>
        <?php endif ?>
        <?php foreach ($rows as $r): ?>
          <?php $isEdit = ((int) $r["id"] === $editId); ?>
          <?php if ($isEdit): ?>
            <tr class="settings-table__row settings-table__row--edit">
              <td><input form="edit-row" name="supplier_id" type="text" required value="<?= htmlspecialchars((string) $r["supplier_id"]) ?>"></td>
              <td><input form="edit-row" name="name" type="text" required value="<?= htmlspecialchars((string) $r["name"]) ?>" class="settings-input--wide"></td>
              <td><input form="edit-row" name="gl_account" type="text" value="<?= htmlspecialchars((string) ($r["gl_account"] ?? "")) ?>" maxlength="8" style="max-width:80px"></td>
              <td><input form="edit-row" name="category" type="text" value="<?= htmlspecialchars((string) ($r["category"] ?? "")) ?>"></td>
              <td><input form="edit-row" name="currency" type="text" value="<?= htmlspecialchars((string) ($r["currency"] ?? "")) ?>" maxlength="8" style="max-width:60px"></td>
              <td class="settings-mono"><input form="edit-row" type="checkbox" name="is_active" value="1"<?= $r["is_active"] ? " checked" : "" ?>></td>
              <td><input form="edit-row" name="notes" type="text" value="<?= htmlspecialchars((string) ($r["notes"] ?? "")) ?>"></td>
              <td class="settings-table__actions">
                <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
                <a href="?<?= htmlspecialchars(build_qs_suppliers([])) ?>" class="settings-btn settings-btn--cancel">Annuler</a>
              </td>
            </tr>
          <?php else: ?>
            <tr class="settings-table__row<?= ($r["last_modified_by"] ?? '') === 'web' ? ' settings-table__row--pinned' : '' ?>">
              <td class="settings-mono"><?= ($r["last_modified_by"] ?? '') === 'web' ? '<span class="settings-pin" title="Épinglé (web)">📌</span> ' : '' ?><?= htmlspecialchars((string) $r["supplier_id"]) ?></td>
              <td><?= htmlspecialchars((string) $r["name"]) ?></td>
              <td class="settings-mono"><?= $r["gl_account"] ? htmlspecialchars((string) $r["gl_account"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["category"] ? htmlspecialchars((string) $r["category"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["currency"] ? htmlspecialchars((string) $r["currency"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["is_active"] ? "✓" : '<span class="settings-muted">·</span>' ?></td>
              <td><?= $r["notes"] ? htmlspecialchars((string) $r["notes"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-table__actions">
                <a href="?<?= htmlspecialchars(build_qs_suppliers(["edit" => (int) $r["id"]])) ?>" class="settings-btn">Modifier</a>
                <form method="post" class="settings-inline-form"
                      onsubmit="return confirm('Supprimer le supplier « <?= htmlspecialchars((string) $r["name"], ENT_QUOTES) ?> » (GL <?= htmlspecialchars((string) ($r["gl_account"] ?? "—"), ENT_QUOTES) ?>) ?');">
                  <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"        value="delete">
                  <input type="hidden" name="id"            value="<?= (int) $r["id"] ?>">
                  <input type="hidden" name="filter_gl"     value="<?= htmlspecialchars($fGl) ?>">
                  <input type="hidden" name="filter_active" value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>">
                  <input type="hidden" name="filter_q"      value="<?= htmlspecialchars($fQ) ?>">
                  <button type="submit" class="settings-btn settings-btn--del">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endif ?>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <?php if ($lastPage > 0): ?>
    <nav class="db-pagination" aria-label="Pagination">
      <?php if ($page > 0): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_suppliers(["page" => $page - 1])) ?>">← précédent</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← précédent</span>
      <?php endif ?>
      <span class="db-pagination__pos">page <?= $page + 1 ?> / <?= $lastPage + 1 ?></span>
      <?php if ($page < $lastPage): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_suppliers(["page" => $page + 1])) ?>">suivant →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suivant →</span>
      <?php endif ?>
    </nav>
  <?php endif ?>

  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter un supplier</h2>
    <form method="post" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label class="settings-add__field">
        <span>supplier_id (clé naturelle)</span>
        <input name="supplier_id" type="text" required placeholder="ex. BC-AGRA">
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Nom</span>
        <input name="name" type="text" required placeholder="ex. AGRARIA">
      </label>
      <label class="settings-add__field">
        <span>GL</span>
        <input name="gl_account" type="text" placeholder="ex. 4101" maxlength="8">
      </label>
      <label class="settings-add__field">
        <span>Catégorie</span>
        <input name="category" type="text" placeholder="ex. Malt">
      </label>
      <label class="settings-add__field">
        <span>Devise</span>
        <input name="currency" type="text" placeholder="CHF" maxlength="8">
      </label>
      <label class="settings-add__field">
        <span>Actif</span>
        <select name="is_active">
          <option value="1" selected>oui</option>
          <option value="0">non</option>
        </select>
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Notes</span>
        <input name="notes" type="text" placeholder="—">
      </label>
      <button type="submit" class="settings-btn settings-btn--add">Ajouter</button>
    </form>
  </section>

</main>

</body>
</html>
