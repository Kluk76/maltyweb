<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/settings-helpers.php";

require_manager_or_admin();
$me = current_user();

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Paramètres", "Ingrédients"];

const PAGE_SIZE = 50;

// Helper: validates subcategory belongs to category.
function validate_subcategory_belongs(PDO $pdo, ?int $catId, ?int $subId): void
{
    if ($subId === null) return;
    $stmt = $pdo->prepare("SELECT category_id FROM ref_mi_subcategories WHERE id = ?");
    $stmt->execute([$subId]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new RuntimeException("Sous-catégorie introuvable.");
    }
    if ($catId === null) {
        throw new RuntimeException("Catégorie requise quand une sous-catégorie est définie.");
    }
    if ((int) $row["category_id"] !== $catId) {
        throw new RuntimeException("La sous-catégorie ne correspond pas à la catégorie sélectionnée.");
    }
}

// ── POST handler ────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/ingredients.php");
    }
    try {
        $pdo    = maltytask_pdo();
        $action = $_POST["action"] ?? "";

        if ($action === "create" || $action === "update") {
            $miId   = post_str("mi_id");
            if ($miId === null) throw new RuntimeException("mi_id requis (clé naturelle).");
            $name   = post_str("name");
            if ($name === null) throw new RuntimeException("Nom requis.");

            $catId        = post_int("category_id");
            $subId        = post_int("subcategory_id");
            validate_subcategory_belongs($pdo, $catId, $subId);

            $inputUnit    = post_str("input_unit");
            $pricingUnit  = post_str("pricing_unit");
            $convFactor   = post_decimal("conversion_factor");
            $currency     = post_str("currency");
            $price        = post_decimal("price");
            $packSize     = post_decimal("pack_size");
            $prefSupplier = post_str("preferred_supplier");
            $glAccount    = post_str("gl_account");
            $notes        = post_str("notes");
            $isActive     = !empty($_POST["is_active"]) ? 1 : 0;

            $hash = compute_row_hash([$miId, $name, $catId, $subId, $inputUnit,
                                      $pricingUnit, $convFactor, $currency, $price,
                                      $packSize, $prefSupplier, $glAccount, $isActive, $notes]);

            if ($action === "create") {
                $stmt = $pdo->prepare(
                    "INSERT INTO ref_mi
                     (mi_id, name, category_id, subcategory_id, input_unit, pricing_unit,
                      conversion_factor, currency, price, pack_size, preferred_supplier,
                      gl_account, notes, is_active, row_hash, last_modified_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'web')"
                );
                $stmt->execute([$miId, $name, $catId, $subId, $inputUnit, $pricingUnit,
                                $convFactor, $currency, $price, $packSize, $prefSupplier,
                                $glAccount, $notes, $isActive, $hash]);
                flash_set("ok", "Ingrédient « {$miId} » ajouté et épinglé (web).");
            } else {
                $id = post_int("id");
                if ($id === null) throw new RuntimeException("ID manquant.");
                $stmt = $pdo->prepare(
                    "UPDATE ref_mi SET
                       mi_id = ?, name = ?, category_id = ?, subcategory_id = ?,
                       input_unit = ?, pricing_unit = ?, conversion_factor = ?,
                       currency = ?, price = ?, pack_size = ?, preferred_supplier = ?,
                       gl_account = ?, notes = ?, is_active = ?, row_hash = ?,
                       last_modified_by = 'web'
                     WHERE id = ?"
                );
                $stmt->execute([$miId, $name, $catId, $subId, $inputUnit, $pricingUnit,
                                $convFactor, $currency, $price, $packSize, $prefSupplier,
                                $glAccount, $notes, $isActive, $hash, $id]);
                flash_set("ok", "Ingrédient mis à jour et épinglé (web).");
            }
        } elseif ($action === "delete") {
            $id = post_int("id");
            if ($id === null) throw new RuntimeException("ID manquant.");
            $stmt = $pdo->prepare("DELETE FROM ref_mi WHERE id = ?");
            $stmt->execute([$id]);
            flash_set("ok", "Ingrédient supprimé.");
        } else {
            flash_set("err", "Action inconnue.");
        }
    } catch (Throwable $e) {
        flash_set("err", pdo_friendly_error($e));
    }

    $qs = http_build_query(array_filter([
        "cat"    => $_POST["filter_cat"]    ?? null,
        "active" => $_POST["filter_active"] ?? null,
        "q"      => $_POST["filter_q"]      ?? null,
    ], fn($v) => $v !== null && $v !== ""));
    redirect_to("/admin/settings/ingredients.php" . ($qs ? "?{$qs}" : ""));
}

// ── GET handler ────────────────────────────────────────────────────────
header("Content-Type: text/html; charset=utf-8");

$fCat    = post_int("cat", $_GET);
$fActive = $_GET["active"] ?? null;
if (!in_array($fActive, ["0", "1"], true)) $fActive = null;
$fQ      = trim((string) ($_GET["q"] ?? ""));
$page    = max(0, (int) ($_GET["page"] ?? 0));

$where  = [];
$params = [];
if ($fCat !== null)    { $where[] = "m.category_id = ?"; $params[] = $fCat; }
if ($fActive !== null) { $where[] = "m.is_active = ?";   $params[] = (int) $fActive; }
if ($fQ !== "")        { $where[] = "(m.name LIKE ? OR m.mi_id LIKE ?)";
                          $params[] = "%{$fQ}%";
                          $params[] = "%{$fQ}%"; }
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

try {
    $pdo = maltytask_pdo();

    $categories = $pdo->query(
        "SELECT id, name, default_gl_account FROM ref_mi_categories ORDER BY name"
    )->fetchAll();

    $subcategories = $pdo->query(
        "SELECT s.id, s.name, s.category_id, s.gl_account, c.name AS category_name
         FROM ref_mi_subcategories s
         JOIN ref_mi_categories c ON c.id = s.category_id
         ORDER BY c.name, s.name"
    )->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ref_mi m {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $lastPage  = $totalRows > 0 ? (int) floor(($totalRows - 1) / PAGE_SIZE) : 0;

    $sql = "SELECT m.*, c.name AS category_name, s.name AS subcategory_name
            FROM ref_mi m
            LEFT JOIN ref_mi_categories    c ON c.id = m.category_id
            LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
            {$whereSql}
            ORDER BY m.mi_id
            LIMIT " . PAGE_SIZE . " OFFSET " . ($page * PAGE_SIZE);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = []; $categories = []; $subcategories = []; $totalRows = 0; $lastPage = 0;
    $loadErr = $e->getMessage();
}

$editId = isset($_GET["edit"]) && ctype_digit((string) $_GET["edit"]) ? (int) $_GET["edit"] : null;
$csrf   = csrf_token();

function build_qs_mi(array $extra): string {
    $base = [];
    foreach (["cat", "active", "q", "page"] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== "") $base[$k] = $_GET[$k];
    }
    return http_build_query(array_merge($base, $extra));
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingrédients (MI) — Paramètres — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin settings-page settings-mi">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <?php flash_render() ?>
  <?php render_ingest_warning("ref_mi", "scripts/python/ingest_mi.py") ?>

  <header class="settings__head">
    <span class="settings__head-eyebrow">— admin · paramètres · ingrédients</span>
    <h1 class="settings__head-title">Ingrédients (MasterIngredients)</h1>
    <p class="settings__head-tag">
      Catalogue MI — un par <code>mi_id</code>. La liste affichée est restreinte
      aux colonnes principales ; ouvre le formulaire « Ajouter » pour voir les
      14 champs disponibles.
      <strong><?= $totalRows ?></strong> ingrédient<?= $totalRows > 1 ? "s" : "" ?>
      au total<?= !empty($where) ? " (filtré)" : "" ?>.
    </p>
  </header>

  <?php if (!empty($loadErr)): ?>
    <div class="wort-error">Erreur : <?= htmlspecialchars($loadErr) ?></div>
  <?php endif ?>

  <form class="db-filter" method="get" action="">
    <label class="db-filter__field db-filter__field--wide">
      <span class="db-filter__label">Recherche</span>
      <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="nom ou mi_id">
    </label>
    <label class="db-filter__field">
      <span class="db-filter__label">Catégorie</span>
      <select name="cat">
        <option value="">toutes</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c["id"] ?>"<?= $fCat === (int) $c["id"] ? " selected" : "" ?>><?= htmlspecialchars((string) $c["name"]) ?></option>
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
      <a href="/admin/settings/ingredients.php" class="settings-btn settings-btn--cancel">Réinitialiser</a>
    <?php endif ?>
  </form>

  <?php if ($editId !== null): ?>
    <form id="edit-row" method="post" action="">
      <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>"            form="edit-row">
      <input type="hidden" name="action"        value="update"                                     form="edit-row">
      <input type="hidden" name="id"            value="<?= (int) $editId ?>"                       form="edit-row">
      <input type="hidden" name="filter_cat"    value="<?= htmlspecialchars((string) ($fCat ?? "")) ?>" form="edit-row">
      <input type="hidden" name="filter_active" value="<?= htmlspecialchars((string) ($fActive ?? "")) ?>" form="edit-row">
      <input type="hidden" name="filter_q"      value="<?= htmlspecialchars($fQ) ?>"               form="edit-row">
    </form>
  <?php endif ?>

  <div class="settings-table-wrap">
    <table class="settings-table">
      <thead>
        <tr>
          <th>mi_id</th>
          <th>Nom</th>
          <th>Cat. > Sub-cat.</th>
          <th>Prix</th>
          <th>Devise</th>
          <th>Pack</th>
          <th>Unité prix</th>
          <th>GL</th>
          <th>Actif</th>
          <th class="settings-table__actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="settings-table__empty">Aucun ingrédient.</td></tr>
        <?php endif ?>
        <?php foreach ($rows as $r): ?>
          <?php $isEdit = ((int) $r["id"] === $editId); ?>
          <?php if ($isEdit): ?>
            <tr class="settings-table__row settings-table__row--edit">
              <td><input form="edit-row" name="mi_id" type="text" required value="<?= htmlspecialchars((string) $r["mi_id"]) ?>"></td>
              <td><input form="edit-row" name="name" type="text" required value="<?= htmlspecialchars((string) $r["name"]) ?>" class="settings-input--wide"></td>
              <td>
                <select form="edit-row" name="category_id">
                  <option value=""<?= $r["category_id"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c["id"] ?>"<?= (int) ($r["category_id"] ?? 0) === (int) $c["id"] ? " selected" : "" ?>><?= htmlspecialchars((string) $c["name"]) ?></option>
                  <?php endforeach ?>
                </select>
                <select form="edit-row" name="subcategory_id" style="margin-top:4px">
                  <option value=""<?= $r["subcategory_id"] === null ? " selected" : "" ?>>—</option>
                  <?php foreach ($subcategories as $s): ?>
                    <option value="<?= (int) $s["id"] ?>"<?= (int) ($r["subcategory_id"] ?? 0) === (int) $s["id"] ? " selected" : "" ?>><?= htmlspecialchars((string) $s["category_name"]) ?> → <?= htmlspecialchars((string) $s["name"]) ?></option>
                  <?php endforeach ?>
                </select>
              </td>
              <td><input form="edit-row" name="price" type="text" inputmode="decimal" value="<?= htmlspecialchars((string) ($r["price"] ?? "")) ?>" style="max-width:90px"></td>
              <td><input form="edit-row" name="currency" type="text" value="<?= htmlspecialchars((string) ($r["currency"] ?? "")) ?>" maxlength="8" style="max-width:60px"></td>
              <td><input form="edit-row" name="pack_size" type="text" inputmode="decimal" value="<?= htmlspecialchars((string) ($r["pack_size"] ?? "")) ?>" style="max-width:80px"></td>
              <td><input form="edit-row" name="pricing_unit" type="text" value="<?= htmlspecialchars((string) ($r["pricing_unit"] ?? "")) ?>" maxlength="16" style="max-width:80px"></td>
              <td><input form="edit-row" name="gl_account" type="text" value="<?= htmlspecialchars((string) ($r["gl_account"] ?? "")) ?>" maxlength="8" style="max-width:80px"></td>
              <td class="settings-mono"><input form="edit-row" type="checkbox" name="is_active" value="1"<?= $r["is_active"] ? " checked" : "" ?>></td>
              <td class="settings-table__actions">
                <button form="edit-row" type="submit" class="settings-btn settings-btn--save">Enregistrer</button>
                <a href="?<?= htmlspecialchars(build_qs_mi([])) ?>" class="settings-btn settings-btn--cancel">Annuler</a>
              </td>
            </tr>
            <!-- Extra fields row, also bound to edit-row form via the form attribute -->
            <tr class="settings-table__row settings-table__row--edit">
              <td colspan="10">
                <div class="settings-mi__extras">
                  <label class="settings-add__field">
                    <span>Unité d'entrée</span>
                    <input form="edit-row" name="input_unit" type="text" value="<?= htmlspecialchars((string) ($r["input_unit"] ?? "")) ?>" maxlength="16">
                  </label>
                  <label class="settings-add__field">
                    <span>Conversion (entrée → prix)</span>
                    <input form="edit-row" name="conversion_factor" type="text" inputmode="decimal" value="<?= htmlspecialchars((string) ($r["conversion_factor"] ?? "")) ?>">
                  </label>
                  <label class="settings-add__field settings-add__field--wide">
                    <span>Fournisseur préféré</span>
                    <input form="edit-row" name="preferred_supplier" type="text" value="<?= htmlspecialchars((string) ($r["preferred_supplier"] ?? "")) ?>">
                  </label>
                  <label class="settings-add__field settings-add__field--wide">
                    <span>Notes</span>
                    <input form="edit-row" name="notes" type="text" value="<?= htmlspecialchars((string) ($r["notes"] ?? "")) ?>">
                  </label>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <tr class="settings-table__row<?= ($r["last_modified_by"] ?? '') === 'web' ? ' settings-table__row--pinned' : '' ?>">
              <td class="settings-mono"><?= ($r["last_modified_by"] ?? '') === 'web' ? '<span class="settings-pin" title="Épinglé (web)">📌</span> ' : '' ?><?= htmlspecialchars((string) $r["mi_id"]) ?></td>
              <td><?= htmlspecialchars((string) $r["name"]) ?></td>
              <td>
                <?php if ($r["category_name"]): ?>
                  <span class="settings-mi__cat"><?= htmlspecialchars((string) $r["category_name"]) ?></span><?php if ($r["subcategory_name"]): ?> <span class="settings-muted">→</span> <span class="settings-mi__subcat"><?= htmlspecialchars((string) $r["subcategory_name"]) ?></span><?php endif ?>
                <?php else: ?>
                  <span class="settings-muted">—</span>
                <?php endif ?>
              </td>
              <td class="settings-mono"><?= $r["price"] !== null ? htmlspecialchars((string) $r["price"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["currency"] ? htmlspecialchars((string) $r["currency"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["pack_size"] !== null ? htmlspecialchars((string) $r["pack_size"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["pricing_unit"] ? htmlspecialchars((string) $r["pricing_unit"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["gl_account"] ? htmlspecialchars((string) $r["gl_account"]) : '<span class="settings-muted">—</span>' ?></td>
              <td class="settings-mono"><?= $r["is_active"] ? "✓" : '<span class="settings-muted">·</span>' ?></td>
              <td class="settings-table__actions">
                <a href="?<?= htmlspecialchars(build_qs_mi(["edit" => (int) $r["id"]])) ?>" class="settings-btn">Modifier</a>
                <form method="post" class="settings-inline-form"
                      onsubmit="return confirm('Supprimer l\'ingrédient « <?= htmlspecialchars((string) $r["mi_id"], ENT_QUOTES) ?> » ?');">
                  <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action"        value="delete">
                  <input type="hidden" name="id"            value="<?= (int) $r["id"] ?>">
                  <input type="hidden" name="filter_cat"    value="<?= htmlspecialchars((string) ($fCat ?? "")) ?>">
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
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_mi(["page" => $page - 1])) ?>">← précédent</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">← précédent</span>
      <?php endif ?>
      <span class="db-pagination__pos">page <?= $page + 1 ?> / <?= $lastPage + 1 ?></span>
      <?php if ($page < $lastPage): ?>
        <a class="db-pagination__link" href="?<?= htmlspecialchars(build_qs_mi(["page" => $page + 1])) ?>">suivant →</a>
      <?php else: ?>
        <span class="db-pagination__link db-pagination__link--off">suivant →</span>
      <?php endif ?>
    </nav>
  <?php endif ?>

  <section class="settings-add">
    <h2 class="settings-add__title">— ajouter un ingrédient</h2>
    <form method="post" class="settings-add__form">
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="create">

      <label class="settings-add__field">
        <span>mi_id (clé naturelle)</span>
        <input name="mi_id" type="text" required placeholder="ex. HOPS_CITRA_PEL">
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Nom</span>
        <input name="name" type="text" required placeholder="ex. Hops Citra Pellets">
      </label>
      <label class="settings-add__field">
        <span>Catégorie</span>
        <select name="category_id">
          <option value="">—</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c["id"] ?>"><?= htmlspecialchars((string) $c["name"]) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Sous-catégorie</span>
        <select name="subcategory_id">
          <option value="">—</option>
          <?php foreach ($subcategories as $s): ?>
            <option value="<?= (int) $s["id"] ?>"><?= htmlspecialchars((string) $s["category_name"]) ?> → <?= htmlspecialchars((string) $s["name"]) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="settings-add__field">
        <span>Unité d'entrée</span>
        <input name="input_unit" type="text" placeholder="ex. kg" maxlength="16">
      </label>
      <label class="settings-add__field">
        <span>Unité de prix</span>
        <input name="pricing_unit" type="text" placeholder="ex. kg" maxlength="16">
      </label>
      <label class="settings-add__field">
        <span>Facteur conversion</span>
        <input name="conversion_factor" type="text" inputmode="decimal" placeholder="1.0">
      </label>
      <label class="settings-add__field">
        <span>Devise</span>
        <input name="currency" type="text" placeholder="CHF" maxlength="8">
      </label>
      <label class="settings-add__field">
        <span>Prix</span>
        <input name="price" type="text" inputmode="decimal" placeholder="ex. 12.50">
      </label>
      <label class="settings-add__field">
        <span>Pack size</span>
        <input name="pack_size" type="text" inputmode="decimal" placeholder="ex. 5">
      </label>
      <label class="settings-add__field settings-add__field--wide">
        <span>Fournisseur préféré</span>
        <input name="preferred_supplier" type="text" placeholder="—">
      </label>
      <label class="settings-add__field">
        <span>GL</span>
        <input name="gl_account" type="text" placeholder="ex. 4101" maxlength="8">
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
