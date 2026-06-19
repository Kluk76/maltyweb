<?php
declare(strict_types=1);
/**
 * scripts/send-supplier-review-reminders.php — Supplier re-evaluation watchdog (CLI only)
 *
 * Queries supplier_evaluations and ref_suppliers for three alert buckets:
 *   A. Évaluations échues (valid_until < CURDATE())
 *   B. Évaluations à échéance prochaine (within lead window, default 60 days)
 *   C. Fournisseurs critiques jamais évalués
 *
 * If all three buckets are empty: exits 0 silently (cron-friendly).
 *
 * Recipient precedence:
 *   1. system_settings WHERE section='ops' AND key_name='supplier_review_alert_recipient'
 *   2. All users WHERE role='admin' AND is_active=1 (comma-joined)
 *   3. If none: log warning, exit 0
 *
 * Flags:
 *   --dry-run   Print digest to stdout; no email sent. (DEFAULT)
 *   --apply     Send digest email for real.
 *
 * Cron: daily via db/cron/maltytask-supplier-review.cron (deployed DISABLED).
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

// ── Helper: resolve recipient from system_settings or admin users ─────────────
function resolve_supplier_review_recipient(PDO $pdo): ?string
{
    // 1. system_settings
    $stmt = $pdo->prepare(
        "SELECT value_text FROM system_settings
          WHERE section = 'ops' AND key_name = 'supplier_review_alert_recipient' AND is_active = 1
          LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['value_text'])) return trim((string)$row['value_text']);

    // 2. Admin users fallback
    $admins = $pdo->query(
        "SELECT email FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email != '' ORDER BY id"
    );
    if ($admins) {
        $emails = array_column($admins->fetchAll(PDO::FETCH_ASSOC), 'email');
        if (!empty($emails)) return implode(',', $emails);
    }
    return null;
}

// ── Helper: build subject + HTML body for the digest email ────────────────────
function build_supplier_review_email(array $buckA, array $buckB, array $buckC, int $leadDays, array $brewery): array
{
    $totalCount  = count($buckA) + count($buckB) + count($buckC);
    $breweryName = htmlspecialchars($brewery['name'], ENT_QUOTES, 'UTF-8');
    $dateLabel   = date('d/m/Y');

    $subject = "[MaltyTask] Réévaluation fournisseurs requise — {$totalCount} fournisseur(s)";

    // Banner colour: red if any overdue, orange if approaching only, brown if never-evaluated only
    if (!empty($buckA)) {
        $bannerColor = '#c0392b';
    } elseif (!empty($buckB)) {
        $bannerColor = '#e67e22';
    } else {
        $bannerColor = '#8a6a3a';
    }

    // ── Build HTML ─────────────────────────────────────────────────────────────
    $html  = '<!DOCTYPE html>' . "\n";
    $html .= '<html lang="fr">' . "\n";
    $html .= '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' . "\n";
    $html .= '<body style="margin:0;padding:0;background:#ede4d3;font-family:\'DM Sans\',Arial,Helvetica,sans-serif;">' . "\n";
    $html .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#ede4d3">' . "\n";
    $html .= '  <tr><td align="center" style="padding:32px 16px;">' . "\n";
    $html .= '    <table cellpadding="0" cellspacing="0" border="0" width="520" style="background:#faf5ec;border:1px solid #c8bba8;border-radius:10px;overflow:hidden;">' . "\n";

    // Header
    $html .= '      <!-- Header -->' . "\n";
    $html .= '      <tr><td style="background:#2c2414;padding:20px 28px;">' . "\n";
    $html .= '        <div style="font-family:Georgia,\'Times New Roman\',serif;font-size:18px;color:#f1e8d4;letter-spacing:.02em;">' . $breweryName . '</div>' . "\n";
    $html .= '        <div style="font-size:12px;color:#9a8f82;margin-top:2px;letter-spacing:.05em;text-transform:uppercase;">Alerte réévaluation fournisseurs · ' . $dateLabel . '</div>' . "\n";
    $html .= '      </td></tr>' . "\n";

    // Banner
    $html .= '      <!-- Banner -->' . "\n";
    $html .= '      <tr><td style="background:' . $bannerColor . ';padding:12px 28px;">' . "\n";
    $html .= '        <div style="font-size:14px;color:#fff;font-weight:600;">' . $totalCount . ' fournisseur(s) à réévaluer</div>' . "\n";
    $html .= '      </td></tr>' . "\n";

    // Body
    $html .= '      <!-- Body -->' . "\n";
    $html .= '      <tr><td style="padding:24px 28px 20px;">' . "\n";
    $html .= '        <p style="margin:0 0 16px;font-size:14px;color:#2c2414;">Les fournisseurs suivants nécessitent une action de réévaluation.</p>' . "\n";

    // ── Bucket A ──────────────────────────────────────────────────────────────
    if (!empty($buckA)) {
        $html .= '        <div style="font-size:14px;font-weight:700;color:#2c2414;margin:16px 0 8px;">&#9888; Évaluations échues</div>' . "\n";
        $html .= '        <table width="100%" style="border-collapse:collapse;font-size:12px;">' . "\n";
        $html .= '          <tr style="background:#f0e0d0;">' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Fournisseur</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Criticité</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Dernière éval.</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Résultat</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:right;border-bottom:1px solid #c8bba8;">Échue depuis (j)</th>' . "\n";
        $html .= '          </tr>' . "\n";
        foreach ($buckA as $i => $r) {
            $rowBg = ($i % 2 === 0) ? '#fff' : '#faf5ec';
            $name       = htmlspecialchars((string)$r['supplier_name'], ENT_QUOTES, 'UTF-8');
            $crit       = htmlspecialchars((string)$r['criticality'], ENT_QUOTES, 'UTF-8');
            $evalAt     = htmlspecialchars((string)($r['evaluated_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $result     = htmlspecialchars((string)($r['result'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $overdue    = (int)$r['days_overdue'];
            $html .= '          <tr style="background:' . $rowBg . ';">' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $name . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $crit . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $evalAt . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $result . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;text-align:right;color:#c0392b;font-weight:600;">' . $overdue . '</td>' . "\n";
            $html .= '          </tr>' . "\n";
        }
        $html .= '        </table>' . "\n";
    }

    // ── Bucket B ──────────────────────────────────────────────────────────────
    if (!empty($buckB)) {
        $html .= '        <div style="font-size:14px;font-weight:700;color:#2c2414;margin:16px 0 8px;">&#9200; Évaluations à échéance prochaine</div>' . "\n";
        $html .= '        <table width="100%" style="border-collapse:collapse;font-size:12px;">' . "\n";
        $html .= '          <tr style="background:#f0e0d0;">' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Fournisseur</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Criticité</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Dernière éval.</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Résultat</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Valable jusqu\'au</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:right;border-bottom:1px solid #c8bba8;">Jours restants</th>' . "\n";
        $html .= '          </tr>' . "\n";
        foreach ($buckB as $i => $r) {
            $rowBg = ($i % 2 === 0) ? '#fff' : '#faf5ec';
            $name       = htmlspecialchars((string)$r['supplier_name'], ENT_QUOTES, 'UTF-8');
            $crit       = htmlspecialchars((string)$r['criticality'], ENT_QUOTES, 'UTF-8');
            $evalAt     = htmlspecialchars((string)($r['evaluated_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $result     = htmlspecialchars((string)($r['result'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $validUntil = htmlspecialchars((string)($r['valid_until'] ?? '—'), ENT_QUOTES, 'UTF-8');
            $remaining  = (int)$r['days_remaining'];
            $html .= '          <tr style="background:' . $rowBg . ';">' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $name . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $crit . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $evalAt . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $result . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $validUntil . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;text-align:right;color:#e67e22;font-weight:600;">' . $remaining . '</td>' . "\n";
            $html .= '          </tr>' . "\n";
        }
        $html .= '        </table>' . "\n";
    }

    // ── Bucket C ──────────────────────────────────────────────────────────────
    if (!empty($buckC)) {
        $html .= '        <div style="font-size:14px;font-weight:700;color:#2c2414;margin:16px 0 8px;">&#128308; Fournisseurs critiques jamais évalués</div>' . "\n";
        $html .= '        <table width="100%" style="border-collapse:collapse;font-size:12px;">' . "\n";
        $html .= '          <tr style="background:#f0e0d0;">' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Fournisseur</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Criticité</th>' . "\n";
        $html .= '            <th style="padding:6px 8px;text-align:left;border-bottom:1px solid #c8bba8;">Statut</th>' . "\n";
        $html .= '          </tr>' . "\n";
        foreach ($buckC as $i => $r) {
            $rowBg = ($i % 2 === 0) ? '#fff' : '#faf5ec';
            $name = htmlspecialchars((string)$r['supplier_name'], ENT_QUOTES, 'UTF-8');
            $crit = htmlspecialchars((string)$r['criticality'], ENT_QUOTES, 'UTF-8');
            $html .= '          <tr style="background:' . $rowBg . ';">' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $name . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;">' . $crit . '</td>' . "\n";
            $html .= '            <td style="padding:5px 8px;border-bottom:1px solid #ede4d3;color:#c0392b;font-weight:600;">Jamais évalué</td>' . "\n";
            $html .= '          </tr>' . "\n";
        }
        $html .= '        </table>' . "\n";
    }

    $html .= '      </td></tr>' . "\n";

    // Footer
    $html .= '      <!-- Footer -->' . "\n";
    $html .= '      <tr><td style="background:#f0e8d8;padding:14px 28px;border-top:1px solid #d8cbb8;">' . "\n";
    $html .= '        <p style="margin:0;font-size:11px;color:#9a8f82;">' . "\n";
    $html .= '          Alerte automatique MaltyTask · ' . $breweryName . '<br>' . "\n";
    $html .= '          <a href="https://app.maltytask.ch" style="color:#9eb060;">app.maltytask.ch</a>' . "\n";
    $html .= '        </p>' . "\n";
    $html .= '      </td></tr>' . "\n";
    $html .= '    </table>' . "\n";
    $html .= '  </td></tr>' . "\n";
    $html .= '</table>' . "\n";
    $html .= '</body>' . "\n";
    $html .= '</html>' . "\n";

    return ['subject' => $subject, 'htmlBody' => $html];
}

// ── Read lead window from system_settings ─────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT value_text FROM system_settings
      WHERE section = 'ops' AND key_name = 'supplier_review_lead_days' AND is_active = 1
      LIMIT 1"
);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$leadDays = ($row && is_numeric($row['value_text'])) ? (int)$row['value_text'] : 60;

// ── Bucket A — Évaluations échues ─────────────────────────────────────────────
$stmtA = $pdo->query(
    "SELECT se.id, rs.name AS supplier_name, rs.criticality,
            se.evaluated_at, se.valid_until, se.result,
            DATEDIFF(CURDATE(), se.valid_until) AS days_overdue
       FROM supplier_evaluations se
       JOIN ref_suppliers rs ON rs.id = se.supplier_id_fk
      WHERE se.status = 'final'
        AND se.superseded_by_id IS NULL
        AND se.valid_until IS NOT NULL
        AND se.valid_until < CURDATE()
      ORDER BY se.valid_until ASC"
);
$buckA = $stmtA ? $stmtA->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Bucket B — Évaluations à échéance prochaine ───────────────────────────────
$stmtB = $pdo->prepare(
    "SELECT se.id, rs.name AS supplier_name, rs.criticality,
            se.evaluated_at, se.valid_until, se.result,
            DATEDIFF(se.valid_until, CURDATE()) AS days_remaining
       FROM supplier_evaluations se
       JOIN ref_suppliers rs ON rs.id = se.supplier_id_fk
      WHERE se.status = 'final'
        AND se.superseded_by_id IS NULL
        AND se.valid_until IS NOT NULL
        AND se.valid_until >= CURDATE()
        AND se.valid_until <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
      ORDER BY se.valid_until ASC"
);
$stmtB->execute([$leadDays]);
$buckB = $stmtB->fetchAll(PDO::FETCH_ASSOC);

// ── Bucket C — Fournisseurs critiques jamais évalués ──────────────────────────
$stmtC = $pdo->query(
    "SELECT rs.id AS supplier_id, rs.name AS supplier_name, rs.criticality
       FROM ref_suppliers rs
      WHERE rs.criticality = 'critique'
        AND rs.is_active = 1
        AND NOT EXISTS (
          SELECT 1 FROM supplier_evaluations se2
           WHERE se2.supplier_id_fk = rs.id
             AND se2.status = 'final'
        )
      ORDER BY rs.name ASC"
);
$buckC = $stmtC ? $stmtC->fetchAll(PDO::FETCH_ASSOC) : [];

// ── If all three buckets empty: exit silently ─────────────────────────────────
if (empty($buckA) && empty($buckB) && empty($buckC)) {
    exit(0);
}

// ── Resolve recipient ─────────────────────────────────────────────────────────
$brewery   = brewery_identity();
$recipient = resolve_supplier_review_recipient($pdo);
if ($recipient === null) {
    fwrite(STDERR, "[supplier-review] WARNING: no recipient resolved. Exiting.\n");
    exit(0);
}

// ── Build email ───────────────────────────────────────────────────────────────
$email = build_supplier_review_email($buckA, $buckB, $buckC, $leadDays, $brewery);

// ── Dry-run or send ───────────────────────────────────────────────────────────
if ($dryRun) {
    $total = count($buckA) + count($buckB) + count($buckC);
    fwrite(STDOUT, "[supplier-review] DRY-RUN: {$total} fournisseur(s) à réévaluer.\n");
    if (!empty($buckA)) {
        fwrite(STDOUT, "[supplier-review] Bucket A — Évaluations échues (" . count($buckA) . "):\n");
        foreach ($buckA as $r) {
            fwrite(STDOUT, "  - {$r['supplier_name']} (criticité: {$r['criticality']}, échue depuis: {$r['days_overdue']} j)\n");
        }
    }
    if (!empty($buckB)) {
        fwrite(STDOUT, "[supplier-review] Bucket B — À échéance prochaine (" . count($buckB) . "):\n");
        foreach ($buckB as $r) {
            fwrite(STDOUT, "  - {$r['supplier_name']} (criticité: {$r['criticality']}, jours restants: {$r['days_remaining']})\n");
        }
    }
    if (!empty($buckC)) {
        fwrite(STDOUT, "[supplier-review] Bucket C — Jamais évalués (" . count($buckC) . "):\n");
        foreach ($buckC as $r) {
            fwrite(STDOUT, "  - {$r['supplier_name']} (criticité: {$r['criticality']})\n");
        }
    }
    fwrite(STDOUT, "[supplier-review] DRY-RUN: email would be sent to {$recipient}.\n");
} else {
    $recipients = array_filter(array_map('trim', explode(',', $recipient)));
    foreach ($recipients as $addr) {
        $sent = send_mail($addr, $email['subject'], $email['htmlBody']);
        if ($sent) {
            fwrite(STDOUT, "[supplier-review] Email sent to {$addr}.\n");
        } else {
            fwrite(STDERR, "[supplier-review] ERROR: failed to send to {$addr}.\n");
        }
    }
}

fwrite(STDOUT, "[supplier-review] Done" . ($dryRun ? " (dry-run — no emails sent)" : "") . ".\n");
