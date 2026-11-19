<?php
require_once __DIR__ . '/env.php';

return [
    'host' => vendlink_env('SMTP_HOST', 'smtp-relay.brevo.com'),
    'port' => (int)vendlink_env('SMTP_PORT', '587'),
    'username' => vendlink_env('SMTP_USER', ''),
    'password' => vendlink_env('SMTP_PASS', ''),
    'from' => vendlink_env('MAIL_FROM', vendlink_env('SMTP_FROM', 'no-reply@example.com')),
];