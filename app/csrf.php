<?php
declare(strict_types=1);

require_once __DIR__ . "/auth.php";

/**
 * Returns the current request's CSRF token, generating one if absent.
 * Tokens are tied to the session and rotate when the session id rotates.
 */
function csrf_token(): string
{
    maltytask_session_start();
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

/**
 * Constant-time check of a posted CSRF token against the session token.
 */
function csrf_verify(?string $posted): bool
{
    maltytask_session_start();
    $expected = $_SESSION["csrf"] ?? "";
    if ($expected === "" || $posted === null || $posted === "") return false;
    return hash_equals($expected, $posted);
}
