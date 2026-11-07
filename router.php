<?php
declare(strict_types=1);
// PHP development router. Apache continues to use public/ and its existing routes.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
if (preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, '\\') || preg_match('~(^|/)\.~', $path)) {
    http_response_code(404); exit('Page not found.');
}
// Keep previously shared /public/ links working after correcting the document root.
if ($path === '/public' || str_starts_with($path, '/public/')) {
    $target = '/' . ltrim(substr($path, 7), '/');
    $query = parse_url($uri, PHP_URL_QUERY);
    header('Location: ' . $target . ($query !== null ? '?' . $query : ''), true, 307);
    exit;
}
$root = realpath(__DIR__ . '/public');
$file = realpath($root . ($path === '/' ? '/index.php' : $path));
if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit('Page not found.');
}
return false;
