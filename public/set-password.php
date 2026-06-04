<?php
declare(strict_types=1);

require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/settings-helpers.php";
require_once __DIR__ . "/../app/services/invite_token.php";
require_once __DIR__ . "/../app/services/rate_limit.php";

maltytask_session_start();

// Already logged in? Bounce to dashboard.
if (current_user() !== null) {
    header("Location: /", true, 302);
    exit;
}

$pdo = maltytask_pdo();

// ── Brewery identity: read once, used in all footer renders ──────────────────
$bi              = brewery_identity();
$biName          = htmlspecialchars($bi['name'],         ENT_QUOTES, 'UTF-8');
$biCity          = htmlspecialchars($bi['city'],         ENT_QUOTES, 'UTF-8');
$biCountryCode   = htmlspecialchars($bi['country_code'], ENT_QUOTES, 'UTF-8');

// ── Two-step param read: default first, then validate ────────────────────────
$rawToken = $_GET['token'] ?? '';
$rawToken = is_string($rawToken) ? trim($rawToken) : '';

// ── Generic invalid-token message (no user-enumeration) ─────────────────────
$invalidHtml = '<!doctype html><html lang="fr"><head>'
    . '<meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>MaltyTask — Lien invalide</title>'
    . '<link rel="preconnect" href="https://fonts.googleapis.com">'
    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    . '<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;1,9..144,300&family=DM+Sans:opsz,wght@9..40,400;9..40,500&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">'
    . '<link rel="stylesheet" href="/css/app.css?v=' . (@filemtime(__DIR__ . '/css/app.css') ?: time()) . '">'
    . '</head><body class="auth"><main class="auth__shell">'
    . '<header class="auth__hero">'
    . '<div class="auth__eyebrow">— accès · La Nébuleuse</div>'
    . '<h1 class="auth__mark"><span class="auth__a">Malty</span><span class="auth__b">Task</span></h1>'
    . '</header>'
    . '<section class="auth__panel">'
    . '<div class="auth__rail" aria-hidden="true"></div>'
    . '<div class="auth__panel-head"><span class="auth__panel-label">— activation de compte</span></div>'
    . '<div class="auth__err">Lien invalide ou expiré.<br>Contacte un administrateur pour recevoir un nouveau lien.</div>'
    . '</section>'
    . '<footer class="auth__foot"><span>' . $biName . ' · Est. 2014</span><span>' . $biCity . ' · ' . $biCountryCode . '</span></footer>'
    . '</main></body></html>';

// ── GET: validate token; render form or invalid message ──────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($rawToken === '') {
        echo $invalidHtml;
        exit;
    }

    $invite = invite_lookup($pdo, $rawToken);
    if ($invite === null) {
        echo $invalidHtml;
        exit;
    }

    // Load the user row to show the username (read-only orientation)
    $userStmt = $pdo->prepare(
        "SELECT id, username, display_name FROM users WHERE id = ? LIMIT 1"
    );
    $userStmt->execute([$invite['user_id']]);
    $targetUser = $userStmt->fetch();
    if (!$targetUser) {
        echo $invalidHtml;
        exit;
    }

    $csrfToken    = csrf_token();
    $safeUsername = htmlspecialchars($targetUser['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeDisplay  = htmlspecialchars($targetUser['display_name'] ?? $targetUser['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeToken    = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
    $cssCacheBust = @filemtime(__DIR__ . '/css/app.css') ?: time();
    ?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — Activation du compte</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssCacheBust ?>">
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
    <p class="auth__tag">Bienvenue dans l'équipe.</p>
  </header>

  <section class="auth__panel">
    <div class="auth__rail" aria-hidden="true"></div>

    <div class="auth__panel-head">
      <span class="auth__panel-label">— activation du compte</span>
      <span class="auth__panel-pill">
        <svg viewBox="0 0 14 16" width="13" height="14" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="1.5" y="7" width="11" height="8" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
          <path d="M4 7V5a3 3 0 1 1 6 0v2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
        </svg>
        sécurisé
      </span>
    </div>

    <form class="auth__form" method="post" action="/set-password.php" autocomplete="off">
      <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="token" value="<?= $safeToken ?>">

      <label class="auth__field">
        <span class="auth__field-label">Compte</span>
        <input type="text" value="<?= $safeUsername ?>" disabled readonly>
      </label>

      <label class="auth__field">
        <span class="auth__field-label">Nom affiché</span>
        <input type="text" name="display_name" value="<?= $safeDisplay ?>"
               maxlength="128" autocomplete="name">
      </label>

      <label class="auth__field">
        <span class="auth__field-label">Mot de passe <small>(8 caractères min.)</small></span>
        <input type="password" name="password" required
               minlength="8" autocomplete="new-password">
      </label>

      <label class="auth__field">
        <span class="auth__field-label">Confirmer le mot de passe</span>
        <input type="password" name="password_confirm" required
               minlength="8" autocomplete="new-password">
      </label>

      <button class="auth__submit" type="submit">
        <span>Activer mon compte</span>
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path d="M3 8h9M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </form>
  </section>

  <footer class="auth__foot">
    <span><?= $biName ?> · Est. 2014</span>
    <span><?= $biCity ?> · <?= $biCountryCode ?></span>
  </footer>

</main>
</body>
</html>
<?php
    exit;
}

// ── POST handler ─────────────────────────────────────────────────────────────

// 1. CSRF check — first gate, before any other validation
$postedCsrf = $_POST['csrf'] ?? null;
if (!csrf_verify($postedCsrf)) {
    // Log the attempt for fail2ban (filter: maltytask-auth)
    $logIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $logLine = sprintf("%s set-password csrf-fail ip=%s\n", date('c'), $logIp);
    @file_put_contents('/var/log/maltytask/auth.log', $logLine, FILE_APPEND | LOCK_EX);

    echo $invalidHtml;
    exit;
}

// 2. Two-step token read: default first, then validate
$rawToken = $_POST['token'] ?? '';
$rawToken = is_string($rawToken) ? trim($rawToken) : '';
if ($rawToken === '') {
    echo $invalidHtml;
    exit;
}

// 3. Re-validate the token server-side (never trust GET/hidden-field state)
$invite = invite_lookup($pdo, $rawToken);
if ($invite === null) {
    // Structured log for fail2ban — invalid token POST attempt
    $logIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $logLine = sprintf("%s set-password invalid-token ip=%s\n", date('c'), $logIp);
    @file_put_contents('/var/log/maltytask/auth.log', $logLine, FILE_APPEND | LOCK_EX);

    echo $invalidHtml;
    exit;
}

$userId = (int) $invite['user_id'];

// 4. Rate-limit: keyed on (user_id, action) — max 5 attempts per 15-minute window
$ip      = $_SERVER['REMOTE_ADDR'] ?? null;
$allowed = rl_check_and_log($userId, 'set_password', 5, 900, $ip, $pdo);
if (!$allowed) {
    $csrfToken    = csrf_token();
    $safeToken    = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
    $cssCacheBust = @filemtime(__DIR__ . '/css/app.css') ?: time();
    ?><!doctype html>
<html lang="fr"><head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — Trop de tentatives</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;1,9..144,300&family=DM+Sans:opsz,wght@9..40,400;9..40,500&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssCacheBust ?>">
</head><body class="auth"><main class="auth__shell">
  <header class="auth__hero">
    <div class="auth__eyebrow">— accès · La Nébuleuse</div>
    <h1 class="auth__mark"><span class="auth__a">Malty</span><span class="auth__b">Task</span></h1>
  </header>
  <section class="auth__panel">
    <div class="auth__rail" aria-hidden="true"></div>
    <div class="auth__panel-head"><span class="auth__panel-label">— activation du compte</span></div>
    <div class="auth__err">Trop de tentatives. Réessaie dans quelques minutes.</div>
  </section>
  <footer class="auth__foot"><span><?= $biName ?> · Est. 2014</span><span><?= $biCity ?> · <?= $biCountryCode ?></span></footer>
</main></body></html>
<?php
    exit;
}

// 5. Read and validate form inputs (two-step: read with ?? default, then validate)
$password        = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';
$displayName     = trim((string) ($_POST['display_name'] ?? ''));

$password        = is_string($password) ? $password : '';
$passwordConfirm = is_string($passwordConfirm) ? $passwordConfirm : '';
$displayName     = substr($displayName, 0, 128);

$error = null;
if (mb_strlen($password, 'UTF-8') < 8) {
    $error = "Le mot de passe doit contenir au moins 8 caractères.";
} elseif ($password !== $passwordConfirm) {
    $error = "Les mots de passe ne correspondent pas.";
}

if ($error !== null) {
    // Re-fetch user to re-render the form with the error
    $userStmt = $pdo->prepare(
        "SELECT id, username, display_name FROM users WHERE id = ? LIMIT 1"
    );
    $userStmt->execute([$userId]);
    $targetUser = $userStmt->fetch();
    if (!$targetUser) {
        // User was deleted in the window between lookup and re-render
        echo $invalidHtml;
        exit;
    }

    $csrfToken    = csrf_token();
    $safeUsername = htmlspecialchars($targetUser['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $safeDisplay  = htmlspecialchars($displayName ?: ($targetUser['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeToken    = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
    $safeError    = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
    $cssCacheBust = @filemtime(__DIR__ . '/css/app.css') ?: time();
    ?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — Activation du compte</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssCacheBust ?>">
</head>
<body class="auth">
<main class="auth__shell">
  <header class="auth__hero">
    <div class="auth__eyebrow">— accès · La Nébuleuse</div>
    <h1 class="auth__mark">
      <span class="auth__a">Malty</span><span class="auth__b">Task</span>
    </h1>
    <p class="auth__tag">Bienvenue dans l'équipe.</p>
  </header>
  <section class="auth__panel">
    <div class="auth__rail" aria-hidden="true"></div>
    <div class="auth__panel-head">
      <span class="auth__panel-label">— activation du compte</span>
    </div>
    <div class="auth__err"><?= $safeError ?></div>
    <form class="auth__form" method="post" action="/set-password.php" autocomplete="off">
      <input type="hidden" name="csrf"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="token" value="<?= $safeToken ?>">
      <label class="auth__field">
        <span class="auth__field-label">Compte</span>
        <input type="text" value="<?= $safeUsername ?>" disabled readonly>
      </label>
      <label class="auth__field">
        <span class="auth__field-label">Nom affiché</span>
        <input type="text" name="display_name" value="<?= $safeDisplay ?>"
               maxlength="128" autocomplete="name">
      </label>
      <label class="auth__field">
        <span class="auth__field-label">Mot de passe <small>(8 caractères min.)</small></span>
        <input type="password" name="password" required
               minlength="8" autocomplete="new-password">
      </label>
      <label class="auth__field">
        <span class="auth__field-label">Confirmer le mot de passe</span>
        <input type="password" name="password_confirm" required
               minlength="8" autocomplete="new-password">
      </label>
      <button class="auth__submit" type="submit">
        <span>Activer mon compte</span>
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path d="M3 8h9M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </form>
  </section>
  <footer class="auth__foot">
    <span><?= $biName ?> · Est. 2014</span>
    <span><?= $biCity ?> · <?= $biCountryCode ?></span>
  </footer>
</main>
</body>
</html>
<?php
    exit;
}

// 6. All validated — hash and persist atomically
$newHash  = password_hash($password, PASSWORD_ARGON2ID);
$inviteId = (int) $invite['id'];
$purpose  = $invite['purpose'];

try {
    $pdo->beginTransaction();

    // Consume the invite token FIRST — this is the single-use race gate.
    // If two requests race, only one gets rowCount=1; the loser must not
    // proceed to set the password.
    if (!invite_consume($pdo, $inviteId)) {
        $pdo->rollBack();
        // Another request already consumed this token — treat as invalid link.
        echo $invalidHtml;
        exit;
    }

    // Only reached if this request won the consume race.
    $up = $pdo->prepare(
        "UPDATE users
            SET password_hash = ?,
                display_name  = ?,
                is_active     = 1
          WHERE id = ?"
    );
    $dispName = ($displayName !== '') ? $displayName : null;
    $up->execute([$newHash, $dispName, $userId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    // Log but do not reveal internals
    error_log('set-password transaction failed: ' . $e->getMessage());
    echo $invalidHtml;
    exit;
}

// 6b. For password-reset flows, revoke all remember-me sessions so stolen
//     device cookies are invalidated immediately after the reset.
//     New-user invites skip this (no prior sessions to revoke).
if ($purpose === 'reset') {
    rt_revoke_all($userId, $pdo);
}

// 7. Fetch the fresh user row (post-write, not from memory) and auto-login
$freshStmt = $pdo->prepare(
    "SELECT id, username, email, display_name, role, is_active
       FROM users WHERE id = ? LIMIT 1"
);
$freshStmt->execute([$userId]);
$freshUser = $freshStmt->fetch();

if (!$freshUser) {
    // Should not happen, but degrade gracefully
    header("Location: /login.php", true, 302);
    exit;
}

// Log the successful activation for audit
$logIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$logLine = sprintf(
    "%s set-password success user=%s ip=%s\n",
    date('c'),
    preg_replace('/[^a-zA-Z0-9_@.\-]/', '?', (string) ($freshUser['username'] ?? '')),
    $logIp
);
@file_put_contents('/var/log/maltytask/auth.log', $logLine, FILE_APPEND | LOCK_EX);

auth_login($freshUser);

// Open-redirect-safe: always go to root
header("Location: /", true, 302);
exit;
