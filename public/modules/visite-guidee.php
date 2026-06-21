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
    'planning'         => 'Le <strong>calendrier de production hebdomadaire</strong> : les managers de production et de logistique y planifient leurs intentions pour la semaine — brassage, fermentation, transferts, conditionnement et livraisons. Le planning est <strong>dynamique</strong> : une opération saisie en début de semaine déverrouille automatiquement les étapes suivantes (par exemple, un transfert planifié rend la bière éligible au conditionnement le lendemain). Un bouton « Suggérer un plan » propose une répartition à partir des données de couverture de stock. Les opérateurs voient le planning en lecture seule — rien à saisir ici.',
    'zeppelin'         => 'Le hub des données de référence : <strong>Salle des Machines</strong> (cuves et équipements), <strong>Salle de contrôle</strong> (QA/QC). C\'est ici que vivent les recettes et les capacités de la brasserie.',
    'wort'             => 'Les indicateurs de brassage : <strong>volumes, densités, rendements</strong> par brassin. Alimenté directement par vos saisies.',
    'fermentation'     => 'L\'état des cuves : quelle bière est dans quelle cuve, depuis combien de temps, et les <strong>pertes par étape</strong>.',
    'packaging'        => 'Le tableau de bord conditionnement : <strong>runs récents, volumes par format, pertes</strong>.',
    'triage'           => 'Le tri des documents : factures et bulletins de livraison arrivent ici pour rapprochement et validation.',
    'invoice-validate' => 'La <strong>porte de validation des factures</strong> : chaque facture parsée apparaît ici avec le détail ligne par ligne (MI résolu, quantités, prix, flags de confiance). Un clic pour <em>Valider</em> — les livraisons s\'écrivent en base — ou <em>Refuser</em> pour signaler un problème de parser.',
    'approvisionnement'=> 'Le suivi des fournisseurs et des réceptions de matières premières : fiches fournisseurs, historique des livraisons, documents associés.',
    'sku-costs'        => 'Le coût de revient par référence (SKU) : décomposition liquide + emballage.',
    'warehouse'        => 'Le stock de produits finis : quantités par SKU, par site.',
    'rm-comparison'    => 'Le bilan de clôture matières premières : comptage physique vs stock théorique, écarts par ingrédient.',
    'expeditions'      => 'Le centre logistique : <strong>saisie des commandes clients</strong> (autocomplétion clients et SKU), suivi des statuts d\'un clic — confirmée, préparée, BL imprimé, livrée — et <strong>Stock PF en direct</strong> : stock physique, disponible cette semaine, disponible après commandes futures, semaines de couverture.',
    'tap-shop'         => 'Tap&amp;Shop réunit en une seule vue les ventes directes de la brasserie : les commandes de la boutique en ligne et les ventes du taproom, mises en regard du stock de produits finis réellement disponible. C\'est une page de consultation : rien ne s\'y saisit. Elle vous sert à voir d\'un coup d\'œil ce qui s\'est vendu en direct et ce qu\'il reste, sans avoir à croiser plusieurs écrans.',
    'journal-saisies'  => 'Le fil en direct de toutes les saisies de production : brassage, fermentation, transferts, conditionnement. Chaque événement apparaît avec l\'opérateur, l\'heure et le lot concerné — la page se rafraîchit automatiquement. Utile pour vérifier qu\'une saisie est bien enregistrée, ou pour suivre l\'activité de l\'équipe en temps réel.',
    'qa'               => 'Le registre des <strong>autocontrôles qualité</strong> de la brasserie, dans le cadre du programme HACCP. Trois types de contrôles sont enregistrés ici : le <strong>contrôle poids / volume au conditionnement</strong> (unités prélevées en cours de run, comparées à la cible — conformité signalée automatiquement), le <strong>contrôle nettoyage et désinfection PRP-04</strong> (tests ATP, visuel ou eau de rinçage sur les surfaces nettoyées, avec résultat et action corrective si nécessaire) et le <strong>contrôle réception verre</strong> (vérification poids et volume à l\'arrivée des bouteilles, passe / recalage). Aucune de ces saisies n\'affecte le stock ni les coûts — c\'est un registre de traçabilité qualité.',
    'email-orders'     => 'La validation des <strong>commandes reçues par e-mail</strong>. Chaque message entrant est analysé et déposé dans l\'un des trois volets : <strong>À valider</strong> (commandes lues et interprétées, affichées côte à côte avec l\'e-mail d\'origine), <strong>Non parsé</strong> (messages que le système n\'a pas pu lire — à traiter manuellement) et <strong>Créées</strong> (commandes déjà converties). Pour chaque ligne à valider, l\'opérateur confirme le vrai client, le bon SKU et la date de livraison — rien n\'est supposé automatiquement — puis clique <em>Valider</em> pour créer la commande. La page signale aussi si une commande déjà saisie en boutique en ligne semble être un doublon.',
    'financier'        => 'La <strong>fiche de calcul COGS mensuelle</strong> à destination du fiduciaire : stock d\'ouverture (= clôture du mois précédent), mouvement de la période, ajustement de base et stock de clôture. Deux exports CSV sont disponibles — la fiche seule et un export complet en quatre sections (fiche, détail par référence, coût de production, taxe bière) — pour le classeur comptable. La page affiche le dernier mois clôturé par défaut ; la sélection du mois reste possible pour consulter l\'historique. Aucune donnée ne s\'y saisit : tout est calculé en amont.',
    'saisie-energie'      => 'La <strong>saisie mensuelle des index compteurs</strong> : eau (m³), gaz (m³) et électricité (jour &amp; nuit). L\'opérateur note ce que les compteurs physiques affichent en fin de mois. L\'écart avec le relevé précédent est calculé en direct pendant la saisie, et une <strong>estimation prévisionnelle du coût énergétique</strong> est mise à jour sur le Financier en temps réel — jusqu\'à ce que la facture réelle arrive et prenne le relais automatiquement. Un historique par période, avec les consommations mois par mois, est affiché en bas de page. Si le mois a déjà été chargé depuis une facture SIE ou SIL, les champs sont en lecture seule : la facture fait foi.',
    'salle-fournisseurs'  => 'Le référentiel fournisseurs, à l\'usage des managers. Chaque <strong>fiche fournisseur</strong> expose l\'état de confiance de ses champs (automatique, vérifié ou verrouillé), le <strong>statut de mise en service</strong> (brouillon → actif), l\'empreinte comptable par compte GL, et les éventuels ancrages de champs décidés par l\'équipe. C\'est ici qu\'un manager valide un nouveau fournisseur avant qu\'il puisse recevoir des livraisons, ou qu\'il corrige une information erronée détectée lors d\'un triage.<br><br>Chaque fiche inclut un <strong>onglet Discussion</strong> : le fil chronologique des échanges e-mails et documents avec le fournisseur, avec possibilité de répondre directement depuis votre adresse &commat;lanebuleuse.ch et de joindre des fichiers.<br><br>Accès <strong>manager et administrateur uniquement</strong>. Pour y accéder : ouvrez <strong>Le Zeppelin</strong>, basculez sur la famille <strong>Salle des Machines</strong>, puis cliquez la carte <em>Fournisseurs</em>.',
];

/* SVG icons per page_key — inline SVG matching mockup styles */
$PAGE_ICONS = [
    'mon-tableau'      => '<svg viewBox="0 0 24 24"><rect x="4" y="14" width="4" height="6" rx="1"/><rect x="10" y="10" width="4" height="10" rx="1"/><rect x="16" y="6" width="4" height="14" rx="1"/><path d="M4 21h16"/></svg>',
    'sb-board'         => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="5" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="3" y="10" width="8" height="11" rx="1"/><rect x="13" y="10" width="8" height="5" rx="1"/></svg>',
    'sb-guerre'        => '<svg viewBox="0 0 24 24"><path d="M5 3v18M5 3h12l-3 5 3 5H5"/></svg>',
    'planning'         => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18"/><path d="M8 2v4M16 2v4"/><path d="M7 13h2M11 13h2M15 13h2"/><path d="M7 17h2M11 17h2"/><circle cx="16" cy="17" r="1.5"/></svg>',
    'zeppelin'         => '<svg viewBox="0 0 24 24"><path d="M12 2l2.5 7.5H22l-6 4.5 2.5 7.5L12 17l-6.5 4.5 2.5-7.5L2 9.5h7.5z"/></svg>',
    'wort'             => '<svg viewBox="0 0 24 24"><path d="M12 22V10"/><path d="M12 10C12 7 10 5 7 5c0 3 2 5 5 5z"/><path d="M12 10C12 7 14 5 17 5c0 3-2 5-5 5z"/><path d="M12 16c0-2-1.5-3.5-4-4 0 2 1.5 3.5 4 4z"/><path d="M12 16c0-2 1.5-3.5 4-4 0 2-1.5 3.5-4 4z"/></svg>',
    'fermentation'     => '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="14" rx="8" ry="6"/><path d="M4 14V8a8 2 0 0 1 16 0v6"/><path d="M8 12c0-1 1-2 2-2s2 1 3 1 2-1 2-1"/></svg>',
    'packaging'        => '<svg viewBox="0 0 24 24"><path d="M21 8l-9-5L3 8l9 5 9-5z"/><path d="M3 8v8l9 5V13"/><path d="M21 8v8l-9 5"/><path d="M12 3v5M7.5 5.5l4.5 2.5"/></svg>',
    'triage'           => '<svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>',
    'approvisionnement'=> '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'sku-costs'        => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v1m0 8v1M9.5 9.5C9.5 8.4 10.6 7.5 12 7.5s2.5.9 2.5 2-.9 2-2.5 2-2.5.9-2.5 2 1.1 2 2.5 2 2.5-.9 2.5-2"/></svg>',
    'warehouse'        => '<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
    'rm-comparison'    => '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'expeditions'      => '<svg viewBox="0 0 24 24"><rect x="1" y="6" width="13" height="11" rx="1"/><path d="M14 9h4l3 4v4h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17" cy="19" r="2"/></svg>',
    'saisies'          => '<svg viewBox="0 0 24 24"><path d="M15 5l4 4L7 21H3v-4L15 5z"/><path d="M12 8l4 4"/></svg>',
    'invoice-validate' => '<svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>',
    'tap-shop'         => '<svg viewBox="0 0 24 24"><path d="M3 9l1.5-5h15L21 9M3 9h18M3 9v11h18V9M9 20v-6h6v6"/></svg>',
    'email-orders'     => '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 8l10 7 10-7"/><path d="M14 14l2 2 4-4"/></svg>',
    'financier'        => '<svg viewBox="0 0 24 24"><rect x="3" y="2" width="14" height="18" rx="2"/><path d="M7 7h6M7 11h4"/><path d="M15 16h6M15 19h6"/><path d="M18 14l-3 2 2 3 3-2"/></svg>',
    'saisie-energie'      => '<svg viewBox="0 0 24 24"><path d="M13 2L5 14h7l-1 8 8-12h-7l1-8z"/><circle cx="19" cy="5" r="2"/><path d="M19 7v3M17 9h4"/></svg>',
    'salle-fournisseurs'  => '<svg viewBox="0 0 24 24"><path d="M2 20h20"/><rect x="4" y="10" width="12" height="10"/><path d="M4 10V6l6-3 6 3v4"/><path d="M8 20v-5h4v5"/><path d="M17 6l4 2v12"/><path d="M15 13l2 2 3-3"/></svg>',
    'journal-saisies'  => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="12" height="16" rx="2"/><path d="M7 8h6M7 12h6M7 16h3"/><circle cx="18" cy="16" r="4"/><path d="M18 14v2l1 1"/></svg>',
    'qa'               => '<svg viewBox="0 0 24 24"><path d="M9 3h6M10 3v6l-5 9a1 1 0 0 0 1 1.5h12a1 1 0 0 0 1-1.5l-5-9V3"/><path d="M7.5 14h9"/></svg>',
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
        case 'planning':
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:10px;display:flex;flex-direction:column;gap:5px;justify-content:center;">
              <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:3px;margin-bottom:2px;">
                <div style="text-align:center;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Lu</div>
                <div style="text-align:center;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Ma</div>
                <div style="text-align:center;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Me</div>
                <div style="text-align:center;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Je</div>
                <div style="text-align:center;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Ve</div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:3px;">
                <div style="background:color-mix(in srgb,var(--hop) 35%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--hop) 25%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:var(--hairline);border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--bbt) 30%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--hop) 25%,var(--hairline));border-radius:3px;height:18px;"></div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:3px;">
                <div style="background:var(--hairline);border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--oak) 30%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--bbt) 30%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:color-mix(in srgb,var(--oak) 25%,var(--hairline));border-radius:3px;height:18px;"></div>
                <div style="background:var(--hairline);border-radius:3px;height:18px;"></div>
              </div>
              <div style="margin-top:3px;display:flex;gap:4px;align-items:center;">
                <div style="width:8px;height:8px;border-radius:2px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline));flex-shrink:0;"></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.03em;">Production</div>
                <div style="width:8px;height:8px;border-radius:2px;background:color-mix(in srgb,var(--bbt) 35%,var(--hairline));flex-shrink:0;margin-left:4px;"></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.03em;">Logistique</div>
              </div>
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
        case 'journal-saisies':
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:12px;display:flex;flex-direction:column;gap:6px;justify-content:center;">
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;">
                <div style="width:6px;height:6px;border-radius:50%;background:var(--hop);flex-shrink:0;"></div>
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:55%;border-radius:3px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div><div style="height:4px;width:35%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.04em;white-space:nowrap;">il y a 2 min</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;">
                <div style="width:6px;height:6px;border-radius:50%;background:var(--bbt);flex-shrink:0;"></div>
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:62%;border-radius:3px;background:color-mix(in srgb,var(--bbt) 40%,var(--hairline))"></div><div style="height:4px;width:40%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.04em;white-space:nowrap;">il y a 18 min</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;opacity:0.7;">
                <div style="width:6px;height:6px;border-radius:50%;background:var(--oak);flex-shrink:0;"></div>
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:48%;border-radius:3px;background:color-mix(in srgb,var(--oak) 40%,var(--hairline))"></div><div style="height:4px;width:30%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.04em;white-space:nowrap;">il y a 1 h</div>
              </div>
            </div>';
        case 'qa':
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:12px;display:flex;flex-direction:column;gap:6px;justify-content:center;">
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:color-mix(in srgb,var(--ok) 6%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 30%,var(--hairline));border-radius:6px;">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--ok);flex-shrink:0;"></div>
                <div style="height:5px;flex:1;border-radius:3px;background:color-mix(in srgb,var(--ok) 30%,var(--hairline))"></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ok);letter-spacing:0.04em;white-space:nowrap;">OK</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:color-mix(in srgb,var(--ok) 6%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 30%,var(--hairline));border-radius:6px;">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--ok);flex-shrink:0;"></div>
                <div style="height:5px;flex:1;border-radius:3px;background:color-mix(in srgb,var(--ok) 30%,var(--hairline))"></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ok);letter-spacing:0.04em;white-space:nowrap;">OK</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:color-mix(in srgb,var(--ember) 6%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ember) 30%,var(--hairline));border-radius:6px;">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--ember);flex-shrink:0;"></div>
                <div style="height:5px;flex:1;border-radius:3px;background:color-mix(in srgb,var(--ember) 30%,var(--hairline))"></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ember);letter-spacing:0.04em;white-space:nowrap;">Signalé</div>
              </div>
            </div>';
        case 'saisie-energie':
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:10px 12px;display:flex;flex-direction:column;gap:5px;justify-content:center;">
              <div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:6px;padding:4px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;">
                <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Eau</div><div style="height:5px;width:70%;border-radius:3px;background:color-mix(in srgb,var(--cold) 50%,var(--hairline));"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ink-soft);letter-spacing:0.04em;white-space:nowrap;">+38 m³</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:6px;padding:4px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;">
                <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Gaz</div><div style="height:5px;width:55%;border-radius:3px;background:color-mix(in srgb,var(--ember) 50%,var(--hairline));"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ink-soft);letter-spacing:0.04em;white-space:nowrap;">+124 m³</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:6px;padding:4px 8px;background:color-mix(in srgb,var(--hop) 8%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--hop) 25%,var(--hairline));border-radius:5px;">
                <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Élec. jour</div><div style="height:5px;width:80%;border-radius:3px;background:color-mix(in srgb,var(--hop) 55%,var(--hairline));"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--hop);letter-spacing:0.04em;white-space:nowrap;">+612 kWh</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:6px;padding:4px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;">
                <div style="display:flex;flex-direction:column;gap:2px;"><div style="font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.05em;text-transform:uppercase;">Élec. nuit</div><div style="height:5px;width:42%;border-radius:3px;background:color-mix(in srgb,var(--bbt) 45%,var(--hairline));"></div></div>
                <div style="font-family:\'JetBrains Mono\',monospace;font-size:7px;color:var(--ink-soft);letter-spacing:0.04em;white-space:nowrap;">+294 kWh</div>
              </div>
            </div>';
        case 'salle-fournisseurs':
            return '<div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:10px 12px;display:flex;flex-direction:column;gap:5px;justify-content:center;">
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;">
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:55%;border-radius:3px;background:var(--ink-mute);"></div><div style="height:4px;width:38%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="padding:2px 6px;background:color-mix(in srgb,var(--ok) 14%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 35%,var(--hairline));border-radius:4px;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ok);letter-spacing:0.04em;white-space:nowrap;">V&#233;rifi&#233;</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;">
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:62%;border-radius:3px;background:var(--ink-mute);"></div><div style="height:4px;width:30%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="padding:2px 6px;background:color-mix(in srgb,var(--hop) 14%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--hop) 35%,var(--hairline));border-radius:4px;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--hop);letter-spacing:0.04em;white-space:nowrap;">Actif</div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;opacity:0.7;">
                <div style="display:flex;flex-direction:column;gap:3px;flex:1;"><div style="height:5px;width:48%;border-radius:3px;background:var(--ink-mute);"></div><div style="height:4px;width:35%;border-radius:2px;background:var(--hairline)"></div></div>
                <div style="padding:2px 6px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:\'JetBrains Mono\',monospace;font-size:6px;color:var(--ink-label);letter-spacing:0.04em;white-space:nowrap;">Brouillon</div>
              </div>
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

    /* Production form sub-steps — multi-step chapters (only if user has at least one prod-form page) */
    if ($hasAnyProdForm) {
        /* ── CHAPITRE BRASSAGE (3 steps) ── */
        $steps[] = [
            'type'    => 'form',
            'key'     => 'brassage',
            'chapter' => 'brassage',
            'chap_n'  => 1,
            'chap_total' => 3,
            'title'   => 'Brassage — identité &amp; ingrédients',
            'href'    => '/modules/form-brewing.php',
            'body'    => 'Recette + N° de brassin = l\'identité du lot. Toute la traçabilité s\'y accroche — vérifiez-les avant d\'envoyer. Re-soumettre le même couple recette + brassin met à jour la fiche existante, sans créer de doublon.'
                       . '<br><br>Ingrédients : une ligne par matière réellement utilisée — malts, houblons, minéraux, auxiliaires — avec quantité, unité et N° de lot. Le N° de lot alimente la traçabilité et le suivi de stock. Lot illisible ou inconnu ? Laissez vide et signalez-le — n\'inventez jamais.'
                       . '<br><br>Levure : souche, génération, provenance.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'brassage',
            'chapter' => 'brassage',
            'chap_n'  => 2,
            'chap_total' => 3,
            'title'   => 'Brassage — brassins &amp; densités',
            'href'    => '/modules/form-brewing.php',
            'body'    => 'Un batch peut compter plusieurs brassins, le même jour ou sur deux jours : une ligne par brassin, avec ses dates et heures de début et de fin.'
                       . '<br><br>Les densités se saisissent en <strong>°Plato</strong>, à chaque étape : FirstWort, Pfannevoll, Kochwürze, puis la densité initiale (OG) au refroidissement. Des garde-fous signalent les valeurs improbables : ils avertissent, ne bloquent pas.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'brassage',
            'chapter' => 'brassage',
            'chap_n'  => 3,
            'chap_total' => 3,
            'title'   => 'Brassage — le volume à froid',
            'href'    => '/modules/form-brewing.php',
            'body'    => 'Le <strong>volume à froid</strong> (cast-out, en HL, par brassin, plus dilution éventuelle) est le chiffre le plus important de votre saisie : c\'est le volume de référence de tout le calcul de pertes du lot, jusqu\'au conditionnement.'
                       . '<br><br>Vous ne saisissez jamais de perte au brassage — elle est calculée automatiquement par rapport au volume nominal de la salle de brassage. Une perte de brassage négative peut être réelle : un rendement au-dessus du nominal, pas une erreur.'
                       . '<br><br>La section CIP enregistre les nettoyages effectués — cochez et datez.',
        ];

        /* ── CHAPITRE FERMENTATION (2 steps) ── */
        $steps[] = [
            'type'    => 'form',
            'key'     => 'fermentation',
            'chapter' => 'fermentation',
            'chap_n'  => 1,
            'chap_total' => 2,
            'title'   => 'Fermentation — un événement à la fois',
            'href'    => '/modules/form-fermenting.php',
            'body'    => 'Chaque saisie est un événement sur un lot : <strong>Mesures densité / pH / temp</strong> (densité °P, pH, température), Houblonnage à froid (une ligne par ajout : houblon, quantité, N° de lot), Purge, ou Cold Crash.'
                       . '<br><br>Le formulaire ne propose que les lots réellement en cuve. Si votre lot n\'apparaît pas, c\'est presque toujours qu\'une étape précédente n\'a pas été saisie.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'fermentation',
            'chapter' => 'fermentation',
            'chap_n'  => 2,
            'chap_total' => 2,
            'title'   => 'Fermentation — mesures &amp; cold crash',
            'href'    => '/modules/form-fermenting.php',
            'body'    => 'Saisissez chaque relevé, même rapproché dans le temps : le système agrège lui-même. Des garde-fous signalent les valeurs improbables (densité, pH, température) : ils avertissent, ne bloquent pas — corrigez ou confirmez.'
                       . '<br><br>Le cold crash compte double : il clôt la fermentation ET déclenche le compteur de garde qui rendra le lot éligible au transfert. C\'est une <strong>case à cocher</strong> dans la section mesures — un cold crash non coché = un lot invisible dans le formulaire Transferts.',
        ];

        /* ── CHAPITRE TRANSFERTS (3 steps) ── */
        $steps[] = [
            'type'    => 'form',
            'key'     => 'transferts',
            'chapter' => 'transferts',
            'chap_n'  => 1,
            'chap_total' => 3,
            'title'   => 'Transferts — lot &amp; équipement',
            'href'    => '/modules/form-racking.php',
            'body'    => 'Les cartes ne montrent que les lots éligibles : garde atteinte (selon les seuils configurés pour la levure ou la recette) et réellement en cuve. Le mode hors-process (managers) déverrouille le choix, avec raison obligatoire.'
                       . '<br><br>Machines : centrifugeuse, KZE, pompe — cochez au moins une. Avec la KZE, saisissez les PU cible et moyenne.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'transferts',
            'chapter' => 'transferts',
            'chap_n'  => 2,
            'chap_total' => 3,
            'title'   => 'Transferts — volumes',
            'href'    => '/modules/form-racking.php',
            'body'    => 'Destination : BBT, CCT ou YT. Avec le débitmètre, saisissez début et fin : le volume transféré se calcule tout seul. Sinon, saisissez-le manuellement.'
                       . '<br><br>Volume résiduel : ce qui se trouvait déjà dans la cuve d\'arrivée — le volume résultant s\'affiche automatiquement.'
                       . '<br><br>Complétez selon votre pratique : CO₂ et O₂ à destination, turbidité, pression.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'transferts',
            'chapter' => 'transferts',
            'chap_n'  => 3,
            'chap_total' => 3,
            'title'   => 'Transferts — pertes exceptionnelles',
            'href'    => '/modules/form-racking.php',
            'body'    => 'La perte standard de process est déjà comptée automatiquement à chaque transfert — ne la saisissez pas une deuxième fois. La section Pertes sert aux pertes exceptionnelles uniquement : <strong>Perte cuve départ</strong> (HL), <strong>Perte cuve arrivée</strong> (HL), cause (Produit / Machine / Humain) et explication.'
                       . '<br><br>Au-delà des seuils configurés, une explication est demandée dans le champ « Détails / explication » — quelques mots suffisent.'
                       . '<br><br>Transfert interrompu ? Cochez-le et expliquez : le système saura quoi faire du lot.',
        ];

        /* ── CHAPITRE CONDITIONNEMENT (3 steps) ── */
        $steps[] = [
            'type'    => 'form',
            'key'     => 'conditionnement',
            'chapter' => 'conditionnement',
            'chap_n'  => 1,
            'chap_total' => 3,
            'title'   => 'Conditionnement — lot source &amp; formats',
            'href'    => '/modules/form-packaging.php',
            'body'    => 'Les cartes montrent les lots prêts à conditionner. La mosaïque des formats propose les références activées de la recette : un clic pré-remplit le format principal.'
                       . '<br><br>Avant remplissage : mesures CO₂ et O₂ en cuve — une fois par lot et par jour ; elles sont reprises automatiquement si déjà saisies.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'conditionnement',
            'chapter' => 'conditionnement',
            'chap_n'  => 2,
            'chap_total' => 3,
            'title'   => 'Conditionnement — runs parallèles',
            'href'    => '/modules/form-packaging.php',
            'body'    => '« + Ajouter un format parallèle » : même bière, même brassin, conditionnée en plusieurs formats dans la même session — par exemple 4-packs et cartons de 24, ou un run Nébuleuse et un run white-label.'
                       . '<br><br>Chaque ligne porte sa propre quantité, et les quantités s\'additionnent : ne saisissez jamais le total de la session sur la ligne principale, et ne soustrayez jamais.'
                       . '<br><br>Une autre bière = une autre session, jamais une ligne parallèle.'
                       . '<br><br>White label : activez-le sur la ligne concernée et choisissez le client — le liquide est le vôtre, la marque est celle du client.',
        ];
        $steps[] = [
            'type'    => 'form',
            'key'     => 'conditionnement',
            'chapter' => 'conditionnement',
            'chap_n'  => 3,
            'chap_total' => 3,
            'title'   => 'Conditionnement — dispositions &amp; pertes',
            'href'    => '/modules/form-packaging.php',
            'body'    => 'Bouteilles et canettes se comptent en unités, fûts en litres. Chaque case a un sens précis :'
                       . '<br>· <strong>Invendable</strong> — bière perdue mais consommable : reste taxée.'
                       . '<br>· <strong>Unité perdue (pleine)</strong> — unité pleine perdue, BOM complet, non taxée.'
                       . '<br>· <strong>Perte liquide sans capsule</strong> — remplie mais jamais capsulée : non taxée.'
                       . '<br>· <strong>Perte liquide à moitié remplie</strong> — compte pour une demi-unité.'
                       . '<br>· <strong>Bibliothèque QA</strong> et <strong>Mesures QA</strong> — pas des pertes : neutres pour le stock et la taxe.'
                       . '<br>· <strong>Fût taproom</strong> — taxé, mais hors stock vendable.'
                       . '<br>· <strong>Perte capuchon fût</strong>, étiquettes, 4-packs… — pertes matériel : jamais déduites du volume.'
                       . '<br><br>Le volume vendable est calculé automatiquement à partir de vos dispositions — vous ne le saisissez jamais. C\'est lui qui alimente le stock, les coûts et la taxe bière : d\'où l\'importance de choisir la bonne case. Le compteur Mesures QA se remplit tout seul à partir des paires CO₂/O₂ saisies.',
        ];

        /* ── CAPSTONE — La chaîne des pertes ── */
        $steps[] = [
            'type'  => 'form',
            'key'   => 'chaine_pertes',
            'chapter' => 'chaine_pertes',
            'chap_n'  => 1,
            'chap_total' => 1,
            'title' => 'La chaîne des pertes',
            'href'  => '/modules/saisies.php',
            'body'  => 'Du brassage au conditionnement, les pertes se calculent automatiquement à chaque étape, à partir de vos volumes : volume à froid (brassage) → volumes et pertes de transfert → dispositions de conditionnement → volume vendable. Personne ne saisit jamais un pourcentage : tout découle de vos chiffres.'
                     . '<br><br>Bon à savoir : une perte totale négative signale presque toujours un assemblage de lots, pas un gain.',
        ];
    }

    /* Inventaire RM — for everyone with saisies access */
    $steps[] = [
        'type'  => 'form',
        'key'   => 'inventaire',
        'chapter' => 'inventaire',
        'chap_n'  => 1,
        'chap_total' => 1,
        'title' => 'Saisie · Inventaire RM',
        'href'  => '/modules/form-rm-stocktake.php',
        'body'  => 'Le comptage mensuel des matières premières, <strong>palette par palette</strong> : cherchez l\'ingrédient, saisissez la quantité, « Ajouter » enregistre immédiatement. Rien à soumettre à la fin — chaque ligne est déjà sauvegardée.'
                 . '<br><br>Vide ≠ zéro : un vrai zéro se saisit (0) ; une absence de comptage se laisse vide.',
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

<?php
/* Track chapter boundaries for dot gap markers */
$prevChapKey = null;
foreach ($steps as $stepIdx => $step):
    $isSection = ($step['type'] === 'saisies_opener');
    $sectionAttr = $isSection ? ' data-section="1"' : '';
    /* Chapter-start detection: first step of a new chapter key gets data-chap-start */
    $thisChapKey = ($step['type'] === 'form') ? ($step['chapter'] ?? $step['key']) : null;
    $isChapStart = ($thisChapKey !== null && $thisChapKey !== $prevChapKey);
    if ($thisChapKey !== null) $prevChapKey = $thisChapKey;
    $chapStartAttr = ($isChapStart && $step['type'] === 'form') ? ' data-chap-start="1"' : '';

    $ariaLabel = match($step['type']) {
        'bienvenue'      => 'Bienvenue',
        'navigation'     => 'La navigation',
        'page'           => htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'),
        'saisies_opener' => 'Saisies — le cœur de votre travail',
        'form'           => htmlspecialchars(strip_tags($step['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'bon_a_savoir'   => 'Bon à savoir',
        'final'          => "C'est parti !",
        default          => 'Étape',
    };
?>
  <article class="vg-step" data-step="<?= $stepIdx ?>"<?= $sectionAttr ?><?= $chapStartAttr ?> aria-label="<?= $ariaLabel ?>">
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
        comptez <strong>5 minutes</strong>. Vous pourrez la relancer à tout moment
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
    /* Chapter eyebrow label — shown for multi-step chapters */
    $chapKey   = $step['chapter'] ?? $step['key'];
    $chapN     = $step['chap_n'] ?? 1;
    $chapTotal = $step['chap_total'] ?? 1;
    $chapLabels = [
        'brassage'       => 'SAISIE · BRASSAGE',
        'fermentation'   => 'SAISIE · FERMENTATION',
        'transferts'     => 'SAISIE · TRANSFERTS',
        'conditionnement'=> 'SAISIE · CONDITIONNEMENT',
        'chaine_pertes'  => 'SAISIE · ENCHAÎNEMENT',
        'inventaire'     => 'SAISIE · INVENTAIRE RM',
    ];
    $chapLabel = $chapLabels[$chapKey] ?? '';
    $showChapEyebrow = ($chapTotal > 1 || $chapKey === 'chaine_pertes');
?>
<?php if ($showChapEyebrow): ?>
      <div class="vg-chapter-eyebrow" aria-hidden="true">
        <?= htmlspecialchars($chapLabel, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($chapTotal > 1): ?><span class="vg-chapter-progress"><?= $chapN ?>/<?= $chapTotal ?></span><?php endif ?>
      </div>
<?php endif ?>

<?php
    /* Vignette per form chapter key + sub-step number */
    if ($chapKey === 'brassage'): ?>
<?php   if ($chapN === 1): /* B1 — identity & ingredients sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val vign-field-input-val--short" style="background:color-mix(in srgb,var(--hop) 30%,var(--hairline))"></div><div style="margin-left:auto;height:8px;width:8px;border-radius:50%;background:var(--hop);opacity:0.5"></div></div></div>
        <div class="vign-field"><div class="vign-field-label" style="width:35%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:30%"></div></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--cat-malt);flex-shrink:0"></div><div style="height:5px;width:55%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--cat-malt) 40%,var(--hairline));margin-left:auto"></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--hop);flex-shrink:0"></div><div style="height:5px;width:48%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline));margin-left:auto"></div></div>
        <div style="display:flex;gap:6px;align-items:center;padding:4px 0;"><div style="height:6px;width:6px;border-radius:50%;background:var(--cat-malt);flex-shrink:0"></div><div style="height:5px;width:60%;border-radius:3px;background:var(--hairline)"></div><div style="height:5px;width:20%;border-radius:3px;background:color-mix(in srgb,var(--cat-malt) 40%,var(--hairline));margin-left:auto"></div></div>
      </div>
<?php   elseif ($chapN === 2): /* B2 — densities / brewday timeline sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;width:100%;">
          <div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:55%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:60%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%;background:color-mix(in srgb,var(--oak) 40%,var(--hairline))"></div></div></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;width:100%;margin-top:2px;">
          <div style="display:flex;flex-direction:column;gap:2px;align-items:center;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;padding:4px;"><div style="height:4px;width:80%;border-radius:2px;background:var(--hairline)"></div><div style="height:7px;width:60%;border-radius:2px;background:color-mix(in srgb,var(--hop) 50%,var(--hairline))"></div><div style="height:3px;width:70%;border-radius:2px;background:var(--hairline);margin-top:1px"></div></div>
          <div style="display:flex;flex-direction:column;gap:2px;align-items:center;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;padding:4px;"><div style="height:4px;width:80%;border-radius:2px;background:var(--hairline)"></div><div style="height:7px;width:60%;border-radius:2px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div><div style="height:3px;width:70%;border-radius:2px;background:var(--hairline);margin-top:1px"></div></div>
          <div style="display:flex;flex-direction:column;gap:2px;align-items:center;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:5px;padding:4px;"><div style="height:4px;width:80%;border-radius:2px;background:var(--hairline)"></div><div style="height:7px;width:60%;border-radius:2px;background:color-mix(in srgb,var(--oak) 40%,var(--hairline))"></div><div style="height:3px;width:70%;border-radius:2px;background:var(--hairline);margin-top:1px"></div></div>
          <div style="display:flex;flex-direction:column;gap:2px;align-items:center;background:color-mix(in srgb,var(--hop) 6%,var(--bg-elev));border:1.5px solid color-mix(in srgb,var(--hop) 40%,var(--hairline));border-radius:5px;padding:4px;"><div style="height:4px;width:80%;border-radius:2px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div><div style="height:7px;width:60%;border-radius:2px;background:var(--hop);opacity:0.7"></div><div style="height:3px;width:70%;border-radius:2px;background:var(--hairline);margin-top:1px"></div></div>
        </div>
      </div>
<?php   else: /* B3 — cast-out volume + CIP sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:6px;width:100%;">
          <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:45%;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div><div style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.04em;">HL</div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:60%"></div></div></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:4px 0;margin-top:2px;border-top:1px solid var(--hairline);padding-top:6px;">
          <div style="width:10px;height:10px;border-radius:2px;border:1.5px solid color-mix(in srgb,var(--ok) 60%,var(--hairline));background:color-mix(in srgb,var(--ok) 20%,var(--bg-elev));flex-shrink:0;"></div>
          <div style="height:5px;width:45%;border-radius:3px;background:var(--hairline)"></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:4px 0;">
          <div style="width:10px;height:10px;border-radius:2px;border:1.5px solid var(--hairline);background:var(--bg-elev);flex-shrink:0;"></div>
          <div style="height:5px;width:38%;border-radius:3px;background:var(--hairline)"></div>
        </div>
      </div>
<?php   endif ?>

<?php elseif ($chapKey === 'fermentation'): ?>
<?php   if ($chapN === 1): /* F1 — event-type selector sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label" style="width:40%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:65%;background:color-mix(in srgb,var(--bbt) 30%,var(--hairline))"></div></div></div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;padding:2px 0;">
          <div style="padding:3px 8px;background:color-mix(in srgb,var(--ink) 8%,var(--bg-elev));border:1px solid var(--hairline);border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--ink-soft);">Mesures</div>
          <div style="padding:3px 8px;background:color-mix(in srgb,var(--hop) 12%,var(--bg-elev));border:1.5px solid color-mix(in srgb,var(--hop) 40%,var(--hairline));border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--hop-deep);">Dry-hop ✓</div>
          <div style="padding:3px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--ink-mute);">Purge</div>
        </div>
        <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:35%;background:color-mix(in srgb,var(--hop) 30%,var(--hairline))"></div></div></div>
        <div class="vign-submit-row"><div class="vign-btn vign-btn--primary">Enregistrer</div></div>
      </div>
<?php   else: /* F2 — readings + cold crash checkbox sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px"><div class="vign-field"><div class="vign-field-label" style="width:90%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:50%"></div></div></div><div class="vign-field"><div class="vign-field-label" style="width:80%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:60%"></div></div></div><div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div></div>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;margin-top:4px;background:color-mix(in srgb,var(--cold) 8%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--cold) 40%,var(--hairline));border-radius:6px;">
          <div style="width:10px;height:10px;border-radius:2px;border:1.5px solid color-mix(in srgb,var(--cold) 60%,var(--hairline));background:color-mix(in srgb,var(--cold) 25%,var(--bg-elev));flex-shrink:0;"></div>
          <div style="height:5px;width:58%;border-radius:3px;background:color-mix(in srgb,var(--cold) 40%,var(--hairline))"></div>
        </div>
        <div class="vign-submit-row"><div class="vign-btn vign-btn--primary" style="background:color-mix(in srgb,var(--cold) 70%,var(--ink))">Enregistrer le cold crash →</div></div>
      </div>
<?php   endif ?>

<?php elseif ($chapKey === 'transferts'): ?>
<?php   if ($chapN === 1): /* T1 — lot selector cards sketch */ ?>
      <div class="vg-vignette vign-board" aria-hidden="true" style="grid-template-columns:repeat(3,1fr);gap:6px;padding:10px;background:var(--bg);align-items:start;">
        <div class="vign-zone" style="grid-column:span 3"><div class="vign-zone-label">Lots éligibles au transfert</div></div>
        <div class="vign-lot-card" style="border-color:color-mix(in srgb,var(--hop) 50%,var(--hairline));background:color-mix(in srgb,var(--hop) 6%,var(--bg-elev))"><div class="vign-lot-name" style="color:var(--hop)">Embuscade</div><div class="vign-lot-sub">Lot 45 · CCT-3</div><div style="height:4px;border-radius:2px;background:var(--hop);margin-top:3px;opacity:0.5"></div></div>
        <div class="vign-lot-card"><div class="vign-lot-name">Moonshine</div><div class="vign-lot-sub">Lot 29 · CCT-1</div></div>
        <div class="vign-lot-card" style="opacity:0.4;border-style:dashed"><div class="vign-lot-name" style="color:var(--ink-mute)">Stirling</div><div class="vign-lot-sub" style="color:color-mix(in srgb,var(--ember) 70%,var(--ink-mute))">Garde insuffisante</div></div>
      </div>
<?php   elseif ($chapN === 2): /* T2 — volume fields sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;width:100%;">
          <div class="vign-field"><div class="vign-field-label" style="width:65%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:45%;background:color-mix(in srgb,var(--bbt) 40%,var(--hairline))"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:70%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:55%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:60%"></div><div class="vign-field-input"><div style="height:6px;width:70%;border-radius:3px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline));font-family:'JetBrains Mono',monospace;font-size:6px"></div></div></div>
        </div>
      </div>
<?php   else: /* T3 — loss fields sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);">
        <div style="display:flex;align-items:center;gap:8px;padding:4px 0 6px;">
          <div style="width:10px;height:10px;border-radius:2px;border:1.5px solid color-mix(in srgb,var(--ember) 60%,var(--hairline));background:color-mix(in srgb,var(--ember) 20%,var(--bg-elev));flex-shrink:0;"></div>
          <div style="height:5px;width:50%;border-radius:3px;background:var(--hairline)"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;width:100%;">
          <div class="vign-field"><div class="vign-field-label" style="width:80%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:80%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
        </div>
        <div class="vign-field"><div class="vign-field-label" style="width:30%"></div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;padding:2px 0;">
            <div style="padding:2px 7px;background:color-mix(in srgb,var(--ember) 12%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ember) 40%,var(--hairline));border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--ember);">Produit</div>
            <div style="padding:2px 7px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--ink-mute);">Machine</div>
            <div style="padding:2px 7px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'DM Sans',sans-serif;font-size:7px;color:var(--ink-mute);">Humain</div>
          </div>
        </div>
      </div>
<?php   endif ?>

<?php elseif ($chapKey === 'conditionnement'): ?>
<?php   if ($chapN === 1): /* C1 — lot source + format tiles sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true">
        <div class="vign-field"><div class="vign-field-label" style="width:40%"></div><div class="vign-field-input vign-field-input--accent"><div class="vign-field-input-val" style="width:60%;background:color-mix(in srgb,var(--bbt) 30%,var(--hairline))"></div></div></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <div style="padding:3px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ink-mute);">ZEPF · 20L</div>
          <div style="padding:3px 8px;background:color-mix(in srgb,var(--hop) 12%,var(--bg-elev));border:1.5px solid color-mix(in srgb,var(--hop) 40%,var(--hairline));border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--hop-deep);">EMB4 · 4×33cl ✓</div>
          <div style="padding:3px 8px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ink-mute);">EMBB · 24×33cl</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
          <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
          <div class="vign-field"><div class="vign-field-label" style="width:55%"></div><div class="vign-field-input"><div class="vign-field-input-val" style="width:40%"></div></div></div>
        </div>
      </div>
<?php   elseif ($chapN === 2): /* C2 — parallel runs sketch */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);">
        <div style="display:flex;flex-direction:column;gap:4px;width:100%;">
          <div style="display:flex;gap:6px;align-items:center;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;padding:5px 8px;">
            <div style="width:6px;height:6px;border-radius:50%;background:var(--hop);flex-shrink:0;"></div>
            <div style="height:5px;width:30%;border-radius:3px;background:color-mix(in srgb,var(--hop) 40%,var(--hairline))"></div>
            <div style="margin-left:auto;height:5px;width:18%;border-radius:3px;background:color-mix(in srgb,var(--hop) 30%,var(--hairline))"></div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:6px;padding:5px 8px;">
            <div style="width:6px;height:6px;border-radius:50%;background:var(--cold);flex-shrink:0;"></div>
            <div style="height:5px;width:35%;border-radius:3px;background:color-mix(in srgb,var(--cold) 40%,var(--hairline))"></div>
            <div style="margin-left:auto;height:5px;width:18%;border-radius:3px;background:color-mix(in srgb,var(--cold) 30%,var(--hairline))"></div>
          </div>
          <div style="display:flex;align-items:center;gap:6px;padding:3px 0;opacity:0.7;">
            <div style="height:4px;width:4px;border-radius:1px;border:1px solid var(--ink-mute);"></div>
            <div style="height:4px;width:50%;border-radius:2px;background:var(--hairline)"></div>
          </div>
        </div>
      </div>
<?php   else: /* C3 — disposition grid sketch — taxed / non-taxed columns */ ?>
      <div class="vg-vignette vign-form" aria-hidden="true" style="background:var(--bg);padding:10px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;width:100%;font-family:'JetBrains Mono',monospace;font-size:6px;letter-spacing:0.06em;text-transform:uppercase;">
          <div style="color:var(--ok);text-align:center;padding-bottom:3px;border-bottom:1px solid color-mix(in srgb,var(--ok) 30%,var(--hairline))">Taxée</div>
          <div style="color:var(--ember);text-align:center;padding-bottom:3px;border-bottom:1px solid color-mix(in srgb,var(--ember) 30%,var(--hairline))">Non taxée</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;width:100%;margin-top:4px;">
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div style="display:flex;align-items:center;gap:4px;background:color-mix(in srgb,var(--ok) 6%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ok) 25%,var(--hairline));border-radius:4px;padding:3px 5px;"><div style="height:4px;width:55%;border-radius:2px;background:color-mix(in srgb,var(--ok) 40%,var(--hairline))"></div><div style="margin-left:auto;height:4px;width:14%;border-radius:2px;background:color-mix(in srgb,var(--ok) 50%,var(--hairline))"></div></div>
            <div style="display:flex;align-items:center;gap:4px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;padding:3px 5px;"><div style="height:4px;width:45%;border-radius:2px;background:var(--hairline)"></div><div style="margin-left:auto;height:4px;width:14%;border-radius:2px;background:var(--hairline)"></div></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:3px;">
            <div style="display:flex;align-items:center;gap:4px;background:color-mix(in srgb,var(--ember) 6%,var(--bg-elev));border:1px solid color-mix(in srgb,var(--ember) 25%,var(--hairline));border-radius:4px;padding:3px 5px;"><div style="height:4px;width:50%;border-radius:2px;background:color-mix(in srgb,var(--ember) 40%,var(--hairline))"></div><div style="margin-left:auto;height:4px;width:14%;border-radius:2px;background:color-mix(in srgb,var(--ember) 50%,var(--hairline))"></div></div>
            <div style="display:flex;align-items:center;gap:4px;background:var(--bg-elev);border:1px solid var(--hairline);border-radius:4px;padding:3px 5px;"><div style="height:4px;width:48%;border-radius:2px;background:var(--hairline)"></div><div style="margin-left:auto;height:4px;width:14%;border-radius:2px;background:var(--hairline)"></div></div>
          </div>
        </div>
      </div>
<?php   endif ?>

<?php elseif ($chapKey === 'chaine_pertes'): /* Capstone — loss chain flow sketch */ ?>
      <div class="vg-vignette" aria-hidden="true" style="background:var(--bg);padding:12px;display:flex;flex-direction:column;gap:6px;justify-content:center;">
        <?php
        $chainSteps = ['Brassage', 'Transfert', 'Conditionnement', 'Vendable'];
        $chainColors = ['var(--hop)', 'var(--bbt)', 'var(--oak)', 'var(--ok)'];
        foreach ($chainSteps as $ci => $cLabel): ?>
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="width:7px;height:7px;border-radius:50%;background:<?= $chainColors[$ci] ?>;flex-shrink:0;"></div>
          <div style="height:5px;width:30%;border-radius:3px;background:<?= $chainColors[$ci] ?>;opacity:0.5;"></div>
          <div style="flex:1;height:1px;background:var(--hairline);margin:0 2px;"></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:6px;color:var(--ink-mute);letter-spacing:0.04em;"><?= htmlspecialchars($cLabel) ?></div>
        </div>
        <?php if ($ci < count($chainSteps)-1): ?>
        <div style="margin-left:3px;padding-left:0;width:1px;height:8px;background:var(--hairline);margin-left:3px;"></div>
        <?php endif ?>
        <?php endforeach ?>
      </div>

<?php else: /* inventaire */ ?>
      <div class="vg-vignette vign-stocktake" aria-hidden="true">
        <div class="vign-search-bar"><div class="vign-search-icon"></div><div class="vign-search-val"></div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:50%"></div><div class="vign-ledger-qty">24,5 kg</div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:45%"></div><div class="vign-ledger-qty">6 sacs</div></div>
        <div class="vign-ledger-item"><div class="vign-ledger-dot"></div><div class="vign-ledger-name" style="width:55%"></div><div class="vign-ledger-qty">3 cartons</div></div>
      </div>
<?php endif /* form vignette switch */ ?>

<?php
    /* Icon per chapter */
    $chapIcons = [
        'brassage'        => '<svg viewBox="0 0 24 24"><path d="M6 3h12v2a6 6 0 0 1-6 6 6 6 0 0 1-6-6V3z"/><path d="M6 5h12"/><path d="M12 11v10"/><path d="M8 21h8"/><path d="M19 8h2v5h-2"/></svg>',
        'fermentation'    => '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="14" rx="8" ry="6"/><path d="M4 14V8a8 2 0 0 1 16 0v6"/><path d="M8 12c0-1 1-2 2-2s2 1 3 1 2-1 2-1"/></svg>',
        'transferts'      => '<svg viewBox="0 0 24 24"><path d="M16 3l4 4-4 4"/><path d="M20 7H4"/><path d="M8 21l-4-4 4-4"/><path d="M4 17h16"/></svg>',
        'conditionnement' => '<svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M12 12v4M10 14h4"/></svg>',
        'chaine_pertes'   => '<svg viewBox="0 0 24 24"><path d="M3 17l4-8 4 4 4-6 3 5"/><circle cx="21" cy="17" r="2"/><circle cx="3" cy="17" r="2"/></svg>',
        'inventaire'      => '<svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>',
    ];
?>
      <div class="vg-card-header">
        <div class="vg-card-icon" aria-hidden="true"><?= $chapIcons[$chapKey] ?? $PAGE_ICONS['_default'] ?></div>
        <div><h2 class="vg-card-title"><?= $step['title'] ?></h2></div>
      </div>
      <p class="vg-card-body"><?= $step['body'] ?></p>
      <a href="<?= htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8') ?>"
         class="vg-card-link"
         aria-label="Ouvrir <?= htmlspecialchars(strip_tags($step['title']), ENT_QUOTES, 'UTF-8') ?>">Ouvrir cette page ↗</a>
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
