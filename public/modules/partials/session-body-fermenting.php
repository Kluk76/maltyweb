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
 * P-A (skeleton): detects the current phase for the (recipe, batch) pair from
 * the URL params (?beer=&batch=), then includes the right phase partial.
 * No phase-gating logic — that is P-B (CIP/cadence firewall) work.
 * All behavior is identical to the pre-extraction inline form.
 *
 * Phase detection:
 *   none        — no (beer, batch) selection yet from URL → start partial
 *   start       — (beer, batch) supplied but NO bd_fermenting_v2 events exist yet
 *   in_progress — at least one event exists AND no ColdCrash event
 *   end         — a ColdCrash event exists for this (beer, batch)
 *
 * For P-A the form renders identically in ALL phases (single-form, no split-write).
 * Phase detection is computed here so P-B can add firewall gates without touching
 * the phase partials.
 *
 * Variables built here and made available to the phase sub-partials:
 *   $ff_phase, $ff_beer, $ff_batch, $ff_recipeId,
 *   $ff_hasEvents, $ff_hasColdCrash, $ff_loadErr
 */

$ff_loadErr     = null;
$ff_hasEvents   = false;
$ff_hasColdCrash = false;

// URL params — operators may land with pre-filled beer/batch from a deep-link.
// Raw values used for DB queries (PDO binds prevent injection); escaping applied
// at render time only (htmlspecialchars in the phase partials and error banner).
$ff_beer     = isset($_GET['beer'])      ? strip_tags(trim((string)$_GET['beer']))  : '';
$ff_batch    = isset($_GET['batch'])     ? strip_tags(trim((string)$_GET['batch'])) : '';
$ff_recipeId = isset($_GET['recipe_id']) ? (int)$_GET['recipe_id']                 : null;

// Detect phase from bd_fermenting_v2 when (beer, batch) are both provided.
if ($ff_beer !== '' && $ff_batch !== '') {
    try {
        $phaseStmt = $pdo->prepare(
            "SELECT event_type
               FROM bd_fermenting_v2
              WHERE beer_raw = ?
                AND batch    = ?
                AND is_tombstoned = 0
              LIMIT 20"
        );
        $phaseStmt->execute([$ff_beer, $ff_batch]);
        $phaseRows = $phaseStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $ff_hasEvents    = !empty($phaseRows);
        $ff_hasColdCrash = in_array('ColdCrash', $phaseRows, true);
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
  P-A RENDER STRATEGY: Phase-gated dispatcher.
  All phases render the full unified form (same HTML as pre-extraction inline).
  P-B will add firewall gates per-phase; P-C will split the write endpoint.
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
