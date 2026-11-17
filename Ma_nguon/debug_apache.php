<?php
declare(strict_types=1);

require_once __DIR__ . '/src/SessionCookie.php';
AppSession::start();

$currentUser = $_SESSION['user'] ?? null;
if (!is_array($currentUser) || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    echo '{"error":"Not Found"}';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo '{"error":"Method Not Allowed"}';
    exit;
}

$iconPath = __DIR__ . '/icons/iamiot-192.png';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
echo json_encode(
    [
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? null,
        '__dir__' => __DIR__,
        'icon_path_1' => $iconPath,
        'icon_exists_1' => file_exists($iconPath),
        'icon_readable_1' => is_readable($iconPath),
        'parent_dir_contents' => scandir(__DIR__),
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
