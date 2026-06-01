<?php
declare(strict_types=1);
/**
 * form-rm-stocktake.php — Operator RM per-pallet stocktake form.
 *
 * GET-render only. Adds/deletes are handled async by JSON endpoints:
 *   POST /api/rm-stocktake-line-add.php
 *   POST /api/rm-stocktake-line-delete.php
 *
 * The rollup into inv_rm_stocktake.counted_qty is driven by those endpoints
 * (rm_recompute_rollup). Legacy periods retain their direct counted_qty values
 * — no backfill of pre-existing rows into the lines table.
 *
 * Auth: require_login() — no manager/admin gate (inventory carve-out).
 * URL: /modules/form-rm-stocktake.php[?period=YYYY-MM]
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/settings.php';
require __DIR__ . '/../../app/settings-helpers.php';

require_login();
$me = current_user();

// ── GET render ────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

$periodParam = isset($_GET['period']) ? trim((string) $_GET['period']) : null;
if ($periodParam !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodParam)) {
    $periodParam = null;
}
$selectedPeriod = $periodParam ?? date('Y-m');

try {
    $pdo = maltytask_pdo();

    // Load all inventoried active MIs for type-ahead + server-render
    $miRows = $pdo->query(
        "SELECT m.id, m.mi_id, m.name, m.pricing_unit,
                c.name AS category
           FROM ref_mi m
           JOIN ref_mi_categories c ON m.category_id = c.id
          WHERE m.is_inventoried = 1
            AND m.is_active = 1
          ORDER BY m.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Active lines for the selected period (grouped by mi_id for the ledger)
    $lineRows = [];
    if ($miRows) {
        $linesStmt = $pdo->prepare(
            'SELECT l.id, l.mi_id_fk, l.mi_id, l.qty, l.counted_at,
                    m.name AS mi_name, m.pricing_unit
               FROM inv_rm_stocktake_lines l
               JOIN ref_mi m ON m.id = l.mi_id_fk
              WHERE l.period = ? AND l.is_active = 1
              ORDER BY m.name ASC, l.id ASC'
        );
        $linesStmt->execute([$selectedPeriod]);
        $lineRows = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Group lines by mi_id for ledger rendering
    $linesByMi = [];
    foreach ($lineRows as $lr) {
        $mid = $lr['mi_id'];
        if (!isset($linesByMi[$mid])) {
            $linesByMi[$mid] = [
                'mi_id'      => $mid,
                'mi_id_fk'   => (int) $lr['mi_id_fk'],
                'mi_name'    => $lr['mi_name'],
                'pricing_unit'=> $lr['pricing_unit'] ?? '—',
                'lines'      => [],
                'subtotal'   => 0.0,
            ];
        }
        $linesByMi[$mid]['lines'][]    = $lr;
        $linesByMi[$mid]['subtotal']  += (float) $lr['qty'];
    }

    // Grand total across all active lines for this period
    $grandTotal = array_sum(array_column($linesByMi, 'subtotal'));

    $loadErr = null;

} catch (Throwable $e) {
    $miRows      = [];
    $linesByMi   = [];
    $grandTotal  = 0.0;
    $loadErr     = $e->getMessage();
}

// Build window.RM_MIS payload (type-ahead data) — XSS-safe via JSON_HEX_TAG|JSON_HEX_AMP
$rmMisJson = json_encode(
    array_map(fn($r) => [
        'id'    => (int) $r['id'],
        'mi_id' => $r['mi_id'],
        'name'  => $r['name'],
        'unit'  => $r['pricing_unit'] ?? '',
    ], $miRows),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
);

$csrf          = csrf_token();
$active_module = 'saisies';

/**
 * Formats a qty decimal for display: strips trailing zeros (e.g. "25.000" → "25").
 */
function fmt_qty(string $qty): string
{
    if (str_contains($qty, '.')) {
        return rtrim(rtrim($qty, '0'), '.');
    }
    return $qty;
}
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
      Saisie du comptage physique mensuel, palette par palette.
      Chaque ajout est enregistré immédiatement et totalise automatiquement.
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
    <p class="rms-period-hint <?= !empty($linesByMi) ? 'rms-period-hint--preloaded' : '' ?>">
      <?php if (!empty($linesByMi)): ?>
        ✓ <?= count($linesByMi) ?> ingrédient<?= count($linesByMi) > 1 ? 's' : '' ?> saisi<?= count($linesByMi) > 1 ? 's' : '' ?>
        pour <strong><?= htmlspecialchars($selectedPeriod) ?></strong>.
      <?php else: ?>
        Aucune saisie pour <strong><?= htmlspecialchars($selectedPeriod) ?></strong>.
      <?php endif ?>
    </p>
  </div>

  <?php if (!empty($miRows)): ?>

  <!-- ── Entry card ───────────────────────────────────────────────────────── -->
  <div class="op-form__card rms-entry-card">
    <div class="op-form__card-title">Ajouter une palette</div>

    <!-- Type-ahead search -->
    <div class="rms-entry-row">
      <div class="rms-search-wrap">
        <input type="text"
               id="rms-mi-search"
               class="op-form__input rms-mi-search"
               placeholder="Rechercher un ingrédient…"
               autocomplete="off"
               autocorrect="off"
               spellcheck="false">
        <ul id="rms-mi-dropdown" class="rms-mi-dropdown" role="listbox" aria-label="Ingrédients" hidden></ul>
      </div>

      <!-- Selected MI display -->
      <div id="rms-selected-mi" class="rms-selected-mi" hidden>
        <span id="rms-selected-name" class="rms-selected-name"></span>
        <span id="rms-selected-unit" class="rms-selected-unit"></span>
        <button type="button" id="rms-clear-mi" class="rms-clear-mi" aria-label="Changer d'ingrédient">✕</button>
      </div>

      <!-- Hidden MI state -->
      <input type="hidden" id="rms-mi-id-fk" value="">

      <!-- Qty input -->
      <div class="rms-qty-wrap" id="rms-qty-wrap" hidden>
        <input type="number"
               id="rms-qty-input"
               class="op-form__input rms-qty-input"
               min="0"
               step="0.001"
               placeholder="0"
               autocomplete="off">
        <span id="rms-qty-unit" class="rms-mi-row__unit"></span>
      </div>

      <!-- Add button -->
      <button type="button" id="rms-add-btn" class="op-form__btn op-form__btn--primary rms-add-btn" hidden>
        + Ajouter
      </button>
    </div>

    <!-- Inline feedback -->
    <div id="rms-entry-msg" class="rms-entry-msg" hidden></div>
  </div>

  <!-- ── Ledger card ───────────────────────────────────────────────────────── -->
  <div class="op-form__card rms-ledger-card">
    <div class="op-form__card-title">
      Saisies du mois
      <span class="rms-ledger-period"><?= htmlspecialchars($selectedPeriod) ?></span>
    </div>

    <div id="rms-ledger" class="rms-ledger" data-period="<?= htmlspecialchars($selectedPeriod) ?>">
      <?php if (empty($linesByMi)): ?>
        <p id="rms-ledger-empty" class="rms-ledger-empty">Aucune saisie pour ce mois.</p>
      <?php else: ?>
        <p id="rms-ledger-empty" class="rms-ledger-empty" hidden>Aucune saisie pour ce mois.</p>
      <?php endif ?>

      <div id="rms-ledger-rows" class="rms-ledger-rows">
        <?php foreach ($linesByMi as $mid => $miData): ?>
        <div class="rms-ledger-mi" data-mi-id="<?= htmlspecialchars($mid) ?>">
          <div class="rms-ledger-mi-header">
            <span class="rms-ledger-mi-name"><?= htmlspecialchars($miData['mi_name']) ?></span>
            <span class="rms-ledger-mi-subtotal" id="sub_<?= htmlspecialchars($mid) ?>">
              <?= htmlspecialchars(fmt_qty(number_format($miData['subtotal'], 3, '.', ''))) ?>
              <span class="rms-ledger-mi-unit"><?= htmlspecialchars($miData['pricing_unit']) ?></span>
            </span>
          </div>
          <div class="rms-ledger-chips" id="chips_<?= htmlspecialchars($mid) ?>">
            <?php foreach ($miData['lines'] as $line): ?>
            <span class="rms-chip" data-line-id="<?= (int) $line['id'] ?>" data-mi-id="<?= htmlspecialchars($mid) ?>">
              <?= htmlspecialchars(fmt_qty($line['qty'])) ?>
              <button type="button" class="rms-chip-del" aria-label="Supprimer cette ligne">✕</button>
            </span>
            <?php endforeach ?>
          </div>
        </div>
        <?php endforeach ?>
      </div>

      <div class="rms-ledger-total-row">
        <span class="rms-ledger-total-label">Total général</span>
        <span class="rms-ledger-grand-total" id="rms-grand-total">
          <?= htmlspecialchars(fmt_qty(number_format($grandTotal, 3, '.', ''))) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- ── FAB: floating submit (links back to saisies after confirmation) ──── -->
  <a href="/modules/saisies.php" class="rms-fab-back op-form__btn op-form__btn--secondary">
    ← Retour
  </a>

  <?php elseif ($loadErr === null): ?>
    <div class="op-form__card">
      <p class="rms-empty">Aucun ingrédient inventoriable actif trouvé en base de données.</p>
    </div>
  <?php endif ?>

</main>

<script>
window.RM_MIS = <?= $rmMisJson ?>;
window.RMS_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.RMS_PERIOD = <?= json_encode($selectedPeriod, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/form-rm-stocktake.js?v=<?= @filemtime(__DIR__ . '/../js/form-rm-stocktake.js') ?: time() ?>" defer></script>
</body>
</html>
