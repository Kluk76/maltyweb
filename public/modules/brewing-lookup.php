<?php
declare(strict_types=1);
/**
 * modules/brewing-lookup.php — Consulter un brassin
 *
 * Standalone read-only lookup for brewing sessions. Reuses the shared
 * lookup-panel partial (driven by $lookupConfig) and the shared
 * brewing-lookup API endpoint (/api/brewing-lookup.php).
 *
 * Auth:  require_page_access('brewing-lookup') — all logged-in users (min_role='viewer').
 * Body:  body.home.brewing-lookup
 * CSS:   /css/app.css, /css/lookup-panel.css, /css/brewing-lookup.css
 * JS:    /js/lookup-panel.js  (self-initialising from data-attributes; no page-level JS needed)
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

require_page_access('brewing-lookup');
$me  = current_user();
$pdo = maltytask_pdo();

// ─── Recipe options for the "Par recette + lot" batch selector ───────────────
$recipeOptions = [];
try {
    $recipeStmt    = $pdo->query("SELECT id, name FROM ref_recipes WHERE is_active = 1 ORDER BY name");
    $recipeOptions = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('brewing-lookup: recipe options query failed — ' . $e->getMessage());
}

$pageTitle = 'Consulter un brassin';

// ─── Lookup panel config — mirrors form-brewing.php setup verbatim ────────────
$lookupConfig = [
    'panel_id'         => 'brewing-lookup',
    'api_endpoint'     => '/api/brewing-lookup.php',
    'mode_batch_label' => 'Par recette + lot',
    'type'             => 'brewing',
    'batch_fields'     => [
        ['name' => 'recipe_id', 'label' => 'Recette', 'type' => 'select', 'options' => $recipeOptions, 'value_col' => 'id', 'label_col' => 'name'],
        ['name' => 'batch',     'label' => 'Lot',      'type' => 'text'],
    ],
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/lookup-panel.css?v=<?= @filemtime(__DIR__ . '/../css/lookup-panel.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/brewing-lookup.css?v=<?= @filemtime(__DIR__ . '/../css/brewing-lookup.css') ?: time() ?>">
</head>
<body class="home brewing-lookup">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="bl-header">
    <div class="bl-eyebrow">MaltyTask · Brassage</div>
    <h1 class="bl-title">Consulter un <em>brassin</em></h1>
    <p class="bl-sub">
      Recherchez les données d'un brassin par date ou par recette et numéro de lot —
      gravités, ingrédients, timings.
    </p>
  </div>

  <!-- ── Lookup panel (shared partial, auto-inits from data-attributes) ──── -->
  <?php require __DIR__ . '/partials/lookup-panel.php'; ?>

</main>

<script defer src="/js/lookup-panel.js?v=<?= @filemtime(__DIR__ . '/../js/lookup-panel.js') ?: time() ?>"></script>
</body>
</html>
