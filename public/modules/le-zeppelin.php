<?php
declare(strict_types=1);
/**
 * le-zeppelin.php — Le Zeppelin family-router hub.
 *
 * Routes to the 4 families:
 *   Salle des Machines  /modules/salle-des-machines.php
 *   Salle de contrôle   /modules/salle-de-controle.php
 *   Le Cockpit          (à venir — disabled card)
 *   Données générales   /modules/reglages-generaux.php  (admin-only)
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

require_page_access('zeppelin');
$me = current_user();

header('Content-Type: text/html; charset=utf-8');

$_breweryId    = brewery_identity();
$active_module = 'zeppelin';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Le Zeppelin — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/le-zeppelin.css?v=<?= @filemtime(__DIR__ . '/../css/le-zeppelin.css') ?: time() ?>">
</head>
<body class="home zeppelin-hub">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <!-- ── Chrome: family switcher ──────────────────────────────────────────── -->
  <div class="lz-chrome">
    <div class="lz-brandmark"><?= htmlspecialchars($_breweryId['name']) ?> · <b>Le Zeppelin</b></div>
    <div class="family-switcher">
      <span class="family-btn fam-hub">
        <span class="fam-dot"></span>Hub
      </span>
      <a class="family-btn fam-sdm" href="/modules/salle-des-machines.php" title="Salle des Machines">
        <span class="fam-dot"></span>Machines
      </a>
      <a class="family-btn fam-sdc" href="/modules/salle-de-controle.php" title="Salle de contrôle">
        <span class="fam-dot"></span>Contrôle
      </a>
      <span class="family-btn fam-cockpit" title="Cockpit commercial — à venir">
        <span class="fam-dot"></span>Cockpit
      </span>
    </div>
  </div>

  <!-- ── Page header ──────────────────────────────────────────────────────── -->
  <div class="sh-header">
    <div class="sh-eyebrow">MaltyTask · <?= htmlspecialchars($_breweryId['name']) ?></div>
    <h1 class="sh-title">Le <em>Zeppelin</em></h1>
    <p class="sh-sub">
      Tableau de bord de l'infrastructure brassicole. Sélectionnez une famille
      pour accéder à ses outils opérateurs.
    </p>
  </div>

  <!-- ── Family cards ─────────────────────────────────────────────────────── -->
  <div class="sh-grid">

    <!-- 1. Salle des Machines -->
    <a class="sh-card" href="/modules/salle-des-machines.php">
      <div class="sh-card__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
          <path d="M4.93 4.93a10 10 0 0 0 0 14.14"/>
          <path d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
        </svg>
      </div>
      <div class="sh-card__name">Salle des Machines</div>
      <div class="sh-card__desc">
        Capacités · Vessels · Formats · Fournisseurs
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

    <!-- 2. Salle de contrôle -->
    <a class="sh-card" href="/modules/salle-de-controle.php">
      <div class="sh-card__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
          <line x1="10" y1="9" x2="8" y2="9"/>
        </svg>
      </div>
      <div class="sh-card__name">Salle de contrôle</div>
      <div class="sh-card__desc">
        Recettes · Biochimie · QA/QC
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>

    <!-- 3. Le Cockpit — disabled (à venir) -->
    <div class="sh-card sh-card--soon" role="button" aria-disabled="true" tabindex="-1">
      <div class="sh-card__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/>
          <line x1="2" y1="12" x2="22" y2="12"/>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
      </div>
      <div class="sh-card__name">
        Le Cockpit <span class="sh-card__badge">à venir</span>
      </div>
      <div class="sh-card__desc">
        Vente · Marketing · Finances
      </div>
      <div class="sh-card__arrow">Bientôt disponible</div>
    </div>

    <!-- 4. Données générales — admin only -->
    <?php if (is_admin($me)): ?>
    <a class="sh-card" href="/modules/reglages-generaux.php">
      <div class="sh-card__icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </div>
      <div class="sh-card__name">Données générales</div>
      <div class="sh-card__desc">
        Réglages · Sites · Utilisateurs · Formats
      </div>
      <div class="sh-card__arrow">Ouvrir →</div>
    </a>
    <?php endif ?>

  </div>

</main>

</body>
</html>
