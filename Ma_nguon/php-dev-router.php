<?php

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    return false;
}

if ($requestPath === '/dashboard-ui') {
    header('Location: /dashboard-ui/', true, 308);
    exit;
}

if (!str_starts_with($requestPath, '/dashboard-ui/')) {
    return false;
}

$distDirectory = realpath(__DIR__ . '/dashboard-ui/dist');
if ($distDirectory === false) {
    http_response_code(503);
    echo 'Dashboard build not found. Run npm run build in dashboard-ui.';
    exit;
}

$relativePath = rawurldecode(substr($requestPath, strlen('/dashboard-ui/')));
if (str_contains($relativePath, "\0")) {
    http_response_code(404);
    exit;
}

$filePath = $relativePath === ''
    ? $distDirectory . '/index.html'
    : realpath($distDirectory . '/' . $relativePath);
$distPrefix = rtrim($distDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if ($filePath === false || !str_starts_with($filePath, $distPrefix) || !is_file($filePath)) {
    if ($relativePath !== '' && pathinfo($relativePath, PATHINFO_EXTENSION) !== '') {
        http_response_code(404);
        exit;
    }

    $filePath = $distDirectory . '/index.html';
}

if (!is_file($filePath)) {
    http_response_code(503);
    echo 'Dashboard build is incomplete. Run npm run build in dashboard-ui.';
    exit;
}

$mimeTypes = [
    'css' => 'text/css; charset=utf-8',
    'html' => 'text/html; charset=utf-8',
    'ico' => 'image/x-icon',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg' => 'image/svg+xml',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Cache-Control: ' . ($extension === 'html' ? 'no-cache' : 'public, max-age=31536000, immutable'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($filePath);
}
