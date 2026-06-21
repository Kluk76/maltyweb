<?php
declare(strict_types=1);
/**
 * scripts/send-kpi-recap.php — KPI recap email dispatcher (CLI only)
 *
 * For each user with an active subscription in user_kpi_recap_subs whose
 * next_due_at <= NOW(), this script:
 *   1. Loads the user's selected KPI trackers (via kpi_dispatch — same registry
 *      used by mon-tableau.php, so dashboard + email share ONE computation).
 *   2. Skips users with no tracker selections (no empty emails).
 *   3. Renders an inline-styled HTML digest + computes next_due_at.
 *   4. Sends via send_mail() (Postfix → IONOS noreply).
 *   5. Updates last_sent_at / next_due_at in user_kpi_recap_subs.
 *
 * Flags:
 *   --dry-run     Render digest to stdout; no send, no DB update. (default)
 *   --apply       Send for real and update DB timestamps.
 *   --user <id>   Scope to a single user ID (combine with --dry-run for preview).
 *
 * Usage (dry-run preview for admin):
 *   php scripts/send-kpi-recap.php --dry-run --user 1
 *
 * Usage (live, from cron):
 *   php scripts/send-kpi-recap.php --apply
 *
 * Cron: hourly via db/cron/maltytask-kpi-recap.cron (deployed DISABLED).
 */

// ── CLI guard ──────────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// ── Args parsing ───────────────────────────────────────────────────────────────
$dryRun  = true;
$scopeId = null;
$args    = $argv;
array_shift($args); // script name

while ($args) {
    $a = array_shift($args);
    if ($a === '--apply') {
        $dryRun = false;
    } elseif ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--user') {
        $raw = array_shift($args) ?? '';
        $scopeId = ctype_digit($raw) ? (int) $raw : null;
        if ($scopeId === null || $scopeId <= 0) {
            fwrite(STDERR, "[kpi-recap] --user requires a positive integer\n");
            exit(1);
        }
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/db.php';
require_once $baseDir . '/app/settings-helpers.php';
require_once $baseDir . '/app/kpi-handlers.php';
require_once $baseDir . '/app/services/mailer.php';
require_once $baseDir . '/app/kpi-email-render.php';
require_once $baseDir . '/app/kpi-recap-schedule.php';

$pdo = maltytask_pdo();

// ── Load due subscriptions ─────────────────────────────────────────────────────
$whereUser = $scopeId !== null ? ' AND s.user_id_fk = ?' : '';
$params    = $scopeId !== null ? [$scopeId] : [];

// In dry-run mode we ignore next_due_at so the operator can always preview.
$dueFilter = $dryRun ? '' : ' AND (s.next_due_at IS NULL OR s.next_due_at <= NOW())';

$stmt = $pdo->prepare(
    "SELECT s.id, s.user_id_fk, s.cadence, s.send_hour_local, s.send_dow, s.last_sent_at, s.next_due_at,
            u.email, u.display_name, u.username, u.role
       FROM user_kpi_recap_subs s
       JOIN users u ON u.id = s.user_id_fk
      WHERE s.is_active = 1
        AND u.is_active = 1
        AND u.email IS NOT NULL
        AND u.email != ''
        {$dueFilter}
        {$whereUser}
      ORDER BY s.user_id_fk"
);
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($subs)) {
    fwrite(STDOUT, "[kpi-recap] No due subscriptions" . ($dryRun ? " (dry-run)" : "") . ".\n");
    exit(0);
}

// ── Process each subscription ──────────────────────────────────────────────────
$brewery = brewery_identity();

foreach ($subs as $sub) {
    $userId      = (int)    $sub['user_id_fk'];
    $userEmail   = (string) $sub['email'];
    $displayName = (string) ($sub['display_name'] ?: $sub['username']);
    $cadence     = (string) $sub['cadence'];
    $userRole    = (string) $sub['role'];

    fwrite(STDOUT, "[kpi-recap] Processing user {$userId} ({$displayName}) cadence={$cadence}" .
        ($dryRun ? " [DRY-RUN]" : "") . "\n");

    // ── Load this cadence's tracker selections ──────────────────────────────
    $selStmt = $pdo->prepare(
        "SELECT urts.tracker_id_fk, urts.position,
                t.slug, t.label, t.source_domain, t.compute_handler,
                t.params_json, t.viz_type, t.category, t.min_role
           FROM user_recap_tracker_selections urts
           JOIN ref_kpi_trackers t ON t.id = urts.tracker_id_fk
          WHERE urts.user_id_fk = ?
            AND urts.cadence = ?
            AND t.data_ready = 1
            AND t.is_active = 1
          ORDER BY urts.position"
    );
    $selStmt->execute([$userId, $cadence]);
    $selectedTrackers = $selStmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter: role floor + finance gate (same logic as mt_build_allowed_set)
    $userRank = _role_rank_cli($userRole);
    $selectedTrackers = array_filter($selectedTrackers, function ($t) use ($userRank) {
        if ($userRank < _role_rank_cli($t['min_role'])) return false;
        if (in_array($t['category'], ['cogs_finance', 'sales'], true) && $userRank < _role_rank_cli('manager')) {
            return false;
        }
        // Skip stubbed domains — read the canonical list from kpi-handlers.php
        // (same source mon-tableau consumes), never a hardcoded copy. Returns []
        // today; tracks future stub regressions automatically.
        if (in_array($t['source_domain'], kpi_stub_domains(), true)) return false;
        return true;
    });
    $selectedTrackers = array_values($selectedTrackers);

    if (empty($selectedTrackers)) {
        fwrite(STDOUT, "[kpi-recap]   → skipped (no tracker selections for cadence={$cadence})\n");
        if (!$dryRun) {
            $sendHour = isset($sub['send_hour_local']) ? (int) $sub['send_hour_local'] : 8;
            $dow      = isset($sub['send_dow']) ? (int) $sub['send_dow'] : null;
            $nextDue  = kpi_recap_next_due($sendHour, $cadence, $dow ?: null);
            $pdo->prepare("UPDATE user_kpi_recap_subs SET next_due_at = ? WHERE id = ?")
                ->execute([$nextDue, (int) $sub['id']]);
            fwrite(STDOUT, "[kpi-recap]   → next_due_at advanced to {$nextDue} (empty selection)\n");
        }
        continue;
    }

    // ── Dispatch KPIs ──────────────────────────────────────────────────────────
    $kpiResults = [];
    foreach ($selectedTrackers as $tracker) {
        if (is_string($tracker['params_json'])) {
            $tracker['params_json'] = json_decode($tracker['params_json'], true) ?? [];
        }
        $kpiResults[] = [
            'tracker' => $tracker,
            'result'  => kpi_dispatch($tracker, $pdo),
        ];
    }

    // ── Render HTML digest ─────────────────────────────────────────────────────
    $cadenceLabel = match ($cadence) {
        'daily'   => 'Récap quotidien',
        'weekly'  => 'Récap hebdomadaire',
        'monthly' => 'Récap mensuel',
        default   => 'Récap KPI',
    };

    $breweryName = htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8');
    $dn          = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $dateLabel   = date('d/m/Y');

    // Build KPI tiles — rendering delegated to kpi-email-render.php
    $tilesHtml = '';
    foreach ($kpiResults as $item) {
        $tilesHtml .= kpi_email_render_viz($item['tracker'], $item['result']);
    }

    // Full email HTML (inline styles — no external CSS in email)
    $htmlBody = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#ede4d3;font-family:\'DM Sans\',Arial,Helvetica,sans-serif;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ede4d3">
  <tr><td align="center" style="padding:32px 16px;">
    <table cellpadding="0" cellspacing="0" border="0" width="520" style="background:#faf5ec;border:1px solid #c8bba8;border-radius:10px;overflow:hidden;">

      <!-- Header -->
      <tr><td style="background:#2c2414;padding:20px 28px;">
        <div style="font-family:Georgia,\'Times New Roman\',serif;font-size:18px;color:#f1e8d4;letter-spacing:.02em;">' . $breweryName . '</div>
        <div style="font-size:12px;color:#9a8f82;margin-top:2px;letter-spacing:.05em;text-transform:uppercase;">' . htmlspecialchars($cadenceLabel, ENT_QUOTES, 'UTF-8') . ' · ' . $dateLabel . '</div>
      </td></tr>

      <!-- Greeting -->
      <tr><td style="padding:24px 28px 12px;">
        <p style="margin:0 0 16px;font-size:14px;color:#2c2414;">Bonjour ' . $dn . ',</p>
        <p style="margin:0 0 20px;font-size:13px;color:#6a5f52;">Voici le récapitulatif de vos indicateurs sélectionnés.</p>
      </td></tr>

      <!-- KPI tiles -->
      <tr><td style="padding:0 28px 20px;">' . $tilesHtml . '</td></tr>

      <!-- Footer -->
      <tr><td style="background:#f0e8d8;padding:14px 28px;border-top:1px solid #d8cbb8;">
        <p style="margin:0;font-size:11px;color:#9a8f82;">
          Cet e-mail est généré automatiquement par MaltyTask · ' . $breweryName . '<br>
          Gérer vos préférences : <a href="https://app.maltytask.ch/modules/mon-tableau.php" style="color:#9eb060;">Mon tableau de bord</a>
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';

    // ── Send or dry-run output ─────────────────────────────────────────────────
    $subject = $cadenceLabel . ' MaltyTask — ' . $dateLabel;

    if ($dryRun) {
        fwrite(STDOUT, "[kpi-recap]   → DRY-RUN: would send to {$userEmail}\n");
        fwrite(STDOUT, "[kpi-recap]   Subject: {$subject}\n");
        fwrite(STDOUT, "[kpi-recap]   KPIs: " . count($kpiResults) . " trackers\n");
        fwrite(STDOUT, "---BEGIN HTML---\n" . $htmlBody . "\n---END HTML---\n");
        continue;
    }

    // ── Live send ──────────────────────────────────────────────────────────────
    $sent = send_mail($userEmail, $subject, $htmlBody);
    if ($sent) {
        fwrite(STDOUT, "[kpi-recap]   → sent to {$userEmail}\n");
    } else {
        fwrite(STDERR, "[kpi-recap]   → FAILED to send to {$userEmail}\n");
    }

    // ── Compute next_due_at via shared helper (app/kpi-recap-schedule.php) ──────
    $sendHour = isset($sub['send_hour_local']) ? (int) $sub['send_hour_local'] : 8;
    $dow      = isset($sub['send_dow']) ? (int) $sub['send_dow'] : null;
    $nextDue  = kpi_recap_next_due($sendHour, $cadence, $dow ?: null);

    // ── Update subscription timestamps ─────────────────────────────────────────
    $upd = $pdo->prepare(
        "UPDATE user_kpi_recap_subs
            SET last_sent_at = NOW(), next_due_at = ?
          WHERE id = ?"
    );
    $upd->execute([$nextDue, (int) $sub['id']]);
    fwrite(STDOUT, "[kpi-recap]   → next_due_at set to {$nextDue}\n");
}

fwrite(STDOUT, "[kpi-recap] Done.\n");

// ── Helper: role rank without loading auth.php (CLI can't load session) ────────
function _role_rank_cli(string $role): int
{
    return match ($role) {
        'viewer'   => 0,
        'operator' => 1,
        'manager'  => 2,
        'admin'    => 3,
        default    => 0,
    };
}
