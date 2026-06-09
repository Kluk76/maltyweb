<?php
/**
 * migrate.php — apply unapplied SQL files from db/migrations/ in order.
 *
 * Usage:
 *   php scripts/migrate.php          # apply pending migrations
 *   php scripts/migrate.php --status # list applied + pending, no changes
 *
 * Tracks applied files in the schema_migrations table (auto-created).
 * A migration file is one .sql file; multiple statements separated by `;`
 * on their own line.
 */
declare(strict_types=1);

require __DIR__ . "/../app/db.php";

$status = in_array("--status", $argv, true);

$pdo = maltytask_pdo();

// Bootstrap schema_migrations table from inside this script in case it doesn't
// exist yet (very first migration). Idempotent.
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(128) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = $pdo->query("SELECT filename FROM schema_migrations")
    ->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$migrationsDir = realpath(__DIR__ . "/../db/migrations");
$files = glob("$migrationsDir/*.sql") ?: [];
sort($files, SORT_STRING);

$pending = [];
foreach ($files as $f) {
    if (!isset($applied[basename($f)])) {
        $pending[] = $f;
    }
}

if ($status) {
    echo "Applied migrations: " . count($applied) . "\n";
    foreach (array_keys($applied) as $name) echo "  ✓ $name\n";
    echo "Pending migrations: " . count($pending) . "\n";
    foreach ($pending as $f) echo "  · " . basename($f) . "\n";
    exit(0);
}

if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

// MySQL DDL (CREATE/ALTER/DROP TABLE) auto-commits — wrapping in a transaction
// would silently break the rollback path. Run statements directly; record the
// migration only on success. A failure mid-file leaves partial state — that's
// the price of MySQL DDL semantics. Keep migrations small and idempotent
// (CREATE TABLE IF NOT EXISTS, ALTER TABLE … IF NOT EXISTS-equivalents).
$touchedRefPages = false;

foreach ($pending as $f) {
    $name = basename($f);
    echo "→ applying $name … ";
    $sql = file_get_contents($f);

    if (stripos($sql, 'ref_pages') !== false) {
        $touchedRefPages = true;
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAIL\n  " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "✓ all migrations applied\n";

if ($touchedRefPages) {
    echo "\nℹ tour-coverage: a migration touched ref_pages — checking Visite guidée cards…\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/tour-gap-check.php') . ' --quiet', $rc);
    if ($rc !== 0) {
        echo "⚠ One or more active pages have no Visite guidée card. Run scripts/tour-gap-check.php for detail, or dispatch maltyweb-tour-steward.\n";
    }
}
