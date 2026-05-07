<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Clients"];

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/clients.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create") {
            $name  = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $notes = post_str("notes");
            $stmt  = $pdo->prepare("INSERT INTO ref_clients (name, notes) VALUES (?, ?)");
            $stmt->execute([$name, $notes]);
            flash_set("ok", "Client « {$name} » ajouté.");
        } elseif ($action === "update") {
            $id    = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $name  = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $notes = post_str("notes");
            $stmt  = $pdo->prepare("UPDATE ref_clients SET name = ?, notes = ? WHERE id = ?");
            $stmt->execute([$name, $notes, $id]);
            flash_set("ok", "Client mis à jour.");
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM ref_clients WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Client supprimé.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }
    redirect_to("/admin/settings/clients.php");
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

try {
    $pdo  = maltytask_pdo();
    // Join with ref_recipes to show how many recipes reference each client.
    $rows = $pdo->query("
        SELECT c.id, c.name, c.notes, c.created_at,
               COUNT(r.id) AS recipe_count
        FROM ref_clients c
        LEFT JOIN ref_recipes r ON r.client_id = c.id
        GROUP BY c.id
        ORDER BY c.name
    ")->fetchAll();
} catch (Throwable $e) {
    $rows    = [];
    $loadErr = $e->getMessage();
}

$editId = isset($_GET["edit"]) && ctype_digit((string) $_GET["edit"]) ? (int) $_GET["edit"] : null;
$csrf   = csrf_token();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clients — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-clients">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · clients</span>
    <h1 class="settings__head-title">Clients</h1>
    <p class="settings__head-tag">
      Clients référencés par les recettes contract / collab. Supprimer un
      client référencé par une recette est bloqué par contrainte FK.
    </p>
  </header>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>" form="edit-row">
      <input type="hidden" name="action" value="update"                          form="edit-row">
      <input type="hidden" name="id"     value="<?= (int) $editId ?>"            form="edit-row">
    </form>
  <?php endif ?>

  <table class="settings-table">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Notes</th>
        <th>Recettes liées</th>
        <th class="settings-table__actions">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" class="settings-table__empty">Aucun client enregistré.</td></tr>
      <?php endif ?>
      <?php foreach ($rows as $r): ?>
        <?php $isEdit = ((int) $r["id"] === $editId); ?>
        <?php if ($isEdit): ?>
          <tr class="settings-table__row settings-table__row--edit">
            <td>
              <input form="edit-row" name="name" type="text" required
                     value="<?= htmlspecialchars((string) $r["name"]) ?>"
                     class="settings-input--wide">
            </td>
            <td>
              <input form="edit-row" name="notes" type="text"
                     value="<?= htmlspecialchars((string) ($r["notes"] ?? "")) ?>"
                     class="settings-input--wide" placeholder="—">
            </td>
            <td class="settings-mono"><?= (int) $r["recipe_count"] ?></td>
            <td class="settings-table__actions">
              <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
              <a href="" class="settings-btn settings-btn--cancel">Annuler</a>
            </td>
          </tr>
        <?php else: ?>
          <tr class="settings-table__row">
            <td><?= htmlspecialchars((string) $r["name"]) ?></td>
            <td><?= $r["notes"] ? htmlspecialchars($r["notes"]) : '<span class="settings-muted">—</span>' ?></td>
            <td class="settings-mono"><?= (int) $r["recipe_count"] ?></td>
            <td class="settings-table__actions">
              <a href="?edit=<?= (int) $r["id"] ?>" class="settings-btn">Modifier</a>
              <form method="post" class="settings-inline-form"
                    onsubmit="return confirm('Supprimer le client « <?= htmlspecialchars($r["name"], ENT_QUOTES) ?> » ?');">
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int) $r["id"] ?>">
                <button type="submit" class="settings-btn settings-btn--del"
                        <?= ((int) $r["recipe_count"] > 0) ? 'title="Référencé par des recettes — suppression sera bloquée par FK"' : '' ?>>
                  Supprimer
                </button>
              </form>
            </td>
          </tr>
        <?php endif ?>
      <?php endforeach ?>
    </tbody>
  </table>

  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter</h2>
    <form method="post" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <label class="settings-add__field settings-add__field--wide">
        <span>Nom</span>
        <input name="name" type="text" required placeholder="ex. Tap & More">
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
