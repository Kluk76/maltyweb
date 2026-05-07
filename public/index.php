<?php
declare(strict_types=1);

require __DIR__ . "/../app/auth.php";

require_login();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

try {
    $pdo = maltytask_pdo();
    $row = $pdo->query("SELECT DATABASE() AS db, CURRENT_USER() AS user, VERSION() AS version")->fetch();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $statusClass = "ok";
    $statusText  = "DB OK";
} catch (Throwable $e) {
    $row = null;
    $tables = [];
    $statusClass = "bad";
    $statusText  = "DB ERROR: " . $e->getMessage();
}

$modules = [
    ["01", "Procurement",     "Sourcing & receiving",        "#mod-procurement"],
    ["02", "Wort Production", "Brewhouse & cooling",         "#mod-wort"],
    ["03", "Fermentation",    "CCT, BBT, dry-hop, racking",  "#mod-fermentation"],
    ["04", "Packaging",       "Bottle, can, keg, cuv",       "#mod-packaging"],
    ["05", "Fulfilment",      "Logistics & dispatch",        "#mod-fulfilment"],
    ["06", "QA / QC",         "Lab, sensory, audit",         "#mod-qa"],
];
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — La Nébuleuse</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/css/app.css') ?: time() ?>">
</head>
<body class="home">

<aside class="side" aria-label="Navigation principale">
  <div class="side__brand">
    <span class="mark">M<span class="mark__t">T</span></span>
    <span class="mark__sub">MaltyTask</span>
  </div>

  <nav class="nav" aria-label="Modules">
    <div class="nav__label">— modules</div>
    <ul>
      <?php foreach ($modules as [$idx, $name, $sub, $href]): ?>
        <li>
          <a class="nav__item" href="<?= htmlspecialchars($href) ?>">
            <span class="nav__idx"><?= htmlspecialchars($idx) ?></span>
            <span class="nav__body">
              <span class="nav__name"><?= htmlspecialchars($name) ?></span>
              <span class="nav__sub"><?= htmlspecialchars($sub) ?></span>
            </span>
            <span class="nav__chev" aria-hidden="true">→</span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  </nav>

  <footer class="side__foot">
    <span class="side__org">la nébuleuse</span>
    <span class="side__ver">v0.1 · 2026</span>
  </footer>
</aside>

<header class="top">
  <div class="top__crumbs">
    <span class="top__here">Accueil</span>
  </div>
  <div class="top__user">
    <span class="user__name"><?= htmlspecialchars($me["display_name"]) ?></span>
    <span class="user__sep">·</span>
    <span class="user__role"><?= htmlspecialchars($me["role"]) ?></span>
    <span class="user__sep">·</span>
    <a class="user__out" href="/logout.php">Déconnexion</a>
  </div>
</header>

<main class="main">
  <section class="hero">
    <div class="hero__eyebrow">brewery operations · est. 2017</div>
    <h1 class="hero__mark">
      <span class="hero__a">Malty</span><span class="hero__b">Task</span>
    </h1>
    <div class="hero__rule" aria-hidden="true">
      <span></span>
      <svg class="hero__hop" viewBox="0 0 14 22" xmlns="http://www.w3.org/2000/svg">
        <g fill="currentColor">
          <rect x="6.4" y="0" width="1.2" height="3.2" rx="0.4"/>
          <path d="M7 3.6 c-2.6 0 -4.6 1.6 -4.6 3.7 c0 1.5 .9 2.8 2.3 3.4 c-.6 -.6 -1 -1.5 -1 -2.4 c0 -1.7 1.5 -3.1 3.3 -3.1 s3.3 1.4 3.3 3.1 c0 .9 -.4 1.8 -1 2.4 c1.4 -.6 2.3 -1.9 2.3 -3.4 c0 -2.1 -2 -3.7 -4.6 -3.7z"/>
          <path d="M2.2 9.4 c0 2 2.1 3.7 4.8 3.7 s4.8 -1.7 4.8 -3.7 c-.9 1.1 -2.7 1.8 -4.8 1.8 s-3.9 -.7 -4.8 -1.8z" opacity="0.92"/>
          <path d="M2.6 12.8 c0 2 2 3.6 4.4 3.6 s4.4 -1.6 4.4 -3.6 c-.8 1.1 -2.5 1.7 -4.4 1.7 s-3.6 -.6 -4.4 -1.7z" opacity="0.78"/>
          <path d="M3.4 16.1 c0 1.8 1.7 3.2 3.6 3.2 s3.6 -1.4 3.6 -3.2 c-.7 1 -2.1 1.5 -3.6 1.5 s-2.9 -.5 -3.6 -1.5z" opacity="0.62"/>
          <path d="M4.6 19.1 c0 1.4 1.1 2.5 2.4 2.5 s2.4 -1.1 2.4 -2.5 c-.5 .8 -1.4 1.2 -2.4 1.2 s-1.9 -.4 -2.4 -1.2z" opacity="0.45"/>
        </g>
      </svg>
      <span></span>
    </div>
    <p class="hero__tag">Une plateforme unique pour la brasserie — du grain au fût.</p>
  </section>

  <section class="status" aria-label="État du système">
    <div class="status__rail" aria-hidden="true"></div>
    <div class="status__head">
      <span class="status__label">— système</span>
      <span class="status__pill status__pill--<?= htmlspecialchars($statusClass) ?>">
        <svg class="gauge" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <defs>
            <linearGradient id="g-bezel" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#d8dce0"/>
              <stop offset="42%" stop-color="#5e6266"/>
              <stop offset="100%" stop-color="#1a1c1e"/>
            </linearGradient>
            <radialGradient id="g-face" cx="50%" cy="38%" r="62%">
              <stop offset="0%" stop-color="#1a140d"/>
              <stop offset="100%" stop-color="#080604"/>
            </radialGradient>
          </defs>
          <circle cx="12" cy="12" r="11" fill="url(#g-bezel)"/>
          <circle cx="12" cy="12" r="9.4" fill="url(#g-face)"/>
          <g stroke="#7a6647" stroke-width="0.6" opacity="0.7" stroke-linecap="round">
            <line x1="12" y1="3.6" x2="12" y2="5"/>
            <line x1="18.4" y1="5.6" x2="17.4" y2="6.6"/>
            <line x1="20.4" y1="12" x2="19" y2="12"/>
            <line x1="18.4" y1="18.4" x2="17.4" y2="17.4"/>
            <line x1="5.6" y1="18.4" x2="6.6" y2="17.4"/>
            <line x1="3.6" y1="12" x2="5" y2="12"/>
            <line x1="5.6" y1="5.6" x2="6.6" y2="6.6"/>
          </g>
          <g class="gauge__needle">
            <line x1="12" y1="12" x2="17" y2="6.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="1.4" fill="#b8bcc0" stroke="#15171a" stroke-width="0.4"/>
          </g>
        </svg>
        <?= htmlspecialchars($statusText) ?>
      </span>
    </div>
    <?php if ($row): ?>
      <dl class="meta">
        <div class="meta__row">
          <dt>database</dt>
          <dd><?= htmlspecialchars($row["db"] ?? "—") ?></dd>
        </div>
        <div class="meta__row">
          <dt>user</dt>
          <dd><?= htmlspecialchars($row["user"] ?? "—") ?></dd>
        </div>
        <div class="meta__row">
          <dt>mysql</dt>
          <dd><?= htmlspecialchars($row["version"] ?? "—") ?></dd>
        </div>
        <div class="meta__row">
          <dt>tables</dt>
          <dd><?= count($tables) ? htmlspecialchars(implode(" · ", $tables)) : "<em>(vide)</em>" ?></dd>
        </div>
        <div class="meta__row">
          <dt>php</dt>
          <dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
        </div>
      </dl>
    <?php endif ?>
  </section>
</main>

</body>
</html>
