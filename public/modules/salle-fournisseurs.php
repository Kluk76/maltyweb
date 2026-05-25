<?php
declare(strict_types=1);
/**
 * /modules/salle-fournisseurs.php
 * Salle des Machines — Gouvernance Fournisseurs (admin/manager).
 *
 * This is the master-data GOVERNANCE counterpart to approvisionnement.php
 * (which is read-only). This page surfaces per-field trust state
 * (auto / vérifié / verrouillé), commissioning_state gate (draft→active),
 * GL footprint from ref_supplier_gls (with fallback to inv_deliveries),
 * and field-level pins from ref_supplier_field_pins.
 *
 * Role gate: managers see the page in read+propose mode; admins get full
 * edit / validate / pin controls. Opérateurs are redirected.
 *
 * Write endpoints: NOT wired in this pass — affordances render disabled
 * stubs. See reports/salle-fournisseurs-write-plan.md for the proposal.
 *
 * Data pattern: separate grouped queries merged in PHP (no JOIN fan-out).
 * ONLY_FULL_GROUP_BY safe: any non-aggregated non-grouped column wrapped
 * in ANY_VALUE(). See anti-pattern #1 in sql skill.
 */

require __DIR__ . "/../../app/auth.php";
require __DIR__ . "/../../app/csrf.php";
require_login();
$me = current_user();

/* ── Role gate ───────────────────────────────────────────────────── */
if (is_admin($me)) {
    $bodyRole = 'admin';
} elseif (is_manager($me)) {
    $bodyRole = 'manager';
} else {
    // Opérateurs have no business on the governance page
    header("Location: /modules/approvisionnement.php");
    exit;
}

$active_module = "salle-fournisseurs"; // not in topbar yet — intentional

/* ── DB connection ───────────────────────────────────────────────── */
$pdo      = maltytask_pdo();
$dbError  = null;
$suppliers = [];

try {

    /* ── Q1: GL label map from ref_mi_categories ─────────────────
     * default_gl_account → human category name for GL display.
     */
    $glLabelMap = [];
    $glStmt = $pdo->query(
        "SELECT default_gl_account, ANY_VALUE(name) AS label
           FROM ref_mi_categories
          WHERE default_gl_account IS NOT NULL
          GROUP BY default_gl_account"
    );
    foreach ($glStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $glLabelMap[(string) $row['default_gl_account']] = (string) $row['label'];
    }

    /* ── Q2: Aliases grouped by supplier_fk ──────────────────────
     * Returns alias + source per supplier. source ENUM('manual','observed').
     */
    $aliasMap = [];
    $aliasStmt = $pdo->query(
        "SELECT supplier_id_fk,
                alias,
                source
           FROM ref_supplier_aliases
          ORDER BY supplier_id_fk, alias"
    );
    foreach ($aliasStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_id_fk'];
        if (!isset($aliasMap[$fk])) $aliasMap[$fk] = [];
        $aliasMap[$fk][] = [
            'alias'  => (string) $row['alias'],
            'source' => (string) $row['source'],
        ];
    }

    /* ── Q3: MI catalogue per supplier ──────────────────────────
     * Observed MIs from inv_deliveries (Active/Consumed).
     * ingredient_fk IS NOT NULL = only fully-resolved lines.
     * ONLY_FULL_GROUP_BY safe: group by d.supplier_fk, m.id;
     * mi_id/name/category are functionally dependent on m.id → ANY_VALUE.
     */
    $catalogueMap = [];
    $catStmt = $pdo->query(
        "SELECT d.supplier_fk,
                ANY_VALUE(m.mi_id)   AS mi_id,
                ANY_VALUE(m.name)    AS mi_name,
                ANY_VALUE(cat.name)  AS category,
                COUNT(*)             AS delivery_count
           FROM inv_deliveries d
           JOIN ref_mi m             ON m.id = d.ingredient_fk
           LEFT JOIN ref_mi_categories cat ON cat.id = m.category_id
          WHERE d.status IN ('Active','Consumed')
            AND d.ingredient_fk IS NOT NULL
          GROUP BY d.supplier_fk, m.id
          ORDER BY d.supplier_fk, COUNT(*) DESC"
    );
    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($catalogueMap[$fk])) $catalogueMap[$fk] = [];
        $catalogueMap[$fk][] = [
            'mi_id'     => (string) $row['mi_id'],
            'name'      => (string) $row['mi_name'],
            'category'  => (string) ($row['category'] ?? ''),
            'deliveries'=> (int)    $row['delivery_count'],
        ];
    }

    /* ── Q4: GL footprint from ref_supplier_gls (governance table) ─
     * May be empty (migrations 121-124 applied but no data seeded yet).
     * Falls back to inv_deliveries footprint (Q4b) if empty for a supplier.
     */
    $glsMap = [];
    $glsStmt = $pdo->query(
        "SELECT supplier_fk, gl_account, gl_label,
                is_primary, is_excluded_from_cogs_footprint,
                derived_from, observed_delivery_count,
                effective_from, effective_until
           FROM ref_supplier_gls
          WHERE effective_until IS NULL
          ORDER BY supplier_fk, is_primary DESC, observed_delivery_count DESC"
    );
    foreach ($glsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($glsMap[$fk])) $glsMap[$fk] = [];
        $glsMap[$fk][] = [
            'gl'           => (string) $row['gl_account'],
            'gl_label'     => (string) ($row['gl_label'] ?? ''),
            'is_primary'   => (int) $row['is_primary'] === 1,
            'excluded_cogs'=> (int) $row['is_excluded_from_cogs_footprint'] === 1,
            'derived_from' => (string) $row['derived_from'],
            'del_count'    => $row['observed_delivery_count'] !== null
                                ? (int) $row['observed_delivery_count'] : null,
            'from_date'    => (string) $row['effective_from'],
            'source'       => 'ref_supplier_gls',
        ];
    }

    /* ── Q4b: GL footprint fallback from inv_deliveries ─────────
     * For suppliers not yet in ref_supplier_gls, derive footprint
     * from observed delivery GL via ref_mi.gl_account.
     * ONLY_FULL_GROUP_BY safe: GROUP BY supplier_fk, gl_account.
     * chf_total and line_count are aggregates; gl_label via LEFT JOIN
     * → ANY_VALUE.
     */
    $footprintFallbackMap = [];
    $fpStmt = $pdo->query(
        "SELECT d.supplier_fk,
                m.gl_account,
                ANY_VALUE(cat.name) AS gl_label,
                SUM(d.total_chf)    AS chf_total,
                COUNT(*)            AS line_count
           FROM inv_deliveries d
           JOIN ref_mi m             ON m.id = d.ingredient_fk
           LEFT JOIN ref_mi_categories cat ON cat.id = m.category_id
          WHERE d.status IN ('Active','Consumed')
            AND d.ingredient_fk IS NOT NULL
            AND d.exclusion_class IS NULL
          GROUP BY d.supplier_fk, m.gl_account
          ORDER BY d.supplier_fk, SUM(d.total_chf) DESC"
    );
    foreach ($fpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($footprintFallbackMap[$fk])) $footprintFallbackMap[$fk] = [];
        $footprintFallbackMap[$fk][] = [
            'gl'       => (string) ($row['gl_account'] ?? ''),
            'gl_label' => (string) ($row['gl_label']   ?? ''),
            'chf'      => (float)  ($row['chf_total']  ?? 0),
            'lines'    => (int)    $row['line_count'],
        ];
    }

    /* ── Q5: Field pins from ref_supplier_field_pins ──────────────
     * Keyed by [supplier_fk][field_name] → pin metadata.
     */
    $pinsMap = [];
    $pinStmt = $pdo->query(
        "SELECT supplier_fk, field_name, pinned_value,
                pinned_by, pinned_at, pin_reason
           FROM ref_supplier_field_pins
          ORDER BY supplier_fk, field_name"
    );
    foreach ($pinStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($pinsMap[$fk])) $pinsMap[$fk] = [];
        $pinsMap[$fk][(string) $row['field_name']] = [
            'value'      => $row['pinned_value'] !== null ? (string) $row['pinned_value'] : null,
            'pinned_by'  => (string) $row['pinned_by'],
            'pinned_at'  => (string) $row['pinned_at'],
            'pin_reason' => $row['pin_reason'] !== null ? (string) $row['pin_reason'] : null,
        ];
    }

    /* ── Q6: Stats per supplier — TWO separate queries, merged in PHP.
     * NEVER join doc_invoices + inv_deliveries in one GROUP BY — fan-out
     * inflates SUM(total_chf) by invoice count (anti-pattern in sql skill).
     */
    $statsMap = [];
    $invCountStmt = $pdo->query(
        "SELECT supplier_fk, COUNT(*) AS invoice_count
           FROM doc_invoices
          WHERE is_active = 1 AND supplier_fk IS NOT NULL
          GROUP BY supplier_fk"
    );
    foreach ($invCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statsMap[(int) $row['supplier_fk']] = [
            'invoices'  => (int) $row['invoice_count'],
            'total_chf' => null,
        ];
    }
    $chfStmt = $pdo->query(
        "SELECT supplier_fk, SUM(total_chf) AS total_chf
           FROM inv_deliveries
          WHERE status IN ('Active','Consumed') AND supplier_fk IS NOT NULL
          GROUP BY supplier_fk"
    );
    foreach ($chfStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($statsMap[$fk])) $statsMap[$fk] = ['invoices' => 0, 'total_chf' => null];
        $statsMap[$fk]['total_chf'] = $row['total_chf'] !== null ? (float) $row['total_chf'] : null;
    }

    /* ── Q7: All suppliers with governance columns ────────────────
     * Direct from ref_suppliers — no JOINs needed; all derived data
     * comes from the maps built above (avoids fan-out risk).
     * Includes governance cols: parser_key, country, vat_number,
     * vat_regime, hors_perimetre_cogs, sporadique, commissioning_state.
     */
    $suppStmt = $pdo->query(
        "SELECT id, supplier_id, name, gl_account, currency, is_active,
                notes, parser_key, country, vat_number, vat_regime,
                hors_perimetre_cogs, sporadique, commissioning_state,
                last_modified_by, last_seen_at, imported_at
           FROM ref_suppliers
          ORDER BY name"
    );

    foreach ($suppStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id  = (int) $row['id'];
        $gl  = (string) ($row['gl_account'] ?? '');
        $cur = (string) ($row['currency']   ?? '');

        /* country_display: stored ISO code takes precedence.
         * Currency-based presumption is display-only, never persisted. */
        $ctry = $row['country'] !== null ? (string) $row['country'] : null;
        if ($ctry !== null && $ctry !== '') {
            $countryDisplay = $ctry;
        } elseif ($cur === 'CHF') {
            $countryDisplay = 'CH (présumé)';
        } elseif ($cur === 'EUR') {
            $countryDisplay = 'UE / étranger (présumé)';
        } else {
            $countryDisplay = '';
        }

        /* GL footprint: prefer ref_supplier_gls, fall back to inv_deliveries. */
        $glsRows    = $glsMap[$id]             ?? [];
        $fpFallback = $footprintFallbackMap[$id] ?? [];

        /* Compute completeness score (0–6) for governance ring. */
        $pins = $pinsMap[$id] ?? [];
        $compFields = ['name','gl_account','currency','country','vat_regime','parser_key'];
        $filled = 0;
        if ($row['name'])        $filled++;
        if ($gl)                 $filled++;
        if ($cur)                $filled++;
        if ($ctry)               $filled++;
        if ($row['vat_regime'])  $filled++;
        if ($row['parser_key'])  $filled++;

        $suppliers[] = [
            'id'                 => $id,
            'supplier_id'        => (string) $row['supplier_id'],
            'name'               => (string) $row['name'],
            'gl_account'         => $gl,
            'gl_label'           => $glLabelMap[$gl] ?? '',
            'currency'           => $cur,
            'is_active'          => (int) $row['is_active'] === 1,
            'commissioning_state'=> (string) ($row['commissioning_state'] ?? 'active'),
            'parser_key'         => $row['parser_key'] !== null ? (string) $row['parser_key'] : null,
            'country'            => $ctry,
            'country_display'    => $countryDisplay,
            'vat_number'         => $row['vat_number'] !== null ? (string) $row['vat_number'] : null,
            'vat_regime'         => $row['vat_regime'] !== null ? (string) $row['vat_regime'] : null,
            'hors_perimetre'     => (int) ($row['hors_perimetre_cogs'] ?? 0) === 1,
            'sporadique'         => (int) ($row['sporadique']          ?? 0) === 1,
            'last_modified_by'   => (string) ($row['last_modified_by'] ?? 'ingest'),
            'last_seen_at'       => $row['last_seen_at']  !== null ? (string) $row['last_seen_at']  : null,
            'imported_at'        => $row['imported_at']   !== null ? (string) $row['imported_at']   : null,
            'notes'              => $row['notes'] !== null ? (string) $row['notes'] : null,
            /* Derived / map-merged */
            'aliases'            => $aliasMap[$id]    ?? [],
            'catalogue'          => $catalogueMap[$id] ?? [],
            'gls'                => $glsRows,
            'gl_footprint_obs'   => $fpFallback,
            'pins'               => $pins,
            'stats'              => $statsMap[$id] ?? ['invoices' => 0, 'total_chf' => null],
            'completeness'       => $filled,
            'completeness_max'   => count($compFields),
        ];
    }

} catch (Throwable $e) {
    $dbError = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}

/* ── Stats for the governance summary bar ────────────────────────── */
$totalSuppliers = count($suppliers);
$draftCount     = count(array_filter($suppliers, fn($s) => $s['commissioning_state'] === 'draft'));
$withParser     = count(array_filter($suppliers, fn($s) => $s['parser_key'] !== null));
$horsCount      = count(array_filter($suppliers, fn($s) => $s['hors_perimetre']));

header("Content-Type: text/html; charset=utf-8");
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gouvernance Fournisseurs — Salle des Machines · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/salle-fournisseurs.css?v=<?= @filemtime(__DIR__ . '/../../public/css/salle-fournisseurs.css') ?: time() ?>">
</head>
<body class="home salle-fournisseurs" data-role="<?= htmlspecialchars($bodyRole, ENT_QUOTES, 'UTF-8') ?>">

<?php require __DIR__ . "/../../app/partials/topbar.php"; ?>

<div class="sf-board" aria-hidden="true"></div>

<!-- Toast -->
<div class="sf-toast" id="sf-toast" role="status" aria-live="polite"></div>

<?php if ($dbError !== null): ?>
<div style="padding:40px;color:var(--ember);font-family:'JetBrains Mono',monospace;font-size:12px;">
  Erreur DB : <?= $dbError ?>
</div>
<?php else: ?>

<main class="sf-workspace" role="main">

  <!-- ── LEFT: Manifest Panel ── -->
  <aside class="sf-manifest" aria-label="Liste des fournisseurs">

    <div class="sf-header">
      <div class="sf-header-top">
        <div>
          <div class="sf-eyebrow">Salle des Machines · Gouvernance fournisseurs</div>
          <div class="sf-module-title">Registre des <em>Fournisseurs</em></div>
        </div>
        <div class="sf-header-badges">
          <?php if ($bodyRole === 'admin'): ?>
          <span class="sf-role-badge admin">Admin</span>
          <?php else: ?>
          <span class="sf-role-badge manager">Manager</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Governance summary bar -->
      <div class="sf-gov-bar">
        <div class="sf-gov-stat">
          <span class="sf-gov-num"><?= $totalSuppliers ?></span>
          <span class="sf-gov-lbl">fournisseurs</span>
        </div>
        <?php if ($draftCount > 0): ?>
        <div class="sf-gov-stat warn">
          <span class="sf-gov-num"><?= $draftCount ?></span>
          <span class="sf-gov-lbl">à valider</span>
        </div>
        <?php endif; ?>
        <div class="sf-gov-stat">
          <span class="sf-gov-num"><?= $withParser ?></span>
          <span class="sf-gov-lbl">avec parseur</span>
        </div>
        <div class="sf-gov-stat">
          <span class="sf-gov-num"><?= $horsCount ?></span>
          <span class="sf-gov-lbl">hors périmètre</span>
        </div>
      </div>

      <!-- Warehouse sketch strip (identical to approvisionnement) -->
      <div class="sf-sketch-strip">
        <svg width="100%" height="56" viewBox="0 0 420 56" xmlns="http://www.w3.org/2000/svg" class="draw" aria-hidden="true">
          <line x1="18" y1="52" x2="18" y2="8" class="ink"/>
          <line x1="78" y1="52" x2="78" y2="8" class="ink"/>
          <line x1="14" y1="52" x2="82" y2="52" class="ink"/>
          <line x1="16" y1="38" x2="80" y2="38" class="ink-2"/>
          <line x1="16" y1="24" x2="80" y2="24" class="ink-2"/>
          <line x1="16" y1="10" x2="80" y2="10" class="ink-2"/>
          <rect x="22" y="39" width="18" height="8" class="ink-2"/>
          <rect x="44" y="39" width="18" height="8" class="ink-2"/>
          <rect x="22" y="25" width="18" height="8" class="ink-2"/>
          <rect x="44" y="25" width="18" height="8" class="ink-2"/>
          <text x="30" y="56" class="dim" text-anchor="middle">RACKS</text>
          <line x1="108" y1="52" x2="108" y2="8" class="ink"/>
          <line x1="168" y1="52" x2="168" y2="8" class="ink"/>
          <line x1="104" y1="52" x2="172" y2="52" class="ink"/>
          <line x1="106" y1="38" x2="170" y2="38" class="ink-2"/>
          <line x1="106" y1="24" x2="170" y2="24" class="ink-2"/>
          <line x1="106" y1="10" x2="170" y2="10" class="ink-2"/>
          <rect x="112" y="39" width="18" height="8" class="ink-2"/>
          <rect x="134" y="39" width="18" height="8" class="ink-2"/>
          <rect x="112" y="25" width="18" height="8" class="ink-2"/>
          <rect x="134" y="25" width="18" height="8" class="ink-2"/>
          <text x="138" y="56" class="dim" text-anchor="middle">STOCK</text>
          <rect x="250" y="28" width="46" height="22" rx="3" class="ink"/>
          <line x1="252" y1="28" x2="252" y2="6" class="ink"/>
          <line x1="262" y1="28" x2="262" y2="6" class="ink"/>
          <line x1="230" y1="44" x2="252" y2="44" class="ink"/>
          <line x1="230" y1="47" x2="252" y2="47" class="ink"/>
          <circle cx="262" cy="51" r="4" class="ink"/>
          <circle cx="288" cy="51" r="4" class="ink"/>
          <rect x="232" y="34" width="16" height="9" class="ink-2"/>
          <text x="270" y="56" class="dim" text-anchor="middle">CHARIOT</text>
          <rect x="340" y="10" width="60" height="42" class="ink-2"/>
          <line x1="370" y1="10" x2="370" y2="52" class="ink-2"/>
          <line x1="342" y1="18" x2="368" y2="18" class="shade"/>
          <line x1="342" y1="26" x2="368" y2="26" class="shade"/>
          <line x1="342" y1="34" x2="368" y2="34" class="shade"/>
          <line x1="342" y1="42" x2="368" y2="42" class="shade"/>
          <line x1="372" y1="18" x2="398" y2="18" class="shade"/>
          <line x1="372" y1="26" x2="398" y2="26" class="shade"/>
          <line x1="372" y1="34" x2="398" y2="34" class="shade"/>
          <line x1="372" y1="42" x2="398" y2="42" class="shade"/>
          <line x1="10" y1="52" x2="410" y2="52" class="ink-2"/>
          <text x="368" y="56" class="dim" text-anchor="middle">QUAI</text>
        </svg>
      </div>
    </div>

    <!-- Search + filter -->
    <div class="sf-filter-bar">
      <div class="sf-search-wrap">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
          <line x1="10.5" y1="10.5" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <input type="search" id="sf-search" placeholder="Nom, ID, alias, parseur…" autocomplete="off" aria-label="Rechercher">
      </div>
    </div>
    <div class="sf-chips-bar" role="group" aria-label="Filtres">
      <button class="sf-chip on" data-filter="all">Tous</button>
      <button class="sf-chip" data-filter="active">Actifs</button>
      <button class="sf-chip" data-filter="parser">Parseur</button>
      <button class="sf-chip" data-filter="catalogue">Catalogue MI</button>
      <button class="sf-chip" data-filter="hors">Hors périmètre</button>
      <?php if ($draftCount > 0): ?>
      <button class="sf-chip warn" data-filter="draft" id="sf-draft-chip">⚑ <?= $draftCount ?> à valider</button>
      <?php endif; ?>
      <button class="sf-chip" data-filter="incomplet">Incomplet</button>
    </div>

    <!-- Sort + admin controls -->
    <div class="sf-controls-bar">
      <select class="sf-sort-select" id="sf-sort-select" aria-label="Trier par">
        <option value="alpha">Alphabétique</option>
        <option value="volume">Volume CHF ↓</option>
        <option value="incomplet">Incomplet d'abord</option>
        <option value="draft">À valider d'abord</option>
      </select>
    </div>

    <!-- Supplier list -->
    <div class="sf-list" id="sf-list" role="list"></div>
    <div class="sf-list-count" id="sf-list-count" aria-live="polite"></div>

  </aside>

  <!-- ── RIGHT: Fiche area ── -->
  <div class="sf-fiche-area">

    <!-- Empty state -->
    <div class="sf-dock-empty" id="sf-dock-empty">
      <svg width="220" height="140" viewBox="0 0 220 140" xmlns="http://www.w3.org/2000/svg" class="draw" style="opacity:.6" aria-hidden="true">
        <line x1="20" y1="130" x2="20" y2="10" class="ink"/>
        <line x1="100" y1="130" x2="100" y2="10" class="ink"/>
        <line x1="14" y1="130" x2="106" y2="130" class="ink"/>
        <line x1="16" y1="100" x2="104" y2="100" class="ink"/>
        <line x1="16" y1="70" x2="104" y2="70" class="ink"/>
        <line x1="16" y1="40" x2="104" y2="40" class="ink"/>
        <line x1="16" y1="12" x2="104" y2="12" class="ink"/>
        <rect x="24" y="101" width="30" height="22" class="ink-2"/>
        <rect x="59" y="101" width="30" height="22" class="ink-2"/>
        <rect x="24" y="71" width="30" height="22" class="ink-2"/>
        <rect x="59" y="71" width="30" height="22" class="ink-2"/>
        <rect x="24" y="41" width="30" height="22" class="ink-2"/>
        <rect x="130" y="80" width="60" height="34" rx="3" class="ink"/>
        <line x1="132" y1="80" x2="132" y2="30" class="ink"/>
        <line x1="146" y1="80" x2="146" y2="30" class="ink"/>
        <line x1="108" y1="104" x2="132" y2="104" class="ink"/>
        <line x1="108" y1="108" x2="132" y2="108" class="ink"/>
        <circle cx="148" cy="117" r="7" class="ink"/>
        <circle cx="178" cy="117" r="7" class="ink"/>
        <rect x="110" y="90" width="20" height="13" class="ink-2"/>
        <line x1="110" y1="96" x2="130" y2="96" class="ink-2"/>
        <line x1="10" y1="130" x2="210" y2="130" class="band-dock"/>
        <text x="60" y="140" class="dim" text-anchor="middle" style="font-size:8px;letter-spacing:.2em">ZONE DE STOCKAGE</text>
      </svg>
      <div class="sf-empty-label">Quai de chargement</div>
      <div class="sf-empty-sub">Sélectionner un fournisseur dans le registre pour ouvrir sa fiche de gouvernance.</div>
    </div>

    <!-- Fiche container -->
    <div class="sf-fiche" id="sf-fiche" role="region" aria-label="Fiche fournisseur"></div>

  </div>

</main>

<!-- ── Modals ── -->

<!-- Confirm field change -->
<div class="sf-modal-overlay" id="sf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sf-modal-title">
  <div class="sf-modal-box">
    <h4 id="sf-modal-title">Confirmer la modification</h4>
    <div class="sf-modal-diff" id="sf-modal-diff"></div>
    <div class="sf-modal-actions">
      <button class="sf-btn-cancel" id="sf-modal-cancel">Annuler</button>
      <button class="sf-btn-confirm" id="sf-modal-confirm">Appliquer</button>
    </div>
  </div>
</div>

<!-- Validate fiche (draft → active) -->
<div class="sf-modal-overlay" id="sf-validate-modal" role="dialog" aria-modal="true" aria-labelledby="sf-validate-title">
  <div class="sf-modal-box">
    <h4 id="sf-validate-title">Valider la fiche fournisseur</h4>
    <div class="sf-modal-diff" id="sf-validate-diff"></div>
    <div class="sf-modal-actions">
      <button class="sf-btn-cancel" id="sf-validate-cancel">Annuler</button>
      <button class="sf-btn-confirm" id="sf-validate-confirm">✓ Valider</button>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
/* ── Data payload ── */
window.SF_SUPPLIERS = <?= json_encode($suppliers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SF_ROLE      = <?= json_encode($bodyRole,  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SF_CSRF      = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/js/salle-fournisseurs.js?v=<?= @filemtime(__DIR__ . '/../../public/js/salle-fournisseurs.js') ?: time() ?>" defer></script>
</body>
</html>
