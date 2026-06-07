<?php
declare(strict_types=1);

require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/services/remember_token.php";
require_once __DIR__ . "/../app/services/device.php";
require_once __DIR__ . "/../app/settings-helpers.php";

maltytask_session_start();

// Resolve device identity before any output (sets Set-Cookie header).
$deviceId     = device_id_ensure();
$pdo          = maltytask_pdo();
$sharedDevice = device_is_shared($deviceId, $pdo);

// Already logged in? Bounce to dashboard.
if (current_user() !== null) {
    header("Location: /modules/mon-tableau.php", true, 302);
    exit;
}

$error = null;
$username = "";
$expired = (($_GET["reason"] ?? "") === "expired");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $posted = $_POST["csrf"] ?? null;
    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");

    if (!csrf_verify($posted)) {
        $error = "Session expirée — recharge la page et réessaie.";
    } elseif ($username === "" || $password === "") {
        $error = "Identifiants requis.";
    } else {
        $user = auth_verify($username, $password);
        if ($user === null) {
            // Generic error — don't reveal whether the username exists
            $error = "Identifiants invalides.";
            // Mild throttle to slow brute force
            usleep(500_000);

            // Structured log for fail2ban (filter: maltytask-auth)
            $logUser = preg_replace('/[^a-zA-Z0-9_@.\-]/', '?', (string) $username);
            if (strlen($logUser) > 64) $logUser = substr($logUser, 0, 64);
            $logIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $logLine = sprintf("%s auth-fail user=%s ip=%s\n", date('c'), $logUser, $logIp);
            @file_put_contents('/var/log/maltytask/auth.log', $logLine, FILE_APPEND | LOCK_EX);
        } else {
            auth_login($user);

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // ── Remember this device? ────────────────────────────────────
            // Three cases:
            //   (a) Unclassified: box ticked → defer token until after classification.
            //       The interstitial page issues rt_create() when 'personal' is chosen.
            //   (b) Classified personal (is_shared=0): issue token as normal.
            //   (c) Shared (is_shared=1): refuse token (existing behaviour).
            if (!empty($_POST["remember_me"])) {
                if (!device_classified($deviceId, $pdo)) {
                    // Device unknown — defer; classification page will issue token if personal.
                    $_SESSION['pending_remember'] = 1;
                } elseif (!$sharedDevice) {
                    // Known personal device — issue immediately.
                    rt_create((int)$user["id"], null, $ip, $ua, $pdo);
                }
                // Shared device: token refused (falls through, no action).
            }

            // Update last-seen in device registry (no-op for unregistered browsers).
            device_touch($deviceId, $ip, $ua, $pdo);

            $next = $_GET["next"] ?? "/modules/mon-tableau.php";
            // Reject open-redirects: must be a same-origin path
            if (!is_string($next) || !str_starts_with($next, "/") || str_starts_with($next, "//")) {
                $next = "/modules/mon-tableau.php";
            }

            // Fresh user who has never seen the tour → redirect to onboarding.
            // Requires a DB read because auth_login() does not populate tour_seen_at in session.
            $tourCheckStmt = $pdo->prepare('SELECT tour_seen_at FROM users WHERE id = ? LIMIT 1');
            $tourCheckStmt->execute([(int) $user['id']]);
            $tourCheckRow = $tourCheckStmt->fetch();
            if ($tourCheckRow && $tourCheckRow['tour_seen_at'] === null) {
                $next = "/modules/visite-guidee.php";
            }

            // Device classification: unclassified device runs the interstitial first.
            // The interstitial carries the original $next through its own `next` param,
            // so the operator lands on their intended destination after classifying.
            if (!device_classified($deviceId, $pdo)) {
                $next = '/modules/classification-appareil.php?next=' . rawurlencode($next);
            }

            header("Location: $next", true, 302);
            exit;
        }
    }
}

$token    = csrf_token();
$insecure = empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off";
$brewery  = brewery_identity();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — Connexion</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/css/app.css') ?: time() ?>">
</head>
<body class="auth">
<main class="auth__shell">

  <header class="auth__hero">
    <div class="auth__eyebrow">— accès · La Nébuleuse</div>
    <h1 class="auth__mark">
      <span class="auth__a">Malty</span><span class="auth__b">Task</span>
    </h1>
    <div class="auth__rule" aria-hidden="true">
      <span></span>
      <svg class="auth__hop" viewBox="0 0 14 22" xmlns="http://www.w3.org/2000/svg">
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
    <p class="auth__tag">L'atelier vous attend.</p>
  </header>

  <section class="auth__panel">
    <div class="auth__rail" aria-hidden="true"></div>

    <div class="auth__panel-head">
      <span class="auth__panel-label">— authentification</span>
      <span class="auth__panel-pill">
        <svg viewBox="0 0 14 16" width="13" height="14" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="1.5" y="7" width="11" height="8" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M4 7V5a3 3 0 1 1 6 0v2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
        sécurisé
      </span>
    </div>

    <?php if ($insecure): ?>
      <div class="auth__warn">⚠ Connexion non chiffrée (HTTP). À traiter dès qu'un nom de domaine + Let's Encrypt sont en place.</div>
    <?php endif ?>
    <?php if ($expired): ?>
      <div class="auth__warn">Session expirée. Reconnecte-toi pour continuer.</div>
    <?php endif ?>
    <?php if ($error): ?>
      <div class="auth__err"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>

    <form class="auth__form" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <label class="auth__field">
        <span class="auth__field-label">Username</span>
        <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" autofocus required>
      </label>
      <label class="auth__field">
        <span class="auth__field-label">Password</span>
        <input type="password" name="password" required>
      </label>
      <?php if ($sharedDevice): ?>
        <p class="auth__warn">Poste partagé — déconnexion automatique après inactivité. Aucune session persistante sur cet appareil.</p>
      <?php else: ?>
        <label class="auth__field auth__field--check">
          <input type="checkbox" name="remember_me" value="1" checked>
          <span class="auth__field-label">Se souvenir de cet appareil (90 jours)</span>
        </label>
      <?php endif ?>
      <button class="auth__submit" type="submit">
        <span>Connexion</span>
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path d="M3 8h9M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </form>
  </section>

  <footer class="auth__foot">
    <span><?= htmlspecialchars($brewery['name']) ?> · Est. 2014</span>
    <span><?= htmlspecialchars($brewery['city']) ?> · <?= htmlspecialchars($brewery['country_code']) ?></span>
  </footer>

</main>
</body>
</html>
