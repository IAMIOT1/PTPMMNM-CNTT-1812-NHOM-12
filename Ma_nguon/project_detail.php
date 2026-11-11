<?php
declare(strict_types=1);

$isImportJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && is_string($_POST['action'] ?? null)
    && $_POST['action'] === 'import_source'
    && (
        (string)($_POST['response_format'] ?? '') === 'json' ||
        str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    );
$isGlossaryJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && is_string($_POST['action'] ?? null)
    && in_array($_POST['action'], ['glossary_save', 'glossary_delete'], true);
$isTranslationJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'save_translation';
$isTranslationLockJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array(($_GET['action'] ?? ''), ['lock_translation', 'unlock_translation'], true);
$isSuggestionJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && ($_GET['action'] ?? '') === 'suggest_translation';
$isTranslationHistoryJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && ($_GET['action'] ?? '') === 'list_translation_history';
$isWorkspaceJsonRequest = $isImportJsonRequest || $isGlossaryJsonRequest || $isTranslationJsonRequest
    || $isTranslationLockJsonRequest || $isSuggestionJsonRequest || $isTranslationHistoryJsonRequest;
$respondImportJsonFailure = static function (string $message, int $statusCode): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};
if ($isWorkspaceJsonRequest) {
    ini_set('display_errors', '0');
    ob_start();
    set_error_handler(static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}
if ($isGlossaryJsonRequest || $isTranslationJsonRequest || $isTranslationLockJsonRequest || $isSuggestionJsonRequest) {
    set_exception_handler(static function (Throwable $exception) use (
        $respondImportJsonFailure,
        $isTranslationJsonRequest,
        $isTranslationLockJsonRequest,
        $isSuggestionJsonRequest,
        $isTranslationHistoryJsonRequest
    ): void {
        error_log('PROJECT_DETAIL_JSON_UNHANDLED: ' . $exception->getMessage());
        $respondImportJsonFailure(
            $isTranslationJsonRequest
                ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.'
                : ($isTranslationLockJsonRequest
                    ? 'Không thể cập nhật trạng thái khóa.'
                    : ($isSuggestionJsonRequest
                        ? 'Không thể tạo gợi ý lúc này.'
                        : ($isTranslationHistoryJsonRequest
                            ? 'Không thể tải lịch sử bản dịch.'
                            : 'Không thể xử lý yêu cầu từ điển. Vui lòng thử lại.'))),
            500
        );
    });
    register_shutdown_function(static function () use (
        $respondImportJsonFailure,
        $isTranslationJsonRequest,
        $isTranslationLockJsonRequest,
        $isSuggestionJsonRequest,
        $isTranslationHistoryJsonRequest
    ): void {
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
        error_log('PROJECT_DETAIL_JSON_FATAL: ' . $error['message']);
        $respondImportJsonFailure(
            $isTranslationJsonRequest
                ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.'
                : ($isTranslationLockJsonRequest
                    ? 'Không thể cập nhật trạng thái khóa.'
                    : ($isSuggestionJsonRequest
                        ? 'Không thể tạo gợi ý lúc này.'
                        : ($isTranslationHistoryJsonRequest
                            ? 'Không thể tải lịch sử bản dịch.'
                            : 'Không thể xử lý yêu cầu từ điển. Vui lòng thử lại.'))),
            500
        );
    });
}

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/ProjectAccess.php';

$connection = null;
$auth = null;
$currentUser = null;
try {
    $connection = db();
    $auth = new AuthManager($connection);
    $currentUser = $auth->getCurrentUser();
} catch (Throwable $exception) {
    if (!$isWorkspaceJsonRequest) {
        throw $exception;
    }
    error_log('PROJECT_DETAIL_API_ROUTE_FAILED: ' . $exception->getMessage());
    $respondImportJsonFailure(
        $isTranslationJsonRequest ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.' : 'Không thể xử lý yêu cầu dự án.',
        500
    );
}
if ($currentUser === null) {
    if ($isWorkspaceJsonRequest) {
        $respondImportJsonFailure('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 401);
    }
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/project_detail.php'));
}

$projectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId < 1) {
    if ($isWorkspaceJsonRequest) {
        $respondImportJsonFailure('ID dự án không hợp lệ.', 400);
    }
    header('Location: index.php', true, 303);
    exit;
}

$accessResult = null;
try {
    $accessResult = queryParams(
        $connection,
        'SELECT p.id
         FROM projects p
         WHERE p.id = $1
           AND (p.created_by = $2 OR EXISTS (
               SELECT 1 FROM project_members pm
               WHERE pm.project_id = p.id AND pm.user_id = $2
           ))',
        [$projectId, (int)$currentUser['id']]
    );
} catch (Throwable $exception) {
    if (!$isWorkspaceJsonRequest) {
        throw $exception;
    }
    error_log('PROJECT_DETAIL_API_ROUTE_FAILED: ' . $exception->getMessage());
    $respondImportJsonFailure(
        $isTranslationJsonRequest ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.' : 'Không thể xử lý yêu cầu dự án.',
        500
    );
}
if (!pg_fetch_assoc($accessResult)) {
    if ($isWorkspaceJsonRequest) {
        $respondImportJsonFailure('Không tìm thấy dự án hoặc bạn không có quyền truy cập.', 404);
    }
    http_response_code(404);
    header('Location: index.php?error=project_not_found', true, 303);
    exit;
}

$_GET['project_id'] = $projectId;
define('TMS_PROJECT_DETAIL', true);
require __DIR__ . '/index.php';
