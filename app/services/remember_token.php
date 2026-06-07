<?php
declare(strict_types=1);

/**
 * Remember-me token service.
 *
 * Tokens are 64-char hex strings (bin2hex(random_bytes(32))).
 * Only the SHA-256 hash is ever stored in the DB.
 * Cookie name: mt_remember — HttpOnly, Secure, SameSite=Lax, Path=/, 90-day expires.
 *
 * Rotation-on-use: every successful rt_lookup() issues a fresh token and
 * revokes the old one. A stolen token is valid at most once.
 */

const RT_COOKIE_NAME    = 'mt_remember';
const RT_TTL_DAYS       = 90;
const RT_TTL_SECONDS    = RT_TTL_DAYS * 86400;

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/** Parse a UA string into a short human label, e.g. "iPhone Safari / iOS 17". */
function _rt_ua_short(?string $ua): string
{
    if ($ua === null || $ua === '') return 'Appareil inconnu';
    $ua = substr($ua, 0, 512); // bound before regex

    // iOS / iPhone / iPad
    if (preg_match('/iPad|iPhone|iPod/i', $ua)) {
        $os = 'iOS';
        if (preg_match('/OS (\d+)/i', $ua, $m)) $os .= ' ' . $m[1];
        $browser = preg_match('/CriOS/i', $ua) ? 'Chrome'
            : (preg_match('/FxiOS/i', $ua) ? 'Firefox' : 'Safari');
        return "iPhone/iPad {$browser} / {$os}";
    }
    // Android
    if (preg_match('/Android (\d+)/i', $ua, $m)) {
        $browser = preg_match('/Chrome\/(\d+)/i', $ua) ? 'Chrome'
            : (preg_match('/Firefox\/(\d+)/i', $ua) ? 'Firefox' : 'Browser');
        return "Android {$m[1]} / {$browser}";
    }
    // Windows desktop
    if (preg_match('/Windows NT/i', $ua)) {
        $browser = preg_match('/Edg\//i', $ua) ? 'Edge'
            : (preg_match('/Chrome\/(\d+)/i', $ua) ? 'Chrome'
            : (preg_match('/Firefox\/(\d+)/i', $ua) ? 'Firefox' : 'Browser'));
        return "Windows / {$browser}";
    }
    // macOS desktop
    if (preg_match('/Macintosh/i', $ua)) {
        $browser = preg_match('/Edg\//i', $ua) ? 'Edge'
            : (preg_match('/Chrome\/(\d+)/i', $ua) ? 'Chrome'
            : (preg_match('/Firefox\/(\d+)/i', $ua) ? 'Firefox' : 'Safari'));
        return "macOS / {$browser}";
    }
    // Linux desktop
    if (preg_match('/Linux/i', $ua)) {
        $browser = preg_match('/Chrome\/(\d+)/i', $ua) ? 'Chrome'
            : (preg_match('/Firefox\/(\d+)/i', $ua) ? 'Firefox' : 'Browser');
        return "Linux / {$browser}";
    }

    // Fallback: first 60 chars
    return substr($ua, 0, 60);
}

/** Pack IP address (v4 or v6) to binary for storage. Returns null on invalid input. */
function _rt_pack_ip(?string $ip): ?string
{
    if ($ip === null || $ip === '') return null;
    $packed = @inet_pton($ip);
    return ($packed !== false) ? $packed : null;
}

/** Unpack binary IP back to string. */
function _rt_unpack_ip(?string $bin): string
{
    if ($bin === null || $bin === '') return '';
    $addr = @inet_ntop($bin);
    return ($addr !== false) ? $addr : '';
}

/** Set the remember-me cookie on the response. */
function _rt_set_cookie(string $raw_token, bool $delete = false): void
{
    $secure  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $expires = $delete ? (time() - 86400) : (time() + RT_TTL_SECONDS);
    setcookie(RT_COOKIE_NAME, $delete ? '' : $raw_token, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Create a new remember-me token for a user.
 * Sets the cookie and returns the raw token string.
 *
 * @param int    $user_id      users.id
 * @param string|null $device_label  Optional operator-supplied label; defaults to UA short-name.
 * @param string|null $ip       REMOTE_ADDR
 * @param string|null $ua       HTTP_USER_AGENT (will be truncated to 255 chars)
 * @param PDO    $pdo
 * @return string  The raw (unhashed) token, also set as the mt_remember cookie.
 */
function rt_create(int $user_id, ?string $device_label, ?string $ip, ?string $ua, PDO $pdo): string
{
    $raw_token  = bin2hex(random_bytes(32));
    $hash       = hash('sha256', $raw_token);
    $packed_ip  = _rt_pack_ip($ip);
    $ua_trimmed = ($ua !== null) ? substr($ua, 0, 255) : null;
    $label      = ($device_label !== null && $device_label !== '')
        ? substr($device_label, 0, 80)
        : _rt_ua_short($ua);
    $expires_ts = date('Y-m-d H:i:s', time() + RT_TTL_SECONDS);

    $stmt = $pdo->prepare(
        "INSERT INTO user_remember_tokens
             (user_id, token_hash, last_used_at, last_ip, last_ua, expires_at, device_label)
         VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $hash, $packed_ip, $ua_trimmed, $expires_ts, $label]);

    _rt_set_cookie($raw_token);
    return $raw_token;
}

/**
 * Validate a remember-me token from the cookie.
 * On success: rotates the token (revoke old, issue new cookie), updates
 * last_used_at / last_ip / last_ua, and returns the user row.
 * Returns null on any failure (unknown hash, expired, revoked).
 *
 * @param string      $raw_token  Value of the mt_remember cookie.
 * @param string|null $ip
 * @param string|null $ua
 * @param PDO         $pdo
 * @return array|null  User row (same shape as auth_login puts in session), or null.
 */
function rt_lookup(string $raw_token, ?string $ip, ?string $ua, PDO $pdo): ?array
{
    $hash = hash('sha256', $raw_token);

    $stmt = $pdo->prepare(
        "SELECT t.id AS token_id, t.user_id, t.expires_at, t.revoked_at,
                t.device_label,
                u.id, u.username, u.display_name, u.role, u.manager_scope,
                u.access_preset_id_fk, u.is_active
           FROM user_remember_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ?
          LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) return null;
    if ((int)$row['is_active'] !== 1) return null;
    if ($row['revoked_at'] !== null) return null;
    if (strtotime($row['expires_at']) < time()) return null;

    // Build the user array (same shape that auth_login stores in $_SESSION)
    $user = [
        'id'                   => (int)$row['id'],
        'username'             => $row['username'],
        'display_name'         => $row['display_name'] ?? $row['username'],
        'role'                 => $row['role'],
        'manager_scope'        => $row['manager_scope'] ?? null,
        'access_preset_id_fk'  => isset($row['access_preset_id_fk'])
                                       ? (int) $row['access_preset_id_fk']
                                       : null,
    ];

    // ── Token rotation: revoke old hash, issue new token ──────────────────
    $pdo->beginTransaction();
    try {
        // Revoke old
        $rev = $pdo->prepare(
            "UPDATE user_remember_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $rev->execute([$row['token_id']]);

        // Create replacement
        $new_raw    = bin2hex(random_bytes(32));
        $new_hash   = hash('sha256', $new_raw);
        $packed_ip  = _rt_pack_ip($ip);
        $ua_trimmed = ($ua !== null) ? substr($ua, 0, 255) : null;
        $expires_ts = date('Y-m-d H:i:s', time() + RT_TTL_SECONDS);

        $ins = $pdo->prepare(
            "INSERT INTO user_remember_tokens
                 (user_id, token_hash, last_used_at, last_ip, last_ua, expires_at, device_label)
             VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?)"
        );
        $ins->execute([
            $user['id'],
            $new_hash,
            $packed_ip,
            $ua_trimmed,
            $expires_ts,
            $row['device_label'],
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return null;
    }

    _rt_set_cookie($new_raw);
    return $user;
}

/**
 * Revoke a single token by its DB id.
 * Authorization: the WHERE includes user_id so a user cannot revoke another's tokens.
 *
 * @return bool  true if a row was actually revoked.
 */
function rt_revoke(int $token_id, int $user_id, PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "UPDATE user_remember_tokens
            SET revoked_at = CURRENT_TIMESTAMP
          WHERE id = ? AND user_id = ? AND revoked_at IS NULL"
    );
    $stmt->execute([$token_id, $user_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Revoke ALL active tokens for a user (e.g. password change).
 *
 * @return int  Number of tokens revoked.
 */
function rt_revoke_all(int $user_id, PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "UPDATE user_remember_tokens
            SET revoked_at = CURRENT_TIMESTAMP
          WHERE user_id = ? AND revoked_at IS NULL"
    );
    $stmt->execute([$user_id]);
    return $stmt->rowCount();
}

/**
 * List active (non-revoked, non-expired) tokens for a user.
 * Returns an array of rows, each with: id, device_label, created_at,
 * last_used_at, last_ip (formatted), last_ua, expires_at.
 */
function rt_list(int $user_id, PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT id, device_label, created_at, last_used_at, last_ip, last_ua, expires_at
           FROM user_remember_tokens
          WHERE user_id = ?
            AND revoked_at IS NULL
            AND expires_at > CURRENT_TIMESTAMP
          ORDER BY last_used_at DESC, created_at DESC"
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();

    // Unpack binary IP for display
    foreach ($rows as &$r) {
        $r['last_ip'] = _rt_unpack_ip($r['last_ip']);
    }
    unset($r);
    return $rows;
}

/**
 * Clear the remember-me cookie from the client (sets past-expiry empty cookie).
 */
function rt_clear_cookie(): void
{
    _rt_set_cookie('', true);
}

/**
 * Revoke the remember-me token stored in the current request's mt_remember cookie,
 * then clear the cookie. Used when a device is classified as shared — a stale 90-day
 * token from a prior personal login on that machine must not persist.
 *
 * Does NOT call rt_lookup() (which rotates the token and issues a fresh one).
 * Instead: reads the cookie, SHA-256-hashes it, DELETEs (revokes) the matching
 * row, and clears the cookie — exactly mirroring the hashing convention in rt_create().
 *
 * No-op if the cookie is absent or the hash has no matching row.
 *
 * @param PDO $pdo
 */
function rt_revoke_current(PDO $pdo): void
{
    $rawToken = $_COOKIE[RT_COOKIE_NAME] ?? '';
    if ($rawToken === '') {
        return;
    }

    $hash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        "UPDATE user_remember_tokens
            SET revoked_at = CURRENT_TIMESTAMP
          WHERE token_hash = ? AND revoked_at IS NULL"
    );
    $stmt->execute([$hash]);

    rt_clear_cookie();
}
