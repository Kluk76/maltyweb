<?php
/**
 * create-user.php — CLI helper to create a user.
 *
 * Usage:
 *   php scripts/create-user.php <username> [--role=admin|operator|viewer] [--email=...] [--display-name=...]
 *
 * Prompts interactively for the password (no echo). Hashes with argon2id.
 */
declare(strict_types=1);

require __DIR__ . "/../app/db.php";

if ($argc < 2) {
    fwrite(STDERR, "usage: php scripts/create-user.php <username> [--role=admin|operator|viewer] [--email=...] [--display-name=...]\n");
    exit(64);
}

$username = $argv[1];
$role = "operator";
$email = null;
$displayName = null;

for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (preg_match('/^--role=(admin|operator|viewer)$/', $arg, $m)) {
        $role = $m[1];
    } elseif (preg_match('/^--email=(.+)$/', $arg, $m)) {
        $email = $m[1];
    } elseif (preg_match('/^--display-name=(.+)$/', $arg, $m)) {
        $displayName = $m[1];
    } else {
        fwrite(STDERR, "unknown argument: $arg\n");
        exit(64);
    }
}

if (!preg_match('/^[a-zA-Z0-9_.-]{2,64}$/', $username)) {
    fwrite(STDERR, "invalid username (allowed: a-z A-Z 0-9 _ . -, length 2-64)\n");
    exit(65);
}

// Read password without echo
fwrite(STDOUT, "Password for {$username}: ");
system("stty -echo");
$password = trim((string) fgets(STDIN));
system("stty echo");
fwrite(STDOUT, "\n");

if (strlen($password) < 8) {
    fwrite(STDERR, "password too short (minimum 8 characters)\n");
    exit(65);
}

fwrite(STDOUT, "Confirm: ");
system("stty -echo");
$confirm = trim((string) fgets(STDIN));
system("stty echo");
fwrite(STDOUT, "\n");

if ($password !== $confirm) {
    fwrite(STDERR, "passwords don't match\n");
    exit(65);
}

$hash = password_hash($password, PASSWORD_ARGON2ID);

$pdo = maltytask_pdo();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, display_name, role)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$username, $email, $hash, $displayName, $role]);
    $id = $pdo->lastInsertId();
    fwrite(STDOUT, "✓ created user #$id ({$username}, role={$role})\n");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), "Duplicate entry")) {
        fwrite(STDERR, "user already exists (username or email collision)\n");
        exit(70);
    }
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(70);
}
