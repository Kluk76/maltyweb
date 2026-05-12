<?php
declare(strict_types=1);

/**
 * document_preview.php — PDF/preview service functions
 *
 * Pure helpers; no output, no HTTP, no auth. Callers handle that.
 *
 * Paths:
 *   Local PDF cache : /var/www/maltytask/storage/documents/{folder}/{file_id}.pdf
 *   Preview PNG cache: /var/www/maltytask/storage/preview-cache/{file_id}-p1.png
 *   Google Drive token cache: /tmp/maltyweb-google-token.json
 */

// ── Constants ─────────────────────────────────────────────────────────────────

const DP_STORAGE_BASE   = '/var/www/maltytask/storage';
const DP_DOCUMENTS_BASE = DP_STORAGE_BASE . '/documents';
const DP_PREVIEW_CACHE  = DP_STORAGE_BASE . '/preview-cache';
const DP_SERVICE_ACCOUNT = '/var/www/maltytask/config/service-account.json';
const DP_TOKEN_CACHE    = '/tmp/maltyweb-google-token.json';
const DP_TOKEN_TTL      = 3300; // 55 min — just under Google's 1 h
const DP_FILE_ID_REGEX  = '/^[A-Za-z0-9_\-]{10,200}$/';


// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Returns absolute filesystem path to a local PDF if it exists, else null.
 * Scans all sub-folders under the documents storage base (inbox, processed, ambiguous).
 */
function dp_local_pdf_path(string $file_id): ?string
{
    if (!preg_match(DP_FILE_ID_REGEX, $file_id)) return null;

    $folders = ['processed', 'inbox', 'ambiguous'];
    foreach ($folders as $folder) {
        $path = DP_DOCUMENTS_BASE . '/' . $folder . '/' . $file_id . '.pdf';
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Returns the internal proxy URL for a fileId.
 * Used to build <iframe src=...> attributes — does NOT fetch the file.
 */
function dp_drive_proxy_url(string $file_id): string
{
    return '/api/document.php?file_id=' . rawurlencode($file_id);
}

/**
 * Generates page-1 PNG from a local or Drive PDF.
 * Caches at DP_PREVIEW_CACHE/{file_id}-p1.png; idempotent.
 * Returns public URL (/api/document-preview-png.php?file_id=X).
 *
 * Returns null if the PDF cannot be located or pdftoppm fails.
 */
function dp_render_page1_png(string $file_id): ?string
{
    if (!preg_match(DP_FILE_ID_REGEX, $file_id)) return null;

    $png_path = DP_PREVIEW_CACHE . '/' . $file_id . '-p1.png';
    if (is_file($png_path)) {
        return '/api/document-preview-png.php?file_id=' . rawurlencode($file_id);
    }

    // Ensure cache dir exists
    if (!is_dir(DP_PREVIEW_CACHE)) {
        @mkdir(DP_PREVIEW_CACHE, 0755, true);
    }

    // Locate the PDF — local first, then Drive download
    $pdf_path = dp_local_pdf_path($file_id);

    if ($pdf_path === null) {
        // Download from Drive to a temp file
        $access_token = dp_google_access_token();
        if ($access_token === null) return null;

        $tmp_pdf = tempnam(sys_get_temp_dir(), 'dppdf_') . '.pdf';
        $ok = dp_drive_download_to_file($file_id, $access_token, $tmp_pdf);
        if (!$ok) {
            @unlink($tmp_pdf);
            return null;
        }
        $pdf_path = $tmp_pdf;
        $cleanup  = true;
    } else {
        $cleanup = false;
    }

    // pdftoppm -r 120 -png -l 1 <pdf> <output_prefix>
    // Produces: <output_prefix>-1.png  (page 1)
    $out_prefix  = DP_PREVIEW_CACHE . '/' . $file_id . '-p1-raw';
    $escaped_pdf = escapeshellarg($pdf_path);
    $escaped_out = escapeshellarg($out_prefix);

    $cmd = "pdftoppm -r 120 -png -l 1 $escaped_pdf $escaped_out 2>&1";
    exec($cmd, $out_lines, $rc);

    if ($cleanup) @unlink($pdf_path);

    if ($rc !== 0) return null;

    // pdftoppm names the file {prefix}-01.png or {prefix}-1.png depending on count
    $candidates = [
        $out_prefix . '-01.png',
        $out_prefix . '-1.png',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            rename($candidate, $png_path);
            return '/api/document-preview-png.php?file_id=' . rawurlencode($file_id);
        }
    }

    return null;
}


// ── Google Drive helpers ──────────────────────────────────────────────────────

/**
 * Returns a valid Google OAuth2 access token for the service account.
 * Caches the token in DP_TOKEN_CACHE (file, TTL just under 1 h).
 * Returns null if the service account JSON is unreadable or JWT signing fails.
 */
function dp_google_access_token(): ?string
{
    // Check file cache
    if (is_file(DP_TOKEN_CACHE)) {
        $data = @json_decode((string) file_get_contents(DP_TOKEN_CACHE), true);
        if (is_array($data)
            && isset($data['access_token'], $data['expires_at'])
            && time() < (int) $data['expires_at']
        ) {
            return (string) $data['access_token'];
        }
    }

    // Load service account JSON
    $sa_raw = @file_get_contents(DP_SERVICE_ACCOUNT);
    if ($sa_raw === false) return null;

    $sa = json_decode($sa_raw, true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
        return null;
    }

    $now = time();
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $jwt = dp_build_jwt($sa['private_key'], $claims);
    if ($jwt === null) return null;

    // Exchange JWT for access token
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) return null;

    $token_data = json_decode((string) $resp, true);
    if (!is_array($token_data) || empty($token_data['access_token'])) return null;

    $access_token = (string) $token_data['access_token'];

    // Cache it
    @file_put_contents(DP_TOKEN_CACHE, json_encode([
        'access_token' => $access_token,
        'expires_at'   => $now + DP_TOKEN_TTL,
    ]));

    return $access_token;
}

/**
 * Downloads a Drive file to a local path using a Bearer access token.
 * Returns true on success (HTTP 200), false otherwise.
 */
function dp_drive_download_to_file(
    string $file_id,
    string $access_token,
    string $dest_path
): bool {
    if (!preg_match(DP_FILE_ID_REGEX, $file_id)) return false;

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . rawurlencode($file_id) . '?alt=media';

    $fh = fopen($dest_path, 'wb');
    if ($fh === false) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($http_code !== 200) {
        @unlink($dest_path);
        return false;
    }
    return true;
}

/**
 * Builds a signed JWT for Google service-account auth.
 * Uses RS256 (RSA-SHA256) as required by Google.
 */
function dp_build_jwt(string $private_key_pem, array $claims): ?string
{
    $header  = dp_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = dp_base64url(json_encode($claims));
    $signing_input = $header . '.' . $payload;

    $signature = '';
    $ok = openssl_sign($signing_input, $signature, $private_key_pem, OPENSSL_ALGO_SHA256);
    if (!$ok) return null;

    return $signing_input . '.' . dp_base64url($signature);
}

/** URL-safe Base64 encoding without trailing padding. */
function dp_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
