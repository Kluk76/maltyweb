<?php
declare(strict_types=1);
/**
 * modules/invoice-validate.php — « À valider » invoice validation gate (B1b).
 *
 * Thin wrapper around partials/valider-queue.php.
 * The queue list query, per-line rendering, window.INVOICES injection, and
 * card HTML live ONLY in the partial — this page may not duplicate them.
 *
 * Also accessible as triage.php?tab=valider (shared partial).
 *
 * Auth: require_page_access('invoice-validate') — operator+.
 * GET-only rendering; all write actions are via JSON API endpoints.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';

require_page_access('invoice-validate');
$me = current_user();

header('Content-Type: text/html; charset=utf-8');
$active_module = 'invoice-validate';

$pdo        = maltytask_pdo();
$csrfToken  = csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>À valider — MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/invoice-validate.css?v=<?= @filemtime(__DIR__ . '/../../public/css/invoice-validate.css') ?: time() ?>">
</head>
<body class="home invoice-validate">

<?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

<main id="main-content" class="main iv-main" role="main">

  <?php require __DIR__ . '/partials/valider-queue.php'; ?>

</main>

<script src="/js/invoice-validate.js?v=<?= @filemtime(__DIR__ . '/../../public/js/invoice-validate.js') ?: time() ?>"></script>
<?php if (function_exists('sw_script')): sw_script(); else: ?>
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/' })
      .catch(function(e) { /* non-fatal */ });
  }
</script>
<?php endif ?>
</body>
</html>
