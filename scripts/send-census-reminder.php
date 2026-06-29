<?php
declare(strict_types=1);
/**
 * scripts/send-census-reminder.php — FG census staleness reminder (CLI only)
 *
 * For each site whose FG inventory is overdue or has negative-stock SKUs,
 * sends a reminder email to the operator(s) responsible for that site
 * (mode='on') or a consolidated test digest to a fixed address (mode='test').
 *
 * Flags:
 *   --dry-run    Render emails to stdout; send nothing. (default)
 *   --apply      Send for real.
 *   --now        Bypass the day-of-week / hour schedule gate (for testing).
 *
 * Usage:
 *   php scripts/send-census-reminder.php --now --dry-run
 *   php scripts/send-census-reminder.php --now --apply     # live test
 *   php scripts/send-census-reminder.php --apply           # from cron
 *
 * Cron: hourly via db/cron/maltytask-census-reminder.cron (deployed DISABLED).
 * The script self-gates on census_reminder_dow + census_reminder_hour so
 * hourly invocation is safe — it exits 0 on all off-schedule hours.
 */

// ── CLI guard ──────────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// ── Args parsing ───────────────────────────────────────────────────────────────
$dryRun          = true;
$bypassSchedule  = false;
$args            = $argv;
array_shift($args); // script name

while ($args) {
    $a = array_shift($args);
    if ($a === '--apply') {
        $dryRun = false;
    } elseif ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--now') {
        $bypassSchedule = true;
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/db.php';
require_once $baseDir . '/app/settings.php';
require_once $baseDir . '/app/settings-helpers.php';
require_once $baseDir . '/app/services/mailer.php';
require_once $baseDir . '/app/census-responsibility.php'; // also requires settings.php, fg-stock.php, fulfilment-site.php

$pdo = maltytask_pdo();

// ── Mode check ─────────────────────────────────────────────────────────────────
$mode = (string) system_setting('census_reminder_email_mode', 'fulfilment', 'off');

if ($mode === 'off') {
    fwrite(STDOUT, "[census-reminder] disabled (mode=off).\n");
    exit(0);
}

if ($mode !== 'test' && $mode !== 'on') {
    fwrite(STDOUT, "[census-reminder] unknown mode '{$mode}' — treating as off.\n");
    exit(0);
}

// ── Schedule gate (skipped when --now) ────────────────────────────────────────
if (!$bypassSchedule) {
    $localNow  = new DateTimeImmutable('now', new DateTimeZone(app_timezone()));
    $curHour   = (int) $localNow->format('G'); // 0-23
    $curDow    = (int) $localNow->format('N'); // 1=Mon..7=Sun ISO
    $wantHour  = (int) system_setting('census_reminder_hour', 'fulfilment', 7);
    $wantDow   = (int) system_setting('census_reminder_dow',  'fulfilment', 1);

    if ($curDow !== $wantDow || $curHour !== $wantHour) {
        fwrite(STDOUT, "[census-reminder] not scheduled now (dow={$curDow}/{$wantDow} hour={$curHour}/{$wantHour}); use --now to override.\n");
        exit(0);
    }
}

// ── Fetch site statuses ────────────────────────────────────────────────────────
// census_site_status() applies the staleness threshold internally.
$statuses = census_site_status($pdo);

// Sites that need attention: overdue OR have negative-stock SKUs
$flagged = array_filter($statuses, fn($s) => $s['is_overdue'] === true || $s['negatives'] > 0);

if (empty($flagged)) {
    fwrite(STDOUT, "[census-reminder] all sites fresh — nothing to send.\n");
    exit(0);
}

$brewery = brewery_identity();

// ── Build and dispatch email(s) ────────────────────────────────────────────────
if ($mode === 'test') {
    // ONE consolidated email to the test address, ALL flagged sites, greeting 'Équipe'
    $testTo = (string) system_setting('census_reminder_test_to', 'fulfilment', 'kouros@lanebuleuse.ch');
    if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDOUT, "[census-reminder] mode=test but census_reminder_test_to is empty or invalid — nothing sent.\n");
        exit(0);
    }

    $subject  = census_reminder_subject(array_values($flagged));
    $htmlBody = census_reminder_html('Équipe', array_values($flagged), $brewery);

    if ($dryRun) {
        fwrite(STDOUT, "[census-reminder] DRY-RUN (mode=test) → would send to {$testTo}\n");
        fwrite(STDOUT, "[census-reminder] Subject: {$subject}\n");
        fwrite(STDOUT, "[census-reminder] Flagged sites: " . implode(', ', array_column(array_values($flagged), 'name')) . "\n");
        fwrite(STDOUT, "---BEGIN HTML---\n" . $htmlBody . "\n---END HTML---\n");
    } else {
        $sent = send_mail($testTo, $subject, $htmlBody);
        if ($sent) {
            fwrite(STDOUT, "[census-reminder] sent to {$testTo} (mode=test)\n");
        } else {
            fwrite(STDERR, "[census-reminder] FAILED to send to {$testTo} (mode=test)\n");
        }
    }
} else {
    // mode='on' — one email per responsible user, listing only their site(s)
    // Accumulate flagged sites under each responsible user (keyed by user id)
    $userSites = []; // [userId => ['user' => [...], 'sites' => [...]]]

    foreach ($flagged as $site) {
        $siteId   = (int) $site['site_id'];
        $responsible = census_responsible_users_for_site($pdo, $siteId);

        if (empty($responsible)) {
            fwrite(STDOUT, "[census-reminder] site '" . $site['name'] . "' flagged but has no responsible user — skipped (mode=on)\n");
            continue;
        }

        foreach ($responsible as $uid => $user) {
            if (!isset($userSites[$uid])) {
                $userSites[$uid] = ['user' => $user, 'sites' => []];
            }
            $userSites[$uid]['sites'][] = $site;
        }
    }

    foreach ($userSites as $uid => $entry) {
        $user      = $entry['user'];
        $sites     = $entry['sites'];
        $recipient = $user['email'];
        $name      = $user['display_name'];
        $subject   = census_reminder_subject($sites);
        $htmlBody  = census_reminder_html($name, $sites, $brewery);

        if ($dryRun) {
            fwrite(STDOUT, "[census-reminder] DRY-RUN (mode=on) → would send to {$recipient} ({$name})\n");
            fwrite(STDOUT, "[census-reminder] Subject: {$subject}\n");
            fwrite(STDOUT, "[census-reminder] Flagged sites: " . implode(', ', array_column($sites, 'name')) . "\n");
            fwrite(STDOUT, "---BEGIN HTML---\n" . $htmlBody . "\n---END HTML---\n");
        } else {
            $sent = send_mail($recipient, $subject, $htmlBody);
            if ($sent) {
                fwrite(STDOUT, "[census-reminder] sent to {$recipient} ({$name})\n");
            } else {
                fwrite(STDERR, "[census-reminder] FAILED to send to {$recipient} ({$name})\n");
            }
        }
    }
}

fwrite(STDOUT, "[census-reminder] Done.\n");

// ── Email helpers (defined in this file — NOT imported from kpi-email-render.php) ──

/**
 * Build the email subject for a list of flagged sites.
 * Caps the site name join: ≤2 sites → comma-joined; >2 → first name + "+N autres".
 *
 * @param list<array{name: string, ...}> $sites
 */
function census_reminder_subject(array $sites): string
{
    $count = count($sites);
    if ($count === 0) {
        return 'Inventaire FG à recompter';
    }
    if ($count <= 2) {
        $joined = implode(', ', array_column($sites, 'name'));
    } else {
        $joined = $sites[0]['name'] . ' +' . ($count - 1) . ' autres';
    }
    return 'Inventaire FG à recompter — ' . $joined;
}

/**
 * Render the inline-styled HTML reminder email.
 *
 * ALL dynamic strings are htmlspecialchars-escaped before insertion.
 * No <style> block, no <script>, no SVG, no external CSS — inline styles only
 * (required for email client compatibility).
 *
 * @param string                          $greetingName  Recipient display name or 'Équipe'
 * @param list<array{
 *   site_id:      int,
 *   name:         string,
 *   days_since:   int|null,
 *   is_overdue:   bool,
 *   negatives:    int,
 * }>                                     $flaggedSites
 * @param array{name: string, city?: string, country?: string} $brewery
 */
function census_reminder_html(string $greetingName, array $flaggedSites, array $brewery): string
{
    $breweryName = htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8');
    $dn          = htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8');
    // Local date (app_timezone) — the VPS clock is UTC; near midnight date() would show the wrong day.
    $dateLabel   = (new DateTimeImmutable('now', new DateTimeZone(app_timezone())))->format('d/m/Y');

    // ── Per-site cards ─────────────────────────────────────────────────────────
    $cards = '';
    foreach ($flaggedSites as $site) {
        $siteName = htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8');
        $siteId   = (int) $site['site_id'];

        if ($site['days_since'] === null) {
            $lastLine = 'Jamais inventorié';
        } else {
            $j        = (int) $site['days_since'];
            $unit     = ($j === 1) ? 'jour' : 'jours';
            $lastLine = "Dernier inventaire il y a {$j} {$unit}";
        }

        $negativeLine = '';
        if ($site['negatives'] > 0) {
            $n            = (int) $site['negatives'];
            $negativeLine = '<p style="margin:6px 0 0;font-size:12px;color:#b5532e;">'
                . $n . ' SKU en négatif'
                . '</p>';
        }

        $ctaUrl = 'https://app.maltytask.ch/modules/expeditions.php?view=stocktake&amp;loc=' . $siteId;

        $cards .= '
      <div style="background:#faf5ec;border:1px solid #c8bba8;border-radius:8px;padding:16px 20px;margin-bottom:12px;">
        <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#2c2414;">' . $siteName . '</p>
        <p style="margin:0;font-size:13px;color:#6a5f52;">' . htmlspecialchars($lastLine, ENT_QUOTES, 'UTF-8') . '</p>'
            . $negativeLine . '
        <table cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
          <tr>
            <td bgcolor="#9eb060" style="border-radius:6px;padding:10px 18px;">
              <a href="' . $ctaUrl . '" style="font-size:13px;color:#ffffff;text-decoration:none;display:block;">Recompter ce site &rarr;</a>
            </td>
          </tr>
        </table>
      </div>';
    }

    return '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#ede4d3;font-family:\'DM Sans\',Arial,Helvetica,sans-serif;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ede4d3">
  <tr><td align="center" style="padding:32px 16px;">
    <table cellpadding="0" cellspacing="0" border="0" width="520" style="background:#faf5ec;border:1px solid #c8bba8;border-radius:10px;overflow:hidden;">

      <!-- Header -->
      <tr><td style="background:#2c2414;padding:20px 28px;">
        <div style="font-family:Georgia,\'Times New Roman\',serif;font-size:18px;color:#f1e8d4;letter-spacing:.02em;">' . $breweryName . '</div>
        <div style="font-size:12px;color:#9a8f82;margin-top:2px;letter-spacing:.05em;text-transform:uppercase;">Rappel inventaire &middot; ' . $dateLabel . '</div>
      </td></tr>

      <!-- Greeting -->
      <tr><td style="padding:24px 28px 12px;">
        <p style="margin:0 0 8px;font-size:14px;color:#2c2414;">Bonjour ' . $dn . ',</p>
        <p style="margin:0 0 20px;font-size:13px;color:#6a5f52;">Les sites suivants n&eacute;cessitent un nouvel inventaire des produits finis&nbsp;:</p>
      </td></tr>

      <!-- Site cards -->
      <tr><td style="padding:0 28px 20px;">' . $cards . '</td></tr>

      <!-- Footer -->
      <tr><td style="background:#f0e8d8;padding:14px 28px;border-top:1px solid #d8cbb8;">
        <p style="margin:0;font-size:11px;color:#9a8f82;">E-mail automatique &middot; ' . $breweryName . ' &middot; Rappel inventaire FG</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>';
}
