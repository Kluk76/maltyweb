<?php
declare(strict_types=1);

require_once __DIR__ . "/../../../app/auth.php";
require_once __DIR__ . "/../../../app/csrf.php";
require_once __DIR__ . "/../../../app/settings-helpers.php";
require_once __DIR__ . "/../../../app/services/remember_token.php";
require_once __DIR__ . "/../../../app/services/device.php";

// Must be called before any output — may set a Set-Cookie header.
$deviceId   = device_id_ensure();

// Available to ALL logged-in operators — not admin-gated.
require_login();
$me = current_user();

$thisShared = device_is_shared($deviceId, maltytask_pdo());

$active_module = "settings";
$crumbs        = ["Accueil", "Admin", "Mes appareils"];

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_verify($_POST["csrf"] ?? null)) {
        flash_set("err", "Session expirée — recharge la page.");
        redirect_to("/admin/settings/devices.php");
    }

    $pdo    = maltytask_pdo();
    $action = $_POST["action"] ?? "";

    try {
        if ($action === "revoke") {
            $token_id = post_int("token_id");
            if ($token_id === null) throw new RuntimeException("token_id manquant.");

            $revoked = rt_revoke($token_id, (int)$me["id"], $pdo);

            // If this is the device the operator is currently using, clear the cookie
            $current_cookie = $_COOKIE[RT_COOKIE_NAME] ?? null;
            if ($current_cookie !== null && $current_cookie !== '') {
                rt_clear_cookie();
            }

            flash_set("ok", $revoked ? "Appareil révoqué." : "Token introuvable ou déjà révoqué.");

        } elseif ($action === "revoke_all") {
            $count = rt_revoke_all((int)$me["id"], $pdo);
            rt_clear_cookie();
            flash_set("ok", "Tous les appareils révoqués ({$count}).");

        } elseif ($action === "mark_shared") {
            if ($deviceId === '') throw new RuntimeException("Identifiant d'appareil introuvable — vide le cache navigateur et réessaie.");
            $label = post_str('device_label');
            $label = ($label !== null && trim($label) !== '') ? substr(trim($label), 0, 120) : null;
            device_mark_shared(
                $deviceId,
                $label,
                (int)$me['id'],
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $pdo
            );
            // Clear remember-me on this browser so the next operator must log in manually.
            rt_clear_cookie();
            flash_set("ok", "Cet appareil est maintenant un poste partagé : la connexion automatique y est désactivée.");

        } elseif ($action === "unmark_shared") {
            if (!is_admin($me)) throw new RuntimeException("Réservé aux administrateurs.");
            $thatId = post_str('device_id') ?? '';
            if ($thatId === '') throw new RuntimeException("device_id manquant.");
            device_unmark_shared($thatId, $pdo);
            flash_set("ok", "Appareil repassé en mode personnel.");

        } elseif ($action === "relabel_device") {
            if (!is_admin($me)) throw new RuntimeException("Réservé aux administrateurs.");
            $thatId = post_str('device_id') ?? '';
            $label  = post_str('device_label') ?? '';
            $label  = trim($label);
            if ($thatId === '') throw new RuntimeException("device_id manquant.");
            $label = $label !== '' ? substr($label, 0, 120) : null;
            $pdo->prepare("UPDATE auth_shared_devices SET label = ? WHERE device_id = ?")
                ->execute([$label, $thatId]);
            flash_set("ok", "Étiquette mise à jour.");

        } else {
            throw new RuntimeException("Action inconnue.");
        }
    } catch (\Throwable $e) {
        flash_set("err", htmlspecialchars($e->getMessage()));
    }

    redirect_to("/admin/settings/devices.php");
}

// ── Load data ─────────────────────────────────────────────────────────────────
$pdo    = maltytask_pdo();
$tokens = rt_list((int)$me["id"], $pdo);
$token  = csrf_token();

// Admin: load shared devices with registrant username.
$sharedDevices = [];
if (is_admin($me)) {
    $stmt = $pdo->query(
        "SELECT d.id, d.device_id, d.label, d.registered_by,
                d.registered_at, d.last_seen_at, d.last_ip, d.last_ua,
                u.username AS registered_by_username
           FROM auth_shared_devices d
           LEFT JOIN users u ON u.id = d.registered_by
          WHERE d.is_shared = 1
          ORDER BY d.last_seen_at DESC"
    );
    $sharedDevices = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Format a stored TIMESTAMP for display, or return an em-dash.
 */
function fmt_ts(?string $ts): string
{
    if ($ts === null || $ts === '') return '—';
    $t = strtotime($ts);
    return $t !== false ? date('d.m.Y H:i', $t) : htmlspecialchars($ts);
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mes appareils — MaltyTask</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css?v=<?= @filemtime(__DIR__ . '/../../css/app.css') ?: time() ?>">
</head>
<body class="home admin devices-page">

<?php require __DIR__ . "/../../../app/partials/sidebar.php" ?>
<?php require __DIR__ . "/../../../app/partials/topbar.php" ?>

<main class="main admin__main settings__main">

  <header class="settings__head">
    <span class="settings__head-eyebrow">— paramètres · session</span>
    <h1 class="settings__head-title">Mes appareils</h1>
    <p class="settings__head-tag">
      Sessions "Se souvenir de moi" actives sur tes appareils. Chaque appareil
      reçoit un jeton à usage unique rotatif (90 jours). Révoque un appareil
      si tu l'as perdu ou partagé.
    </p>
  </header>

  <?php flash_render() ?>

  <!-- ── "Cet appareil" panel ──────────────────────────────────────────────── -->
  <section class="settings__section settings__device-panel" aria-label="Cet appareil">
    <h2 class="settings__section-title">Cet appareil</h2>

    <?php if ($thisShared): ?>
      <?php
        // Retrieve the label for this device if any.
        $stmt = $pdo->prepare("SELECT label FROM auth_shared_devices WHERE device_id = ? AND is_shared = 1 LIMIT 1");
        $stmt->execute([$deviceId]);
        $thisRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $thisLabel = ($thisRow && $thisRow['label'] !== null && $thisRow['label'] !== '') ? htmlspecialchars($thisRow['label']) : null;
      ?>
      <div class="settings__device-status">
        <span class="settings__device-badge settings__device-badge--shared">Poste partagé</span>
        <?php if ($thisLabel): ?>
          <span class="settings__device-label"><?= $thisLabel ?></span>
        <?php endif ?>
      </div>
      <?php if (is_admin($me)): ?>
        <form method="post" class="settings__inline-form settings__inline-form--unmark">
          <input type="hidden" name="csrf"      value="<?= htmlspecialchars($token) ?>">
          <input type="hidden" name="action"    value="unmark_shared">
          <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
          <button type="submit" class="settings__btn settings__btn--neutral"
                  onclick="return confirm('Repasser cet appareil en mode personnel ?')">
            Repasser en appareil personnel
          </button>
        </form>
      <?php else: ?>
        <p class="settings__device-note">La connexion automatique (« rester connecté ») est désactivée sur ce poste. Chaque opérateur doit s'identifier manuellement.</p>
      <?php endif ?>

    <?php else: ?>
      <div class="settings__device-status">
        <span class="settings__device-badge settings__device-badge--personal">Appareil personnel</span>
      </div>
      <p class="settings__device-note">Marquer cet appareil comme poste partagé désactive la connexion automatique à 90 jours sur ce navigateur, afin que chaque opérateur se connecte comme lui-même.</p>
      <form method="post" class="settings__inline-form settings__inline-form--mark">
        <input type="hidden" name="csrf"   value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="mark_shared">
        <div class="settings__device-mark-row">
          <input type="text" name="device_label"
                 class="settings__device-input"
                 placeholder="ex: Poste salle de contrôle 1"
                 maxlength="120">
          <button type="submit" class="settings__btn settings__btn--primary">
            Marquer comme poste partagé
          </button>
        </div>
      </form>
    <?php endif ?>
  </section>

  <!-- ── Admin — Postes partagés (central registry) ────────────────────────── -->
  <?php if (is_admin($me)): ?>
  <section class="settings__section" aria-label="Postes partagés">
    <h2 class="settings__section-title">Postes partagés</h2>

    <?php if (empty($sharedDevices)): ?>
      <p class="settings__empty">Aucun poste partagé enregistré.</p>
    <?php else: ?>
      <div class="settings__table-wrap">
        <table class="settings__table">
          <thead>
            <tr>
              <th>Étiquette</th>
              <th>Enregistré par</th>
              <th>Dernière activité</th>
              <th>IP</th>
              <th>Renommer</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sharedDevices as $d): ?>
              <tr>
                <td class="settings__mono"><?= htmlspecialchars($d['label'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['registered_by_username'] ?? '—') ?></td>
                <td><?= fmt_ts($d['last_seen_at']) ?></td>
                <td class="settings__mono"><?= htmlspecialchars($d['last_ip'] ?? '—') ?></td>
                <td>
                  <form method="post" class="settings__inline-form settings__inline-form--relabel">
                    <input type="hidden" name="csrf"      value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action"    value="relabel_device">
                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($d['device_id']) ?>">
                    <div class="settings__relabel-row">
                      <input type="text" name="device_label"
                             class="settings__device-input settings__device-input--sm"
                             value="<?= htmlspecialchars($d['label'] ?? '') ?>"
                             maxlength="120">
                      <button type="submit" class="settings__btn">Sauver</button>
                    </div>
                  </form>
                </td>
                <td>
                  <form method="post" class="settings__inline-form">
                    <input type="hidden" name="csrf"      value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action"    value="unmark_shared">
                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($d['device_id']) ?>">
                    <button type="submit" class="settings__btn settings__btn--danger"
                            onclick="return confirm('Repasser cet appareil en mode personnel ?')">
                      Désinscrire
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>
  <?php endif ?>

  <!-- ── Sessions "Se souvenir de moi" ─────────────────────────────────────── -->
  <section class="settings__section" aria-label="Sessions actives">
    <h2 class="settings__section-title">Sessions "Se souvenir de moi"</h2>

    <?php if (empty($tokens)): ?>
      <p class="settings__empty">Aucun appareil enregistré — connecte-toi en cochant "Se souvenir de cet appareil".</p>
    <?php else: ?>

      <div class="settings__table-wrap">
        <table class="settings__table">
          <thead>
            <tr>
              <th>Appareil</th>
              <th>Dernière activité</th>
              <th>IP</th>
              <th>User-Agent</th>
              <th>Créé le</th>
              <th>Expire le</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tokens as $t): ?>
              <tr>
                <td><?= htmlspecialchars($t['device_label'] ?? '—') ?></td>
                <td><?= fmt_ts($t['last_used_at']) ?></td>
                <td class="settings__mono"><?= htmlspecialchars($t['last_ip'] ?? '—') ?></td>
                <td class="settings__ua"><?= htmlspecialchars(substr($t['last_ua'] ?? '—', 0, 80)) ?></td>
                <td><?= fmt_ts($t['created_at']) ?></td>
                <td><?= fmt_ts($t['expires_at']) ?></td>
                <td>
                  <form method="post" class="settings__inline-form">
                    <input type="hidden" name="csrf"     value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="action"   value="revoke">
                    <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="settings__btn settings__btn--danger"
                            onclick="return confirm('Révoquer cet appareil ?')">
                      Révoquer
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>

      <?php if (count($tokens) > 1): ?>
        <div class="settings__bulk-actions">
          <form method="post">
            <input type="hidden" name="csrf"   value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="action" value="revoke_all">
            <button type="submit" class="settings__btn settings__btn--danger-outline"
                    onclick="return confirm('Révoquer tous les appareils ?\nTu devras te reconnecter sur chaque appareil.')">
              Révoquer tous les appareils
            </button>
          </form>
        </div>
      <?php endif ?>

    <?php endif ?>
  </section>

</main>

</body>
</html>
