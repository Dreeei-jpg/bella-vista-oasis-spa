<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone']);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; font-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('Cache-Control: no-store');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)$config['session_timeout']);
session_name('bella_vista_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > $config['session_timeout']) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/modules/auth.php';
require_once __DIR__ . '/modules/catalog.php';
require_once __DIR__ . '/modules/booking.php';

set_exception_handler(function (Throwable $exception): void {
    error_log('Bella Vista: ' . $exception->getMessage());
    http_response_code(503);
    $pageTitle = 'A moment of pause';
    require __DIR__ . '/components/header.php';
    echo '<section class="page-intro container"><p class="eyebrow">Temporarily unavailable</p><h1>A moment<br>of pause.</h1><p>We could not connect to our service. Please try again shortly.</p><a class="button" href="' . e(url('/')) . '">Return home <span>↗</span></a></section>';
    require __DIR__ . '/components/footer.php';
});
