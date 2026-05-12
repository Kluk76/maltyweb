<?php
declare(strict_types=1);

require __DIR__ . "/../../../app/auth.php";
require __DIR__ . "/../../../app/csrf.php";
require __DIR__ . "/../../../app/settings-helpers.php";
require __DIR__ . "/../../../app/services/remember_token.php";

// Available to ALL logged-in operators — not admin-gated.
require_login();
$me = current_user();

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
                // We don't know which token_id corresponds to the current cookie
                // without a DB lookup; safest: always clear cookie on revoke
                // (session remains active so the operator stays logged in).
                rt_clear_cookie();
            }

            flash_set("ok", $revoked ? "Appareil révoqué." : "Token introuvable ou déjà révoqué.");
        } elseif ($action === "revoke_all") {
            $count = rt_revoke_all((int)$me["id"], $pdo);
            rt_clear_cookie();
            flash_set("ok", "Tous les appareils révoqués ({$count}).");
        } else {
            throw new RuntimeException("Action inconnue.");
        }
    } catch (\Throwable $e) {
        flash_set("err", htmlspecialchars($e->getMessage()));
    }

    redirect_to("/admin/settings/devices.php");
}

// ── Load tokens ───────────────────────────────────────────────────────────────
$pdo    = maltytask_pdo();
$tokens = rt_list((int)$me["id"], $pdo);
$token  = csrf_token();

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

  <?php if (empty($tokens)): ?>
    <p class="settings__empty">Aucun appareil enregistré — connecte-toi en cochant "Se souvenir de cet appareil".</p>
  <?php else: ?>

    <section class="settings__section" aria-label="Appareils actifs">

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

    </section>

  <?php endif ?>

</main>

</body>
</html>
