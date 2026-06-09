<?php
declare(strict_types=1);
/**
 * modules/journal-saisies.php — Journal des saisies
 *
 * Global live feed of every operator input across all 7 production-event tables
 * (bd_racking_v2, bd_fermenting_v2, bd_packaging_v2, bd_brewing_brewday_v2,
 *  bd_brewing_gravity_v2, bd_brewing_ingredients_v2, bd_brewing_timings_v2).
 *
 * Live auto-refresh via journal-feed.php?since=<cursor> (20s poll, paused
 * when tab hidden). Drill-down per row via journal-detail.php.
 *
 * Auth:   require_page_access('journal-saisies') — all roles.
 * Body:   body.home.journal-saisies
 * CSS:    /css/journal-saisies.css
 * JS:     /js/journal-saisies.js
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';

require_page_access('journal-saisies');
$me  = current_user();
$pdo = maltytask_pdo();

// ─── Initial feed (newest 60 events, NOT tombstoned) ─────────────────────────

$feedRows = [];
$maxCursor = null;

try {
    $stmt = $pdo->query(
        "SELECT
            v.source_table,
            v.row_pk,
            v.form_type,
            DATE_FORMAT(v.event_date, '%Y-%m-%d') AS event_date,
            DATE_FORMAT(v.submitted_at, '%Y-%m-%d %H:%i:%s') AS submitted_at,
            v.operator_email,
            COALESCE(NULLIF(u.display_name,''), v.operator_email, 'Opérateur') AS operator_display,
            v.label
         FROM v_saisie_events v
         LEFT JOIN users u ON u.id = v.submitted_by_user_id_fk
         WHERE v.submitted_at IS NOT NULL
         ORDER BY v.submitted_at DESC
         LIMIT 60"
    );
    $feedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($feedRows)) {
        $maxCursor = $feedRows[0]['submitted_at'];
    }
} catch (Throwable $e) {
    error_log('journal-saisies: feed query failed — ' . $e->getMessage());
}

$pageTitle = 'Journal des saisies';
$active_module = 'journal-saisies';

// JSON-encode with XSS-safe flags for window.* injection
$jsData   = json_encode($feedRows,   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsCursor = json_encode($maxCursor,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/journal-saisies.css?v=<?= @filemtime(__DIR__ . '/../css/journal-saisies.css') ?: time() ?>">
</head>
<body class="home journal-saisies">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="js-header">
    <div class="js-eyebrow">MaltyTask · Production</div>
    <h1 class="js-title">Journal des <em>saisies</em></h1>
    <p class="js-sub">
      Toutes les saisies opérateur en temps réel — brassage, fermentation,
      transferts, conditionnement. Cliquez sur une ligne pour l'historique complet.
    </p>
  </div>

  <!-- ── Filter chips ────────────────────────────────────────────────────── -->
  <div class="js-filters" role="group" aria-label="Filtrer par type de saisie">
    <button class="js-chip js-chip--active" data-filter="all"   type="button">Tout</button>
    <button class="js-chip" data-filter="Brassage"              type="button">Brassage</button>
    <button class="js-chip" data-filter="Fermentation"          type="button">Fermentation</button>
    <button class="js-chip" data-filter="Transfert"             type="button">Transfert</button>
    <button class="js-chip" data-filter="Conditionnement"       type="button">Conditionnement</button>
  </div>

  <!-- ── Live-status bar ─────────────────────────────────────────────────── -->
  <div class="js-live-bar" aria-live="polite" aria-atomic="false">
    <span class="js-live-dot" aria-hidden="true"></span>
    <span id="js-live-status">En direct</span>
  </div>

  <!-- ── Feed list ───────────────────────────────────────────────────────── -->
  <div id="js-feed" class="js-feed" role="list" aria-label="Journal des saisies"></div>

  <!-- ── Load more ───────────────────────────────────────────────────────── -->
  <div class="js-load-more-wrap">
    <button id="js-load-more" class="js-load-more" type="button">
      Charger plus
    </button>
  </div>

  <!-- ── Empty state (shown by JS when list is empty) ─────────────────────── -->
  <div id="js-empty" class="js-empty" hidden>
    <p>Aucune saisie pour ce filtre.</p>
  </div>

</main>

<!-- ── Drill-down dialog ─────────────────────────────────────────────────── -->
<dialog id="js-detail-dialog" class="js-detail-dialog">
  <div class="jsd-inner">
    <div class="jsd-topbar">
      <span id="jsd-title" class="jsd-title"></span>
      <button class="jsd-close" id="jsd-close" type="button" aria-label="Fermer">✕</button>
    </div>
    <div id="jsd-body" class="jsd-body">
      <div class="jsd-loading">Chargement…</div>
    </div>
  </div>
</dialog>

<script>
window.JOURNAL_DATA   = <?= $jsData ?>;
window.JOURNAL_CURSOR = <?= $jsCursor ?>;
</script>
<script src="/js/journal-saisies.js?v=<?= @filemtime(__DIR__ . '/../js/journal-saisies.js') ?: time() ?>"></script>
</body>
</html>
