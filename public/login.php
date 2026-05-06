<?php
declare(strict_types=1);

require __DIR__ . "/../app/auth.php";
require __DIR__ . "/../app/csrf.php";

maltytask_session_start();

// Already logged in? Bounce to dashboard.
if (current_user() !== null) {
    header("Location: /", true, 302);
    exit;
}

$error = null;
$username = "";

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
        } else {
            auth_login($user);
            $next = $_GET["next"] ?? "/";
            // Reject open-redirects: must be a same-origin path
            if (!is_string($next) || !str_starts_with($next, "/") || str_starts_with($next, "//")) {
                $next = "/";
            }
            header("Location: $next", true, 302);
            exit;
        }
    }
}

$token = csrf_token();
$insecure = empty($_SERVER["HTTPS"]) || $_SERVER["HTTPS"] === "off";
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>MaltyTask — Connexion</title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="auth">
<h1>MaltyTask</h1>
<?php if ($insecure): ?>
  <div class="warn">⚠ Connexion non chiffrée (HTTP). À traiter dès qu'un nom de domaine + Let's Encrypt sont en place.</div>
<?php endif ?>
<?php if ($error): ?>
  <div class="err"><?= htmlspecialchars($error) ?></div>
<?php endif ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
  <label>
    Username
    <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" autofocus required>
  </label>
  <label>
    Password
    <input type="password" name="password" required>
  </label>
  <button type="submit">Connexion</button>
</form>
</body>
</html>
