<?php
/**
 * Minimal SMTP sender using PHP's built-in mail() is not suitable for Mailpit.
 * This helper opens a raw SMTP connection (enough for local Mailpit testing).
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   smtp_send('to@example.com', 'Subject', "Body\n");
 *   smtp_send_return_completion_pdf(...) — return form PDF to technician (see services/returnPDF.php).
 */

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

    $signature = "\n\nInformation Technology Department UniKL RCMP";
    if (strpos($body, 'Information Technology Department UniKL RCMP') === false) {
        $body .= $signature;
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
    $signature = "\n\nInformation Technology Department UniKL RCMP";
    if (strpos($bodyText, 'Information Technology Department UniKL RCMP') === false) {
        $bodyText .= $signature;
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

    if ($status === 'rejected') {
        $subject = 'NIMS - Your equipment request #' . $nexcheckId . ' was not approved';
        $body = 'Hi ' . $name . ",\n\n"
            . 'Your NextCheck equipment request #' . $nexcheckId . " was not approved (rejected) by IT.\n\n"
            . 'Borrow: ' . $borrow . "\n"
            . 'Return: ' . $ret . "\n"
            . 'Program: ' . $pt . "\n"
            . 'Location: ' . ($loc !== '' ? $loc : '—') . "\n\n";
        $rej = trim((string) ($ctx['rejection_reason'] ?? ''));
        if ($rej !== '') {
            $body .= "Reason given:\n" . wordwrap($rej, 72, "\n", true) . "\n\n";
        }
        $body .= "If you have questions, contact the IT department.\n";
    } elseif ($status === 'accepted') {
        $subject = 'NIMS - Your equipment request #' . $nexcheckId . ' is being fulfilled';
        $body = 'Hi ' . $name . ",\n\n"
            . 'Good news: your NextCheck equipment request #' . $nexcheckId . " has been approved — all lines are assigned and equipment is prepared for checkout.\n\n"
            . 'Borrow: ' . $borrow . "\n"
            . 'Return: ' . $ret . "\n"
            . 'Program: ' . $pt . "\n"
            . 'Location: ' . ($loc !== '' ? $loc : '—') . "\n\n"
            . "Please follow any instructions from IT for collection and return.\n";
    } else {
        return;
    }

    smtp_send($email, $subject, $body, null, $cc);
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

    smtp_send($to, $subject, $body);
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
