<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

// Vessel type discriminator. Validated against a hard whitelist before
// any SQL touches the table.
const VESSEL_TYPES = ["cct" => "ref_cct", "yt" => "ref_yt", "bbt" => "ref_bbt"];
const VESSEL_LABELS = [
    "cct" => "CCT — Cylindro-conical (fermenteurs)",
    "yt"  => "YT — Yeast tanks (levures)",
    "bbt" => "BBT — Bright beer tanks (clarification)",
];
$type = $_GET["type"] ?? "cct";
if (!array_key_exists($type, VESSEL_TYPES)) $type = "cct";
$table = VESSEL_TYPES[$type];

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Cuves"];

// ── POST handler (create / update / delete) ────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/vessels.php?type={$type}");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create") {
            $number = post_int("number");
            if ($number === null || $number < 1) {
                throw new RuntimeException("Numéro requis (entier positif).");
            }
            $cap    = post_decimal("capacity_hl");
            $status = must_be_one_of("status", $_POST["status"] ?? "active", VESSEL_STATUSES);
            $notes  = post_str("notes");

            $stmt = $pdo->prepare(
                "INSERT INTO `{$table}` (number, capacity_hl, status, notes) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$number, $cap, $status, $notes]);
            flash_set("ok", "Cuve {$type} #{$number} ajoutée.");
        } elseif ($action === "update") {
            $id     = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $number = post_int("number");
            if ($number === null || $number < 1) {
                throw new RuntimeException("Numéro requis (entier positif).");
            }
            $cap    = post_decimal("capacity_hl");
            $status = must_be_one_of("status", $_POST["status"] ?? "active", VESSEL_STATUSES);
            $notes  = post_str("notes");

            $stmt = $pdo->prepare(
                "UPDATE `{$table}` SET number = ?, capacity_hl = ?, status = ?, notes = ? WHERE id = ?"
            );
            $stmt->execute([$number, $cap, $status, $notes, $id]);
            flash_set("ok", "Cuve mise à jour.");
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Cuve supprimée.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }
    redirect_to("/admin/settings/vessels.php?type={$type}");
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

try {
    $pdo  = maltytask_pdo();
    $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY number ASC")->fetchAll();
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
  <title>Cuves — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-vessels">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · cuves</span>
    <h1 class="settings__head-title">Cuves</h1>
    <p class="settings__head-tag">
      Capacités et statuts des fermenteurs (CCT), tanks à levure (YT) et BBT.
      Aucun pipeline d'ingest ne touche ces tables — les modifications sont durables.
    </p>
  </header>

  <!-- Type tabs (CCT / YT / BBT) -->
  <nav class="settings-tabs" aria-label="Type de cuve">
    <?php foreach (VESSEL_TYPES as $k => $_t): ?>
      <a class="settings-tabs__tab<?= $k === $type ? ' settings-tabs__tab--active' : '' ?>"
         href="?type=<?= htmlspecialchars($k) ?>">
        <?= htmlspecialchars(strtoupper($k)) ?>
      </a>
    <?php endforeach ?>
  </nav>

  <p class="settings-vessels__sub"><?= htmlspecialchars(VESSEL_LABELS[$type]) ?></p>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <!-- Edit form sits OUTSIDE the table; inputs reference it via the form attribute. -->
  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="?type=<?= htmlspecialchars($type) ?>">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>" form="edit-row">
      <input type="hidden" name="action" value="update"                          form="edit-row">
      <input type="hidden" name="id"     value="<?= (int) $editId ?>"            form="edit-row">
    </form>
  <?php endif ?>

  <table class="settings-table">
    <thead>
      <tr>
        <th>Numéro</th>
        <th>Capacité (HL)</th>
        <th>Statut</th>
        <th>Notes</th>
        <th class="settings-table__actions">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="settings-table__empty">Aucune cuve enregistrée.</td></tr>
      <?php endif ?>
      <?php foreach ($rows as $r): ?>
        <?php $isEdit = ((int) $r["id"] === $editId); ?>
        <?php if ($isEdit): ?>
          <tr class="settings-table__row settings-table__row--edit">
            <td>
              <input form="edit-row" name="number" type="number" min="1"
                     value="<?= htmlspecialchars((string) $r["number"]) ?>" required>
            </td>
            <td>
              <input form="edit-row" name="capacity_hl" type="text" inputmode="decimal"
                     value="<?= htmlspecialchars((string) ($r["capacity_hl"] ?? "")) ?>"
                     placeholder="—">
            </td>
            <td>
              <select form="edit-row" name="status">
                <?php foreach (VESSEL_STATUSES as $s): ?>
                  <option value="<?= $s ?>"<?= $r["status"] === $s ? " selected" : "" ?>><?= $s ?></option>
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
              <a href="?type=<?= htmlspecialchars($type) ?>" class="settings-btn settings-btn--cancel">Annuler</a>
            </td>
          </tr>
        <?php else: ?>
          <tr class="settings-table__row">
            <td class="settings-mono"><?= htmlspecialchars((string) $r["number"]) ?></td>
            <td class="settings-mono"><?= $r["capacity_hl"] !== null ? htmlspecialchars((string) $r["capacity_hl"]) : '<span class="settings-muted">—</span>' ?></td>
            <td>
              <span class="settings-pill settings-pill--<?= htmlspecialchars($r["status"]) ?>">
                <?= htmlspecialchars($r["status"]) ?>
              </span>
            </td>
            <td><?= $r["notes"] ? htmlspecialchars($r["notes"]) : '<span class="settings-muted">—</span>' ?></td>
            <td class="settings-table__actions">
              <a href="?type=<?= htmlspecialchars($type) ?>&edit=<?= (int) $r["id"] ?>" class="settings-btn">Modifier</a>
              <form method="post" action="?type=<?= htmlspecialchars($type) ?>" class="settings-inline-form"
                    onsubmit="return confirm('Supprimer la cuve <?= htmlspecialchars((string) $r["number"]) ?> ?');">
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

  <!-- Add new -->
  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter</h2>
    <form method="post" action="?type=<?= htmlspecialchars($type) ?>" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label class="settings-add__field">
        <span>Numéro</span>
        <input name="number" type="number" min="1" required>
      </label>
      <label class="settings-add__field">
        <span>Capacité (HL)</span>
        <input name="capacity_hl" type="text" inputmode="decimal" placeholder="ex. 90">
      </label>
      <label class="settings-add__field">
        <span>Statut</span>
        <select name="status">
          <?php foreach (VESSEL_STATUSES as $s): ?>
            <option value="<?= $s ?>"<?= $s === "active" ? " selected" : "" ?>><?= $s ?></option>
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
