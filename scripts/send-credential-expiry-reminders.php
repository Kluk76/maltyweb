<?php
declare(strict_types=1);
/**
 * scripts/send-credential-expiry-reminders.php — Credential-expiry watchdog (CLI only)
 *
 * For each active row in ops_credential_expiry this script:
 *   1. Computes days_left = DATEDIFF(expires_on, CURDATE()).
 *   2. Parses lead_days (e.g. "30,14,7,1") into sorted thresholds (descending).
 *   3. Determines the largest threshold S where days_left <= S AND (last_reminded_stage IS NULL
 *      OR S < last_reminded_stage). This means we fire each threshold exactly once, largest-first,
 *      walking down toward 1 as time passes.
 *   4. Post-expiry (days_left < 0): fires an "EXPIRED" notice once (sentinel last_reminded_stage=0).
 *      After that, the row is silent until expires_on is updated (which resets last_reminded_stage to NULL).
 *   5. On --apply: sends the email via send_mail() and writes last_reminded_stage to the DB.
 *      On --dry-run (default): prints what WOULD be sent, no DB write, no email.
 *   6. If no rows are due across all active credentials: exits 0 silently.
 *
 * Recipient precedence:
 *   1. Row-level ops_credential_expiry.recipient (if not null/empty).
 *   2. system_settings WHERE section='ops' AND key_name='credential_alert_recipient'.
 *   3. All users WHERE role='admin' AND is_active=1 (joined as comma list).
 *   4. If even the admin list is empty: logs a warning and skips sending for this row.
 *
 * Flags:
 *   --dry-run   Print what WOULD be sent; no email, no DB write. (DEFAULT)
 *   --apply     Send for real and update last_reminded_stage in DB.
 *
 * Usage (dry-run preview):
 *   php scripts/send-credential-expiry-reminders.php --dry-run
 *
 * Usage (live, from cron):
 *   php scripts/send-credential-expiry-reminders.php --apply
 *
 * Cron: daily via db/cron/maltytask-credential-expiry.cron (deployed DISABLED).
 */

// ── CLI guard ──────────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

// ── Args parsing ───────────────────────────────────────────────────────────────
$dryRun = true;
$args   = $argv;
array_shift($args); // strip script name

while ($args) {
    $a = array_shift($args);
    if ($a === '--apply') {
        $dryRun = false;
    } elseif ($a === '--dry-run') {
        $dryRun = true;
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/db.php';
require_once $baseDir . '/app/settings-helpers.php';
require_once $baseDir . '/app/services/mailer.php';

$pdo = maltytask_pdo();

// ── Helper: resolve fallback recipient from system_settings or admin users ────
function resolve_recipient(PDO $pdo, ?string $rowRecipient): ?string
{
    // 1. Row-level override
    if ($rowRecipient !== null && trim($rowRecipient) !== '') {
        return trim($rowRecipient);
    }

    // 2. system_settings.ops.credential_alert_recipient
    $stmt = $pdo->prepare(
        "SELECT value_text FROM system_settings
          WHERE section = 'ops' AND key_name = 'credential_alert_recipient' AND is_active = 1
          LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['value_text'])) {
        return trim((string) $row['value_text']);
    }

    // 3. All admin users
    $admins = $pdo->query(
        "SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email != '' ORDER BY id"
    );
    if ($admins) {
        $emails = array_column($admins->fetchAll(PDO::FETCH_ASSOC), 'email');
        if (!empty($emails)) {
            return implode(',', $emails);
        }
    }

    return null;
}

// ── Helper: parse lead_days string into sorted thresholds (ascending) ─────────
function parse_lead_days(string $leadDays): array
{
    $parts = array_filter(
        array_map('intval', explode(',', $leadDays)),
        fn($v) => $v > 0
    );
    $thresholds = array_unique(array_values($parts));
    sort($thresholds); // ascending: 1, 7, 14, 30
    return $thresholds;
}

// ── Helper: determine which stage to fire (or null if nothing due) ────────────
//
// Semantics: "tightest bracket not yet sent".
// Thresholds sorted ascending (1, 7, 14, 30).
// Find the SMALLEST S where days_left <= S AND S < lastStage (or NULL).
// "Smallest S that applies" = tightest bracket. Iterating ascending means
// first qualifying S is the answer.
//
// Example: thresholds=[1,7,14,30], days_left=5, lastStage=NULL
//   s=1: 5<=1? No → skip
//   s=7: 5<=7? Yes, NULL → fire stage=7
//
// After firing 7 (lastStage=7), days_left=1:
//   s=1: 1<=1? Yes, 1<7? Yes → fire stage=1
//
// After firing 1 (lastStage=1), days_left=-2 (expired):
//   → hits daysLeft<0 branch → fire expired notice (stage=0 sentinel)
//
// Sentinel 0 = "EXPIRED notice already sent".
function determine_stage(int $daysLeft, array $thresholds, ?int $lastStage): ?int
{
    // Post-expiry: days_left < 0 — fire once with sentinel 0 if not already done.
    if ($daysLeft < 0) {
        // lastStage === 0 means the expired notice was already sent: don't re-fire.
        if ($lastStage === 0) {
            return null;
        }
        return 0; // sentinel for "expired" notice
    }

    // Within-expiry: thresholds are sorted ascending (1, 7, 14, 30).
    // Find the SMALLEST S where:
    //   - days_left <= S  (we are within this alert window), AND
    //   - S < lastStage (or lastStage is NULL)  (this threshold not yet sent).
    //
    // Because we iterate ascending, the first S that satisfies both conditions
    // is the tightest bracket — return it immediately.
    foreach ($thresholds as $s) {
        if ($daysLeft <= $s && ($lastStage === null || $s < $lastStage)) {
            return $s;
        }
    }

    return null;
}

// ── Helper: build subject and HTML body for a reminder ────────────────────────
function build_email(array $cred, int $daysLeft, int $stage, array $brewery): array
{
    $label = htmlspecialchars((string) $cred['label'], ENT_QUOTES, 'UTF-8');
    $credKey = ($cred['cred_key'] !== null && trim((string) $cred['cred_key']) !== '')
        ? htmlspecialchars(trim((string) $cred['cred_key']), ENT_QUOTES, 'UTF-8')
        : null;
    $expiresOn = htmlspecialchars((string) $cred['expires_on'], ENT_QUOTES, 'UTF-8');
    $breweryName = htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8');
    $dateLabel = date('d/m/Y');

    if ($stage === 0) {
        // Expired notice
        $absDays = abs($daysLeft);
        $subject = "[MaltyTask] EXPIRÉ — {$cred['label']} (il y a {$absDays} j)";
        $urgencyColor = '#c0392b';
        $urgencyLabel = "EXPIRÉ depuis {$absDays} jour" . ($absDays > 1 ? 's' : '');
        $urgencyNote  = "Ce secret est <strong>déjà expiré</strong> depuis {$absDays} jour"
                      . ($absDays > 1 ? 's' : '') . ". Il doit être renouvelé immédiatement "
                      . "pour rétablir les intégrations qui en dépendent.";
    } else {
        $subject = "[MaltyTask] Secret expire dans {$daysLeft} j — {$cred['label']}";
        $urgencyColor = $daysLeft <= 7 ? '#e67e22' : '#2980b9';
        $urgencyLabel = "Expire dans {$daysLeft} jour" . ($daysLeft > 1 ? 's' : '');
        $urgencyNote  = "Ce secret expire dans <strong>{$daysLeft} jour"
                      . ($daysLeft > 1 ? 's' : '') . "</strong> (le {$expiresOn}). "
                      . "Planifiez la rotation maintenant pour éviter une interruption de service.";
    }

    $credKeyRow = $credKey !== null
        ? "<tr><td style=\"padding:6px 0;font-size:13px;color:#6a5f52;\">Localisation&nbsp;:</td>"
          . "<td style=\"padding:6px 0 6px 12px;font-size:13px;color:#2c2414;font-family:'Courier New',monospace;\">"
          . $credKey . "</td></tr>"
        : '';

    $notesRow = '';
    if (!empty($cred['notes'])) {
        $notesEsc = htmlspecialchars((string) $cred['notes'], ENT_QUOTES, 'UTF-8');
        $notesRow = "<tr><td colspan=\"2\" style=\"padding:12px 0 0;font-size:13px;color:#6a5f52;\">"
                  . "<strong>Notes&nbsp;:</strong> {$notesEsc}</td></tr>";
    }

    $renewalBlock = <<<'HTML'
      <!-- Renewal steps -->
      <tr><td style="padding:0 28px 20px;">
        <div style="font-size:13px;font-weight:700;color:#2c2414;margin:4px 0 10px;border-top:1px solid #ddd2c0;padding-top:16px;">&#128273; Comment renouveler le secret</div>

        <div style="font-size:12px;font-weight:600;color:#6a5f52;margin:0 0 4px;">Partie A &mdash; Azure (~3 min)</div>
        <ol style="margin:0 0 14px;padding-left:18px;font-size:13px;color:#2c2414;line-height:1.5;">
          <li><a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Credentials/appId/9a68b9e5-e3a0-49d7-b2ca-82504718820b" style="color:#9eb060;">Ouvrir Certificates &amp; secrets</a> (app maltytask)</li>
          <li>Onglet <strong>Client secrets</strong> &rarr; <strong>New client secret</strong></li>
          <li>Description &laquo;&nbsp;BC API S2S &mdash; renouvel&eacute; {mois-ann&eacute;e}&nbsp;&raquo;, expiration <strong>24 mois</strong> &rarr; <strong>Add</strong></li>
          <li>Copier imm&eacute;diatement la <strong>Value</strong> (pas le Secret ID) &mdash; affich&eacute;e une seule fois</li>
        </ol>

        <div style="font-size:12px;font-weight:600;color:#6a5f52;margin:0 0 4px;">Partie B &mdash; Serveur</div>
        <div style="font-family:'Courier New',monospace;font-size:11px;color:#f1e8d4;background:#2c2414;border-radius:6px;padding:12px 14px;line-height:1.6;white-space:pre-wrap;word-break:break-word;"># 1. ssh maltyweb
# 2. Remplacer la ligne BC_CLIENT_SECRET= par la nouvelle Value :
sudo nano /var/www/maltytask/config/bc.env
# 3. Rearmer le rappel (remplace AAAA-MM-JJ) :
cd /var/www/maltytask &amp;&amp; sudo -u maltytask php -r 'require "app/db.php"; maltytask_pdo()->prepare("UPDATE ops_credential_expiry SET expires_on=?, last_reminded_stage=NULL WHERE label=?")->execute(["AAAA-MM-JJ","BC Entra client_secret"]);'
# 4. Verifier que l auth BC passe :
cd /var/www/maltytask &amp;&amp; sudo -u maltytask /usr/bin/python3 scripts/python/ingest_bc_sales_orders.py --dry-run | tail -15
# 5. Confirme -> supprimer l ancien secret dans Azure</div>
      </td></tr>
HTML;

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#ede4d3;font-family:'DM Sans',Arial,Helvetica,sans-serif;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ede4d3">
  <tr><td align="center" style="padding:32px 16px;">
    <table cellpadding="0" cellspacing="0" border="0" width="520" style="background:#faf5ec;border:1px solid #c8bba8;border-radius:10px;overflow:hidden;">

      <!-- Header -->
      <tr><td style="background:#2c2414;padding:20px 28px;">
        <div style="font-family:Georgia,'Times New Roman',serif;font-size:18px;color:#f1e8d4;letter-spacing:.02em;">{$breweryName}</div>
        <div style="font-size:12px;color:#9a8f82;margin-top:2px;letter-spacing:.05em;text-transform:uppercase;">Alerte credential · {$dateLabel}</div>
      </td></tr>

      <!-- Urgency banner -->
      <tr><td style="background:{$urgencyColor};padding:12px 28px;">
        <div style="font-size:14px;color:#fff;font-weight:600;">{$urgencyLabel}</div>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:24px 28px 20px;">
        <p style="margin:0 0 16px;font-size:14px;color:#2c2414;">{$urgencyNote}</p>

        <table cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:8px;border-top:1px solid #ddd2c0;">
          <tr><td style="padding:10px 0 6px;font-size:13px;color:#6a5f52;">Secret&nbsp;:</td>
              <td style="padding:10px 0 6px 12px;font-size:14px;font-weight:600;color:#2c2414;">{$label}</td></tr>
          <tr><td style="padding:6px 0;font-size:13px;color:#6a5f52;">Expire le&nbsp;:</td>
              <td style="padding:6px 0 6px 12px;font-size:13px;color:#2c2414;">{$expiresOn}</td></tr>
          {$credKeyRow}
          {$notesRow}
        </table>
      </td></tr>

      {$renewalBlock}

      <!-- Footer -->
      <tr><td style="background:#f0e8d8;padding:14px 28px;border-top:1px solid #d8cbb8;">
        <p style="margin:0;font-size:11px;color:#9a8f82;">
          Alerte automatique MaltyTask · {$breweryName}<br>
          <a href="https://app.maltytask.ch" style="color:#9eb060;">app.maltytask.ch</a>
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    return [
        'subject'  => $subject,
        'htmlBody' => $htmlBody,
    ];
}

// ── Load active credentials ────────────────────────────────────────────────────
$stmt = $pdo->query(
    "SELECT id, label, cred_key, expires_on, lead_days, last_reminded_stage, recipient, notes,
            DATEDIFF(expires_on, CURDATE()) AS days_left
       FROM ops_credential_expiry
      WHERE is_active = 1
      ORDER BY expires_on ASC"
);
$credentials = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

if (empty($credentials)) {
    fwrite(STDOUT, "[credential-expiry] No active credentials configured. Exiting.\n");
    exit(0);
}

// ── Brewery identity (for email footer) ───────────────────────────────────────
$brewery = brewery_identity();

// ── Process each credential ───────────────────────────────────────────────────
$anyDue = false;

foreach ($credentials as $cred) {
    $credId    = (int)    $cred['id'];
    $label     = (string) $cred['label'];
    $daysLeft  = (int)    $cred['days_left'];
    $lastStage = $cred['last_reminded_stage'] !== null ? (int) $cred['last_reminded_stage'] : null;
    $thresholds = parse_lead_days((string) $cred['lead_days']);

    $stage = determine_stage($daysLeft, $thresholds, $lastStage);

    if ($stage === null) {
        fwrite(STDOUT, "[credential-expiry] {$label}: no reminder due (days_left={$daysLeft}, last_stage=" . ($lastStage ?? 'NULL') . ").\n");
        continue;
    }

    $anyDue = true;

    // Resolve recipient
    $recipient = resolve_recipient($pdo, $cred['recipient'] ?? null);
    if ($recipient === null) {
        fwrite(STDERR, "[credential-expiry] WARNING: {$label}: no recipient resolved (no row override, no system_setting, no admin users). Skipping.\n");
        continue;
    }

    // Build email
    $email = build_email($cred, $daysLeft, $stage, $brewery);
    $stageLabel = $stage === 0 ? 'EXPIRED' : "{$stage}-day";

    if ($dryRun) {
        fwrite(STDOUT, "[credential-expiry] DRY-RUN: {$label} → stage={$stageLabel}, days_left={$daysLeft}, to={$recipient}\n");
        fwrite(STDOUT, "[credential-expiry]   Subject: {$email['subject']}\n");
        fwrite(STDOUT, "---BEGIN EMAIL PREVIEW---\n" . $email['htmlBody'] . "\n---END EMAIL PREVIEW---\n");
        continue;
    }

    // Live: send to each recipient (support comma-list from admin fallback)
    $recipients = array_filter(array_map('trim', explode(',', $recipient)));
    $allOk = true;
    foreach ($recipients as $addr) {
        $sent = send_mail($addr, $email['subject'], $email['htmlBody']);
        if ($sent) {
            fwrite(STDOUT, "[credential-expiry] {$label}: reminder sent to {$addr} (stage={$stageLabel}).\n");
        } else {
            fwrite(STDERR, "[credential-expiry] ERROR: {$label}: failed to send to {$addr}.\n");
            $allOk = false;
        }
    }

    if ($allOk) {
        // Update last_reminded_stage so this threshold is not re-fired
        $upd = $pdo->prepare(
            "UPDATE ops_credential_expiry SET last_reminded_stage = ? WHERE id = ?"
        );
        $upd->execute([$stage, $credId]);
        fwrite(STDOUT, "[credential-expiry] {$label}: last_reminded_stage set to {$stage}.\n");
    } else {
        fwrite(STDERR, "[credential-expiry] {$label}: NOT updating last_reminded_stage due to send failure.\n");
    }
}

if (!$anyDue) {
    // No credentials needed a reminder — silent exit (cron-friendly)
    exit(0);
}

fwrite(STDOUT, "[credential-expiry] Done" . ($dryRun ? " (dry-run — no emails sent, no DB writes)" : "") . ".\n");
