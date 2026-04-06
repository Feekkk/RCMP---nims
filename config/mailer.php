<?php
/**
 * Minimal SMTP sender using PHP's built-in mail() is not suitable for Mailpit.
 * This helper opens a raw SMTP connection (enough for local Mailpit testing).
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   smtp_send('to@example.com', 'Subject', "Body\n");
 */

function smtp_send(string $to, string $subject, string $body, ?string $from = null): void
{
    $cfg = require __DIR__ . '/mail.php';
    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $port = (int) ($cfg['port'] ?? 1025);
    $fromAddr = $from ?: (string) (($cfg['from']['address'] ?? null) ?: 'nexcheck.rcmp@unikl.edu.my');

    $fp = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
    }
    $read = static function () use ($fp): string {
        $line = '';
        while (($l = fgets($fp, 515)) !== false) {
            $line .= $l;
            if (isset($l[3]) && $l[3] === ' ') break;
        }
        return $line;
    };
    $cmd = static function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $cmd('EHLO nims.local');
    $cmd('MAIL FROM:<' . $fromAddr . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $cmd('DATA');

    $headers = [
        'From: ' . $fromAddr,
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $data = preg_replace("/\r?\n/", "\r\n", (string) $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $read();
    $cmd('QUIT');
    fclose($fp);
}
