<?php
declare(strict_types=1);
/**
 * form-rm-stocktake.php — Operator RM stocktake entry form.
 *
 * Writes to:
 *   inv_rm_stocktake — one row per (mi_id, period), upserted idempotently.
 *
 * Natural key: (mi_id, period) — UNIQUE KEY uniq_mi_period on the table.
 * row_hash: sha256 of (mi_id_fk, mi_id, period, counted_qty_str, source, counted_by).
 * final_qty is a GENERATED ALWAYS column (coalesce(counted_qty, expected_qty)) — NOT written.
 *
 * Pattern: inline POST → CSRF → coerce → bd_upsert per MI → log_revision → flash → PRG.
 * MySQL only. No BSF / Google Sheets writes.
 *
 * URL: /modules/form-rm-stocktake.php
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';

require_login();
$me = current_user();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/form-rm-stocktake.php');
    }

    try {
        $pdo = maltytask_pdo();

        // 1. Validate period
        $period = post_str('period') ?? '';
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException("Période invalide — format attendu : AAAA-MM.");
        }

        // 2. Collect submitted counts — key: mi_id_fk (int), value: qty string|null
        $submitted = $_POST['counts'] ?? [];
        if (!is_array($submitted)) {
            throw new RuntimeException("Format de saisie invalide.");
        }

        // 3. Load MI map for this submit (mi_id_fk → [mi_id, name, unit])
        $miIdFks = array_keys($submitted);
        $miIdFks = array_filter($miIdFks, fn($v) => is_numeric($v) && (int)$v > 0);
        $miIdFks = array_map('intval', $miIdFks);

        if (empty($miIdFks)) {
            flash_set('err', 'Aucune valeur saisie — rien à enregistrer.');
            redirect_to('/modules/form-rm-stocktake.php?period=' . urlencode($period));
        }

        $inList = implode(',', $miIdFks);
        $miMap  = [];
        $miRows = $pdo->query(
            "SELECT m.id, m.mi_id, m.name
               FROM ref_mi m
              WHERE m.id IN ($inList)
                AND m.is_inventoried = 1
                AND m.is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($miRows as $r) {
            $miMap[(int)$r['id']] = $r;
        }

        // 4. Upsert each non-blank count
        $countedAt = date('Y-m-d H:i:s');
        $countedBy = $me['username'] ?? 'unknown';
        $n = 0;
        $nInsert = 0;
        $nUpdate = 0;

        foreach ($miIdFks as $miFk) {
            $rawVal = $submitted[$miFk] ?? '';
            if (!is_scalar($rawVal)) continue;
            $rawVal = trim((string)$rawVal);
            if ($rawVal === '') continue; // blank = skip

            // Validate numeric
            $rawVal = str_replace(',', '.', $rawVal);
            if (!is_numeric($rawVal) || (float)$rawVal < 0) {
                throw new RuntimeException(
                    "Valeur invalide pour MI #{$miFk} : " . htmlspecialchars($rawVal)
                );
            }
            $countedQty = $rawVal; // string for DECIMAL column

            if (!isset($miMap[$miFk])) {
                // MI not inventoried or not found — skip silently (safety)
                continue;
            }
            $miId = $miMap[$miFk]['mi_id'];

            // Deterministic row_hash over stable identity fields
            $rowHash = hash('sha256', implode('|', [
                (string)$miFk,
                $miId,
                $period,
                $countedQty,
                'web-form',
                $countedBy,
            ]));

            // Snapshot before-state for audit
            $existingPk = bd_lookup_pk_by_nk($pdo, 'inv_rm_stocktake', ['mi_id', 'period'], [
                'mi_id'  => $miId,
                'period' => $period,
            ]);
            $before = $existingPk !== null ? bd_fetch_before($pdo, 'inv_rm_stocktake', $existingPk) : null;

            $row = [
                'mi_id'      => $miId,
                'mi_id_fk'   => $miFk,
                'period'     => $period,
                'counted_qty' => $countedQty,
                'source'     => 'web-form',
                'counted_by' => $countedBy,
                'counted_at' => $countedAt,
                'is_active'  => 1,
                'notes'      => null,
                'row_hash'   => $rowHash,
            ];

            $result = bd_upsert($pdo, 'inv_rm_stocktake', $row, ['mi_id', 'period']);
            $pk     = $result['id'];
            $action = $result['action'];

            log_revision($pdo, $me, 'inv_rm_stocktake', $pk, $before,
                array_merge($row, ['counted_qty' => $countedQty]), 'normal',
                "Inventaire RM période {$period} — saisie web");

            $n++;
            if ($action === 'insert') $nInsert++;
            else                      $nUpdate++;
        }

        if ($n === 0) {
            flash_set('err', 'Aucune valeur non-vide à enregistrer.');
        } else {
            $parts = [];
            if ($nInsert > 0) $parts[] = "{$nInsert} nouvel" . ($nInsert > 1 ? 'les' : '') . " enregistrement" . ($nInsert > 1 ? 's' : '');
            if ($nUpdate > 0) $parts[] = "{$nUpdate} mise" . ($nUpdate > 1 ? 's' : '') . " à jour";
            flash_set('ok', "Inventaire RM {$period} enregistré — " . implode(', ', $parts) . ".");
        }

        redirect_to('/modules/form-rm-stocktake.php?period=' . urlencode($period));

    } catch (Throwable $e) {
        flash_set('err', 'Erreur : ' . pdo_friendly_error($e, 'rm-stocktake'));
        $period = post_str('period') ?? '';
        $qs = $period !== '' ? '?period=' . urlencode($period) : '';
        redirect_to('/modules/form-rm-stocktake.php' . $qs);
    }
}

// ── GET ───────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

// Determine period to display
$periodParam = isset($_GET['period']) ? trim($_GET['period']) : null;
if ($periodParam !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodParam)) {
    $periodParam = null;
}
// Default: current month
if ($periodParam === null) {
    $periodParam = date('Y-m');
}
$selectedPeriod = $periodParam;

try {
    $pdo = maltytask_pdo();

    // Load all inventoried, active MIs grouped by category
    $miRows = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.pricing_unit,
                c.id AS cat_id, c.name AS category,
                COALESCE(sc.name, '') AS subcategory
           FROM ref_mi m
           JOIN ref_mi_categories c ON m.category_id = c.id
           LEFT JOIN ref_mi_subcategories sc ON m.subcategory_id = sc.id
          WHERE m.is_inventoried = 1
            AND m.is_active = 1
          ORDER BY c.name ASC, m.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $miByCategory = [];
    foreach ($miRows as $r) {
        $cat = $r['category'];
        if (!isset($miByCategory[$cat])) {
            $miByCategory[$cat] = [];
        }
        $miByCategory[$cat][] = $r;
    }

    // Pre-load existing counts for the selected period (sticky)
    $existingCounts = [];
    $existingNotes  = [];
    if ($miRows) {
        $stmt = $pdo->prepare(
            "SELECT mi_id, counted_qty, notes
               FROM inv_rm_stocktake
              WHERE period = ?
                AND is_active = 1"
        );
        $stmt->execute([$selectedPeriod]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ec) {
            $existingCounts[$ec['mi_id']] = $ec['counted_qty'];
            $existingNotes[$ec['mi_id']]  = $ec['notes'] ?? '';
        }
    }

    // Available periods for selector (show existing + current month)
    $periodRows = $pdo->query(
        "SELECT DISTINCT period FROM inv_rm_stocktake ORDER BY period DESC LIMIT 12"
    )->fetchAll(PDO::FETCH_COLUMN);
    // Ensure current month is always an option
    if (!in_array(date('Y-m'), $periodRows, true)) {
        array_unshift($periodRows, date('Y-m'));
    }
    // Ensure selected period is always an option
    if (!in_array($selectedPeriod, $periodRows, true)) {
        array_unshift($periodRows, $selectedPeriod);
    }

    $loadErr = null;

} catch (Throwable $e) {
    $miByCategory   = [];
    $existingCounts = [];
    $existingNotes  = [];
    $periodRows     = [date('Y-m')];
    $loadErr        = $e->getMessage();
}

$csrf          = csrf_token();
$active_module = 'saisies';
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventaire RM — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/form-rm-stocktake.css?v=<?= @filemtime(__DIR__ . '/../css/form-rm-stocktake.css') ?: time() ?>">
</head>
<body class="home op-form-page form-rm-stocktake">

<?php require __DIR__ . '/../../app/partials/sidebar.php' ?>
<?php require __DIR__ . '/../../app/partials/topbar.php' ?>

<main class="main">

  <?php flash_render() ?>

  <?php if ($loadErr !== null): ?>
    <div class="db-flash db-flash--err">
      ⚠ Erreur de chargement : <?= htmlspecialchars($loadErr) ?>
    </div>
  <?php endif ?>

  <!-- ── Page header ─────────────────────────────────────────────────────── -->
  <div class="op-form__header">
    <div class="op-form__eyebrow">Inventaire · Matières Premières</div>
    <h1 class="op-form__title">Inventaire <em>RM</em></h1>
    <p class="op-form__sub">
      Saisie du comptage physique mensuel des matières premières.
      Les quantités enregistrées alimentent directement la base de données de production
      (<code>inv_rm_stocktake</code>). Re-soumettre la même période met à jour les valeurs.
    </p>
  </div>

  <!-- ── Period selector card ─────────────────────────────────────────────── -->
  <div class="op-form__card rms-period-card">
    <div class="op-form__card-title">Période de comptage</div>
    <div class="rms-period-row">
      <div class="op-form__field rms-period-field">
        <label class="op-form__label" for="period-input">Période
          <span class="op-form__unit">AAAA-MM</span>
        </label>
        <input type="month"
               id="period-input"
               name="period_nav"
               class="op-form__input rms-period-input"
               value="<?= htmlspecialchars($selectedPeriod) ?>"
               autocomplete="off">
      </div>
      <button type="button" class="op-form__btn op-form__btn--secondary rms-period-btn"
              id="rms-period-go">Afficher ce mois →</button>
    </div>
    <?php if (!empty($existingCounts)): ?>
      <p class="rms-period-hint rms-period-hint--preloaded">
        ✓ <?= count($existingCounts) ?> valeur<?= count($existingCounts) > 1 ? 's' : '' ?> existante<?= count($existingCounts) > 1 ? 's' : '' ?>
        pré-chargée<?= count($existingCounts) > 1 ? 's' : '' ?> pour <strong><?= htmlspecialchars($selectedPeriod) ?></strong>.
        Re-soumettre mettra à jour ces valeurs.
      </p>
    <?php else: ?>
      <p class="rms-period-hint">
        Aucun comptage enregistré pour <strong><?= htmlspecialchars($selectedPeriod) ?></strong>.
      </p>
    <?php endif ?>
  </div>

  <!-- ── Count form ───────────────────────────────────────────────────────── -->
  <?php if (!empty($miByCategory)): ?>
  <form method="POST" action="/modules/form-rm-stocktake.php" novalidate
        id="rms-form" class="rms-form">
    <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="period" value="<?= htmlspecialchars($selectedPeriod) ?>">

    <?php foreach ($miByCategory as $catName => $catMIs): ?>
    <div class="op-form__card rms-cat-card">
      <div class="op-form__card-title rms-cat-title">
        <?= htmlspecialchars($catName) ?>
        <span class="rms-cat-count"><?= count($catMIs) ?> article<?= count($catMIs) > 1 ? 's' : '' ?></span>
      </div>

      <div class="rms-mi-table">
        <div class="rms-mi-header">
          <span class="rms-mi-header__name">Ingrédient</span>
          <span class="rms-mi-header__id">ID</span>
          <span class="rms-mi-header__qty">Quantité comptée</span>
          <span class="rms-mi-header__unit">Unité</span>
        </div>

        <?php foreach ($catMIs as $mi): ?>
          <?php
            $miFk    = (int)$mi['id'];
            $miId    = $mi['mi_id'];
            $miName  = $mi['name'];
            $miUnit  = $mi['pricing_unit'] ?? '—';
            $existing = $existingCounts[$miId] ?? null;
            $dispVal  = $existing !== null ? rtrim(rtrim($existing, '0'), '.') : '';
            $hasVal   = $existing !== null;
          ?>
        <div class="rms-mi-row <?= $hasVal ? 'rms-mi-row--preloaded' : '' ?>">
          <label class="rms-mi-row__name"
                 for="cnt_<?= $miFk ?>">
            <?= htmlspecialchars($miName) ?>
          </label>
          <span class="rms-mi-row__id"><?= htmlspecialchars($miId) ?></span>
          <input type="number"
                 id="cnt_<?= $miFk ?>"
                 name="counts[<?= $miFk ?>]"
                 class="op-form__input rms-qty-input <?= $hasVal ? 'rms-qty-input--prefilled' : '' ?>"
                 value="<?= htmlspecialchars($dispVal) ?>"
                 min="0"
                 step="0.001"
                 placeholder="—"
                 autocomplete="off">
          <span class="rms-mi-row__unit"><?= htmlspecialchars($miUnit) ?></span>
        </div>
        <?php endforeach ?>
      </div>
    </div>
    <?php endforeach ?>

    <!-- ── Submit bar ────────────────────────────────────────────────────── -->
    <div class="op-form__submit-bar rms-submit-bar">
      <span class="rms-submit-hint">
        Seules les cases remplies seront enregistrées.
        Les cases vides sont ignorées.
      </span>
      <a href="/modules/saisies.php" class="op-form__btn op-form__btn--secondary">
        ← Retour
      </a>
      <button type="submit" class="op-form__btn op-form__btn--primary">
        Enregistrer l'inventaire
      </button>
    </div>

  </form>
  <?php elseif ($loadErr === null): ?>
    <div class="op-form__card">
      <p class="rms-empty">Aucun ingrédient inventoriable actif trouvé en base de données.</p>
    </div>
  <?php endif ?>

</main>

<script src="/js/form-rm-stocktake.js?v=<?= @filemtime(__DIR__ . '/../js/form-rm-stocktake.js') ?: time() ?>" defer></script>
</body>
</html>
