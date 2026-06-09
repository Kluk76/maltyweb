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
require_once __DIR__ . '/../../app/services/invite_token.php';
require_once __DIR__ . '/../../app/services/mailer.php';
// app/db.php + app/services/remember_token.php are already included transitively via auth.php

require_login();
$me = current_user();

// Hard admin gate — 302 non-admins silently (no 403 info-leak about the page)
if (!is_admin($me)) {
    redirect_to('/');
}

/**
 * Sanitize a submitted username.
 * Allows Unicode letters (incl. accented), digits, space, dot, underscore,
 * straight apostrophe, curly apostrophe (U+2019), and hyphen.
 * Collapses runs of whitespace to a single space and trims.
 * Returns the sanitized string, or '' if it reduces to fewer than 2 chars (caller throws).
 */
function rg_sanitize_username(?string $raw): string {
    $s = (string) ($raw ?? '');
    // /u flag is required for \p{L} and \p{N} Unicode property escapes
    $s = preg_replace('/[^\p{L}\p{N} ._\'\x{2019}-]/u', '', $s);
    $s = trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    return mb_substr($s, 0, 64);
}

/**
 * Returns the first-name token of a username — mirrors auth_resolve_user()
 * normalization so collision detection and login resolution use identical logic.
 */
function rg_first_name_token(string $username): string {
    $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $username)));
    return explode(' ', $norm)[0];
}

/**
 * Returns other users whose first-name token OR email local-part equals the
 * first-name token of $username. Used to warn the admin that first-name login
 * will become ambiguous for all colliding accounts (auth_resolve_user fails
 * closed when ≥2 active users share a first-name token).
 *
 * @param PDO    $pdo
 * @param string $username      The sanitized username being created/updated.
 * @param int|null $excludeUserId  When updating, exclude this user's own id.
 * @return array  Rows with keys: id, username, is_active.
 */
function rg_first_name_collisions(PDO $pdo, string $username, ?int $excludeUserId = null): array {
    $tok = rg_first_name_token($username);
    if ($tok === '') {
        return [];
    }
    // Native prepared statements (EMULATE_PREPARES=false) do not allow the same
    // named placeholder twice in one query — use positional ? and pass $tok twice.
    if ($excludeUserId !== null) {
        $stmt = $pdo->prepare(
            "SELECT id, username, is_active
               FROM users
              WHERE (LOWER(SUBSTRING_INDEX(username, ' ', 1)) = ?
                 OR (email IS NOT NULL AND LOWER(SUBSTRING_INDEX(email, '@', 1)) = ?))
                AND id <> ?"
        );
        $stmt->execute([$tok, $tok, $excludeUserId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, username, is_active
               FROM users
              WHERE LOWER(SUBSTRING_INDEX(username, ' ', 1)) = ?
                 OR (email IS NOT NULL AND LOWER(SUBSTRING_INDEX(email, '@', 1)) = ?)"
        );
        $stmt->execute([$tok, $tok]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $siteType    = post_str('site_type') ?? 'warehouse';
            must_be_one_of('site_type', $siteType, ['production', 'warehouse', 'pos', 'consignment']);
            $holdsFgStock = isset($_POST['holds_fg_stock']) ? 1 : 0;
            $notes       = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 1000);

            $ins = $pdo->prepare(
                "INSERT INTO ref_sites (name, address_line, postal_code, city, country, site_type, holds_fg_stock, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$name, $addressLine, $postalCode, $city, $country, $siteType, $holdsFgStock, $notes, $me['username']]);
            $newId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ref_sites', $newId, null,
                ['name' => $name, 'address_line' => $addressLine, 'postal_code' => $postalCode, 'city' => $city, 'country' => $country, 'site_type' => $siteType, 'holds_fg_stock' => $holdsFgStock, 'notes' => $notes],
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
            $siteType    = post_str('site_type') ?? 'warehouse';
            must_be_one_of('site_type', $siteType, ['production', 'warehouse', 'pos', 'consignment']);
            $holdsFgStock = isset($_POST['holds_fg_stock']) ? 1 : 0;
            $notes = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 1000);

            $upd = $pdo->prepare(
                "UPDATE ref_sites SET name=?, address_line=?, postal_code=?, city=?, country=?, site_type=?, holds_fg_stock=?, notes=?, updated_by=?
                  WHERE id = ?"
            );
            $upd->execute([$name, $addressLine, $postalCode, $city, $country, $siteType, $holdsFgStock, $notes, $me['username'], $siteId]);

            $after = ['name' => $name, 'address_line' => $addressLine, 'postal_code' => $postalCode, 'city' => $city, 'country' => $country, 'site_type' => $siteType, 'holds_fg_stock' => $holdsFgStock, 'notes' => $notes];
            log_revision($pdo, $me, 'ref_sites', $siteId,
                ['name' => $before['name'], 'address_line' => $before['address_line'], 'postal_code' => $before['postal_code'], 'city' => $before['city'], 'country' => $before['country'], 'site_type' => $before['site_type'], 'holds_fg_stock' => $before['holds_fg_stock'], 'notes' => $before['notes']],
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

        // ── Action: add packaging client ──
        if ($action === 'add_pkg_client') {
            $name = post_str('name');
            if ($name === null || strlen(trim($name)) < 2) {
                throw new RuntimeException('Le nom du client est obligatoire (minimum 2 caractères).');
            }
            $name       = substr(trim($name), 0, 128);
            $notes      = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 255);
            $sortOrder  = post_int('sort_order') ?? 0;

            $ins = $pdo->prepare(
                "INSERT INTO ref_packaging_clients (name, notes, sort_order, updated_by)
                 VALUES (?, ?, ?, ?)"
            );
            $ins->execute([$name, $notes, $sortOrder, $me['username']]);
            $newId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ref_packaging_clients', $newId, null,
                ['name' => $name, 'notes' => $notes, 'sort_order' => $sortOrder, 'is_active' => 1],
                'normal', 'Réglages généraux: nouveau client packaging');

            flash_set('ok', "Client « " . htmlspecialchars($name) . " » ajouté.");
            redirect_to('/modules/reglages-generaux.php?sec=pkg_clients');
        }

        // ── Action: edit packaging client ──
        if ($action === 'edit_pkg_client') {
            $clientId = post_int('client_id');
            if ($clientId === null || $clientId <= 0) {
                throw new RuntimeException('Identifiant de client invalide.');
            }
            $before = bd_fetch_before($pdo, 'ref_packaging_clients', $clientId);
            if ($before === null) {
                throw new RuntimeException('Client introuvable.');
            }

            $name = post_str('name');
            if ($name === null || strlen(trim($name)) < 2) {
                throw new RuntimeException('Le nom du client est obligatoire (minimum 2 caractères).');
            }
            $name      = substr(trim($name), 0, 128);
            $notes     = post_str('notes');
            if ($notes !== null) $notes = substr($notes, 0, 255);
            $sortOrder = post_int('sort_order') ?? 0;

            $upd = $pdo->prepare(
                "UPDATE ref_packaging_clients SET name=?, notes=?, sort_order=?, updated_by=?
                  WHERE id = ?"
            );
            $upd->execute([$name, $notes, $sortOrder, $me['username'], $clientId]);

            log_revision($pdo, $me, 'ref_packaging_clients', $clientId,
                ['name' => $before['name'], 'notes' => $before['notes'], 'sort_order' => $before['sort_order']],
                ['name' => $name, 'notes' => $notes, 'sort_order' => $sortOrder],
                'normal', 'Réglages généraux: client packaging modifié');

            flash_set('ok', "Client « " . htmlspecialchars($name) . " » mis à jour.");
            redirect_to('/modules/reglages-generaux.php?sec=pkg_clients');
        }

        // ── Action: toggle packaging client active ──
        if ($action === 'toggle_pkg_client') {
            $clientId = post_int('client_id');
            if ($clientId === null || $clientId <= 0) throw new RuntimeException('Identifiant invalide.');
            $before = bd_fetch_before($pdo, 'ref_packaging_clients', $clientId);
            if ($before === null) throw new RuntimeException('Client introuvable.');

            $newActive = ((int) $before['is_active'] === 1) ? 0 : 1;
            $upd = $pdo->prepare("UPDATE ref_packaging_clients SET is_active = ?, updated_by = ? WHERE id = ?");
            $upd->execute([$newActive, $me['username'], $clientId]);

            log_revision($pdo, $me, 'ref_packaging_clients', $clientId,
                ['is_active' => $before['is_active']],
                ['is_active' => $newActive],
                'normal', 'Réglages généraux: client packaging ' . ($newActive ? 'réactivé' : 'désactivé'));

            $verb = $newActive ? 'réactivé' : 'désactivé';
            flash_set('ok', "Client « " . htmlspecialchars((string) $before['name']) . " » {$verb}.");
            redirect_to('/modules/reglages-generaux.php?sec=pkg_clients');
        }

        // ── Action: create user ──
        if ($action === 'create_user') {
            $username    = post_str('username');
            $displayName = post_str('display_name');
            $role        = post_str('role') ?? '';
            $password    = $_POST['password'] ?? '';
            $emailRaw    = post_str('email');
            $scopeRaw    = post_str('manager_scope');
            $presetRaw   = post_str('access_preset_id_fk');

            if ($username === null || $username === '') {
                throw new RuntimeException('Identifiant utilisateur obligatoire (minimum 2 caractères).');
            }
            $username = rg_sanitize_username($username);
            if (mb_strlen($username) < 2) {
                throw new RuntimeException('Identifiant invalide — minimum 2 caractères, lettres/chiffres/espaces/._-\' autorisés.');
            }
            if ($displayName !== null) $displayName = substr($displayName, 0, 128);

            $allowedRoles = ['admin', 'operator', 'viewer', 'manager'];
            must_be_one_of('role', $role, $allowedRoles);

            // Email: optional but must be valid when provided
            $email = null;
            if ($emailRaw !== null && $emailRaw !== '') {
                if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Adresse e-mail invalide.');
                }
                $email = substr($emailRaw, 0, 255);
            }

            // manager_scope: required when role=manager; forced NULL otherwise
            $managerScope = null;
            if ($role === 'manager') {
                must_be_one_of('manager_scope', $scopeRaw ?? '', ['production', 'logistics', 'all']);
                $managerScope = $scopeRaw;
            }

            // Password is optional — blank → create inactive with unusable hash + send invite
            $passwordProvided = is_string($password) && strlen($password) >= 8;
            if (!$passwordProvided && $password !== '' && $password !== null) {
                throw new RuntimeException('Mot de passe trop court (minimum 8 caractères), ou laisser vide pour envoyer une invitation.');
            }

            if ($passwordProvided) {
                $hash     = password_hash($password, PASSWORD_ARGON2ID);
                $isActive = 1;
            } else {
                // Unusable random hash — user activates via invite link
                $hash     = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
                $isActive = 0;
            }

            // access_preset_id_fk: validate against ref_access_presets if provided
            $createPresetId = null;
            if ($presetRaw !== null && $presetRaw !== '' && $presetRaw !== '0') {
                $presetCheck = $pdo->prepare("SELECT id FROM ref_access_presets WHERE id = ?");
                $presetCheck->execute([(int)$presetRaw]);
                if (!$presetCheck->fetch()) {
                    throw new RuntimeException('Preset d\'accès invalide.');
                }
                $createPresetId = (int)$presetRaw;
            }

            $ins = $pdo->prepare(
                "INSERT INTO users (username, email, display_name, role, manager_scope, password_hash, is_active, access_preset_id_fk)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$username, $email, $displayName ?? $username, $role, $managerScope, $hash, $isActive, $createPresetId]);
            $newUserId = (int) $pdo->lastInsertId();

            // Log revision — never log the hash itself
            log_revision($pdo, $me, 'users', $newUserId, null,
                ['username' => $username, 'email' => $email, 'display_name' => $displayName, 'role' => $role, 'manager_scope' => $managerScope, 'is_active' => $isActive, 'access_preset_id_fk' => $createPresetId],
                'normal', 'Réglages généraux: création utilisateur');

            // First-name collision warning (non-blocking): if another user shares
            // the same first-name token the login shortcut becomes ambiguous for both.
            $collisionSuffix = '';
            $collisions = rg_first_name_collisions($pdo, $username);
            if ($collisions !== []) {
                $tok   = rg_first_name_token($username);
                $names = implode(', ', array_map(
                    static fn($u) => htmlspecialchars((string) $u['username']),
                    $collisions
                ));
                $collisionSuffix = ' — ⚠ Attention : le prénom « ' . htmlspecialchars($tok)
                    . ' » est déjà utilisé par : ' . $names
                    . '. La connexion par prénom seul sera ambiguë — ces utilisateurs devront se connecter avec leur nom complet ou leur e-mail.';
            }

            if (!$passwordProvided) {
                // Generate invite link — try to email it, always show copy-able fallback
                $raw       = invite_create($pdo, $newUserId, (int) $me['id'], 'invite', 72);
                $inviteUrl = 'https://app.maltytask.ch/set-password.php?token=' . $raw;

                $mailSent = false;
                if ($email !== null) {
                    $tpl      = mail_account_template(
                        $displayName ?? $username,
                        $inviteUrl,
                        $me['display_name'] ?? $me['username'],
                        'invite'
                    );
                    $mailSent = send_mail($email, $tpl['subject'], $tpl['html'], $tpl['text']);
                }

                if ($email !== null && $mailSent) {
                    flash_set('ok', "Utilisateur « " . htmlspecialchars($username) . " » créé (inactif). Invitation envoyée à " . htmlspecialchars($email) . ". · Lien de secours : " . $inviteUrl . $collisionSuffix);
                } elseif ($email !== null && !$mailSent) {
                    flash_set('ok', "Utilisateur « " . htmlspecialchars($username) . " » créé (inactif). Envoi e-mail indisponible — transmettez ce lien : " . $inviteUrl . $collisionSuffix);
                } else {
                    flash_set('ok', "Utilisateur « " . htmlspecialchars($username) . " » créé (inactif). Lien d'invitation (valable 72 h) — copier et envoyer à l'utilisateur :\n" . $inviteUrl . $collisionSuffix);
                }
            } else {
                flash_set('ok', "Utilisateur « " . htmlspecialchars($username) . " » créé." . $collisionSuffix);
            }
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: update user ──
        if ($action === 'update_user') {
            $userId      = post_int('user_id');
            $usernameRaw = post_str('username');
            $displayName = post_str('display_name');
            $role        = post_str('role') ?? '';
            $scopeRaw    = post_str('manager_scope');
            $emailRaw    = post_str('email');
            $presetRaw   = post_str('access_preset_id_fk');

            if ($userId === null || $userId <= 0) {
                throw new RuntimeException('Identifiant utilisateur invalide.');
            }
            $before = bd_fetch_before($pdo, 'users', $userId);
            if ($before === null) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            // Username: sanitize + validate (parity with create_user)
            if ($usernameRaw === null || $usernameRaw === '') {
                throw new RuntimeException('Identifiant obligatoire (minimum 2 caractères).');
            }
            $username = rg_sanitize_username($usernameRaw);
            if (mb_strlen($username) < 2) {
                throw new RuntimeException('Identifiant invalide — minimum 2 caractères, lettres/chiffres/espaces/._-\' autorisés.');
            }

            // No-op: skip collision check when username unchanged
            $usernameChanged = ($username !== (string) $before['username']);
            if ($usernameChanged) {
                $ck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
                $ck->execute([$username, $userId]);
                if ($ck->fetch()) {
                    throw new RuntimeException('Cet identifiant est déjà utilisé par un autre compte.');
                }
            }

            $allowedRoles = ['admin', 'operator', 'viewer', 'manager'];
            must_be_one_of('role', $role, $allowedRoles);

            if ($displayName !== null) $displayName = substr($displayName, 0, 128);

            // Email: optional but must be valid when provided; NULL when empty
            $email = null;
            if ($emailRaw !== null && $emailRaw !== '') {
                if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Adresse e-mail invalide.');
                }
                $email = substr($emailRaw, 0, 255);
            }

            // Last-active-admin guard: cannot downgrade the last active admin
            if ((string) $before['role'] === 'admin' && (int) $before['is_active'] === 1 && $role !== 'admin') {
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
                if ($cnt <= 1) {
                    throw new RuntimeException("Impossible : c'est le dernier administrateur actif.");
                }
            }

            // manager_scope: required when role=manager; forced NULL otherwise
            $managerScope = null;
            if ($role === 'manager') {
                must_be_one_of('manager_scope', $scopeRaw ?? '', ['production', 'logistics', 'all']);
                $managerScope = $scopeRaw;
            }

            // access_preset_id_fk: validate against ref_access_presets if provided
            $presetId = null;
            if ($presetRaw !== null && $presetRaw !== '' && $presetRaw !== '0') {
                $presetCheck = $pdo->prepare("SELECT id FROM ref_access_presets WHERE id = ?");
                $presetCheck->execute([(int)$presetRaw]);
                if (!$presetCheck->fetch()) {
                    throw new RuntimeException('Preset d\'accès invalide.');
                }
                $presetId = (int)$presetRaw;
            }

            try {
                $upd = $pdo->prepare(
                    "UPDATE users SET username=?, display_name=?, role=?, manager_scope=?, email=?, access_preset_id_fk=? WHERE id=?"
                );
                $upd->execute([$username, $displayName ?? ($before['display_name'] ?? $before['username']), $role, $managerScope, $email, $presetId, $userId]);
            } catch (\PDOException $pdoEx) {
                if (str_contains($pdoEx->getMessage(), '1062')) {
                    throw new RuntimeException('Cet identifiant ou e-mail est déjà utilisé par un autre compte.');
                }
                throw $pdoEx;
            }

            log_revision($pdo, $me, 'users', $userId,
                ['username' => $before['username'], 'display_name' => $before['display_name'], 'role' => $before['role'], 'manager_scope' => $before['manager_scope'], 'email' => $before['email'] ?? null, 'access_preset_id_fk' => $before['access_preset_id_fk'] ?? null],
                ['username' => $username, 'display_name' => $displayName, 'role' => $role, 'manager_scope' => $managerScope, 'email' => $email, 'access_preset_id_fk' => $presetId],
                'normal', 'Réglages généraux: mise à jour utilisateur');

            // Self-rename: refresh session so topbar + actor stamps use the new name
            if ((int) $me['id'] === $userId) {
                $_SESSION['user']['username']     = $username;
                $_SESSION['user']['display_name'] = $displayName ?? ($before['display_name'] ?? $username);
            }

            $flashMsg = "Utilisateur « " . htmlspecialchars($username) . " » mis à jour.";
            if ($usernameChanged) {
                $flashMsg .= " Nouvel identifiant de connexion : " . htmlspecialchars($username) . ".";
            }

            // First-name collision warning (non-blocking): check after the update
            // so any username change is already stored; exclude the edited user.
            $collisions = rg_first_name_collisions($pdo, $username, $userId);
            if ($collisions !== []) {
                $tok   = rg_first_name_token($username);
                $names = implode(', ', array_map(
                    static fn($u) => htmlspecialchars((string) $u['username']),
                    $collisions
                ));
                $flashMsg .= ' — ⚠ Attention : le prénom « ' . htmlspecialchars($tok)
                    . ' » est déjà utilisé par : ' . $names
                    . '. La connexion par prénom seul sera ambiguë — ces utilisateurs devront se connecter avec leur nom complet ou leur e-mail.';
            }

            flash_set('ok', $flashMsg);
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: toggle user active ──
        if ($action === 'toggle_user_active') {
            $userId = post_int('user_id');
            if ($userId === null || $userId <= 0) {
                throw new RuntimeException('Identifiant utilisateur invalide.');
            }
            $before = bd_fetch_before($pdo, 'users', $userId);
            if ($before === null) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            $newActive = ((int) $before['is_active'] === 1) ? 0 : 1;
            $deactivating = ($newActive === 0);

            // Forbid self-deactivation
            if ($deactivating && (int) $me['id'] === $userId) {
                throw new RuntimeException("Impossible de désactiver son propre compte.");
            }

            // Last-active-admin guard
            if ($deactivating && (string) $before['role'] === 'admin') {
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
                if ($cnt <= 1) {
                    throw new RuntimeException("Impossible : c'est le dernier administrateur actif.");
                }
            }

            $upd = $pdo->prepare("UPDATE users SET is_active=? WHERE id=?");
            $upd->execute([$newActive, $userId]);

            // On deactivation, revoke all remember-me tokens immediately
            if ($deactivating) {
                rt_revoke_all($userId, $pdo);
            }

            log_revision($pdo, $me, 'users', $userId,
                ['is_active' => (int) $before['is_active']],
                ['is_active' => $newActive],
                'normal', 'Réglages généraux: compte ' . ($deactivating ? 'désactivé' : 'réactivé'));

            $verb = $deactivating ? 'désactivé' : 'réactivé';
            flash_set('ok', "Compte « " . htmlspecialchars((string) $before['username']) . " » {$verb}.");
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: reset user password (admin sends reset link) ──
        if ($action === 'reset_user_password') {
            $userId = post_int('user_id');
            if ($userId === null || $userId <= 0) {
                throw new RuntimeException('Identifiant utilisateur invalide.');
            }
            $targetUser = bd_fetch_before($pdo, 'users', $userId);
            if ($targetUser === null) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            $raw      = invite_create($pdo, $userId, (int) $me['id'], 'reset', 72);
            $resetUrl = 'https://app.maltytask.ch/set-password.php?token=' . $raw;

            log_revision($pdo, $me, 'users', $userId,
                null,
                ['event' => 'password_reset_link_generated'],
                'normal', 'Réglages généraux: lien de réinitialisation mot de passe généré');

            $targetEmail = ($targetUser['email'] ?? '') !== '' ? (string) $targetUser['email'] : null;
            $mailSent    = false;
            if ($targetEmail !== null) {
                $tpl      = mail_account_template(
                    $targetUser['display_name'] ?? $targetUser['username'],
                    $resetUrl,
                    $me['display_name'] ?? $me['username'],
                    'reset'
                );
                $mailSent = send_mail($targetEmail, $tpl['subject'], $tpl['html'], $tpl['text']);
            }

            if ($targetEmail !== null && $mailSent) {
                flash_set('ok', "Lien de réinitialisation envoyé à " . htmlspecialchars($targetEmail) . " · Lien de secours : " . $resetUrl);
            } elseif ($targetEmail !== null && !$mailSent) {
                flash_set('ok', "Envoi e-mail indisponible — transmettez ce lien à « " . htmlspecialchars((string) $targetUser['username']) . " » : " . $resetUrl);
            } else {
                flash_set('ok', "Lien de réinitialisation pour « " . htmlspecialchars((string) $targetUser['username']) . " » (valable 72 h) — copier et envoyer à l'utilisateur :\n" . $resetUrl);
            }
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: resend invite (send invite link for inactive/uninvited users) ──
        if ($action === 'invite_user') {
            $userId = post_int('user_id');
            if ($userId === null || $userId <= 0) {
                throw new RuntimeException('Identifiant utilisateur invalide.');
            }
            $targetUser = bd_fetch_before($pdo, 'users', $userId);
            if ($targetUser === null) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            $raw       = invite_create($pdo, $userId, (int) $me['id'], 'invite', 72);
            $inviteUrl = 'https://app.maltytask.ch/set-password.php?token=' . $raw;

            log_revision($pdo, $me, 'users', $userId,
                null,
                ['event' => 'invite_link_generated'],
                'normal', 'Réglages généraux: lien d\'invitation généré');

            $targetEmail = ($targetUser['email'] ?? '') !== '' ? (string) $targetUser['email'] : null;
            $mailSent    = false;
            if ($targetEmail !== null) {
                $tpl      = mail_account_template(
                    $targetUser['display_name'] ?? $targetUser['username'],
                    $inviteUrl,
                    $me['display_name'] ?? $me['username'],
                    'invite'
                );
                $mailSent = send_mail($targetEmail, $tpl['subject'], $tpl['html'], $tpl['text']);
            }

            if ($targetEmail !== null && $mailSent) {
                flash_set('ok', "Invitation envoyée à " . htmlspecialchars($targetEmail) . " · Lien de secours : " . $inviteUrl);
            } elseif ($targetEmail !== null && !$mailSent) {
                flash_set('ok', "Envoi e-mail indisponible — transmettez ce lien à « " . htmlspecialchars((string) $targetUser['username']) . " » : " . $inviteUrl);
            } else {
                flash_set('ok', "Lien d'invitation pour « " . htmlspecialchars((string) $targetUser['username']) . " » (valable 72 h) — copier et envoyer à l'utilisateur :\n" . $inviteUrl);
            }
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: update user access (per-page overrides) ──
        if ($action === 'update_user_access') {
            $userId = post_int('user_id');
            if ($userId === null || $userId <= 0) {
                throw new RuntimeException('Identifiant utilisateur invalide.');
            }
            $targetUser = bd_fetch_before($pdo, 'users', $userId);
            if ($targetUser === null) {
                throw new RuntimeException('Utilisateur introuvable.');
            }

            // Build canonical allowed page-id set from ref_pages (mig-239 lesson)
            $pageStmt = $pdo->query("SELECT id, page_key FROM ref_pages");
            $allowedPages = [];
            foreach ($pageStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $allowedPages[(int)$pr['id']] = $pr['page_key'];
            }

            // The posted page overrides: array indexed by page_id, value 'inherit'|'allow'|'deny'
            $posted = $_POST['page_access'] ?? [];

            foreach ($allowedPages as $pageId => $pageKey) {
                $val = $posted[$pageId] ?? 'inherit';
                if (!in_array($val, ['inherit', 'allow', 'deny'], true)) {
                    $val = 'inherit';
                }

                if ($val === 'inherit') {
                    // DELETE the override row if it exists
                    $del = $pdo->prepare("DELETE FROM user_page_access WHERE user_id_fk = ? AND page_id_fk = ?");
                    $del->execute([$userId, $pageId]);
                } else {
                    $granted = ($val === 'allow') ? 1 : 0;
                    // Upsert
                    $ups = $pdo->prepare(
                        "INSERT INTO user_page_access (user_id_fk, page_id_fk, granted, set_by_fk)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE granted = VALUES(granted), set_by_fk = VALUES(set_by_fk)"
                    );
                    $ups->execute([$userId, $pageId, $granted, (int)$me['id']]);
                }
            }

            log_revision($pdo, $me, 'user_page_access', $userId,
                ['user_id_fk' => $userId, 'event' => 'before_bulk_update'],
                ['user_id_fk' => $userId, 'page_count' => count($allowedPages), 'event' => 'bulk_update'],
                'normal', 'Réglages généraux: mise à jour accès pages utilisateur');

            flash_set('ok', "Accès pages mis à jour pour « " . htmlspecialchars((string)$targetUser['username']) . " ».");
            redirect_to('/modules/reglages-generaux.php?sec=users');
        }

        // ── Action: update page registry row ──
        if ($action === 'update_page') {
            $pageId = post_int('page_id');
            if ($pageId === null || $pageId <= 0) throw new RuntimeException('Identifiant page invalide.');
            $before = bd_fetch_before($pdo, 'ref_pages', $pageId);
            if ($before === null) throw new RuntimeException('Page introuvable.');

            $label   = post_str('label');
            $href    = post_str('href');
            $minRole = post_str('min_role') ?? '';
            $domain  = post_str('domain');
            $sort    = post_int('sort') ?? 0;

            if ($label === null || strlen(trim($label)) < 1) throw new RuntimeException('Label obligatoire.');
            $label = substr(trim($label), 0, 64);
            if ($href === null || strlen(trim($href)) < 1) throw new RuntimeException('URL obligatoire.');
            $href = substr(trim($href), 0, 128);
            must_be_one_of('min_role', $minRole, ['viewer', 'operator', 'manager', 'admin']);
            if ($domain !== null && $domain !== '') {
                must_be_one_of('domain', $domain, ['production', 'logistics', 'admin', 'general']);
            } else {
                $domain = null;
            }

            $upd = $pdo->prepare(
                "UPDATE ref_pages SET label=?, href=?, min_role=?, domain=?, sort=? WHERE id=?"
            );
            $upd->execute([$label, $href, $minRole, $domain, $sort, $pageId]);

            log_revision($pdo, $me, 'ref_pages', $pageId,
                ['label' => $before['label'], 'href' => $before['href'], 'min_role' => $before['min_role'], 'domain' => $before['domain'], 'sort' => $before['sort']],
                ['label' => $label, 'href' => $href, 'min_role' => $minRole, 'domain' => $domain, 'sort' => $sort],
                'normal', 'Réglages généraux: page modifiée');

            flash_set('ok', "Page « " . htmlspecialchars($label) . " » mise à jour.");
            redirect_to('/modules/reglages-generaux.php?sec=access');
        }

        // ── Action: create page registry row ──
        if ($action === 'create_page') {
            $pageKey = post_str('page_key');
            $label   = post_str('label');
            $href    = post_str('href');
            $minRole = post_str('min_role') ?? 'viewer';
            $domain  = post_str('domain');
            $sort    = post_int('sort') ?? 0;

            if ($pageKey === null || !preg_match('/^[a-z0-9-]+$/', $pageKey)) {
                throw new RuntimeException('page_key invalide — caractères autorisés : a-z 0-9 -');
            }
            $pageKey = substr($pageKey, 0, 48);
            if ($label === null || strlen(trim($label)) < 1) throw new RuntimeException('Label obligatoire.');
            $label = substr(trim($label), 0, 64);
            if ($href === null || strlen(trim($href)) < 1) throw new RuntimeException('URL obligatoire.');
            $href = substr(trim($href), 0, 128);
            must_be_one_of('min_role', $minRole, ['viewer', 'operator', 'manager', 'admin']);
            if ($domain !== null && $domain !== '') {
                must_be_one_of('domain', $domain, ['production', 'logistics', 'admin', 'general']);
            } else {
                $domain = null;
            }

            $ins = $pdo->prepare(
                "INSERT INTO ref_pages (page_key, label, href, min_role, domain, sort, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)"
            );
            $ins->execute([$pageKey, $label, $href, $minRole, $domain, $sort]);
            $newId = (int) $pdo->lastInsertId();

            log_revision($pdo, $me, 'ref_pages', $newId, null,
                ['page_key' => $pageKey, 'label' => $label, 'href' => $href, 'min_role' => $minRole, 'domain' => $domain, 'sort' => $sort, 'is_active' => 1],
                'normal', 'Réglages généraux: nouvelle page créée');

            flash_set('ok', "Page « " . htmlspecialchars($label) . " » (key: " . htmlspecialchars($pageKey) . ") créée.");
            redirect_to('/modules/reglages-generaux.php?sec=access');
        }

        // ── Action: toggle page active ──
        if ($action === 'toggle_page_active') {
            $pageId = post_int('page_id');
            if ($pageId === null || $pageId <= 0) throw new RuntimeException('Identifiant invalide.');
            $before = bd_fetch_before($pdo, 'ref_pages', $pageId);
            if ($before === null) throw new RuntimeException('Page introuvable.');

            $newActive = ((int)$before['is_active'] === 1) ? 0 : 1;
            $upd = $pdo->prepare("UPDATE ref_pages SET is_active = ? WHERE id = ?");
            $upd->execute([$newActive, $pageId]);

            log_revision($pdo, $me, 'ref_pages', $pageId,
                ['is_active' => (int)$before['is_active']],
                ['is_active' => $newActive],
                'normal', 'Réglages généraux: page ' . ($newActive ? 'activée' : 'désactivée'));

            $verb = $newActive ? 'activée' : 'désactivée';
            flash_set('ok', "Page « " . htmlspecialchars((string)$before['label']) . " » {$verb}.");
            redirect_to('/modules/reglages-generaux.php?sec=access');
        }

        // ── Action: assign default delivery site to a customer ──
        if ($action === 'assign_delivery_site') {
            $customerId = post_int('customer_id');
            $siteId     = post_int('site_id');

            if ($customerId === null || $customerId <= 0) {
                throw new RuntimeException('Client invalide.');
            }
            if ($siteId === null || $siteId <= 0) {
                throw new RuntimeException('Site invalide.');
            }

            // Validate customer exists
            $ckCust = $pdo->prepare("SELECT id, name FROM ref_customers WHERE id = ? AND is_active = 1");
            $ckCust->execute([$customerId]);
            $custRow = $ckCust->fetch(PDO::FETCH_ASSOC);
            if ($custRow === false) {
                throw new RuntimeException('Client introuvable ou inactif.');
            }

            // Validate site is in the holds_fg_stock whitelist
            $ckSite = $pdo->prepare("SELECT id, name FROM ref_sites WHERE id = ? AND holds_fg_stock = 1 AND is_active = 1");
            $ckSite->execute([$siteId]);
            $siteRow = $ckSite->fetch(PDO::FETCH_ASSOC);
            if ($siteRow === false) {
                throw new RuntimeException('Site invalide — doit être un site actif avec stock PF.');
            }

            $before = bd_fetch_before($pdo, 'ref_customers', $customerId);

            $upd = $pdo->prepare(
                "UPDATE ref_customers SET default_delivery_site_id_fk = ?, updated_by = ? WHERE id = ?"
            );
            $upd->execute([$siteId, $me['username'], $customerId]);

            log_revision($pdo, $me, 'ref_customers', $customerId,
                ['default_delivery_site_id_fk' => $before['default_delivery_site_id_fk'] ?? null],
                ['default_delivery_site_id_fk' => $siteId],
                'normal', 'Réglages généraux: site de livraison par défaut assigné');

            flash_set('ok', "Client « " . htmlspecialchars((string) $custRow['name']) . " » → site « " . htmlspecialchars((string) $siteRow['name']) . " ».");
            redirect_to('/modules/reglages-generaux.php?sec=delivery_sites');
        }

        // ── Action: remove default delivery site from a customer ──
        if ($action === 'remove_delivery_site') {
            $customerId = post_int('customer_id');
            if ($customerId === null || $customerId <= 0) {
                throw new RuntimeException('Client invalide.');
            }

            $before = bd_fetch_before($pdo, 'ref_customers', $customerId);
            if ($before === null) {
                throw new RuntimeException('Client introuvable.');
            }
            if ($before['default_delivery_site_id_fk'] === null) {
                throw new RuntimeException('Ce client n\'a pas de site de livraison par défaut.');
            }

            $upd = $pdo->prepare(
                "UPDATE ref_customers SET default_delivery_site_id_fk = NULL, updated_by = ? WHERE id = ?"
            );
            $upd->execute([$me['username'], $customerId]);

            log_revision($pdo, $me, 'ref_customers', $customerId,
                ['default_delivery_site_id_fk' => $before['default_delivery_site_id_fk']],
                ['default_delivery_site_id_fk' => null],
                'normal', 'Réglages généraux: site de livraison par défaut retiré');

            flash_set('ok', "Site de livraison par défaut retiré pour « " . htmlspecialchars((string) $before['name']) . " ».");
            redirect_to('/modules/reglages-generaux.php?sec=delivery_sites');
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

$initialSec = in_array($_GET['sec'] ?? '', ['general', 'pkg_clients', 'delivery_sites', 'users', 'access'], true)
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

// Edit-packaging-client context (from ?edit_pkg_client=ID)
$editPkgClientId  = isset($_GET['edit_pkg_client']) ? (int) $_GET['edit_pkg_client'] : null;
$editPkgClientRow = null;

// Packaging clients
$packagingClients        = [];
$packagingClientsApplied = false;

// Delivery site assignments
$deliverySiteCustomers = [];   // ref_customers that have default_delivery_site_id_fk set
$deliverySiteOptions   = [];   // ref_sites WHERE holds_fg_stock=1 AND is_active=1
$allCustomersForSelect = [];   // ref_customers (id, name) for the assign datalist

// Access control data
$presets           = [];
$refPages          = [];
$userPageAccessMap = [];
$presetPageMap     = [];
$editPageId        = isset($_GET['edit_page']) ? (int)$_GET['edit_page'] : null;
$editPageRow       = null;

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

    // ref_packaging_clients
    try {
        $stmt = $pdo->query(
            "SELECT id, name, is_active, sort_order, notes
               FROM ref_packaging_clients
              ORDER BY sort_order ASC, name ASC"
        );
        $packagingClients        = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $packagingClientsApplied = true;
        if ($editPkgClientId !== null) {
            foreach ($packagingClients as $pc) {
                if ((int) $pc['id'] === $editPkgClientId) { $editPkgClientRow = $pc; break; }
            }
        }
    } catch (Throwable) {
        $packagingClientsApplied = false;
    }

    // delivery site assignments: customers that have a default_delivery_site_id_fk
    try {
        $stmt = $pdo->query(
            "SELECT rc.id, rc.name AS customer_name, rs.id AS site_id, rs.name AS site_name
               FROM ref_customers rc
               JOIN ref_sites rs ON rs.id = rc.default_delivery_site_id_fk
              WHERE rc.default_delivery_site_id_fk IS NOT NULL
              ORDER BY rc.name ASC"
        );
        $deliverySiteCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $deliverySiteCustomers = [];
    }

    // ref_sites eligible for default_delivery_site_id_fk
    try {
        $stmt = $pdo->query(
            "SELECT id, name FROM ref_sites
              WHERE holds_fg_stock = 1 AND is_active = 1
              ORDER BY name ASC"
        );
        $deliverySiteOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $deliverySiteOptions = [];
    }

    // all active customers for the assign datalist
    try {
        $stmt = $pdo->query(
            "SELECT id, name FROM ref_customers
              WHERE is_active = 1
              ORDER BY name ASC"
        );
        $allCustomersForSelect = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $allCustomersForSelect = [];
    }

    // users
    try {
        $stmt  = $pdo->query(
            "SELECT id, username, email, display_name, role, manager_scope, is_active, last_login_at, access_preset_id_fk
               FROM users
              ORDER BY role ASC, username ASC"
        );
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $users = [];
    }

    // ref_access_presets
    try {
        $stmt    = $pdo->query("SELECT id, preset_key, label, description FROM ref_access_presets ORDER BY id ASC");
        $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $presets = [];
    }

    // ref_pages (for access matrix + page registry)
    try {
        $stmt     = $pdo->query(
            "SELECT id, page_key, label, icon, href, min_role, domain, is_active, sort
               FROM ref_pages ORDER BY sort ASC, page_key ASC"
        );
        $refPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $refPages = [];
    }

    // user_page_access — all rows, keyed by user_id_fk then page_id_fk
    // Also resolve to page_key for display
    try {
        $stmt = $pdo->query(
            "SELECT upa.user_id_fk, upa.page_id_fk, upa.granted, rp.page_key
               FROM user_page_access upa
               JOIN ref_pages rp ON rp.id = upa.page_id_fk"
        );
        $userPageAccessRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Index: [user_id][page_id] = 0|1
        $userPageAccessMap = [];
        foreach ($userPageAccessRaw as $r) {
            $userPageAccessMap[(int)$r['user_id_fk']][(int)$r['page_id_fk']] = (int)$r['granted'];
        }
    } catch (Throwable) {
        $userPageAccessMap = [];
    }

    // ref_access_preset_pages — for showing effective access
    // [preset_id][page_key] = true
    try {
        $stmt = $pdo->query(
            "SELECT rapp.preset_id_fk, rp.page_key, rp.id AS page_id
               FROM ref_access_preset_pages rapp
               JOIN ref_pages rp ON rp.id = rapp.page_id_fk"
        );
        $presetPageMap = []; // [preset_id][page_key] = true
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $presetPageMap[(int)$r['preset_id_fk']][$r['page_key']] = true;
        }
    } catch (Throwable) {
        $presetPageMap = [];
    }

    // edit_page context (from ?edit_page=ID)
    if ($editPageId !== null) {
        foreach ($refPages as $rp) {
            if ((int)$rp['id'] === $editPageId) { $editPageRow = $rp; break; }
        }
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
$_breweryId = brewery_identity();
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
<!-- Session keepalive: page does not include topbar.php so form-resilience is wired directly -->
<script defer src="/js/form-resilience.js?v=<?= @filemtime(__DIR__ . '/../js/form-resilience.js') ?: time() ?>"></script>
</head>
<body class="rg-page" data-role="<?= htmlspecialchars($me['role'] ?? 'admin') ?>">

<div class="board"></div>
<span class="mark tl"></span><span class="mark tr"></span>
<span class="mark bl"></span><span class="mark br"></span>

<!-- CHROME -->
<div class="chrome">
  <div class="brandmark"><?= htmlspecialchars($_breweryId['name']) ?> · Le Zeppelin · <b>Réglages généraux</b></div>

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
<div class="rg-stage" id="main-content">

  <!-- LEFT NAV -->
  <nav class="nav-rail">
    <div class="nav-section-label">Réglages généraux</div>

    <button type="button" class="nav-item<?= $initialSec === 'general' ? ' active' : '' ?>" data-sec="general" onclick="switchSection('general')"<?= $initialSec === 'general' ? ' aria-current="true"' : '' ?>>
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.2"/>
          <circle cx="8" cy="8" r="1.8" fill="currentColor" opacity=".5"/>
          <path d="M8 2.5V1M8 15v-1.5M2.5 8H1M15 8h-1.5M4.1 4.1l-1-1M12.9 12.9l-1-1M4.1 11.9l-1 1M12.9 3.1l-1 1" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
        </svg>
      </span>
      Données générales
    </button>

    <button type="button" class="nav-item<?= $initialSec === 'pkg_clients' ? ' active' : '' ?>" data-sec="pkg_clients" onclick="switchSection('pkg_clients')"<?= $initialSec === 'pkg_clients' ? ' aria-current="true"' : '' ?>>
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="1.5" y="4.5" width="13" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5 4.5V3a3 3 0 0 1 6 0v1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <circle cx="8" cy="9" r="1.5" fill="currentColor" opacity=".55"/>
        </svg>
      </span>
      Clients packaging
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;background:color-mix(in srgb, var(--hop) 18%, transparent);color:var(--hop);padding:2px 7px;border-radius:10px;"><?= count($packagingClients) ?></span>
    </button>

    <button type="button" class="nav-item<?= $initialSec === 'delivery_sites' ? ' active' : '' ?>" data-sec="delivery_sites" onclick="switchSection('delivery_sites')"<?= $initialSec === 'delivery_sites' ? ' aria-current="true"' : '' ?>>
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <path d="M8 1.5C5.5 1.5 3.5 3.5 3.5 6c0 3.5 4.5 8.5 4.5 8.5s4.5-5 4.5-8.5c0-2.5-2-4.5-4.5-4.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
          <circle cx="8" cy="6" r="1.5" fill="currentColor" opacity=".55"/>
        </svg>
      </span>
      Sites de livraison clients
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;background:color-mix(in srgb, var(--hop) 18%, transparent);color:var(--hop);padding:2px 7px;border-radius:10px;"><?= count($deliverySiteCustomers) ?></span>
    </button>

    <button type="button" class="nav-item<?= $initialSec === 'users' ? ' active' : '' ?>" data-sec="users" onclick="switchSection('users')"<?= $initialSec === 'users' ? ' aria-current="true"' : '' ?>>
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="5.5" r="2.8" stroke="currentColor" stroke-width="1.2"/>
          <path d="M2 13c0-3 2.5-5 6-5s6 2 6 5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
      </span>
      Utilisateurs
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;background:color-mix(in srgb, var(--bbt) 18%, transparent);color:var(--bbt);padding:2px 7px;border-radius:10px;"><?= count($users) ?></span>
    </button>

    <button type="button" class="nav-item<?= $initialSec === 'access' ? ' active' : '' ?>" data-sec="access" onclick="switchSection('access')"<?= $initialSec === 'access' ? ' aria-current="true"' : '' ?>>
      <span class="nav-icon">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
          <rect x="3" y="7" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
          <path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
          <circle cx="8" cy="10.5" r="1.2" fill="currentColor" opacity=".55"/>
        </svg>
      </span>
      Pages &amp; Accès
      <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;letter-spacing:.06em;background:color-mix(in srgb, var(--oak) 18%, transparent);color:var(--oak);padding:2px 7px;border-radius:10px;"><?= count($refPages) ?></span>
    </button>
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
          <p class="rg-field-desc">ℹ️ Le conditionnement alimente le stock PF des sites de type « Production ».</p>

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
                <div>
                  <label class="rg-form-label">Type de site</label>
                  <select class="rg-select" name="site_type">
                    <?php foreach (['production' => 'Production', 'warehouse' => 'Entrepôt', 'pos' => 'Point de vente', 'consignment' => 'Consignation'] as $val => $lbl): ?>
                    <option value="<?= $val ?>"<?= ($editSiteRow['site_type'] ?? 'warehouse') === $val ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Stock PF</label>
                  <div class="rg-toggle-wrap">
                    <label class="rg-toggle">
                      <input type="checkbox" name="holds_fg_stock" value="1"<?= (int) ($editSiteRow['holds_fg_stock'] ?? 0) === 1 ? ' checked' : '' ?>>
                      <span class="rg-slider"></span>
                    </label>
                    <span class="rg-toggle-val">Détient du stock PF</span>
                  </div>
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
                <div>
                  <label class="rg-form-label">Type de site</label>
                  <select class="rg-select" name="site_type">
                    <?php foreach (['production' => 'Production', 'warehouse' => 'Entrepôt', 'pos' => 'Point de vente', 'consignment' => 'Consignation'] as $val => $lbl): ?>
                    <option value="<?= $val ?>"<?= $val === 'warehouse' ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Stock PF</label>
                  <div class="rg-toggle-wrap">
                    <label class="rg-toggle">
                      <input type="checkbox" name="holds_fg_stock" value="1" checked>
                      <span class="rg-slider"></span>
                    </label>
                    <span class="rg-toggle-val">Détient du stock PF</span>
                  </div>
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


    <!-- ═══════════════ SECTION: Clients packaging ═══════════════ -->
    <div class="section-panel<?= $initialSec === 'pkg_clients' ? ' active' : '' ?>" id="sec-pkg_clients">
      <div class="section-scroll">

        <div class="sec-title">Clients <em>packaging</em></div>
        <div class="sec-subtitle">Livraison cuve de service · venues · festivals</div>

        <?php
        if ($initialSec === 'pkg_clients'):
            $flashPc = flash_pop();
            if ($flashPc !== null):
                $fcPc = $flashPc['type'] === 'ok' ? 'rg-flash--ok' : 'rg-flash--err';
                $fiPc = $flashPc['type'] === 'ok' ? '✓' : '⚠';
        ?>
        <div class="rg-flash <?= $fcPc ?>"><?= $fiPc ?> <?= htmlspecialchars($flashPc['msg']) ?></div>
        <?php endif; endif ?>

        <?php if (!$packagingClientsApplied): ?>
        <div class="rg-migration-banner">
          <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="flex:0 0 18px;margin-top:1px"><path d="M9 3 L16 15 H2 Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M9 8v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="9" cy="13.5" r=".8" fill="currentColor"/></svg>
          <div>
            <strong>Migration 237 en attente.</strong>
            La table <code>ref_packaging_clients</code> n'existe pas encore.
            Appliquer la migration <code>237_packaging_clients_normalise.sql</code> via
            <code>php scripts/migrate.php</code> pour gérer les clients packaging.
          </div>
        </div>
        <?php else: ?>

        <div class="rg-card">
          <div class="rg-card-title">
            Clients packaging (livraison cuve)
            <span class="rg-card-label">ref_packaging_clients</span>
          </div>

          <?php if (!empty($packagingClients)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table">
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Ordre</th>
                  <th>Notes</th>
                  <th>Statut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($packagingClients as $pc): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $pc['name']) ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink-mute);"><?= (int) $pc['sort_order'] ?></td>
                  <td><?= htmlspecialchars((string) ($pc['notes'] ?? '—')) ?></td>
                  <td><?php if ((int) $pc['is_active'] === 1): ?>
                    <span class="rg-pill-active">Actif</span>
                  <?php else: ?>
                    <span class="rg-pill-inactive">Inactif</span>
                  <?php endif ?></td>
                  <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <a href="?sec=pkg_clients&edit_pkg_client=<?= (int) $pc['id'] ?>" class="rg-action-link">Modifier</a>
                    <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="toggle_pkg_client">
                      <input type="hidden" name="client_id" value="<?= (int) $pc['id'] ?>">
                      <input type="hidden" name="sec" value="pkg_clients">
                      <button type="submit" class="rg-action-link<?= (int) $pc['is_active'] === 1 ? ' danger' : '' ?>">
                        <?= (int) $pc['is_active'] === 1 ? 'Désactiver' : 'Réactiver' ?>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--ink-faint);text-transform:uppercase;padding:16px 0;">Aucun client packaging enregistré.</p>
          <?php endif ?>

          <!-- Edit or Add packaging client form -->
          <?php if ($editPkgClientRow !== null): ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Modifier le client · <?= htmlspecialchars((string) $editPkgClientRow['name']) ?></div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="edit_pkg_client">
              <input type="hidden" name="client_id" value="<?= (int) $editPkgClientRow['id'] ?>">
              <input type="hidden" name="sec" value="pkg_clients">
              <div class="rg-form-grid">
                <div class="full">
                  <label class="rg-form-label">Nom du client / venue *</label>
                  <input class="rg-input" type="text" name="name" maxlength="128" required
                         value="<?= htmlspecialchars((string) $editPkgClientRow['name']) ?>">
                </div>
                <div>
                  <label class="rg-form-label">Ordre d'affichage</label>
                  <input class="rg-input" type="number" name="sort_order" min="0" max="9999"
                         value="<?= (int) ($editPkgClientRow['sort_order'] ?? 0) ?>">
                </div>
                <div class="full">
                  <label class="rg-form-label">Notes (libre)</label>
                  <input class="rg-input" type="text" name="notes" maxlength="255"
                         value="<?= htmlspecialchars((string) ($editPkgClientRow['notes'] ?? '')) ?>">
                </div>
              </div>
              <div style="display:flex;gap:10px;">
                <button type="submit" class="rg-btn rg-btn-primary">Enregistrer</button>
                <a href="/modules/reglages-generaux.php?sec=pkg_clients" class="rg-btn rg-btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Annuler</a>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Ajouter un client</div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="add_pkg_client">
              <input type="hidden" name="sec" value="pkg_clients">
              <div class="rg-form-grid">
                <div class="full">
                  <label class="rg-form-label">Nom du client / venue *</label>
                  <input class="rg-input" type="text" name="name" maxlength="128" required
                         placeholder="ex: Festival de la Cité">
                </div>
                <div>
                  <label class="rg-form-label">Ordre d'affichage</label>
                  <input class="rg-input" type="number" name="sort_order" min="0" max="9999" value="0">
                </div>
                <div class="full">
                  <label class="rg-form-label">Notes (libre)</label>
                  <input class="rg-input" type="text" name="notes" maxlength="255">
                </div>
              </div>
              <button type="submit" class="rg-btn rg-btn-primary">Ajouter le client</button>
            </form>
          </div>
          <?php endif ?>

        </div><!-- /.rg-card pkg_clients -->

        <?php endif /* $packagingClientsApplied */ ?>

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-pkg_clients -->


    <!-- ═══════════════ SECTION: Sites de livraison clients ═══════════════ -->
    <div class="section-panel<?= $initialSec === 'delivery_sites' ? ' active' : '' ?>" id="sec-delivery_sites">
      <div class="section-scroll">

        <div class="sec-title">Sites de livraison <em>clients</em></div>
        <div class="sec-subtitle">Site d'expédition par défaut · consignation · Nausikraft</div>

        <?php
        if ($initialSec === 'delivery_sites'):
            $flashDs = flash_pop();
            if ($flashDs !== null):
                $fcDs = $flashDs['type'] === 'ok' ? 'rg-flash--ok' : 'rg-flash--err';
                $fiDs = $flashDs['type'] === 'ok' ? '✓' : '⚠';
        ?>
        <div class="rg-flash <?= $fcDs ?>"><?= $fiDs ?> <?= htmlspecialchars($flashDs['msg']) ?></div>
        <?php endif; endif ?>

        <div class="rg-card">
          <div class="rg-card-title">
            Sites de livraison par défaut
            <span class="rg-card-label">ref_customers · default_delivery_site_id_fk</span>
          </div>

          <p class="rg-field-desc">Définit le site d'où partent par défaut les ventes d'un client (ex. clients livrés depuis Nausikraft). Modifiable par commande.</p>

          <?php if (!empty($deliverySiteCustomers)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Site par défaut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($deliverySiteCustomers as $dsc): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $dsc['customer_name']) ?></td>
                  <td><span class="rg-pill-active"><?= htmlspecialchars((string) $dsc['site_name']) ?></span></td>
                  <td>
                    <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="remove_delivery_site">
                      <input type="hidden" name="customer_id" value="<?= (int) $dsc['id'] ?>">
                      <input type="hidden" name="sec" value="delivery_sites">
                      <button type="submit" class="rg-action-link danger">Retirer</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--ink-faint);text-transform:uppercase;padding:16px 0;">Aucun client avec site de livraison par défaut.</p>
          <?php endif ?>

          <!-- Assign form -->
          <div class="rg-inline-form">
            <div class="rg-form-title">Assigner un site de livraison par défaut</div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="assign_delivery_site">
              <input type="hidden" name="sec" value="delivery_sites">
              <div class="rg-form-grid">
                <div class="full">
                  <label class="rg-form-label" for="ds-customer-input">Client *</label>
                  <input class="rg-input" type="text" id="ds-customer-input" name="customer_name_hint"
                         list="ds-customer-list" autocomplete="off"
                         placeholder="Commencer à taper le nom du client…" required
                         oninput="rgDsResolveCustomer(this.value)">
                  <input type="hidden" name="customer_id" id="ds-customer-id" value="">
                  <datalist id="ds-customer-list">
                    <?php foreach ($allCustomersForSelect as $c): ?>
                    <option value="<?= htmlspecialchars((string) $c['name']) ?>" data-id="<?= (int) $c['id'] ?>"></option>
                    <?php endforeach ?>
                  </datalist>
                </div>
                <div>
                  <label class="rg-form-label" for="ds-site-select">Site de livraison *</label>
                  <select class="rg-input" id="ds-site-select" name="site_id" required>
                    <option value="">— choisir un site —</option>
                    <?php foreach ($deliverySiteOptions as $so): ?>
                    <option value="<?= (int) $so['id'] ?>"><?= htmlspecialchars((string) $so['name']) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
              </div>
              <button type="submit" class="rg-btn rg-btn-primary" id="ds-submit-btn" disabled>Assigner</button>
            </form>
          </div>

        </div><!-- /.rg-card delivery_sites -->

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-delivery_sites -->


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
            Comptes utilisateurs
            <span class="rg-card-label"><?= count($users) ?> utilisateur<?= count($users) !== 1 ? 's' : '' ?></span>
          </div>

          <?php if (!empty($users)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table" id="rg-users-table">
              <thead>
                <tr>
                  <th>Identifiant</th>
                  <th>Nom d'affichage</th>
                  <th>E-mail</th>
                  <th>Rôle · Périmètre</th>
                  <th>Preset accès</th>
                  <th>Statut</th>
                  <th>Dernière connexion</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u):
                    $uid        = (int) $u['id'];
                    $uRole      = (string) ($u['role'] ?? 'operator');
                    $uScope      = $u['manager_scope'] ?? null;
                    $uActive     = (int) $u['is_active'] === 1;
                    $uOnboarded  = $u['last_login_at'] !== null; // has logged in at least once
                    $isSelf      = ((int) $me['id'] === $uid);
                    $isAdmin     = $uRole === 'admin';
                    $scopeLabel  = ['production' => 'production', 'logistics' => 'logistique', 'all' => 'tout'];
                    $uPresetId   = isset($u['access_preset_id_fk']) && $u['access_preset_id_fk'] !== null ? (int)$u['access_preset_id_fk'] : null;
                    $uPresetLabel = null;
                    foreach ($presets as $pr) {
                        if ((int)$pr['id'] === $uPresetId) { $uPresetLabel = $pr['label']; break; }
                    }
                ?>
                <tr id="rg-user-row-<?= $uid ?>">
                  <td style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?= htmlspecialchars((string) $u['username']) ?></td>
                  <td><?= htmlspecialchars((string) ($u['display_name'] ?? '—')) ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink-mute);"><?= htmlspecialchars((string) ($u['email'] ?? '—')) ?></td>
                  <td>
                    <span class="rg-role-badge <?= htmlspecialchars($uRole) ?>"><?= htmlspecialchars($uRole) ?></span>
                    <?php if ($uRole === 'manager' && $uScope !== null): ?>
                    <span class="rg-scope-badge"><?= htmlspecialchars($scopeLabel[$uScope] ?? $uScope) ?></span>
                    <?php endif ?>
                  </td>
                  <td>
                    <?php if ($isAdmin): ?>
                    <span class="rg-preset-bypass">Admin — bypass</span>
                    <?php elseif ($uPresetLabel !== null): ?>
                    <span class="rg-preset-badge"><?= htmlspecialchars($uPresetLabel) ?></span>
                    <?php else: ?>
                    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink-faint);">—</span>
                    <?php endif ?>
                  </td>
                  <td><?php if ($uActive): ?>
                    <span class="rg-pill-active">Actif</span>
                  <?php else: ?>
                    <span class="rg-pill-inactive">Inactif</span>
                  <?php endif ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink-mute);">
                    <?= $u['last_login_at'] ? htmlspecialchars((string) $u['last_login_at']) : '—' ?>
                  </td>
                  <td>
                    <div class="rg-user-actions">
                      <!-- Toggle edit row -->
                      <button type="button" class="rg-action-link"
                              onclick="rgToggleEditRow(<?= $uid ?>)">Modifier</button>
                      <!-- Toggle per-user access matrix -->
                      <button type="button" class="rg-action-link"
                              onclick="rgToggleAccessRow(<?= $uid ?>)">Accès</button>

                      <!-- Toggle active / deactivate -->
                      <?php if (!$isSelf): ?>
                      <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="toggle_user_active">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="hidden" name="sec" value="users">
                        <button type="submit" class="rg-action-link<?= $uActive ? ' danger' : '' ?>">
                          <?= $uActive ? 'Désactiver' : 'Réactiver' ?>
                        </button>
                      </form>
                      <?php endif ?>

                      <?php if (!$uOnboarded): ?>
                      <!-- PRIMARY for never-logged-in users: send welcome / onboarding e-mail -->
                      <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="invite_user">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="hidden" name="sec" value="users">
                        <button type="submit" class="rg-action-link rg-action-primary">Envoyer l'e-mail de bienvenue</button>
                      </form>
                      <!-- Secondary: password reset still accessible for edge cases -->
                      <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="reset_user_password">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="hidden" name="sec" value="users">
                        <button type="submit" class="rg-action-link">Réinitialiser MDP</button>
                      </form>
                      <?php else: ?>
                      <!-- PRIMARY for onboarded users: password reset -->
                      <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="reset_user_password">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="hidden" name="sec" value="users">
                        <button type="submit" class="rg-action-link">Réinitialiser le mot de passe</button>
                      </form>
                      <?php endif ?>
                    </div>
                  </td>
                </tr>
                <!-- Inline edit row (hidden by default) -->
                <tr id="rg-edit-row-<?= $uid ?>" class="rg-edit-row" style="display:none;">
                  <td colspan="8">
                    <form method="post" action="/modules/reglages-generaux.php" class="rg-inline-edit-form">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="update_user">
                      <input type="hidden" name="user_id" value="<?= $uid ?>">
                      <input type="hidden" name="sec" value="users">
                      <div class="rg-form-grid">
                        <div>
                          <label class="rg-form-label">Identifiant (login) *</label>
                          <input class="rg-input" type="text" name="username" maxlength="64" required
                                 value="<?= htmlspecialchars((string) $u['username']) ?>"
                                 title="Lettres, chiffres, espaces et . _ - ' autorisés">
                        </div>
                        <div>
                          <label class="rg-form-label">Nom d'affichage</label>
                          <input class="rg-input" type="text" name="display_name" maxlength="128"
                                 value="<?= htmlspecialchars((string) ($u['display_name'] ?? '')) ?>">
                        </div>
                        <div>
                          <label class="rg-form-label">E-mail</label>
                          <input class="rg-input" type="email" name="email" maxlength="255"
                                 value="<?= htmlspecialchars((string) ($u['email'] ?? '')) ?>"
                                 placeholder="ex: prenom@example.com">
                        </div>
                        <div>
                          <label class="rg-form-label">Rôle *</label>
                          <select class="rg-select rg-role-select" name="role" required
                                  onchange="rgUpdateScopeSelect(this)">
                            <?php foreach (['operator' => 'Opérateur', 'manager' => 'Manager', 'viewer' => 'Lecteur (viewer)', 'admin' => 'Administrateur'] as $rv => $rl): ?>
                            <option value="<?= $rv ?>"<?= $uRole === $rv ? ' selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                            <?php endforeach ?>
                          </select>
                        </div>
                        <div class="rg-scope-field<?= $uRole !== 'manager' ? ' rg-scope-hidden' : '' ?>" id="rg-scope-field-<?= $uid ?>">
                          <label class="rg-form-label">Périmètre manager</label>
                          <select class="rg-select" name="manager_scope"<?= $uRole !== 'manager' ? ' disabled' : '' ?>>
                            <option value=""<?= $uScope === null ? ' selected' : '' ?>>— choisir —</option>
                            <?php foreach (['production' => 'Production', 'logistics' => 'Logistique', 'all' => 'Tout'] as $sv => $sl): ?>
                            <option value="<?= $sv ?>"<?= $uScope === $sv ? ' selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                            <?php endforeach ?>
                          </select>
                        </div>
                        <div>
                          <label class="rg-form-label">Preset d'accès</label>
                          <select class="rg-select" name="access_preset_id_fk">
                            <option value="">— aucun —</option>
                            <?php foreach ($presets as $pr): ?>
                            <option value="<?= (int)$pr['id'] ?>"<?= $uPresetId === (int)$pr['id'] ? ' selected' : '' ?>><?= htmlspecialchars($pr['label']) ?></option>
                            <?php endforeach ?>
                          </select>
                        </div>
                      </div>
                      <div style="display:flex;gap:10px;margin-top:12px;">
                        <button type="submit" class="rg-btn rg-btn-primary">Enregistrer</button>
                        <button type="button" class="rg-btn rg-btn-secondary"
                                onclick="rgToggleEditRow(<?= $uid ?>)">Annuler</button>
                      </div>
                    </form>
                  </td>
                </tr>
                <!-- Per-user access matrix row (hidden by default) -->
                <tr id="rg-access-row-<?= $uid ?>" class="rg-access-row" style="display:none;">
                  <td colspan="8">
                    <?php if ($isAdmin): ?>
                    <div class="rg-access-admin-note">
                      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="flex:0 0 14px;"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 7v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="5" r=".9" fill="currentColor"/></svg>
                      Les administrateurs contournent toujours le contrôle d'accès — les overrides per-page sont sans effet.
                    </div>
                    <?php endif ?>
                    <form method="post" action="/modules/reglages-generaux.php" class="rg-access-form">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="update_user_access">
                      <input type="hidden" name="user_id" value="<?= $uid ?>">
                      <input type="hidden" name="sec" value="users">
                      <div class="rg-access-grid">
                        <?php
                        $domainLabels = ['production' => 'Production', 'logistics' => 'Logistique', 'admin' => 'Admin', 'general' => 'Général'];
                        $currentPresetPages = ($uPresetId !== null && isset($presetPageMap[$uPresetId])) ? $presetPageMap[$uPresetId] : null;
                        foreach ($refPages as $rp):
                            $rpId      = (int)$rp['id'];
                            $rpKey     = $rp['page_key'];
                            // Determine tri-state: does user_page_access override exist?
                            if (isset($userPageAccessMap[$uid][$rpId])) {
                                $triState = $userPageAccessMap[$uid][$rpId] ? 'allow' : 'deny';
                            } else {
                                $triState = 'inherit';
                            }
                            // Effective access computation (mirrors user_can_access logic, minus admin bypass)
                            if ($triState !== 'inherit') {
                                $effectiveAccess = ($triState === 'allow');
                                $effectiveSrc    = $triState === 'allow' ? 'override-allow' : 'override-deny';
                            } elseif ($currentPresetPages !== null) {
                                $effectiveAccess = isset($currentPresetPages[$rpKey]);
                                $effectiveSrc    = 'preset';
                            } else {
                                $effectiveAccess = true; // fallback: no preset, role floor passed
                                $effectiveSrc    = 'fallback';
                            }
                            $domainLabel = $domainLabels[$rp['domain'] ?? ''] ?? ($rp['domain'] ?? '');
                        ?>
                        <div class="rg-access-item<?= !(bool)(int)$rp['is_active'] ? ' rg-access-item--inactive' : '' ?>">
                          <div class="rg-access-page-info">
                            <span class="rg-access-page-label"><?= htmlspecialchars($rp['label']) ?></span>
                            <?php if ($domainLabel): ?>
                            <span class="rg-domain-badge rg-domain-<?= htmlspecialchars($rp['domain'] ?? 'general') ?>"><?= htmlspecialchars($domainLabel) ?></span>
                            <?php endif ?>
                            <?php if (!(bool)(int)$rp['is_active']): ?>
                            <span class="rg-pill-inactive" style="font-size:7.5px;padding:1px 6px;">inactif</span>
                            <?php endif ?>
                            <span class="rg-access-effective <?= $effectiveAccess ? 'eff-allow' : 'eff-deny' ?>">
                              <?= $effectiveAccess ? '✓' : '✗' ?> <?php
                              if ($effectiveSrc === 'override-allow') echo 'Override autorisé';
                              elseif ($effectiveSrc === 'override-deny') echo 'Override refusé';
                              elseif ($effectiveSrc === 'preset') echo ($effectiveAccess ? 'Via preset' : 'Non dans preset');
                              else echo 'Fallback (aucun preset)';
                              ?>
                            </span>
                          </div>
                          <div class="rg-tristate">
                            <label class="rg-tristate-opt<?= $triState === 'inherit' ? ' selected' : '' ?>">
                              <input type="radio" name="page_access[<?= $rpId ?>]" value="inherit"<?= $triState === 'inherit' ? ' checked' : '' ?>>
                              Hérité
                            </label>
                            <label class="rg-tristate-opt<?= $triState === 'allow' ? ' selected' : '' ?>">
                              <input type="radio" name="page_access[<?= $rpId ?>]" value="allow"<?= $triState === 'allow' ? ' checked' : '' ?>>
                              Autorisé
                            </label>
                            <label class="rg-tristate-opt<?= $triState === 'deny' ? ' selected' : '' ?>">
                              <input type="radio" name="page_access[<?= $rpId ?>]" value="deny"<?= $triState === 'deny' ? ' checked' : '' ?>>
                              Refusé
                            </label>
                          </div>
                        </div>
                        <?php endforeach ?>
                      </div><!-- /.rg-access-grid -->
                      <div style="display:flex;gap:10px;margin-top:14px;">
                        <button type="submit" class="rg-btn rg-btn-primary">Enregistrer les accès</button>
                        <button type="button" class="rg-btn rg-btn-secondary"
                                onclick="rgToggleAccessRow(<?= $uid ?>)">Annuler</button>
                      </div>
                    </form>
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
                       placeholder="ex: Stéphane Lemos"
                       title="Lettres, chiffres, espaces et . _ - ' autorisés">
              </div>
              <div>
                <label class="rg-form-label">Nom d'affichage</label>
                <input class="rg-input" type="text" name="display_name" maxlength="128"
                       autocomplete="off"
                       placeholder="ex: Jean Dupont">
              </div>
              <div>
                <label class="rg-form-label">E-mail</label>
                <input class="rg-input" type="email" name="email" maxlength="255"
                       autocomplete="off"
                       placeholder="ex: jean@example.com">
              </div>
              <div>
                <label class="rg-form-label">Rôle *</label>
                <select class="rg-select rg-role-select" name="role" required id="rg-create-role"
                        onchange="rgUpdateScopeSelect(this)">
                  <option value="operator" selected>Opérateur</option>
                  <option value="manager">Manager</option>
                  <option value="viewer">Lecteur (viewer)</option>
                  <option value="admin">Administrateur</option>
                </select>
              </div>
              <div class="rg-scope-field rg-scope-hidden" id="rg-scope-field-create">
                <label class="rg-form-label">Périmètre manager</label>
                <select class="rg-select" name="manager_scope" disabled id="rg-create-scope">
                  <option value="">— choisir —</option>
                  <option value="production">Production</option>
                  <option value="logistics">Logistique</option>
                  <option value="all">Tout</option>
                </select>
              </div>
              <div>
                <label class="rg-form-label">Preset d'accès</label>
                <select class="rg-select" name="access_preset_id_fk">
                  <option value="">— aucun —</option>
                  <?php foreach ($presets as $pr): ?>
                  <option value="<?= (int)$pr['id'] ?>"><?= htmlspecialchars($pr['label']) ?></option>
                  <?php endforeach ?>
                </select>
              </div>
              <div>
                <label class="rg-form-label">Mot de passe (laisser vide pour envoyer une invitation)</label>
                <input class="rg-input" type="password" name="password"
                       autocomplete="new-password"
                       placeholder="Laisser vide → lien d'invitation généré">
                <span class="rg-pw-hint">Min. 8 caractères, ou vide → compte inactif + lien invitation. Haché Argon2id.</span>
              </div>
            </div>

            <div class="rg-save-row" style="margin-top:0;border-top:none;padding-top:0;">
              <button type="submit" class="rg-btn rg-btn-primary">Créer l'utilisateur</button>
            </div>
          </form>
        </div><!-- /.rg-create-user -->

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-users -->


    <!-- ═══════════════ SECTION: Pages & Accès ═══════════════ -->
    <div class="section-panel<?= $initialSec === 'access' ? ' active' : '' ?>" id="sec-access">
      <div class="section-scroll">

        <?php if ($initialSec === 'access'):
            $flashAc = flash_pop();
            if ($flashAc !== null):
                $fcAc = $flashAc['type'] === 'ok' ? 'rg-flash--ok' : 'rg-flash--err';
                $fiAc = $flashAc['type'] === 'ok' ? '✓' : '⚠';
        ?>
        <div class="rg-flash <?= $fcAc ?>"><?= $fiAc ?> <?= htmlspecialchars($flashAc['msg']) ?></div>
        <?php endif; endif ?>

        <div class="sec-title">Pages &amp; <em>Accès</em></div>
        <div class="sec-subtitle">Registre des pages · présets d'accès · contrôle d'accès</div>

        <!-- Presets info card -->
        <div class="rg-card">
          <div class="rg-card-title">
            Présets d'accès
            <span class="rg-card-label">ref_access_presets</span>
          </div>
          <?php if (!empty($presets)): ?>
          <div class="rg-access-presets-list">
            <?php foreach ($presets as $pr):
                $prId     = (int)$pr['id'];
                $prPages  = isset($presetPageMap[$prId]) ? $presetPageMap[$prId] : [];
                // Count users with this preset
                $prUserCt = 0;
                foreach ($users as $uu) {
                    if (isset($uu['access_preset_id_fk']) && (int)$uu['access_preset_id_fk'] === $prId) $prUserCt++;
                }
            ?>
            <div class="rg-preset-card">
              <div class="rg-preset-card-header">
                <span class="rg-preset-name"><?= htmlspecialchars($pr['label']) ?></span>
                <span class="rg-preset-key"><?= htmlspecialchars($pr['preset_key']) ?></span>
                <span class="rg-preset-userct"><?= $prUserCt ?> utilisateur<?= $prUserCt !== 1 ? 's' : '' ?></span>
              </div>
              <?php if ($pr['description']): ?>
              <div class="rg-preset-desc"><?= htmlspecialchars($pr['description']) ?></div>
              <?php endif ?>
              <div class="rg-preset-pages">
                <?php foreach ($refPages as $rp):
                    if (!(bool)(int)$rp['is_active']) continue;
                    $inPreset = isset($prPages[$rp['page_key']]);
                ?>
                <span class="rg-preset-page-chip <?= $inPreset ? 'in-preset' : 'out-preset' ?>">
                  <?= htmlspecialchars($rp['label']) ?>
                </span>
                <?php endforeach ?>
              </div>
            </div>
            <?php endforeach ?>
          </div>
          <p class="rg-access-note">Les présets sont gérés via migrations SQL. Assignez-les aux utilisateurs depuis la section Utilisateurs.</p>
          <?php else: ?>
          <p style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--ink-faint);text-transform:uppercase;padding:16px 0;">Aucun préset chargé.</p>
          <?php endif ?>
        </div><!-- /.rg-card presets -->

        <!-- Page registry card -->
        <div class="rg-card">
          <div class="rg-card-title">
            Registre des pages
            <span class="rg-card-label">ref_pages · <?= count($refPages) ?> entrées</span>
          </div>

          <?php if (!empty($refPages)): ?>
          <div class="rg-table-wrap">
            <table class="rg-table rg-pages-table">
              <thead>
                <tr>
                  <th>page_key</th>
                  <th>Label</th>
                  <th>URL</th>
                  <th>min_role</th>
                  <th>Domaine</th>
                  <th>Sort</th>
                  <th>Statut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($refPages as $rp):
                    $rpId = (int)$rp['id'];
                    $rpActive = (bool)(int)$rp['is_active'];
                ?>
                <tr class="<?= !$rpActive ? 'rg-row-inactive' : '' ?>">
                  <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink-mute);"><?= htmlspecialchars($rp['page_key']) ?></td>
                  <td style="font-weight:500;"><?= htmlspecialchars($rp['label']) ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink-mute);"><?= htmlspecialchars($rp['href']) ?></td>
                  <td><span class="rg-minrole-badge rg-minrole-<?= htmlspecialchars($rp['min_role']) ?>"><?= htmlspecialchars($rp['min_role']) ?></span></td>
                  <td><?php if ($rp['domain']): ?><span class="rg-domain-badge rg-domain-<?= htmlspecialchars($rp['domain']) ?>"><?= htmlspecialchars($rp['domain']) ?></span><?php else: ?>—<?php endif ?></td>
                  <td style="font-family:'JetBrains Mono',monospace;font-size:11px;text-align:right;"><?= (int)$rp['sort'] ?></td>
                  <td><?= $rpActive ? '<span class="rg-pill-active">Actif</span>' : '<span class="rg-pill-inactive">Inactif</span>' ?></td>
                  <td>
                    <div class="rg-user-actions">
                      <a href="?sec=access&edit_page=<?= $rpId ?>" class="rg-action-link">Modifier</a>
                      <form method="post" action="/modules/reglages-generaux.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="toggle_page_active">
                        <input type="hidden" name="page_id" value="<?= $rpId ?>">
                        <input type="hidden" name="sec" value="access">
                        <button type="submit" class="rg-action-link<?= $rpActive ? ' danger' : '' ?>">
                          <?= $rpActive ? 'Désactiver' : 'Activer' ?>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php endif ?>

          <!-- Edit or Add page form -->
          <?php if ($editPageRow !== null): ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Modifier la page · <?= htmlspecialchars($editPageRow['page_key']) ?></div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="update_page">
              <input type="hidden" name="page_id" value="<?= (int)$editPageRow['id'] ?>">
              <input type="hidden" name="sec" value="access">
              <div class="rg-form-grid">
                <div>
                  <label class="rg-form-label">Label *</label>
                  <input class="rg-input" type="text" name="label" maxlength="64" required
                         value="<?= htmlspecialchars($editPageRow['label']) ?>">
                </div>
                <div>
                  <label class="rg-form-label">URL (href) *</label>
                  <input class="rg-input" type="text" name="href" maxlength="128" required
                         value="<?= htmlspecialchars($editPageRow['href']) ?>">
                </div>
                <div>
                  <label class="rg-form-label">Rôle minimum *</label>
                  <select class="rg-select" name="min_role">
                    <?php foreach (['viewer' => 'Viewer', 'operator' => 'Opérateur', 'manager' => 'Manager', 'admin' => 'Admin'] as $rv => $rl): ?>
                    <option value="<?= $rv ?>"<?= $editPageRow['min_role'] === $rv ? ' selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Domaine</label>
                  <select class="rg-select" name="domain">
                    <option value="">— aucun —</option>
                    <?php foreach (['production' => 'Production', 'logistics' => 'Logistique', 'admin' => 'Admin', 'general' => 'Général'] as $dv => $dl): ?>
                    <option value="<?= $dv ?>"<?= ($editPageRow['domain'] ?? '') === $dv ? ' selected' : '' ?>><?= htmlspecialchars($dl) ?></option>
                    <?php endforeach ?>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Ordre d'affichage</label>
                  <input class="rg-input" type="number" name="sort" min="0" max="9999"
                         value="<?= (int)$editPageRow['sort'] ?>">
                </div>
              </div>
              <div style="display:flex;gap:10px;">
                <button type="submit" class="rg-btn rg-btn-primary">Enregistrer</button>
                <a href="/modules/reglages-generaux.php?sec=access" class="rg-btn rg-btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Annuler</a>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div class="rg-inline-form">
            <div class="rg-form-title">Ajouter une page</div>
            <form method="post" action="/modules/reglages-generaux.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="action" value="create_page">
              <input type="hidden" name="sec" value="access">
              <div class="rg-form-grid">
                <div>
                  <label class="rg-form-label">page_key * <span style="font-weight:400;font-size:9px;">(a-z 0-9 -)</span></label>
                  <input class="rg-input" type="text" name="page_key" maxlength="48" required
                         placeholder="ex: mon-module"
                         pattern="[a-z0-9-]+" title="Minuscules, chiffres et tirets uniquement">
                </div>
                <div>
                  <label class="rg-form-label">Label *</label>
                  <input class="rg-input" type="text" name="label" maxlength="64" required
                         placeholder="ex: Mon Module">
                </div>
                <div>
                  <label class="rg-form-label">URL (href) *</label>
                  <input class="rg-input" type="text" name="href" maxlength="128" required
                         placeholder="ex: /modules/mon-module.php">
                </div>
                <div>
                  <label class="rg-form-label">Rôle minimum *</label>
                  <select class="rg-select" name="min_role">
                    <option value="viewer" selected>Viewer</option>
                    <option value="operator">Opérateur</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Domaine</label>
                  <select class="rg-select" name="domain">
                    <option value="">— aucun —</option>
                    <option value="production">Production</option>
                    <option value="logistics">Logistique</option>
                    <option value="admin">Admin</option>
                    <option value="general">Général</option>
                  </select>
                </div>
                <div>
                  <label class="rg-form-label">Ordre d'affichage</label>
                  <input class="rg-input" type="number" name="sort" min="0" max="9999" value="0">
                </div>
              </div>
              <button type="submit" class="rg-btn rg-btn-primary">Créer la page</button>
            </form>
          </div>
          <?php endif ?>

        </div><!-- /.rg-card page registry -->

      </div><!-- /.section-scroll -->
    </div><!-- /#sec-access -->

  </div><!-- /.content-area -->
</div><!-- /.rg-stage -->

<script>
(function() {
  'use strict';

  // ── Section switching (nav + panel) ──────────────────────────────────────
  function switchSection(sec) {
    document.querySelectorAll('.nav-item').forEach(function(n) {
      var isActive = n.dataset.sec === sec;
      n.classList.toggle('active', isActive);
      // RG-05: aria-current="true" on the active section button (section-within-page)
      if (isActive) {
        n.setAttribute('aria-current', 'true');
      } else {
        n.removeAttribute('aria-current');
      }
    });
    document.querySelectorAll('.section-panel').forEach(function(p) {
      p.classList.toggle('active', p.id === 'sec-' + sec);
    });
    // Update URL without reload (history API)
    var url = new URL(window.location.href);
    url.searchParams.set('sec', sec);
    url.searchParams.delete('edit_site');
    url.searchParams.delete('edit_pkg_client');
    url.searchParams.delete('edit_page');
    history.replaceState(null, '', url.toString());
    // Reset datalist hidden id when switching away from delivery_sites
    var dsId = document.getElementById('ds-customer-id');
    var dsBtn = document.getElementById('ds-submit-btn');
    if (dsId) dsId.value = '';
    if (dsBtn) dsBtn.disabled = true;
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

  // ── Inline edit row toggle ────────────────────────────────────────────────
  function rgToggleEditRow(uid) {
    var editRow   = document.getElementById('rg-edit-row-' + uid);
    var accessRow = document.getElementById('rg-access-row-' + uid);
    if (!editRow) return;
    var nowVisible = editRow.style.display !== 'none';
    editRow.style.display = nowVisible ? 'none' : 'table-row';
    // Close access row if opening edit row
    if (!nowVisible && accessRow) accessRow.style.display = 'none';
  }
  window.rgToggleEditRow = rgToggleEditRow;

  // ── Per-user access matrix row toggle ────────────────────────────────────
  function rgToggleAccessRow(uid) {
    var accessRow = document.getElementById('rg-access-row-' + uid);
    var editRow   = document.getElementById('rg-edit-row-' + uid);
    if (!accessRow) return;
    var nowVisible = accessRow.style.display !== 'none';
    accessRow.style.display = nowVisible ? 'none' : 'table-row';
    // Close edit row if opening access row
    if (!nowVisible && editRow) editRow.style.display = 'none';
  }
  window.rgToggleAccessRow = rgToggleAccessRow;

  // ── Tri-state radio: highlight selected label ─────────────────────────────
  // Delegates to the .rg-access-grid container via a single listener.
  document.addEventListener('change', function(e) {
    var input = e.target;
    if (input.type !== 'radio' || !input.closest('.rg-access-grid')) return;
    // Update selected class on sibling labels within this tristate group
    var group = input.closest('.rg-tristate');
    if (!group) return;
    group.querySelectorAll('.rg-tristate-opt').forEach(function(lbl) {
      lbl.classList.toggle('selected', lbl.querySelector('input') === input);
    });
  });

  // ── manager_scope select: enable/disable based on role select ────────────
  // Works for both the create form and each per-user edit form.
  // Called via onchange on any .rg-role-select element.
  function rgUpdateScopeSelect(roleSelect) {
    // Find the nearest ancestor form, then the scope field within it
    var form = roleSelect.closest('form');
    if (!form) return;
    var scopeField  = form.querySelector('[id^="rg-scope-field-"]');
    var scopeSelect = form.querySelector('select[name="manager_scope"]');
    if (!scopeField || !scopeSelect) return;

    var isManager = roleSelect.value === 'manager';
    scopeField.classList.toggle('rg-scope-hidden', !isManager);
    scopeSelect.disabled = !isManager;
    if (!isManager) scopeSelect.value = '';
  }
  window.rgUpdateScopeSelect = rgUpdateScopeSelect;

  // ── Delivery site assign: resolve datalist selection → hidden customer_id ──
  // The datalist <option value="…" data-id="…"> approach: match the typed value
  // against option text, populate the hidden field, enable/disable the submit button.
  function rgDsResolveCustomer(val) {
    var list    = document.getElementById('ds-customer-list');
    var hiddenId = document.getElementById('ds-customer-id');
    var btn      = document.getElementById('ds-submit-btn');
    if (!list || !hiddenId || !btn) return;
    var matched = null;
    var opts = list.querySelectorAll('option');
    for (var i = 0; i < opts.length; i++) {
      if (opts[i].value === val) { matched = opts[i]; break; }
    }
    hiddenId.value = matched ? matched.dataset.id : '';
    btn.disabled   = !matched;
  }
  window.rgDsResolveCustomer = rgDsResolveCustomer;
})();
</script>

</body>
</html>
