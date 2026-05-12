<?php
declare(strict_types=1);

/**
 * document.php — streams a document PDF to the browser.
 *
 * Auth-gated: must be logged in.
 * GET ?file_id=<drive-file-id>
 *
 * Resolution order:
 *   1. Local VPS storage (processed / inbox / ambiguous)
 *   2. Google Drive proxy (service account, Bearer JWT)
 *   3. 404
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/services/document_preview.php';

require_login();

// ── Validate file_id ──────────────────────────────────────────────────────────

$file_id = trim($_GET['file_id'] ?? '');

if ($file_id === '' || !preg_match(DP_FILE_ID_REGEX, $file_id)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Paramètre file_id invalide.';
    exit;
}

// ── Verify the file_id exists in doc_files (prevent enumeration) ──────────────

try {
    $pdo  = maltytask_pdo();
    $stmt = $pdo->prepare('SELECT file_name FROM doc_files WHERE file_id = ? LIMIT 1');
    $stmt->execute([$file_id]);
    $row  = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erreur base de données.';
    exit;
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document introuvable.';
    exit;
}

$file_name = (string) ($row['file_name'] ?? ($file_id . '.pdf'));
// Sanitize filename for Content-Disposition
$safe_name = preg_replace('/[^A-Za-z0-9._\-]/', '_', basename($file_name));
if (!str_ends_with(strtolower($safe_name), '.pdf')) {
    $safe_name .= '.pdf';
}

// ── Try local storage first ───────────────────────────────────────────────────

$local = dp_local_pdf_path($file_id);

if ($local !== null) {
    $size = filesize($local);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safe_name . '"');
    header('Cache-Control: private, max-age=86400');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($local);
    exit;
}

// ── Proxy from Google Drive ───────────────────────────────────────────────────

$access_token = dp_google_access_token();

if ($access_token === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Impossible d\'obtenir un token Google Drive. Service temporairement indisponible.';
    exit;
}

// Cache locally on first hit for performance
$processed_dir = DP_DOCUMENTS_BASE . '/processed';
$local_cache   = $processed_dir . '/' . $file_id . '.pdf';
$cached        = false;

if (is_dir($processed_dir) && is_writable($processed_dir)) {
    $ok = dp_drive_download_to_file($file_id, $access_token, $local_cache);
    if ($ok) {
        $cached = true;
    }
}

if ($cached) {
    $size = filesize($local_cache);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safe_name . '"');
    header('Cache-Control: private, max-age=86400');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($local_cache);
    exit;
}

// Streaming fallback: download directly to output buffer
$url = 'https://www.googleapis.com/drive/v3/files/'
    . rawurlencode($file_id) . '?alt=media';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
]);
$pdf_bytes = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($pdf_bytes === false || $http_code !== 200) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Impossible de récupérer le fichier depuis Google Drive (HTTP ' . $http_code . ').';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safe_name . '"');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen((string) $pdf_bytes));
echo $pdf_bytes;
