<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = getenv('INEXO_BASE_PATH') ?: getenv('APP_BASE_PATH') ?: '';
$base = $base !== '' ? '/' . trim($base, '/') : '';
$filePath = $path;

if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
    $filePath = substr($path, strlen($base)) ?: '/';
}

$file = __DIR__ . $filePath;

if ($filePath !== '/' && is_file($file)) {
    $contentType = function_exists('mime_content_type') ? mime_content_type($file) : false;
    if (is_string($contentType) && $contentType !== '') {
        header('Content-Type: ' . $contentType);
    }
    readfile($file);
    return true;
}

require __DIR__ . '/index.php';
