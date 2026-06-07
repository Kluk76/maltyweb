<?php
declare(strict_types=1);
/**
 * modules/classification-appareil.php — Device classification interstitial.
 *
 * One-time page shown after first login when the browser's mt_device_id has
 * no row in auth_shared_devices. Asks the operator whether they are on a
 * personal device or one of the shared control-room PCs.
 *
 * Accessible to every authenticated user (require_login only — NOT in ref_pages
 * nav, exactly like modules/visite-guidee.php). The reconcile-pages drift check
 * will show this file as UNREGISTERED; that is expected and intentional —
 * no ref_pages row is needed for auth-flow interstitials.
 *
 * Flow:
 *   GET  — render the 4-choice radio-card form.
 *   POST — validate CSRF + choice, write to auth_shared_devices via device.php
 *          service, handle pending_remember flag, then PRG-redirect to $next.
 *
 * Idempotent: if the device is already classified (re-entry, double-click),
 * immediately redirects to the validated $next without re-rendering.
 */

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/csrf.php';
require_once __DIR__ . '/../../app/settings-helpers.php';
require_once __DIR__ . '/../../app/services/device.php';
require_once __DIR__ . '/../../app/services/remember_token.php';

require_login();

maltytask_session_start();

$me  = current_user();
$pdo = maltytask_pdo();

// ── Validate and sanitise the `next` param (same guard as login.php) ────────
$rawNext = $_REQUEST['next'] ?? '/modules/mon-tableau.php';
if (!is_string($rawNext) || !str_starts_with($rawNext, '/') || str_starts_with($rawNext, '//')) {
    $rawNext = '/modules/mon-tableau.php';
}
$safeNext = $rawNext;

// ── Resolve device identity ──────────────────────────────────────────────────
$deviceId = device_id_ensure();

// Idempotent re-entry: already classified → skip straight to destination.
if (device_classified($deviceId, $pdo)) {
    header('Location: ' . $safeNext, true, 302);
    exit;
}

// ── POST handler ─────────────────────────────────────────────────────────────
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF first — before any business logic
    $postedCsrf = $_POST['csrf'] ?? null;
    if (!csrf_verify($postedCsrf)) {
        $error = 'Session expirée — recharge la page et réessaie.';
    } else {
        // 2. Validate choice against whitelist
        $rawChoice = $_POST['choice'] ?? '';
        $VALID_CHOICES = ['personal', 'cr1', 'cr2', 'other'];
        if (!in_array($rawChoice, $VALID_CHOICES, true)) {
            $error = 'Choix invalide — sélectionne une option.';
        } else {
            // 3. Resolve label and is_shared from choice
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $userId = isset($me['id']) ? (int) $me['id'] : null;

            if ($rawChoice === 'personal') {
                device_mark_personal($deviceId, $userId, $ip, $ua, $pdo);

                // If remember-me was deferred during login (box ticked but device
                // was unclassified at login time), issue the token now.
                if (!empty($_SESSION['pending_remember'])) {
                    unset($_SESSION['pending_remember']);
                    rt_create($userId, null, $ip, $ua, $pdo);
                }

            } elseif ($rawChoice === 'cr1') {
                $label = 'PC Salle de contrôle 1 (PC de gauche)';
                device_mark_shared($deviceId, $label, $userId, $ip, $ua, $pdo);
                unset($_SESSION['pending_remember']);
                rt_revoke_current($pdo);

            } elseif ($rawChoice === 'cr2') {
                $label = 'PC Salle de contrôle 2 (PC de droite)';
                device_mark_shared($deviceId, $label, $userId, $ip, $ua, $pdo);
                unset($_SESSION['pending_remember']);
                rt_revoke_current($pdo);

            } else {
                // 'other' — free-text label (required)
                $rawLabel = trim((string) ($_POST['other_label'] ?? ''));
                $label    = mb_substr($rawLabel, 0, 120, 'UTF-8');
                if ($label === '') {
                    $error = 'Précise le nom de cet appareil partagé.';
                } else {
                    device_mark_shared($deviceId, $label, $userId, $ip, $ua, $pdo);
                    unset($_SESSION['pending_remember']);
                    rt_revoke_current($pdo);
                }
            }

            // PRG redirect (only when no error set)
            if ($error === null) {
                header('Location: ' . $safeNext, true, 302);
                exit;
            }
        }
    }
}

// ── GET render ───────────────────────────────────────────────────────────────
$csrfToken   = csrf_token();
$brewery     = brewery_identity();
$biName      = htmlspecialchars($brewery['name'],         ENT_QUOTES, 'UTF-8');
$biCity      = htmlspecialchars($brewery['city'],         ENT_QUOTES, 'UTF-8');
$biCountry   = htmlspecialchars($brewery['country_code'], ENT_QUOTES, 'UTF-8');
$safeNextEnc = htmlspecialchars($safeNext, ENT_QUOTES, 'UTF-8');

$appCssBust = @filemtime(__DIR__ . '/../css/app.css') ?: time();
$caCssBust  = @filemtime(__DIR__ . '/../css/classification-appareil.css') ?: time();
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MaltyTask — Identification de l'appareil</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= $appCssBust ?>">
  <link rel="stylesheet" href="/css/classification-appareil.css?v=<?= $caCssBust ?>">
</head>
<body class="auth classification-appareil">
<main class="auth__shell">

  <header class="auth__hero">
    <div class="auth__eyebrow">— accès · <?= $biName ?></div>
    <h1 class="auth__mark">
      <span class="auth__a">Malty</span><span class="auth__b">Task</span>
    </h1>
    <div class="auth__rule" aria-hidden="true">
      <span></span>
      <svg class="auth__hop" viewBox="0 0 14 22" xmlns="http://www.w3.org/2000/svg">
        <g fill="currentColor">
          <rect x="6.4" y="0" width="1.2" height="3.2" rx="0.4"/>
          <path d="M7 3.6 c-2.6 0 -4.6 1.6 -4.6 3.7 c0 1.5 .9 2.8 2.3 3.4 c-.6 -.6 -1 -1.5 -1 -2.4 c0 -1.7 1.5 -3.1 3.3 -3.1 s3.3 1.4 3.3 3.1 c0 .9 -.4 1.8 -1 2.4 c1.4 -.6 2.3 -1.9 2.3 -3.4 c0 -2.1 -2 -3.7 -4.6 -3.7z"/>
          <path d="M2.2 9.4 c0 2 2.1 3.7 4.8 3.7 s4.8 -1.7 4.8 -3.7 c-.9 1.1 -2.7 1.8 -4.8 1.8 s-3.9 -.7 -4.8 -1.8z" opacity="0.92"/>
          <path d="M2.6 12.8 c0 2 2 3.6 4.4 3.6 s4.4 -1.6 4.4 -3.6 c-.8 1.1 -2.5 1.7 -4.4 1.7 s-3.6 -.6 -4.4 -1.7z" opacity="0.78"/>
          <path d="M3.4 16.1 c0 1.8 1.7 3.2 3.6 3.2 s3.6 -1.4 3.6 -3.2 c-.7 1 -2.1 1.5 -3.6 1.5 s-2.9 -.5 -3.6 -1.5z" opacity="0.62"/>
          <path d="M4.6 19.1 c0 1.4 1.1 2.5 2.4 2.5 s2.4 -1.1 2.4 -2.5 c-.5 .8 -1.4 1.2 -2.4 1.2 s-1.9 -.4 -2.4 -1.2z" opacity="0.45"/>
        </g>
      </svg>
      <span></span>
    </div>
    <p class="auth__tag">Une seule question.</p>
  </header>

  <section class="auth__panel">
    <div class="auth__rail" aria-hidden="true"></div>

    <div class="auth__panel-head">
      <span class="auth__panel-label">— identification de l'appareil</span>
    </div>

    <?php if ($error !== null): ?>
      <div class="auth__err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif ?>

    <form class="auth__form" method="post"
          action="/modules/classification-appareil.php?next=<?= $safeNextEnc ?>"
          autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="next" value="<?= $safeNextEnc ?>">

      <p class="ca-info">
        Cette question n'apparaît qu'une fois par appareil.
        Les postes partagés désactivent &laquo;&nbsp;se souvenir de moi&nbsp;&raquo;
        et ne maintiennent pas de session longue durée.
      </p>

      <fieldset style="border:none;padding:0;margin:0;" aria-label="Où es-tu connecté ?">
        <legend class="auth__field-label" style="margin-bottom:10px;">Où es-tu connecté ?</legend>

        <div class="ca-choices">

          <!-- (a) Personal device -->
          <label class="ca-card">
            <input type="radio" name="choice" value="personal" required>
            <span class="ca-radio-dot" aria-hidden="true"></span>
            <span class="ca-card-body">
              <span class="ca-card-title">Mon téléphone / poste personnel</span>
              <span class="ca-card-sub">Session longue — « se souvenir de moi » disponible.</span>
            </span>
          </label>

          <!-- (b) PC Salle de contrôle 1 -->
          <label class="ca-card">
            <input type="radio" name="choice" value="cr1">
            <span class="ca-radio-dot" aria-hidden="true"></span>
            <span class="ca-card-body">
              <span class="ca-card-title">PC Salle de contrôle 1 (PC de gauche)</span>
              <span class="ca-card-sub">Poste partagé — session courte, pas de souvenir.</span>
            </span>
          </label>

          <!-- (c) PC Salle de contrôle 2 -->
          <label class="ca-card">
            <input type="radio" name="choice" value="cr2">
            <span class="ca-radio-dot" aria-hidden="true"></span>
            <span class="ca-card-body">
              <span class="ca-card-title">PC Salle de contrôle 2 (PC de droite)</span>
              <span class="ca-card-sub">Poste partagé — session courte, pas de souvenir.</span>
            </span>
          </label>

          <!-- (d) Other shared device + free-text -->
          <label class="ca-card">
            <input type="radio" name="choice" value="other" id="choice-other">
            <span class="ca-radio-dot" aria-hidden="true"></span>
            <span class="ca-card-body">
              <span class="ca-card-title">Autre appareil partagé</span>
              <span class="ca-card-sub">Précise son nom ci-dessous.</span>
              <span class="ca-other-wrap" id="other-wrap">
                <input type="text" name="other_label" id="other-label"
                       class="ca-other-input"
                       placeholder="Ex. : Tablette brasserie, PC bureau…"
                       maxlength="120"
                       autocomplete="off">
              </span>
            </span>
          </label>

        </div>
      </fieldset>

      <button class="auth__submit ca-submit" type="submit">
        <span>Confirmer</span>
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
          <path d="M3 8h9M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </form>
  </section>

  <footer class="auth__foot">
    <span><?= $biName ?> · Est. 2014</span>
    <span><?= $biCity ?> · <?= $biCountry ?></span>
  </footer>

</main>

<script>
(function () {
  // Show/hide the free-text input for the 'other' choice.
  // Tiny inline script: no external JS dep, no framework.
  var otherRadio = document.getElementById('choice-other');
  var otherWrap  = document.getElementById('other-wrap');
  var otherInput = document.getElementById('other-label');

  function syncOther() {
    var checked = otherRadio.checked;
    if (checked) {
      otherWrap.classList.add('ca-other-wrap--visible');
      otherInput.required = true;
      otherInput.focus();
    } else {
      otherWrap.classList.remove('ca-other-wrap--visible');
      otherInput.required = false;
      otherInput.value    = '';
    }
  }

  // Wire all radio buttons (clicking a sibling must hide the other-wrap)
  var radios = document.querySelectorAll('input[name="choice"]');
  radios.forEach(function (r) { r.addEventListener('change', syncOther); });

  // Restore state if the form is re-rendered with 'other' pre-selected (validation error)
  syncOther();
}());
</script>
</body>
</html>
