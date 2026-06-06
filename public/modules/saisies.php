<?php
declare(strict_types=1);
/**
 * saisies.php — Hub page listing operator input forms.
 *
 * Active forms: Conditionnement (/modules/form-packaging.php)
 *               Soutirage      (/modules/form-racking.php)
 *               Brassage       (/modules/form-brewing.php)
 *               Fermentation   (/modules/form-fermenting.php)
 *               Inventaire RM  (/modules/form-rm-stocktake.php)
 *
 * All forms are active. No placeholder cards remain.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

require_page_access('saisies');
$me = current_user();

header('Content-Type: text/html; charset=utf-8');

$active_module = 'saisies';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saisies — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/saisies.css?v=<?= @filemtime(__DIR__ . '/../css/saisies.css') ?: time() ?>">
</head>
<body class="home saisies-hub">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <div class="sh-header">
    <div class="sh-eyebrow">MaltyTask · Saisies opérateur</div>
    <h1 class="sh-title">Formulaires de <em>saisie</em></h1>
    <p class="sh-sub">
      Enregistrement des événements de production : soutirage, conditionnement,
      brassage, fermentation, stocks. Chaque saisie alimente directement la base
      de données de production.
    </p>
  </div>

  <!-- ── Active forms ──────────────────────────────────────────────────── -->
  <p class="sh-section-label">— formulaires disponibles</p>
  <div class="sh-grid">

    <a class="sh-card" href="/modules/form-packaging.php">
      <div class="sh-card__icon">📦</div>
      <div class="sh-card__name">Conditionnement</div>
      <div class="sh-card__desc">
        Enregistrement d'un run de conditionnement : sélection du lot source (BBT/CCT),
        formats produits, volumes, pertes et mesures QA.
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

    <!-- Transferts card: two entry points — classic form + new session path. -->
    <div class="sh-card sh-card--split">
      <div class="sh-card__icon">🔀</div>
      <div class="sh-card__name">Transferts</div>
      <div class="sh-card__desc">
        Enregistrement d'un transfert : soutirage de bière depuis une CCT vers un BBT
        ou une autre CCT, avec volumes et mesures associés.
      </div>
      <div class="sh-card__actions">
        <a class="sh-card__link" href="/modules/form-racking.php">Saisie classique →</a>
        <button type="button" id="sh-start-racking-session"
                class="sh-card__btn-session"
                data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
          Démarrer (session) ⟶
        </button>
      </div>
    </div>

    <a class="sh-card" href="/modules/form-brewing.php">
      <div class="sh-card__icon">🍺</div>
      <div class="sh-card__name">Brassage</div>
      <div class="sh-card__desc">
        Saisie des données de brassage : ingrédients, volumes, densités initiales
        et paramètres de cuve.
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

    <a class="sh-card" href="/modules/form-fermenting.php">
      <div class="sh-card__icon">🧪</div>
      <div class="sh-card__name">Fermentation</div>
      <div class="sh-card__desc">
        Suivi de fermentation : relevés de densité, températures et état des cuves
        en cours de fermentation.
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

    <a class="sh-card" href="/modules/form-rm-stocktake.php">
      <div class="sh-card__icon">📋</div>
      <div class="sh-card__name">Inventaire RM</div>
      <div class="sh-card__desc">
        Saisie du stock physique de matières premières : comptage mensuel par
        ingrédient pour le suivi d'inventaire.
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

  </div>

</main>

<script src="/js/saisies.js?v=<?= @filemtime(__DIR__ . '/../js/saisies.js') ?: time() ?>"></script>
</body>
</html>
