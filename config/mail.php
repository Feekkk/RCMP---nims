<?php
/**
 * Mail config for local/dev.
 *
 * Mailpit defaults:
 * - SMTP: http://127.0.0.1:1025 (SMTP host/port)
 * - UI:   http://127.0.0.1:8025
 */
return [
    'transport' => getenv('MAIL_TRANSPORT') ?: 'smtp',
    'host' => getenv('MAIL_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('MAIL_PORT') ?: 1025),
    'username' => getenv('MAIL_USERNAME') ?: null,
    'password' => getenv('MAIL_PASSWORD') ?: null,
    'encryption' => getenv('MAIL_ENCRYPTION') ?: null, // 'tls' | 'ssl' | null
    'from' => [
        'address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@nims.local',
        'name' => getenv('MAIL_FROM_NAME') ?: 'NIMS',
    ],
    /** Notified when a user submits an equipment (NextCheck) request. Override with MAIL_REQUEST_ITEMS_TO. */
    'notify_item_requests_to' => getenv('MAIL_REQUEST_ITEMS_TO') ?: 'it.rcmp@unikl.edu.my',
    /** CC on NextCheck status emails to the requester (accepted / rejected). Override with MAIL_NEXTCHECK_USER_CC. */
    'nextcheck_user_notify_cc' => getenv('MAIL_NEXTCHECK_USER_CC') ?: 'it.rcmp@unikl.edu.my',
];
