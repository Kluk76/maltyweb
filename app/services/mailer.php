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

const MAIL_FROM      = 'noreply@maltytask.ch';
const MAIL_FROM_NAME = 'MaltyTask — La Nébuleuse';

/**
 * Send a UTF-8 multipart/alternative email via PHP mail().
 *
 * @param string      $to        Recipient address — validated before sending.
 * @param string      $subject   Plain UTF-8 subject (will be RFC2047-encoded).
 * @param string      $htmlBody  HTML part.
 * @param string|null $textBody  Plain-text part; derived from HTML if null.
 * @return bool  true on success, false if validation fails or mail() returns false.
 *               On failure an error is written to the PHP error log.
 *               NEVER log the body or any token/URL it may contain.
 */
function send_mail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('send_mail: invalid recipient address (rejected without sending)');
        return false;
    }

    // Derive plain-text fallback from HTML when not explicitly supplied.
    if ($textBody === null) {
        $textBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody)
        );
        // Collapse runs of blank lines to a single blank line.
        $textBody = preg_replace("/\n{3,}/", "\n\n", $textBody) ?? $textBody;
        $textBody = trim($textBody);
    }

    // RFC2047 subject encoding so accented French characters render correctly.
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

    // Unique MIME boundary.
    $boundary = 'mt_' . uniqid('', true);

    // ── Headers ──────────────────────────────────────────────────────────────
    $fromEncoded = mb_encode_mimeheader(MAIL_FROM_NAME, 'UTF-8', 'B', "\r\n");
    $headers     = implode("\r\n", [
        'From: '     . $fromEncoded . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_FROM,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: MaltyTask',
    ]);

    // ── Body ─────────────────────────────────────────────────────────────────
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($textBody) . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";

    $body .= "--{$boundary}--";

    $ok = mail($to, $encodedSubject, $body, $headers);

    if (!$ok) {
        // Log the failure but NEVER log the body (which contains the token/URL).
        error_log('send_mail: mail() returned false for recipient ' . $to);
    }

    return $ok;
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
