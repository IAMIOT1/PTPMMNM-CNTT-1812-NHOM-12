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
    error_log('TM_REPOSITORY_DETAILS_UNCAUGHT: ' . $exception->getMessage());
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
    error_log('TM_REPOSITORY_DETAILS_FATAL: ' . $error['message']);
    $sendJson(['success' => false, 'message' => $error['message']], 500);
});

try {
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/AuthManager.php';
    require_once __DIR__ . '/src/TmRepository.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        $sendJson(['success' => false, 'message' => 'Chỉ chấp nhận GET.'], 405);
    }
    $filename = $_GET['filename'] ?? null;
    if (!is_string($filename) || $filename === '') {
        throw new InvalidArgumentException('TM_REPOSITORY_FILENAME_INVALID');
    }

    $connection = db();
    $auth = new AuthManager($connection);
    if ($auth->getCurrentUser() === null) {
        $sendJson(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn.'], 401);
    }

    $repository = new TmRepository($connection, dirname(__DIR__) . '/storage/tm-repository');
    $sendJson([
        'success' => true,
        'filename' => $filename,
        'entries' => $repository->getFileEntries($filename),
    ]);
} catch (Throwable $exception) {
    error_log('TM_REPOSITORY_DETAILS_FAILED: ' . $exception->getMessage());
    $statusCode = $exception instanceof InvalidArgumentException ? 400 : 500;
    $sendJson(['success' => false, 'message' => $exception->getMessage()], $statusCode);
}