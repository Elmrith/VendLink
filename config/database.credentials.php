<?php
require_once __DIR__ . '/env.php';

return [
    'host' => vendlink_env('DB_HOST', vendlink_env('VENDLINK_DB_HOST', '')),
    'database' => vendlink_env('DB_NAME', vendlink_env('VENDLINK_DB_NAME', '')),
    'username' => vendlink_env('DB_USER', vendlink_env('VENDLINK_DB_USER', '')),
    'password' => vendlink_env('DB_PASS', vendlink_env('VENDLINK_DB_PASS', '')),
    'charset' => vendlink_env('DB_CHARSET', vendlink_env('VENDLINK_DB_CHARSET', 'utf8mb4')),
];