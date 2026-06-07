<?php
declare(strict_types=1);
/**
 * sb-board.php — Lots en cours (Mother Shell board, Atom 3).
 *
 * Renders the "Theater of Operations" diorama: 5 production zones, each
 * showing its active mother-shell cards + vessel illustrations.
 * The page is data-driven via app/sb-board.php (Atom 1); SVGs come from
 * app/svg-vessels.php (Atom 2). No JS file yet (Atom 6).
 *
 * Auth: require_login() — all logged-in operators.
 * Body class: home sb-board (home = standard layout; sb-board = CSS scope).
 * Active module: sb-board (topbar highlights the new entry).
 *
 * Reuse anchors (DO NOT FORK):
 *   app/auth.php        — require_login(), current_user()
 *   app/csrf.php        — csrf_token()
 *   app/db.php          — maltytask_pdo() (pulled in via auth)
 *   app/sb-board.php    — sb_open_mothers(), SB_ZONES
 *   app/svg-vessels.php — svg_vessel_cct(), svg_vessel_bbt(),
 *                          svg_vessel_kettle(), svg_vessel_packaging_line()
 *   app/partials/sidebar.php, topbar.php — standard nav shell
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/sb-board.php';
require_once __DIR__ . '/../../app/svg-vessels.php';

require_page_access('sb-board');
$me  = current_user();
$pdo = maltytask_pdo();

// ─── Data fetch ───────────────────────────────────────────────────────────────

$byZone = sb_open_mothers($pdo);
$csrf   = csrf_token();

// Recently-closed lots for ghost strip (last 3 closed mothers).
// RULE-2 BLOCK fix: routes through sb_recent_closed_mothers() in atom 1's API
// (single-source-of-truth contract for all board reads).
$closedMothers = sb_recent_closed_mothers($pdo, 3);

// Atoms 14+15: corrected occupancy engine.
// CCT occupancy from bd_brewing_brewday_v2 (sb_fermentation_occupancy via sb_merged_occupancy).
// BBT occupancy from bd_racking_v2 survivor model (sb_observed_occupancy via sb_merged_occupancy).
// $occupancy is NOT used for vessel SVG grids (grids removed — replaced by availability widget).
// $fleet is used by the empty-zone fallback strings and the avail-bar segment loops.
$fleet            = sb_fleet($pdo);                // ['cct'=>[…],'bbt'=>[…],…]
$occupancy        = sb_merged_occupancy($pdo);     // keyed "cct-N"/"bbt-N"
$fleetAvail       = sb_fleet_availability($pdo);   // per-kind {active,occupied,available}

// Observed in-flight cards — bucketed by zone, reuses merged occupancy (no extra DB queries).
// READ-ONLY projection. op_sessions (true mothers) path is unaffected and rendered in parallel.
$observedInFlight = sb_observed_in_flight($pdo); // ['fermentation' => [...], 'bbt' => [...]]

// Total open mothers across all zones (expedition excluded by sb_open_mothers)
$totalMothers = 0;
foreach (SB_ZONES as $z) {
    if ($z !== 'expedition') {
        $totalMothers += count($byZone[$z] ?? []);
    }
}

// Atom 18: Bottom batch-list removed — dedup pre-count no longer needed.

// Recipe lookup map for the retro-link modal (admin only, but fetched once for simplicity).
// Keyed by recipe id → name; injected as window.SB_RECIPES in the page.
$recipesForRl = [];
if (($me['role'] ?? '') === 'admin') {
    $rlStmt = $pdo->query("SELECT id, name FROM ref_recipes ORDER BY name");
    foreach ($rlStmt->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        $recipesForRl[(int) $rr['id']] = (string) $rr['name'];
    }
}

// ─── Local helpers ────────────────────────────────────────────────────────────

/** Escape for HTML output. */
function sbb_esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** French short date: "3 mai" */
function sbb_date_fr(string $dt): string
{
    $ts = strtotime($dt);
    if ($ts === false) {
        return '—';
    }
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
    return (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];
}

/** French short datetime: "3 mai, 14h22" */
function sbb_date_time_fr(string $dt): string
{
    $ts = strtotime($dt);
    if ($ts === false) {
        return '—';
    }
    $months = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
    $date   = (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];
    $time   = date('H', $ts) . 'h' . date('i', $ts);
    return $date . ', ' . $time;
}

/** Map a board zone to its mother-card phase CSS modifier. */
function sbb_zone_phase_class(string $zone): string
{
    return match ($zone) {
        'brasserie'       => 'brewing',
        'fermentation'    => 'fermenting',
        'bbt'             => 'racking',
        'conditionnement' => 'packaging',
        default           => '',
    };
}

/** Human-readable zone label. */
function sbb_zone_label(string $zone): string
{
    return match ($zone) {
        'brasserie'       => 'Brasserie',
        'fermentation'    => 'Cave Fermentation',
        'bbt'             => 'Salle BBT',
        'conditionnement' => 'Conditionnement',
        'expedition'      => 'Expédition',
        default           => ucfirst($zone),
    };
}

/** Aria-label for zone with count. */
function sbb_zone_aria(string $zone, int $count): string
{
    $label = sbb_zone_label($zone);
    return $count === 0
        ? "{$label} — aucun lot actif"
        : "{$label} — {$count} lot" . ($count > 1 ? 's' : '') . ' actif' . ($count > 1 ? 's' : '');
}

/**
 * Render a mother card for a given zone.
 * Returns escaped HTML string — safe to echo directly.
 */
function sbb_render_mother_card(array $m, string $zone): string
{
    $phaseClass  = sbb_zone_phase_class($zone);
    $motherId    = (int) $m['id'];
    $batch       = sbb_esc($m['batch'] ?? '—');
    $recipeName  = sbb_esc($m['recipe_name'] ?? '—');
    $heartbeat   = sbb_esc($m['heartbeat_severity'] ?? 'red');
    // sbb_date_fr returns digits + French month abbrevs only — no HTML chars, no escape needed
    $openedAt    = sbb_date_fr((string)($m['opened_at'] ?? ''));
    $hasVessel   = ($m['current_vessel_kind'] !== null && $m['current_vessel_number'] !== null);
    $vesselLabel = $hasVessel
        ? sbb_esc(strtoupper((string)$m['current_vessel_kind'])) . '-' . (int)$m['current_vessel_number']
        : '';
    $hasEta      = ($m['eta_close_date'] !== null && $m['eta_close_date'] !== '');
    $eta         = $hasEta ? sbb_esc((string)$m['eta_close_date']) : '';
    $hasPct      = ($m['pct_packaged'] !== null);
    $pct         = $hasPct ? (int) $m['pct_packaged'] : 0;
    $pctWarn     = $pct >= 80 ? ' sb-progress__fill--warn' : '';

    $html  = '<div class="sb-card' . ($phaseClass ? ' sb-card--' . $phaseClass : '') . '" data-mother-id="' . $motherId . '">';
    $html .= '<div class="sb-card__top">';
    $html .= '<span class="sb-card__batch">#' . $batch . '</span>';
    $html .= '<span class="sb-heartbeat sb-heartbeat--' . $heartbeat . '" aria-label="Activité : ' . $heartbeat . '"></span>';
    $html .= '</div>';
    $html .= '<div class="sb-card__name">' . $recipeName . '</div>';
    $html .= '<div class="sb-card__meta">';
    $html .= '<span class="sb-card__meta-item">Ouvert le ' . $openedAt . '</span>';
    if ($hasVessel) {
        $html .= '<span class="sb-card__meta-dot" aria-hidden="true"></span>';
        $html .= '<span class="sb-card__meta-item sb-card__meta-item--vessel">' . $vesselLabel . '</span>';
    }
    if ($hasEta) {
        $html .= '<span class="sb-card__meta-dot" aria-hidden="true"></span>';
        $html .= '<span class="sb-eta">ETA ' . $eta . '</span>';
    }
    $html .= '</div>';
    if ($hasPct) {
        $html .= '<div class="sb-progress">';
        $html .= '<div class="sb-progress__fill' . $pctWarn . '" style="width:' . $pct . '%" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"></div>';
        $html .= '</div>';
    }
    $html .= '<a href="/modules/sb-mother.php?id=' . $motherId . '" class="sb-card__link">';
    $html .= 'Voir <span class="sb-card__link-arrow" aria-hidden="true">→</span>';
    $html .= '</a>';
    $html .= '</div>';

    return $html;
}

/**
 * Render a minimal chip for an in-flight observed batch (Atom 18 re-skin).
 *
 * Face: "{recipe_name} · #{batch} · {vessel_label}" — three tokens, one line.
 * The chip is a link to sb-batch.php?recipe=<id>&batch=<encoded>.
 * Provenance (observé) = muted/faint styling, not text badge.
 * Abandoned = distinct warning border tint, not text badge.
 * Assemblage = tiny "⋈" glyph appended to the text with a title tooltip.
 *
 * Returns escaped HTML string — safe to echo directly.
 */
function sbb_render_observed_chip(array $c): string
{
    $vesselLabel  = sbb_esc($c['vessel_label']);
    $recipeName   = sbb_esc($c['recipe_name'] ?: '—');
    $batch        = sbb_esc($c['batch']);
    $recipeId     = (int) ($c['recipe_id'] ?? 0);
    $isBbt        = str_starts_with((string) ($c['vessel_key'] ?? ''), 'bbt-');
    $isAssemblage = !empty($c['is_assemblage']);
    $isAbandoned  = !empty($c['is_abandoned']);
    $daysInTank   = isset($c['days_in_tank']) && $c['days_in_tank'] !== null
                      ? (int) $c['days_in_tank'] : null;

    // Tooltip carries the richer context (vessel · event date · flags) since the chip face is minimal.
    $eventDate = $isBbt ? ($c['racked_on'] ?? '') : ($c['brewed_on'] ?? '');
    $eventVerb = $isBbt ? 'soutiré le' : 'brassé le';
    $daysStr   = $daysInTank !== null ? ' · ' . $daysInTank . ' j' : '';
    $tooltip   = $vesselLabel . ' · ' . $eventVerb . ' ' . sbb_esc((string) $eventDate) . $daysStr;
    if ($isAbandoned) {
        $tooltip .= ' · ABANDONNÉ ?';
    }
    if ($isAssemblage) {
        $tooltip .= ' · assemblage';
    }

    // URL: recipe id as int, batch rawurlencode'd — sb-batch.php stub (atom 18); drill-in = atom 19.
    $href = '/modules/sb-batch.php?recipe=' . $recipeId . '&amp;batch=' . rawurlencode((string)($c['batch'] ?? ''));

    $chipClass = 'sb-observed-chip';
    if ($isAbandoned) {
        $chipClass .= ' sb-observed-chip--abandoned';
    }

    $html  = '<a class="' . $chipClass . '" href="' . $href . '" title="' . $tooltip . '"';
    $html .= ' aria-label="' . $recipeName . ' #' . $batch . ' — ' . $vesselLabel . ' (observé)">';
    $html .= '<span class="sb-observed-chip__text">';
    /* Line 1: recipe name — DM Sans, readable weight */
    $html .= '<span class="sb-observed-chip__name">' . $recipeName . '</span>';
    /* Line 2: #batch · vessel (+ assemblage glyph if set) */
    $html .= '<span class="sb-observed-chip__meta">#' . $batch . ' · ' . $vesselLabel;
    if ($isAssemblage) {
        $html .= ' <span class="sb-observed-chip__assemblage" title="assemblage" aria-label="assemblage">⋈</span>';
    }
    $html .= '</span>';
    $html .= '</span>';
    $html .= '</a>';

    return $html;
}

// ─── Page variables ───────────────────────────────────────────────────────────

$active_module = 'sb-board';
$cssAppV       = @filemtime(__DIR__ . '/../css/app.css')      ?: time();
$cssBoardV     = @filemtime(__DIR__ . '/../css/sb-board.css') ?: time();
$jsBoardV      = @filemtime(__DIR__ . '/../js/sb-board.js')   ?: time();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Lots en cours — MaltyTask</title>
  <meta name="csrf-token" content="<?= sbb_esc($csrf) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $cssAppV ?>">
  <link rel="stylesheet" href="/css/sb-board.css?v=<?= $cssBoardV ?>">
</head>
<body class="home sb-board">

<!-- Engineering registration marks (schematic corners) -->
<div class="sb-reg-mark sb-reg-mark--tl" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--tr" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--bl" aria-hidden="true"></div>
<div class="sb-reg-mark sb-reg-mark--br" aria-hidden="true"></div>

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">
<div class="sb-board-wrap">

  <!-- Board controls row — sync pill + admin retro-link, right-aligned as a flex unit -->
  <div class="sb-board-controls">
    <?php if (($me['role'] ?? '') === 'admin'): ?>
    <button class="sb-rl-trigger" id="sb-rl-trigger"
            onclick="window.sbRetroLink && window.sbRetroLink.open()"
            title="Voir les propositions de liaisons rétroactives (admin)">
      ⇆ Retro-link
    </button>
    <?php endif ?>
    <div class="sb-sync-ts" data-sb-fetched-at aria-live="polite"></div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════
       DIORAMA — Theater of Operations (5 production zones)
  ══════════════════════════════════════════════════════════════════ -->
  <section class="sb-diorama" aria-label="Tableau des lots par phase<?= $totalMothers === 0 ? ' — aucun lot actif' : '' ?>">

    <?php foreach (SB_ZONES as $zone):
        $mothers   = $byZone[$zone] ?? [];
        $zoneKey   = 'zone-' . $zone . '-label';
        $phaseClass = sbb_zone_phase_class($zone);
        $isExpedition = ($zone === 'expedition');
        // FIX 1: Badge must reflect both true mothers AND observed in-flight cards.
        // Fermentation and BBT zones each get an effective count so the badge is
        // never "0" while cards are visible beneath it.  Other zones (brasserie,
        // conditionnement, expedition) have no observed cards — effective == true count.
        $observedCountForZone = match ($zone) {
            'fermentation' => count($observedInFlight['fermentation'] ?? []),
            'bbt'          => count($observedInFlight['bbt'] ?? []),
            default        => 0,
        };
        $zoneCount = count($mothers) + $observedCountForZone;
    ?>

    <?php if ($isExpedition): ?>
    <!-- ── ZONE 5: EXPÉDITION (v1 placeholder) ── -->
    <div class="sb-zone sb-zone--placeholder" role="region" aria-labelledby="<?= $zoneKey ?>">
      <div class="sb-zone__header">
        <h2 id="<?= $zoneKey ?>" class="sb-zone__label"><?= sbb_zone_label($zone) ?></h2>
        <div class="sb-zone__corner" aria-hidden="true"></div>
      </div>
      <div class="sb-zone__body">
        <div class="sb-placeholder-msg">
          <div class="sb-placeholder-msg__icon" aria-hidden="true">🚛</div>
          <div class="sb-placeholder-msg__label">Phase 3</div>
          <div class="sb-placeholder-msg__phase">Intégration planifiée</div>
        </div>
      </div>
    </div>

    <?php elseif ($zone === 'brasserie'): ?>
    <!-- ── ZONE 1: BRASSERIE ── -->
    <div class="sb-zone sb-zone--brasserie" role="region" aria-labelledby="<?= $zoneKey ?>">
      <div class="sb-zone__header">
        <h2 id="<?= $zoneKey ?>" class="sb-zone__label">Brasserie</h2>
        <span class="sb-zone__count<?= $zoneCount === 0 ? ' sb-zone__count--zero' : '' ?>"
              aria-label="<?= $zoneCount ?> lot<?= $zoneCount > 1 ? 's' : '' ?>"><?= $zoneCount ?></span>
        <div class="sb-zone__corner" aria-hidden="true"></div>
      </div>
      <div class="sb-zone__body">
        <span class="sb-annotation sb-annotation--bl" aria-hidden="true">Brew ·</span>

        <!-- Cards area -->
        <div class="sb-zone__cards">
          <?php if ($zoneCount > 0): ?>
          <div class="sb-cards-stack">
            <?php foreach ($mothers as $m): ?>
            <?= sbb_render_mother_card($m, $zone) ?>
            <?php endforeach ?>
          </div>
          <?php else: ?>
          <div class="sb-zone-empty">
            <div class="sb-zone-empty__msg">Aucun lot en brassage</div>
            <a href="#"
               class="sb-zone-empty__cta"
               aria-disabled="true"
               title="Disponible après pilot 6 (brassage)"
               tabindex="-1">Démarrer un brassin →</a>
          </div>
          <?php endif ?>
        </div>

        <!-- Vessel stage — brewhouse kettle -->
        <div class="sb-vessel-stage">
          <div class="sb-kettle-wrap">
            <?= svg_vessel_kettle(1, 'idle') ?>
          </div>
          <div class="sb-ground" aria-hidden="true"></div>
        </div>
      </div>
    </div>

    <?php elseif ($zone === 'fermentation'): ?>
    <!-- ── ZONE 2: CAVE FERMENTATION ── -->
    <div class="sb-zone sb-zone--fermentation" role="region" aria-labelledby="<?= $zoneKey ?>">
      <div class="sb-zone__header">
        <h2 id="<?= $zoneKey ?>" class="sb-zone__label">Cave Fermentation</h2>
        <span class="sb-zone__count<?= $zoneCount === 0 ? ' sb-zone__count--zero' : '' ?>"
              aria-label="<?= $zoneCount ?> lot<?= $zoneCount > 1 ? 's' : '' ?>"><?= $zoneCount ?></span>
        <div class="sb-zone__corner" aria-hidden="true"></div>
      </div>
      <div class="sb-zone__body">
        <span class="sb-annotation sb-annotation--br" aria-hidden="true">CCT</span>

        <!-- Cards area -->
        <div class="sb-zone__cards">
          <?php
            $fermObserved = $observedInFlight['fermentation'] ?? [];
            $hasFermCards = ($zoneCount > 0 || count($fermObserved) > 0);
          ?>
          <?php if ($hasFermCards): ?>
          <div class="sb-cards-stack">
            <?php foreach ($mothers as $m): ?>
            <?= sbb_render_mother_card($m, $zone) ?>
            <?php endforeach ?>
          </div>
          <?php if (count($fermObserved) > 0): ?>
          <div class="sb-chips-pack">
            <?php foreach ($fermObserved as $c): ?>
            <?= sbb_render_observed_chip($c) ?>
            <?php endforeach ?>
          </div>
          <?php endif ?>
          <?php else: ?>
          <div class="sb-zone-empty">
            <div class="sb-zone-empty__msg">Aucun lot en fermentation</div>
            <div class="sb-zone-empty__msg sb-zone-empty__msg--sub"><?= count($fleet['cct']) ?> CCTs disponibles</div>
          </div>
          <?php endif ?>
        </div>

        <!-- Availability widget — replaces per-tank CCT SVG grid (Atom 14) -->
        <?php
            $cctAvail     = $fleetAvail['cct'];
            $cctOccupied  = $cctAvail['occupied'];
            $cctActive    = $cctAvail['active'];
            $cctAvailable = $cctAvail['available'];
            $cctAbandonedCount = 0;
            foreach ($observedInFlight['fermentation'] ?? [] as $card) {
                if (!empty($card['is_abandoned'])) { $cctAbandonedCount++; }
            }
        ?>
        <div class="sb-avail-widget">
          <div class="sb-avail-widget__label">
            Cuves disponibles
            <span class="sb-avail-widget__count"><?= $cctAvailable ?>/<?= $cctActive ?></span>
          </div>
          <div class="sb-avail-bar" role="img" aria-label="<?= $cctOccupied ?> cuve<?= $cctOccupied > 1 ? 's' : '' ?> occupée<?= $cctOccupied > 1 ? 's' : '' ?> sur <?= $cctActive ?>">
            <?php foreach ($fleet['cct'] as $_cctVessel): ?>
              <?php
                $num     = (int)$_cctVessel['number'];
                $segKey  = 'cct-' . $num;
                $segOcc  = isset($occupancy[$segKey]);
                // CCT abandoned state not tracked separately; always false
                $segClass = $segOcc ? 'sb-avail-bar__seg--occupied' : 'sb-avail-bar__seg--free';
                $segTitle = $segOcc
                    ? ('CCT-' . $num . ': ' . ($occupancy[$segKey]['recipe_name'] ?? '') . ' #' . ($occupancy[$segKey]['batch'] ?? ''))
                    : ('CCT-' . $num . ': libre');
              ?>
              <div class="sb-avail-bar__seg <?= $segClass ?>" title="<?= sbb_esc($segTitle) ?>"></div>
            <?php endforeach ?>
          </div>
          <?php if ($cctAbandonedCount > 0): ?>
          <div class="sb-avail-widget__note">
            <?= $cctAbandonedCount ?> lot<?= $cctAbandonedCount > 1 ? 's' : '' ?> potentiellement abandonné<?= $cctAbandonedCount > 1 ? 's' : '' ?>
          </div>
          <?php endif ?>
        </div>
      </div>
    </div>

    <?php elseif ($zone === 'bbt'): ?>
    <!-- ── ZONE 3: SALLE BBT ── -->
    <div class="sb-zone sb-zone--bbt" role="region" aria-labelledby="<?= $zoneKey ?>">
      <div class="sb-zone__header">
        <h2 id="<?= $zoneKey ?>" class="sb-zone__label">Salle BBT</h2>
        <span class="sb-zone__count<?= $zoneCount === 0 ? ' sb-zone__count--zero' : '' ?>"
              aria-label="<?= $zoneCount ?> lot<?= $zoneCount > 1 ? 's' : '' ?>"><?= $zoneCount ?></span>
        <div class="sb-zone__corner" aria-hidden="true"></div>
      </div>
      <div class="sb-zone__body">
        <span class="sb-annotation sb-annotation--br" aria-hidden="true">BBT</span>

        <!-- Cards area -->
        <div class="sb-zone__cards">
          <?php
            $bbtObserved = $observedInFlight['bbt'] ?? [];
            $hasBbtCards = ($zoneCount > 0 || count($bbtObserved) > 0);
          ?>
          <?php if ($hasBbtCards): ?>
          <div class="sb-cards-stack">
            <?php foreach ($mothers as $m): ?>
            <?= sbb_render_mother_card($m, $zone) ?>
            <?php endforeach ?>
          </div>
          <?php if (count($bbtObserved) > 0): ?>
          <div class="sb-chips-pack">
            <?php foreach ($bbtObserved as $c): ?>
            <?= sbb_render_observed_chip($c) ?>
            <?php endforeach ?>
          </div>
          <?php endif ?>
          <?php else: ?>
          <div class="sb-zone-empty">
            <div class="sb-zone-empty__msg">Aucun lot en soutirage</div>
            <div class="sb-zone-empty__msg sb-zone-empty__msg--sub"><?= count($fleet['bbt']) ?> BBTs disponibles</div>
          </div>
          <?php endif ?>
        </div>

        <!-- Availability widget — replaces per-tank BBT SVG grid (Atom 14) -->
        <?php
            $bbtAvail     = $fleetAvail['bbt'];
            $bbtOccupied  = $bbtAvail['occupied'];
            $bbtActive    = $bbtAvail['active'];
            $bbtAvailable = $bbtAvail['available'];
            $bbtAbandonedCount = 0;
            foreach ($observedInFlight['bbt'] ?? [] as $card) {
                if (!empty($card['is_abandoned'])) { $bbtAbandonedCount++; }
            }
            $bbtAssemblageCount = 0;
            foreach ($observedInFlight['bbt'] ?? [] as $card) {
                if (!empty($card['is_assemblage'])) { $bbtAssemblageCount++; }
            }
        ?>
        <div class="sb-avail-widget sb-avail-widget--bbt">
          <div class="sb-avail-widget__label">
            BBT disponibles
            <span class="sb-avail-widget__count"><?= $bbtAvailable ?>/<?= $bbtActive ?></span>
          </div>
          <div class="sb-avail-bar" role="img" aria-label="<?= $bbtOccupied ?> BBT<?= $bbtOccupied > 1 ? 's' : '' ?> occupé<?= $bbtOccupied > 1 ? 's' : '' ?> sur <?= $bbtActive ?>">
            <?php foreach ($fleet['bbt'] as $_bbtVessel): ?>
              <?php
                $num      = (int)$_bbtVessel['number'];
                $segKey   = 'bbt-' . $num;
                $segOcc   = isset($occupancy[$segKey]);
                $segAbnd  = $segOcc && !empty($occupancy[$segKey]['is_abandoned'] ?? false);
                $segClass = 'sb-avail-bar__seg--free';
                if ($segOcc && $segAbnd) {
                    $segClass = 'sb-avail-bar__seg--abandoned';
                } elseif ($segOcc) {
                    $segClass = 'sb-avail-bar__seg--occupied';
                }
                $segTitle = $segOcc
                    ? ('BBT-' . $num . ': ' . ($occupancy[$segKey]['recipe_name'] ?? '') . ' #' . ($occupancy[$segKey]['batch'] ?? ''))
                    : ('BBT-' . $num . ': libre');
              ?>
              <div class="sb-avail-bar__seg <?= $segClass ?>" title="<?= sbb_esc($segTitle) ?>"></div>
            <?php endforeach ?>
          </div>
          <?php if ($bbtAbandonedCount > 0): ?>
          <div class="sb-avail-widget__note">
            <?= $bbtAbandonedCount ?> BBT<?= $bbtAbandonedCount > 1 ? 's' : '' ?> potentiellement abandonné<?= $bbtAbandonedCount > 1 ? 's' : '' ?>
          </div>
          <?php endif ?>
          <?php if ($bbtAssemblageCount > 0): ?>
          <div class="sb-avail-widget__note sb-avail-widget__note--assemblage">
            <?= $bbtAssemblageCount ?> assemblage<?= $bbtAssemblageCount > 1 ? 's' : '' ?> en cours
          </div>
          <?php endif ?>
        </div>
      </div>
    </div>

    <?php elseif ($zone === 'conditionnement'): ?>
    <!-- ── ZONE 4: CONDITIONNEMENT ── -->
    <div class="sb-zone sb-zone--conditionnement" role="region" aria-labelledby="<?= $zoneKey ?>">
      <div class="sb-zone__header">
        <h2 id="<?= $zoneKey ?>" class="sb-zone__label">Conditionnement</h2>
        <span class="sb-zone__count<?= $zoneCount === 0 ? ' sb-zone__count--zero' : '' ?>"
              aria-label="<?= $zoneCount ?> lot<?= $zoneCount > 1 ? 's' : '' ?>"><?= $zoneCount ?></span>
        <div class="sb-zone__corner" aria-hidden="true"></div>
      </div>
      <div class="sb-zone__body">

        <!-- Cards area -->
        <div class="sb-zone__cards">
          <?php if ($zoneCount > 0): ?>
          <div class="sb-cards-stack">
            <?php foreach ($mothers as $m): ?>
            <?= sbb_render_mother_card($m, $zone) ?>
            <?php endforeach ?>
          </div>
          <?php else: ?>
          <div class="sb-zone-empty">
            <div class="sb-zone-empty__msg">Aucun lot en conditionnement</div>
          </div>
          <?php endif ?>
        </div>

        <!-- Vessel stage — packaging line, idle in v1 -->
        <div class="sb-vessel-stage">
          <div style="width:90%;margin:0 auto;" aria-hidden="true">
            <?= svg_vessel_packaging_line('idle') ?>
          </div>
          <div class="sb-ground" aria-hidden="true"></div>
        </div>
      </div>
    </div>

    <?php endif ?>
    <?php endforeach ?>

  </section><!-- /sb-diorama -->

  <!-- ══════════════════════════════════════════════════════════════
       GHOST STRIP — recently closed lots
  ══════════════════════════════════════════════════════════════════ -->
  <div class="sb-closed-strip" role="region" aria-label="Lots récemment clos">
    <span class="sb-closed-strip__label">Clos récemment</span>

    <?php if (empty($closedMothers)): ?>
    <span style="font-family:'JetBrains Mono',monospace;font-size:0.54rem;letter-spacing:0.06em;color:var(--ink-faint);">
      Aucun lot clos récemment
    </span>
    <?php else: ?>
    <?php foreach ($closedMothers as $cm):
        $closedDate = $cm['closed_at'] ? sbb_date_fr((string)$cm['closed_at']) : '—';
        $daysOpen   = $cm['days_open'] !== null ? (int)$cm['days_open'] . ' j' : '—';
        $titleStr   = sbb_esc(($cm['recipe_name'] ?? '?') . ' #' . ($cm['batch'] ?? '?') . ' — clos ' . ($cm['closed_at'] ?? ''));
    ?>
    <div class="sb-ghost-card" tabindex="0" title="<?= $titleStr ?>">
      <span class="sb-ghost-card__closed-stamp">Clôturé</span>
      <div>
        <div class="sb-ghost-card__name"><em style="font-style:italic;color:var(--ink-mute);"><?= sbb_esc($cm['recipe_name'] ?? '—') ?></em></div>
        <div class="sb-ghost-card__meta">#<?= sbb_esc($cm['batch'] ?? '—') ?> · <?= $daysOpen ?> · <?= sbb_esc($closedDate) ?></div>
      </div>
    </div>
    <?php endforeach ?>

    <a href="/modules/sessions.php"
       style="font-family:'JetBrains Mono',monospace;font-size:0.54rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--oak);text-decoration:none;margin-left:8px;white-space:nowrap;">
      Journal de bord →
    </a>
    <?php endif ?>
  </div><!-- /sb-closed-strip -->

</div><!-- /sb-board-wrap -->
</main>

<?php if (($me['role'] ?? '') === 'admin'): ?>
<script>
/* Retro-link recipe lookup map — admin only (id → name). */
window.SB_RECIPES = <?= json_encode($recipesForRl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php require __DIR__ . '/../../app/partials/sb-retro-link-modal.php' ?>
<?php endif ?>
<script src="/js/sb-board.js?v=<?= $jsBoardV ?>" defer></script>
</body>
</html>
