<?php
declare(strict_types=1);
/**
 * modules/reglages-generaux.php — Réglages généraux
 *
 * Le Zeppelin family · Admin-only. Two sections:
 *   1. Données générales — system_settings (date/time/language/brewery name) + ref_sites CRUD.
 *   2. Utilisateurs      — users list (read-only) + create-user form (admin only).
 *
 * Auth: require_login() + hard is_admin() gate (non-admins get 302 to /).
 * POST: csrf_verify → validate → write → log_revision → PRG redirect.
 * Graceful degradation: if migration 129/130 not applied, shows pending banner.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/db-write-helpers.php';
// app/db.php is already included transitively via auth.php → db.php

require_login();
$me = current_user();

// Hard admin gate — 302 non-admins silently (no 403 info-leak about the page)
if (!is_admin($me)) {
    redirect_to('/');
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('err', 'Session expirée — recharge la page.');
        redirect_to('/modules/reglages-generaux.php?' . http_build_query(['sec' => post_str('sec') ?? 'general']));
    }

    $action = post_str('action') ?? '';

    try {
        $pdo = maltytask_pdo();

        // ── Action: save general settings ──
        if ($action === 'save_general') {
            $fields = [
                'date_format'        => ['type' => 'text', 'label' => 'Format de date',   'allowed' => ['d/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y']],
                'time_format'        => ['type' => 'text', 'label' => 'Format d\'heure',   'allowed' => ['H:i', 'h:i A']],
                'language'           => ['type' => 'text', 'label' => 'Langue',            'allowed' => ['fr', 'de', 'en']],
                'brewery_name'       => ['type' => 'text', 'label' => 'Nom brasserie',     'allowed' => null],
                'date_parse_dayfirst'=> ['type' => 'num',  'label' => 'Format saisie date','allowed' => null],
            ];

            foreach ($fields as $key => $def) {
                if ($def['type'] === 'text') {
                    $val = post_str($key);
                    if ($val === null) continue;
                    if ($def['allowed'] !== null) {
                        must_be_one_of($def['label'], $val, $def['allowed']);
                    }
                    $val = substr($val, 0, 255);
                } else {
                    // numeric flag: 0 or 1
                    $val = isset($_POST[$key]) ? 1.0 : 0.0;
                }

                // Fetch before-state
                $sel = $pdo->prepare(
                    "SELECT id, value_text, value_num FROM system_settings
                      WHERE section = 'general' AND key_name = ? AND is_active = 1
                      LIMIT 1"
                );
                $sel->execute([$key]);
                $existing = $sel->fetch(PDO::FETCH_ASSOC);

                if (!$existing) continue; // migration not applied yet

                $before = ['value_text' => $existing['value_text'], 'value_num' => $existing['value_num']];

                if ($def['type'] === 'text') {
                    $upd = $pdo->prepare(
                        "UPDATE system_settings SET value_text = ?, value_num = NULL, updated_by = ?
                          WHERE id = ?"
                    );
                    $upd->execute([$val, $me['username'], (int) $existing['id']]);
                    $after = ['value_text' => $val, 'value_num' => null];
                } else {
                    $upd = $pdo->prepare(
                        "UPDATE system_settings SET value_num = ?, value_text = NULL, updated_by = ?
                          WHERE id = ?"
                    );
                    $upd->execute([$val, $me['username'], (int) $existing['id']]);
                    $after = ['value_text' => null, 'value_num' => $val];
                }

                log_revision($pdo, $me, 'system_settings', (int) $existing['id'], $before, $after, 'normal',
                    "Réglages généraux: general.{$key}");
            }

            flash_set('ok', 'Paramètres généraux mis à jour.');
            redirect_to('/modules/reglages-generaux.php?sec=general');
        }

        // ── Action: add site ──
        if ($action === 'add_site') {
            $name = post_str('name');
            if ($name === null || strlen($name) < 2) {
                throw new RuntimeException('Le nom du site est obligatoire (minimum 2 caractères).');
            }
            $name        = substr($name, 0, 120);
            $addressLine = post_str('address_line');
            if ($addressLine !== null) $addressLine = substr($addressLine, 0, 255);
            $postalCode  = post_str('postal_code');
            if ($postalCode !== null) $postalCode = substr($postalCode, 0, 16);
            $city        = post_str('city');
            if ($city !== null) $city = substr($city, 0, 120);
            $country     = post_str('country') ?? 'CH';
            must_be_one_of('country', $country, ['CH', 'FR', 'DE', 'IT', 'BE', 'LU', 'AT', 'NL', 'US', 'GB']);
            $notes       = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 1000);

            $ins = $pdo->prepare(
                "INSERT INTO ref_sites (name, address_line, postal_code, city, country, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$name, $addressLine, $postalCode, $city, $country, $notes, $me['username']]);
            $newId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ref_sites', $newId, null,
                ['name' => $name, 'address_line' => $addressLine, 'postal_code' => $postalCode, 'city' => $city, 'country' => $country, 'notes' => $notes],
                'normal', 'Réglages généraux: nouveau site');

            flash_set('ok', "Site « " . htmlspecialchars($name) . " » ajouté.");
            redirect_to('/modules/reglages-generaux.php?sec=general');
        }

        // ── Action: edit site ──
        if ($action === 'edit_site') {
            $siteId = post_int('site_id');
            if ($siteId === null || $siteId <= 0) {
                throw new RuntimeException('Identifiant de site invalide.');
            }
            $before = bd_fetch_before($pdo, 'ref_sites', $siteId);
            if ($before === null) {
                throw new RuntimeException('Site introuvable.');
            }

            $name = post_str('name');
            if ($name === null || strlen($name) < 2) {
                throw new RuntimeException('Le nom du site est obligatoire (minimum 2 caractères).');
            }
            $name        = substr($name, 0, 120);
            $addressLine = post_str('address_line');
            if ($addressLine !== null) $addressLine = substr($addressLine, 0, 255);
            $postalCode  = post_str('postal_code');
            if ($postalCode !== null) $postalCode = substr($postalCode, 0, 16);
            $city        = post_str('city');
            if ($city !== null) $city = substr($city, 0, 120);
            $country     = post_str('country') ?? 'CH';
            must_be_one_of('country', $country, ['CH', 'FR', 'DE', 'IT', 'BE', 'LU', 'AT', 'NL', 'US', 'GB']);
            $notes = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 1000);

            $upd = $pdo->prepare(
                "UPDATE ref_sites SET name=?, address_line=?, postal_code=?, city=?, country=?, notes=?, updated_by=?
                  WHERE id = ?"
            );
            $upd->execute([$name, $addressLine, $postalCode, $city, $country, $notes, $me['username'], $siteId]);

            $after = ['name' => $name, 'address_line' => $addressLine, 'postal_code' => $postalCode, 'city' => $city, 'country' => $country, 'notes' => $notes];
            log_revision($pdo, $me, 'ref_sites', $siteId,
                ['name' => $before['name'], 'address_line' => $before['address_line'], 'postal_code' => $before['postal_code'], 'city' => $before['city'], 'country' => $before['country'], 'notes' => $before['notes']],
                $after, 'normal', 'Réglages généraux: site modifié');

            flash_set('ok', "Site « " . htmlspecialchars($name) . " » mis à jour.");
            redirect_to('/modules/reglages-generaux.php?sec=general');
        }

        // ── Action: toggle site active ──
        if ($action === 'toggle_site') {
            $siteId = post_int('site_id');
            if ($siteId === null || $siteId <= 0) throw new RuntimeException('Identifiant invalide.');
            $before = bd_fetch_before($pdo, 'ref_sites', $siteId);
            if ($before === null) throw new RuntimeException('Site introuvable.');

            $newActive = ((int) $before['is_active'] === 1) ? 0 : 1;
            $upd = $pdo->prepare("UPDATE ref_sites SET is_active = ?, updated_by = ? WHERE id = ?");
            $upd->execute([$newActive, $me['username'], $siteId]);

            log_revision($pdo, $me, 'ref_sites', $siteId,
                ['is_active' => $before['is_active']],
                ['is_active' => $newActive],
                'normal', 'Réglages généraux: site ' . ($newActive ? 'réactivé' : 'désactivé'));

            $verb = $newActive ? 'réactivé' : 'désactivé';
            flash_set('ok', "Site « " . htmlspecialchars((string) $before['name']) . " » {$verb}.");
            redirect_to('/modules/reglages-generaux.php?sec=general');
        }

        // ── Action: create user ──
        if ($action === 'create_user') {
            $username    = post_str('username');
            $displayName = post_str('display_name');
            $role        = post_str('role') ?? '';
            $password    = $_POST['password'] ?? '';

            if ($username === null || strlen($username) < 2) {
                throw new RuntimeException('Identifiant utilisateur obligatoire (minimum 2 caractères).');
            }
            $username = substr(preg_replace('/[^a-z0-9._-]/i', '', $username), 0, 64);
            if (strlen($username) < 2) {
                throw new RuntimeException('Identifiant invalide — caractères autorisés : a-z 0-9 . _ -');
            }
            if ($displayName !== null) $displayName = substr($displayName, 0, 128);

            $allowedRoles = ['admin', 'operator', 'viewer', 'manager'];
            must_be_one_of('role', $role, $allowedRoles);

            if (!is_string($password) || strlen($password) < 8) {
                throw new RuntimeException('Mot de passe obligatoire (minimum 8 caractères).');
            }

            // Hash using the same algorithm as auth.php (Argon2id)
            $hash = password_hash($password, PASSWORD_ARGON2ID);

            $ins = $pdo->prepare(
                "INSERT INTO users (username, display_name, role, password_hash, is_active)
                 VALUES (?, ?, ?, ?, 1)"
            );
            $ins->execute([$username, $displayName ?? $username, $role, $hash]);
            $newUserId = (int) $pdo->lastInsertId();

            // Log revision — never log the hash itself
            log_revision($pdo, $me, 'users', $newUserId, null,
                ['username' => $username, 'display_name' => $displayName, 'role' => $role, 'is_active' => 1],
                'normal', 'Réglages généraux: création utilisateur');

            flash_set('ok', "Utilisateur « " . htmlspecialchars($username) . " » créé.");
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        throw new RuntimeException("Action inconnue : " . htmlspecialchars($action));

    } catch (Throwable $e) {
        $sec = post_str('sec') ?? 'general';
        flash_set('err', pdo_friendly_error($e, 'reglages-generaux'));
        redirect_to('/modules/reglages-generaux.php?sec=' . urlencode($sec));
    }
}

// ── GET — load data ───────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

$initialSec = in_array($_GET['sec'] ?? '', ['general', 'users'], true)
    ? $_GET['sec']
    : 'general';

// Load system_settings (section=general)
$systemSettings   = [];
$settingsByKey    = [];
$migrationApplied = false;
$loadErr          = null;

// Load sites
$sites       = [];
$sitesApplied = false;

// Load users
$users        = [];

// Edit-site context (from ?edit_site=ID)
$editSiteId   = isset($_GET['edit_site']) ? (int) $_GET['edit_site'] : null;
$editSiteRow  = null;

try {
    $pdo = maltytask_pdo();

    // system_settings
    try {
        $stmt = $pdo->query(
            "SELECT key_name, label_fr, description_fr,
                    value_text, value_num, unit_fr, default_text, default_num
               FROM system_settings
              WHERE section = 'general' AND is_active = 1
              ORDER BY id ASC"
        );
        $systemSettings   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($systemSettings as $s) {
            $settingsByKey[$s['key_name']] = $s;
        }
        $migrationApplied = !empty($systemSettings);
    } catch (Throwable $e2) {
        $loadErr = $e2->getMessage();
    }

    // ref_sites
    try {
        $stmt        = $pdo->query("SELECT * FROM ref_sites ORDER BY name ASC");
        $sites        = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sitesApplied = true;
        if ($editSiteId !== null) {
            foreach ($sites as $s) {
                if ((int) $s['id'] === $editSiteId) { $editSiteRow = $s; break; }
            }
        }
    } catch (Throwable) {
        $sitesApplied = false;
    }

    // users
    try {
        $stmt  = $pdo->query(
            "SELECT id, username, display_name, role, is_active, last_login_at
               FROM users
              ORDER BY role ASC, username ASC"
        );
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $users = [];
    }

} catch (Throwable $e) {
    $loadErr = $e->getMessage();
}

// Helper: effective value for a setting row
function eff_val(array $row): string {
    if ($row['value_text'] !== null && $row['value_text'] !== '') return $row['value_text'];
    if ($row['value_num']  !== null) return (string) $row['value_num'];
    if ($row['default_text'] !== null && $row['default_text'] !== '') return $row['default_text'];
    if ($row['default_num']  !== null) return (string) $row['default_num'];
    return '';
}

$csrf = csrf_token();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réglages généraux — Le Zeppelin · MaltyTask</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&family=DM+Sans:opsz,wght@9..40,300..600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?: time() ?>">
<link rel="stylesheet" href="/css/reglages-generaux.css?v=<?= @filemtime(__DIR__ . '/../css/reglages-generaux.css') ?: time() ?>">
</head>
<body class="rg-page" data-role="<?= htmlspecialchars($me['role'] ?? 'admin') ?>">

<div class="board"></div>
<span class="mark tl"></span><span class="mark tr"></span>
<span class="mark bl"></span><span class="mark br"></span>

<!-- CHROME -->
<div class="chrome">
  <div class="brandmark">La Nébuleuse · Le Zeppelin · <b>Réglages généraux</b></div>

  <div class="family-switcher">
    <a class="family-btn fam-sdm" href="/modules/salle-des-machines.php" title="Salle des Machines">
      <span class="fam-dot"></span>Machines
    </a>
    <a class="family-btn fam-sdc" href="/modules/salle-de-controle.php" title="Salle de contrôle">
      <span class="fam-dot"></span>Contrôle
    </a>
    <span class="family-btn fam-rg" aria-current="page">
      <span class="fam-dot"></span>Réglages
    </span>
  </div>

  <div class="rg-user-info">
    <span class="rg-role-pill">Admin</span>
    <span class="rg-username"><?= htmlspecialchars($me['username'] ?? '') ?></span>
  </div>
</div>

<!-- MAIN STAGE -->
<div class="rg-stage">

  <!-- LEFT NAV -->
  <nav class="nav-rail">
    <div class="nav-section-label">Réglages généraux</div>

    <div class="nav-item<?= $initialSec === 'general' ? ' active' : '' ?>" data-sec="general" onclick="switchSection('general')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.2"/>
          <circle cx="8" cy="8" r="1.8" fill="currentColor" opacity=".5"/>
          <path d="M8 2.5V1M8 15v-1.5M2.5 8H1M15 8h-1.5M4.1 4.1l-1-1M12.9 12.9l-1-1M4.1 11.9l-1 1M12.9 3.1l-1 1" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
      </span>
      Données générales
    </div>

    <div class="nav-item<?= $initialSec === 'users' ? ' active' : '' ?>" data-sec="users" onclick="switchSection('users')">
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="5.5" r="2.8" stroke="currentColor" stroke-width="1.2"/>
          <path d="M2 13c0-3 2.5-5 6-5s6 2 6 5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Utilisateurs
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;background:color-mix(in srgb, var(--bbt) 18%, transparent);color:var(--bbt);padding:2px 7px;border-radius:10px;"><?= count($users) ?></span>
    </div>
  </nav>

  <!-- CONTENT AREA -->
  <div class="content-area">

    <!-- ═══════════════ SECTION: Données générales ═══════════════ -->
    <div class="section-panel<?= $initialSec === 'general' ? ' active' : '' ?>" id="sec-general">
      <div class="section-scroll">

        <div class="sec-title">Données <em>générales</em></div>
        <div class="sec-subtitle">Paramètres système · formats · org · sites</div>

        <?php
        $flash = flash_pop();
        if ($flash !== null):
            $fc = $flash['type'] === 'ok' ? 'rg-flash--ok' : 'rg-flash--err';
            $fi = $flash['type'] === 'ok' ? '✓' : '⚠';
        ?>
        <div class="rg-flash <?= $fc ?>"><?= $fi ?> <?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif ?>

        <?php if (!$migrationApplied): ?>
        <div class="rg-migration-banner">
          <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex:0 0 18px;margin-top:1px"><path d="M9 3 L16 15 H2 Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M9 8v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="9" cy="13.5" r=".8" fill="currentColor"/></svg>
          <div>
            <strong>Migration 129 en attente.</strong>
            La table <code>system_settings</code> n'a pas encore été créée.
            Appliquer la migration <code>129_system_settings.sql</code> via
            <code>php scripts/migrate.php</code> pour activer l'édition des paramètres.
            <?php if ($loadErr): ?>
            <br><small style="opacity:.7"><?= htmlspecialchars($loadErr) ?></small>
            <?php endif ?>
          </div>
        </div>
        <?php endif ?>

        <!-- ── General settings form ── -->
        <?php if ($migrationApplied): ?>
        <div class="rg-card">
          <div class="rg-card-title">
            Paramètres d'organisation
            <span class="rg-card-label">section · general</span>
          </div>
          <form method="post" action="/modules/reglages-generaux.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_general">
            <input type="hidden" name="sec" value="general">

            <div class="rg-field-group">

              <?php
              $breweryRow = $settingsByKey['brewery_name'] ?? null;
              $breweryVal = $breweryRow !== null ? eff_val($breweryRow) : 'La Nébuleuse';
              ?>
              <div class="rg-field">
                <div>
                  <div class="rg-field-label">Nom de la brasserie</div>
                  <div class="rg-field-desc">Utilisé dans les en-têtes de rapports et documents générés.</div>
                </div>
                <input class="rg-input" type="text" name="brewery_name"
                       value="<?= htmlspecialchars($breweryVal) ?>"
                       maxlength="255" required>
              </div>

              <hr class="rg-divider">

              <?php
              $dfRow  = $settingsByKey['date_format'] ?? null;
              $dfVal  = $dfRow !== null ? eff_val($dfRow) : 'd/m/Y';
              ?>
              <div class="rg-field">
                <div>
                  <div class="rg-field-label">Format de date (affichage)</div>
                  <div class="rg-field-desc">
                    Format PHP pour l'affichage des dates dans l'interface.<br>
                    <code style="font-family:'JetBrains Mono',monospace;font-size:10px;background:rgba(60,40,20,0.08);padding:1px 5px;border-radius:3px;">d/m/Y</code> = jj/mm/aaaa (Suisse/Europe) ·
                    <code style="font-family:'JetBrains Mono',monospace;font-size:10px;background:rgba(60,40,20,0.08);padding:1px 5px;border-radius:3px;">Y-m-d</code> = ISO
                  </div>
                </div>
                <select class="rg-select" name="date_format">
                  <?php foreach (['d/m/Y' => 'jj/mm/aaaa (Suisse)', 'Y-m-d' => 'aaaa-mm-jj (ISO)', 'd.m.Y' => 'jj.mm.aaaa (DE/AT)', 'm/d/Y' => 'mm/jj/aaaa (US)'] as $v => $l): ?>
                  <option value="<?= htmlspecialchars($v) ?>"<?= $dfVal === $v ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                  <?php endforeach ?>
                </select>
              </div>

              <?php
              $dpRow = $settingsByKey['date_parse_dayfirst'] ?? null;
              $dpVal = $dpRow !== null ? (bool) round((float) eff_val($dpRow)) : true;
              ?>
              <div class="rg-field">
                <div>
                  <div class="rg-field-label">Saisie dates : jour-d'abord (jj/mm/aaaa)</div>
                  <div class="rg-field-desc">
                    Lorsqu'activé, les dates saisies par les opérateurs et parseurs sont interprétées
                    en format européen <strong>jj/mm/aaaa</strong>. Désactiver pour le format ISO aaaa-mm-jj.
                    Influe sur l'analyse des dates dans les imports et formulaires.
                  </div>
                </div>
                <div class="rg-toggle-wrap">
                  <label class="rg-toggle">
                    <input type="checkbox" name="date_parse_dayfirst" value="1"<?= $dpVal ? ' checked' : '' ?>>
                    <span class="rg-slider"></span>
                  </label>
                  <span class="rg-toggle-val" id="dpToggleLabel"><?= $dpVal ? 'Activé' : 'Désactivé' ?></span>
                </div>
              </div>

              <?php
              $tfRow = $settingsByKey['time_format'] ?? null;
              $tfVal = $tfRow !== null ? eff_val($tfRow) : 'H:i';
              ?>
              <div class="rg-field">
                <div>
                  <div class="rg-field-label">Format d'heure (affichage)</div>
                  <div class="rg-field-desc">
                    <code style="font-family:'JetBrains Mono',monospace;font-size:10px;background:rgba(60,40,20,0.08);padding:1px 5px;border-radius:3px;">H:i</code> = 14:30 (24h) ·
                    <code style="font-family:'JetBrains Mono',monospace;font-size:10px;background:rgba(60,40,20,0.08);padding:1px 5px;border-radius:3px;">h:i A</code> = 02:30 PM (12h)
                  </div>
                </div>
                <select class="rg-select" name="time_format">
                  <?php foreach (['H:i' => '14:30 (24h)', 'h:i A' => '02:30 PM (12h)'] as $v => $l): ?>
                  <option value="<?= htmlspecialchars($v) ?>"<?= $tfVal === $v ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                  <?php endforeach ?>
                </select>
              </div>

              <?php
              $langRow = $settingsByKey['language'] ?? null;
              $langVal = $langRow !== null ? eff_val($langRow) : 'fr';
              ?>
              <div class="rg-field">
                <div>
                  <div class="rg-field-label">Langue de l'interface</div>
                  <div class="rg-field-desc">Code ISO 639-1. L'internationalisation complète est prévue ; actuellement utilisé pour les rapports générés.</div>
                </div>
                <select class="rg-select" name="language">
                  <?php foreach (['fr' => 'Français', 'de' => 'Deutsch', 'en' => 'English'] as $v => $l): ?>
                  <option value="<?= htmlspecialchars($v) ?>"<?= $langVal === $v ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option>
                  <?php endforeach ?>
                </select>
              </div>

            </div><!-- /.rg-field-group -->

            <div class="rg-save-row">
              <button type="submit" class="rg-btn rg-btn-primary">Enregistrer les paramètres</button>
            </div>
          </form>
        </div><!-- /.rg-card settings -->
        <?php endif ?>

        <!-- ── Sites block ── -->
        <div class="rg-card">
          <div class="rg-card-title">
            Sites de production
            <span class="rg-card-label">ref_sites</span>
          </div>

          <?php if (!$sitesApplied): ?>
          <div class="rg-migration-banner">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex:0 0 18px"><path d="M9 3 L16 15 H2 Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M9 8v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="9" cy="13.5" r=".8" fill="currentColor"/></svg>
            <div>
              <strong>Migration 130 en attente.</strong>
              La table <code>ref_sites</code> n'existe pas encore. Appliquer la migration
              <code>130_ref_sites.sql</code> pour gérer les sites.
            </div>
          </div>
          <?php else: ?>

          <?php if (!empty($sites)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table">
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Adresse</th>
                  <th>Code postal / Ville</th>
                  <th>Pays</th>
                  <th>Statut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sites as $site): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $site['name']) ?></td>
                  <td><?= htmlspecialchars((string) ($site['address_line'] ?? '—')) ?></td>
                  <td><?= htmlspecialchars((string) ($site['postal_code'] ?? '')) ?><?= !empty($site['postal_code']) && !empty($site['city']) ? ' · ' : '' ?><?= htmlspecialchars((string) ($site['city'] ?? '—')) ?></td>
                  <td><?= htmlspecialchars((string) ($site['country'] ?? 'CH')) ?></td>
                  <td><?php if ((int) $site['is_active'] === 1): ?>
                    <span class="rg-pill-active">Actif</span>
                  <?php else: ?>
                    <span class="rg-pill-inactive">Inactif</span>
                  <?php endif ?></td>
                  <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <a href="?sec=general&edit_site=<?= (int) $site['id'] ?>" class="rg-action-link">Modifier</a>
                    <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="toggle_site">
                      <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                      <input type="hidden" name="sec" value="general">
                      <button type="submit" class="rg-action-link<?= (int) $site['is_active'] === 1 ? ' danger' : '' ?>">
                        <?= (int) $site['is_active'] === 1 ? 'Désactiver' : 'Réactiver' ?>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--ink-faint);text-transform:uppercase;padding:16px 0;">Aucun site enregistré.</p>
          <?php endif ?>

          <!-- Edit or Add site form -->
          <?php if ($editSiteRow !== null): ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Modifier le site · <?= htmlspecialchars((string) $editSiteRow['name']) ?></div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="edit_site">
              <input type="hidden" name="site_id" value="<?= (int) $editSiteRow['id'] ?>">
              <input type="hidden" name="sec" value="general">
              <div class="rg-form-grid">
                <div class="full">
                  <label class="rg-form-label">Nom du site *</label>
                  <input class="rg-input" type="text" name="name" maxlength="120" required
                         value="<?= htmlspecialchars((string) $editSiteRow['name']) ?>">
                </div>
                <div class="full">
                  <label class="rg-form-label">Adresse (rue + numéro)</label>
                  <input class="rg-input" type="text" name="address_line" maxlength="255"
                         value="<?= htmlspecialchars((string) ($editSiteRow['address_line'] ?? '')) ?>">
                </div>
                <div>
                  <label class="rg-form-label">Code postal</label>
                  <input class="rg-input" type="text" name="postal_code" maxlength="16"
                         value="<?= htmlspecialchars((string) ($editSiteRow['postal_code'] ?? '')) ?>">
                </div>
                <div>
                  <label class="rg-form-label">Ville</label>
                  <input class="rg-input" type="text" name="city" maxlength="120"
                         value="<?= htmlspecialchars((string) ($editSiteRow['city'] ?? '')) ?>">
                </div>
                <div>
                  <label class="rg-form-label">Pays</label>
                  <select class="rg-select" name="country">
                    <?php foreach (['CH' => 'Suisse', 'FR' => 'France', 'DE' => 'Allemagne', 'IT' => 'Italie', 'BE' => 'Belgique', 'LU' => 'Luxembourg', 'AT' => 'Autriche', 'NL' => 'Pays-Bas', 'US' => 'États-Unis', 'GB' => 'Royaume-Uni'] as $code => $label): ?>
                    <option value="<?= $code ?>"<?= ($editSiteRow['country'] ?? 'CH') === $code ? ' selected' : '' ?>><?= $code ?> — <?= htmlspecialchars($label) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div class="full">
                  <label class="rg-form-label">Notes (libre)</label>
                  <input class="rg-input" type="text" name="notes" maxlength="500"
                         value="<?= htmlspecialchars((string) ($editSiteRow['notes'] ?? '')) ?>">
                </div>
              </div>
              <div style="display:flex;gap:10px;">
                <button type="submit" class="rg-btn rg-btn-primary">Enregistrer</button>
                <a href="/modules/reglages-generaux.php?sec=general" class="rg-btn rg-btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Annuler</a>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Ajouter un site</div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="add_site">
              <input type="hidden" name="sec" value="general">
              <div class="rg-form-grid">
                <div class="full">
                  <label class="rg-form-label">Nom du site *</label>
                  <input class="rg-input" type="text" name="name" maxlength="120" required placeholder="ex: La Nébuleuse — Brasserie">
                </div>
                <div class="full">
                  <label class="rg-form-label">Adresse (rue + numéro)</label>
                  <input class="rg-input" type="text" name="address_line" maxlength="255" placeholder="Rue de l'Industrie 42">
                </div>
                <div>
                  <label class="rg-form-label">Code postal</label>
                  <input class="rg-input" type="text" name="postal_code" maxlength="16" placeholder="2000">
                </div>
                <div>
                  <label class="rg-form-label">Ville</label>
                  <input class="rg-input" type="text" name="city" maxlength="120" placeholder="Neuchâtel">
                </div>
                <div>
                  <label class="rg-form-label">Pays</label>
                  <select class="rg-select" name="country">
                    <?php foreach (['CH' => 'Suisse', 'FR' => 'France', 'DE' => 'Allemagne', 'IT' => 'Italie', 'BE' => 'Belgique', 'LU' => 'Luxembourg', 'AT' => 'Autriche', 'NL' => 'Pays-Bas', 'US' => 'États-Unis', 'GB' => 'Royaume-Uni'] as $code => $label): ?>
                    <option value="<?= $code ?>"><?= $code ?> — <?= htmlspecialchars($label) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div class="full">
                  <label class="rg-form-label">Notes (libre)</label>
                  <input class="rg-input" type="text" name="notes" maxlength="500">
                </div>
              </div>
              <button type="submit" class="rg-btn rg-btn-primary">Ajouter le site</button>
            </form>
          </div>
          <?php endif ?>

          <?php endif /* $sitesApplied */ ?>
        </div><!-- /.rg-card sites -->

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-general -->


    <!-- ═══════════════ SECTION: Utilisateurs ═══════════════ -->
    <div class="section-panel<?= $initialSec === 'users' ? ' active' : '' ?>" id="sec-users">
      <div class="section-scroll">

        <?php if ($initialSec === 'users'):
            $flash2 = flash_pop();
            if ($flash2 !== null):
                $fc2 = $flash2['type'] === 'ok' ? 'rg-flash--ok' : 'rg-flash--err';
                $fi2 = $flash2['type'] === 'ok' ? '✓' : '⚠';
        ?>
        <div class="rg-flash <?= $fc2 ?>"><?= $fi2 ?> <?= htmlspecialchars($flash2['msg']) ?></div>
        <?php endif; endif ?>

        <div class="sec-title">Utilisateurs <em>système</em></div>
        <div class="sec-subtitle">Comptes opérateur · gestion des accès</div>

        <!-- Users list -->
        <div class="rg-card">
          <div class="rg-card-title">
            Comptes actifs
            <span class="rg-card-label"><?= count($users) ?> utilisateur<?= count($users) !== 1 ? 's' : '' ?></span>
          </div>

          <?php if (!empty($users)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table">
              <thead>
                <tr>
                  <th>Identifiant</th>
                  <th>Nom d'affichage</th>
                  <th>Rôle</th>
                  <th>Statut</th>
                  <th>Dernière connexion</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?= htmlspecialchars((string) $u['username']) ?></td>
                  <td><?= htmlspecialchars((string) ($u['display_name'] ?? '—')) ?></td>
                  <td>
                    <span class="rg-role-badge <?= htmlspecialchars((string) ($u['role'] ?? 'operator')) ?>">
                      <?= htmlspecialchars((string) ($u['role'] ?? 'operator')) ?>
                    </span>
                  </td>
                  <td><?php if ((int) $u['is_active'] === 1): ?>
                    <span class="rg-pill-active">Actif</span>
                  <?php else: ?>
                    <span class="rg-pill-inactive">Inactif</span>
                  <?php endif ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink-mute);">
                    <?= $u['last_login_at'] ? htmlspecialchars((string) $u['last_login_at']) : '—' ?>
                  </td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--ink-faint);text-transform:uppercase;padding:16px 0;">Aucun utilisateur trouvé.</p>
          <?php endif ?>
        </div><!-- /.rg-card users list -->

        <!-- Create user form -->
        <div class="rg-create-user">
          <div class="rg-form-title">Créer un utilisateur</div>
          <form method="post" action="/modules/reglages-generaux.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="sec" value="users">
            <!-- Honeypot field for autocomplete isolation -->
            <input type="text" name="_hp" style="display:none;" tabindex="-1" aria-hidden="true">

            <div class="rg-form-grid">
              <div>
                <label class="rg-form-label">Identifiant (login) *</label>
                <input class="rg-input" type="text" name="username" maxlength="64" required
                       autocomplete="off"
                       placeholder="ex: jdupont"
                       pattern="[a-zA-Z0-9._-]+" title="Caractères autorisés : a-z 0-9 . _ -">
              </div>
              <div>
                <label class="rg-form-label">Nom d'affichage</label>
                <input class="rg-input" type="text" name="display_name" maxlength="128"
                       autocomplete="off"
                       placeholder="ex: Jean Dupont">
              </div>
              <div>
                <label class="rg-form-label">Rôle *</label>
                <select class="rg-select" name="role" required>
                  <option value="operator" selected>Opérateur</option>
                  <option value="manager">Manager</option>
                  <option value="viewer">Lecteur (viewer)</option>
                  <option value="admin">Administrateur</option>
                </select>
              </div>
              <div>
                <label class="rg-form-label">Mot de passe * (min. 8 caractères)</label>
                <input class="rg-input" type="password" name="password" minlength="8" required
                       autocomplete="new-password">
                <span class="rg-pw-hint">Haché Argon2id — jamais stocké en clair.</span>
              </div>
            </div>

            <div class="rg-save-row" style="margin-top:0;border-top:none;padding-top:0;">
              <button type="submit" class="rg-btn rg-btn-primary">Créer l'utilisateur</button>
            </div>
          </form>
        </div><!-- /.rg-create-user -->

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-users -->

  </div><!-- /.content-area -->
</div><!-- /.rg-stage -->

<script>
(function() {
  'use strict';

  // ── Section switching (nav + panel) ──────────────────────────────────────
  function switchSection(sec) {
    document.querySelectorAll('.nav-item').forEach(function(n) {
      n.classList.toggle('active', n.dataset.sec === sec);
    });
    document.querySelectorAll('.section-panel').forEach(function(p) {
      p.classList.toggle('active', p.id === 'sec-' + sec);
    });
    // Update URL without reload (history API)
    var url = new URL(window.location.href);
    url.searchParams.set('sec', sec);
    url.searchParams.delete('edit_site');
    history.replaceState(null, '', url.toString());
  }

  // Expose for onclick handlers in HTML
  window.switchSection = switchSection;

  // ── Toggle label update ───────────────────────────────────────────────────
  var dpCb    = document.querySelector('input[name="date_parse_dayfirst"]');
  var dpLabel = document.getElementById('dpToggleLabel');
  if (dpCb && dpLabel) {
    dpCb.addEventListener('change', function() {
      dpLabel.textContent = dpCb.checked ? 'Activé' : 'Désactivé';
    });
  }
})();
</script>

</body>
</html>
