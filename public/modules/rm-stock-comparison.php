<?php
declare(strict_types=1);
/**
 * rm-stock-comparison.php — Bilan de clôture MP
 *
 * Comparatif comptage physique vs théorique par MP, avec écarts.
 * READ-ONLY: aucune écriture en base, aucun POST, aucun CSRF.
 *
 * Paramètre : ?period=YYYY-MM  (défaut : mois en cours)
 * Formule théorique PM-approved :
 *   opening  = inv_rm_stocktake[période N-1, is_active=1].final_qty
 *   entrées  = SUM(inv_deliveries.qty_delivered) sur status IN ('Active','Pending')
 *   sorties  = SUM(inv_consumption.qty) toutes source_event confondues
 *   théorique = opening + entrées - sorties
 *   compté   = inv_rm_stocktake[période N, is_active=1].counted_qty
 *   Δ abs    = compté - théorique
 *   Δ %      = Δ abs / |théorique|
 *
 * INTERDIT : lecture de v_rm_stock_dynamic (ancre mobile = dérive tautologique).
 */

require __DIR__ . "/../../app/auth.php";
require_page_access('rm-comparison');
$me = current_user();

$active_module = "rm-comparison";

header("Content-Type: text/html; charset=utf-8");

// ── Paramètre period : lecture avec défaut, puis validation ─────────────────
$periodRaw = $_GET['period'] ?? date('Y-m');   // défaut = mois courant
$period    = (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodRaw)) ? $periodRaw : date('Y-m');

// Période N-1 via DateTime
$dtClose  = DateTimeImmutable::createFromFormat('Y-m', $period);
$dtPrior  = $dtClose->modify('first day of last month');
$priorMonth = $dtPrior->format('Y-m');

// Premier et dernier jour du mois de clôture
$monthStart = $dtClose->format('Y-m-01');
$monthEnd   = $dtClose->format('Y-m-t');    // last day

// Libellés FR (clôture + N-1) — calculés une fois, partagés via $priorMonthFR
$monthsFRLong = [
    1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
    7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre',
];
$periodLabel  = $monthsFRLong[(int) $dtClose->format('n')] . ' ' . $dtClose->format('Y');
$priorMonthFR = $monthsFRLong[(int) $dtPrior->format('n')] . ' ' . $dtPrior->format('Y');

// ── Seuils drift (P3 : déplacer vers commissioning_settings) ────────────────
const DRIFT_ABS_THRESHOLD = 50;
const DRIFT_PCT_THRESHOLD = 0.05;

// ── Data layer ───────────────────────────────────────────────────────────────
$dbError = null;
$rows    = [];

/*
 * SQL de clôture comparative (ancre fixe = final_qty de la période N-1).
 * NE LIT PAS v_rm_stock_dynamic.
 * LEFT JOIN depuis le spine is_inventoried=1 : aucune MP ne disparaît.
 */
$sql = "
WITH
  mi_spine AS (
    SELECT m.id            AS mi_pk,
           m.mi_id,
           m.name          AS mi_name,
           m.pricing_unit  AS unit,
           COALESCE(c.name, '(Sans catégorie)') AS category,
           m.is_active
      FROM ref_mi m
      LEFT JOIN ref_mi_categories c ON c.id = m.category_id
     WHERE m.is_inventoried = 1
  ),
  opening AS (
    -- final_qty = GENERATED COALESCE(counted_qty, expected_qty)
    -- ~111 lignes avril sont expected-only : elles ont un opening valide via final_qty.
    SELECT st.mi_id_fk,
           st.final_qty,
           st.counted_qty  AS open_counted_qty,
           st.expected_qty AS open_expected_qty
      FROM inv_rm_stocktake st
     WHERE st.period    = :prior_month
       AND st.is_active = 1
  ),
  inflows AS (
    SELECT d.ingredient_fk,
           COALESCE(SUM(d.qty_delivered), 0) AS qty_in
      FROM inv_deliveries d
     WHERE d.date_received BETWEEN :month_start AND :month_end
       AND d.status IN ('Active', 'Pending')
     GROUP BY d.ingredient_fk
  ),
  outflows_cte AS (
    SELECT c.mi_id_fk,
           COALESCE(SUM(c.qty), 0) AS qty_out
      FROM inv_consumption c
     WHERE c.consumed_at BETWEEN :month_start2 AND :month_end2
     GROUP BY c.mi_id_fk
  ),
  closing_count AS (
    -- counted_qty uniquement : le décompte physique frais (pas final_qty)
    SELECT st.mi_id_fk,
           st.counted_qty
      FROM inv_rm_stocktake st
     WHERE st.period    = :closing_month
       AND st.is_active = 1
  )
SELECT
    sp.category,
    sp.mi_name,
    sp.unit,
    sp.mi_pk,
    op.final_qty                                                       AS opening,
    op.open_counted_qty,
    op.open_expected_qty,
    COALESCE(inf.qty_in,  0)                                           AS inflows,
    COALESCE(oc.qty_out,  0)                                           AS outflows,
    CASE WHEN op.final_qty IS NULL THEN NULL
         ELSE op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0)
    END                                                                AS theoretical,
    cc.counted_qty                                                     AS counted,
    -- drift_abs = NULL si opening absent OU si pas encore de comptage de clôture
    CASE WHEN op.final_qty IS NULL   THEN NULL
         WHEN cc.counted_qty IS NULL THEN NULL
         ELSE cc.counted_qty
              - (op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0))
    END                                                                AS drift_abs,
    -- drift_pct sur valeur absolue du théorique pour éviter l'inversion de signe
    CASE WHEN op.final_qty IS NULL   THEN NULL
         WHEN cc.counted_qty IS NULL THEN NULL
         WHEN (op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0)) = 0 THEN NULL
         ELSE (cc.counted_qty
               - (op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0)))
              / ABS(op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0))
    END                                                                AS drift_pct,
    CASE
      WHEN op.final_qty IS NULL   THEN 'no_opening_anchor'
      WHEN cc.counted_qty IS NULL THEN 'no_closing_count'
      WHEN ABS(cc.counted_qty
               - (op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0))) > :drift_abs
           OR ABS(
                (cc.counted_qty
                 - (op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0)))
                / NULLIF(
                    ABS(op.final_qty + COALESCE(inf.qty_in, 0) - COALESCE(oc.qty_out, 0)),
                    0
                  )
              ) > :drift_pct
        THEN 'drift'
      ELSE 'ok'
    END                                                                AS flag
  FROM mi_spine sp
  LEFT JOIN opening       op  ON op.mi_id_fk      = sp.mi_pk
  LEFT JOIN inflows       inf ON inf.ingredient_fk = sp.mi_pk
  LEFT JOIN outflows_cte  oc  ON oc.mi_id_fk       = sp.mi_pk
  LEFT JOIN closing_count cc  ON cc.mi_id_fk        = sp.mi_pk
 ORDER BY sp.category, sp.mi_name
";

try {
    $pdo = maltytask_pdo();

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':prior_month'   => $priorMonth,
        ':month_start'   => $monthStart,
        ':month_end'     => $monthEnd,
        ':month_start2'  => $monthStart,
        ':month_end2'    => $monthEnd,
        ':closing_month' => $period,
        ':drift_abs'     => DRIFT_ABS_THRESHOLD,
        ':drift_pct'     => DRIFT_PCT_THRESHOLD,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ── Bucketing ────────────────────────────────────────────────────────────────
$bucketDrift    = [];   // écarts actionables, triés par |drift_abs| desc
$bucketNoAnchor = [];   // pas d'opening N-1 → non réconciliable
$bucketNoCount  = [];   // théorique OK mais comptage clôture absent
$bucketOk       = [];   // tout bon

foreach ($rows as $row) {
    switch ($row['flag']) {
        case 'drift':            $bucketDrift[]    = $row; break;
        case 'no_opening_anchor':$bucketNoAnchor[] = $row; break;
        case 'no_closing_count': $bucketNoCount[]  = $row; break;
        default:                 $bucketOk[]       = $row;
    }
}

// Tri drift : |Δ abs| desc
usort($bucketDrift, function (array $a, array $b): int {
    return (int) (abs((float)$b['drift_abs']) * 1000 - abs((float)$a['drift_abs']) * 1000);
});

// ── Helpers d'affichage ──────────────────────────────────────────────────────

function rsc_num(mixed $v, int $dec = 2, string $fallback = '—'): string
{
    if ($v === null || $v === '') return $fallback;
    return number_format((float)$v, $dec, '.', '\u{202F}');
}

function rsc_pct(mixed $v, string $fallback = '—'): string
{
    if ($v === null || $v === '') return $fallback;
    $pct = (float)$v * 100;
    return ($pct >= 0 ? '+' : '') . number_format($pct, 1, '.', '') . '%';
}

function rsc_drift_class(mixed $drift_abs): string
{
    if ($drift_abs === null) return '';
    return (float)$drift_abs > 0 ? 'rsc-drift--over' : 'rsc-drift--under';
}

/**
 * Indique si l'opening est basé sur expected_qty uniquement (pas compté).
 */
function rsc_opening_source(array $row): string
{
    global $priorMonthFR;
    if ($row['opening'] === null) return '';
    if ($row['open_counted_qty'] === null && $row['open_expected_qty'] !== null) {
        $label = htmlspecialchars((string) ($priorMonthFR ?? 'la période précédente'), ENT_QUOTES, 'UTF-8');
        return '<span class="rsc-badge rsc-badge--expected" title="Valeur attendue (non comptée en ' . $label . ')">att.</span>';
    }
    return '';
}

/**
 * Génère le tableau pour un groupe de lignes, triées par catégorie.
 */
function rsc_table(array $rows, bool $showDrift = false): void
{
    if (empty($rows)) {
        echo '<p class="rsc-empty">Aucune ligne dans cette section.</p>';
        return;
    }

    // Regroupement par catégorie
    $byCategory = [];
    foreach ($rows as $row) {
        $byCategory[$row['category']][] = $row;
    }
    ksort($byCategory);
    ?>
    <table class="rsc-table">
      <thead>
        <tr>
          <th class="rsc-th rsc-th--mi">Matière première</th>
          <th class="rsc-th rsc-th--unit">Unité</th>
          <th class="rsc-th rsc-th--num">Ouverture</th>
          <th class="rsc-th rsc-th--num">+ Entrées</th>
          <th class="rsc-th rsc-th--num">− Sorties</th>
          <th class="rsc-th rsc-th--num rsc-th--theor">Théorique</th>
          <th class="rsc-th rsc-th--num rsc-th--counted">Compté</th>
          <?php if ($showDrift): ?>
          <th class="rsc-th rsc-th--num rsc-th--drift">Δ abs</th>
          <th class="rsc-th rsc-th--num rsc-th--drift">Δ %</th>
          <?php endif ?>
          <th class="rsc-th rsc-th--flag">État</th>
        </tr>
      </thead>
      <tbody>
    <?php
    foreach ($byCategory as $catName => $catRows) {
        ?>
        <tr class="rsc-cat-row">
          <td colspan="<?= $showDrift ? 10 : 8 ?>" class="rsc-cat-cell">
            <?= htmlspecialchars($catName) ?>
            <span class="rsc-cat-count"><?= count($catRows) ?></span>
          </td>
        </tr>
        <?php
        foreach ($catRows as $row) {
            $flagClass = match ($row['flag']) {
                'drift'            => 'rsc-flag--drift',
                'no_opening_anchor'=> 'rsc-flag--no-anchor',
                'no_closing_count' => 'rsc-flag--no-count',
                default            => 'rsc-flag--ok',
            };
            $flagLabel = match ($row['flag']) {
                'drift'            => 'Écart',
                'no_opening_anchor'=> 'Ouverture manquante',
                'no_closing_count' => 'Comptage attendu',
                default            => 'OK',
            };
            $driftAbs = $row['drift_abs'] !== null ? (float)$row['drift_abs'] : null;
            $driftClass = rsc_drift_class($driftAbs);
            ?>
            <tr class="rsc-row rsc-row--<?= htmlspecialchars($row['flag']) ?>">
              <td class="rsc-td rsc-td--mi">
                <?= htmlspecialchars($row['mi_name']) ?>
                <?= rsc_opening_source($row) ?>
              </td>
              <td class="rsc-td rsc-td--unit"><?= htmlspecialchars((string)($row['unit'] ?? '—')) ?></td>
              <td class="rsc-td rsc-td--num"><?= rsc_num($row['opening']) ?></td>
              <td class="rsc-td rsc-td--num rsc-td--inflow"><?= rsc_num($row['inflows']) ?></td>
              <td class="rsc-td rsc-td--num rsc-td--outflow"><?= rsc_num($row['outflows']) ?></td>
              <td class="rsc-td rsc-td--num rsc-td--theor"><?= rsc_num($row['theoretical']) ?></td>
              <td class="rsc-td rsc-td--num rsc-td--counted"><?= rsc_num($row['counted']) ?></td>
              <?php if ($showDrift): ?>
              <td class="rsc-td rsc-td--num rsc-td--drift <?= $driftClass ?>">
                <?= $driftAbs !== null ? (($driftAbs >= 0 ? '+' : '') . rsc_num($driftAbs)) : '—' ?>
              </td>
              <td class="rsc-td rsc-td--num rsc-td--drift <?= $driftClass ?>">
                <?= rsc_pct($row['drift_pct']) ?>
              </td>
              <?php endif ?>
              <td class="rsc-td rsc-td--flag">
                <span class="rsc-flag <?= $flagClass ?>"><?= htmlspecialchars($flagLabel) ?></span>
              </td>
            </tr>
        <?php } // end foreach catRows
    } // end foreach byCategory
    ?>
      </tbody>
    </table>
    <?php
}

// ── KPIs ─────────────────────────────────────────────────────────────────────
$totalMi     = count($rows);
$countDrift  = count($bucketDrift);
$countNoAnch = count($bucketNoAnchor);
$countNoCount= count($bucketNoCount);
$countOk     = count($bucketOk);

// $periodLabel et $priorMonthFR sont calculés en tête de fichier.
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bilan MP — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
  <link rel="stylesheet" href="/css/rm-stock-comparison.css?v=<?= @filemtime(__DIR__ . '/../css/rm-stock-comparison.css') ?: time() ?>">
</head>
<body class="home">

<?php require __DIR__ . "/../../app/partials/sidebar.php" ?>
<?php require __DIR__ . "/../../app/partials/topbar.php" ?>

<main class="main rsc-main">

  <?php if ($dbError): ?>
    <div class="rsc-dberror">
      Erreur base de données&nbsp;: <?= htmlspecialchars($dbError) ?>
    </div>
  <?php endif ?>

  <!-- ── En-tête ──────────────────────────────────────────────────────────── -->
  <header class="rsc-header">
    <div class="rsc-header__titles">
      <h1 class="rsc-title">Bilan de clôture MP</h1>
      <p class="rsc-subtitle">
        Comptage physique vs&nbsp;théorique — clôture&nbsp;
        <strong><?= htmlspecialchars($periodLabel) ?></strong>
      </p>
    </div>
    <form class="rsc-period-form" method="get" action="">
      <label class="rsc-period-label" for="rsc-period-input">Période de clôture</label>
      <input type="month"
             id="rsc-period-input"
             name="period"
             value="<?= htmlspecialchars($period) ?>"
             class="rsc-period-input">
      <button type="submit" class="rsc-period-btn">Actualiser</button>
    </form>
  </header>

  <!-- ── Note lecture seule ───────────────────────────────────────────────── -->
  <div class="rsc-readonly-notice" role="note">
    <span class="rsc-readonly-icon" aria-hidden="true">🔒</span>
    Rapport lecture seule — aucune correction automatique.
    Toute action corrective est décidée par l'opérateur.
  </div>

  <!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
  <section class="rsc-kpis" aria-label="Vue d'ensemble">
    <div class="rsc-kpi rsc-kpi--total">
      <span class="rsc-kpi__num"><?= $totalMi ?></span>
      <span class="rsc-kpi__label">MP inventoriées</span>
    </div>
    <div class="rsc-kpi rsc-kpi--drift">
      <span class="rsc-kpi__num"><?= $countDrift ?></span>
      <span class="rsc-kpi__label">Écarts détectés</span>
    </div>
    <div class="rsc-kpi rsc-kpi--noanchor">
      <span class="rsc-kpi__num"><?= $countNoAnch ?></span>
      <span class="rsc-kpi__label">Sans ouverture N-1</span>
    </div>
    <div class="rsc-kpi rsc-kpi--nocount">
      <span class="rsc-kpi__num"><?= $countNoCount ?></span>
      <span class="rsc-kpi__label">Comptage attendu</span>
    </div>
    <div class="rsc-kpi rsc-kpi--ok">
      <span class="rsc-kpi__num"><?= $countOk ?></span>
      <span class="rsc-kpi__label">Sans écart</span>
    </div>
  </section>

  <!-- ── PRIORITÉ 1 : Écarts actionables ──────────────────────────────────── -->
  <?php if (!empty($bucketDrift)): ?>
  <section class="rsc-section rsc-section--drift" aria-labelledby="rsc-drift-hd">
    <h2 class="rsc-section-title" id="rsc-drift-hd">
      <span class="rsc-section-icon" aria-hidden="true">⚑</span>
      Écarts détectés
      <span class="rsc-section-count"><?= $countDrift ?></span>
    </h2>
    <p class="rsc-section-desc">
      Triés par écart absolu décroissant. Seuils&nbsp;: &gt;&nbsp;<?= DRIFT_ABS_THRESHOLD ?> unités
      OU &gt;&nbsp;<?= (int)(DRIFT_PCT_THRESHOLD * 100) ?>%.
    </p>
    <?php rsc_table($bucketDrift, showDrift: true) ?>
  </section>
  <?php endif ?>

  <!-- ── PRIORITÉ 2 : Ouverture manquante ─────────────────────────────────── -->
  <?php if (!empty($bucketNoAnchor)): ?>
  <section class="rsc-section rsc-section--no-anchor" aria-labelledby="rsc-noanchor-hd">
    <h2 class="rsc-section-title" id="rsc-noanchor-hd">
      <span class="rsc-section-icon" aria-hidden="true">◎</span>
      Ouverture N-1 manquante
      <span class="rsc-section-count"><?= $countNoAnch ?></span>
    </h2>
    <p class="rsc-section-desc">
      Ces MP n'ont pas de ligne de clôture en&nbsp;<?= htmlspecialchars($priorMonth) ?>.
      Aucune réconciliation possible tant qu'un solde d'ouverture n'est pas saisi.
    </p>
    <?php rsc_table($bucketNoAnchor, showDrift: false) ?>
  </section>
  <?php endif ?>

  <!-- ── PRIORITÉ 3 : Comptage de clôture attendu ──────────────────────────── -->
  <?php if (!empty($bucketNoCount)): ?>
  <section class="rsc-section rsc-section--no-count" aria-labelledby="rsc-nocount-hd">
    <h2 class="rsc-section-title" id="rsc-nocount-hd">
      <span class="rsc-section-icon" aria-hidden="true">◷</span>
      Comptage de clôture attendu
      <span class="rsc-section-count"><?= $countNoCount ?></span>
    </h2>
    <p class="rsc-section-desc">
      Théorique calculé depuis l'ouverture d'<?= htmlspecialchars($priorMonth) ?>.
      Le comptage physique de&nbsp;<?= htmlspecialchars($periodLabel) ?> n'a pas encore été saisi.
    </p>
    <details class="rsc-details">
      <summary class="rsc-details__summary">
        Afficher les <?= $countNoCount ?> lignes
      </summary>
      <?php rsc_table($bucketNoCount, showDrift: false) ?>
    </details>
  </section>
  <?php endif ?>

  <!-- ── OK : sans écart ───────────────────────────────────────────────────── -->
  <?php if (!empty($bucketOk)): ?>
  <section class="rsc-section rsc-section--ok" aria-labelledby="rsc-ok-hd">
    <h2 class="rsc-section-title rsc-section-title--ok" id="rsc-ok-hd">
      <span class="rsc-section-icon" aria-hidden="true">✓</span>
      Sans écart
      <span class="rsc-section-count"><?= $countOk ?></span>
    </h2>
    <details class="rsc-details">
      <summary class="rsc-details__summary">
        Afficher les <?= $countOk ?> lignes conformes
      </summary>
      <?php rsc_table($bucketOk, showDrift: true) ?>
    </details>
  </section>
  <?php endif ?>

  <?php if (empty($rows) && !$dbError): ?>
  <div class="rsc-empty-state">
    Aucune matière première inventoriée trouvée pour la période sélectionnée.
  </div>
  <?php endif ?>

</main>
</body>
</html>
