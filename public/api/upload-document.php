<?php
declare(strict_types=1);

/**
 * POST /api/upload-document.php — operator document upload endpoint.
 *
 * Option B (VPS-local path, active since 2026-05-12):
 *   Drive upload is no longer called here. The file is written directly to
 *   /var/www/maltytask/storage/documents/inbox/ and a background Node process
 *   is triggered to ingest it.
 *
 * Flow:
 *   1. Auth check
 *   2. CSRF verify
 *   3. File presence check
 *   4. Rate-limit check  (100/h per user)
 *   5. MIME + size validation via uv_validate()
 *   6. HEIC/HEIF → JPEG conversion via heif-convert (if needed)
 *   7. Generate UUID storage filename
 *   8. Write file to inbox dir: /var/www/maltytask/storage/documents/inbox/{uuid}.{ext}
 *   9. INSERT doc_uploads row (pipeline_status='uploaded', drive_file_id = uuid)
 *       NOTE: drive_file_id is repurposed as the canonical file identifier (UUID);
 *             it no longer holds a Google Drive file ID for uploads from this endpoint.
 *  10. exec async: sudo -u maltytask ingest-one-local.sh <inbox_path>
 *  11. UPDATE doc_uploads: pipeline_status='triggered', pipeline_started_at=NOW()
 *  12. Return 200 JSON (or 303 redirect for plain form posts)
 *  13. Clean up any HEIC conversion temp file
 *
 * dp_drive_upload() is still defined in document_preview.php for potential
 * future callers — it is no longer called from this endpoint.
 *
 * Any failure between steps 8–11 sets pipeline_status='failed' in doc_uploads.
 *
 * GET: admin-only test form — delegates to upload-test.php rendering logic.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/services/rate_limit.php';
require __DIR__ . '/../../app/services/upload_validation.php';
require __DIR__ . '/../../app/services/document_preview.php';

// ── Storage inbox dir ─────────────────────────────────────────────────────────
const UPLOAD_INBOX_DIR = '/var/www/maltytask/storage/documents/inbox';

// ── Ingest pipeline command template (Option B: local path) ──────────────────
// Uses a dedicated wrapper script (/opt/maltytask-pipeline/ingest-one-local.sh)
// that is the only command www-data can sudo-as-maltytask for local-path ingests.
// The %s placeholder is replaced with escapeshellarg($inbox_path).
// nohup detaches the process; output appended to upload-ingest.log.
const UPLOAD_INGEST_CMD =
    'nohup sudo -u maltytask /opt/maltytask-pipeline/ingest-one-local.sh %s'
    . ' >> /var/log/maltytask/upload-ingest.log 2>&1 &';

// ─────────────────────────────────────────────────────────────────────────────

require_login();
$me = current_user();

// ── GET: not supported (upload-test.php removed in Step 8) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// ── All other non-POST methods ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$pdo    = maltytask_pdo();
$ip     = $_SERVER['REMOTE_ADDR'] ?? null;
$ua     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$userId = (int) $me['id'];

// Detect whether caller wants JSON or a redirect (form vs. JS fetch)
$wants_json = (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'))
           || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'))
           || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

$fail = static function (int $code, string $msg) use ($wants_json): never {
    http_response_code($code);
    if ($wants_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
};

// ── Step 2: CSRF ──────────────────────────────────────────────────────────────
if (!csrf_verify($_POST['csrf'] ?? null)) {
    $fail(400, 'Token CSRF invalide.');
}

// ── Detect upload mode: 'bulk' | 'multipage' | 'single' (legacy default) ─────
// Clients send mode= explicitly; no-mode clients keep legacy behavior unchanged.
$upload_mode_raw = trim($_POST['mode'] ?? '');
$upload_mode     = in_array($upload_mode_raw, ['bulk', 'multipage', 'single'], true)
                   ? $upload_mode_raw
                   : 'single'; // backward-compat default

// ── Step 3: File presence — accept single 'file' OR multi 'files[]' ──────────
$is_multi = isset($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)
            && count(array_filter((array)($_FILES['files']['error'] ?? []), fn($e) => $e !== UPLOAD_ERR_NO_FILE)) > 0;

$is_single = !$is_multi
    && isset($_FILES['file'])
    && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if (!$is_single && !$is_multi) {
    $fail(400, 'Aucun fichier reçu.');
}

// ── Step 4: Rate limit ────────────────────────────────────────────────────────
if (!rl_upload_document($userId, $ip, $pdo)) {
    $fail(429, 'Limite de téléversement atteinte (100/h). Réessaie plus tard.');
}

// ── Steps 5–6: Validate + HEIC conversion ────────────────────────────────────
// Helper: normalise a single $_FILES[x] array OR one element from a multi array
// into a uniform struct, validate, and optionally convert HEIC.
// Returns ['ok'=>true, 'tmp_path', 'mime', 'ext', 'orig_name', 'byte_size', 'cleanup_tmp']
// or ['ok'=>false, 'error'].
$validate_and_convert = static function (
    string $tmp,
    int    $error,
    string $name
) use ($fail): array {
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => "Erreur upload PHP : code {$error}"];
    }

    // Build a synthetic $_FILES[x] structure for uv_validate
    $file_entry = [
        'name'     => $name,
        'tmp_name' => $tmp,
        'error'    => $error,
        'size'     => is_file($tmp) ? (int) filesize($tmp) : 0,
        'type'     => '',
    ];

    $v = uv_validate($file_entry);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => $v['error']];
    }

    $mime       = (string) $v['mime'];
    $ext        = (string) $v['ext'];
    $orig_name  = (string) $v['sanitized_filename'];
    $byte_size  = (int)    $v['size'];
    $tmp_path   = $tmp;
    $cleanup    = null;

    if ($ext === 'heic' || $ext === 'heif') {
        $jpeg_tmp = tempnam(sys_get_temp_dir(), 'mtupload_') . '.jpg';
        $esc_in   = escapeshellarg($tmp_path);
        $esc_out  = escapeshellarg($jpeg_tmp);
        exec("heif-convert {$esc_in} {$esc_out} 2>&1", $conv_out, $conv_rc);

        if ($conv_rc !== 0 || !is_file($jpeg_tmp)) {
            @unlink($jpeg_tmp);
            return ['ok' => false, 'error' => 'Conversion HEIC→JPEG échouée. Réessaie ou envoie un JPEG directement.'];
        }

        $cleanup   = $jpeg_tmp;
        $tmp_path  = $jpeg_tmp;
        $mime      = 'image/jpeg';
        $ext       = 'jpg';
        $byte_size = (int) filesize($jpeg_tmp);
        $orig_name = preg_replace('/\.(heic|heif)$/i', '.jpg', $orig_name) ?? $orig_name;
    }

    return [
        'ok'        => true,
        'tmp_path'  => $tmp_path,
        'mime'      => $mime,
        'ext'       => $ext,
        'orig_name' => $orig_name,
        'byte_size' => $byte_size,
        'cleanup'   => $cleanup,
    ];
};

$cleanup_tmp = null; // extra temp file to delete at end (single-file path)

// ── Bulk mode: each file becomes its own ingest job ───────────────────────────
// Triggered when client sends mode=bulk (desktop drag-drop / browse / paste with N files).
// PDFs are allowed; images stay as separate invoices (no stitching).
// One bad file fails just that entry, not the whole request.
// Guard: max 20 files per bulk upload.
if ($upload_mode === 'bulk') {
    // Re-parse files list (same logic as is_multi pre-check)
    $raw_names  = (array) $_FILES['files']['name'];
    $raw_tmps   = (array) $_FILES['files']['tmp_name'];
    $raw_errors = (array) $_FILES['files']['error'];

    $inputs = [];
    foreach ($raw_names as $i => $rname) {
        $rerr = (int) ($raw_errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($rerr === UPLOAD_ERR_NO_FILE) continue;
        $inputs[] = ['name' => $rname, 'tmp' => (string)$raw_tmps[$i], 'error' => $rerr];
    }

    if (count($inputs) === 0) {
        $fail(400, 'Aucun fichier reçu.');
    }

    // Max 50 files guard
    if (count($inputs) > 50) {
        $fail(400, 'Maximum 50 fichiers par envoi groupé. Veuillez réduire la sélection.');
    }

    // Single-file downgrade: treat as regular single upload but wrap in uploads[] response
    if (count($inputs) === 1) {
        // Fall through to the single-file path below, then re-wrap response at step 12.
        // Set a flag so step 12 knows to emit uploads[] shape.
        $bulk_single_downgrade = true;
        $_FILES['file'] = [
            'name'     => $inputs[0]['name'],
            'tmp_name' => $inputs[0]['tmp'],
            'error'    => $inputs[0]['error'],
            'size'     => is_file($inputs[0]['tmp']) ? (int) filesize($inputs[0]['tmp']) : 0,
            'type'     => '',
        ];
        $upload_mode = 'single';
        $is_single   = true;
        $is_multi    = false;
        // Continue below into the single path; response wrapping handled at step 12.
    } else {
        // ── True bulk: process each file independently ──────────────────────
        $upload_results = [];

        // Determine source value
        $source_raw = trim($_POST['source'] ?? 'maltyweb-web');
        $source     = in_array($source_raw, ['maltyweb-web', 'maltyweb-mobile'], true)
                      ? $source_raw : 'maltyweb-web';

        $packed_ip = null;
        if ($ip !== null && $ip !== '') {
            $packed = @inet_pton($ip);
            if ($packed !== false) $packed_ip = $packed;
        }

        if (!is_dir(UPLOAD_INBOX_DIR)) {
            if (!@mkdir(UPLOAD_INBOX_DIR, 0755, true)) {
                $fail(500, 'Impossible de créer le répertoire inbox : ' . UPLOAD_INBOX_DIR);
            }
        }

        foreach ($inputs as $inp) {
            $entry = ['file_name' => $inp['name']];

            // Validate + convert (HEIC → JPEG; PDF stays as-is)
            $result = $validate_and_convert($inp['tmp'], $inp['error'], $inp['name']);
            if (!$result['ok']) {
                $entry['status'] = 'failed';
                $entry['error']  = $result['error'];
                $upload_results[] = $entry;
                continue;
            }

            // Image files: if not a PDF, convert single image to PDF via img2pdf
            $img_cleanup = null;
            $file_tmp    = $result['tmp_path'];
            $file_mime   = $result['mime'];
            $file_ext    = $result['ext'];
            $file_name   = $result['orig_name'];
            $file_size   = $result['byte_size'];

            if ($file_ext !== 'pdf') {
                // Single-image-to-PDF (same path as the single-file upload would take
                // after img2pdf; image stays as-image here — ingest pipeline handles it).
                // Actually: we pass image files straight to inbox; ingest-one-local.sh
                // wraps single images just as it would from a single upload.
                // No action needed — fall through with original image file.
            }

            // Generate storage filename
            $storage_filename = uv_make_storage_filename($file_name, $file_ext);
            $inbox_path_file  = UPLOAD_INBOX_DIR . '/' . $storage_filename;

            // Path pattern guard
            if (!preg_match(
                '#^/var/www/maltytask/storage/documents/inbox/\d{4}-\d{2}-\d{2}_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z]{2,5}$#',
                $inbox_path_file
            )) {
                if ($result['cleanup']) @unlink($result['cleanup']);
                $entry['status'] = 'failed';
                $entry['error']  = 'Chemin inbox invalide — ingest annulé par sécurité.';
                $upload_results[] = $entry;
                continue;
            }

            // Write file to inbox
            $moved = false;
            if ($result['cleanup'] !== null && $file_tmp === $result['cleanup']) {
                if (@copy($file_tmp, $inbox_path_file)) {
                    @unlink($result['cleanup']);
                    $moved = true;
                }
            } else {
                if (move_uploaded_file($file_tmp, $inbox_path_file)) {
                    $moved = true;
                } elseif ($result['cleanup'] !== null) {
                    if (@copy($file_tmp, $inbox_path_file)) {
                        @unlink($result['cleanup']);
                        $moved = true;
                    }
                }
            }

            if (!$moved) {
                if ($result['cleanup']) @unlink($result['cleanup']);
                $entry['status'] = 'failed';
                $entry['error']  = 'Impossible d\'écrire le fichier dans le répertoire inbox.';
                $upload_results[] = $entry;
                continue;
            }

            // Extract UUID from storage filename
            $file_id = preg_replace('/^\d{4}-\d{2}-\d{2}_/', '', pathinfo($storage_filename, PATHINFO_FILENAME)) ?? '';

            // INSERT doc_uploads
            $bulk_upload_id = null;
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO doc_uploads
                        (user_id, source, original_filename, storage_filename,
                         mime_type, byte_size, pipeline_status, drive_file_id, client_ip, client_ua)
                     VALUES (?, ?, ?, ?, ?, ?, 'uploaded', ?, ?, ?)"
                );
                $ins->execute([
                    $userId, $source, $file_name, $storage_filename,
                    $file_mime, $file_size, $file_id !== '' ? $file_id : null,
                    $packed_ip, $ua !== '' ? $ua : null,
                ]);
                $bulk_upload_id = (int) $pdo->lastInsertId();
            } catch (Throwable $e) {
                @unlink($inbox_path_file);
                $entry['status']    = 'failed';
                $entry['error']     = 'Erreur base de données : ' . $e->getMessage();
                $upload_results[]   = $entry;
                continue;
            }

            // Fire background ingest
            $cmd_bulk = sprintf(UPLOAD_INGEST_CMD, escapeshellarg($inbox_path_file));
            exec($cmd_bulk);

            // UPDATE → triggered
            try {
                $upd = $pdo->prepare(
                    "UPDATE doc_uploads
                        SET pipeline_status     = 'triggered',
                            pipeline_started_at = NOW()
                      WHERE id = ?"
                );
                $upd->execute([$bulk_upload_id]);
            } catch (Throwable $e) {
                error_log("upload-document.php bulk: failed to update doc_uploads id={$bulk_upload_id}: " . $e->getMessage());
            }

            $entry['status']    = 'queued';
            $entry['upload_id'] = $bulk_upload_id;
            $entry['file_id']   = $file_id;
            $entry['status_url'] = '/api/upload-status.php?upload_id=' . $bulk_upload_id;
            $upload_results[] = $entry;
        }

        // Respond with bulk result array
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'mode'    => 'bulk',
            'uploads' => $upload_results,
        ]);
        exit;
    }
}

// Note: if bulk downgraded to single above, $bulk_single_downgrade=true is set;
// step 12 uses it to wrap the response in uploads[] shape.

if ($is_multi) {
    // ── Multi-file path ────────────────────────────────────────────────────
    // Validate ALL files first — reject whole batch on any failure.
    // Only images accepted in multi mode (PDFs can't be meaningfully stitched).
    $raw_names  = (array) $_FILES['files']['name'];
    $raw_tmps   = (array) $_FILES['files']['tmp_name'];
    $raw_errors = (array) $_FILES['files']['error'];

    // Filter out UPLOAD_ERR_NO_FILE slots
    $inputs = [];
    foreach ($raw_names as $i => $rname) {
        $rerr = (int) ($raw_errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($rerr === UPLOAD_ERR_NO_FILE) continue;
        $inputs[] = ['name' => $rname, 'tmp' => (string)$raw_tmps[$i], 'error' => $rerr];
    }

    if (count($inputs) === 0) {
        $fail(400, 'Aucun fichier valide reçu.');
    }

    // Degenerate multi-file with one file — downgrade to single
    if (count($inputs) === 1) {
        $is_multi  = false;
        $_FILES['file'] = [
            'name'     => $inputs[0]['name'],
            'tmp_name' => $inputs[0]['tmp'],
            'error'    => $inputs[0]['error'],
            'size'     => is_file($inputs[0]['tmp']) ? (int) filesize($inputs[0]['tmp']) : 0,
            'type'     => '',
        ];
    }
}

if ($is_multi) {
    // ── Multi-file stitch path (count($inputs) >= 2 guaranteed) ───────────
    // $inputs was built above in the pre-check block; rebuild here to avoid
    // out-of-scope variable reference after the conditional downgrade check.
    $raw_names  = (array) $_FILES['files']['name'];
    $raw_tmps   = (array) $_FILES['files']['tmp_name'];
    $raw_errors = (array) $_FILES['files']['error'];
    $inputs = [];
    foreach ($raw_names as $i => $rname) {
        $rerr = (int) ($raw_errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($rerr === UPLOAD_ERR_NO_FILE) continue;
        $inputs[] = ['name' => $rname, 'tmp' => (string)$raw_tmps[$i], 'error' => $rerr];
    }

    // Validate each
    $validated = [];
    foreach ($inputs as $inp) {
        $result = $validate_and_convert($inp['tmp'], $inp['error'], $inp['name']);
        if (!$result['ok']) {
            // Clean up any already-converted temps
            foreach ($validated as $v) {
                if ($v['cleanup']) @unlink($v['cleanup']);
            }
            $fail(400, 'Fichier invalide (' . htmlspecialchars($inp['name'], ENT_QUOTES, 'UTF-8') . '): ' . $result['error']);
        }
        // Reject PDFs in multi-file mode
        if ($result['ext'] === 'pdf') {
            foreach ($validated as $v) {
                if ($v['cleanup']) @unlink($v['cleanup']);
            }
            if ($result['cleanup']) @unlink($result['cleanup']);
            $fail(400, 'Les PDF ne peuvent pas être combinés en mode multi-pages. Envoyez les images séparément ou un PDF existant seul.');
        }
        $validated[] = $result;
    }

    // ── Stitch via img2pdf ─────────────────────────────────────────────────
    $img_paths = array_map(fn($v) => $v['tmp_path'], $validated);
    $combined  = tempnam(sys_get_temp_dir(), 'mtupload_multi_') . '.pdf';

    $esc_imgs  = implode(' ', array_map('escapeshellarg', $img_paths));
    $esc_out   = escapeshellarg($combined);
    $cmd       = "img2pdf {$esc_imgs} -o {$esc_out} 2>&1";
    exec($cmd, $stitch_out, $stitch_rc);

    // Clean up HEIC conversion temps
    foreach ($validated as $v) {
        if ($v['cleanup']) @unlink($v['cleanup']);
    }

    if ($stitch_rc !== 0 || !is_file($combined) || filesize($combined) === 0) {
        @unlink($combined);
        $stitch_msg = implode('; ', array_slice($stitch_out, 0, 3));
        $fail(500, 'Assemblage PDF (img2pdf) échoué. Diagnostic : ' . ($stitch_msg ?: 'aucun output'));
    }

    $cleanup_tmp = $combined;
    $tmp_path    = $combined;
    $mime        = 'application/pdf';
    $ext         = 'pdf';
    $byte_size   = (int) filesize($combined);
    $base_name   = pathinfo($validated[0]['orig_name'], PATHINFO_FILENAME);
    $orig_name   = $base_name . '-combined.pdf';

} else {
    // ── Single-file path ───────────────────────────────────────────────────
    if ((int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $fail(400, 'Aucun fichier reçu.');
    }

    $result = $validate_and_convert(
        (string) $_FILES['file']['tmp_name'],
        (int)    $_FILES['file']['error'],
        (string) $_FILES['file']['name']
    );
    if (!$result['ok']) {
        $fail(400, $result['error']);
    }

    $mime        = $result['mime'];
    $ext         = $result['ext'];
    $orig_name   = $result['orig_name'];
    $byte_size   = $result['byte_size'];
    $tmp_path    = $result['tmp_path'];
    $cleanup_tmp = $result['cleanup'];
}

// ── Step 7: Storage filename ──────────────────────────────────────────────────
$storage_filename = uv_make_storage_filename($orig_name, $ext);

// Determine upload source from optional form field (default: maltyweb-web)
$source_raw = trim($_POST['source'] ?? 'maltyweb-web');
$source     = in_array($source_raw, ['maltyweb-web', 'maltyweb-mobile'], true)
              ? $source_raw
              : 'maltyweb-web';

// Pack IP for storage
$packed_ip = null;
if ($ip !== null && $ip !== '') {
    $packed = @inet_pton($ip);
    if ($packed !== false) $packed_ip = $packed;
}

// Inbox destination path — write the file here before triggering the pipeline
$inbox_path = UPLOAD_INBOX_DIR . '/' . $storage_filename;

// ── Step 8: Write file to inbox dir ──────────────────────────────────────────
if (!is_dir(UPLOAD_INBOX_DIR)) {
    if (!@mkdir(UPLOAD_INBOX_DIR, 0755, true)) {
        if ($cleanup_tmp) @unlink($cleanup_tmp);
        $fail(500, 'Impossible de créer le répertoire inbox : ' . UPLOAD_INBOX_DIR);
    }
}

// $tmp_path may be:
//  (a) an uploaded file slot          → use move_uploaded_file
//  (b) a HEIC-converted or stitched temp → use copy+unlink (regular file)
$moved = false;
if ($cleanup_tmp !== null && $tmp_path === $cleanup_tmp) {
    // Case (b): regular temp file (HEIC conversion or img2pdf stitch)
    if (@copy($tmp_path, $inbox_path)) {
        @unlink($cleanup_tmp);
        $cleanup_tmp = null;
        $moved = true;
    }
} else {
    // Case (a): standard uploaded file
    if (move_uploaded_file($tmp_path, $inbox_path)) {
        $moved = true;
    } elseif ($cleanup_tmp !== null) {
        // Fallback: file was converted and tmp_path points elsewhere
        if (@copy($tmp_path, $inbox_path)) {
            @unlink($cleanup_tmp);
            $cleanup_tmp = null;
            $moved = true;
        }
    }
}

if (!$moved) {
    if ($cleanup_tmp) @unlink($cleanup_tmp);
    $fail(500, 'Impossible d\'écrire le fichier dans le répertoire inbox.');
}
// All temps handled; inbox_path is the live file.
$cleanup_tmp = null; // prevent double-unlink in any later error path

// Extract UUID portion from storage_filename for use as canonical file identifier.
// Format: YYYY-MM-DD_{uuid}.ext  — the UUID is the canonical file ID used in doc_files.
// NOTE: drive_file_id column is repurposed — it holds the UUID (not a Google Drive ID)
//       for uploads via this Option-B local-path flow. The column name is kept to avoid
//       a schema migration for this transitional phase; callers of upload-status.php use
//       the upload_id, not drive_file_id, for polling.
$file_id = preg_replace('/^\d{4}-\d{2}-\d{2}_/', '', pathinfo($storage_filename, PATHINFO_FILENAME)) ?? '';

// ── Step 9: INSERT doc_uploads ────────────────────────────────────────────────
$upload_id = null;
try {
    $ins = $pdo->prepare(
        "INSERT INTO doc_uploads
            (user_id, source, original_filename, storage_filename,
             mime_type, byte_size, pipeline_status, drive_file_id, client_ip, client_ua)
         VALUES (?, ?, ?, ?, ?, ?, 'uploaded', ?, ?, ?)"
    );
    $ins->execute([
        $userId, $source, $orig_name, $storage_filename,
        $mime, $byte_size, $file_id !== '' ? $file_id : null,
        $packed_ip, $ua !== '' ? $ua : null,
    ]);
    $upload_id = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($inbox_path);
    $fail(500, 'Erreur base de données lors de l\'enregistrement : ' . $e->getMessage());
}

// Helper: mark row as failed, remove inbox file, and respond with error
$mark_failed = static function (string $msg) use ($pdo, $upload_id, $inbox_path, $fail): never {
    try {
        $upd = $pdo->prepare(
            "UPDATE doc_uploads
                SET pipeline_status = 'failed',
                    error_text      = ?
              WHERE id = ?"
        );
        $upd->execute([$msg, $upload_id]);
    } catch (Throwable) { /* non-fatal */ }
    @unlink($inbox_path);
    $fail(500, $msg);
};

// ── Step 10: Fire background ingest (local path) ──────────────────────────────
// Defense-in-depth: re-validate inbox_path before injecting into shell command.
// The path is under our control (constant prefix + storage_filename from our own
// uv_make_storage_filename), but we double-check the pattern for belt+suspenders.
if (!preg_match(
    '#^/var/www/maltytask/storage/documents/inbox/\d{4}-\d{2}-\d{2}_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z]{2,5}$#',
    $inbox_path
)) {
    $mark_failed('Chemin inbox invalide — ingest annulé par sécurité.');
}

$cmd = sprintf(UPLOAD_INGEST_CMD, escapeshellarg($inbox_path));
exec($cmd);
// exec() returns immediately; background process runs independently

// ── Step 11: UPDATE pipeline_status → triggered ───────────────────────────────
try {
    $upd = $pdo->prepare(
        "UPDATE doc_uploads
            SET pipeline_status     = 'triggered',
                pipeline_started_at = NOW()
          WHERE id = ?"
    );
    $upd->execute([$upload_id]);
} catch (Throwable $e) {
    // Non-fatal — ingest is already running; log and continue
    error_log("upload-document.php: failed to update doc_uploads id={$upload_id}: " . $e->getMessage());
}

// ── Step 12: Respond ─────────────────────────────────────────────────────────
// $cleanup_tmp was set to null after move/copy in step 8; nothing to unlink here.

$status_url = '/api/upload-status.php?upload_id=' . $upload_id;

if ($wants_json) {
    header('Content-Type: application/json; charset=utf-8');
    // Bulk single-downgrade: wrap in uploads[] array for stable response shape
    if (!empty($bulk_single_downgrade)) {
        echo json_encode([
            'ok'      => true,
            'mode'    => 'bulk',
            'uploads' => [[
                'file_name'  => $orig_name,
                'upload_id'  => $upload_id,
                'file_id'    => $file_id,
                'status'     => 'queued',
                'status_url' => $status_url,
            ]],
        ]);
    } else {
        echo json_encode([
            'ok'         => true,
            'upload_id'  => $upload_id,
            'file_id'    => $file_id,    // UUID — canonical identifier for doc_files lookup
            'status_url' => $status_url,
        ]);
    }
} else {
    // Plain form post — redirect to status page
    header('Location: ' . $status_url, true, 303);
}
exit;
