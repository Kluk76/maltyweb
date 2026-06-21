<?php
declare(strict_types=1);
/**
 * Per-user UI preference helpers backed by user_ui_prefs (mig 430).
 * Absence of a row means "use the default" — callers supply a $default.
 */

if (!function_exists('user_pref_get')) {
    /**
     * Retrieve a single UI preference for a user.
     *
     * @param PDO         $pdo
     * @param int         $userId
     * @param string      $key
     * @param string|null $default  Returned when no row exists.
     * @return string|null
     */
    function user_pref_get(PDO $pdo, int $userId, string $key, ?string $default = null): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT pref_value FROM user_ui_prefs WHERE user_id_fk = ? AND pref_key = ? LIMIT 1'
        );
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($row !== false) ? (string) $row['pref_value'] : $default;
    }
}

if (!function_exists('user_pref_set')) {
    /**
     * Persist a UI preference for a user (upsert).
     *
     * @param PDO    $pdo
     * @param int    $userId
     * @param string $key
     * @param string $value
     * @return void
     */
    function user_pref_set(PDO $pdo, int $userId, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO user_ui_prefs (user_id_fk, pref_key, pref_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$userId, $key, $value]);
    }
}
