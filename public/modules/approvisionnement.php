<?php
declare(strict_types=1);
/**
 * /modules/approvisionnement.php
 * READ-ONLY Approvisionnement > Fournisseurs dashboard.
 * All write paths are absent — no POST handler, no mutation queries.
 *
 * Role mapping (body[data-role]):
 *   is_admin($me)   → "admin"
 *   is_manager($me) → "manager"
 *   else            → "operateur"
 *
 * The mockup CSS gates certain elements by [data-role] — operateur sees the
 * clean read-only view with no admin/manager control surfaces.
 */

require __DIR__ . "/../../app/auth.php";
require_page_access('approvisionnement');
$me = current_user();

$active_module = "approvisionnement";

/* ── Role resolution ──────────────────────────────────────────────── */
if (is_admin($me)) {
    $bodyRole = 'admin';
} elseif (is_manager($me)) {
    $bodyRole = 'manager';
} else {
    $bodyRole = 'operateur';
}

/* ── DB connection ────────────────────────────────────────────────── */
$pdo     = maltytask_pdo();
$dbError = null;
$suppliers = [];

try {

    /* ── Query 1: GL label map from ref_mi_categories ──────────────
     * Maps default_gl_account → category name for gl_label lookups.
     * Any category with a non-null default_gl_account contributes.
     * ONLY_FULL_GROUP_BY safe: GROUP BY default_gl_account; name is
     * functionally dependent but wrapped in ANY_VALUE for strict mode.
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

    /* ── Query 2: aliases grouped by supplier ───────────────────────
     * One aggregate row per supplier_id_fk → JSON array of aliases.
     * FK column name confirmed as supplier_id_fk (per task spec).
     */
    $aliasMap = [];
    $aliasStmt = $pdo->query(
        "SELECT supplier_id_fk, GROUP_CONCAT(alias ORDER BY alias SEPARATOR '|||') AS aliases_raw
           FROM ref_supplier_aliases
          GROUP BY supplier_id_fk"
    );
    foreach ($aliasStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk  = (int) $row['supplier_id_fk'];
        $raw = (string) $row['aliases_raw'];
        $aliasMap[$fk] = $raw !== '' ? explode('|||', $raw) : [];
    }

    /* ── Query 3: MI catalogue per supplier ─────────────────────────
     * Observed MIs from inv_deliveries for status Active/Consumed only.
     * ingredient_fk IS NOT NULL required (unresolved lines excluded —
     * documented in a code comment in the PHP and in the fiche callout).
     * ONLY_FULL_GROUP_BY safe: group by d.supplier_fk, m.id;
     * m.mi_id / m.name are functionally dependent on m.id → ANY_VALUE.
     * cat.name is from a LEFT JOIN on cat.id — also ANY_VALUE.
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

    /* ── Query 4: GL footprint per supplier ─────────────────────────
     * Observed GL distribution from inv_deliveries (Active/Consumed).
     * Excludes rows where exclusion_class IS NOT NULL (passthrough VAT
     * and non-transport freight) and unresolved (ingredient_fk NULL).
     * ONLY_FULL_GROUP_BY safe: group by d.supplier_fk, m.gl_account;
     * cat.name is from LEFT JOIN → ANY_VALUE.
     */
    $footprintMap = [];
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
        if (!isset($footprintMap[$fk])) $footprintMap[$fk] = [];
        $footprintMap[$fk][] = [
            'gl'       => (string) ($row['gl_account'] ?? ''),
            'gl_label' => (string) ($row['gl_label']   ?? ''),
            'chf'      => (float)  ($row['chf_total']  ?? 0),
            'lines'    => (int)    $row['line_count'],
        ];
    }

    /* ── Query 5b: invoice headers per supplier ─────────────────────────
     * Plain SELECT — no GROUP BY, no fan-out risk.
     * doc_invoices i LEFT JOIN doc_files f ON f.id = i.file_id
     * Note the dual-key: i.file_id is BIGINT FK to doc_files.id;
     * f.file_id is the UUID string used by the preview/document endpoints.
     * has_pdf: true when a doc_files row exists (meaning a PDF was ingested).
     */
    $invoicesMap = [];
    $invHdrStmt = $pdo->query(
        "SELECT i.id              AS invoice_id,
                i.supplier_fk,
                i.invoice_ref,
                i.invoice_date,
                i.total_ht,
                i.total_ttc,
                i.currency,
                i.parser_name,
                f.file_id         AS drive_file_id,
                (i.file_id IS NOT NULL AND f.id IS NOT NULL) AS has_pdf
           FROM doc_invoices i
           LEFT JOIN doc_files f ON f.id = i.file_id
          WHERE i.is_active = 1
            AND i.supplier_fk IS NOT NULL
          ORDER BY i.supplier_fk, i.invoice_date DESC"
    );
    foreach ($invHdrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (int) $row['supplier_fk'];
        if (!isset($invoicesMap[$fk])) $invoicesMap[$fk] = [];
        $invoicesMap[$fk][] = [
            'id'            => (int)    $row['invoice_id'],
            'ref'           => (string) ($row['invoice_ref']   ?? ''),
            'date'          => (string) ($row['invoice_date']  ?? ''),
            'total_ht'      => $row['total_ht']  !== null ? (float) $row['total_ht']  : null,
            'total_ttc'     => $row['total_ttc'] !== null ? (float) $row['total_ttc'] : null,
            'currency'      => (string) ($row['currency']      ?? 'CHF'),
            'parser'        => $row['parser_name'] !== null ? (string) $row['parser_name'] : null,
            'drive_file_id' => $row['drive_file_id'] !== null ? (string) $row['drive_file_id'] : null,
            'has_pdf'       => (bool) $row['has_pdf'],
        ];
    }

    /* ── Query 5: stats per supplier ────────────────────────────────
     * Invoice count (doc_invoices) and total CHF (inv_deliveries) are
     * computed in TWO SEPARATE grouped queries, then merged in PHP.
     * They MUST NOT be joined in one query: joining both doc_invoices and
     * inv_deliveries to ref_suppliers fans out to I×D rows, which multiplies
     * SUM(total_chf) by the invoice count (a supplier with 174 invoices would
     * report ~174× its real CHF total). Each query below is single-table and
     * ONLY_FULL_GROUP_BY-safe (aggregate + GROUP BY column only).
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

    /* ── Query 6: all suppliers ─────────────────────────────────────
     * Direct from ref_suppliers. No JOINs needed — all derived data
     * comes from maps built above.
     * Ordered alphabetically for consistent initial render.
     */
    $suppStmt = $pdo->query(
        "SELECT id, supplier_id, name, gl_account, currency, is_active, notes,
                parser_key, country, vat_number, vat_regime,
                hors_perimetre_cogs, sporadique, commissioning_state
           FROM ref_suppliers
          ORDER BY name"
    );

    foreach ($suppStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id    = (int) $row['id'];
        $gl    = (string) ($row['gl_account'] ?? '');
        $cur   = (string) ($row['currency']   ?? '');
        $ctry  = $row['country'] !== null ? (string) $row['country'] : null;

        /* country_display: use stored country if present, else a DISPLAY-ONLY
         * currency-based presumption. The "présumé" suffix signals gaps to the
         * operator; this value is never persisted.
         */
        if ($ctry !== null && $ctry !== '') {
            $countryDisplay = $ctry;
        } elseif ($cur === 'CHF') {
            $countryDisplay = 'Suisse (présumé)';
        } elseif ($cur === 'EUR') {
            $countryDisplay = 'UE / étranger (présumé)';
        } else {
            $countryDisplay = 'à renseigner';
        }

        $suppliers[] = [
            'id'                 => $id,
            'supplier_id'        => (string) $row['supplier_id'],
            'name'               => (string) $row['name'],
            'gl_account'         => $gl,
            'gl_label'           => $glLabelMap[$gl] ?? '',
            'currency'           => $cur,
            'is_active'          => (int) $row['is_active'] === 1,
            'parser_key'         => $row['parser_key'] !== null ? (string) $row['parser_key'] : null,
            'country'            => $ctry,
            'country_display'    => $countryDisplay,
            'vat_number'         => $row['vat_number'] !== null ? (string) $row['vat_number'] : null,
            'vat_regime'         => $row['vat_regime'] !== null ? (string) $row['vat_regime'] : null,
            'hors_perimetre'     => (int) ($row['hors_perimetre_cogs'] ?? 0) === 1,
            'sporadique'         => (int) ($row['sporadique'] ?? 0) === 1,
            'commissioning_state'=> $row['commissioning_state'] !== null
                                      ? (string) $row['commissioning_state']
                                      : null,
            'notes'              => $row['notes'] !== null ? (string) $row['notes'] : null,
            'aliases'            => $aliasMap[$id]     ?? [],
            'catalogue'          => $catalogueMap[$id] ?? [],
            'gl_footprint'       => $footprintMap[$id] ?? [],
            'stats'              => $statsMap[$id]     ?? ['invoices' => 0, 'total_chf' => null],
            'invoices'           => $invoicesMap[$id]  ?? [],
        ];
    }

} catch (Throwable $e) {
    $dbError = htmlspecialchars($e->getMessage());
}

/* ── DEDUP_PAIRS: emit [] — detection out of scope for read-only ──── */
$dedupPairs = [];

header("Content-Type: text/html; charset=utf-8");
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fournisseurs — Approvisionnement · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../public/css/app.css') ?: time() ?>">
</head>
<body class="home approv-fournisseurs" data-role="<?= htmlspecialchars($bodyRole) ?>">

<?php require __DIR__ . "/../../app/partials/topbar.php"; ?>

<div class="af-board" aria-hidden="true"></div>

<!-- Toast -->
<div class="af-toast" id="af-toast" role="status" aria-live="polite"></div>

<main id="main-content" class="af-workspace" role="main">

<?php if ($dbError !== null): ?>
  <div style="padding:40px;color:var(--ember);font-family:'JetBrains Mono',monospace;font-size:12px;">
    Erreur DB : <?= $dbError ?>
  </div>
<?php else: ?>

  <!-- ── LEFT: Manifest Panel ── -->
  <aside class="af-manifest" aria-label="Liste des fournisseurs">

    <!-- Header with warehouse sketch -->
    <div class="af-wh-header">
      <div class="af-wh-header-top">
        <div>
          <div class="af-eyebrow">01 · Approvisionnement · fournisseurs</div>
          <div class="af-module-title">Zone de <em>Réception</em></div>
          <div class="af-readonly-chip">🔒 lecture seule — phase 1</div>
        </div>
      </div>
      <!-- Warehouse sketch strip -->
      <div class="af-sketch-strip">
        <svg width="100%" height="56" viewBox="0 0 420 56" xmlns="http://www.w3.org/2000/svg" class="draw" aria-hidden="true">
          <!-- Pallet rack 1 -->
          <line x1="18" y1="52" x2="18" y2="8" class="ink"/>
          <line x1="78" y1="52" x2="78" y2="8" class="ink"/>
          <line x1="14" y1="52" x2="82" y2="52" class="ink"/>
          <line x1="16" y1="38" x2="80" y2="38" class="ink-2"/>
          <line x1="16" y1="24" x2="80" y2="24" class="ink-2"/>
          <line x1="16" y1="10" x2="80" y2="10" class="ink-2"/>
          <rect x="22" y="39" width="18" height="8" class="ink-2"/>
          <rect x="22" y="40" width="18" height="2" class="shade"/>
          <rect x="44" y="39" width="18" height="8" class="ink-2"/>
          <rect x="44" y="40" width="18" height="2" class="shade"/>
          <rect x="22" y="25" width="18" height="8" class="ink-2"/>
          <rect x="44" y="25" width="18" height="8" class="ink-2"/>
          <text x="30" y="56" class="dim" text-anchor="middle">RACKS</text>
          <!-- Pallet rack 2 -->
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
          <rect x="112" y="11" width="18" height="8" class="ink-2"/>
          <text x="138" y="56" class="dim" text-anchor="middle">STOCK</text>
          <!-- Forklift -->
          <rect x="250" y="28" width="46" height="22" rx="3" class="ink"/>
          <line x1="252" y1="28" x2="252" y2="6" class="ink"/>
          <line x1="262" y1="28" x2="262" y2="6" class="ink"/>
          <line x1="230" y1="44" x2="252" y2="44" class="ink"/>
          <line x1="230" y1="47" x2="252" y2="47" class="ink"/>
          <circle cx="262" cy="51" r="4" class="ink"/>
          <circle cx="288" cy="51" r="4" class="ink"/>
          <rect x="232" y="34" width="16" height="9" class="ink-2"/>
          <text x="270" y="56" class="dim" text-anchor="middle">CHARIOT</text>
          <!-- Dock door -->
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

    <!-- Filter bar — search -->
    <div class="af-filter-bar">
      <div class="af-search-wrap">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
          <line x1="10.5" y1="10.5" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <input type="search" id="af-search" placeholder="Rechercher un fournisseur…" autocomplete="off"
          aria-label="Rechercher un fournisseur">
      </div>
      <select id="af-sort-select" aria-label="Trier par"
        style="background:rgba(60,40,20,0.08);border:1px solid var(--hairline-2);border-radius:5px;color:var(--ink-mute);font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;padding:4px 8px;outline:none;cursor:pointer;">
        <option value="alpha">A–Z</option>
        <option value="volume">Volume CHF ↓</option>
      </select>
    </div>

    <!-- Filter chips -->
    <div class="af-chips-bar" role="group" aria-label="Filtres">
      <button class="af-chip on" data-filter="all">Tous</button>
      <button class="af-chip" data-filter="active">Actifs</button>
      <button class="af-chip" data-filter="catalogue">Catalogue MI</button>
      <button class="af-chip" data-filter="hors">Hors périmètre</button>
    </div>

    <!-- Supplier list (rendered by JS) -->
    <div class="af-sup-list" id="af-sup-list" role="list" aria-label="Fournisseurs"></div>
    <div class="af-list-count" id="af-list-count" aria-live="polite"></div>

  </aside>

  <!-- ── RIGHT: Fiche area ── -->
  <section class="af-fiche-area" aria-label="Fiche fournisseur">

    <!-- Empty state -->
    <div class="af-dock-empty" id="af-dock-empty">
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
        <line x1="10" y1="130" x2="210" y2="130" class="band-dock"/>
        <text x="60" y="140" class="dim" text-anchor="middle" style="font-size:8px;letter-spacing:.2em">ZONE DE STOCKAGE</text>
      </svg>
      <div class="de-label">Quai de chargement</div>
      <div class="de-sub">Sélectionner un fournisseur dans le manifeste pour ouvrir sa fiche.</div>
    </div>

    <!-- Fiche (populated by JS) -->
    <div class="af-fiche" id="af-fiche" role="region" aria-label="Détail fournisseur"></div>

  </section>

<?php endif; ?>

</main>

<!--
  Inline JSON injection — the ONLY inline script block.
  JSON_HEX_TAG prevents </script> injection via supplier names.
  JSON_UNESCAPED_UNICODE preserves French characters.
-->
<script>
window.SUPPLIERS  = <?= json_encode($suppliers,   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.DEDUP_PAIRS = <?= json_encode($dedupPairs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script defer src="/js/approvisionnement-fournisseurs.js?v=<?= @filemtime(__DIR__ . '/../../public/js/approvisionnement-fournisseurs.js') ?: time() ?>"></script>

</body>
</html>
