<?php
/**
 * Mail config.
 *
 * Defaults are set for Microsoft 365 / Outlook SMTP.
 * For local development with Mailpit, override:
 *   MAIL_HOST=127.0.0.1
 *   MAIL_PORT=1025
 *   MAIL_ENCRYPTION=
 *   MAIL_USERNAME=
 *   MAIL_PASSWORD=
 */
return [
    'transport' => getenv('MAIL_TRANSPORT') ?: 'smtp',
    'host' => getenv('MAIL_HOST') ?: 'smtp.office365.com',
    'port' => (int) (getenv('MAIL_PORT') ?: 587),
    'username' => getenv('MAIL_USERNAME') ?: 'nexcheck.rcmp@unikl.edu.my',
    'password' => getenv('MAIL_PASSWORD') ?: 'Nex@ITD03',
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls', // 'tls' | 'ssl' | null
    'from' => [
        'address' => getenv('MAIL_FROM_ADDRESS') ?: 'nexcheck.rcmp@unikl.edu.my',
        'name' => getenv('MAIL_FROM_NAME') ?: 'NexCheck',
    ],
    /** Notified when a user submits an equipment (NextCheck) request. Override with MAIL_REQUEST_ITEMS_TO. */
    'notify_item_requests_to' => getenv('MAIL_REQUEST_ITEMS_TO') ?: 'it.rcmp@unikl.edu.my',
    /** CC on user-facing NIMS emails (NextCheck status, equipment return confirmations). Override with MAIL_NEXTCHECK_USER_CC. */
    'nextcheck_user_notify_cc' => getenv('MAIL_NEXTCHECK_USER_CC') ?: 'it.rcmp@unikl.edu.my',
];
