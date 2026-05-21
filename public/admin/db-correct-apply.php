<?php
declare(strict_types=1);

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/db-correct.php";
require __DIR__ . "/../../app/schema-meta.php";

require_admin();
$me = current_user();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /admin/db-browser.php", true, 303);
    exit;
}

if (!csrf_verify($_POST["csrf"] ?? null)) {
    http_response_code(400);
    header("Content-Type: text/html; charset=utf-8");
    echo "<!doctype html><meta charset=utf-8><body><h1>400</h1><p>CSRF invalide. <a href=\"/admin/db-browser.php\">Retour</a></p></body>";
    exit;
}

$token = $_POST["token"] ?? "";
if (!is_string($token) || $token === "") {
    http_response_code(400);
    header("Content-Type: text/html; charset=utf-8");
    echo "<!doctype html><meta charset=utf-8><body><h1>400</h1><p>Token absent. <a href=\"/admin/db-browser.php\">Retour</a></p></body>";
    exit;
}

$payload = dbcorrect_pop_pending($token);
if ($payload === null) {
    http_response_code(410);
    header("Content-Type: text/html; charset=utf-8");
    echo "<!doctype html><meta charset=utf-8>"
       . "<link rel=stylesheet href=/css/app.css>"
       . "<body class=auth><h1>410 — expiré</h1>"
       . "<div class=err>La proposition a expiré ou a déjà été appliquée. "
       . "Recommence depuis le DB Browser.</div>"
       . "<p><a href=\"/admin/db-browser.php\">Retour</a></p></body>";
    exit;
}

$pdo = maltytask_pdo();

// Hard policy guard — schema_meta wins regardless of how the payload was constructed.
$tableMeta = schema_meta_for_table($pdo, $payload["table"]);
$policy    = $tableMeta["corrections_policy"] ?? "allowed";
if ($policy === "blocked" || $policy === "blocked_with_redirect") {
    $hint  = $tableMeta["upstream_hint"] ?? null;
    $class = $tableMeta["table_class"] ?? "";
    if ($policy === "blocked_with_redirect") {
        $msg = "Table dérivée — modifications désactivées.";
        if ($hint !== null && $hint !== "") {
            $msg .= " Pour corriger : " . htmlspecialchars($hint);
        }
    } else {
        $msg = "Lecture seule (table " . htmlspecialchars($class) . ").";
    }
    http_response_code(403);
    header("Content-Type: text/html; charset=utf-8");
    echo "<!doctype html><meta charset=utf-8>"
       . "<link rel=stylesheet href=/css/app.css>"
       . "<body class=auth><h1>403 — ⛔ " . htmlspecialchars($msg) . "</h1>"
       . "<p><a href=\"/admin/db-browser.php?table=" . urlencode($payload["table"]) . "\">Retour</a></p></body>";
    exit;
}

try {
    $result = dbcorrect_apply($pdo, $payload, $me);
} catch (Throwable $e) {
    http_response_code(500);
    header("Content-Type: text/html; charset=utf-8");
    $safe = htmlspecialchars($e->getMessage());
    echo "<!doctype html><meta charset=utf-8>"
       . "<link rel=stylesheet href=/css/app.css>"
       . "<body class=auth><h1>500 — erreur</h1>"
       . "<div class=err>L'exécution a échoué et a été annulée :<br>{$safe}</div>"
       . "<p><a href=\"/admin/db-browser.php\">Retour</a></p></body>";
    exit;
}

// Build redirect back to the same table view with a flash message in the URL.
$qs = http_build_query([
    "table"            => $payload["table"],
    "applied_action"   => $payload["action"],
    "applied_rows"     => $result["rows_affected"],
    "applied_col"      => $payload["column"] ?? "",
    "aliases_upserted" => $result["aliases_upserted"],
]);
header("Location: /admin/db-browser.php?{$qs}", true, 303);
exit;
