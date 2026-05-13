<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/csrf.php";

require_admin();
$me = current_user();

header("Content-Type: text/html; charset=utf-8");

$active_module = "ingest-failures";
$crumbs        = ["Accueil", "Admin", "Ingest Failures"];

// ── POST: mark a failure resolved ────────────────────────────────────────────
$flashMsg  = null;
$flashType = "ok";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_GET["action"] ?? "") === "resolve") {
    $id   = isset($_POST["id"]) ? (int) $_POST["id"] : 0;
    $note = trim($_POST["resolution_note"] ?? "");
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        http_response_code(400);
        $flashMsg  = "CSRF token invalide.";
        $flashType = "err";
    } elseif ($id <= 0) {
        $flashMsg  = "ID invalide.";
        $flashType = "err";
    } else {
        try {
            $pdo = maltytask_pdo();
            $stmt = $pdo->prepare(
                "UPDATE ingest_failures
                    SET resolved_at      = CURRENT_TIMESTAMP,
                        resolution_note  = :note
                  WHERE id = :id AND resolved_at IS NULL"
            );
            $stmt->execute([":note" => $note === "" ? "marked resolved by operator" : $note, ":id" => $id]);
            $affected = $stmt->rowCount();
            $qs = http_build_query(array_filter([
                "source_tab" => $_POST["source_tab"] ?? "",
                "status"     => $_POST["status_filter"] ?? "",
                "q"          => $_POST["q"] ?? "",
                "resolved"   => $affected > 0 ? "1" : null,
            ]));
            header("Location: /admin/ingest-failures.php?" . $qs, true, 303);
            exit;
        } catch (Throwable $e) {
            $flashMsg  = "Erreur DB : " . htmlspecialchars($e->getMessage());
            $flashType = "err";
        }
    }
}

// ── Flash from redirect ───────────────────────────────────────────────────────
if ($flashMsg === null && ($_GET["resolved"] ?? "") === "1") {
    $flashMsg  = "Failure marquée traitée.";
    $flashType = "ok";
}

// ── Ensure PDO is available for the partial ───────────────────────────────────
try {
    $pdo = maltytask_pdo();
} catch (Throwable $e) {
    // will be surfaced by partial
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingest Failures — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
</head>
<body class="home admin ingest-failures-page">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>

<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main admin__main">

  <?php if ($flashMsg !== null): ?>
    <div class="db-flash db-flash--<?= $flashType === "ok" ? "ok" : "err" ?>">
      <?= htmlspecialchars($flashMsg) ?>
    </div>
  <?php endif ?>

  <?php require __DIR__ . "/../modules/triage_form_ingest_partial.php" ?>

</main>

</body>
</html>
