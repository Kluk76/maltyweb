<?php
declare(strict_types=1);
/**
 * modules/visite-guidee.php — Onboarding tour.
 *
 * Accessible to every authenticated user (require_login only — not in ref_pages nav).
 * Steps are generated server-side from ref_pages × user_can_access.
 * First-view: marks tour_seen_at on GET when still NULL (idempotent with AND tour_seen_at IS NULL).
 * Re-launchable at any time from the user menu.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';

require_login();
$me  = current_user();
$pdo = maltytask_pdo();

/* ── Brewery identity (never hardcode) ──────────────────────────────────────── */
$brewery = brewery_identity();

/* ── Fetch user's fresh tour_seen_at from DB (session may not carry it) ────── */
$tourStmt = $pdo->prepare('SELECT tour_seen_at FROM users WHERE id = ? LIMIT 1');
$tourStmt->execute([(int) $me['id']]);
$tourRow = $tourStmt->fetch();
$tourSeenAt = $tourRow ? $tourRow['tour_seen_at'] : null;

/* ── Mark first view (idempotent — AND tour_seen_at IS NULL guards re-runs) ── */
if ($tourSeenAt === null) {
    $markStmt = $pdo->prepare(
        'UPDATE users SET tour_seen_at = NOW() WHERE id = ? AND tour_seen_at IS NULL'
    );
    $markStmt->execute([(int) $me['id']]);

    /* Audit log — mirrors the update pattern used for user rows */
    log_revision(
        $pdo,
        $me,
        'users',
        (int) $me['id'],
        ['tour_seen_at' => null],
        ['tour_seen_at' => 'NOW()'],
        'normal',
        'Visite guidée : première ouverture'
    );
}

/* ── Build step list server-side ─────────────────────────────────────────────
   Query all non-admin, active ref_pages and filter to what this user can access.
   ─────────────────────────────────────────────────────────────────────────── */
$pagesStmt = $pdo->query(
    "SELECT page_key, label, icon, href
       FROM ref_pages
      WHERE is_active = 1
        AND (domain IS NULL OR domain != 'admin')
      ORDER BY sort"
);
$allPages = $pagesStmt->fetchAll();

/* French descriptions keyed by page_key — mockup texts verbatim where provided */
$PAGE_DESCRIPTIONS = [
    'mon-tableau'      => 'Votre tableau de bord personnel — et votre <strong>page d\'accueil</strong>. Choisissez vos indicateurs parmi le catalogue (production, stocks…) et suivez-les en un coup d\'œil. Vous pouvez aussi recevoir un récapitulatif par e-mail, à la cadence de votre choix.',
    'sb-board'         => 'La vue d\'ensemble de la production : chaque lot en cours apparaît sur le plateau, zone par zone — <strong>brassage, fermentation, garde, conditionnement</strong> — avec ses cuves. Cliquez une carte pour ouvrir le détail du lot.',
    'sb-guerre'        => 'La vue d\'alerte : les <strong>anomalies critiques</strong> qui demandent une action. Si la salle est vide, tout va bien.',
    'zeppelin'         => 'Le hub des données de référence : <strong>Salle des Machines</strong> (cuves et équipements), <strong>Salle de contrôle</strong> (QA/QC). C\'est ici que vivent les recettes et les capacités de la brasserie.',
    'wort'             => 'Les indicateurs de brassage : <strong>volumes, densités, rendements</strong> par brassin. Alimenté directement par vos saisies.',
    'fermentation'     => 'L\'état des cuves : quelle bière est dans quelle cuve, depuis combien de temps, et les <strong>pertes par étape</strong>.',
    'packaging'        => 'Le tableau de bord conditionnement : <strong>runs récents, volumes par format, pertes</strong>.',
    'triage'           => 'Le tri des documents : factures et bulletins de livraison arrivent ici pour rapprochement et validation.',
    'approvisionnement'=> 'Le suivi des fournisseurs et des réceptions de matières premières : fiches fournisseurs, historique des livraisons, documents associés.',
    'sku-costs'        => 'Le coût de revient par référence (SKU) : décomposition liquide + emballage.',
    'warehouse'        => 'Le stock de produits finis : quantités par SKU, par site.',
    'rm-comparison'    => 'Le bilan de clôture matières premières : comptage physique vs stock théorique, écarts par ingrédient.',
];

/* SVG icons per page_key — inline SVG matching mockup styles */
$PAGE_ICONS = [
    'mon-tableau'      => '<svg viewBox="0 0 24 24"><rect x="4" y="14" width="4" height="6" rx="1"/><rect x="10" y="10" width="4" height="10" rx="1"/><rect x="16" y="6" width="4" height="14" rx="1"/><path d="M4 21h16"/></svg>',
    'sb-board'         => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="5" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="3" y="10" width="8" height="11" rx="1"/><rect x="13" y="10" width="8" height="5" rx="1"/></svg>',
    'sb-guerre'        => '<svg viewBox="0 0 24 24"><path d="M5 3v18M5 3h12l-3 5 3 5H5"/></svg>',
    'zeppelin'         => '<svg viewBox="0 0 24 24"><path d="M12 2l2.5 7.5H22l-6 4.5 2.5 7.5L12 17l-6.5 4.5 2.5-7.5L2 9.5h7.5z"/></svg>',
    'wort'             => '<svg viewBox="0 0 24 24"><path d="M12 22V10"/><path d="M12 10C12 7 10 5 7 5c0 3 2 5 5 5z"/><path d="M12 10C12 7 14 5 17 5c0 3-2 5-5 5z"/><path d="M12 16c0-2-1.5-3.5-4-4 0 2 1.5 3.5 4 4z"/><path d="M12 16c0-2 1.5-3.5 4-4 0 2-1.5 3.5-4 4z"/></svg>',
    'fermentation'     => '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="14" rx="8" ry="6"/><path d="M4 14V8a8 2 0 0 1 16 0v6"/><path d="M8 12c0-1 1-2 2-2s2 1 3 1 2-1 2-1"/></svg>',
    'packaging'        => '<svg viewBox="0 0 24 24"><path d="M21 8l-9-5L3 8l9 5 9-5z"/><path d="M3 8v8l9 5V13"/><path d="M21 8v8l-9 5"/><path d="M12 3v5M7.5 5.5l4.5 2.5"/></svg>',
    'triage'           => '<svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>',
    'approvisionnement'=> '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'sku-costs'        => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v1m0 8v1M9.5 9.5C9.5 8.4 10.6 7.5 12 7.5s2.5.9 2.5 2-.9 2-2.5 2-2.5.9-2.5 2 1.1 2 2.5 2 2.5-.9 2.5-2"/></svg>',
    'warehouse'        => '<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
    'rm-comparison'    => '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'saisies'          => '<svg viewBox="0 0 24 24"><path d="M15 5l4 4L7 21H3v-4L15 5z"/><path d="M12 8l4 4"/></svg>',
    '_default'         => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>',
];

/* Vignettes per page_key — inline HTML snippets from the mockup */
function vg_vignette_for(string $key): string
{
    switch ($key) {
        case 'mon-tableau':
            return '<div class="vg-vignette vign-dashboard" aria-hidden="true">
              <div class="vign-kpi"><div class="vign-kpi__label">Brassin</div><div class="vign-kpi__val vign-kpi__val--hop">6</div><div class="vign-kpi__sub">en cours</div></div>
              <div class="vign-kpi"><div class="vign-kpi__label">Embuscade</div><div class="vign-kpi__val">85 HL</div><div class="vign-kpi__sub">en garde</div></div>
              <div class="vign-kpi"><div class="vign-kpi__label">Stock orge</div><div class="vign-kpi__val vign-kpi__val--oak">94 %</div><div class="vign-kpi__sub">vs cible</div></div>
              <div class="vign-sparkline"><div class="vign-spark-label">HL produits / semaine</div>
                <svg class="vign-spark-svg" viewBox="0 0 200 32" preserveAspectRatio="none" aria-hidden="true">
                  <polyline points="0,28 30,22 55,24 80,16 110,20 135,12 160,10 185,8 200,14" fill="none" stroke="#567020" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
                  <path d="M0,28 30,22 55,24 80,16 110,20 135,12 160,10 185,8 200,14 V32 H0 Z" fill="rgba(86,112,32,0.12)"/>
                </svg>
              </div>
            </div>';
        case 'sb-board':
            return '<div class="vg-vignette vign-board" aria-hidden="true">
              <div class="vign-zone"><div class="vign-zone-label">Brassage</div><div class="vign-lot-card"><div class="vign-lot-name">Zeppelin</div><div class="vign-lot-sub">Lot 48 · J+1</div></div></div>
              <div class="vign-zone"><div class="vign-zone-label">Fermentation</div><div class="vign-lot-card"><div class="vign-lot-name">Embuscade</div><div class="vign-lot-sub">Lot 45 · J+7</div></div><div class="vign-lot-card"><div class="vign-lot-name">Stirling</div><div class="vign-lot-sub">Lot 31 · J+5</div></div><div class="vign-tank-row"><div class="vign-tank"><div class="vign-tank-fill" style="height:70%"></div></div><div class="vign-tank"><div class="vign-tank-fill" style="height:85%"></div></div><div class="vign-tank"><div class="vign-tank-fill" style="height:45%"></div></div></div></div>
              <div class="vign-zone"><div class="vign-zone-label">Garde</div><div class="vign-lot-card"><div class="vign-lot-name">Moonshine</div><div class="vign-lot-sub">Lot 29 · J+22</div></div></div>
              <div class="vign-zone"><div class="vign-zone-label">Conditionnement</div><div class="vign-lot-card"><div class="vign-lot-name">Stirling</div><div class="vign-lot-sub">Lot 28 · Run</div></div></div>
            </div>';
        case 'sb-guerre':
            return '<div class="vg-vignette vign-guerre" aria-hidden="true">
              <div class="vign-alert-row" style="border-color:color-mix(in srgb,var(--ember) 40%,var(--hairline))"><div class="vign-alert-dot vign-alert-dot--red"></div><div class="vign-alert-text"><div class="vign-alert-line vign-alert-line--med"></div><div class="vign-alert-line vign-alert-line--short"></div></div></div>
              <div class="vign-alert-row"><div class="vign-alert-dot vign-alert-dot--warn"></div><div class="vign-alert-text"><div class="vign-alert-line vign-alert-line--med"></div><div class="vign-alert-line vign-alert-line--short"></div></div></div>
              <div style="padding:4px 8px;font-family:\'JetBrains Mono\',monospace;font-size:8px;color:var(--ink-mute);letter-spacing:0.06em;text-transform:uppercase;">2 alertes actives</div>
            </div>';
        case 'zeppelin':
            return '<div class="vg-vignette vign-zeppelin" aria-hidden="true">
              <div class="vign-ref-block"><div class="vign-ref-title">Salle des Machines</div><div class="vign-ref-items"><div class="vign-ref-item vign-ref-item--full"></div><div class="vign-ref-item vign-ref-item--med vign-ref-item--hop"></div><div class="vign-ref-item vign-ref-item--short"></div><div class="vign-ref-item vign-ref-item--med"></div></div></div>
              <div class="vign-ref-block"><div class="vign-ref-title">Salle de contrôle</div><div class="vign-ref-items"><div class="vign-ref-item vign-ref-item--med"></div><div class="vign-ref-item vign-ref-item--full"></div><div class="vign-ref-item vign-ref-item--short vign-ref-item--hop"></div><div class="vign-ref-item vign-ref-item--med"></div></div></div>
              <div class="vign-ref-block" style="grid-column:span 2"><div class="vign-ref-title">Recettes</div><div class="vign-ref-items" style="display:flex;flex-direction:row;gap:6px;flex-wrap:wrap"><div class="vign-ref-item" style="width:80px;height:7px;border-radius:4px;background:color-mix(in srgb,var(--cat-malt) 40%,var(--hairline))"></div><div class="vign-ref-item" style="width:64px;height:7px;border-radius:4px;background:color-mix(in srgb,var(--cold) 40%,var(--hairline))"></div><div class="vign-ref-item" style="width:72px;height:7px;border-radius:4px;background:color-mix(in srgb,var(--oak) 40%,var(--hairline))"></div><div class="vign-ref-item" style="width:56px;height:7px;border-radius:4px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div></div></div>
            </div>';
        case 'wort':
            return '<div class="vg-vignette vign-table-wrap" aria-hidden="true">
              <div class="vign-table-header"><div class="vign-th" style="width:80%"></div><div class="vign-th" style="width:90%"></div><div class="vign-th" style="width:70%"></div><div class="vign-th" style="width:60%"></div></div>
              <div class="vign-table-row"><div class="vign-td vign-td--bold"></div><div class="vign-td vign-td--full vign-td--hop"></div><div class="vign-td vign-td--75"></div><div class="vign-td vign-td--50"></div></div>
              <div class="vign-table-row"><div class="vign-td" style="width:85%"></div><div class="vign-td vign-td--full"></div><div class="vign-td vign-td--75 vign-td--hop"></div><div class="vign-td vign-td--50"></div></div>
              <div class="vign-table-row"><div class="vign-td" style="width:70%"></div><div class="vign-td vign-td--full"></div><div class="vign-td vign-td--75"></div><div class="vign-td vign-td--50 vign-td--ember"></div></div>
              <div class="vign-table-row"><div class="vign-td vign-td--bold"></div><div class="vign-td vign-td--full vign-td--hop"></div><div class="vign-td vign-td--75"></div><div class="vign-td vign-td--50"></div></div>
            </div>';
        case 'fermentation':
            return '<div class="vg-vignette vign-dashboard" aria-hidden="true">
              <div class="vign-kpi"><div class="vign-kpi__label">CCT actives</div><div class="vign-kpi__val">4 / 6</div><div class="vign-kpi__sub">cuves occupées</div></div>
              <div class="vign-kpi"><div class="vign-kpi__label">Perte moy.</div><div class="vign-kpi__val vign-kpi__val--oak">3,2 %</div><div class="vign-kpi__sub">par transfert</div></div>
              <div class="vign-kpi"><div class="vign-kpi__label">Garde</div><div class="vign-kpi__val vign-kpi__val--hop">18 j</div><div class="vign-kpi__sub">Embuscade -45</div></div>
              <div class="vign-sparkline"><div class="vign-spark-label">Densité fermentation · Embuscade -45</div>
                <svg class="vign-spark-svg" viewBox="0 0 200 32" preserveAspectRatio="none" aria-hidden="true">
                  <polyline points="0,4 25,5 50,8 75,14 100,20 125,25 150,28 175,29 200,30" fill="none" stroke="#8b5e2a" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>
                  <path d="M0,4 25,5 50,8 75,14 100,20 125,25 150,28 175,29 200,30 V32 H0 Z" fill="rgba(139,94,42,0.10)"/>
                </svg>
              </div>
            </div>';
        case 'packaging':
            return '<div class="vg-vignette vign-table-wrap" aria-hidden="true">
              <div class="vign-table-header"><div class="vign-th" style="width:80%"></div><div class="vign-th" style="width:70%"></div><div class="vign-th" style="width:90%"></div><div class="vign-th" style="width:60%"></div></div>
              <div class="vign-table-row"><div class="vign-td vign-td--bold"></div><div class="vign-td vign-td--75"></div><div class="vign-td vign-td--full vign-td--hop"></div><div class="vign-td vign-td--50"></div></div>
              <div class="vign-table-row"><div class="vign-td" style="width:90%;background:color-mix(in srgb,var(--cold) 40%,var(--hairline))"></div><div class="vign-td vign-td--full"></div><div class="vign-td vign-td--75 vign-td--hop"></div><div class="vign-td vign-td--50"></div></div>
              <div class="vign-table-row"><div class="vign-td vign-td--bold"></div><div class="vign-td vign-td--75"></div><div class="vign-td vign-td--full"></div><div class="vign-td vign-td--50 vign-td--ember"></div></div>
            </div>';
        default:
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);display:flex;align-items:center;justify-content:center;">
              <div style="width:60px;height:60px;border-radius:14px;background:var(--bg-elev);border:1.5px solid var(--hairline);display:flex;align-items:center;justify-content:center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--ink-mute)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
              </div>
            </div>';
    }
}

/* Filter pages to what this user can access */
$userPages = [];
foreach ($allPages as $p) {
    if ($p['page_key'] === 'saisies') continue; // handled specially below
    if (user_can_access($p['page_key'], $me)) {
        $userPages[] = $p;
    }
}
$hasSaisies = user_can_access('saisies', $me);

/* Check which production forms the user can access (for saisies sub-steps) */
$canWort        = user_can_access('wort', $me);
$canFermentation = user_can_access('fermentation', $me);
$canPackaging   = user_can_access('packaging', $me);
$hasAnyProdForm = ($canWort || $canFermentation || $canPackaging);

/* ── Assemble the full step list ─────────────────────────────────────────────
   We build an array of step descriptors; PHP renders them all hidden,
   JS activates them by index.
   ─────────────────────────────────────────────────────────────────────────── */
$steps = [];

/* Step 0 — Bienvenue */
$displayName = htmlspecialchars($me['display_name'] ?: ($me['username'] ?? ''), ENT_QUOTES, 'UTF-8');
$steps[] = ['type' => 'bienvenue', 'display_name' => $displayName];

/* Step 1 — Navigation */
$steps[] = ['type' => 'navigation'];

/* Steps for accessible pages (excluding saisies hub — it gets special treatment) */
foreach ($userPages as $p) {
    $steps[] = [
        'type'        => 'page',
        'page_key'    => $p['page_key'],
        'label'       => $p['label'],
        'href'        => $p['href'],
        'description' => $PAGE_DESCRIPTIONS[$p['page_key']]
                         ?? (htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8')
                             . ' — consultez cette page pour explorer les données disponibles.'),
        'icon_svg'    => $PAGE_ICONS[$p['page_key']] ?? $PAGE_ICONS['_default'],
        'vignette'    => vg_vignette_for($p['page_key']),
    ];
}

/* Saisies section-opener + sub-steps */
if ($hasSaisies) {
    // Find the saisies href from ref_pages
    $saisiesRow = null;
    foreach ($allPages as $p) {
        if ($p['page_key'] === 'saisies') { $saisiesRow = $p; break; }
    }
    $saisiesHref = $saisiesRow ? $saisiesRow['href'] : '/modules/saisies.php';

    $steps[] = ['type' => 'saisies_opener', 'href' => $saisiesHref];

    /* Production form sub-steps (only if user has at least one prod-form page) */
    if ($hasAnyProdForm) {
        $steps[] = [
            'type'  => 'form',
            'key'   => 'brassage',
            'title' => 'Saisie · Brassage',
            'href'  => '/modules/form-brewing.php',
            'body'  => 'À remplir <strong>le jour du brassin</strong> : bière, numéro de lot, ingrédients (malts, houblons, avec leurs lots), volumes et densités. Chaque ligne d\'ingrédient compte pour la traçabilité et les coûts.',
        ];
        $steps[] = [
            'type'  => 'form',
            'key'   => 'fermentation',
            'title' => 'Saisie · Fermentation',
            'href'  => '/modules/form-fermenting.php',
            'body'  => 'Pour suivre une bière en cuve : <strong>relevés</strong> (densité, pH, température), ajouts de dry-hop, cold crash. Le formulaire ne propose que les lots réellement en cuve — si votre lot n\'apparaît pas, vérifiez l\'étape précédente.',
        ];
        $steps[] = [
            'type'  => 'form',
            'key'   => 'transferts',
            'title' => 'Saisie · Transferts',
            'href'  => '/modules/form-racking.php',
            'body'  => 'Pour enregistrer un soutirage : d\'une CCT vers un BBT ou une autre CCT. Seuls les <strong>lots éligibles</strong> (temps de garde atteint) sont proposés sous forme de cartes.',
        ];
        $steps[] = [
            'type'  => 'form',
            'key'   => 'conditionnement',
            'title' => 'Saisie · Conditionnement',
            'href'  => '/modules/form-packaging.php',
            'body'  => 'À remplir après chaque run : lot source, <strong>formats produits</strong> (fûts, bouteilles, canettes), quantités, pertes et mesures QA (CO₂&nbsp;/&nbsp;O₂). Gère aussi les runs parallèles — 4-packs et cartons d\'un même lot.',
        ];
    }

    /* Inventaire RM — for everyone with saisies access */
    $steps[] = [
        'type'  => 'form',
        'key'   => 'inventaire',
        'title' => 'Saisie · Inventaire RM',
        'href'  => '/modules/form-rm-stocktake.php',
        'body'  => 'Le comptage mensuel des matières premières, <strong>palette par palette</strong> : cherchez l\'ingrédient, saisissez la quantité, « Ajouter » enregistre immédiatement. Rien à soumettre à la fin — chaque ligne est déjà sauvegardée.',
    ];
}

/* Bon à savoir */
$steps[] = ['type' => 'bon_a_savoir'];

/* Final */
$steps[] = ['type' => 'final'];

$totalSteps = count($steps);

/* ── Render ──────────────────────────────────────────────────────────────── */
$active_module = '';
$cssCacheBust = @filemtime(__DIR__ . '/../css/visite-guidee.css') ?: time();
$jsCacheBust  = @filemtime(__DIR__ . '/../js/visite-guidee.js') ?: time();
$appCssCacheBust = @filemtime(__DIR__ . '/../css/app.css') ?: time();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Visite guidée — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,200;0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $appCssCacheBust ?>">
  <link rel="stylesheet" href="/css/visite-guidee.css?v=<?= $cssCacheBust ?>">
</head>
<body class="home visite-guidee">

<?php require __DIR__ . '/../../app/partials/sidebar.php'; ?>
<?php require __DIR__ . '/../../app/partials/topbar.php'; ?>

<main id="main-content" class="main">

<div class="vg-wrap">

  <a href="#main-content" class="skip-link">Aller au contenu</a>

  <!-- HEADER -->
  <header class="vg-header" role="banner">
    <div class="vg-mark" aria-hidden="true">M<span>T</span></div>
    <div class="vg-header-meta">
      <div class="vg-eyebrow" aria-hidden="true">VISITE GUIDÉE</div>
      <div class="vg-step-counter" id="step-counter" aria-live="polite">étape 1 / <?= $totalSteps ?></div>
    </div>
    <a href="/modules/mon-tableau.php" class="vg-skip-tour" aria-label="Passer la visite guidée">Passer la visite</a>
  </header>

  <!-- PROGRESS DOTS -->
  <nav class="vg-dots-wrap" aria-label="Navigation par étapes">
    <div class="vg-dots" id="dots-container" role="tablist"
         aria-label="Étapes de la visite"
         data-total="<?= $totalSteps ?>">
      <!-- dots injected by JS -->
    </div>
  </nav>

  <!-- STEP STAGE — all steps rendered server-side; JS shows/hides -->
  <section class="vg-stage" aria-live="polite" aria-atomic="true">

<?php foreach ($steps as $stepIdx => $step):
    $isSection = ($step['type'] === 'saisies_opener');
    $sectionAttr = $isSection ? ' data-section="1"' : '';
    $ariaLabel = match($step['type']) {
        'bienvenue'      => 'Bienvenue',
        'navigation'     => 'La navigation',
        'page'           => htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'),
        'saisies_opener' => 'Saisies — le cœur de votre travail',
        'form'           => htmlspecialchars($step['title'] ?? '', ENT_QUOTES, 'UTF-8'),
        'bon_a_savoir'   => 'Bon à savoir',
        'final'          => "C'est parti !",
        default          => 'Étape',
    };
?>
  <article class="vg-step" data-step="<?= $stepIdx ?>"<?= $sectionAttr ?> aria-label="<?= $ariaLabel ?>">
<?php if ($step['type'] === 'bienvenue'): ?>
    <div class="vg-card">
      <div class="vg-vignette vign-welcome" aria-hidden="true">
        <div class="vign-app-sketch">
          <div class="vign-app-bar">
            <div class="vign-app-bar-logo">M<span>T</span></div>
            <div class="vign-app-bar-sep"></div>
            <div class="vign-app-bar-nav">
              <div class="vign-app-bar-item vign-app-bar-item--active">Mon tableau</div>
              <div class="vign-app-bar-item">Lots en cours</div>
              <div class="vign-app-bar-item">Saisies</div>
              <div class="vign-app-bar-item">Le Zeppelin</div>
            </div>
            <div class="vign-app-bar-user"></div>
          </div>
          <div class="vign-app-body">
            <div class="vign-app-tile"><div class="vign-app-tile__label"></div><div class="vign-app-tile__val vign-app-tile__val--hop"></div></div>
            <div class="vign-app-tile"><div class="vign-app-tile__label"></div><div class="vign-app-tile__val"></div></div>
            <div class="vign-app-tile"><div class="vign-app-tile__label"></div><div class="vign-app-tile__val vign-app-tile__val--oak"></div></div>
            <div class="vign-app-tile vign-app-tile--wide"><div class="vign-app-tile__label"></div><div class="vign-app-tile__spark"></div></div>
            <div class="vign-app-tile"><div class="vign-app-tile__label"></div><div class="vign-app-tile__val"></div></div>
          </div>
        </div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h12l-1 14H6L5 4z"/><path d="M8 4V2.5M12 4V2.5M16 4V2.5"/><path d="M17 9h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3"/></svg></div>
        <div><h1 class="vg-card-title">Bienvenue <?= $step['display_name'] ?>&nbsp;!</h1></div>
      </div>
      <p class="vg-card-body">
        MaltyTask est l'outil de suivi de production de <strong><?= htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8') ?></strong>.
        Cette visite vous montre les pages auxquelles vous avez accès —
        comptez <strong>3 minutes</strong>. Vous pourrez la relancer à tout moment
        depuis le menu utilisateur (en haut à droite).
      </p>
    </div>

<?php elseif ($step['type'] === 'navigation'): ?>
    <div class="vg-card">
      <div class="vg-vignette vign-nav" aria-hidden="true">
        <div class="vign-topbar">
          <div class="vign-topbar-mark">MT</div>
          <div class="vign-topbar-separator"></div>
          <div class="vign-nav-pill vign-nav-pill--active">Mon tableau</div>
          <div class="vign-nav-pill">Lots en cours</div>
          <div class="vign-nav-pill">Saisies</div>
          <div class="vign-nav-pill">Le Zeppelin</div>
          <div class="vign-topbar-space"></div>
          <div class="vign-user-chip"><?= mb_strtoupper(mb_substr($displayName, 0, 1)) ?></div>
        </div>
        <div class="vign-content-placeholder">
          <div class="vign-placeholder-col">
            <div class="vign-placeholder-line vign-placeholder-line--accent vign-placeholder-line--med"></div>
            <div class="vign-placeholder-line vign-placeholder-line--full"></div>
            <div class="vign-placeholder-line vign-placeholder-line--short"></div>
          </div>
          <div class="vign-placeholder-col">
            <div class="vign-placeholder-line vign-placeholder-line--full"></div>
            <div class="vign-placeholder-line vign-placeholder-line--med"></div>
          </div>
        </div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></div>
        <div><h2 class="vg-card-title">La navigation</h2></div>
      </div>
      <p class="vg-card-body">
        La barre du haut vous emmène partout. Sur tablette ou téléphone, utilisez
        le menu <strong>☰</strong>. Sous votre nom, en haut à droite :
        <strong>Mes appareils</strong> (déclarez un ordinateur partagé),
        <strong>Changer d'utilisateur</strong> et <strong>Déconnexion</strong>.
      </p>
    </div>

<?php elseif ($step['type'] === 'page'): ?>
    <div class="vg-card">
      <?= $step['vignette'] ?>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><?= $step['icon_svg'] ?></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>
      <p class="vg-card-body"><?= $step['description'] ?></p>
      <a href="<?= htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8') ?>"
         class="vg-card-link"
         aria-label="Ouvrir <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?>">Ouvrir cette page ↗</a>
    </div>

<?php elseif ($step['type'] === 'saisies_opener'): ?>
    <div class="vg-card vg-card--section">
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:color-mix(in srgb,var(--ember) 4%,var(--bg))">
        <div class="vign-field">
          <div class="vign-field-label" style="background:color-mix(in srgb,var(--ember) 30%,var(--hairline))"></div>
          <div class="vign-field-input vign-field-input--accent" style="border-color:color-mix(in srgb,var(--ember) 50%,var(--hairline))">
            <div class="vign-field-input-val vign-field-input-val--short" style="background:color-mix(in srgb,var(--ember) 25%,var(--hairline))"></div>
          </div>
        </div>
        <div class="vign-field">
          <div class="vign-field-label"></div>
          <div class="vign-field-input"><div class="vign-field-input-val vign-field-input-val--short"></div></div>
        </div>
        <div class="vign-field">
          <div class="vign-field-label"></div>
          <div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div>
        </div>
        <div class="vign-submit-row">
          <div class="vign-btn vign-btn--primary" style="background:var(--ember)">Enregistrer la saisie</div>
          <div class="vign-btn vign-btn--ghost">Annuler</div>
        </div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><?= $PAGE_ICONS['saisies'] ?></div>
        <div><h2 class="vg-card-title">Saisies — <em>le cœur de votre travail</em></h2></div>
      </div>
      <p class="vg-card-body">
        C'est ici que vous enregistrez la production : <strong>formulaires de saisie</strong>,
        expliqués dans les étapes suivantes. Chaque saisie alimente directement
        la base de production.
      </p>
      <a href="<?= htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8') ?>" class="vg-card-link" aria-label="Ouvrir la page Saisies">Ouvrir cette page ↗</a>
    </div>

<?php elseif ($step['type'] === 'form'): ?>
    <div class="vg-card">
<?php
    /* Vignette per form key */
    if ($step['key'] === 'brassage'): ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val vign-field-input-val--short" style="background:color-mix(in srgb,var(--hop) 30%,var(--hairline))"></div><div style="margin-left:auto;height:8px;width:8px;border-radius:50%;background:var(--hop);opacity:0.5"></div></div></div>
        <div class="vign-field"><div class="vign-field-label" style="width:35%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:30%"></div></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--cat-malt);flex-shrink:0"></div><div style="height:5px;width:55%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--cat-malt) 40%,var(--hairline));margin-left:auto"></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--hop);flex-shrink:0"></div><div style="height:5px;width:48%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline));margin-left:auto"></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--cat-malt);flex-shrink:0"></div><div style="height:5px;width:60%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--cat-malt) 40%,var(--hairline));margin-left:auto"></div></div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h12v2a6 6 0 0 1-6 6 6 6 0 0 1-6-6V3z"/><path d="M6 5h12"/><path d="M12 11v10"/><path d="M8 21h8"/><path d="M19 8h2v5h-2"/></svg></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>

<?php elseif ($step['key'] === 'fermentation'): ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label" style="width:40%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:65%;background:color-mix(in srgb,var(--bbt) 30%,var(--hairline))"></div></div></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px"><div class="vign-field"><div class="vign-field-label" style="width:90%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:50%"></div></div></div><div class="vign-field"><div class="vign-field-label" style="width:80%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:60%"></div></div></div><div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div></div>
        <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:35%;background:color-mix(in srgb,var(--hop) 30%,var(--hairline))"></div></div></div>
        <div class="vign-submit-row"><div class="vign-btn vign-btn--primary">Enregistrer</div></div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="14" rx="8" ry="6"/><path d="M4 14V8a8 2 0 0 1 16 0v6"/><path d="M8 12c0-1 1-2 2-2s2 1 3 1 2-1 2-1"/></svg></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>

<?php elseif ($step['key'] === 'transferts'): ?>
      <div class="vg-vignette vign-board" aria-hidden="true" style="grid-template-columns:repeat(3,1fr);gap:6px;padding:10px;background:var(--bg);align-items:start;">
        <div class="vign-zone" style="grid-column:span 3"><div class="vign-zone-label">Lots éligibles au transfert</div></div>
        <div class="vign-lot-card" style="border-color:color-mix(in srgb,var(--hop) 50%,var(--hairline));background:color-mix(in srgb,var(--hop) 6%,var(--bg-elev))"><div class="vign-lot-name" style="color:var(--hop)">Embuscade</div><div class="vign-lot-sub">Lot 45 · CCT-3</div><div style="height:4px;border-radius:2px;background:var(--hop);margin-top:3px;opacity:0.5"></div></div>
        <div class="vign-lot-card"><div class="vign-lot-name">Moonshine</div><div class="vign-lot-sub">Lot 29 · CCT-1</div></div>
        <div class="vign-lot-card" style="opacity:0.4;border-style:dashed"><div class="vign-lot-name" style="color:var(--ink-mute)">Stirling</div><div class="vign-lot-sub" style="color:color-mix(in srgb,var(--ember) 70%,var(--ink-mute))">Garde insuffisante</div></div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 3l4 4-4 4"/><path d="M20 7H4"/><path d="M8 21l-4-4 4-4"/><path d="M4 17h16"/></svg></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>

<?php elseif ($step['key'] === 'conditionnement'): ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label" style="width:40%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:60%;background:color-mix(in srgb,var(--bbt) 30%,var(--hairline))"></div></div></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <div style="padding:3px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ink-mute);">ZEPF · 20L</div>
          <div style="padding:3px 8px;background:color-mix(in srgb,var(--hop) 12%,var(--bg-elev));border:1.5px solid color-mix(in srgb,var(--hop) 40%,var(--hairline));border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--hop-deep);">EMB4 · 4×33cl ✓</div>
          <div style="padding:3px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ink-mute);">EMBB · 24×33cl</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:50%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:55%;background:color-mix(in srgb,var(--ember) 25%,var(--hairline))"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
        </div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M12 12v4M10 14h4"/></svg></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>

<?php else: /* inventaire */ ?>
      <div class="vg-vignette vign-stocktake" aria-hidden="true">
        <div class="vign-search-bar"><div class="vign-search-icon"></div><div class="vign-search-val"></div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:50%"></div><div class="vign-ledger-qty">24,5 kg</div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:45%"></div><div class="vign-ledger-qty">6 sacs</div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:55%"></div><div class="vign-ledger-qty">3 cartons</div></div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg></div>
        <div><h2 class="vg-card-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></h2></div>
      </div>

<?php endif; /* form key switch */ ?>
      <p class="vg-card-body"><?= $step['body'] ?></p>
      <a href="<?= htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8') ?>"
         class="vg-card-link"
         aria-label="Ouvrir <?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?>">Ouvrir cette page ↗</a>
    </div>

<?php elseif ($step['type'] === 'bon_a_savoir'): ?>
    <div class="vg-card">
      <div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:14px;display:flex;flex-direction:column;gap:10px;justify-content:center;">
        <div style="display:flex;align-items:flex-start;gap:10px;">
          <div style="width:28px;height:28px;border-radius:8px;background:color-mix(in srgb,var(--ok) 15%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 30%,var(--hairline));display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg></div>
          <div style="display:flex;flex-direction:column;gap:4px;padding-top:4px;"><div style="height:6px;width:75%;border-radius:3px;background:var(--ink-mute)"></div><div style="height:5px;width:90%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:65%;border-radius:3px;background:var(--hairline)"></div></div>
        </div>
        <div style="display:flex;align-items:flex-start;gap:10px;">
          <div style="width:28px;height:28px;border-radius:8px;background:color-mix(in srgb,var(--bbt) 15%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--bbt) 30%,var(--hairline));display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--bbt)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
          <div style="display:flex;flex-direction:column;gap:4px;padding-top:4px;"><div style="height:6px;width:60%;border-radius:3px;background:var(--ink-mute)"></div><div style="height:5px;width:80%;border-radius:3px;background:var(--hairline)"></div></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:color-mix(in srgb,var(--ok) 10%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 30%,var(--hairline));border-radius:7px"><div style="width:8px;height:8px;border-radius:50%;background:var(--ok);flex-shrink:0"></div><div style="height:6px;width:60%;border-radius:3px;background:color-mix(in srgb,var(--ok) 40%,var(--hairline))"></div></div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2l8 3v7c0 4-3.5 7.5-8 9-4.5-1.5-8-5-8-9V5l8-3z"/><path d="M9 12l2 2 4-4"/></svg></div>
        <div><h2 class="vg-card-title">Bon à savoir</h2></div>
      </div>
      <p class="vg-card-body">
        Vos saisies sont protégées : <strong>brouillon auto-sauvegardé</strong>
        (si l'onglet se ferme, vos valeurs reviennent), session maintenue active
        pendant que vous tapez, et chaque envoi se termine par une
        <strong>confirmation verte</strong>. En cas de doute : saisissez,
        puis signalez.
      </p>
    </div>

<?php elseif ($step['type'] === 'final'): ?>
    <div class="vg-card">
      <div class="vg-vignette vign-welcome" aria-hidden="true" style="background:linear-gradient(135deg,color-mix(in srgb,var(--hop) 8%,var(--bg)) 0%,var(--bg) 100%)">
        <div style="display:flex;flex-direction:column;align-items:center;gap:12px">
          <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="var(--hop)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h12l-1 14H6L5 4z"/><path d="M8 4V2.5M12 4V2.5M16 4V2.5"/><path d="M17 9h3a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-3"/><path d="M8 10 c0 0, 1 2, 2 2 s2-2 3-2 s1 2 2 2" opacity="0.5"/></svg>
          <div style="font-family:'Fraunces',serif;font-variation-settings:'opsz' 96;font-size:1.2rem;font-weight:300;color:var(--hop-deep);letter-spacing:-0.01em;text-align:center"><em>Bonne brasse&nbsp;!</em></div>
        </div>
      </div>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-5"/></svg></div>
        <div><h2 class="vg-card-title">C'est parti&nbsp;!</h2></div>
      </div>
      <p class="vg-card-body">
        Votre page d'accueil est <strong>Mon tableau</strong>. Vous pouvez relancer
        cette visite à tout moment depuis le menu utilisateur. Bonne brasse&nbsp;!
      </p>
    </div>

<?php endif; /* step type switch */ ?>
  </article>
<?php endforeach; ?>

  </section><!-- /.vg-stage -->

  <!-- Footer navigation -->
  <nav class="vg-footer" aria-label="Navigation entre étapes">
    <button class="vg-btn vg-btn--prev" id="btn-prev" aria-label="Étape précédente">← Précédent</button>
    <button class="vg-btn vg-btn--next" id="btn-next" aria-label="Étape suivante">Suivant →</button>
  </nav>

  <!-- DONE URL for JS (plain anchor so no inline script) -->
  <span id="vg-done-url" data-url="/modules/mon-tableau.php" aria-hidden="true" style="display:none"></span>

</div><!-- /.vg-wrap -->

</main>

<script src="/js/visite-guidee.js?v=<?= $jsCacheBust ?>"></script>
</body>
</html>
