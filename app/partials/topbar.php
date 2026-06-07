<?php
declare(strict_types=1);
/**
 * topbar.php — horizontal nav topbar (Option F redesign)
 *
 * Variables expected before require:
 *   $active_module  string  — key of the active module (e.g. "triage", "wort")
 *   $me             array   — current_user() result
 */
$active_module = $active_module ?? "";
$me            = $me ?? current_user() ?? [];

// ── Load nav from ref_pages (replaces hardcoded $modules array) ─────────────
$_tbPdo     = maltytask_pdo();
$_tbStmt    = $_tbPdo->query(
    "SELECT page_key, label, icon, href, min_role, domain
       FROM ref_pages
      WHERE is_active = 1
      ORDER BY sort"
);
$_tbAllRows = $_tbStmt->fetchAll();

$_tbMainRows  = [];  // domain != 'admin', filtered to what user can see
$_tbAdminRows = [];  // domain = 'admin',  filtered to what user can see

foreach ($_tbAllRows as $_tbRow) {
    if (!user_can_access($_tbRow['page_key'], $me)) continue;
    if ($_tbRow['domain'] === 'admin') {
        $_tbAdminRows[] = $_tbRow;
    } else {
        $_tbMainRows[]  = $_tbRow;
    }
}

$showAdminBlock = count($_tbAdminRows) > 0;

// ── Ingest badge (1-row query, 60-second file-transient cache) ───────────────
// Cache is skipped when the last run status is 'failed' or 'partial' so the
// operator always sees the latest alert state immediately.
$ingestBadge = null;  // null → table empty / not yet available
if ($showAdminBlock) {
    $ibCacheDir  = '/var/www/maltytask/storage/cache';
    $ibCacheFile = $ibCacheDir . '/ingest_badge.json';
    $ibCacheTTL  = 60; // seconds
    $ibCached    = false;

    // Attempt to read from cache.
    if (is_readable($ibCacheFile)) {
        $ibRaw = @file_get_contents($ibCacheFile);
        if ($ibRaw !== false) {
            $ibEntry = json_decode($ibRaw, true);
            if (
                is_array($ibEntry)
                && isset($ibEntry['ts'], $ibEntry['row'])
                && (time() - (int)$ibEntry['ts']) < $ibCacheTTL
            ) {
                $ingestBadge = $ibEntry['row'];  // may be null (no runs) or array
                $ibCached    = true;
            }
        }
    }

    if (!$ibCached) {
        try {
            $ibPdo  = maltytask_pdo();
            $ibStmt = $ibPdo->query(
                "SELECT id, started_at, finished_at, status,
                        TIMESTAMPDIFF(SECOND, started_at, NOW()) AS age_sec,
                        (SELECT COUNT(*) FROM ingest_failures
                          WHERE run_id = ir.id) AS failure_count
                   FROM ingest_runs ir
                  ORDER BY started_at DESC
                  LIMIT 1"
            );
            $ingestBadge = $ibStmt->fetch() ?: null;

            // Only cache successful runs — failures/partial must always be fresh.
            $ibStatus = $ingestBadge['status'] ?? null;
            if (!in_array($ibStatus, ['failed', 'partial'], true)) {
                @mkdir($ibCacheDir, 0775, true);
                @file_put_contents(
                    $ibCacheFile,
                    json_encode(['ts' => time(), 'row' => $ingestBadge]),
                    LOCK_EX
                );
            }
        } catch (Throwable $e) {
            // Table may not exist yet (migration pending) — degrade gracefully.
            $ingestBadge = false;  // false = table unavailable
        }
    }
}

// Compute badge state from the row.
// Returns one of: 'green' | 'orange' | 'red' | 'grey' | null (hidden)
function _ingest_badge_state(?array $row): string
{
    if ($row === null) return 'grey';   // no runs yet
    $status   = $row['status']   ?? 'unknown';
    $age      = (int)($row['age_sec'] ?? PHP_INT_MAX);
    $failures = (int)($row['failure_count'] ?? 0);

    if ($status === 'failed')                            return 'red';
    if ($age > 10800)                                    return 'red';   // > 3 h
    if ($status === 'partial')                           return 'orange';
    if ($status === 'running' && $age > 3600)            return 'orange'; // stuck > 1 h
    if ($status === 'ok'      && $age > 5400 && $age <= 10800) return 'orange'; // 90–180 min stale
    if ($status === 'ok'      && $age <= 5400)           return 'green';
    return 'orange'; // everything else: cautious
}

function _ingest_badge_tooltip(?array $row): string
{
    if ($row === null) return 'Aucune exécution d\'ingest enregistrée';
    $age    = (int)($row['age_sec'] ?? 0);
    $mins   = (int)round($age / 60);
    $status = $row['status'] ?? '?';
    $fail   = (int)($row['failure_count'] ?? 0);

    if ($status === 'ok' && $fail === 0) {
        return "Dernier ingest il y a {$mins}m — OK";
    } elseif ($status === 'partial') {
        return "Dernier ingest il y a {$mins}m — PARTIEL ({$fail} ligne" . ($fail > 1 ? 's' : '') . " rejetée" . ($fail > 1 ? 's' : '') . ")";
    } elseif ($status === 'failed') {
        return "Dernier ingest il y a {$mins}m — ERREUR";
    } elseif ($status === 'running') {
        return "Ingest en cours depuis {$mins}m";
    }
    return "Dernier ingest il y a {$mins}m — {$status}";
}

$ibState   = ($ingestBadge === false) ? null : _ingest_badge_state($ingestBadge ?: null);
$ibTooltip = ($ingestBadge === false) ? 'Table ingest_runs indisponible' : _ingest_badge_tooltip($ingestBadge ?: null);

$userName = htmlspecialchars($me["display_name"] ?? $me["username"] ?? "");
$userRole = htmlspecialchars($me["role"] ?? "");
?>
<header class="tb" id="topbar" role="banner" aria-label="Navigation principale">

  <!-- ── Mobile burger ── -->
  <button class="tb__burger" id="tb-burger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="tb-drawer">
    <span></span><span></span><span></span>
  </button>

  <!-- ── Brand ── -->
  <a class="tb__brand" href="/modules/mon-tableau.php" aria-label="MaltyTask — Mon tableau">
    <span class="tb-mark">M<span class="tb-mark__t">T</span></span>
  </a>

  <!-- ── Module nav (desktop) ── -->
  <nav class="tb__nav" aria-label="Modules" id="tb-nav">
    <ul>
      <?php foreach ($_tbMainRows as $_tbMod): ?>
        <?php $active = ($_tbMod['page_key'] === $active_module); ?>
        <li>
          <a class="tb__module<?= $active ? ' tb__module--active' : '' ?>"
             href="<?= htmlspecialchars($_tbMod['href']) ?>"
             <?= $active ? 'aria-current="page"' : '' ?>>
            <span class="tb__midx"><?= htmlspecialchars($_tbMod['icon'] ?? '') ?></span><span class="tb__mname"><?= htmlspecialchars($_tbMod['label']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  </nav>

  <!-- ── Right cluster ── -->
  <div class="tb__right">

    <?php if ($showAdminBlock && $ibState !== null): ?>
    <!-- Ingest status badge -->
    <a class="tb__ingest-badge tb__ingest-badge--<?= htmlspecialchars($ibState) ?>"
       href="/admin/ingest.php"
       title="<?= htmlspecialchars($ibTooltip) ?>"
       aria-label="<?= htmlspecialchars($ibTooltip) ?>">
      <span class="tb__ingest-dot" aria-hidden="true"></span>
      <span class="tb__ingest-label"><?= $ibState === 'grey' ? '—' : strtoupper($ibState === 'green' ? 'ok' : ($ibState === 'orange' ? 'warn' : 'err')) ?></span>
    </a>
    <?php endif ?>

    <?php if ($showAdminBlock): ?>
    <!-- Admin overflow -->
    <div class="tb__admin-wrap" id="tb-admin-wrap">
      <button class="tb__admin-btn" id="tb-admin-btn" aria-label="Menu admin" aria-expanded="false" aria-controls="tb-admin-panel">
        <svg viewBox="0 0 16 12" width="16" height="12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect y="0"  width="16" height="1.5" rx="0.75" fill="currentColor"/>
          <rect y="5"  width="16" height="1.5" rx="0.75" fill="currentColor"/>
          <rect y="10" width="16" height="1.5" rx="0.75" fill="currentColor"/>
        </svg>
      </button>
      <div class="tb__admin-panel" id="tb-admin-panel" role="menu" hidden>
        <?php foreach ($_tbAdminRows as $_tbAdm): ?>
          <a class="tb__admin-item<?= ($_tbAdm['page_key'] === $active_module) ? ' tb__admin-item--active' : '' ?>"
             href="<?= htmlspecialchars($_tbAdm['href']) ?>"
             role="menuitem"><?= htmlspecialchars($_tbAdm['label']) ?></a>
        <?php endforeach ?>
        <div class="tb__admin-sep" role="separator"></div>
        <a class="tb__admin-item tb__admin-item--out" href="/logout.php" role="menuitem">Déconnexion</a>
      </div>
    </div>
    <?php endif ?>

    <!-- User chip -->
    <div class="tb__user-wrap" id="tb-user-wrap">
      <button class="tb__user-btn" id="tb-user-btn" aria-label="Menu utilisateur" aria-expanded="false" aria-controls="tb-user-panel">
        <span class="tb__uname"><?= $userName ?></span>
        <svg class="tb__ucaret" viewBox="0 0 10 6" width="10" height="6" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="tb__user-panel" id="tb-user-panel" role="menu" hidden>
        <?php if ($userRole): ?>
          <div class="tb__user-role"><?= $userRole ?></div>
        <?php endif ?>
        <div class="tb__admin-sep" role="separator"></div>
        <a class="tb__admin-item" href="/admin/settings/devices.php" role="menuitem">Mes appareils</a>
        <a class="tb__admin-item tb__admin-item--switch" href="/logout.php" role="menuitem">Changer d'utilisateur</a>
      </div>
    </div>

  </div><!-- /.tb__right -->

</header>

<!-- ── Mobile drawer ── -->
<div class="tb-drawer" id="tb-drawer" aria-label="Menu mobile" aria-hidden="true">
  <div class="tb-drawer__backdrop" id="tb-backdrop" aria-hidden="true"></div>
  <nav class="tb-drawer__panel" role="dialog" aria-modal="true" aria-label="Navigation">

    <div class="tb-drawer__head">
      <span class="tb-mark tb-mark--drawer">M<span class="tb-mark__t">T</span></span>
      <button class="tb-drawer__close" id="tb-drawer-close" aria-label="Fermer le menu">
        <svg viewBox="0 0 14 14" width="14" height="14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M1 1L13 13M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <div class="tb-drawer__section-label">— modules</div>
    <ul class="tb-drawer__list">
      <?php foreach ($_tbMainRows as $_tbMod): ?>
        <?php $active = ($_tbMod['page_key'] === $active_module); ?>
        <li>
          <a class="tb-drawer__item<?= $active ? ' tb-drawer__item--active' : '' ?>"
             href="<?= htmlspecialchars($_tbMod['href']) ?>"
             <?= $active ? 'aria-current="page"' : '' ?>>
            <span class="tb-drawer__idx"><?= htmlspecialchars($_tbMod['icon'] ?? '') ?></span>
            <span class="tb-drawer__name"><?= htmlspecialchars($_tbMod['label']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>

    <?php if ($showAdminBlock): ?>
    <div class="tb-drawer__section-label tb-drawer__section-label--admin">— admin</div>
    <ul class="tb-drawer__list">
      <?php foreach ($_tbAdminRows as $_tbAdm): ?>
        <li>
          <a class="tb-drawer__item<?= ($_tbAdm['page_key'] === $active_module) ? ' tb-drawer__item--active' : '' ?>"
             href="<?= htmlspecialchars($_tbAdm['href']) ?>">
            <span class="tb-drawer__name"><?= htmlspecialchars($_tbAdm['label']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <div class="tb-drawer__foot">
      <a class="tb-drawer__logout" href="/logout.php">Déconnexion</a>
      <span class="tb-drawer__org">la nébuleuse · v0.1</span>
    </div>

  </nav>
</div>

<script defer src="/js/topbar.js?v=<?= @filemtime(__DIR__ . '/../../public/js/topbar.js') ?: time() ?>"></script>
<script defer src="/js/form-resilience.js?v=<?= @filemtime(__DIR__ . '/../../public/js/form-resilience.js') ?: time() ?>"></script>
