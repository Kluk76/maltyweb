<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/db-correct.php";
require __DIR__ . "/../../app/schema-meta.php";

require_admin();
$me = current_user();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /admin/db-browser.php", true, 303);
    exit;
}

header("Content-Type: text/html; charset=utf-8");

$active_module = "db-browser";
$crumbs        = ["Accueil", "Admin", "DB Browser", "Confirmer"];

$error     = null;
$payload   = null;
$preview   = [];
$token     = null;
$tableMeta = null;

try {
    $pdo       = maltytask_pdo();
    $payload   = dbcorrect_validate($pdo, $_POST);
    $tableMeta = schema_meta_for_table($pdo, $payload["table"]);
    $policy    = $tableMeta["corrections_policy"] ?? "allowed";
    if ($policy === "blocked" || $policy === "blocked_with_redirect") {
        $hint = $tableMeta["upstream_hint"] ?? null;
        $class = $tableMeta["table_class"] ?? "";
        if ($policy === "blocked_with_redirect") {
            $msg = "Table dérivée — modifications désactivées.";
            if ($hint !== null && $hint !== "") {
                $msg .= " Pour corriger : " . $hint;
            }
        } else {
            $msg = "Lecture seule (table {$class}).";
        }
        throw new RuntimeException("⛔ " . $msg);
    }
    $preview = dbcorrect_preview($pdo, $payload);
    if (count($preview) === 0) {
        throw new RuntimeException("Aucune ligne ne correspond aux IDs sélectionnés "
            . "(elles ont peut-être été supprimées entre-temps).");
    }
    $token = dbcorrect_store_pending($payload);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function fmt_val($v): string {
    if ($v === null) return "<span class=\"db-cell--null\">NULL</span>";
    $s = (string) $v;
    if (strlen($s) > 200) $s = substr($s, 0, 200) . "…";
    return htmlspecialchars($s);
}

$csrf = csrf_token();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Confirmer la correction — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home admin db-browser db-browser--confirm">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main db-browser__main">

  <?php if ($error !== null): ?>
    <div class="wort-error">
      <?= htmlspecialchars($error) ?>
    </div>
    <p>
      <a href="javascript:history.back()" class="db-pagination__link">← Retour</a>
    </p>
  <?php else: ?>

    <header class="db-confirm__head">
      <span class="db-confirm__eyebrow">— admin · correction · confirmation</span>
      <h1 class="db-confirm__title">
        <?php if ($payload["action"] === "update"): ?>
          Modifier <code><?= htmlspecialchars($payload["column"]) ?></code>
          sur <strong><?= count($preview) ?></strong> ligne<?= count($preview) > 1 ? "s" : "" ?>
          de <code><?= htmlspecialchars($payload["table"]) ?></code>
        <?php else: ?>
          Supprimer <strong><?= count($preview) ?></strong> ligne<?= count($preview) > 1 ? "s" : "" ?>
          de <code><?= htmlspecialchars($payload["table"]) ?></code>
        <?php endif ?>
      </h1>
    </header>

    <section class="db-confirm__diff">
      <?php if ($payload["action"] === "update"): ?>
        <table class="db-table db-confirm__table">
          <thead>
            <tr>
              <th><?= htmlspecialchars($payload["pk_column"]) ?></th>
              <th>Avant — <?= htmlspecialchars($payload["column"]) ?></th>
              <th>Après</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $r): ?>
              <tr>
                <td class="db-td db-td--mono"><?= htmlspecialchars((string) $r["_pk"]) ?></td>
                <td class="db-td db-td--old"><?= fmt_val($r["_old"]) ?></td>
                <td class="db-td db-td--new">
                  <?= $payload["set_null"]
                      ? "<span class=\"db-cell--null\">NULL</span>"
                      : htmlspecialchars((string) $payload["new_value"]) ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="db-confirm__warn">
          ⚠ Suppression définitive. Les valeurs actuelles seront archivées
          dans <code>debug_corrections.old_values</code>, mais la
          restauration n'est pas automatique.
        </p>
        <table class="db-table db-confirm__table">
          <thead>
            <tr>
              <?php if (!empty($preview)): ?>
                <?php foreach (array_keys($preview[0]) as $col): ?>
                  <th><?= htmlspecialchars($col) ?></th>
                <?php endforeach ?>
              <?php endif ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $r): ?>
              <tr>
                <?php foreach ($r as $v): ?>
                  <td class="db-td"><?= fmt_val($v) ?></td>
                <?php endforeach ?>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </section>

    <?php
    $sideEffectPolicy = ($tableMeta !== null && $tableMeta["corrections_policy"] === "allowed_with_side_effect");
    if ($sideEffectPolicy):
        $sideEffectHint = $tableMeta["upstream_hint"] ?? null;
        $aliasPreview = dbcorrect_is_alias_trigger($payload)
            ? dbcorrect_alias_preview($pdo, $payload)
            : [];
    ?>
      <section class="db-confirm__side-effect">
        <h2 class="db-confirm__side-effect-title">&#9888;&#65039; Effet de bord</h2>
        <?php if ($sideEffectHint !== null && $sideEffectHint !== ""): ?>
          <p class="db-confirm__side-effect-intro"><?= htmlspecialchars($sideEffectHint) ?></p>
        <?php endif ?>
        <?php if (!empty($aliasPreview)): ?>
        <table class="db-table db-confirm__side-effect-table">
          <thead>
            <tr>
              <th>raw_name (alias normalisé)</th>
              <th>→ mi_id_fk</th>
              <th>Master Ingredient</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aliasPreview as $item): ?>
              <tr>
                <td class="db-td db-td--mono"><?= htmlspecialchars($item["alias"]) ?></td>
                <td class="db-td db-td--mono"><?= (int) $item["mi_id_fk"] ?></td>
                <td class="db-td"><?= htmlspecialchars($item["mi_name"]) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
        <?php endif ?>
      </section>
    <?php endif ?>

    <form class="db-confirm__form" method="post" action="/admin/db-correct-apply.php">
      <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <p class="db-confirm__note">
        <?php if ($payload["action"] === "update"): ?>
          La requête SQL exécutée :
          <code class="db-confirm__sql">UPDATE <?= htmlspecialchars($payload["table"]) ?> SET <?= htmlspecialchars($payload["column"]) ?> = <?= $payload["set_null"] ? "NULL" : "'" . htmlspecialchars((string) $payload["new_value"]) . "'" ?> WHERE <?= htmlspecialchars($payload["pk_column"]) ?> IN (<?= htmlspecialchars(implode(", ", $payload["ids"])) ?>);</code>
        <?php else: ?>
          La requête SQL exécutée :
          <code class="db-confirm__sql">DELETE FROM <?= htmlspecialchars($payload["table"]) ?> WHERE <?= htmlspecialchars($payload["pk_column"]) ?> IN (<?= htmlspecialchars(implode(", ", $payload["ids"])) ?>);</code>
        <?php endif ?>
      </p>

      <div class="db-confirm__actions">
        <a class="db-confirm__cancel"
           href="/admin/db-browser.php?<?= htmlspecialchars(http_build_query(["table" => $payload["table"]])) ?>">
          ← Annuler
        </a>
        <button type="submit" class="db-confirm__submit db-confirm__submit--<?= htmlspecialchars($payload["action"]) ?>">
          <?php if ($payload["action"] === "update"): ?>
            Confirmer & exécuter
          <?php else: ?>
            Confirmer la suppression
          <?php endif ?>
        </button>
      </div>
    </form>

  <?php endif ?>

</main>

</body>
</html>
