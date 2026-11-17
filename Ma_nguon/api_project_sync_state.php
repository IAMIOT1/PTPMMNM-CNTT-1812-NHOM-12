<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/ProjectSyncState.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    $connection = db();
    $user = (new AuthManager($connection))->getCurrentUser();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'revision' => ProjectSyncState::fingerprint($connection, (int)$user['id']),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('PROJECT_SYNC_STATE_FAILED: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Không thể kiểm tra cập nhật workspace.']);
}
