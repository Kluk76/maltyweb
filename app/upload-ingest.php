<?php
declare(strict_types=1);

/**
 * app/upload-ingest.php — shared exec helper for the ingest pipeline.
 *
 * Extracted from public/api/upload-document.php so that upload-document.php
 * and upload-retry.php share one definition of the sudoers command contract.
 *
 * PRODUCTION LESSON: the `>> log &` redirect runs as www-data BEFORE sudo,
 * so the log file must be www-data-writable (it already is at
 * /var/log/maltytask/upload-ingest.log). Do NOT move the redirect inside
 * the sudo invocation — it would run as maltytask and fail if the file's
 * group is www-data without group-write permission.
 *
 * Usage:
 *   require __DIR__ . '/upload-ingest.php';
 *   upload_ingest_trigger($inbox_path);   // fires background ingest
 *
 * The %s placeholder in UPLOAD_INGEST_CMD is replaced with an
 * escapeshellarg()-quoted inbox_path. Callers must validate inbox_path
 * against the strict inbox regex BEFORE calling this function.
 */

// Guard: this file defines the constants and functions once; prevent
// redeclaration if somehow required twice.
if (!defined('UPLOAD_INGEST_CMD')) {
    define(
        'UPLOAD_INGEST_CMD',
        'nohup sudo -u maltytask /opt/maltytask-pipeline/ingest-one-local.sh %s'
        . ' >> /var/log/maltytask/upload-ingest.log 2>&1 &'
    );
}

if (!defined('UPLOAD_COMMIT_CMD')) {
    // COMMIT wrapper — triggers the --commit path of ingest-one-local.ts.
    // First %s = upload_id (integer), second %s = user_id (integer, optional but always supplied).
    // Both arguments are validated as integers by the caller before substitution.
    // The >> redirect runs as www-data (BEFORE sudo); the log file must be www-data-writable.
    define(
        'UPLOAD_COMMIT_CMD',
        'nohup sudo -u maltytask /opt/maltytask-pipeline/ingest-one-local-commit.sh %s %s'
        . ' >> /var/log/maltytask/upload-ingest.log 2>&1 &'
    );
}

if (!function_exists('upload_ingest_trigger')) {
    /**
     * Fire the background ingest worker for a file already written to the inbox.
     *
     * @param string $inbox_path  Fully-qualified path that has ALREADY been
     *                            validated against the strict inbox regex by the caller.
     */
    function upload_ingest_trigger(string $inbox_path): void
    {
        $cmd = sprintf(UPLOAD_INGEST_CMD, escapeshellarg($inbox_path));
        exec($cmd);
        // exec() returns immediately; background process runs independently.
    }
}

if (!function_exists('upload_commit_trigger')) {
    /**
     * Fire the background commit worker for a staged invoice.
     *
     * Invokes ingest-one-local-commit.sh <upload_id> <user_id> via sudo,
     * which runs ingest-one-local.ts --commit <upload_id> --by <user_id>.
     *
     * On success the worker stamps doc_invoices.validated_at + validated_by
     * and writes inv_deliveries rows per the staged delivery_write_plan.
     * On failure it writes doc_uploads.error_text (validated_at stays NULL).
     *
     * @param int $uploadId   doc_uploads.id — MUST be a positive integer (caller-validated).
     * @param int $userId     users.id of the operator initiating the validate action.
     */
    function upload_commit_trigger(int $uploadId, int $userId): void
    {
        // Both arguments are integers — no shell-injection risk, but use
        // escapeshellarg for belt-and-suspenders (handles edge cases like 0).
        $cmd = sprintf(
            UPLOAD_COMMIT_CMD,
            escapeshellarg((string) $uploadId),
            escapeshellarg((string) $userId)
        );
        exec($cmd);
        // exec() returns immediately; background process runs independently.
    }
}
