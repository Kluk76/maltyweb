<?php
declare(strict_types=1);

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/db.php";

/**
 * Shared helpers for the DB Browser correction flow.
 *
 * Two-step flow:
 *   propose.php  validates inputs, fetches a preview of affected rows,
 *                stashes the action under a token in $_SESSION, renders
 *                a confirmation page.
 *   apply.php    pulls the action from $_SESSION by token, runs UPDATE
 *                or DELETE in a transaction, writes one debug_corrections
 *                row with the old values, redirects.
 *
 * The proposed payload never round-trips through the client between
 * propose and apply — only the opaque token does. This eliminates any
 * tamper surface on the apply step.
 */

const DBCORRECT_MAX_IDS         = 1000;
const DBCORRECT_PENDING_TTL     = 300;   // seconds
const DBCORRECT_READONLY_TABLES = ["schema_migrations", "debug_corrections"];
const DBCORRECT_BLOCKED_COLUMNS = [
    // table => [columns…]   — never editable through this UI
    "users" => ["password_hash"],
];

/**
 * Returns the list of tables the user is allowed to modify, intersected
 * with what actually exists in the DB.
 */
function dbcorrect_writable_tables(PDO $pdo): array
{
    $all = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($all, DBCORRECT_READONLY_TABLES));
}

/**
 * Returns column metadata for $table:
 *   ['columns' => [['name'=>..,'type'=>..], ...],
 *    'pk_column' => 'id' | null,
 *    'editable_columns' => [name, name, …] (excludes PK + blocked + auto)]
 *
 * pk_column is null when the table has no single-column PK; the
 * correction UI is disabled in that case.
 */
function dbcorrect_table_meta(PDO $pdo, string $table): array
{
    $tableQuoted = "`" . str_replace("`", "``", $table) . "`";
    $cols = $pdo->query("SHOW COLUMNS FROM {$tableQuoted}")->fetchAll();

    $columns = [];
    foreach ($cols as $c) {
        $columns[] = [
            "name"  => $c["Field"],
            "type"  => $c["Type"],
            "null"  => $c["Null"] === "YES",
            "key"   => $c["Key"],
            "extra" => $c["Extra"],
        ];
    }

    // Single-column PK only.
    $pkRows = $pdo->query("SHOW KEYS FROM {$tableQuoted} WHERE Key_name = 'PRIMARY'")->fetchAll();
    $pkColumn = (count($pkRows) === 1) ? $pkRows[0]["Column_name"] : null;

    $blocked = DBCORRECT_BLOCKED_COLUMNS[$table] ?? [];

    $editable = [];
    foreach ($columns as $c) {
        if ($c["name"] === $pkColumn)              continue;
        if (in_array($c["name"], $blocked, true))  continue;
        // Skip auto-managed columns (auto_increment, generated stored).
        if (stripos($c["extra"], "auto_increment") !== false)        continue;
        if (stripos($c["extra"], "VIRTUAL GENERATED") !== false)     continue;
        if (stripos($c["extra"], "STORED GENERATED") !== false)      continue;
        $editable[] = $c["name"];
    }

    return [
        "columns"          => $columns,
        "pk_column"        => $pkColumn,
        "editable_columns" => $editable,
    ];
}

/**
 * Validates POST inputs from the propose form. Returns a normalized
 * payload array on success, or throws RuntimeException with a
 * user-readable message on failure.
 */
function dbcorrect_validate(PDO $pdo, array $post): array
{
    if (!csrf_verify($post["csrf"] ?? null)) {
        throw new RuntimeException("Session expirée — recharge la page.");
    }

    $table = $post["table"] ?? "";
    if (!is_string($table) || $table === "") {
        throw new RuntimeException("Table manquante.");
    }
    $writable = dbcorrect_writable_tables($pdo);
    if (!in_array($table, $writable, true)) {
        throw new RuntimeException("Table non modifiable : " . $table);
    }

    $action = $post["action"] ?? "";
    if (!in_array($action, ["update", "delete"], true)) {
        throw new RuntimeException("Action invalide.");
    }

    $idsRaw = $post["ids"] ?? null;
    if (!is_array($idsRaw) || count($idsRaw) === 0) {
        throw new RuntimeException("Sélectionne au moins une ligne.");
    }
    if (count($idsRaw) > DBCORRECT_MAX_IDS) {
        throw new RuntimeException("Trop de lignes sélectionnées (max "
            . DBCORRECT_MAX_IDS . ").");
    }
    $ids = [];
    foreach ($idsRaw as $v) {
        if (!is_scalar($v)) {
            throw new RuntimeException("ID invalide.");
        }
        $s = (string) $v;
        if ($s === "") continue;
        $ids[] = $s;
    }
    if (count($ids) === 0) {
        throw new RuntimeException("Aucun ID valide sélectionné.");
    }
    $ids = array_values(array_unique($ids));

    $meta = dbcorrect_table_meta($pdo, $table);
    if ($meta["pk_column"] === null) {
        throw new RuntimeException("Table sans clé primaire à colonne unique — "
            . "correction non disponible.");
    }

    $payload = [
        "table"     => $table,
        "action"    => $action,
        "pk_column" => $meta["pk_column"],
        "ids"       => $ids,
        "column"    => null,
        "new_value" => null,
        "set_null"  => false,
    ];

    if ($action === "update") {
        $col = $post["column"] ?? "";
        if (!is_string($col) || !in_array($col, $meta["editable_columns"], true)) {
            throw new RuntimeException("Colonne non modifiable : " . htmlspecialchars((string) $col));
        }
        $setNull = !empty($post["set_null"]);
        $newVal  = $post["new_value"] ?? "";
        if (!$setNull && !is_string($newVal)) {
            throw new RuntimeException("Valeur invalide.");
        }

        // Reject NULL when column doesn't allow it.
        if ($setNull) {
            $colMeta = null;
            foreach ($meta["columns"] as $c) {
                if ($c["name"] === $col) { $colMeta = $c; break; }
            }
            if ($colMeta && !$colMeta["null"]) {
                throw new RuntimeException("La colonne `{$col}` n'accepte pas NULL.");
            }
        }

        $payload["column"]    = $col;
        $payload["new_value"] = $setNull ? null : (string) $newVal;
        $payload["set_null"]  = $setNull;
    }

    return $payload;
}

/**
 * Runs the SELECT preview for a payload — what current values will be
 * affected. Returns array of rows: each row has the PK column and (for
 * update) the target column's current value; for delete, full row.
 */
function dbcorrect_preview(PDO $pdo, array $payload): array
{
    $table  = $payload["table"];
    $pk     = $payload["pk_column"];
    $action = $payload["action"];
    $ids    = $payload["ids"];

    $tableQ = "`" . str_replace("`", "``", $table) . "`";
    $pkQ    = "`" . str_replace("`", "``", $pk)    . "`";
    $place  = implode(",", array_fill(0, count($ids), "?"));

    if ($action === "update") {
        $colQ = "`" . str_replace("`", "``", $payload["column"]) . "`";
        $sql  = "SELECT {$pkQ} AS _pk, {$colQ} AS _old "
              . "FROM {$tableQ} WHERE {$pkQ} IN ({$place}) "
              . "ORDER BY {$pkQ}";
    } else {
        // For delete, fetch the entire row so the audit log can store it.
        $sql = "SELECT * FROM {$tableQ} WHERE {$pkQ} IN ({$place}) ORDER BY {$pkQ}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * Stashes a validated payload in $_SESSION under a fresh random token.
 * Returns the token. The token is the only piece of state that
 * round-trips through the client.
 */
function dbcorrect_store_pending(array $payload): string
{
    maltytask_session_start();
    $token = bin2hex(random_bytes(24));
    if (empty($_SESSION["db_correct_pending"])) {
        $_SESSION["db_correct_pending"] = [];
    }
    $_SESSION["db_correct_pending"][$token] = [
        "payload"    => $payload,
        "expires_at" => time() + DBCORRECT_PENDING_TTL,
    ];
    // Lightweight GC of expired tokens.
    $now = time();
    foreach ($_SESSION["db_correct_pending"] as $t => $entry) {
        if ($entry["expires_at"] < $now) {
            unset($_SESSION["db_correct_pending"][$t]);
        }
    }
    return $token;
}

/**
 * Pops a pending payload by token. Returns the payload or null when
 * absent / expired. The entry is removed atomically — replay-proof.
 */
function dbcorrect_pop_pending(string $token): ?array
{
    maltytask_session_start();
    $entry = $_SESSION["db_correct_pending"][$token] ?? null;
    if ($entry === null) return null;
    unset($_SESSION["db_correct_pending"][$token]);
    if ($entry["expires_at"] < time()) return null;
    return $entry["payload"];
}

/**
 * Executes a validated payload inside a transaction. Writes one
 * debug_corrections row with the captured old values, then runs the
 * UPDATE/DELETE. Returns the number of affected rows.
 *
 * Throws on any DB error — the transaction will have been rolled back.
 */
function dbcorrect_apply(PDO $pdo, array $payload, array $user): int
{
    $table  = $payload["table"];
    $pk     = $payload["pk_column"];
    $action = $payload["action"];
    $ids    = $payload["ids"];

    $tableQ = "`" . str_replace("`", "``", $table) . "`";
    $pkQ    = "`" . str_replace("`", "``", $pk)    . "`";
    $place  = implode(",", array_fill(0, count($ids), "?"));

    $pdo->beginTransaction();
    try {
        // 1. Snapshot old values for audit.
        if ($action === "update") {
            $colQ = "`" . str_replace("`", "``", $payload["column"]) . "`";
            $stmt = $pdo->prepare(
                "SELECT {$pkQ} AS _pk, {$colQ} AS _old FROM {$tableQ} "
              . "WHERE {$pkQ} IN ({$place})"
            );
            $stmt->execute($ids);
            $old = [];
            foreach ($stmt->fetchAll() as $r) {
                $old[(string) $r["_pk"]] = $r["_old"];
            }
            $oldJson = json_encode($old, JSON_UNESCAPED_UNICODE);

            $upStmt = $pdo->prepare(
                "UPDATE {$tableQ} SET {$colQ} = ? WHERE {$pkQ} IN ({$place})"
            );
            $params = array_merge([$payload["set_null"] ? null : $payload["new_value"]], $ids);
            $upStmt->execute($params);
            $rowsAffected = $upStmt->rowCount();
        } else {
            // delete — snapshot full rows.
            $stmt = $pdo->prepare(
                "SELECT * FROM {$tableQ} WHERE {$pkQ} IN ({$place})"
            );
            $stmt->execute($ids);
            $oldJson = json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);

            $delStmt = $pdo->prepare(
                "DELETE FROM {$tableQ} WHERE {$pkQ} IN ({$place})"
            );
            $delStmt->execute($ids);
            $rowsAffected = $delStmt->rowCount();
        }

        // 2. Audit row.
        $logStmt = $pdo->prepare(
            "INSERT INTO debug_corrections "
          . "(user_id, username, table_name, action, pk_column, "
          . " affected_ids, column_name, new_value, set_null, old_values, rows_affected) "
          . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $logStmt->execute([
            (int) $user["id"],
            $user["username"],
            $table,
            $action,
            $pk,
            json_encode($ids, JSON_UNESCAPED_UNICODE),
            $payload["column"],
            $payload["set_null"] ? null : $payload["new_value"],
            $payload["set_null"] ? 1 : 0,
            $oldJson,
            $rowsAffected,
        ]);

        $pdo->commit();
        return $rowsAffected;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
