<?php
declare(strict_types=1);
/**
 * /modules/salle-des-machines.php
 * Salle des Machines — Capacités & Commissioning
 *
 * Three-section slide page: Brassage / Fermentation / Conditionnement.
 * Wired to canonical tables:
 *   Conditionnement : ref_process_machines, ref_filler_containers,
 *                     dbc_container_types, dbc_equipment_types,
 *                     ref_container_mi, ref_packaging_formats
 *   Fermentation    : ref_cct, ref_bbt, ref_yt   (SAME as vessels.php)
 *   Brassage        : ref_brewhouse_vessels, ref_brewhouse_size
 *
 * Writes use the SAME columns/semantics as vessels.php for CCT/BBT/YT
 * so vessels.php can be retired without a data migration.
 *
 * Role gate: admin = full edit, manager = read+propose stub, opérateur = read.
 * POST: csrf → action → transaction → log_revision → PRG redirect.
 * Snapshots: written to data/snapshots/ before any DELETE.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/db-write-helpers.php';
require __DIR__ . '/../../app/settings-helpers.php';
require_page_access('zeppelin');
$me = current_user();

/* ── Role gate ──────────────────────────────────────────────────── */
if (is_admin($me)) {
    $bodyRole = 'admin';
} elseif (is_manager($me)) {
    $bodyRole = 'manager';
} else {
    $bodyRole = 'operateur';
}

$active_module = 'salle-des-machines';

/* ── POST handler ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/salle-des-machines.php');
    }
    if ($bodyRole === 'operateur') {
        flash_set('err', 'Accès refusé.');
        redirect_to('/modules/salle-des-machines.php');
    }
    if ($bodyRole === 'manager') {
        flash_set('ok', 'Demande de modification envoyée à l\'administrateur (stub — non persistée).');
        redirect_to('/modules/salle-des-machines.php');
    }

    /* admin-only write paths */
    $pdo    = maltytask_pdo();
    $action = $_POST['action'] ?? '';

    try {
        /* ── Vessel CRUD (CCT / BBT / YT / brewhouse) ─────────────
         * Mirrors vessels.php exactly: same columns, same semantics.
         */
        if ($action === 'vessel-update') {
            $tableKey = must_be_one_of('table', $_POST['table'] ?? '', ['cct','bbt','yt','brewhouse']);
            $id       = post_int('id');
            $cap      = post_decimal('capacity_hl');
            $volHl    = post_decimal('volume_hl');   // brewhouse uses volume_hl

            if ($id === null) throw new RuntimeException('ID manquant.');

            if ($tableKey === 'brewhouse') {
                $before = bd_fetch_before($pdo, 'ref_brewhouse_vessels', $id);
                $stmt   = $pdo->prepare(
                    'UPDATE ref_brewhouse_vessels SET volume_hl = ?, updated_at = NOW() WHERE id = ?'
                );
                $stmt->execute([$volHl ?? $cap, $id]);
                $after = ['volume_hl' => $volHl ?? $cap];
            } else {
                $tbl    = ['cct'=>'ref_cct','bbt'=>'ref_bbt','yt'=>'ref_yt'][$tableKey];
                $before = bd_fetch_before($pdo, $tbl, $id);
                $stmt   = $pdo->prepare(
                    "UPDATE `{$tbl}` SET capacity_hl = ? WHERE id = ?"
                );
                $stmt->execute([$cap, $id]);
                $after = ['capacity_hl' => $cap];
            }
            log_revision($pdo, $me, $tableKey, (int) $id, $before, $after, 'capacity-update');
            flash_set('ok', 'Capacité mise à jour.');

        } elseif ($action === 'vessel-del') {
            $tableKey = must_be_one_of('table', $_POST['table'] ?? '', ['cct','bbt','yt','brewhouse']);
            $id       = post_int('id');
            if ($id === null) throw new RuntimeException('ID manquant.');

            $tbl    = ['cct'=>'ref_cct','bbt'=>'ref_bbt','yt'=>'ref_yt','brewhouse'=>'ref_brewhouse_vessels'][$tableKey];
            $before = bd_fetch_before($pdo, $tbl, $id);

            /* snapshot before delete */
            $snapDir = __DIR__ . '/../../data/snapshots';
            if (is_dir($snapDir)) {
                $snap = $snapDir . '/' . $tbl . '-del-' . date('Ymd-His') . '.json';
                file_put_contents($snap, json_encode([$before], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            $stmt = $pdo->prepare("DELETE FROM `{$tbl}` WHERE id = ?");
            $stmt->execute([$id]);
            log_revision($pdo, $me, $tbl, (int) $id, $before, [], 'delete');
            flash_set('ok', 'Cuve supprimée.');

        } elseif ($action === 'add' && ($_POST['zone'] ?? '') === 'cct') {
            $cap = post_decimal('capacity_hl');
            $stmt = $pdo->prepare(
                "INSERT INTO ref_cct (number, capacity_hl, status, notes) VALUES
                 ((SELECT COALESCE(MAX(number),0)+1 FROM ref_cct AS _t), ?, 'active', ?)"
            );
            $stmt->execute([$cap, 'seed via Salle des Machines']);
            $newId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_cct', $newId, null, ['capacity_hl'=>$cap], 'create');
            flash_set('ok', "CCT ajouté ({$cap} HL).");

        } elseif ($action === 'add' && ($_POST['zone'] ?? '') === 'bbt') {
            $cap = post_decimal('capacity_hl');
            $stmt = $pdo->prepare(
                "INSERT INTO ref_bbt (number, capacity_hl, status, notes) VALUES
                 ((SELECT COALESCE(MAX(number),0)+1 FROM ref_bbt AS _t), ?, 'active', ?)"
            );
            $stmt->execute([$cap, 'seed via Salle des Machines']);
            $newId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_bbt', $newId, null, ['capacity_hl'=>$cap], 'create');
            flash_set('ok', "BBT ajouté ({$cap} HL).");

        } elseif ($action === 'add' && ($_POST['zone'] ?? '') === 'yt') {
            $cap = post_decimal('capacity_hl');
            $stmt = $pdo->prepare(
                "INSERT INTO ref_yt (number, capacity_hl, status, notes) VALUES
                 ((SELECT COALESCE(MAX(number),0)+1 FROM ref_yt AS _t), ?, 'active', ?)"
            );
            $stmt->execute([$cap, 'seed via Salle des Machines']);
            $newId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_yt', $newId, null, ['capacity_hl'=>$cap], 'create');
            flash_set('ok', "YT ajouté ({$cap} HL).");

        } elseif ($action === 'add' && in_array($_POST['zone'] ?? '', ['water','hot'], true)) {
            $vesselType = must_be_one_of('vessel_type', $_POST['vessel_type'] ?? '',
                ['hlt','clt','mash','lauter','buffer','kettle','whirlpool']);
            $vol = post_decimal('volume_hl');
            $stmt = $pdo->prepare(
                "INSERT INTO ref_brewhouse_vessels (vessel_type, number, volume_hl, is_active, notes) VALUES
                 (?, (SELECT COALESCE(MAX(number),0)+1 FROM ref_brewhouse_vessels AS _t WHERE vessel_type=?), ?, 1, ?)"
            );
            $stmt->execute([$vesselType, $vesselType, $vol, 'seed via Salle des Machines']);
            $newId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_brewhouse_vessels', $newId, null, ['vessel_type'=>$vesselType,'volume_hl'=>$vol], 'create');
            flash_set('ok', "Cuve brassage ajoutée ({$vol} HL).");

        } elseif ($action === 'machine-update') {
            $id    = post_int('id');
            $field = must_be_one_of('field', $_POST['field'] ?? '', ['throughput_hl_h','speed_units_h','temp_c']);
            $val   = post_decimal('value');
            if ($id === null) throw new RuntimeException('ID manquant.');
            $before = bd_fetch_before($pdo, 'ref_process_machines', $id);
            $stmt   = $pdo->prepare("UPDATE ref_process_machines SET `{$field}` = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$val, $id]);
            log_revision($pdo, $me, 'ref_process_machines', $id, $before, [$field => $val], 'machine-update');
            flash_set('ok', 'Machine mise à jour.');

        } elseif ($action === 'machine-del') {
            $id = post_int('id');
            if ($id === null) throw new RuntimeException('ID manquant.');
            $before  = bd_fetch_before($pdo, 'ref_process_machines', $id);
            $snapDir = __DIR__ . '/../../data/snapshots';
            if (is_dir($snapDir)) {
                $snap = $snapDir . '/ref_process_machines-del-' . date('Ymd-His') . '.json';
                file_put_contents($snap, json_encode([$before], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $pdo->prepare('DELETE FROM ref_filler_containers WHERE machine_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ref_process_machines WHERE id = ?')->execute([$id]);
            log_revision($pdo, $me, 'ref_process_machines', $id, $before, [], 'delete');
            flash_set('ok', 'Machine supprimée.');

        } elseif ($action === 'add' && in_array($_POST['zone'] ?? '', ['cellar-machine','pkg-machine'], true)) {
            $machType = must_be_one_of('machine_type', $_POST['machine_type'] ?? '',
                ['centrifuge','kze','filler_bottle','filler_can','filler_keg','cartoner','filler_cuv']);
            $thru  = post_decimal('throughput_hl_h');
            $speed = post_int('speed_units_h');
            /* resolve catalog_id */
            $cRow  = $pdo->prepare('SELECT id FROM dbc_equipment_types WHERE machine_type = ? LIMIT 1');
            $cRow->execute([$machType]);
            $catalogId = ($cRow->fetch(PDO::FETCH_ASSOC)['id'] ?? null);

            $stmt = $pdo->prepare(
                'INSERT INTO ref_process_machines
                 (machine_type, throughput_hl_h, speed_units_h, is_active, catalog_id, notes,
                  effective_from, effective_until)
                 VALUES (?, ?, ?, 1, ?, ?, NOW(), "9999-12-31 23:59:59")'
            );
            $stmt->execute([$machType, $thru, $speed, $catalogId, 'added via Salle des Machines']);
            $newId = (int) $pdo->lastInsertId();
            log_revision($pdo, $me, 'ref_process_machines', $newId, null,
                ['machine_type'=>$machType,'throughput_hl_h'=>$thru,'speed_units_h'=>$speed], 'create');
            flash_set('ok', "Machine {$machType} ajoutée.");

        } elseif ($action === 'format-toggle') {
            $id       = post_int('id');
            $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($id === null) throw new RuntimeException('ID manquant.');
            $before   = bd_fetch_before($pdo, 'ref_packaging_formats', $id);
            $stmt     = $pdo->prepare('UPDATE ref_packaging_formats SET is_active = ? WHERE id = ?');
            $stmt->execute([$isActive, $id]);
            log_revision($pdo, $me, 'ref_packaging_formats', $id, $before,
                ['is_active' => $isActive], 'format-toggle');
            $label = $isActive ? 'activé' : 'désactivé';
            flash_set('ok', "Format {$label}.");

        } else {
            flash_set('err', 'Action inconnue ou non implémentée.');
        }
    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e));
    }
    redirect_to('/modules/salle-des-machines.php');
}

/* ── GET: load all data ─────────────────────────────────────────── */
header('Content-Type: text/html; charset=utf-8');
$dbError = null;

/* defaults — page renders gracefully if tables are empty (migration 140/142 not applied) */
$ccts         = [];
$bbts         = [];
$yts          = [];
$brewhouseVessels = [];
$brewSize     = ['size_hl' => 30.000];
$pkgMachines  = [];
$fillerConts  = []; // machine_id → [{container_code, display_name}]
$containerMi  = []; // container_code → mi_id_fk
$pkgFormats   = [];
$cellarMachines = [];
$servingTanks = []; // ref_serving_tanks (in-house), seeded by migration 142

try {
    $pdo = maltytask_pdo();

    /* Fermentation vessels */
    $ccts = $pdo->query("SELECT id, number, capacity_hl, status, notes FROM ref_cct ORDER BY number")->fetchAll(PDO::FETCH_ASSOC);
    $bbts = $pdo->query("SELECT id, number, capacity_hl, status, notes FROM ref_bbt ORDER BY number")->fetchAll(PDO::FETCH_ASSOC);
    $yts  = $pdo->query("SELECT id, number, capacity_hl, status, notes FROM ref_yt  ORDER BY number")->fetchAll(PDO::FETCH_ASSOC);

    /* Brassage vessels */
    $brewhouseVessels = $pdo->query(
        "SELECT id, vessel_type, number, name, volume_hl, is_active, notes
           FROM ref_brewhouse_vessels
          WHERE is_active = 1
          ORDER BY vessel_type, number"
    )->fetchAll(PDO::FETCH_ASSOC);

    /* Brewhouse size (current row: effective_until IS NULL or max date) */
    $bsRow = $pdo->query(
        "SELECT size_hl FROM ref_brewhouse_size
          ORDER BY created_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($bsRow) $brewSize = $bsRow;

    /* Process machines — packaging (centrifuge/KZE shown in cellar panel) */
    $allMachines = $pdo->query(
        "SELECT pm.id, pm.machine_type, pm.name, pm.throughput_hl_h,
                pm.speed_units_h, pm.temp_c, pm.is_active, pm.notes,
                det.takes_containers, det.has_throughput_hl_h, det.has_speed_units_h
           FROM ref_process_machines pm
           LEFT JOIN dbc_equipment_types det ON det.id = pm.catalog_id
          WHERE pm.effective_until = '9999-12-31 23:59:59'
          ORDER BY det.sort_order, pm.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allMachines as $m) {
        if (in_array($m['machine_type'], ['centrifuge','kze'], true)) {
            $cellarMachines[] = $m;
        } else {
            $pkgMachines[] = $m;
        }
    }

    /* Filler ↔ container map */
    $fcRows = $pdo->query(
        "SELECT fc.machine_id, ct.container_code, ct.display_name
           FROM ref_filler_containers fc
           JOIN dbc_container_types   ct ON ct.id = fc.container_id
          WHERE fc.is_active = 1
            AND fc.effective_until = '9999-12-31 23:59:59'
          ORDER BY fc.machine_id, ct.sort_order"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fcRows as $r) {
        $mid = (int) $r['machine_id'];
        if (!isset($fillerConts[$mid])) $fillerConts[$mid] = [];
        $fillerConts[$mid][] = ['container_code' => $r['container_code'], 'display_name' => $r['display_name']];
    }

    /* Container → MI map (for display: shows bound MI id) */
    $cmRows = $pdo->query(
        "SELECT ct.container_code, rcm.mi_id_fk,
                ANY_VALUE(m.mi_id) AS mi_id_str, ANY_VALUE(m.name) AS mi_name
           FROM ref_container_mi rcm
           JOIN dbc_container_types ct ON ct.id = rcm.container_id
           LEFT JOIN ref_mi m          ON m.id  = rcm.mi_id_fk
          WHERE rcm.is_active = 1
            AND rcm.effective_until = '9999-12-31 23:59:59'
          GROUP BY ct.container_code, rcm.mi_id_fk"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cmRows as $r) {
        $containerMi[$r['container_code']] = (int) $r['mi_id_fk'];
    }

    /* Packaging formats */
    $pkgFormats = $pdo->query(
        "SELECT id, format_code, display_name, run_type, is_active
           FROM ref_packaging_formats
          ORDER BY FIELD(run_type,'bot','can','can33','keg','cuv',''), id"
    )->fetchAll(PDO::FETCH_ASSOC);

    /* Serving tanks — in-house only (migration 142) */
    try {
        $servingTanks = $pdo->query(
            "SELECT id, number, capacity_hl, status, notes
               FROM ref_serving_tanks
              WHERE location = 'in_house'
              ORDER BY number"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_e) {
        /* Table absent if migration 142 not yet applied — silently ignore */
        $servingTanks = [];
    }

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$csrf = csrf_token();

/* ── Stats for overview cards ──────────────────────────────────── */
$cctTotal   = array_sum(array_column($ccts, 'capacity_hl'));
$bbtTotal   = array_sum(array_column($bbts, 'capacity_hl'));
$cellarCount = count($ccts) + count($bbts) + count($yts);
$cellarCap   = $cctTotal + $bbtTotal + array_sum(array_column($yts, 'capacity_hl'));
$pkgCount    = count($pkgMachines);
/* distinct container codes across all fillers ($fillerConts: machine_id → [{container_code,…}]) */
$activeContCodes = [];
foreach ($fillerConts as $rowsForMachine) {
    foreach ($rowsForMachine as $r) { $activeContCodes[$r['container_code']] = true; }
}
$activeCont  = count($activeContCodes);

/* ── JSON blobs for JS hydration ────────────────────────────────── */
$jsBrewVessels    = json_encode(
    array_values($brewhouseVessels),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);
$jsBrewSize       = json_encode((float) $brewSize['size_hl'], JSON_UNESCAPED_UNICODE);
$jsYt             = json_encode($yts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsCct            = json_encode($ccts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsBbt            = json_encode($bbts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsCellarMachines = json_encode($cellarMachines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsPkgMachines    = json_encode($pkgMachines, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsFillerConts    = json_encode($fillerConts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsContainerMi    = json_encode($containerMi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsPkgFormats     = json_encode($pkgFormats, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsServingTanks   = json_encode($servingTanks, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$servingTankTotal = array_sum(array_column($servingTanks, 'capacity_hl'));

$_breweryId = brewery_identity();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Salle des Machines — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/salle-des-machines.css?v=<?= @filemtime(__DIR__ . '/../css/salle-des-machines.css') ?: time() ?>">
</head>
<body class="home salle-des-machines" data-role="<?= htmlspecialchars($bodyRole) ?>">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<!-- registration marks -->
<span class="sdm-mark tl"></span><span class="sdm-mark tr"></span>
<span class="sdm-mark bl"></span><span class="sdm-mark br"></span>

<div class="sdm-board"></div>
<div class="sdm-toast" id="sdmToast" role="status" aria-live="polite"></div>

<!-- csrf for JS-submitted forms -->
<input type="hidden" id="sdmCsrf" value="<?= htmlspecialchars($csrf) ?>">

<main id="main-content" class="main">

  <!-- ── chrome (breadcrumbs + role badge) ─────────────────────── -->
  <div class="sdm-chrome">
    <div class="sdm-brandmark"><?= htmlspecialchars($_breweryId['name']) ?> · Le Zeppelin · <b>Salle des Machines</b></div>
    <div class="sdm-crumbs" id="sdmCrumbs"><span class="here">Plan</span></div>
    <span class="sdm-role-badge" data-role="<?= htmlspecialchars($bodyRole) ?>"><?= htmlspecialchars($bodyRole) ?></span>
  </div>

  <?php if ($dbError): ?>
    <div class="sdm-notice err" role="alert" style="position:relative;z-index:5;margin:8px 44px;">
      Erreur DB : <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <?php
  $flash = flash_pop();
  if ($flash !== null): ?>
    <div class="sdm-notice <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"
         role="alert" style="position:relative;z-index:5;margin:8px 44px;">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif ?>

  <!-- ── sliding stage ─────────────────────────────────────────── -->
  <div class="sdm-stage" id="sdmStage">

    <!-- OVERVIEW (room 0) ──────────────────────────────────────── -->
    <section class="sdm-room">
      <div class="sdm-overview">

        <div class="sdm-overview-head">
          <div class="k">Capacités · racine de commissioning · 2026</div>
          <h1>Croquis des <em>installations</em></h1>
        </div>

        <!-- Brassage zone -->
        <div class="sdm-zone" data-sdm-go="1">
          <span class="enter">entrer ↦</span>
          <div class="znum">01 · EAU &amp; BRASSAGE</div>
          <h2>Salle de <em>Brassage</em></h2>
          <div class="sub">HLT · CLT · Maische · Lauter · Buffer · Cuite · Whirlpool · YT</div>
          <div class="scene" id="sceneBrew"></div>
          <div class="stats">
            <div class="stat">
              <div class="v"><?= htmlspecialchars((string) $brewSize['size_hl']) ?><span> HL</span></div>
              <div class="k">Brassin nominal</div>
            </div>
            <div class="stat">
              <div class="v"><?= count($brewhouseVessels) + count($yts) ?></div>
              <div class="k">Cuves</div>
            </div>
          </div>
        </div>

        <!-- Cave zone -->
        <div class="sdm-zone" data-sdm-go="2">
          <span class="enter">entrer ↦</span>
          <div class="znum">02 · CAVE</div>
          <h2>Cave de <em>Fermentation</em></h2>
          <div class="sub">CCT · BBT · YT · Centrifugeuse · KZE</div>
          <div class="scene" id="sceneCellar"></div>
          <div class="stats">
            <div class="stat">
              <div class="v"><?= $cellarCount ?></div>
              <div class="k">Cuves</div>
            </div>
            <div class="stat">
              <div class="v"><?= number_format($cellarCap, 0, ',', ' ') ?><span> HL</span></div>
              <div class="k">Capacité</div>
            </div>
          </div>
        </div>

        <!-- Conditionnement zone -->
        <div class="sdm-zone pkg" data-sdm-go="3">
          <span class="enter">entrer ↦</span>
          <div class="znum">03 · CONDITIONNEMENT</div>
          <h2>Ligne de <em>Conditionnement</em></h2>
          <div class="sub">Soutireuse bouteille · Ligne canettes · Soutireuse fûts · Encartonneuse</div>
          <div class="scene" id="scenePkg"></div>
          <div class="stats">
            <div class="stat">
              <div class="v"><?= $pkgCount ?></div>
              <div class="k">Machines</div>
            </div>
            <div class="stat">
              <div class="v"><?= $activeCont ?><span> formats</span></div>
              <div class="k">Contenants actifs</div>
            </div>
          </div>
        </div>

      </div>
    </section>

    <!-- BRASSAGE detail (room 1) ────────────────────────────────── -->
    <section class="sdm-room detail">
      <div class="sdm-detail-head">
        <button class="sdm-back" data-sdm-back="0">↤ Plan</button>
        <h1>Salle de <em>Brassage</em></h1>
        <span class="htag">eau &amp; brassage · rév <?= date('Y-m-d') ?></span>
      </div>
      <div class="sdm-req-banner">
        <span>
          <?php if ($bodyRole === 'operateur'): ?>
            Lecture seule — contactez un manager pour proposer une modification.
          <?php else: ?>
            Manager — les modifications nécessitent une validation administrateur.
          <?php endif ?>
        </span>
      </div>
      <div class="sdm-panel">
        <div class="sdm-croqui" id="croquiBrew"><span class="floorlabel">Élévation — côté chaud</span></div>
        <div class="sdm-config" id="cfgBrew"></div>
      </div>
    </section>

    <!-- CAVE detail (room 2) ────────────────────────────────────── -->
    <section class="sdm-room detail">
      <div class="sdm-detail-head">
        <button class="sdm-back" data-sdm-back="0">↤ Plan</button>
        <h1>Cave de <em>Fermentation</em></h1>
        <span class="htag">cave · rév <?= date('Y-m-d') ?></span>
      </div>
      <div class="sdm-req-banner">
        <span>
          <?php if ($bodyRole === 'operateur'): ?>
            Lecture seule.
          <?php else: ?>
            Manager — modifications soumises à validation.
          <?php endif ?>
        </span>
      </div>
      <div class="sdm-panel">
        <div class="sdm-croqui" id="croquiCellar"><span class="floorlabel">Plan — hall de fermentation</span></div>
        <div class="sdm-config" id="cfgCellar"></div>
      </div>
    </section>

    <!-- CONDITIONNEMENT detail (room 3) ─────────────────────────── -->
    <section class="sdm-room detail">
      <div class="sdm-detail-head">
        <button class="sdm-back" data-sdm-back="0">↤ Plan</button>
        <h1>Ligne de <em>Conditionnement</em></h1>
        <span class="htag">packaging · rév <?= date('Y-m-d') ?></span>
      </div>
      <div class="sdm-req-banner">
        <span>
          <?php if ($bodyRole === 'operateur'): ?>
            Lecture seule.
          <?php else: ?>
            Manager — modifications soumises à validation.
          <?php endif ?>
        </span>
      </div>
      <div class="sdm-panel">
        <div class="sdm-croqui" id="croquiPkg"><span class="floorlabel">Plan — conditionnement</span></div>
        <div class="sdm-config" id="cfgPkg"></div>
      </div>
    </section>

  </div><!-- /sdm-stage -->

</main>

<!-- footer label -->
<div class="sdm-titleblock">
  <?= htmlspecialchars($_breweryId['name']) ?> — <?= htmlspecialchars($_breweryId['city']) ?><br>
  Salle des Machines · Plan №01<br>
  rév. <?= date('Y-m-d') ?> · éch. n/a
</div>

<!-- ── server → JS data injection ─────────────────────────────── -->
<script>
window.SDM_BREW_VESSELS    = <?= $jsBrewVessels ?>;
window.SDM_BREW_SIZE       = <?= $jsBrewSize ?>;
window.SDM_YT              = <?= $jsYt ?>;
window.SDM_CCT             = <?= $jsCct ?>;
window.SDM_BBT             = <?= $jsBbt ?>;
window.SDM_CELLAR_MACHINES = <?= $jsCellarMachines ?>;
window.SDM_PKG_MACHINES    = <?= $jsPkgMachines ?>;
window.SDM_FILLER_CONTAINERS = <?= $jsFillerConts ?>;
window.SDM_CONTAINER_MI    = <?= $jsContainerMi ?>;
window.SDM_PKG_FORMATS     = <?= $jsPkgFormats ?>;
window.SDM_SERVING_TANKS   = <?= $jsServingTanks ?>;
window.SDM_SERVING_TANK_TOTAL = <?= json_encode((float) $servingTankTotal) ?>;
</script>
<script src="/js/salle-des-machines.js?v=<?= @filemtime(__DIR__ . '/../js/salle-des-machines.js') ?: time() ?>"></script>

</body>
</html>
