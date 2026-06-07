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

// Guard: this file defines the constant and function once; prevent
// redeclaration if somehow required twice.
if (!defined('UPLOAD_INGEST_CMD')) {
    define(
        'UPLOAD_INGEST_CMD',
        'nohup sudo -u maltytask /opt/maltytask-pipeline/ingest-one-local.sh %s'
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
