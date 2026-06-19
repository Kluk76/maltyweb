<?php
declare(strict_types=1);
/**
 * qa.php — QA/QC controls page.
 *
 * Three panels:
 *   A: Net content checks (qa_net_content_readings)
 *   B: Cleaning efficacy / CIP checks (qa_cleaning_efficacy_checks)
 *   C: Bottle/glass reception checks (qa_bottle_reception_checks)
 *
 * GET-render only. Submissions handled by:
 *   POST /api/qa-net-content.php
 *   POST /api/qa-cleaning-efficacy.php
 *   POST /api/qa-bottle-reception.php
 *
 * URL: /modules/qa.php[?view=all|net|cip|recep]
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';

require_page_access('qa');
$me = current_user();

header('Content-Type: text/html; charset=utf-8');

// View param — read THEN validate
$view = $_GET['view'] ?? 'all';
if (!in_array($view, ['all', 'net', 'cip', 'recep', 'eau'], true)) {
    $view = 'all';
}

// DB queries
$loadErr = null;
try {
    $pdo = maltytask_pdo();

    // Panel A: recent packaging events for dropdown (limit 50, most recent first)
    $pkgRows = $pdo->query(
        "SELECT p.id,
                COALESCE(p.neb_beer, p.contract_beer) AS beer_label,
                p.neb_batch,
                p.event_date,
                p.run_type,
                COALESCE(rs.sku_code, '') AS sku_code
           FROM bd_packaging_v2 p
           LEFT JOIN ref_skus rs ON rs.id = p.sku_id_fk
          WHERE p.is_tombstoned = 0
            AND p.event_date IS NOT NULL
          ORDER BY p.event_date DESC, p.id DESC
          LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel A readback: recent 20 qa_net_content_readings
    $netReadings = $pdo->query(
        "SELECT n.id, n.packaging_id_fk, n.reading_seq, n.measure_type,
                n.measured_value, n.target_value, n.tolerance_abs,
                n.is_conforming, n.tare_value, n.measured_at, n.comments,
                COALESCE(p.neb_beer, p.contract_beer) AS beer_label,
                p.event_date, p.run_type,
                COALESCE(rs.sku_code, '') AS sku_code
           FROM qa_net_content_readings n
           LEFT JOIN bd_packaging_v2 p ON p.id = n.packaging_id_fk
           LEFT JOIN ref_skus rs ON rs.id = p.sku_id_fk
          ORDER BY n.id DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel B: recent CIP events for dropdown (limit 30)
    $cipRows = $pdo->query(
        "SELECT c.id, c.cip_date, c.target_kind, c.target_code, c.target_number,
                ct.name AS cip_type_name
           FROM bd_cip_events c
           LEFT JOIN ref_cip_types ct ON ct.id = c.cip_type_id_fk
          WHERE c.is_tombstoned = 0
          ORDER BY c.id DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel B readback: recent 20 qa_cleaning_efficacy_checks
    $cipChecks = $pdo->query(
        "SELECT q.id, q.check_date, q.method, q.surface_label, q.cip_event_id_fk,
                q.result_value, q.result_unit, q.threshold_value, q.outcome,
                q.corrective_action, q.measured_at, q.comments,
                q.created_at
           FROM qa_cleaning_efficacy_checks q
          ORDER BY q.id DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel C: Packaging category MIs (category_id=8, Packaging glass/bottle/can)
    $pkgMiRows = $pdo->query(
        "SELECT m.id, m.mi_id, m.name
           FROM ref_mi m
          WHERE m.category_id = 8 AND m.is_active = 1
          ORDER BY m.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel C: recent deliveries for dropdown (50 most recent, active only)
    $deliveryRows = $pdo->query(
        "SELECT id, date_received, supplier_raw, ingredient_raw
           FROM inv_deliveries
          WHERE status NOT IN ('Cancelled','Tombstoned')
          ORDER BY date_received DESC, id DESC
          LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel C readback: recent 20 qa_bottle_reception_checks
    $bottleChecks = $pdo->query(
        "SELECT b.id, b.delivery_id_fk, b.mi_id_fk, b.reception_date, b.lot_ref,
                b.measure_type, b.sample_size, b.measured_value, b.target_value,
                b.tolerance_abs, b.outcome, b.comments, b.created_at,
                m.name AS mi_name, m.mi_id AS mi_code
           FROM qa_bottle_reception_checks b
           LEFT JOIN ref_mi m ON m.id = b.mi_id_fk
          ORDER BY b.id DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel D: sample points
    $waterSamplePoints = $pdo->query(
        "SELECT id, code, label, is_ccp FROM ref_water_sample_points WHERE is_active=1 ORDER BY sort_order"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel D: parameters
    $waterParams = $pdo->query(
        "SELECT id, code, label, unit, limit_operator, limit_min, limit_max, limit_basis FROM ref_water_parameters WHERE is_active=1 ORDER BY sort_order"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Panel D: recent 25 water analyses
    $waterAnalyses = $pdo->query(
        "SELECT w.id, w.sample_point_id_fk, w.parameter_id_fk,
                w.measured_value, w.measured_text, w.unit, w.action_limit,
                w.is_conforming, w.lab_name, w.method, w.sampled_at,
                w.report_ref, w.comments,
                sp.code AS sp_code, sp.label AS sp_label,
                p.label AS p_label, p.unit AS p_unit
           FROM qa_water_analysis w
           JOIN ref_water_sample_points sp ON sp.id = w.sample_point_id_fk
           JOIN ref_water_parameters p ON p.id = w.parameter_id_fk
          ORDER BY w.sampled_at DESC, w.id DESC
          LIMIT 25"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $loadErr           = $e->getMessage();
    $pkgRows           = [];
    $netReadings       = [];
    $cipRows           = [];
    $cipChecks         = [];
    $pkgMiRows         = [];
    $deliveryRows      = [];
    $bottleChecks      = [];
    $waterSamplePoints = [];
    $waterParams       = [];
    $waterAnalyses     = [];
}

$csrf          = csrf_token();
$active_module = 'qa';

// ── Helper functions (TOP-LEVEL) ───────────────────────────────────────────────

function qa_format_pkg_label(array $row): string
{
    $beer   = $row['beer_label'] ?? '?';
    $date   = !empty($row['event_date']) ? date('d/m/Y', strtotime($row['event_date'])) : '?';
    $fmtMap = ['bot' => 'Bot', 'can' => 'Can', 'can33' => 'Can 33cl', 'keg' => 'Keg', 'cuv' => 'Cuve'];
    $fmt    = $fmtMap[$row['run_type'] ?? ''] ?? ($row['run_type'] ?? '?');
    $sku    = $row['sku_code'] ?? '';
    return $beer . ' — ' . $date . ' — ' . $fmt . ($sku ? ' (' . $sku . ')' : '');
}

function qa_format_cip_label(array $row): string
{
    $date = $row['cip_date'] ?? '?';
    $code = strtoupper($row['target_code'] ?? '');
    $num  = !empty($row['target_number']) ? ' ' . $row['target_number'] : '';
    $type = !empty($row['cip_type_name']) ? ' (' . $row['cip_type_name'] . ')' : '';
    return $date . ' — ' . $code . $num . $type;
}

function qa_format_delivery_label(array $row): string
{
    $supplier = $row['supplier_raw'] ?? '?';
    $date     = !empty($row['date_received']) ? date('d/m/Y', strtotime($row['date_received'])) : '?';
    $item     = $row['ingredient_raw'] ?? '?';
    if (strlen($item) > 50) {
        $item = substr($item, 0, 47) . '…';
    }
    return $supplier . ' — ' . $date . ' — ' . $item;
}

function qa_conform_label(?int $is_conforming): string
{
    if ($is_conforming === null) return '—';
    return $is_conforming ? '✓' : '✗';
}

function qa_conform_class(?int $is_conforming): string
{
    if ($is_conforming === null) return 'qa-conform-na';
    return $is_conforming ? 'qa-conform-ok' : 'qa-conform-fail';
}

function qa_outcome_label(string $outcome): string
{
    return match ($outcome) {
        'pass'     => 'Conforme',
        'fail'     => 'Non conforme',
        'marginal' => 'Marginal',
        'pending'  => 'En attente',
        default    => htmlspecialchars($outcome),
    };
}

function qa_outcome_class(string $outcome): string
{
    return match ($outcome) {
        'pass'     => 'qa-outcome-pass',
        'fail'     => 'qa-outcome-fail',
        'marginal' => 'qa-outcome-marginal',
        'pending'  => 'qa-outcome-pending',
        default    => 'qa-outcome-pending',
    };
}

function qa_method_label(string $method): string
{
    return match ($method) {
        'atp'         => 'ATP (luminométrie)',
        'swab'        => 'Écouvillonnage',
        'visual'      => 'Visuel',
        'rinse_water' => 'Eau de rinçage',
        default       => htmlspecialchars($method),
    };
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>QA / QC — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/qa.css?v=<?= @filemtime(__DIR__ . '/../css/qa.css') ?: time() ?>">
</head>
<body class="home qa-page">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main id="main-content" class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      ⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Qualité · Conformité</div>
    <h1 class="op-form__title">QA / QC — <em>Contrôles</em></h1>
    <p class="op-form__sub">
      Saisies de contrôle : poids/volume au conditionnement, efficacité nettoyage (PRP-04), réception verre.
    </p>
  </div>

  <!-- ── View filter tabs ─────────────────────────────────────────────────── -->
  <nav class="qa-view-tabs" aria-label="Filtrer par type de contrôle">
    <a href="?view=all"   class="qa-tab <?= $view === 'all'   ? 'qa-tab--active' : '' ?>">Tous les contrôles</a>
    <a href="?view=net"   class="qa-tab <?= $view === 'net'   ? 'qa-tab--active' : '' ?>">Poids / Volume</a>
    <a href="?view=cip"   class="qa-tab <?= $view === 'cip'   ? 'qa-tab--active' : '' ?>">Nettoyage (PRP-04)</a>
    <a href="?view=recep" class="qa-tab <?= $view === 'recep' ? 'qa-tab--active' : '' ?>">Réception verre</a>
    <a href="?view=eau" class="qa-tab <?= $view === 'eau' ? 'qa-tab--active' : '' ?>">Analyse de l'eau</a>
  </nav>

  <!-- ═══════════════════════════════════════════════════════════════════════
       PANEL A — Net content (poids / volume au conditionnement)
  ════════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'all' || $view === 'net'): ?>
  <section class="qa-panel" id="qa-panel-net">
    <div class="qa-panel__header">
      <h2 class="qa-panel__title">Contrôle poids / volume au conditionnement</h2>
      <p class="qa-panel__desc">PRP-03 · Vérification du contenu net par mesure individuelle.</p>
    </div>

    <div class="op-form__card qa-form-card">
      <div class="op-form__card-title">Nouvelle mesure</div>
      <form id="qa-form-net" action="/api/qa-net-content.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="qa-form-grid">

          <!-- packaging_id_fk -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-pkg-id">Session de conditionnement <span class="qa-req">*</span></label>
            <select class="op-form__input" id="net-pkg-id" name="packaging_id_fk" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($pkgRows as $pr): ?>
              <option value="<?= (int) $pr['id'] ?>"><?= htmlspecialchars(qa_format_pkg_label($pr)) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- reading_seq -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-seq">N° mesure</label>
            <input type="number" class="op-form__input" id="net-seq" name="reading_seq" min="1" value="1">
          </div>

          <!-- measure_type -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-type">Type de mesure</label>
            <select class="op-form__input" id="net-type" name="measure_type">
              <option value="weight">Poids (g)</option>
              <option value="volume">Volume (mL)</option>
            </select>
          </div>

          <!-- measured_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-val">Valeur mesurée <span class="qa-req">*</span> <span class="qa-unit" id="net-val-unit">g</span></label>
            <input type="number" class="op-form__input" id="net-val" name="measured_value" step="0.001" required placeholder="0.000">
          </div>

          <!-- target_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-target">Valeur cible <span class="qa-unit" id="net-target-unit">g</span></label>
            <input type="number" class="op-form__input" id="net-target" name="target_value" step="0.001" placeholder="—">
          </div>

          <!-- tolerance_abs -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-tol">Tolérance abs. <span class="qa-unit" id="net-tol-unit">g</span></label>
            <input type="number" class="op-form__input" id="net-tol" name="tolerance_abs" step="0.001" placeholder="—">
          </div>

          <!-- tare_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-tare">Tare <span class="qa-unit" id="net-tare-unit">g</span></label>
            <input type="number" class="op-form__input" id="net-tare" name="tare_value" step="0.001" placeholder="—">
          </div>

          <!-- measured_at -->
          <div class="op-form__field">
            <label class="op-form__label" for="net-at">Heure de mesure <span class="qa-req">*</span></label>
            <input type="datetime-local" class="op-form__input" id="net-at" name="measured_at" required>
          </div>

          <!-- comments -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="net-comments">Commentaires</label>
            <textarea class="op-form__input qa-textarea" id="net-comments" name="comments" rows="2" placeholder="Observations éventuelles…"></textarea>
          </div>

        </div>

        <div class="qa-form-actions">
          <button type="submit" class="op-form__btn op-form__btn--primary" id="qa-net-submit">
            Enregistrer la mesure
          </button>
          <span class="qa-inline-msg" id="qa-net-msg" hidden></span>
        </div>
      </form>
    </div>

    <!-- Readback table -->
    <div class="op-form__card qa-readback-card">
      <div class="op-form__card-title">20 dernières mesures</div>
      <?php if (empty($netReadings)): ?>
        <p class="qa-empty">Aucune mesure enregistrée.</p>
      <?php else: ?>
      <div class="qa-table-wrap">
        <table class="qa-table" id="qa-net-table">
          <thead>
            <tr>
              <th>Heure</th>
              <th>Session</th>
              <th>N°</th>
              <th>Type</th>
              <th>Mesuré</th>
              <th>Cible</th>
              <th>Tol.</th>
              <th>Conforme</th>
              <th>Commentaire</th>
            </tr>
          </thead>
          <tbody id="qa-net-tbody">
            <?php foreach ($netReadings as $nr): ?>
            <?php
              $nrConforming = (isset($nr['is_conforming']) && $nr['is_conforming'] !== null)
                ? (int) $nr['is_conforming']
                : null;
            ?>
            <tr>
              <td class="qa-mono"><?= htmlspecialchars($nr['measured_at'] ? substr($nr['measured_at'], 0, 16) : '—') ?></td>
              <td><?= htmlspecialchars(qa_format_pkg_label([
                    'beer_label' => $nr['beer_label'] ?? '',
                    'event_date' => $nr['event_date'] ?? '',
                    'run_type'   => $nr['run_type'] ?? '',
                    'sku_code'   => $nr['sku_code'] ?? '',
                  ])) ?></td>
              <td class="qa-mono"><?= (int) $nr['reading_seq'] ?></td>
              <td><?= $nr['measure_type'] === 'weight' ? 'Poids' : 'Volume' ?></td>
              <td class="qa-mono">
                <?= htmlspecialchars(number_format((float) $nr['measured_value'], 3, ',', ' ')) ?>
                <?= $nr['measure_type'] === 'weight' ? 'g' : 'mL' ?>
              </td>
              <td class="qa-mono">
                <?= $nr['target_value'] !== null
                    ? htmlspecialchars(number_format((float) $nr['target_value'], 3, ',', ' '))
                    : '—' ?>
              </td>
              <td class="qa-mono">
                <?= $nr['tolerance_abs'] !== null
                    ? '±' . htmlspecialchars(number_format((float) $nr['tolerance_abs'], 3, ',', ' '))
                    : '—' ?>
              </td>
              <td>
                <span class="qa-conform <?= qa_conform_class($nrConforming) ?>">
                  <?= qa_conform_label($nrConforming) ?>
                </span>
              </td>
              <td class="qa-comment"><?= htmlspecialchars($nr['comments'] ?? '') ?></td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
  </section>
  <?php endif ?>

  <!-- ═══════════════════════════════════════════════════════════════════════
       PANEL B — Cleaning efficacy / CIP (PRP-04)
  ════════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'all' || $view === 'cip'): ?>
  <section class="qa-panel" id="qa-panel-cip">
    <div class="qa-panel__header">
      <h2 class="qa-panel__title">Contrôle efficacité nettoyage / désinfection (PRP-04)</h2>
      <p class="qa-panel__desc">Enregistrement des contrôles post-CIP : ATP, écouvillonnage, visuel ou eau de rinçage.</p>
    </div>

    <div class="op-form__card qa-form-card">
      <div class="op-form__card-title">Nouveau contrôle</div>
      <form id="qa-form-cip" action="/api/qa-cleaning-efficacy.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="qa-form-grid">

          <!-- check_date -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-check-date">Date du contrôle <span class="qa-req">*</span></label>
            <input type="date" class="op-form__input" id="cip-check-date" name="check_date" required
                   value="<?= htmlspecialchars(date('Y-m-d')) ?>">
          </div>

          <!-- method -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-method">Méthode <span class="qa-req">*</span></label>
            <select class="op-form__input" id="cip-method" name="method" required>
              <option value="atp">ATP (luminométrie)</option>
              <option value="swab">Écouvillonnage</option>
              <option value="visual">Visuel</option>
              <option value="rinse_water">Eau de rinçage</option>
            </select>
          </div>

          <!-- surface_label -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="cip-surface">Surface / équipement <span class="qa-req">*</span></label>
            <input type="text" class="op-form__input" id="cip-surface" name="surface_label" required
                   placeholder="ex. CCT 7 — vanne de fond" maxlength="128">
          </div>

          <!-- cip_event_id_fk (optional) -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="cip-event">Événement CIP associé <span class="qa-opt">(optionnel)</span></label>
            <select class="op-form__input" id="cip-event" name="cip_event_id_fk">
              <option value="">— Aucun —</option>
              <?php foreach ($cipRows as $cr): ?>
              <option value="<?= (int) $cr['id'] ?>"><?= htmlspecialchars(qa_format_cip_label($cr)) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- result_value + result_unit -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-result-val">Valeur mesurée <span class="qa-opt">(opt.)</span></label>
            <input type="number" class="op-form__input" id="cip-result-val" name="result_value" step="0.01" placeholder="—">
          </div>

          <div class="op-form__field">
            <label class="op-form__label" for="cip-result-unit">Unité <span class="qa-opt">(opt.)</span></label>
            <input type="text" class="op-form__input" id="cip-result-unit" name="result_unit"
                   placeholder="RLU / CFU·cm⁻²" maxlength="16">
          </div>

          <!-- threshold_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-threshold">Seuil <span class="qa-opt">(opt.)</span></label>
            <input type="number" class="op-form__input" id="cip-threshold" name="threshold_value" step="0.01" placeholder="—">
          </div>

          <!-- outcome -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-outcome">Résultat <span class="qa-req">*</span></label>
            <select class="op-form__input" id="cip-outcome" name="outcome" required>
              <option value="pending" selected>En attente</option>
              <option value="pass">Conforme</option>
              <option value="marginal">Marginal</option>
              <option value="fail">Non conforme</option>
            </select>
          </div>

          <!-- measured_at -->
          <div class="op-form__field">
            <label class="op-form__label" for="cip-measured-at">Heure de mesure <span class="qa-opt">(opt.)</span></label>
            <input type="datetime-local" class="op-form__input" id="cip-measured-at" name="measured_at">
          </div>

          <!-- corrective_action -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="cip-corrective">Action corrective <span class="qa-opt">(opt.)</span></label>
            <textarea class="op-form__input qa-textarea" id="cip-corrective" name="corrective_action" rows="2"
                      placeholder="Décrire l'action corrective si résultat non conforme…"></textarea>
          </div>

          <!-- comments -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="cip-comments">Commentaires <span class="qa-opt">(opt.)</span></label>
            <textarea class="op-form__input qa-textarea" id="cip-comments" name="comments" rows="2" placeholder="Observations…"></textarea>
          </div>

        </div>

        <div class="qa-form-actions">
          <button type="submit" class="op-form__btn op-form__btn--primary" id="qa-cip-submit">
            Enregistrer le contrôle
          </button>
          <span class="qa-inline-msg" id="qa-cip-msg" hidden></span>
        </div>
      </form>
    </div>

    <!-- Readback table -->
    <div class="op-form__card qa-readback-card">
      <div class="op-form__card-title">20 derniers contrôles</div>
      <?php if (empty($cipChecks)): ?>
        <p class="qa-empty">Aucun contrôle enregistré.</p>
      <?php else: ?>
      <div class="qa-table-wrap">
        <table class="qa-table" id="qa-cip-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Méthode</th>
              <th>Surface</th>
              <th>Résultat</th>
              <th>Seuil</th>
              <th>Issue</th>
              <th>Action corrective</th>
            </tr>
          </thead>
          <tbody id="qa-cip-tbody">
            <?php foreach ($cipChecks as $cc): ?>
            <tr>
              <td class="qa-mono"><?= htmlspecialchars($cc['check_date'] ?? '—') ?></td>
              <td><?= htmlspecialchars(qa_method_label($cc['method'] ?? '')) ?></td>
              <td><?= htmlspecialchars($cc['surface_label'] ?? '—') ?></td>
              <td class="qa-mono">
                <?php if ($cc['result_value'] !== null): ?>
                  <?= htmlspecialchars(number_format((float) $cc['result_value'], 2, ',', ' ')) ?>
                  <?= !empty($cc['result_unit']) ? ' ' . htmlspecialchars($cc['result_unit']) : '' ?>
                <?php else: ?>—<?php endif ?>
              </td>
              <td class="qa-mono">
                <?= $cc['threshold_value'] !== null
                    ? htmlspecialchars(number_format((float) $cc['threshold_value'], 2, ',', ' '))
                    : '—' ?>
              </td>
              <td>
                <span class="qa-outcome <?= qa_outcome_class($cc['outcome'] ?? 'pending') ?>">
                  <?= qa_outcome_label($cc['outcome'] ?? 'pending') ?>
                </span>
              </td>
              <td class="qa-comment"><?= htmlspecialchars($cc['corrective_action'] ?? '') ?></td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
  </section>
  <?php endif ?>

  <!-- ═══════════════════════════════════════════════════════════════════════
       PANEL C — Bottle / glass reception checks
  ════════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'all' || $view === 'recep'): ?>
  <section class="qa-panel" id="qa-panel-recep">
    <div class="qa-panel__header">
      <h2 class="qa-panel__title">Contrôle réception verre (poids / volume)</h2>
      <p class="qa-panel__desc">Vérification des caractéristiques des contenants à réception.</p>
    </div>

    <div class="op-form__card qa-form-card">
      <div class="op-form__card-title">Nouveau contrôle réception</div>
      <form id="qa-form-recep" action="/api/qa-bottle-reception.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="qa-form-grid">

          <!-- delivery_id_fk (optional) -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="recep-delivery">Livraison associée <span class="qa-opt">(opt.)</span></label>
            <select class="op-form__input" id="recep-delivery" name="delivery_id_fk">
              <option value="">— Aucune —</option>
              <?php foreach ($deliveryRows as $dr): ?>
              <option value="<?= (int) $dr['id'] ?>"><?= htmlspecialchars(qa_format_delivery_label($dr)) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- mi_id_fk (optional, packaging MIs only, category_id=8) -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="recep-mi">Article verre / contenant <span class="qa-opt">(opt.)</span></label>
            <select class="op-form__input" id="recep-mi" name="mi_id_fk">
              <option value="">— Sélectionner —</option>
              <?php foreach ($pkgMiRows as $pm): ?>
              <option value="<?= (int) $pm['id'] ?>"><?= htmlspecialchars($pm['name']) ?> [<?= htmlspecialchars($pm['mi_id']) ?>]</option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- reception_date -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-date">Date de réception <span class="qa-req">*</span></label>
            <input type="date" class="op-form__input" id="recep-date" name="reception_date" required
                   value="<?= htmlspecialchars(date('Y-m-d')) ?>">
          </div>

          <!-- lot_ref -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-lot">Référence lot <span class="qa-opt">(opt.)</span></label>
            <input type="text" class="op-form__input" id="recep-lot" name="lot_ref" maxlength="64"
                   placeholder="ex. L24-001">
          </div>

          <!-- measure_type -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-type">Type de mesure <span class="qa-req">*</span></label>
            <select class="op-form__input" id="recep-type" name="measure_type" required>
              <option value="weight">Poids (g)</option>
              <option value="volume">Volume (mL)</option>
            </select>
          </div>

          <!-- sample_size -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-sample">Taille d'échantillon <span class="qa-opt">(opt.)</span></label>
            <input type="number" class="op-form__input" id="recep-sample" name="sample_size" min="1" step="1" placeholder="—">
          </div>

          <!-- measured_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-val">Valeur mesurée <span class="qa-req">*</span> <span class="qa-unit" id="recep-val-unit">g</span></label>
            <input type="number" class="op-form__input" id="recep-val" name="measured_value" step="0.001" required placeholder="0.000">
          </div>

          <!-- target_value -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-target">Valeur cible <span class="qa-unit" id="recep-target-unit">g</span></label>
            <input type="number" class="op-form__input" id="recep-target" name="target_value" step="0.001" placeholder="—">
          </div>

          <!-- tolerance_abs -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-tol">Tolérance abs. <span class="qa-unit" id="recep-tol-unit">g</span></label>
            <input type="number" class="op-form__input" id="recep-tol" name="tolerance_abs" step="0.001" placeholder="—">
          </div>

          <!-- outcome -->
          <div class="op-form__field">
            <label class="op-form__label" for="recep-outcome">Résultat <span class="qa-req">*</span></label>
            <select class="op-form__input" id="recep-outcome" name="outcome" required>
              <option value="pass">Conforme</option>
              <option value="marginal">Marginal</option>
              <option value="fail">Non conforme</option>
            </select>
          </div>

          <!-- comments -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="recep-comments">Commentaires <span class="qa-opt">(opt.)</span></label>
            <textarea class="op-form__input qa-textarea" id="recep-comments" name="comments" rows="2" placeholder="Observations…"></textarea>
          </div>

        </div>

        <div class="qa-form-actions">
          <button type="submit" class="op-form__btn op-form__btn--primary" id="qa-recep-submit">
            Enregistrer le contrôle
          </button>
          <span class="qa-inline-msg" id="qa-recep-msg" hidden></span>
        </div>
      </form>
    </div>

    <!-- Readback table -->
    <div class="op-form__card qa-readback-card">
      <div class="op-form__card-title">20 derniers contrôles réception</div>
      <?php if (empty($bottleChecks)): ?>
        <p class="qa-empty">Aucun contrôle enregistré.</p>
      <?php else: ?>
      <div class="qa-table-wrap">
        <table class="qa-table" id="qa-recep-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Article</th>
              <th>Lot</th>
              <th>Type</th>
              <th>Échantillon</th>
              <th>Mesuré</th>
              <th>Cible</th>
              <th>Issue</th>
            </tr>
          </thead>
          <tbody id="qa-recep-tbody">
            <?php foreach ($bottleChecks as $bc): ?>
            <tr>
              <td class="qa-mono"><?= htmlspecialchars($bc['reception_date'] ?? '—') ?></td>
              <td><?= htmlspecialchars($bc['mi_name'] ?? '—') ?></td>
              <td class="qa-mono"><?= htmlspecialchars($bc['lot_ref'] ?? '—') ?></td>
              <td><?= ($bc['measure_type'] ?? '') === 'weight' ? 'Poids' : 'Volume' ?></td>
              <td class="qa-mono"><?= $bc['sample_size'] !== null ? (int) $bc['sample_size'] : '—' ?></td>
              <td class="qa-mono">
                <?= htmlspecialchars(number_format((float) $bc['measured_value'], 3, ',', ' ')) ?>
                <?= ($bc['measure_type'] ?? '') === 'weight' ? 'g' : 'mL' ?>
              </td>
              <td class="qa-mono">
                <?= $bc['target_value'] !== null
                    ? htmlspecialchars(number_format((float) $bc['target_value'], 3, ',', ' '))
                    : '—' ?>
              </td>
              <td>
                <span class="qa-outcome <?= qa_outcome_class($bc['outcome'] ?? 'pass') ?>">
                  <?= qa_outcome_label($bc['outcome'] ?? 'pass') ?>
                </span>
              </td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
  </section>
  <?php endif ?>

  <!-- ═══════════════════════════════════════════════════════════════════════
       PANEL D — Analyse de l'eau
  ════════════════════════════════════════════════════════════════════════ -->
  <?php if ($view === 'all' || $view === 'eau'): ?>
  <section class="qa-panel" id="qa-panel-eau">
    <div class="qa-panel__header">
      <h2 class="qa-panel__title">Analyse de l'eau</h2>
      <p class="qa-panel__desc">Suivi des points de contrôle eau (CCP et PRP) — pH, turbidité, micro, etc.</p>
    </div>

    <div class="op-form__card qa-form-card">
      <div class="op-form__card-title">Nouveau résultat d'analyse</div>
      <form id="qa-form-eau" action="/api/qa-water-analysis.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="qa-form-grid">

          <!-- sample_point_id_fk -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-point">Point de prélèvement <span class="qa-req">*</span></label>
            <select class="op-form__input" id="eau-point" name="sample_point_id_fk" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($waterSamplePoints as $sp): ?>
              <option value="<?= (int) $sp['id'] ?>">
                <?= htmlspecialchars($sp['code'] . ' — ' . $sp['label']) ?>
                <?php if ($sp['is_ccp']): ?> <span class="qa-ccp-tag">CCP</span><?php endif ?>
              </option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- parameter_id_fk -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-param">Paramètre <span class="qa-req">*</span></label>
            <select class="op-form__input" id="eau-param" name="parameter_id_fk" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($waterParams as $wp): ?>
              <option value="<?= (int) $wp['id'] ?>"><?= htmlspecialchars($wp['code'] . ' — ' . $wp['label']) ?></option>
              <?php endforeach ?>
            </select>
          </div>

          <!-- measured_value (numeric) — shown/hidden by JS -->
          <div class="op-form__field" id="eau-num-wrap">
            <label class="op-form__label" for="eau-val">
              Valeur mesurée <span class="qa-req">*</span>
              <span class="qa-unit" id="eau-val-unit"></span>
            </label>
            <input type="number" class="op-form__input" id="eau-val" name="measured_value"
                   step="any" placeholder="0.00">
            <span class="qa-limit-hint" id="eau-limit-hint"></span>
          </div>

          <!-- measured_text (presence/absence select) — shown/hidden by JS -->
          <div class="op-form__field" id="eau-pa-wrap" hidden>
            <label class="op-form__label" for="eau-pa">Résultat <span class="qa-req">*</span></label>
            <select class="op-form__input" id="eau-pa" name="measured_text">
              <option value="Absence" selected>Absence</option>
              <option value="Présence">Présence</option>
            </select>
          </div>

          <!-- sampled_at -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-at">Date / heure de prélèvement <span class="qa-req">*</span></label>
            <input type="datetime-local" class="op-form__input" id="eau-at" name="sampled_at" required>
          </div>

          <!-- lab_name -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-lab">Laboratoire <span class="qa-opt">(opt.)</span></label>
            <input type="text" class="op-form__input" id="eau-lab" name="lab_name"
                   maxlength="190" placeholder="ex. Eurofins, interne…">
          </div>

          <!-- method -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-method">Méthode <span class="qa-opt">(opt.)</span></label>
            <input type="text" class="op-form__input" id="eau-method" name="method"
                   maxlength="190" placeholder="ex. ISO 7027">
          </div>

          <!-- report_ref -->
          <div class="op-form__field">
            <label class="op-form__label" for="eau-ref">Réf. rapport <span class="qa-opt">(opt.)</span></label>
            <input type="text" class="op-form__input" id="eau-ref" name="report_ref"
                   maxlength="120" placeholder="ex. LAB-2026-042">
          </div>

          <!-- comments -->
          <div class="op-form__field qa-field--wide">
            <label class="op-form__label" for="eau-comments">Commentaires <span class="qa-opt">(opt.)</span></label>
            <textarea class="op-form__input qa-textarea" id="eau-comments" name="comments"
                      rows="2" placeholder="Observations éventuelles…"></textarea>
          </div>

        </div>

        <div class="qa-form-actions">
          <button type="submit" class="op-form__btn op-form__btn--primary" id="qa-eau-submit">
            Enregistrer l'analyse
          </button>
          <span class="qa-inline-msg" id="qa-eau-msg" hidden></span>
        </div>
      </form>
    </div>

    <!-- Readback table -->
    <div class="op-form__card qa-readback-card">
      <div class="op-form__card-title">25 dernières analyses</div>
      <?php if (empty($waterAnalyses)): ?>
        <p class="qa-empty">Aucune analyse enregistrée.</p>
      <?php else: ?>
      <div class="qa-table-wrap">
        <table class="qa-table" id="qa-eau-table">
          <thead>
            <tr>
              <th>Date prélèvement</th>
              <th>Point</th>
              <th>Paramètre</th>
              <th>Résultat</th>
              <th>Limite</th>
              <th>Conformité</th>
              <th>Labo</th>
              <th>Réf.</th>
            </tr>
          </thead>
          <tbody id="qa-eau-tbody">
            <?php foreach ($waterAnalyses as $wa): ?>
            <?php
              $waConforming = (isset($wa['is_conforming']) && $wa['is_conforming'] !== null)
                ? (int) $wa['is_conforming'] : null;
              $waResult = ($wa['measured_value'] !== null)
                ? htmlspecialchars(number_format((float) $wa['measured_value'], 4, ',', ' '))
                  . (!empty($wa['unit']) ? ' ' . htmlspecialchars($wa['unit']) : '')
                : htmlspecialchars($wa['measured_text'] ?? '—');
              $waLimit = htmlspecialchars($wa['action_limit'] ?? '—');
            ?>
            <tr>
              <td class="qa-mono"><?= htmlspecialchars($wa['sampled_at'] ? substr($wa['sampled_at'], 0, 16) : '—') ?></td>
              <td><?= htmlspecialchars($wa['sp_code'] . ' — ' . $wa['sp_label']) ?></td>
              <td><?= htmlspecialchars($wa['p_label']) ?></td>
              <td class="qa-mono"><?= $waResult ?></td>
              <td class="qa-mono qa-comment"><?= $waLimit ?></td>
              <td>
                <span class="qa-conform <?= qa_conform_class($waConforming) ?>">
                  <?= qa_conform_label($waConforming) ?>
                </span>
              </td>
              <td class="qa-comment"><?= htmlspecialchars($wa['lab_name'] ?? '—') ?></td>
              <td class="qa-mono qa-comment"><?= htmlspecialchars($wa['report_ref'] ?? '—') ?></td>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php endif ?>
    </div>
  </section>
  <?php endif ?>

</main>

<?php
// Build params map for JS
$waterParamsMap = [];
foreach ($waterParams as $wp) {
    $waterParamsMap[$wp['id']] = [
        'label'          => $wp['label'],
        'unit'           => $wp['unit'],
        'limit_operator' => $wp['limit_operator'],
        'limit_min'      => $wp['limit_min'] !== null ? (float) $wp['limit_min'] : null,
        'limit_max'      => $wp['limit_max'] !== null ? (float) $wp['limit_max'] : null,
        'limit_basis'    => $wp['limit_basis'],
    ];
}
?>
<script>
window.QA_WATER_PARAMS = <?= json_encode($waterParamsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
window.QA = {
    csrf: <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
</script>
<script src="/js/qa.js?v=<?= @filemtime(__DIR__ . '/../js/qa.js') ?: time() ?>" defer></script>
</body>
</html>
