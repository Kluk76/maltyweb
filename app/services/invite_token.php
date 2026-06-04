<?php
declare(strict_types=1);

/**
 * Invite / password-reset token service.
 *
 * Modelled on app/services/remember_token.php.
 *
 * Tokens are 64-char hex strings (bin2hex(random_bytes(32))).
 * Only the SHA-256 hex digest is ever stored in the DB (token_hash CHAR(64)).
 * The raw token is returned to the caller once and never persisted.
 *
 * Re-invite safety: invite_create() expires any prior unconsumed token for
 * the same (user_id, purpose) pair before inserting the new row, so old links
 * become invalid the moment a fresh link is issued.
 */

/**
 * Create a new invite/reset token for a user.
 *
 * Revokes/expires all prior unconsumed tokens for (user_id, purpose), then
 * inserts a new row storing only the SHA-256 hash of the raw token.
 *
 * @param PDO    $pdo
 * @param int    $userId     users.id of the invited user
 * @param int    $createdBy  users.id of the admin issuing the invite
 * @param string $purpose    'invite' or 'reset'
 * @param int    $ttlHours   Token lifetime in hours (default 72)
 * @return string  The raw (unhashed) token — caller builds the URL from this.
 *                 This value is never stored and cannot be recovered.
 */
function invite_create(
    PDO    $pdo,
    int    $userId,
    int    $createdBy,
    string $purpose   = 'invite',
    int    $ttlHours  = 72
): string {
    // Expire any prior unconsumed tokens for this user+purpose so old links
    // are immediately invalid once a fresh invite is issued.
    $revoke = $pdo->prepare(
        "UPDATE user_invites
            SET consumed_at = NOW()
          WHERE user_id     = ?
            AND purpose     = ?
            AND consumed_at IS NULL
            AND expires_at  > NOW()"
    );
    $revoke->execute([$userId, $purpose]);

    // Generate a 256-bit raw token; store only its SHA-256 hex.
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

    $ins = $pdo->prepare(
        "INSERT INTO user_invites
             (user_id, token_hash, purpose, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->execute([$userId, $tokenHash, $purpose, $expiresAt, $createdBy]);

    return $rawToken;
}

/**
 * Look up a token by its raw value.
 *
 * Hashes the input and queries by the hash (UNIQUE index lookup — no timing
 * side-channel from table scanning). Returns the full row only if:
 *   - the hash matches a row
 *   - consumed_at IS NULL (not yet used)
 *   - expires_at > NOW() (not expired)
 *
 * Returns null on any failure — callers MUST NOT distinguish between
 * "no such token", "expired", and "already consumed" to prevent enumeration.
 *
 * @param PDO    $pdo
 * @param string $rawToken  The token from the URL query string.
 * @return array|null  Full user_invites row, or null.
 */
function invite_lookup(PDO $pdo, string $rawToken): ?array
{
    if ($rawToken === '') return null;

    $hash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        "SELECT id, user_id, token_hash, purpose, expires_at, consumed_at, created_by, created_at
           FROM user_invites
          WHERE token_hash  = ?
            AND consumed_at IS NULL
            AND expires_at  > NOW()
          LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    return ($row !== false) ? $row : null;
}

/**
 * Mark a token as consumed (single-use enforcement).
 * Uses `AND consumed_at IS NULL` so only one concurrent request wins — the
 * UPDATE affects exactly 1 row for the winner and 0 rows for any racer.
 * Call this inside a transaction; commit the password update only when this
 * returns true.
 *
 * @param PDO $pdo
 * @param int $inviteId  user_invites.id
 * @return bool  true if this call won the consume race; false if already consumed.
 */
function invite_consume(PDO $pdo, int $inviteId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE user_invites SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL"
    );
    $stmt->execute([$inviteId]);
    return $stmt->rowCount() === 1;
}
