<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";

/**
 * Bootstraps a hardened PHP session.
 * - HttpOnly cookies (no JS access)
 * - SameSite=Strict (no cross-site sends)
 * - Secure flag if request came via HTTPS (auto-detected)
 * - Session name 'maltytask_sid'
 *
 * Call this before any output on every request.
 */
function maltytask_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $secure = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";

    session_name("maltytask_sid");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => $secure,
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    session_start();
}

/**
 * Verifies username + password against the users table.
 * Returns the user row on success, null on failure.
 * Updates last_login_at on success.
 */
function auth_verify(string $username, string $password): ?array
{
    $pdo = maltytask_pdo();
    $stmt = $pdo->prepare(
        "SELECT id, username, email, password_hash, display_name, role, is_active
         FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) return null;
    if ((int) $user["is_active"] !== 1) return null;
    if (!password_verify($password, $user["password_hash"])) return null;

    // Rehash if algorithm/cost changed
    if (password_needs_rehash($user["password_hash"], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID);
        $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $up->execute([$newHash, $user["id"]]);
    }

    $touch = $pdo->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?");
    $touch->execute([$user["id"]]);

    unset($user["password_hash"]);
    return $user;
}

/**
 * Marks the current session as authenticated. Regenerates session id to
 * prevent fixation. Call after auth_verify() returns a user.
 */
function auth_login(array $user): void
{
    maltytask_session_start();
    session_regenerate_id(true);
    $_SESSION["user"] = [
        "id"           => (int) $user["id"],
        "username"     => $user["username"],
        "display_name" => $user["display_name"] ?? $user["username"],
        "role"         => $user["role"],
    ];
}

/**
 * Returns the current logged-in user (associative array) or null.
 */
function current_user(): ?array
{
    maltytask_session_start();
    return $_SESSION["user"] ?? null;
}

/**
 * Redirects to /login.php if no user. Pass-through otherwise.
 * Preserves the requested path so login can bounce back.
 */
function require_login(): void
{
    if (current_user() !== null) return;

    $next = $_SERVER["REQUEST_URI"] ?? "/";
    $qs = http_build_query(["next" => $next]);
    header("Location: /login.php?{$qs}", true, 302);
    exit;
}

/**
 * Destroys the session entirely.
 */
function auth_logout(): void
{
    maltytask_session_start();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

/**
 * Role check helpers. Pass an explicit user array to test someone other
 * than the current session, otherwise current_user() is used.
 */
function is_admin(?array $user = null): bool
{
    $u = $user ?? current_user();
    return ($u["role"] ?? "") === "admin";
}

function is_manager(?array $user = null): bool
{
    $u = $user ?? current_user();
    return ($u["role"] ?? "") === "manager";
}

/**
 * Hard role gates. require_login() first, then 403 if role mismatch.
 */
function require_admin(): void
{
    require_login();
    if (is_admin()) return;
    _send_403("Admin uniquement.");
}

function require_manager_or_admin(): void
{
    require_login();
    if (is_admin() || is_manager()) return;
    _send_403("Réservé aux comptes admin et manager.");
}

function _send_403(string $msg): void
{
    http_response_code(403);
    header("Content-Type: text/html; charset=utf-8");
    $safe = htmlspecialchars($msg);
    echo "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\">"
       . "<title>403 — accès refusé</title>"
       . "<link rel=\"stylesheet\" href=\"/css/app.css\"></head>"
       . "<body class=\"auth\"><h1>403</h1><div class=\"err\">{$safe}</div>"
       . "<p><a href=\"/\">Retour à l'accueil</a></p></body></html>";
    exit;
}
