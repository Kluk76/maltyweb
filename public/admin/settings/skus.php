<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "SKU"];

const PAGE_SIZE = 50;

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/skus.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create" || $action === "update") {
            $skuCode    = post_str("sku_code");
            if ($skuCode === null) throw new RuntimeException("sku_code requis.");
            $recipeId   = post_int("recipe_id");
            $beerRaw    = post_str("beer_raw");
            $format     = post_str("format");
            $unitLabel  = post_str("unit_label");
            $hlPerUnit  = post_decimal("hl_per_unit");
            $isActive   = !empty($_POST["is_active"]) ? 1 : 0;

            $hash = compute_row_hash([$skuCode, $recipeId, $beerRaw, $format,
                                      $unitLabel, $hlPerUnit, $isActive]);

            if ($action === "create") {
                $stmt = $pdo->prepare(
                    "INSERT INTO ref_skus
                     (sku_code, recipe_id, beer_raw, format, unit_label, hl_per_unit, is_active, row_hash, last_modified_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'web')"
                );
                $stmt->execute([$skuCode, $recipeId, $beerRaw, $format, $unitLabel, $hlPerUnit, $isActive, $hash]);
                flash_set("ok", "SKU « {$skuCode} » ajouté et épinglé (web).");
            } else {
                $id = post_int("id");
                if ($id === null) throw new RuntimeException("ID manquant.");
                $stmt = $pdo->prepare(
                    "UPDATE ref_skus SET
                       sku_code = ?, recipe_id = ?, beer_raw = ?, format = ?,
                       unit_label = ?, hl_per_unit = ?, is_active = ?, row_hash = ?,
                       last_modified_by = 'web'
                     WHERE id = ?"
                );
                $stmt->execute([$skuCode, $recipeId, $beerRaw, $format, $unitLabel, $hlPerUnit, $isActive, $hash, $id]);
                flash_set("ok", "SKU mis à jour et épinglé (web).");
            }
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            // ref_sku_bom has ON DELETE CASCADE, so BOM lines clean up automatically.
            $stmt = $pdo->prepare("DELETE FROM ref_skus WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "SKU supprimé (lignes BOM associées en cascade).");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }

    $qs = http_build_query(array_filter([
        "format" => $_POST["filter_format"] ?? null,
        "active" => $_POST["filter_active"] ?? null,
        "q"      => $_POST["filter_q"]      ?? null,
    ], fn($v) => $v !== null && $v !== ""));
    redirect_to("/admin/settings/skus.php" . ($qs ? "?{$qs}" : ""));
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

$fFormat = trim((string) ($_GET["format"] ?? ""));
$fActive = $_GET["active"] ?? null;
if (!in_array($fActive, ["0", "1"], true)) $fActive = null;
$fQ      = trim((string) ($_GET["q"] ?? ""));
$page    = max(0, (int) ($_GET["page"] ?? 0));

$where  = [];
$params = [];
if ($fFormat !== "")   { $where[] = "s.format = ?";    $params[] = $fFormat; }
if ($fActive !== null) { $where[] = "s.is_active = ?"; $params[] = (int) $fActive; }
if ($fQ !== "")        { $where[] = "(s.sku_code LIKE ? OR s.beer_raw LIKE ?)";
                          $params[] = "%{$fQ}%";
                          $params[] = "%{$fQ}%"; }
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $pdo = maltytask_pdo();

    $formats = $pdo->query(
        "SELECT DISTINCT format FROM ref_skus WHERE format IS NOT NULL ORDER BY format"
    )->fetchAll(PDO::FETCH_COLUMN);

    $recipes = $pdo->query(
        "SELECT id, name FROM ref_recipes WHERE is_active = 1 ORDER BY name"
    )->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ref_skus s {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $lastPage  = $totalRows > 0 ? (int) floor(($totalRows - 1) / PAGE_SIZE) : 0;

    $sql = "SELECT s.*,
                   r.name AS recipe_name,
                   (SELECT COUNT(*) FROM ref_sku_bom b WHERE b.sku_id = s.id) AS bom_count
            FROM ref_skus s
            LEFT JOIN ref_recipes r ON r.id = s.recipe_id
            {$whereSql}
            ORDER BY s.sku_code
            LIMIT " . PAGE_SIZE . " OFFSET " . ($page * PAGE_SIZE);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = []; $formats = []; $recipes = []; $totalRows = 0; $lastPage = 0;
    $loadErr = $e->getMessage();
}

$editId = isset($_GET["edit"]) && ctype_digit((string) $_GET["edit"]) ? (int) $_GET["edit"] : null;
$csrf   = csrf_token();

function build_qs_skus(array $extra): string {
    $base = [];
    foreach (["format", "active", "q", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") $base[$k] = $_GET[$k];
    }
    return http_build_query(array_merge($base, $extra));
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SKU — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-skus">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>
  <?php render_ingest_warning("ref_skus", "scripts/python/ingest_sku_bom.py") ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · sku</span>
    <h1 class="settings__head-title">SKU</h1>
    <p class="settings__head-tag">
      Codes SKU sellables (ZEPF, ZEP4, ZEPC…). Le compteur « BOM » indique le
      nombre de lignes Bill-of-Materials liées (en lecture seule — toute la BOM
      est purgée et reconstruite à chaque ingest).
      <strong><?= $totalRows ?></strong> SKU<?= $totalRows > 1 ? "s" : "" ?>
      au total<?= !empty($where) ? " (filtré)" : "" ?>.
    </p>
  </header>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <form class="db-filter" method="get" action="">
    <label class="db-filter__field db-filter__field--wide">
      <span class="db-filter__label">Recherche</span>
      <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="sku_code ou beer_raw">
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">Format</span>
      <select name="format">
        <option value="">tous</option>
        <?php foreach ($formats as $f): ?>
          <option value="<?= htmlspecialchars((string) $f) ?>"<?= $fFormat === $f ? " selected" : "" ?>><?= htmlspecialchars((string) $f) ?></option>
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
      <a href="/admin/settings/skus.php" class="settings-btn settings-btn--cancel">Réinitialiser</a>
    <?php endif ?>
  </form>

  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="">
      <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>"            form="edit-row">
      <input type="hidden" name="action"        value="update"                                     form="edit-row">
      <input type="hidden" name="id"            value="<?= (int) $editId ?>"                       form="edit-row">
      <input type="hidden" name="filter_format" value="<?= htmlspecialchars($fFormat) ?>"          form="edit-row">
      <input type="hidden" name="filter_active" value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>" form="edit-row">
      <input type="hidden" name="filter_q"      value="<?= htmlspecialchars($fQ) ?>"               form="edit-row">
    </form>
  <?php endif ?>

  <div class="settings-table-wrap">
    <table class="settings-table">
      <thead>
        <tr>
          <th>sku_code</th>
          <th>Beer (raw)</th>
          <th>Recette</th>
          <th>Format</th>
          <th>Unit label</th>
          <th>HL/unité</th>
          <th>BOM</th>
          <th>Actif</th>
          <th class="settings-table__actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="settings-table__empty">Aucun SKU.</td></tr>
        <?php endif ?>
        <?php foreach ($rows as $r): ?>
          <?php $isEdit = ((int) $r["id"] === $editId); ?>
          <?php if ($isEdit): ?>
            <tr class="settings-table__row settings-table__row--edit">
              <td><input form="edit-row" name="sku_code" type="text" required value="<?= htmlspecialchars((string) $r["sku_code"]) ?>" maxlength="16" style="max-width:120px"></td>
              <td><input form="edit-row" name="beer_raw" type="text" value="<?= htmlspecialchars((string) ($r["beer_raw"] ?? "")) ?>"></td>
              <td>
                <select form="edit-row" name="recipe_id">
                  <option value=""<?= $r["recipe_id"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach ($recipes as $rec): ?>
                    <option value="<?= (int) $rec["id"] ?>"<?= (int) ($r["recipe_id"] ?? 0) === (int) $rec["id"] ? " selected" : "" ?>><?= htmlspecialchars((string) $rec["name"]) ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td>
                <select form="edit-row" name="format">
                  <option value=""<?= $r["format"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach ($formats as $f): ?>
                    <option value="<?= htmlspecialchars((string) $f) ?>"<?= $r["format"] === $f ? " selected" : "" ?>><?= htmlspecialchars((string) $f) ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td><input form="edit-row" name="unit_label" type="text" value="<?= htmlspecialchars((string) ($r["unit_label"] ?? "")) ?>" class="settings-input--wide"></td>
              <td><input form="edit-row" name="hl_per_unit" type="text" inputmode="decimal" value="<?= htmlspecialchars((string) ($r["hl_per_unit"] ?? "")) ?>" style="max-width:100px"></td>
              <td class="settings-mono"><?= (int) $r["bom_count"] ?></td>
              <td class="settings-mono"><input form="edit-row" type="checkbox" name="is_active" value="1"<?= $r["is_active"] ? " checked" : "" ?>></td>
              <td class="settings-table__actions">
                <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
                <a href="?<?= htmlspecialchars(build_qs_skus([])) ?>" class="settings-btn settings-btn--cancel">Annuler</a>
              </td>
            </tr>
          <?php else: ?>
            <tr class="settings-table__row<?= ($r["last_modified_by"] ?? '') === 'web' ? ' settings-table__row--pinned' : '' ?>">
              <td class="settings-mono"><?= ($r["last_modified_by"] ?? '') === 'web' ? '<span class="settings-pin" title="Épinglé (web)">📌</span> ' : '' ?><?= htmlspecialchars((string) $r["sku_code"]) ?></td>
              <td><?= $r["beer_raw"] ? htmlspecialchars((string) $r["beer_raw"]) : '<span class="settings-muted">—</span>' ?></td>
              <td><?= $r["recipe_name"] ? htmlspecialchars((string) $r["recipe_name"]) : '<span class="settings-muted">—</span>' ?></td>
              <td><?= $r["format"] ? '<span class="settings-pill">' . htmlspecialchars((string) $r["format"]) . '</span>' : '<span class="settings-muted">—</span>' ?></td>
              <td><?= $r["unit_label"] ? htmlspecialchars((string) $r["unit_label"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["hl_per_unit"] !== null ? htmlspecialchars((string) $r["hl_per_unit"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= (int) $r["bom_count"] ?></td>
              <td class="settings-mono"><?= $r["is_active"] ? "✓" : '<span class="settings-muted">·</span>' ?></td>
              <td class="settings-table__actions">
                <a href="?<?= htmlspecialchars(build_qs_skus(["edit" => (int) $r["id"]])) ?>" class="settings-btn">Modifier</a>
                <form method="post" class="settings-inline-form"
                      onsubmit="return confirm('Supprimer le SKU « <?= htmlspecialchars((string) $r["sku_code"], ENT_QUOTES) ?> » et ses <?= (int) $r["bom_count"] ?> lignes BOM en cascade ?');">
                  <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"        value="delete">
                  <input type="hidden" name="id"            value="<?= (int) $r["id"] ?>">
                  <input type="hidden" name="filter_format" value="<?= htmlspecialchars($fFormat) ?>">
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
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_skus(["page" => $page - 1])) ?>">← précédent</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← précédent</span>
      <?php endif ?>
      <span class="db-pagination__pos">page <?= $page + 1 ?> / <?= $lastPage + 1 ?></span>
      <?php if ($page < $lastPage): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_skus(["page" => $page + 1])) ?>">suivant →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suivant →</span>
      <?php endif ?>
    </nav>
  <?php endif ?>

  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter un SKU</h2>
    <form method="post" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label class="settings-add__field">
        <span>sku_code (clé)</span>
        <input name="sku_code" type="text" required placeholder="ex. ZEPF" maxlength="16">
      </label>
      <label class="settings-add__field">
        <span>Beer (raw)</span>
        <input name="beer_raw" type="text" placeholder="ex. Zepp">
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Recette</span>
        <select name="recipe_id">
          <option value="">—</option>
          <?php foreach ($recipes as $rec): ?>
            <option value="<?= (int) $rec["id"] ?>"><?= htmlspecialchars((string) $rec["name"]) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field">
        <span>Format</span>
        <select name="format">
          <option value="">—</option>
          <?php foreach ($formats as $f): ?>
            <option value="<?= htmlspecialchars((string) $f) ?>"><?= htmlspecialchars((string) $f) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Unit label</span>
        <input name="unit_label" type="text" placeholder="ex. 24-pack box (24 × 33cl)">
      </label>
      <label class="settings-add__field">
        <span>HL/unité</span>
        <input name="hl_per_unit" type="text" inputmode="decimal" placeholder="ex. 0.0792">
      </label>
      <label class="settings-add__field">
        <span>Actif</span>
        <select name="is_active">
          <option value="1" selected>oui</option>
          <option value="0">non</option>
        </select>
      </label>
      <button type="submit" class="settings-btn settings-btn--add">Ajouter</button>
    </form>
  </section>

</main>

</body>
</html>
