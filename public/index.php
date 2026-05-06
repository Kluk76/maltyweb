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
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>MaltyTask — Dashboard</title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="dashboard">
<div class="topbar">
  <strong><?= htmlspecialchars($me["display_name"]) ?></strong>
  <span>
    <span class="role">role: <?= htmlspecialchars($me["role"]) ?></span> ·
    <a href="/logout.php">Déconnexion</a>
  </span>
</div>
<div class="panda">
  <svg viewBox="0 0 100 100" width="120" height="120" aria-label="panda vert">
    <circle cx="25" cy="25" r="14" fill="#1f5d2f"/>
    <circle cx="75" cy="25" r="14" fill="#1f5d2f"/>
    <circle cx="25" cy="25" r="7"  fill="#9fd6a8"/>
    <circle cx="75" cy="25" r="7"  fill="#9fd6a8"/>
    <circle cx="50" cy="58" r="34" fill="#9fd6a8"/>
    <ellipse cx="37" cy="52" rx="8" ry="11" fill="#1f5d2f" transform="rotate(-15 37 52)"/>
    <ellipse cx="63" cy="52" rx="8" ry="11" fill="#1f5d2f" transform="rotate(15 63 52)"/>
    <circle cx="38" cy="54" r="2.5" fill="#fff"/>
    <circle cx="62" cy="54" r="2.5" fill="#fff"/>
    <ellipse cx="50" cy="68" rx="4" ry="3" fill="#1f5d2f"/>
    <path d="M 50 71 Q 50 76 45 76" stroke="#1f5d2f" fill="none" stroke-width="1.8" stroke-linecap="round"/>
    <path d="M 50 71 Q 50 76 55 76" stroke="#1f5d2f" fill="none" stroke-width="1.8" stroke-linecap="round"/>
  </svg>
</div>
<h1>MaltyTask — Dashboard</h1>
<p>Status: <span class="<?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span></p>
<?php if ($row): ?>
<table>
  <tr><td>database</td><td><?= htmlspecialchars($row["db"] ?? "") ?></td></tr>
  <tr><td>user</td><td><?= htmlspecialchars($row["user"] ?? "") ?></td></tr>
  <tr><td>mysql</td><td><?= htmlspecialchars($row["version"] ?? "") ?></td></tr>
  <tr><td>tables</td><td><?= count($tables) ? htmlspecialchars(implode(", ", $tables)) : "<em>(empty)</em>" ?></td></tr>
  <tr><td>php</td><td><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
</table>
<?php endif ?>
</body>
</html>
