<?php
declare(strict_types=1);

/**
 * /admin/ingest/retry-row.php — retry a single ingest failure row.
 *
 * POST-only, CSRF-gated, admin-only.
 * Shells out synchronously to retry_row.py via the sudoers-permitted
 * retry-row.sh wrapper in /opt/maltytask-pipeline/.
 *
 * POST inputs:
 *   id          — ingest_failures.id (int)
 *   csrf        — CSRF token
 *   return_url  — redirect destination (default /admin/ingest-failures.php)
 */

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/csrf.php";

require_admin();

// POST only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

// CSRF
if (!csrf_verify($_POST["csrf"] ?? null)) {
    http_response_code(400);
    echo "CSRF token invalide.";
    exit;
}

$failureId = isset($_POST["id"]) ? (int) $_POST["id"] : 0;
$returnUrl = trim($_POST["return_url"] ?? "");

// Sanitise return_url: must be a relative path on the same origin.
if (!preg_match('#^/[a-zA-Z0-9/_?=&%\-\.]+$#', $returnUrl)) {
    $returnUrl = "/admin/ingest-failures.php";
}

if ($failureId <= 0) {
    $qs = http_build_query(["retry" => "err", "msg" => "ID invalide"]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

// ── Load failure record from DB ───────────────────────────────────────────────
try {
    $pdo = maltytask_pdo();
    $stmt = $pdo->prepare(
        "SELECT id, source_tab, sheet_row_index, resolved_at
           FROM ingest_failures WHERE id = :id"
    );
    $stmt->execute([":id" => $failureId]);
    $failure = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $qs = http_build_query(["retry" => "err", "msg" => "Erreur DB : " . $e->getMessage()]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

if (!$failure) {
    $qs = http_build_query(["retry" => "err", "msg" => "Failure #" . $failureId . " introuvable."]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

if ($failure["resolved_at"] !== null) {
    $qs = http_build_query(["retry" => "already_resolved", "id" => $failureId]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

$sourceTab    = (string) $failure["source_tab"];
$sheetRowIdx  = (int)    $failure["sheet_row_index"];

// Tab names in ingest_failures match the --tab args 1:1 (brewing/fermenting/racking/packaging).
// Guard against unexpected values before passing to shell.
$allowedTabs = ["brewing", "fermenting", "racking", "packaging"];
if (!in_array($sourceTab, $allowedTabs, true)) {
    $qs = http_build_query(["retry" => "err", "msg" => "Source tab inconnue : " . $sourceTab]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

// ── Shell exec via sudoers-permitted wrapper ──────────────────────────────────
$wrapper = "/opt/maltytask-pipeline/retry-row.sh";

if (!is_executable($wrapper)) {
    $qs = http_build_query(["retry" => "err", "msg" => "Wrapper introuvable — vérifier déploiement."]);
    header("Location: {$returnUrl}?{$qs}", true, 303);
    exit;
}

$tabArg   = escapeshellarg($sourceTab);
$rowArg   = escapeshellarg((string) $sheetRowIdx);
$idArg    = escapeshellarg((string) $failureId);
$cmd      = "sudo -u maltytask {$wrapper} {$tabArg} {$rowArg} {$idArg} 2>/dev/null";

$output   = [];
$exitCode = 0;
$timeout  = 30; // seconds — Sheets API round-trip + DB insert

// proc_open for timeout control
$descriptors = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"],
];
$process = proc_open($cmd, $descriptors, $pipes);

$stdout = "";
if (is_resource($process)) {
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start = time();
    while (true) {
        $chunk = fread($pipes[1], 4096);
        if ($chunk !== false && $chunk !== "") {
            $stdout .= $chunk;
        }
        if (feof($pipes[1])) {
            break;
        }
        if (time() - $start > $timeout) {
            proc_terminate($process);
            $stdout = "";
            break;
        }
        usleep(20000); // 20 ms poll
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
}

// ── Parse stdout (last non-empty line is the JSON result) ────────────────────
$jsonLine = "";
foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
    $line = trim($line);
    if ($line !== "") {
        $jsonLine = $line;
        break;
    }
}

$result = null;
if ($jsonLine !== "") {
    $result = json_decode($jsonLine, true);
}

// ── Map result to flash message ───────────────────────────────────────────────
$retryType = "err";
$retryMsg  = "";

if ($result === null) {
    $retryType = "err";
    $retryMsg  = "Erreur inattendue (voir logs)";
} else {
    $status  = $result["status"]  ?? "";
    $outcome = $result["outcome"] ?? "";
    $reason  = $result["reason"]  ?? "";
    $tab     = $result["target_table"] ?? $sourceTab;
    $row     = $sheetRowIdx;
    $reasonText = htmlspecialchars((string)($result["reason_text"] ?? ""));

    if ($status === "ok" && $outcome === "inserted") {
        $retryType = "ok";
        $retryMsg  = "Ligne #{$row} (tab {$tab}) ré-ingérée avec succès.";
    } elseif ($status === "ok" && $outcome === "duplicate") {
        $retryType = "ok";
        $retryMsg  = "Ligne #{$row} déjà présente — failure fermée.";
    } elseif ($status === "ok" && $reason === "already_resolved") {
        $retryType = "info";
        $retryMsg  = "Ligne déjà résolue.";
    } elseif ($status === "failed" && $outcome === "still_failing") {
        $retryType = "warn";
        $retryMsg  = "Ligne #{$row} a échoué à nouveau : {$reasonText}";
    } elseif ($status === "error" && $reason === "bsf_row_empty") {
        $retryType = "warn";
        $retryMsg  = "Ligne BSF vide — opérateur a peut-être déplacé/supprimé la ligne.";
    } elseif ($status === "error" && $reason === "unknown_tab") {
        $retryType = "err";
        $retryMsg  = "Tab source inconnue : " . htmlspecialchars($sourceTab);
    } elseif ($status === "error" && $reason === "unknown_failure_id") {
        $retryType = "err";
        $retryMsg  = "Failure #{$failureId} introuvable.";
    } elseif ($status === "dry_run") {
        // Should not reach here since we always pass --apply; guard anyway
        $retryType = "info";
        $retryMsg  = "Dry-run (pas d'--apply transmis).";
    } else {
        $retryType = "err";
        $retryMsg  = "Réponse inattendue du worker.";
    }
}

$qs = http_build_query(["retry" => $retryType, "msg" => $retryMsg]);

// Strip any existing retry/msg params from return_url before appending
$returnBase = preg_replace('/[?&](retry|msg)=[^&]*/', "", $returnUrl);
$returnBase = rtrim($returnBase, "?&");
$sep        = str_contains($returnBase, "?") ? "&" : "?";
header("Location: {$returnBase}{$sep}{$qs}", true, 303);
exit;
