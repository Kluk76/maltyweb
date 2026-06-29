<?php
declare(strict_types=1);
/**
 * topbar.php — horizontal nav topbar (category consolidation, 2026-06)
 *
 * Variables expected before require:
 *   $active_module  string  — key of the active module (e.g. "triage", "wort")
 *   $me             array   — current_user() result
 *
 * Nav structure:
 *   [Brand]  [✦ Le Zeppelin]  [✎ Saisies]  [Production ▾] … [Système ▾]   [right cluster]
 *
 * Standalone buttons: zeppelin + saisies (category_key = NULL + not mon-tableau).
 * Category dropdowns: 6 categories from page_categories() accessor, children
 *   grouped by category_key ordered by category_sort.
 * The mon-tableau page is the brand link target (not a standalone button).
 */

require_once __DIR__ . '/../page-categories.php';

$active_module = $active_module ?? "";
$me            = $me ?? current_user() ?? [];

// ── Load nav from ref_pages ──────────────────────────────────────────────────
$_tbPdo  = maltytask_pdo();
$_tbStmt = $_tbPdo->query(
    "SELECT page_key, label, icon, href, min_role, domain, category_key, category_sort
       FROM ref_pages
      WHERE is_active = 1
      ORDER BY sort"
);
$_tbAllRows = $_tbStmt->fetchAll();

// ── Partition rows through access control ───────────────────────────────────
// Standalone buttons: zeppelin + saisies (category_key NULL, not mon-tableau).
// Category children: all other pages with a non-null category_key.
// (mon-tableau and any future NULL-category pages that aren't standalone are skipped here
//  because they have no designated nav slot — brand link covers mon-tableau.)

$_tbStandaloneKeys = ['zeppelin', 'saisies']; // explicit list, never pattern-match
$_tbStandalone     = [];   // ordered by sort asc (already ordered from query)
$_tbByCategory     = [];   // ['production' => [rows...], …]

foreach ($_tbAllRows as $_tbRow) {
    if (!user_can_access($_tbRow['page_key'], $me)) continue;

    $pk  = $_tbRow['page_key'];
    $cat = $_tbRow['category_key'];

    if (in_array($pk, $_tbStandaloneKeys, true)) {
        $_tbStandalone[$pk] = $_tbRow;
    } elseif ($cat !== null && $cat !== '') {
        $_tbByCategory[$cat][] = $_tbRow;
    }
    // mon-tableau + NULL-category non-standalones: skip (brand covers mon-tableau)
}

// Keep standalones in declared order (zeppelin first, then saisies)
$_tbStandaloneOrdered = [];
foreach ($_tbStandaloneKeys as $_sk) {
    if (isset($_tbStandalone[$_sk])) {
        $_tbStandaloneOrdered[] = $_tbStandalone[$_sk];
    }
}

// Sort category children by category_sort, then label
foreach ($_tbByCategory as &$_catRows) {
    usort($_catRows, fn($a, $b) =>
        ((int)$a['category_sort'] <=> (int)$b['category_sort'])
        ?: strcmp($a['label'], $b['label'])
    );
}
unset($_catRows);

// Build ordered list of categories (from canonical accessor), drop empty ones
$_tbCategories = []; // [['key'=>'production','meta'=>[...],'rows'=>[...]], …]
foreach (page_categories() as $_catKey => $_catMeta) {
    $_tbRows = $_tbByCategory[$_catKey] ?? [];
    if (count($_tbRows) === 0) continue; // no permitted children → don't render
    $_tbCategories[] = ['key' => $_catKey, 'meta' => $_catMeta, 'rows' => $_tbRows];
}
// Sort by order field from canonical accessor
usort($_tbCategories, fn($a, $b) => $a['meta']['order'] <=> $b['meta']['order']);

// Determine if active page is in a category (for active-state on category button)
$_tbActiveCat = null;
foreach ($_tbCategories as $_c) {
    foreach ($_c['rows'] as $_r) {
        if ($_r['page_key'] === $active_module) {
            $_tbActiveCat = $_c['key'];
            break 2;
        }
    }
}

// ── Ingest badge (60-second file-transient cache, admin-accessible pages only) ──
// Show badge if user has access to any 'systeme' category page
$_tbHasSysteme = !empty($_tbByCategory['systeme'] ?? []);
$ingestBadge = null;
if ($_tbHasSysteme) {
    $ibCacheDir  = '/var/www/maltytask/storage/cache';
    $ibCacheFile = $ibCacheDir . '/ingest_badge.json';
    $ibCacheTTL  = 60;
    $ibCached    = false;

    if (is_readable($ibCacheFile)) {
        $ibRaw = @file_get_contents($ibCacheFile);
        if ($ibRaw !== false) {
            $ibEntry = json_decode($ibRaw, true);
            if (
                is_array($ibEntry)
                && isset($ibEntry['ts'], $ibEntry['row'])
                && (time() - (int)$ibEntry['ts']) < $ibCacheTTL
            ) {
                $ingestBadge = $ibEntry['row'];
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
            $ingestBadge = false;
        }
    }
}

function _ingest_badge_state(?array $row): string
{
    if ($row === null) return 'grey';
    $status   = $row['status']   ?? 'unknown';
    $age      = (int)($row['age_sec'] ?? PHP_INT_MAX);
    $failures = (int)($row['failure_count'] ?? 0);

    if ($status === 'failed')                            return 'red';
    if ($age > 10800)                                    return 'red';
    if ($status === 'partial')                           return 'orange';
    if ($status === 'running' && $age > 3600)            return 'orange';
    if ($status === 'ok'      && $age > 5400 && $age <= 10800) return 'orange';
    if ($status === 'ok'      && $age <= 5400)           return 'green';
    return 'orange';
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
<a class="skip-link" href="#main-content">Aller au contenu</a>
<header class="tb" id="topbar" role="banner" aria-label="Navigation principale">

  <!-- ── Mobile burger ── -->
  <button class="tb__burger" id="tb-burger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="tb-drawer">
    <span></span><span></span><span></span>
  </button>

  <!-- ── Brand (links to mon-tableau) ── -->
  <a class="tb__brand" href="/modules/mon-tableau.php" aria-label="MaltyTask — Mon tableau">
    <span class="tb-mark">M<span class="tb-mark__t">T</span></span>
  </a>

  <!-- ── Standalone primary buttons ── -->
  <?php foreach ($_tbStandaloneOrdered as $_tbSA): ?>
    <?php
    $saActive = ($_tbSA['page_key'] === $active_module);
    $saIsZep  = ($_tbSA['page_key'] === 'zeppelin');
    ?>
    <a class="tb__standalone<?= $saActive ? ' tb__standalone--active' : '' ?><?= $saIsZep ? ' tb__standalone--zep' : '' ?>"
       href="<?= htmlspecialchars($_tbSA['href']) ?>"
       <?= $saActive ? 'aria-current="page"' : '' ?>>
      <span class="tb__midx" aria-hidden="true"><?= htmlspecialchars($_tbSA['icon'] ?? '') ?></span><span class="tb__mname"><?= htmlspecialchars($_tbSA['label']) ?></span>
    </a>
  <?php endforeach ?>

  <!-- ── Category dropdown nav (desktop) ── -->
  <nav class="tb__nav" aria-label="Modules" id="tb-nav">
    <ul>
      <?php foreach ($_tbCategories as $_cat):
        $catKey    = $_cat['key'];
        $catMeta   = $_cat['meta'];
        $catRows   = $_cat['rows'];
        $catActive = ($catKey === $_tbActiveCat);
        $catId     = 'tb-cat-' . htmlspecialchars($catKey);
        $panelId   = 'tb-cat-panel-' . htmlspecialchars($catKey);
      ?>
      <li>
        <div class="tb__cat-wrap" id="<?= $catId ?>-wrap">
          <button class="tb__cat-btn<?= $catActive ? ' tb__cat-btn--active' : '' ?>"
                  id="<?= $catId ?>-btn"
                  aria-expanded="false"
                  aria-controls="<?= $panelId ?>"
                  <?= $catActive ? 'aria-current="true"' : '' ?>>
            <span class="tb__midx" aria-hidden="true"><?= htmlspecialchars($catMeta['icon']) ?></span><span class="tb__mname"><?= htmlspecialchars($catMeta['label']) ?></span>
            <svg class="tb__cat-caret" viewBox="0 0 10 6" width="8" height="8" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          <div class="tb__cat-panel" id="<?= $panelId ?>" role="menu" hidden>
            <?php foreach ($catRows as $_item):
              $itemActive = ($_item['page_key'] === $active_module);
            ?>
              <a class="tb__admin-item<?= $itemActive ? ' tb__admin-item--active' : '' ?>"
                 href="<?= htmlspecialchars($_item['href']) ?>"
                 role="menuitem"
                 <?= $itemActive ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($_item['label']) ?></a>
            <?php endforeach ?>
          </div>
        </div>
      </li>
      <?php endforeach ?>
    </ul>
  </nav>

  <!-- ── Right cluster ── -->
  <div class="tb__right">

    <?php if ($_tbHasSysteme && $ibState !== null): ?>
    <!-- Ingest status badge -->
    <a class="tb__ingest-badge tb__ingest-badge--<?= htmlspecialchars($ibState) ?>"
       href="/admin/ingest.php"
       title="<?= htmlspecialchars($ibTooltip) ?>"
       aria-label="<?= htmlspecialchars($ibTooltip) ?>">
      <span class="tb__ingest-dot" aria-hidden="true"></span>
      <span class="tb__ingest-label"><?= $ibState === 'grey' ? '—' : strtoupper($ibState === 'green' ? 'ok' : ($ibState === 'orange' ? 'warn' : 'err')) ?></span>
    </a>
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
        <a class="tb__admin-item" href="/modules/visite-guidee.php" role="menuitem">Visite guidée</a>
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

    <!-- Standalone buttons in drawer -->
    <?php if (!empty($_tbStandaloneOrdered)): ?>
    <div class="tb-drawer__section-label">— accès rapide</div>
    <ul class="tb-drawer__list">
      <?php foreach ($_tbStandaloneOrdered as $_tbSA): ?>
        <?php $active = ($_tbSA['page_key'] === $active_module); ?>
        <li>
          <a class="tb-drawer__item<?= $active ? ' tb-drawer__item--active' : '' ?>"
             href="<?= htmlspecialchars($_tbSA['href']) ?>"
             <?= $active ? 'aria-current="page"' : '' ?>>
            <span class="tb-drawer__idx"><?= htmlspecialchars($_tbSA['icon'] ?? '') ?></span>
            <span class="tb-drawer__name"><?= htmlspecialchars($_tbSA['label']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <!-- Category sections in drawer (collapsible) -->
    <?php foreach ($_tbCategories as $_cat):
      $catKey  = $_cat['key'];
      $catMeta = $_cat['meta'];
      $catRows = $_cat['rows'];
      $catIsActive = ($catKey === $_tbActiveCat);
      $drawerCatId = 'tb-drawer-cat-' . htmlspecialchars($catKey);
    ?>
    <div class="tb-drawer__section-label tb-drawer__section-label--cat"
         role="button"
         tabindex="0"
         aria-expanded="<?= $catIsActive ? 'true' : 'false' ?>"
         aria-controls="<?= $drawerCatId ?>"
         id="<?= $drawerCatId ?>-hdr">
      <span class="tb-drawer__cat-icon" aria-hidden="true"><?= htmlspecialchars($catMeta['icon']) ?></span>
      <?= htmlspecialchars($catMeta['label']) ?>
      <svg class="tb-drawer__cat-caret" viewBox="0 0 10 6" width="8" height="8" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <ul class="tb-drawer__list tb-drawer__list--cat<?= $catIsActive ? ' tb-drawer__list--cat-open' : '' ?>"
        id="<?= $drawerCatId ?>"
        <?= $catIsActive ? '' : 'hidden' ?>>
      <?php foreach ($catRows as $_item):
        $active = ($_item['page_key'] === $active_module);
      ?>
        <li>
          <a class="tb-drawer__item<?= $active ? ' tb-drawer__item--active' : '' ?>"
             href="<?= htmlspecialchars($_item['href']) ?>"
             <?= $active ? 'aria-current="page"' : '' ?>>
            <span class="tb-drawer__name"><?= htmlspecialchars($_item['label']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
    <?php endforeach ?>

    <div class="tb-drawer__foot">
      <a class="tb-drawer__logout" href="/logout.php">Déconnexion</a>
      <span class="tb-drawer__org">la nébuleuse · v0.1</span>
    </div>

  </nav>
</div>

<script defer src="/js/topbar.js?v=<?= @filemtime(__DIR__ . '/../../public/js/topbar.js') ?: time() ?>"></script>
<script defer src="/js/form-resilience.js?v=<?= @filemtime(__DIR__ . '/../../public/js/form-resilience.js') ?: time() ?>"></script>
