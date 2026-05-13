<?php
declare(strict_types=1);

/**
 * /admin/ingest/run.php — trigger a manual ingest run in the background.
 *
 * GET  ?csrf=TOKEN  — admin only, CSRF-verified.
 * Shells out to ingest.py --tab=all --apply --trigger=manual via nohup.
 * Redirects back to /admin/ingest.php immediately (non-blocking).
 *
 * Security: admin-only, CSRF token required. Per maltyweb topology note,
 * this page is served on Tailscale-only app.maltytask.ch so it is not
 * reachable from the public internet even without auth.
 */

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/csrf.php";

require_admin();

// CSRF: token passed as GET param since there's no POST body.
// The token is short-lived; the admin just clicked the button from ingest.php
// where csrf_token() was rendered. One-shot is fine here.
$token = $_GET["csrf"] ?? "";
if (!csrf_verify($token)) {
    http_response_code(400);
    echo "CSRF token invalide.";
    exit;
}

// Validate that the ingest script exists at the expected VPS path.
// On local dev the path won't exist — that's OK, the exec will fail and
// we redirect with rerun=fail so the operator is not silently lost.
$script  = "/var/www/maltytask/scripts/python/ingest.py";
$venv    = "/var/www/maltytask/.venv/bin/python";
$logDir  = "/var/log/maltytask";
$logFile = $logDir . "/ingest-manual.log";

// Build the command. nohup + & detaches immediately.
// Redirect stdout+stderr to a timestamped logfile so the operator can SSH
// and read it if needed. www-data must be able to write to $logDir
// (chmod 775, group www-data or a dedicated log group).
$ts  = date('YmdHis');
$out = escapeshellarg("{$logDir}/ingest-manual-{$ts}.log");
$cmd = "nohup sudo -u maltytask {$venv} {$script} --tab=all --apply --trigger=manual > {$out} 2>&1 &";

$result = false;
if (is_executable($venv) && is_file($script)) {
    // exec() inside PHP — the redirect runs as www-data BEFORE sudo,
    // so the logfile must be writable by www-data. If it's not, the
    // whole command silently does nothing (memory: feedback_php_exec_redirect_ownership).
    // We touch the file first to ensure it exists and is owned by www-data.
    if (is_dir($logDir) && is_writable($logDir)) {
        touch("{$logDir}/ingest-manual-{$ts}.log");
        exec($cmd);
        $result = true;
    }
}

$qs = $result ? "rerun=started" : "rerun=fail";
header("Location: /admin/ingest.php?{$qs}", true, 303);
exit;
