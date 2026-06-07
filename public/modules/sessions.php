<?php
declare(strict_types=1);
/**
 * sessions.php — Journal de bord operations cockpit.
 *
 * The central dashboard for all op_sessions activity spanning all 4 form
 * types (racking / fermenting / brewing / packaging).
 *
 * Two views (toggled client-side, persisted in localStorage):
 *   Direction C (PRIMARY) — chronological timeline feed of recent sessions,
 *     grouped by day, each card = (timestamp, actor, vessel, form_type,
 *     phase pill, last 3 steps preview, link → session-shell).
 *   Direction B (SECONDARY) — vessel-grouped card grid.
 *
 * URL: /modules/sessions.php
 *   Optional GET params (server-side filtering):
 *     form_type   — one of racking|fermenting|brewing|packaging (or '')
 *     vessel_kind — one of cct|bbt|yt|fermenter|brewhouse|machine (or '')
 *     status      — open|closed|abandoned (or '' for default = open+closed last 7 days)
 *     date_from   — YYYY-MM-DD
 *     date_to     — YYYY-MM-DD
 *     show_abandoned — 1 to include abandoned rows in the result (default 0)
 *     page        — positive integer, default 1
 *     view        — timeline|vessels (client persists via localStorage, but also
 *                   accepted as GET param for direct links)
 *
 * Session linking:
 *   Every session card links to /modules/session-shell.php?id={session_id}.
 *   The breadcrumb in session-shell.php links back here (both link targets
 *   fixed to /modules/sessions.php — previously pointing to sessions-list.php).
 *
 * Reuse anchors called (DO NOT FORK):
 *   app/sessions.php  — sessions_recent(), session_labels(), SESSION_FORM_TYPES,
 *                        SESSION_VESSEL_KINDS, SESSION_STATUSES, SESSION_STEP_TYPES
 *   app/auth.php      — require_login(), current_user()
 *   app/db.php        — maltytask_pdo()
 *   app/partials/sidebar.php, topbar.php — standard nav shell
 *
 * N+1 guard:
 *   After sessions_recent() returns up to $PAGE_SIZE session rows, ONE batched
 *   query fetches the last K steps for all session IDs in one IN(…) round-trip
 *   (see sessions_last_steps_batch below).
 *
 * Performance:
 *   Compute-on-read. No cache tables. Default LIMIT 100 (paginated).
 */

require __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/sessions.php';

require_page_access('saisies');
$me  = current_user();
$pdo = maltytask_pdo();

// ─── Constants ────────────────────────────────────────────────────────────────

const SD_PAGE_SIZE   = 50;   // sessions per page
const SD_STEPS_SHOWN = 3;    // last K steps shown in timeline preview

// ─── Human label maps (no DB nomenclature in UI) ──────────────────────────────

$FORM_LABELS = [
    'racking'    => 'Soutirage',
    'fermenting' => 'Fermentation',
    'brewing'    => 'Brassage',
    'packaging'  => 'Conditionnement',
];
$PHASE_LABELS = [
    'start'       => 'Démarrage',
    'in_progress' => 'En cours',
    'end'         => 'Fin',
    'closed'      => 'Clôturé',
];
$STATUS_LABELS = [
    'open'      => 'Ouvert',
    'closed'    => 'Clôturé',
    'abandoned' => 'Abandonné',
];
$STEP_LABELS = [
    'firewall_qc_passed'   => 'QC validé',
    'cip_attested'         => 'CIP attesté',
    'eligibility_attested' => 'Lots validés',
    'phase_advanced'       => 'Phase avancée',
    'event_linked'         => 'Événement lié',
    'handover'             => 'Passation',
    'note'                 => 'Note',
    'abandon'              => 'Abandon',
    'recap_acknowledged'   => 'Récap signé',
];
$VESSEL_KIND_LABELS = [
    'cct'       => 'CCT',
    'bbt'       => 'BBT',
    'yt'        => 'YT',
    'fermenter' => 'Fermenteur',
    'brewhouse' => 'Brewhouse',
    'machine'   => 'Machine',
];

// ─── Read + validate GET params ───────────────────────────────────────────────

/** Safe GET string helper: read first, validate second (anti-pattern #9). */
function _sd_get_str(string $key, string $default = ''): string
{
    $raw = $_GET[$key] ?? $default;
    return is_string($raw) ? trim($raw) : $default;
}

function _sd_get_int(string $key, int $default = 1, int $min = 1, int $max = PHP_INT_MAX): int
{
    $raw = $_GET[$key] ?? '';
    if (!is_string($raw) || !ctype_digit($raw)) return $default;
    $v = (int)$raw;
    return max($min, min($max, $v));
}

$filterFormType   = _sd_get_str('form_type');
$filterVesselKind = _sd_get_str('vessel_kind');
$filterStatus     = _sd_get_str('status');
$filterDateFrom   = _sd_get_str('date_from');
$filterDateTo     = _sd_get_str('date_to');
$showAbandoned    = _sd_get_str('show_abandoned') === '1';
$currentPage      = _sd_get_int('page', 1, 1, 999);

// Whitelist validation — must happen AFTER reading the default.
if (!in_array($filterFormType,   array_merge([''], SESSION_FORM_TYPES),   true)) $filterFormType   = '';
if (!in_array($filterVesselKind, array_merge([''], SESSION_VESSEL_KINDS), true)) $filterVesselKind = '';
if (!in_array($filterStatus,     array_merge([''], SESSION_STATUSES),     true)) $filterStatus     = '';

// Validate dates — accept only YYYY-MM-DD format.
$filterDateFrom = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) ? $filterDateFrom : '';
$filterDateTo   = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo))   ? $filterDateTo   : '';

// Default date range: last 7 days when no filter specified.
$hasFilters = ($filterFormType !== '' || $filterVesselKind !== '' || $filterStatus !== ''
    || $filterDateFrom !== '' || $filterDateTo !== '');

if (!$hasFilters) {
    $filterDateFrom = date('Y-m-d', strtotime('-7 days'));
    $filterDateTo   = date('Y-m-d');
}

// ─── Build filter array for sessions_recent() ─────────────────────────────────

$filter = [];
if ($filterFormType   !== '') $filter['form_type']    = $filterFormType;
if ($filterVesselKind !== '') $filter['vessel_kind']  = $filterVesselKind;
if ($filterDateFrom   !== '') $filter['date_from']    = $filterDateFrom;
if ($filterDateTo     !== '') $filter['date_to']      = $filterDateTo;

// Status filter: when 'abandoned' not shown, restrict to open+closed by default.
// (Cannot express OR in sessions_recent's single-status filter without a new fn —
// we fetch without status filter then PHP-filter abandoned out; see N+1 note below.)
if ($filterStatus !== '') {
    $filter['status'] = $filterStatus;
    // When operator explicitly filters by status, honour it (including abandoned).
    if ($filterStatus === 'abandoned') $showAbandoned = true;
}

// Validated filter base — used by sd_filter_url() to compose pagination links
// from clean values (NEVER raw $_GET, otherwise junk reflects into URLs).
$filterBase = [
    'form_type'      => $filterFormType,
    'vessel_kind'    => $filterVesselKind,
    'status'         => $filterStatus,
    'date_from'      => $filterDateFrom,
    'date_to'        => $filterDateTo,
    'show_abandoned' => $showAbandoned ? '1' : '',
];

// Fetch enough rows to paginate.  sessions_recent() takes a flat limit.
// We fetch PAGE_SIZE+1 extra to know if a "next" page exists.
$fetchLimit  = SD_PAGE_SIZE * $currentPage + 1;
$allSessions = sessions_recent($pdo, $filter, $fetchLimit);

// PHP-filter abandoned rows when not requested (since sessions_recent can't OR statuses).
if (!$showAbandoned && $filterStatus === '') {
    $allSessions = array_values(array_filter(
        $allSessions,
        fn (array $s): bool => ($s['status'] ?? '') !== 'abandoned'
    ));
}

$totalFetched = count($allSessions);
$hasNextPage  = ($totalFetched > SD_PAGE_SIZE * $currentPage);

// Slice to the current page window.
$offset       = SD_PAGE_SIZE * ($currentPage - 1);
$sessions     = array_slice($allSessions, $offset, SD_PAGE_SIZE);

// ─── N+1 guard: batch-load last K steps for all session IDs ──────────────────

/**
 * Fetch the last $k steps for each session in $ids, in one batched query.
 * Returns: [ session_id => [ step_row, … ] ] (last $k, oldest-first within session).
 *
 * Why sessions_recent() is insufficient for steps:
 *   sessions_recent() returns the op_sessions envelope with no steps included.
 *   Loading steps per-session would be an N+1 query pattern. This one-shot
 *   batched loader replaces it cleanly.
 */
function sessions_last_steps_batch(PDO $pdo, array $ids, int $k = 3): array
{
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // Use a window function (MySQL 8 native) to rank steps within each session.
    $stmt = $pdo->prepare(
        "SELECT ss.id, ss.session_id_fk, ss.phase, ss.step_type,
                ss.actor_user_id_fk, ss.acted_at, ss.payload,
                u.username AS actor_username
           FROM (
             SELECT oss.*,
                    ROW_NUMBER() OVER (
                      PARTITION BY oss.session_id_fk
                      ORDER BY oss.acted_at DESC, oss.id DESC
                    ) AS rn
               FROM op_session_steps oss
              WHERE oss.session_id_fk IN ({$placeholders})
           ) ss
           JOIN users u ON u.id = ss.actor_user_id_fk
          WHERE ss.rn <= ?
          ORDER BY ss.session_id_fk ASC, ss.acted_at ASC, ss.id ASC"
    );
    $params = array_merge(array_map('intval', $ids), [$k]);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $sid = (int)$row['session_id_fk'];
        $map[$sid][] = $row;
    }
    return $map;
}

$sessionIds = array_column($sessions, 'id');
$stepsMap   = sessions_last_steps_batch($pdo, $sessionIds, SD_STEPS_SHOWN);

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Escape for HTML output. */
function sd_esc(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a datetime string as HH:MM (24h). */
function sd_time(string $dt): string
{
    $ts = strtotime($dt);
    return ($ts !== false) ? date('H:i', $ts) : '—';
}

/** Format a datetime string as a French date "lun. 26 mai". */
function sd_date_fr(string $dt): string
{
    $ts = strtotime($dt);
    if ($ts === false) return '—';
    $days   = ['dim.','lun.','mar.','mer.','jeu.','ven.','sam.'];
    $months = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
    return $days[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1];
}

/** Return the date-only part (YYYY-MM-DD) for grouping. */
function sd_date_key(string $dt): string
{
    $ts = strtotime($dt);
    return ($ts !== false) ? date('Y-m-d', $ts) : '';
}

/** Build a label for a session — form_type + vessel (if any) + batch (if any).
 *  Returns RAW (unescaped) text; the single caller wraps in sd_esc() at output. */
function sd_session_title(array $s, array $formLabels, array $vesselKindLabels): string
{
    $parts = [];
    $parts[] = $formLabels[$s['form_type']] ?? $s['form_type'];
    if (!empty($s['vessel_kind']) && !empty($s['vessel_number'])) {
        $vk      = $vesselKindLabels[$s['vessel_kind']] ?? strtoupper($s['vessel_kind']);
        $parts[] = $vk . ' ' . $s['vessel_number'];
    }
    if (!empty($s['batch'])) {
        $parts[] = '#' . $s['batch'];
    }
    return implode(' — ', $parts);
}

/** Human elapsed from opened_at to closed_at (or now). */
function sd_elapsed(array $s): string
{
    $start = strtotime($s['opened_at'] ?? '');
    if ($start === false) return '';
    $end = !empty($s['closed_at']) ? (strtotime($s['closed_at']) ?: time()) : time();
    $sec = max(0, $end - $start);
    if ($sec < 60)   return $sec . ' sec';
    $min = (int)round($sec / 60);
    if ($min < 60)   return $min . ' min';
    $h = (int)floor($sec / 3600);
    $m = (int)round(($sec % 3600) / 60);
    if ($h < 24)     return $m > 0 ? $h . ' h ' . $m . ' min' : $h . ' h';
    $d  = (int)floor($h / 24);
    $hr = $h % 24;
    $dl = $d === 1 ? 'jour' : 'jours';
    return $hr > 0 ? $d . ' ' . $dl . ' ' . $hr . ' h' : $d . ' ' . $dl;
}

/** CSS class for a form_type. */
function sd_form_css(string $formType): string
{
    return sd_esc($formType); // already whitelisted to ENUM values
}

/** CSS class for a status. */
function sd_status_css(string $status): string
{
    return sd_esc($status);
}

/** CSS class for a phase pill. */
function sd_phase_css(string $phase): string
{
    return sd_esc(str_replace('_', '-', $phase));
}

// ─── Group sessions by day for timeline ───────────────────────────────────────

$sessionsByDay = [];
foreach ($sessions as $s) {
    $key = sd_date_key($s['opened_at'] ?? '');
    $sessionsByDay[$key][] = $s;
}

// ─── URL builder (preserves current filters) ──────────────────────────────────
// Reads VALIDATED filter variables (not raw $_GET) so pagination links never
// reflect attacker-crafted junk through the query string. Whitelist already
// applied above; this just composes a clean URL.

function sd_filter_url(array $overrides, array $filterBase): string
{
    $params = array_filter($filterBase, static fn($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = http_build_query($params);
    return '/modules/sessions.php' . ($qs !== '' ? '?' . $qs : '');
}

// ─── Page variables ───────────────────────────────────────────────────────────

$active_module = 'saisies'; // nearest nav entry while sessions don't have their own
$cssDashV  = @filemtime(__DIR__ . '/../css/sessions-dashboard.css') ?: time();
$cssShellV = @filemtime(__DIR__ . '/../css/session-shell.css') ?: time();
$cssAppV   = @filemtime(__DIR__ . '/../css/app.css') ?: time();
$jsDashV   = @filemtime(__DIR__ . '/../js/sessions-dashboard.js') ?: time();

$pageTitle = 'Journal de bord — MaltyTask';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= sd_esc($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssAppV ?>">
  <link rel="stylesheet" href="/css/session-shell.css?v=<?= $cssShellV ?>">
  <link rel="stylesheet" href="/css/sessions-dashboard.css?v=<?= $cssDashV ?>">
</head>
<body class="home sessions-dashboard">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">
<div class="sd-page">

  <!-- ════════════════════════════════════════════════════════════
       TOP STRIP — breadcrumb + view toggle + session count + clock
  ══════════════════════════════════════════════════════════════════ -->
  <div class="sd-topstrip">

    <h1 class="sd-breadcrumb">Journal <em>de bord</em></h1>

    <!-- View toggle (C ↔ B) -->
    <div class="sd-view-toggle" role="group" aria-label="Vue">
      <button id="sd-btn-timeline" class="sd-view-btn active"
              title="Vue chronologique">
        &#9776; Chronologique
      </button>
      <button id="sd-btn-vessels" class="sd-view-btn"
              title="Vue par cuves">
        &#9632; Cuves
      </button>
    </div>

    <!-- Session count -->
    <span class="sd-count-pill" id="sd-count">
      <?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?>
    </span>

    <!-- Live clock -->
    <span id="sd-clock" style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.06em;color:var(--ink-mute);margin-left:auto;"></span>

  </div>

  <!-- ════════════════════════════════════════════════════════════
       FILTERS
  ══════════════════════════════════════════════════════════════════ -->
  <form class="sd-filters" method="get" action="/modules/sessions.php">

    <!-- Form type -->
    <div class="sd-filter-group">
      <label class="sd-filter-label" for="sd-f-form">Type</label>
      <select id="sd-f-form" name="form_type">
        <option value="">Tous les types</option>
        <?php foreach ($FORM_LABELS as $v => $l): ?>
          <option value="<?= sd_esc($v) ?>"<?= $filterFormType === $v ? ' selected' : '' ?>>
            <?= sd_esc($l) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- Vessel kind -->
    <div class="sd-filter-group">
      <label class="sd-filter-label" for="sd-f-vessel">Cuve</label>
      <select id="sd-f-vessel" name="vessel_kind">
        <option value="">Toutes</option>
        <?php foreach ($VESSEL_KIND_LABELS as $v => $l): ?>
          <option value="<?= sd_esc($v) ?>"<?= $filterVesselKind === $v ? ' selected' : '' ?>>
            <?= sd_esc($l) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- Status -->
    <div class="sd-filter-group">
      <label class="sd-filter-label" for="sd-f-status">Statut</label>
      <select id="sd-f-status" name="status">
        <option value="">Ouvertes + clôturées</option>
        <?php foreach ($STATUS_LABELS as $v => $l): ?>
          <option value="<?= sd_esc($v) ?>"<?= $filterStatus === $v ? ' selected' : '' ?>>
            <?= sd_esc($l) ?>
          </option>
        <?php endforeach ?>
      </select>
    </div>

    <!-- Date from -->
    <div class="sd-filter-group">
      <label class="sd-filter-label" for="sd-f-from">Du</label>
      <input type="date" id="sd-f-from" name="date_from"
             value="<?= sd_esc($filterDateFrom) ?>" max="<?= date('Y-m-d') ?>">
    </div>

    <!-- Date to -->
    <div class="sd-filter-group">
      <label class="sd-filter-label" for="sd-f-to">Au</label>
      <input type="date" id="sd-f-to" name="date_to"
             value="<?= sd_esc($filterDateTo) ?>" max="<?= date('Y-m-d') ?>">
    </div>

    <!-- Apply + reset -->
    <div class="sd-filter-actions">
      <button type="submit" class="sd-btn-apply">Filtrer</button>
      <?php if ($hasFilters): ?>
        <a href="/modules/sessions.php" class="sd-btn-reset">Réinitialiser</a>
      <?php endif ?>
    </div>

    <!-- Abandoned toggle (right-aligned via margin-left:auto on CSS) -->
    <label class="sd-abandoned-toggle" title="Afficher aussi les sessions abandonnées">
      <input type="checkbox" id="sd-show-abandoned" name="show_abandoned"
             value="1"<?= $showAbandoned ? ' checked' : '' ?>>
      <span class="sd-abandoned-label">+ abandonnées</span>
    </label>

  </form>

  <!-- ════════════════════════════════════════════════════════════
       BODY
  ══════════════════════════════════════════════════════════════════ -->
  <div class="sd-body">

    <?php if (empty($sessions)): ?>
      <!-- Empty state -->
      <div class="sd-empty">
        <div class="sd-empty-icon">&#9998;</div>
        <div class="sd-empty-text">Aucune session trouvée</div>
        <div class="sd-empty-sub">Modifiez les filtres ou ouvrez une session depuis un formulaire de saisie</div>
      </div>

    <?php else: ?>

      <!-- ─────────────────────────────────────────────────────────
           PANEL C — TIMELINE (default, visible)
      ────────────────────────────────────────────────────────────── -->
      <div id="sd-panel-timeline" aria-label="Vue chronologique">
        <div class="sd-timeline">

          <?php
          $lastDayKey = '';
          foreach ($sessions as $s):
            $sid        = (int)$s['id'];
            $openedAt   = (string)($s['opened_at'] ?? '');
            $dayKey     = sd_date_key($openedAt);
            $status     = (string)($s['status']    ?? 'open');
            $phase      = (string)($s['phase']     ?? 'start');
            $formType   = (string)($s['form_type'] ?? '');
            $opener     = (string)($s['opened_by_username'] ?? '—');
            $steps      = $stepsMap[$sid] ?? [];
            $shellUrl   = '/modules/session-shell.php?id=' . $sid;

            // Title = form label + vessel + batch (no raw DB tokens in UI).
            // RAW concat — sd_esc() applies at output (line below); pre-escaping would double-encode.
            $titleParts = [$FORM_LABELS[$formType] ?? $formType];
            if (!empty($s['vessel_kind']) && !empty($s['vessel_number'])) {
                $vl = $VESSEL_KIND_LABELS[$s['vessel_kind']] ?? strtoupper($s['vessel_kind']);
                $titleParts[] = $vl . ' ' . $s['vessel_number'];
            }
            if (!empty($s['batch'])) {
                $titleParts[] = '#' . $s['batch'];
            }
            $titleStr = implode(' — ', $titleParts);
          ?>

            <?php if ($dayKey !== $lastDayKey): ?>
              <!-- Day divider -->
              <div class="sd-day">
                <span class="sd-day-label"><?= sd_esc(sd_date_fr($openedAt)) ?></span>
                <span class="sd-day-line"></span>
              </div>
              <?php $lastDayKey = $dayKey; ?>
            <?php endif ?>

            <!-- Timeline row -->
            <div class="sd-tl-row">
              <div class="sd-tl-time"><?= sd_esc(sd_time($openedAt)) ?></div>

              <div class="sd-tl-spine">
                <div class="sd-tl-dot <?= sd_status_css($status) ?>" title="<?= sd_esc($STATUS_LABELS[$status] ?? $status) ?>"></div>
                <div class="sd-tl-line"></div>
              </div>

              <div class="sd-tl-content">
                <a href="<?= sd_esc($shellUrl) ?>" class="sd-entry status-<?= sd_status_css($status) ?>"<?= $status === 'abandoned' ? ' hidden' : '' ?>>

                  <!-- Meta row -->
                  <div class="sd-entry-meta">
                    <span class="sd-form-chip <?= sd_form_css($formType) ?>">
                      <?= sd_esc($FORM_LABELS[$formType] ?? $formType) ?>
                    </span>
                    <span class="sd-phase-pill <?= sd_phase_css($phase) ?>">
                      <?= sd_esc($PHASE_LABELS[$phase] ?? $phase) ?>
                    </span>
                    <span class="sd-status-dot <?= sd_status_css($status) ?>"></span>
                    <?php if (!empty($steps)): ?>
                      <?php $lastStep = end($steps); ?>
                      <span class="sd-step-chip">
                        <?= sd_esc($STEP_LABELS[$lastStep['step_type'] ?? ''] ?? ($lastStep['step_type'] ?? '')) ?>
                      </span>
                    <?php endif ?>
                  </div>

                  <!-- Title -->
                  <div class="sd-entry-title">
                    <?= sd_esc($titleStr) ?>
                  </div>

                  <!-- Sub-line: opener · elapsed -->
                  <div class="sd-entry-sub">
                    <span><?= sd_esc($opener) ?></span>
                    <span class="sd-entry-sub-sep">·</span>
                    <span><?= sd_esc(sd_elapsed($s)) ?></span>
                    <?php if (!empty($s['recipe_id_fk'])): ?>
                      <span class="sd-entry-sub-sep">·</span>
                      <span>Recette #<?= (int)$s['recipe_id_fk'] ?></span>
                    <?php endif ?>
                  </div>

                  <!-- Last steps preview -->
                  <?php if (!empty($steps)): ?>
                    <div class="sd-steps-preview">
                      <?php foreach ($steps as $step): ?>
                        <div class="sd-step-row">
                          <span class="sd-step-time"><?= sd_esc(sd_time((string)($step['acted_at'] ?? ''))) ?></span>
                          <span class="sd-step-actor"><?= sd_esc((string)($step['actor_username'] ?? '—')) ?></span>
                          <span class="sd-step-chip"><?= sd_esc($STEP_LABELS[$step['step_type'] ?? ''] ?? ($step['step_type'] ?? '')) ?></span>
                        </div>
                      <?php endforeach ?>
                    </div>
                  <?php endif ?>

                </a>
              </div>
            </div>

          <?php endforeach ?>

        </div>
      </div><!-- #sd-panel-timeline -->

      <!-- ─────────────────────────────────────────────────────────
           PANEL B — VESSELS (hidden by default; JS toggles)
      ────────────────────────────────────────────────────────────── -->
      <div id="sd-panel-vessels" aria-label="Vue par cuves" hidden>
        <div class="sd-vessel-grid">

          <?php foreach ($sessions as $s): ?>
            <?php
            $sid      = (int)$s['id'];
            $status   = (string)($s['status']   ?? 'open');
            $phase    = (string)($s['phase']    ?? 'start');
            $formType = (string)($s['form_type']?? '');
            $opener   = (string)($s['opened_by_username'] ?? '—');
            $shellUrl = '/modules/session-shell.php?id=' . $sid;

            // Vessel label
            $vesselLabel = '—';
            if (!empty($s['vessel_kind']) && !empty($s['vessel_number'])) {
                $vl = $VESSEL_KIND_LABELS[$s['vessel_kind']] ?? strtoupper($s['vessel_kind']);
                $vesselLabel = $vl . ' ' . $s['vessel_number'];
            }
            ?>
            <a href="<?= sd_esc($shellUrl) ?>"
               class="sd-vessel-card <?= sd_status_css($status) ?>"
               <?= $status === 'abandoned' ? 'hidden' : '' ?>>

              <div class="sd-vc-head">
                <span class="sd-vc-vessel-badge<?= $vesselLabel === '—' ? ' no-vessel' : '' ?>">
                  <?= sd_esc($vesselLabel) ?>
                </span>
                <span class="sd-form-chip <?= sd_form_css($formType) ?>">
                  <?= sd_esc($FORM_LABELS[$formType] ?? $formType) ?>
                </span>
              </div>

              <div class="sd-vc-title">
                <?= sd_esc(sd_session_title($s, $FORM_LABELS, $VESSEL_KIND_LABELS)) ?>
              </div>

              <div class="sd-vc-sub">
                <span class="sd-phase-pill <?= sd_phase_css($phase) ?>">
                  <?= sd_esc($PHASE_LABELS[$phase] ?? $phase) ?>
                </span>
                <span class="sd-status-dot <?= sd_status_css($status) ?>"></span>
              </div>

              <div class="sd-vc-footer">
                <span class="sd-vc-operator"><?= sd_esc($opener) ?></span>
                <span class="sd-vc-elapsed"><?= sd_esc(sd_elapsed($s)) ?></span>
              </div>

            </a>
          <?php endforeach ?>

        </div>
      </div><!-- #sd-panel-vessels -->

      <!-- Pagination -->
      <div class="sd-pagination">
        <?php if ($currentPage > 1): ?>
          <a href="<?= sd_esc(sd_filter_url(['page' => $currentPage - 1], $filterBase)) ?>"
             class="sd-page-link">&#8592; Précédent</a>
        <?php else: ?>
          <span class="sd-page-link disabled">&#8592; Précédent</span>
        <?php endif ?>

        <span class="sd-page-info">Page <?= $currentPage ?></span>

        <?php if ($hasNextPage): ?>
          <a href="<?= sd_esc(sd_filter_url(['page' => $currentPage + 1], $filterBase)) ?>"
             class="sd-page-link">Suivant &#8594;</a>
        <?php else: ?>
          <span class="sd-page-link disabled">Suivant &#8594;</span>
        <?php endif ?>
      </div>

    <?php endif ?>

  </div><!-- .sd-body -->

</div><!-- .sd-page -->
</main>

<script src="/js/sessions-dashboard.js?v=<?= $jsDashV ?>"></script>
</body>
</html>
