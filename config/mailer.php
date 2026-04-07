<?php
/**
 * Minimal SMTP sender using PHP's built-in mail() is not suitable for Mailpit.
 * This helper opens a raw SMTP connection (enough for local Mailpit testing).
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   smtp_send('to@example.com', 'Subject', "Body\n");
 */

function smtp__read_banner($fp): void
{
    while (($l = fgets($fp, 515)) !== false) {
        if (isset($l[3]) && $l[3] === ' ') {
            return;
        }
    }
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

function smtp__send_raw(string $to, string $rawMessage, ?string $from = null): void
{
    $cfg = require __DIR__ . '/mail.php';
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 1025);
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    $fp = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
    }

    smtp__read_banner($fp);
    smtp__cmd($fp, 'EHLO nims.local');
    smtp__cmd($fp, 'MAIL FROM:<' . $fromAddr . '>');
    smtp__cmd($fp, 'RCPT TO:<' . $to . '>');
    smtp__cmd($fp, 'DATA');

    $rawMessage = preg_replace("/\r?\n/", "\r\n", (string) $rawMessage);
    fwrite($fp, $rawMessage . "\r\n.\r\n");
    smtp__read_reply($fp);
    smtp__cmd($fp, 'QUIT');
    fclose($fp);
}

function smtp_send(string $to, string $subject, string $body, ?string $from = null): void
{
    $cfg = require __DIR__ . '/mail.php';
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    $signature = "\n\nInformation Technology Department UniKL RCMP";
    if (strpos($body, 'Information Technology Department UniKL RCMP') === false) {
        $body .= $signature;
    }

    $headers = [
        'From: ' . $fromAddr,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    smtp__send_raw($to, $raw, $from);
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
    smtp__send_raw($to, $raw, $from);
}
