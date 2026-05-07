<?php
declare(strict_types=1);

require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/db.php";

/**
 * Shared helpers for the Settings sub-pages.
 *
 * - Flash messages (via $_SESSION) for the post/redirect/get pattern after
 *   form submissions.
 * - Friendly translation of MySQL error codes (FK constraints, duplicates).
 */

/**
 * Stashes a one-shot flash message in the session. Read once via flash_pop().
 */
function flash_set(string $type, string $msg): void
{
    maltytask_session_start();
    $_SESSION["flash"] = ["type" => $type, "msg" => $msg];
}

/**
 * Pops the flash message (clears it from the session). Returns null when
 * no flash is pending.
 */
function flash_pop(): ?array
{
    maltytask_session_start();
    $f = $_SESSION["flash"] ?? null;
    unset($_SESSION["flash"]);
    return $f;
}

/**
 * Prints the flash banner if one is pending. Call inside <main>, before
 * the main content.
 */
function flash_render(): void
{
    $f = flash_pop();
    if ($f === null) return;
    $type = $f["type"] === "ok" ? "ok" : "err";
    $cls  = "db-flash db-flash--{$type}";
    $icon = $type === "ok" ? "✓" : "⚠";
    echo "<div class=\"{$cls}\">{$icon} " . htmlspecialchars($f["msg"]) . "</div>";
}

/**
 * Maps a PDOException onto a user-readable message. Captures the common
 * cases (FK violation, duplicate key, NOT NULL); falls back to the raw
 * driver message otherwise.
 */
function pdo_friendly_error(Throwable $e, string $context = ""): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, "1452")) {
        // FK violation on INSERT/UPDATE
        return "Référence invalide — la valeur liée n'existe pas.";
    }
    if (str_contains($msg, "1451")) {
        // FK violation on DELETE
        return "Suppression impossible : cette ligne est référencée par d'autres tables.";
    }
    if (str_contains($msg, "1062")) {
        // Duplicate entry on UNIQUE
        return "Doublon : cette valeur existe déjà.";
    }
    if (str_contains($msg, "1048")) {
        // NOT NULL violation
        return "Champ obligatoire manquant.";
    }
    return ($context !== "" ? "[{$context}] " : "") . $msg;
}

/**
 * 303-redirects to the given URL and exits. Use after a successful POST
 * to avoid form-resubmission on refresh.
 */
function redirect_to(string $url): void
{
    header("Location: {$url}", true, 303);
    exit;
}

/**
 * Enum values for ref_cct/yt/bbt.status and ref_yeast_strains.type.
 * Single source of truth — used by both the form dropdowns and the
 * server-side validation.
 */
const VESSEL_STATUSES = ["active", "maintenance", "retired"];
const YEAST_TYPES     = ["ale", "lager", "wild", "hybrid", "unknown"];

/**
 * Validates that $value is one of the whitelisted enum members. Throws
 * RuntimeException with a user-readable message otherwise.
 */
function must_be_one_of(string $field, $value, array $allowed): string
{
    $v = (string) $value;
    if (!in_array($v, $allowed, true)) {
        throw new RuntimeException("Valeur invalide pour {$field} : "
            . htmlspecialchars($v) . " (attendu : " . implode(" | ", $allowed) . ")");
    }
    return $v;
}

/**
 * Casts $_POST input to a clean string. Trims whitespace.
 * Returns null when the input is null or an empty string after trim.
 */
function post_str(?string $key, array $src = null): ?string
{
    $src = $src ?? $_POST;
    $v = $src[$key] ?? null;
    if (!is_scalar($v)) return null;
    $s = trim((string) $v);
    return $s === "" ? null : $s;
}

/**
 * Casts $_POST input to int or null.
 */
function post_int(?string $key, array $src = null): ?int
{
    $src = $src ?? $_POST;
    $v = $src[$key] ?? null;
    if (!is_numeric($v)) return null;
    return (int) $v;
}

/**
 * Casts $_POST input to a decimal-aware string (for DECIMAL columns)
 * or null. Accepts both `12.5` and `12,5`.
 */
function post_decimal(?string $key, array $src = null): ?string
{
    $src = $src ?? $_POST;
    $v = $src[$key] ?? null;
    if (!is_scalar($v)) return null;
    $s = trim((string) $v);
    if ($s === "") return null;
    $s = str_replace(",", ".", $s);
    if (!is_numeric($s)) {
        throw new RuntimeException("Nombre invalide : " . htmlspecialchars((string) $v));
    }
    return $s;
}

/**
 * Computes a deterministic row_hash for tables that require one
 * (ref_mi, ref_suppliers, etc.). The hash doesn't have to match the
 * Python ingest hash byte-for-byte — the next ingest run will recompute
 * and overwrite. This is just a placeholder that satisfies the NOT NULL
 * constraint and changes when fields change.
 */
function compute_row_hash(array $values): string
{
    $s = implode("|", array_map(
        fn($v) => $v === null ? "" : (string) $v,
        $values
    ));
    return hash("sha256", $s);
}

/**
 * Renders the emancipation notice at the top of Phase-4/5 sub-pages.
 * Explains the hybrid pattern: ingest still runs, but rows edited via
 * Maltyweb are flagged `last_modified_by='web'` and survive future
 * ingest passes.
 */
function render_ingest_warning(string $tableLabel, string $script): void
{
    echo "<div class=\"settings-warning settings-warning--emancipated\">\n"
       . "  <span class=\"settings-warning__icon\" aria-hidden=\"true\">📌</span>\n"
       . "  <span class=\"settings-warning__text\">\n"
       . "    <strong>" . htmlspecialchars($tableLabel) . "</strong> reste synchronisée depuis BSF "
       . "via <code>" . htmlspecialchars($script) . "</code> pour absorber les nouveautés ajoutées "
       . "côté maltytask. Les lignes éditées ou ajoutées <em>ici</em> sont <em>épinglées</em> "
       . "(<code>last_modified_by='web'</code>) et l'ingest les préservera. "
       . "Les rows épinglées portent l'icône 📌 dans le tableau.\n"
       . "  </span>\n"
       . "</div>\n";
}
