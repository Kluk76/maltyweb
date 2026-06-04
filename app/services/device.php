<?php
declare(strict_types=1);

/**
 * Device-registry service.
 *
 * A per-browser UUID (`mt_device_id` cookie) identifies a browser agent.
 * Admins can mark a device as "shared" via device_mark_shared(), which causes
 * login.php to refuse remember-me token creation for that device.
 *
 * Cookie: mt_device_id — v4 UUID, 2-year expiry, HttpOnly, Secure, SameSite=Strict.
 *
 * All writes go through device_mark_shared() / device_unmark_shared().
 * Never UPDATE auth_shared_devices.device_id or is_shared directly.
 */

const DEVICE_COOKIE_NAME    = 'mt_device_id';
const DEVICE_COOKIE_TTL     = 63072000; // 2 years in seconds

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/** Generate a random v4 UUID string (36 chars, RFC 4122). */
function _device_uuid_v4(): string
{
    $bytes = random_bytes(16);
    // Set version bits (v4) and variant bits (RFC 4122)
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return sprintf(
        '%08s-%04s-%04s-%04s-%12s',
        bin2hex(substr($bytes, 0, 4)),
        bin2hex(substr($bytes, 4, 2)),
        bin2hex(substr($bytes, 6, 2)),
        bin2hex(substr($bytes, 8, 2)),
        bin2hex(substr($bytes, 10, 6))
    );
}

/** Detect HTTPS the same way maltytask_session_start() does. */
function _device_is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Return the device-id cookie value if already set, or null.
 * Does NOT generate or set a cookie.
 */
function device_id_get(): ?string
{
    $v = $_COOKIE[DEVICE_COOKIE_NAME] ?? null;
    return ($v !== null && $v !== '') ? (string) $v : null;
}

/**
 * Return the current device-id cookie value.
 * If no cookie exists, generate a v4 UUID, set the cookie, and return it.
 *
 * MUST be called before any output (sets a Set-Cookie header).
 * After setting, $_COOKIE[DEVICE_COOKIE_NAME] is populated so the value
 * is readable within the same request.
 *
 * @return string 36-char UUID
 */
function device_id_ensure(): string
{
    $existing = device_id_get();
    if ($existing !== null) {
        return $existing;
    }

    $id = _device_uuid_v4();
    setcookie(DEVICE_COOKIE_NAME, $id, [
        'expires'  => time() + DEVICE_COOKIE_TTL,
        'path'     => '/',
        'domain'   => '',
        'secure'   => _device_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    // Make the value available within this request
    $_COOKIE[DEVICE_COOKIE_NAME] = $id;
    return $id;
}

/**
 * Return true if the given device_id is registered as a shared device
 * (is_shared = 1 in auth_shared_devices).
 *
 * Empty / invalid device IDs return false without hitting the DB.
 *
 * @param string $deviceId  The 36-char UUID from the cookie.
 * @param PDO    $pdo
 * @return bool
 */
function device_is_shared(string $deviceId, PDO $pdo): bool
{
    if ($deviceId === '') return false;

    $stmt = $pdo->prepare(
        "SELECT is_shared FROM auth_shared_devices WHERE device_id = ? LIMIT 1"
    );
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row !== false && (int) $row['is_shared'] === 1);
}

/**
 * Mark a device as shared (upsert).
 *
 * On first registration: inserts a new row with is_shared=1.
 * On subsequent calls: sets is_shared=1, updates last_seen / IP / UA.
 * Label is updated only when a non-null value is supplied.
 *
 * @param string      $deviceId   36-char UUID.
 * @param string|null $label      Human label, e.g. "Poste salle de contrôle 1".
 * @param int|null    $byUserId   users.id of the admin performing the action.
 * @param string|null $ip         IPv4/IPv6 string.
 * @param string|null $ua         User-agent string (truncated to 255).
 * @param PDO         $pdo
 */
function device_mark_shared(
    string  $deviceId,
    ?string $label,
    ?int    $byUserId,
    ?string $ip,
    ?string $ua,
    PDO     $pdo
): void {
    $uaTrimmed = ($ua !== null) ? substr($ua, 0, 255) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO auth_shared_devices
             (device_id, label, is_shared, registered_by, last_seen_at, last_ip, last_ua)
         VALUES (?, ?, 1, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
             is_shared    = 1,
             label        = COALESCE(VALUES(label), label),
             last_seen_at = NOW(),
             last_ip      = VALUES(last_ip),
             last_ua      = VALUES(last_ua)"
    );
    $stmt->execute([$deviceId, $label, $byUserId, $ip, $uaTrimmed]);
}

/**
 * Unmark a device as shared (sets is_shared=0, keeps row for audit).
 *
 * @param string $deviceId
 * @param PDO    $pdo
 */
function device_unmark_shared(string $deviceId, PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "UPDATE auth_shared_devices SET is_shared = 0 WHERE device_id = ?"
    );
    $stmt->execute([$deviceId]);
}

/**
 * List all currently-shared devices (is_shared=1), most-recently-seen first.
 * For use by the admin device-registry UI (future follow-up task).
 *
 * @param PDO $pdo
 * @return array  Rows from auth_shared_devices.
 */
function device_list_shared(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, device_id, label, is_shared, registered_by,
                registered_at, last_seen_at, last_ip, last_ua
           FROM auth_shared_devices
          WHERE is_shared = 1
          ORDER BY last_seen_at DESC"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Touch last-seen metadata for a known registered device.
 * No-op when no row exists for the given device_id (unregistered devices
 * are not auto-created here — use device_mark_shared() for that).
 *
 * @param string      $deviceId
 * @param string|null $ip
 * @param string|null $ua
 * @param PDO         $pdo
 */
function device_touch(string $deviceId, ?string $ip, ?string $ua, PDO $pdo): void
{
    if ($deviceId === '') return;

    $uaTrimmed = ($ua !== null) ? substr($ua, 0, 255) : null;
    $stmt = $pdo->prepare(
        "UPDATE auth_shared_devices
            SET last_seen_at = NOW(), last_ip = ?, last_ua = ?
          WHERE device_id = ?"
    );
    $stmt->execute([$ip, $uaTrimmed, $deviceId]);
}
