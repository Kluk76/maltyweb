<?php
declare(strict_types=1);
/**
 * /modules/comm-unknown-domains.php
 * Manager-gated triage screen for comm_unknown_domain_seen.
 *
 * Managers and admins can promote an unknown domain to the ref_entity_email_domains
 * registry (supplier or customer), or dismiss it. URL-only page (not in ref_pages nav),
 * gated directly via can_use_comm_tracker().
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

require_login();
$me = current_user();
if (!can_use_comm_tracker($me)) {
    header('Location: /');
    exit;
}

$active_module = 'comm-unknown-domains';

$pdo  = maltytask_pdo();
$rows = [];

$stmt = $pdo->query(
    'SELECT id, domain, hit_count, sample_address,
            DATE_FORMAT(first_seen_at, \'%Y-%m-%d\') AS first_seen,
            DATE_FORMAT(last_seen_at,  \'%Y-%m-%d\') AS last_seen
       FROM comm_unknown_domain_seen
      WHERE is_dismissed = 0
      ORDER BY hit_count DESC'
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[] = [
        'id'             => (int) $row['id'],
        'domain'         => (string) $row['domain'],
        'hit_count'      => (int)    $row['hit_count'],
        'sample_address' => $row['sample_address'] !== null ? (string) $row['sample_address'] : null,
        'first_seen'     => (string) $row['first_seen'],
        'last_seen'      => (string) $row['last_seen'],
    ];
}

$count = count($rows);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Domaines inconnus — Tracker comm · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/comm-unknown.css?v=<?= @filemtime(__DIR__ . '/../../public/css/comm-unknown.css') ?: time() ?>">
</head>
<body class="home comm-unknown">
<?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

<div class="cud-page">

  <div class="cud-header">
    <h1>Domaines inconnus</h1>
    <span class="cud-badge" id="cud-count"><?= $count ?> domaine<?= $count !== 1 ? 's' : '' ?> en attente</span>
    <span class="cud-subtitle">Domaines e-mail non résolus dans le tracker de communication</span>
  </div>

  <div class="cud-refresh-note" id="cud-refresh-note">Actualisation automatique toutes les 30 s</div>

<?php if ($count === 0): ?>
  <div class="cud-empty">Aucun domaine inconnu en attente. Tout est propre.</div>
<?php else: ?>
  <table class="cud-table" aria-label="Domaines inconnus en attente">
    <thead>
      <tr>
        <th>Domaine</th>
        <th>Hits</th>
        <th>Adresse exemple</th>
        <th>Première fois</th>
        <th>Dernière fois</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="cud-table-body">
<?php foreach ($rows as $r): ?>
      <tr data-id="<?= (int)$r['id'] ?>">
        <td><span class="cud-domain"><?= htmlspecialchars($r['domain'], ENT_QUOTES, 'UTF-8') ?></span></td>
        <td class="cud-hits-cell"><span class="cud-hits<?= $r['hit_count'] > 5 ? ' cud-hits--hot' : '' ?>"><?= (int)$r['hit_count'] ?></span></td>
        <td><span class="cud-addr"><?= $r['sample_address'] !== null ? htmlspecialchars($r['sample_address'], ENT_QUOTES, 'UTF-8') : '—' ?></span></td>
        <td><span class="cud-date"><?= htmlspecialchars($r['first_seen'], ENT_QUOTES, 'UTF-8') ?></span></td>
        <td><span class="cud-date"><?= htmlspecialchars($r['last_seen'], ENT_QUOTES, 'UTF-8') ?></span></td>
        <td>
          <div class="cud-actions">
            <button class="cud-btn cud-btn--supplier" type="button"
                    data-action="open-supplier" data-id="<?= (int)$r['id'] ?>"
                    data-domain="<?= htmlspecialchars($r['domain'], ENT_QUOTES, 'UTF-8') ?>">→ Fournisseur</button>
            <button class="cud-btn cud-btn--customer" type="button"
                    data-action="open-customer" data-id="<?= (int)$r['id'] ?>"
                    data-domain="<?= htmlspecialchars($r['domain'], ENT_QUOTES, 'UTF-8') ?>">→ Client</button>
            <button class="cud-btn cud-btn--dismiss" type="button"
                    data-action="dismiss" data-id="<?= (int)$r['id'] ?>"
                    data-domain="<?= htmlspecialchars($r['domain'], ENT_QUOTES, 'UTF-8') ?>">Ignorer</button>
          </div>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <p class="cud-hint">Un fournisseur manquant ? Créez-le d'abord dans le module <a href="/modules/triage.php">Triage</a>.</p>
<?php endif; ?>

</div>

<!-- Supplier modal -->
<div class="cud-modal-backdrop" id="cud-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cud-modal-title" hidden>
  <div class="cud-modal">
    <h2 id="cud-modal-title"></h2>
    <input class="cud-search-input" id="cud-search-input" type="search" placeholder="Rechercher…" autocomplete="off">
    <ul class="cud-search-results" id="cud-search-results" role="listbox"></ul>
    <div class="cud-modal-actions">
      <button class="cud-btn cud-btn--cancel" id="cud-modal-cancel" type="button">Annuler</button>
      <button class="cud-btn cud-btn--confirm" id="cud-modal-confirm" type="button" disabled>Confirmer la promotion</button>
    </div>
  </div>
</div>

<script>
window.CUD_DATA = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.CUD_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/comm-unknown.js?v=<?= @filemtime(__DIR__ . '/../../public/js/comm-unknown.js') ?: time() ?>" defer></script>
</body>
</html>
