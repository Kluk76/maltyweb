<?php
declare(strict_types=1);
/**
 * scripts/send-census-reminder.php — FG census reminders (CLI only)
 *
 * Two independent passes run on every invocation:
 *
 *  1. OVERDUE PASS — weekly staleness / negative-stock reminder per site.
 *     Gated by system_settings census_reminder_email_mode ('on'/'test'/'off').
 *     Fires on the configured dow + hour (skipped with --now).
 *
 *  2. MONTH-CLOSE PASS — month-end FG census reminder.
 *     Gated by system_settings census_month_close_reminder_mode ('on'/'test'/'off').
 *     Fires only within the configured lead-day window before month end;
 *     suppressed once the month is sealed or all sites have a month_end census.
 *
 * The two passes are INDEPENDENT: either can fire even when the other is 'off'.
 *
 * Flags:
 *   --dry-run    Render emails to stdout; send nothing. (default)
 *   --apply      Send for real.
 *   --now        Bypass the day-of-week / hour schedule gate (for testing).
 *                NOTE: the month-close lead-window gate is ALWAYS enforced,
 *                even with --now.
 *
 * Usage:
 *   php scripts/send-census-reminder.php --now --dry-run
 *   php scripts/send-census-reminder.php --now --apply     # live test
 *   php scripts/send-census-reminder.php --apply           # from cron
 *
 * Cron: hourly via db/cron/maltytask-census-reminder.cron (deployed DISABLED).
 * Both passes self-gate so hourly invocation is safe.
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
require_once $baseDir . '/app/stocktake-helpers.php';     // exp_st_month_is_sealed()

$pdo = maltytask_pdo();

run_overdue_pass($pdo, $dryRun, $bypassSchedule);
run_month_close_pass($pdo, $dryRun, $bypassSchedule);
fwrite(STDOUT, "[census-reminder] Done.\n");

// ── Pass 1: weekly overdue-site reminder ───────────────────────────────────────

function run_overdue_pass(PDO $pdo, bool $dryRun, bool $bypassSchedule): void
{
    // ── Mode check ─────────────────────────────────────────────────────────────
    $mode = (string) system_setting('census_reminder_email_mode', 'fulfilment', 'off');

    if ($mode === 'off') {
        fwrite(STDOUT, "[census-reminder] disabled (mode=off).\n");
        return;
    }

    if ($mode !== 'test' && $mode !== 'on') {
        fwrite(STDOUT, "[census-reminder] unknown mode '{$mode}' — treating as off.\n");
        return;
    }

    // ── Schedule gate (skipped when --now) ────────────────────────────────────
    if (!$bypassSchedule) {
        $localNow  = new DateTimeImmutable('now', new DateTimeZone(app_timezone()));
        $curHour   = (int) $localNow->format('G'); // 0-23
        $curDow    = (int) $localNow->format('N'); // 1=Mon..7=Sun ISO
        $wantHour  = (int) system_setting('census_reminder_hour', 'fulfilment', 7);
        $wantDow   = (int) system_setting('census_reminder_dow',  'fulfilment', 1);

        if ($curDow !== $wantDow || $curHour !== $wantHour) {
            fwrite(STDOUT, "[census-reminder] not scheduled now (dow={$curDow}/{$wantDow} hour={$curHour}/{$wantHour}); use --now to override.\n");
            return;
        }
    }

    // ── Fetch site statuses ───────────────────────────────────────────────────
    // census_site_status() applies the staleness threshold internally.
    $statuses = census_site_status($pdo);

    // Sites that need attention: overdue OR have negative-stock SKUs
    $flagged = array_filter($statuses, fn($s) => $s['is_overdue'] === true || $s['negatives'] > 0);

    if (empty($flagged)) {
        fwrite(STDOUT, "[census-reminder] all sites fresh — nothing to send.\n");
        return;
    }

    $brewery = brewery_identity();

    // ── Build and dispatch email(s) ───────────────────────────────────────────
    if ($mode === 'test') {
        // ONE consolidated email to the test address, ALL flagged sites, greeting 'Équipe'
        $testTo = (string) system_setting('census_reminder_test_to', 'fulfilment', 'kouros@lanebuleuse.ch');
        if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDOUT, "[census-reminder] mode=test but census_reminder_test_to is empty or invalid — nothing sent.\n");
            return;
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
            $siteId      = (int) $site['site_id'];
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
}

// ── Pass 2: month-close FG census reminder ─────────────────────────────────────

function run_month_close_pass(PDO $pdo, bool $dryRun, bool $bypassSchedule): void
{
    // (A) Mode check — independent of the overdue pass mode
    $mcMode = (string) system_setting('census_month_close_reminder_mode', 'fulfilment', 'off');

    if ($mcMode === 'off') {
        fwrite(STDOUT, "[census-reminder] month-close pass disabled (mode=off).\n");
        return;
    }

    if ($mcMode !== 'test' && $mcMode !== 'on') {
        fwrite(STDOUT, "[census-reminder] month-close: unknown mode '{$mcMode}' — treating as off.\n");
        return;
    }

    // (B) Compute local time once
    $localNow  = new DateTimeImmutable('now', new DateTimeZone(app_timezone()));
    $monthKey  = $localNow->format('Y-m');          // month being closed = current calendar month
    $curHour   = (int) $localNow->format('G');
    $lastDay   = (int) $localNow->format('t');
    $today     = (int) $localNow->format('j');
    $remaining = $lastDay - $today;                 // whole days left in the month (0 on the last day)

    // (C) Lead-window gate — enforced even under --now so cron noise doesn't spam mid-month
    $leadDays = (int) system_setting('census_month_close_lead_days', 'fulfilment', 3);
    if ($remaining > $leadDays) {
        fwrite(STDOUT, "[census-reminder] month-close: not within lead window (remaining={$remaining}, lead={$leadDays}).\n");
        return;
    }

    // (D) Hour gate (once-per-day; SKIPPED only when $bypassSchedule)
    $wantHour = (int) system_setting('census_reminder_hour', 'fulfilment', 7);
    if (!$bypassSchedule && $curHour !== $wantHour) {
        fwrite(STDOUT, "[census-reminder] month-close: not the send hour ({$curHour}/{$wantHour}); use --now to override.\n");
        return;
    }

    // (E1) Seal check — fails closed on error (exp_st_month_is_sealed: app/stocktake-helpers.php:55)
    if (exp_st_month_is_sealed($pdo, $monthKey)) {
        fwrite(STDOUT, "[census-reminder] month-close: {$monthKey} already sealed — nothing to send.\n");
        return;
    }

    // (E2) Find production sites still missing a month_end census for $monthKey
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name
           FROM ref_sites s
          WHERE s.site_type = \'production\'
            AND s.holds_fg_stock = 1
            AND s.is_active = 1
            AND NOT EXISTS (
                  SELECT 1 FROM inv_fg_stocktake t
                   WHERE t.location_id_fk = s.id
                     AND t.count_type   = \'month_end\'
                     AND t.month_closed = ?
                     AND t.is_active    = 1
                )
          ORDER BY s.sort_order ASC, s.id ASC'
    );
    $stmt->execute([$monthKey]);
    $rawSites     = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $missingSites = array_map(
        fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
        $rawSites
    );

    if (empty($missingSites)) {
        fwrite(STDOUT, "[census-reminder] month-close: all production sites have a month_end census for {$monthKey} (or none configured) — nothing to send.\n");
        return;
    }

    // (F) Build month display label in French
    $frMonths = [
        1  => 'Janvier',  2  => 'Février',   3  => 'Mars',
        4  => 'Avril',    5  => 'Mai',        6  => 'Juin',
        7  => 'Juillet',  8  => 'Août',       9  => 'Septembre',
        10 => 'Octobre',  11 => 'Novembre',   12 => 'Décembre',
    ];
    $monthLabel = $frMonths[(int) $localNow->format('n')] . ' ' . $localNow->format('Y'); // e.g. "Juin 2026"

    // (G) Recipients + dispatch
    $subject = 'Inventaire de clôture mensuelle à faire — ' . $monthLabel;
    $brewery = brewery_identity();

    if ($mcMode === 'test') {
        $to = (string) system_setting('census_reminder_test_to', 'fulfilment', 'kouros@lanebuleuse.ch');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            fwrite(STDOUT, "[census-reminder] month-close: mode=test but census_reminder_test_to empty/invalid — nothing sent.\n");
            return;
        }
        $htmlBody = census_month_close_html($monthLabel, $missingSites, $brewery);

        if ($dryRun) {
            fwrite(STDOUT, "[census-reminder] DRY-RUN (month-close, mode=test) → would send to {$to}\n");
            fwrite(STDOUT, "[census-reminder] Subject: {$subject}\n");
            fwrite(STDOUT, "[census-reminder] Missing sites: " . implode(', ', array_column($missingSites, 'name')) . "\n");
            fwrite(STDOUT, "---BEGIN HTML---\n" . $htmlBody . "\n---END HTML---\n");
        } else {
            $sent = send_mail($to, $subject, $htmlBody);
            if ($sent) {
                fwrite(STDOUT, "[census-reminder] month-close: sent to {$to} (mode=test)\n");
            } else {
                fwrite(STDERR, "[census-reminder] month-close: FAILED to send to {$to} (mode=test)\n");
            }
        }
    } else {
        // mode='on' — one email per manager/admin
        $recipients = census_month_close_recipients($pdo);
        if (empty($recipients)) {
            fwrite(STDOUT, "[census-reminder] month-close: mode=on but no manager/admin recipients — nothing sent.\n");
            return;
        }
        foreach ($recipients as $recipient) {
            $html = census_month_close_html($monthLabel, $missingSites, $brewery, $recipient['display_name']);
            if ($dryRun) {
                fwrite(STDOUT, "[census-reminder] DRY-RUN (month-close, mode=on) → would send to {$recipient['email']} ({$recipient['display_name']})\n");
                fwrite(STDOUT, "[census-reminder] Subject: {$subject}\n");
                fwrite(STDOUT, "[census-reminder] Missing sites: " . implode(', ', array_column($missingSites, 'name')) . "\n");
                fwrite(STDOUT, "---BEGIN HTML---\n" . $html . "\n---END HTML---\n");
            } else {
                $sent = send_mail($recipient['email'], $subject, $html);
                if ($sent) {
                    fwrite(STDOUT, "[census-reminder] month-close: sent to {$recipient['email']} ({$recipient['display_name']})\n");
                } else {
                    fwrite(STDERR, "[census-reminder] month-close: FAILED to send to {$recipient['email']} ({$recipient['display_name']})\n");
                }
            }
        }
    }
}

// ── Email helpers (overdue pass — defined in this file, NOT imported from kpi-email-render.php) ──

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

// ── Email helpers (month-close pass) ──────────────────────────────────────────
// Note: recipients come from census_month_close_recipients() in
// app/census-responsibility.php (managers + admins) — NOT redefined here.

/**
 * Render the inline-styled HTML month-close census reminder email.
 *
 * ALL dynamic strings are htmlspecialchars-escaped before insertion.
 * No <style> block, no <script>, no SVG, no external CSS — inline styles only
 * (required for email client compatibility).
 *
 * @param string $monthLabel   French display label, e.g. "Juin 2026"
 * @param list<array{id: int, name: string}> $missingSites
 * @param array{name: string, city?: string, country?: string} $brewery
 * @param string $greetingName Recipient display name or 'Équipe'
 */
function census_month_close_html(
    string $monthLabel,
    array  $missingSites,
    array  $brewery,
    string $greetingName = 'Équipe'
): string {
    $breweryName = htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8');
    $dn          = htmlspecialchars($greetingName,    ENT_QUOTES, 'UTF-8');
    $ml          = htmlspecialchars($monthLabel,      ENT_QUOTES, 'UTF-8');

    // Build list items for each missing site
    $siteItems = '';
    foreach ($missingSites as $site) {
        $siteName   = htmlspecialchars((string) $site['name'], ENT_QUOTES, 'UTF-8');
        $siteItems .= '<li style="margin:4px 0;font-size:13px;color:#2c2414;">' . $siteName . '</li>';
    }

    // No & in this URL — plain string is safe as-is in the href attribute
    $ctaUrl = 'https://app.maltytask.ch/modules/expeditions.php?view=stocktake';

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
        <div style="font-size:12px;color:#9a8f82;margin-top:2px;letter-spacing:.05em;text-transform:uppercase;">Cl&ocirc;ture mensuelle &middot; ' . $ml . '</div>
      </td></tr>

      <!-- Greeting -->
      <tr><td style="padding:24px 28px 12px;">
        <p style="margin:0 0 8px;font-size:14px;color:#2c2414;">Bonjour ' . $dn . ',</p>
        <p style="margin:0 0 20px;font-size:13px;color:#6a5f52;">L\'inventaire de cl&ocirc;ture des produits finis pour <strong>' . $ml . '</strong> doit &ecirc;tre effectu&eacute; avant de pouvoir cl&ocirc;turer le mois. Les sites suivants n\'ont pas encore r&eacute;alis&eacute; leur inventaire de cl&ocirc;ture&nbsp;:</p>
      </td></tr>

      <!-- Missing sites block -->
      <tr><td style="padding:0 28px 20px;">
        <div style="background:#faf5ec;border:1px solid #c8bba8;border-radius:8px;padding:16px 20px;">
          <p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#2c2414;">Sites &agrave; inventorier&nbsp;:</p>
          <ul style="margin:0;padding:0 0 0 18px;">' . $siteItems . '</ul>
          <table cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;">
            <tr>
              <td bgcolor="#9eb060" style="border-radius:6px;padding:10px 18px;">
                <a href="' . $ctaUrl . '" style="font-size:13px;color:#ffffff;text-decoration:none;display:block;">Faire l\'inventaire de cl&ocirc;ture &rarr;</a>
              </td>
            </tr>
          </table>
        </div>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f0e8d8;padding:14px 28px;border-top:1px solid #d8cbb8;">
        <p style="margin:0;font-size:11px;color:#9a8f82;">E-mail automatique &middot; ' . $breweryName . ' &middot; Cl&ocirc;ture mensuelle</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>';
}
