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
const DP_TOKEN_WRITE_CACHE = '/tmp/maltyweb-google-token-write.json';
const DP_TOKEN_TTL      = 3300; // 55 min — just under Google's 1 h
const DP_FILE_ID_REGEX  = '/^[A-Za-z0-9_\-]{10,200}$/';

// Page-1 preview render DPI. Higher = crisper when the operator zooms the
// in-app preview. Baked into the cache filename (dp_preview_png_path) so that
// changing it auto-invalidates the cache instead of serving stale low-DPI PNGs.
const DP_PREVIEW_DPI         = 300;                 // legacy alias — kept for external callers
const DP_PREVIEW_DPI_DEFAULT = 300;                 // canonical default
const DP_PREVIEW_DPIS_ALLOWED = [300, 600];         // whitelist — prevents DoS via huge -r values

/** Absolute path of the cached page-1 PNG for a file_id, keyed by render DPI. */
function dp_preview_png_path(string $file_id, int $dpi = DP_PREVIEW_DPI_DEFAULT): string
{
    return DP_PREVIEW_CACHE . '/' . $file_id . '-p1-r' . $dpi . '.png';
}

/**
 * Drive inbox folder ID — canonical source: maltytask/lib/config.js DRIVE_INBOX_FOLDER.
 * TODO: future cleanup — pull from a shared env file / YAML so Node + PHP stay in sync
 *       without manual duplication. For now this is the only PHP caller.
 */
const DOC_DRIVE_INBOX_FOLDER = '1vtWVPJRmY7s4shMY79Rp2FZLiToRBFwY';


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
 * Caches at DP_PREVIEW_CACHE/{file_id}-p1-r{dpi}.png; idempotent.
 * Returns public URL (/api/document-preview-png.php?file_id=X&dpi=N).
 *
 * $dpi is clamped to DP_PREVIEW_DPIS_ALLOWED — callers should pre-validate,
 * but this function is the last line of defense against arbitrary DPI renders.
 *
 * Returns null if the PDF cannot be located or pdftoppm fails.
 */
function dp_render_page1_png(string $file_id, int $dpi = DP_PREVIEW_DPI_DEFAULT): ?string
{
    if (!preg_match(DP_FILE_ID_REGEX, $file_id)) return null;

    // Clamp DPI to whitelist — prevents DoS via huge -r values
    if (!in_array($dpi, DP_PREVIEW_DPIS_ALLOWED, true)) {
        $dpi = DP_PREVIEW_DPI_DEFAULT;
    }

    $png_path = dp_preview_png_path($file_id, $dpi);
    if (is_file($png_path)) {
        return '/api/document-preview-png.php?file_id=' . rawurlencode($file_id) . '&dpi=' . $dpi;
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

    // pdftoppm -r <DPI> -png -l 1 <pdf> <output_prefix>
    // Produces: <output_prefix>-1.png  (page 1)
    // out_prefix is dpi-keyed to avoid cross-dpi temp-file collisions
    $out_prefix  = DP_PREVIEW_CACHE . '/' . $file_id . '-p1-r' . $dpi . '-raw';
    $escaped_pdf = escapeshellarg($pdf_path);
    $escaped_out = escapeshellarg($out_prefix);

    // Resolve pdftoppm by absolute path — the php-fpm pool runs with an empty
    // PATH (env[PATH] is not set), so a bare command name would not be found
    // when rendering on-demand from the web context.
    $bin = '';
    foreach (['/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/bin/pdftoppm'] as $cand) {
        if (is_executable($cand)) { $bin = $cand; break; }
    }
    if ($bin === '') { $bin = 'pdftoppm'; }

    $cmd = "$bin -r $dpi -png -l 1 $escaped_pdf $escaped_out 2>&1";
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
            return '/api/document-preview-png.php?file_id=' . rawurlencode($file_id) . '&dpi=' . $dpi;
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
 * Returns a Google OAuth2 access token with drive.file write scope.
 * Separate cache file from the read-only token so both can coexist.
 * Returns null on failure (unreadable SA key, JWT sign error, HTTP error).
 */
function dp_google_access_token_write(): ?string
{
    // Check file cache
    if (is_file(DP_TOKEN_WRITE_CACHE)) {
        $data = @json_decode((string) file_get_contents(DP_TOKEN_WRITE_CACHE), true);
        if (is_array($data)
            && isset($data['access_token'], $data['expires_at'])
            && time() < (int) $data['expires_at']
        ) {
            return (string) $data['access_token'];
        }
    }

    $sa_raw = @file_get_contents(DP_SERVICE_ACCOUNT);
    if ($sa_raw === false) return null;

    $sa = json_decode($sa_raw, true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
        return null;
    }

    $now = time();
    $claims = [
        'iss'   => $sa['client_email'],
        // Full Drive scope — same as used by the Node.js ingest pipeline (lib/sheets.js).
        // drive.file is insufficient: service accounts have no quota for new files
        // unless the folder is a shared drive. The inbox is a personal Drive folder
        // shared with the SA as editor, which requires the broader drive scope.
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $jwt = dp_build_jwt($sa['private_key'], $claims);
    if ($jwt === null) return null;

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
    $resp      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) return null;

    $token_data = json_decode((string) $resp, true);
    if (!is_array($token_data) || empty($token_data['access_token'])) return null;

    $access_token = (string) $token_data['access_token'];

    @file_put_contents(DP_TOKEN_WRITE_CACHE, json_encode([
        'access_token' => $access_token,
        'expires_at'   => $now + DP_TOKEN_TTL,
    ]));

    return $access_token;
}

/**
 * Uploads a local file to a Drive folder using the multipart upload API.
 *
 * NOTE: no longer used by upload-document.php — see Option B local-path flow
 *       (scripts/ingest-one-local.ts + ingest-one-local.sh). Kept here for
 *       potential future callers (batch re-ingest, admin tools, etc.).
 *
 * Drive API v3 multipart upload:
 *   POST https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart
 *   Content-Type: multipart/related; boundary=<boundary>
 *   Part 1: JSON metadata  (application/json; charset=UTF-8)
 *   Part 2: File binary    ($mime_type)
 *
 * @param string $local_path      Absolute path to the file to upload
 * @param string $drive_filename  Name to assign the file on Drive
 * @param string $mime_type       MIME type of the file content
 * @param string $drive_folder_id Drive folder ID to place the file in
 * @return string                 The new Drive fileId
 * @throws RuntimeException       On auth failure, HTTP error, or malformed response
 */
function dp_drive_upload(
    string $local_path,
    string $drive_filename,
    string $mime_type,
    string $drive_folder_id
): string {
    $access_token = dp_google_access_token_write();
    if ($access_token === null) {
        throw new RuntimeException('dp_drive_upload: could not obtain write-scope access token');
    }

    $file_data = @file_get_contents($local_path);
    if ($file_data === false) {
        throw new RuntimeException("dp_drive_upload: cannot read local file: {$local_path}");
    }

    $boundary = '-------maltyweb_upload_' . bin2hex(random_bytes(8));

    // Part 1: JSON metadata
    $metadata = json_encode([
        'name'    => $drive_filename,
        'parents' => [$drive_folder_id],
    ]);

    // Assemble multipart/related body manually — PHP has no built-in helper
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= $metadata . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime_type}\r\n\r\n";
    $body .= $file_data . "\r\n";
    $body .= "--{$boundary}--";

    $ch = curl_init(
        'https://www.googleapis.com/upload/drive/v3/files'
        . '?uploadType=multipart&fields=id&supportsAllDrives=true'
    );
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
    ]);

    $resp      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curl_err !== '') {
        throw new RuntimeException("dp_drive_upload: curl error: {$curl_err}");
    }
    if ($http_code !== 200) {
        throw new RuntimeException(
            "dp_drive_upload: Drive API returned HTTP {$http_code}: " . substr((string)$resp, 0, 300)
        );
    }

    $result = json_decode((string) $resp, true);
    if (!is_array($result) || empty($result['id'])) {
        throw new RuntimeException(
            'dp_drive_upload: unexpected Drive API response: ' . substr((string)$resp, 0, 300)
        );
    }

    return (string) $result['id'];
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
