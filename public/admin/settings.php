<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";

require_manager_or_admin();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres"];

// Each card: [key, title, table, description, phase, href|null].
// href=null → disabled placeholder card (future phase).
$entities = [
    ["recipes",     "Recettes",         "ref_recipes",        "Recettes : classification, vintage, sous-type",     null,      "/admin/settings/recipes.php"],
    ["skus",        "SKU",              "ref_skus",           "Codes SKU + format + volume par unité",              null,      "/admin/settings/skus.php"],
    ["vessels",     "Cuves",            "ref_cct / yt / bbt", "CCT, YT, BBT — capacités et statuts",                null,      "/admin/settings/vessels.php"],
    ["clients",     "Clients",          "ref_clients",        "Clients contract / collab",                          null,      "/admin/settings/clients.php"],
    ["yeasts",      "Levures",          "ref_yeast_strains",  "Souches de levure et fournisseurs",                  null,      "/admin/settings/yeasts.php"],
    ["suppliers",   "Suppliers",        "ref_suppliers",      "Suppliers — un par paire (supplier, GL)",            null,      "/admin/settings/suppliers.php"],
    ["ingredients", "Ingrédients (MI)", "ref_mi",             "MasterIngredients — catégories, prix, alias",        null,      "/admin/settings/ingredients.php"],
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres</span>
    <h1 class="settings__head-title">Données de référence</h1>
    <p class="settings__head-tag">
      Édition des tables référentielles. Avertissement : pour les entités
      ingérées depuis BSF (Recettes, Suppliers, MI, SKU), les modifications
      seront écrasées au prochain run d'ingest Python.
    </p>
  </header>

  <section class="settings__grid" aria-label="Entités">
    <?php foreach ($entities as [$key, $title, $table, $desc, $phase, $href]): ?>
      <?php if ($href !== null): ?>
        <a class="settings__card settings__card--live" href="<?= htmlspecialchars($href) ?>" data-key="<?= htmlspecialchars($key) ?>">
          <div class="settings__card-head">
            <span class="settings__card-title"><?= htmlspecialchars($title) ?></span>
            <span class="settings__card-arrow" aria-hidden="true">→</span>
          </div>
          <p class="settings__card-table"><?= htmlspecialchars($table) ?></p>
          <p class="settings__card-desc"><?= htmlspecialchars($desc) ?></p>
          <span class="settings__card-state">— ouvrir</span>
        </a>
      <?php else: ?>
        <div class="settings__card settings__card--disabled" data-key="<?= htmlspecialchars($key) ?>">
          <div class="settings__card-head">
            <span class="settings__card-title"><?= htmlspecialchars($title) ?></span>
            <span class="settings__card-badge"><?= htmlspecialchars($phase) ?></span>
          </div>
          <p class="settings__card-table"><?= htmlspecialchars($table) ?></p>
          <p class="settings__card-desc"><?= htmlspecialchars($desc) ?></p>
          <span class="settings__card-state">— à venir</span>
        </div>
      <?php endif ?>
    <?php endforeach ?>
  </section>

</main>

</body>
</html>
