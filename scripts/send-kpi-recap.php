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

$pdo = maltytask_pdo();

// ── Load due subscriptions ─────────────────────────────────────────────────────
$whereUser = $scopeId !== null ? ' AND s.user_id_fk = ?' : '';
$params    = $scopeId !== null ? [$scopeId] : [];

// In dry-run mode we ignore next_due_at so the operator can always preview.
$dueFilter = $dryRun ? '' : ' AND (s.next_due_at IS NULL OR s.next_due_at <= NOW())';

$stmt = $pdo->prepare(
    "SELECT s.id, s.user_id_fk, s.cadence, s.last_sent_at, s.next_due_at,
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

    // ── Load this user's selected trackers (same gate as mon-tableau) ──────────
    $selStmt = $pdo->prepare(
        "SELECT uks.tracker_id_fk, uks.position,
                t.slug, t.label, t.source_domain, t.compute_handler,
                t.params_json, t.viz_type, t.category, t.min_role
           FROM user_kpi_selections uks
           JOIN ref_kpi_trackers t ON t.id = uks.tracker_id_fk
          WHERE uks.user_id_fk = ?
            AND t.data_ready = 1
            AND t.is_active = 1
          ORDER BY uks.position"
    );
    $selStmt->execute([$userId]);
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
        fwrite(STDOUT, "[kpi-recap]   → skipped (no tracker selections)\n");
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

    // Build KPI tiles
    $tilesHtml = '';
    foreach ($kpiResults as $item) {
        $t = $item['tracker'];
        $r = $item['result'];

        $label = htmlspecialchars($t['label'], ENT_QUOTES, 'UTF-8');
        $value = $r['value'] ?? null;
        $unit  = htmlspecialchars((string) ($r['unit'] ?? ''), ENT_QUOTES, 'UTF-8');

        // Format value
        if ($value === null) {
            $valueDisplay = '<span style="color:#9a8f82;">—</span>';
        } elseif (is_float($value)) {
            $valueDisplay = htmlspecialchars(number_format($value, 1, ',', ' '), ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $valueDisplay = htmlspecialchars(number_format($value, 0, ',', ' '), ENT_QUOTES, 'UTF-8');
        } else {
            $valueDisplay = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        // Delta
        $delta = $r['delta'] ?? null;
        $deltaHtml = '';
        if ($delta !== null) {
            $sign  = $delta >= 0 ? '+' : '';
            $color = match ($r['tint'] ?? 'neutral') {
                'green'  => '#5a8a5a',
                'red'    => '#a04040',
                'amber'  => '#a07020',
                default  => '#9a8f82',
            };
            $deltaLabel = htmlspecialchars((string) ($r['delta_label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $deltaHtml  = '<div style="font-size:11px;color:' . $color . ';margin-top:2px;">'
                        . htmlspecialchars($sign . number_format((float) $delta, 1, ',', ' '), ENT_QUOTES, 'UTF-8')
                        . ' ' . $deltaLabel . '</div>';
        }

        // Inline sparkline: simple inline-SVG bar chart if series available
        $sparkHtml = '';
        $series = $r['series'] ?? null;
        if (is_array($series) && count($series) >= 2) {
            $vals = array_column($series, 'value');
            $maxV = max(array_filter($vals, 'is_numeric')) ?: 1;
            $w    = 80;
            $h    = 24;
            $barW = max(2, (int) floor($w / count($vals)) - 1);
            $svgBars = '';
            foreach ($vals as $i => $sv) {
                $sv = is_numeric($sv) ? (float) $sv : 0;
                $bh = max(1, (int) round($sv / $maxV * ($h - 2)));
                $x  = $i * ($barW + 1);
                $y  = $h - $bh;
                $svgBars .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$barW}\" height=\"{$bh}\" fill=\"#9eb060\" rx=\"1\"/>";
            }
            $sparkHtml = '<div style="margin-top:6px;">'
                . "<svg width=\"{$w}\" height=\"{$h}\" viewBox=\"0 0 {$w} {$h}\" xmlns=\"http://www.w3.org/2000/svg\" style=\"display:block;\">{$svgBars}</svg>"
                . '</div>';
        }

        $tilesHtml .= '
        <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;background:#f9f3e8;border:1px solid #d8cbb8;border-radius:6px;">
          <tr>
            <td style="padding:12px 16px;">
              <div style="font-family:\'DM Sans\',Arial,sans-serif;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#9a8f82;margin-bottom:4px;">' . $label . '</div>
              <div style="font-family:\'JetBrains Mono\',\'Courier New\',monospace;font-size:22px;font-weight:500;color:#2c2414;line-height:1;">'
                  . $valueDisplay . '<span style="font-size:13px;color:#9a8f82;margin-left:4px;">' . $unit . '</span>'
              . '</div>'
              . $deltaHtml
              . $sparkHtml
              . '</td>
          </tr>
        </table>';
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

    // ── Compute next_due_at ────────────────────────────────────────────────────
    $nextDue = match ($cadence) {
        'daily'   => date('Y-m-d H:i:s', strtotime('+1 day')),
        'weekly'  => date('Y-m-d H:i:s', strtotime('+7 days')),
        'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
        default   => date('Y-m-d H:i:s', strtotime('+1 day')),
    };

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
