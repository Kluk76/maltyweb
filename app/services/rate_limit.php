<?php
declare(strict_types=1);

/**
 * Rate-limiting service backed by user_action_log.
 *
 * Pattern: rolling-window COUNT, then INSERT on allow.
 * Race tolerance: at our scale a double-insert is acceptable — there is no
 * distributed lock. The window check is re-evaluated on every request, so
 * brief over-allowance is bounded to the concurrent-request count.
 */

/**
 * Check whether a user is within the rate limit for a given action,
 * and if so, log the action.
 *
 * @param int    $user_id        users.id
 * @param string $action         Short label, e.g. 'upload_document', 'login'
 * @param int    $max_per_window Maximum allowed attempts in the window
 * @param int    $window_seconds Rolling window size in seconds
 * @param string|null $ip        REMOTE_ADDR (stored for audit)
 * @param PDO    $pdo
 * @return bool  true = allowed + logged; false = over limit, NOT logged
 */
function rl_check_and_log(
    int    $user_id,
    string $action,
    int    $max_per_window,
    int    $window_seconds,
    ?string $ip,
    PDO    $pdo
): bool {
    // Count existing actions in the rolling window
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n
           FROM user_action_log
          WHERE user_id   = ?
            AND action    = ?
            AND created_at > NOW() - INTERVAL ? SECOND"
    );
    $stmt->execute([$user_id, $action, $window_seconds]);
    $count = (int)($stmt->fetch()['n'] ?? 0);

    if ($count >= $max_per_window) {
        return false; // over limit — do NOT log
    }

    // Under limit: log the action
    $packed_ip = null;
    if ($ip !== null && $ip !== '') {
        $packed = @inet_pton($ip);
        if ($packed !== false) $packed_ip = $packed;
    }

    $ins = $pdo->prepare(
        "INSERT INTO user_action_log (user_id, action, ip) VALUES (?, ?, ?)"
    );
    $ins->execute([$user_id, $action, $packed_ip]);

    return true;
}

/**
 * Convenience wrapper for document uploads.
 * Limit: 100 uploads per hour per user.
 */
function rl_upload_document(int $user_id, ?string $ip, PDO $pdo): bool
{
    return rl_check_and_log($user_id, 'upload_document', 100, 3600, $ip, $pdo);
}

/**
 * Convenience wrapper for login attempts (per user_id, post-auth).
 * Limit: 30 logins per 10 minutes — mainly for audit; brute-force happens
 * pre-auth (fail2ban handles that layer).
 */
function rl_login(int $user_id, ?string $ip, PDO $pdo): bool
{
    return rl_check_and_log($user_id, 'login', 30, 600, $ip, $pdo);
}
