<?php
declare(strict_types=1);

/**
 * GET /api/upload-test.php — admin smoke-test form for the upload endpoint.
 *
 * LIFECYCLE NOTE: This file is deleted at the end of Step 8 (upload UI).
 * The real drag-drop / mobile-camera UI in /modules/triage.php replaces it.
 * Do not add permanent logic here.
 *
 * Usage: visit /api/upload-test.php as an admin user. Pick a PDF/image and
 * submit. The form POSTs to /api/upload-document.php. On success the browser
 * follows the 303 redirect to /api/upload-status.php?upload_id=N which shows
 * the live pipeline_status JSON.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

require_login();
$me = current_user();

if (!is_admin($me)) {
    http_response_code(404);
    exit;
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Upload test — MaltyTask</title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    body { padding: 2rem; font-family: sans-serif; max-width: 640px; }
    h1   { margin-bottom: 1.5rem; }
    .field { margin-bottom: 1rem; }
    label { display: block; margin-bottom: .25rem; font-weight: 600; }
    input[type=file] { display: block; margin-top: .25rem; }
    .note { margin-top: 1.5rem; padding: .75rem 1rem;
            background: #fff3cd; border-left: 4px solid #e6ac00;
            font-size: .875rem; }
    .btn { margin-top: 1rem; padding: .5rem 1.25rem;
           background: #1a73e8; color: #fff; border: none;
           border-radius: 4px; cursor: pointer; font-size: 1rem; }
    .btn:hover { background: #1558b0; }
  </style>
</head>
<body>
<h1>Test upload (admin only)</h1>

<p>POSTs to <code>/api/upload-document.php</code>. On success, browser redirects
to the status endpoint. Check <code>doc_uploads</code> and Drive inbox to verify.</p>

<form method="post" action="/api/upload-document.php" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="source" value="maltyweb-web">

  <div class="field">
    <label for="file">Fichier (PDF / JPEG / PNG / HEIC / WebP — max 20 Mo)</label>
    <input type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.webp" required>
  </div>

  <button type="submit" class="btn">Téléverser</button>
</form>

<div class="note">
  <strong>Smoke-test checklist</strong>
  <ul>
    <li>Upload valid PDF ≤ 5 MB → 303 → status JSON with pipeline_status=triggered</li>
    <li>Upload .exe renamed .pdf → 400 (MIME mismatch)</li>
    <li>Upload file &gt; 20 MB → 400 (size exceeded)</li>
    <li>Submit without CSRF (edit DOM) → 400</li>
    <li>Check <code>doc_uploads</code> row on VPS DB</li>
    <li>Check Drive inbox folder for uploaded file</li>
    <li>Tail <code>/var/log/maltytask/upload-ingest.log</code> on VPS</li>
    <li>Wait 30–60 s, poll status URL → pipeline_status=processed</li>
  </ul>
  <em>This page is deleted at end of Step 8.</em>
</div>
</body>
</html>
