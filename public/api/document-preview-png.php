<?php
declare(strict_types=1);

/**
 * document-preview-png.php — streams page-1 PNG preview of a document.
 *
 * Auth-gated: must be logged in.
 * GET ?file_id=<drive-file-id>[&dpi=300|600]
 *
 * The ?dpi= param selects render resolution (default 300, on-demand 600 for
 * hi-res zoom). Only values in DP_PREVIEW_DPIS_ALLOWED are accepted; anything
 * else is silently clamped to DP_PREVIEW_DPI_DEFAULT — never arbitrary DPI.
 *
 * Delegates to dp_render_page1_png() which:
 *   - Locates local PDF or proxies from Drive
 *   - Runs pdftoppm to render page 1 at the requested DPI
 *   - Caches to /var/www/maltytask/storage/preview-cache/{file_id}-p1-r{dpi}.png
 *   - Is idempotent — returns the cached PNG on repeat calls
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

// ── Verify the file_id exists in doc_files ────────────────────────────────────

try {
    $pdo  = maltytask_pdo();
    $stmt = $pdo->prepare('SELECT id FROM doc_files WHERE file_id = ? LIMIT 1');
    $stmt->execute([$file_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Document introuvable.';
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erreur base de données.';
    exit;
}

// ── Validate dpi param (two-step: read with default first, then validate) ──────

$dpi = (int) ($_GET['dpi'] ?? DP_PREVIEW_DPI_DEFAULT);
if (!in_array($dpi, DP_PREVIEW_DPIS_ALLOWED, true)) {
    $dpi = DP_PREVIEW_DPI_DEFAULT;
}

// ── Serve from cache if available ─────────────────────────────────────────────

$png_path = dp_preview_png_path($file_id, $dpi);

if (is_file($png_path)) {
    $size = filesize($png_path);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($png_path);
    exit;
}

// ── Generate and cache ────────────────────────────────────────────────────────

$public_url = dp_render_page1_png($file_id, $dpi);

if ($public_url === null) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Impossible de générer l\'aperçu. Vérifiez que pdftoppm est installé et que le PDF est accessible.';
    exit;
}

// After render the file should be cached — serve it
if (is_file($png_path)) {
    $size = filesize($png_path);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($png_path);
    exit;
}

// Fallback — redirect to the generated URL (should not reach here normally)
header('Location: ' . $public_url, true, 302);
