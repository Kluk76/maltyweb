<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Recettes"];

const RECIPE_CLASSIFICATIONS = ["Neb", "Contract"];
const RECIPE_SUBTYPES        = ["Core", "EPH", "CollabIn", "CollabOut", "WhiteLabel", "Archive"];
const PAGE_SIZE              = 50;

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/recipes.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create" || $action === "update") {
            $name           = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $classification = must_be_one_of("classification",
                $_POST["classification"] ?? "Neb", RECIPE_CLASSIFICATIONS);

            $subtypeRaw = $_POST["subtype"] ?? "";
            $subtype = ($subtypeRaw === "")
                ? null
                : must_be_one_of("subtype", $subtypeRaw, RECIPE_SUBTYPES);

            $clientId       = post_int("client_id");
            $shortName      = post_str("recipe_short_name");
            $vintage        = post_str("vintage") ?? "";   // NOT NULL with empty default
            $skuPrefix      = post_str("sku_prefix");
            $isActive       = !empty($_POST["is_active"]) ? 1 : 0;
            $notes          = post_str("notes");

            if ($action === "create") {
                $stmt = $pdo->prepare(
                    "INSERT INTO ref_recipes
                     (name, classification, subtype, client_id, recipe_short_name,
                      vintage, sku_prefix, is_active, notes, last_modified_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'web')"
                );
                $stmt->execute([$name, $classification, $subtype, $clientId,
                                $shortName, $vintage, $skuPrefix, $isActive, $notes]);
                flash_set("ok", "Recette « {$name} » ajoutée et épinglée (web).");
            } else {
                $id = post_int("id");
                if ($id === null) throw new RuntimeException("ID manquant.");
                $stmt = $pdo->prepare(
                    "UPDATE ref_recipes SET
                       name = ?, classification = ?, subtype = ?, client_id = ?,
                       recipe_short_name = ?, vintage = ?, sku_prefix = ?, is_active = ?, notes = ?,
                       last_modified_by = 'web'
                     WHERE id = ?"
                );
                $stmt->execute([$name, $classification, $subtype, $clientId,
                                $shortName, $vintage, $skuPrefix, $isActive, $notes, $id]);
                flash_set("ok", "Recette mise à jour et épinglée (web).");
            }
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM ref_recipes WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Recette supprimée.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }

    // Preserve current filter on redirect.
    $qs = http_build_query(array_filter([
        "classification" => $_POST["filter_classification"] ?? null,
        "subtype"        => $_POST["filter_subtype"]        ?? null,
        "active"         => $_POST["filter_active"]         ?? null,
        "q"              => $_POST["filter_q"]              ?? null,
    ], fn($v) => $v !== null && $v !== ""));
    redirect_to("/admin/settings/recipes.php" . ($qs ? "?{$qs}" : ""));
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

// Filter inputs
$fClass   = $_GET["classification"] ?? null;
if (!in_array($fClass, RECIPE_CLASSIFICATIONS, true)) $fClass = null;
$fSubtype = $_GET["subtype"] ?? null;
if (!in_array($fSubtype, RECIPE_SUBTYPES, true)) $fSubtype = null;
$fActive  = $_GET["active"] ?? null;
if (!in_array($fActive, ["0", "1"], true)) $fActive = null;
$fQ       = trim((string) ($_GET["q"] ?? ""));
$page     = max(0, (int) ($_GET["page"] ?? 0));

$where  = [];
$params = [];
if ($fClass !== null)   { $where[] = "r.classification = ?"; $params[] = $fClass; }
if ($fSubtype !== null) { $where[] = "r.subtype = ?";        $params[] = $fSubtype; }
if ($fActive !== null)  { $where[] = "r.is_active = ?";      $params[] = (int) $fActive; }
if ($fQ !== "")         { $where[] = "r.name LIKE ?";        $params[] = "%{$fQ}%"; }
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $pdo = maltytask_pdo();

    $clients = $pdo->query("SELECT id, name FROM ref_clients ORDER BY name")->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ref_recipes r {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $lastPage  = $totalRows > 0 ? (int) floor(($totalRows - 1) / PAGE_SIZE) : 0;

    $sql = "SELECT r.*, c.name AS client_name
            FROM ref_recipes r
            LEFT JOIN ref_clients c ON c.id = r.client_id
            {$whereSql}
            ORDER BY r.classification, r.name
            LIMIT " . PAGE_SIZE . " OFFSET " . ($page * PAGE_SIZE);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = []; $clients = []; $totalRows = 0; $lastPage = 0;
    $loadErr = $e->getMessage();
}

$editId = isset($_GET["edit"]) && ctype_digit((string) $_GET["edit"]) ? (int) $_GET["edit"] : null;
$csrf   = csrf_token();

function build_qs_recipes(array $extra): string {
    $base = [];
    foreach (["classification", "subtype", "active", "q", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") $base[$k] = $_GET[$k];
    }
    return http_build_query(array_merge($base, $extra));
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recettes — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-recipes">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>
  <?php render_ingest_warning("ref_recipes", "scripts/python/seed_references.py") ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · recettes</span>
    <h1 class="settings__head-title">Recettes</h1>
    <p class="settings__head-tag">
      Catalogue de toutes les recettes brassées (Neb + Contract).
      <strong><?= $totalRows ?></strong> recette<?= $totalRows > 1 ? "s" : "" ?>
      au total<?= !empty($where) ? " (filtré)" : "" ?>.
    </p>
  </header>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- Filter form -->
  <form class="db-filter" method="get" action="">
    <label class="db-filter__field">
      <span class="db-filter__label">Recherche</span>
      <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="nom recette">
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">Classification</span>
      <select name="classification">
        <option value="">—</option>
        <?php foreach (RECIPE_CLASSIFICATIONS as $c): ?>
          <option value="<?= $c ?>"<?= $fClass === $c ? " selected" : "" ?>><?= $c ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">Sous-type</span>
      <select name="subtype">
        <option value="">—</option>
        <?php foreach (RECIPE_SUBTYPES as $s): ?>
          <option value="<?= $s ?>"<?= $fSubtype === $s ? " selected" : "" ?>><?= $s ?></option>
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
      <a href="/admin/settings/recipes.php" class="settings-btn settings-btn--cancel">Réinitialiser</a>
    <?php endif ?>
  </form>

  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="">
      <input type="hidden" name="csrf"                  value="<?= htmlspecialchars($csrf) ?>"             form="edit-row">
      <input type="hidden" name="action"                value="update"                                      form="edit-row">
      <input type="hidden" name="id"                    value="<?= (int) $editId ?>"                        form="edit-row">
      <input type="hidden" name="filter_classification" value="<?= htmlspecialchars((string) ($fClass ?? "")) ?>"   form="edit-row">
      <input type="hidden" name="filter_subtype"        value="<?= htmlspecialchars((string) ($fSubtype ?? "")) ?>" form="edit-row">
      <input type="hidden" name="filter_active"         value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>"  form="edit-row">
      <input type="hidden" name="filter_q"              value="<?= htmlspecialchars($fQ) ?>"                         form="edit-row">
    </form>
  <?php endif ?>

  <div class="settings-table-wrap">
    <table class="settings-table">
      <thead>
        <tr>
          <th>Nom</th>
          <th>Classif.</th>
          <th>Sous-type</th>
          <th>Client</th>
          <th>Short</th>
          <th>Vint.</th>
          <th>SKU pfx</th>
          <th>Act.</th>
          <th class="settings-table__actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="settings-table__empty">Aucune recette.</td></tr>
        <?php endif ?>
        <?php foreach ($rows as $r): ?>
          <?php $isEdit = ((int) $r["id"] === $editId); ?>
          <?php if ($isEdit): ?>
            <tr class="settings-table__row settings-table__row--edit">
              <td><input form="edit-row" name="name" type="text" required value="<?= htmlspecialchars((string) $r["name"]) ?>" class="settings-input--wide"></td>
              <td>
                <select form="edit-row" name="classification">
                  <?php foreach (RECIPE_CLASSIFICATIONS as $c): ?>
                    <option value="<?= $c ?>"<?= $r["classification"] === $c ? " selected" : "" ?>><?= $c ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td>
                <select form="edit-row" name="subtype">
                  <option value=""<?= $r["subtype"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach (RECIPE_SUBTYPES as $s): ?>
                    <option value="<?= $s ?>"<?= $r["subtype"] === $s ? " selected" : "" ?>><?= $s ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td>
                <select form="edit-row" name="client_id">
                  <option value=""<?= $r["client_id"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach ($clients as $c): ?>
                    <option value="<?= (int) $c["id"] ?>"<?= (int) $r["client_id"] === (int) $c["id"] ? " selected" : "" ?>><?= htmlspecialchars((string) $c["name"]) ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td><input form="edit-row" name="recipe_short_name" type="text" value="<?= htmlspecialchars((string) ($r["recipe_short_name"] ?? "")) ?>"></td>
              <td><input form="edit-row" name="vintage" type="text" value="<?= htmlspecialchars((string) ($r["vintage"] ?? "")) ?>" maxlength="8" style="max-width:60px"></td>
              <td><input form="edit-row" name="sku_prefix" type="text" value="<?= htmlspecialchars((string) ($r["sku_prefix"] ?? "")) ?>" maxlength="8" style="max-width:60px"></td>
              <td class="settings-mono"><input form="edit-row" type="checkbox" name="is_active" value="1"<?= $r["is_active"] ? " checked" : "" ?>></td>
              <td class="settings-table__actions">
                <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
                <a href="<?= htmlspecialchars("?" . build_qs_recipes([])) ?>" class="settings-btn settings-btn--cancel">Annuler</a>
              </td>
            </tr>
          <?php else: ?>
            <tr class="settings-table__row<?= ($r["last_modified_by"] ?? '') === 'web' ? ' settings-table__row--pinned' : '' ?>">
              <td><?= ($r["last_modified_by"] ?? '') === 'web' ? '<span class="settings-pin" title="Épinglé (web) — préservé par l\'ingest">📌</span> ' : '' ?><?= htmlspecialchars((string) $r["name"]) ?></td>
              <td><span class="settings-pill settings-pill--<?= htmlspecialchars(strtolower((string) $r["classification"])) ?>"><?= htmlspecialchars((string) $r["classification"]) ?></span></td>
              <td><?= $r["subtype"] ? '<span class="settings-pill">' . htmlspecialchars((string) $r["subtype"]) . '</span>' : '<span class="settings-muted">—</span>' ?></td>
              <td><?= $r["client_name"] ? htmlspecialchars((string) $r["client_name"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["recipe_short_name"] ? htmlspecialchars((string) $r["recipe_short_name"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["vintage"] ? htmlspecialchars((string) $r["vintage"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["sku_prefix"] ? htmlspecialchars((string) $r["sku_prefix"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["is_active"] ? "✓" : '<span class="settings-muted">·</span>' ?></td>
              <td class="settings-table__actions">
                <a href="?<?= htmlspecialchars(build_qs_recipes(["edit" => (int) $r["id"]])) ?>" class="settings-btn">Modifier</a>
                <form method="post" class="settings-inline-form"
                      onsubmit="return confirm('Supprimer la recette « <?= htmlspecialchars((string) $r["name"], ENT_QUOTES) ?> » ?');">
                  <input type="hidden" name="csrf"                  value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"                value="delete">
                  <input type="hidden" name="id"                    value="<?= (int) $r["id"] ?>">
                  <input type="hidden" name="filter_classification" value="<?= htmlspecialchars((string) ($fClass ?? "")) ?>">
                  <input type="hidden" name="filter_subtype"        value="<?= htmlspecialchars((string) ($fSubtype ?? "")) ?>">
                  <input type="hidden" name="filter_active"         value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>">
                  <input type="hidden" name="filter_q"              value="<?= htmlspecialchars($fQ) ?>">
                  <button type="submit" class="settings-btn settings-btn--del">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endif ?>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($lastPage > 0): ?>
    <nav class="db-pagination" aria-label="Pagination">
      <?php if ($page > 0): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_recipes(["page" => $page - 1])) ?>">← précédent</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← précédent</span>
      <?php endif ?>
      <span class="db-pagination__pos">page <?= $page + 1 ?> / <?= $lastPage + 1 ?></span>
      <?php if ($page < $lastPage): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_recipes(["page" => $page + 1])) ?>">suivant →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suivant →</span>
      <?php endif ?>
    </nav>
  <?php endif ?>

  <!-- Add new -->
  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter une recette</h2>
    <form method="post" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label class="settings-add__field settings-add__field--wide">
        <span>Nom (clé naturelle, doit matcher BeerTypes)</span>
        <input name="name" type="text" required placeholder="ex. EPH-2026-1">
      </label>
      <label class="settings-add__field">
        <span>Classification</span>
        <select name="classification">
          <?php foreach (RECIPE_CLASSIFICATIONS as $c): ?>
            <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field">
        <span>Sous-type</span>
        <select name="subtype">
          <option value="">—</option>
          <?php foreach (RECIPE_SUBTYPES as $s): ?>
            <option value="<?= $s ?>"><?= $s ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field">
        <span>Client</span>
        <select name="client_id">
          <option value="">—</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c["id"] ?>"><?= htmlspecialchars((string) $c["name"]) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field">
        <span>Short name</span>
        <input name="recipe_short_name" type="text" placeholder="ex. Zepp">
      </label>
      <label class="settings-add__field">
        <span>Vintage</span>
        <input name="vintage" type="text" placeholder="ex. 2026" maxlength="8">
      </label>
      <label class="settings-add__field">
        <span>SKU prefix</span>
        <input name="sku_prefix" type="text" placeholder="ex. ZEP" maxlength="8">
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
