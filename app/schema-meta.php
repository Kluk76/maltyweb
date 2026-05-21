<?php
declare(strict_types=1);

/**
 * Thin read-only accessor for the schema_meta table.
 *
 * Returns the schema_meta row for $table, or null when the table is absent
 * from schema_meta (pre-migration tables, temporary tables, etc.).
 *
 * @return array{table_name: string, table_class: string, writer_script: string|null,
 *               corrections_policy: string, upstream_hint: string|null, notes: string|null}|null
 */
function schema_meta_for_table(PDO $pdo, string $table): ?array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        "SELECT table_name, table_class, writer_script, corrections_policy, upstream_hint, notes "
      . "FROM schema_meta WHERE table_name = ?"
    );
    $stmt->execute([$table]);
    $row = $stmt->fetch() ?: null;
    $cache[$table] = $row;
    return $row;
}

/**
 * Bulk-load schema_meta for a list of tables. Cheaper than N single reads
 * when rendering the table list. Returns a map of table_name → row.
 *
 * @param  string[] $tables
 * @return array<string, array>  keyed by table_name
 */
function schema_meta_bulk(PDO $pdo, array $tables): array
{
    if (empty($tables)) {
        return [];
    }
    $place = implode(",", array_fill(0, count($tables), "?"));
    $stmt  = $pdo->prepare(
        "SELECT table_name, table_class, writer_script, corrections_policy, upstream_hint, notes "
      . "FROM schema_meta WHERE table_name IN ({$place})"
    );
    $stmt->execute($tables);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row["table_name"]] = $row;
    }
    return $rows;
}
