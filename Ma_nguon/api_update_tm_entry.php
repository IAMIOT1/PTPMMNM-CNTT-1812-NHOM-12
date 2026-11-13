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
    error_log('TM_REPOSITORY_UPDATE_UNCAUGHT: ' . $exception->getMessage());
    $sendJson(['success' => false, 'message' => $exception->getMessage()], 500);
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
    error_log('TM_REPOSITORY_UPDATE_FATAL: ' . $error['message']);
    $sendJson(['success' => false, 'message' => $error['message']], 500);
});

try {
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/AuthManager.php';
    require_once __DIR__ . '/src/Csrf.php';
    require_once __DIR__ . '/src/ProjectAccess.php';
    require_once __DIR__ . '/src/TmRepository.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $sendJson(['success' => false, 'message' => 'Chỉ chấp nhận POST.'], 405);
    }

    $connection = db();
    $auth = new AuthManager($connection);
    $user = $auth->getCurrentUser();
    if ($user === null) {
        $sendJson(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn.'], 401);
    }
    if (!Csrf::isValid($_POST)) {
        $sendJson(['success' => false, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
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
            $sendJson(['success' => false, 'message' => 'Bạn không có quyền sửa kho TM.'], 403);
        }
    }

    $filename = $_POST['filename'] ?? null;
    $rowIndex = filter_var($_POST['row_index'] ?? null, FILTER_VALIDATE_INT);
    $targetText = $_POST['target_text'] ?? null;
    if (!is_string($filename) || $filename === '' || $rowIndex === false || !is_string($targetText)) {
        throw new InvalidArgumentException('TM_REPOSITORY_ENTRY_INVALID');
    }

    $repository = new TmRepository($connection, dirname(__DIR__) . '/storage/tm-repository');
    $entry = $repository->updateFileTranslation($filename, (int)$rowIndex, $targetText);
    $sendJson([
        'success' => true,
        'entry' => $entry,
        'message' => 'Đã lưu bản dịch.',
    ]);
} catch (Throwable $exception) {
    error_log('TM_REPOSITORY_UPDATE_FAILED: ' . $exception->getMessage());
    $statusCode = $exception instanceof InvalidArgumentException ? 400 : 500;
    $sendJson(['success' => false, 'message' => $exception->getMessage()], $statusCode);
}