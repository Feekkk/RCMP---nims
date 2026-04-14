<?php

declare(strict_types=1);

/**
 * Minimal SMTP sender using PHP's built-in mail() is not suitable for Mailpit.
 * This helper opens a raw SMTP connection (enough for local Mailpit testing).
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   smtp_send('to@example.com', 'Subject', "Body\n");
 *   smtp_send_html_with_logo(...) — branded HTML + inline logo (public/logo-nims.png).
 *   smtp_send_return_completion_pdf(...) — return form PDF to technician (see services/returnPDF.php).
 */

const MAILER_LOGO_CONTENT_ID = 'nimslogo@nims.rcmp';

function mailer_logo_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'logo-nims.png';
}

function mailer_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mailer_plain_footer(): string
{
    return "\n\nInformation Technology Department UniKL RCMP";
}

function mailer_wrap_document(string $bannerTitle, string $innerHtml): string
{
    $logoPath = mailer_logo_path();
    $hasLogo = is_file($logoPath) && is_readable($logoPath) && (int) filesize($logoPath) > 0;
    $logoBlock = $hasLogo
        ? '<img src="cid:' . MAILER_LOGO_CONTENT_ID . '" width="200" alt="NIMS — NexCheck" style="display:block;margin:0 auto 10px;border:0;max-width:200px;height:auto">'
        : '<p style="margin:0 0 8px;font-size:22px;font-weight:800;letter-spacing:-0.03em;color:#fff">NIMS</p>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . mailer_esc($bannerTitle) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:28px 14px">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,0.1);border:1px solid #e2e8f0">'
        . '<tr><td style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 45%,#16a34a 100%);padding:26px 22px 22px;text-align:center">'
        . $logoBlock
        . '<p style="margin:0;font-size:14px;font-weight:700;color:rgba(255,255,255,0.96);letter-spacing:0.04em;text-transform:uppercase">' . mailer_esc($bannerTitle) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:30px 26px 34px;color:#0f172a;font-size:15px;line-height:1.55">' . $innerHtml . '</td></tr>'
        . '<tr><td style="padding:18px 22px 22px;border-top:1px solid #e2e8f0;background:#f8fafc;font-size:12px;line-height:1.6;color:#64748b;text-align:center">'
        . 'Universiti Kuala Lumpur RCMP<br><span style="color:#94a3b8">Information Technology Department &middot; NexCheck (NIMS) automated message</span>'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * HTML + plain alternative; embeds logo-nims.png when present (multipart/related).
 *
 * @param string[] $cc
 */
function smtp_send_html_with_logo(string $to, string $subject, string $plainBody, string $htmlDocument, ?string $from = null, array $cc = []): void
{
    $cfg = require __DIR__ . '/mail.php';
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    if (strpos($plainBody, 'Information Technology Department UniKL RCMP') === false) {
        $plainBody .= mailer_plain_footer();
    }

    $cc = array_values(array_unique(array_filter(array_map('trim', $cc), static fn ($a) => $a !== '')));
    $ccFiltered = [];
    $toNorm = smtp__normalize_addr($to);
    foreach ($cc as $c) {
        if (smtp__normalize_addr($c) !== $toNorm) {
            $ccFiltered[] = $c;
        }
    }

    $headers = [
        'From: ' . $fromAddr,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
    ];
    if ($ccFiltered !== []) {
        $headers[] = 'Cc: ' . implode(', ', $ccFiltered);
    }

    $logoPath = mailer_logo_path();
    $logoBytes = (is_file($logoPath) && is_readable($logoPath) && (int) filesize($logoPath) > 0) ? (string) file_get_contents($logoPath) : '';

    if ($logoBytes === '') {
        $bAlt = 'alt_' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"';
        $msg = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $bAlt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $plainBody . "\r\n"
            . '--' . $bAlt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $htmlDocument . "\r\n"
            . '--' . $bAlt . "--\r\n";
        smtp__send_raw(array_merge([$to], $ccFiltered), $msg, $from);

        return;
    }

    $bRel = 'rel_' . bin2hex(random_bytes(10));
    $bAlt = 'alt_' . bin2hex(random_bytes(10));
    $headers[] = 'Content-Type: multipart/related; boundary="' . $bRel . '"';

    $logoB64 = rtrim(chunk_split(base64_encode($logoBytes), 76, "\r\n"));
    $msg = implode("\r\n", $headers) . "\r\n\r\n"
        . '--' . $bRel . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $bAlt . '"' . "\r\n\r\n"
        . '--' . $bAlt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $plainBody . "\r\n"
        . '--' . $bAlt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $htmlDocument . "\r\n"
        . '--' . $bAlt . "--\r\n"
        . '--' . $bRel . "\r\n"
        . 'Content-Type: image/png; name="logo-nims.png"' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n"
        . 'Content-ID: <' . MAILER_LOGO_CONTENT_ID . '>' . "\r\n"
        . 'Content-Disposition: inline; filename="logo-nims.png"' . "\r\n\r\n"
        . $logoB64 . "\r\n"
        . '--' . $bRel . "--\r\n";

    smtp__send_raw(array_merge([$to], $ccFiltered), $msg, $from);
}

function smtp__read_reply($fp): string
{
    $line = '';
    while (($l = fgets($fp, 515)) !== false) {
        $line .= $l;
        if (isset($l[3]) && $l[3] === ' ') break;
    }
    return $line;
}

function smtp__cmd($fp, string $c): string
{
    fwrite($fp, $c . "\r\n");
    return smtp__read_reply($fp);
}

function smtp__first_line(string $reply): string
{
    $reply = trim($reply);
    $p = strpos($reply, "\r\n");
    if ($p === false) {
        return $reply;
    }
    return trim(substr($reply, 0, $p));
}

function smtp__expect_ok(string $reply, string $step): void
{
    if ($reply === '') {
        throw new RuntimeException('SMTP: empty response at ' . $step);
    }
    $c = $reply[0] ?? '';
    if ($c !== '2' && $c !== '3') {
        throw new RuntimeException('SMTP at ' . $step . ': ' . smtp__first_line($reply));
    }
}

function smtp__normalize_addr(string $addr): string
{
    return strtolower(trim($addr));
}

/** @param string[] $rcptTos Unique envelope recipients (To + Cc + Bcc). */
function smtp__send_raw(array $rcptTos, string $rawMessage, ?string $from = null): void
{
    $cfg = require __DIR__ . '/mail.php';
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 1025);
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    $rcptTos = array_values(array_unique(array_filter(array_map('trim', $rcptTos), static fn ($a) => $a !== '')));
    if ($rcptTos === []) {
        throw new RuntimeException('SMTP: no recipients');
    }

    $fp = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno}) — check MAIL_HOST/MAIL_PORT or start Mailpit");
    }

    smtp__expect_ok(smtp__read_reply($fp), 'greeting');
    smtp__expect_ok(smtp__cmd($fp, 'EHLO nims.local'), 'EHLO');
    smtp__expect_ok(smtp__cmd($fp, 'MAIL FROM:<' . $fromAddr . '>'), 'MAIL FROM');
    foreach ($rcptTos as $to) {
        smtp__expect_ok(smtp__cmd($fp, 'RCPT TO:<' . $to . '>'), 'RCPT TO');
    }
    smtp__expect_ok(smtp__cmd($fp, 'DATA'), 'DATA');

    $rawMessage = preg_replace("/\r?\n/", "\r\n", (string) $rawMessage);
    fwrite($fp, $rawMessage . "\r\n.\r\n");
    smtp__expect_ok(smtp__read_reply($fp), 'message body');
    smtp__cmd($fp, 'QUIT');
    fclose($fp);
}

/**
 * @param string[] $cc Each address is added to headers and SMTP envelope (RCPT TO).
 */
function smtp_send(string $to, string $subject, string $body, ?string $from = null, array $cc = []): void
{
    $cfg = require __DIR__ . '/mail.php';
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    if (strpos($body, 'Information Technology Department UniKL RCMP') === false) {
        $body .= mailer_plain_footer();
    }

    $cc = array_values(array_unique(array_filter(array_map('trim', $cc), static fn ($a) => $a !== '')));
    $ccFiltered = [];
    $toNorm = smtp__normalize_addr($to);
    foreach ($cc as $c) {
        if (smtp__normalize_addr($c) !== $toNorm) {
            $ccFiltered[] = $c;
        }
    }

    $headers = [
        'From: ' . $fromAddr,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($ccFiltered !== []) {
        $headers[] = 'Cc: ' . implode(', ', $ccFiltered);
    }

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $envelope = array_merge([$to], $ccFiltered);
    smtp__send_raw($envelope, $raw, $from);
}

function smtp_send_with_attachment(
    string $to,
    string $subject,
    string $bodyText,
    string $attachmentBytes,
    string $attachmentFilename,
    ?string $from = null
): void {
    $cfg = require __DIR__ . '/mail.php';
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    $boundary = 'b1_' . bin2hex(random_bytes(12));
    $encoded = chunk_split(base64_encode($attachmentBytes));
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachmentFilename) ?: 'attachment.pdf';

    $headers = [
        'From: ' . $fromAddr,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $parts = [];
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/plain; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: 8bit';
    if (strpos($bodyText, 'Information Technology Department UniKL RCMP') === false) {
        $bodyText .= mailer_plain_footer();
    }

    $parts[] = '';
    $parts[] = $bodyText;
    $parts[] = '';
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: application/pdf; name="' . $safeName . '"';
    $parts[] = 'Content-Transfer-Encoding: base64';
    $parts[] = 'Content-Disposition: attachment; filename="' . $safeName . '"';
    $parts[] = '';
    $parts[] = rtrim($encoded);
    $parts[] = '';
    $parts[] = '--' . $boundary . '--';
    $parts[] = '';

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    smtp__send_raw([$to], $raw, $from);
}

/**
 * Email the borrower/requester when equipment is marked as returned (laptop handover or NextCheck).
 * CCs IT (config nextcheck_user_notify_cc).
 *
 * @param array<int, array{k: string, v: string}> $summaryRows
 * @param array<int, array{text: string}> $itemLines
 */
function nims_return_notify_recipient(
    string $recipientEmail,
    string $recipientDisplayName,
    string $subject,
    string $bannerTitle,
    string $plainIntro,
    array $summaryRows,
    array $itemLines,
    ?string $recordedBy = null
): void {
    $email = trim($recipientEmail);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $cfg = require __DIR__ . '/mail.php';
    $ccAddr = trim((string) ($cfg['nextcheck_user_notify_cc'] ?? ''));
    $cc = ($ccAddr !== '' && smtp__normalize_addr($ccAddr) !== smtp__normalize_addr($email)) ? [$ccAddr] : [];

    $name = trim($recipientDisplayName) !== '' ? trim($recipientDisplayName) : 'there';

    $plain = 'Hi ' . $name . ",\n\n" . $plainIntro . "\n\n";
    foreach ($summaryRows as $row) {
        $k = (string) ($row['k'] ?? '');
        $v = (string) ($row['v'] ?? '');
        if ($k !== '' || $v !== '') {
            $plain .= $k . ($k !== '' ? ': ' : '') . $v . "\n";
        }
    }
    if ($itemLines !== []) {
        $plain .= "\nEquipment:\n";
        foreach ($itemLines as $line) {
            $plain .= '  • ' . (string) ($line['text'] ?? '') . "\n";
        }
    }
    if ($recordedBy !== null && trim($recordedBy) !== '') {
        $plain .= "\nRecorded by: " . trim($recordedBy) . "\n";
    }

    $inner = '<p style="margin:0 0 18px;font-size:16px;line-height:1.55">Hi ' . mailer_esc($name) . ',</p>'
        . '<p style="margin:0 0 22px;font-size:15px;line-height:1.55;color:#0f172a">' . mailer_esc($plainIntro) . '</p>';

    if ($summaryRows !== []) {
        $inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">';
        foreach ($summaryRows as $row) {
            $k = (string) ($row['k'] ?? '');
            $v = (string) ($row['v'] ?? '');
            $inner .= '<tr><td style="padding:10px 16px;color:#64748b;font-size:13px;font-weight:600;width:140px;vertical-align:top;border-bottom:1px solid #e2e8f0">' . mailer_esc($k) . '</td>'
                . '<td style="padding:10px 16px;color:#0f172a;font-size:15px;vertical-align:top;border-bottom:1px solid #e2e8f0">' . mailer_esc($v) . '</td></tr>';
        }
        $inner .= '</table>';
    }

    if ($itemLines !== []) {
        $inner .= '<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin:8px 0 10px">Equipment</div>'
            . '<ul style="margin:0 0 8px;padding-left:20px;color:#0f172a;font-size:14px;line-height:1.65">';
        foreach ($itemLines as $line) {
            $inner .= '<li style="margin:5px 0">' . mailer_esc((string) ($line['text'] ?? '')) . '</li>';
        }
        $inner .= '</ul>';
    }

    if ($recordedBy !== null && trim($recordedBy) !== '') {
        $inner .= '<p style="margin:18px 0 0;font-size:13px;color:#64748b">Recorded by <strong style="color:#334155">' . mailer_esc(trim($recordedBy)) . '</strong></p>';
    }

    $html = mailer_wrap_document($bannerTitle, $inner);
    smtp_send_html_with_logo($email, $subject, $plain, $html, null, $cc);
}

/**
 * Email the requester when a NextCheck request is rejected or fully accepted (all lines assigned).
 * CCs IT per config nextcheck_user_notify_cc (default it.rcmp@unikl.edu.my).
 */
function nexcheck_request_notify_user_status(
    string $userEmail,
    string $userDisplayName,
    int $nexcheckId,
    string $status,
    array $ctx = []
): void {
    $email = trim($userEmail);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $cfg = require __DIR__ . '/mail.php';
    $ccAddr = trim((string) ($cfg['nextcheck_user_notify_cc'] ?? ''));
    $cc = ($ccAddr !== '' && smtp__normalize_addr($ccAddr) !== smtp__normalize_addr($email)) ? [$ccAddr] : [];

    $name = trim($userDisplayName) !== '' ? trim($userDisplayName) : 'there';
    $borrow = (string) ($ctx['borrow_date'] ?? '');
    $ret = (string) ($ctx['return_date'] ?? '');
    $loc = trim((string) ($ctx['usage_location'] ?? ''));
    $ptKey = (string) ($ctx['program_type'] ?? '');
    $programLabels = [
        'academic' => 'Academic project / class',
        'official_event' => 'Official event',
        'club_society' => 'Club / society activities',
    ];
    $pt = $programLabels[$ptKey] ?? ($ptKey !== '' ? $ptKey : '—');

    $locDisp = $loc !== '' ? $loc : '—';
    $ridLabel = 'Request #' . $nexcheckId;

    if ($status === 'rejected') {
        $subject = 'NIMS - Your equipment request #' . $nexcheckId . ' was not approved';
        $body = 'Hi ' . $name . ",\n\n"
            . 'Your NextCheck equipment request #' . $nexcheckId . " was not approved (rejected) by IT.\n\n"
            . 'Borrow: ' . $borrow . "\n"
            . 'Return: ' . $ret . "\n"
            . 'Program: ' . $pt . "\n"
            . 'Location: ' . $locDisp . "\n\n";
        $rej = trim((string) ($ctx['rejection_reason'] ?? ''));
        if ($rej !== '') {
            $body .= "Reason given:\n" . wordwrap($rej, 72, "\n", true) . "\n\n";
        }
        $body .= "If you have questions, contact the IT department.\n";

        $inner = '<p style="margin:0 0 18px;font-size:16px;line-height:1.55">Hi ' . mailer_esc($name) . ',</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#b91c1c;font-weight:600">Your NextCheck equipment request <strong style="color:#991b1b">#' . (int) $nexcheckId . '</strong> was not approved.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">'
            . '<tr><td colspan="2" style="padding:12px 16px 8px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.07em">' . mailer_esc($ridLabel) . ' &mdash; summary</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;width:132px;vertical-align:top">Borrow</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($borrow) . '</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Return</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($ret) . '</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Program</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($pt) . '</td></tr>'
            . '<tr><td style="padding:8px 16px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Location</td><td style="padding:8px 16px 16px;color:#0f172a;font-size:15px">' . mailer_esc($locDisp) . '</td></tr></table>';
        if ($rej !== '') {
            $inner .= '<div style="margin:0 0 20px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px">'
                . '<div style="font-size:11px;font-weight:800;color:#991b1b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px">Reason</div>'
                . '<div style="font-size:14px;color:#7f1d1d;line-height:1.5;white-space:pre-wrap">' . mailer_esc($rej) . '</div></div>';
        }
        $inner .= '<p style="margin:0;font-size:14px;color:#475569">If you have questions, contact the IT department.</p>';
        $html = mailer_wrap_document('Request update', $inner);
    } elseif ($status === 'accepted') {
        $subject = 'NIMS - Your equipment request #' . $nexcheckId . ' is being fulfilled';
        $body = 'Hi ' . $name . ",\n\n"
            . 'Good news: your NextCheck equipment request #' . $nexcheckId . " has been approved — all lines are assigned and equipment is prepared for checkout.\n\n"
            . 'Borrow: ' . $borrow . "\n"
            . 'Return: ' . $ret . "\n"
            . 'Program: ' . $pt . "\n"
            . 'Location: ' . $locDisp . "\n\n"
            . "Please follow any instructions from IT for collection and return.\n";

        $inner = '<p style="margin:0 0 18px;font-size:16px;line-height:1.55">Hi ' . mailer_esc($name) . ',</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#047857;font-weight:600">Your request <strong>#' . (int) $nexcheckId . '</strong> is approved. All lines are assigned and equipment is ready for checkout.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px">'
            . '<tr><td colspan="2" style="padding:12px 16px 8px;font-size:11px;font-weight:800;color:#166534;text-transform:uppercase;letter-spacing:0.07em">' . mailer_esc($ridLabel) . ' &mdash; summary</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#166534;font-size:13px;font-weight:600;width:132px;vertical-align:top">Borrow</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($borrow) . '</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#166534;font-size:13px;font-weight:600;vertical-align:top">Return</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($ret) . '</td></tr>'
            . '<tr><td style="padding:8px 16px;color:#166534;font-size:13px;font-weight:600;vertical-align:top">Program</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($pt) . '</td></tr>'
            . '<tr><td style="padding:8px 16px 16px;color:#166534;font-size:13px;font-weight:600;vertical-align:top">Location</td><td style="padding:8px 16px 16px;color:#0f172a;font-size:15px">' . mailer_esc($locDisp) . '</td></tr></table>'
            . '<p style="margin:0;font-size:14px;color:#475569">Please follow IT instructions for collection and return.</p>';
        $html = mailer_wrap_document('Request approved', $inner);
    } else {
        return;
    }

    smtp_send_html_with_logo($email, $subject, $body, $html, null, $cc);
}

/**
 * Email IT when a staff member submits a NextCheck equipment request (nexcheck_request).
 * Failures are non-fatal; callers should catch and log.
 */
function nexcheck_request_notify_it(
    int $nexcheckId,
    string $requesterName,
    string $requesterStaffId,
    array $values,
    array $lineItems,
    array $equipmentCategoryLabel
): void {
    $cfg = require __DIR__ . '/mail.php';
    $to = trim((string) ($cfg['notify_item_requests_to'] ?? ''));
    if ($to === '') {
        return;
    }

    $programLabels = [
        'academic' => 'Academic',
        'official_event' => 'Official event',
        'club_society' => 'Club / society',
    ];
    $pt = $programLabels[$values['program_type'] ?? ''] ?? (string) ($values['program_type'] ?? '');

    $byCat = [];
    foreach ($lineItems as $row) {
        $id = $row['id'] ?? '';
        $qty = (int) ($row['qty'] ?? 0);
        if ($qty < 1) {
            continue;
        }
        $label = $equipmentCategoryLabel[$id] ?? (string) $id;
        $byCat[$label] = ($byCat[$label] ?? 0) + $qty;
    }
    $lines = [];
    foreach ($byCat as $lab => $q) {
        $lines[] = '  • ' . $lab . ' × ' . $q;
    }
    $itemsBlock = $lines !== [] ? implode("\n", $lines) : '  (none)';

    $reason = trim((string) ($values['reason'] ?? ''));
    $loc = trim((string) ($values['usage_location'] ?? ''));

    $subject = 'NIMS - New equipment request #' . $nexcheckId;
    $body = "A new equipment request was submitted in NIMS.\n\n"
        . "Request ID: {$nexcheckId}\n"
        . 'Requester: ' . $requesterName . ' (staff_id: ' . $requesterStaffId . ")\n"
        . 'Borrow date: ' . ($values['borrow_date'] ?? '') . "\n"
        . 'Return date: ' . ($values['return_date'] ?? '') . "\n"
        . 'Program type: ' . $pt . "\n"
        . 'Usage location: ' . $loc . "\n"
        . "Reason:\n" . ($reason !== '' ? wordwrap($reason, 72, "\n", true) : '—') . "\n\n"
        . "Items:\n"
        . $itemsBlock . "\n";

    $bd = (string) ($values['borrow_date'] ?? '');
    $rd = (string) ($values['return_date'] ?? '');
    $itemsHtml = '<ul style="margin:8px 0 0;padding-left:20px;color:#0f172a;font-size:14px;line-height:1.65">';
    if ($lines === []) {
        $itemsHtml .= '<li style="color:#64748b">(none)</li>';
    } else {
        foreach ($lines as $line) {
            $t = trim(ltrim($line, " \t•\0\x0B"));
            $itemsHtml .= '<li style="margin:4px 0">' . mailer_esc($t) . '</li>';
        }
    }
    $itemsHtml .= '</ul>';

    $inner = '<p style="margin:0 0 18px;font-size:16px;line-height:1.55">A new <strong>NexCheck</strong> equipment request needs your attention.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">'
        . '<tr><td colspan="2" style="padding:12px 16px 8px;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.07em">Request #' . (int) $nexcheckId . '</td></tr>'
        . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;width:140px;vertical-align:top">Requester</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($requesterName) . ' <span style="color:#64748b;font-size:13px">(' . mailer_esc($requesterStaffId) . ')</span></td></tr>'
        . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Borrow</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($bd) . '</td></tr>'
        . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Return</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($rd) . '</td></tr>'
        . '<tr><td style="padding:8px 16px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Program</td><td style="padding:8px 16px;color:#0f172a;font-size:15px">' . mailer_esc($pt) . '</td></tr>'
        . '<tr><td style="padding:8px 16px 12px;color:#64748b;font-size:13px;font-weight:600;vertical-align:top">Location</td><td style="padding:8px 16px 12px;color:#0f172a;font-size:15px">' . mailer_esc($loc !== '' ? $loc : '—') . '</td></tr>'
        . '<tr><td colspan="2" style="padding:0 16px 16px">'
        . '<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px">Reason</div>'
        . '<div style="font-size:14px;color:#334155;line-height:1.5;white-space:pre-wrap">' . mailer_esc($reason !== '' ? $reason : '—') . '</div>'
        . '</td></tr></table>'
        . '<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px">Requested items</div>'
        . $itemsHtml;

    $html = mailer_wrap_document('New equipment request', $inner);
    smtp_send_html_with_logo($to, $subject, $body, $html);
}

/**
 * Email the laptop/desktop return form PDF to the technician who completed the return (NIMS user).
 */
function smtp_send_return_completion_pdf(
    string $toEmail,
    string $technicianName,
    string $pdfBytes,
    int $assetId,
    ?string $from = null
): void {
    $name = trim($technicianName) !== '' ? trim($technicianName) : 'Technician';
    $subject = 'NIMS - Return form PDF (Asset ' . $assetId . ')';
    $body = 'Hi ' . $name . ",\n\n"
        . 'Thank you for completing the equipment return in NIMS. Attached is the generated return form PDF for your records.' . "\n\n"
        . 'Asset ID: ' . $assetId . "\n\n"
        . 'If you did not perform this return, please contact the IT Department.' . "\n";
    $filename = 'UNIKL_RCMP_Return_Form_' . $assetId . '.pdf';
    smtp_send_with_attachment($toEmail, $subject, $body, $pdfBytes, $filename, $from);
}
