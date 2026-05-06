<?php
declare(strict_types=1);

/**
 * Returns a PDO connection to the maltytask DB.
 * Reads credentials from /var/www/maltytask/config/db.env (mode 640).
 */
function maltytask_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $env = parse_ini_file(__DIR__ . "/../config/db.env", false, INI_SCANNER_RAW);
    if ($env === false) {
        throw new RuntimeException("cannot read config/db.env");
    }

    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        $env["DB_HOST"],
        $env["DB_PORT"],
        $env["DB_NAME"]
    );

    $pdo = new PDO($dsn, $env["DB_USER"], $env["DB_PASSWORD"], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
