<?php
declare(strict_types=1);
/**
 * sb-salle-de-guerre.php — Salle de Guerre · Critical Alert View (Atom 9).
 *
 * "Panic view": filtered SELECT over doc_review_queue for the 4 mother-shell
 * RQ types introduced in migration 215. NO new table — filtered view only.
 *
 * Auth: require_login() — any operator sees the panic view.
 * Body class: home sb-board sb-guerre (reuses sb-board tokens; sb-guerre scopes new CSS).
 * Active module: sb-board (keeps "Lots en cours" highlighted in topbar).
 *
 * Keyboard shortcut: Shift+W opens from the board; Esc or Shift+W returns.
 * Topbar: discrete "Salle de Guerre" link alongside the "Lots en cours" entry.
 *
 * Architecture: doc_review_queue is the canonical RQ surface.
 *   SELECT * FROM doc_review_queue
 *   WHERE type IN ('garde_seuil_overdue','contamination_flagged',
 *                  'mother_abandoned','packaged_volume_anomaly')
 *     AND closed_at IS NULL
 *   ORDER BY created_at DESC
 *
 * Reuse anchors (DO NOT FORK):
 *   app/auth.php        — require_login(), current_user()
 *   app/csrf.php        — csrf_token()
 *   app/db.php          — maltytask_pdo() (pulled in via auth)
 *   app/partials/sidebar.php, topbar.php — standard nav shell
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';

require_login();
$me   = current_user();
$pdo  = maltytask_pdo();
$csrf = csrf_token();

/* ─── Constants ─────────────────────────────────────────────────────────────── */

const SDG_TYPES = [
    'garde_seuil_overdue',
    'contamination_flagged',
    'mother_abandoned',
    'packaged_volume_anomaly',
];

/* ─── Data fetch ─────────────────────────────────────────────────────────────── */

$placeholders = implode(',', array_fill(0, count(SDG_TYPES), '?'));
$stmt = $pdo->prepare(
    "SELECT id, type, body, metadata, severity, created_at, decision
       FROM doc_review_queue
      WHERE type IN ({$placeholders})
        AND closed_at IS NULL
      ORDER BY created_at DESC"
);
$stmt->execute(SDG_TYPES);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Split into critical vs warning sections (guard: severity column may be NULL). */
$critical = array_filter($rows, fn($r) => !in_array($r['severity'] ?? 'critical', ['warning', 'warn'], true));
$warnings = array_filter($rows, fn($r) =>  in_array($r['severity'] ?? 'critical', ['warning', 'warn'], true));
$totalOpen = count($rows);
$refreshedAt = date('Y-m-d H:i:s');

/* ─── Local helpers ──────────────────────────────────────────────────────────── */

/** Escape for HTML output. */
function sdg_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** French short datetime: "29 mai, 09h47" */
function sdg_date_fr(string|null $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return sdg_esc($dt);
    }
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
    $day    = (int) date('j', $ts);
    $month  = $months[(int) date('n', $ts) - 1];
    $time   = date('H', $ts) . 'h' . date('i', $ts);
    return "{$day} {$month}, {$time}";
}

/** Human-readable label for each RQ type. */
function sdg_type_label(string $type): string
{
    return match ($type) {
        'garde_seuil_overdue'    => 'GARDE OVERDUE',
        'contamination_flagged'  => 'CONTAM. FLAGGED',
        'mother_abandoned'       => 'ABANDON DÉCLARÉ',
        'packaged_volume_anomaly'=> 'VOLUME ANOMALY',
        default                  => strtoupper($type),
    };
}

/** Decode metadata JSON; return empty array on failure. */
function sdg_meta(string|null $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Render a single alert card.
 * $isWarn — true renders the amber warning variant.
 */
function sdg_render_card(array $row, bool $isWarn = false): string
{
    $meta      = sdg_meta($row['metadata'] ?? null);
    $motherId  = isset($meta['mother_id']) ? (int) $meta['mother_id'] : null;
    $body      = sdg_esc($row['body'] ?? '');
    $type      = $row['type'] ?? '';
    $createdAt = sdg_date_fr($row['created_at'] ?? null);
    $stampText = sdg_type_label($type);
    $rqId      = (int) $row['id'];

    $cardClass  = $isWarn ? ' sb-guerre-card--warn' : '';
    $stampClass = $isWarn ? ' sb-guerre-card__stamp--warn' : '';
    $typeClass  = $isWarn ? ' sb-guerre-rq-type--warn' : '';

    /* Mother link — links to sb-mother.php when mother_id is resolvable. */
    $voirHref = $motherId !== null
        ? '/modules/sb-mother.php?id=' . $motherId
        : '#';

    $html  = '<article class="sb-guerre-card' . $cardClass . '" aria-label="' . sdg_esc($type) . ' — alerte #' . $rqId . '">';
    $html .= '<div class="sb-guerre-card__inner">';

    /* Stamp */
    $html .= '<div class="sb-guerre-card__stamp' . $stampClass . '">' . nl2br(sdg_esc($stampText)) . '</div>';

    /* Body */
    $html .= '<div class="sb-guerre-card__body">';

    /* Ref line: type badge + mother link */
    $html .= '<div class="sb-guerre-card__ref">';
    $html .= '<span class="sb-guerre-rq-type' . $typeClass . '">' . sdg_esc($type) . '</span>';
    if ($motherId !== null) {
        $html .= '<span>Lot #' . $motherId . '</span>';
    }
    $html .= '<span>RQ #' . $rqId . '</span>';
    $html .= '<span>Créé : ' . $createdAt . '</span>';
    $html .= '</div>';

    /* Title from metadata if available, else fallback to type label */
    $titleText = '';
    if (!empty($meta['recipe_name']) && !empty($meta['batch'])) {
        $titleText = sdg_esc($meta['recipe_name']) . ' — Lot #' . sdg_esc((string) $meta['batch']);
    } elseif (!empty($meta['recipe_name'])) {
        $titleText = sdg_esc($meta['recipe_name']);
    } elseif ($motherId !== null) {
        $titleText = 'Lot #' . $motherId;
    } else {
        $titleText = sdg_esc(sdg_type_label($type));
    }
    $html .= '<h2 class="sb-guerre-card__title"><em>' . $titleText . '</em></h2>';

    /* Detail body */
    $html .= '<div class="sb-guerre-card__detail">' . nl2br($body) . '</div>';

    $html .= '</div>';/* /card__body */

    /* Actions */
    $html .= '<div class="sb-guerre-card__actions">';
    $html .= '<a href="' . sdg_esc($voirHref) . '" class="sb-guerre-action-voir">Voir →</a>';
    $html .= '<button class="sb-guerre-action-snooze" type="button" disabled title="Snooze non disponible en v1">Acquitter</button>';
    $html .= '</div>';

    $html .= '</div>';/* /card__inner */
    $html .= '</article>';

    return $html;
}

/* ─── Page variables ─────────────────────────────────────────────────────────── */

$active_module = 'sb-board';
$cssAppV       = @filemtime(__DIR__ . '/../css/app.css')        ?: time();
$cssBoardV     = @filemtime(__DIR__ . '/../css/sb-board.css')   ?: time();
$cssGuerreV    = @filemtime(__DIR__ . '/../css/sb-guerre.css')  ?: time();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Salle de Guerre — Alertes critiques · MaltyTask</title>
  <meta name="csrf-token" content="<?= sdg_esc($csrf) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssAppV ?>">
  <link rel="stylesheet" href="/css/sb-board.css?v=<?= $cssBoardV ?>">
  <link rel="stylesheet" href="/css/sb-guerre.css?v=<?= $cssGuerreV ?>">
</head>
<body class="home sb-board sb-guerre">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">
<div class="sb-guerre-wrap">

  <!-- ══════════════════════════════════════════════════════════════
       TOP BANNER — "VUE DE GUERRE" announcement strip
  ══════════════════════════════════════════════════════════════════ -->
  <div class="sb-guerre-banner" role="banner" aria-label="Salle de Guerre — vue alertes critiques">
    <div class="sb-guerre-banner__signal">
      <div class="sb-guerre-banner__dot" aria-hidden="true"></div>
      <div class="sb-guerre-banner__title">VUE DE GUERRE — alertes critiques uniquement</div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;">
      <div class="sb-guerre-banner__count" aria-label="<?= $totalOpen ?> alertes critiques"><?= $totalOpen ?></div>
      <div class="sb-guerre-banner__count-label">alerte<?= $totalOpen !== 1 ? 's' : '' ?> active<?= $totalOpen !== 1 ? 's' : '' ?></div>
    </div>
    <div class="sb-guerre-banner__refresh" style="margin-left:auto;margin-right:0;">
      Actualisé : <?= sdg_esc($refreshedAt) ?> · Auto-refresh 15s
    </div>
    <a href="/modules/sb-board.php" class="sb-guerre-banner__close-hint"
       title="Retour au tableau (Esc ou Shift+W)" aria-label="Retour au tableau de bord">
      ← Retour tableau · Esc
    </a>
  </div>

  <!-- RQ source note -->
  <div class="sb-guerre-rq-note" role="note">
    <span class="sb-guerre-rq-note__tag">review_queue</span>
    Vue filtrée : type IN (garde_seuil_overdue, contamination_flagged, mother_abandoned, packaged_volume_anomaly) · Les alertes acquittées n'apparaissent pas ici.
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       ALERT BODY — critical then warning sections
  ══════════════════════════════════════════════════════════════════ -->
  <div class="sb-guerre-body" role="main" aria-label="Alertes critiques">

    <?php if ($totalOpen === 0): ?>
    <!-- ── Empty state ── -->
    <div class="sb-guerre-empty" aria-label="Aucune alerte critique">
      <div class="sb-guerre-empty__icon" aria-hidden="true">◻</div>
      <div class="sb-guerre-empty__title">Aucune alerte critique</div>
      <div class="sb-guerre-empty__sub">
        Aucun lot ne présente de dépassement de garde, de contamination signalée,
        d'abandon ou d'anomalie de volume à ce moment.
      </div>
    </div>

    <?php else: ?>

    <?php if (!empty($critical)): ?>
    <!-- ── Critique section ── -->
    <div class="sb-guerre-section-div">
      <span class="sb-guerre-section-div__label">
        Critique · <?= count($critical) ?> alerte<?= count($critical) !== 1 ? 's' : '' ?>
      </span>
      <div class="sb-guerre-section-div__rule"></div>
    </div>
    <?php foreach ($critical as $row): ?>
    <?= sdg_render_card($row, false) ?>
    <?php endforeach ?>
    <?php endif ?>

    <?php if (!empty($warnings)): ?>
    <!-- ── Attention section ── -->
    <div class="sb-guerre-section-div sb-guerre-section-div--warn" style="margin-top:8px;">
      <span class="sb-guerre-section-div__label">
        Attention · <?= count($warnings) ?> alerte<?= count($warnings) !== 1 ? 's' : '' ?>
      </span>
      <div class="sb-guerre-section-div__rule"></div>
    </div>
    <?php foreach ($warnings as $row): ?>
    <?= sdg_render_card($row, true) ?>
    <?php endforeach ?>
    <?php endif ?>

    <!-- About footer -->
    <div class="sb-guerre-about">
      <div class="sb-guerre-about__label">À propos de cette vue</div>
      <div class="sb-guerre-about__text">
        La Salle de Guerre est une vue filtrée de <code>doc_review_queue</code> — aucun stockage parallèle.
        Les alertes sont générées automatiquement par les routines de surveillance (garde seuil, QA seuils, volume emballé, abandon).
        Acquitter une alerte fermera la ligne RQ et retirera la carte de cette vue.
      </div>
    </div>

    <?php endif ?>

  </div><!-- /sb-guerre-body -->

</div><!-- /sb-guerre-wrap -->
</main>

<script>
/* Auto-refresh every 15 seconds — reloads the page for fresh RQ data. */
(function () {
  'use strict';

  /* Auto-refresh */
  setTimeout(function () { location.reload(); }, 15000);

  /* Keyboard shortcuts: Shift+W or Esc → back to board. */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      window.location.href = '/modules/sb-board.php';
      return;
    }
    /* Shift+W — capital W requires shift on most keyboards.
       Guard: skip if focus is in an input/textarea to avoid trapping typing. */
    if (e.key === 'W' && e.shiftKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName ?? '')) {
      window.location.href = '/modules/sb-board.php';
    }
  });
})();
</script>

</body>
</html>
