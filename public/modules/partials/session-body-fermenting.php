<?php
declare(strict_types=1);
/**
 * session-body-fermenting.php — Phase dispatcher for fermenting sessions.
 *
 * Loaded by form-fermenting.php (GET path) after auth, data-load, and the
 * HTML shell (<head> + sidebar + topbar + flash + page-header). Mirrors
 * session-body-racking.php structurally.
 *
 * Inherits form-fermenting.php scope:
 *   $pdo, $me, $csrf, $recipes, $hopsJs, $recentFerm, $displayFmt
 *
 * P-B (firewall): when phase='start', loads:
 *   - CCT assignment for (beer, batch) via bd_brewing_brewday_v2
 *   - CCT CIP status via app/cct-cip-cadence.php
 *   - Yeast eligibility for earliest ColdCrash display via app/yeast-eligibility.php
 * These are made available to fermenting-phase-start.php as:
 *   $ff_cctNumber, $ff_cctMissing, $ff_cipStatus, $ff_yeastInfo
 *
 * Phase detection:
 *   none        — no (beer, batch) selection yet from URL → start partial
 *   start       — (beer, batch) supplied but NO bd_fermenting_v2 events exist yet
 *   in_progress — at least one event exists AND no ColdCrash event
 *   end         — a ColdCrash event exists for this (beer, batch)
 *
 * Variables built here and made available to the phase sub-partials:
 *   $ff_phase, $ff_beer, $ff_batch, $ff_recipeId,
 *   $ff_hasEvents, $ff_hasColdCrash, $ff_loadErr,
 *   $ff_cctNumber, $ff_cctMissing, $ff_cipStatus, $ff_yeastInfo (P-B, start only)
 */

$ff_loadErr      = null;
$ff_hasEvents    = false;
$ff_hasColdCrash = false;

// P-B firewall data — populated only when phase='start' and (beer, batch) supplied.
$ff_cctNumber = null;  // int|null — CCT number from bd_brewing_brewday_v2
$ff_cctMissing = false; // true when (beer,batch) found but cct IS NULL
$ff_cipStatus  = null;  // array|null — from cct_cip_status()
$ff_yeastInfo  = null;  // array|null — from resolve_recipe_yeast()

// URL params — operators may land with pre-filled beer/batch from a deep-link.
// Security: PDO binding handles SQL injection; htmlspecialchars at render time handles XSS.
// trim() drops accidental whitespace from URL copy-paste. strip_tags() removed — it added
// no SQL-layer protection (PDO binding is the actual defence) and was misleading.
$ff_beer     = isset($_GET['beer'])      ? trim((string)$_GET['beer'])  : '';
$ff_batch    = isset($_GET['batch'])     ? trim((string)$_GET['batch']) : '';
$ff_recipeId = isset($_GET['recipe_id']) ? (int)$_GET['recipe_id']      : null;

// Detect phase from bd_fermenting_v2 when (beer, batch) are both provided.
// Aggregate existence check — no LIMIT so ColdCrash is never truncated away on
// long ferments (3-week ferment with daily Reads = 21+ events).
if ($ff_beer !== '' && $ff_batch !== '') {
    try {
        $phaseStmt = $pdo->prepare(
            "SELECT
               MAX(event_type = 'ColdCrash') AS has_cold_crash,
               COUNT(*) > 0                  AS has_events
             FROM bd_fermenting_v2
             WHERE beer_raw      = ?
               AND batch         = ?
               AND is_tombstoned = 0"
        );
        $phaseStmt->execute([$ff_beer, $ff_batch]);
        $phaseRow = $phaseStmt->fetch(PDO::FETCH_ASSOC);

        // COUNT(*)>0 returns 0/1 even on no rows; MAX() returns NULL on no rows — coalesce both.
        $ff_hasEvents    = !empty($phaseRow) && (bool)($phaseRow['has_events']     ?? false);
        $ff_hasColdCrash = !empty($phaseRow) && (bool)($phaseRow['has_cold_crash'] ?? false);
    } catch (Throwable $_phErr) {
        $ff_loadErr = $_phErr->getMessage();
    }
}

// Derive phase string (mirrors racking's $_phase pattern).
if ($ff_beer === '' || $ff_batch === '') {
    $ff_phase = 'none';      // No selection yet — show start (selector) view
} elseif ($ff_hasColdCrash) {
    $ff_phase = 'end';       // ColdCrash recorded — ready-for-racking
} elseif ($ff_hasEvents) {
    $ff_phase = 'in_progress'; // Events exist, no ColdCrash yet
} else {
    $ff_phase = 'start';     // Selection supplied, no events yet
}

if ($ff_loadErr !== null): ?>
<div class="db-flash db-flash--err">
  Erreur de détection de phase : <?= htmlspecialchars($ff_loadErr) ?>
</div>
<?php endif ?>

<!--
  P-B FIREWALL DATA LOAD: When phase is 'start' (beer+batch provided, no events yet),
  load CCT assignment + CCT CIP status + yeast eligibility for the start-firewall view.
  These resolvers are pure-read; errors are caught and surfaced as banners in the partial.
-->
<?php if (($ff_phase === 'start' || $ff_phase === 'none') && $ff_beer !== '' && $ff_batch !== ''): ?>
<?php
require_once __DIR__ . '/../../../app/cct-cip-cadence.php';
require_once __DIR__ . '/../../../app/yeast-eligibility.php';

try {
    // ── 1. CCT assignment for this (beer, batch) ──────────────────────────
    // Use the MOST RECENT brewday row — ORDER BY id DESC LIMIT 1 handles
    // batches that were re-entered or corrected without requiring event_date.
    $cctStmt = $pdo->prepare(
        "SELECT cct, recipe_id_fk
           FROM bd_brewing_brewday_v2
          WHERE beer  = ?
            AND batch = ?
            AND is_tombstoned = 0
          ORDER BY id DESC
          LIMIT 1"
    );
    $cctStmt->execute([$ff_beer, $ff_batch]);
    $cctRow = $cctStmt->fetch(PDO::FETCH_ASSOC);

    if ($cctRow !== false) {
        if ($cctRow['cct'] !== null) {
            $ff_cctNumber = (int)$cctRow['cct'];
        } else {
            // Brewday row exists but CCT is NULL — operator didn't record it.
            $ff_cctMissing = true;
        }
        // Use recipe_id from brewday if not already set from URL param.
        if ($ff_recipeId === null && $cctRow['recipe_id_fk'] !== null) {
            $ff_recipeId = (int)$cctRow['recipe_id_fk'];
        }
    }
    // No brewday row at all: ff_cctNumber stays null, ff_cctMissing stays false.
    // The partial will show "Aucune CCT trouvée" banner (no brewday row = refuse-don't-NULL).

    // ── 2. CCT CIP status ─────────────────────────────────────────────────
    if ($ff_cctNumber !== null) {
        $ff_cipStatus = cct_cip_status($pdo, $ff_cctNumber);
    }

    // ── 3. Yeast eligibility (display-only — earliest ColdCrash date) ─────
    if ($ff_recipeId !== null) {
        try {
            $ff_yeastInfo = resolve_recipe_yeast($pdo, $ff_recipeId);
        } catch (RuntimeException $e) {
            // Recipe not found — leave ff_yeastInfo null; partial will omit the row.
            $ff_yeastInfo = null;
        }
    }
} catch (Throwable $e) {
    // Non-fatal: firewall data load failed. Partial will render banners in place of predicates.
    if ($ff_loadErr === null) {
        $ff_loadErr = 'Firewall data load error: ' . $e->getMessage();
    }
}
?>
<?php endif ?>

<!--
  Phase-gated dispatcher.
-->
<?php if ($ff_phase === 'none' || $ff_phase === 'start'): ?>
  <?php require __DIR__ . '/fermenting-phase-start.php' ?>
<?php elseif ($ff_phase === 'in_progress'): ?>
  <?php require __DIR__ . '/fermenting-phase-in-progress.php' ?>
<?php elseif ($ff_phase === 'end'): ?>
  <?php require __DIR__ . '/fermenting-phase-end.php' ?>
<?php else: ?>
  <div class="db-flash db-flash--err">
    Phase de fermentation inconnue : <?= htmlspecialchars($ff_phase) ?>
  </div>
<?php endif ?>

<!-- Recent submissions are rendered OUTSIDE the <form> — matching form-fermenting.php structure. -->
<?php require __DIR__ . '/fermenting-phase-recent.php' ?>
