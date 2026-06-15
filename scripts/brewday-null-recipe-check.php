<?php
declare(strict_types=1);
/**
 * brewday-null-recipe-check.php — Read-only NULL-FK backstop probe.
 *
 * SELECTs all non-tombstoned bd_brewing_brewday_v2 rows where recipe_id_fk IS NULL
 * and prints them (or reports OK if none).
 *
 * Purpose: post-migration verification + standing operational backstop.
 * THIS SCRIPT NEVER WRITES.
 *
 * Usage (on VPS):
 *   sudo php /var/www/maltytask/scripts/brewday-null-recipe-check.php
 *
 * Exit codes:
 *   0  — no NULL-FK brewday rows found
 *   1  — at least one NULL-FK row detected
 */

$root = dirname(__DIR__);
require_once $root . '/app/db.php';

$pdo  = maltytask_pdo();
$rows = $pdo->query(
    "SELECT id, beer, batch, cct, event_date
       FROM bd_brewing_brewday_v2
      WHERE recipe_id_fk IS NULL AND is_tombstoned = 0
      ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) === 0) {
    echo "brewday-null-recipe-check: OK — no NULL-FK brewday rows.\n";
    exit(0);
}

echo "brewday-null-recipe-check: FAIL — " . count($rows) . " NULL-FK row(s) detected:\n";
foreach ($rows as $r) {
    printf(
        "  id=%-6d  beer=%-20s  batch=%-5s  cct=%s  event_date=%s\n",
        $r['id'],
        $r['beer'],
        $r['batch'],
        $r['cct'] ?? '?',
        $r['event_date'] ?? '?'
    );
}
exit(1);
