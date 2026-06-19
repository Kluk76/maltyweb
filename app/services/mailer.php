<?php
declare(strict_types=1);

/**
 * app/services/mailer.php — Transactional mailer
 *
 * Thin wrapper around PHP's mail() which routes via the local sendmail/Postfix
 * relay.  Degrades gracefully when the relay is not yet configured — callers
 * must handle the false return and show the admin a copy-able fallback link.
 *
 * MAIL_FROM / MAIL_FROM_NAME are defined here as single-source constants.
 * If a central app/config.php is ever introduced, move them there and remove
 * the definitions from this file.
 */

require_once __DIR__ . '/../settings-helpers.php';
require_once __DIR__ . '/../settings.php';

const MAIL_FROM      = 'noreply@maltytask.ch';
const MAIL_FROM_NAME = 'MaltyTask — La Nébuleuse';

/**
 * Send a UTF-8 email via PHP mail().
 *
 * When $inlineImages is null/empty → multipart/alternative (plain + html).
 * When $inlineImages is non-empty  → multipart/related wrapping multipart/alternative + images.
 *
 * @param string      $to           Recipient address — validated before sending.
 * @param string      $subject      Plain UTF-8 subject (RFC2047-encoded).
 * @param string      $htmlBody     HTML part.
 * @param string|null $textBody     Plain-text part; derived from HTML if null.
 * @param string|null $from         Envelope/From address; defaults to MAIL_FROM.
 * @param string|null $fromName     Display name; defaults to MAIL_FROM_NAME.
 * @param string|null $replyTo      Reply-To address; defaults to $from.
 * @param array|null  $inlineImages List of ['path'=>…,'cid'=>…,'mime'=>…] for inline attachments.
 * @return bool
 */
function send_mail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $textBody = null,
    ?string $from = null,
    ?string $fromName = null,
    ?string $replyTo = null,
    ?array $inlineImages = null
): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('send_mail: invalid recipient address (rejected without sending)');
        return false;
    }

    $from     = $from     ?? MAIL_FROM;
    $fromName = $fromName ?? MAIL_FROM_NAME;
    $replyTo  = $replyTo  ?? $from;

    // Derive plain-text fallback from HTML when not explicitly supplied.
    if ($textBody === null) {
        $textBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody)
        );
        $textBody = preg_replace("/\n{3,}/", "\n\n", $textBody) ?? $textBody;
        $textBody = trim($textBody);
    }

    // RFC2047 subject + From encoding.
    $encodedSubject  = mb_encode_mimeheader($subject,  'UTF-8', 'B', "\r\n");
    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n");

    // Build inline-images list, skipping unreadable paths.
    $images = [];
    if (!empty($inlineImages)) {
        foreach ($inlineImages as $img) {
            $path = $img['path'] ?? '';
            if (!is_readable($path)) {
                error_log('send_mail: inline image not readable, skipping: ' . $path);
                continue;
            }
            $data = file_get_contents($path);
            if ($data === false) {
                error_log('send_mail: could not read inline image, skipping: ' . $path);
                continue;
            }
            $images[] = [
                'cid'  => $img['cid']  ?? basename($path),
                'mime' => $img['mime'] ?? 'application/octet-stream',
                'name' => basename($path),
                'b64'  => chunk_split(base64_encode($data)),
            ];
        }
    }

    $altBoundary = 'mt_alt_' . bin2hex(random_bytes(16));

    // ── multipart/alternative inner part ─────────────────────────────────────
    if (empty($images)) {
        // Simple path — same structure as before.
        $boundary = $altBoundary;
        $headers  = implode("\r\n", [
            'From: '     . $encodedFromName . ' <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: MaltyTask',
        ]);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
        $body .= "--{$boundary}--";
    } else {
        // Inline-images path — multipart/related wrapping multipart/alternative.
        $relBoundary = 'mt_rel_' . bin2hex(random_bytes(16));

        $altPart  = "--{$altBoundary}\r\n";
        $altPart .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $altPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $altPart .= quoted_printable_encode($textBody) . "\r\n\r\n";
        $altPart .= "--{$altBoundary}\r\n";
        $altPart .= "Content-Type: text/html; charset=UTF-8\r\n";
        $altPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $altPart .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
        $altPart .= "--{$altBoundary}--";

        $headers = implode("\r\n", [
            'From: '     . $encodedFromName . ' <' . $from . '>',
            'Reply-To: ' . $replyTo,
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; type="multipart/alternative"; boundary="' . $relBoundary . '"',
            'X-Mailer: MaltyTask',
        ]);

        $body  = "--{$relBoundary}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        $body .= $altPart . "\r\n";

        foreach ($images as $img) {
            $body .= "--{$relBoundary}\r\n";
            $body .= "Content-Type: {$img['mime']}; name=\"{$img['name']}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-ID: <{$img['cid']}>\r\n";
            $body .= "Content-Disposition: inline; filename=\"{$img['name']}\"\r\n\r\n";
            $body .= $img['b64'] . "\r\n";
        }

        $body .= "--{$relBoundary}--";
    }

    $ok = mail($to, $encodedSubject, $body, $headers, '-f ' . $from);

    if (!$ok) {
        error_log('send_mail: mail() returned false for recipient ' . $to);
    }

    return $ok;
}

/**
 * Build the client-facing order-confirmation email.
 *
 * @param array      $order  Keys: order_no, customer_name, requested_date_fr (jj/mm/aaaa or empty)
 * @param array      $lines  List of ['ref', 'designation', 'qty']
 * @param array|null $address ['line1','line2','postal_code','city','canton'] or null
 * @return array ['subject', 'html', 'text']
 */
function mail_order_confirmation_template(array $order, array $lines, ?array $address): array
{
    $orderNo      = htmlspecialchars(trim((string)($order['order_no']          ?? '')), ENT_QUOTES, 'UTF-8');
    $customerName = htmlspecialchars(trim((string)($order['customer_name']     ?? '')), ENT_QUOTES, 'UTF-8');
    $dateFr       = htmlspecialchars(trim((string)($order['requested_date_fr'] ?? '')), ENT_QUOTES, 'UTF-8');

    $subject = $orderNo !== '' ? "Confirmation de commande — {$orderNo}" : 'Confirmation de commande';

    // ── Brewery identity ──────────────────────────────────────────────────────
    $bi          = brewery_identity();
    $breweryName = htmlspecialchars($bi['name'], ENT_QUOTES, 'UTF-8');

    // ── Production site address (best-effort) ─────────────────────────────────
    $siteAddress = 'Ch. du Closel 1, 1020 Renens, Suisse';
    try {
        $pdo = maltytask_pdo();
        if ($pdo !== null) {
            $siteStmt = $pdo->prepare(
                'SELECT address_line1, postal_code, city, country FROM ref_sites
                  WHERE site_type = \'production\' ORDER BY id ASC LIMIT 1'
            );
            $siteStmt->execute();
            $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
            if ($site !== false) {
                $parts = array_filter([
                    trim((string)($site['address_line1'] ?? '')),
                    trim(((string)($site['postal_code'] ?? '')) . ' ' . ((string)($site['city'] ?? ''))),
                    trim((string)($site['country'] ?? '')),
                ]);
                if (!empty($parts)) {
                    $siteAddress = implode(', ', $parts);
                }
            }
        }
    } catch (Throwable $ignored) {}

    // ── Contact email ─────────────────────────────────────────────────────────
    $contactEmail = (string) system_setting('confirmation_email_from', 'fulfilment', 'commandes@lanebuleuse.ch');
    $contactEmailEsc = htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8');

    // ── Line-items rows ───────────────────────────────────────────────────────
    $htmlRows = '';
    $textRows = '';
    foreach ($lines as $i => $line) {
        $ref  = htmlspecialchars((string)($line['ref']         ?? ''), ENT_QUOTES, 'UTF-8');
        $desig = htmlspecialchars((string)($line['designation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawQty = $line['qty'] ?? 0;
        // Format qty: integer-like → no decimal, else strip trailing zeros
        if (is_numeric($rawQty) && (float)$rawQty == (int)(float)$rawQty) {
            $fmtQty = (string)(int)(float)$rawQty;
        } else {
            $fmtQty = rtrim(rtrim(number_format((float)$rawQty, 3, '.', ''), '0'), '.');
        }
        $bg = ($i % 2 === 0) ? '#ffffff' : '#f9f7f4';
        $htmlRows .= "<tr style=\"background:{$bg};\">"
            . "<td style=\"padding:8px 12px;font-size:13px;color:#3d2e1a;font-family:monospace;white-space:nowrap;border-bottom:1px solid #e0d8cc;\">{$ref}</td>"
            . "<td style=\"padding:8px 12px;font-size:13px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;\">{$desig}</td>"
            . "<td style=\"padding:8px 12px;font-size:13px;color:#3d2e1a;text-align:right;white-space:nowrap;border-bottom:1px solid #e0d8cc;\">{$fmtQty}</td>"
            . "</tr>\n";
        $textRows .= "  {$ref}  {$desig}  ×{$fmtQty}\n";
    }

    // ── Optional delivery address ─────────────────────────────────────────────
    $htmlAddress = '';
    $textAddress = '';
    if ($address !== null) {
        $parts = [];
        if (trim((string)($address['line1'] ?? '')) !== '') {
            $parts[] = htmlspecialchars(trim($address['line1']), ENT_QUOTES, 'UTF-8');
        }
        if (trim((string)($address['line2'] ?? '')) !== '') {
            $parts[] = htmlspecialchars(trim($address['line2']), ENT_QUOTES, 'UTF-8');
        }
        $cityCantonPart = '';
        $city   = htmlspecialchars(trim((string)($address['city']   ?? '')), ENT_QUOTES, 'UTF-8');
        $canton = htmlspecialchars(trim((string)($address['canton'] ?? '')), ENT_QUOTES, 'UTF-8');
        $postal = htmlspecialchars(trim((string)($address['postal_code'] ?? '')), ENT_QUOTES, 'UTF-8');
        if ($postal !== '' || $city !== '') {
            $cityLine = trim("$postal $city");
            if ($canton !== '') {
                $cityLine .= " ({$canton})";
            }
            $parts[] = $cityLine;
        }
        if (!empty($parts)) {
            $htmlAddress = '<p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#3d2e1a;">'
                . '<strong>Adresse de livraison :</strong><br>'
                . implode('<br>', $parts) . '</p>';
            $textAddress = "Adresse de livraison :\n" . implode("\n", array_map('strip_tags', $parts)) . "\n\n";
        }
    }

    // ── Optional date line ────────────────────────────────────────────────────
    $htmlDateLine = '';
    $textDateLine = '';
    if ($dateFr !== '') {
        $htmlDateLine = "<p style=\"margin:0 0 16px;font-size:14px;color:#3d2e1a;\">Date de livraison souhaitée : <strong>{$dateFr}</strong></p>";
        $textDateLine = "Date de livraison souhaitée : {$dateFr}\n\n";
    }

    // ── Intro text ────────────────────────────────────────────────────────────
    $greeting = $customerName !== '' ? "Bonjour {$customerName}," : 'Bonjour,';
    $orderSentence = $orderNo !== ''
        ? "Nous avons bien reçu votre commande <strong>{$orderNo}</strong> et nous vous en confirmons la réception."
        : "Nous avons bien reçu votre commande et nous vous en confirmons la réception.";

    // ── HTML ──────────────────────────────────────────────────────────────────
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f5f3ef;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#3d2e1a;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f5f3ef;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e0d8cc;overflow:hidden;">

      <!-- Header -->
      <tr>
        <td style="background:#1c1409;padding:20px 32px;text-align:center;">
          <img src="cid:nebuleuse-logo" width="140" alt="La Nébuleuse" style="display:block;margin:0 auto;">
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:32px 32px 24px;">
          <p style="margin:0 0 20px;font-size:16px;font-weight:600;color:#3d2e1a;">{$greeting}</p>
          <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#3d2e1a;">{$orderSentence}</p>
          {$htmlDateLine}

          <!-- Line-items table -->
          <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;border:1px solid #e0d8cc;border-radius:6px;overflow:hidden;border-collapse:collapse;">
            <tr style="background:#1c1409;">
              <th style="padding:10px 12px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#c8a96e;text-align:left;font-weight:600;">Réf.</th>
              <th style="padding:10px 12px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#c8a96e;text-align:left;font-weight:600;">Désignation</th>
              <th style="padding:10px 12px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#c8a96e;text-align:right;font-weight:600;">Qté</th>
            </tr>
            {$htmlRows}
          </table>

          {$htmlAddress}

          <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#3d2e1a;">Pour toute question concernant votre commande, contactez-nous à <a href="mailto:{$contactEmailEsc}" style="color:#c8a96e;">{$contactEmailEsc}</a>.</p>

          <p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#7a6a55;">Brassicalement,<br>L'équipe {$breweryName}</p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f5f3ef;border-top:1px solid #e0d8cc;padding:16px 32px;">
          <p style="margin:0;font-size:11px;color:#9a8a75;line-height:1.7;">
            {$breweryName}<br>
            {$siteAddress}<br>
            <a href="mailto:{$contactEmailEsc}" style="color:#9a8a75;">{$contactEmailEsc}</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    // ── Plain text ────────────────────────────────────────────────────────────
    $orderNoText = $orderNo !== '' ? $orderNo : '(en cours)';
    $text  = strip_tags($greeting) . "\n\n";
    $text .= "Nous avons bien reçu votre commande {$orderNoText} et nous vous en confirmons la réception.\n\n";
    $text .= $textDateLine;
    $text .= "Détail de la commande :\n";
    $text .= str_repeat('-', 40) . "\n";
    $text .= $textRows;
    $text .= str_repeat('-', 40) . "\n\n";
    $text .= $textAddress;
    $text .= "Pour toute question : {$contactEmail}\n\n";
    $text .= "Brassicalement,\nL'équipe " . $bi['name'] . "\n";

    return [
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
}

/**
 * Build the invite / password-reset email template.
 *
 * Returns an array with keys 'subject', 'html', 'text'.
 * All user-supplied strings are escaped before being embedded in HTML.
 *
 * @param string $displayName  Recipient's display name (e.g. "Jean Dupont").
 * @param string $link         The full set-password URL (contains the raw token).
 * @param string $inviterName  Admin who triggered the action (for body copy).
 * @param string $purpose      'invite' or 'reset'.
 */
function mail_account_template(
    string $displayName,
    string $link,
    string $inviterName,
    string $purpose
): array {
    $dn      = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $inv     = htmlspecialchars($inviterName,  ENT_QUOTES, 'UTF-8');
    $linkEsc = htmlspecialchars($link,          ENT_QUOTES, 'UTF-8');

    if ($purpose === 'reset') {
        $subject  = 'Réinitialisation de votre mot de passe — MaltyTask';
        $headline = 'Réinitialisation de votre mot de passe';
        $intro    = "L'administrateur <strong>{$inv}</strong> a demandé une réinitialisation "
                  . "du mot de passe de votre compte MaltyTask.<br><br>"
                  . "Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe. "
                  . "Le lien est valable <strong>72 heures</strong>.";
        $btnLabel = 'Choisir mon nouveau mot de passe';
        $footnote = "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet e-mail — "
                  . "votre mot de passe actuel reste inchangé.";

        $textIntro = "L'administrateur {$inviterName} a demandé une réinitialisation du mot de passe "
                   . "de votre compte MaltyTask.\n\n"
                   . "Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe "
                   . "(valable 72 heures) :\n\n"
                   . $link . "\n\n"
                   . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail — "
                   . "votre mot de passe actuel reste inchangé.";
    } else {
        // 'invite' — new account activation
        $subject  = 'Activez votre compte MaltyTask';
        $headline = 'Bienvenue sur MaltyTask';
        $intro    = "L'administrateur <strong>{$inv}</strong> a créé un compte MaltyTask "
                  . "à votre nom.<br><br>"
                  . "Cliquez sur le bouton ci-dessous pour définir votre mot de passe et "
                  . "accéder à l'application. Le lien est valable <strong>72 heures</strong>.";
        $btnLabel = 'Activer mon compte et choisir mon mot de passe';
        $footnote = "Si vous avez reçu cet e-mail par erreur, ignorez-le simplement — "
                  . "aucune action n'est requise.";

        $textIntro = "L'administrateur {$inviterName} a créé un compte MaltyTask à votre nom.\n\n"
                   . "Cliquez sur le lien ci-dessous pour définir votre mot de passe et accéder "
                   . "à l'application (valable 72 heures) :\n\n"
                   . $link . "\n\n"
                   . "Si vous avez reçu cet e-mail par erreur, ignorez-le simplement — "
                   . "aucune action n'est requise.";
    }

    // ── Brewery identity for footer (reads DB; falls back gracefully) ─────────
    $bi         = brewery_identity();
    $footerLine = htmlspecialchars($bi['name'], ENT_QUOTES, 'UTF-8')
                . ' · '
                . htmlspecialchars($bi['city'], ENT_QUOTES, 'UTF-8')
                . ', '
                . htmlspecialchars($bi['country'], ENT_QUOTES, 'UTF-8');

    // ── Plain-text version ────────────────────────────────────────────────────
    $text = "Bonjour {$displayName},\n\n"
          . $textIntro . "\n\n"
          . "— L'équipe {$bi['name']} · MaltyTask\n"
          . "   https://app.maltytask.ch";

    // ── HTML version ──────────────────────────────────────────────────────────
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f5f3ef;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#2b2414;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f5f3ef;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e0d8cc;overflow:hidden;">

      <!-- Header band -->
      <tr>
        <td style="background:#1c1409;padding:20px 32px;">
          <p style="margin:0;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#c8a96e;font-weight:600;">La Nébuleuse · MaltyTask</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:32px 32px 24px;">
          <h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#1c1409;line-height:1.3;">{$headline}</h1>
          <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#3d2e1a;">Bonjour <strong>{$dn}</strong>,</p>
          <p style="margin:0 0 28px;font-size:15px;line-height:1.6;color:#3d2e1a;">{$intro}</p>

          <!-- CTA button -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
              <td style="background:#c8a96e;border-radius:6px;">
                <a href="{$linkEsc}"
                   style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:600;color:#1c1409;text-decoration:none;letter-spacing:.04em;"
                   target="_blank" rel="noopener noreferrer">{$btnLabel}</a>
              </td>
            </tr>
          </table>

          <!-- Raw URL fallback -->
          <p style="margin:0 0 8px;font-size:12px;color:#7a6a55;">Ou copier ce lien dans votre navigateur&nbsp;:</p>
          <p style="margin:0 0 24px;font-size:11px;font-family:'Courier New',Courier,monospace;word-break:break-all;background:#f5f3ef;border:1px solid #e0d8cc;border-radius:4px;padding:10px 12px;color:#3d2e1a;">{$linkEsc}</p>

          <p style="margin:0;font-size:13px;line-height:1.5;color:#7a6a55;">{$footnote}</p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f5f3ef;border-top:1px solid #e0d8cc;padding:16px 32px;">
          <p style="margin:0;font-size:11px;color:#9a8a75;line-height:1.5;">
            {$footerLine}<br>
            <a href="https://app.maltytask.ch" style="color:#9a8a75;text-decoration:underline;">app.maltytask.ch</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    return [
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
}

/**
 * Build subject/html/text for recipe change-request notifications.
 *
 * @param string $kind  'filed'   — new request filed by a manager (sent to admins)
 *                      'decided' — request approved or rejected (sent to requester)
 * @param array  $args  Keys depend on $kind:
 *   'filed':
 *     manager_name, recipe_name, change_kind_label, summary, deep_link
 *   'decided':
 *     recipe_name, change_kind_label, decision ('approuvée'|'refusée'),
 *     decision_note (may be ''), decided_by_name, requester_email
 *
 * @return array{subject: string, html: string, text: string}
 */
function mail_recipe_change_template(string $kind, array $args): array
{
    $bi         = brewery_identity();
    $footerLine = htmlspecialchars($bi['name'], ENT_QUOTES, 'UTF-8')
                . ' · '
                . htmlspecialchars($bi['city'], ENT_QUOTES, 'UTF-8')
                . ', '
                . htmlspecialchars($bi['country'], ENT_QUOTES, 'UTF-8');

    if ($kind === 'filed') {
        $managerName     = htmlspecialchars($args['manager_name']      ?? 'Gestionnaire',   ENT_QUOTES, 'UTF-8');
        $recipeName      = htmlspecialchars($args['recipe_name']        ?? '—',              ENT_QUOTES, 'UTF-8');
        $changeKindLabel = htmlspecialchars($args['change_kind_label']  ?? '—',              ENT_QUOTES, 'UTF-8');
        $summary         = htmlspecialchars(substr($args['summary'] ?? '', 0, 500),          ENT_QUOTES, 'UTF-8');
        $deepLink        = htmlspecialchars($args['deep_link']          ?? 'https://app.maltytask.ch', ENT_QUOTES, 'UTF-8');

        $subject = "Nouvelle demande de modification — {$args['recipe_name']}";

        $text = "Une nouvelle demande de modification de recette a été soumise.\n\n"
              . "Gestionnaire : {$args['manager_name']}\n"
              . "Recette      : {$args['recipe_name']}\n"
              . "Type         : {$args['change_kind_label']}\n"
              . ($args['summary'] !== '' ? "Résumé       : {$args['summary']}\n" : '')
              . "\nConsulter la demande :\n{$args['deep_link']}\n\n"
              . "— {$bi['name']} · MaltyTask\n   https://app.maltytask.ch";

        $summaryRow = '';
        if ($summary !== '') {
            $summaryRow = <<<HTML

            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;">Résumé</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;">{$summary}</td>
            </tr>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f5f3ef;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#2b2414;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f5f3ef;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e0d8cc;overflow:hidden;">

      <!-- Header band -->
      <tr>
        <td style="background:#1c1409;padding:20px 32px;">
          <p style="margin:0;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#c8a96e;font-weight:600;">La Nébuleuse · MaltyTask</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:32px 32px 24px;">
          <h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#1c1409;line-height:1.3;">Nouvelle demande de modification</h1>
          <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3d2e1a;">
            <strong>{$managerName}</strong> a soumis une demande de modification sur la recette <strong>{$recipeName}</strong>.
          </p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;width:100%;border-collapse:collapse;">
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;white-space:nowrap;width:130px;">Type de modification</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;"><strong>{$changeKindLabel}</strong></td>
            </tr>
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;">Recette</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;">{$recipeName}</td>
            </tr>{$summaryRow}
          </table>

          <!-- CTA -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
              <td style="background:#c8a96e;border-radius:6px;">
                <a href="{$deepLink}"
                   style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:600;color:#1c1409;text-decoration:none;letter-spacing:.04em;"
                   target="_blank" rel="noopener noreferrer">Consulter la demande</a>
              </td>
            </tr>
          </table>

          <p style="margin:0;font-size:12px;color:#7a6a55;">Ou copier ce lien dans votre navigateur&nbsp;: <span style="font-family:'Courier New',Courier,monospace;word-break:break-all;">{$deepLink}</span></p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f5f3ef;border-top:1px solid #e0d8cc;padding:16px 32px;">
          <p style="margin:0;font-size:11px;color:#9a8a75;line-height:1.5;">
            {$footerLine}<br>
            <a href="https://app.maltytask.ch" style="color:#9a8a75;text-decoration:underline;">app.maltytask.ch</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    } else {
        // 'decided' — approved or rejected
        $recipeName      = htmlspecialchars($args['recipe_name']       ?? '—',         ENT_QUOTES, 'UTF-8');
        $changeKindLabel = htmlspecialchars($args['change_kind_label'] ?? '—',         ENT_QUOTES, 'UTF-8');
        $decision        = $args['decision'] ?? 'traitée';
        $decisionEsc     = htmlspecialchars($decision,                                  ENT_QUOTES, 'UTF-8');
        $decisionNote    = htmlspecialchars($args['decision_note']     ?? '',           ENT_QUOTES, 'UTF-8');
        $decidedByName   = htmlspecialchars($args['decided_by_name']   ?? 'Admin',     ENT_QUOTES, 'UTF-8');

        $isApproved      = ($decision === 'approuvée');
        $decisionColor   = $isApproved ? '#2d6a2d' : '#8b2a2a';
        $decisionLabel   = $isApproved ? '✓ Approuvée' : '✗ Refusée';

        $subject = "Votre demande sur {$args['recipe_name']} a été {$decision}";

        $text = "Votre demande de modification a été {$decision}.\n\n"
              . "Recette          : {$args['recipe_name']}\n"
              . "Type             : {$args['change_kind_label']}\n"
              . "Décision         : {$decision}\n"
              . "Décidé par       : {$args['decided_by_name']}\n"
              . ($args['decision_note'] !== '' ? "Motif            : {$args['decision_note']}\n" : '')
              . "\n— {$bi['name']} · MaltyTask\n   https://app.maltytask.ch";

        $decisionNoteRow = '';
        if ($decisionNote !== '') {
            $decisionNoteRow = <<<HTML

            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;">Motif</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;font-style:italic;">{$decisionNote}</td>
            </tr>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f5f3ef;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#2b2414;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f5f3ef;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e0d8cc;overflow:hidden;">

      <!-- Header band -->
      <tr>
        <td style="background:#1c1409;padding:20px 32px;">
          <p style="margin:0;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#c8a96e;font-weight:600;">La Nébuleuse · MaltyTask</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:32px 32px 24px;">
          <h1 style="margin:0 0 20px;font-size:22px;font-weight:600;color:#1c1409;line-height:1.3;">Votre demande a été {$decisionEsc}</h1>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;width:100%;border-collapse:collapse;">
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;white-space:nowrap;width:130px;">Recette</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;">{$recipeName}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;">Type</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;">{$changeKindLabel}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;">Décision</td>
              <td style="padding:8px 12px;font-size:14px;font-weight:600;color:{$decisionColor};border-bottom:1px solid #e0d8cc;">{$decisionLabel}</td>
            </tr>
            <tr>
              <td style="padding:8px 12px;font-size:13px;color:#7a6a55;border-bottom:1px solid #e0d8cc;">Décidé par</td>
              <td style="padding:8px 12px;font-size:14px;color:#3d2e1a;border-bottom:1px solid #e0d8cc;">{$decidedByName}</td>
            </tr>{$decisionNoteRow}
          </table>

          <p style="margin:0;font-size:13px;line-height:1.5;color:#7a6a55;">
            Pour consulter vos demandes : <a href="https://app.maltytask.ch/modules/salle-de-controle.php?sec=demandes" style="color:#c8a96e;">Demandes de modification</a>
          </p>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f5f3ef;border-top:1px solid #e0d8cc;padding:16px 32px;">
          <p style="margin:0;font-size:11px;color:#9a8a75;line-height:1.5;">
            {$footerLine}<br>
            <a href="https://app.maltytask.ch" style="color:#9a8a75;text-decoration:underline;">app.maltytask.ch</a>
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    return [
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];
}
