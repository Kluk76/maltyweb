<?php
declare(strict_types=1);
/**
 * session-body-fermenting.php — Phase dispatcher for fermenting sessions (P-C).
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
 * P-C (split-write): when (beer, batch) are both provided, opens or looks up
 *   the op_sessions row for this (recipe_id_fk, batch) and makes available:
 *   $ff_sessionId  — int, the session PK to embed in form hidden fields
 *
 * Phase detection (from bd_fermenting_v2 events for this beer/batch):
 *   none        — no (beer, batch) selection yet from URL → start partial
 *   start       — (beer, batch) supplied but NO bd_fermenting_v2 events exist yet
 *   in_progress — at least one event exists AND no ColdCrash event
 *   end         — a ColdCrash event exists for this (beer, batch)
 *
 * Session phase is authoritative for the submit endpoint; bd_fermenting_v2 event
 * count drives the display phase here (matches P-A/P-B; consistent with historical
 * rows that have no session_id_fk).
 *
 * Variables built here and made available to the phase sub-partials:
 *   $ff_phase, $ff_beer, $ff_batch, $ff_recipeId,
 *   $ff_hasEvents, $ff_hasColdCrash, $ff_loadErr,
 *   $ff_sessionId  (P-C — always set when beer+batch known; 0 when not yet resolved)
 *   $ff_cctNumber, $ff_cctMissing, $ff_cipStatus, $ff_yeastInfo (P-B, start only)
 */

$ff_loadErr      = null;
$ff_hasEvents    = false;
$ff_hasColdCrash = false;
$ff_sessionId    = 0;   // P-C: op_sessions.id for this (recipe_id, batch); 0 until resolved

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

// ── P-C: Open or find the op_sessions row for this (recipe_id_fk, batch) ─────
//
// Strategy: look for an open fermenting session whose (recipe_id_fk, batch) matches.
// If found, reuse it. If not found AND a (beer, batch) is known (any phase except none),
// open a new session. This keeps the session creation lazy — we don't open a session
// until the operator has selected a beer/batch, and we don't create duplicates.
//
// The session is embedded in the form as a hidden field; the endpoint uses it
// to gate the write and advance the phase. Historical submissions where the
// form still posts to form-fermenting.php are unaffected (no session_id field).
if ($ff_beer !== '' && $ff_batch !== '' && $ff_loadErr === null) {
    require_once __DIR__ . '/../../../app/sessions.php';
    require_once __DIR__ . '/../../../app/mother-shell.php';
    try {
        // Look for an existing open fermenting session for this (recipe_id, batch).
        // If recipe_id is known, use it; otherwise fall back to a batch-only lookup
        // which is less precise but avoids stalling on missing recipe metadata.
        $sessLookupParams = ['fermenting'];
        $sessLookupWhere  = "form_type = ? AND status = 'open' AND is_tombstoned = 0";

        if ($ff_recipeId !== null) {
            $sessLookupWhere  .= " AND recipe_id_fk = ?";
            $sessLookupParams[] = $ff_recipeId;
        }
        $sessLookupWhere  .= " AND batch = ?";
        $sessLookupParams[] = $ff_batch;

        $sessLookupStmt = $pdo->prepare(
            "SELECT id FROM op_sessions WHERE {$sessLookupWhere} ORDER BY id DESC LIMIT 1"
        );
        $sessLookupStmt->execute($sessLookupParams);
        $sessLookupRow = $sessLookupStmt->fetch(PDO::FETCH_ASSOC);

        if ($sessLookupRow !== false) {
            $ff_sessionId = (int)$sessLookupRow['id'];
        } else {
            // No open session found — open one.
            $ctx = [
                'form_type' => 'fermenting',
                'batch'     => $ff_batch,
            ];
            if ($ff_recipeId !== null) {
                $ctx['recipe_id_fk'] = $ff_recipeId;
            }
            $ff_sessionId = session_open($pdo, $ctx, (int)$me['id']);

            // ── Auto-link to mother (Phase 1) ─────────────────────────────────
            // Fires only when a NEW fermenting session is opened (not on lookup).
            // Failure must NOT block the form render.
            if ($ff_sessionId > 0 && $ff_recipeId !== null) {
                try {
                    link_daily_to_mother($pdo, $ff_sessionId, $ff_recipeId, $ff_batch);
                } catch (Throwable $_linkErr) {
                    error_log('[mother-shell] link_daily_to_mother (fermenting session=' . $ff_sessionId
                        . '): ' . $_linkErr->getMessage());
                }
            }
        }
    } catch (Throwable $_sessErr) {
        // Non-fatal — forms render but session_id will be 0; endpoint will reject.
        if ($ff_loadErr === null) {
            $ff_loadErr = 'Session open/lookup error: ' . $_sessErr->getMessage();
        }
        $ff_sessionId = 0;
    }
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
