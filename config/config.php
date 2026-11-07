<?php
declare(strict_types=1);

// Environment variables override local development defaults. Never commit secrets.
$config = [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'bella_vista_spa',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'timezone' => 'Asia/Manila',
    'session_timeout' => 1800,
    'admin_invitation_minutes' => 60,
    'development_mode' => getenv('APP_ENV') === 'development',
];
if (is_file(__DIR__ . '/local.php')) {
    $config = array_replace($config, require __DIR__ . '/local.php');
}
return $config;
