<?php
declare(strict_types=1);
/**
 * session-shell.php — Universal session shell template.
 *
 * Renders the lifecycle envelope for any op_sessions row:
 * sticky header, phase stepper, QC firewall (phase=start),
 * per-form-type body slot ($formBody), audit rail, sticky footer.
 *
 * URL: /modules/session-shell.php?id={op_sessions.id}
 *
 * Server variables populated here:
 *   $session  — full op_sessions row + username joins (session_for_id)
 *   $steps    — ordered audit trail (session_steps_for)
 *   $firewall — three-gate status (session_firewall_status)
 *   $labels   — human display labels (session_labels)
 *   $me       — current_user()
 *   $formBody — rendered HTML from per-form-type partial (or empty string)
 *
 * Terminal guard:
 *   $session === null            → 404
 *   status = closed|abandoned    → read-only terminal view (no footer, no slot)
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sessions.php';

require_login();
$me  = current_user();
$pdo = maltytask_pdo();

// ── 1. Resolve session from ?id= ──────────────────────────────────────────────
$rawId     = $_GET['id'] ?? null;
$sessionId = ($rawId !== null && ctype_digit((string)$rawId)) ? (int)$rawId : 0;

$session = ($sessionId > 0) ? session_for_id($pdo, $sessionId) : null;

// ── 2. Guard: 404 ─────────────────────────────────────────────────────────────
if ($session === null) {
    http_response_code(404);
    ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>404 — Session introuvable</title>
<link rel="stylesheet" href="/css/app.css?v=<?= filemtime(__DIR__ . '/../css/app.css') ?>"></head>
<body class="auth">
<h1>404</h1>
<p>Session introuvable ou supprimée.</p>
<p><a href="/">Retour à l'accueil</a></p>
</body></html><?php
    exit;
}

// ── 3. Load audit trail + helpers ─────────────────────────────────────────────
$steps    = session_steps_for($pdo, $sessionId);
$firewall = session_firewall_status($pdo, $sessionId);
$labels   = session_labels($pdo, $sessionId);

// ── 4. Terminal-state check ───────────────────────────────────────────────────
$isTerminal = in_array($session['status'], ['closed', 'abandoned'], true);

// ── 5. Next phase (for Avancer button) ───────────────────────────────────────
// Session phases: start → in_progress → end → closed.
// Returns null when already at a terminal state or phase=closed.
const _SS_PHASE_NEXT = ['start' => 'in_progress', 'in_progress' => 'end', 'end' => 'closed'];
$nextPhase = !$isTerminal ? (_SS_PHASE_NEXT[$session['phase']] ?? null) : null;

// Avancer is disabled when:
//   • phase=start and firewall not all_clear
//   • already terminal (no footer rendered)
$advanceEnabled = $nextPhase !== null && !($session['phase'] === 'start' && !$firewall['all_clear']);

// ── 6. Handover detection ─────────────────────────────────────────────────────
// Show handover banner when: current user ≠ opener AND a handover step exists.
$hasHandoverStep  = false;
$lastHandoverFrom = null;
foreach ($steps as $step) {
    if ($step['step_type'] === 'handover') {
        $hasHandoverStep  = true;
        $lastHandoverFrom = $step['actor_username'] ?? null;
    }
}
$showHandoverBanner = $hasHandoverStep && ((int)$me['id'] !== (int)$session['opened_by_fk']);

// ── 7. Opener operator chip ────────────────────────────────────────────────────
$openerUsername    = $session['opened_by_username'] ?? $session['opened_by_fk'];
$openerInitials    = _ss_initials((string)$openerUsername);
$openerDisplayTime = $session['opened_at'] ? date('H:i', strtotime((string)$session['opened_at'])) : '';

// Current actor = last step actor, or opener if no steps.
$currentActorUsername = $openerUsername;
$currentActorTime     = $openerDisplayTime;
if (!empty($steps)) {
    $last = end($steps);
    $currentActorUsername = $last['actor_username'] ?? $openerUsername;
    $currentActorTime     = $last['acted_at'] ? date('H:i', strtotime((string)$last['acted_at'])) : '';
}
$currentActorInitials = _ss_initials((string)$currentActorUsername);
$currentActorIsMe     = ($currentActorUsername === ($me['username'] ?? ''));

// ── 8. Phase-step sub-labels (stepper) ────────────────────────────────────────
// Build per-phase "done by X at HH:MM" from phase_advanced steps.
// NB: values carry PRE-ENCODED HTML (escaped $actor + safe literals). The `Html`
// suffix marks the contract — consumers MUST echo raw, MUST NOT double-escape,
// and MUST keep concatenated literals HTML-safe. See _SUB_LABEL_HTML invariant
// at the consumer site below.
$phaseStepSubLabelsHtml = [];
foreach ($steps as $step) {
    if ($step['step_type'] === 'phase_advanced') {
        $payload = $step['payload'] ? json_decode((string)$step['payload'], true) : [];
        $from    = $payload['from'] ?? null;
        $actor   = $step['actor_username'] ?? '';
        $ts      = $step['acted_at'] ? date('H:i', strtotime((string)$step['acted_at'])) : '';
        if ($from) {
            $phaseStepSubLabelsHtml[$from] = htmlspecialchars($actor) . ' · ' . $ts;
        }
    }
}

// ── 9. Stepper step state logic ───────────────────────────────────────────────
$phaseOrder = ['start', 'in_progress', 'end', 'closed'];
$currentIdx = array_search($session['phase'], $phaseOrder, true);
if ($currentIdx === false) $currentIdx = 0;

function _ss_step_state(string $phaseKey, int $currentIdx, array $phaseOrder, string $sessionStatus): string
{
    $idx = array_search($phaseKey, $phaseOrder, true);
    if ($idx === false) return 'future';
    if ($sessionStatus === 'abandoned') {
        // Freeze stepper at wherever the session was abandoned.
        return $idx < $currentIdx ? 'done' : ($idx === $currentIdx ? 'active' : 'future');
    }
    if ($sessionStatus === 'closed') return 'done'; // all phases done
    return $idx < $currentIdx ? 'done' : ($idx === $currentIdx ? 'active' : 'future');
}

// ── 10. Audit rail: step_type → dot class + human label ──────────────────────
$stepTypeDotClass = [
    'phase_advanced'      => 'phase',
    'cip_attested'        => 'cip',
    'eligibility_attested'=> 'eligibility',
    'firewall_qc_passed'  => 'firewall',
    'handover'            => 'handover',
    'abandon'             => 'abandon',
    'note'                => 'note',
    'event_linked'        => 'event',
    'recap_acknowledged'  => 'recap',
];
$stepTypeLabel = [
    'phase_advanced'      => 'Phase avancée',
    'cip_attested'        => 'CIP attesté',
    'eligibility_attested'=> 'Lots validés',
    'firewall_qc_passed'  => 'QC pare-feu',
    'handover'            => 'Passage de main',
    'abandon'             => 'Session abandonnée',
    'note'                => 'Note',
    'event_linked'        => 'Événement lié',
    'recap_acknowledged'  => 'Récap. validé',
];

// Synthetic "session opened" entry prepended to steps.
$railEntries = array_merge(
    [['_synthetic' => true, 'step_type' => 'phase_advanced',
      'actor_username' => $openerUsername, 'acted_at' => $session['opened_at'],
      'payload' => null, '_label' => 'Session ouverte']],
    $steps
);

// ── 11. Load per-form-type partial into $formBody ─────────────────────────────
$formBody = '';
if (!$isTerminal) {
    $partialFile = __DIR__ . '/partials/session-body-' . $session['form_type'] . '.php';
    if (file_exists($partialFile)) {
        ob_start();
        // Make all context variables available to the partial.
        require $partialFile;
        $formBody = ob_get_clean();
    }
}

// ── 12. List operators for Handover dialog (server-rendered) ──────────────────
$availableOperators = [];
if (!$isTerminal) {
    $opStmt = $pdo->prepare(
        "SELECT id, username, display_name FROM users
          WHERE is_active = 1 AND id != ?
          ORDER BY username ASC"
    );
    $opStmt->execute([(int)$me['id']]);
    $availableOperators = $opStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 13. Terminal pill config ──────────────────────────────────────────────────
$terminalPill = _ss_terminal_pill($session['status'], $session['phase']);

// ── 14. CSRF + JS data ───────────────────────────────────────────────────────
$csrf = csrf_token();
$jsData = json_encode([
    'session_id'            => $sessionId,
    'phase'                 => $session['phase'],
    'status'                => $session['status'],
    'csrf'                  => $csrf,
    'is_terminal'           => $isTerminal,
    'next_phase'            => $nextPhase,
    'opener_id'             => (int)$session['opened_by_fk'],
    'me_id'                 => (int)$me['id'],
    'handover_dismissed_key'=> 'ss-handover-dismissed-' . $sessionId,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);

// ── Helper functions ──────────────────────────────────────────────────────────

/** Derive 1-2 char initials from a username. */
function _ss_initials(string $name): string
{
    $parts = preg_split('/[\s._-]+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

/** Build terminal pill config array. */
function _ss_terminal_pill(string $status, string $phase): array
{
    if ($status === 'abandoned') return ['cls' => 'ss-terminal-pill--abandoned', 'text' => '✕ Abandonnée'];
    if ($status === 'closed')   return ['cls' => 'ss-terminal-pill--closed',   'text' => '✓ Clôturée'];
    $phaseLabels = ['start' => '● Démarrage', 'in_progress' => '● En cours', 'end' => '● Fin', 'closed' => '✓ Clôturée'];
    return ['cls' => 'ss-terminal-pill--open', 'text' => $phaseLabels[$phase] ?? '● En cours'];
}

// ─── RENDER ───────────────────────────────────────────────────────────────────
$cssCacheV  = filemtime(__DIR__ . '/../css/session-shell.css') ?: time();
$appCssV    = filemtime(__DIR__ . '/../css/app.css') ?: time();
$jsCacheV   = filemtime(__DIR__ . '/../js/session-framework.js') ?: time();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($labels['session_ref']) ?> — MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= $appCssV ?>">
<link rel="stylesheet" href="/css/session-shell.css?v=<?= $cssCacheV ?>">
</head>

<body class="session-shell home">

<div class="ss-page">

  <!-- ══════════════════════════════════════════════════════════
       SESSION HEADER
  ═══════════════════════════════════════════════════════════════ -->
  <header class="ss-header">
    <a href="/modules/sessions-list.php" class="ss-header__back">
      <svg class="ss-icon" viewBox="0 0 14 12" fill="none"><path d="M6 1L1 6m0 0l5 5M1 6h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Journal de bord
    </a>
    <div class="ss-header__sep"></div>

    <div class="ss-header__identity">
      <div class="ss-header__type-badge">
        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><circle cx="5" cy="5" r="4" stroke="currentColor" stroke-width="1.2"/><path d="M3 5h4M5 3v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
        <?= htmlspecialchars($labels['form_type']) ?><?= $labels['vessel'] ? ' · ' . htmlspecialchars($labels['vessel']) : '' ?>
      </div>
      <div class="ss-header__title">
        <?php if ($labels['recipe']): ?>
          <em><?= htmlspecialchars($labels['recipe']) ?></em> · Lot <?= htmlspecialchars($labels['batch']) ?>
        <?php else: ?>
          <?= htmlspecialchars($labels['batch']) ?>
        <?php endif; ?>
      </div>
      <div class="ss-header__sub">
        Session <?= htmlspecialchars($labels['session_ref']) ?>
        · Ouvert <?= htmlspecialchars($openerDisplayTime) ?> par <?= htmlspecialchars($openerUsername) ?>
        <?php if ($showHandoverBanner && $currentActorUsername !== $openerUsername): ?>
          · Repris par <?= htmlspecialchars($currentActorUsername) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="ss-header__right">
      <?php if (!$isTerminal): ?>
      <div class="ss-elapsed">
        <div class="ss-live-dot"></div>
        <span><?= htmlspecialchars($labels['elapsed']) ?></span>
      </div>
      <?php else: ?>
      <div class="ss-elapsed">
        <span><?= htmlspecialchars($labels['elapsed']) ?></span>
      </div>
      <?php endif; ?>

      <!-- Operator chips: opener + current actor (when different) -->
      <div class="ss-op-chips">
        <div class="ss-op-chip" title="<?= htmlspecialchars($openerUsername) ?> — a ouvert la session">
          <div class="ss-op-av"><?= htmlspecialchars($openerInitials) ?></div>
          <div class="ss-op-chip__info">
            <span class="ss-op-chip__name"><?= htmlspecialchars($openerUsername) ?></span>
            <span class="ss-op-chip__role">Ouvert <?= htmlspecialchars($openerDisplayTime) ?></span>
          </div>
        </div>
        <?php if ($currentActorUsername !== $openerUsername): ?>
        <div class="ss-op-chip" title="<?= htmlspecialchars($currentActorUsername) ?> — acteur courant">
          <div class="ss-op-av ss-op-av--active"><?= htmlspecialchars($currentActorInitials) ?></div>
          <div class="ss-op-chip__info">
            <span class="ss-op-chip__name"><?= htmlspecialchars($currentActorUsername) ?></span>
            <span class="ss-op-chip__role">Actif · <?= htmlspecialchars($currentActorTime) ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <a href="/modules/sessions-list.php" class="ss-header__journal">Journal ↗</a>
    </div>
  </header>

  <!-- ══════════════════════════════════════════════════════════
       PHASE STEPPER — strict forward-only, never clickable
  ═══════════════════════════════════════════════════════════════ -->
  <nav class="ss-stepper" aria-label="Phases de la session">
    <?php
    $stepDefs = [
      ['key' => 'start',       'num' => '1', 'label' => 'Démarrage',   'default_sub' => 'Pare-feu QC · attestation CIP'],
      ['key' => 'in_progress', 'num' => '2', 'label' => 'En cours',    'default_sub' => 'Capture opérationnelle'],
      ['key' => 'end',         'num' => '3', 'label' => 'Fin',         'default_sub' => 'Récapitulatif + fermeture'],
    ];
    foreach ($stepDefs as $sd):
      $state = _ss_step_state($sd['key'], $currentIdx, $phaseOrder, $session['status']);
      $ariaSelected = $state === 'active' ? 'true' : 'false';
      $ariaDisabled = $state === 'future' ? 'true' : 'false';
      // _SUB_LABEL_HTML invariant: $subLabelHtml carries pre-encoded HTML.
      // Both branches must produce escaped output (see $phaseStepSubLabelsHtml
      // builder above + htmlspecialchars() on the default). Adding a new branch?
      // It MUST also produce escaped HTML, or the raw echo below becomes XSS.
      $subLabelHtml = $state === 'done' && isset($phaseStepSubLabelsHtml[$sd['key']])
        ? 'Pare-feu validé par ' . $phaseStepSubLabelsHtml[$sd['key']]
        : htmlspecialchars($sd['default_sub']);
    ?>
    <div class="ss-step ss-step--<?= $state ?>"
         role="tab"
         aria-selected="<?= $ariaSelected ?>"
         aria-disabled="<?= $ariaDisabled ?>"
         aria-label="<?= htmlspecialchars($sd['label']) ?> — <?= $state === 'done' ? 'terminé' : ($state === 'active' ? 'phase active' : 'verrouillé') ?>">
      <div class="ss-step__circle">
        <?php if ($state === 'done'): ?>✓<?php else: echo $sd['num']; endif; ?>
      </div>
      <div class="ss-step__info">
        <span class="ss-step__label"><?= htmlspecialchars($sd['label']) ?></span>
        <span class="ss-step__sub"><?= $subLabelHtml /* pre-encoded HTML; see _SUB_LABEL_HTML invariant */ ?></span>
      </div>
      <?php if ($state === 'future'): ?>
        <svg class="ss-icon" style="color:var(--ink-faint);margin-left:auto" viewBox="0 0 12 14" fill="none"><rect x="1" y="6" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 6V4a2 2 0 014 0v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Terminal status pill -->
    <div class="ss-step ss-step--terminal">
      <div class="<?= htmlspecialchars($terminalPill['cls']) ?>"><?= htmlspecialchars($terminalPill['text']) ?></div>
    </div>
  </nav>

  <!-- ══════════════════════════════════════════════════════════
       HANDOVER BANNER
  ═══════════════════════════════════════════════════════════════ -->
  <?php if ($showHandoverBanner): ?>
  <div class="ss-handover-banner" id="ss-handover-banner">
    <div class="ss-handover-banner__icon">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 8h5m0 0L5 6m2 2L5 10M14 8H9m0 0l2-2M9 8l2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <div class="ss-handover-banner__text">
      <strong>Reprise de session.</strong>
      Cette session a été ouverte par <strong><?= htmlspecialchars($openerUsername) ?></strong> à <?= htmlspecialchars($openerDisplayTime) ?>.
      <?php if ($lastHandoverFrom): ?>
        Transmission depuis <?= htmlspecialchars($lastHandoverFrom) ?>.
      <?php endif; ?>
      Vous êtes maintenant l'opérateur actif.
    </div>
    <button class="ss-handover-banner__dismiss" data-dismiss-banner aria-label="Fermer">✕</button>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       AUDIT FLAGS WARNING (PQ-6 — multi-active session on same vessel)
  ═══════════════════════════════════════════════════════════════ -->
  <?php if (!empty($session['audit_flags'])): ?>
  <?php $flags = json_decode((string)$session['audit_flags'], true); ?>
  <?php if (!empty($flags['multi_active_vessel_warn'])): ?>
  <div style="padding:8px 24px;background:color-mix(in srgb,var(--ember) 8%,var(--bg));border-bottom:1px solid color-mix(in srgb,var(--ember) 22%,transparent)">
    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.06em;color:var(--ember)">
      ⚠ <?= htmlspecialchars((string)($flags['multi_active_vessel_warn']['message'] ?? '')) ?>
    </span>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       MAIN LAYOUT — body column + audit rail
  ═══════════════════════════════════════════════════════════════ -->
  <div class="ss-layout">

    <!-- ── LEFT: Phase body ───────────────────────────────────── -->
    <div class="ss-body">

      <?php if ($session['status'] === 'abandoned'): ?>
      <!-- ═════════ TERMINAL: abandoned ═════════ -->
      <div class="ss-terminal-banner ss-terminal-banner--abandoned">
        <div class="ss-terminal-banner__icon">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M11 7v5M11 15v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="11" cy="11" r="9" stroke="currentColor" stroke-width="1.5"/></svg>
        </div>
        <div class="ss-terminal-banner__body">
          <div class="ss-terminal-banner__heading">Session abandonnée</div>
          <div class="ss-terminal-banner__meta">
            Abandonnée par <?= htmlspecialchars($session['closed_by_username'] ?? '—') ?>
            <?php if ($session['closed_at']): ?>· <?= date('H:i', strtotime((string)$session['closed_at'])) ?><?php endif; ?>
            · Phase : <?= htmlspecialchars($session['phase']) ?>
          </div>
          <?php if ($session['abandon_reason']): ?>
          <div class="ss-terminal-banner__reason">
            Raison : <?= htmlspecialchars((string)$session['abandon_reason']) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="ss-body__phase-label">Historique — Lecture seule</div>
      <div class="ss-body__content">
        <p style="font-size:13px;color:var(--ink-mute);line-height:1.6">
          Les événements liés à cette session sont conservés. Aucune modification n'est possible.
        </p>
        <p style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.1em;color:var(--ember);text-align:center;padding:24px">
          — SESSION ABANDONNÉE — AUCUNE REPRISE POSSIBLE —
        </p>
      </div>

      <?php elseif ($session['status'] === 'closed'): ?>
      <!-- ═════════ TERMINAL: closed ═════════ -->
      <div class="ss-terminal-banner ss-terminal-banner--closed">
        <div class="ss-terminal-banner__icon">
          <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 11.5L9.5 14.5L15.5 8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="ss-terminal-banner__body">
          <div class="ss-terminal-banner__heading">Session clôturée avec succès</div>
          <div class="ss-terminal-banner__meta">
            Clôturée par <?= htmlspecialchars($session['closed_by_username'] ?? '—') ?>
            <?php if ($session['closed_at']): ?>· <?= date('H:i', strtotime((string)$session['closed_at'])) ?><?php endif; ?>
            · Durée totale : <?= htmlspecialchars($labels['elapsed']) ?>
          </div>
        </div>
      </div>

      <div class="ss-body__phase-label">Récapitulatif — Lecture seule</div>
      <div class="ss-body__content">
        <p style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.1em;color:var(--ink-mute);text-align:center;padding:24px">
          — SESSION CLÔTURÉE — AUCUNE MODIFICATION POSSIBLE —
        </p>
      </div>

      <?php elseif ($session['phase'] === 'start'): ?>
      <!-- ═════════ ACTIVE: start ═════════ -->
      <div class="ss-body__phase-label">Démarrage — Pare-feu QC</div>
      <div class="ss-body__content">

        <!-- QC Firewall checklist -->
        <div class="ss-firewall">
          <div class="ss-firewall__head">
            <span class="ss-firewall__title">Pare-feu qualité</span>
            <div class="ss-firewall__progress">
              <?php $doneCount = (int)$firewall['cip_done'] + (int)$firewall['eligibility_done'] + (int)$firewall['qc_done']; ?>
              <span class="ss-firewall__count"><?= $doneCount ?> / 3</span>
              <div class="ss-firewall__bar-wrap">
                <div class="ss-firewall__bar-fill" style="width:<?= round($doneCount / 3 * 100) ?>%"></div>
              </div>
            </div>
          </div>

          <!-- CIP attestation -->
          <div class="ss-fw-item">
            <div class="ss-fw-check<?= $firewall['cip_done'] ? ' ss-fw-check--done' : '' ?>">
              <?php if ($firewall['cip_done']): ?>
                <svg width="11" height="9" viewBox="0 0 11 9" fill="none"><path d="M1 4.5L4 7.5L10 1.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <?php endif; ?>
            </div>
            <div class="ss-fw-content">
              <span class="ss-fw-label<?= $firewall['cip_done'] ? ' ss-fw-label--done' : '' ?>">CIP attesté<?= $labels['vessel'] ? ' — ' . htmlspecialchars($labels['vessel']) . ' propre' : '' ?></span>
              <span class="ss-fw-desc">Nettoyage en place du tank de destination confirmé par l'opérateur.</span>
              <?php if ($firewall['cip_done']):
                // Find the cip_attested step for actor + time.
                foreach ($steps as $s) { if ($s['step_type'] === 'cip_attested') { $cipStep = $s; break; } }
                $cipActor = isset($cipStep) ? htmlspecialchars($cipStep['actor_username'] ?? '') : '';
                $cipTime  = isset($cipStep) && $cipStep['acted_at'] ? date('H:i', strtotime((string)$cipStep['acted_at'])) : '';
              ?>
              <span class="ss-fw-attested">✓ Validé par <?= $cipActor ?> · <?= $cipTime ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Eligibility attestation -->
          <div class="ss-fw-item">
            <div class="ss-fw-check<?= $firewall['eligibility_done'] ? ' ss-fw-check--done' : '' ?>">
              <?php if ($firewall['eligibility_done']): ?>
                <svg width="11" height="9" viewBox="0 0 11 9" fill="none"><path d="M1 4.5L4 7.5L10 1.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <?php endif; ?>
            </div>
            <div class="ss-fw-content">
              <span class="ss-fw-label<?= $firewall['eligibility_done'] ? ' ss-fw-label--done' : '' ?>">Lots éligibles sélectionnés</span>
              <span class="ss-fw-desc">Les lots sources ont été confirmés éligibles (cold crash atteint).</span>
              <?php if ($firewall['eligibility_done']):
                foreach ($steps as $s) { if ($s['step_type'] === 'eligibility_attested') { $elStep = $s; break; } }
                $elActor = isset($elStep) ? htmlspecialchars($elStep['actor_username'] ?? '') : '';
                $elTime  = isset($elStep) && $elStep['acted_at'] ? date('H:i', strtotime((string)$elStep['acted_at'])) : '';
              ?>
              <span class="ss-fw-attested">✓ Validé par <?= $elActor ?> · <?= $elTime ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- QC readings attestation -->
          <div class="ss-fw-item">
            <div class="ss-fw-check<?= $firewall['qc_done'] ? ' ss-fw-check--done' : '' ?>">
              <?php if ($firewall['qc_done']): ?>
                <svg width="11" height="9" viewBox="0 0 11 9" fill="none"><path d="M1 4.5L4 7.5L10 1.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <?php endif; ?>
            </div>
            <div class="ss-fw-content">
              <span class="ss-fw-label<?= $firewall['qc_done'] ? ' ss-fw-label--done' : '' ?>">Lectures QC pré-opération</span>
              <span class="ss-fw-desc">Gravité finale, pH, turbidité dans les seuils attendus.</span>
              <?php if ($firewall['qc_done']):
                foreach ($steps as $s) { if ($s['step_type'] === 'firewall_qc_passed') { $qcStep = $s; break; } }
                $qcActor = isset($qcStep) ? htmlspecialchars($qcStep['actor_username'] ?? '') : '';
                $qcTime  = isset($qcStep) && $qcStep['acted_at'] ? date('H:i', strtotime((string)$qcStep['acted_at'])) : '';
              ?>
              <span class="ss-fw-attested">✓ Validé par <?= $qcActor ?> · <?= $qcTime ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="ss-firewall__action">
            <?php if ($firewall['all_clear']): ?>
              <span style="font-family:'JetBrains Mono',monospace;font-size:9.5px;color:var(--ok);letter-spacing:0.06em;display:flex;align-items:center;gap:6px">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="5" stroke="currentColor" stroke-width="1.3"/><path d="M3.5 6.5L5 8L8.5 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Pare-feu complet — prêt à avancer
              </span>
            <?php else: ?>
              <div class="ss-blocked-msg">
                <span class="ss-blocked-msg__icon">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M6.5 4v3.5M6.5 9v.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                </span>
                <span class="ss-blocked-msg__text">
                  En attente : <?= htmlspecialchars(implode(', ', $firewall['blocking_items'])) ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- FORM BODY SLOT -->
        <?php if ($formBody !== ''): ?>
          <?= $formBody ?>
        <?php else: ?>
          <div class="ss-slot-placeholder">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="color:var(--ink-faint)"><rect x="4" y="4" width="20" height="20" rx="2" stroke="currentColor" stroke-width="1.4" stroke-dasharray="3 2"/><path d="M10 14h8M14 10v8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            <span class="ss-slot-placeholder__label">Formulaire non disponible</span>
            <span class="ss-slot-placeholder__hint">Le formulaire de saisie pour <?= htmlspecialchars($labels['form_type']) ?> sera injecté ici via <code>$formBody</code>.</span>
          </div>
        <?php endif; ?>

      </div><!-- /.ss-body__content -->

      <?php elseif ($session['phase'] === 'in_progress'): ?>
      <!-- ═════════ ACTIVE: in_progress ═════════ -->
      <div class="ss-body__phase-label">En cours — Capture opérationnelle</div>
      <div class="ss-body__content">

        <!-- FORM BODY SLOT -->
        <?php if ($formBody !== ''): ?>
          <?= $formBody ?>
        <?php else: ?>
          <div class="ss-slot-placeholder" style="min-height:320px">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="color:var(--ink-faint)"><rect x="4" y="4" width="20" height="20" rx="2" stroke="currentColor" stroke-width="1.4" stroke-dasharray="3 2"/><path d="M10 14h8M14 10v8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            <span class="ss-slot-placeholder__label">Formulaire non disponible</span>
            <span class="ss-slot-placeholder__hint">Surface de capture <?= htmlspecialchars($labels['form_type']) ?> — injectée via <code>$formBody</code>.</span>
          </div>
        <?php endif; ?>

      </div>

      <?php else: /* phase = end */ ?>
      <!-- ═════════ ACTIVE: end ═════════ -->
      <div class="ss-body__phase-label">Fin — Récapitulatif &amp; fermeture</div>
      <div class="ss-body__content">

        <!-- FORM BODY SLOT -->
        <?php if ($formBody !== ''): ?>
          <?= $formBody ?>
        <?php else: ?>
          <div class="ss-slot-placeholder">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none" style="color:var(--ink-faint)"><rect x="4" y="4" width="20" height="20" rx="2" stroke="currentColor" stroke-width="1.4" stroke-dasharray="3 2"/></svg>
            <span class="ss-slot-placeholder__label">Récapitulatif non disponible</span>
            <span class="ss-slot-placeholder__hint">Lectures finales + notes de clôture, injectées via <code>$formBody</code>.</span>
          </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>

    </div><!-- /.ss-body -->

    <!-- ── RIGHT: Audit rail ────────────────────────────────────── -->
    <aside class="ss-rail" aria-label="Journal des actions">
      <div class="ss-rail__head">
        <span class="ss-rail__title">Activité</span>
        <span class="ss-rail__count"><?= count($railEntries) ?> entrée<?= count($railEntries) !== 1 ? 's' : '' ?></span>
      </div>

      <button class="ss-rail__toggle"
              data-rail-toggle="ss-rail-entries"
              aria-expanded="true"
              aria-label="Afficher/masquer le journal">
        Journal ▾
      </button>

      <div class="ss-rail__entries" id="ss-rail-entries">
        <?php foreach ($railEntries as $i => $entry):
          $isLast    = ($i === count($railEntries) - 1);
          $stepType  = $entry['step_type'] ?? 'phase_advanced';
          $dotClass  = 'ss-rail__dot--' . ($stepTypeDotClass[$stepType] ?? 'event');
          $typeLabel = $entry['_label'] ?? ($stepTypeLabel[$stepType] ?? $stepType);
          $actor     = $entry['actor_username'] ?? '';
          $actedAt   = $entry['acted_at'] ?? null;
          $timeStr   = $actedAt ? date('H:i', strtotime((string)$actedAt)) : '';

          // Extract a payload snippet.
          $payloadSnippet = '';
          if (!empty($entry['payload'])) {
            $pl = json_decode((string)$entry['payload'], true);
            if (is_array($pl)) {
              if (isset($pl['to']) && isset($pl['from'])) {
                $payloadSnippet = htmlspecialchars($pl['from']) . ' → ' . htmlspecialchars($pl['to']);
              } elseif (isset($pl['text'])) {
                $payloadSnippet = htmlspecialchars(mb_substr((string)$pl['text'], 0, 60));
              } elseif (isset($pl['reason'])) {
                $payloadSnippet = htmlspecialchars(mb_substr((string)$pl['reason'], 0, 60));
              } elseif (isset($pl['to_user_fk'])) {
                $payloadSnippet = 'Vers utilisateur #' . (int)$pl['to_user_fk'];
              }
            }
          }
        ?>
        <div class="ss-rail__entry">
          <div class="ss-rail__dot-col">
            <div class="ss-rail__dot <?= $dotClass ?>"></div>
            <?php if (!$isLast): ?>
            <div class="ss-rail__connector"></div>
            <?php endif; ?>
          </div>
          <div class="ss-rail__entry-body">
            <span class="ss-rail__entry-type"><?= htmlspecialchars($typeLabel) ?></span>
            <span class="ss-rail__entry-actor"><?= htmlspecialchars($actor) ?></span>
            <span class="ss-rail__entry-time"><?= htmlspecialchars($timeStr) ?></span>
            <?php if ($payloadSnippet): ?>
            <span class="ss-rail__entry-payload"><?= $payloadSnippet ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </aside>

  </div><!-- /.ss-layout -->

  <!-- ══════════════════════════════════════════════════════════
       STICKY LIFECYCLE FOOTER — not shown for terminal sessions
  ═══════════════════════════════════════════════════════════════ -->
  <?php if (!$isTerminal): ?>
  <footer class="ss-footer">
    <!-- Primary action: Avancer -->
    <?php
    $advanceLabels = [
      'start'       => 'Démarrer la session',
      'in_progress' => 'Passer à la clôture',
      'end'         => 'Clôturer la session',
    ];
    $advanceLabel = $advanceLabels[$session['phase']] ?? 'Avancer';
    ?>
    <button class="ss-btn-advance" id="ss-btn-advance" <?= !$advanceEnabled ? 'disabled' : '' ?>>
      <svg class="ss-icon" viewBox="0 0 14 12" fill="none"><path d="M8 1L13 6m0 0L8 11M13 6H1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <?= htmlspecialchars($advanceLabel) ?>
    </button>

    <!-- Blocked indicator (shows when advance is disabled and reasons exist) -->
    <?php if (!$advanceEnabled && !empty($firewall['blocking_items'])): ?>
    <div class="ss-advance-blocked">
      <div class="ss-advance-blocked__dot"></div>
      <span><?= htmlspecialchars(implode(' · ', $firewall['blocking_items'])) ?></span>
    </div>
    <?php endif; ?>

    <div class="ss-footer__sep"></div>

    <!-- Passer la main -->
    <button class="ss-btn-ghost" id="ss-btn-handover" title="Transférer la session à un autre opérateur">
      <svg class="ss-icon" viewBox="0 0 14 12" fill="none"><path d="M2 6h4m0 0L4 4m2 2L4 8M12 6H8m0 0l2-2M8 6l2 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Passer la main
    </button>

    <!-- Ajouter une note -->
    <button class="ss-btn-ghost" id="ss-btn-note" title="Ajouter une note au journal">
      <svg class="ss-icon" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M4 5h6M4 8h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
      Note
    </button>

    <div class="ss-footer__right">
      <button class="ss-btn-ghost ss-btn-ghost--danger" id="ss-btn-abandon">
        <svg class="ss-icon" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        Abandonner
      </button>
    </div>
  </footer>

  <!-- ═════════ DIALOG: Abandonner ═════════ -->
  <dialog class="ss-dialog" id="ss-dlg-abandon">
    <div class="ss-dialog__head">
      <span class="ss-dialog__title ss-dialog__title--danger">Abandonner la session</span>
      <button class="ss-dialog__close" data-dlg-close aria-label="Fermer">✕</button>
    </div>
    <div class="ss-dialog__body">
      <label class="ss-dialog__label" for="ss-abandon-reason">
        Raison de l'abandon — obligatoire, visible dans le journal de bord
      </label>
      <textarea class="ss-dialog__textarea ss-dialog__textarea--danger"
                id="ss-abandon-reason"
                data-abandon-reason
                placeholder="Ex : fuite sur vanne, panne matériel, priorité rebrassage…"
                rows="4"></textarea>
    </div>
    <div class="ss-dialog__foot">
      <button class="ss-btn-ghost" data-dlg-close>Annuler</button>
      <button class="ss-btn-confirm ss-btn-confirm--danger" data-confirm-abandon>Confirmer l'abandon</button>
    </div>
  </dialog>

  <!-- ═════════ DIALOG: Passer la main ═════════ -->
  <dialog class="ss-dialog" id="ss-dlg-handover">
    <div class="ss-dialog__head">
      <span class="ss-dialog__title">Passer la main</span>
      <button class="ss-dialog__close" data-dlg-close aria-label="Fermer">✕</button>
    </div>
    <div class="ss-dialog__body">
      <label class="ss-dialog__label" for="ss-handover-user">
        Transférer à :
      </label>
      <select class="ss-dialog__select" id="ss-handover-user" data-handover-user>
        <option value="">— Choisir un opérateur —</option>
        <?php foreach ($availableOperators as $op): ?>
        <option value="<?= (int)$op['id'] ?>">
          <?= htmlspecialchars($op['display_name'] ?: $op['username']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <label class="ss-dialog__label" for="ss-handover-note" style="margin-top:12px">
        Note de passation (optionnel)
      </label>
      <textarea class="ss-dialog__textarea" id="ss-handover-note" data-handover-note
                placeholder="Ex : 2 lectures soutirage OK, vanne 3 ouverte à 40 %…" rows="3"></textarea>
    </div>
    <div class="ss-dialog__foot">
      <button class="ss-btn-ghost" data-dlg-close>Annuler</button>
      <button class="ss-btn-confirm" data-confirm-handover>Confirmer le transfert</button>
    </div>
  </dialog>

  <!-- ═════════ DIALOG: Ajouter une note ═════════ -->
  <dialog class="ss-dialog" id="ss-dlg-note">
    <div class="ss-dialog__head">
      <span class="ss-dialog__title">Ajouter une note</span>
      <button class="ss-dialog__close" data-dlg-close aria-label="Fermer">✕</button>
    </div>
    <div class="ss-dialog__body">
      <label class="ss-dialog__label" for="ss-note-text">
        Note — visible dans le journal de bord
      </label>
      <textarea class="ss-dialog__textarea" id="ss-note-text" data-note-text
                placeholder="Observation, point d'attention, mesure…" rows="4"></textarea>
    </div>
    <div class="ss-dialog__foot">
      <button class="ss-btn-ghost" data-dlg-close>Annuler</button>
      <button class="ss-btn-confirm" data-confirm-note>Enregistrer la note</button>
    </div>
  </dialog>

  <?php endif; /* !$isTerminal */ ?>

  <!-- Toast notification -->
  <div class="ss-toast" id="ss-toast" role="alert" aria-live="assertive"></div>

</div><!-- /.ss-page -->

<!-- JS data surface -->
<script>window.SS_DATA = <?= $jsData ?>;</script>
<script src="/js/session-framework.js?v=<?= $jsCacheV ?>"></script>

</body>
</html>
