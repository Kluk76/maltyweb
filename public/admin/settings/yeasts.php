<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Levures"];

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/yeasts.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create") {
            $name     = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $supplier = post_str("supplier");
            $type     = must_be_one_of("type", $_POST["type"] ?? "unknown", YEAST_TYPES);
            $notes    = post_str("notes");
            $stmt = $pdo->prepare(
                "INSERT INTO ref_yeast_strains (name, supplier, type, notes) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$name, $supplier, $type, $notes]);
            flash_set("ok", "Levure « {$name} » ajoutée.");
        } elseif ($action === "update") {
            $id       = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $name     = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");
            $supplier = post_str("supplier");
            $type     = must_be_one_of("type", $_POST["type"] ?? "unknown", YEAST_TYPES);
            $notes    = post_str("notes");
            $stmt = $pdo->prepare(
                "UPDATE ref_yeast_strains SET name = ?, supplier = ?, type = ?, notes = ? WHERE id = ?"
            );
            $stmt->execute([$name, $supplier, $type, $notes, $id]);
            flash_set("ok", "Levure mise à jour.");
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM ref_yeast_strains WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Levure supprimée.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }
    redirect_to("/admin/settings/yeasts.php");
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

try {
    $pdo  = maltytask_pdo();
    $rows = $pdo->query("SELECT * FROM ref_yeast_strains ORDER BY name")->fetchAll();
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
  <title>Levures — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-yeasts">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · levures</span>
    <h1 class="settings__head-title">Souches de levure</h1>
    <p class="settings__head-tag">
      Catalogue des souches utilisées en fermentation. Le fournisseur est
      libre (pas de FK), le type contraint à un enum réduit.
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
        <th>Fournisseur</th>
        <th>Type</th>
        <th>Notes</th>
        <th class="settings-table__actions">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="settings-table__empty">Aucune levure enregistrée.</td></tr>
      <?php endif ?>
      <?php foreach ($rows as $r): ?>
        <?php $isEdit = ((int) $r["id"] === $editId); ?>
        <?php if ($isEdit): ?>
          <tr class="settings-table__row settings-table__row--edit">
            <td>
              <input form="edit-row" name="name" type="text" required
                     value="<?= htmlspecialchars((string) $r["name"]) ?>">
            </td>
            <td>
              <input form="edit-row" name="supplier" type="text"
                     value="<?= htmlspecialchars((string) ($r["supplier"] ?? "")) ?>"
                     placeholder="—">
            </td>
            <td>
              <select form="edit-row" name="type">
                <?php foreach (YEAST_TYPES as $t): ?>
                  <option value="<?= $t ?>"<?= $r["type"] === $t ? " selected" : "" ?>><?= $t ?></option>
                <?php endforeach ?>
              </select>
            </td>
            <td>
              <input form="edit-row" name="notes" type="text"
                     value="<?= htmlspecialchars((string) ($r["notes"] ?? "")) ?>"
                     placeholder="—" class="settings-input--wide">
            </td>
            <td class="settings-table__actions">
              <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
              <a href="" class="settings-btn settings-btn--cancel">Annuler</a>
            </td>
          </tr>
        <?php else: ?>
          <tr class="settings-table__row">
            <td><?= htmlspecialchars((string) $r["name"]) ?></td>
            <td><?= $r["supplier"] ? htmlspecialchars($r["supplier"]) : '<span class="settings-muted">—</span>' ?></td>
            <td>
              <span class="settings-pill settings-pill--<?= htmlspecialchars($r["type"]) ?>">
                <?= htmlspecialchars($r["type"]) ?>
              </span>
            </td>
            <td><?= $r["notes"] ? htmlspecialchars($r["notes"]) : '<span class="settings-muted">—</span>' ?></td>
            <td class="settings-table__actions">
              <a href="?edit=<?= (int) $r["id"] ?>" class="settings-btn">Modifier</a>
              <form method="post" class="settings-inline-form"
                    onsubmit="return confirm('Supprimer la levure « <?= htmlspecialchars($r["name"], ENT_QUOTES) ?> » ?');">
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int) $r["id"] ?>">
                <button type="submit" class="settings-btn settings-btn--del">Supprimer</button>
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

      <label class="settings-add__field">
        <span>Nom</span>
        <input name="name" type="text" required placeholder="ex. WLP001">
      </label>
      <label class="settings-add__field">
        <span>Fournisseur</span>
        <input name="supplier" type="text" placeholder="—">
      </label>
      <label class="settings-add__field">
        <span>Type</span>
        <select name="type">
          <?php foreach (YEAST_TYPES as $t): ?>
            <option value="<?= $t ?>"<?= $t === "unknown" ? " selected" : "" ?>><?= $t ?></option>
          <?php endforeach ?>
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
