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
 * Resolves a typed login identifier (full username, first name, or email)
 * to a single active user row, or null when there is no match or the match
 * is ambiguous.
 *
 * SAFETY INVARIANT — ambiguous → fail closed.
 * If the normalised input matches MORE THAN ONE active user (e.g. two users
 * share a first name), this function returns null rather than picking one
 * arbitrarily. This protects against future first-name collisions silently
 * granting access to the wrong account.
 *
 * Matching rules (all case-insensitive, whitespace-normalised):
 *   • full username  — LOWER(username) = $norm
 *   • email          — LOWER(email)    = $norm
 *   • first-name     — LOWER(SUBSTRING_INDEX(username,' ',1)) = $firstToken
 *   • email local-part — LOWER(SUBSTRING_INDEX(email,'@',1)) = $firstToken
 *
 * utf8mb4_unicode_ci already folds accents on the DB side; LOWER() is
 * belt-and-suspenders for engines that might differ on case folding.
 */
function auth_resolve_user(PDO $pdo, string $input): ?array
{
    // Normalise: collapse internal whitespace, trim, lowercase.
    $norm       = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $input)));
    $firstToken = explode(' ', $norm)[0];

    $stmt = $pdo->prepare(
        "SELECT id, username, email, password_hash, display_name, role, manager_scope,
                access_preset_id_fk, is_active
           FROM users
          WHERE is_active = 1
            AND (
                    LOWER(username) = ?
                 OR LOWER(email)    = ?
                 OR LOWER(SUBSTRING_INDEX(username, ' ', 1)) = ?
                 OR (email IS NOT NULL AND LOWER(SUBSTRING_INDEX(email, '@', 1)) = ?)
                )"
    );
    $stmt->execute([$norm, $norm, $firstToken, $firstToken]);
    $rows = $stmt->fetchAll();

    // Deduplicate by primary key in case two match-arms land the same row.
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int) $row['id']] = $row;
    }

    // Exactly one distinct active user → safe to return.
    if (count($byId) === 1) {
        return reset($byId);
    }

    // 0 matches or ≥2 distinct users → fail closed (no enumeration signal to caller).
    return null;
}

/**
 * Verifies a typed identifier + password against the users table.
 * Returns the user row on success, null on failure.
 * Updates last_login_at on success.
 *
 * The identifier is resolved via auth_resolve_user() which accepts the full
 * username ("First Last"), first-name alone ("Gonzalo"), or email address —
 * all case-insensitive. Ambiguous matches (≥2 candidates) fail closed.
 */
function auth_verify(string $username, string $password): ?array
{
    $pdo  = maltytask_pdo();
    $user = auth_resolve_user($pdo, $username);

    // Resolver already enforces is_active=1 and uniqueness; defensive re-check.
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
        "id"                   => (int) $user["id"],
        "username"             => $user["username"],
        "display_name"         => $user["display_name"] ?? $user["username"],
        "role"                 => $user["role"],
        "manager_scope"        => $user["manager_scope"] ?? null,
        "access_preset_id_fk"  => isset($user["access_preset_id_fk"])
                                       ? (int) $user["access_preset_id_fk"]
                                       : null,
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
                "id"                   => $remembered["id"],
                "username"             => $remembered["username"],
                "display_name"         => $remembered["display_name"],
                "role"                 => $remembered["role"],
                "manager_scope"        => $remembered["manager_scope"] ?? null,
                "access_preset_id_fk"  => isset($remembered["access_preset_id_fk"])
                                              ? (int) $remembered["access_preset_id_fk"]
                                              : null,
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
 * Expéditions write gate.
 * Write access is granted to admins, operators, and logistics/production managers.
 * A manager with scope=NULL (read-only financial viewer) is denied.
 */
function can_write_expeditions(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!$u) return false;
    return is_admin($u)
        || ($u['role'] ?? '') === 'operator'
        || manager_can('logistics', $u);
}

/**
 * Entity Discussion Tracker gate (comm_threads / comm_messages).
 * Grants access to managers and admins only — operators are excluded.
 * This surface exposes private supplier email correspondence and is
 * intentionally not available to production/logistics operators.
 */
function can_use_comm_tracker(?array $user = null): bool
{
    $u = $user ?? current_user();
    if (!$u) return false;
    return is_admin($u) || is_manager($u);
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

// ── Per-page access control ──────────────────────────────────────────────────

/**
 * Returns all ref_pages rows keyed by page_key.
 * Loaded once per request via static cache — ≤1 query total.
 *
 * @return array<string, array{min_role: string, domain: string}>
 */
function _page_registry(): array
{
    static $registry = null;
    if ($registry !== null) return $registry;

    try {
        $pdo  = maltytask_pdo();
        $stmt = $pdo->query("SELECT page_key, min_role, domain FROM ref_pages");
        $registry = [];
        foreach ($stmt->fetchAll() as $row) {
            $registry[$row['page_key']] = [
                'min_role' => $row['min_role'],
                'domain'   => $row['domain'],
            ];
        }
    } catch (\Throwable $e) {
        $registry = []; // degrade gracefully — deny non-admins on DB error
    }
    return $registry;
}

/**
 * Returns the user_page_access override rows for the given user,
 * keyed by page_key. Loaded once per request via static cache.
 *
 * @return array<string, bool>  page_key → granted (true/false)
 */
function _user_page_overrides(int $userId): array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    try {
        $pdo  = maltytask_pdo();
        $stmt = $pdo->prepare(
            "SELECT rp.page_key, upa.granted
               FROM user_page_access upa
               JOIN ref_pages rp ON rp.id = upa.page_id_fk
              WHERE upa.user_id_fk = ?"
        );
        $stmt->execute([$userId]);
        $cache[$userId] = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$userId][$row['page_key']] = (bool)(int)$row['granted'];
        }
    } catch (\Throwable $e) {
        $cache[$userId] = [];
    }
    return $cache[$userId];
}

/**
 * Returns the set of page_keys in a given preset.
 * Loaded once per request via static cache.
 *
 * @return array<string, true>  page_key → true (membership set)
 */
function _preset_page_keys(int $presetId): array
{
    static $cache = [];
    if (array_key_exists($presetId, $cache)) return $cache[$presetId];

    try {
        $pdo  = maltytask_pdo();
        $stmt = $pdo->prepare(
            "SELECT rp.page_key
               FROM ref_access_preset_pages rapp
               JOIN ref_pages rp ON rp.id = rapp.page_id_fk
              WHERE rapp.preset_id_fk = ?"
        );
        $stmt->execute([$presetId]);
        $cache[$presetId] = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$presetId][$row['page_key']] = true;
        }
    } catch (\Throwable $e) {
        $cache[$presetId] = [];
    }
    return $cache[$presetId];
}

/**
 * Role rank for floor comparison. Higher = more privileged.
 */
function _role_rank(string $role): int
{
    return match($role) {
        'viewer'   => 0,
        'operator' => 1,
        'manager'  => 2,
        'admin'    => 3,
        default    => 0,
    };
}

/**
 * Checks whether the given (or current) user may access a page by page_key.
 *
 * Resolution order:
 *   1. Admin bypass — admins always have access.
 *   2. Page not found in ref_pages — deny (unregistered surface).
 *   3. Explicit user_page_access override (per-user grant/deny) — overrides
 *      the role floor below; a deny always denies.
 *   4. Role floor — user's role rank must be ≥ page's min_role rank.
 *   5. Preset membership — if user has a preset assigned.
 *   6. Fallback (no preset) — allow (role floor already passed).
 *      CRITICAL: all current users have access_preset_id_fk=NULL and must
 *      retain today's full access until presets are assigned at onboarding.
 */
function user_can_access(string $page_key, ?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u) return false;

    // 1. Admin bypass — never lock out admins
    if (($u['role'] ?? '') === 'admin') return true;

    // 2. Look up page in registry (static-cached)
    $registry = _page_registry();
    if (!isset($registry[$page_key])) return false;

    $page = $registry[$page_key];

    // 3. Explicit per-user override (static-cached by user id) — wins over the
    //    role floor: a per-user grant can surface a page above the user's role,
    //    and a per-user deny always denies.
    $userId    = (int)($u['id'] ?? 0);
    $overrides = _user_page_overrides($userId);
    if (array_key_exists($page_key, $overrides)) {
        return $overrides[$page_key];
    }

    // 4. Role floor check
    if (_role_rank($u['role'] ?? '') < _role_rank($page['min_role'])) return false;

    // 5. Preset membership
    $presetId = isset($u['access_preset_id_fk']) ? (int)$u['access_preset_id_fk'] : null;
    if ($presetId !== null && $presetId > 0) {
        return isset(_preset_page_keys($presetId)[$page_key]);
    }

    // 6. Fallback — no preset assigned; role floor passed → allow
    return true;
}

/**
 * Require login, then assert page access. Sends 403 if denied.
 * Drop-in companion to require_login() / require_admin().
 */
function require_page_access(string $page_key): void
{
    require_login();
    if (user_can_access($page_key)) return;
    _send_403("Accès non autorisé à cette section.");
}
