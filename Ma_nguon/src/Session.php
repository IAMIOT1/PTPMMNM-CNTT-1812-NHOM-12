<?php

declare(strict_types=1);
// Hạ tầng mẫu do giảng viên cấp; buổi này tập trung thao tác $_SESSION.
require_once __DIR__ . '/SessionCookie.php';
AppSession::start();
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
function checkCsrf(array $input): void
{
    $token = $input['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('CSRF_INVALID');
    }
}
