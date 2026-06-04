<?php
declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/services/remember_token.php";

/**
 * Idle timeout for authenticated sessions. After this many seconds without
 * activity, current_user() destroys the session and require_login() redirects
 * to /login.php?reason=expired. PHP gc_maxlifetime is also pinned to this
 * value so server-side session files are garbage-collected eventually.
 */
const MALTYTASK_SESSION_IDLE_MAX = 1800; // 30 min

/**
 * Periodic session-id rotation interval. Mitigates session-fixation /
 * stolen-cookie windows by issuing a fresh id every N seconds of activity.
 */
const MALTYTASK_SESSION_REGEN_INTERVAL = 900; // 15 min

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

    // Server-side garbage collection: clean up expired session files.
    // Probability 1/100 keeps the sweep cheap while bounding stale-file age.
    ini_set('session.gc_maxlifetime', (string) MALTYTASK_SESSION_IDLE_MAX);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

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
        "SELECT id, username, email, password_hash, display_name, role, manager_scope, is_active
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
        "id"            => (int) $user["id"],
        "username"      => $user["username"],
        "display_name"  => $user["display_name"] ?? $user["username"],
        "role"          => $user["role"],
        "manager_scope" => $user["manager_scope"] ?? null,
    ];
    $_SESSION["last_activity"] = time();
    $_SESSION["regen_at"] = time();
}

/**
 * Returns the current logged-in user (associative array) or null.
 *
 * Order of precedence:
 *   1. Active PHP session (fast path, 30-min idle timeout).
 *   2. Remember-me cookie mt_remember (90-day persistent, token-rotation on use).
 */
function current_user(): ?array
{
    maltytask_session_start();
    $user = $_SESSION["user"] ?? null;

    // ── 1. Session path ─────────────────────────────────────────────────────
    if ($user !== null) {
        $now  = time();
        $last = $_SESSION["last_activity"] ?? $now;

        // Idle timeout: destroy session, signal expiry to require_login()
        if ($now - $last > MALTYTASK_SESSION_IDLE_MAX) {
            $_SESSION = [];
            session_destroy();
            $GLOBALS["_maltytask_session_expired"] = true;
            // Fall through to remember-me check below
        } else {
            // Touch activity timestamp
            $_SESSION["last_activity"] = $now;

            // Periodic session-id rotation
            $regen = $_SESSION["regen_at"] ?? $now;
            if ($now - $regen > MALTYTASK_SESSION_REGEN_INTERVAL) {
                session_regenerate_id(true);
                $_SESSION["regen_at"] = $now;
            }

            return $user;
        }
    }

    // ── 2. Remember-me cookie path ──────────────────────────────────────────
    $raw_token = $_COOKIE[RT_COOKIE_NAME] ?? null;
    if ($raw_token !== null && $raw_token !== '') {
        $pdo        = maltytask_pdo();
        $ip         = $_SERVER["REMOTE_ADDR"] ?? null;
        $ua         = $_SERVER["HTTP_USER_AGENT"] ?? null;
        $remembered = rt_lookup($raw_token, $ip, $ua, $pdo);

        if ($remembered !== null) {
            // Rebuild session from remembered user
            maltytask_session_start();
            session_regenerate_id(true);
            $_SESSION["user"] = [
                "id"            => $remembered["id"],
                "username"      => $remembered["username"],
                "display_name"  => $remembered["display_name"],
                "role"          => $remembered["role"],
                "manager_scope" => $remembered["manager_scope"] ?? null,
            ];
            $_SESSION["last_activity"] = time();
            $_SESSION["regen_at"]      = time();
            return $_SESSION["user"];
        }
    }

    return null;
}

/**
 * Redirects to /login.php if no user. Pass-through otherwise.
 * Preserves the requested path so login can bounce back.
 */
function require_login(): void
{
    if (current_user() !== null) return;

    $next = $_SERVER["REQUEST_URI"] ?? "/";
    $params = ["next" => $next];
    if (!empty($GLOBALS["_maltytask_session_expired"])) {
        $params["reason"] = "expired";
    }
    $qs = http_build_query($params);
    header("Location: /login.php?{$qs}", true, 302);
    exit;
}

/**
 * Destroys the session entirely and revokes any active remember-me token.
 */
function auth_logout(): void
{
    maltytask_session_start();

    // Revoke the remember-me token for this device (if any)
    $raw_token = $_COOKIE[RT_COOKIE_NAME] ?? null;
    if ($raw_token !== null && $raw_token !== '') {
        $hash = hash('sha256', $raw_token);
        try {
            $pdo  = maltytask_pdo();
            $stmt = $pdo->prepare(
                "UPDATE user_remember_tokens
                    SET revoked_at = CURRENT_TIMESTAMP
                  WHERE token_hash = ? AND revoked_at IS NULL"
            );
            $stmt->execute([$hash]);
        } catch (\Throwable $e) {
            // Non-fatal: session still destroyed below
        }
        rt_clear_cookie();
    }

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
 * Domain-scoped capability check.
 *
 * Admins can do everything. Managers are constrained by manager_scope:
 *   'production' — can perform production-override actions (⊇ logistics)
 *   'logistics'  — can perform logistics/supply-chain actions only
 *   'all'        — unrestricted manager (both domains)
 *   NULL         — same as no scope (returns false; only operator role has NULL)
 *
 * A production manager also passes the 'logistics' check because production ⊇ logistics.
 */
function manager_can(string $domain, ?array $user = null): bool
{
    $u = $user ?? current_user();
    if (($u["role"] ?? "") === "admin") return true;
    if (($u["role"] ?? "") !== "manager") return false;
    $scope = $u["manager_scope"] ?? null;
    if ($scope === "all") return true;
    if ($scope === $domain) return true;
    // production ⊇ logistics: a production manager may also perform logistics actions.
    if ($scope === "production" && $domain === "logistics") return true;
    return false;
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
