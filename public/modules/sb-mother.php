<?php
declare(strict_types=1);
/**
 * sb-mother.php — Per-mother drill-in page (Atom 4).
 *
 * Renders the full lifecycle envelope for one mother session:
 *   • Active mother        (status='open', merged_into_session_id_fk IS NULL)
 *   • Merged survivor      (status='open', has absorbed children)
 *   • Archived             (status='closed' OR status='abandoned')
 *   • Wort-contract        (ref_recipes.process_type='wort_contract') → Brassage only
 *
 * URL:  /modules/sb-mother.php?id=<int>
 * Auth: require_login() — all logged-in operators.
 * Body: home sb-board sb-mother
 *
 * Data: exclusively from sb_mother_drill_in(PDO, int) in app/sb-board.php.
 * Actions: DISABLED (deferred to atoms 7+). Rendered with aria-disabled.
 *
 * Dependencies (Atom 3 chain):
 *   app/auth.php        require_login(), current_user()
 *   app/csrf.php        csrf_token()
 *   app/db.php          maltytask_pdo() (pulled in via auth)
 *   app/sb-board.php    sb_mother_drill_in()
 *   app/svg-vessels.php svg_vessel_* (no inline SVG)
 *   app/mother-shell.php  — type reference only (no direct calls)
 *   app/partials/sidebar.php, topbar.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sb-board.php';
require_once __DIR__ . '/../../app/svg-vessels.php';
require_once __DIR__ . '/../../app/mother-shell.php';

require_login();
$me   = current_user();
$pdo  = maltytask_pdo();
$csrf = csrf_token();

// ─── 1. Validate ?id= ──────────────────────────────────────────────────────────

$rawId    = $_GET['id'] ?? null;
$motherId = ($rawId !== null && ctype_digit((string)$rawId) && (int)$rawId > 0)
    ? (int)$rawId
    : 0;

// Missing or invalid id → redirect to board.
if ($motherId === 0) {
    header('Location: /modules/sb-board.php', true, 302);
    exit;
}

// ─── 2. Fetch drill-in payload ─────────────────────────────────────────────────

$payload = sb_mother_drill_in($pdo, $motherId);

// ─── 2b. Derive current vessel from children (FIX 1) ──────────────────────────
// sb_mother_drill_in() does NOT expose current_vessel_kind at the top level.
// Derive it from the latest open child that has a vessel assignment.
$currentVesselKind   = null;
$currentVesselNumber = null;
if ($payload !== null) {
    $latestOpenChildWithVessel = null;
    foreach ($payload['children'] as $child) {
        if (($child['status'] ?? '') === 'open'
            && !empty($child['vessel_kind'])
            && isset($child['vessel_number'])) {
            if ($latestOpenChildWithVessel === null
                || $child['opened_at'] > $latestOpenChildWithVessel['opened_at']) {
                $latestOpenChildWithVessel = $child;
            }
        }
    }
    if ($latestOpenChildWithVessel !== null) {
        $currentVesselKind   = $latestOpenChildWithVessel['vessel_kind'];
        $currentVesselNumber = (int)$latestOpenChildWithVessel['vessel_number'];
    }
}

// ─── 3. Local helpers ──────────────────────────────────────────────────────────

/** Escape value for HTML output. */
function smh_esc(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** French short date: "17 mai". */
function smh_date_fr(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return smh_esc($dt);
    }
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1];
}

/** French short datetime: "17 mai, 14h22". */
function smh_datetime_fr(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return smh_esc($dt);
    }
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ', ' . date('H', $ts) . 'h' . date('i', $ts);
}

/** Days-open counter: "J+12" from opened_at, or "J+?" */
function smh_days_open(?string $opened_at): string
{
    if ($opened_at === null) {
        return '—';
    }
    $ts = strtotime($opened_at);
    if ($ts === false) {
        return '—';
    }
    $days = (int)floor((time() - $ts) / 86400);
    return 'J+' . $days;
}

/** Human label for child form_type. */
function smh_form_label(string $form_type): string
{
    return match ($form_type) {
        'brewing'    => 'Brassage',
        'fermenting' => 'Fermentation',
        'racking'    => 'Soutirage',
        'packaging'  => 'Conditionnement',
        'batch'      => 'Mother',
        default      => smh_esc($form_type),
    };
}

/** Form-type → child module link (e.g. /modules/form-fermenting.php). */
function smh_child_link(array $child): string
{
    // RULE-2 P1 TODO: ?session_id=N param is currently ignored by form modules
    // (they're stateless — load session list from DB independently). Atom 6 wires
    // the JS polling driver and at the same time should add ?session_id=N receiver
    // to each form module so child-card links pre-scroll to the session row.
    // Until then: links navigate correctly, just don't pre-scroll (acceptable).
    $id = (int)$child['id'];
    return match ($child['form_type']) {
        'brewing'    => '/modules/form-brewing.php?session_id=' . $id,
        'fermenting' => '/modules/form-fermenting.php?session_id=' . $id,
        'racking'    => '/modules/form-racking.php?session_id=' . $id,
        'packaging'  => '/modules/form-packaging.php?session_id=' . $id,
        default      => '#',
    };
}

/** Human vessel kind label. */
function smh_vessel_label(?string $kind, ?int $number): string
{
    if ($kind === null) {
        return '—';
    }
    $label = match ($kind) {
        'cct' => 'CCT',
        'bbt' => 'BBT',
        default => strtoupper($kind),
    };
    return $label . ($number !== null ? '-' . $number : '');
}

/** CSS phase class from form_type (for border + dot coloring). */
function smh_phase_class(string $form_type): string
{
    return match ($form_type) {
        'brewing'    => 'smh-phase--brewing',
        'fermenting' => 'smh-phase--fermenting',
        'racking'    => 'smh-phase--racking',
        'packaging'  => 'smh-phase--packaging',
        default      => '',
    };
}

/** Arc zone: status indicator class for the 4-phase progress arc. */
function smh_arc_class(string $form_type, array $children, bool $wort_contract): string
{
    // Determine done/active/future based on whether children of this type exist and are closed.
    $has    = false;
    $closed = true;
    foreach ($children as $c) {
        if ($c['form_type'] === $form_type) {
            $has = true;
            if ($c['status'] !== 'closed') {
                $closed = false;
            }
        }
    }
    if ($wort_contract && $form_type !== 'brewing') {
        return 'smh-arc__zone--disabled';
    }
    if (!$has) {
        return 'smh-arc__zone--future';
    }
    if ($closed) {
        return 'smh-arc__zone--done';
    }
    return 'smh-arc__zone--active';
}

// ─── 4. Page-level variables ───────────────────────────────────────────────────

$cssAppV    = @filemtime(__DIR__ . '/../css/app.css')       ?: time();
$cssBoardV  = @filemtime(__DIR__ . '/../css/sb-board.css')  ?: time();
$cssMotherV = @filemtime(__DIR__ . '/../css/sb-mother.css') ?: time();

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($payload !== null): ?>
  <title><?= smh_esc($payload['mother']['recipe_name'] ?? 'Lot') ?> #<?= smh_esc($payload['mother']['batch'] ?? '—') ?> — MaltyTask</title>
<?php else: ?>
  <title>Mother shell introuvable — MaltyTask</title>
<?php endif ?>
  <!-- CSRF meta for atom 7+ action endpoints -->
  <meta name="csrf-token" content="<?= smh_esc($csrf) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssAppV ?>">
  <link rel="stylesheet" href="/css/sb-board.css?v=<?= $cssBoardV ?>">
  <link rel="stylesheet" href="/css/sb-mother.css?v=<?= $cssMotherV ?>">
</head>
<body class="home sb-board sb-mother">

<!-- Engineering registration marks -->
<div class="sb-reg-mark sb-reg-mark--tl" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--tr" aria-hidden="true"></div>

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">
<?php if ($payload === null): ?>
<!-- ══════════════════════════════════════════════════════════════
     MOTHER INTROUVABLE
══════════════════════════════════════════════════════════════════ -->
<div class="smh-not-found" role="main" aria-label="Mother shell introuvable">
  <div class="smh-not-found__inner">
    <div class="smh-not-found__code" aria-hidden="true">◻</div>
    <h1 class="smh-not-found__title">Mother shell introuvable</h1>
    <p class="smh-not-found__sub">Le lot demandé n'existe pas, a été supprimé, ou n'est pas une mother session.</p>
    <a href="/modules/sb-board.php" class="sb-btn sb-btn--secondary">← Retour au tableau</a>
  </div>
</div>

<?php else:
    // ── Unpack payload ────────────────────────────────────────────────────────
    $mother   = $payload['mother'];
    $children = $payload['children'];
    $merge    = $payload['merge'];        // null or {survivor_id, sources[], blend_share_pct}
    $archived = $payload['archived'];

    $mId          = (int)$mother['id'];
    $recipeName   = $mother['recipe_name'] ?? '—';
    $batch        = $mother['batch'] ?? '—';
    $status       = $mother['status'] ?? 'open';
    $openedAt     = $mother['opened_at'] ?? null;
    $closedAt     = $mother['closed_at'] ?? null;
    $eta          = $mother['eta_close_date'] ?? null;
    $heartbeat    = $mother['heartbeat_severity'] ?? 'red';
    $lastActivity = $mother['last_activity_at'] ?? null;
    $wortContract = (bool)($mother['wort_contract'] ?? false);
    $blendPct     = $mother['blend_share_pct'] !== null ? (float)$mother['blend_share_pct'] : null;

    // Determine rendering variant
    $isMergedSurvivor = ($status === 'open' && $merge !== null);
    $isActive         = ($status === 'open' && !$isMergedSurvivor);
    $isArchived       = $archived;

    // Days open
    $daysOpen = smh_days_open($openedAt);

    // ETA display
    $etaLabel = ($eta !== null) ? smh_esc($eta) : 'Non défini';
    $etaDays  = null;
    if ($eta !== null) {
        $etaTs = strtotime($eta);
        if ($etaTs !== false) {
            $etaDays = (int)ceil(($etaTs - time()) / 86400);
        }
    }

    // Phase arc: detect active phase from children
    $arcPhases = ['brewing', 'fermenting', 'racking', 'packaging'];
?>

<!-- ══════════════════════════════════════════════════════════════
     SHELL WRAP
══════════════════════════════════════════════════════════════════ -->
<div class="smh-shell">

  <!-- ── ARCHIVED BANNER ───────────────────────────────────────── -->
  <?php if ($isArchived): ?>
  <div class="smh-archive-banner" role="note" aria-label="Lot archivé">
    <div class="smh-archive-banner__stamp" aria-hidden="true">
      <?= $status === 'abandoned' ? '✕ ABANDONNÉ' : '✓ CLÔTURÉ' ?>
    </div>
    <div class="smh-archive-banner__meta">
      <?php if ($closedAt !== null): ?>
      <span>Clos le <?= smh_date_fr($closedAt) ?></span>
      <?php endif ?>
      <?php if (!empty($mother['audit_flags']['close_reason'])): ?>
      <span class="smh-archive-banner__sep">·</span>
      <span><?= smh_esc($mother['audit_flags']['close_reason']) ?></span>
      <?php endif ?>
    </div>
    <a href="/modules/sb-board.php" class="smh-archive-banner__link">← Tableau</a>
  </div>
  <?php endif ?>

  <!-- ── SHELL HEADER — sticky ─────────────────────────────────── -->
  <!-- RULE-2 P2: role=region not banner — banner is reserved for the page-level
       <header> already provided by topbar.php; nested banner is a11y error. -->
  <div class="smh-header" role="region" aria-label="Entête du lot">
    <a href="/modules/sb-board.php" class="smh-header__back" aria-label="Retour au tableau">← Tableau</a>
    <div class="smh-header__sep" aria-hidden="true"></div>

    <!-- Title block -->
    <div class="smh-header__title-block">
      <h1 class="smh-header__title">
        <em><?= smh_esc($recipeName) ?></em>
        <span class="smh-header__batch">#<?= smh_esc($batch) ?></span>
      </h1>

      <!-- Status pill -->
      <?php if ($isArchived): ?>
      <span class="smh-status-pill smh-status-pill--archived">
        <?= $status === 'abandoned' ? 'Abandonné' : 'Clôturé' ?>
      </span>
      <?php elseif ($wortContract): ?>
      <span class="smh-status-pill smh-status-pill--mout">
        <span class="sb-heartbeat sb-heartbeat--<?= smh_esc($heartbeat) ?>" aria-label="Activité : <?= smh_esc($heartbeat) ?>"></span>
        MOÛT
      </span>
      <?php elseif ($isMergedSurvivor): ?>
      <span class="smh-status-pill smh-status-pill--merged">
        <span class="sb-heartbeat sb-heartbeat--<?= smh_esc($heartbeat) ?>" aria-label="Activité : <?= smh_esc($heartbeat) ?>"></span>
        Fusionnée
      </span>
      <?php else: ?>
      <span class="smh-status-pill smh-status-pill--active">
        <span class="sb-heartbeat sb-heartbeat--<?= smh_esc($heartbeat) ?>" aria-label="Activité : <?= smh_esc($heartbeat) ?>"></span>
        Actif
      </span>
      <?php endif ?>

      <!-- ETA chip -->
      <?php if ($eta !== null && !$isArchived): ?>
      <span class="smh-eta-chip" title="Date de clôture estimée">
        ETA <?= $etaLabel ?>
        <?php if ($etaDays !== null): ?>
        <span class="smh-eta-chip__delta">(<?= $etaDays >= 0 ? '+' . $etaDays . 'j' : $etaDays . 'j' ?>)</span>
        <?php endif ?>
      </span>
      <?php endif ?>
    </div>

    <!-- Meta cluster -->
    <div class="smh-header__meta" aria-label="Métadonnées du lot">
      <span class="smh-header__days"><?= smh_esc($daysOpen) ?></span>
      <span class="smh-header__meta-sep" aria-hidden="true">·</span>
      <span>Ouvert le <?= smh_esc(smh_date_fr($openedAt)) ?></span>
      <?php if ($lastActivity !== null): ?>
      <span class="smh-header__meta-sep" aria-hidden="true">·</span>
      <span class="smh-header__last-activity">Activité <?= smh_esc(smh_datetime_fr($lastActivity)) ?></span>
      <?php endif ?>
    </div>
  </div>

  <!-- ── PHASE ARC — 4-zone progress strip ─────────────────────── -->
  <?php if ($isMergedSurvivor && !$isArchived): ?>
  <!-- Merged arc: two-source visualization -->
  <div class="smh-arc smh-arc--merged" role="navigation" aria-label="Progression du lot fusionné">
    <div class="smh-arc-merged__label-strip">
      <span class="smh-arc-merged__survivor-label">Mother principale <?= smh_esc('#' . $batch) ?></span>
      <?php foreach ($merge['sources'] as $srcId): ?>
      <span class="smh-arc-merged__absorbed-label" title="Source absorbée">
        ⊕ Source #<?= (int)$srcId ?> absorbée
      </span>
      <?php endforeach ?>
    </div>
    <div class="smh-arc__zones">
      <?php foreach ($arcPhases as $phase):
          $arcClass = smh_arc_class($phase, $children, $wortContract);
      ?>
      <div class="smh-arc__zone <?= smh_esc($arcClass) ?>" aria-label="<?= smh_esc(smh_form_label($phase)) ?>">
        <span class="smh-arc__icon" aria-hidden="true">
          <?= ($arcClass === 'smh-arc__zone--done') ? '✓' : (($arcClass === 'smh-arc__zone--active') ? '⬡' : '—') ?>
        </span>
        <div class="smh-arc__name"><?= smh_esc(smh_form_label($phase)) ?></div>
      </div>
      <?php endforeach ?>
    </div>
  </div>
  <?php else: ?>
  <!-- Standard arc -->
  <nav class="smh-arc smh-arc--standard" aria-label="Progression du lot par phase">
    <?php foreach ($arcPhases as $phase):
        // RULE-2 P1 fix: $arcZoneActive was named $isActive and clobbered the
        // page-level $isActive (line 254), causing the footer Fusionner button
        // to never render (loop always ends on packaging zone = future = false).
        $arcClass      = smh_arc_class($phase, $children, $wortContract);
        $isDisabled    = ($arcClass === 'smh-arc__zone--disabled');
        $isDone        = ($arcClass === 'smh-arc__zone--done');
        $arcZoneActive = ($arcClass === 'smh-arc__zone--active');

        // Phase-specific detail for the arc zone
        $phaseDetail = '';
        foreach ($children as $c) {
            if ($c['form_type'] === $phase) {
                $phaseDetail = smh_vessel_label($c['vessel_kind'] ?? null, isset($c['vessel_number']) ? (int)$c['vessel_number'] : null);
                if ($c['opened_at']) {
                    $phaseDetail .= ($phaseDetail ? ' · ' : '') . smh_date_fr($c['opened_at']);
                }
                break;
            }
        }
        if ($isDisabled) {
            $phaseDetail = 'N/A — moût transféré';
        } elseif ($arcClass === 'smh-arc__zone--future') {
            $phaseDetail = 'En attente';
        }
        $stepAttr = $arcZoneActive ? ' aria-current="step"' : '';
    ?>
    <div class="smh-arc__zone <?= smh_esc($arcClass) ?>"<?= $stepAttr ?>>
      <span class="smh-arc__icon" aria-hidden="true">
        <?= $isDone ? '✓' : ($arcZoneActive ? '⬡' : '—') ?>
      </span>
      <div>
        <div class="smh-arc__name"><?= smh_esc(smh_form_label($phase)) ?></div>
        <?php if ($phaseDetail !== ''): ?>
        <span class="smh-arc__detail"><?= smh_esc($phaseDetail) ?></span>
        <?php endif ?>
      </div>
    </div>
    <?php endforeach ?>
  </nav>
  <?php endif ?>

  <!-- ── FACE TABS ──────────────────────────────────────────────── -->
  <div class="smh-face-tabs" role="tablist" aria-label="Faces du lot">
    <button class="smh-face-tab smh-face-tab--active" role="tab" aria-selected="true"
            aria-controls="smh-face-production" id="smh-tab-production">Production</button>
    <button class="smh-face-tab" role="tab" aria-selected="false"
            aria-controls="smh-face-cout" id="smh-tab-cout"
            title="Disponible en Phase 2" style="opacity:0.45;" aria-disabled="true" tabindex="-1">Coût</button>
    <button class="smh-face-tab" role="tab" aria-selected="false"
            aria-controls="smh-face-qualite" id="smh-tab-qualite"
            title="Disponible en Phase 2" style="opacity:0.45;" aria-disabled="true" tabindex="-1">Qualité</button>
  </div>

  <!-- ── BODY — 2 columns ──────────────────────────────────────── -->
  <div class="smh-body">

    <!-- LEFT: content column -->
    <div class="smh-content">

      <!-- ═══════════════════════════════════════════════════════
           FACE: PRODUCTION
      ═══════════════════════════════════════════════════════════ -->
      <div id="smh-face-production" class="smh-face-content smh-face-content--visible"
           role="tabpanel" aria-labelledby="smh-tab-production">

        <?php if ($wortContract): ?>
        <!-- WORT CONTRACT: context notice -->
        <div class="smh-mout-context" role="note">
          <div class="smh-mout-context__title">⚡ Contrat moût — cycle tronqué</div>
          <div class="smh-mout-context__body">
            Ce lot est un <strong>contrat de fourniture de moût</strong>.
            Le moût sera transféré directement après brassage —
            fermentation, soutirage et conditionnement ne sont pas prévus ici.
          </div>
        </div>
        <?php endif ?>

        <?php if ($isMergedSurvivor): ?>
        <!-- ── COMPOSITION — Merged survivor ── -->
        <div class="smh-section-head">
          <span class="smh-section-label">Composition</span>
          <?php if ($blendPct !== null): ?>
          <span class="smh-section-meta">Ratio principal: <?= number_format($blendPct, 1) ?>%</span>
          <?php endif ?>
        </div>

        <div class="smh-composition" aria-label="Composition du lot fusionné">
          <!-- Survivor (this mother — main) -->
          <div class="smh-comp-source smh-comp-source--main">
            <?php if ($blendPct !== null): ?>
            <div class="smh-comp-source__bar" style="width:<?= (int)$blendPct ?>%" aria-hidden="true"></div>
            <?php endif ?>
            <div class="smh-comp-source__inner">
              <div class="smh-comp-source__labels">
                <span class="smh-comp-tag smh-comp-tag--main">Mother principale</span>
                <span class="smh-comp-ref">#<?= smh_esc($batch) ?></span>
              </div>
              <div class="smh-comp-source__name">
                <em><?= smh_esc($recipeName) ?></em>
                <?php if ($blendPct !== null): ?>
                <span class="smh-comp-source__pct"><?= number_format($blendPct, 1) ?>%</span>
                <?php endif ?>
              </div>
              <div class="smh-comp-source__detail">Ouvert le <?= smh_esc(smh_date_fr($openedAt)) ?></div>
            </div>
          </div>

          <!-- Absorbed sources -->
          <?php foreach ($merge['sources'] as $srcIdx => $srcId):
              // Fetch minimal data about the absorbed source — only via sb_mother_drill_in
              $srcPayload = sb_mother_drill_in($pdo, (int)$srcId);
              if ($srcPayload === null) continue;
              $srcMother   = $srcPayload['mother'];
              $srcBatch    = $srcMother['batch'] ?? '—';
              $srcRecipe   = $srcMother['recipe_name'] ?? '—';
              $srcOpenedAt = $srcMother['opened_at'] ?? null;
              $srcClosedAt = $srcMother['closed_at'] ?? null;
              $srcBlend    = $srcMother['blend_share_pct'] !== null ? (float)$srcMother['blend_share_pct'] : null;
              $expandId    = 'smh-absorbed-' . (int)$srcId;
          ?>
          <div class="smh-comp-source smh-comp-source--absorbed">
            <?php if ($srcBlend !== null): ?>
            <div class="smh-comp-source__bar smh-comp-source__bar--absorbed" style="width:<?= (int)$srcBlend ?>%" aria-hidden="true"></div>
            <?php endif ?>
            <div class="smh-comp-source__inner">
              <div class="smh-comp-source__labels">
                <span class="smh-comp-tag smh-comp-tag--absorbed">Absorbée</span>
                <span class="smh-comp-ref">#<?= smh_esc($srcBatch) ?></span>
                <span class="smh-comp-tag smh-comp-tag--closed">CLÔTURÉE</span>
              </div>
              <div class="smh-comp-source__name" style="opacity:0.65;">
                <em><?= smh_esc($srcRecipe) ?></em>
                <?php if ($srcBlend !== null): ?>
                <span class="smh-comp-source__pct" style="color:var(--ink-faint);"><?= number_format($srcBlend, 1) ?>%</span>
                <?php endif ?>
              </div>
              <div class="smh-comp-source__detail" style="opacity:0.55;">
                Brassé le <?= smh_esc(smh_date_fr($srcOpenedAt)) ?>
                <?php if ($srcClosedAt): ?> · Fusionné le <?= smh_esc(smh_date_fr($srcClosedAt)) ?><?php endif ?>
              </div>
              <div class="smh-comp-source__actions">
                <button class="smh-comp-source__expand"
                        aria-expanded="false" aria-controls="<?= smh_esc($expandId) ?>"
                        onclick="smhToggleAbsorbed(this,'<?= smh_esc($expandId) ?>')">
                  Voir le détail ↓
                </button>
                <a href="/modules/sb-mother.php?id=<?= (int)$srcId ?>" class="smh-comp-source__archive-link">
                  Archive →
                </a>
              </div>
            </div>

            <!-- Expandable mini-timeline for absorbed source -->
            <div class="smh-comp-expanded" id="<?= smh_esc($expandId) ?>" aria-hidden="true">
              <div class="smh-mini-timeline-label">Mini-chronologie #<?= smh_esc($srcBatch) ?></div>
              <?php if (!empty($srcPayload['children'])): ?>
              <div class="smh-mini-timeline">
                <?php foreach (array_reverse($srcPayload['children']) as $sc): ?>
                <div class="smh-mini-event">
                  <span class="smh-mini-event__type"><?= smh_esc(smh_form_label($sc['form_type'])) ?></span>
                  <span class="smh-mini-event__vessel"><?= smh_esc(smh_vessel_label($sc['vessel_kind'] ?? null, isset($sc['vessel_number']) ? (int)$sc['vessel_number'] : null)) ?></span>
                  <span class="smh-mini-event__date"><?= smh_esc(smh_date_fr($sc['opened_at'] ?? null)) ?></span>
                  <span class="smh-mini-event__status smh-mini-event__status--<?= smh_esc($sc['status'] ?? 'open') ?>">
                    <?= $sc['status'] === 'closed' ? 'Clos' : 'Ouvert' ?>
                  </span>
                </div>
                <?php endforeach ?>
              </div>
              <?php else: ?>
              <div class="smh-mini-timeline-empty">Aucune session enfant enregistrée.</div>
              <?php endif ?>
              <div class="smh-comp-expanded__footer">
                Lot archivé (lecture seule) →
                <a href="/modules/sb-mother.php?id=<?= (int)$srcId ?>" class="smh-comp-source__archive-link">
                  Voir la fiche archive →
                </a>
              </div>
            </div>
          </div>
          <?php endforeach ?>
        </div><!-- /composition -->
        <div class="smh-divider" aria-hidden="true"></div>
        <?php else: ?>
        <!-- Simple lot (no merge) — composition note -->
        <div class="smh-section-head">
          <span class="smh-section-label">Composition</span>
        </div>
        <p class="smh-simple-composition">
          Lot simple — aucune fusion.
          <?php if ($openedAt): ?>
          Volume issu du brassage <?= smh_esc($recipeName) ?> #<?= smh_esc($batch) ?> du <?= smh_esc(smh_date_fr($openedAt)) ?>.
          <?php endif ?>
        </p>
        <div class="smh-divider" aria-hidden="true"></div>
        <?php endif ?>

        <!-- ── CHILD SESSIONS SUMMARY ── -->
        <div class="smh-section-head">
          <span class="smh-section-label">Sessions par phase</span>
          <span class="smh-section-meta"><?= count($children) ?> session<?= count($children) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($children)): ?>
        <div class="smh-no-children">
          <div class="smh-no-children__icon" aria-hidden="true">◻</div>
          <div class="smh-no-children__msg">Aucune session enfant enregistrée pour ce lot.</div>
        </div>

        <?php else: ?>
        <div class="smh-children-list" role="list" aria-label="Sessions par phase">
          <?php foreach ($children as $child):
              $childId    = (int)$child['id'];
              $childType  = $child['form_type'] ?? 'unknown';
              $childPhase = $child['phase'] ?? '—';
              $childSt    = $child['status'] ?? 'open';
              $childVessel= smh_vessel_label($child['vessel_kind'] ?? null, isset($child['vessel_number']) ? (int)$child['vessel_number'] : null);
              $childEvts  = (int)$child['events_count'];
              $childLast  = $child['last_activity'] ?? null;
              $childLink  = smh_child_link($child);
              $phaseClass = smh_phase_class($childType);

              // Heartbeat severity for this child
              $childLastDt = null;
              if ($childLast !== null) {
                  $childLastDt = DateTime::createFromFormat('Y-m-d H:i:s.u', $childLast)
                              ?: DateTime::createFromFormat('Y-m-d H:i:s', $childLast);
              }
              $childHb = 'red';
              if ($childLastDt !== null) {
                  $hoursAgo = (time() - $childLastDt->getTimestamp()) / 3600.0;
                  if ($hoursAgo <= 24) { $childHb = 'green'; }
                  elseif ($hoursAgo <= 72) { $childHb = 'amber'; }
              }
          ?>
          <div class="smh-child-card <?= smh_esc($phaseClass) ?> smh-child-card--<?= smh_esc($childSt) ?>"
               role="listitem">
            <div class="smh-child-card__type-bar" aria-hidden="true"></div>
            <div class="smh-child-card__body">
              <div class="smh-child-card__top">
                <span class="smh-child-card__type-label"><?= smh_esc(smh_form_label($childType)) ?></span>
                <span class="sb-heartbeat sb-heartbeat--<?= smh_esc($childHb) ?>"
                      aria-label="Activité : <?= smh_esc($childHb) ?>"></span>
                <span class="smh-child-card__status smh-child-card__status--<?= smh_esc($childSt) ?>">
                  <?= smh_esc($childSt === 'closed' ? 'Clos' : ($childSt === 'abandoned' ? 'Abandonné' : 'Ouvert')) ?>
                </span>
              </div>
              <div class="smh-child-card__meta">
                <?php if ($childVessel !== '—'): ?>
                <span class="smh-child-card__vessel"><?= smh_esc($childVessel) ?></span>
                <span class="smh-child-meta-sep" aria-hidden="true">·</span>
                <?php endif ?>
                <span>Ouvert <?= smh_esc(smh_date_fr($child['opened_at'] ?? null)) ?></span>
                <?php if ($childLast !== null): ?>
                <span class="smh-child-meta-sep" aria-hidden="true">·</span>
                <span class="smh-child-card__last-act">Activité <?= smh_esc(smh_datetime_fr($childLast)) ?></span>
                <?php endif ?>
                <?php if ($childEvts > 0): ?>
                <span class="smh-child-meta-sep" aria-hidden="true">·</span>
                <span><?= $childEvts ?> événement<?= $childEvts > 1 ? 's' : '' ?></span>
                <?php endif ?>
              </div>
              <?php if ($childLink !== '#' && !$wortContract): ?>
              <div class="smh-child-card__actions">
                <a href="<?= smh_esc($childLink) ?>" class="smh-child-card__link">
                  Voir la session →
                </a>
              </div>
              <?php endif ?>
            </div>
          </div>
          <?php endforeach ?>
        </div>
        <?php endif ?>

        <?php if ($wortContract): ?>
        <!-- WORT CONTRACT: disabled zones notice -->
        <div class="smh-divider" aria-hidden="true"></div>
        <div class="smh-wort-disabled-zones" role="note" aria-label="Phases désactivées">
          <div class="smh-wort-disabled-zones__label">Phases hors process</div>
          <?php foreach (['fermenting' => 'Fermentation', 'racking' => 'Soutirage', 'packaging' => 'Conditionnement'] as $zKey => $zLabel): ?>
          <div class="smh-wort-zone-disabled">
            <span class="smh-wort-zone-disabled__name"><?= smh_esc($zLabel) ?></span>
            <span class="smh-wort-zone-disabled__badge">N/A — moût livré</span>
          </div>
          <?php endforeach ?>
        </div>
        <?php endif ?>

        <!-- ── EXPÉDITION — Phase placeholder ── -->
        <div class="smh-divider" aria-hidden="true"></div>
        <div class="smh-section-head">
          <span class="smh-section-label">Expédition</span>
          <span class="smh-section-meta smh-section-meta--muted">Phase 3</span>
        </div>
        <div class="smh-placeholder-zone" aria-label="Zone Expédition — à venir">
          <div class="smh-placeholder-zone__icon" aria-hidden="true">🚛</div>
          <div class="smh-placeholder-zone__label">Intégration prévue — Phase 3</div>
          <div class="smh-placeholder-zone__sub">Cette zone affichera les expéditions liées au lot.</div>
        </div>

      </div><!-- /face-production -->

      <!-- FACE: COÛT (placeholder) -->
      <div id="smh-face-cout" class="smh-face-content"
           role="tabpanel" aria-labelledby="smh-tab-cout" hidden>
        <div class="smh-face-placeholder">
          <div class="smh-face-placeholder__label">Face Coût — Phase 2</div>
          <div class="smh-face-placeholder__msg">Valorisation du lot, coût matières, CHF/HL estimé.<br>Disponible après intégration Phase 2.</div>
        </div>
      </div>

      <!-- FACE: QUALITÉ (placeholder) -->
      <div id="smh-face-qualite" class="smh-face-content"
           role="tabpanel" aria-labelledby="smh-tab-qualite" hidden>
        <div class="smh-face-placeholder">
          <div class="smh-face-placeholder__label">Face Qualité — Phase 2</div>
          <div class="smh-face-placeholder__msg">Profil gravimétrique, pH, turbidité, seuils QA.<br>Disponible après intégration Phase 2.</div>
        </div>
      </div>

    </div><!-- /smh-content -->

    <!-- RIGHT RAIL — lot info + alerts -->
    <aside class="smh-rail" aria-label="Informations du lot">
      <div class="smh-rail-head">Informations du lot</div>
      <div class="smh-rail-section">
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Recette</span>
          <span class="smh-rail-item__value smh-rail-item__value--recipe"><em><?= smh_esc($recipeName) ?></em></span>
        </div>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Batch</span>
          <span class="smh-rail-item__value">#<?= smh_esc($batch) ?></span>
        </div>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Statut</span>
          <span class="smh-rail-item__value">
            <?= smh_esc($status === 'open' ? 'Ouvert' : ($status === 'closed' ? 'Clôturé' : 'Abandonné')) ?>
          </span>
        </div>
        <?php if ($wortContract): ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Type</span>
          <span class="smh-rail-item__value smh-rail-item__value--mout">wort_contract</span>
        </div>
        <?php endif ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Ouvert le</span>
          <span class="smh-rail-item__value"><?= smh_esc(smh_date_fr($openedAt)) ?></span>
        </div>
        <?php if ($closedAt !== null): ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Clos le</span>
          <span class="smh-rail-item__value"><?= smh_esc(smh_date_fr($closedAt)) ?></span>
        </div>
        <?php endif ?>
        <?php if ($eta !== null && !$isArchived): ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">ETA clôture</span>
          <span class="smh-rail-item__value smh-rail-item__value--cold"><?= $etaLabel ?></span>
        </div>
        <?php endif ?>
        <?php if ($isMergedSurvivor && $blendPct !== null): ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Ratio fusion</span>
          <span class="smh-rail-item__value"><?= number_format($blendPct, 1) ?>% (principal)</span>
        </div>
        <?php endif ?>
      </div>

      <!-- Heartbeat / last activity -->
      <div class="smh-rail-head">Activité</div>
      <div class="smh-rail-section">
        <div class="smh-heartbeat-row">
          <span class="sb-heartbeat sb-heartbeat--<?= smh_esc($heartbeat) ?>" style="width:10px;height:10px;"
                aria-label="Sévérité : <?= smh_esc($heartbeat) ?>"></span>
          <span class="smh-heartbeat-label smh-heartbeat-label--<?= smh_esc($heartbeat) ?>">
            <?= smh_esc(match($heartbeat) {
                'green' => 'Récente (< 24h)',
                'amber' => 'Modérée (< 72h)',
                default => 'Inactivité (> 72h)',
            }) ?>
          </span>
        </div>
        <?php if ($lastActivity !== null): ?>
        <div class="smh-rail-item">
          <span class="smh-rail-item__label">Dernière activité</span>
          <span class="smh-rail-item__value"><?= smh_esc(smh_datetime_fr($lastActivity)) ?></span>
        </div>
        <?php endif ?>
      </div>

      <!-- Actions rapides (deferred to atom 7+) -->
      <?php if (!$isArchived): ?>
      <div class="smh-rail-head">Actions rapides</div>
      <div class="smh-rail-section smh-rail-section--actions">
        <a href="/modules/sb-board.php" class="smh-btn smh-btn--secondary">← Tableau</a>
        <a href="/modules/sessions.php" class="smh-btn smh-btn--secondary">Journal de bord →</a>
      </div>
      <?php endif ?>
    </aside><!-- /smh-rail -->

  </div><!-- /smh-body -->

  <!-- ── STICKY FOOTER ─────────────────────────────────────────── -->
  <?php if (!$isArchived): ?>
  <footer class="smh-footer" role="complementary" aria-label="Actions du lot">
    <div class="smh-footer__left">
      <?php if (!$wortContract): ?>
      <?php if ($isActive && $currentVesselKind !== null): ?>
      <!-- Cuve vide — Atom 7: active, has vessel assignment -->
      <button class="smh-btn smh-btn--secondary"
              onclick="window.sbCuveVide && window.sbCuveVide.open()"
              title="Déclarer la cuve vide — déclenche la clôture des lots">
        Cuve vide
      </button>
      <?php elseif ($isActive): ?>
      <!-- Cuve vide — active but no vessel assigned yet -->
      <button class="smh-btn smh-btn--secondary smh-btn--disabled"
              disabled aria-disabled="true" tabindex="-1"
              title="Aucune cuve assignée — soutirage requis d'abord">
        Cuve vide
      </button>
      <?php endif ?>
      <!-- Fusionner — Atom 8: active mothers only -->
      <?php if ($isActive): ?>
      <button class="smh-btn smh-btn--secondary"
              onclick="window.sbMerge && window.sbMerge.open()"
              title="Fusionner ce lot avec un autre lot ouvert">
        Fusionner
      </button>
      <?php endif ?>
      <?php endif ?>
    </div>
    <div class="smh-footer__right">
      <!-- Clôturer manuellement — admin only, Atom 8 -->
      <?php if ($isActive && ($me['role'] ?? '') === 'admin'): ?>
      <button class="smh-btn smh-btn--danger"
              onclick="window.sbForceClose && window.sbForceClose.open()"
              title="Clôturer manuellement ce lot — admin uniquement">
        Clôturer manuellement
      </button>
      <?php elseif ($isActive): ?>
      <button class="smh-btn smh-btn--danger smh-btn--disabled"
              disabled aria-disabled="true" tabindex="-1"
              title="Réservé aux administrateurs">
        Clôturer manuellement
      </button>
      <?php endif ?>
    </div>
  </footer>
  <?php endif ?>

</div><!-- /smh-shell -->
<?php endif ?>

<?php if ($payload !== null && !$isArchived && $currentVesselKind !== null): ?>
<?php require __DIR__ . '/../../app/partials/sb-cuve-vide-modal.php'; ?>
<?php endif ?>

<?php if ($payload !== null && $isActive && !$isMergedSurvivor): ?>
<?php $motherId = $mId; require __DIR__ . '/../../app/partials/sb-merge-modal.php'; ?>
<?php endif ?>

<?php if ($payload !== null && $isActive && !$isArchived && ($me['role'] ?? '') === 'admin'): ?>
<?php $motherId = $mId; require __DIR__ . '/../../app/partials/sb-force-close-modal.php'; ?>
<?php endif ?>
</main>

<script>
// ── Face tab switching ──────────────────────────────────────────────────────
(function () {
  'use strict';
  var tabs   = document.querySelectorAll('.smh-face-tab');
  var panels = document.querySelectorAll('.smh-face-content');

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      if (tab.getAttribute('aria-disabled') === 'true') { return; }
      var target = tab.getAttribute('aria-controls');
      tabs.forEach(function (t) {
        t.classList.remove('smh-face-tab--active');
        t.setAttribute('aria-selected', 'false');
      });
      panels.forEach(function (p) {
        p.classList.remove('smh-face-content--visible');
        p.hidden = true;
      });
      tab.classList.add('smh-face-tab--active');
      tab.setAttribute('aria-selected', 'true');
      var panel = document.getElementById(target);
      if (panel) {
        panel.classList.add('smh-face-content--visible');
        panel.hidden = false;
      }
    });
  });
}());

// ── Absorbed source expand/collapse ────────────────────────────────────────
// RULE-2 P2: explicitly attach to window to make namespace clear before atom 6
// loads sb-board.js on this page (avoids accidental collision).
window.smhToggleAbsorbed = function (btn, expandId) {
  var detail   = document.getElementById(expandId);
  if (!detail) { return; }
  var expanded = detail.classList.toggle('smh-comp-expanded--visible');
  detail.setAttribute('aria-hidden', expanded ? 'false' : 'true');
  btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  btn.textContent = expanded ? 'Réduire ↑' : 'Voir le détail ↓';
};
</script>

</body>
</html>
