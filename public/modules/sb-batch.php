<?php
declare(strict_types=1);
/**
 * sb-batch.php — Per-batch detail stub (Atom 18 stub; full drill-in = Atom 19).
 *
 * Receives:
 *   ?recipe=<int>   — ref_recipes.id
 *   ?batch=<string> — batch identifier (e.g. "65", "2025-04-A")
 *
 * No data queries in this stub. Renders a placeholder page with a back link.
 * Full implementation (observed-batch drill-in) is deferred to Atom 19.
 *
 * Auth: require_login() — all logged-in operators.
 * Body class: home sb-board sb-batch
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/sb-board.php';

require_page_access('sb-board');
$me   = current_user();

// ─── 1. Read + validate params (read with ?? default THEN validate) ────────────

$rawRecipe = $_GET['recipe'] ?? null;
$rawBatch  = $_GET['batch']  ?? null;

$recipeId = ($rawRecipe !== null && ctype_digit((string) $rawRecipe) && (int) $rawRecipe > 0)
    ? (int) $rawRecipe
    : 0;

$batch = (is_string($rawBatch) && $rawBatch !== '')
    ? $rawBatch
    : '';

// Missing or invalid params → redirect to board.
if ($recipeId === 0 || $batch === '') {
    header('Location: /modules/sb-board.php', true, 302);
    exit;
}

// ─── 2. Escape for display ────────────────────────────────────────────────────

function sbbatch_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$displayBatch    = sbbatch_esc($batch);
$displayRecipeId = $recipeId;

// ─── 3. Page assets ───────────────────────────────────────────────────────────

$active_module = 'sb-board';
$cssAppV       = @filemtime(__DIR__ . '/../css/app.css')      ?: time();
$cssBoardV     = @filemtime(__DIR__ . '/../css/sb-board.css') ?: time();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Détail du lot #<?= $displayBatch ?> — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssAppV ?>">
  <link rel="stylesheet" href="/css/sb-board.css?v=<?= $cssBoardV ?>">
</head>
<body class="home sb-board sb-batch">

<!-- Engineering registration marks -->
<div class="sb-reg-mark sb-reg-mark--tl" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--tr" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--bl" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--br" aria-hidden="true"></div>

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">
<div class="sb-board-wrap">

  <div class="sb-batch-stub" role="main" aria-label="Détail du lot">
    <div class="sb-batch-stub__inner">
      <div class="sb-batch-stub__back">
        <a href="/modules/sb-board.php" class="sb-batch-stub__back-link">← Tableau de bord</a>
      </div>
      <div class="sb-batch-stub__heading">
        <span class="sb-batch-stub__label">Détail du lot</span>
        <h1 class="sb-batch-stub__title">Recette #<?= $displayRecipeId ?> · lot #<?= $displayBatch ?></h1>
      </div>
      <div class="sb-batch-stub__placeholder">
        <div class="sb-batch-stub__placeholder-icon" aria-hidden="true">◻</div>
        <div class="sb-batch-stub__placeholder-msg">Visualisation détaillée à venir</div>
        <div class="sb-batch-stub__placeholder-sub">Atom 19 — drill-in observé en cours de développement.</div>
      </div>
    </div>
  </div>

</div>
</main>

</body>
</html>
