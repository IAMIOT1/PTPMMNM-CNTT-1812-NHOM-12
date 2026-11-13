<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

$sendJson = static function (array $payload, int $statusCode = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (Throwable $exception) use ($sendJson): void {
    error_log('TM_REPOSITORY_UPLOAD_UNCAUGHT: ' . $exception->getMessage());
    $sendJson(['success' => false, 'imported' => 0, 'message' => $exception->getMessage()], 500);
});
register_shutdown_function(static function () use ($sendJson): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ], true)) {
        return;
    }
    error_log('TM_REPOSITORY_UPLOAD_FATAL: ' . $error['message']);
    $sendJson(['success' => false, 'imported' => 0, 'message' => $error['message']], 500);
});

try {
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/AuthManager.php';
    require_once __DIR__ . '/src/Csrf.php';
    require_once __DIR__ . '/src/ProjectAccess.php';
    require_once __DIR__ . '/src/TmRepository.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $sendJson(['success' => false, 'imported' => 0, 'message' => 'Chỉ chấp nhận POST.'], 405);
    }

    $connection = db();
    $auth = new AuthManager($connection);
    $user = $auth->getCurrentUser();
    if ($user === null) {
        $sendJson(['success' => false, 'imported' => 0, 'message' => 'Phiên đăng nhập đã hết hạn.'], 401);
    }
    if (!Csrf::isValid($_POST)) {
        $sendJson(['success' => false, 'imported' => 0, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
    }
    if (!ProjectAccess::isSystemAdmin($user)) {
        $accessResult = queryParams(
            $connection,
            "SELECT EXISTS (
                SELECT 1 FROM project_members
                WHERE user_id = $1 AND role_in_project = 'Team Lead'
             )",
            [(int)$user['id']]
        );
        if (!(bool)pg_fetch_result($accessResult, 0, 0)) {
            $sendJson(['success' => false, 'imported' => 0, 'message' => 'Bạn không có quyền quản lý kho TM.'], 403);
        }
    }
    if (($_POST['action'] ?? '') !== 'upload_tm_csv') {
        $sendJson(['success' => false, 'imported' => 0, 'message' => 'Action upload không hợp lệ.'], 400);
    }
    if (!isset($_FILES['tm_csv']) || !is_array($_FILES['tm_csv'])) {
        throw new InvalidArgumentException('TM_REPOSITORY_UPLOAD_INVALID');
    }

    $upload = $_FILES['tm_csv'];
    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'TM_REPOSITORY_UPLOAD_TOO_LARGE'
                : 'TM_REPOSITORY_UPLOAD_INVALID'
        );
    }
    if (!is_string($upload['tmp_name'] ?? null) || !is_string($upload['name'] ?? null)) {
        throw new InvalidArgumentException('TM_REPOSITORY_UPLOAD_INVALID');
    }

    $repository = new TmRepository($connection, dirname(__DIR__) . '/storage/tm-repository');
    $result = $repository->importUploaded($upload['tmp_name'], $upload['name']);
    $filePath = $repository->resolveFilename($result['filename']);
    $stat = stat($filePath);
    if ($stat === false) {
        throw new RuntimeException('TM_REPOSITORY_FILE_STAT_FAILED');
    }

    $sendJson([
        'success' => true,
        'imported' => $result['rows'],
        'message' => "Đã nhập {$result['rows']} cặp câu vào kho TM.",
        'file' => [
            'name' => $result['filename'],
            'size' => (int)$stat['size'],
            'modified_at' => (int)$stat['mtime'],
            'rows' => $result['rows'],
        ],
    ]);
} catch (Throwable $exception) {
    error_log('TM_REPOSITORY_UPLOAD_FAILED: ' . $exception->getMessage());
    $sendJson([
        'success' => false,
        'imported' => 0,
        'message' => $exception->getMessage(),
    ], 500);
}