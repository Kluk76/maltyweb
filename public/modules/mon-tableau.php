<?php
declare(strict_types=1);
/**
 * modules/mon-tableau.php — Tableau de bord personnel
 *
 * Shows the logged-in user's selected KPI trackers.
 * Selection is managed server-side: only data_ready=1 + is_active=1 trackers
 * whose min_role ≤ user role (and category gate for cogs_finance/sales) are
 * ever exposed or persisted.
 *
 * POST handler (selection write): CSRF → server-side re-validation of every
 * submitted tracker_id against the same allowed-set → upsert user_kpi_selections
 * → log_revision → PRG redirect.
 *
 * Auth: require_page_access('mon-tableau') — every authenticated user may access.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
require_once __DIR__ . '/../../app/kpi-handlers.php';

require_page_access('mon-tableau');
$me  = current_user();
$pdo = maltytask_pdo();

$myUserId = (int) $me['id'];
$myRole   = $me['role'] ?? 'viewer';

/* Email read from DB, not session — the auth_login() payload doesn't carry it,
   and an admin-added email must take effect without re-login. */
$meEmailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
$meEmailStmt->execute([$myUserId]);
$myEmail = $meEmailStmt->fetchColumn() ?: null;

/* ─────────────────────────────────────────────────────────────────────────────
   STUB-DOMAIN RECONCILIATION
   Domains that kpi_dispatch routes to kpi_stub_handler even though some
   trackers may carry data_ready=1. We detect this at build time so we can
   skip/flag those trackers in the UI.
   ─────────────────────────────────────────────────────────────────────────── */
/* Stub-domain list now lives in kpi-handlers.php (kpi_stub_domains()) next to
   the dispatch match it mirrors — a stale copy here silently hid the 21 new
   racking/packaging/tanks trackers from the picker (caught by smoke E2). */

/* ─────────────────────────────────────────────────────────────────────────────
   ALLOWED-SET BUILDER
   Server-side gate applied in BOTH the GET render and the POST handler.
   Returns: array of tracker rows keyed by id (int).
   Rules:
     1. data_ready=1 AND is_active=1
     2. min_role ≤ user role (role rank)
     3. category IN ('cogs_finance','sales') → manager+ only
   ─────────────────────────────────────────────────────────────────────────── */
function mt_build_allowed_set(PDO $pdo, string $userRole): array
{
    $rank = _role_rank($userRole);

    $stmt = $pdo->query(
        "SELECT id, slug, label, description, category, domain, source_domain,
                compute_handler, viz_type, min_role, params_json, sort
           FROM ref_kpi_trackers
          WHERE data_ready = 1 AND is_active = 1
          ORDER BY sort, id"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allowed = [];
    foreach ($rows as $row) {
        /* Role floor */
        if ($rank < _role_rank($row['min_role'])) continue;

        /* Finance/sales gate: manager+ only */
        if (in_array($row['category'], ['cogs_finance', 'sales'], true)
            && $rank < _role_rank('manager')) {
            continue;
        }

        $allowed[(int)$row['id']] = $row;
    }
    return $allowed;
}

/* Pre-compute stub mismatch list (data_ready=1 trackers with stubbed handlers).
   Flagged for admin notice — not rendered as real KPIs. */
function mt_stub_mismatches(array $allowedSet): array
{
    $mismatches = [];
    foreach ($allowedSet as $id => $row) {
        if (in_array($row['source_domain'], kpi_stub_domains(), true)) {
            $mismatches[] = $row['slug'] . ' (' . $row['source_domain'] . ')';
        }
    }
    return $mismatches;
}

/* ─────────────────────────────────────────────────────────────────────────────
   POST HANDLER — selection write
   ─────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF first — always before any other processing */
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/mon-tableau.php');
    }

    $action = post_str('action') ?? '';

    if ($action === 'update_selection') {
        /* Rebuild the allowed set server-side — identical logic to GET path */
        $allowedSet = mt_build_allowed_set($pdo, $myRole);

        /* submitted tracker IDs (may be absent if none selected) */
        $rawIds  = $_POST['tracker_ids'] ?? [];
        if (!is_array($rawIds)) $rawIds = [];

        /* Sanitize: keep only ints that exist in the allowed set */
        $validIds = [];
        foreach ($rawIds as $raw) {
            $id = (int) $raw;
            if ($id > 0 && isset($allowedSet[$id])) {
                /* Skip stubbed-domain handlers: refuse, don't persist */
                if (!in_array($allowedSet[$id]['source_domain'], kpi_stub_domains(), true)) {
                    $validIds[] = $id;
                }
            }
        }
        $validIds = array_unique($validIds);

        /* Snapshot before write */
        $selStmt = $pdo->prepare(
            "SELECT tracker_id_fk, position FROM user_kpi_selections WHERE user_id_fk = ? ORDER BY position"
        );
        $selStmt->execute([$myUserId]);
        $before = $selStmt->fetchAll(PDO::FETCH_ASSOC);

        /* Transactional replace: delete current selections then re-insert in order */
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM user_kpi_selections WHERE user_id_fk = ?")->execute([$myUserId]);

            $ins = $pdo->prepare(
                "INSERT INTO user_kpi_selections (user_id_fk, tracker_id_fk, position) VALUES (?, ?, ?)"
            );
            foreach ($validIds as $pos => $tid) {
                $ins->execute([$myUserId, $tid, $pos]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flash_set('err', 'Erreur lors de la sauvegarde — réessaie.');
            redirect_to('/modules/mon-tableau.php');
        }

        /* Audit trail */
        $after = array_map(function($tid, $pos) {
            return ['tracker_id_fk' => $tid, 'position' => $pos];
        }, $validIds, array_keys($validIds));

        log_revision(
            $pdo, $me,
            'user_kpi_selections', $myUserId,
            $before ?: null,
            $after,
            'normal',
            'mon-tableau selection update'
        );

        flash_set('ok', 'Tableau mis à jour.');
        redirect_to('/modules/mon-tableau.php');
    }

    if ($action === 'update_recap_cadence') {
        /* Guard: only users with an email can set a recap subscription */
        if (empty($myEmail)) {
            flash_set('err', 'Aucune adresse e-mail associée à ton compte.');
            redirect_to('/modules/mon-tableau.php');
        }
        $rawCadence = post_str('cadence') ?? '';
        $allowed    = ['none', 'daily', 'weekly', 'monthly'];
        if (!in_array($rawCadence, $allowed, true)) {
            flash_set('err', 'Cadence invalide.');
            redirect_to('/modules/mon-tableau.php');
        }

        /* Snapshot before write */
        $snapStmt = $pdo->prepare(
            "SELECT id, cadence, is_active FROM user_kpi_recap_subs WHERE user_id_fk = ?"
        );
        $snapStmt->execute([$myUserId]);
        $before = $snapStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($rawCadence === 'none') {
            /* Remove subscription row (sparse — absence = no recap) */
            if ($before !== null) {
                $pdo->prepare("DELETE FROM user_kpi_recap_subs WHERE user_id_fk = ?")->execute([$myUserId]);
                log_revision(
                    $pdo, $me,
                    'user_kpi_recap_subs', (int) $before['id'],
                    $before,
                    null,
                    'normal',
                    'mon-tableau recap cadence: removed'
                );
            }
        } else {
            /* Upsert subscription: set cadence + clear next_due_at so cron fires on next check */
            $upsSql = $before !== null
                ? "UPDATE user_kpi_recap_subs SET cadence = ?, next_due_at = NOW(), is_active = 1 WHERE user_id_fk = ?"
                : "INSERT INTO user_kpi_recap_subs (cadence, next_due_at, is_active, user_id_fk) VALUES (?, NOW(), 1, ?)";
            $pdo->prepare($upsSql)->execute([$rawCadence, $myUserId]);

            $afterId = $before !== null ? (int) $before['id'] : (int) $pdo->lastInsertId();
            $after   = ['cadence' => $rawCadence, 'next_due_at' => date('Y-m-d H:i:s'), 'is_active' => 1];
            log_revision(
                $pdo, $me,
                'user_kpi_recap_subs', $afterId,
                $before,
                $after,
                'normal',
                "mon-tableau recap cadence: set to {$rawCadence}"
            );
        }

        flash_set('ok', 'Préférences de récap enregistrées.');
        redirect_to('/modules/mon-tableau.php');
    }

    /* Unknown action: ignore + redirect */
    redirect_to('/modules/mon-tableau.php');
}

/* ─────────────────────────────────────────────────────────────────────────────
   GET — build payload
   ─────────────────────────────────────────────────────────────────────────── */
$allowedSet    = mt_build_allowed_set($pdo, $myRole);
$stubMismatches = mt_stub_mismatches($allowedSet);

/* User's current selections (ordered by position) */
$selStmt = $pdo->prepare(
    "SELECT uks.tracker_id_fk, uks.position
       FROM user_kpi_selections uks
      WHERE uks.user_id_fk = ?
      ORDER BY uks.position"
);
$selStmt->execute([$myUserId]);
$userSelections = $selStmt->fetchAll(PDO::FETCH_ASSOC);

/* Resolve each selection: only keep if in the allowed set and not stubbed */
$selectedTrackers = [];
foreach ($userSelections as $sel) {
    $tid = (int) $sel['tracker_id_fk'];
    if (!isset($allowedSet[$tid])) continue;
    $tracker = $allowedSet[$tid];
    /* Skip stubbed-domain trackers in the active render */
    if (in_array($tracker['source_domain'], kpi_stub_domains(), true)) continue;
    $selectedTrackers[] = $tracker;
}

/* Dispatch KPIs: call kpi_dispatch for each selected tracker */
$kpiResults = [];
foreach ($selectedTrackers as $tracker) {
    $trackerArr = $tracker;
    if (is_string($trackerArr['params_json'])) {
        $trackerArr['params_json'] = json_decode($trackerArr['params_json'], true) ?? [];
    }
    $kpiResults[$tracker['id']] = kpi_dispatch($trackerArr, $pdo);
}

/* ─── Category labels + canonical render order (ONE place) ─── */
$CATEGORY_LABELS = [
    'production'     => 'Production — Wort',
    'fermentation'   => 'Fermentation',
    'racking'        => 'Transferts',  /* operator ruling 2026-06-07: "soutirage" = filling/packaging in their usage — confusing */
    'packaging'      => 'Packaging',
    'fg_stock'       => 'Stock PF',
    'rm_procurement' => 'Matières premières',
    'logistics'      => 'Logistique',
    'sales'          => 'Ventes',
    'cogs_finance'   => 'COGS / Finance',
    'utilities'      => 'Utilités',
    'qa_qc'          => 'QA / QC',
    'ops_health'     => 'Santé ops',
    'equipment'      => 'Équipement',
    'control_loop'   => 'Indicateurs pilotage',
];
$CATEGORY_ORDER = [
    'production', 'fermentation', 'racking', 'packaging', 'fg_stock',
    'rm_procurement', 'logistics', 'sales', 'cogs_finance', 'utilities',
    'qa_qc', 'ops_health', 'equipment', 'control_loop',
];

/* Group selected trackers by category (in fixed order) for dashboard sections */
$dashboardSections = [];
foreach ($CATEGORY_ORDER as $cat) {
    $inCat = array_filter($selectedTrackers, fn($t) => $t['category'] === $cat);
    if (!empty($inCat)) {
        $dashboardSections[$cat] = [
            'label'    => $CATEGORY_LABELS[$cat] ?? ucfirst($cat),
            'trackers' => array_values($inCat),
        ];
    }
}

/* Group allowed trackers by category for the picker UI */
$pickerGroups = [];

/* Only show non-stubbed trackers in the picker */
$selectedIds = array_column($selectedTrackers, 'id');
foreach ($CATEGORY_ORDER as $cat) {
    foreach ($allowedSet as $id => $row) {
        if ($row['category'] !== $cat) continue;
        if (in_array($row['source_domain'], kpi_stub_domains(), true)) continue;
        if (!isset($pickerGroups[$cat])) {
            $pickerGroups[$cat] = [
                'label'    => $CATEGORY_LABELS[$cat] ?? ucfirst($cat),
                'trackers' => [],
            ];
        }
        $row['selected'] = in_array($id, $selectedIds, true);
        $pickerGroups[$cat]['trackers'][] = $row;
    }
}

/* Current recap subscription (for cadence selector display) */
$recapSub = null;
try {
    $recapStmt = $pdo->prepare(
        "SELECT cadence, last_sent_at, next_due_at, is_active FROM user_kpi_recap_subs WHERE user_id_fk = ? LIMIT 1"
    );
    $recapStmt->execute([$myUserId]);
    $recapSub = $recapStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    /* Non-fatal: cadence selector shows 'none' */
    error_log('[mon-tableau] recap sub load failed: ' . $e->getMessage());
}
$currentCadence = ($recapSub && (int) $recapSub['is_active'] === 1) ? (string) $recapSub['cadence'] : 'none';

$csrfToken = csrf_token();
$flash     = flash_pop();
$isAdmin   = ($myRole === 'admin');

// ── ref_pages registration — idempotent, runs on every page load ──
// This is the safest approach for a new page: the INSERT ... ON DUPLICATE KEY
// ensures the row exists without requiring a separate migration file.
try {
    $pdo->prepare(
        "INSERT INTO ref_pages (page_key, label, icon, href, min_role, domain, is_active, sort)
         VALUES ('mon-tableau', 'Mon tableau', '📊', '/modules/mon-tableau.php', 'viewer', 'general', 1, 5)
         ON DUPLICATE KEY UPDATE
           label      = VALUES(label),
           icon       = VALUES(icon),
           href       = VALUES(href),
           min_role   = VALUES(min_role),
           domain     = VALUES(domain),
           is_active  = VALUES(is_active)"
    )->execute();
} catch (\Throwable $e) {
    /* Non-fatal: page still renders; log to error_log for visibility. */
    error_log('[mon-tableau] ref_pages upsert failed: ' . $e->getMessage());
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mon tableau — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/mon-tableau.css?v=<?= @filemtime(__DIR__ . '/../css/mon-tableau.css') ?: time() ?>">
</head>
<body class="home mon-tableau">

<?php
$active_module = 'mon-tableau';
$crumbs        = ['Accueil', 'Mon tableau'];
require __DIR__ . '/../../app/partials/sidebar.php';
require __DIR__ . '/../../app/partials/topbar.php';
?>

<main id="main-content" class="main">
<div class="mt-page">

  <h1 class="mt-page__title">Mon tableau de bord</h1>
  <p class="mt-page__sub">Indicateurs personnalisés — <?= htmlspecialchars($me['display_name'] ?? $me['username']) ?></p>

  <?php if ($flash): ?>
  <div class="mt-flash mt-flash--<?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
  <?php endif ?>

  <?php if ($isAdmin && !empty($stubMismatches)): ?>
  <div class="mt-flash mt-flash--err mt-flash--admin-notice">
    <strong>Admin notice —</strong> trackers data_ready=1 mais handler STUB (exclus du tableau) :
    <?= htmlspecialchars(implode(', ', $stubMismatches)) ?>
  </div>
  <?php endif ?>

  <!-- ═══════════════════════════════════════════════════
       ACTIVE KPI DASHBOARD — category-grouped sections
       ═══════════════════════════════════════════════════ -->
  <h2 class="mt-section-head">Mes indicateurs</h2>

  <?php if (empty($selectedTrackers)): ?>
  <div class="mt-kpi-grid--empty">
    Aucun indicateur sélectionné.<br>
    Utilise le sélecteur ci-dessous pour ajouter des KPIs à ton tableau.
  </div>
  <?php else: ?>
  <div class="mt-dashboard" id="mt-kpi-grid">
    <?php foreach ($dashboardSections as $cat => $section): ?>
    <section class="mt-cat-section" data-cat="<?= htmlspecialchars($cat) ?>">
      <h3 class="mt-cat-section__head"><?= htmlspecialchars($section['label']) ?></h3>
      <div class="mt-kpi-grid">
        <?php foreach ($section['trackers'] as $tracker): ?>
        <div class="kpc-card kpc-card--<?= htmlspecialchars($tracker['viz_type']) ?>"
             id="mt-card-<?= (int)$tracker['id'] ?>"
             data-tracker-id="<?= (int)$tracker['id'] ?>"
             data-tracker-slug="<?= htmlspecialchars($tracker['slug']) ?>"
             data-viz-type="<?= htmlspecialchars($tracker['viz_type']) ?>">
          <!-- Rendered by kpi-charts.js renderKpiCard() via window.MY_KPIS -->
        </div>
        <?php endforeach ?>
      </div>
    </section>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <!-- ═══════════════════════════════════════════════════
       PICKER — add / remove (category-grouped, collapsible)
       ═══════════════════════════════════════════════════ -->
  <div class="mt-picker" id="mt-picker">
    <button type="button" class="mt-picker__toggle" id="mt-picker-toggle" aria-expanded="false" aria-controls="mt-picker-body">
      <span class="mt-picker__toggle-icon" aria-hidden="true">+</span>
      <span>Gérer mes indicateurs</span>
    </button>
    <div class="mt-picker__body" id="mt-picker-body">

      <input type="search" class="mt-picker__search" id="mt-picker-search"
             placeholder="Rechercher un indicateur…" aria-label="Rechercher un indicateur">

      <form method="post" action="/modules/mon-tableau.php" id="mt-picker-form" novalidate>
        <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="update_selection">
        <!-- tracker_ids[] populated by JS on submit -->

        <?php foreach ($pickerGroups as $cat => $group): ?>
        <?php if (empty($group['trackers'])) continue; ?>
        <?php
          /* Count selected items in this group for the badge */
          $groupSelected = count(array_filter($group['trackers'], fn($t) => $t['selected']));
        ?>
        <div class="mt-picker__group" data-cat="<?= htmlspecialchars($cat) ?>">
          <button type="button" class="mt-picker__group-toggle"
                  aria-expanded="true"
                  aria-controls="mt-pg-<?= htmlspecialchars($cat) ?>">
            <span class="mt-picker__group-label"><?= htmlspecialchars($group['label']) ?></span>
            <?php if ($groupSelected > 0): ?>
            <span class="mt-picker__group-badge"><?= $groupSelected ?></span>
            <?php endif ?>
            <span class="mt-picker__group-chevron" aria-hidden="true">▾</span>
          </button>
          <div class="mt-tracker-grid" id="mt-pg-<?= htmlspecialchars($cat) ?>">
            <?php foreach ($group['trackers'] as $t): ?>
            <div class="mt-tracker-item <?= $t['selected'] ? 'mt-tracker-item--selected' : 'mt-tracker-item--unselected' ?>"
                 data-tracker-id="<?= (int)$t['id'] ?>"
                 data-tracker-label="<?= htmlspecialchars(mb_strtolower($t['label'])) ?>"
                 role="checkbox"
                 aria-checked="<?= $t['selected'] ? 'true' : 'false' ?>"
                 tabindex="0">
              <span class="mt-tracker-item__check" aria-hidden="true"><?= $t['selected'] ? '✓' : '○' ?></span>
              <div>
                <div class="mt-tracker-item__label"><?= htmlspecialchars($t['label']) ?></div>
                <div class="mt-tracker-item__meta"><?= htmlspecialchars($t['viz_type']) ?> · <?= htmlspecialchars($t['source_domain']) ?></div>
              </div>
            </div>
            <?php endforeach ?>
          </div>
        </div>
        <?php endforeach ?>

        <div class="mt-picker__save-bar">
          <button type="submit" class="mt-picker__save-btn" id="mt-save-btn">Enregistrer</button>
          <span class="mt-picker__save-note">Les modifications prennent effet immédiatement.</span>
        </div>
      </form>
    </div><!-- /.mt-picker__body -->
  </div><!-- /.mt-picker -->

  <!-- ═══════════════════════════════════════════════════
       RECAP EMAIL CADENCE — per-user preference
       ═══════════════════════════════════════════════════ -->
  <?php if (!empty($myEmail)): ?>
  <div class="mt-recap-prefs">
    <h3 class="mt-recap-prefs__head">Récap par e-mail</h3>
    <p class="mt-recap-prefs__sub">
      Reçois un résumé de tes indicateurs sélectionnés directement dans ta boîte mail.
    </p>
    <form method="post" action="/modules/mon-tableau.php" novalidate>
      <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="update_recap_cadence">
      <div class="mt-recap-prefs__row">
        <?php
        $cadenceOptions = [
            'none'    => 'Aucun',
            'daily'   => 'Quotidien',
            'weekly'  => 'Hebdomadaire',
            'monthly' => 'Mensuel',
        ];
        foreach ($cadenceOptions as $val => $label):
            $checked = $currentCadence === $val ? ' checked' : '';
        ?>
        <label class="mt-recap-prefs__opt">
          <input type="radio" name="cadence" value="<?= htmlspecialchars($val) ?>"<?= $checked ?>>
          <?= htmlspecialchars($label) ?>
        </label>
        <?php endforeach ?>
        <button type="submit" class="mt-recap-prefs__save">Enregistrer</button>
      </div>
      <?php if ($recapSub && $currentCadence !== 'none'): ?>
      <p class="mt-recap-prefs__info">
        Prochain envoi :
        <?php if ($recapSub['next_due_at']): ?>
          <?= htmlspecialchars(date('d/m/Y H:i', strtotime($recapSub['next_due_at']))) ?>
        <?php else: ?>
          dès le prochain passage du cron
        <?php endif ?>
        <?php if ($recapSub['last_sent_at']): ?>
          · Dernier envoi : <?= htmlspecialchars(date('d/m/Y H:i', strtotime($recapSub['last_sent_at']))) ?>
        <?php endif ?>
      </p>
      <?php endif ?>
    </form>
  </div>
  <?php else: ?>
  <div class="mt-recap-prefs mt-recap-prefs--disabled">
    <h3 class="mt-recap-prefs__head">Récap par e-mail</h3>
    <p class="mt-recap-prefs__sub">Non disponible — aucune adresse e-mail associée à ton compte.</p>
  </div>
  <?php endif ?>

</div><!-- /.mt-page -->
</main>

<!-- Server-injected KPI payload — consumed by kpi-charts.js -->
<script>
window.MY_KPIS = <?= json_encode([
    'trackers' => array_values(array_map(function($t) {
        return [
            'id'       => (int)$t['id'],
            'slug'     => $t['slug'],
            'label'    => $t['label'],
            'viz_type' => $t['viz_type'],
            'category' => $t['category'],
        ];
    }, $selectedTrackers)),
    'results'  => array_combine(
        array_map(fn($t) => (string)(int)$t['id'], $selectedTrackers),
        array_map(fn($t) => $kpiResults[$t['id']] ?? [], $selectedTrackers)
    ),
    'is_admin' => ($myRole === 'admin'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script src="/js/kpi-charts.js?v=<?= @filemtime(__DIR__ . '/../js/kpi-charts.js') ?: time() ?>"></script>
<script src="/js/mon-tableau.js?v=<?= @filemtime(__DIR__ . '/../js/mon-tableau.js') ?: time() ?>"></script>

</body>
</html>
