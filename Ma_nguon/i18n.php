<?php
declare(strict_types=1);

$language = (string)($_GET['lang'] ?? 'vi');
if (!in_array($language, ['vi', 'en'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unsupported language.']);
    exit;
}

$dictionary = require __DIR__ . '/lang/' . $language . '.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo json_encode($dictionary, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
