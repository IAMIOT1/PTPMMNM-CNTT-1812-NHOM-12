<?php
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/VariableValidator.php';
require_once __DIR__ . '/src/AuditLogger.php';
require_once __DIR__ . '/src/NotificationManager.php';
require_once __DIR__ . '/src/RemoteContentService.php';

$syncBackJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_SERVER['HTTP_X_TMS_SYNC_BACK'] ?? '') === '1';
$syncBackJsonBufferStarted = false;
if ($syncBackJsonRequest) {
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
    $syncBackJsonBufferStarted = true;
}
$syncBackJsonInputError = null;
$syncBackJsonInputStatus = 400;
if ($syncBackJsonRequest) {
    try {
        $requestContentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($requestContentType !== 'application/json') {
            $syncBackJsonInputError = 'Yêu cầu đồng bộ phải sử dụng Content-Type application/json.';
        } else {
            $rawSyncBackInput = file_get_contents('php://input');
            $syncBackPayload = is_string($rawSyncBackInput) && trim($rawSyncBackInput) !== ''
                ? json_decode($rawSyncBackInput, true, 512, JSON_THROW_ON_ERROR)
                : null;
            if (
                !is_array($syncBackPayload) ||
                array_is_list($syncBackPayload) ||
                ($syncBackPayload['action'] ?? null) !== 'sync_back' ||
                !is_string($syncBackPayload['sync_url'] ?? null) ||
                !is_string($syncBackPayload['sync_method'] ?? null) ||
                !is_string($syncBackPayload['sync_token'] ?? null) ||
                !is_string($syncBackPayload['csrf_token'] ?? null)
            ) {
                $syncBackJsonInputError = 'Yêu cầu đồng bộ phải chứa một đối tượng JSON hợp lệ.';
            } else {
                $_POST = $syncBackPayload;
            }
        }
    } catch (JsonException) {
        $syncBackJsonInputError = 'Nội dung JSON gửi lên không hợp lệ.';
    } catch (Throwable $exception) {
        error_log('TMS standalone sync-back request parsing failed: ' . $exception->getMessage());
        $syncBackJsonInputError = 'Không thể đọc nội dung yêu cầu đồng bộ.';
        $syncBackJsonInputStatus = 500;
    }
}
$syncBackWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    && ($syncBackJsonRequest || ($_POST['action'] ?? '') === 'sync_back');
$syncBackJsonResponseSent = false;
$sendSyncBackJsonFailure = static function (
    string $message,
    int $statusCode = 500
) use (&$syncBackJsonResponseSent): void {
    $syncBackJsonResponseSent = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        [
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $statusCode >= 500 ? 'PHP Error: Lỗi hệ thống khi xử lý yêu cầu đồng bộ.' : $message,
            'detail' => null,
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};
if ($syncBackWantsJson) {
    ini_set('display_errors', '0');
    if (!$syncBackJsonBufferStarted) {
        ob_start();
    }
    set_exception_handler(static function (Throwable $exception) use ($sendSyncBackJsonFailure): void {
        error_log('TMS standalone sync-back request failed: ' . $exception->getMessage());
        $sendSyncBackJsonFailure('Lỗi PHP khi xử lý yêu cầu Sync Back. Vui lòng thử lại sau.', 500);
    });
    register_shutdown_function(static function () use ($sendSyncBackJsonFailure, &$syncBackJsonResponseSent): void {
        if ($syncBackJsonResponseSent) {
            return;
        }
        $lastError = error_get_last();
        if ($lastError !== null && in_array($lastError['type'], [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true)) {
            error_log('TMS standalone sync-back fatal error: ' . $lastError['message']);
            $sendSyncBackJsonFailure('Lỗi PHP khi xử lý yêu cầu Sync Back. Vui lòng thử lại sau.', 500);
        }
    });
}
if ($syncBackWantsJson && $syncBackJsonInputError !== null) {
    $sendSyncBackJsonFailure($syncBackJsonInputError, $syncBackJsonInputStatus);
}

if (!isset($_SESSION['user_id'])) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translate.php'));
}

$conn = db();
$auth = new AuthManager($conn);
if (!$auth->isLoggedIn()) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translate.php'));
}
$currentUser = $auth->getCurrentUser();
if ($currentUser === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translate.php'));
}
$csrfToken = Csrf::token();
$notificationManager = new NotificationManager($conn);
$notifications = $notificationManager->recentForUser((int)$currentUser['id']);
$unreadNotificationCount = $notificationManager->unreadCount((int)$currentUser['id']);
$logger = new AuditLogger($conn);
$message = '';
$error = '';
$conflictDetails = null;
$syncRemoteErrorDetail = null;
$syncRemoteStatusCode = null;
$syncRemoteContentType = null;
$syncRemoteResponseTruncated = false;
$syncRemoteSuccessResponse = null;
$syncRemoteRequestCompleted = false;
$syncHistoryRecorded = false;
$remoteContent = new RemoteContentService();

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : null;
if ($versionId === null) {
    $versionRes = queryParams(
        $conn,
        "SELECT DISTINCT source_version_id FROM strings WHERE source_version_id IS NOT NULL ORDER BY source_version_id DESC LIMIT 1",
        []
    );
    $versionRow = pg_fetch_assoc($versionRes);

    if ($versionRow) {
        $versionId = (int)$versionRow['source_version_id'];
    } else {
        $versionRes = queryParams($conn, "SELECT id FROM source_versions ORDER BY id DESC LIMIT 1", []);
        $versionRow = pg_fetch_assoc($versionRes);
        $versionId = $versionRow ? (int)$versionRow['id'] : 1;
    }
}
$locale = $_GET['locale'] ?? 'vi';
$currentVersionRes = queryParams(
    $conn,
    'SELECT id, project_id, frozen_at, version_number FROM source_versions WHERE id = $1',
    [$versionId]
);
$currentVersion = pg_fetch_assoc($currentVersionRes);
$projectId = $currentVersion ? (int)$currentVersion['project_id'] : 1;
$currentProjectRole = $currentVersion
    ? ProjectAccess::roleFor($conn, $projectId, (int)$currentUser['id'])
    : null;
if (!$currentVersion || $currentProjectRole === null) {
    http_response_code(404);
    exit('Không tìm thấy phiên bản hoặc bạn không có quyền truy cập dự án.');
}
$isVersionFrozen = $currentVersion['frozen_at'] !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($syncBackWantsJson && !Csrf::isValid($_POST)) {
        $sendSyncBackJsonFailure('Yêu cầu không hợp lệ hoặc phiên đã hết hạn.', 403);
    }
    if (!$syncBackWantsJson) {
        Csrf::verify($_POST);
    }
    $action = (string)$_POST['action'];
    if ($action === 'sync_back') {
        if (!ProjectAccess::canReview($conn, $projectId, $currentUser)) {
            http_response_code(403);
            $error = 'Chỉ Kiểm duyệt viên hoặc Trưởng nhóm mới được đồng bộ bản dịch đã duyệt.';
        } else {
            $syncUrl = trim((string)($_POST['sync_url'] ?? ''));
            $syncMethod = strtoupper((string)($_POST['sync_method'] ?? ''));
            $bearerToken = trim((string)($_POST['sync_token'] ?? ''));
            try {
                RemoteContentService::validatePublicUrl($syncUrl);
                if (!in_array($syncMethod, ['POST', 'PUT'], true)) {
                    throw new InvalidArgumentException('REMOTE_METHOD_INVALID');
                }
                $syncMethod = RemoteContentService::syncMethodForEndpoint($syncUrl, $syncMethod);
                $approvedJson = RemoteContentService::approvedPackage($conn, $versionId, (string)$locale);
                $approvedCountRes = queryParams(
                    $conn,
                    "SELECT COUNT(*)::INT
                     FROM strings s
                     JOIN translations t ON t.string_id = s.id
                     WHERE s.source_version_id = $1
                       AND t.locale = $2
                       AND t.status = 'approved'",
                    [$versionId, (string)$locale]
                );
                $approvedCount = (int)pg_fetch_result($approvedCountRes, 0, 0);
                $historyEndpoint = RemoteContentService::endpointForHistory($syncUrl);
                $targetRollbackData = RemoteContentService::currentSyncData(
                    $conn,
                    $projectId,
                    $historyEndpoint
                );
                if ($targetRollbackData === null) {
                    $targetRollbackData = $remoteContent->fetchJsonSnapshot(
                        $syncUrl,
                        $bearerToken !== '' ? $bearerToken : null
                    );
                }
                $newSyncData = RemoteContentService::prepareSyncPayload($syncUrl, $approvedJson);
                $syncRemoteSuccessResponse = $remoteContent->fetch(
                    $syncUrl,
                    $syncMethod,
                    $newSyncData,
                    $bearerToken,
                    $syncRemoteStatusCode
                );
                $syncRemoteRequestCompleted = true;
                queryParams(
                    $conn,
                    "INSERT INTO release_sync_history
                        (project_id, source_version_id, type, version_number, locale, endpoint_url,
                         record_count, actor_user_id, new_data, target_rollback_data, requires_auth)
                     VALUES ($1, $2, 'api_sync', $3, $4, $5, $6, $7, $8::JSONB, $9::JSONB, $10)",
                    [
                        $projectId,
                        $versionId,
                        (string)$currentVersion['version_number'],
                        (string)$locale,
                        $historyEndpoint,
                        $approvedCount,
                        (int)$currentUser['id'],
                        $newSyncData,
                        $targetRollbackData,
                        $bearerToken !== '',
                    ]
                );
                $syncHistoryRecorded = true;
                $logger->log(
                    $projectId,
                    null,
                    'SYNC_APPROVED_TRANSLATIONS',
                    "Đồng bộ bản dịch đã duyệt ($locale) bằng $syncMethod",
                    (int)$currentUser['id']
                );
                $message = 'Đã gửi bản dịch đã duyệt tới endpoint đích thành công.';
            } catch (Throwable $exception) {
                error_log('TMS translation sync failed: ' . $exception->getMessage());
                if (!$syncRemoteRequestCompleted) {
                    $logger->log(
                        $projectId,
                        null,
                        'SYNC_APPROVED_TRANSLATIONS_FAILED',
                        "Đồng bộ bản dịch đã duyệt ($locale) thất bại",
                        (int)$currentUser['id']
                    );
                } else {
                    $error = $syncHistoryRecorded
                        ? 'Endpoint đã nhận dữ liệu và lịch sử đồng bộ đã được lưu, nhưng không thể ghi nhật ký kiểm toán.'
                        : 'Endpoint đã nhận dữ liệu, nhưng TMS không thể lưu lịch sử đồng bộ. Vui lòng kiểm tra lịch sử trước khi thử lại.';
                    http_response_code(500);
                }
                $exceptionMessage = $exception->getMessage();
                if ($exception instanceof RemoteResponseException) {
                    $syncRemoteErrorDetail = $exception->responseBody;
                    $syncRemoteStatusCode = $exception->status;
                    $syncRemoteContentType = $exception->contentType;
                    $syncRemoteResponseTruncated = $exception->responseBodyTruncated;
                    http_response_code($exception instanceof RemoteHttpException ? $exception->status : 502);
                } elseif (
                    str_starts_with($exception->getMessage(), 'REMOTE_REQUEST_FAILED')
                    || in_array($exception->getMessage(), [
                        'REMOTE_REQUEST_INIT_FAILED',
                        'REMOTE_REQUEST_CONFIGURATION_FAILED',
                        'CURL_EXTENSION_MISSING',
                    ], true)
                ) {
                    $syncRemoteErrorDetail = $exception->getMessage();
                    http_response_code(502);
                } elseif (
                    $syncBackWantsJson &&
                    !$syncRemoteRequestCompleted &&
                    !($exception instanceof InvalidArgumentException) &&
                    !str_starts_with($exceptionMessage, 'REMOTE_') &&
                    !str_starts_with($exceptionMessage, 'SYNC_')
                ) {
                    http_response_code(500);
                    $error = 'Lỗi PHP khi xử lý yêu cầu Sync Back. Vui lòng thử lại sau.';
                }
                if ($error === '') {
                $error = match ($exception->getMessage()) {
                    'REMOTE_URL_INVALID' => 'URL đích không hợp lệ. Chỉ hỗ trợ URL HTTP/HTTPS công khai.',
                    'REMOTE_HOST_NOT_PUBLIC' => 'Máy chủ đích không có địa chỉ IP công khai.',
                    'REMOTE_REQUEST_FAILED' => 'Không thể kết nối tới endpoint đích.',
                    'REMOTE_REQUEST_INIT_FAILED', 'REMOTE_REQUEST_CONFIGURATION_FAILED' => 'Không thể khởi tạo yêu cầu tới máy chủ đích.',
                    'CURL_EXTENSION_MISSING' => 'Máy chủ PHP chưa bật tiện ích cURL.',
                    'SYNC_NO_APPROVED_TRANSLATIONS' => 'Chưa có bản dịch nào được duyệt để đồng bộ.',
                    'REMOTE_METHOD_INVALID' => 'Phương thức đồng bộ chỉ được là POST hoặc PUT.',
                    'REMOTE_JSON_BODY_INVALID' => 'Dữ liệu bản dịch tạo ra không phải JSON hợp lệ.',
                    'REMOTE_HTTPS_REQUIRED' => 'Endpoint đồng bộ phải sử dụng HTTPS để bảo vệ nội dung và token.',
                    'REMOTE_TOKEN_INVALID' => 'Bearer token chứa ký tự không hợp lệ.',
                    'REMOTE_RESPONSE_NOT_JSON' => 'Máy chủ đích không trả về phản hồi JSON.',
                    'REMOTE_RESPONSE_INVALID_JSON' => 'Máy chủ đích khai báo JSON nhưng nội dung phản hồi không hợp lệ.',
                    'REMOTE_RESPONSE_EMPTY' => 'Máy chủ đích trả về phản hồi rỗng (chỉ chấp nhận phản hồi rỗng với HTTP 204).',
                    default => str_starts_with($exception->getMessage(), 'REMOTE_HTTP_STATUS_')
                        ? 'Máy chủ đích trả về lỗi HTTP ' . substr($exception->getMessage(), strlen('REMOTE_HTTP_STATUS_')) . '.'
                        : (str_starts_with($exception->getMessage(), 'REMOTE_REQUEST_FAILED')
                            ? 'Không thể kết nối tới endpoint đích: ' . substr($exception->getMessage(), strlen('REMOTE_REQUEST_FAILED: '))
                            : (str_starts_with($exception->getMessage(), 'REMOTE_RESPONSE_TOO_LARGE_HTTP_STATUS_')
                                ? 'Phản hồi lỗi từ máy chủ đích vượt quá giới hạn cho phép.'
                                : (str_starts_with($exception->getMessage(), 'REMOTE_EMPTY_RESPONSE_HTTP_STATUS_')
                                    ? 'Máy chủ đích trả về phản hồi rỗng.'
                                    : 'Không thể đồng bộ bản dịch. Vui lòng kiểm tra endpoint và cấu hình xác thực.'))),
                };
                }
            }
        }
    }
    if ($syncBackWantsJson) {
        $syncBackJsonResponseSent = true;
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $syncSucceeded = $error === '';
        if (!$syncSucceeded && http_response_code() === 200) {
            http_response_code(422);
        }
        echo json_encode(
            [
                'success' => $syncSucceeded,
                'message' => $syncSucceeded
                    ? $message
                    : ($error !== '' ? $error : 'Không thể đồng bộ bản dịch.'),
                'detail' => $syncRemoteErrorDetail,
                'remote_status' => $syncRemoteStatusCode,
                'remote_content_type' => $syncRemoteContentType,
                'remote_response_truncated' => $syncRemoteResponseTruncated,
                'remote_response' => $syncRemoteSuccessResponse,
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}

// Xử lý lưu bản dịch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['string_id'])) {
    Csrf::verify($_POST);
    $stringId = (int)($_POST['string_id'] ?? 0);
    $translatedText = trim((string)($_POST['translated_text'] ?? ''));
    $baseVersion = $_POST['base_version'] ?? null;

    $sourceRes = queryParams(
        $conn,
        'SELECT s.project_id, s.key_path, s.source_text
         FROM strings s
         WHERE s.id = $1 AND s.source_version_id = $2',
        [$stringId, $versionId]
    );
    $sourceRow = pg_fetch_assoc($sourceRes);

    if ($isVersionFrozen) {
        http_response_code(409);
        $error = 'Phiên bản đã chốt và chỉ có thể xem.';
    } elseif (!$sourceRow) {
        $error = 'Không tìm thấy chuỗi nguồn để lưu bản dịch.';
    } elseif (!is_string($baseVersion) || strlen($baseVersion) > 40) {
        http_response_code(400);
        $error = 'Thiếu phiên bản nền của bản dịch. Vui lòng tải lại trang.';
    } elseif (!ProjectAccess::canTranslate($conn, (int)$sourceRow['project_id'], $currentUser)) {
        http_response_code(403);
        $error = 'Bạn chưa là thành viên của dự án này.';
    } elseif (!ProjectAccess::canEditTranslation(
        $conn,
        (int)$sourceRow['project_id'],
        (int)$currentUser['id']
    )) {
        http_response_code(403);
        $error = 'Bạn không có quyền chỉnh sửa bản dịch trong dự án này.';
    } elseif (!VariableValidator::validate($sourceRow['source_text'], $translatedText)) {
        $error = "Lỗi: Bản dịch chứa sai hoặc thiếu biến thay thế (placeholder) so với bản gốc!";
    }
    if ($sourceRow && $error === '') {
        if (pg_query($conn, 'BEGIN') === false) {
            throw new RuntimeException('TRANSLATION_SAVE_TRANSACTION_FAILED');
        }
        try {
            queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
            queryParams($conn, 'SELECT pg_advisory_xact_lock($1, hashtext($2))', [$stringId, (string)$locale]);
            $priorResult = queryParams(
                $conn,
                'SELECT t.id, t.translated_text, t.status,
                        to_char(t.updated_at AT TIME ZONE \'UTC\',
                                \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                        u.username AS updated_by
                 FROM translations t
                 LEFT JOIN users u ON u.id = t.user_id
                 WHERE t.string_id = $1 AND t.locale = $2 FOR UPDATE OF t',
                [$stringId, $locale]
            );
            $priorTranslation = pg_fetch_assoc($priorResult);
            $expectedVersion = $baseVersion === '' ? null : $baseVersion;
            if (($priorTranslation['updated_at'] ?? null) !== $expectedVersion) {
                pg_query($conn, 'ROLLBACK');
                http_response_code(409);
                $error = 'Bản dịch này đã được cập nhật. Vui lòng xem phiên bản hiện tại trước khi lưu.';
                $conflictDetails = [
                    'updatedBy' => $priorTranslation['updated_by'] ?? null,
                    'updatedAt' => $priorTranslation['updated_at'] ?? null,
                    'currentText' => $priorTranslation['translated_text'] ?? null,
                    'currentStatus' => $priorTranslation['status'] ?? null,
                    'keyPath' => $sourceRow['key_path'],
                    'submittedText' => $translatedText,
                ];
            } else {
                if ($priorTranslation) {
                    $saveRes = queryParams(
                        $conn,
                        "UPDATE translations
                         SET translated_text = $1, status = 'draft',
                             updated_at = clock_timestamp(), user_id = $2
                         WHERE id = $3 RETURNING id",
                        [$translatedText, (int)$currentUser['id'], (int)$priorTranslation['id']]
                    );
                } else {
                    $saveRes = queryParams(
                        $conn,
                        "INSERT INTO translations
                            (string_id, locale, translated_text, status, updated_at, user_id)
                         VALUES ($1, $2, $3, 'draft', clock_timestamp(), $4)
                         ON CONFLICT (string_id, locale) DO NOTHING
                         RETURNING id",
                        [$stringId, $locale, $translatedText, (int)$currentUser['id']]
                    );
                }
                if (!pg_fetch_assoc($saveRes)) {
                    $currentResult = queryParams(
                        $conn,
                        'SELECT t.translated_text, t.status,
                                to_char(t.updated_at AT TIME ZONE \'UTC\',
                                        \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                                u.username AS updated_by
                         FROM translations t
                         LEFT JOIN users u ON u.id = t.user_id
                         WHERE t.string_id = $1 AND t.locale = $2 FOR UPDATE OF t',
                        [$stringId, $locale]
                    );
                    $currentTranslation = pg_fetch_assoc($currentResult);
                    pg_query($conn, 'ROLLBACK');
                    http_response_code(409);
                    $error = 'Bản dịch này đã được cập nhật. Vui lòng xem phiên bản hiện tại trước khi lưu.';
                    $conflictDetails = [
                        'updatedBy' => $currentTranslation['updated_by'] ?? null,
                        'updatedAt' => $currentTranslation['updated_at'] ?? null,
                        'currentText' => $currentTranslation['translated_text'] ?? null,
                        'currentStatus' => $currentTranslation['status'] ?? null,
                        'keyPath' => $sourceRow['key_path'],
                        'submittedText' => $translatedText,
                    ];
                } elseif (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('TRANSLATION_SAVE_COMMIT_FAILED');
                }
            }
        } catch (Throwable $e) {
            if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($conn, 'ROLLBACK');
            }
            throw $e;
        }
        if ($conflictDetails === null && isset($saveRes) && pg_num_rows($saveRes) > 0) {
            $logger->log((int)$sourceRow['project_id'], $stringId, 'UPDATE_TRANSLATION', "Cập nhật bản dịch cho khóa ID $stringId ($locale)");
            $message = "Đã lưu bản dịch thành công!";
        }
    }
}

// Lấy danh sách chuỗi và bản dịch hiện tại
$sql = "SELECT s.id as string_id, s.key_path, s.source_text, t.translated_text, t.status,
               COALESCE(
                   to_char(t.updated_at AT TIME ZONE 'UTC',
                           'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),
                   ''
               ) AS translation_updated_at
        FROM strings s 
        LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
        WHERE s.source_version_id = $1";
$res = queryParams($conn, $sql, [$versionId, $locale]);
$strings = [];
while ($row = pg_fetch_assoc($res)) {
    $strings[] = $row;
}
$previewJson = json_encode(
    array_map(
        static fn(array $item): array => [
            'key' => $item['key_path'],
            'source' => $item['source_text'],
            'draft' => $item['translated_text'] ?? '',
            'status' => $item['status'] ?? 'draft',
            'stringId' => (int)$item['string_id'],
        ],
        $strings
    ),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="translation.title">Dịch thuật - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
    <script src="tms-sync-back.js" defer></script>
</head>
<body class="bg-gray-50 min-h-screen p-6">
    <div class="tms-shell max-w-6xl mx-auto bg-white p-6 rounded-xl shadow">
        <div class="tms-topbar flex flex-col sm:flex-row justify-between items-center mb-6 p-4 gap-4">
            <h1 class="text-2xl font-bold text-indigo-600">✍️ <span data-i18n="translation.page_heading">Giao diện Dịch thuật &amp; Kiểm tra Biến</span></h1>
            <div class="flex items-center gap-3 text-sm">
                <?php require __DIR__ . '/templates/tms-header.php'; ?>
                <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600" data-i18n="translation.back_home">Quay lại Trang chủ</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($conflictDetails !== null): ?>
            <dialog class="tms-modal tms-conflict-modal backdrop:backdrop-blur-md backdrop:bg-black/40" id="translation-conflict-dialog" aria-labelledby="translation-conflict-title" style="width:min(42rem, calc(100vw - 2rem))">
                <section class="tms-modal-card tms-conflict-card rounded-2xl border border-slate-100 p-6 shadow-2xl sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-amber-700">409 Conflict</p>
                            <h2 id="translation-conflict-title" class="mt-1 text-xl font-bold text-slate-900"><span aria-hidden="true">⚠️</span> Cảnh báo xung đột</h2>
                        </div>
                        <button type="button" class="tms-modal-close border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-100" aria-label="Đóng" onclick="document.getElementById('translation-conflict-dialog').close()">×</button>
                    </div>
                    <p class="mt-3 text-sm text-slate-700">Bản dịch này đã được cập nhật bởi người dùng khác từ phiên bản sau. Vui lòng tải lại dữ liệu mới nhất để xem thay đổi.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                        <code class="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 font-mono font-semibold text-indigo-800"><?= htmlspecialchars((string)$conflictDetails['keyPath'], ENT_QUOTES, 'UTF-8') ?></code>
                        <span class="inline-flex items-center gap-1 text-slate-600">👤 <?= htmlspecialchars((string)($conflictDetails['updatedBy'] ?? 'Không xác định'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="inline-flex items-center gap-1 text-slate-600">🕒 <?= htmlspecialchars((string)($conflictDetails['updatedAt'] ?? 'Không xác định'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-slate-600">Trạng thái: <?= htmlspecialchars((string)($conflictDetails['currentStatus'] ?? 'chưa có'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <section class="rounded-xl border border-amber-200 bg-amber-50/60 p-3 text-amber-900">
                            <h3 class="text-xs font-semibold uppercase tracking-wide">Bản hiện tại trong cơ sở dữ liệu</h3>
                            <pre class="mt-2 min-h-20 whitespace-pre-wrap break-words rounded-lg border border-amber-100 bg-white/80 p-3 text-sm text-amber-950"><?= htmlspecialchars((string)($conflictDetails['currentText'] ?? '(bản dịch trống hoặc đã bị xóa)'), ENT_QUOTES, 'UTF-8') ?></pre>
                        </section>
                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-slate-800">
                            <h3 class="text-xs font-semibold uppercase tracking-wide">Nội dung bạn định lưu</h3>
                            <pre class="mt-2 min-h-20 whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-800"><?= htmlspecialchars((string)$conflictDetails['submittedText'], ENT_QUOTES, 'UTF-8') ?></pre>
                        </section>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400" onclick="document.getElementById('translation-conflict-dialog').close()">Đóng</button>
                        <button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:scale-[1.02] hover:bg-indigo-700 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" onclick="window.location.reload()">Tải lại dữ liệu mới nhất</button>
                    </div>
                </section>
            </dialog>
            <script>document.getElementById('translation-conflict-dialog').showModal();</script>
        <?php endif; ?>

        <section class="grid gap-5 lg:grid-cols-2 mb-6" aria-label="Đồng bộ bản dịch" data-i18n-aria-label="translation.sync">
            <?php if (ProjectAccess::canReview($conn, $projectId, $currentUser)): ?>
            <form method="POST" class="border rounded-lg p-4 bg-slate-50 space-y-3" data-sync-back-form novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="sync_back">
                <h2 class="font-semibold text-lg" data-i18n="translation.sync_heading">Đồng bộ bản dịch đã duyệt</h2>
                <label class="block text-sm" for="sync-url" data-i18n="translation.sync_endpoint">Endpoint đích nhận JSON</label>
                <input id="sync-url" name="sync_url" type="url" pattern="https?://.+" required maxlength="2048" placeholder="https://example.com/api/translations" class="w-full border rounded p-2">
                <div class="flex flex-wrap gap-3 items-center">
                    <label class="text-sm" for="sync-method" data-i18n="translation.method">Phương thức</label>
                    <select id="sync-method" name="sync_method" class="border rounded p-2">
                        <option value="POST">POST</option>
                        <option value="PUT" selected>PUT</option>
                    </select>
                    <input name="sync_token" type="password" autocomplete="off" placeholder="Bearer token (nếu cần)" class="border rounded p-2 flex-1 min-w-48">
                    <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded hover:bg-emerald-700" data-i18n="translation.send_approved">Gửi bản đã duyệt</button>
                </div>
                <p class="text-xs text-slate-600" data-i18n="translation.sync_note">Chỉ gửi các khóa có trạng thái Đã duyệt của phiên bản và ngôn ngữ hiện tại. Endpoint phải dùng HTTPS; token không được lưu vào CSDL.</p>
            </form>
            <?php endif; ?>
        </section>

        <section class="border rounded-lg p-4 mb-6" aria-labelledby="preview-title">
            <div class="flex flex-wrap justify-between gap-3 items-center mb-3">
                <h2 id="preview-title" class="font-semibold text-lg">👁️ <span data-i18n="translation.preview">Xem trước giao diện (Live Preview)</span></h2>
                <div class="inline-flex gap-2" role="group" aria-label="Chế độ xem trước">
                    <button id="preview-draft" type="button" aria-pressed="true" class="px-3 py-1 rounded bg-indigo-600 text-white">👁️ <span data-i18n="translation.draft_preview">Xem bản nháp (Draft Preview)</span></button>
                    <button id="preview-approved" type="button" aria-pressed="false" class="px-3 py-1 rounded border">🚀 <span data-i18n="translation.approved_preview">Xem bản đã duyệt (Approved Preview)</span></button>
                </div>
            </div>
            <div class="overflow-hidden rounded-lg border bg-slate-100 p-2 sm:p-4">
                <iframe id="translation-preview" title="Mẫu giao diện trang web đã dịch" data-i18n-title="translation.no_preview" sandbox="allow-scripts" class="w-full h-[28rem] rounded-lg border bg-white shadow-sm"></iframe>
            </div>
            <p class="text-xs text-slate-600 mt-2" data-i18n="translation.preview_note">Bản nháp cập nhật ngay khi nhập. Bản đã duyệt chỉ hiển thị bản dịch đã lưu ở trạng thái Đã duyệt; các chuỗi chưa duyệt giữ nguyên văn bản nguồn.</p>
        </section>

        <div class="tms-table-wrap">
        <table class="w-full border-collapse border border-gray-200">
            <thead>
                <tr class="bg-gray-100 text-left">
                    <th class="p-3 border" data-i18n="translation.key">Khóa (Key Path)</th>
                    <th class="p-3 border" data-i18n="translation.source_text">Văn bản gốc (Source)</th>
                    <th class="p-3 border"><span data-i18n="translation.header_target">Bản dịch</span> (<?= htmlspecialchars($locale) ?>)</th>
                    <th class="p-3 border" data-i18n="translation.header_status">Trạng thái</th>
                    <th class="p-3 border" data-i18n="translation.header_action">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($strings as $item): ?>
                <?php $canEditItem = ProjectAccess::canEditTranslationForRole($currentProjectRole);
                $translationFormId = 'translation-form-' . (int)$item['string_id'];
                $itemStatus = $item['status'] ?? 'draft';
                $statusLabels = [
                    'draft' => 'Bản nháp',
                    'approved' => 'Đã duyệt',
                    'rejected' => 'Từ chối',
                    'needs_review' => 'Cần kiểm tra',
                ]; ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="p-3 border font-mono text-sm text-indigo-700">
                        <form id="<?= $translationFormId ?>" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="string_id" value="<?= (int)$item['string_id'] ?>">
                            <input type="hidden" name="base_version" value="<?= htmlspecialchars($item['translation_updated_at'], ENT_QUOTES, 'UTF-8') ?>">
                        </form>
                        <?= htmlspecialchars($item['key_path']) ?>
                    </td>
                    <td class="p-3 border text-sm"><?= htmlspecialchars($item['source_text']) ?></td>
                    <td class="p-3 border">
                        <textarea form="<?= $translationFormId ?>" name="translated_text" rows="2" <?= $isVersionFrozen || !$canEditItem ? 'disabled' : '' ?> class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-100"><?= htmlspecialchars($item['translated_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </td>
                    <td class="p-3 border text-sm">
                        <span class="tms-status tms-status-<?= htmlspecialchars($itemStatus, ENT_QUOTES, 'UTF-8') ?> inline-flex px-3 py-1" data-i18n="<?= match ($itemStatus) { 'draft' => 'translation.draft', 'approved' => 'translation.approved', 'rejected' => 'translation.rejected', 'needs_review' => 'translation.needs_review', default => '' } ?>"><?= htmlspecialchars($statusLabels[$itemStatus] ?? $itemStatus, ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                        <td class="p-3 border text-center">
                            <button type="submit" form="<?= $translationFormId ?>" <?= $isVersionFrozen || !$canEditItem ? 'disabled' : '' ?> class="bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700 disabled:opacity-50 text-sm font-bold" data-i18n="translation.save_draft">Lưu nháp</button>
                        </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <script id="translation-preview-data" type="application/json"><?= $previewJson ?></script>
    <script>
        (() => {
            const rows = JSON.parse(document.getElementById('translation-preview-data').textContent);
            const frame = document.getElementById('translation-preview');
            const draftButton = document.getElementById('preview-draft');
            const approvedButton = document.getElementById('preview-approved');
            let mode = 'draft';
            const previewDocument = `<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>
                *{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#172033;font:14px system-ui,sans-serif}
                .bar{height:56px;padding:0 6%;display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e2e8f0}
                .brand{font-weight:800;font-size:18px;color:#4338ca}.nav{display:flex;gap:20px;color:#475569}
                .hero{margin:24px auto;max-width:920px;padding:32px 24px;text-align:center;border-radius:18px;
                background:linear-gradient(135deg,#eef2ff,#f5f3ff)}.eyebrow{font-size:11px;color:#6366f1;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
                h1{font-size:clamp(24px,5vw,40px);line-height:1.15;margin:12px auto;max-width:700px}
                .intro{color:#64748b;max-width:620px;margin:0 auto 20px;line-height:1.6}
                .cta{display:inline-block;background:#4f46e5;color:white;padding:10px 18px;border-radius:9px;font-weight:700}
                .section{max-width:920px;margin:26px auto;padding:0 20px}.section-title{font-size:18px;font-weight:700;margin-bottom:12px}
                .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
                .card{min-height:96px;padding:16px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 2px 6px #0f172a08}
                .card p{margin:8px 0 0;line-height:1.5;overflow-wrap:anywhere}.key{font:10px ui-monospace,monospace;color:#6366f1;overflow-wrap:anywhere}
                .empty{color:#64748b;text-align:center;padding:24px}
            </style></head><body><header class="bar"><div class="brand">Preview App</div><nav id="nav" class="nav"></nav></header>
                <main id="content"><section class="hero"><div class="eyebrow">Live interface preview</div><h1></h1><p class="intro"></p><span class="cta"></span></section>
                <section class="section"><h2 class="section-title"></h2><div class="grid"></div></section></main><script>
                addEventListener('message',event=>{if(event.source!==parent||event.data?.type!=='tms-preview')return;
                const rows=event.data.rows;const nav=document.getElementById('nav');nav.replaceChildren();
                const navRows=rows.filter(row=>/(^|\\.)(menu|nav)(\\.|$)/i.test(row.key));
                navRows.slice(0,4).forEach(row=>{const link=document.createElement('span');link.textContent=row.text;link.title=row.key;nav.append(link)});
                const pageRows=rows.filter(row=>!navRows.includes(row));
                const hero=document.querySelector('.hero');const title=hero.querySelector('h1');const intro=hero.querySelector('.intro');const cta=hero.querySelector('.cta');
                title.textContent=pageRows[0]?.text||'';title.title=pageRows[0]?.key||'';
                intro.textContent=pageRows[1]?.text||'';intro.title=pageRows[1]?.key||'';
                cta.textContent=pageRows[2]?.text||'';cta.title=pageRows[2]?.key||'';
                const section=document.querySelector('.section');const sectionTitle=section.querySelector('.section-title');
                sectionTitle.textContent=pageRows[3]?.text||'';sectionTitle.title=pageRows[3]?.key||'';
                const grid=section.querySelector('.grid');grid.replaceChildren();
                pageRows.slice(4).forEach(row=>{const card=document.createElement('article');card.className='card';
                const key=document.createElement('div');key.className='key';key.textContent=row.key;
                const text=document.createElement('p');text.textContent=row.text;card.append(key,text);grid.append(card)});
                if(pageRows.length===0){hero.hidden=true;sectionTitle.textContent='';grid.innerHTML='<p class="empty">Chưa có nội dung để xem trước.</p>'}
                else{hero.hidden=false}});
            <\/script></body></html>`;
            frame.srcdoc = previewDocument;

            const currentRows = () => rows.map(row => {
                if (mode === 'approved') {
                    return { ...row, text: row.status === 'approved' && row.draft !== '' ? row.draft : row.source };
                }
                const editor = document.querySelector(`textarea[form="translation-form-${row.stringId}"]`);
                return { ...row, text: editor ? editor.value : (row.draft || row.source) };
            });
            const render = () => {
                if (frame.contentWindow) {
                    frame.contentWindow.postMessage({ type: 'tms-preview', rows: currentRows() }, '*');
                }
                draftButton.setAttribute('aria-pressed', String(mode === 'draft'));
                approvedButton.setAttribute('aria-pressed', String(mode === 'approved'));
                draftButton.classList.toggle('bg-indigo-600', mode === 'draft');
                draftButton.classList.toggle('text-white', mode === 'draft');
                approvedButton.classList.toggle('bg-indigo-600', mode === 'approved');
                approvedButton.classList.toggle('text-white', mode === 'approved');
            };
            draftButton.addEventListener('click', () => { mode = 'draft'; render(); });
            approvedButton.addEventListener('click', () => { mode = 'approved'; render(); });
            document.querySelectorAll('textarea[name="translated_text"]').forEach(editor => {
                editor.addEventListener('input', render);
            });
            frame.addEventListener('load', render);
            render();
        })();
    </script>
</body>
</html>