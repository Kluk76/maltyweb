<?php
declare(strict_types=1);
/**
 * modules/salle-de-controle.php — Salle de contrôle (Qualité)
 *
 * Le Zeppelin family · Sections: Recettes, Biochimie, Conditionnement.
 * Recettes + Biochimie: presentational (TODO: data-wiring phase).
 * Conditionnement: LIVE — reads/writes commissioning_settings (section='packaging').
 *
 * Auth: require_login() — all logged-in users can view; edit gated to is_admin().
 * POST: csrf_verify → validate int 0-365 → UPDATE commissioning_settings → log_revision → PRG.
 */

require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/csrf.php';
require __DIR__ . '/../../app/settings-helpers.php';
require __DIR__ . '/../../app/db-write-helpers.php';

require_login();
$me = current_user();

// ── POST handler (Conditionnement settings — admin only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Non-admins cannot POST here
    if (!is_admin($me)) {
        flash_set('err', 'Modification réservée aux administrateurs.');
        redirect_to('/modules/salle-de-controle.php?sec=conditionnement');
    }

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/salle-de-controle.php?sec=conditionnement');
    }

    $action = post_str('action') ?? '';

    try {
        $pdo = maltytask_pdo();

        if ($action === 'update_min_days') {
            $rawDays = post_decimal('min_days_after_racking');
            if ($rawDays === null) {
                throw new RuntimeException('Valeur requise pour le délai minimum après soutirage.');
            }
            $days = (float) $rawDays;
            if ($days < 0 || $days > 365) {
                throw new RuntimeException('Valeur invalide : doit être entre 0 et 365 jours.');
            }

            // Fetch before-state for audit
            $fetchStmt = $pdo->prepare(
                "SELECT id, value_num FROM commissioning_settings
                  WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
                    AND is_active = 1
                  LIMIT 1"
            );
            $fetchStmt->execute();
            $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new RuntimeException(
                    'Paramètre introuvable — la migration 128 doit être appliquée.'
                );
            }

            $before = ['value_num' => $existing['value_num']];
            $after  = ['value_num' => $days];

            $upStmt = $pdo->prepare(
                "UPDATE commissioning_settings
                    SET value_num = ?, updated_by = ?
                  WHERE section = 'packaging' AND key_name = 'min_days_after_racking'
                    AND is_active = 1"
            );
            $upStmt->execute([$days, $me['username']]);

            log_revision(
                $pdo,
                $me,
                'commissioning_settings',
                (int) $existing['id'],
                $before,
                $after,
                'normal',
                'Salle de contrôle: packaging.min_days_after_racking'
            );

            flash_set('ok', "Délai minimum mis à jour : {$days} jour(s).");
        } else {
            throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $e) {
        flash_set('err', pdo_friendly_error($e, 'salle-de-controle-cond'));
    }

    redirect_to('/modules/salle-de-controle.php?sec=conditionnement');
}

// ── GET — load commissioning_settings for Conditionnement section ─────────────
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = maltytask_pdo();

    $settingsStmt = $pdo->prepare(
        "SELECT key_name, label_fr, description_fr, value_num, default_num, unit_fr
           FROM commissioning_settings
          WHERE section = 'packaging' AND is_active = 1
          ORDER BY id ASC"
    );
    $settingsStmt->execute();
    $packagingSettings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);

    $settingsByKey = [];
    foreach ($packagingSettings as $s) {
        $settingsByKey[$s['key_name']] = $s;
    }
    $migrationApplied = !empty($packagingSettings);
    $loadErr          = null;
} catch (Throwable $e) {
    $packagingSettings = [];
    $settingsByKey     = [];
    $migrationApplied  = false;
    $loadErr           = $e->getMessage();
}

$minDaysSetting = $settingsByKey['min_days_after_racking'] ?? null;
$minDaysCurrent = $minDaysSetting !== null
    ? (float) ($minDaysSetting['value_num'] ?? $minDaysSetting['default_num'] ?? 1)
    : 1.0;
$minDaysInt = (int) round($minDaysCurrent);

$csrf = csrf_token();

// Active section from query string (for PRG redirect after save)
$initialSec = in_array($_GET['sec'] ?? '', ['recettes', 'biochem', 'conditionnement'], true)
    ? ($_GET['sec'])
    : 'recettes';

?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Salle de contrôle — Qualité · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/salle-de-controle.css?v=<?= @filemtime(__DIR__ . '/../css/salle-de-controle.css') ?: time() ?>">
</head>
<body class="sdc-page" data-role="<?= htmlspecialchars($me['role'] ?? 'operateur') ?>">

<div class="board"><div class="scanlines"></div></div>
<span class="mark tl"></span><span class="mark tr"></span><span class="mark bl"></span><span class="mark br"></span>

<!-- CHROME -->
<div class="chrome">
  <div class="brandmark">La Nébuleuse · Le Zeppelin · <b>Salle de contrôle</b></div>
  <div class="family-switcher">
    <a class="family-btn fam-sdm" href="/modules/salle-des-machines.php" title="Salle des Machines">
      <span class="fam-dot"></span>Machines
    </a>
    <span class="family-btn fam-sdc">
      <span class="fam-dot"></span>Contrôle
    </span>
    <a class="family-btn fam-cockpit" href="/_design/le-cockpit.html" title="Cockpit commercial">
      <span class="fam-dot"></span>Cockpit
    </a>
  </div>
  <div class="sdc-user-info">
    <span class="sdc-role-pill sdc-role-pill--<?= htmlspecialchars($me['role'] ?? 'operateur') ?>">
      <?= htmlspecialchars(ucfirst($me['role'] ?? 'opérateur')) ?>
    </span>
    <span class="sdc-username"><?= htmlspecialchars($me['username'] ?? '') ?></span>
  </div>
</div>

<div class="toast" id="sdcToast"></div>

<!-- MAIN STAGE -->
<div class="sdc-stage">

  <!-- LEFT NAV -->
  <nav class="nav-rail">
    <div class="nav-section-label">Salle de contrôle</div>

    <div style="padding:0 12px 12px;display:flex;justify-content:center;">
      <svg class="lab-sketch draw-lab" width="160" height="80" viewBox="0 0 160 80">
        <path class="ink" d="M44 14 L44 44 Q44 58 56 60 L72 60 Q84 58 84 44 L84 14"/>
        <path class="ink-2" d="M38 14 L90 14"/>
        <path class="ink-2" d="M44 36 Q64 32 84 36"/>
        <path class="fillx" d="M44 36 Q64 32 84 36 L84 44 Q84 58 72 60 L56 60 Q44 58 44 44 Z"/>
        <path class="band-lab" d="M44 36 Q64 32 84 36"/>
        <path class="ink" d="M110 56 L116 34 L118 18 M122 18 L124 34 L130 56"/>
        <path class="ink-2" d="M113 56 Q120 60 127 56"/>
        <path class="ink-2" d="M116 18 L124 18"/>
        <path class="fillx" d="M116 34 L110 56 Q120 62 130 56 L124 34 Z"/>
        <circle class="ink-2" cx="148" cy="34" r="12"/>
        <path class="band-lab" d="M148 34 L148 28"/>
        <path class="ink-2" d="M142 34 L136 34"/>
        <path class="ink-2" d="M154 34 L160 34"/>
        <path class="shade" d="M146 28 L148 34 L150 28"/>
        <text class="dim-lab" x="143" y="50">pH</text>
      </svg>
    </div>

    <div class="nav-section-label" style="margin-top:4px;">Sections</div>
    <div class="nav-item" data-sec="recettes" onclick="switchSection('recettes')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="2" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5 6h6M5 8.5h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Recettes
      <span class="nav-badge">9</span>
    </div>
    <div class="nav-item" data-sec="biochem" onclick="switchSection('biochem')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M5 2v5L2 13h12L11 7V2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M5 2h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <circle cx="8" cy="10" r="1.5" fill="currentColor" opacity=".5"/>
        </svg>
      </span>
      Biochimie
    </div>
    <div class="nav-item" data-sec="conditionnement" onclick="switchSection('conditionnement')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="2" y="6" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5 6V4a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <path d="M8 9v2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          <circle cx="8" cy="9" r=".8" fill="currentColor"/>
        </svg>
      </span>
      Conditionnement
    </div>

    <div class="nav-section-label" style="margin-top:16px;">À venir</div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.2"/>
          <path d="M8 5v3l2 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      QA / Contrôles
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-faint);">Q3</span>
    </div>
    <div class="nav-item" style="opacity:.4;pointer-events:none;">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M3 3h10v10H3z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
          <path d="M6 8h4M8 6v4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Jalons process
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-faint);">Q4</span>
    </div>
  </nav>

  <!-- CONTENT AREA -->
  <div class="content-area">

    <!-- ════════════════════════════════ RECETTES SECTION -->
    <div class="section-panel" id="sec-recettes">
      <div class="recettes-layout">

        <!-- recipe list column -->
        <div class="recipe-list-col">
          <div class="col-header">
            <div class="col-title">Recettes <em>actives</em></div>
            <div class="col-subtitle">Nébuleuse · core + EPH</div>
          </div>
          <div class="recipe-scroll" id="recipeScroll"></div>
          <?php if (is_admin($me) || is_manager($me)): ?>
          <button class="btn-new-recipe" id="btnNewRecipe" onclick="openNewRecipeModal()">
            <span class="btn-plus">+</span>
            <span id="newRecipeBtnLabel"><?= is_admin($me) ? 'Nouvelle recette' : 'Demander nouvelle recette' ?></span>
          </button>
          <?php endif ?>
        </div>

        <!-- recipe detail -->
        <div class="recipe-detail-col" id="recipeDetailCol">
          <div class="recipe-detail-header" id="recipeDetailHeader">
            <div>
              <div class="rdh-title" id="rdh-title">Sélectionner <em>une recette</em></div>
              <div class="rdh-style-row">
                <input class="rdh-style-input" id="rdh-style" type="text" placeholder="style — à renseigner" maxlength="80"
                  onblur="onStyleBlur(this)" autocomplete="off"
                  <?= !is_admin($me) && !is_manager($me) ? 'readonly' : '' ?>>
                <span class="new-field-tag">nouveau champ · à câbler</span>
              </div>
              <div class="rdh-meta" id="rdh-meta">—</div>
            </div>
            <div style="margin-left:auto;text-align:right;">
              <div class="rdh-abv" id="rdh-abv">—</div>
              <div class="rdh-abv-label">ABV estimé</div>
            </div>
          </div>

          <div class="subtabs">
            <div class="subtab active" onclick="switchSubtab('ingr')">Ingrédients</div>
            <div class="subtab" onclick="switchSubtab('process')">Process</div>
          </div>

          <div class="subtab-pane active" id="pane-ingr" style="flex-direction:column;">
            <div style="display:flex;align-items:center;padding:8px 28px 6px;border-bottom:1px solid var(--hairline);flex:0 0 auto;gap:10px;">
              <span style="font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:var(--ink-faint);">Quantités</span>
              <div class="ingr-scale-toggle">
                <button class="ist-btn active" id="istBrassin" onclick="setIngrScale('brassin')">Par brassin · 30 hl</button>
                <button class="ist-btn" id="istHl" onclick="setIngrScale('hl')">Par hl</button>
              </div>
            </div>
            <div class="ingr-pane" id="ingrPaneContent" style="flex:1;">
              <div style="padding:40px;text-align:center;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Sélectionner une recette</div>
            </div>
          </div>

          <div class="subtab-pane" id="pane-process">
            <div class="process-pane" id="processPaneContent">
              <div style="padding:40px;text-align:center;color:var(--ink-faint);font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.15em;text-transform:uppercase;">Sélectionner une recette</div>
            </div>
          </div>
        </div><!-- /recipe-detail-col -->
      </div><!-- /recettes-layout -->
    </div><!-- /sec-recettes -->

    <!-- ════════════════════════════════ BIOCHIMIE SECTION -->
    <!-- TODO: data-wiring phase — currently presentational -->
    <div class="section-panel" id="sec-biochem">
      <div class="biochem-layout">
        <div class="biochem-header">
          <h2>Paramètres <em>Biochimie</em></h2>
          <div class="bh-sub">Valeurs par défaut par famille levurienne · source des héritages Process</div>
        </div>

        <div class="yf-cards" id="yfCards"></div>

        <div class="pending-block" style="margin-top:24px;">
          <div class="pending-block-head">Nouveaux champs · à câbler en DB</div>
          <div class="pending-field-list">
            <span class="pf-pill">yeast_family (ref_recipes)</span>
            <span class="pf-pill">default_ferm_temp_min (ref_biochem_defaults)</span>
            <span class="pf-pill">default_ferm_temp_max (ref_biochem_defaults)</span>
            <span class="pf-pill">default_garde_days_min (ref_biochem_defaults)</span>
            <span class="pf-pill">target_co2_vol (ref_recipe_profile)</span>
            <span class="pf-pill">recipe_ferm_temp_override (ref_recipe_profile)</span>
            <span class="pf-pill">recipe_garde_days_override (ref_recipe_profile)</span>
          </div>
        </div>

        <div style="margin-top:20px;background:rgba(74,140,120,.05);border:1px solid rgba(74,140,120,.15);border-radius:10px;padding:16px 20px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:var(--lab);margin-bottom:8px;">Modèle d'héritage</div>
          <div style="font-size:12.5px;color:var(--ink-mute);line-height:1.7;">
            <b style="color:var(--ink-soft);">Biochimie</b> définit les valeurs par défaut de la famille.<br>
            <b style="color:var(--ink-soft);">Process fiche</b> hérite et peut surcharger par recette.<br>
            Badge <span style="background:rgba(74,140,120,.15);color:var(--lab);padding:1px 5px;border-radius:3px;font-family:'JetBrains Mono',monospace;font-size:8px;">défaut famille</span> = valeur héritée non modifiée.<br>
            Badge <span style="background:rgba(202,168,110,.15);color:var(--oak);padding:1px 5px;border-radius:3px;font-family:'JetBrains Mono',monospace;font-size:8px;">override recette</span> = surcharge spécifique recette.
          </div>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════ CONDITIONNEMENT SECTION (LIVE) -->
    <div class="section-panel" id="sec-conditionnement">
      <div class="cond-layout">
        <div class="cond-header">
          <h2>Paramètres <em>Conditionnement</em></h2>
          <div class="ch-sub">Seuils process · Soutirage → Conditionnement · commissioning_settings</div>
        </div>

        <?php if ($loadErr): ?>
          <div class="sdc-flash sdc-flash--err">Erreur DB : <?= htmlspecialchars($loadErr) ?></div>
        <?php endif ?>

        <?php /* Flash messages from PRG redirect */ ?>
        <?php $flashMsg = flash_pop(); if ($flashMsg): ?>
          <div class="sdc-flash sdc-flash--<?= $flashMsg['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= $flashMsg['type'] === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($flashMsg['msg']) ?>
          </div>
        <?php endif ?>

        <div class="cond-cards">

          <!-- Délai soutirage → conditionnement — LIVE EDIT CARD -->
          <div class="cond-card">
            <div class="cc-head">
              <div class="cc-icon">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                  <circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="1.3"/>
                  <path d="M9 5v4l2.5 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
              </div>
              <div>
                <div class="cc-title">Délai soutirage</div>
                <div class="cc-sub">Seuil d'éligibilité conditionnement</div>
              </div>
            </div>

            <div class="cond-setting-row">
              <div class="csr-label">
                Conditionnement autorisé
                <small>jours après soutirage (CC)</small>
              </div>
              <div style="display:flex;align-items:baseline;gap:4px;">
                <span class="csr-value" id="csr-days-display"><?= $minDaysInt ?></span><span class="csr-unit">j</span>
                <?php if ($minDaysSetting && $minDaysSetting['default_num'] !== null): ?>
                  <span class="csr-default-note">(défaut: <?= (int) $minDaysSetting['default_num'] ?>)</span>
                <?php endif ?>
              </div>
            </div>

            <?php if ($migrationApplied && $minDaysSetting && $minDaysSetting['description_fr']): ?>
            <p class="cond-note">
              <?= htmlspecialchars($minDaysSetting['description_fr']) ?>
            </p>
            <?php else: ?>
            <div class="cond-note">
              Un lot peut être conditionné dès qu'il a passé <b>au moins <?= $minDaysInt ?> jour(s)</b>
              en CC/BBT après soutirage. Le formulaire n'affiche que les lots dont le délai est atteint.
            </div>
            <?php endif ?>

            <?php if (!$migrationApplied): ?>
              <div class="sdc-flash sdc-flash--err" style="margin-top:12px;">Migration 128 non appliquée — paramètre indisponible.</div>
            <?php elseif (is_admin($me)): ?>
              <!-- LIVE EDIT FORM — admin only -->
              <form method="post" action="/modules/salle-de-controle.php" class="cond-edit-form" novalidate>
                <input type="hidden" name="csrf"   value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="update_min_days">
                <div class="cond-edit-row">
                  <label class="cond-edit-label" for="min_days_after_racking">
                    Nouveau délai
                    <span class="cond-edit-unit">(<?= htmlspecialchars($minDaysSetting['unit_fr'] ?? 'jours') ?>)</span>
                  </label>
                  <input
                    id="min_days_after_racking"
                    name="min_days_after_racking"
                    type="number"
                    min="0"
                    max="365"
                    step="1"
                    class="cond-edit-input"
                    value="<?= htmlspecialchars((string) $minDaysInt) ?>"
                    required
                  >
                  <button type="submit" class="cond-edit-btn">Enregistrer</button>
                </div>
                <p class="cond-edit-hint">
                  0 = aucune restriction temporelle (tests uniquement).
                  Valeur habituelle : 1 (lot soutiré hier = éligible aujourd'hui).
                  L'override "Choix Hors Process" sur le formulaire permet un bypass ponctuel sans modifier ce seuil global.
                </p>
              </form>
            <?php else: ?>
              <p class="cond-readonly-note">
                Modification réservée aux administrateurs.
              </p>
            <?php endif ?>
          </div>

          <!-- Grille de lecture rapide — Lots éligibles -->
          <div class="cond-card">
            <div class="cc-head">
              <div class="cc-icon">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                  <path d="M3 5h12M3 9h8M3 13h5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                  <circle cx="14" cy="13" r="2.5" stroke="currentColor" stroke-width="1.2"/>
                  <path d="M14 12v1l.6.6" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
                </svg>
              </div>
              <div>
                <div class="cc-title">Lots éligibles</div>
                <div class="cc-sub">Logique de gate process</div>
              </div>
            </div>
            <div class="cond-setting-row">
              <div class="csr-label">
                Lots affichés dans le formulaire
                <small>état éligible uniquement</small>
              </div>
              <div>
                <span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.1em;color:var(--lab);background:rgba(74,140,120,.12);border:1px solid rgba(74,140,120,.2);border-radius:6px;padding:4px 10px;">soutirage ≥ seuil</span>
              </div>
            </div>
            <div class="cond-note">
              Le gate compare <b>DATE(submitted_at)</b> du soutirage avec la date courante.
              Seuls les lots ayant passé le seuil sont proposés dans le menu déroulant —
              les autres restent invisibles pour l'opérateur jusqu'à éligibilité.
            </div>
          </div>
        </div><!-- /cond-cards -->

        <!-- Choix Hors Process / override gate -->
        <div class="override-gate-card">
          <div class="ogc-head">
            <span class="ogc-pip"></span>
            <span class="ogc-label">Choix Hors Process — gate d'override</span>
          </div>
          <div class="ogc-body">
            <b>Managers</b> <span class="role-pill mgr">Manager</span> et <b>Admins</b> <span class="role-pill adm">Admin</span>
            peuvent conditionner un lot avant que le délai réglementaire soit atteint
            (urgence commerciale, erreur de planification, test). Ce choix est signalé
            explicitement dans le formulaire et <b>marque le record</b> avec un flag
            <code>hors_process = 1</code>
            persisté en DB pour audit.<br><br>
            <b>Opérateurs</b> <span class="role-pill opr">Opérateur</span> ne voient
            que les lots éligibles — le gate leur est opaque, aucune action hors process
            n'est exposée dans leur interface.
          </div>
        </div>

        <!-- Override / audit note -->
        <div class="impl-note">
          <div class="impl-note-head">Persistance &amp; audit</div>
          <div class="impl-note-body">
            Les seuils sont lus depuis <code>commissioning_settings</code>
            (clé <code>min_days_after_racking</code>, section <code>packaging</code>).
            Chaque modification est journalisée dans <code>audit_row_revisions</code>
            avec horodatage et auteur. Le flag <code>hors_process</code>
            alimente le journal des lots et sera visible dans la future section
            QA / Contrôles (Q3).
          </div>
        </div>
      </div>
    </div><!-- /sec-conditionnement -->

  </div><!-- /content-area -->
</div><!-- /sdc-stage -->

<!-- CONFIRM MODAL (client-side, mockup interactions only — TODO: data-wiring) -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <h4>Confirmer la modification</h4>
    <p>Paramètre de <span id="modalContext" style="color:var(--lab);">—</span></p>
    <div class="modal-diff">
      <span class="old" id="modalOld">—</span> → <span class="new" id="modalNew">—</span>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Annuler</button>
      <button class="btn-confirm" onclick="applyModal()">Confirmer</button>
    </div>
  </div>
</div>

<!-- NEW RECIPE MODAL (presentational) -->
<div class="modal-overlay modal-new-recipe" id="newRecipeOverlay">
  <div class="modal-box">
    <h4>Nouvelle <em style="color:var(--lab);">recette</em></h4>
    <p style="margin-bottom:18px;">Création d'une fiche vierge — les ingrédients et paramètres process sont à renseigner.</p>
    <div class="modal-form-row">
      <div class="modal-form-label">Nom de la recette</div>
      <input class="modal-form-input" id="nr-name" type="text" placeholder="ex. Estafette" autocomplete="off">
    </div>
    <div class="modal-form-row">
      <div class="modal-form-label">Style <span class="new-field-tag">nouveau champ · à câbler</span></div>
      <input class="modal-form-input" id="nr-style" type="text" placeholder="ex. West Coast IPA" autocomplete="off">
    </div>
    <div class="modal-form-row">
      <div class="modal-form-label">Famille de levure <span class="new-field-tag">à câbler</span></div>
      <select class="modal-form-select" id="nr-yeast">
        <option value="ale">Ale</option>
        <option value="lager">Lager</option>
        <option value="spontane">Spontané</option>
        <option value="mixte">Mixte</option>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeNewRecipeModal()">Annuler</button>
      <button class="btn-confirm" onclick="createRecipe()">Créer la fiche</button>
    </div>
  </div>
</div>

<?php
// Inject real role for JS
$jsRole = htmlspecialchars(json_encode($me['role'] ?? 'operateur'), ENT_QUOTES | ENT_SUBSTITUTE);
?>
<script>
/* ═══════════════════════════════════════════
   SERVER-INJECTED STATE
   ═══════════════════════════════════════════ */
const SDC_ROLE      = <?= json_encode($me['role'] ?? 'operateur', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const SDC_INITIAL   = <?= json_encode($initialSec, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

/* ═══════════════════════════════════════════
   DATA — baked from live DB (ref_recipe_profile rolling_12mo)
   Recettes + Biochimie are presentational this pass.
   TODO: data-wiring phase — replace with server-injected window.SDC_* JSON.
   ═══════════════════════════════════════════ */
const RECIPES = [
  {id:57,name:"Zepp",sku:"ZEP",subtype:"Core",vintage:""},
  {id:32,name:"Embuscade",sku:"EMB",subtype:"Core",vintage:""},
  {id:44,name:"Moonshine",sku:"MOO",subtype:"Core",vintage:""},
  {id:51,name:"Speakeasy",sku:"SPY",subtype:"Core",vintage:""},
  {id:52,name:"Stirling",sku:"STI",subtype:"Core",vintage:""},
  {id:30,name:"Double Oat",sku:"DOA",subtype:"Core",vintage:""},
  {id:25,name:"Diversion",sku:"DIV",subtype:"Core",vintage:""},
  {id:26,name:"Diversion Blanche",sku:"DIB",subtype:"Core",vintage:""},
  {id:6,name:"Alternative",sku:"ALT",subtype:"Core",vintage:""},
];

const INGREDIENTS = {
  57:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:15.833,unit:"kg"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:13.333,unit:"g"},{mi:"HOPS_SAAZER",name:"Saazer",cat:"Hops",qty:83.333,unit:"g"},{mi:"HOPS_SPALTER_SELECT",name:"Spalter Select",cat:"Hops",qty:83.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:23.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:5.833,unit:"g"},{mi:"MIN_MGCL2",name:"Magnesium Chloride",cat:"Minéraux",qty:5.833,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:3.5,unit:"g"},{mi:"MIN_NACL",name:"Sodium Chloride",cat:"Minéraux",qty:4.667,unit:"g"}],
  32:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:8.0,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:14.833,unit:"kg"},{mi:"HOPS_AMARILLO",name:"Amarillo",cat:"Hops",qty:333.333,unit:"g"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:33.333,unit:"g"},{mi:"HOPS_SIMCOE",name:"Simcoe",cat:"Hops",qty:166.667,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:45.0,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:10.0,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:6.667,unit:"g"}],
  44:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:10.333,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:8.333,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"ADJ_CORIANDER",name:"Coriander",cat:"Adjunct",qty:73.333,unit:"g"},{mi:"ADJ_ORANGE_PEEL",name:"Orange Peel",cat:"Adjunct",qty:110.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:26.667,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:5.933,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:5.933,unit:"g"},{mi:"MIN_MGCL2",name:"Magnesium Chloride",cat:"Minéraux",qty:3.567,unit:"g"}],
  51:[{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:2.0,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:8.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:4.667,unit:"kg"},{mi:"HOPS_C_INCOGNITO",name:"Citra Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"HOPS_EL_DORADO",name:"El Dorado",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:333.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:28.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:19.667,unit:"g"}],
  52:[{mi:"MALT_CRYSTAL",name:"Cara Crystal",cat:"Malt",qty:0.277,unit:"kg"},{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:4.167,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:13.667,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_HERKULES",name:"Herkules",cat:"Hops",qty:16.667,unit:"g"},{mi:"HOPS_SIMCOE",name:"Simcoe",cat:"Hops",qty:266.667,unit:"g"},{mi:"PROC_DEHAZE",name:"Dehaze",cat:"Proc/Chem",qty:5.0,unit:"ml"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:40.0,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:2.433,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_NACL",name:"Sodium Chloride",cat:"Minéraux",qty:6.083,unit:"g"}],
  30:[{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:4.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:25.0,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:3.333,unit:"kg"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:500.0,unit:"g"},{mi:"HOPS_M_INCOGNITO",name:"Mosaic Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:53.333,unit:"ml"},{mi:"PROC_YEASTVIT",name:"Yeastvit",cat:"Proc/Chem",qty:8.0,unit:"g"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:8.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:34.2,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:12.667,unit:"g"}],
  25:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:1.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:6.333,unit:"kg"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:166.667,unit:"g"},{mi:"HOPS_M_INCOGNITO",name:"Mosaic Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"PROC_NAGARDO",name:"Nagardo",cat:"Proc/Chem",qty:2.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:41.667,unit:"ml"},{mi:"MIN_CACL2",name:"Calcium Chloride",cat:"Minéraux",qty:4.867,unit:"g"},{mi:"MIN_CASO4",name:"Calcium Sulphate",cat:"Minéraux",qty:2.433,unit:"g"},{mi:"MIN_MGSO4",name:"Magnesium Sulphate",cat:"Minéraux",qty:4.867,unit:"g"}],
  26:[{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:5.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:5.333,unit:"kg"},{mi:"HOPS_CASCADE",name:"Cascade",cat:"Hops",qty:40.0,unit:"g"},{mi:"ADJ_PEACH_TEA",name:"Peach Tea",cat:"Adjunct",qty:133.333,unit:"g"},{mi:"PROC_ISYENHANCE",name:"IsyEnhance",cat:"Proc/Chem",qty:40.0,unit:"g"},{mi:"PROC_NAGARDO",name:"Nagardo",cat:"Proc/Chem",qty:2.0,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:43.333,unit:"ml"}],
  6:[{mi:"MALT_MUNICH",name:"Munich",cat:"Malt",qty:1.833,unit:"kg"},{mi:"MALT_OAT_FLAKES",name:"Oat Flakes",cat:"Malt",qty:0.667,unit:"kg"},{mi:"MALT_PILSENER",name:"Pilsener",cat:"Malt",qty:4.667,unit:"kg"},{mi:"MALT_WHEAT",name:"Wheat",cat:"Malt",qty:1.0,unit:"kg"},{mi:"HOPS_C_INCOGNITO",name:"Citra Incognito",cat:"Hops",qty:66.667,unit:"g"},{mi:"HOPS_MOSAIC",name:"Mosaic",cat:"Hops",qty:333.333,unit:"g"},{mi:"PROC_PHOSPHORIQUE",name:"Phosphorique",cat:"Proc/Chem",qty:23.333,unit:"ml"}],
};

const PROFILES = {
  57:{og:10.09,fg:2.08,atten:79.37,phCool:4.98,phFerm:4.41,fermDays:13.43,ccDays:28.25,gardeDays:null,co2:null,batches:37},
  32:{og:14.10,fg:2.20,atten:84.40,phCool:4.99,phFerm:4.59,fermDays:12.70,ccDays:25.58,gardeDays:null,co2:null,batches:21},
  44:{og:12.24,fg:2.25,atten:81.56,phCool:5.04,phFerm:4.19,fermDays:7.84,ccDays:35.50,gardeDays:null,co2:null,batches:20},
  51:{og:9.07,fg:1.74,atten:80.70,phCool:4.96,phFerm:4.04,fermDays:6.92,ccDays:10.23,gardeDays:null,co2:null,batches:14},
  52:{og:11.84,fg:2.75,atten:76.83,phCool:5.02,phFerm:4.39,fermDays:8.89,ccDays:17.11,gardeDays:null,co2:null,batches:11},
  30:{og:17.06,fg:3.62,atten:78.75,phCool:5.05,phFerm:4.45,fermDays:13.75,ccDays:13.13,gardeDays:null,co2:null,batches:9},
  25:{og:5.10,fg:4.54,atten:8.89,phCool:4.60,phFerm:4.31,fermDays:2.57,ccDays:42.90,gardeDays:null,co2:null,batches:14},
  26:{og:7.05,fg:5.20,atten:22.30,phCool:4.65,phFerm:4.37,fermDays:3.75,ccDays:20.0,gardeDays:null,co2:null,batches:4},
  6:{og:6.07,fg:1.26,atten:78.23,phCool:4.86,phFerm:4.06,fermDays:7.50,ccDays:19.67,gardeDays:null,co2:null,batches:7},
};

const YEAST_DEFAULTS = {
  ale:{fermTempMin:18,fermTempMax:22,gardeMin:14,gardeMax:28,typical:"Saccharomyces cerevisiae — ale top-fermenting. Température ambiante, développe esters fruités."},
  lager:{fermTempMin:9,fermTempMax:13,gardeMin:28,gardeMax:56,typical:"Saccharomyces pastorianus — fermentation basse. Cave froide, profil propre, garde longue."},
  spontane:{fermTempMin:14,fermTempMax:20,gardeMin:90,gardeMax:365,typical:"Levures et bactéries sauvages — lambic, gueuze. Fermentation longue et complexe."},
  mixte:{fermTempMin:18,fermTempMax:22,gardeMin:21,gardeMax:60,typical:"Mix Saccharomyces + Brettanomyces / bactéries. Profil fruité-acide, garde variable."},
};

const RECIPE_YEAST = {57:"lager",32:"ale",44:"ale",51:"ale",52:"ale",30:"ale",25:"spontane",26:"ale",6:"ale"};

/* ═══════════════════════════════════════════
   ABV calc — Plato-based (Balling / Terrill)
   ═══════════════════════════════════════════ */
function calcAbv(og, fg){
  if(!og || !fg) return null;
  const sgOG = 1 + og/(258.6 - og/258.2*227.1);
  const sgFG = 1 + fg/(258.6 - fg/258.2*227.1);
  return ((sgOG - sgFG) * 131.25).toFixed(1);
}

/* ═══════════════════════════════════════════
   STATE
   ═══════════════════════════════════════════ */
let selectedRecipeId = null;
let currentSubtab    = 'ingr';
let pendingModal     = null;
let ingrScale        = 'brassin';
const BRASSIN_HL     = 30;
const RECIPE_STYLES  = {};

/* ═══════════════════════════════════════════
   SECTION SWITCHER
   ═══════════════════════════════════════════ */
function switchSection(sec){
  document.querySelectorAll('.section-panel').forEach(p=>p.classList.toggle('active',p.id==='sec-'+sec));
  document.querySelectorAll('.nav-item[data-sec]').forEach(n=>n.classList.toggle('active',n.dataset.sec===sec));
}

/* ═══════════════════════════════════════════
   SUB-TAB SWITCHER
   ═══════════════════════════════════════════ */
function switchSubtab(tab){
  currentSubtab = tab;
  document.querySelectorAll('.subtab').forEach((el,i)=>{
    const tabs=['ingr','process'];
    el.classList.toggle('active',tabs[i]===tab);
  });
  document.querySelectorAll('.subtab-pane').forEach(p=>p.classList.toggle('active',p.id==='pane-'+tab));
}

/* ═══════════════════════════════════════════
   RECIPE LIST RENDER
   ═══════════════════════════════════════════ */
function escHtml(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildRecipeList(){
  const scroll=document.getElementById('recipeScroll');
  if(!scroll) return;
  scroll.innerHTML='';
  const label=document.createElement('div');
  label.className='recipe-group-label';
  label.textContent='Core — Nébuleuse';
  scroll.appendChild(label);
  RECIPES.forEach(r=>{
    const p=PROFILES[r.id];
    const abv=p?calcAbv(p.og,p.fg):null;
    const div=document.createElement('div');
    div.className='recipe-item';
    div.dataset.id=r.id;
    const dotClass={Core:'core',EPH:'eph',CollabIn:'collab',Archive:'archive'}[r.subtype]||'archive';
    div.innerHTML=`<span class="ri-dot ${escHtml(dotClass)}"></span>
      <div class="ri-body">
        <div class="ri-name">${escHtml(r.name)}</div>
        <div class="ri-meta">${p?p.batches+' brassin(s) · OG '+p.og+'°P':'—'}</div>
      </div>
      <div>
        ${r.sku?`<div class="ri-sku">${escHtml(r.sku)}</div>`:''}
        ${abv?`<div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--lab);text-align:right;margin-top:3px;">${escHtml(abv)}%</div>`:''}
      </div>`;
    div.addEventListener('click',()=>selectRecipe(r.id));
    scroll.appendChild(div);
  });
}

function selectRecipe(id){
  selectedRecipeId=id;
  document.querySelectorAll('.recipe-item').forEach(el=>el.classList.toggle('sel',+el.dataset.id===id));
  const rec=RECIPES.find(r=>r.id===id);
  const p=PROFILES[id];
  const abv=p?calcAbv(p.og,p.fg):null;
  document.getElementById('rdh-title').innerHTML=`<em>${escHtml(rec.name)}</em>`;
  const styleInp=document.getElementById('rdh-style');
  if(styleInp){styleInp.value=RECIPE_STYLES[id]||'';styleInp.defaultValue=styleInp.value;}
  document.getElementById('rdh-meta').textContent=(rec.sku||'—')+' · '+rec.subtype+(p?' · '+p.batches+' brassins':'');
  document.getElementById('rdh-abv').textContent=abv?abv+'%':'—';
  renderIngrPane(id,p);
  renderProcessPane(id,p);
}

/* ═══════════════════════════════════════════
   INGRÉDIENTS PANE
   ═══════════════════════════════════════════ */
const CAT_COLORS={Malt:'#a07a48',Hops:'#9eb060',Adjunct:'#9b7cc8','Proc/Chem':'#6593b8',Minéraux:'#4a8c78'};
const CAT_ORDER=['Malt','Hops','Adjunct','Proc/Chem','Minéraux'];

function scaledQty(qty,unit){
  const factor=ingrScale==='brassin'?BRASSIN_HL:1;
  const scaled=qty*factor;
  if(unit==='kg'){return scaled>=1?{val:scaled,unit:'kg'}:{val:scaled*1000,unit:'g'};}
  if(unit==='g'){return scaled>=1000?{val:scaled/1000,unit:'kg'}:{val:scaled,unit:'g'};}
  if(unit==='ml'){return scaled>=1000?{val:scaled/1000,unit:'L'}:{val:scaled,unit:'ml'};}
  return{val:scaled,unit};
}
function fmtVal(v,unit){
  if(unit==='kg') return v<1?v.toFixed(3):v.toFixed(2);
  if(unit==='g') return v<10?v.toFixed(1):Math.round(v);
  if(unit==='L') return v.toFixed(2);
  return v%1===0?v:v.toFixed(1);
}
function setIngrScale(scale){
  ingrScale=scale;
  document.getElementById('istBrassin').classList.toggle('active',scale==='brassin');
  document.getElementById('istHl').classList.toggle('active',scale==='hl');
  if(selectedRecipeId!==null) renderIngrPane(selectedRecipeId,PROFILES[selectedRecipeId]);
}
function renderIngrPane(id,profile){
  const container=document.getElementById('ingrPaneContent');
  if(!container) return;
  const items=INGREDIENTS[id]||[];
  if(!items.length){container.innerHTML='<div style="padding:40px;text-align:center;color:var(--ink-faint);">Aucun ingrédient.</div>';return;}
  const groups={};
  items.forEach(it=>{const c=it.cat;if(!groups[c])groups[c]=[];groups[c].push(it);});
  const toGRaw=(qty,unit)=>unit==='kg'?qty*1000:qty;
  const maxPerCat={};
  Object.entries(groups).forEach(([c,rows])=>{maxPerCat[c]=Math.max(...rows.map(r=>toGRaw(r.qty,r.unit)));});
  let html='';
  const abv=profile?calcAbv(profile.og,profile.fg):null;
  if(abv){html+=`<div style="margin-bottom:16px;padding:12px 14px;background:rgba(74,140,120,.07);border:1px solid rgba(74,140,120,.2);border-radius:8px;display:flex;align-items:baseline;gap:12px;"><span class="abv-big">${escHtml(abv)}</span><span class="abv-pct">% ABV</span><span class="abv-calc-note" style="margin-left:auto;">Estimé Plato · OG ${profile.og}°P · FG ${profile.fg}°P · ${profile.batches} brassins</span></div>`;}
  const scaleLabel=ingrScale==='brassin'?`/ brassin · ${BRASSIN_HL} hl`:'/ hl';
  CAT_ORDER.forEach(cat=>{
    const rows=groups[cat];if(!rows)return;
    const color=CAT_COLORS[cat]||'var(--oak)';
    const totalKgScaled=rows.filter(r=>r.unit==='kg').reduce((s,r)=>s+r.qty*(ingrScale==='brassin'?BRASSIN_HL:1),0);
    const totalGScaled=rows.filter(r=>r.unit==='g').reduce((s,r)=>s+r.qty*(ingrScale==='brassin'?BRASSIN_HL:1),0);
    let total='';
    if(totalKgScaled>0){total=totalKgScaled>=1?totalKgScaled.toFixed(2)+' kg '+scaleLabel:(totalKgScaled*1000).toFixed(0)+' g '+scaleLabel;}
    else if(totalGScaled>0){total=totalGScaled>=1000?(totalGScaled/1000).toFixed(2)+' kg '+scaleLabel:totalGScaled.toFixed(0)+' g '+scaleLabel;}
    html+=`<div class="ingr-section-head"><span class="ish-dot" style="background:${color}"></span><span class="ish-label">${escHtml(cat)}</span><span class="ish-rule"></span><span class="ish-total">${escHtml(total)}</span></div>`;
    rows.forEach(it=>{
      const pct=toGRaw(it.qty,it.unit)/maxPerCat[cat]*100;
      const s=scaledQty(it.qty,it.unit);
      html+=`<div class="ingr-row"><div class="ingr-name">${escHtml(it.name)}</div><div class="ingr-mid">${escHtml(it.mi)}</div><div class="ingr-qty">${escHtml(String(fmtVal(s.val,s.unit)))}</div><div class="ingr-unit">${escHtml(s.unit)}</div><div class="ingr-bar-wrap"><div class="ingr-bar" style="width:${pct}%;background:${color};opacity:.7;"></div></div></div>`;
    });
  });
  container.innerHTML=html;
}

/* ═══════════════════════════════════════════
   PROCESS PANE
   ═══════════════════════════════════════════ */
function renderProcessPane(id,profile){
  const container=document.getElementById('processPaneContent');
  if(!container) return;
  const rec=RECIPES.find(r=>r.id===id);
  const yf=RECIPE_YEAST[id]||'ale';
  const yd=YEAST_DEFAULTS[yf];
  const abv=profile?calcAbv(profile.og,profile.fg):null;
  const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');
  let html='';
  if(abv){html+=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;"><div class="abv-display"><span class="abv-big">${escHtml(abv)}</span><span class="abv-pct">% ABV</span></div><div><div class="abv-calc-note">Lié à l'onglet Ingrédients</div><div class="abv-calc-note" style="margin-top:2px;">OG/FG en °Plato → formule Balling/Terrill</div></div></div>`;}
  html+=`<div class="yeast-family-row"><span class="yf-label">Famille levurienne</span><span class="new-field-tag">nouveau champ · à câbler</span><div class="yf-options">${['ale','lager','spontane','mixte'].map(f=>`<button class="yf-btn${f===yf?' active':''}" onclick="changeYeastFamily(${id},'${escHtml(f)}')">${escHtml(f)}</button>`).join('')}</div></div>`;
  if(profile){html+=`<div class="xray-panel"><div class="xp-label">Cibles mesurées · <span style="color:var(--ink-mute);font-weight:normal;">${profile.batches} brassins rolling 12 mois · °Plato</span></div><div class="xp-grid"><div class="xp-field"><div class="xf-k">OG cible</div><div class="xf-v">${profile.og}<span class="xf-unit">°P</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">FG cible</div><div class="xf-v">${profile.fg}<span class="xf-unit">°P</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">Atténuation app.</div><div class="xf-v lab-color">${profile.atten}<span class="xf-unit">%</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">pH refroid.</div><div class="xf-v">${profile.phCool}</div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">pH post-ferm.</div><div class="xf-v">${profile.phFerm}</div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">Ferm. (jours)</div><div class="xf-v">${profile.fermDays}<span class="xf-unit">j</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">CC / garde (jours)</div><div class="xf-v">${profile.ccDays}<span class="xf-unit">j</span></div><span class="xf-badge badge-inherited">DB réel</span></div><div class="xp-field"><div class="xf-k">ABV calculé</div><div class="xf-v lab-color">${escHtml(abv||'—')}<span class="xf-unit">%</span></div><span class="xf-badge badge-inherited">Calculé</span></div></div></div>`;}
  const roAttr=canEdit?'':' readonly';
  html+=`<div class="xray-panel"><div class="xp-label">Héritage famille <em style="color:var(--ink)">${escHtml(yf)}</em><span class="new-field-tag">champs en attente de migration</span></div><div class="xp-grid" id="yf-inherit-grid-${id}"><div class="xp-field inherited"><div class="xf-k">Temp. fermentation</div><div class="xf-v" style="font-size:16px;">${yd.fermTempMin}–${yd.fermTempMax}<span class="xf-unit">°C</span></div><span class="xf-badge badge-inherited">défaut famille</span></div><div class="xp-field inherited"><div class="xf-k">Garde min. (jours)</div><div class="xf-v"><input type="number" id="inp-garde-${id}" value="${yd.gardeMin}"${roAttr} onblur="onFieldBlur(this,'garde_min','${id}')"><span class="xf-unit">j</span></div><span class="xf-badge badge-inherited" id="badge-garde-${id}">défaut famille</span></div><div class="xp-field pending"><div class="xf-k">CO₂ cible BBT</div><div class="xf-v"><input type="number" id="inp-co2-${id}" value="2.5"${roAttr} onblur="onFieldBlur(this,'co2_bbt','${id}')"><span class="xf-unit">vol</span></div><span class="xf-badge badge-pending">à câbler</span></div></div></div>`;
  container.innerHTML=html;
}

/* ═══════════════════════════════════════════
   BIOCHIMIE PAGE BUILD
   ═══════════════════════════════════════════ */
function buildBiochemPage(){
  const container=document.getElementById('yfCards');
  if(!container) return;
  const canEdit=(SDC_ROLE==='admin'||SDC_ROLE==='manager');
  const families=[
    {key:'ale',label:'Ale',color:'var(--oak)',recipes:['Zepp','Embuscade','Moonshine','Speakeasy','Stirling','Double Oat','Diversion Blanche','Alternative']},
    {key:'lager',label:'Lager',color:'var(--bbt)',recipes:['Zepp']},
    {key:'spontane',label:'Spontanée',color:'var(--ember)',recipes:['Diversion']},
    {key:'mixte',label:'Mixte',color:'var(--pkg-band)',recipes:[]},
  ];
  families.forEach(f=>{
    const d=YEAST_DEFAULTS[f.key];
    const div=document.createElement('div');
    div.className='yf-card '+f.key;
    const roAttr=canEdit?'':' readonly';
    div.innerHTML=`<div class="yf-card-head"><div class="yf-family-name ${escHtml(f.key)}"><em>${escHtml(f.label)}</em></div><div class="yf-recipe-count">${f.recipes.length} recette(s) assignée(s) <span class="new-field-tag">mock</span></div></div><div class="yf-defaults"><div class="yf-def-row pending-field"><div class="yf-def-k">Temp. ferm. min <span class="new-field-tag">à câbler</span></div><div class="yf-def-v"><input type="number" value="${d.fermTempMin}" min="0" max="40"${roAttr} onblur="onBiochemBlur(this,'${escHtml(f.key)}','fermTempMin')"><span class="yf-def-unit">°C</span></div></div><div class="yf-def-row pending-field"><div class="yf-def-k">Temp. ferm. max <span class="new-field-tag">à câbler</span></div><div class="yf-def-v"><input type="number" value="${d.fermTempMax}" min="0" max="40"${roAttr} onblur="onBiochemBlur(this,'${escHtml(f.key)}','fermTempMax')"><span class="yf-def-unit">°C</span></div></div><div class="yf-def-row pending-field"><div class="yf-def-k">Garde min. <span class="new-field-tag">à câbler</span></div><div class="yf-def-v"><input type="number" value="${d.gardeMin}" min="0"${roAttr} onblur="onBiochemBlur(this,'${escHtml(f.key)}','gardeMin')"><span class="yf-def-unit">j</span></div></div><div class="yf-def-row pending-field"><div class="yf-def-k">Garde max. <span class="new-field-tag">à câbler</span></div><div class="yf-def-v"><input type="number" value="${d.gardeMax}" min="0"${roAttr} onblur="onBiochemBlur(this,'${escHtml(f.key)}','gardeMax')"><span class="yf-def-unit">j</span></div></div></div><div class="yf-card-note">${escHtml(d.typical)}</div>`;
    container.appendChild(div);
  });
}

/* ═══════════════════════════════════════════
   INTERACTIVE — yeast family change
   ═══════════════════════════════════════════ */
function changeYeastFamily(id,family){
  RECIPE_YEAST[id]=family;
  renderProcessPane(id,PROFILES[id]);
  showToast('Famille: '+family+' · champ à câbler en DB');
}

/* ═══════════════════════════════════════════
   INTERACTIVE — field edit (mock, pending DB wiring)
   ═══════════════════════════════════════════ */
function onFieldBlur(inp,field,id){
  if(SDC_ROLE==='operateur') return;
  const oldVal=inp.defaultValue;
  const newVal=inp.value;
  if(oldVal===newVal) return;
  if(SDC_ROLE==='admin'){
    pendingModal={inp,oldVal,newVal,field,id};
    document.getElementById('modalContext').textContent=field+' (recette #'+id+')';
    document.getElementById('modalOld').textContent=oldVal;
    document.getElementById('modalNew').textContent=newVal;
    document.getElementById('modalOverlay').classList.add('open');
  } else {
    inp.value=oldVal;
    showToast('Modification soumise pour approbation');
  }
}

function closeModal(){
  if(pendingModal) pendingModal.inp.value=pendingModal.oldVal;
  pendingModal=null;
  document.getElementById('modalOverlay').classList.remove('open');
}
function applyModal(){
  if(pendingModal){
    pendingModal.inp.defaultValue=pendingModal.newVal;
    if(pendingModal.isStyle&&selectedRecipeId!==null){RECIPE_STYLES[selectedRecipeId]=pendingModal.newVal;}
    showToast('Enregistré (mock): '+pendingModal.field+' = '+pendingModal.newVal);
  }
  pendingModal=null;
  document.getElementById('modalOverlay').classList.remove('open');
}

function onBiochemBlur(inp,family,field){
  if(SDC_ROLE==='operateur'){inp.value=inp.defaultValue;return;}
  const oldVal=inp.defaultValue;const newVal=inp.value;
  if(oldVal===newVal) return;
  if(SDC_ROLE==='admin'){
    pendingModal={inp,oldVal,newVal,field,id:family};
    document.getElementById('modalContext').textContent='famille '+family+' · '+field;
    document.getElementById('modalOld').textContent=oldVal;
    document.getElementById('modalNew').textContent=newVal;
    document.getElementById('modalOverlay').classList.add('open');
  } else {inp.value=oldVal;showToast('Modification soumise pour approbation');}
}

/* ═══════════════════════════════════════════
   STYLE FIELD
   ═══════════════════════════════════════════ */
function onStyleBlur(inp){
  if(SDC_ROLE==='operateur') return;
  const oldVal=inp.defaultValue;
  const newVal=inp.value.trim();
  if(oldVal===newVal) return;
  if(SDC_ROLE==='admin'){
    pendingModal={inp,oldVal,newVal,field:'style',id:selectedRecipeId,isStyle:true};
    document.getElementById('modalContext').textContent='style · recette #'+selectedRecipeId;
    document.getElementById('modalOld').textContent=oldVal||'(vide)';
    document.getElementById('modalNew').textContent=newVal||'(vide)';
    document.getElementById('modalOverlay').classList.add('open');
  } else {inp.value=oldVal;showToast('Style soumis pour approbation');}
}

/* ═══════════════════════════════════════════
   NEW RECIPE MODAL
   ═══════════════════════════════════════════ */
function openNewRecipeModal(){
  if(SDC_ROLE==='operateur') return;
  const el=document.getElementById('newRecipeOverlay');
  if(!el) return;
  document.getElementById('nr-name').value='';
  document.getElementById('nr-style').value='';
  document.getElementById('nr-yeast').value='ale';
  el.classList.add('open');
  setTimeout(()=>document.getElementById('nr-name').focus(),80);
}
function closeNewRecipeModal(){
  const el=document.getElementById('newRecipeOverlay');
  if(el) el.classList.remove('open');
}
function createRecipe(){
  const name=document.getElementById('nr-name').value.trim();
  const style=document.getElementById('nr-style').value.trim();
  const yeast=document.getElementById('nr-yeast').value;
  if(!name){document.getElementById('nr-name').focus();showToast('Nom de recette requis');return;}
  if(SDC_ROLE==='manager'){closeNewRecipeModal();showToast('Demande soumise: '+name+' · en attente admin');return;}
  const newId=Date.now();
  const newRec={id:newId,name,sku:'',subtype:'Core',vintage:''};
  RECIPES.push(newRec);INGREDIENTS[newId]=[];PROFILES[newId]=null;RECIPE_YEAST[newId]=yeast;
  if(style) RECIPE_STYLES[newId]=style;
  const badge=document.querySelector('.nav-badge');
  if(badge) badge.textContent=RECIPES.length;
  closeNewRecipeModal();buildRecipeList();selectRecipe(newId);
  showToast('Recette créée (mock): '+name+(style?' · '+style:''));
}

/* ═══════════════════════════════════════════
   TOAST
   ═══════════════════════════════════════════ */
let toastTimer;
function showToast(msg){
  const t=document.getElementById('sdcToast');
  t.textContent=msg;t.classList.add('show');
  clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('show'),2800);
}

/* ═══════════════════════════════════════════
   INIT
   ═══════════════════════════════════════════ */
buildRecipeList();
buildBiochemPage();
switchSection(SDC_INITIAL);
if(SDC_INITIAL==='recettes') selectRecipe(RECIPES[0].id);
</script>
</body>
</html>
