<?php
declare(strict_types=1);

$importWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'import_source'
    && (
        (string)($_POST['response_format'] ?? '') === 'json' ||
        str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    );
$saveTranslationWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'save_translation';
$translationLockWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array(($_GET['action'] ?? ''), ['lock_translation', 'unlock_translation'], true);
$suggestionWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && ($_GET['action'] ?? '') === 'suggest_translation';
$workspaceHtmlRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && !isset($_GET['action']);
if ($workspaceHtmlRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
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
        error_log('TMS sync-back request parsing failed: ' . $exception->getMessage());
        $syncBackJsonInputError = 'Không thể đọc nội dung yêu cầu đồng bộ.';
        $syncBackJsonInputStatus = 500;
    }
}
$syncBackWantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    && ($syncBackJsonRequest || ($_POST['action'] ?? '') === 'sync_back');
$respondImportJsonFailure = static function (string $message, int $statusCode = 500): void {
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
$respondSyncBackJsonFailure = static function (): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        [
            'success' => false,
            'status' => 500,
            'message' => 'Lỗi PHP khi xử lý yêu cầu Sync Back. Vui lòng thử lại sau.',
            'error' => 'PHP Error: Lỗi hệ thống khi xử lý yêu cầu đồng bộ.',
            'detail' => null,
        ],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};
$importJsonResponseSent = false;
if ($importWantsJson || $saveTranslationWantsJson || $translationLockWantsJson || $suggestionWantsJson || $syncBackWantsJson) {
    ini_set('display_errors', '0');
    if (!$syncBackJsonBufferStarted) {
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
    set_exception_handler(static function (Throwable $exception) use (
        $respondImportJsonFailure,
        $respondSyncBackJsonFailure,
        &$importJsonResponseSent,
        $saveTranslationWantsJson,
        $translationLockWantsJson,
        $suggestionWantsJson,
        $syncBackWantsJson
    ): void {
        error_log('JSON_REQUEST_UNHANDLED: ' . $exception->getMessage());
        $importJsonResponseSent = true;
        if ($syncBackWantsJson) {
            $respondSyncBackJsonFailure();
        }
        $respondImportJsonFailure(
            $saveTranslationWantsJson
                ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.'
                : ($translationLockWantsJson
                    ? 'Không thể cập nhật trạng thái khóa.'
                    : ($suggestionWantsJson
                        ? 'Không thể tạo gợi ý lúc này.'
                        : ($syncBackWantsJson ? 'Không thể xử lý yêu cầu đồng bộ.' : 'Không thể xử lý yêu cầu import.')))
        );
    });
    register_shutdown_function(static function () use (
        $respondImportJsonFailure,
        $respondSyncBackJsonFailure,
        &$importJsonResponseSent,
        $saveTranslationWantsJson,
        $translationLockWantsJson,
        $suggestionWantsJson,
        $syncBackWantsJson
    ): void {
        if ($importJsonResponseSent) {
            return;
        }
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
        error_log('JSON_REQUEST_FATAL: ' . $error['message']);
        $importJsonResponseSent = true;
        if ($syncBackWantsJson) {
            $respondSyncBackJsonFailure();
        }
        $respondImportJsonFailure(
            $saveTranslationWantsJson
                ? 'Lỗi: Không thể lưu bản dịch do lỗi hệ thống.'
                : ($translationLockWantsJson
                    ? 'Không thể cập nhật trạng thái khóa.'
                    : ($suggestionWantsJson
                        ? 'Không thể tạo gợi ý lúc này.'
                        : ($syncBackWantsJson ? 'Không thể xử lý yêu cầu đồng bộ.' : 'Không thể xử lý yêu cầu import.')))
        );
    });
}
if ($syncBackWantsJson && $syncBackJsonInputError !== null) {
    if ($syncBackJsonInputStatus >= 500) {
        $respondSyncBackJsonFailure();
    }
    $respondImportJsonFailure($syncBackJsonInputError, $syncBackJsonInputStatus);
}

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';

if (($_GET['action'] ?? '') === 'logout') {
    AuthManager::destroySession();
    header('Location: index.php?action=login', true, 303);
    exit;
}

if (($_GET['action'] ?? '') === 'login') {
    require __DIR__ . '/login.php';
    exit;
}

require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/JsonParser.php';
require_once __DIR__ . '/src/TranslationStats.php';
require_once __DIR__ . '/src/ReleaseExporter.php';
require_once __DIR__ . '/src/TranslationRepository.php';
require_once __DIR__ . '/src/TranslationMemory.php';
require_once __DIR__ . '/src/TmRepository.php';
require_once __DIR__ . '/src/SuggestionEngine.php';
require_once __DIR__ . '/src/AiTranslator.php';
require_once __DIR__ . '/src/GlossaryManager.php';
require_once __DIR__ . '/src/AuditLogger.php';
require_once __DIR__ . '/src/AuditLogPresenter.php';
require_once __DIR__ . '/src/VariableValidator.php';
require_once __DIR__ . '/src/NotificationManager.php';
require_once __DIR__ . '/src/TranslationPackageExporter.php';
require_once __DIR__ . '/src/RemoteContentService.php';
require_once __DIR__ . '/src/LocaleRepository.php';
require_once __DIR__ . '/src/LocaleRepository.php';
require_once __DIR__ . '/src/LanguageCatalog.php';
require_once __DIR__ . '/src/SourceImportParser.php';
require_once __DIR__ . '/src/MyMemoryTranslationService.php';
require_once __DIR__ . '/src/TranslationProviderService.php';
$viDictionary = require __DIR__ . '/lang/vi.php';

$conn = db();
$auth = new AuthManager($conn);
$localeRepository = new LocaleRepository($conn);
$allLocales = LanguageCatalog::all();

$currentUser = $auth->getCurrentUser();
$isAuthenticated = $currentUser !== null;
$isAdmin = $isAuthenticated && ProjectAccess::isSystemAdmin($currentUser);
if ($isAuthenticated && ($_SESSION['user']['role'] ?? '') === 'admin') {
    header('Location: admin_users.php', true, 303);
    exit;
}
$requestedAction = $_POST['action'] ?? null;
$isLocaleApiAction = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && is_string($requestedAction)
    && in_array($requestedAction, ['activate_project_locale', 'list_project_locales'], true);
$isGlossaryApiAction = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && is_string($requestedAction)
    && in_array($requestedAction, ['glossary_save', 'glossary_delete'], true);
$csrfToken = $isAuthenticated ? Csrf::token() : null;
$projectRoleLabels = [
    'Team Lead' => 'Trưởng nhóm',
    'Reviewer' => 'Kiểm duyệt viên',
    'Translator' => 'Biên dịch viên'
];

if (!$isAuthenticated) {
    if ($isGlossaryApiAction || $isLocaleApiAction) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($importWantsJson || $saveTranslationWantsJson || $translationLockWantsJson || $suggestionWantsJson) {
        $respondImportJsonFailure('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 401);
    }
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/index.php'));
}

if (($_POST['action'] ?? '') === 'delete_project') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Chỉ hỗ trợ yêu cầu POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!Csrf::isValid($_POST)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Yêu cầu không hợp lệ hoặc phiên đã hết hạn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $deleteProjectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
    if ($deleteProjectId === false || $deleteProjectId === null || $deleteProjectId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID dự án không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (pg_query($conn, 'BEGIN') === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không thể bắt đầu giao dịch xóa dự án.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $ownedProjectRes = queryParams(
            $conn,
            'SELECT id FROM projects WHERE id = $1 AND created_by = $2 FOR UPDATE',
            [(int)$deleteProjectId, (int)$currentUser['id']]
        );
        if (!pg_fetch_assoc($ownedProjectRes)) {
            pg_query($conn, 'ROLLBACK');
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy dự án hoặc bạn không phải chủ sở hữu.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $publishedRes = queryParams(
            $conn,
            'SELECT 1 FROM release_snapshots WHERE project_id = $1 LIMIT 1',
            [(int)$deleteProjectId]
        );
        if (pg_fetch_assoc($publishedRes)) {
            pg_query($conn, 'ROLLBACK');
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Không thể xóa dự án đã có gói phát hành. Các gói đã xuất bản được bảo vệ và không thể xóa.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $frozenVersionRes = queryParams(
            $conn,
            'SELECT 1 FROM source_versions
             WHERE project_id = $1 AND frozen_at IS NOT NULL
             LIMIT 1',
            [(int)$deleteProjectId]
        );
        if (pg_fetch_assoc($frozenVersionRes)) {
            pg_query($conn, 'ROLLBACK');
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Không thể xóa dự án có phiên bản đã chốt để bảo toàn dữ liệu bất biến.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        queryParams($conn, 'DELETE FROM glossary WHERE project_id = $1', [(int)$deleteProjectId]);
        queryParams($conn, 'DELETE FROM translation_memory WHERE project_id = $1', [(int)$deleteProjectId]);
        queryParams($conn, 'DELETE FROM audit_logs WHERE project_id = $1', [(int)$deleteProjectId]);
        $deletedProjectRes = queryParams(
            $conn,
            'DELETE FROM projects WHERE id = $1 AND created_by = $2 RETURNING id',
            [(int)$deleteProjectId, (int)$currentUser['id']]
        );
        if (!pg_fetch_assoc($deletedProjectRes)) {
            throw new RuntimeException('PROJECT_DELETE_OWNERSHIP_CHANGED');
        }
        if (pg_query($conn, 'COMMIT') === false) {
            throw new RuntimeException('PROJECT_DELETE_COMMIT_FAILED');
        }

        echo json_encode(['success' => true, 'message' => 'Đã xóa dự án thành công.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
            pg_query($conn, 'ROLLBACK');
        }
        error_log('PROJECT_DELETE_FAILED: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không thể xóa dự án do lỗi cơ sở dữ liệu.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$notificationManager = new NotificationManager($conn);
$notifications = $notificationManager->recentForUser((int)$currentUser['id']);
$unreadNotificationCount = $notificationManager->unreadCount((int)$currentUser['id']);

$invalidLocale = false;
$successMessage = '';
$errorMessage = $invalidLocale ? 'Mã hoặc tên ngôn ngữ đích không hợp lệ.' : '';
$syncRemoteErrorDetail = null;
$syncRemoteStatusCode = null;
$syncRemoteContentType = null;
$syncRemoteResponseTruncated = false;
$syncRemoteSuccessResponse = null;
$syncRemoteRequestCompleted = false;
$syncHistoryRecorded = false;
$translationConflict = null;
if (($_GET['created'] ?? '') === '1') {
    $successMessage = 'Tạo dự án mới thành công. Bạn là Trưởng nhóm của dự án.';
}

try {
    [$currentLocale] = AiTranslator::resolveTargetLanguage((string)($_GET['locale'] ?? $_GET['lang'] ?? 'vi'));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    $currentLocale = 'vi';
    $invalidLocale = true;
}

$currentHistoryLocale = strtoupper($currentLocale);
if (!isset($_SESSION['translated_history']) || !is_array($_SESSION['translated_history'])) {
    $_SESSION['translated_history'] = [$currentHistoryLocale];
} elseif (!in_array($currentHistoryLocale, $_SESSION['translated_history'], true)) {
    $_SESSION['translated_history'][] = $currentHistoryLocale;
}
$translatedHistory = array_values(array_filter(
    $_SESSION['translated_history'],
    static fn ($locale): bool => is_string($locale) && $locale !== ''
));
$_SESSION['translated_history'] = $translatedHistory;

$projects = [];
$projectsRes = queryParams(
    $conn,
    'WITH visible_projects AS (
         SELECT DISTINCT p.id, p.name, p.description, p.created_by, p.created_at
         FROM projects p
         LEFT JOIN project_members pm ON pm.project_id = p.id
         WHERE p.created_by = $1 OR pm.user_id = $1
     ),
     project_keys AS (
         SELECT s.project_id,
                COUNT(DISTINCT s.id) AS key_count,
                COUNT(DISTINCT s.id) FILTER (
                    WHERE t.translated_text IS NOT NULL AND t.translated_text <> \'\'
                ) AS translated_count,
                COUNT(DISTINCT s.id) FILTER (
                    WHERE t.status = \'approved\'
                      AND t.translated_text IS NOT NULL AND t.translated_text <> \'\'
                ) AS approved_count,
                MAX(GREATEST(v.created_at, COALESCE(t.updated_at, v.created_at))) AS last_activity
         FROM strings s
         JOIN source_versions v ON v.id = s.source_version_id
         LEFT JOIN translations t ON t.string_id = s.id
         GROUP BY s.project_id
     ),
     project_languages AS (
         SELECT project_id, COUNT(*) AS locale_count
         FROM project_locales
         GROUP BY project_id
     )
     SELECT vp.id, vp.name, vp.description, vp.created_by, vp.created_at,
            COALESCE(pk.key_count, 0) AS key_count,
            COALESCE(pk.translated_count, 0) AS translated_count,
            COALESCE(pk.approved_count, 0) AS approved_count,
            COALESCE(pl.locale_count, 0) AS locale_count,
            COALESCE(pk.last_activity, vp.created_at) AS last_activity
     FROM visible_projects vp
     LEFT JOIN project_keys pk ON pk.project_id = vp.id
     LEFT JOIN project_languages pl ON pl.project_id = vp.id
     ORDER BY vp.name, vp.id',
    [(int)$currentUser['id']]
);
while ($project = pg_fetch_assoc($projectsRes)) {
    $project['key_count'] = (int)$project['key_count'];
    $project['translated_count'] = (int)$project['translated_count'];
    $project['approved_count'] = (int)$project['approved_count'];
    $project['locale_count'] = (int)$project['locale_count'];
    $project['progress_percent'] = $project['key_count'] > 0
        ? round(($project['translated_count'] / $project['key_count']) * 100, 1)
        : 0.0;
    $projects[] = $project;
}
$dashboardStats = [
    'projects' => count($projects),
    'average_progress' => $projects
        ? round(array_sum(array_column($projects, 'progress_percent')) / count($projects), 1)
        : 0.0,
];

if (!defined('TMS_PROJECT_DETAIL')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_project') {
        Csrf::verify($_POST);
        $projectName = trim((string)($_POST['project_name'] ?? ''));
        $projectDescription = trim((string)($_POST['description'] ?? ''));
        $projectNameLength = preg_match_all('/./us', $projectName);
        $descriptionLength = preg_match_all('/./us', $projectDescription);

        if ($projectNameLength === false || $projectNameLength === 0 || $projectNameLength > 150) {
            $errorMessage = 'Tên dự án là bắt buộc và không được dài quá 150 ký tự.';
        } elseif ($descriptionLength === false || $descriptionLength > 5000) {
            $errorMessage = 'Mô tả dự án không được dài quá 5000 ký tự.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                throw new RuntimeException('PROJECT_CREATE_TRANSACTION_FAILED');
            }
            try {
                $createdProjectRes = queryParams(
                    $conn,
                    'INSERT INTO projects (name, description, created_by) VALUES ($1, $2, $3) RETURNING id',
                    [
                        $projectName,
                        $projectDescription !== '' ? $projectDescription : null,
                        (int)$currentUser['id']
                    ]
                );
                $newProjectId = (int)pg_fetch_result($createdProjectRes, 0, 'id');
                queryParams(
                    $conn,
                    "INSERT INTO project_members (project_id, user_id, role_in_project)
                     VALUES ($1, $2, 'Team Lead')",
                    [$newProjectId, (int)$currentUser['id']]
                );
                queryParams(
                    $conn,
                    "INSERT INTO project_locales (project_id, locale_code)
                     VALUES ($1, 'vi'), ($1, 'en')
                     ON CONFLICT DO NOTHING",
                    [$newProjectId]
                );
                if (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('PROJECT_CREATE_COMMIT_FAILED');
                }
            } catch (Throwable $e) {
                if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                    pg_query($conn, 'ROLLBACK');
                }
                throw $e;
            }
            header('Location: project_detail.php?id=' . $newProjectId . '&created=1', true, 303);
            exit;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title data-i18n="workspace.page_title">Dự án của tôi - Enterprise TMS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="tms.css">
        <script src="tms-theme.js" defer></script>
        <script src="tms-sync-back.js" defer></script>
    </head>
    <body class="bg-gray-50 text-gray-800 font-sans">
    <main class="tms-shell max-w-7xl mx-auto px-4 py-8">
        <header class="tms-topbar flex flex-col md:flex-row justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100 gap-4">
            <div>
                <a href="index.php" class="text-2xl font-bold text-indigo-600">🌍 Enterprise TMS</a>
                <p class="text-sm text-gray-500 mt-1" data-i18n="workspace.subtitle">Không gian làm việc dự án dịch thuật của bạn</p>
            </div>
            <div class="flex items-center gap-3">
                <?php require __DIR__ . '/templates/tms-header.php'; ?>
            </div>
        </header>

        <?php if ($successMessage !== ''): ?>
            <div class="tms-notice mb-6 bg-green-50 border-l-4 border-green-400 p-4 text-green-800">
                <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="tms-notice mb-6 bg-red-50 border-l-4 border-red-400 p-4 text-red-800">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="tms-dashboard-stats mb-6" aria-label="Tổng quan workspace" data-i18n-aria-label="workspace.overview">
            <div class="tms-dashboard-stat">
                <span class="tms-dashboard-stat-icon bg-indigo-100 text-indigo-700">▦</span>
                <div>
                    <p data-i18n="workspace.managed_projects">Dự án đang quản lý</p>
                    <strong><?= $dashboardStats['projects'] ?></strong>
                </div>
            </div>
            <div class="tms-dashboard-stat">
                <span class="tms-dashboard-stat-icon bg-emerald-100 text-emerald-700">↗</span>
                <div>
                    <p data-i18n="workspace.average_progress">Tiến độ trung bình</p>
                    <strong><?= $dashboardStats['average_progress'] ?>%</strong>
                </div>
            </div>
        </section>

        <section class="tms-accent-indigo bg-white p-6 md:p-8 rounded-xl shadow-sm border border-gray-100">
            <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-indigo-500" data-i18n="workspace.section_label">Workspace</p>
                    <h1 class="text-2xl font-bold text-slate-900 mt-1" data-i18n="workspace.title">Dự án của tôi</h1>
                    <p class="text-sm text-slate-500 mt-1" data-i18n="workspace.projects_description">Các dự án bạn đã tạo hoặc được thêm làm thành viên.</p>
                </div>
                <button type="button" class="tms-button-primary text-white px-4 py-2.5 rounded-lg text-sm font-semibold" data-open-project-modal>
                    + <span data-i18n="workspace.create_project">Tạo dự án mới</span>
                </button>
            </div>
            <section id="recent-projects" class="tms-recent-projects mb-5" hidden aria-labelledby="recent-projects-title">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="recent-projects-title" class="text-sm font-bold text-slate-800" data-i18n="workspace.recent">Truy cập gần đây</h2>
                    <span class="text-xs text-slate-400" data-i18n="workspace.quick_access">Quick access</span>
                </div>
                <div id="recent-project-list" class="tms-recent-project-list"></div>
            </section>
            <div class="tms-project-toolbar mb-5">
                <label class="tms-project-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="project-search" placeholder="Tìm nhanh theo tên dự án..." aria-label="Tìm kiếm dự án" data-i18n-placeholder="workspace.search_placeholder" data-i18n-aria-label="workspace.search_label">
                </label>
                <div class="tms-project-filters" role="tablist" aria-label="Lọc dự án" data-i18n-aria-label="workspace.filter_label">
                    <button type="button" class="is-active" data-project-filter="all" role="tab" aria-selected="true" data-i18n="workspace.filter_all">Tất cả dự án</button>
                    <button type="button" data-project-filter="owned" role="tab" aria-selected="false" data-i18n="workspace.filter_owned">Dự án của tôi</button>
                    <button type="button" data-project-filter="pinned" role="tab" aria-selected="false" data-i18n="workspace.filter_pinned">Được ghim</button>
                </div>
            </div>

            <?php if ($projects): ?>
                <div class="tms-project-grid-scroll" tabindex="0" aria-label="Danh sách thẻ dự án" data-i18n-aria-label="workspace.project_list">
                    <div id="project-card-grid" class="tms-project-grid">
                        <?php foreach ($projects as $project): ?>
                        <article class="tms-project-link rounded-xl border border-slate-200 p-5 hover:border-indigo-400 hover:bg-indigo-50"
                                 data-project-card="<?= (int)$project['id'] ?>"
                                 data-project-owned="<?= (int)$project['created_by'] === (int)$currentUser['id'] ? 'true' : 'false' ?>"
                                 data-project-name="<?= htmlspecialchars((string)$project['name'], ENT_QUOTES, 'UTF-8') ?>"
                                 data-project-search="<?= htmlspecialchars(strtolower((string)$project['name'] . ' ' . (string)($project['description'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="flex items-start justify-between gap-3">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-100 text-lg font-bold text-indigo-700" aria-hidden="true">
                                    <?= htmlspecialchars(strtoupper(substr((string)$project['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><span data-i18n="workspace.project_id_prefix">ID:</span> <?= (int)$project['id'] ?></span>
                                    <button type="button" class="tms-project-pin" data-project-pin="<?= (int)$project['id'] ?>" aria-label="Ghim dự án" data-i18n-aria-label="workspace.pinned" aria-pressed="false">☆</button>
                                    <?php if ((int)$project['created_by'] === (int)$currentUser['id']): ?>
                                        <button type="button"
                                                class="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50"
                                                data-delete-project
                                                data-project-id="<?= (int)$project['id'] ?>"
                                                data-project-name="<?= htmlspecialchars((string)$project['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-csrf-token="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            🗑 <span data-i18n="workspace.delete">Xóa</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a class="block focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 rounded-lg"
                               href="project_detail.php?id=<?= (int)$project['id'] ?>">
                                <span class="mt-4 block text-base font-bold text-slate-900" data-i18n-ignore><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mt-1 block min-h-10 text-sm text-slate-500" <?= empty($project['description']) ? 'data-i18n="workspace.no_project_description"' : 'data-i18n-ignore' ?>><?= htmlspecialchars((string)($project['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Chưa có mô tả dự án.' ?></span>
                                <div class="mt-4 flex items-center justify-between text-xs text-slate-500">
                                    <span data-i18n-ignore><?= $project['locale_count'] ?> <span data-i18n="workspace.languages">ngôn ngữ</span></span>
                                    <strong class="text-indigo-700"><?= $project['progress_percent'] ?>%</strong>
                                </div>
                                <div class="tms-mini-progress" role="progressbar" aria-valuenow="<?= $project['progress_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                    <span style="width: <?= $project['progress_percent'] ?>%"></span>
                                </div>
                                <div class="mt-2 flex items-center justify-between text-xs text-slate-400">
                                    <span data-i18n-ignore><?= number_format($project['key_count']) ?> <span data-i18n="workspace.keys">keys</span></span>
                                    <time datetime="<?= htmlspecialchars((string)$project['last_activity'], ENT_QUOTES, 'UTF-8') ?>"><span data-i18n="tm.updated">Cập nhật</span> <?= htmlspecialchars(date('d/m/Y', strtotime((string)$project['last_activity'])), ENT_QUOTES, 'UTF-8') ?></time>
                                </div>
                                <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-indigo-600"><span data-i18n="workspace.open">Mở workspace</span> <span aria-hidden="true">→</span></span>
                            </a>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p id="project-search-empty" class="hidden rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600" data-i18n="workspace.search_empty">Không tìm thấy dự án phù hợp.</p>
                <nav id="project-pagination" class="tms-pagination mt-6" aria-label="Phân trang dự án" data-i18n-aria-label="workspace.pagination"></nav>
            <?php else: ?>
                <div class="rounded-xl border border-dashed border-indigo-200 bg-indigo-50/60 px-6 py-10 text-center">
                    <p class="text-base font-semibold text-slate-800" data-i18n="workspace.no_projects">Bạn chưa có dự án nào.</p>
                    <p class="mt-1 text-sm text-slate-500" data-i18n="workspace.no_projects_hint">Tạo dự án mới hoặc nhờ Trưởng nhóm thêm bạn vào dự án qua User ID.</p>
                    <button type="button" class="tms-button-primary mt-5 text-white px-4 py-2 rounded-lg text-sm font-semibold" data-open-project-modal>
                        <span data-i18n="workspace.create_first_project">Tạo dự án đầu tiên</span>
                    </button>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <dialog class="tms-modal" id="create-project-modal" aria-labelledby="create-project-title">
        <form method="post" action="index.php" class="tms-modal-card">
            <input type="hidden" name="action" value="create_project">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-indigo-500" data-i18n="workspace.create_project_eyebrow">Dự án mới</p>
                    <h2 id="create-project-title" class="mt-1 text-xl font-bold text-slate-900" data-i18n="workspace.create_project">Tạo dự án mới</h2>
                    <p class="mt-1 text-sm text-slate-500" data-i18n="workspace.create_project_hint">Bạn sẽ tự động trở thành Trưởng nhóm của dự án.</p>
                </div>
                <button type="button" class="tms-modal-close" aria-label="Đóng" data-i18n-aria-label="common.close" data-close-project-modal>×</button>
            </div>
            <label class="mt-5 block text-sm font-semibold text-slate-700" for="project_name" data-i18n="project.name">Tên dự án</label>
            <input id="project_name" name="project_name" maxlength="150" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm" placeholder="Ví dụ: Ứng dụng di động" data-i18n-placeholder="workspace.project_name_placeholder">
            <label class="mt-4 block text-sm font-semibold text-slate-700" for="project_description" data-i18n="project.description">Mô tả</label>
            <textarea id="project_description" name="description" maxlength="5000" rows="3" class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm" placeholder="Mục tiêu hoặc ngữ cảnh dự án" data-i18n-placeholder="workspace.project_description_placeholder"></textarea>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="tms-modal-cancel" data-i18n="common.cancel" data-close-project-modal>Hủy</button>
                <button type="submit" class="tms-button-primary text-white px-5 py-2 rounded-lg text-sm font-semibold" data-i18n="workspace.create_project_submit">Tạo dự án</button>
            </div>
        </form>
    </dialog>
    <script id="tms-history-script">
        (() => {
    const modal = document.getElementById('create-project-modal');
            document.querySelectorAll('[data-open-project-modal]').forEach((button) => {
                button.addEventListener('click', () => modal.showModal());
            });
            document.querySelectorAll('[data-close-project-modal]').forEach((button) => {
                button.addEventListener('click', () => modal.close());
            });
            document.querySelectorAll('[data-delete-project]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const projectName = button.dataset.projectName || 'dự án này';
                    if (!window.confirm(`Bạn có chắc chắn muốn xóa dự án ${projectName} không? Hành động này không thể hoàn tất lại.`)) {
                        return;
                    }

                    button.disabled = true;
                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept': 'application/json'
                            },
                            body: new URLSearchParams({
                                action: 'delete_project',
                                project_id: button.dataset.projectId || '',
                                csrf_token: button.dataset.csrfToken || ''
                            })
                        });
                        let result;
                        try {
                            result = await response.json();
                        } catch {
                            throw new Error('Phản hồi máy chủ không hợp lệ. Vui lòng tải lại trang và thử lại.');
                        }
                        if (!response.ok || !result.success) {
                            throw new Error(result.message || 'Không thể xóa dự án.');
                        }
                        window.location.reload();
                    } catch (error) {
                        window.alert(error instanceof Error ? error.message : 'Không thể xóa dự án.');
                        button.disabled = false;
                    }
                });
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.close();
            });
            <?php if ($errorMessage !== ''): ?>
            modal.showModal();
            <?php endif; ?>
        })();
        const projectSearch = document.getElementById('project-search');
        const projectCards = [...document.querySelectorAll('[data-project-card]')];
        const projectSearchEmpty = document.getElementById('project-search-empty');
        const pagination = document.getElementById('project-pagination');
        const recentProjects = document.getElementById('recent-projects');
        const recentProjectList = document.getElementById('recent-project-list');
        const filterButtons = [...document.querySelectorAll('[data-project-filter]')];
        const pageSize = 9;
        const recentKey = 'tms-recent-projects';
        const pinnedKey = 'tms-pinned-projects';
        let activeFilter = 'all';
        let currentPage = 1;
        const readIds = key => {
            try {
                const value = JSON.parse(localStorage.getItem(key) || '[]');
                return Array.isArray(value) ? value.map(String) : [];
            } catch {
                return [];
            }
        };
        const writeIds = (key, ids) => localStorage.setItem(key, JSON.stringify(ids.slice(0, 8)));
        const pinnedIds = () => readIds(pinnedKey);
        const filteredCards = () => {
            const query = (projectSearch?.value || '').trim().toLowerCase();
            const pinned = pinnedIds();
            return projectCards.filter(card => {
                const matchesQuery = query === '' || (card.dataset.projectSearch || '').includes(query);
                const matchesFilter = activeFilter === 'all'
                    || (activeFilter === 'owned' && card.dataset.projectOwned === 'true')
                    || (activeFilter === 'pinned' && pinned.includes(card.dataset.projectCard));
                return matchesQuery && matchesFilter;
            });
        };
        const renderRecent = () => {
            if (!recentProjects || !recentProjectList) return;
            const cardsById = new Map(projectCards.map(card => [card.dataset.projectCard, card]));
            const recent = readIds(recentKey).map(id => cardsById.get(id)).filter(Boolean).slice(0, 4);
            recentProjectList.replaceChildren();
            recent.forEach(card => {
                const link = document.createElement('a');
                link.href = `project_detail.php?id=${encodeURIComponent(card.dataset.projectCard)}`;
                link.className = 'tms-recent-project';
                link.textContent = card.dataset.projectName || 'Dự án';
                recentProjectList.append(link);
            });
            recentProjects.hidden = recent.length === 0;
        };
        const renderPins = () => {
            const pinned = pinnedIds();
            document.querySelectorAll('[data-project-pin]').forEach(button => {
                const isPinned = pinned.includes(button.dataset.projectPin);
                button.textContent = isPinned ? '★' : '☆';
                button.classList.toggle('is-pinned', isPinned);
                button.setAttribute('aria-pressed', String(isPinned));
            });
        };
        const renderPagination = (totalPages) => {
            if (!pagination) return;
            pagination.replaceChildren();
            if (totalPages <= 1) return;
            const addButton = (label, page, disabled = false, current = false) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                if (label === '← Trước') button.dataset.i18n = 'workspace.pagination_previous';
                if (label === 'Sau →') button.dataset.i18n = 'workspace.pagination_next';
                button.disabled = disabled;
                button.className = `tms-page-button${current ? ' is-current' : ''}`;
                button.addEventListener('click', () => {
                    currentPage = page;
                    renderProjects();
                    document.getElementById('project-card-grid')?.closest('.tms-project-grid-scroll')?.scrollTo({ top: 0, behavior: 'smooth' });
                });
                pagination.append(button);
            };
            addButton('← Trước', currentPage - 1, currentPage === 1);
            for (let page = 1; page <= totalPages; page += 1) addButton(String(page), page, false, page === currentPage);
            addButton('Sau →', currentPage + 1, currentPage === totalPages);
        };
        const renderProjects = () => {
            const visibleCards = filteredCards();
            const totalPages = Math.max(1, Math.ceil(visibleCards.length / pageSize));
            currentPage = Math.min(currentPage, totalPages);
            const pageCards = new Set(visibleCards.slice((currentPage - 1) * pageSize, currentPage * pageSize));
            projectCards.forEach(card => { card.hidden = !pageCards.has(card); });
            if (projectSearchEmpty) {
                projectSearchEmpty.hidden = visibleCards.length > 0;
                projectSearchEmpty.classList.toggle('hidden', visibleCards.length > 0);
            }
            renderPagination(totalPages);
        };
        projectSearch?.addEventListener('input', () => { currentPage = 1; renderProjects(); });
        filterButtons.forEach(button => button.addEventListener('click', () => {
            activeFilter = button.dataset.projectFilter || 'all';
            currentPage = 1;
            filterButtons.forEach(item => {
                const selected = item === button;
                item.classList.toggle('is-active', selected);
                item.setAttribute('aria-selected', String(selected));
            });
            renderProjects();
        }));
        document.querySelectorAll('[data-project-pin]').forEach(button => button.addEventListener('click', event => {
            event.preventDefault();
            const id = button.dataset.projectPin;
            const pinned = pinnedIds();
            writeIds(pinnedKey, pinned.includes(id) ? pinned.filter(item => item !== id) : [id, ...pinned]);
            renderPins();
            renderProjects();
        }));
        document.querySelectorAll('[data-project-card] a[href*="project_detail.php"]').forEach(link => link.addEventListener('click', () => {
            const card = link.closest('[data-project-card]');
            if (!card) return;
            writeIds(recentKey, [card.dataset.projectCard, ...readIds(recentKey).filter(id => id !== card.dataset.projectCard)]);
        }));
        renderPins();
        renderRecent();
        renderProjects();
    </script>
    </body>
    </html>
    <?php
    exit;
}

$requestedProjectId = isset($_GET['project_id'])
    ? max(1, (int)$_GET['project_id'])
    : ($projects ? (int)$projects[0]['id'] : null);
if ($requestedProjectId !== null && !in_array(
    $requestedProjectId,
    array_map(static fn(array $project): int => (int)$project['id'], $projects),
    true
)) {
    $requestedProjectId = $projects ? (int)$projects[0]['id'] : null;
}
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : null;
if ($versionId !== null) {
    $versionAccessRes = queryParams(
        $conn,
        'SELECT sv.id
         FROM source_versions sv
         JOIN projects p ON p.id = sv.project_id
         WHERE sv.id = $1
           AND sv.project_id = $3
           AND (p.created_by = $2 OR EXISTS (
               SELECT 1 FROM project_members pm
               WHERE pm.project_id = p.id AND pm.user_id = $2
           ))',
        [$versionId, (int)$currentUser['id'], $requestedProjectId]
    );
    if (!pg_fetch_assoc($versionAccessRes)) {
        $versionId = null;
    }
}
if ($versionId === null && $requestedProjectId !== null) {
    $versionRes = queryParams(
        $conn,
        'SELECT v.id
         FROM source_versions v
         WHERE v.project_id = $1
           AND EXISTS (SELECT 1 FROM strings s WHERE s.source_version_id = v.id)
         ORDER BY v.id DESC LIMIT 1',
        [$requestedProjectId]
    );
    $versionRow = pg_fetch_assoc($versionRes);
    if ($versionRow) {
        $versionId = (int)$versionRow['id'];
    } else {
        $versionRes = queryParams(
            $conn,
            'SELECT id FROM source_versions WHERE project_id = $1 ORDER BY id DESC LIMIT 1',
            [$requestedProjectId]
        );
        $versionRow = pg_fetch_assoc($versionRes);
        $versionId = $versionRow ? (int)$versionRow['id'] : 0;
    }
} elseif ($versionId === null) {
    $versionId = 0;
}

$versionRes = queryParams($conn, "SELECT id, version_number, project_id, source_url, frozen_at, frozen_by_user_id FROM source_versions WHERE id = $1 LIMIT 1", [$versionId]);
$currentVersion = pg_fetch_assoc($versionRes);
$isVersionFrozen = $currentVersion && $currentVersion['frozen_at'] !== null;
$projectId = $currentVersion
    ? (int)$currentVersion['project_id']
    : ($requestedProjectId ?? 0);
if (!$currentVersion && $requestedProjectId !== null) {
    $projectId = $requestedProjectId;
}
$hasSourceStrings = false;
if ($currentVersion) {
    $sourceCountRes = queryParams(
        $conn,
        'SELECT EXISTS (
             SELECT 1 FROM strings
             WHERE source_version_id = $1 AND project_id = $2
         )',
        [(int)$currentVersion['id'], $projectId]
    );
    $hasSourceStrings = pg_fetch_result($sourceCountRes, 0, 0) === 't';
}
$projectRes = queryParams($conn, 'SELECT id, name, created_by FROM projects WHERE id = $1', [$projectId]);
$projectRow = pg_fetch_assoc($projectRes);
$projectExists = $projectRow !== false;
$projectName = $projectRow ? (string)$projectRow['name'] : '';
$tmRepository = new TmRepository($conn, dirname(__DIR__) . '/storage/tm-repository');
$currentProjectRole = $isAuthenticated && $projectExists
    ? ProjectAccess::roleFor($conn, $projectId, (int)$currentUser['id'])
    : null;
$isProjectLeader = $currentProjectRole === 'Team Lead';
$canManageCurrentProject = $isAuthenticated && $projectExists &&
    ProjectAccess::canManageMembers($conn, $projectId, $currentUser);
$canTranslateCurrentProject = $isAuthenticated && $currentVersion &&
    ProjectAccess::canTranslate($conn, $projectId, $currentUser);
$canReviewCurrentProject = $isAuthenticated && $currentVersion &&
    ProjectAccess::canReview($conn, $projectId, $currentUser);
$canExportAuditTrail = $isAuthenticated && $projectExists &&
    ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser['id']);
$versionQuery = $currentVersion
    ? 'version_id=' . (int)$versionId
    : 'project_id=' . $projectId;
$workspaceAction = 'project_detail.php?id=' . $projectId;
$getImportWorkStatus = static function (int $sourceVersionId, int $sourceProjectId) use ($conn): array {
    $result = queryParams(
        $conn,
        "WITH locale_translations AS (
             SELECT t.locale, MAX(t.updated_at) AS latest_translation_at
             FROM strings s
             JOIN translations t ON t.string_id = s.id
             WHERE s.source_version_id = $1
               AND NULLIF(BTRIM(t.translated_text), '') IS NOT NULL
             GROUP BY t.locale
         )
         SELECT
             (SELECT frozen_at FROM source_versions WHERE id = $1) AS frozen_at,
             (SELECT COUNT(*)
              FROM strings s
              JOIN translations t ON t.string_id = s.id
              WHERE s.source_version_id = $1
                AND NULLIF(BTRIM(t.translated_text), '') IS NOT NULL)::int AS translated_count,
             (SELECT COUNT(*)
              FROM locale_translations lt
              WHERE NOT EXISTS (
                  SELECT 1
                  FROM release_snapshots rs
                  WHERE rs.source_version_id = $1
                    AND rs.locale = lt.locale
                    AND rs.created_at >= lt.latest_translation_at
                  UNION ALL
                  SELECT 1
                  FROM audit_logs a
                  WHERE a.project_id = $2
                    AND a.action_type = 'EXPORT_TRANSLATION_PACKAGE'
                    AND a.created_at >= lt.latest_translation_at
                    AND a.details LIKE 'source_version_id=' || $1::text ||
                        '; locale=' || lt.locale || ';%'
              ))::int AS unexported_locale_count",
        [$sourceVersionId, $sourceProjectId]
    );
    $row = pg_fetch_assoc($result);
    $translatedCount = (int)($row['translated_count'] ?? 0);
    $unexportedLocaleCount = (int)($row['unexported_locale_count'] ?? 0);
    $isFrozen = $row && $row['frozen_at'] !== null;
    return [
        'translatedCount' => $translatedCount,
        'unexportedLocaleCount' => $unexportedLocaleCount,
        'requiresConfirmation' => $translatedCount > 0 &&
            (!$isFrozen || $unexportedLocaleCount > 0),
    ];
};

if ($isLocaleApiAction) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondLocale = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respondLocale(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Hãy tải lại trang.'], 403);
    }

    $requestedProjectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
    $requestedVersionId = filter_var($_POST['version_id'] ?? null, FILTER_VALIDATE_INT);
    if (
        $requestedProjectId === false ||
        $requestedProjectId !== $projectId ||
        $requestedVersionId === false ||
        $requestedVersionId === null ||
        $requestedVersionId < 1
    ) {
        $respondLocale(['success' => false, 'message' => 'Dự án hoặc phiên bản không hợp lệ.'], 400);
    }

    try {
        $versionAccess = queryParams(
            $conn,
            'SELECT id FROM source_versions WHERE id = $1 AND project_id = $2',
            [$requestedVersionId, $projectId]
        );
        if (!pg_fetch_assoc($versionAccess) || !ProjectAccess::canTranslate($conn, $projectId, $currentUser)) {
            $respondLocale(['success' => false, 'message' => 'Bạn không có quyền truy cập phiên bản này.'], 403);
        }

        $formatLocales = static function (array $activeLocales) use ($allLocales): array {
            $names = [];
            foreach ($allLocales as $availableLocale) {
                $names[strtolower((string)$availableLocale['code'])] = $availableLocale;
            }
            $formatted = [];
            foreach ($activeLocales as $activeLocale) {
                $code = strtolower((string)$activeLocale['locale_code']);
                $catalogEntry = $names[$code] ?? null;
                $formatted[] = [
                    'code' => $code,
                    'name' => (string)($catalogEntry['name_vi'] ?? $activeLocale['name']),
                    'name_en' => (string)($catalogEntry['name_en'] ?? $activeLocale['name']),
                ];
            }
            return $formatted;
        };

        if ($_POST['action'] === 'list_project_locales') {
            $respondLocale([
                'success' => true,
                'locales' => $formatLocales(
                    $localeRepository->getActiveForProject($projectId, (int)$requestedVersionId)
                ),
            ]);
        }

        if (!ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser['id'])) {
            $respondLocale(['success' => false, 'message' => 'Chỉ Trưởng nhóm của dự án hiện tại mới được triển khai ngôn ngữ.'], 403);
        }
        $localeInput = $_POST['locale_code'] ?? '';
        $localeCode = is_string($localeInput) ? strtolower(trim($localeInput)) : '';
        if ($localeCode === '') {
            $respondLocale(['success' => false, 'message' => 'Mã ngôn ngữ không hợp lệ.'], 400);
        }
        $localeResult = queryParams(
            $conn,
            'SELECT code FROM locales WHERE LOWER(code) = $1',
            [$localeCode]
        );
        $locale = pg_fetch_assoc($localeResult);
        if (!$locale) {
            $respondLocale(['success' => false, 'message' => 'Ngôn ngữ không hợp lệ.'], 400);
        }

        $requestedLanguages = json_decode(
            is_string($_POST['deployed_languages'] ?? null) ? $_POST['deployed_languages'] : '[]',
            true
        );
        if (!is_array($requestedLanguages)) {
            $respondLocale(['success' => false, 'message' => 'Danh sách ngôn ngữ không hợp lệ.'], 400);
        }
        $requestedCodes = [$localeCode];
        foreach ($requestedLanguages as $requestedLanguage) {
            if (is_array($requestedLanguage) && is_string($requestedLanguage['code'] ?? null)) {
                $requestedCodes[] = strtolower(trim($requestedLanguage['code']));
            }
        }
        $requestedCodes = array_values(array_unique(array_filter(
            $requestedCodes,
            static fn(string $code): bool => $code !== ''
        )));
        $wasAdded = false;
        foreach ($requestedCodes as $requestedCode) {
            $validLocale = pg_fetch_assoc(queryParams(
                $conn,
                'SELECT code FROM locales WHERE LOWER(code) = $1',
                [$requestedCode]
            ));
            if (!$validLocale) {
                $respondLocale(['success' => false, 'message' => 'Danh sách chứa mã ngôn ngữ không hợp lệ.'], 400);
            }
            $wasAdded = $localeRepository->addLocaleForProject(
                $projectId,
                $requestedCode,
                (int)$requestedVersionId
            ) || $wasAdded;
        }
        $localeName = $localeCode;
        foreach ($allLocales as $availableLocale) {
            if ($availableLocale['code'] === $localeCode) {
                $localeName = (string)$availableLocale['name_vi'];
                break;
            }
        }
        if ($wasAdded) {
            try {
                (new AuditLogger($conn))->log(
                    $projectId,
                    null,
                    'ACTIVATE_PROJECT_LOCALE',
                    "User [{$currentUser['username']}] đã triển khai ngôn ngữ [{$localeCode}]",
                    (int)$currentUser['id']
                );
            } catch (Throwable $exception) {
                error_log('PROJECT_LOCALE_ACTIVATION_AUDIT_FAILED: ' . $exception->getMessage());
            }
        }
        $respondLocale([
            'success' => true,
            'message' => $wasAdded ? 'Đã triển khai ngôn ngữ.' : 'Ngôn ngữ này đã được triển khai.',
            'locale' => ['code' => $localeCode, 'name' => $localeName],
            'locales' => $formatLocales(
                $localeRepository->getActiveForProject($projectId, (int)$requestedVersionId)
            ),
            'already_active' => !$wasAdded,
        ]);
    } catch (Throwable $exception) {
        error_log('PROJECT_LOCALE_ACTIVATION_FAILED: ' . $exception->getMessage());
        $respondLocale(['success' => false, 'message' => 'Không thể triển khai ngôn ngữ. Vui lòng thử lại.'], 500);
    }
}

if ($isGlossaryApiAction) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondGlossary = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respondGlossary(['success' => false, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
    }

    $requestedProjectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);
    $localeInput = $_POST['locale'] ?? '';
    $requestedLocale = is_string($localeInput) ? strtolower(trim($localeInput)) : '';
    if (
        $requestedProjectId === false ||
        $requestedProjectId !== $projectId ||
        $requestedLocale !== $currentLocale ||
        !ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser['id'])
    ) {
        $respondGlossary(['success' => false, 'message' => 'Chỉ Trưởng nhóm của dự án hiện tại mới được quản lý thuật ngữ.'], 403);
    }

    try {
        $glossaryManager = new GlossaryManager($conn);
        $auditLogger = new AuditLogger($conn);
        if ($_POST['action'] === 'glossary_save') {
            $termIdInputValue = $_POST['term_id'] ?? '';
            $termIdInput = is_string($termIdInputValue) ? trim($termIdInputValue) : '';
            $termId = $termIdInput === '' ? null : filter_var($termIdInput, FILTER_VALIDATE_INT);
            $termInput = $_POST['term'] ?? '';
            $translationInput = $_POST['translation'] ?? '';
            $descriptionInput = $_POST['description'] ?? '';
            $term = is_string($termInput) ? trim($termInput) : '';
            $translation = is_string($translationInput) ? trim($translationInput) : '';
            $description = is_string($descriptionInput) ? trim($descriptionInput) : '';
            $termLength = preg_match_all('/./us', $term);
            $translationLength = preg_match_all('/./us', $translation);
            $descriptionLength = preg_match_all('/./us', $description);
            if (
                ($termIdInput !== '' && ($termId === false || $termId < 1)) ||
                $term === '' ||
                $translation === '' ||
                $termLength === false ||
                $termLength > 500 ||
                $translationLength === false ||
                $translationLength > 500 ||
                $descriptionLength === false ||
                $descriptionLength > 2000
            ) {
                $respondGlossary(['success' => false, 'message' => 'Vui lòng nhập từ gốc và bản dịch hợp lệ (tối đa 500 ký tự mỗi mục).'], 400);
            }

            $savedTermId = $glossaryManager->saveProjectTerm(
                $projectId,
                $currentLocale,
                $term,
                $translation,
                $description,
                $termId === null ? null : (int)$termId
            );
            $auditAction = $termId === null ? 'ADD_GLOSSARY' : 'UPDATE_GLOSSARY';
            $auditLogger->log(
                $projectId,
                null,
                $auditAction,
                "User [{$currentUser['username']}] " . ($termId === null ? 'thêm' : 'cập nhật') .
                    " thuật ngữ [{$currentLocale}]: {$term} -> {$translation}"
            );
            $respondGlossary([
                'success' => true,
                'message' => $termId === null ? 'Đã thêm thuật ngữ.' : 'Đã cập nhật thuật ngữ.',
                'term_id' => $savedTermId,
            ]);
        }

        $termIdInput = $_POST['term_id'] ?? null;
        $termId = is_string($termIdInput) || is_int($termIdInput)
            ? filter_var($termIdInput, FILTER_VALIDATE_INT)
            : false;
        if ($termId === false || $termId < 1) {
            $respondGlossary(['success' => false, 'message' => 'Thuật ngữ cần xóa không hợp lệ.'], 400);
        }
        if (!$glossaryManager->deleteProjectTerm($projectId, $currentLocale, (int)$termId)) {
            $respondGlossary(['success' => false, 'message' => 'Không tìm thấy thuật ngữ trong dự án hiện tại.'], 404);
        }
        $auditLogger->log(
            $projectId,
            null,
            'DELETE_GLOSSARY',
            "User [{$currentUser['username']}] xóa thuật ngữ ID [{$termId}] của locale [{$currentLocale}]"
        );
        $respondGlossary(['success' => true, 'message' => 'Đã xóa thuật ngữ.']);
    } catch (Throwable $exception) {
        error_log('GLOSSARY_API_FAILED: ' . $exception->getMessage());
        $isDuplicate = $exception->getMessage() === 'GLOSSARY_TERM_EXISTS' ||
            str_contains(strtolower($exception->getMessage()), 'duplicate key');
        $respondGlossary([
            'success' => false,
            'message' => $isDuplicate
                ? 'Từ gốc này đã tồn tại trong từ điển.'
                : 'Không thể lưu thay đổi từ điển. Vui lòng thử lại.',
        ], $isDuplicate ? 409 : 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_source_preflight') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondImportPreflight = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respondImportPreflight(['success' => false, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
    }
    if (
        !$currentVersion ||
        (int)($_POST['project_id'] ?? 0) !== $projectId ||
        !ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser['id'])
    ) {
        $respondImportPreflight(['success' => false, 'message' => 'Bạn không có quyền kiểm tra trạng thái import.'], 403);
    }
    try {
        $respondImportPreflight([
            'success' => true,
            'status' => $getImportWorkStatus((int)$currentVersion['id'], $projectId),
        ]);
    } catch (Throwable $exception) {
        error_log('SOURCE_IMPORT_PREFLIGHT_FAILED: ' . $exception->getMessage());
        $respondImportPreflight(['success' => false, 'message' => 'Không thể kiểm tra trạng thái phiên bản hiện tại.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish_current_for_import') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondPublish = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respondPublish(['success' => false, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
    }
    if (
        !$currentVersion ||
        (int)($_POST['project_id'] ?? 0) !== $projectId ||
        !ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser['id'])
    ) {
        $respondPublish(['success' => false, 'message' => 'Chỉ Trưởng nhóm của dự án mới được chốt và xuất bản phiên bản.'], 403);
    }

    $transactionStarted = false;
    try {
        if (pg_query($conn, 'BEGIN') === false) {
            throw new RuntimeException('PUBLISH_IMPORT_TRANSACTION_FAILED');
        }
        $transactionStarted = true;
        $versionResult = queryParams(
            $conn,
            'SELECT id, version_number, frozen_at
             FROM source_versions
             WHERE id = $1 AND project_id = $2
             FOR UPDATE',
            [(int)$currentVersion['id'], $projectId]
        );
        $versionToPublish = pg_fetch_assoc($versionResult);
        if (!$versionToPublish) {
            throw new RuntimeException('SOURCE_VERSION_NOT_FOUND');
        }
        $wasFrozen = $versionToPublish['frozen_at'] !== null;
        if (!$wasFrozen) {
            queryParams(
                $conn,
                'UPDATE source_versions
                 SET frozen_at = clock_timestamp(), frozen_by_user_id = $2
                 WHERE id = $1',
                [(int)$versionToPublish['id'], (int)$currentUser['id']]
            );
            queryParams(
                $conn,
                "INSERT INTO release_sync_history
                    (project_id, source_version_id, type, version_number, record_count, actor_user_id)
                 VALUES (
                    $1, $2, 'version_lock', $3,
                    (SELECT COUNT(*)::INT FROM strings WHERE source_version_id = $2),
                    $4
                 )
                 ON CONFLICT DO NOTHING",
                [
                    $projectId,
                    (int)$versionToPublish['id'],
                    (string)$versionToPublish['version_number'],
                    (int)$currentUser['id'],
                ]
            );
        }
        $safeLocale = preg_replace('/[^A-Za-z0-9_-]/', '', $currentLocale);
        $safeVersion = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$versionToPublish['version_number']);
        $outputPath = __DIR__ . "/releases/{$safeLocale}/{$safeVersion}.json";
        $content = (new ReleaseExporter($conn))->generateRelease(
            $projectId,
            $currentLocale,
            (string)$versionToPublish['version_number'],
            $outputPath,
            (int)$versionToPublish['id']
        );
        $auditLogger = new AuditLogger($conn);
        if (!$wasFrozen) {
            $auditLogger->log(
                $projectId,
                null,
                'FREEZE_SOURCE_VERSION',
                "User [{$currentUser['username']}] đã chốt phiên bản [{$versionToPublish['version_number']}]"
            );
        }
        $auditLogger->log(
            $projectId,
            null,
            'EXPORT_RELEASE',
            "User [{$currentUser['username']}] xuất bản gói [{$currentLocale}] v{$versionToPublish['version_number']}"
        );
        if (pg_query($conn, 'COMMIT') === false) {
            throw new RuntimeException('PUBLISH_IMPORT_COMMIT_FAILED');
        }
        $transactionStarted = false;

        $requestedImportVersion = trim((string)($_POST['import_version_number'] ?? ''));
        $suggestedImportVersion = null;
        if ($requestedImportVersion === (string)$versionToPublish['version_number']) {
            $versionNumber = (string)$versionToPublish['version_number'];
            if (preg_match('/^([vV]?)(\d+)\.(\d+)\.(\d+)$/', $versionNumber, $parts) === 1) {
                $suggestedImportVersion = $parts[1] . $parts[2] . '.' . $parts[3] . '.' . ((int)$parts[4] + 1);
            } else {
                $suggestedImportVersion = substr($versionNumber, 0, 44) . '-next';
            }
            $suffix = 1;
            while (true) {
                $collision = queryParams(
                    $conn,
                    'SELECT 1 FROM source_versions WHERE project_id = $1 AND version_number = $2 LIMIT 1',
                    [$projectId, $suggestedImportVersion]
                );
                if (!pg_fetch_assoc($collision)) {
                    break;
                }
                $suffix++;
                $base = preg_replace('/-(?:next|\d+)$/', '', $suggestedImportVersion) ?? $suggestedImportVersion;
                $suggestedImportVersion = substr($base, 0, 46) . '-' . $suffix;
            }
        }
        $respondPublish([
            'success' => true,
            'message' => 'Đã chốt phiên bản và xuất bản thành công.',
            'version_number' => (string)$versionToPublish['version_number'],
            'import_version_number' => $suggestedImportVersion,
            'locale' => $currentLocale,
            'bytes' => strlen($content),
        ]);
    } catch (Throwable $exception) {
        if ($transactionStarted && pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
            pg_query($conn, 'ROLLBACK');
        }
        error_log('PUBLISH_CURRENT_FOR_IMPORT_FAILED: ' . $exception->getMessage());
        $respondPublish([
            'success' => false,
            'message' => 'Không thể chốt và xuất bản phiên bản. Import chưa được thực hiện.',
        ], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_GET['action'] ?? ''), ['lock_translation', 'unlock_translation'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondLock = static function (array $payload, int $status = 200): never {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST) || !$currentVersion || !$canTranslateCurrentProject) {
        $respondLock(['success' => false, 'message' => 'Bạn không có quyền khóa chuỗi này.'], 403);
    }
    $lockStringId = filter_var($_POST['string_id'] ?? null, FILTER_VALIDATE_INT);
    if ($lockStringId === false || $lockStringId === null || $lockStringId < 1) {
        $respondLock(['success' => false, 'message' => 'Chuỗi không hợp lệ.'], 400);
    }
    try {
        $stringCheck = queryParams(
            $conn,
            'SELECT 1 FROM strings WHERE id = $1 AND source_version_id = $2 AND project_id = $3',
            [(int)$lockStringId, (int)$versionId, $projectId]
        );
        if (!pg_fetch_assoc($stringCheck)) {
            $respondLock(['success' => false, 'message' => 'Chuỗi không thuộc phiên bản hiện tại.'], 404);
        }
        if (($_GET['action'] ?? '') === 'unlock_translation') {
            queryParams(
                $conn,
                'DELETE FROM translation_locks WHERE string_id = $1 AND locale = $2 AND user_id = $3',
                [(int)$lockStringId, $currentLocale, (int)$currentUser['id']]
            );
            $respondLock(['success' => true]);
        }
        $lockResult = queryParams(
            $conn,
            "INSERT INTO translation_locks (string_id, locale, user_id, acquired_at, expires_at)
             VALUES ($1, $2, $3, clock_timestamp(), clock_timestamp() + INTERVAL '5 minutes')
             ON CONFLICT (string_id, locale) DO UPDATE
             SET user_id = EXCLUDED.user_id,
                 acquired_at = EXCLUDED.acquired_at,
                 expires_at = EXCLUDED.expires_at
             WHERE translation_locks.expires_at <= clock_timestamp()
                OR translation_locks.user_id = EXCLUDED.user_id
             RETURNING user_id, expires_at::text AS expires_at",
            [(int)$lockStringId, $currentLocale, (int)$currentUser['id']]
        );
        $lock = pg_fetch_assoc($lockResult);
        if (!$lock || (int)$lock['user_id'] !== (int)$currentUser['id']) {
            $respondLock(['success' => false, 'message' => 'Chuỗi đang được thành viên khác chỉnh sửa.'], 409);
        }
        $respondLock(['success' => true, 'expiresAt' => $lock['expires_at']]);
    } catch (Throwable $exception) {
        error_log('TRANSLATION_LOCK_FAILED: ' . $exception->getMessage());
        $respondLock(['success' => false, 'message' => 'Không thể cập nhật trạng thái khóa.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'suggest_translation') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $respondSuggestion = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!$currentVersion || !$canTranslateCurrentProject) {
        $respondSuggestion(['success' => false, 'message' => 'Bạn không có quyền sử dụng gợi ý dịch thuật.'], 403);
    }
    $stringId = filter_var($_GET['string_id'] ?? null, FILTER_VALIDATE_INT);
    if ($stringId === false || $stringId === null || $stringId < 1) {
        $respondSuggestion(['success' => false, 'message' => 'Chuỗi nguồn không hợp lệ.'], 400);
    }
    try {
        $sourceResult = queryParams(
            $conn,
            'SELECT source_text FROM strings WHERE id = $1 AND source_version_id = $2 AND project_id = $3',
            [(int)$stringId, (int)$versionId, $projectId]
        );
        $source = pg_fetch_result($sourceResult, 0, 'source_text');
        if ($source === false) {
            $respondSuggestion(['success' => false, 'message' => 'Không tìm thấy chuỗi nguồn.'], 404);
        }
        $suggestion = (new SuggestionEngine($conn))->suggest($projectId, $currentLocale, (string)$source);
        $respondSuggestion(['success' => true] + $suggestion);
    } catch (Throwable $exception) {
        error_log('SUGGESTION_ENGINE_FAILED: ' . $exception->getMessage());
        $respondSuggestion(['success' => false, 'message' => 'Không thể tạo gợi ý lúc này.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export_audit_csv') {
    if (!$canExportAuditTrail) {
        http_response_code(403);
        exit('Bạn không có quyền xuất Audit Trail của dự án này.');
    }

    $exportFilters = [
        'action' => trim((string)($_GET['audit_action'] ?? '')),
        'user_id' => trim((string)($_GET['audit_user_id'] ?? '')),
        'from' => trim((string)($_GET['audit_from'] ?? '')),
        'to' => trim((string)($_GET['audit_to'] ?? '')),
    ];
    $auditWhere = ['a.project_id = $1'];
    $auditParams = [$projectId];
    foreach (['action', 'user_id'] as $filter) {
        if ($exportFilters[$filter] !== '') {
            $auditWhere[] = ($filter === 'user_id' ? 'a.actor_user_id' : 'a.action_type')
                . ' = $' . (count($auditParams) + 1);
            $auditParams[] = $filter === 'user_id' ? (int)$exportFilters[$filter] : $exportFilters[$filter];
        }
    }
    if ($exportFilters['from'] !== '' && strtotime($exportFilters['from']) !== false) {
        $auditWhere[] = 'a.created_at >= $' . (count($auditParams) + 1) . '::timestamptz';
        $auditParams[] = $exportFilters['from'] . ' 00:00:00';
    }
    if ($exportFilters['to'] !== '' && strtotime($exportFilters['to']) !== false) {
        $auditWhere[] = 'a.created_at < $' . (count($auditParams) + 1) . '::date + INTERVAL \'1 day\'';
        $auditParams[] = $exportFilters['to'];
    }
    $auditResult = queryParams(
        $conn,
        'SELECT a.action_type, a.details, a.created_at,
                COALESCE(u.username, \'Hệ thống\') AS actor_username
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE ' . implode(' AND ', $auditWhere) . '
         ORDER BY a.created_at ASC, a.id ASC',
        $auditParams
    );
    $filename = 'audit-trail-project-' . $projectId . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('AUDIT_EXPORT_OUTPUT_FAILED');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Loại hành động', 'Người thực hiện', 'Chi tiết thay đổi', 'Thời gian']);
    while ($auditRow = pg_fetch_assoc($auditResult)) {
        fputcsv($output, [
            $auditRow['action_type'],
            $auditRow['actor_username'],
            $auditRow['details'],
            $auditRow['created_at'],
        ]);
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'live_preview_data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    $respondPreview = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG |
                JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit;
    };
    if (!$currentVersion || !$canTranslateCurrentProject) {
        $respondPreview(['success' => false, 'message' => 'Không có quyền xem trước phiên bản này.'], 403);
    }

    try {
        $snapshotResult = queryParams(
            $conn,
            'SELECT id, version_number, source_url, source_html
             FROM source_versions
             WHERE id = $1 AND project_id = $2',
            [(int)$currentVersion['id'], $projectId]
        );
        $snapshot = pg_fetch_assoc($snapshotResult);
        if (!$snapshot) {
            $respondPreview(['success' => false, 'message' => 'Không tìm thấy phiên bản xem trước.'], 404);
        }
        $previewDataResult = queryParams(
            $conn,
            "SELECT s.id AS string_id, s.key_path, s.source_text,
                    COALESCE(t.translated_text, '') AS translated_text,
                    COALESCE(t.status, 'draft') AS status
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
             WHERE s.source_version_id = $2
             ORDER BY s.key_path",
            [$currentLocale, (int)$snapshot['id']]
        );
        $freshRows = [];
        while ($row = pg_fetch_assoc($previewDataResult)) {
            $freshRows[] = [
                'key' => $row['key_path'],
                'source' => $row['source_text'],
                'translation' => $row['translated_text'],
                'status' => $row['status'],
                'stringId' => (int)$row['string_id'],
            ];
        }
        $respondPreview([
            'success' => true,
            'versionId' => (int)$snapshot['id'],
            'versionNumber' => $snapshot['version_number'],
            'sourceUrl' => $snapshot['source_url'],
            'sourceHtml' => $snapshot['source_html'],
            'rows' => $freshRows,
        ]);
    } catch (Throwable $exception) {
        error_log('LIVE_PREVIEW_DATA_FAILED: ' . $exception->getMessage());
        $respondPreview(['success' => false, 'message' => 'Không thể tải dữ liệu mới nhất cho xem trước.'], 500);
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_GET['action'] ?? '') === 'bulk_save_translations'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondBulk = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $rawBody = file_get_contents('php://input');
    try {
        $payload = $contentType === 'application/json' && $rawBody !== false
            ? json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR)
            : null;
    } catch (JsonException) {
        $payload = null;
    }
    if (!is_array($payload)) {
        $respondBulk(['success' => false, 'message' => 'Nội dung yêu cầu JSON không hợp lệ.'], 400);
    }
    if (!Csrf::isValid($payload)) {
        $respondBulk(['success' => false, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject || $invalidLocale) {
        $respondBulk(['success' => false, 'message' => 'Bạn không có quyền cập nhật bản dịch phiên bản này.'], 403);
    }
    if ($isVersionFrozen) {
        $respondBulk(['success' => false, 'message' => 'Phiên bản đã chốt; không thể sửa bản dịch.'], 409);
    }

    $selectAll = $payload['select_all'] ?? false;
    if (!is_bool($selectAll)) {
        $respondBulk(['success' => false, 'message' => 'Phạm vi chọn hàng loạt không hợp lệ.'], 400);
    }
    if ($selectAll) {
        $globalSearch = $payload['search'] ?? null;
        $globalFilterStatus = $payload['filter_status'] ?? null;
        $globalStatus = $payload['new_status'] ?? null;
        $requestedProjectId = filter_var($payload['project_id'] ?? null, FILTER_VALIDATE_INT);
        $requestedVersionId = filter_var($payload['version_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            ($payload['statusOnly'] ?? false) !== true ||
            !is_string($globalFilterStatus) ||
            !in_array($globalFilterStatus, ['', 'draft', 'translated', 'approved', 'rejected', 'needs_review'], true) ||
            !is_string($globalSearch) ||
            strlen($globalSearch) > 500 ||
            !is_string($globalStatus) ||
            !in_array($globalStatus, ['draft', 'approved', 'rejected', 'needs_review'], true) ||
            $requestedProjectId !== $projectId ||
            $requestedVersionId !== (int)$currentVersion['id'] ||
            !is_string($payload['locale'] ?? null) ||
            $payload['locale'] !== $currentLocale
        ) {
            $respondBulk(['success' => false, 'message' => 'Dữ liệu cập nhật toàn bộ kết quả không hợp lệ.'], 400);
        }
        if (
            !ProjectAccess::canEditTranslation($conn, $projectId, (int)$currentUser['id']) ||
            !ProjectAccess::canSetTranslationStatus(
                $conn,
                $projectId,
                (int)$currentUser['id'],
                $globalStatus
            )
        ) {
            $respondBulk(['success' => false, 'message' => 'Vai trò của bạn không được phép cập nhật trạng thái này.'], 403);
        }

        $filterParams = [(int)$currentVersion['id'], $projectId, $currentLocale];
        $filterConditions = ['s.source_version_id = $1', 's.project_id = $2'];
        $globalSearch = trim($globalSearch);
        if ($globalSearch !== '') {
            $filterParams[] = '%' . $globalSearch . '%';
            $searchParameter = '$' . count($filterParams);
            $filterConditions[] = "(s.key_path ILIKE {$searchParameter}
                OR s.source_text ILIKE {$searchParameter}
                OR COALESCE(t.translated_text, '') ILIKE {$searchParameter})";
        }
        if (in_array($globalFilterStatus, ['draft', 'translated'], true)) {
            $filterConditions[] = "COALESCE(t.status, 'draft') IN ('draft', 'translated')";
        } elseif ($globalFilterStatus !== '') {
            $filterConditions[] = "COALESCE(t.status, 'draft') = " . match ($globalFilterStatus) {
                'approved' => "'approved'",
                'rejected' => "'rejected'",
                'needs_review' => "'needs_review'",
            };
        }
        $globalWhere = implode(' AND ', $filterConditions);
        $globalFrom = 'FROM strings s
                       LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $3
                       WHERE ' . $globalWhere;

        if (pg_query($conn, 'BEGIN') === false) {
            $respondBulk(['success' => false, 'message' => 'Không thể bắt đầu cập nhật toàn bộ kết quả.'], 500);
        }
        try {
            queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
            $matchedResult = queryParams($conn, "SELECT COUNT(*) {$globalFrom}", $filterParams);
            $matchedCount = (int)pg_fetch_result($matchedResult, 0, 0);

            $actorParameter = '$' . (count($filterParams) + 1);
            $actorParams = $filterParams;
            $actorParams[] = (int)$currentUser['id'];
            $unlockedCondition = "NOT EXISTS (
                SELECT 1 FROM translation_locks l
                WHERE l.string_id = s.id AND l.locale = $3
                  AND l.expires_at > clock_timestamp() AND l.user_id <> {$actorParameter}
            )";
            $eligibleResult = queryParams(
                $conn,
                "SELECT COUNT(*) {$globalFrom} AND {$unlockedCondition}",
                $actorParams
            );
            $eligibleCount = (int)pg_fetch_result($eligibleResult, 0, 0);

            $statusParameter = '$' . (count($actorParams) + 1);
            $writeParams = $actorParams;
            $writeParams[] = $globalStatus;
            $priorOwners = [];
            if (in_array($globalStatus, ['approved', 'rejected', 'needs_review'], true)) {
                $ownersResult = queryParams(
                    $conn,
                    "SELECT DISTINCT t.user_id
                     {$globalFrom}
                       AND t.status IS DISTINCT FROM {$statusParameter}
                       AND t.user_id IS NOT NULL AND t.user_id <> {$actorParameter}
                       AND {$unlockedCondition}",
                    $writeParams
                );
                while ($owner = pg_fetch_assoc($ownersResult)) {
                    $priorOwners[(int)$owner['user_id']] = true;
                }
            }

            $updatedResult = queryParams(
                $conn,
                "INSERT INTO translations
                    (string_id, locale, translated_text, status, updated_at, user_id)
                 SELECT s.id, $3, '', {$statusParameter}, clock_timestamp(), {$actorParameter}
                 FROM strings s
                 LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $3
                 WHERE {$globalWhere} AND {$unlockedCondition}
                 ON CONFLICT (string_id, locale) DO UPDATE
                 SET status = EXCLUDED.status,
                     updated_at = clock_timestamp(),
                     user_id = EXCLUDED.user_id
                 WHERE translations.status IS DISTINCT FROM EXCLUDED.status
                 RETURNING id",
                $writeParams
            );
            $changedTranslationIds = [];
            while ($updatedRow = pg_fetch_assoc($updatedResult)) {
                $changedTranslationIds[] = (int)$updatedRow['id'];
            }
            $updatedCount = count($changedTranslationIds);
            if (
                $changedTranslationIds !== [] &&
                in_array($globalStatus, ['approved', 'rejected'], true)
            ) {
                $translationIdArray = '{' . implode(',', $changedTranslationIds) . '}';
                queryParams(
                    $conn,
                    'INSERT INTO reviews (translation_id, reviewer_id, status, comment)
                     SELECT changed.id, $2, $3, $4
                     FROM unnest($1::INT[]) AS changed(id)',
                    [
                        $translationIdArray,
                        (int)$currentUser['id'],
                        $globalStatus,
                        'Review hàng loạt từ giao diện TMS',
                    ]
                );
            }
            if ($priorOwners !== []) {
                $recipientArray = '{' . implode(',', array_keys($priorOwners)) . '}';
                $notificationMessage = $globalStatus === 'approved'
                    ? 'Bản dịch của bạn đã được duyệt.'
                    : 'Bản dịch của bạn cần được xem xét và chỉnh sửa.';
                queryParams(
                    $conn,
                    'INSERT INTO notifications (user_id, message)
                     SELECT recipients.user_id, $2
                     FROM unnest($1::INT[]) AS recipients(user_id)',
                    [$recipientArray, $notificationMessage]
                );
            }
            (new AuditLogger($conn))->log(
                $projectId,
                null,
                'BULK_UPDATE_TRANSLATIONS',
                "User [{$currentUser['username']}] cập nhật hàng loạt [{$currentLocale}]: "
                . "trạng thái [{$globalStatus}], cập nhật [{$updatedCount}] bản ghi trong kết quả lọc"
            );
            if (pg_query($conn, 'COMMIT') === false) {
                throw new RuntimeException('GLOBAL_BULK_TRANSLATION_COMMIT_FAILED');
            }
        } catch (Throwable $exception) {
            pg_query($conn, 'ROLLBACK');
            error_log('GLOBAL_BULK_TRANSLATION_FAILED: ' . $exception->getMessage());
            $respondBulk(['success' => false, 'message' => 'Không thể cập nhật trạng thái cho toàn bộ kết quả.'], 500);
        }

        if ($updatedCount > 0 && $globalStatus === 'approved') {
            try {
                $tmRepository->exportProject($projectId, $projectName);
            } catch (Throwable $repositoryException) {
                error_log('TM_REPOSITORY_AUTO_EXPORT_FAILED: ' . $repositoryException->getMessage());
            }
        }
        $lockedCount = max(0, $matchedCount - $eligibleCount);
        $respondBulk([
            'success' => true,
            'matchedCount' => $matchedCount,
            'eligibleCount' => $eligibleCount,
            'updatedCount' => $updatedCount,
            'skippedLockedCount' => $lockedCount,
            'message' => 'Đã cập nhật thành công toàn bộ kết quả',
        ]);
    }

    $requestedRows = $payload['translations'] ?? null;
    if (
        !is_array($requestedRows) ||
        !array_is_list($requestedRows) ||
        count($requestedRows) < 1 ||
        count($requestedRows) > 100
    ) {
        $respondBulk(['success' => false, 'message' => 'Vui lòng chọn từ 1 đến 100 dòng để lưu.'], 400);
    }

    $validatedRows = [];
    $statusOnly = ($payload['statusOnly'] ?? false) === true;
    $seenStringIds = [];
    $approvedSaved = false;
    foreach ($requestedRows as $requestedRow) {
        if (!is_array($requestedRow)) {
            $respondBulk(['success' => false, 'message' => 'Dữ liệu một hoặc nhiều dòng không hợp lệ.'], 400);
        }
        $stringId = filter_var($requestedRow['stringId'] ?? null, FILTER_VALIDATE_INT);
        $translatedText = $requestedRow['translatedText'] ?? null;
        $status = $requestedRow['status'] ?? null;
        $baseVersion = $requestedRow['base_version'] ?? null;
        if (
            $stringId === false || $stringId === null || $stringId < 1 ||
            isset($seenStringIds[$stringId]) ||
            !array_key_exists('base_version', $requestedRow) ||
            ($baseVersion !== null && (!is_string($baseVersion) || strlen($baseVersion) > 40)) ||
            (!$statusOnly && (!is_string($translatedText) || strlen($translatedText) > 100000)) ||
            ($statusOnly && $translatedText !== null && (!is_string($translatedText) || strlen($translatedText) > 100000)) ||
            !is_string($status)
        ) {
            $respondBulk(['success' => false, 'message' => 'Dữ liệu một hoặc nhiều dòng không hợp lệ.'], 400);
        }
        $seenStringIds[$stringId] = true;
        $validatedRows[] = [
            'stringId' => (int)$stringId,
            'translatedText' => $translatedText,
            'submittedText' => $translatedText,
            'status' => $status,
            'baseVersion' => $baseVersion === '' ? null : $baseVersion,
        ];
    }

    $preparedRows = [];
    foreach ($validatedRows as $row) {
        if (!in_array($row['status'], ['draft', 'approved', 'rejected', 'needs_review'], true)) {
            $respondBulk(['success' => false, 'message' => 'Trạng thái bản dịch không hợp lệ.'], 400);
        }
        $sourceResult = queryParams(
            $conn,
            'SELECT project_id, key_path, source_text FROM strings
             WHERE id = $1 AND source_version_id = $2',
            [$row['stringId'], (int)$currentVersion['id']]
        );
        $sourceRow = pg_fetch_assoc($sourceResult);
        if (!$sourceRow || (int)$sourceRow['project_id'] !== $projectId) {
            $respondBulk(['success' => false, 'message' => 'Một dòng được chọn không thuộc phiên bản hiện tại.'], 404);
        }
        $lockResult = queryParams(
            $conn,
            'SELECT user_id FROM translation_locks
             WHERE string_id = $1 AND locale = $2 AND expires_at > clock_timestamp()',
            [$row['stringId'], $currentLocale]
        );
        $activeLock = pg_fetch_assoc($lockResult);
        if ($activeLock && (int)$activeLock['user_id'] !== (int)$currentUser['id']) {
            $respondBulk(['success' => false, 'message' => 'Một dòng đang được thành viên khác chỉnh sửa.'], 409);
        }
        if (!ProjectAccess::canEditTranslation($conn, $projectId, (int)$currentUser['id'])) {
            $respondBulk(['success' => false, 'message' => 'Bạn không có quyền chỉnh sửa bản dịch trong dự án này.'], 403);
        }
        $priorResult = queryParams(
            $conn,
            'SELECT translated_text, status FROM translations
             WHERE string_id = $1 AND locale = $2',
            [$row['stringId'], $currentLocale]
        );
        $priorRow = pg_fetch_assoc($priorResult);
        if ($statusOnly) {
            $row['translatedText'] = (string)($priorRow['translated_text'] ?? '');
        } elseif (
            $row['status'] === 'approved' &&
            (!$priorRow || $priorRow['translated_text'] !== $row['translatedText'])
        ) {
            $row['status'] = 'needs_review';
        }
        $canSetStatus = ProjectAccess::canSetTranslationStatus(
            $conn,
            $projectId,
            (int)$currentUser['id'],
            $row['status']
        );
        $preservingUnchangedStatus = $priorRow &&
            $priorRow['status'] === $row['status'] &&
            $priorRow['translated_text'] === $row['translatedText'];
        if (!$canSetStatus && !$preservingUnchangedStatus) {
            $respondBulk(['success' => false, 'message' => 'Vai trò của bạn không được phép chọn trạng thái này.'], 403);
        }
        if (!$statusOnly && !VariableValidator::validate($sourceRow['source_text'], $row['translatedText'])) {
            $respondBulk([
                'success' => false,
                'message' => 'Bản dịch phải giữ nguyên các biến trong chuỗi nguồn.',
                'stringId' => $row['stringId'],
            ], 422);
        }
        $row['sourceText'] = $sourceRow['source_text'];
        $row['keyPath'] = $sourceRow['key_path'];
        $preparedRows[] = $row;
    }

    if (pg_query($conn, 'BEGIN') === false) {
        $respondBulk(['success' => false, 'message' => 'Không thể bắt đầu lưu các dòng đã chọn.'], 500);
    }
    try {
        queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
        $savedRows = [];
        $auditChanges = [];
        foreach ($preparedRows as $row) {
            queryParams(
                $conn,
                'SELECT pg_advisory_xact_lock($1, hashtext($2))',
                [$row['stringId'], $currentLocale]
            );
            $priorResult = queryParams(
                $conn,
                'SELECT t.id, t.user_id, t.status, t.translated_text,
                        to_char(t.updated_at AT TIME ZONE \'UTC\',
                                \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                        u.username AS updated_by
                 FROM translations t
                 LEFT JOIN users u ON u.id = t.user_id
                 WHERE t.string_id = $1 AND t.locale = $2 FOR UPDATE OF t',
                [$row['stringId'], $currentLocale]
            );
            $priorTranslation = pg_fetch_assoc($priorResult);
            if (($priorTranslation['updated_at'] ?? null) !== $row['baseVersion']) {
                pg_query($conn, 'ROLLBACK');
                $respondBulk([
                    'success' => false,
                    'error_code' => 'CONFLICT',
                    'message' => 'Cảnh báo xung đột: Bản dịch đã được cập nhật bởi người dùng khác từ phiên bản sau. Vui lòng tải lại dữ liệu mới nhất để xem thay đổi.',
                    'conflicts' => [[
                        'stringId' => $row['stringId'],
                        'keyPath' => $row['keyPath'],
                        'updatedBy' => $priorTranslation['updated_by'] ?? null,
                        'updatedAt' => $priorTranslation['updated_at'] ?? null,
                        'currentText' => $priorTranslation['translated_text'] ?? null,
                        'currentStatus' => $priorTranslation['status'] ?? null,
                        'submittedText' => $row['submittedText'] ?? '',
                        'submittedStatus' => $row['status'],
                    ]],
                ], 409);
            }
            if ($statusOnly) {
                $row['translatedText'] = (string)($priorTranslation['translated_text'] ?? '');
            }
            if ($priorTranslation) {
                $saveResult = queryParams(
                    $conn,
                    "UPDATE translations
                     SET translated_text = $1, status = $2, updated_at = clock_timestamp(), user_id = $3
                     WHERE id = $4
                     RETURNING id,
                               to_char(updated_at AT TIME ZONE 'UTC',
                                       'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
                    [
                        $row['translatedText'],
                        $row['status'],
                        (int)$currentUser['id'],
                        (int)$priorTranslation['id'],
                    ]
                );
            } else {
                $saveResult = queryParams(
                    $conn,
                    "INSERT INTO translations
                        (string_id, locale, translated_text, status, updated_at, user_id)
                     VALUES ($1, $2, $3, $4, clock_timestamp(), $5)
                     ON CONFLICT (string_id, locale) DO NOTHING
                     RETURNING id,
                               to_char(updated_at AT TIME ZONE 'UTC',
                                       'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
                    [
                        $row['stringId'],
                        $currentLocale,
                        $row['translatedText'],
                        $row['status'],
                        (int)$currentUser['id'],
                    ]
                );
            }
            $saved = pg_fetch_assoc($saveResult);
            if (!$saved) {
                $currentResult = queryParams(
                    $conn,
                    'SELECT t.translated_text, t.status,
                            to_char(t.updated_at AT TIME ZONE \'UTC\',
                                    \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                            u.username AS updated_by
                     FROM translations t
                     LEFT JOIN users u ON u.id = t.user_id
                     WHERE t.string_id = $1 AND t.locale = $2 FOR UPDATE OF t',
                    [$row['stringId'], $currentLocale]
                );
                $currentTranslation = pg_fetch_assoc($currentResult);
                pg_query($conn, 'ROLLBACK');
                $respondBulk([
                    'success' => false,
                    'error_code' => 'CONFLICT',
                    'message' => 'Cảnh báo xung đột: Bản dịch đã được cập nhật bởi người dùng khác từ phiên bản sau. Vui lòng tải lại dữ liệu mới nhất để xem thay đổi.',
                    'conflicts' => [[
                        'stringId' => $row['stringId'],
                        'keyPath' => $row['keyPath'],
                        'updatedBy' => $currentTranslation['updated_by'] ?? null,
                        'updatedAt' => $currentTranslation['updated_at'] ?? null,
                        'currentText' => $currentTranslation['translated_text'] ?? null,
                        'currentStatus' => $currentTranslation['status'] ?? null,
                        'submittedText' => $row['submittedText'] ?? '',
                        'submittedStatus' => $row['status'],
                    ]],
                ], 409);
            }
            $unchangedTranslation = $priorTranslation &&
                $priorTranslation['status'] === $row['status'] &&
                $priorTranslation['translated_text'] === $row['translatedText'];
            $approvedSaved = $approvedSaved || $row['status'] === 'approved';
            if (!$unchangedTranslation) {
                $auditChanges[] = sprintf(
                    'key %d: nội dung [%s] → [%s], trạng thái [%s] → [%s]',
                    $row['stringId'],
                    ($priorTranslation['translated_text'] ?? $row['sourceText']) !== '' ? ($priorTranslation['translated_text'] ?? $row['sourceText']) : '(trống)',
                    $row['translatedText'] !== '' ? $row['translatedText'] : '(trống)',
                    $priorTranslation['status'] ?? 'chưa có',
                    $row['status']
                );
            }
            (new TranslationMemory($conn))->saveToMemory(
                $projectId,
                $currentLocale,
                $row['sourceText'],
                $row['translatedText']
            );
            if (!$unchangedTranslation && in_array($row['status'], ['approved', 'rejected'], true)) {
                queryParams(
                    $conn,
                    'INSERT INTO reviews (translation_id, reviewer_id, status, comment)
                     VALUES ($1, $2, $3, $4)',
                    [(int)$saved['id'], (int)$currentUser['id'], $row['status'], 'Review hàng loạt từ giao diện TMS']
                );
            }
            if (
                !$unchangedTranslation &&
                in_array($row['status'], ['approved', 'needs_review', 'rejected'], true) &&
                $priorTranslation &&
                $priorTranslation['user_id'] !== null &&
                (int)$priorTranslation['user_id'] !== (int)$currentUser['id'] &&
                $priorTranslation['status'] !== $row['status']
            ) {
                $reviewMessage = $row['status'] === 'approved'
                    ? 'Bản dịch của bạn đã được duyệt.'
                    : 'Bản dịch của bạn cần được xem xét và chỉnh sửa.';
                NotificationManager::create($conn, (int)$priorTranslation['user_id'], $reviewMessage, 'comments');
            }
            $savedRows[] = [
                'stringId' => $row['stringId'],
                'keyPath' => $row['keyPath'],
                'translatedText' => $statusOnly
                    ? ($priorTranslation['translated_text'] ?? '')
                    : $row['translatedText'],
                'status' => $row['status'],
                'updatedAt' => $saved['updated_at'],
            ];
        }
        (new AuditLogger($conn))->log(
            $projectId,
            null,
            'BULK_UPDATE_TRANSLATIONS',
            "User [{$currentUser['username']}] cập nhật hàng loạt [{$currentLocale}]: "
            . ($auditChanges !== [] ? implode('; ', $auditChanges) : 'không có thay đổi')
        );
        if (pg_query($conn, 'COMMIT') === false) {
            throw new RuntimeException('BULK_TRANSLATION_COMMIT_FAILED');
        }
        if ($approvedSaved) {
            try {
                $tmRepository->exportProject($projectId, $projectName);
            } catch (Throwable $repositoryException) {
                error_log('TM_REPOSITORY_AUTO_EXPORT_FAILED: ' . $repositoryException->getMessage());
            }
        }
        $respondBulk(['success' => true, 'translations' => $savedRows, 'message' => 'Đã lưu các dòng được chọn.']);
    } catch (Throwable $exception) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
            pg_query($conn, 'ROLLBACK');
        }
        error_log('BULK_TRANSLATION_SAVE_FAILED: ' . $exception->getMessage());
        $respondBulk(['success' => false, 'message' => 'Không thể lưu các dòng đã chọn do lỗi cơ sở dữ liệu.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_translate_text') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respond(['success' => false, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject || $isVersionFrozen || $invalidLocale) {
        $respond(['success' => false, 'message' => 'Bạn không có quyền hoặc phiên bản không thể dịch AI.'], 403);
    }

    $stringId = filter_var($_POST['string_id'] ?? 0, FILTER_VALIDATE_INT);
    $sourceText = trim((string)($_POST['source_text'] ?? ''));
    if ($stringId === false || $stringId < 1 || $sourceText === '' || strlen($sourceText) > 50000) {
        $respond(['success' => false, 'message' => 'Văn bản nguồn không hợp lệ.'], 400);
    }
    try {
        $sourceResult = queryParams(
            $conn,
            'SELECT source_text FROM strings WHERE id = $1 AND source_version_id = $2',
            [(int)$stringId, (int)$currentVersion['id']]
        );
        $sourceRow = pg_fetch_assoc($sourceResult);
        if (!$sourceRow || (string)$sourceRow['source_text'] !== $sourceText) {
            $respond(['success' => false, 'message' => 'Văn bản nguồn không thuộc phiên bản hiện tại.'], 400);
        }
        $translatedText = (new AiTranslator($conn))->translateText($sourceText, $currentLocale);
        $respond([
            'success' => true,
            'string_id' => (int)$stringId,
            'source_text' => $sourceText,
            'target_text' => $translatedText,
            'locale' => $currentLocale,
        ]);
    } catch (Throwable $exception) {
        error_log('AI_TRANSLATION_TEXT_FAILED: ' . $exception->getMessage());
        $respond(['success' => false, 'message' => 'Không thể dịch văn bản lúc này. Hãy kiểm tra cấu hình AI hoặc thử lại.'], 502);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'google_translate_text') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    try {
        if (!Csrf::isValid($_POST)) {
            $respond(['success' => false, 'translated_text' => null, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
        }
        if (!$currentVersion || !$canTranslateCurrentProject || $isVersionFrozen || $invalidLocale) {
            $respond(['success' => false, 'translated_text' => null, 'message' => 'Bạn không có quyền hoặc phiên bản không thể dịch.'], 403);
        }
        $stringId = filter_var($_POST['string_id'] ?? 0, FILTER_VALIDATE_INT);
        $sourceText = trim((string)($_POST['source_text'] ?? ''));
        $sourceLocale = strtolower(trim((string)($_POST['source_locale'] ?? (getenv('TMS_SOURCE_LOCALE') ?: 'vi'))));
        $requestedTargetLocale = strtolower(trim((string)($_POST['target_locale'] ?? $currentLocale)));
        $translatorProvider = strtolower(trim((string)($_POST['translator_provider'] ?? TranslationProviderService::MYMEMORY)));
        if (!in_array($translatorProvider, [
            TranslationProviderService::MYMEMORY,
            TranslationProviderService::GOOGLE,
            TranslationProviderService::LIBRETRANSLATE,
        ], true)) {
            $respond(['success' => false, 'translated_text' => null, 'message' => 'Nhà cung cấp dịch không hợp lệ.'], 400);
        }
        if ($requestedTargetLocale !== strtolower($currentLocale)) {
            $respond([
                'success' => false,
                'translated_text' => null,
                'message' => 'Ngôn ngữ đích không khớp với ngôn ngữ đang chọn.',
            ], 400);
        }
        if ($stringId === false || $stringId < 1 || $sourceText === '' || strlen($sourceText) > 50000) {
            $respond(['success' => false, 'translated_text' => null, 'message' => 'Văn bản nguồn không hợp lệ.'], 400);
        }
        $sourceResult = queryParams(
            $conn,
            'SELECT source_text FROM strings WHERE id = $1 AND source_version_id = $2',
            [(int)$stringId, (int)$currentVersion['id']]
        );
        $sourceRow = pg_fetch_assoc($sourceResult);
        if (!$sourceRow || (string)$sourceRow['source_text'] !== $sourceText) {
            $respond(['success' => false, 'translated_text' => null, 'message' => 'Văn bản nguồn không thuộc phiên bản hiện tại.'], 400);
        }
        $existingTranslationResult = queryParams(
            $conn,
            'SELECT translated_text FROM translations WHERE string_id = $1 AND locale = $2',
            [(int)$stringId, $currentLocale]
        );
        $existingTranslation = pg_fetch_assoc($existingTranslationResult);
        if ($existingTranslation && trim((string)$existingTranslation['translated_text']) !== '') {
            $progressResult = queryParams(
                $conn,
                "SELECT COUNT(s.id)::int AS total,
                        COUNT(*) FILTER (
                            WHERE COALESCE(NULLIF(BTRIM(t.translated_text), ''), '') <> ''
                        )::int AS translated
                 FROM strings s
                 LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
                 WHERE s.source_version_id = $1",
                [(int)$currentVersion['id'], $currentLocale]
            );
            $progress = pg_fetch_assoc($progressResult);
            $respond([
                'success' => true,
                'translated_text' => (string)$existingTranslation['translated_text'],
                'target_text' => (string)$existingTranslation['translated_text'],
                'string_id' => (int)$stringId,
                'locale' => $currentLocale,
                'provider' => $translatorProvider,
                'skipped' => true,
                'progress_total' => (int)($progress['total'] ?? 0),
                'progress_translated' => (int)($progress['translated'] ?? 0),
            ]);
        }
        $translatedText = (new TranslationProviderService(new MyMemoryTranslationService()))
            ->translate($translatorProvider, $sourceText, $sourceLocale, $currentLocale);
        $respond([
            'success' => true,
            'translated_text' => $translatedText,
            'target_text' => $translatedText,
            'string_id' => (int)$stringId,
            'locale' => $currentLocale,
            'provider' => $translatorProvider,
        ]);
    } catch (Throwable $exception) {
        error_log('GOOGLE_TRANSLATION_TEXT_FAILED: ' . $exception->getMessage());
        $errorCode = $exception->getMessage();
        $providerLabel = match ($translatorProvider ?? TranslationProviderService::MYMEMORY) {
            TranslationProviderService::GOOGLE => 'Google Translate',
            TranslationProviderService::LIBRETRANSLATE => 'LibreTranslate',
            default => 'MyMemory',
        };
        $isRateLimited = $exception->getCode() === 429 ||
            str_contains($errorCode, 'HTTP 429') ||
            str_contains($errorCode, 'QUOTA_EXCEEDED');
        $message = match ($exception->getMessage()) {
            'MYMEMORY_CURL_MISSING' => 'Máy chủ chưa bật PHP cURL.',
            'MYMEMORY_HTTP_ERROR' => 'MyMemory trả về lỗi HTTP.',
            'MYMEMORY_INVALID_JSON' => 'MyMemory trả về dữ liệu JSON không hợp lệ.',
            'MYMEMORY_LOCALE_INVALID' => 'Mã ngôn ngữ nguồn hoặc đích không hợp lệ.',
            'MYMEMORY_EMAIL_INVALID' => 'Email MyMemory trong cấu hình không hợp lệ.',
            default => 'MyMemory không khả dụng lúc này. Vui lòng thử lại.',
        };
        if ($isRateLimited) {
            $message = $providerLabel . ' đang giới hạn tần suất hoặc đã hết quota (HTTP 429). Vui lòng đổi nhà cung cấp dịch hoặc thử lại sau.';
        }
        $safeDetails = preg_replace('/([?&](?:key|token|api_key)=)[^&\s]+/i', '$1[redacted]', $errorCode) ?? $errorCode;
        if (!$isRateLimited && !in_array($exception->getMessage(), [
            'MYMEMORY_TEXT_INVALID',
            'MYMEMORY_LOCALE_INVALID',
            'MYMEMORY_EMAIL_INVALID',
            'MYMEMORY_CURL_MISSING',
        ], true)) {
            $respond([
                'success' => true,
                'translated_text' => $sourceText,
                'target_text' => $sourceText,
                'string_id' => (int)$stringId,
                'locale' => $currentLocale,
                'provider' => 'mymemory',
                'fallback' => true,
                'message' => $providerLabel . ' tạm thời không khả dụng; giữ nguyên văn bản nguồn và tiếp tục.',
            ]);
        }
        $respond([
            'success' => false,
            'translated_text' => null,
            'message' => $message,
            'error_code' => get_class($exception),
            'retryable' => $isRateLimited,
            'retry_after_seconds' => $isRateLimited ? 5 : null,
            'details' => $safeDetails,
        ], $isRateLimited ? 429 : 502);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'google_translate_pending') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respond(['success' => false, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject || $isVersionFrozen || $invalidLocale) {
        $respond(['success' => false, 'message' => 'Bạn không có quyền hoặc phiên bản không thể dịch.'], 403);
    }
    try {
        $progressResult = queryParams(
            $conn,
            "SELECT COUNT(s.id)::int AS total,
                    COUNT(*) FILTER (
                        WHERE COALESCE(NULLIF(BTRIM(t.translated_text), ''), '') <> ''
                    )::int AS translated
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1",
            [(int)$currentVersion['id'], $currentLocale]
        );
        $progress = pg_fetch_assoc($progressResult);
        $total = (int)($progress['total'] ?? 0);
        $translated = (int)($progress['translated'] ?? 0);
        $rows = [];
        if (($_POST['progress_only'] ?? '') !== '1') {
            $pendingResult = queryParams(
                $conn,
                "SELECT s.id AS string_id, s.source_text
                 FROM strings s
                 LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
                 WHERE s.source_version_id = $1
                   AND COALESCE(NULLIF(BTRIM(t.translated_text), ''), '') = ''
                 ORDER BY s.id",
                [(int)$currentVersion['id'], $currentLocale]
            );
            while ($row = pg_fetch_assoc($pendingResult)) {
                $rows[] = [
                    'stringId' => (int)$row['string_id'],
                    'sourceText' => (string)$row['source_text'],
                ];
            }
        }
        $respond([
            'success' => true,
            'rows' => $rows,
            'total' => $total,
            'translated' => $translated,
            'percent' => $total > 0 ? (int)round($translated / $total * 100) : 100,
        ]);
    } catch (Throwable $exception) {
        error_log('GOOGLE_TRANSLATION_PENDING_FAILED: ' . $exception->getMessage());
        $respond(['success' => false, 'message' => 'Không thể tải danh sách chuỗi chưa dịch.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'google_translate_save') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respond(['success' => false, 'message' => 'Phiên làm việc không hợp lệ.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject || $isVersionFrozen || $invalidLocale) {
        $respond(['success' => false, 'message' => 'Bạn không có quyền cập nhật bản dịch phiên bản này.'], 403);
    }
    $stringId = filter_var($_POST['string_id'] ?? null, FILTER_VALIDATE_INT);
    $translatedText = (string)($_POST['translated_text'] ?? '');
    if ($stringId === false || $stringId < 1 || trim($translatedText) === '' || strlen($translatedText) > 100000) {
        $respond(['success' => false, 'message' => 'Bản dịch không hợp lệ.'], 400);
    }
    try {
        $sourceResult = queryParams(
            $conn,
            'SELECT source_text FROM strings WHERE id = $1 AND source_version_id = $2',
            [(int)$stringId, (int)$currentVersion['id']]
        );
        $source = pg_fetch_assoc($sourceResult);
        if (!$source || !VariableValidator::validate((string)$source['source_text'], $translatedText)) {
            $respond(['success' => false, 'message' => 'Bản dịch không hợp lệ hoặc không thuộc phiên bản hiện tại.'], 400);
        }
        $savedResult = queryParams(
            $conn,
            "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
             VALUES ($1, $2, $3, 'draft', $4)
             ON CONFLICT (string_id, locale) DO UPDATE
             SET translated_text = EXCLUDED.translated_text,
                 status = 'draft', updated_at = clock_timestamp(), user_id = EXCLUDED.user_id
             WHERE COALESCE(NULLIF(BTRIM(translations.translated_text), ''), '') = ''
             RETURNING translated_text,
                       to_char(updated_at AT TIME ZONE 'UTC',
                               'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
            [(int)$stringId, $currentLocale, $translatedText, (int)$currentUser['id']]
        );
        $saved = pg_fetch_assoc($savedResult);
        if ($saved) {
            $respond([
                'success' => true,
                'translated_text' => (string)$saved['translated_text'],
                'updated_at' => $saved['updated_at'],
                'skipped' => false,
            ]);
        }
        $existingResult = queryParams(
            $conn,
            'SELECT translated_text FROM translations WHERE string_id = $1 AND locale = $2',
            [(int)$stringId, $currentLocale]
        );
        $existing = pg_fetch_assoc($existingResult);
        if (!$existing || trim((string)$existing['translated_text']) === '') {
            throw new RuntimeException('MYMEMORY_TRANSLATION_SAVE_FAILED');
        }
        $progressResult = queryParams(
            $conn,
            "SELECT COUNT(s.id)::int AS total,
                    COUNT(*) FILTER (
                        WHERE COALESCE(NULLIF(BTRIM(t.translated_text), ''), '') <> ''
                    )::int AS translated
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1",
            [(int)$currentVersion['id'], $currentLocale]
        );
        $progress = pg_fetch_assoc($progressResult);
        $respond([
            'success' => true,
            'translated_text' => (string)$existing['translated_text'],
            'skipped' => true,
            'progress_total' => (int)($progress['total'] ?? 0),
            'progress_translated' => (int)($progress['translated'] ?? 0),
        ]);
    } catch (Throwable $exception) {
        error_log('GOOGLE_TRANSLATION_SAVE_FAILED: ' . $exception->getMessage());
        $respond(['success' => false, 'message' => 'Không thể lưu bản dịch tự động.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'google_translate_batch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!Csrf::isValid($_POST)) {
        $respond(['success' => false, 'translated_text' => null, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject || $isVersionFrozen || $invalidLocale) {
        $respond(['success' => false, 'translated_text' => null, 'message' => 'Bạn không có quyền hoặc phiên bản không thể dịch.'], 403);
    }
    $forcePlaceholderSave = $_POST['force_placeholders'] ?? '0';
    if (!in_array($forcePlaceholderSave, ['0', '1'], true)) {
        $respond(['success' => false, 'message' => 'Tùy chọn bỏ qua kiểm tra biến không hợp lệ.'], 400);
    }
    $forcePlaceholderSave = $forcePlaceholderSave === '1';
    $batchRows = json_decode((string)($_POST['rows'] ?? ''), true);
    if (!is_array($batchRows) || !array_is_list($batchRows) || count($batchRows) < 1 || count($batchRows) > 20) {
        $respond(['success' => false, 'message' => 'Gói dịch phải có từ 1 đến 20 câu.'], 400);
    }
    $stringIds = [];
    foreach ($batchRows as $batchRow) {
        $stringId = is_array($batchRow)
            ? filter_var($batchRow['stringId'] ?? null, FILTER_VALIDATE_INT)
            : false;
        if ($stringId === false || $stringId < 1 || in_array((int)$stringId, $stringIds, true)) {
            $respond(['success' => false, 'message' => 'Danh sách câu trong gói dịch không hợp lệ.'], 400);
        }
        $stringIds[] = (int)$stringId;
    }
    $batchSourceLocale = strtolower(trim((string)(getenv('TMS_SOURCE_LOCALE') ?: 'vi')));
    $batchTargetLocale = strtolower(trim((string)($_POST['target_locale'] ?? $currentLocale)));
    $translatorProvider = strtolower(trim((string)($_POST['translator_provider'] ?? TranslationProviderService::MYMEMORY)));
    if (!in_array($translatorProvider, [
        TranslationProviderService::MYMEMORY,
        TranslationProviderService::GOOGLE,
        TranslationProviderService::LIBRETRANSLATE,
    ], true)) {
        $respond(['success' => false, 'message' => 'Nhà cung cấp dịch không hợp lệ.'], 400);
    }
    if ($batchTargetLocale !== strtolower($currentLocale)) {
        $respond(['success' => false, 'translated_text' => null, 'message' => 'Ngôn ngữ đích không khớp với ngôn ngữ đang chọn.'], 400);
    }
    try {
        $placeholders = [];
        $sourceQueryParams = [(int)$currentVersion['id'], $currentLocale];
        foreach ($stringIds as $index => $stringId) {
            $placeholders[] = '$' . ($index + 3);
            $sourceQueryParams[] = $stringId;
        }
        $sourceResult = queryParams(
            $conn,
            "SELECT s.id AS string_id, s.source_text, t.translated_text
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1
               AND s.id IN (" . implode(', ', $placeholders) . ')',
            $sourceQueryParams
        );
        $sourceRows = [];
        while ($row = pg_fetch_assoc($sourceResult)) {
            $sourceRows[(int)$row['string_id']] = $row;
        }
        $translator = new TranslationProviderService(new MyMemoryTranslationService());
        $translated = [];
        $errors = [];
        $warnings = [];
        $rateLimited = false;
        foreach ($stringIds as $index => $stringId) {
            $row = $sourceRows[$stringId] ?? null;
            if (!$row) {
                $errors[] = ['stringId' => $stringId, 'message' => 'Câu không thuộc phiên bản hiện tại.'];
                continue;
            }
            if (trim((string)($row['translated_text'] ?? '')) !== '') {
                $translated[] = [
                    'stringId' => $stringId,
                    'translatedText' => (string)$row['translated_text'],
                    'skipped' => true,
                ];
                continue;
            }

            try {
                $targetText = $translator->translate(
                    $translatorProvider,
                    (string)$row['source_text'],
                    $batchSourceLocale,
                    $batchTargetLocale
                );
            } catch (Throwable $exception) {
                $isRateLimited = $exception->getCode() === 429 ||
                    str_contains($exception->getMessage(), 'HTTP 429') ||
                    str_contains($exception->getMessage(), 'RATE_LIMITED') ||
                    str_contains($exception->getMessage(), 'QUOTA_EXCEEDED');
                $details = preg_replace(
                    '/([?&](?:key|token|api_key)=)[^&\s]+/i',
                    '$1[redacted]',
                    $exception->getMessage()
                ) ?? $exception->getMessage();
                error_log(sprintf('GOOGLE_TRANSLATION_BATCH_ITEM_FAILED string_id=%d: %s', $stringId, $details));
                $errors[] = [
                    'stringId' => $stringId,
                    'message' => $isRateLimited
                        ? sprintf('%s đang giới hạn tần suất hoặc đã hết quota. Hãy đổi nhà cung cấp dịch hoặc thử lại sau.', $translatorProvider)
                        : 'Không thể dịch câu này: ' . $details,
                    'retryable' => $isRateLimited,
                ];
                if ($isRateLimited) {
                    $rateLimited = true;
                    foreach (array_slice($stringIds, $index + 1) as $remainingId) {
                        $errors[] = [
                            'stringId' => $remainingId,
                            'message' => sprintf('Chưa xử lý do %s đang giới hạn tần suất hoặc đã hết quota.', $translatorProvider),
                            'retryable' => true,
                        ];
                    }
                    break;
                }
                continue;
            }

            $placeholdersValid = VariableValidator::validate((string)$row['source_text'], $targetText);
            if (!$placeholdersValid && !$forcePlaceholderSave) {
                $errors[] = [
                    'stringId' => $stringId,
                    'code' => 'PLACEHOLDER_MISMATCH',
                    'forceSaveable' => true,
                    'message' => 'Biến định dạng hoặc thẻ HTML trong bản dịch khác với câu gốc.',
                    'retryable' => false,
                ];
                continue;
            }
            if (!$placeholdersValid) {
                $warnings[] = [
                    'stringId' => $stringId,
                    'message' => 'Đã lưu theo lựa chọn bỏ qua kiểm tra biến định dạng.',
                ];
            }

            $saveResult = queryParams(
                $conn,
                "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
                 VALUES ($1, $2, $3, 'draft', $4)
                 ON CONFLICT (string_id, locale) DO UPDATE
                 SET translated_text = EXCLUDED.translated_text,
                     status = 'draft', updated_at = CURRENT_TIMESTAMP, user_id = EXCLUDED.user_id
                 WHERE COALESCE(NULLIF(BTRIM(translations.translated_text), ''), '') = ''
                 RETURNING translated_text,
                               to_char(updated_at AT TIME ZONE 'UTC',
                                       'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
                [$stringId, $currentLocale, $targetText, (int)$currentUser['id']]
            );
            $saved = pg_fetch_assoc($saveResult);
            if ($saved) {
                $translated[] = [
                    'stringId' => $stringId,
                    'translatedText' => (string)$saved['translated_text'],
                    'updatedAt' => $saved['updated_at'],
                    'skipped' => false,
                ];
            } else {
                $existingResult = queryParams(
                    $conn,
                    'SELECT translated_text FROM translations WHERE string_id = $1 AND locale = $2',
                    [$stringId, $currentLocale]
                );
                $existing = pg_fetch_assoc($existingResult);
                if ($existing && trim((string)$existing['translated_text']) !== '') {
                    $translated[] = [
                        'stringId' => $stringId,
                        'translatedText' => (string)$existing['translated_text'],
                        'skipped' => true,
                    ];
                } else {
                    $errors[] = ['stringId' => $stringId, 'message' => 'Không thể lưu bản dịch câu này.'];
                }
            }
        }

        $progressResult = queryParams(
            $conn,
            "SELECT COUNT(s.id)::int AS total,
                    COUNT(*) FILTER (
                        WHERE COALESCE(NULLIF(BTRIM(t.translated_text), ''), '') <> ''
                    )::int AS translated
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1",
            [(int)$currentVersion['id'], $currentLocale]
        );
        $batchProgress = pg_fetch_assoc($progressResult);
        $respond([
            'success' => true,
            'processed' => count($translated),
            'translations' => $translated,
            'errors' => $errors,
            'warnings' => $warnings,
            'provider' => $translatorProvider,
            'retryable' => $rateLimited,
            'progress_total' => (int)($batchProgress['total'] ?? 0),
            'progress_translated' => (int)($batchProgress['translated'] ?? 0),
        ]);
    } catch (Throwable $exception) {
        error_log('GOOGLE_TRANSLATION_BATCH_FAILED: ' . $exception->getMessage());
        $respond([
            'success' => false,
            'message' => 'Không thể xử lý gói dịch. Các gói đã hoàn tất vẫn được giữ nguyên.',
            'error_code' => get_class($exception),
            'details' => preg_replace(
                '/([?&](?:key|token|api_key)=)[^&\s]+/i',
                '$1[redacted]',
                $exception->getMessage()
            ) ?? $exception->getMessage(),
        ], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_translate_batch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $respond = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };

    if (!Csrf::isValid($_POST)) {
        $respond(['success' => false, 'message' => 'Phiên làm việc không hợp lệ. Hãy tải lại trang.'], 403);
    }
    if (!$currentVersion || !$canTranslateCurrentProject) {
        $respond(['success' => false, 'message' => 'Bạn cần là thành viên của dự án để chạy dịch AI.'], 403);
    }
    if ($invalidLocale) {
        $respond(['success' => false, 'message' => 'Mã ngôn ngữ đích không hợp lệ.'], 400);
    }
    if ($isVersionFrozen) {
        $respond(['success' => false, 'message' => 'Phiên bản đã chốt; không thể chạy dịch AI.'], 409);
    }
    $batchSize = filter_var($_POST['batch_size'] ?? 8, FILTER_VALIDATE_INT);
    if ($batchSize === false || $batchSize < 5 || $batchSize > 10) {
        $respond(['success' => false, 'message' => 'Kích thước batch không hợp lệ.'], 400);
    }

    try {
        $batch = (new AiTranslator($conn))->translateNextBatch(
            (int)$currentVersion['id'],
            $currentLocale,
            (int)$currentUser['id'],
            $batchSize
        );
        if ($batch['processed'] > 0) {
            (new AuditLogger($conn))->log(
                $projectId,
                null,
                'AI_TRANSLATE',
                "User [{$currentUser['username']}] đã tự động dịch {$batch['processed']} chuỗi [{$currentLocale}]"
            );
        }

        $batchError = $batch['error'];
        if ($batchError instanceof Throwable) {
            error_log('AI_TRANSLATION_BATCH_FAILED: ' . $batchError->getMessage());
        }
        $retryableBatchError = $batchError instanceof Throwable && (
            preg_match('/GEMINI_CURL_REQUEST_FAILED/', $batchError->getMessage()) === 1 ||
            preg_match('/GEMINI_API_HTTP_ERROR: HTTP (429|500|502|503|504)\b/', $batchError->getMessage()) === 1
        );
        $respond([
            'success' => !($batchError instanceof Throwable),
            'retryable' => $retryableBatchError,
            'total' => $batch['total'],
            'remaining' => $batch['remaining'],
            'completed' => max(0, $batch['total'] - $batch['remaining']),
            'processed' => $batch['processed'],
            'translations' => $batch['translations'],
            'message' => $batchError instanceof Throwable
                ? 'Gặp sự cố kết nối hoặc dịch thuật với AI.'
                : '',
        ]);
    } catch (Throwable $exception) {
        error_log('AI_TRANSLATION_BATCH_REQUEST_FAILED: ' . $exception->getMessage());
        $respond([
            'success' => false,
            'message' => 'Không thể xử lý batch dịch AI. Hãy kiểm tra kết nối rồi thử tiếp tục.',
        ], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($importWantsJson || $saveTranslationWantsJson || $translationLockWantsJson || $syncBackWantsJson || $isLocaleApiAction) && !Csrf::isValid($_POST)) {
        $respondImportJsonFailure('Yêu cầu không hợp lệ hoặc phiên đã hết hạn.', 403);
    }
    if (!$translationLockWantsJson && !$syncBackWantsJson && !$isLocaleApiAction) {
        Csrf::verify($_POST);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $auditLogger = new AuditLogger($conn);

    if ($action === 'freeze_source_version' && $currentVersion) {
        if (!ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser['id'])) {
            http_response_code(403);
            $errorMessage = 'Chỉ Trưởng nhóm của dự án mới được chốt phiên bản.';
        } elseif ($isVersionFrozen) {
            $successMessage = 'Phiên bản này đã được chốt và không thể chỉnh sửa.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                throw new RuntimeException('VERSION_FREEZE_TRANSACTION_FAILED');
            }
            try {
                $freezeRes = queryParams(
                    $conn,
                    'UPDATE source_versions
                     SET frozen_at = clock_timestamp(), frozen_by_user_id = $2
                     WHERE id = $1 AND frozen_at IS NULL
                     RETURNING frozen_at',
                    [(int)$currentVersion['id'], (int)$currentUser['id']]
                );
                $freezeRow = pg_fetch_assoc($freezeRes);
                if ($freezeRow) {
                    queryParams(
                        $conn,
                        "INSERT INTO release_sync_history
                            (project_id, source_version_id, type, version_number, record_count, actor_user_id, created_at)
                         VALUES (
                            $1, $2, 'version_lock', $3,
                            (SELECT COUNT(*)::INT FROM strings WHERE source_version_id = $2),
                            $4, $5
                         )
                         ON CONFLICT DO NOTHING",
                        [
                            $projectId,
                            (int)$currentVersion['id'],
                            (string)$currentVersion['version_number'],
                            (int)$currentUser['id'],
                            $freezeRow['frozen_at'],
                        ]
                    );
                }
                if (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('VERSION_FREEZE_COMMIT_FAILED');
                }
            } catch (Throwable $exception) {
                if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                    pg_query($conn, 'ROLLBACK');
                }
                throw $exception;
            }
            if ($freezeRow) {
                $currentVersion['frozen_at'] = $freezeRow['frozen_at'];
                $isVersionFrozen = true;
                $successMessage = 'Đã chốt phiên bản. Nội dung nguồn và bản dịch của phiên bản này hiện chỉ đọc.';
            } else {
                $errorMessage = 'Phiên bản đã được chốt bởi người khác. Hãy tải lại trang.';
            }
        }
    } elseif ($action === 'create_project') {
        $projectName = trim((string)($_POST['project_name'] ?? ''));
        $projectDescription = trim((string)($_POST['description'] ?? ''));
        $projectNameLength = preg_match_all('/./us', $projectName);
        $descriptionLength = preg_match_all('/./us', $projectDescription);
        if ($projectNameLength === false || $projectNameLength === 0 || $projectNameLength > 150) {
            $errorMessage = 'Tên dự án là bắt buộc và không được dài quá 150 ký tự.';
        } elseif ($descriptionLength === false || $descriptionLength > 5000) {
            $errorMessage = 'Mô tả dự án không được dài quá 5000 ký tự.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                throw new RuntimeException('PROJECT_CREATE_TRANSACTION_FAILED');
            }
            try {
                $projectRes = queryParams(
                    $conn,
                    'INSERT INTO projects (name, description, created_by) VALUES ($1, $2, $3) RETURNING id',
                    [$projectName, $projectDescription !== '' ? $projectDescription : null, (int)$currentUser['id']]
                );
                $newProjectId = (int)pg_fetch_result($projectRes, 0, 'id');
                queryParams(
                    $conn,
                    "INSERT INTO project_members (project_id, user_id, role_in_project)
                     VALUES ($1, $2, 'Team Lead')",
                    [$newProjectId, (int)$currentUser['id']]
                );
                queryParams(
                    $conn,
                    "INSERT INTO project_locales (project_id, locale_code)
                     VALUES ($1, 'vi'), ($1, 'en')
                     ON CONFLICT DO NOTHING",
                    [$newProjectId]
                );
                if (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('PROJECT_CREATE_COMMIT_FAILED');
                }
            } catch (Throwable $e) {
                if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                    pg_query($conn, 'ROLLBACK');
                }
                throw $e;
            }

            header(
                'Location: index.php?project_id=' . $newProjectId . '&created=1',
                true,
                303
            );
            exit;
        }
    } elseif ($action === 'add_project_member') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $memberRole = (string)($_POST['role_in_project'] ?? '');
        if (!in_array($memberRole, ['Team Lead', 'Translator', 'Reviewer'], true)) {
            $errorMessage = 'Vai trò trong dự án không hợp lệ.';
        } elseif (
            $targetProjectId !== $projectId ||
            !ProjectAccess::canManageSourceVersion($conn, $targetProjectId, (int)$currentUser['id'])
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Trưởng nhóm của dự án hiện tại mới được quản lý thành viên.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('PROJECT_MEMBER_UPDATE_TRANSACTION_FAILED');
            }
            try {
                    $memberRes = queryParams(
                        $conn,
                        'INSERT INTO project_members (project_id, user_id, role_in_project)
                         SELECT p.id, u.id, $3
                         FROM projects p CROSS JOIN users u
                         WHERE p.id = $1 AND u.id = $2
                         ON CONFLICT (project_id, user_id)
                         DO UPDATE SET role_in_project = EXCLUDED.role_in_project
                         WHERE project_members.role_in_project IS DISTINCT FROM EXCLUDED.role_in_project
                         RETURNING user_id',
                        [$targetProjectId, $memberUserId, $memberRole]
                    );
                    $memberChanged = pg_fetch_assoc($memberRes) !== false;
                    if ($memberChanged) {
                        $projectNameRes = queryParams($conn, 'SELECT name FROM projects WHERE id = $1', [$targetProjectId]);
                        $projectName = (string)pg_fetch_result($projectNameRes, 0, 'name');
                        NotificationManager::create(
                            $conn,
                            $memberUserId,
                            "Bạn đã được thêm vào dự án {$projectName} với vai trò " .
                                ($projectRoleLabels[$memberRole] ?? $memberRole) . '.',
                            'projects'
                        );
                    }
                    if (pg_query($conn, 'COMMIT') === false) {
                        throw new RuntimeException('PROJECT_MEMBER_UPDATE_COMMIT_FAILED');
                    }
            } catch (Throwable $e) {
                    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                        pg_query($conn, 'ROLLBACK');
                    }
                    throw $e;
            }
            if ($memberChanged) {
                    $successMessage = 'Đã thêm thành viên hoặc cập nhật vai trò, đồng thời gửi thông báo.';
            } else {
                    $existingMemberRes = queryParams(
                        $conn,
                        'SELECT 1 FROM project_members WHERE project_id = $1 AND user_id = $2',
                        [$targetProjectId, $memberUserId]
                    );
                    if (pg_fetch_assoc($existingMemberRes)) {
                        $successMessage = 'Thành viên đã có đúng vai trò này.';
                    } else {
                        $errorMessage = 'Không tìm thấy dự án hoặc User ID.';
                    }
            }
        }
    } elseif ($action === 'update_project_member_role') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $memberRole = (string)($_POST['role_in_project'] ?? '');
        if (!in_array($memberRole, ['Translator', 'Reviewer'], true)) {
            $errorMessage = 'Vai trò mới không hợp lệ.';
        } elseif (
            $targetProjectId !== $projectId ||
            ProjectAccess::roleFor($conn, $targetProjectId, (int)$currentUser['id']) !== 'Team Lead'
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Trưởng nhóm của dự án hiện tại mới được quản lý thành viên.';
        } else {
            $updateMemberRes = queryParams(
                $conn,
                'UPDATE project_members pm
                 SET role_in_project = $3
                 FROM projects p
                 WHERE pm.project_id = $1
                   AND pm.user_id = $2
                   AND p.id = pm.project_id
                   AND p.created_by IS DISTINCT FROM pm.user_id
                   AND pm.role_in_project IS DISTINCT FROM $3
                 RETURNING pm.user_id',
                [$targetProjectId, $memberUserId, $memberRole]
            );
            if (pg_fetch_assoc($updateMemberRes)) {
                $successMessage = 'Đã cập nhật vai trò thành viên.';
            } else {
                $memberExistsRes = queryParams(
                    $conn,
                    'SELECT 1 FROM project_members WHERE project_id = $1 AND user_id = $2',
                    [$targetProjectId, $memberUserId]
                );
                $errorMessage = pg_fetch_assoc($memberExistsRes)
                    ? 'Không thể sửa vai trò của chủ dự án hoặc vai trò không thay đổi.'
                    : 'Không tìm thấy thành viên trong dự án.';
            }
        }
    } elseif ($action === 'remove_project_member') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        if (
            $targetProjectId !== $projectId ||
            ProjectAccess::roleFor($conn, $targetProjectId, (int)$currentUser['id']) !== 'Team Lead'
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Trưởng nhóm của dự án hiện tại mới được quản lý thành viên.';
        } elseif ($memberUserId < 1) {
            $errorMessage = 'User ID thành viên không hợp lệ.';
        } else {
            $removeMemberRes = queryParams(
                $conn,
                'DELETE FROM project_members pm
                 USING projects p
                 WHERE pm.project_id = $1
                   AND pm.user_id = $2
                   AND p.id = pm.project_id
                   AND p.created_by IS DISTINCT FROM pm.user_id
                 RETURNING pm.user_id',
                [$targetProjectId, $memberUserId]
            );
            if (pg_fetch_assoc($removeMemberRes)) {
                $successMessage = 'Đã xóa thành viên khỏi dự án.';
            } else {
                $errorMessage = 'Không tìm thấy thành viên hoặc không thể xóa chủ dự án.';
            }
        }
    } elseif ($action === 'save_translation') {
        $savedTranslation = null;
        try {
        $stringId = filter_var($_POST['string_id'] ?? null, FILTER_VALIDATE_INT);
        $translatedText = $_POST['translated_text'] ?? null;
        $status = $_POST['status'] ?? null;
        $baseVersion = $_POST['base_version'] ?? null;

        if ($stringId === false || $stringId === null || $stringId < 1 ||
            !is_string($translatedText) || !is_string($status) ||
            !array_key_exists('base_version', $_POST) ||
            ($baseVersion !== null && (!is_string($baseVersion) || strlen($baseVersion) > 40))) {
            http_response_code(400);
            $errorMessage = 'Thiếu hoặc sai dữ liệu bản dịch cần lưu.';
        } elseif ($isVersionFrozen) {
            http_response_code(409);
            $errorMessage = 'Phiên bản đã được chốt; không thể sửa bản dịch.';
        } elseif (!in_array($status, ['draft', 'approved', 'rejected', 'needs_review'], true)) {
            $errorMessage = 'Trạng thái bản dịch không hợp lệ.';
        } else {
            $sourceRes = queryParams(
                $conn,
                'SELECT s.project_id, s.key_path, s.source_text
                 FROM strings s
                 WHERE s.id = $1 AND s.source_version_id = $2',
                [$stringId, $versionId]
            );
            $sourceRow = pg_fetch_assoc($sourceRes);

            if (!$sourceRow) {
                http_response_code(404);
                $errorMessage = 'Không tìm thấy chuỗi nguồn để lưu bản dịch.';
            } elseif (!ProjectAccess::canTranslate($conn, (int)$sourceRow['project_id'], $currentUser)) {
                http_response_code(403);
                $errorMessage = 'Bạn chưa là thành viên của dự án này.';
            } elseif (!ProjectAccess::canEditTranslation(
                $conn,
                (int)$sourceRow['project_id'],
                (int)$currentUser['id']
            )) {
                http_response_code(403);
                $errorMessage = 'Bạn không có quyền chỉnh sửa bản dịch trong dự án này.';
            } elseif (!VariableValidator::validate($sourceRow['source_text'], $translatedText)) {
                $errorMessage = 'Bản dịch phải giữ nguyên các biến trong chuỗi nguồn (ví dụ: {name}).';
            } elseif (!ProjectAccess::canSetTranslationStatus(
                $conn,
                (int)$sourceRow['project_id'],
                (int)$currentUser['id'],
                $status === 'approved' && !$canReviewCurrentProject ? 'needs_review' : $status
            )) {
                http_response_code(403);
                $errorMessage = 'Biên dịch viên chỉ được chọn Bản nháp hoặc Cần kiểm tra; Kiểm duyệt viên và Trưởng nhóm được chọn mọi trạng thái.';
            }
            if ($sourceRow && $errorMessage === '') {
                if ($status === 'approved' && !$canReviewCurrentProject) {
                    $status = 'needs_review';
                }
                $lockResult = queryParams(
                    $conn,
                    'SELECT user_id FROM translation_locks
                     WHERE string_id = $1 AND locale = $2
                       AND expires_at > clock_timestamp()',
                    [$stringId, $currentLocale]
                );
                $activeLock = pg_fetch_assoc($lockResult);
                if ($activeLock && (int)$activeLock['user_id'] !== (int)$currentUser['id']) {
                    $errorMessage = 'Chuỗi đang được thành viên khác chỉnh sửa. Vui lòng thử lại sau.';
                }
            }
            if ($sourceRow && $errorMessage === '') {
                if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('TRANSLATION_SAVE_TRANSACTION_FAILED');
                }
                try {
                    queryParams($conn, 'SELECT pg_advisory_xact_lock($1, hashtext($2))', [$stringId, $currentLocale]);
                    $priorTranslationRes = queryParams(
                        $conn,
                        'SELECT t.id, t.user_id, t.status, t.translated_text,
                                to_char(t.updated_at AT TIME ZONE \'UTC\',
                                        \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                                u.username AS updated_by
                         FROM translations t
                         LEFT JOIN users u ON u.id = t.user_id
                         WHERE t.string_id = $1 AND t.locale = $2
                         FOR UPDATE OF t',
                        [$stringId, $currentLocale]
                    );
                    $priorTranslation = pg_fetch_assoc($priorTranslationRes);
                    $translationContentChanged = !$priorTranslation ||
                        $priorTranslation['translated_text'] !== $translatedText;
                    if ($status === 'approved' && $translationContentChanged) {
                        $status = 'needs_review';
                    }
                    $requestedBaseVersion = $baseVersion === '' ? null : $baseVersion;
                    if (($priorTranslation['updated_at'] ?? null) !== $requestedBaseVersion) {
                        pg_query($conn, 'ROLLBACK');
                        http_response_code(409);
                        $errorMessage = 'Bản dịch này đã được cập nhật. Vui lòng xem phiên bản hiện tại trước khi lưu.';
                        $translationConflict = [
                            'stringId' => (int)$stringId,
                            'keyPath' => (string)$sourceRow['key_path'],
                            'updatedBy' => $priorTranslation['updated_by'] ?? null,
                            'updatedAt' => $priorTranslation['updated_at'] ?? null,
                            'currentText' => $priorTranslation['translated_text'] ?? null,
                            'currentStatus' => $priorTranslation['status'] ?? null,
                            'submittedText' => $translatedText,
                            'submittedStatus' => $status,
                        ];
                    } else {
                        queryParams(
                            $conn,
                            "SELECT set_config('tms.user_id', $1, TRUE)",
                            [(string)$currentUser['id']]
                        );
                        if ($priorTranslation) {
                            $saveRes = queryParams(
                                $conn,
                                "UPDATE translations
                                 SET translated_text = $1, status = $2, updated_at = clock_timestamp(), user_id = $3
                                 WHERE id = $4
                                 RETURNING id,
                                           to_char(updated_at AT TIME ZONE 'UTC',
                                                   'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
                                [$translatedText, $status, (int)$currentUser['id'], (int)$priorTranslation['id']]
                            );
                        } else {
                            $saveRes = queryParams(
                                $conn,
                                "INSERT INTO translations
                                    (string_id, locale, translated_text, status, updated_at, user_id)
                                 VALUES ($1, $2, $3, $4, clock_timestamp(), $5)
                                 ON CONFLICT (string_id, locale) DO NOTHING
                                 RETURNING id,
                                           to_char(updated_at AT TIME ZONE 'UTC',
                                                   'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at",
                                [$stringId, $currentLocale, $translatedText, $status, (int)$currentUser['id']]
                            );
                        }
                        $savedTranslation = pg_fetch_assoc($saveRes);
                        if (!$savedTranslation) {
                            $currentTranslationRes = queryParams(
                                $conn,
                                'SELECT t.status, t.translated_text,
                                        to_char(t.updated_at AT TIME ZONE \'UTC\',
                                                \'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS updated_at,
                                        u.username AS updated_by
                                 FROM translations t
                                 LEFT JOIN users u ON u.id = t.user_id
                                 WHERE t.string_id = $1 AND t.locale = $2
                                 FOR UPDATE OF t',
                                [$stringId, $currentLocale]
                            );
                            $currentTranslation = pg_fetch_assoc($currentTranslationRes);
                            pg_query($conn, 'ROLLBACK');
                            http_response_code(409);
                            $errorMessage = 'Bản dịch này đã được cập nhật. Vui lòng xem phiên bản hiện tại trước khi lưu.';
                            $translationConflict = [
                                'stringId' => (int)$stringId,
                                'keyPath' => (string)$sourceRow['key_path'],
                                'updatedBy' => $currentTranslation['updated_by'] ?? null,
                                'updatedAt' => $currentTranslation['updated_at'] ?? null,
                                'currentText' => $currentTranslation['translated_text'] ?? null,
                                'currentStatus' => $currentTranslation['status'] ?? null,
                                'submittedText' => $translatedText,
                                'submittedStatus' => $status,
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
                if ($savedTranslation) {
                    $tm = new TranslationMemory($conn);
                    $tm->saveToMemory((int)$sourceRow['project_id'], $currentLocale, $sourceRow['source_text'], $translatedText);
                    if ($status === 'approved') {
                        try {
                            $tmRepository->exportProject($projectId, $projectName);
                        } catch (Throwable $repositoryException) {
                            error_log('TM_REPOSITORY_AUTO_EXPORT_FAILED: ' . $repositoryException->getMessage());
                        }
                    }

                    $oldText = $priorTranslation['translated_text'] ?? $sourceRow['source_text'];
                    $oldStatus = $priorTranslation['status'] ?? 'chưa có';
                    $auditLogger->log(
                        $projectId,
                        $stringId,
                        'UPDATE_TRANSLATION',
                        "User [{$currentUser['username']}] thay đổi bản dịch [{$currentLocale}]: "
                        . "nội dung từ [" . ($oldText !== '' ? $oldText : '(trống)') . "] sang [" . ($translatedText !== '' ? $translatedText : '(trống)') . "]; "
                        . "trạng thái từ [{$oldStatus}] sang [{$status}]"
                    );
                    if (in_array($status, ['approved', 'rejected'], true)) {
                        queryParams(
                            $conn,
                            'INSERT INTO reviews (translation_id, reviewer_id, status, comment)
                             VALUES ($1, $2, $3, $4)',
                            [(int)$savedTranslation['id'], (int)$currentUser['id'], $status, 'Review từ giao diện TMS']
                        );
                    }
                    if (
                        in_array($status, ['approved', 'needs_review', 'rejected'], true) &&
                        $priorTranslation &&
                        $priorTranslation['user_id'] !== null &&
                        (int)$priorTranslation['user_id'] !== (int)$currentUser['id'] &&
                        $priorTranslation['status'] !== $status
                    ) {
                        $reviewMessage = $status === 'approved'
                            ? 'Bản dịch của bạn đã được duyệt.'
                            : 'Bản dịch của bạn cần được xem xét và chỉnh sửa.';
                        NotificationManager::create($conn, (int)$priorTranslation['user_id'], $reviewMessage, 'comments');
                    }
                    $successMessage = "Đã lưu bản dịch thành công!";
                    $savedTranslationUpdatedAt = $savedTranslation['updated_at'] ?? null;
                } elseif ($translationConflict === null) {
                    $errorMessage = 'Không thể ghi bản dịch vào cơ sở dữ liệu.';
                }
            }
        }
        } catch (Throwable $exception) {
            if (isset($conn) && $conn instanceof PgSql\Connection &&
                pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($conn, 'ROLLBACK');
            }
            error_log('TRANSLATION_SAVE_FAILED: ' . $exception->getMessage());
            http_response_code(500);
            $errorMessage = $saveTranslationWantsJson
                ? 'Không thể lưu bản dịch do lỗi cơ sở dữ liệu hoặc lỗi hệ thống.'
                : 'Không thể lưu bản dịch do lỗi hệ thống.';
        }
    } 
    elseif ($action === 'add_glossary' && $currentVersion) {
        if (!ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser['id'])) {
            $errorMessage = "Bạn không có quyền thực hiện hành động này!";
        } else {
            $term = trim($_POST['term'] ?? '');
            $translation = trim($_POST['translation'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (!empty($term) && !empty($translation)) {
                $glossaryManager = new GlossaryManager($conn);
                $glossaryManager->addTerm($projectId, $currentLocale, $term, $translation, $description);

                $auditLogger->log($projectId, null, 'ADD_GLOSSARY', "User [{$currentUser['username']}] thêm thuật ngữ [{$currentLocale}]: {$term} -> {$translation}");
                $successMessage = "Đã thêm thuật ngữ [{$term}] thành công!";
            } else {
                $errorMessage = "Vui lòng nhập đầy đủ thông tin thuật ngữ!";
            }
        }
    }
    elseif ($action === 'sync_back' && $currentVersion) {
        if (!ProjectAccess::canReview($conn, $projectId, $currentUser)) {
            http_response_code(403);
            $errorMessage = 'Chỉ Kiểm duyệt viên hoặc Trưởng nhóm mới được đồng bộ bản dịch đã duyệt.';
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
                $approvedJson = RemoteContentService::approvedPackage(
                    $conn,
                    (int)$currentVersion['id'],
                    $currentLocale
                );
                $approvedCountRes = queryParams(
                    $conn,
                    "SELECT COUNT(*)::INT
                     FROM strings s
                     JOIN translations t ON t.string_id = s.id
                     WHERE s.source_version_id = $1
                       AND t.locale = $2
                       AND t.status = 'approved'",
                    [(int)$currentVersion['id'], $currentLocale]
                );
                $approvedCount = (int)pg_fetch_result($approvedCountRes, 0, 0);
                $historyEndpoint = RemoteContentService::endpointForHistory($syncUrl);
                $targetRollbackData = RemoteContentService::currentSyncData(
                    $conn,
                    $projectId,
                    $historyEndpoint
                );
                if ($targetRollbackData === null) {
                    $targetRollbackData = (new RemoteContentService())->fetchJsonSnapshot(
                        $syncUrl,
                        $bearerToken !== '' ? $bearerToken : null
                    );
                }
                $newSyncData = RemoteContentService::prepareSyncPayload($syncUrl, $approvedJson);
                $syncRemoteSuccessResponse = (new RemoteContentService())->fetch(
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
                        (int)$currentVersion['id'],
                        (string)$currentVersion['version_number'],
                        $currentLocale,
                        $historyEndpoint,
                        $approvedCount,
                        (int)$currentUser['id'],
                        $newSyncData,
                        $targetRollbackData,
                        $bearerToken !== '',
                    ]
                );
                $syncHistoryRecorded = true;
                $auditLogger->log(
                    $projectId,
                    null,
                    'SYNC_APPROVED_TRANSLATIONS',
                    "User [{$currentUser['username']}] đồng bộ bản dịch đã duyệt [{$currentLocale}] bằng {$syncMethod}",
                    (int)$currentUser['id']
                );
                $successMessage = 'Đã đồng bộ bản dịch thành công về trang web gốc!';
            } catch (Throwable $exception) {
                error_log('TMS dashboard translation sync failed: ' . $exception->getMessage());
                if (!$syncRemoteRequestCompleted) {
                    $auditLogger->log(
                        $projectId,
                        null,
                        'SYNC_APPROVED_TRANSLATIONS_FAILED',
                        "User [{$currentUser['username']}] đồng bộ bản dịch đã duyệt [{$currentLocale}] thất bại",
                        (int)$currentUser['id']
                    );
                }
                if ($syncRemoteRequestCompleted) {
                    http_response_code(500);
                    $errorMessage = $syncHistoryRecorded
                        ? 'Endpoint đã nhận dữ liệu và lịch sử đồng bộ đã được lưu, nhưng không thể ghi nhật ký kiểm toán.'
                        : 'Endpoint đã nhận dữ liệu, nhưng TMS không thể lưu lịch sử đồng bộ. Vui lòng kiểm tra lịch sử trước khi thử lại.';
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
                    $errorMessage = 'Lỗi PHP khi xử lý yêu cầu Sync Back. Vui lòng thử lại sau.';
                }
                if ($errorMessage === '') {
                $errorMessage = match ($exception->getMessage()) {
                    'REMOTE_URL_INVALID' => 'URL endpoint không hợp lệ. Chỉ hỗ trợ URL HTTP/HTTPS công khai.',
                    'REMOTE_HOST_NOT_PUBLIC' => 'Máy chủ đích không có địa chỉ IP công khai.',
                    'REMOTE_REQUEST_FAILED' => 'Không thể kết nối tới endpoint đích.',
                    'REMOTE_REQUEST_INIT_FAILED', 'REMOTE_REQUEST_CONFIGURATION_FAILED' => 'Không thể khởi tạo yêu cầu đồng bộ.',
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
    elseif ($action === 'export_release' && $currentVersion) {
        if (!ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser['id'])) {
            http_response_code(403);
            $errorMessage = "Chỉ Trưởng nhóm của dự án mới có quyền xuất bản gói ngôn ngữ.";
        } elseif (!$isVersionFrozen) {
            $errorMessage = "Cần chốt phiên bản trước khi xuất bản gói ngôn ngữ.";
        } else {
            $versionNumber = $currentVersion['version_number'];
            $outputPath = __DIR__ . "/releases/{$currentLocale}/{$versionNumber}.json";
            
            $exporter = new ReleaseExporter($conn);
            $exporter->generateRelease($projectId, $currentLocale, $versionNumber, $outputPath);

            $auditLogger->log($projectId, null, 'EXPORT_RELEASE', "User [{$currentUser['username']}] xuất bản gói [{$currentLocale}] v{$versionNumber}");
            $successMessage = "Đã xuất bản gói ngôn ngữ thành công!";
        }
    }
    elseif ($action === 'export_translation_package' && $currentVersion) {
            if (!ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser['id'])) {
                http_response_code(403);
                $errorMessage = 'Chỉ Trưởng nhóm của dự án mới được xuất tệp dịch.';
            } else {
                $exporter = new TranslationPackageExporter($conn);
                $format = strtolower((string)($_POST['export_format'] ?? 'json'));
                $json = $exporter->export((int)$currentVersion['id'], $currentLocale, $format);
                $auditLogger->log(
                    $projectId,
                    null,
                    'EXPORT_TRANSLATION_PACKAGE',
                    "source_version_id={$currentVersion['id']}; locale={$currentLocale}; format={$format}; User [{$currentUser['username']}]"
                );
                $safeLocale = preg_replace('/[^A-Za-z0-9_-]/', '', $currentLocale);
                $safeVersion = preg_replace('/[^A-Za-z0-9._-]/', '_', $currentVersion['version_number']);
                $extension = $exporter->extensionFor($format);
                header('Content-Type: ' . $exporter->contentTypeFor($format) . '; charset=utf-8');
                header('Content-Disposition: attachment; filename="translation-' . $safeLocale . '-' . $safeVersion . '.' . $extension . '"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            }
        }
        elseif ($action === 'rollback_translation' && $currentVersion) {
                if ($isVersionFrozen) {
                    http_response_code(409);
                    $errorMessage = 'Phiên bản đã được chốt; không thể hoàn tác bản dịch.';
                } else {
                $historyId = (int)($_POST['history_id'] ?? 0);
            $translationId = (int)($_POST['translation_id'] ?? 0);
            $historyRes = queryParams(
                $conn,
                'SELECT COALESCE(h.old_text, s.source_text) AS old_text,
                        s.project_id, t.string_id, t.locale, s.source_text, t.updated_at
                 FROM translation_history h
                 JOIN translations t ON t.id = h.translation_id
                 JOIN strings s ON s.id = t.string_id
                 WHERE h.id = $1 AND t.id = $2 AND t.locale = $3
                   AND s.source_version_id = $4',
                [$historyId, $translationId, $currentLocale, $versionId]
            );
            $historyRow = pg_fetch_assoc($historyRes);
            if (!$historyRow || !ProjectAccess::canReview($conn, (int)$historyRow['project_id'], $currentUser)) {
                http_response_code(403);
                $errorMessage = 'Không tìm thấy lịch sử hoặc bạn không có quyền hoàn tác.';
            } elseif (!VariableValidator::validate($historyRow['source_text'], (string)($historyRow['old_text'] ?? ''))) {
                $errorMessage = 'Không thể hoàn tác vì bản cũ không còn khớp placeholder với chuỗi nguồn.';
            } else {
                if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('TRANSLATION_ROLLBACK_TRANSACTION_FAILED');
                }
                try {
                    queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
                    $rollbackRes = queryParams(
                        $conn,
                        "UPDATE translations
                         SET translated_text = $1, status = 'needs_review', updated_at = clock_timestamp()
                         WHERE id = $2
                         RETURNING id",
                        [$historyRow['old_text'], $translationId]
                    );
                    if (pg_query($conn, 'COMMIT') === false) {
                        throw new RuntimeException('TRANSLATION_ROLLBACK_COMMIT_FAILED');
                    }
                } catch (Throwable $e) {
                    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                        pg_query($conn, 'ROLLBACK');
                    }
                    throw $e;
                }
                if (pg_fetch_assoc($rollbackRes)) {
                    $successMessage = 'Đã hoàn tác bản dịch về nội dung đã chọn và chuyển trạng thái sang cần xem xét.';
                }
                }
            }
        }
    elseif ($action === 'import_source') {
            try {
            $importedVersionId = null;
            $requiresImportConfirmation = false;
            if (
            !ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser['id']) ||
            (int)($_POST['project_id'] ?? 0) !== $projectId
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Trưởng nhóm của dự án hiện tại mới được nhập nguồn dữ liệu.';
        } else {
            $versionNumber = trim((string)($_POST['version_number'] ?? ''));
            $sourceMode = (string)($_POST['source_mode'] ?? 'file');
            $sourceData = null;
            $sourceUrl = null;
            $sourceHtml = null;
            if ($versionNumber === '' || strlen($versionNumber) > 50) {
                $errorMessage = 'Vui lòng nhập phiên bản nguồn hợp lệ (tối đa 50 ký tự).';
            } elseif ($sourceMode === 'file') {
                $uploadError = $_FILES['json_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $errorMessage = match ($uploadError) {
                        UPLOAD_ERR_NO_FILE => 'Vui lòng chọn tệp JSON nguồn.',
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Tệp JSON vượt quá dung lượng cho phép của máy chủ.',
                        default => 'Không thể tải tệp JSON lên. Vui lòng thử lại.',
                    };
                } else {
                    try {
                        $originalName = (string)($_FILES['json_file']['name'] ?? '');
                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        $selectedFormat = strtolower(trim((string)($_POST['import_format'] ?? '')));
                        $formatExtensions = [
                            'json' => ['json'],
                            'csv' => ['csv'],
                            'po' => ['po'],
                            'xliff' => ['xlf', 'xliff'],
                            'android_xml' => ['xml'],
                            'xlsx' => ['xlsx'],
                        ];
                        if (!isset($formatExtensions[$selectedFormat])) {
                            throw new InvalidArgumentException('IMPORT_FORMAT_INVALID');
                        }
                        if (!in_array($extension, $formatExtensions[$selectedFormat], true)) {
                            throw new InvalidArgumentException('IMPORT_FORMAT_MISMATCH');
                        }
                        $sourceData = SourceImportParser::parse((string)$_FILES['json_file']['tmp_name'], $extension);
                    } catch (Throwable $exception) {
                        error_log('TMS file import failed: ' . $exception->getMessage());
                        $errorMessage = match ($exception->getMessage()) {
                            'IMPORT_FORMAT_UNSUPPORTED' => 'Chỉ hỗ trợ JSON, CSV, PO, XLIFF, Android XML hoặc XLSX.',
                            'IMPORT_FORMAT_INVALID' => 'Vui lòng chọn định dạng tệp cần nhập.',
                            'IMPORT_FORMAT_MISMATCH' => 'Định dạng đã chọn không khớp với phần mở rộng của tệp.',
                            'IMPORT_CSV_COLUMNS_REQUIRED', 'IMPORT_XLSX_COLUMNS_REQUIRED' => 'Tệp phải có cột key và source_text (hoặc text).',
                            'IMPORT_XLSX_EXTENSION_MISSING', 'IMPORT_XML_EXTENSION_MISSING' => 'Máy chủ chưa bật tiện ích XML cần thiết để đọc tệp.',
                            'IMPORT_XML_INVALID' => 'Tệp XML/XLIFF/Android XML không hợp lệ.',
                            default => 'Tệp nguồn không hợp lệ hoặc không thể đọc.',
                        };
                    }
                }
            } elseif ($sourceMode === 'url') {
                $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
                $sourceType = (string)($_POST['source_type'] ?? '');
                try {
                    if (!in_array($sourceType, ['json', 'html'], true)) {
                        throw new InvalidArgumentException('INGESTION_TYPE_INVALID');
                    }
                    $response = (new RemoteContentService())->fetch($sourceUrl);
                    $sourceData = $sourceType === 'json'
                        ? RemoteContentService::parseJson($response)
                        : RemoteContentService::parseHtml($response);
                    if ($sourceType === 'html') {
                        $sourceHtml = $response;
                    }
                } catch (Throwable $exception) {
                    error_log('TMS dashboard source ingestion failed: ' . $exception->getMessage());
                    $errorMessage = match ($exception->getMessage()) {
                        'REMOTE_URL_INVALID' => 'URL không hợp lệ. Chỉ hỗ trợ URL HTTP/HTTPS công khai.',
                        'REMOTE_HOST_NOT_PUBLIC' => 'Máy chủ nguồn không có địa chỉ IP công khai.',
                        'REMOTE_REQUEST_FAILED' => 'Không thể tải trang: ' . $exception->getMessage(),
                        'REMOTE_EMPTY_RESPONSE_HTTP_STATUS_200' => 'Trang trả về rỗng (HTTP 200), không có nội dung để phân tích.',
                        'REMOTE_RESPONSE_TOO_LARGE_HTTP_STATUS_200' => 'Trang trả về vượt quá giới hạn 2 MB (HTTP 200).',
                        'REMOTE_REQUEST_INIT_FAILED', 'REMOTE_REQUEST_CONFIGURATION_FAILED' => 'Không thể khởi tạo yêu cầu tới máy chủ nguồn.',
                        'CURL_EXTENSION_MISSING' => 'Máy chủ PHP chưa bật tiện ích cURL.',
                        'INGESTION_JSON_INVALID' => 'Phản hồi API không phải JSON hợp lệ.',
                        'INGESTION_JSON_OBJECT_REQUIRED' => 'JSON phải là object hoặc array có nội dung.',
                        'INGESTION_HTML_EMPTY', 'INGESTION_HTML_INVALID', 'INGESTION_HTML_NO_TEXT' => 'Không tìm thấy nội dung văn bản có thể dịch trong trang HTML.',
                        'INGESTION_HTML_DOM_MISSING' => 'Máy chủ chưa bật DOMDocument để phân tích HTML.',
                        'INGESTION_HTML_BLOCKED_CLOUDFLARE' => 'Trang nguồn đang chặn bot bằng Cloudflare. Hãy dùng URL công khai khác hoặc nhập file HTML thủ công.',
                        'INGESTION_HTML_BLOCKED_ACCESS_DENIED' => 'Trang nguồn trả về Access Denied và không cho phép tải nội dung.',
                        'INGESTION_HTML_BLOCKED_CAPTCHA' => 'Trang nguồn yêu cầu CAPTCHA nên không thể tự động nhập nội dung.',
                        'INGESTION_TYPE_INVALID' => 'Loại nội dung cần nhập không hợp lệ.',
                        default => str_starts_with($exception->getMessage(), 'REMOTE_HTTP_STATUS_')
                            ? 'Máy chủ nguồn trả về lỗi HTTP ' . substr($exception->getMessage(), strlen('REMOTE_HTTP_STATUS_')) . '.'
                            : (str_starts_with($exception->getMessage(), 'REMOTE_EMPTY_RESPONSE_HTTP_STATUS_')
                                ? 'Máy chủ nguồn trả về nội dung rỗng với ' . substr($exception->getMessage(), strlen('REMOTE_EMPTY_RESPONSE_HTTP_STATUS_')) . '.'
                                : (str_starts_with($exception->getMessage(), 'REMOTE_RESPONSE_TOO_LARGE_HTTP_STATUS_')
                                    ? 'Máy chủ nguồn trả về nội dung vượt quá giới hạn 2 MB với ' . substr($exception->getMessage(), strlen('REMOTE_RESPONSE_TOO_LARGE_HTTP_STATUS_')) . '.'
                                    : 'Không thể kéo nội dung: ' . $exception->getMessage())),
                    };
                }
            } else {
                $errorMessage = 'Hãy chọn tải tệp JSON hoặc kéo dữ liệu từ URL/API.';
            }

            if ($sourceData !== null && $errorMessage === '') {
                if ($currentVersion && (int)$currentVersion['project_id'] === $projectId) {
                    $importWorkStatus = $getImportWorkStatus((int)$currentVersion['id'], $projectId);
                    $requiresImportConfirmation = $importWorkStatus['requiresConfirmation'];
                    if ($requiresImportConfirmation && ($_POST['confirm_import'] ?? '') !== '1') {
                        $errorMessage = 'Phiên bản hiện tại đang có dữ liệu dịch chưa được xuất bản hoặc xuất tệp.';
                        $sourceData = null;
                    }
                }
            }

            if ($sourceData !== null && $errorMessage === '') {
                $importProjectId = $projectId;
                $repository = new TranslationRepository($conn);
                $importedVersionId = null;
                try {
                    $importedVersionId = $repository->importSourceVersion(
                        $importProjectId,
                        $versionNumber,
                        $sourceData,
                        $sourceMode === 'url' ? $sourceUrl : null,
                        $sourceHtml
                    );
                } catch (RuntimeException $e) {
                    if ($e->getMessage() === 'SOURCE_VERSION_FROZEN') {
                        http_response_code(409);
                        $errorMessage = 'Phiên bản đã được chốt; hãy nhập với mã phiên bản mới.';
                    } else {
                        throw $e;
                    }
                }

                if ($importedVersionId !== null) {
                    $versionId = $importedVersionId;
                    $auditLogger->log($importProjectId, null, 'IMPORT_SOURCE', "User [{$currentUser['username']}] import phiên bản [{$versionNumber}]");
                    $successMessage = 'Import nguồn dữ liệu thành công!';

                    $versionRes = queryParams($conn, "SELECT id, version_number, project_id, source_url, frozen_at, frozen_by_user_id FROM source_versions WHERE id = $1", [$versionId]);
                    $currentVersion = pg_fetch_assoc($versionRes);
                    $isVersionFrozen = $currentVersion && $currentVersion['frozen_at'] !== null;
                    $projectId = $currentVersion ? (int)$currentVersion['project_id'] : $importProjectId;
                    $hasSourceStrings = true;
                    $canTranslateCurrentProject = ProjectAccess::canTranslate($conn, $projectId, $currentUser);
                    $canReviewCurrentProject = ProjectAccess::canReview($conn, $projectId, $currentUser);
                }
            }
        }
        if ($importWantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $importResponse = [
                'success' => $errorMessage === '' && $importedVersionId !== null,
                'message' => $errorMessage !== ''
                    ? $errorMessage
                    : ($successMessage !== '' ? $successMessage : 'Không thể import nguồn dữ liệu.'),
                'data' => [],
            ];
            if ($requiresImportConfirmation) {
                $importResponse['requires_confirmation'] = true;
            }
            if ($importedVersionId !== null) {
                $importResponse['version_id'] = (int)$importedVersionId;
                $rowsResult = queryParams(
                    $conn,
                    "SELECT s.key_path, s.source_text,
                            COALESCE(t.status, 'draft') AS status
                     FROM strings s
                     LEFT JOIN translations t
                       ON t.string_id = s.id AND t.locale = $2
                     WHERE s.source_version_id = $1
                     ORDER BY s.key_path",
                    [(int)$importedVersionId, $currentLocale]
                );
                while ($row = pg_fetch_assoc($rowsResult)) {
                    $importResponse['data'][] = [
                        'key_path' => (string)$row['key_path'],
                        'source_text' => (string)$row['source_text'],
                        'status' => (string)$row['status'],
                    ];
                }
            }
            http_response_code($importResponse['success'] ? 200 : (
                $requiresImportConfirmation || $errorMessage === 'Phiên bản đã được chốt; hãy nhập với mã phiên bản mới.'
                    ? 409
                    : 400
            ));
            echo json_encode($importResponse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        } catch (Throwable $exception) {
            error_log('SOURCE_IMPORT_FAILED: ' . $exception->getMessage());
            if ($importWantsJson) {
                $importJsonResponseSent = true;
                $respondImportJsonFailure($exception->getMessage());
            }
            http_response_code(500);
            $errorMessage = $exception->getMessage();
        }
    }
}

if ($syncBackWantsJson) {
    $importJsonResponseSent = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $syncSucceeded = $errorMessage === '';
    if (!$syncSucceeded && http_response_code() === 200) {
        http_response_code(422);
    }
    echo json_encode(
        [
            'success' => $syncSucceeded,
            'message' => $syncSucceeded
                ? $successMessage
                : ($errorMessage !== '' ? $errorMessage : 'Không thể đồng bộ bản dịch.'),
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

if ($saveTranslationWantsJson) {
    $importJsonResponseSent = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($errorMessage !== '') {
        if (http_response_code() === 200) {
            http_response_code(422);
        }
        $response = ['success' => false, 'message' => 'Lỗi: ' . $errorMessage];
        if ($translationConflict !== null) {
            $response['error_code'] = 'CONFLICT';
            $response['conflict'] = $translationConflict;
        }
        echo json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
    if (isset($savedTranslation) && is_array($savedTranslation)) {
        echo json_encode(
            [
                'success' => true,
                'message' => 'Cập nhật thành công',
                'translated_text' => $translatedText,
                'status' => $status,
                'updated_at' => $savedTranslationUpdatedAt ?? null,
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
    $respondImportJsonFailure('Lỗi: Không thể xác nhận trạng thái lưu bản dịch.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'list_translation_history') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $respondHistory = static function (array $payload, int $statusCode = 200): never {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    $historyStringId = filter_var($_GET['string_id'] ?? null, FILTER_VALIDATE_INT);
    if (
        !$currentVersion ||
        !$canTranslateCurrentProject ||
        $historyStringId === false ||
        $historyStringId < 1
    ) {
        $respondHistory(['success' => false, 'message' => 'Không thể tải lịch sử bản dịch.'], 403);
    }
    try {
        $historyRes = queryParams(
            $conn,
            'SELECT h.id, h.translation_id, h.old_text, h.new_text, s.source_text,
                    ROW_NUMBER() OVER (PARTITION BY h.translation_id ORDER BY h.created_at, h.id) AS revision_number,
                    h.created_at, u.username
             FROM translation_history h
             JOIN translations t ON t.id = h.translation_id
             JOIN strings s ON s.id = t.string_id
             LEFT JOIN users u ON u.id = h.changed_by_user_id
             WHERE s.id = $1 AND s.source_version_id = $2 AND t.locale = $3
             ORDER BY h.created_at DESC, h.id DESC',
            [(int)$historyStringId, (int)$currentVersion['id'], $currentLocale]
        );
        $history = [];
        while ($historyRow = pg_fetch_assoc($historyRes)) {
            $revisionNumber = (int)$historyRow['revision_number'];
            $history[] = [
                'id' => (int)$historyRow['id'],
                'translation_id' => (int)$historyRow['translation_id'],
                'old_text' => $historyRow['old_text'],
                'new_text' => $historyRow['new_text'],
                'rollback_text' => $revisionNumber === 1
                    ? $historyRow['source_text']
                    : $historyRow['old_text'],
                'username' => $historyRow['username'],
                'created_at' => $historyRow['created_at'],
                'revision_number' => $revisionNumber,
            ];
        }
        $respondHistory([
            'success' => true,
            'history' => $history,
            'can_rollback' => $canReviewCurrentProject && !$isVersionFrozen,
        ]);
    } catch (Throwable $exception) {
        error_log('TRANSLATION_HISTORY_LIST_FAILED: ' . $exception->getMessage());
        $respondHistory(['success' => false, 'message' => 'Không thể tải lịch sử bản dịch.'], 500);
    }
}

$stats = ['total' => 0, 'translated' => 0, 'approved' => 0, 'draft' => 0, 'empty' => 0, 'percentage' => 0.0];
$strings = [];
$glossaryTerms = [];
$auditLogs = [];
$productivity = [];

if ($currentVersion && $canTranslateCurrentProject) {
    $statsManager = new TranslationStats($conn);
    $stats = $statsManager->getProgress($projectId, $versionId, $currentLocale);

    $glossaryManager = new GlossaryManager($conn);
    $glossaryTerms = $glossaryManager->getTerms($projectId, $currentLocale);

    $auditLogger = new AuditLogger($conn);
    $auditFilters = [
        'action' => trim((string)($_GET['audit_action'] ?? '')),
        'user_id' => trim((string)($_GET['audit_user_id'] ?? '')),
        'from' => trim((string)($_GET['audit_from'] ?? '')),
        'to' => trim((string)($_GET['audit_to'] ?? '')),
    ];
    $auditLogs = $auditLogger->getLogs($projectId, 1000, $auditFilters);
    $productivityResult = queryParams(
        $conn,
        "SELECT COALESCE(u.username, 'Hệ thống') AS username,
                COUNT(*) FILTER (WHERE a.action_type IN ('UPDATE_TRANSLATION', 'BULK_UPDATE_TRANSLATIONS')) AS updates,
                COUNT(DISTINCT a.string_id) FILTER (WHERE a.string_id IS NOT NULL) AS keys_touched
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE a.project_id = $1
         GROUP BY u.username
         ORDER BY updates DESC, keys_touched DESC
         LIMIT 20",
        [$projectId]
    );
    while ($row = pg_fetch_assoc($productivityResult)) {
        $productivity[] = $row;
    }

    $pageSize = max(10, min(100, (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
    $countSql = "SELECT COUNT(*)
                 FROM strings s
                 LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
                 WHERE s.source_version_id = $2";
    $countParams = [$currentLocale, $versionId];
    $sqlStrings = "SELECT s.id AS string_id, s.key_path, s.source_text,
                          t.id AS translation_id,
                          COALESCE(NULLIF(BTRIM(t.translated_text), ''), latest_history.new_text, '') AS translated_text,
                          CASE
                              WHEN NULLIF(BTRIM(t.translated_text), '') IS NULL
                                   AND NULLIF(BTRIM(latest_history.new_text), '') IS NOT NULL
                              THEN 'draft'
                              ELSE COALESCE(t.status, 'draft')
                          END AS status,
                          COALESCE(
                              to_char(t.updated_at AT TIME ZONE 'UTC',
                                      'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),
                              ''
                          ) AS translation_updated_at,
                          COALESCE((
                              SELECT COUNT(*)
                              FROM translation_history th
                              WHERE th.translation_id = t.id
                          ), 0)::int AS history_count,
                          CASE WHEN l.user_id IS NOT NULL AND l.user_id <> $3
                               THEN l.user_id ELSE NULL END AS locked_by_user_id
                   FROM strings s
                   LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
                   LEFT JOIN LATERAL (
                       SELECT h.new_text
                       FROM translation_history h
                       WHERE h.translation_id = t.id
                       ORDER BY h.created_at DESC, h.id DESC
                       LIMIT 1
                   ) latest_history ON TRUE
                   LEFT JOIN translation_locks l
                     ON l.string_id = s.id AND l.locale = $1 AND l.expires_at > clock_timestamp()
                   WHERE s.source_version_id = $2";
    $stringParams = [$currentLocale, $versionId, (int)$currentUser['id']];
    $statusFilter = (string)($_GET['status'] ?? '');
    if ($statusFilter === 'draft' || $statusFilter === 'translated') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') IN ('draft', 'translated')";
    } elseif ($statusFilter === 'approved') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') = 'approved'";
    } elseif ($statusFilter === 'rejected') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') = 'rejected'";
    } elseif ($statusFilter === 'needs_review') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') = 'needs_review'";
    }
    $searchQuery = trim((string)($_GET['q'] ?? ''));
    if ($searchQuery !== '') {
        $sqlStrings .= ' AND (s.key_path ILIKE $4 OR s.source_text ILIKE $4 OR COALESCE(t.translated_text, \'\') ILIKE $4)';
        $stringParams[] = '%' . $searchQuery . '%';
        $countParams[] = '%' . $searchQuery . '%';
        $countSql .= ' AND (s.key_path ILIKE $3 OR s.source_text ILIKE $3 OR COALESCE(t.translated_text, \'\') ILIKE $3)';
    }
    $countResult = queryParams($conn, $countSql, $countParams);
    $totalStrings = (int)pg_fetch_result($countResult, 0, 0);
    $totalPages = max(1, (int)ceil($totalStrings / $pageSize));
    $page = min($page, $totalPages);
    $stringParams[] = $pageSize;
    $stringParams[] = ($page - 1) * $pageSize;
    $sqlStrings .= ' ORDER BY s.key_path LIMIT $' . (count($stringParams) - 1) . ' OFFSET $' . count($stringParams);
    $stringsRes = queryParams($conn, $sqlStrings, $stringParams);
    while ($row = pg_fetch_assoc($stringsRes)) {
        $strings[] = $row;
    }
}
$previewRows = [];
if ($currentVersion && $canTranslateCurrentProject) {
    $previewRes = queryParams(
        $conn,
        "SELECT s.id AS string_id, s.key_path, s.source_text,
                COALESCE(t.translated_text, '') AS translated_text,
                COALESCE(t.status, 'draft') AS status
         FROM strings s
         LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
         WHERE s.source_version_id = $2
         ORDER BY s.key_path",
        [$currentLocale, $versionId]
    );
    while ($row = pg_fetch_assoc($previewRes)) {
        $previewRows[] = [
            'key' => $row['key_path'],
            'source' => $row['source_text'],
            'translation' => $row['translated_text'],
            'status' => $row['status'],
            'stringId' => (int)$row['string_id'],
        ];
    }
}
$workspacePreviewJson = json_encode(
    $previewRows,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$historyStringId = isset($_GET['history_string_id']) ? (int)$_GET['history_string_id'] : 0;
$translationHistory = [];
if ($currentVersion && $canReviewCurrentProject && $historyStringId > 0) {
    $historyRes = queryParams(
        $conn,
        'SELECT h.id, h.translation_id, h.old_text, h.new_text, s.source_text,
                ROW_NUMBER() OVER (PARTITION BY h.translation_id ORDER BY h.created_at, h.id) AS revision_number,
                s.key_path,
                h.changed_by_user_id, h.created_at, u.username, t.updated_at
         FROM translation_history h
         JOIN translations t ON t.id = h.translation_id
         JOIN strings s ON s.id = t.string_id
         LEFT JOIN users u ON u.id = h.changed_by_user_id
         WHERE s.id = $1 AND s.source_version_id = $2 AND t.locale = $3
         ORDER BY h.created_at DESC, h.id DESC',
        [$historyStringId, $versionId, $currentLocale]
    );
    while ($historyRow = pg_fetch_assoc($historyRes)) {
        $translationHistory[] = $historyRow;
    }
}
$canAccessMemberManagement = $projects !== [];
$projectMembers = [];
$versionChanges = [];
$releaseHistory = [];
if ($currentVersion) {
    $repository = new TranslationRepository($conn);
    $versionChanges = $repository->getVersionChanges((int)$currentVersion['id']);
    $releaseRes = queryParams(
        $conn,
        'SELECT id, locale, version_number, created_at
         FROM release_snapshots
         WHERE source_version_id = $1
         ORDER BY created_at DESC, id DESC',
        [(int)$currentVersion['id']]
    );
    while ($releaseRow = pg_fetch_assoc($releaseRes)) {
        $releaseHistory[] = $releaseRow;
    }
}
if ($currentProjectRole !== null) {
    $membersRes = queryParams(
        $conn,
        'SELECT u.id, u.username, u.email, pm.role_in_project,
                (p.created_by = u.id) AS is_project_owner
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         JOIN projects p ON p.id = pm.project_id
         WHERE pm.project_id = $1 ORDER BY u.username',
        [$projectId]
    );
    while ($row = pg_fetch_assoc($membersRes)) {
        $projectMembers[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="project.app_heading">Enterprise TMS - Full Featured</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
    <script src="tms-sync-back.js" defer></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
    <div class="<?= ($currentProjectRole === 'Reviewer' || $currentProjectRole === 'Translator') ? 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full space-y-6' : 'tms-shell ' . ($currentProjectRole === 'Team Lead' ? 'tms-team-lead-dashboard-shell max-w-7xl mx-auto' : 'max-w-7xl mx-auto') . ' px-4 sm:px-6 lg:px-8 py-8' ?>">
        <header class="tms-topbar flex flex-col md:flex-row justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100 gap-4">
            <div>
                <h1 class="text-2xl font-bold text-indigo-600">🌍 <span data-i18n="project.app_heading">Enterprise TMS &amp; Full Suite</span></h1>
                <p class="text-sm text-gray-500 mt-1" data-i18n="header_subtitle">Hệ thống quản lý dịch thuật cấp độ doanh nghiệp tích hợp TM, AI, Glossary & RBAC</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <?php if (defined('TMS_PROJECT_DETAIL')): ?>
                    <a href="index.php" class="tms-filter-reset"><span data-i18n="project.back_to_my_projects">← Dự án của tôi</span></a>
                <?php endif; ?>
                <?php require __DIR__ . '/templates/tms-header.php'; ?>

            </div>
        </header>

        <?php if ($projectId > 0 && $currentVersion && $hasSourceStrings && $canTranslateCurrentProject): ?>
        <?php
        $totalKeys = (int)($stats['total'] ?? 0);
        $translatedKeys = (int)($stats['translated'] ?? 0);
        $approvedKeys = (int)($stats['approved'] ?? 0);
        $untranslatedKeys = max(0, $totalKeys - $translatedKeys);
        $translatedPercent = $totalKeys > 0
            ? round(($translatedKeys / $totalKeys) * 100, 2)
            : 0;
        ?>
        <section class="tms-progress-card" aria-label="Tiến độ chi tiết bản dịch">
            <div class="tms-progress-overview">
                <div class="tms-progress-heading">
                    <h2 data-i18n="translation.progress">Tiến độ chi tiết</h2>
                    <span><?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <dl class="tms-progress-metrics">
                    <div><dt data-i18n="translation.total">Tổng số</dt><dd><?= $totalKeys ?></dd></div>
                    <div><dt data-i18n="translation.translated">Đã dịch</dt><dd><?= $translatedKeys ?></dd></div>
                    <div><dt data-i18n="translation.untranslated">Chưa dịch</dt><dd><?= $untranslatedKeys ?></dd></div>
                    <div><dt data-i18n="translation.approved">Đã duyệt</dt><dd><?= $approvedKeys ?></dd></div>
                </dl>
                <div class="tms-progress-completion">
                    <strong><?= $translatedPercent ?>%</strong>
                    <span data-i18n="translation.completed">hoàn thành</span>
                </div>
            </div>
            <div
                class="tms-progress-track"
                role="progressbar"
                aria-label="Tiến độ dịch"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= $translatedPercent ?>"
                aria-valuetext="<?= $translatedKeys ?> trên <?= $totalKeys ?> chuỗi đã dịch"
            >
                <span style="width: <?= $translatedPercent ?>%"></span>
            </div>
        </section>
        <?php endif; ?>

        <div class="tms-dashboard-grid">
        <?php if (!defined('TMS_PROJECT_DETAIL')): ?>
        <section class="tms-main-panel tms-my-projects tms-accent-indigo mb-8 rounded-xl bg-white p-6 shadow-sm border border-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold" data-i18n="workspace.title">Dự án của tôi</h2>
                    <p class="text-sm text-gray-500" data-i18n="workspace.projects_description">Các dự án bạn đã tạo hoặc được thêm làm thành viên.</p>
                </div>
            </div>
            <?php if ($projects): ?>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($projects as $project): ?>
                        <a class="tms-project-link rounded-xl border border-gray-200 p-4 hover:border-indigo-400 hover:bg-indigo-50"
                           href="project_detail.php?id=<?= (int)$project['id'] ?>">
                            <span class="block font-semibold text-indigo-700"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="mt-1 block text-xs text-gray-500">Project ID: <?= (int)$project['id'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Bạn chưa có dự án nào. Hãy tạo dự án mới hoặc nhờ Trưởng nhóm thêm bạn vào dự án qua User ID.
                </p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Thông báo -->
        <?php if (!empty($successMessage)): ?>
            <div class="tms-notice mb-6 bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                <p class="text-green-700 font-medium"><?= htmlspecialchars($successMessage) ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="tms-notice mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-md shadow-sm">
                <p class="text-red-700 font-medium"><?= htmlspecialchars($errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 w-full col-span-12 mb-8 items-stretch tms-project-layout-row">
        <!-- Unified source import form -->
        <?php ob_start(); ?>
        <?php if (defined('TMS_PROJECT_DETAIL') && $isAuthenticated && $isProjectLeader): ?>
        <div class="order-2 w-full lg:col-span-1 tms-project-admin-column tms-import-column">
        <div class="tms-side-panel tms-import-panel tms-accent-violet w-full h-full flex flex-col bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">📥 <span data-i18n="project.import_source">Nhập nguồn dữ liệu</span></h2>
            <p class="mb-4 text-sm text-gray-500"><span data-i18n="project.project_label" data-i18n-name="<?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-i18n-id="<?= $projectId ?>">Dự án: <?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID: <?= $projectId ?>)</span></p>
            <form method="POST" enctype="multipart/form-data" class="tms-import-form" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                <input type="hidden" name="action" value="import_source">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="response_format" value="json">
                <input type="hidden" name="confirm_import" value="0">
                <div class="tms-import-version">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="import-version" data-i18n="project.version">Phiên bản</label>
                    <input id="import-version" type="text" name="version_number" value="v1.0.1" maxlength="50" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <fieldset class="mt-4">
                    <legend class="block text-xs font-semibold text-gray-500 uppercase mb-2" data-i18n="project.source">Nguồn dữ liệu</legend>
                    <div class="flex flex-wrap gap-4 mb-3">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                            <input type="radio" name="source_mode" value="file" checked>
                            <span data-i18n="project.upload_json">Tải tệp JSON từ máy</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                            <input type="radio" name="source_mode" value="url">
                            <span data-i18n="project.import_url">Kéo từ URL / Endpoint API</span>
                        </label>
                    </div>
                    <div data-import-source="file">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="import-format" data-i18n="project.file_format_label">Định dạng tệp</label>
                        <select id="import-format" name="import_format" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="json">JSON</option>
                            <option value="csv">CSV</option>
                            <option value="po">PO (Gettext)</option>
                            <option value="xliff">XLIFF</option>
                            <option value="android_xml">Android XML</option>
                            <option value="xlsx">Excel (XLSX)</option>
                        </select>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mt-3 mb-1" for="source-json-file">Tệp nguồn</label>
                        <div class="tms-file-picker">
                            <input id="source-json-file" class="tms-file-input" type="file" name="json_file" accept=".json,.csv,.po,.xlf,.xliff,.xml,application/json,text/csv,application/xml,text/xml" required>
                            <label class="tms-file-button" for="source-json-file">Chọn tệp</label>
                            <span class="tms-file-name" data-file-name>Chưa có tệp nào được chọn</span>
                        </div>
                    </div>
                    <div data-import-source="url" hidden>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="source-url">URL trang hoặc Endpoint API</label>
                        <input id="source-url" name="source_url" type="url" maxlength="2048" value="<?= htmlspecialchars((string)($currentVersion['source_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/content.json" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        <label class="block text-xs font-semibold text-gray-500 uppercase mt-3 mb-1" for="source-type">Định dạng nội dung</label>
                        <select id="source-type" name="source_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="json">API JSON</option>
                            <option value="html">Trang HTML</option>
                        </select>
                        <p class="mt-2 text-xs text-gray-500">Chỉ hỗ trợ máy chủ công khai; phản hồi tối đa 2 MB.</p>
                    </div>
                </fieldset>
                <button type="submit" class="tms-button-primary tms-import-submit mt-4 w-full bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-lg font-semibold text-sm shadow-sm transition-colors" data-i18n="process_import_btn">
                    🚀 Xử lý &amp; Import
                </button>
                <?php if ($isVersionFrozen): ?><p class="mt-2 text-xs text-amber-700">Phiên bản hiện tại đã chốt. Hãy nhập mã phiên bản mới.</p><?php endif; ?>
            </form>
            <dialog class="tms-modal" id="import-source-confirm-dialog" aria-labelledby="import-source-confirm-title" style="width:min(36rem, calc(100vw - 2rem))">
                <section class="tms-modal-card">
                    <p class="text-xs font-bold uppercase tracking-widest text-amber-600">Cảnh báo dữ liệu dịch</p>
                    <h2 id="import-source-confirm-title" class="mt-1 text-xl font-bold text-slate-900">Phiên bản hiện tại có dữ liệu chưa lưu an toàn</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">Phiên bản hiện tại đang có dữ liệu dịch. Vui lòng báo Trưởng nhóm thực hiện xuất bản hoặc xuất tệp (export) và chốt phiên bản trước khi import nguồn mới. Nếu bạn tiếp tục import mà không lưu, dữ liệu hiện tại có thể bị thay thế.</p>
                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" id="import-source-confirm-cancel" class="tms-modal-cancel">Hủy / Quay lại</button>
                        <button type="button" id="import-source-confirm-continue" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Tiếp tục import nguồn khác</button>
                        <button type="button" id="import-source-confirm-publish" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Xuất bản ngay &amp; Import</button>
                    </div>
                </section>
            </dialog>
            <script>
                (() => {
                    const fileInput = document.getElementById('source-json-file');
                    const fileName = document.querySelector('[data-file-name]');
                    const form = fileInput?.closest('form');
                    const sourceUrl = document.getElementById('source-url');
                    const importFormat = document.getElementById('import-format');
                    const confirmDialog = document.getElementById('import-source-confirm-dialog');
                    const confirmCancel = document.getElementById('import-source-confirm-cancel');
                    const confirmContinue = document.getElementById('import-source-confirm-continue');
                    const confirmPublish = document.getElementById('import-source-confirm-publish');
                    const confirmField = form?.querySelector('input[name="confirm_import"]');
                    if (!fileInput || !fileName || !form || !sourceUrl || !importFormat ||
                        !confirmDialog || !confirmCancel || !confirmContinue || !confirmPublish || !confirmField) return;
                    let isSubmitting = false;
                    let isPublishing = false;
                    const importEndpoint = new URL('project_detail.php', window.location.href);
                    const currentPageUrl = new URL(window.location.href);
                    importEndpoint.searchParams.set('id', form.elements.project_id.value);
                    ['locale', 'version_id'].forEach((parameter) => {
                        const value = currentPageUrl.searchParams.get(parameter);
                        if (value !== null) importEndpoint.searchParams.set(parameter, value);
                    });
                    const sourceSections = form.querySelectorAll('[data-import-source]');
                    const sourceModes = form.querySelectorAll('input[name="source_mode"]');
                    const updateSourceMode = () => {
                        const selectedMode = form.querySelector('input[name="source_mode"]:checked')?.value || 'file';
                        sourceSections.forEach((section) => {
                            const isActive = section.dataset.importSource === selectedMode;
                            section.hidden = !isActive;
                            section.querySelectorAll('input, select').forEach((input) => {
                                input.required = isActive && (input === fileInput || input === sourceUrl);
                            });
                        });
                    };
                    const readJsonResponse = async (response) => {
                        const text = await response.text();
                        console.log(text);
                        try {
                            return JSON.parse(text);
                        } catch {
                            throw new Error(`Phản hồi không phải JSON (HTTP ${response.status}). Xem nội dung trong Console.`);
                        }
                    };
                    sourceModes.forEach((radio) => radio.addEventListener('change', updateSourceMode));
                    const formatExtensions = {
                        json: '.json',
                        csv: '.csv',
                        po: '.po',
                        xliff: '.xlf,.xliff',
                        android_xml: '.xml',
                        xlsx: '.xlsx'
                    };
                    importFormat.addEventListener('change', () => {
                        fileInput.accept = formatExtensions[importFormat.value || ''] || '';
                    });
                    updateSourceMode();
                    fileInput.addEventListener('change', () => {
                        fileName.textContent = fileInput.files.length
                            ? fileInput.files[0].name
                            : 'Chưa có tệp nào được chọn';
                        fileName.title = fileName.textContent;
                    });
                    confirmCancel.addEventListener('click', () => confirmDialog.close('cancel'));
                    confirmContinue.addEventListener('click', () => confirmDialog.close('continue'));
                    confirmDialog.addEventListener('cancel', (event) => {
                        if (isPublishing) event.preventDefault();
                    });
                    confirmPublish.addEventListener('click', async () => {
                        try {
                            await publishCurrentVersion();
                            confirmDialog.close('publish');
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Không thể chốt và xuất bản phiên bản.';
                            window.alert(message);
                        }
                    });
                    const askToContinueImport = () => new Promise((resolve) => {
                        confirmDialog.addEventListener('close', () => {
                            resolve(confirmDialog.returnValue);
                        }, {once: true});
                        confirmDialog.showModal();
                    });
                    const publishCurrentVersion = async () => {
                        isPublishing = true;
                        confirmCancel.disabled = true;
                        confirmContinue.disabled = true;
                        confirmPublish.disabled = true;
                        confirmPublish.textContent = 'Đang chốt và xuất bản...';
                        try {
                            const response = await fetch(importEndpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                                },
                                body: new URLSearchParams({
                                    action: 'publish_current_for_import',
                                    project_id: form.elements.project_id.value,
                                    csrf_token: form.elements.csrf_token.value,
                                    import_version_number: form.elements.version_number.value,
                                })
                            });
                            const result = await readJsonResponse(response);
                            if (!response.ok || !result.success) {
                                throw new Error(result.message || 'Không thể chốt và xuất bản phiên bản.');
                            }
                            if (result.import_version_number) {
                                form.elements.version_number.value = result.import_version_number;
                            }
                            confirmField.value = '1';
                        } finally {
                            isPublishing = false;
                            confirmCancel.disabled = false;
                            confirmContinue.disabled = false;
                            confirmPublish.disabled = false;
                            confirmPublish.textContent = 'Xuất bản ngay & Import';
                        }
                    };
                    form.addEventListener('submit', async (event) => {
                        event.preventDefault();
                        if (isSubmitting) return;
                        isSubmitting = true;
                        const submitButton = form.querySelector('button[type="submit"]');
                        const originalButtonText = submitButton.textContent;
                        try {
                            if (confirmField.value !== '1' && <?= (int)$versionId ?> > 0) {
                                const preflightResponse = await fetch(importEndpoint, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                                    },
                                    body: new URLSearchParams({
                                        action: 'import_source_preflight',
                                        project_id: form.elements.project_id.value,
                                        csrf_token: form.elements.csrf_token.value,
                                    })
                                });
                                const preflight = await readJsonResponse(preflightResponse);
                                if (!preflightResponse.ok || !preflight.success) {
                                    throw new Error(preflight.message || 'Không thể kiểm tra trạng thái phiên bản hiện tại.');
                                }
                                if (preflight.status?.requiresConfirmation) {
                                    const choice = await askToContinueImport();
                                    if (choice === 'publish') {
                                        if (confirmField.value !== '1') return;
                                    } else if (choice === 'continue') {
                                        confirmField.value = '1';
                                    } else {
                                        return;
                                    }
                                }
                            }
                            for (let attempt = 0; attempt < 2; attempt++) {
                                submitButton.disabled = true;
                                submitButton.textContent = 'Đang import...';
                                const response = await fetch(importEndpoint, {
                                    method: 'POST',
                                    body: new FormData(form),
                                    credentials: 'same-origin',
                                    headers: {Accept: 'application/json'}
                                });
                                const result = await readJsonResponse(response);
                                if (response.status === 409 && result.requires_confirmation && confirmField.value !== '1') {
                                    const choice = await askToContinueImport();
                                    if (choice === 'publish') {
                                        if (confirmField.value !== '1') return;
                                    } else if (choice === 'continue') {
                                        confirmField.value = '1';
                                    } else {
                                        return;
                                    }
                                    continue;
                                }
                                if (!response.ok || !result.success) {
                                    throw new Error(result.message || 'Import nguồn dữ liệu thất bại.');
                                }
                                const query = new URLSearchParams(window.location.search);
                                query.set('version_id', String(result.version_id || <?= (int)$versionId ?>));
                                window.location.assign(`${window.location.pathname}?${query.toString()}`);
                                return;
                            }
                        } catch (error) {
                            const message = error instanceof Error ? error.message : 'Import nguồn dữ liệu thất bại.';
                            window.alert(message);
                        } finally {
                            isSubmitting = false;
                            submitButton.disabled = false;
                            submitButton.textContent = originalButtonText;
                        }
                    });
                })();
            </script>
        </div>
        </div>
        <?php endif; ?>

        <?php if ($isAuthenticated && !defined('TMS_PROJECT_DETAIL')): ?>
        <section class="tms-side-panel tms-create-project tms-accent-indigo tms-highlight mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-lg font-semibold text-gray-800 mb-2" data-i18n="project.create_title">Tạo dự án mới</h2>
            <p class="text-sm text-gray-500 mb-4" data-i18n="project.create_hint">Người tạo dự án sẽ tự động được gán vai trò Trưởng nhóm.</p>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="create_project">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="grid gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="project_name" data-i18n="project.name">Tên dự án</label>
                        <input id="project_name" name="project_name" maxlength="150" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="project_description" data-i18n="project.description">Mô tả</label>
                        <textarea id="project_description" name="description" maxlength="5000" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></textarea>
                    </div>
                </div>
                <button type="submit" class="tms-button-primary mt-4 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-semibold text-sm" data-i18n="workspace.create_project">Tạo dự án</button>
            </form>
        </section>
        <?php endif; ?>

        <?php $projectImportMarkup = ob_get_clean(); ?>
        <?php if (defined('TMS_PROJECT_DETAIL') && $currentProjectRole !== null): ?>
        <div class="order-1 w-full col-span-12 min-w-0 h-full flex flex-col <?= ($currentProjectRole === 'Reviewer' || $currentProjectRole === 'Translator') ? 'lg:col-span-12' : 'lg:col-span-7' ?>">
        <section class="tms-main-panel tms-members-panel tms-accent-emerald w-full h-full flex flex-col bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800 mb-1" data-i18n="project.members">Thành viên dự án</h2>
                    <p class="text-sm text-gray-500"><span data-i18n="project.project_label" data-i18n-name="<?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-i18n-id="<?= $projectId ?>">Dự án: <?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID: <?= $projectId ?>)</span> — <span class="tms-role-badge <?= $currentProjectRole === 'Team Lead' ? 'tms-role-lead' : ($currentProjectRole === 'Translator' ? 'tms-role-translator' : 'tms-role-reviewer') ?>"><?= htmlspecialchars($projectRoleLabels[$currentProjectRole] ?? $currentProjectRole, ENT_QUOTES, 'UTF-8') ?></span></p>
                </div>
                <?php if ($isProjectLeader): ?>
                    <button type="button" class="tms-button-primary px-4 py-2 text-sm font-semibold text-white" data-open-member-modal>
                        + <span data-i18n="project.add_member">Thêm thành viên</span>
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($isProjectLeader): ?>
            <dialog class="tms-modal" id="add-member-modal" aria-labelledby="add-member-title">
                <form method="POST" class="tms-modal-card" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="add_project_member">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Project team</p>
                            <h3 id="add-member-title" class="mt-1 text-xl font-bold text-slate-900" data-i18n="project.add_member">Thêm thành viên</h3>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-member-modal>×</button>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700" for="member_user_id">User ID</label>
                    <input id="member_user_id" type="number" name="user_id" min="1" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                    <label class="mt-4 block text-sm font-semibold text-slate-700" for="role_in_project">Vai trò</label>
                    <select id="role_in_project" name="role_in_project" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                        <option value="Translator">Biên dịch viên</option>
                        <option value="Reviewer">Kiểm duyệt viên</option>
                        <option value="Team Lead">Trưởng nhóm</option>
                    </select>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="tms-modal-cancel" data-close-member-modal>Hủy</button>
                        <button type="submit" class="tms-button-primary px-5 py-2 text-sm font-semibold text-white">Thêm thành viên</button>
                    </div>
                </form>
            </dialog>
            <?php endif; ?>
            <div class="tms-members-table-wrap mt-5 w-full max-h-[480px] overflow-y-auto overflow-x-hidden">
                <table class="tms-members-table w-full table-fixed text-left text-sm border-collapse">
                    <thead><tr class="border-b text-gray-500"><th class="py-2">User ID</th><th>Username</th><th>Email</th><th>Vai trò</th><?php if ($isProjectLeader): ?><th class="text-right">Thao tác</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($projectMembers as $member): ?>
                        <tr class="border-b last:border-0">
                            <td class="py-2"><?= (int)$member['id'] ?></td>
                            <td><?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $roleBadgeClass = match ($member['role_in_project']) {
                                    'Team Lead' => 'tms-role-lead',
                                    'Translator' => 'tms-role-translator',
                                    'Reviewer' => 'tms-role-reviewer',
                                    default => 'tms-role-default',
                                };
                                ?>
                                <span class="tms-role-badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($projectRoleLabels[$member['role_in_project']] ?? $member['role_in_project'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <?php if ($isProjectLeader): ?>
                            <td class="text-right">
                                <?php if ($member['is_project_owner'] !== 't'): ?>
                                    <div class="tms-member-actions">
                                        <button type="button"
                                                class="tms-member-action tms-member-edit"
                                                aria-label="Sửa vai trò của <?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-edit-member
                                                data-user-id="<?= (int)$member['id'] ?>"
                                                data-username="<?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-role="<?= htmlspecialchars($member['role_in_project'], ENT_QUOTES, 'UTF-8') ?>">✎</button>
                                        <form method="POST" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>"
                                              onsubmit="return confirm('Bạn có chắc muốn xóa thành viên này khỏi dự án?');">
                                            <input type="hidden" name="action" value="remove_project_member">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                                            <button type="submit" class="tms-member-action tms-member-delete" aria-label="Xóa <?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>">×</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">Chủ dự án</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($isProjectLeader): ?>
            <dialog class="tms-modal" id="edit-member-modal" aria-labelledby="edit-member-title">
                <form method="POST" class="tms-modal-card" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="update_project_member_role">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="user_id" value="" data-edit-user-id>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Project team</p>
                            <h3 id="edit-member-title" class="mt-1 text-xl font-bold text-slate-900">Sửa vai trò thành viên</h3>
                            <p class="mt-1 text-sm text-slate-500" data-edit-member-name></p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-edit-modal>×</button>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700" for="edit-role-in-project">Vai trò</label>
                    <select id="edit-role-in-project" name="role_in_project" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                        <option value="Translator">Biên dịch viên</option>
                        <option value="Reviewer">Kiểm duyệt viên</option>
                    </select>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="tms-modal-cancel" data-close-edit-modal>Hủy</button>
                        <button type="submit" class="tms-button-primary px-5 py-2 text-sm font-semibold text-white">Lưu thay đổi</button>
                    </div>
                </form>
            </dialog>
            <script>
                (() => {
                    const addModal = document.getElementById('add-member-modal');
                    const editModal = document.getElementById('edit-member-modal');
                    if (!addModal || !editModal) return;
                    document.querySelectorAll('[data-open-member-modal]').forEach((button) => {
                        button.addEventListener('click', () => addModal.showModal());
                    });
                    document.querySelectorAll('[data-close-member-modal]').forEach((button) => {
                        button.addEventListener('click', () => addModal.close());
                    });
                    document.querySelectorAll('[data-edit-member]').forEach((button) => {
                        button.addEventListener('click', () => {
                            editModal.querySelector('[data-edit-user-id]').value = button.dataset.userId;
                            editModal.querySelector('[data-edit-member-name]').textContent = button.dataset.username;
                            editModal.querySelector('#edit-role-in-project').value =
                                button.dataset.role === 'Reviewer' ? 'Reviewer' : 'Translator';
                            editModal.showModal();
                        });
                    });
                    document.querySelectorAll('[data-close-edit-modal]').forEach((button) => {
                        button.addEventListener('click', () => editModal.close());
                    });
                    [addModal, editModal].filter(Boolean).forEach((modal) => {
                        modal.addEventListener('click', (event) => {
                            if (event.target === modal) modal.close();
                        });
                    });
                })();
            </script>
            <?php endif; ?>
        </section>
        </div>
        <?php endif; ?>
        <?php if ($isProjectLeader): ?>
        <div class="order-2 w-full min-w-0 h-full flex flex-col lg:col-span-5 tms-project-admin-column tms-project-admin-stack">
        <?= $projectImportMarkup ?>
        <?php endif; ?>
        <?php if (defined('TMS_PROJECT_DETAIL') && $currentVersion && $isProjectLeader): ?>
        <div class="tms-export-column">
        <section class="tms-export-panel tms-accent-indigo w-full h-full flex flex-col bg-white p-5 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-base font-semibold text-gray-800 mb-3">📦 <span data-i18n="translation.publish_heading">Xuất bản</span></h2>
            <div class="tms-export-actions">
                <?php if ($isProjectLeader): ?>
                <form class="tms-export-action tms-export-package-form" method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="export_translation_package">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <select name="export_format" class="w-full px-2 py-2 text-sm">
                        <option value="json">JSON</option>
                        <option value="csv">CSV</option>
                        <option value="po">PO</option>
                        <option value="xliff">XLIFF</option>
                        <option value="android_xml">Android XML</option>
                    </select>
                    <button type="submit" class="tms-button-primary w-full px-4 py-2.5 text-sm font-semibold text-white">⬇ <span data-i18n="translation.export_file">Xuất tệp</span></button>
                </form>
                <?php endif; ?>
                <?php if ($isProjectLeader): ?>
                <form class="tms-export-action" method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="export_release">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" <?= $isVersionFrozen ? '' : 'disabled' ?> class="tms-button-secondary w-full px-4 py-2.5 text-sm font-semibold disabled:opacity-50">🚀 <span data-i18n="translation.export_json">Xuất bản JSON</span></button>
                </form>
                <?php endif; ?>
                <?php if ($currentProjectRole === 'Team Lead' || $currentProjectRole === 'Reviewer'): ?>
                <button type="button" data-open-sync-back class="tms-export-action w-full rounded-lg border border-emerald-700 bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        🚀 <span data-i18n="translation.sync_back">Đồng bộ trực tiếp API (Sync Back)</span>
                </button>
                <?php endif; ?>
                <?php if ($isProjectLeader): ?>
                    <?php if (!$isVersionFrozen): ?>
                        <form class="tms-export-action" method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                            <input type="hidden" name="action" value="freeze_source_version">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="tms-export-action w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">🔒 <span data-i18n="translation.freeze_version">Chốt phiên bản</span></button>
                        </form>
                    <?php else: ?>
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">🔒 Đã chốt: <?= htmlspecialchars((string)$currentVersion['frozen_at'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <a class="tms-export-action tms-filter-reset text-center" href="release_history.php?project_id=<?= (int)$projectId ?>" data-i18n="releases.history">Lịch sử phát hành</a>
            </div>
            <?php if ($currentProjectRole === 'Team Lead' || $currentProjectRole === 'Reviewer'): ?>
            <dialog class="tms-modal" id="sync-back-dialog" aria-labelledby="sync-back-title">
                <form method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>" class="tms-modal-card" data-sync-back-form novalidate>
                    <input type="hidden" name="action" value="sync_back">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-emerald-600">Sync Back API</p>
                            <h3 id="sync-back-title" class="mt-1 text-xl font-bold text-slate-900" data-i18n="translation.sync_heading">Đồng bộ bản dịch đã duyệt</h3>
                            <p class="mt-1 text-sm text-slate-500">Chỉ gửi các bản dịch đã duyệt của phiên bản và ngôn ngữ đang chọn.</p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-sync-back>×</button>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700" for="sync-back-url">URL Endpoint API đích</label>
                    <input id="sync-back-url" name="sync_url" type="url" pattern="https?://.+" required maxlength="2048" placeholder="https://example.com/api/translations" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm font-semibold text-slate-700">
                            Phương thức
                            <select name="sync_method" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal">
                                <option value="POST">POST</option>
                                <option value="PUT" selected>PUT</option>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">
                            Bearer token (nếu cần)
                            <input name="sync_token" type="password" autocomplete="off" placeholder="Nhập token API" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal">
                        </label>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Endpoint phải dùng HTTPS. Token không được lưu vào CSDL.</p>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="tms-modal-cancel" data-close-sync-back data-i18n="common.cancel">Hủy</button>
                        <button type="submit" class="tms-button-primary px-5 py-2 text-sm font-semibold text-white" data-i18n="translation.send_approved">Gửi bản dịch đã duyệt</button>
                    </div>
                </form>
            </dialog>
            <script>
                (() => {
                    const dialog = document.getElementById('sync-back-dialog');
                    document.querySelectorAll('[data-open-sync-back]').forEach((button) => {
                        button.addEventListener('click', () => dialog.showModal());
                    });
                    document.querySelectorAll('[data-close-sync-back]').forEach((button) => {
                        button.addEventListener('click', () => dialog.close());
                    });
                    dialog.addEventListener('click', (event) => {
                        if (event.target === dialog) dialog.close();
                    });
                })();
            </script>
            <?php endif; ?>
        </section>
        </div>
        <?php endif; ?>
        <?php if ($isProjectLeader): ?>
        </div>
        <?php endif; ?>
        </div>

        <div class="tms-bottom-layout-row grid grid-cols-1 lg:grid-cols-12 gap-6 items-start w-full col-span-12">
        <?php if ($projectId > 0 && (!$currentVersion || !$hasSourceStrings)): ?>
            <div class="tms-bottom-section tms-main-panel tms-notice w-full col-span-12 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                <p class="text-yellow-700">Chưa có dữ liệu phiên bản nguồn cho dự án này.</p>
            </div>
        <?php elseif ($canTranslateCurrentProject): ?>
            <?php if ($currentVersion && $versionChanges): ?>
                <section class="tms-bottom-section tms-reviewer-language-section w-full col-span-12 rounded-xl border border-sky-100 bg-sky-50 p-6">
                    <div class="flex flex-wrap justify-between gap-2">
                        <h2 class="font-semibold text-sky-900" data-i18n="translation.source_changes">Thay đổi nguồn của phiên bản</h2>
                        <span class="text-xs text-sky-700">
                            <?= count(array_filter($versionChanges, static fn(array $change): bool => $change['change_type'] === 'added')) ?> thêm ·
                            <?= count(array_filter($versionChanges, static fn(array $change): bool => $change['change_type'] === 'modified')) ?> sửa ·
                            <?= count(array_filter($versionChanges, static fn(array $change): bool => $change['change_type'] === 'deleted')) ?> xóa
                        </span>
                    </div>
                    <div class="mt-3 max-h-40 overflow-y-auto overscroll-contain pr-2">
                        <ul class="grid gap-1 text-sm text-sky-900">
                            <?php foreach (array_slice($versionChanges, 0, 30) as $change): ?>
                                <li><span class="mr-2 rounded px-1.5 py-0.5 text-xs font-bold <?= $change['change_type'] === 'deleted' ? 'bg-red-100 text-red-700' : ($change['change_type'] === 'modified' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') ?>"><?= htmlspecialchars($change['change_type'], ENT_QUOTES, 'UTF-8') ?></span><?= htmlspecialchars($change['key_path'], ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php if (count($versionChanges) > 30): ?><p class="mt-2 text-xs text-sky-700">Đang hiển thị 30 trên <?= count($versionChanges) ?> thay đổi.</p><?php endif; ?>
                </section>
            <?php endif; ?>
            <!-- Glossary Section -->
            <section class="tms-bottom-section tms-reviewer-glossary-section tms-glossary-panel w-full col-span-12 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" id="project-glossary">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800">📖 <span data-i18n="project.glossary">Từ điển thuật ngữ</span></h2>
                        <p class="text-xs text-gray-500">Ngôn ngữ: <span class="font-semibold uppercase text-indigo-600"><?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?></span></p>
                    </div>
                    <?php if ($isProjectLeader): ?>
                        <button type="button" class="tms-glossary-add" data-glossary-add>+ <span data-i18n="project.add_term">Thêm thuật ngữ</span></button>
                    <?php endif; ?>
                </div>

                <?php if ($glossaryTerms): ?>
                    <ul class="tms-glossary-list" aria-label="Danh sách thuật ngữ">
                        <?php foreach ($glossaryTerms as $g): ?>
                            <?php $isProjectTerm = $g['project_id'] !== null && (int)$g['project_id'] === $projectId; ?>
                            <li class="tms-glossary-item">
                                <div class="tms-glossary-pair">
                                    <span class="tms-glossary-source"><?= htmlspecialchars((string)$g['term'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="tms-glossary-arrow" aria-hidden="true">→</span>
                                    <span class="tms-glossary-target"><?= htmlspecialchars((string)$g['translation'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ((string)($g['description'] ?? '') !== ''): ?>
                                        <span class="tms-glossary-description"><?= htmlspecialchars((string)$g['description'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isProjectLeader && $isProjectTerm): ?>
                                    <div class="tms-glossary-actions">
                                        <button type="button" data-glossary-edit
                                            data-term-id="<?= (int)$g['id'] ?>"
                                            data-term="<?= htmlspecialchars((string)$g['term'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-translation="<?= htmlspecialchars((string)$g['translation'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-description="<?= htmlspecialchars((string)($g['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Sửa</button>
                                        <button type="button" data-glossary-delete
                                            data-term-id="<?= (int)$g['id'] ?>"
                                            data-term="<?= htmlspecialchars((string)$g['term'], ENT_QUOTES, 'UTF-8') ?>">Xóa</button>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="tms-glossary-empty">Chưa có thuật ngữ nào cho ngôn ngữ này.</p>
                <?php endif; ?>

                <?php if ($isProjectLeader): ?>
                    <dialog class="tms-modal" id="glossary-edit-dialog" aria-labelledby="glossary-dialog-title" style="width:min(32rem, calc(100vw - 2rem))">
                        <form class="tms-modal-card" id="glossary-edit-form">
                            <input type="hidden" name="term_id" value="">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-widest text-violet-500">Project glossary</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-900" id="glossary-dialog-title">Thêm thuật ngữ</h3>
                                </div>
                                <button type="button" class="tms-modal-close" data-glossary-close aria-label="Đóng">×</button>
                            </div>
                            <label class="tms-glossary-field">
                                <span>Từ gốc (Source)</span>
                                <input name="term" type="text" maxlength="500" required placeholder="Dashboard">
                            </label>
                            <label class="tms-glossary-field">
                                <span>Bản dịch chuẩn (Target)</span>
                                <input name="translation" type="text" maxlength="500" required placeholder="Bảng điều khiển">
                            </label>
                            <label class="tms-glossary-field">
                                <span>Ghi chú <small>(không bắt buộc)</small></span>
                                <input name="description" type="text" maxlength="2000" placeholder="Ngữ cảnh sử dụng">
                            </label>
                            <p class="tms-glossary-error" id="glossary-form-error" role="alert" hidden></p>
                            <div class="flex justify-end gap-2 pt-2">
                                <button type="button" class="tms-modal-cancel" data-glossary-close>Hủy</button>
                                <button type="submit" class="tms-glossary-save">Lưu thuật ngữ</button>
                            </div>
                        </form>
                    </dialog>
                    <script>
                        (() => {
                            const panel = document.getElementById('project-glossary');
                            const dialog = document.getElementById('glossary-edit-dialog');
                            const form = document.getElementById('glossary-edit-form');
                            const title = document.getElementById('glossary-dialog-title');
                            const error = document.getElementById('glossary-form-error');
                            if (!panel || !dialog || !form || !title || !error) return;

                            const apiUrl = new URL('project_detail.php', window.location.href);
                            apiUrl.searchParams.set('id', <?= $projectId ?>);
                            apiUrl.searchParams.set('locale', <?= json_encode($currentLocale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                            const request = async (action, values = {}) => {
                                const body = new URLSearchParams({
                                    action,
                                    project_id: '<?= $projectId ?>',
                                    locale: <?= json_encode($currentLocale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                    csrf_token: '<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>',
                                    ...values
                                });
                                const response = await fetch(apiUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                                    },
                                    body
                                });
                                const text = await response.text();
                                let result;
                                try {
                                    result = JSON.parse(text);
                                } catch {
                                    throw new Error(`Phản hồi không hợp lệ (HTTP ${response.status}).`);
                                }
                                if (!response.ok || !result.success) {
                                    throw new Error(result.message || 'Không thể cập nhật từ điển.');
                                }
                                return result;
                            };
                            const openDialog = (term = null) => {
                                form.reset();
                                form.elements.term_id.value = term?.id || '';
                                form.elements.term.value = term?.term || '';
                                form.elements.translation.value = term?.translation || '';
                                form.elements.description.value = term?.description || '';
                                title.textContent = term ? 'Sửa thuật ngữ' : 'Thêm thuật ngữ';
                                error.hidden = true;
                                error.textContent = '';
                                dialog.showModal();
                                form.elements.term.focus();
                            };

                            panel.querySelector('[data-glossary-add]')?.addEventListener('click', () => openDialog());
                            panel.querySelectorAll('[data-glossary-edit]').forEach((button) => {
                                button.addEventListener('click', () => openDialog({
                                    id: button.dataset.termId,
                                    term: button.dataset.term,
                                    translation: button.dataset.translation,
                                    description: button.dataset.description
                                }));
                            });
                            panel.querySelectorAll('[data-glossary-delete]').forEach((button) => {
                                button.addEventListener('click', async () => {
                                    if (!window.confirm(`Xóa thuật ngữ "${button.dataset.term}"?`)) return;
                                    button.disabled = true;
                                    try {
                                        await request('glossary_delete', {term_id: button.dataset.termId});
                                        window.location.reload();
                                    } catch (exception) {
                                        window.alert(exception instanceof Error ? exception.message : 'Không thể xóa thuật ngữ.');
                                        button.disabled = false;
                                    }
                                });
                            });
                            form.addEventListener('submit', async (event) => {
                                event.preventDefault();
                                const submit = form.querySelector('button[type="submit"]');
                                submit.disabled = true;
                                submit.textContent = 'Đang lưu...';
                                error.hidden = true;
                                const values = Object.fromEntries(new FormData(form).entries());
                                try {
                                    await request('glossary_save', values);
                                    window.location.reload();
                                } catch (exception) {
                                    error.textContent = exception instanceof Error ? exception.message : 'Không thể lưu thuật ngữ.';
                                    error.hidden = false;
                                } finally {
                                    submit.disabled = false;
                                    submit.textContent = 'Lưu thuật ngữ';
                                }
                            });
                            dialog.querySelectorAll('[data-glossary-close]').forEach((button) => {
                                button.addEventListener('click', () => dialog.close());
                            });
                            dialog.addEventListener('click', (event) => {
                                if (event.target === dialog) dialog.close();
                            });
                        })();
                    </script>
                <?php endif; ?>
            </div>

            <!-- Translation Table -->
            <div class="tms-bottom-section tms-reviewer-translation-section tms-main-panel tms-translation-panel tms-accent-indigo w-full col-span-12 h-full flex flex-col bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex min-w-0 flex-wrap items-center gap-3">
                        <h2 class="text-lg font-semibold text-gray-800"><span data-i18n="translation.language_content">Bản dịch ngôn ngữ:</span> <span class="uppercase text-indigo-600"><?= $currentLocale ?></span></h2>
                        <label class="tms-language-picker">
                            <span data-i18n="common.language">Ngôn ngữ</span>
                            <details class="tms-locale-dropdown">
                                <summary class="tms-locale-summary">🌐 <?= htmlspecialchars(strtoupper($currentLocale), ENT_QUOTES, 'UTF-8') ?></summary>
                                <div class="tms-locale-menu">
                                    <input type="search" class="tms-locale-search" placeholder="Tìm tên hoặc mã ngôn ngữ..." aria-label="Tìm ngôn ngữ" data-i18n-placeholder="translation.search_language_hint" data-i18n-aria-label="translation.search_language">
                                    <div class="tms-locale-options" role="listbox" aria-label="Danh sách ngôn ngữ" data-i18n-aria-label="translation.language_options">
                                        <?php foreach ($allLocales as $availableLocale): ?>
                                            <?php $localeCode = (string)$availableLocale['code']; ?>
                                            <div class="flex items-center gap-2 cursor-pointer rounded-md transition-colors hover:bg-indigo-50"
                                                 data-locale-row
                                                 data-locale-code="<?= htmlspecialchars($localeCode, ENT_QUOTES, 'UTF-8') ?>"
                                                 data-can-activate="<?= $currentProjectRole === 'Team Lead' ? 'true' : 'false' ?>">
                                                <a class="tms-locale-option <?= $localeCode === $currentLocale ? 'is-current' : '' ?>"
                                                   href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($localeCode) ?>&amp;<?= $versionQuery ?>"
                                                   role="option"
                                                   aria-selected="<?= $localeCode === $currentLocale ? 'true' : 'false' ?>"
                                                   data-locale-code="<?= htmlspecialchars($localeCode, ENT_QUOTES, 'UTF-8') ?>"
                                                   data-locale-name-en="<?= htmlspecialchars((string)$availableLocale['name_en'], ENT_QUOTES, 'UTF-8') ?>"
                                                   data-locale-name-vi="<?= htmlspecialchars((string)$availableLocale['name_vi'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <span><?= htmlspecialchars((string)$availableLocale['name_en'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)$availableLocale['name_vi'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <small><?= htmlspecialchars($localeCode, ENT_QUOTES, 'UTF-8') ?></small>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                        <p class="tms-locale-empty" data-i18n="translation.language_no_results" hidden>Không tìm thấy ngôn ngữ phù hợp</p>
                                    </div>
                                </div>
                            </details>
                        </label>
                    </div>
                    <section class="w-full rounded-lg border border-slate-200 bg-slate-50 p-4" aria-labelledby="translation-history-heading">
                        <h2 id="translation-history-heading" class="text-sm font-semibold text-slate-800">Lịch sử các ngôn ngữ đã dịch</h2>
                        <div id="translation-history-container" class="mt-3 flex flex-wrap gap-2 items-center" aria-live="polite">
                            <?php foreach ($translatedHistory as $historyLocale): ?>
                                <?php
                                $historyLocaleUpper = strtoupper($historyLocale);
                                $historyLocaleIsActive = $historyLocaleUpper === strtoupper($currentLocale);
                                $historyPillClass = $historyLocaleIsActive
                                    ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                                    : 'bg-indigo-50 text-indigo-700 border-indigo-100 hover:bg-indigo-100 hover:border-indigo-300 shadow-sm';
                                $historyLocaleUrl = $workspaceAction . '&locale=' . rawurlencode(strtolower($historyLocale)) . '&' . $versionQuery;
                                ?>
                                <a href="<?= htmlspecialchars($historyLocaleUrl, ENT_QUOTES, 'UTF-8') ?>"
                                   class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border mr-1 transition-all cursor-pointer <?= $historyPillClass ?>"
                                   <?= $historyLocaleIsActive ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($historyLocaleUpper, ENT_QUOTES, 'UTF-8') ?></a>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Các ngôn ngữ đã từng được chọn để dịch trong phiên này.</p>
                    </section>
                    <script>
                        document.querySelectorAll('.tms-locale-dropdown').forEach((dropdown) => {
                            const search = dropdown.querySelector('.tms-locale-search');
                            const optionsContainer = dropdown.querySelector('.tms-locale-options');
                            const options = [...dropdown.querySelectorAll('.tms-locale-option')];
                            const emptyState = dropdown.querySelector('.tms-locale-empty');
                            const optionRows = [...optionsContainer.children].filter((row) =>
                                row.querySelector('.tms-locale-option')
                            ).sort((first, second) => {
                                const firstOption = first.querySelector('.tms-locale-option');
                                const secondOption = second.querySelector('.tms-locale-option');
                                return firstOption.dataset.localeNameEn.localeCompare(
                                    secondOption.dataset.localeNameEn,
                                    'en',
                                    { sensitivity: 'base' }
                                ) || firstOption.dataset.localeCode.localeCompare(secondOption.dataset.localeCode);
                            });
                            const sortedOptions = document.createDocumentFragment();
                            optionRows.forEach((row) => sortedOptions.append(row));
                            optionsContainer?.prepend(sortedOptions);
                            optionRows.forEach((row) => {
                                row.addEventListener('click', (event) => {
                                    if (event.target === row.querySelector('.tms-locale-option') ||
                                        row.querySelector('.tms-locale-option')?.contains(event.target)) {
                                        return;
                                    }
                                    const option = row.querySelector('.tms-locale-option');
                                    option?.click();
                                });
                            });
                            const normalize = value => value
                                .normalize('NFD')
                                .replace(/[\u0300-\u036f]/g, '')
                                .toLocaleLowerCase();
                            search?.addEventListener('input', () => {
                                const query = normalize(search.value.trim());
                                let visibleCount = 0;
                                options.forEach((option) => {
                                    const searchable = normalize([
                                        option.dataset.localeCode,
                                        option.dataset.localeNameEn,
                                        option.dataset.localeNameVi,
                                    ].join(' '));
                                    const isVisible = query === '' || searchable.includes(query);
                                    option.hidden = !isVisible;
                                    const row = option.closest('[data-locale-row]');
                                    if (row) row.hidden = !isVisible;
                                    if (isVisible) visibleCount++;
                                });
                                if (emptyState) emptyState.hidden = visibleCount > 0;
                            });
                            dropdown.addEventListener('keydown', (event) => {
                                const visibleOptions = options.filter((option) => !option.hidden);
                                const activeIndex = visibleOptions.indexOf(document.activeElement);
                                if (event.key === 'Escape') {
                                    dropdown.open = false;
                                    dropdown.querySelector('summary')?.focus();
                                    return;
                                }
                                if (event.target === search && event.key === 'ArrowDown') {
                                    event.preventDefault();
                                    visibleOptions[0]?.focus();
                                    return;
                                }
                                if (event.target === search && event.key === 'Enter') {
                                    const firstVisible = visibleOptions[0];
                                    if (!firstVisible) return;
                                    event.preventDefault();
                                    firstVisible.closest('[data-locale-row]')?.click();
                                    return;
                                }
                                if (event.target.matches('.tms-locale-option')) {
                                    let nextIndex = activeIndex;
                                    if (event.key === 'ArrowDown') nextIndex++;
                                    else if (event.key === 'ArrowUp') nextIndex--;
                                    else if (event.key === 'Home') nextIndex = 0;
                                    else if (event.key === 'End') nextIndex = visibleOptions.length - 1;
                                    else return;
                                    event.preventDefault();
                                    visibleOptions[Math.max(0, Math.min(nextIndex, visibleOptions.length - 1))]?.focus();
                                }
                            });
                            dropdown.addEventListener('toggle', () => {
                                if (!dropdown.open) return;
                                search.value = '';
                                options.forEach((option) => { option.hidden = false; });
                                options.forEach((option) => {
                                    const row = option.closest('[data-locale-row]');
                                    if (row) row.hidden = false;
                                });
                                if (emptyState) emptyState.hidden = true;
                                search.focus();
                            });
                        });
                    </script>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full font-medium"><span data-i18n="translation.version_id">Phiên bản ID:</span> <?= $currentVersion['id'] ?></span>
                        <button type="button" data-open-live-preview aria-haspopup="dialog" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            👁️ <span data-i18n="translation.live_preview">Xem trước Live Preview</span>
                        </button>
                        <?php if ($currentVersion && $canTranslateCurrentProject): ?>
                                <button type="button" data-open-google-translate <?= $isVersionFrozen ? 'disabled title="Phiên bản đã chốt; không thể dịch tự động."' : '' ?> class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                                    🌐 <span data-i18n="translation.auto_translate_short">Dịch tự động</span>
                                </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($currentVersion && $canTranslateCurrentProject): ?>
                    <dialog class="tms-modal" id="google-auto-translate-dialog" aria-labelledby="google-auto-translate-title" style="width:min(36rem, calc(100vw - 2rem))">
                        <section class="tms-modal-card">
                            <p class="text-xs font-bold uppercase tracking-widest text-blue-600" data-i18n="translation.mymemory">MyMemory Dịch</p>
                            <h2 id="google-auto-translate-title" class="mt-1 text-xl font-bold text-slate-900" data-i18n="translation.auto_translate">Dịch tự động toàn bộ chuỗi</h2>
                            <label for="translator-provider" class="mt-4 block text-sm font-semibold text-slate-700">Nhà cung cấp dịch</label>
                            <select id="translator-provider" name="translator_provider" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="mymemory">MyMemory API (Mặc định)</option>
                                <option value="google">Google Translate (Free/API)</option>
                                <option value="libretranslate">LibreTranslate (Open Source)</option>
                            </select>
                            <p id="google-auto-translate-status" class="mt-3 text-sm text-slate-600" role="status" aria-live="polite" data-i18n="translation.ready">Sẵn sàng.</p>
                            <p id="google-auto-translate-eta" class="mt-1 text-xs text-slate-500" aria-live="polite" data-i18n="translation.not_started">Chưa bắt đầu.</p>
                            <label id="google-auto-translate-force-placeholders-control" class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" hidden>
                                <input id="google-auto-translate-force-placeholders" type="checkbox" class="mt-0.5">
                                <span data-i18n="translation.force_placeholder_save">Buộc lưu các câu lỗi (bỏ qua kiểm tra biến)</span>
                            </label>
                            <p id="google-auto-translate-progress-label" class="mt-3 text-xs font-medium text-slate-600" data-i18n="translation.checking_progress">Đang kiểm tra tiến độ...</p>
                            <div id="google-auto-translate-progress" class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100" role="progressbar" aria-label="Tiến độ bản dịch" data-i18n-aria-label="translation.translation_progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <span id="google-auto-translate-bar" class="block h-full w-0 rounded-full bg-blue-600 transition-all duration-300"></span>
                            </div>
                            <div class="mt-5 flex justify-end gap-2">
                                <button type="button" class="tms-modal-cancel" data-close-google-auto-translate data-i18n="common.close">Đóng</button>
                                <button type="button" id="google-auto-translate-start" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" data-i18n="translation.start">Bắt đầu dịch</button>
                            </div>
                            <input type="hidden" id="google-auto-translate-csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        </section>
                    </dialog>
                    <?php endif; ?>
                </div>
                <form method="get" class="tms-translation-filters">
                    <input type="hidden" name="id" value="<?= $projectId ?>">
                    <input type="hidden" name="locale" value="<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                    <input type="hidden" name="per_page" value="<?= (int)$pageSize ?>">
                    <label>
                        <span data-i18n="translation.status_filter">Trạng thái</span>
                        <select name="status">
                            <option value="" data-i18n="translation.all_statuses">Tất cả trạng thái</option>
                            <option value="draft" <?= in_array($statusFilter, ['draft', 'translated'], true) ? 'selected' : '' ?>>Bản nháp</option>
                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Đã duyệt</option>
                            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Từ chối</option>
                            <option value="needs_review" <?= $statusFilter === 'needs_review' ? 'selected' : '' ?>>Cần kiểm tra</option>
                        </select>
                    </label>
                    <label class="tms-search-field">
                        <span data-i18n="translation.search">Tìm kiếm</span>
                        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Key, văn bản gốc hoặc bản dịch" data-i18n-placeholder="translation.search_hint">
                    </label>
                    <button type="submit" class="tms-button-primary bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold" data-i18n="translation.filter">Lọc</button>
                    <a class="tms-filter-reset" href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>" data-i18n="translation.clear_filters">Xóa lọc</a>
                </form>
                <?php if ($currentVersion && $canTranslateCurrentProject): ?>
                    <div id="translation-bulk-toolbar" class="tms-bulk-toolbar mx-4 mb-3" style="display:none" hidden>
                        <span class="tms-bulk-selection">
                            <span class="tms-bulk-selection-icon" aria-hidden="true">✓</span>
                            <span id="translation-bulk-count" class="text-sm font-semibold text-indigo-900" aria-live="polite" data-i18n="translation.bulk_selected_count" data-i18n-count="0">0 dòng được chọn</span>
                            <label id="translation-skip-non-text-control" class="tms-skip-non-text-control" hidden>
                                <input id="translation-skip-non-text" type="checkbox" <?= $isVersionFrozen ? 'disabled' : '' ?>>
                                <span data-i18n="translation.skip_non_text">Ẩn nội dung không phải văn bản</span>
                            </label>
                        </span>
                        <span class="tms-bulk-divider" aria-hidden="true"></span>
                        <div class="tms-bulk-controls">
                        <label class="sr-only" for="translation-bulk-status">Trạng thái áp dụng cho các dòng đã chọn</label>
                        <select id="translation-bulk-status" class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm" <?= $isVersionFrozen ? 'disabled' : '' ?>>
                            <option value="">Giữ trạng thái từng dòng</option>
                            <?php if ($canReviewCurrentProject): ?>
                                <option value="draft">Bản nháp</option>
                                <option value="approved">Đã duyệt</option>
                                <option value="rejected">Từ chối</option>
                                <option value="needs_review">Cần kiểm tra</option>
                            <?php else: ?>
                                <option value="draft">Bản nháp</option>
                                <option value="needs_review">Cần kiểm tra</option>
                            <?php endif; ?>
                        </select>
                        <button id="translation-bulk-apply-status" type="button" class="rounded-lg border border-indigo-300 bg-white px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50" <?= $isVersionFrozen ? 'disabled' : '' ?>>
                            Lưu trạng thái hàng loạt
                        </button>
                        <button id="translation-bulk-save" type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" <?= $isVersionFrozen ? 'disabled' : '' ?>>
                            Lưu thay đổi
                        </button>
                        </div>
                    </div>
                    <p id="translation-global-selection" class="mx-4 mb-3 flex flex-wrap items-center gap-2 text-sm text-indigo-900" data-total-count="<?= (int)$totalStrings ?>" role="status" aria-live="polite" hidden>
                        <span id="translation-global-selection-summary" data-i18n="translation.select_page_summary" data-i18n-count="0"></span>
                        <button id="translation-select-all-matching" type="button" class="font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900" data-i18n="translation.select_all_matching" data-i18n-count="<?= (int)$totalStrings ?>">Chọn tất cả <?= (int)$totalStrings ?> bản ghi trong toàn bộ kết quả lọc</button>
                        <button id="translation-clear-global-selection" type="button" class="font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900" data-i18n="translation.clear_selection" hidden>Bỏ chọn</button>
                        <span id="translation-global-selection-note" class="basis-full text-xs text-slate-600" data-i18n="translation.global_status_only" hidden>Chọn toàn bộ chỉ áp dụng cho cập nhật trạng thái; nội dung dịch chỉ áp dụng cho các dòng đang tải.</span>
                    </p>
                    <p id="translation-bulk-message" class="mx-4 mb-3 text-sm text-slate-700" role="status" aria-live="polite" hidden></p>
                <?php endif; ?>
                <?php if ($currentVersion && $canTranslateCurrentProject): ?>
                    <dialog class="tms-modal tms-conflict-modal backdrop:backdrop-blur-md backdrop:bg-black/40" id="translation-conflict-dialog" aria-labelledby="translation-conflict-title" style="width:min(48rem, calc(100vw - 2rem))">
                        <section class="tms-modal-card tms-conflict-card rounded-2xl border border-slate-100 p-6 shadow-2xl sm:p-8">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-widest text-amber-700">409 Conflict</p>
                                    <h2 id="translation-conflict-title" class="mt-1 text-xl font-bold text-slate-900"><span aria-hidden="true">⚠️</span> Cảnh báo xung đột</h2>
                                </div>
                                <button type="button" class="tms-modal-close border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-100" aria-label="Đóng" data-close-translation-conflict>×</button>
                            </div>
                            <p class="mt-3 text-sm text-slate-700">Bản dịch này đã được cập nhật bởi người dùng khác từ phiên bản sau. Vui lòng tải lại dữ liệu mới nhất để xem thay đổi.</p>
                            <div id="translation-conflict-details" class="mt-4 space-y-3" aria-live="polite"></div>
                            <div class="mt-5 flex justify-end">
                                <button type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400" data-close-translation-conflict>Đóng</button>
                            </div>
                        </section>
                    </dialog>
                <?php endif; ?>
                <div class="tms-table-wrap tms-translation-table-wrap overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <colgroup>
                            <col class="tms-translation-select-column">
                            <col class="tms-translation-key-column">
                            <col class="tms-translation-source-column">
                            <col class="tms-translation-target-column">
                            <col class="tms-translation-status-column">
                            <col class="tms-translation-actions-column">
                        </colgroup>
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                <th class="px-4 py-3 font-semibold text-center">
                                    <input id="translation-select-all" type="checkbox" aria-label="Chọn tất cả dòng có thể chỉnh sửa" data-i18n-aria-label="translation.select_all" <?= $isVersionFrozen || !$canTranslateCurrentProject ? 'disabled' : '' ?>>
                                </th>
                                <th class="px-6 py-3 font-semibold w-1/5" data-i18n="translation.key">Khóa (Key Path)</th>
                                <th class="px-6 py-3 font-semibold w-1/4" data-i18n="translation.header_source">Văn bản gốc</th>
                                <th class="px-6 py-3 font-semibold w-1/3" data-i18n="translation.header_target">Bản dịch</th>
                                <th class="px-6 py-3 font-semibold" data-i18n="translation.header_status">Trạng thái</th>
                                <th class="px-6 py-3 font-semibold text-right" data-i18n="common.actions">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php foreach ($strings as $row): ?>
                                <?php $canEditString = ProjectAccess::canEditTranslationForRole($currentProjectRole);
                                $isLockedByOther = $row['locked_by_user_id'] !== null;
                                $translationFormId = 'translation-form-' . (int)$row['string_id'];
                                ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-4 text-center align-middle" data-i18n-ignore>
                                        <input class="translation-row-select" type="checkbox" value="<?= (int)$row['string_id'] ?>"
                                               aria-label="Chọn dòng <?= htmlspecialchars($row['key_path'], ENT_QUOTES, 'UTF-8') ?>"
                                               data-key-path="<?= htmlspecialchars((string)$row['key_path'], ENT_QUOTES, 'UTF-8') ?>"
                                               data-source-text="<?= htmlspecialchars((string)$row['source_text'], ENT_QUOTES, 'UTF-8') ?>"
                                               <?= $isVersionFrozen || !$canEditString || $isLockedByOther ? 'disabled' : '' ?>>
                                    </td>
                                    <td class="px-6 py-4 font-mono text-indigo-600 font-medium align-middle" data-i18n-ignore>
                                            <form id="<?= $translationFormId ?>" method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                                                <input type="hidden" name="action" value="save_translation">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="string_id" value="<?= (int)$row['string_id'] ?>">
                                                <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                <input type="hidden" name="per_page" value="<?= (int)$pageSize ?>">
                                                <input type="hidden" name="base_version" value="<?= htmlspecialchars($row['translation_updated_at'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="response_format" value="json">
                                            </form>
                                            <?= htmlspecialchars($row['key_path']) ?>
                                        </td>
                                        <td class="px-6 py-4 text-gray-600 align-middle" data-i18n-ignore>
                                            <?= htmlspecialchars($row['source_text']) ?>
                                        </td>
                                        <td class="px-6 py-4 align-middle" data-i18n-ignore>
                                            <div class="tms-translation-input-group">
                                            <input type="text" form="<?= $translationFormId ?>" name="translated_text" value="<?= htmlspecialchars($row['translated_text'], ENT_QUOTES, 'UTF-8') ?>"
                                                   data-saved-text="<?= htmlspecialchars($row['translated_text'], ENT_QUOTES, 'UTF-8') ?>"
                                                   data-source-text="<?= htmlspecialchars((string)$row['source_text'], ENT_QUOTES, 'UTF-8') ?>"
                                                   <?= $isVersionFrozen || !$canEditString || $isLockedByOther ? 'disabled' : '' ?> class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-100 text-sm">
                                            <?php if ($isLockedByOther): ?>
                                                <span class="tms-lock-badge" title="Đang được thành viên khác chỉnh sửa" data-i18n-title="translation.locked_badge">🔒 <span data-i18n="translation.locked_badge">Đang chỉnh sửa</span></span>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 align-middle" data-i18n-ignore>
                                            <?php
                                            $statusLabels = [
                                                'draft' => 'Bản nháp',
                                                'approved' => 'Đã duyệt',
                                                'rejected' => 'Từ chối',
                                                'needs_review' => 'Cần kiểm tra',
                                            ];
                                            $statusValue = (string)$row['status'];
                                            $hasTranslation = trim((string)$row['translated_text']) !== '';
                                            $statusLabel = !$hasTranslation
                                                ? 'Chưa dịch'
                                                : ($statusLabels[$statusValue] ?? 'Đã dịch');
                                            if ($hasTranslation && $statusValue === 'draft') {
                                                $statusLabel = 'Đã dịch';
                                            }
                                            $statusI18nKey = !$hasTranslation
                                                ? 'translation.untranslated'
                                                : match ($statusValue) {
                                                    'approved' => 'translation.approved',
                                                    'rejected' => 'translation.rejected',
                                                    'needs_review' => 'translation.needs_review',
                                                    default => 'translation.translated',
                                                };
                                            $statusBadgeId = 'translation-status-' . (int)$row['string_id'];
                                            ?>
                                            <input type="hidden" form="<?= $translationFormId ?>" name="status"
                                                   value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"
                                                   data-status-badge-id="<?= $statusBadgeId ?>">
                                            <span id="<?= $statusBadgeId ?>"
                                                  class="tms-status tms-status-<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?><?= $hasTranslation ? '' : ' tms-status-empty' ?> inline-flex items-center px-3 py-1 text-xs"
                                                  role="status"
                                                  aria-label="Trạng thái bản dịch <?= htmlspecialchars($row['key_path'], ENT_QUOTES, 'UTF-8') ?>"
                                                  data-i18n="<?= $statusI18nKey ?>"
                                                  data-status="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-right align-middle" data-i18n-ignore>
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <button type="submit" form="<?= $translationFormId ?>" <?= $isVersionFrozen || !$canEditString || $isLockedByOther ? 'disabled' : '' ?> class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" data-i18n="translation.save_draft">Lưu nháp</button>
                                                <?php if (trim((string)$row['translated_text']) !== '' && (int)$row['history_count'] > 0): ?>
                                                    <button type="button"
                                                            class="tms-history-link"
                                                            data-open-translation-history
                                                            data-string-id="<?= (int)$row['string_id'] ?>"
                                                            data-key-path="<?= htmlspecialchars($row['key_path'], ENT_QUOTES, 'UTF-8') ?>">Lịch sử</button>
                                                <?php endif; ?>
                                                <a href="translation_comments.php?string_id=<?= (int)$row['string_id'] ?>&amp;locale=<?= rawurlencode($currentLocale) ?>" class="tms-history-link" data-i18n="translation.comments">Nhận xét</a>
                                            </div>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <nav class="tms-key-pagination" aria-label="Phân trang chuỗi dịch">
                    <span class="text-sm text-slate-500">
                        <span class="tms-visible-row-count"><strong id="translation-visible-row-count"><?= count($strings) ?></strong> <span data-i18n="translation.visible_rows">dòng hiển thị trên trang này</span> · </span>
                        <?= $totalStrings > 0 ? (($page - 1) * $pageSize + 1) . '–' . min($page * $pageSize, $totalStrings) : '0' ?>
                        / <?= $totalStrings ?> keys
                    </span>
                    <div class="flex items-center gap-1">
                        <?php
                        $paginationUrl = static function (int $targetPage) use ($workspaceAction, $currentLocale, $versionId, $statusFilter, $searchQuery, $pageSize): string {
                            $query = http_build_query([
                                'locale' => $currentLocale,
                                'version_id' => $versionId,
                                'status' => $statusFilter,
                                'q' => $searchQuery,
                                'per_page' => $pageSize,
                                'page' => $targetPage,
                            ]);
                            return $workspaceAction . '&' . $query;
                        };
                        ?>
                        <a class="tms-page-button <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($paginationUrl(max(1, $page - 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Trang trước">←</a>
                        <?php for ($paginationPage = 1; $paginationPage <= $totalPages; $paginationPage++): ?>
                            <a class="tms-page-button <?= $paginationPage === $page ? 'is-current' : '' ?>" href="<?= htmlspecialchars($paginationUrl($paginationPage), ENT_QUOTES, 'UTF-8') ?>"><?= $paginationPage ?></a>
                        <?php endfor; ?>
                        <a class="tms-page-button <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($paginationUrl(min($totalPages, $page + 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Trang sau">→</a>
                    </div>
                </nav>
            </div>

            <?php if ($currentVersion && $canTranslateCurrentProject): ?>
            <script>
                (() => {
                    const toolbar = document.getElementById('translation-bulk-toolbar');
                    const selectAll = document.getElementById('translation-select-all');
                    const selectedCount = document.getElementById('translation-bulk-count');
                    const bulkStatus = document.getElementById('translation-bulk-status');
                    const applyStatusButton = document.getElementById('translation-bulk-apply-status');
                    const saveButton = document.getElementById('translation-bulk-save');
                    const message = document.getElementById('translation-bulk-message');
                    const skipNonTextControl = document.getElementById('translation-skip-non-text-control');
                    const skipNonText = document.getElementById('translation-skip-non-text');
                    const globalSelectionNotice = document.getElementById('translation-global-selection');
                    const globalSelectionSummary = document.getElementById('translation-global-selection-summary');
                    const selectAllMatchingButton = document.getElementById('translation-select-all-matching');
                    const clearGlobalSelectionButton = document.getElementById('translation-clear-global-selection');
                    const globalSelectionNote = document.getElementById('translation-global-selection-note');
                    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const canReview = <?= $canReviewCurrentProject ? 'true' : 'false' ?>;
                    const conflictDialog = document.getElementById('translation-conflict-dialog');
                    const conflictDetails = document.getElementById('translation-conflict-details');
                    const allRowCheckboxes = [...document.querySelectorAll('.translation-row-select')];
                    const rowCheckboxes = [...document.querySelectorAll('.translation-row-select:not(:disabled)')];
                    let busy = false;
                    let isAllPagesSelected = false;
                    const totalMatchingRows = Number(globalSelectionNotice.dataset.totalCount) || 0;
                    let activeDictionary = null;
                    const localizedCount = (key, count, fallback) => {
                        const template = activeDictionary?.[key];
                        return typeof template === 'string'
                            ? template.replaceAll('{count}', String(count))
                            : fallback;
                    };
                    const showConflictDialog = conflicts => {
                        if (!conflictDialog || !conflictDetails) return;
                        conflictDetails.replaceChildren();
                        conflicts.forEach(conflict => {
                            const item = document.createElement('article');
                            item.className = 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
                            const metadata = document.createElement('div');
                            metadata.className = 'flex flex-wrap items-center gap-2';
                            const keyTag = document.createElement('code');
                            keyTag.className = 'rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 font-mono text-xs font-semibold text-indigo-800';
                            keyTag.textContent = conflict.keyPath || `ID ${conflict.stringId}`;
                            const editorTag = document.createElement('span');
                            editorTag.className = 'inline-flex items-center gap-1 text-xs text-slate-600';
                            editorTag.textContent = `👤 ${conflict.updatedBy || 'Người dùng không xác định'}`;
                            const timeTag = document.createElement('span');
                            timeTag.className = 'inline-flex items-center gap-1 text-xs text-slate-600';
                            timeTag.textContent = `🕒 ${conflict.updatedAt || 'thời gian không xác định'}`;
                            metadata.append(keyTag, editorTag, timeTag);
                            const comparison = document.createElement('div');
                            comparison.className = 'mt-3 grid gap-3 sm:grid-cols-2';
                            [
                                {
                                    label: 'Bản hiện tại trong cơ sở dữ liệu',
                                    text: conflict.currentText ?? '(bản dịch trống hoặc đã bị xóa)',
                                    classes: 'border-amber-200 bg-amber-50/60 text-amber-900',
                                    preClasses: 'border-amber-100 bg-white/80 text-amber-950',
                                },
                                {
                                    label: 'Nội dung bạn định lưu',
                                    text: conflict.submittedText ?? '',
                                    classes: 'border-slate-200 bg-slate-50 text-slate-800',
                                    preClasses: 'border-slate-200 bg-white text-slate-800',
                                },
                            ].forEach(({label, text, classes, preClasses}) => {
                                const section = document.createElement('section');
                                section.className = `rounded-xl border p-3 ${classes}`;
                                const caption = document.createElement('p');
                                caption.className = 'text-xs font-semibold uppercase tracking-wide';
                                caption.textContent = label;
                                const value = document.createElement('pre');
                                value.className = `mt-2 min-h-20 whitespace-pre-wrap break-words rounded-lg border p-3 text-sm ${preClasses}`;
                                value.textContent = text;
                                section.append(caption, value);
                                comparison.append(section);
                            });
                            const actions = document.createElement('div');
                            actions.className = 'mt-3 flex justify-end';
                            const refreshButton = document.createElement('button');
                            refreshButton.type = 'button';
                            refreshButton.className = 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:scale-[1.02] hover:bg-indigo-700 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2';
                            refreshButton.textContent = 'Làm mới dòng này';
                            refreshButton.addEventListener('click', () => {
                                const formId = `translation-form-${conflict.stringId}`;
                                const form = document.getElementById(formId);
                                const input = document.querySelector(`input[name="translated_text"][form="${formId}"]`);
                                const statusInput = document.querySelector(`[name="status"][form="${formId}"]`);
                                if (input) {
                                    input.value = conflict.currentText ?? '';
                                    input.dataset.savedText = input.value;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                                if (statusInput) window.tmsUpdateStatusBadge(statusInput, conflict.currentStatus || 'draft');
                                const baseline = form?.querySelector('[name="base_version"]');
                                if (baseline) baseline.value = conflict.updatedAt || '';
                                const checkbox = rowCheckboxes.find(row => Number(row.value) === Number(conflict.stringId));
                                if (checkbox) checkbox.checked = false;
                                if (message) {
                                    message.hidden = false;
                                    message.textContent = 'Đã làm mới dòng xung đột. Các dòng khác chưa được lưu và vẫn giữ nguyên.';
                                }
                                refreshToolbar();
                                conflictDialog.close();
                            });
                            actions.append(refreshButton);
                            item.append(metadata, comparison, actions);
                            conflictDetails.append(item);
                        });
                        conflictDialog.showModal();
                    };
                    conflictDialog?.querySelectorAll('[data-close-translation-conflict]').forEach(button => {
                        button.addEventListener('click', () => conflictDialog.close());
                    });
                    conflictDialog?.addEventListener('click', event => {
                        if (event.target === conflictDialog) conflictDialog.close();
                    });
                    const isNonTextRow = checkbox => {
                        const keyPath = (checkbox.dataset.keyPath || '').toLowerCase();
                        const sourceText = (checkbox.dataset.sourceText || '').trim();
                        return /(?:^|[._-])(?:id|image|price)(?:$|[._-])/.test(keyPath)
                            || /^(?:[a-z][a-z\d+.-]*:\/\/|\/\/|www\.)/i.test(sourceText)
                            || /^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(sourceText)
                            || /^(?:true|false)$/i.test(sourceText);
                    };
                    const refreshNonTextControl = () => {
                        const hasNonText = allRowCheckboxes.some(isNonTextRow);
                        const hasSelection = rowCheckboxes.some(checkbox => checkbox.checked);
                        skipNonTextControl.hidden = !hasNonText || !hasSelection;
                        if (!hasNonText) skipNonText.checked = false;
                        return hasNonText;
                    };
                    const selectAllTargets = () => rowCheckboxes.filter(checkbox =>
                        !checkbox.closest('tr')?.hidden &&
                        (!skipNonText.checked || !isNonTextRow(checkbox))
                    );
                    const applySelectAll = checked => {
                        refreshNonTextControl();
                        rowCheckboxes.forEach(checkbox => {
                            checkbox.checked = checked
                                && !checkbox.closest('tr')?.hidden
                                && (!skipNonText.checked || !isNonTextRow(checkbox));
                        });
                    };
                    const applyNonTextVisibility = () => {
                        allRowCheckboxes.forEach(checkbox => {
                            const row = checkbox.closest('tr');
                            if (!row) return;
                            const shouldHide = skipNonText.checked && isNonTextRow(checkbox);
                            row.hidden = shouldHide;
                            if (shouldHide && checkbox.checked) checkbox.checked = false;
                        });
                        const visibleRows = allRowCheckboxes.filter(checkbox => !checkbox.closest('tr')?.hidden);
                        document.getElementById('translation-visible-row-count').textContent = String(visibleRows.length);
                    };
                    window.tmsSetTranslationUpdatedAt = (stringId, updatedAt) => {
                        if (updatedAt === null || updatedAt === undefined || updatedAt === '') return;
                        const form = document.getElementById(`translation-form-${stringId}`);
                        const baseline = form?.querySelector('[name="base_version"]');
                        if (baseline) baseline.value = String(updatedAt);
                    };
                    window.tmsUpdateStatusBadge = (statusInput, status) => {
                        if (!statusInput) return;
                        statusInput.value = status;
                        const badge = document.getElementById(statusInput.dataset.statusBadgeId || '');
                        if (!badge) return;
                        const formId = statusInput.getAttribute('form');
                        const translatedText = document.querySelector(
                            `input[name="translated_text"][form="${formId}"]`
                        )?.value.trim() || '';
                        const label = translatedText === ''
                            ? 'Chưa dịch'
                            : ({
                                approved: 'Đã duyệt',
                                rejected: 'Từ chối',
                                needs_review: 'Cần kiểm tra'
                            }[status] || 'Đã dịch');
                        badge.dataset.i18n = translatedText === ''
                            ? 'translation.untranslated'
                            : ({
                                approved: 'translation.approved',
                                rejected: 'translation.rejected',
                                needs_review: 'translation.needs_review'
                            }[status] || 'translation.translated');
                        badge.className = `tms-status tms-status-${status}${translatedText === '' ? ' tms-status-empty' : ''} inline-flex items-center px-3 py-1 text-xs`;
                        badge.dataset.status = status;
                        badge.textContent = label;
                    };
                    document.querySelectorAll('.tms-translation-table-wrap input[name="translated_text"]').forEach((input) => {
                        input.addEventListener('input', () => {
                            const formId = input.getAttribute('form');
                            const statusInput = document.querySelector(
                                `[name="status"][form="${formId}"]`
                            );
                            const historyButton = input.closest('tr')?.querySelector('[data-open-translation-history]');
                            if (historyButton) {
                                historyButton.hidden = input.value.trim() === '';
                            }
                            if (!statusInput) return;
                            const nextStatus = input.value.trim() === ''
                                ? 'draft'
                                : statusInput.value === 'needs_review'
                                    ? 'needs_review'
                                    : 'draft';
                            window.tmsUpdateStatusBadge(statusInput, nextStatus);
                        });
                    });

                    const selectedRows = () => rowCheckboxes.filter(
                        checkbox => checkbox.checked && !checkbox.closest('tr')?.hidden
                    );
                    const refreshToolbar = () => {
                        const selected = selectedRows();
                        toolbar.hidden = selected.length === 0 && !isAllPagesSelected;
                        toolbar.style.display = toolbar.hidden ? 'none' : 'flex';
                        refreshNonTextControl();
                        const selectedCountValue = isAllPagesSelected ? totalMatchingRows : selected.length;
                        selectedCount.dataset.i18nCount = String(selectedCountValue);
                        selectedCount.textContent = localizedCount(
                            'translation.bulk_selected_count',
                            selectedCountValue,
                            `${selectedCountValue} dòng được chọn`
                        );
                        saveButton.disabled = busy || selected.length === 0 || isAllPagesSelected;
                        applyStatusButton.disabled = busy ||
                            (selected.length === 0 && !isAllPagesSelected) ||
                            !bulkStatus?.value;
                        const targets = selectAllTargets();
                        const selectedTargets = targets.filter(checkbox => checkbox.checked).length;
                        const pageFullySelected = targets.length > 0 && selectedTargets === targets.length;
                        selectAll.checked = targets.length > 0 && selectedTargets === targets.length;
                        selectAll.indeterminate = selectedTargets > 0 && selectedTargets < targets.length;
                        const canSelectAllMatching = pageFullySelected &&
                            totalMatchingRows > targets.length &&
                            !skipNonText.checked;
                        globalSelectionNotice.hidden = !isAllPagesSelected && !canSelectAllMatching;
                        globalSelectionSummary.dataset.i18n = isAllPagesSelected
                            ? 'translation.global_selected'
                            : 'translation.select_page_summary';
                        globalSelectionSummary.dataset.i18nCount = String(
                            isAllPagesSelected ? totalMatchingRows : selectedTargets
                        );
                        globalSelectionSummary.textContent = localizedCount(
                            isAllPagesSelected
                                ? 'translation.global_selected'
                                : 'translation.select_page_summary',
                            isAllPagesSelected ? totalMatchingRows : selectedTargets,
                            isAllPagesSelected
                                ? `Đã chọn tất cả ${totalMatchingRows} bản ghi trong toàn bộ kết quả lọc.`
                                : `Tất cả ${selectedTargets} dòng trên trang này đã được chọn.`
                        );
                        selectAllMatchingButton.hidden = isAllPagesSelected || !canSelectAllMatching;
                        selectAllMatchingButton.disabled = busy;
                        clearGlobalSelectionButton.hidden = !isAllPagesSelected;
                        clearGlobalSelectionButton.disabled = busy;
                        globalSelectionNote.hidden = !isAllPagesSelected;
                    };
                    document.addEventListener('tms:language-changed', event => {
                        activeDictionary = event.detail.dictionary;
                        refreshToolbar();
                    });
                    const setBusy = value => {
                        busy = value;
                        rowCheckboxes.forEach(checkbox => { checkbox.disabled = value; });
                        selectAll.disabled = value || rowCheckboxes.length === 0 || <?= $isVersionFrozen ? 'true' : 'false' ?>;
                        skipNonText.disabled = value || rowCheckboxes.length === 0 || <?= $isVersionFrozen ? 'true' : 'false' ?>;
                        bulkStatus.disabled = value;
                        refreshToolbar();
                    };
                    const readTranslationSaveResponse = async response => {
                        const rawBody = await response.text();
                        const contentType = response.headers.get('content-type') || '';
                        if (/^(?:<!doctype\s+html\b|<html\b)/i.test(rawBody.trimStart())) {
                            console.error('[TMS Translation Save] Server returned HTML instead of JSON.', {
                                url: response.url,
                                status: response.status,
                                contentType,
                                body: rawBody.slice(0, 2000),
                            });
                            throw new Error(`Máy chủ trả về HTML thay vì JSON (HTTP ${response.status}). Vui lòng kiểm tra URL hoặc lỗi backend.`);
                        }
                        try {
                            return JSON.parse(rawBody);
                        } catch (error) {
                            console.error('[TMS Translation Save] Server returned invalid JSON.', {
                                url: response.url,
                                status: response.status,
                                contentType,
                                body: rawBody.slice(0, 2000),
                                error,
                            });
                            throw new Error(`Phản hồi lưu bản dịch không phải JSON hợp lệ (HTTP ${response.status}).`);
                        }
                    };
                    const createPayloadRows = statusOverride => selectedRows().map(checkbox => {
                        const formId = `translation-form-${checkbox.value}`;
                        const translatedText = document.querySelector(
                            `input[name="translated_text"][form="${formId}"]`
                        );
                        const statusInput = document.querySelector(
                            `[name="status"][form="${formId}"]`
                        );
                        const currentStatus = statusOverride || statusInput?.value || 'draft';
                        const contentChanged = (translatedText?.value || '') !== (translatedText?.dataset.savedText || '');
                        return {
                            stringId: Number(checkbox.value),
                            base_version: document.getElementById(formId)?.querySelector('[name="base_version"]')?.value || null,
                            translatedText: translatedText?.value || '',
                            status: contentChanged && currentStatus === 'approved'
                                ? 'needs_review'
                                : !canReview &&
                                    !['draft', 'needs_review'].includes(currentStatus) &&
                                    contentChanged
                                    ? 'needs_review'
                                    : currentStatus,
                        };
                    });
                    const createStatusPayloadRows = status => selectedRows().map(checkbox => {
                        const formId = `translation-form-${checkbox.value}`;
                        return {
                            stringId: Number(checkbox.value),
                            base_version: document.getElementById(formId)?.querySelector('[name="base_version"]')?.value || null,
                            translatedText: document.querySelector(
                                `input[name="translated_text"][form="${formId}"]`
                            )?.value || '',
                            status
                        };
                    });
                    const saveSelected = async (statusOverride, statusOnly = false) => {
                        const selected = selectedRows();
                        const globalAction = isAllPagesSelected;
                        if (busy || (!globalAction && selected.length === 0)) return;
                        if (globalAction && !statusOnly) {
                            message.hidden = false;
                            message.textContent = 'Chọn toàn bộ kết quả chỉ áp dụng cho thao tác đổi trạng thái.';
                            return;
                        }
                        setBusy(true);
                        message.hidden = false;
                        message.textContent = 'Đang lưu...';
                        try {
                            const endpoint = new URL(window.location.href);
                            endpoint.searchParams.set('action', 'bulk_save_translations');
                            const requestedStatus = statusOverride || bulkStatus?.value || '';
                            if (globalAction && !requestedStatus) {
                                message.hidden = false;
                                message.textContent = 'Hãy chọn trạng thái cần áp dụng cho toàn bộ kết quả.';
                                return;
                            }
                            const response = await fetch(endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                cache: 'no-store',
                                headers: {
                                    Accept: 'application/json',
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    csrf_token: csrfToken,
                                    select_all: globalAction,
                                    statusOnly: statusOnly || globalAction,
                                    project_id: <?= (int)$projectId ?>,
                                    version_id: <?= (int)$currentVersion['id'] ?>,
                                    locale: <?= json_encode($currentLocale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                    search: endpoint.searchParams.get('q') || '',
                                    filter_status: endpoint.searchParams.get('status') || '',
                                    new_status: requestedStatus,
                                    translations: globalAction
                                        ? []
                                        : statusOnly
                                            ? createStatusPayloadRows(requestedStatus)
                                            : createPayloadRows(null)
                                })
                            });
                            const result = await readTranslationSaveResponse(response);
                            if (!response.ok || !result.success) {
                                const isConflict = result.error_code === 'CONFLICT'
                                    || (response.status === 409 && Array.isArray(result.conflicts));
                                if (isConflict && Array.isArray(result.conflicts)) {
                                    showConflictDialog(result.conflicts);
                                    message.textContent = result.message || 'Phát hiện xung đột khi lưu.';
                                    return;
                                }
                                throw new Error(result.message || `Lưu thất bại (HTTP ${response.status}).`);
                            }
                            if (globalAction) {
                                rowCheckboxes.forEach(checkbox => { checkbox.checked = false; });
                                isAllPagesSelected = false;
                                bulkStatus.value = '';
                                selectAll.checked = false;
                                const skippedMessage = result.skippedLockedCount > 0
                                    ? ` ${result.skippedLockedCount} dòng bị bỏ qua vì đang được người khác chỉnh sửa.`
                                    : '';
                                message.textContent = `${result.message}${skippedMessage}`;
                                window.setTimeout(() => window.location.reload(), 700);
                                return;
                            }
                            result.translations.forEach(row => {
                                const formId = `translation-form-${row.stringId}`;
                                const translatedText = document.querySelector(
                                    `input[name="translated_text"][form="${formId}"]`
                                );
                                const statusInput = document.querySelector(
                                    `[name="status"][form="${formId}"]`
                                );
                                if (translatedText) {
                                    translatedText.value = row.translatedText;
                                    translatedText.dataset.savedText = row.translatedText;
                                    translatedText.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                                window.tmsUpdateStatusBadge(statusInput, row.status);
                                window.tmsSetTranslationUpdatedAt(row.stringId, row.updatedAt);
                            });
                            selected.forEach(checkbox => { checkbox.checked = false; });
                            bulkStatus.value = '';
                            message.textContent = result.message;
                            console.log('[TMS Bulk Actions] Lưu thành công.', {
                                mode: statusOnly ? 'status-only' : 'content-and-status',
                                rows: result.translations?.length || 0,
                                status: statusOverride || 'per-row'
                            });
                        } catch (error) {
                            message.textContent = error instanceof Error ? error.message : 'Không thể lưu các dòng đã chọn.';
                            console.error('[TMS Bulk Actions] Không thể lưu các dòng đã chọn.', error);
                        } finally {
                            setBusy(false);
                        }
                    };

                    document.querySelectorAll('form[id^="translation-form-"]').forEach(form => {
                        form.addEventListener('submit', async event => {
                            event.preventDefault();
                            const submitButton = event.submitter;
                            if (submitButton) submitButton.disabled = true;
                            try {
                                const formEndpoint = new URL(form.getAttribute('action') || window.location.href, window.location.href);
                                if (formEndpoint.origin !== window.location.origin) {
                                    throw new Error('URL lưu bản dịch không cùng website hiện tại.');
                                }
                                const endpoint = new URL(window.location.pathname, window.location.origin);
                                endpoint.search = formEndpoint.search;
                                const response = await fetch(endpoint, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    cache: 'no-store',
                                    headers: {Accept: 'application/json'},
                                    body: new FormData(form),
                                });
                                const result = await readTranslationSaveResponse(response);
                                if (!response.ok || !result.success) {
                                    const isConflict = result.error_code === 'CONFLICT'
                                        || (response.status === 409 && result.conflict);
                                    if (isConflict && result.conflict) {
                                        showConflictDialog([result.conflict]);
                                        return;
                                    }
                                    if (isConflict) {
                                        message.hidden = false;
                                        message.textContent = result.message || 'Bản dịch đã được cập nhật bởi người dùng khác. Hãy tải lại dữ liệu mới nhất.';
                                        return;
                                    }
                                    throw new Error(result.message || `Lưu thất bại (HTTP ${response.status}).`);
                                }
                                const stringId = Number(form.querySelector('[name="string_id"]')?.value);
                                const input = document.querySelector(`input[name="translated_text"][form="${form.id}"]`);
                                const statusInput = document.querySelector(`[name="status"][form="${form.id}"]`);
                                if (input) input.dataset.savedText = result.translated_text;
                                if (statusInput) window.tmsUpdateStatusBadge(statusInput, result.status);
                                window.tmsSetTranslationUpdatedAt(stringId, result.updated_at);
                                if (message) {
                                    message.hidden = false;
                                    message.textContent = result.message || 'Đã lưu bản dịch thành công.';
                                }
                            } catch (error) {
                                if (message) {
                                    message.hidden = false;
                                    message.textContent = error instanceof Error ? error.message : 'Không thể lưu bản dịch.';
                                }
                                console.error('[TMS Translation Save] Không thể lưu bản dịch.', error);
                            } finally {
                                if (submitButton) submitButton.disabled = false;
                            }
                        });
                    });

                    rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', () => {
                        isAllPagesSelected = false;
                        message.hidden = true;
                        refreshToolbar();
                    }));
                    selectAll.addEventListener('change', () => {
                        isAllPagesSelected = false;
                        applyNonTextVisibility();
                        applySelectAll(selectAll.checked);
                        message.hidden = true;
                        refreshToolbar();
                    });
                    skipNonText.addEventListener('change', () => {
                        isAllPagesSelected = false;
                        applyNonTextVisibility();
                        refreshToolbar();
                    });
                    selectAllMatchingButton.addEventListener('click', () => {
                        if (busy || totalMatchingRows === 0 || skipNonText.checked) return;
                        isAllPagesSelected = true;
                        message.hidden = true;
                        refreshToolbar();
                    });
                    clearGlobalSelectionButton.addEventListener('click', () => {
                        isAllPagesSelected = false;
                        rowCheckboxes.forEach(checkbox => { checkbox.checked = false; });
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                        message.hidden = true;
                        refreshToolbar();
                    });
                    bulkStatus.addEventListener('change', refreshToolbar);
                    saveButton.addEventListener('click', () => saveSelected(null, false));
                    applyStatusButton.addEventListener('click', () => {
                        const selectedStatus = bulkStatus?.value || '';
                        if (!selectedStatus) return;
                        console.log('[TMS Bulk Actions] Gửi yêu cầu lưu trạng thái riêng.', {
                            status: selectedStatus,
                            rows: selectedRows().length
                        });
                        saveSelected(selectedStatus, true);
                    });
                    refreshNonTextControl();
                    refreshToolbar();
                })();
            </script>
            <script>
                (() => {
                    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const activeLocks = new Set();
                    const pendingLocks = new Set();
                    const lock = async (stringId) => {
                        if (activeLocks.has(stringId) || pendingLocks.has(stringId)) return;
                        pendingLocks.add(stringId);
                        const endpoint = new URL(window.location.href);
                        endpoint.searchParams.set('action', 'lock_translation');
                        try {
                            const response = await fetch(endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                                body: new URLSearchParams({csrf_token: csrfToken, string_id: stringId})
                            });
                            const contentType = response.headers.get('content-type') || '';
                            if (!contentType.toLowerCase().includes('application/json')) {
                                const rawBody = await response.text();
                                console.error('[TMS Translation Lock] Non-JSON response.', {
                                    status: response.status,
                                    contentType,
                                    body: rawBody.slice(0, 1000)
                                });
                                throw new Error(`Server trả về không phải JSON hợp lệ (HTTP ${response.status}).`);
                            }
                            const result = await response.json();
                            if (!response.ok || !result.success) {
                                throw new Error(result.message || 'Chuỗi đang bị khóa.');
                            }
                            activeLocks.add(stringId);
                        } finally {
                            pendingLocks.delete(stringId);
                        }
                    };
                    const unlock = (stringId, keepalive = false) => {
                        if (!activeLocks.has(stringId)) return;
                        const endpoint = new URL(window.location.href);
                        endpoint.searchParams.set('action', 'unlock_translation');
                        fetch(endpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            keepalive,
                            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
                            body: new URLSearchParams({csrf_token: csrfToken, string_id: stringId})
                        }).catch(() => {});
                        activeLocks.delete(stringId);
                    };
                    document.querySelectorAll('input[name="translated_text"]:not(:disabled)').forEach((input) => {
                        const stringId = input.form?.querySelector('input[name="string_id"]')?.value;
                        if (!stringId) return;
                        input.addEventListener('focus', () => {
                            input.dataset.lockAttempted = 'false';
                        });
                        input.addEventListener('input', async (event) => {
                            if (!event.isTrusted || input.dataset.lockAttempted === 'true') return;
                            input.dataset.lockAttempted = 'true';
                            try {
                                await lock(stringId);
                                if (!input.matches(':focus')) unlock(stringId);
                            } catch (error) {
                                input.blur();
                                window.alert(error instanceof Error ? error.message : 'Không thể khóa chuỗi.');
                            }
                        });
                        input.addEventListener('blur', () => unlock(stringId));
                    });
                    window.addEventListener('pagehide', () => activeLocks.forEach((stringId) => unlock(stringId, true)));
                })();
            </script>
            <?php endif; ?>

            <?php if ($currentVersion && $canTranslateCurrentProject): ?>
            <dialog class="tms-modal" id="ai-translate-dialog" aria-labelledby="ai-translate-title" style="width:min(36rem, calc(100vw - 2rem))">
                <section class="tms-modal-card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-purple-600">AI Translate</p>
                            <h2 id="ai-translate-title" class="mt-1 text-xl font-bold text-slate-900">Tự động dịch theo batch</h2>
                            <p class="mt-1 text-sm text-slate-500">Mỗi lượt dịch tối đa 8 chuỗi. Kết quả được lưu ngay sau từng chuỗi để có thể tiếp tục nếu gặp lỗi.</p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng tiến trình dịch AI" data-close-ai-translate>×</button>
                    </div>
                    <p id="ai-translate-progress-label" class="tms-ai-progress-status mt-5 text-sm font-semibold text-slate-700" role="status" aria-live="polite"><span class="tms-ai-status-icon" aria-hidden="true">✦</span><span>Sẵn sàng dịch.</span></p>
                    <div class="tms-ai-progress-track mt-3" role="progressbar" aria-label="Tiến độ dịch AI" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <span id="ai-translate-progress-bar"></span>
                    </div>
                    <div class="tms-ai-progress-meta" aria-live="polite">
                        <span id="ai-translate-progress-percent">0%</span>
                        <span id="ai-translate-eta">Thời gian còn lại: —</span>
                        <span id="ai-translate-speed">Tốc độ: —</span>
                    </div>
                    <p id="ai-translate-error" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800" role="alert" hidden></p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" id="ai-translate-cancel" class="tms-modal-cancel tms-ai-cancel" hidden>Hủy tiến trình</button>
                        <button type="button" class="tms-modal-cancel" data-close-ai-translate>Đóng</button>
                        <button type="button" id="ai-translate-continue" class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700" hidden>Tiếp tục</button>
                    </div>
                    <input type="hidden" id="ai-translate-csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                </section>
            </dialog>
            <script>
                (() => {
                    const dialog = document.getElementById('ai-translate-dialog');
                    const progressBar = document.getElementById('ai-translate-progress-bar');
                    const progressTrack = progressBar.parentElement;
                    const progressLabel = document.getElementById('ai-translate-progress-label');
                    const progressPercent = document.getElementById('ai-translate-progress-percent');
                    const etaLabel = document.getElementById('ai-translate-eta');
                    const speedLabel = document.getElementById('ai-translate-speed');
                    const errorMessage = document.getElementById('ai-translate-error');
                    const continueButton = document.getElementById('ai-translate-continue');
                    const cancelButton = document.getElementById('ai-translate-cancel');
                    const csrfToken = document.getElementById('ai-translate-csrf')?.value || '';
                    if (!dialog || !progressBar || !progressTrack || !progressLabel || !progressPercent ||
                        !etaLabel || !speedLabel || !errorMessage || !continueButton || !cancelButton || !csrfToken) return;
                    let busy = false;
                    let cancelled = false;
                    let activeController = null;
                    let completed = 0;
                    let total = 0;
                    let startedAt = 0;

                    const formatSeconds = seconds => {
                        if (!Number.isFinite(seconds) || seconds < 1) return 'dưới 1 giây';
                        if (seconds < 60) return `~${Math.ceil(seconds)} giây`;
                        return `~${Math.floor(seconds / 60)} phút ${Math.ceil(seconds % 60)} giây`;
                    };

                    const updateProgress = (result) => {
                        total = Number.isInteger(result.total) ? result.total : total;
                        completed = Number.isInteger(result.completed) ? result.completed : completed;
                        const percent = total > 0 ? Math.min(100, Math.round((completed / total) * 100)) : 100;
                        progressBar.style.width = `${percent}%`;
                        progressTrack.setAttribute('aria-valuenow', String(percent));
                        progressPercent.textContent = `${percent}%`;
                        progressLabel.querySelector('span:last-child').textContent = total > 0
                            ? (result.success && result.remaining > 0
                                ? `Đang dịch... ${completed}/${total} chuỗi`
                                : `${completed}/${total} chuỗi đã hoàn tất`)
                            : 'Không có chuỗi cần dịch.';
                        if (startedAt && completed > 0) {
                            const secondsPerString = (Date.now() - startedAt) / 1000 / completed;
                            speedLabel.textContent = `Tốc độ: ~${secondsPerString.toFixed(1)} giây/chuỗi`;
                            etaLabel.textContent = result.remaining > 0
                                ? `Thời gian còn lại: ${formatSeconds(secondsPerString * result.remaining)}`
                                : 'Đã hoàn tất';
                        }

                        (result.translations || []).forEach((translation) => {
                            const input = document.querySelector(
                                `input[name="translated_text"][form="translation-form-${translation.stringId}"]`
                            );
                            const status = document.querySelector(
                                `[name="status"][form="translation-form-${translation.stringId}"]`
                            );
                            if (input) input.value = translation.translatedText;
                            if (status) {
                                window.tmsUpdateStatusBadge(status, 'draft');
                            }
                            if (input) input.dispatchEvent(new Event('input', { bubbles: true }));
                        });
                    };

                    const runBatches = async () => {
                        if (busy) return;
                        busy = true;
                        cancelled = false;
                        startedAt = Date.now();
                        continueButton.hidden = true;
                        cancelButton.hidden = false;
                        errorMessage.hidden = true;

                        try {
                            while (true) {
                                if (cancelled) return;
                                progressLabel.querySelector('span:last-child').textContent = total > 0
                                    ? `Đang dịch... ${completed}/${total} chuỗi`
                                    : 'Đang chuẩn bị batch dịch...';
                                const formData = new FormData();
                                formData.set('action', 'ai_translate_batch');
                                formData.set('csrf_token', csrfToken);
                                formData.set('batch_size', '8');
                                let result = null;
                                let lastError = null;
                                for (let attempt = 1; attempt <= 3; attempt++) {
                                    const controller = new AbortController();
                                    activeController = controller;
                                    const requestTimeout = setTimeout(() => controller.abort(), 60000);
                                    let responseStatus = 0;
                                    let retryable = false;
                                    try {
                                        const response = await fetch(window.location.href, {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: { Accept: 'application/json' },
                                            body: formData,
                                            signal: controller.signal
                                        });
                                        responseStatus = response.status;
                                        retryable = response.status >= 500 ||
                                            [408, 425, 429].includes(response.status);
                                        try {
                                            result = await response.json();
                                        } catch {
                                            retryable = retryable || response.ok;
                                            throw new Error('Phản hồi máy chủ không hợp lệ.');
                                        }
                                        if (Number.isInteger(result.total)) updateProgress(result);
                                        if (!response.ok || !result.success) {
                                            retryable = response.status >= 500 ||
                                                [408, 425, 429].includes(response.status) ||
                                                result.retryable === true;
                                            throw new Error(result.message || 'Batch dịch chưa hoàn tất.');
                                        }
                                        lastError = null;
                                        break;
                                    } catch (error) {
                                        const wasAborted = error instanceof DOMException && error.name === 'AbortError';
                                        retryable = retryable || wasAborted ||
                                            (responseStatus === 0 && !wasAborted);
                                        lastError = new Error(wasAborted
                                            ? 'Yêu cầu batch vượt quá thời gian chờ 60 giây.'
                                            : (error instanceof Error ? error.message : 'Lỗi kết nối không xác định.'));
                                        const logDetails = {
                                            attempt,
                                            maxAttempts: 3,
                                            maxRetries: 2,
                                            httpStatus: responseStatus || null,
                                            retryable,
                                            message: lastError.message
                                        };
                                        if (retryable && attempt < 3) {
                                            console.warn('[TMS AI Translate] Batch gặp lỗi tạm thời; sẽ thử lại.', logDetails);
                                            await new Promise(resolve => setTimeout(resolve, 1500));
                                        } else {
                                            console.error('[TMS AI Translate] Batch thất bại.', logDetails);
                                            break;
                                        }
                                    } finally {
                                        clearTimeout(requestTimeout);
                                    }
                                }
                                if (lastError) throw lastError;

                                if (result.remaining === 0) {
                                    updateProgress(result);
                                    break;
                                }
                                if (result.processed === 0) {
                                    throw new Error('Batch không tạo được tiến trình mới.');
                                }
                                updateProgress(result);
                                await new Promise(resolve => setTimeout(resolve, 1500));
                                if (cancelled) return;
                            }
                        } catch (error) {
                            if (cancelled) {
                                progressLabel.querySelector('span:last-child').textContent = `Đã hủy ở ${completed}/${total} chuỗi.`;
                                return;
                            }
                            progressLabel.querySelector('span:last-child').textContent = `Đã dịch ${completed}/${total} chuỗi. Gặp sự cố kết nối, bạn có muốn tiếp tục dịch phần còn lại không?`;
                            errorMessage.textContent = error instanceof Error
                                ? error.message
                                : 'Yêu cầu bị gián đoạn.';
                            errorMessage.hidden = false;
                            continueButton.hidden = false;
                        } finally {
                            busy = false;
                            activeController = null;
                            cancelButton.hidden = true;
                        }
                    };

                    document.querySelectorAll('[data-open-ai-translate]').forEach(button => {
                        button.addEventListener('click', () => {
                            dialog.showModal();
                            runBatches();
                        });
                    });
                    document.querySelectorAll('[data-close-ai-translate]').forEach(button => {
                        button.addEventListener('click', () => dialog.close());
                    });
                    dialog.addEventListener('click', event => {
                        if (event.target === dialog) dialog.close();
                    });
                    continueButton.addEventListener('click', runBatches);
                    cancelButton.addEventListener('click', () => {
                        if (!busy) return;
                        cancelled = true;
                        activeController?.abort();
                        cancelButton.hidden = true;
                        continueButton.hidden = true;
                        progressLabel.querySelector('span:last-child').textContent = `Đang hủy tiến trình ở ${completed}/${total} chuỗi...`;
                    });
                })();
            </script>
            <?php endif; ?>

            <dialog class="tms-modal" id="live-preview-dialog" aria-labelledby="live-preview-title" style="width:min(100rem, calc(100vw - 2rem)); height:calc(100dvh - 2rem); max-height:none; overflow:hidden">
                <section class="tms-modal-card" style="display:flex; flex-direction:column; gap:.75rem; width:100%; height:100%; max-height:none; overflow:hidden; padding:1rem">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Live Preview</p>
                            <h2 id="live-preview-title" class="mt-1 text-xl font-bold text-slate-900">Xem trước giao diện dịch</h2>
                            <p class="mt-1 text-sm text-slate-500">Dùng trực tiếp snapshot HTML/CSS đã nhập cho phiên bản này; chỉ nguồn không có snapshot mới dùng bản xem trước dự phòng.</p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng xem trước" data-close-live-preview>×</button>
                    </div>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Chế độ xem trước bản dịch">
                        <button id="workspace-preview-draft" type="button" aria-pressed="true" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Bản nháp / Draft Preview</button>
                        <button id="workspace-preview-approved" type="button" aria-pressed="false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Bản đã duyệt / Approved Preview</button>
                    </div>
                    <p id="workspace-preview-source" class="truncate text-xs text-slate-500" title="<?= htmlspecialchars((string)($currentVersion['source_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        Nguồn phiên bản: <?= htmlspecialchars((string)($currentVersion['source_url'] ?? 'Tệp JSON hoặc chưa lưu URL'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p id="workspace-preview-notice" class="hidden rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status"></p>
                    <div style="display:flex; min-height:0; flex:1; overflow:hidden" class="rounded-lg border bg-slate-100">
                        <iframe id="workspace-translation-preview" title="Bản xem trước trang nguồn đã dịch" class="w-full h-full" style="display:block; width:100%; height:100%; min-height:0; border:0; background:#fff"></iframe>
                    </div>
                </section>
            </dialog>
            <script id="workspace-preview-data" type="application/json"><?= $workspacePreviewJson ?></script>
            <script>
                (() => {
                    const dialog = document.getElementById('live-preview-dialog');
                    const frame = document.getElementById('workspace-translation-preview');
                    const draftButton = document.getElementById('workspace-preview-draft');
                    const approvedButton = document.getElementById('workspace-preview-approved');
                    let rows = JSON.parse(document.getElementById('workspace-preview-data').textContent);
                    const sourceLabel = document.getElementById('workspace-preview-source');
                    const previewNotice = document.getElementById('workspace-preview-notice');
                    const expectedVersionId = <?= (int)$versionId ?>;
                    let mode = 'draft';
                    let previewReady = false;
                    let previewMode = 'loading';
                    let snapshotHtml = '';
                    let snapshotUrl = '';

                    frame.srcdoc = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>';
                    const fallbackPreviewHtml = `<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
                        *{box-sizing:border-box}body{margin:0;background:#f2eff8;color:#211b2e;font:14px Inter,ui-sans-serif,system-ui,sans-serif}
                        .store{min-height:100vh;margin:auto;background:#fff}.announcement{padding:9px 20px;background:#4b2474;color:#fff;text-align:center;font-size:11px;letter-spacing:.04em}
                        .header{display:flex;align-items:center;gap:30px;padding:17px 5%;background:#702ca1;color:#fff}.brand{flex:none;color:#fff;font-size:22px;font-weight:850;letter-spacing:-.06em}.brand span{color:#e9c8ff}
                        .nav{display:flex;flex:1;align-items:center;gap:22px;min-width:0}.nav-item{color:#f3e8ff;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                        .header-actions{display:flex;align-items:center;gap:14px}.search{width:145px;padding:8px 10px;border:1px solid #ffffff55;border-radius:7px;background:#ffffff18;color:#fff;font-size:11px}
                        .icon{width:18px;height:18px;display:block;stroke:currentColor;fill:none;stroke-width:1.7}.hero-wrap{padding:30px 5% 22px;background:#faf8fc}
                        .hero{position:relative;display:grid;grid-template-columns:1.05fr .95fr;min-height:300px;overflow:hidden;border-radius:16px;background:linear-gradient(115deg,#eee4fa,#f5effb 50%,#e6d3f5)}
                        .hero-copy{z-index:1;align-self:center;padding:36px 0 36px 8%}.eyebrow{margin-bottom:15px;color:#7333a0;font-size:10px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
                        .hero h1{max-width:440px;margin:0 0 12px;color:#281b35;font-size:clamp(28px,4vw,48px);font-weight:850;letter-spacing:-.045em;line-height:1.04}
                        .hero-copy p{max-width:390px;margin:0 0 22px;color:#665a70;font-size:13px;line-height:1.7}.shop-button{display:inline-flex;align-items:center;gap:14px;padding:11px 17px;border:0;border-radius:7px;background:#702ca1;color:#fff;font-size:11px;font-weight:750}
                        .hero-art{position:relative;display:grid;place-items:center;min-height:300px}.orb{position:absolute;width:min(78%,300px);aspect-ratio:1;border-radius:50%;background:#d6b7ed}.orb:after{position:absolute;inset:12%;border:1px solid #ffffff9c;border-radius:50%;content:""}
                        .headphones{position:relative;z-index:1;width:min(76%,250px);filter:drop-shadow(0 22px 18px #481d6540)}.floating-tag{position:absolute;z-index:2;right:9%;bottom:16%;padding:9px 12px;border:1px solid #ffffffa6;border-radius:8px;background:#ffffffdc;color:#532777;font-size:10px;font-weight:750}
                        .categories{display:flex;gap:9px;overflow:auto;padding:0 5% 22px;background:#faf8fc}.category{padding:8px 13px;border:1px solid #e9e1ef;border-radius:999px;background:#fff;color:#5d5066;font-size:10px;font-weight:650;white-space:nowrap}.category.active{border-color:#702ca1;background:#702ca1;color:#fff}
                        .products{padding:10px 5% 32px}.section-head{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:14px}.section-head h2{margin:0;color:#281b35;font-size:19px;font-weight:800;letter-spacing:-.03em}.view-all{color:#702ca1;font-size:10px;font-weight:750}
                        .product-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.product{min-width:0;overflow:hidden;border:1px solid #eee9f2;border-radius:10px;background:#fff}.product-art{position:relative;display:grid;place-items:center;height:104px;overflow:hidden;background:linear-gradient(140deg,#f5f0f9,#e7d8f2)}
                        .product-art:before{position:absolute;width:74px;height:74px;border-radius:50%;background:#dac3ea;content:""}.mini-headphone{position:relative;z-index:1;width:42px;height:48px;border:6px solid #592a75;border-bottom:0;border-radius:24px 24px 0 0}
                        .mini-headphone:before,.mini-headphone:after{position:absolute;bottom:-2px;width:10px;height:20px;border-radius:5px;background:#592a75;content:""}.mini-headphone:before{left:-8px}.mini-headphone:after{right:-8px}
                        .product-copy{padding:10px}.product-copy h3{margin:0;color:#30253a;font-size:11px;font-weight:750;line-height:1.4;overflow-wrap:anywhere}.footer{padding:19px 5%;background:#301b3d;color:#e8dcef;font-size:10px}
                        @media(max-width:760px){.header{flex-wrap:wrap;gap:12px;padding:14px 5%}.nav{order:3;flex-basis:100%;gap:16px;overflow:auto}.header-actions{margin-left:auto}.hero{min-height:250px}.hero-art{min-height:250px}.hero-copy{padding-left:7%}.product-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.product-art{height:90px}}
                        @media(max-width:520px){.announcement{font-size:9px}.brand{font-size:19px}.nav{gap:12px}.nav-item{font-size:10px}.search{display:none}.hero-wrap{padding-top:16px}.hero{grid-template-columns:1fr;min-height:0}.hero-copy{padding:25px 22px 5px}.hero h1{font-size:31px}.hero-copy p{font-size:11px;margin-bottom:14px}.hero-art{min-height:155px}.orb{width:145px}.headphones{width:126px}.floating-tag{right:7%;bottom:10%}.categories{gap:6px}.category{padding:7px 10px;font-size:9px}.products{padding-top:6px}.section-head h2{font-size:16px}}
                    </style></head><body><div class="store">
                    <div class="announcement" id="announcement">COMPLIMENTARY SHIPPING ON ORDERS OVER $75</div>
                    <header class="header"><div class="brand">Tech<span>Store</span></div><nav class="nav" id="nav"></nav>
                    <div class="header-actions"><div class="search" id="search-label">Search products</div><svg class="icon" viewBox="0 0 24 24" aria-label="Account"><circle cx="12" cy="8" r="3.5"/><path d="M5 21c.7-4.1 3-6 7-6s6.3 1.9 7 6"/></svg>
                    <svg class="icon" viewBox="0 0 24 24" aria-label="Shopping bag"><path d="M5 8h14l1 13H4L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg></div></header>
                    <main><section class="hero-wrap"><div class="hero"><div class="hero-copy"><div class="eyebrow" id="hero-eyebrow">NEW SEASON · IMMERSIVE SOUND</div>
                    <h1 id="hero-title">Sound that moves you.</h1><p id="hero-description">Discover your next favorite sound with premium headphones designed for every moment.</p>
                    <button class="shop-button" type="button"><span id="hero-cta">Shop Now</span><span aria-hidden="true">→</span></button></div><div class="hero-art"><div class="orb"></div>
                    <svg class="headphones" viewBox="0 0 260 250" role="img" aria-label="Premium over-ear headphones"><defs><linearGradient id="band" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#54236f"/><stop offset="1" stop-color="#27143a"/></linearGradient><linearGradient id="cup" x1="0" x2="1"><stop stop-color="#7e45a0"/><stop offset="1" stop-color="#3b1e54"/></linearGradient></defs>
                    <path d="M55 139V105a75 75 0 0 1 150 0v34" fill="none" stroke="url(#band)" stroke-width="25" stroke-linecap="round"/><path d="M56 126c-12 0-22 10-22 22v40c0 12 10 22 22 22h19v-84H56Zm148 0c12 0 22 10 22 22v40c0 12-10 22-22 22h-19v-84h19Z" fill="url(#cup)"/></svg>
                    <div class="floating-tag" id="hero-product-tag">STUDIO PRO · WIRELESS</div></div></div></section><div class="categories" id="categories"></div>
                    <section class="products"><div class="section-head"><h2 id="products-title">Featured Favorites</h2><span class="view-all" id="products-link">View all →</span></div><div class="product-grid" id="product-grid"></div></section></main>
                    <footer class="footer" id="footer-copy">TechStore · Technology for everyday life</footer></div></body></html>`;

                    const normalizePreviewText = value => String(value ?? '').replace(/\s+/g, ' ').trim();
                    const getRows = () => rows.reduce((result, row) => {
                        const editor = document.querySelector(`input[name="translated_text"][form="translation-form-${row.stringId}"]`);
                        const status = document.querySelector(`[name="status"][form="translation-form-${row.stringId}"]`);
                        const translation = normalizePreviewText(editor?.value ?? row.translation);
                        const currentStatus = status?.value || row.status || 'draft';
                        if (translation === '') return result;
                        if (mode === 'approved' && currentStatus !== 'approved') return result;
                        result.push({
                            key: row.key,
                            source: row.source,
                            text: translation,
                        });
                        return result;
                    }, []);
                    const updateTabState = () => {
                        [
                            [draftButton, mode === 'draft'],
                            [approvedButton, mode === 'approved'],
                        ].forEach(([button, active]) => {
                            if (!button) return;
                            button.setAttribute('aria-pressed', String(active));
                            button.classList.toggle('bg-indigo-600', active);
                            button.classList.toggle('text-white', active);
                            button.classList.toggle('border', !active);
                            button.classList.toggle('border-slate-300', !active);
                            button.classList.toggle('text-slate-700', !active);
                        });
                    };
                    const updatePreviewNotice = () => {
                        const previewRows = getRows();
                        if (mode === 'approved' && previewRows.length === 0) {
                            previewNotice.textContent = 'Chưa có chuỗi nào được duyệt. Đang hiển thị bản gốc.';
                            previewNotice.classList.remove('hidden');
                        } else {
                            previewNotice.textContent = '';
                            previewNotice.classList.add('hidden');
                        }
                    };
                    const renderRowsPreview = () => {
                        if (!previewReady) return;
                        const previewDocument = frame.contentDocument;
                        if (!previewDocument?.body) return;
                        previewDocument.head.innerHTML = '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:24px;background:#f8fafc;color:#172033;font:14px system-ui,sans-serif}main{max-width:900px;margin:auto}h1{font-size:20px}.row{margin:10px 0;padding:14px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.key{color:#4f46e5;font:12px ui-monospace,monospace}.text{margin:6px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}.empty{color:#64748b}</style>';
                        previewDocument.body.replaceChildren();
                        const main = previewDocument.createElement('main');
                        const heading = previewDocument.createElement('h1');
                        heading.textContent = 'Bản xem trước bản dịch';
                        const list = previewDocument.createElement('section');
                        const previewRows = rows.map(row => {
                            const formId = `translation-form-${row.stringId}`;
                            const editor = document.querySelector(`input[name="translated_text"][form="${formId}"]`);
                            const status = document.querySelector(`[name="status"][form="${formId}"]`)?.value || row.status;
                            const translation = normalizePreviewText(editor?.value ?? row.translation);
                            return {
                                key: row.key,
                                text: mode === 'approved'
                                    ? (status === 'approved' && translation !== '' ? translation : row.source)
                                    : (translation || row.source),
                            };
                        });
                        previewRows.forEach(row => {
                            const item = previewDocument.createElement('article');
                            item.className = 'row';
                            const key = previewDocument.createElement('div');
                            key.className = 'key';
                            key.textContent = row.key;
                            const text = previewDocument.createElement('p');
                            text.className = 'text';
                            text.textContent = row.text;
                            item.append(key, text);
                            list.append(item);
                        });
                        if (previewRows.length === 0) {
                            const empty = previewDocument.createElement('p');
                            empty.className = 'empty';
                            empty.textContent = 'Chưa có nội dung để xem trước.';
                            list.append(empty);
                        }
                        main.append(heading, list);
                        previewDocument.body.append(main);
                    };
                    const postSnapshotRows = () => {
                        if (previewMode !== 'snapshot') return;
                        frame.srcdoc = prepareSnapshot(snapshotHtml, snapshotUrl, getRows());
                    };
                    const prepareSnapshot = (html, sourceUrl, previewRows) => {
                        const parsed = new DOMParser().parseFromString(html, 'text/html');
                        parsed.querySelectorAll('script,base,iframe,frame,frameset,object,embed,applet,portal')
                            .forEach(element => element.remove());
                        parsed.querySelectorAll('meta[http-equiv]').forEach(meta => meta.remove());
                        parsed.querySelectorAll('link').forEach(link => {
                            if (!/\bstylesheet\b/i.test(link.getAttribute('rel') || '')) link.remove();
                        });
                        parsed.querySelectorAll('*').forEach(element => {
                            [...element.attributes].forEach(attribute => {
                                const name = attribute.name.toLowerCase();
                                const value = attribute.value.trim();
                                if (name.startsWith('on') || ['srcdoc', 'nonce', 'integrity'].includes(name)) {
                                    element.removeAttribute(attribute.name);
                                } else if (
                                    ['href', 'src', 'xlink:href', 'action', 'formaction', 'poster'].includes(name) &&
                                    /^(?:javascript|vbscript|data\s*:\s*text\/html):/i.test(value)
                                ) {
                                    element.removeAttribute(attribute.name);
                                }
                            });
                        });

                        if (!parsed.head) {
                            parsed.documentElement.insertBefore(parsed.createElement('head'), parsed.body);
                        }
                        const baseUrl = new URL(sourceUrl || window.location.href, window.location.href);
                        const base = parsed.createElement('base');
                        base.href = baseUrl.href;
                        parsed.head.prepend(base);
                        const normalize = value => String(value ?? '')
                            .replace(/\u00a0/g, ' ')
                            .replace(/[\u200b-\u200d\ufeff]/g, '')
                            .replace(/\s+/g, ' ')
                            .trim()
                            .normalize('NFKC');
                        const translations = new Map(
                            previewRows.map(row => [normalize(row.source), String(row.text ?? '')])
                        );
                        const walker = parsed.createTreeWalker(parsed.body, NodeFilter.SHOW_TEXT);
                        while (walker.nextNode()) {
                            const node = walker.currentNode;
                            if (node.parentElement?.closest('script,style,noscript,template,svg')) continue;
                            const original = node.nodeValue || '';
                            const replacement = translations.get(normalize(original));
                            if (replacement === undefined || replacement === '') continue;
                            const whitespace = original.match(/^\s*/)?.[0] || '';
                            const trailingWhitespace = original.match(/\s*$/)?.[0] || '';
                            node.nodeValue = whitespace + replacement + trailingWhitespace;
                        }
                        return '<!doctype html>' + parsed.documentElement.outerHTML;
                    };
                    const loadFreshPreview = async () => {
                        const endpoint = new URL(window.location.href);
                        endpoint.searchParams.set('action', 'live_preview_data');
                        endpoint.searchParams.set('_preview', String(Date.now()));
                        const response = await fetch(endpoint, {
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: { Accept: 'application/json' }
                        });
                        if (!response.ok) throw new Error(`Tải preview thất bại (HTTP ${response.status}).`);
                        const payload = await response.json();
                        if (!payload.success || payload.versionId !== expectedVersionId) {
                            throw new Error(payload.message || 'Dữ liệu preview không thuộc phiên bản đang chọn.');
                        }
                        rows = payload.rows;
                        sourceLabel.textContent = `Phiên bản ${payload.versionNumber} · Nguồn: ${payload.sourceUrl || (payload.sourceHtml ? 'Snapshot HTML đã lưu' : 'Tệp dữ liệu')}`;
                        sourceLabel.title = payload.sourceUrl || (payload.sourceHtml ? 'Snapshot HTML đã lưu' : 'Tệp dữ liệu');
                        const savedSnapshotHtml = typeof payload.sourceHtml === 'string'
                            ? payload.sourceHtml.trim()
                            : '';
                        if (savedSnapshotHtml !== '') {
                            previewMode = 'snapshot';
                            frame.setAttribute('sandbox', '');
                            snapshotHtml = savedSnapshotHtml;
                            snapshotUrl = payload.sourceUrl || window.location.href;
                        } else {
                            previewMode = 'rows';
                            previewReady = false;
                            frame.removeAttribute('sandbox');
                            frame.srcdoc = '<!doctype html><html lang="vi"><head></head><body></body></html>';
                        }
                    };
                    const render = () => {
                        updateTabState();
                        updatePreviewNotice();
                        if (previewMode === 'snapshot') {
                            postSnapshotRows();
                            return;
                        }
                        if (previewMode === 'rows') {
                            renderRowsPreview();
                            return;
                        }
                        if (!previewReady) return;
                        const previewDocument = frame.contentDocument;
                        if (!previewDocument?.body) return;
                        const previewRows = getRows();
                        const used = new Set();
                        const take = pattern => {
                            const row = previewRows.find(item => !used.has(item.key) && pattern.test(item.key + ' ' + item.source));
                            if (row) used.add(row.key);
                            return row;
                        };
                        const show = (id, row, fallback) => {
                            const element = previewDocument.getElementById(id);
                            if (element) element.textContent = row ? row.text : fallback;
                        };
                        const navRows = previewRows.filter(row => /(menu|nav|category|all.?products|headphones|speakers|accessories)/i.test(row.key + ' ' + row.source)).slice(0, 4);
                        navRows.forEach(row => used.add(row.key));
                        const navFallback = ['All Products', 'Headphones', 'Speakers', 'Accessories'];
                        const nav = previewDocument.getElementById('nav');
                        const categories = previewDocument.getElementById('categories');
                        if (!nav || !categories) return;
                        nav.replaceChildren();
                        categories.replaceChildren();
                        (navRows.length ? navRows.map(row => row.text) : navFallback).forEach((text, index) => {
                            const link = previewDocument.createElement('span');
                            link.className = 'nav-item';
                            link.textContent = text;
                            nav.append(link);
                            const category = previewDocument.createElement('span');
                            category.className = 'category' + (index === 0 ? ' active' : '');
                            category.textContent = text;
                            categories.append(category);
                        });
                        show('announcement', take(/announcement|shipping|delivery|offer/i), 'COMPLIMENTARY SHIPPING ON ORDERS OVER $75');
                        show('search-label', take(/search/i), 'Search products');
                        show('hero-eyebrow', take(/eyebrow|eyecatch|season|tagline/i), 'NEW SEASON · IMMERSIVE SOUND');
                        show('hero-title', take(/hero|headline|banner|welcome|main.?title/i), 'Sound that moves you.');
                        show('hero-description', take(/description|subtitle|intro|summary/i), 'Discover your next favorite sound with premium headphones designed for every moment.');
                        show('hero-cta', take(/cta|shop.?now|buy.?now|button|action/i), 'Shop Now');
                        show('hero-product-tag', take(/headphone|audio|sound|product.?tag|promo/i), 'STUDIO PRO · WIRELESS');
                        show('products-title', take(/featured|collection|products.?title|bestseller/i), 'Featured Favorites');
                        show('products-link', take(/view.?all|browse.?all|see.?all/i), 'View all →');
                        show('footer-copy', take(/footer|copyright|legal/i), 'TechStore · Technology for everyday life');
                        const productGrid = previewDocument.getElementById('product-grid');
                        if (!productGrid) return;
                        productGrid.replaceChildren();
                        const fallbackProducts = ['Wireless Headphones', 'Portable Speaker', 'Studio Headphones', 'Everyday Audio'];
                        const remaining = previewRows.filter(row => !used.has(row.key));
                        const productTitles = remaining.length ? remaining.map(row => row.text) : fallbackProducts;
                        productTitles.slice(0, 16).forEach(titleText => {
                            const card = previewDocument.createElement('article');
                            card.className = 'product';
                            const art = previewDocument.createElement('div');
                            art.className = 'product-art';
                            const headphones = previewDocument.createElement('div');
                            headphones.className = 'mini-headphone';
                            art.append(headphones);
                            const copy = previewDocument.createElement('div');
                            copy.className = 'product-copy';
                            const title = previewDocument.createElement('h3');
                            title.textContent = titleText;
                            copy.append(title);
                            card.append(art, copy);
                            productGrid.append(card);
                        });
                    };
                    const previewOpener = document.querySelector('[data-open-live-preview]');
                    if (!previewOpener) return;
                    previewOpener.addEventListener('click', async () => {
                        dialog.showModal();
                        sourceLabel.textContent = 'Đang tải dữ liệu mới nhất...';
                        try {
                            await loadFreshPreview();
                            render();
                        } catch (error) {
                            previewMode = 'error';
                            frame.removeAttribute('sandbox');
                            frame.srcdoc = '<!doctype html><html lang="vi"><head><meta charset="utf-8"></head><body style="font:14px system-ui;padding:24px;color:#b91c1c">Không thể tải dữ liệu xem trước.</body></html>';
                            sourceLabel.textContent = error instanceof Error ? error.message : 'Không thể tải preview.';
                        }
                    });
                    document.querySelectorAll('[data-close-live-preview]').forEach(button => {
                        button.addEventListener('click', () => dialog.close());
                    });
                    dialog.addEventListener('click', event => {
                        if (event.target === dialog) dialog.close();
                    });
                    draftButton?.addEventListener('click', () => {
                        mode = 'draft';
                        render();
                    });
                    approvedButton?.addEventListener('click', () => {
                        mode = 'approved';
                        render();
                    });
                    document.querySelectorAll('input[name="translated_text"]').forEach(editor => {
                        editor.addEventListener('input', render);
                    });
                    frame.addEventListener('load', () => {
                        if (previewMode === 'snapshot') {
                            frame.style.visibility = 'visible';
                            return;
                        }
                        previewReady = true;
                        frame.style.visibility = 'visible';
                        render();
                    });
                    render();
                })();
            </script>

            <dialog id="translation-history-dialog" class="tms-modal" aria-labelledby="translation-history-dialog-title">
                <section class="tms-modal-card max-w-3xl">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Revision History</p>
                            <h2 id="translation-history-dialog-title" class="mt-1 text-xl font-bold text-slate-900">Lịch sử chỉnh sửa</h2>
                            <p id="translation-history-dialog-key" class="mt-1 break-all font-mono text-sm text-slate-500"></p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-translation-history>×</button>
                    </div>
                    <div id="translation-history-dialog-list" class="mt-5 max-h-[60vh] space-y-3 overflow-y-auto" aria-live="polite">
                        <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Chọn một dòng để tải lịch sử.</p>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <button type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-close-translation-history>Đóng</button>
                    </div>
                </section>
            </dialog>
            <script>
                (() => {
                    const dialog = document.getElementById('translation-history-dialog');
                    const list = document.getElementById('translation-history-dialog-list');
                    const keyLabel = document.getElementById('translation-history-dialog-key');
                    if (!dialog || !list || !keyLabel) return;

                    const setMessage = (message, className = 'rounded-lg bg-slate-50 p-4 text-sm text-slate-500') => {
                        list.replaceChildren();
                        const element = document.createElement('p');
                        element.className = className;
                        element.textContent = message;
                        list.append(element);
                    };
                    const renderHistory = (history, canRollback, stringId) => {
                        list.replaceChildren();
                        if (!history.length) {
                            setMessage('Chưa có lịch sử chỉnh sửa cho câu này.');
                            return;
                        }
                        history.forEach((revision) => {
                            const item = document.createElement('article');
                            item.className = 'rounded-lg border border-slate-200 bg-white p-4 shadow-sm';

                            const meta = document.createElement('div');
                            meta.className = 'flex flex-wrap items-center justify-between gap-2';
                            const actor = document.createElement('span');
                            actor.className = 'text-sm font-semibold text-slate-800';
                            actor.textContent = revision.username || 'Hệ thống';
                            const time = document.createElement('time');
                            time.className = 'text-xs text-slate-500';
                            time.dateTime = revision.created_at || '';
                            time.textContent = revision.created_at || '';
                            meta.append(actor, time);

                            const content = document.createElement('div');
                            content.className = 'mt-3 grid gap-2 md:grid-cols-2';
                            const oldText = document.createElement('div');
                            oldText.className = 'whitespace-pre-wrap break-words rounded border-l-4 border-red-300 bg-red-50 p-3 font-mono text-sm text-red-900';
                            oldText.textContent = revision.old_text ?? '(trống)';
                            const newText = document.createElement('div');
                            newText.className = 'whitespace-pre-wrap break-words rounded border-l-4 border-green-400 bg-green-50 p-3 font-mono text-sm text-green-900';
                            newText.textContent = revision.new_text ?? '';
                            content.append(oldText, newText);

                            const footer = document.createElement('div');
                            footer.className = 'mt-3 flex items-center justify-between gap-2';
                            const revisionLabel = document.createElement('span');
                            revisionLabel.className = 'text-xs text-slate-500';
                            revisionLabel.textContent = `Phiên bản #${revision.revision_number}`;
                            footer.append(revisionLabel);
                            if (canRollback && revision.rollback_text !== null) {
                                const rollback = document.createElement('button');
                                rollback.type = 'button';
                                rollback.className = 'rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700';
                                rollback.textContent = 'Hoàn tác';
                                rollback.dataset.rollbackText = revision.rollback_text;
                                rollback.dataset.stringId = String(stringId);
                                footer.append(rollback);
                            }
                            item.append(meta, content, footer);
                            list.append(item);
                        });
                    };

                    const loadHistory = async (button) => {
                        const stringId = button.dataset.stringId || '';
                        keyLabel.textContent = button.dataset.keyPath || '';
                        setMessage('Đang tải lịch sử...');
                        dialog.showModal();
                        try {
                            const form = document.getElementById(`translation-form-${stringId}`);
                            const endpoint = new URL(
                                form?.getAttribute('action') || window.location.href,
                                window.location.href
                            );
                            endpoint.searchParams.set('action', 'list_translation_history');
                            endpoint.searchParams.set('string_id', stringId);
                            endpoint.searchParams.set('locale', <?= json_encode($currentLocale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                            endpoint.searchParams.set('version_id', <?= (int)$versionId ?>);
                            const response = await fetch(endpoint, {
                                credentials: 'same-origin',
                                cache: 'no-store',
                                headers: {Accept: 'application/json'}
                            });
                            const rawBody = await response.text();
                            const contentType = response.headers.get('content-type') || '';
                            if (/^(?:<!doctype\s+html\b|<html\b)/i.test(rawBody.trimStart())) {
                                console.error('[TMS Revision History] Server returned HTML instead of JSON.', {
                                    url: response.url,
                                    status: response.status,
                                    contentType,
                                    body: rawBody.slice(0, 2000),
                                });
                                throw new Error(`Máy chủ trả về HTML thay vì JSON (HTTP ${response.status}).`);
                            }
                            let result;
                            try {
                                result = JSON.parse(rawBody);
                            } catch (parseError) {
                                console.error('[TMS Revision History] Invalid JSON response.', {
                                    url: response.url,
                                    status: response.status,
                                    contentType,
                                    body: rawBody.slice(0, 2000),
                                    error: parseError,
                                });
                                throw new Error(`Máy chủ trả về dữ liệu không hợp lệ (HTTP ${response.status}).`);
                            }
                            if (!response.ok || !result.success || !Array.isArray(result.history)) {
                                throw new Error(result.message || 'Không thể tải lịch sử chỉnh sửa.');
                            }
                            renderHistory(result.history, result.can_rollback === true, stringId);
                        } catch (error) {
                            setMessage(error instanceof Error ? error.message : 'Không thể tải lịch sử chỉnh sửa.', 'rounded-lg bg-red-50 p-4 text-sm text-red-700');
                        }
                    };

                    document.querySelectorAll('[data-open-translation-history]').forEach((button) => {
                        button.addEventListener('click', () => loadHistory(button));
                    });
                    document.querySelectorAll('[data-close-translation-history]').forEach((button) => {
                        button.addEventListener('click', () => dialog.close());
                    });
                    dialog.addEventListener('click', (event) => {
                        if (event.target === dialog) dialog.close();
                    });
                    list.addEventListener('click', (event) => {
                        const rollback = event.target.closest('[data-rollback-text]');
                        if (!rollback) return;
                        const stringId = rollback.dataset.stringId || '';
                        const form = document.getElementById(`translation-form-${stringId}`);
                        const input = document.querySelector(`input[name="translated_text"][form="translation-form-${stringId}"]`);
                        const status = form?.querySelector('[name="status"]');
                        if (!form || !input || !status) {
                            setMessage('Không tìm thấy ô nhập bản dịch để hoàn tác.', 'rounded-lg bg-red-50 p-4 text-sm text-red-700');
                            return;
                        }
                        if (!window.confirm('Khôi phục phiên bản này và lưu lại bản dịch?')) return;
                        input.value = rollback.dataset.rollbackText || '';
                        status.value = input.value.trim() ? 'needs_review' : 'draft';
                        window.tmsUpdateStatusBadge?.(status, status.value);
                        dialog.close();
                        form.requestSubmit();
                    });
                })();
            </script>

            <?php if ($canReviewCurrentProject && $historyStringId > 0): ?>
                <section id="translation-history" class="tms-bottom-section tms-main-panel tms-accent-violet w-full col-span-12 bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-8">
                    <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Lịch sử chỉnh sửa</h2>
                            <p class="text-sm text-slate-500">Chỉ Trưởng nhóm và Kiểm duyệt viên có thể hoàn tác.</p>
                        </div>
                        <a class="tms-filter-reset" href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">Đóng lịch sử</a>
                    </div>
                    <?php if ($translationHistory): ?>
                        <div class="tms-table-wrap">
                            <div class="mb-4 rounded-lg bg-slate-50 px-4 py-3">
                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Khóa</span>
                                <code class="ml-2 break-all font-mono text-sm font-semibold text-indigo-700"><?= htmlspecialchars($translationHistory[0]['key_path'], ENT_QUOTES, 'UTF-8') ?></code>
                            </div>
                            <table class="w-full text-left text-sm">
                                <thead><tr><th class="px-4 py-3">Người thực hiện / Thời gian</th><th class="px-4 py-3">− Nội dung cũ</th><th class="px-4 py-3">+ Nội dung mới</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                <?php foreach ($translationHistory as $history): ?>
                                    <tr>
                                        <td class="px-4 py-3">
                                            <span class="block font-medium"><?php if (($history['username'] ?? null) === null): ?><span data-i18n="audit.system">Hệ thống</span><?php else: ?><?= htmlspecialchars($history['username'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                                            <time class="text-xs text-slate-500" datetime="<?= htmlspecialchars($history['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($history['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                                        </td>
                                        <?php $historyOldText = (int)$history['revision_number'] === 1 ? $history['source_text'] : $history['old_text']; ?>
                                        <td class="max-w-xs whitespace-pre-wrap break-words border-l-4 border-red-300 bg-red-50 px-4 py-3 font-mono text-red-900"><?= htmlspecialchars($historyOldText ?? '(trống)', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="max-w-xs whitespace-pre-wrap break-words border-l-4 border-green-400 bg-green-50 px-4 py-3 font-mono text-green-900"><?= htmlspecialchars($history['new_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($historyOldText !== null): ?>
                                                <form method="post" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>&amp;history_string_id=<?= $historyStringId ?>#translation-history">
                                                    <input type="hidden" name="action" value="rollback_translation">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="history_id" value="<?= (int)$history['id'] ?>">
                                                    <input type="hidden" name="translation_id" value="<?= (int)$history['translation_id'] ?>">
                                                    <input type="hidden" name="translation_updated_at" value="<?= htmlspecialchars($history['updated_at'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="tms-history-rollback">Hoàn tác</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Chưa có lịch sử cho chuỗi dịch này.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Audit Trail Section -->
            <div class="tms-bottom-section tms-reviewer-audit-section w-full col-span-12 tms-accent-slate h-full flex flex-col bg-white p-6 rounded-xl shadow-sm border border-gray-100" id="audit-trail">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-gray-800">📋 <span data-i18n="audit.title">Nhật ký hoạt động &amp; Kiểm toán (Audit Trail)</span></h2>
                    <?php if ($canExportAuditTrail): ?>
                        <a class="tms-button-primary inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                           href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>&amp;action=export_audit_csv&amp;audit_action=<?= rawurlencode($auditFilters['action'] ?? '') ?>&amp;audit_user_id=<?= rawurlencode($auditFilters['user_id'] ?? '') ?>&amp;audit_from=<?= rawurlencode($auditFilters['from'] ?? '') ?>&amp;audit_to=<?= rawurlencode($auditFilters['to'] ?? '') ?>">
                            ⬇ <span data-i18n="audit.export_report">Xuất báo cáo Audit Trail</span>
                        </a>
                    <?php endif; ?>
                </div>
                <form method="get" class="tms-audit-filters mb-4">
                    <input type="hidden" name="id" value="<?= $projectId ?>">
                    <input type="hidden" name="locale" value="<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                    <input name="audit_action" value="<?= htmlspecialchars($auditFilters['action'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Hành động" data-i18n-placeholder="audit.filter_action">
                    <input name="audit_user_id" value="<?= htmlspecialchars($auditFilters['user_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="User ID" data-i18n-placeholder="audit.filter_user_id">
                    <input type="date" name="audit_from" value="<?= htmlspecialchars($auditFilters['from'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Từ ngày" data-i18n-aria-label="audit.date_from">
                    <input type="date" name="audit_to" value="<?= htmlspecialchars($auditFilters['to'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-label="Đến ngày" data-i18n-aria-label="audit.date_to">
                    <button type="submit" data-i18n="translation.filter">Lọc</button>
                    <a href="<?= htmlspecialchars($workspaceAction . '&locale=' . rawurlencode($currentLocale) . '&version_id=' . (int)$versionId . '#audit-trail', ENT_QUOTES, 'UTF-8') ?>" data-i18n="translation.clear_filters">Xóa lọc</a>
                </form>
                <?php if (!empty($auditLogs)): ?>
                    <div class="tms-table-wrap tms-audit-table-wrap overflow-x-auto">
                        <table class="w-full text-left border-collapse border border-gray-100 rounded-lg overflow-hidden text-sm">
                            <thead>
                                <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                    <th class="px-3 py-1.5 font-semibold border-b" data-i18n="audit.action_type">Loại hành động</th>
                                    <th class="px-3 py-1.5 font-semibold border-b" data-i18n="audit.performer">Người thực hiện</th>
                                    <th class="px-3 py-1.5 font-semibold border-b" data-i18n="audit.change_details">Chi tiết thay đổi</th>
                                    <th class="px-3 py-1.5 font-semibold border-b" data-i18n="audit.timestamp">Thời gian</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($auditLogs as $log): ?>
                                    <?php
                                    $auditDescription = AuditLogPresenter::describe(
                                        (string)$log['action_type'],
                                        (string)$log['details']
                                    );
                                    $auditDescriptionTemplate = $viDictionary[$auditDescription['key']] ?? '';
                                    $auditDescriptionText = strtr(
                                        $auditDescriptionTemplate,
                                        array_map(
                                            static fn(string $value): string => $value,
                                            array_combine(
                                                array_map(static fn(string $name): string => '{' . $name . '}', array_keys($auditDescription['params'])),
                                                array_values($auditDescription['params'])
                                            ) ?: []
                                        )
                                    );
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-1.5 font-mono font-bold text-indigo-600 text-xs"><?= htmlspecialchars($log['action_type']) ?></td>
                                        <td class="px-3 py-1.5 text-gray-600 text-xs"><?php if (($log['actor_username'] ?? null) === 'Hệ thống'): ?><span data-i18n="audit.system">Hệ thống</span><?php else: ?><?= htmlspecialchars((string)$log['actor_username'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></td>
                                        <td class="px-3 py-1.5 text-gray-800"><span data-i18n="<?= htmlspecialchars($auditDescription['key'], ENT_QUOTES, 'UTF-8') ?>"<?php foreach ($auditDescription['params'] as $name => $value): ?> data-i18n-<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?php endforeach; ?>><?= htmlspecialchars($auditDescriptionText, ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="px-3 py-1.5 text-gray-400 text-xs"><?= htmlspecialchars($log['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic" data-i18n="audit.empty">Chưa có nhật ký hoạt động nào được ghi nhận.</p>
                <?php endif; ?>
            </div>
            <div class="tms-bottom-section tms-reviewer-performance-section tms-member-performance w-full col-span-12 tms-accent-indigo h-full flex flex-col bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">📈 <span data-i18n="audit.member_performance">Hiệu suất thành viên</span></h2>
                <?php if ($productivity !== []): ?>
                    <div class="tms-productivity-grid">
                        <?php foreach ($productivity as $member): ?>
                            <div class="tms-productivity-card">
                                <strong><?php if ($member['username'] === 'Hệ thống'): ?><span data-i18n="audit.system">Hệ thống</span><?php else: ?><?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong>
                                <span data-i18n="audit.keys_touched" data-i18n-count="<?= (int)$member['keys_touched'] ?>"><?= (int)$member['keys_touched'] ?> keys đã chạm</span>
                                <span data-i18n="audit.updates" data-i18n-count="<?= (int)$member['updates'] ?>"><?= (int)$member['updates'] ?> lượt cập nhật</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-400" data-i18n="translation.no_performance">Chưa có dữ liệu hiệu suất.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </div>
    <script>
        (() => {
            const suggestionUrl = new URL(window.location.href);
            suggestionUrl.searchParams.set('action', 'suggest_translation');
            let activeSuggestionBox = null;
            const closeSuggestions = () => {
                activeSuggestionBox?.remove();
                activeSuggestionBox = null;
            };
            document.querySelectorAll('input[name="translated_text"]:not(:disabled)').forEach((input) => {
                let requestSerial = 0;
                let suggestionTimer = 0;
                let suggestionController = null;
                input.addEventListener('input', (event) => {
                    if (!event.isTrusted) return;
                    const stringId = input.form?.querySelector('input[name="string_id"]')?.value;
                    if (!stringId || !input.dataset.sourceText) return;
                    const serial = ++requestSerial;
                    window.clearTimeout(suggestionTimer);
                    suggestionController?.abort();
                    closeSuggestions();
                    suggestionTimer = window.setTimeout(async () => {
                        const box = document.createElement('div');
                        box.className = 'tms-inline-suggestions';
                        box.textContent = 'Đang tìm gợi ý...';
                        input.closest('.tms-translation-input-group')?.append(box);
                        activeSuggestionBox = box;
                        const requestUrl = new URL(suggestionUrl);
                        requestUrl.searchParams.set('string_id', stringId);
                        suggestionController = new AbortController();
                        try {
                            const response = await fetch(requestUrl, {
                                headers: {'Accept': 'application/json'},
                                signal: suggestionController.signal
                            });
                            const contentType = response.headers.get('content-type') || '';
                            if (!contentType.toLowerCase().includes('application/json')) {
                                const rawBody = await response.text();
                                console.error('[TMS Translation Suggestion] Non-JSON response.', {
                                    status: response.status,
                                    contentType,
                                    body: rawBody.slice(0, 1000)
                                });
                                throw new Error('Server trả về không phải JSON hợp lệ.');
                            }
                            const payload = await response.json();
                            if (serial !== requestSerial) return;
                            if (!response.ok || !payload.success) {
                                throw new Error(payload.message || `Gợi ý thất bại (HTTP ${response.status}).`);
                            }
                            box.replaceChildren();
                            const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];
                            if (payload.suggestion) suggestions.unshift({
                                translated_text: payload.suggestion,
                                score: payload.score || 0,
                                tier: payload.tier || 'suggestion'
                            });
                            const unique = new Set();
                            suggestions.slice(0, 5).forEach((suggestion) => {
                                const text = String(suggestion.translated_text || '');
                                if (!text || unique.has(text)) return;
                                unique.add(text);
                                const option = document.createElement('button');
                                option.type = 'button';
                                option.className = 'tms-inline-suggestion';
                                option.textContent = text;
                                option.title = `${suggestion.tier || 'TM'} · ${Math.round(Number(suggestion.score || 0) * 100)}%`;
                                option.addEventListener('mousedown', (mousedownEvent) => mousedownEvent.preventDefault());
                                option.addEventListener('click', () => {
                                    input.value = text;
                                    input.dispatchEvent(new Event('input', {bubbles: true}));
                                    closeSuggestions();
                                });
                                box.append(option);
                            });
                            if (!box.childElementCount) {
                                box.textContent = 'Chưa tìm thấy gợi ý phù hợp.';
                                box.classList.add('is-empty');
                            }
                        } catch (error) {
                            if (serial === requestSerial && !(error instanceof Error && error.name === 'AbortError')) {
                                box.textContent = error instanceof Error ? error.message : 'Không thể tải gợi ý lúc này.';
                                box.classList.add('is-empty');
                            }
                        }
                    }, 250);
                });
                input.addEventListener('blur', () => {
                    window.clearTimeout(suggestionTimer);
                    suggestionController?.abort();
                    window.setTimeout(closeSuggestions, 160);
                });
            });
            const opener = document.querySelector('[data-open-google-translate]');
            const dialog = document.getElementById('google-auto-translate-dialog');
            const start = document.getElementById('google-auto-translate-start');
            const status = document.getElementById('google-auto-translate-status');
            const eta = document.getElementById('google-auto-translate-eta');
            const forcePlaceholderControl = document.getElementById('google-auto-translate-force-placeholders-control');
            const forcePlaceholderCheckbox = document.getElementById('google-auto-translate-force-placeholders');
            const bar = document.getElementById('google-auto-translate-bar');
            const progress = document.getElementById('google-auto-translate-progress');
            const progressLabel = document.getElementById('google-auto-translate-progress-label');
            const providerSelect = document.getElementById('translator-provider');
            const csrfToken = document.getElementById('google-auto-translate-csrf')?.value;
            if (opener && dialog && start && csrfToken) {
                const setLocalizedText = (element, key, fallback, params = {}) => {
                    element.dataset.i18n = key;
                    Object.entries(params).forEach(([name, value]) => {
                        element.setAttribute(`data-i18n-${name}`, String(value));
                    });
                    element.textContent = fallback.replace(/\{([a-z0-9_]+)\}/gi, (match, name) =>
                        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
                    );
                };
                let isRunning = false;
                let isCancelled = false;
                let progressLoading = false;
                let activeController = null;
                const activeControllers = new Set();
                let activePendingRows = [];
                let activeCompleted = 0;
                let activeTranslatedIds = new Set();
                let totalRows = 0;
                let translatedRows = 0;
                const setOpenerState = (percent = null) => {
                    if (isRunning) {
                        setLocalizedText(
                            opener,
                            percent === null ? 'translation.stop' : 'translation.stop_progress',
                            percent === null ? '⏹ Dừng dịch' : `⏹ Dừng dịch · ${percent}%`,
                            percent === null ? {} : {percent}
                        );
                        opener.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                        opener.classList.add('bg-amber-600', 'hover:bg-amber-700');
                        opener.dataset.i18nAriaLabel = 'translation.stop_aria';
                        opener.setAttribute('aria-label', 'Dừng tiến trình dịch tự động');
                        return;
                    }
                    setLocalizedText(opener, 'translation.auto_translate_short', '🌐 Dịch tự động');
                    opener.classList.remove('bg-amber-600', 'hover:bg-amber-700');
                    opener.classList.add('bg-blue-600', 'hover:bg-blue-700');
                    opener.removeAttribute('aria-label');
                    delete opener.dataset.i18nAriaLabel;
                };
                const markUnfinishedRows = () => {
                    activePendingRows.forEach((row) => {
                        if (activeTranslatedIds.has(row.stringId)) return;
                        const input = document.querySelector(
                            `input[name="translated_text"][form="translation-form-${row.stringId}"]`
                        );
                        const formId = input?.getAttribute('form');
                        const statusSelect = formId
                            ? document.getElementById(formId)?.querySelector('[name="status"]')
                            : null;
                        if (statusSelect) {
                            if (window.tmsUpdateStatusBadge) {
                                window.tmsUpdateStatusBadge(
                                    statusSelect,
                                    input?.value.trim() ? 'needs_review' : 'draft'
                                );
                            } else {
                                statusSelect.value = input?.value.trim() ? 'needs_review' : 'draft';
                            }
                        }
                    });
                };
                const cancelTranslation = () => {
                    if (!isRunning) return;
                    isCancelled = true;
                    activeController?.abort();
                    activeControllers.forEach((controller) => controller.abort());
                    markUnfinishedRows();
                    setLocalizedText(status, 'translation.stopped', `Đã dừng. ${activeCompleted} dòng đã được giữ lại; các dòng còn lại cần kiểm tra.`, {count: activeCompleted});
                    setLocalizedText(eta, 'translation.resume_hint', 'Bạn có thể mở lại để tiếp tục dịch.');
                    setLocalizedText(start, 'translation.resume', 'Tiếp tục dịch');
                    setOpenerState();
                };
                const readTranslationResponse = async (response) => {
                    const rawBody = await response.text();
                    let result;
                    try {
                        result = rawBody === '' ? {} : JSON.parse(rawBody);
                    } catch (parseError) {
                        console.error('[TMS MyMemory] Phản hồi không phải JSON.', {
                            status: response.status,
                            statusText: response.statusText,
                            contentType: response.headers.get('content-type'),
                            body: rawBody.slice(0, 2000),
                            parseError
                        });
                        throw new Error(`Máy chủ trả về dữ liệu không hợp lệ (HTTP ${response.status}).`);
                    }
                    console.log('[TMS MyMemory] Phản hồi API.', {
                        status: response.status,
                        statusText: response.statusText,
                        success: result.success,
                        message: result.message || null,
                        errorCode: result.error_code || null,
                        details: result.details || null
                    });
                    return {response, result};
                };
                const updateProgress = () => {
                    const percent = totalRows > 0
                        ? Math.round((translatedRows / totalRows) * 100)
                        : 100;
                    setLocalizedText(progressLabel, 'translation.progress', `Đã dịch ${translatedRows}/${totalRows} câu (${percent}%)`, {
                        translated: translatedRows,
                        total: totalRows,
                        percent
                    });
                    setLocalizedText(status, 'translation.progress', `Đã dịch ${translatedRows}/${totalRows} câu (${percent}%)`, {
                        translated: translatedRows,
                        total: totalRows,
                        percent
                    });
                    progress.setAttribute('aria-valuenow', String(percent));
                    bar.style.width = `${percent}%`;
                    setOpenerState(percent);
                };
                const updateBatchProgress = (completedBatches, batchCount) => {
                    const percent = totalRows > 0
                        ? Math.round((translatedRows / totalRows) * 100)
                        : 100;
                    const params = {current: completedBatches, total: batchCount, percent};
                    setLocalizedText(progressLabel, 'translation.progress', `Đã dịch ${translatedRows}/${totalRows} câu (${percent}%)`, {
                        translated: translatedRows,
                        total: totalRows,
                        percent
                    });
                    setLocalizedText(status, 'translation.batch_progress', `Đã hoàn tất ${completedBatches}/${batchCount} gói · ${percent}%`, params);
                    progress.setAttribute('aria-valuenow', String(percent));
                    bar.style.width = `${percent}%`;
                    setOpenerState(percent);
                };
                const updateBatchStarted = (batchIndex, batchCount) => {
                    const percent = totalRows > 0
                        ? Math.round((translatedRows / totalRows) * 100)
                        : 100;
                    setLocalizedText(status, 'translation.batch_started', `Đang xử lý gói ${batchIndex + 1}/${batchCount} · ${percent}%`, {
                        current: batchIndex + 1,
                        total: batchCount,
                        percent
                    });
                };
                const splitIntoBatches = (rows, maxSize = 20) => {
                    if (rows.length === 0) return [];
                    let batchCount = Math.ceil(rows.length / 15);
                    if (Math.floor(rows.length / batchCount) < 10) {
                        batchCount = Math.ceil(rows.length / maxSize);
                    }
                    const baseSize = Math.floor(rows.length / batchCount);
                    const largerBatchCount = rows.length % batchCount;
                    const batches = [];
                    let offset = 0;
                    for (let index = 0; index < batchCount; index++) {
                        const size = baseSize + (index < largerBatchCount ? 1 : 0);
                        batches.push(rows.slice(offset, offset + size));
                        offset += size;
                    }
                    return batches;
                };
                const loadPendingRows = async (progressOnly = false) => {
                    const pendingEndpoint = new URL(window.location.href);
                    pendingEndpoint.searchParams.set('action', 'google_translate_pending');
                    activeController = new AbortController();
                    const pendingResponse = await fetch(pendingEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
                        signal: activeController.signal,
                        body: new URLSearchParams({
                            action: 'google_translate_pending',
                            csrf_token: csrfToken,
                            progress_only: progressOnly ? '1' : '0',
                            translator_provider: providerSelect?.value || 'mymemory',
                        })
                    });
                    const pendingPayload = await readTranslationResponse(pendingResponse);
                    activeController = null;
                    if (!pendingResponse.ok || !pendingPayload.result.success) {
                        throw new Error(pendingPayload.result.message || 'Không thể tải danh sách chuỗi chưa dịch.');
                    }
                    totalRows = Number(pendingPayload.result.total || 0);
                    translatedRows = Number(pendingPayload.result.translated || 0);
                    activePendingRows = Array.isArray(pendingPayload.result.rows)
                        ? pendingPayload.result.rows
                        : [];
                    return activePendingRows;
                };
                opener.addEventListener('click', async () => {
                    if (isRunning) {
                        cancelTranslation();
                        return;
                    }
                    dialog.showModal();
                    progressLoading = true;
                    start.disabled = true;
                    setLocalizedText(status, 'translation.checking_progress', 'Đang kiểm tra tiến độ bản dịch...');
                    setLocalizedText(eta, 'translation.database_loading', 'Đang tải dữ liệu từ cơ sở dữ liệu.');
                    setLocalizedText(progressLabel, 'translation.checking_progress', 'Đang kiểm tra tiến độ bản dịch...');
                    bar.style.width = '0%';
                    progress.setAttribute('aria-valuenow', '0');
                    try {
                        await loadPendingRows(true);
                        updateProgress();
                        const remaining = Math.max(totalRows - translatedRows, 0);
                        if (remaining > 0) {
                            setLocalizedText(status, 'translation.remaining', `Còn ${remaining} câu chưa có bản dịch.`, {count: remaining});
                            setLocalizedText(eta, 'translation.remaining_to_translate', `Còn ${remaining} câu cần dịch.`, {count: remaining});
                        } else {
                            setLocalizedText(status, 'translation.all_translated', 'Tất cả câu đã có bản dịch.');
                            setLocalizedText(eta, 'translation.all_translated', 'Tất cả câu đã có bản dịch.');
                        }
                        setLocalizedText(start, remaining > 0 ? 'translation.start' : 'translation.done', remaining > 0 ? 'Bắt đầu dịch' : 'Đã hoàn tất');
                        start.disabled = remaining === 0;
                    } catch (error) {
                        status.textContent = error instanceof Error ? error.message : 'Không thể kiểm tra tiến độ.';
                        setLocalizedText(eta, 'translation.retry_progress', 'Vui lòng thử tải lại tiến độ.');
                        setLocalizedText(start, 'translation.retry', 'Thử lại');
                        start.disabled = false;
                    } finally {
                        progressLoading = false;
                        activeController = null;
                    }
                });
                document.querySelectorAll('[data-close-google-auto-translate]').forEach((button) => {
                    button.addEventListener('click', () => dialog.close());
                });
                let resumeRequired = false;
                start.addEventListener('click', async () => {
                    if (isRunning || progressLoading) return;
                    isRunning = true;
                    isCancelled = false;
                    const forcePlaceholderSave = forcePlaceholderCheckbox?.checked === true;
                    if (forcePlaceholderCheckbox) forcePlaceholderCheckbox.disabled = true;
                    setOpenerState(Math.round((translatedRows / Math.max(totalRows, 1)) * 100));
                    start.disabled = true;
                    let pendingRows = [];
                    activeTranslatedIds = new Set();
                    const visibleInput = (stringId) => document.querySelector(
                        `input[name="translated_text"][form="translation-form-${stringId}"]`
                    );
                    let completed = 0;
                    activeCompleted = 0;
                    let rateLimited = false;
                    const failedBatches = [];
                    const forcedPlaceholderWarnings = [];
                    let startedAt = performance.now();
                    const waitWithCountdown = async (seconds, messageKey) => {
                        for (let remaining = seconds; remaining > 0; remaining--) {
                            if (isCancelled) throw new DOMException('Translation cancelled', 'AbortError');
                            const fallback = messageKey === 'translation.wait_resume'
                                ? `Đang chuẩn bị tiếp tục dịch. Còn ${remaining} giây...`
                                : `MyMemory đang giới hạn tần suất. Còn ${remaining} giây...`;
                            setLocalizedText(status, messageKey, fallback, {seconds: remaining});
                            setLocalizedText(eta, 'translation.safe_wait', 'Tạm dừng an toàn để tránh HTTP 429.');
                            await new Promise((resolve) => setTimeout(resolve, 1000));
                        }
                    };
                    const formatEta = (remaining, elapsed) => {
                        if (completed === 0 || elapsed <= 0) {
                            setLocalizedText(eta, 'translation.eta_calculating', 'Đang tính thời gian còn lại...');
                            return;
                        }
                        const seconds = Math.ceil((remaining * elapsed / completed) / 1000);
                        setLocalizedText(eta, 'translation.eta_remaining', `Ước tính còn lại: ~${seconds} giây`, {seconds});
                    };
                    try {
                        if (resumeRequired) {
                            await waitWithCountdown(3, 'translation.wait_resume');
                        }
                        pendingRows = await loadPendingRows();
                        updateProgress();
                        setLocalizedText(
                            status,
                            pendingRows.length ? 'translation.translating_pending' : 'translation.no_pending',
                            pendingRows.length ? `Đang dịch bằng ${providerSelect?.selectedOptions[0]?.text || 'nhà cung cấp đã chọn'}...` : 'Không còn câu nào cần dịch.'
                        );
                        let completedBatchCount = 0;
                        const processBatch = async (batch, batchIndex, batchCount, sequential = false) => {
                            const controller = new AbortController();
                            activeControllers.add(controller);
                            try {
                                const endpoint = new URL(window.location.href);
                                endpoint.searchParams.set('action', 'google_translate_batch');
                                const response = await fetch(endpoint, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
                                    signal: controller.signal,
                                    body: new URLSearchParams({
                                        action: 'google_translate_batch',
                                        csrf_token: csrfToken,
                                        rows: JSON.stringify(batch.map((row) => ({stringId: row.stringId}))),
                                        force_placeholders: forcePlaceholderSave ? '1' : '0',
                                        translator_provider: providerSelect?.value || 'mymemory',
                                        target_locale: <?= json_encode($currentLocale, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                                    })
                                });
                                const {result} = await readTranslationResponse(response);
                                if (!response.ok || !result.success) {
                                    const error = new Error(result.details || result.message || `Không thể xử lý gói ${batchIndex + 1}.`);
                                    error.retryable = result.retryable === true;
                                    throw error;
                                }
                                for (const translation of (Array.isArray(result.translations) ? result.translations : [])) {
                                    const stringId = Number(translation.stringId);
                                    if (!Number.isInteger(stringId) || stringId < 1) continue;
                                    activeTranslatedIds.add(stringId);
                                    const input = visibleInput(stringId);
                                    if (input) {
                                        input.value = translation.translatedText;
                                        input.dataset.savedText = translation.translatedText;
                                        input.dispatchEvent(new Event('input', {bubbles: true}));
                                    }
                                    if (translation.updatedAt && window.tmsSetTranslationUpdatedAt) {
                                        window.tmsSetTranslationUpdatedAt(stringId, translation.updatedAt);
                                    }
                                }
                                const reportedProgress = Number(result.progress_translated);
                                if (Number.isFinite(reportedProgress)) {
                                    translatedRows = Math.max(translatedRows, reportedProgress);
                                } else {
                                    translatedRows = Math.max(translatedRows, activeTranslatedIds.size);
                                }
                                (Array.isArray(result.errors) ? result.errors : []).forEach((item) => {
                                    failedBatches.push(`Câu ${item.stringId}: ${item.message}`);
                                    if (item.code === 'PLACEHOLDER_MISMATCH' && item.forceSaveable === true) {
                                        forcePlaceholderControl.hidden = false;
                                    }
                                    if (item.retryable) rateLimited = true;
                                });
                                (Array.isArray(result.warnings) ? result.warnings : []).forEach((item) => {
                                    forcedPlaceholderWarnings.push(`Câu ${item.stringId}: ${item.message}`);
                                });
                                if (result.retryable) rateLimited = true;
                            } catch (error) {
                                if (isCancelled || error?.name === 'AbortError') throw error;
                                rateLimited = rateLimited || error?.retryable === true;
                                const detail = error instanceof Error ? error.message : 'Lỗi mạng không xác định.';
                                failedBatches.push(sequential
                                    ? `Câu ${batchIndex + 1}/${batchCount}: ${detail}`
                                    : `Gói ${batchIndex + 1}/${batchCount}: ${detail}`);
                                console.error('[TMS MyMemory] Gói dịch thất bại.', {
                                    batch: batchIndex + 1,
                                    totalBatches: batchCount,
                                    error
                                });
                            } finally {
                                activeControllers.delete(controller);
                                if (!sequential) completedBatchCount++;
                                completed += batch.length;
                                activeCompleted = activeTranslatedIds.size;
                                if (sequential) {
                                    updateProgress();
                                } else {
                                    updateBatchProgress(completedBatchCount, batchCount);
                                }
                                formatEta(Math.max(totalRows - translatedRows, 0), performance.now() - startedAt);
                            }
                        };
                        if (pendingRows.length <= 10) {
                            completed = 0;
                            for (const [rowIndex, row] of pendingRows.entries()) {
                                if (isCancelled || rateLimited) break;
                                setLocalizedText(
                                    status,
                                    'translation.translating_row',
                                    `Đang dịch câu ${rowIndex + 1}/${pendingRows.length}`,
                                    {current: rowIndex + 1, total: pendingRows.length}
                                );
                                await processBatch([row], rowIndex, pendingRows.length, true);
                            }
                        } else {
                            const batches = splitIntoBatches(pendingRows);
                            let nextBatchIndex = 0;
                            const workerCount = Math.min(5, batches.length);
                            const worker = async () => {
                                while (!isCancelled && !rateLimited) {
                                    const batchIndex = nextBatchIndex++;
                                    if (batchIndex >= batches.length) return;
                                    updateBatchStarted(batchIndex, batches.length);
                                    await processBatch(batches[batchIndex], batchIndex, batches.length);
                                    if (!isCancelled && !rateLimited && nextBatchIndex < batches.length) {
                                        await new Promise((resolve) => setTimeout(resolve, 250));
                                    }
                                }
                            };
                            await Promise.allSettled(
                                Array.from({length: workerCount}, () => worker())
                            );
                        }
                        if (isCancelled) throw new DOMException('Translation cancelled', 'AbortError');
                        const remaining = Math.max(totalRows - translatedRows, 0);
                        if (failedBatches.length > 0) {
                            const displayedErrors = failedBatches.slice(0, 3).join(' | ');
                            const omittedErrors = failedBatches.length > 3 ? ` (+${failedBatches.length - 3} lỗi khác)` : '';
                            setLocalizedText(
                                status,
                                forcedPlaceholderWarnings.length > 0
                                    ? 'translation.batch_failures_with_forced_saves'
                                    : 'translation.batch_failures',
                                forcedPlaceholderWarnings.length > 0
                                    ? `Hoàn tất các gói có thể xử lý; có ${failedBatches.length} lỗi và đã buộc lưu ${forcedPlaceholderWarnings.length} câu có cảnh báo về biến.`
                                    : `Hoàn tất các gói có thể xử lý; có ${failedBatches.length} lỗi.`,
                                {count: failedBatches.length, forced: forcedPlaceholderWarnings.length}
                            );
                            setLocalizedText(eta, 'translation.batch_failure_details', `Chi tiết lỗi: ${displayedErrors}${omittedErrors}`, {
                                details: `${displayedErrors}${omittedErrors}`
                            });
                        } else {
                            setLocalizedText(status, 'translation.done', 'Đã hoàn tất.');
                            if (forcedPlaceholderWarnings.length > 0) {
                                setLocalizedText(
                                    eta,
                                    'translation.force_placeholder_saved',
                                    'Đã buộc lưu {count} câu dù có cảnh báo về biến.',
                                    {count: forcedPlaceholderWarnings.length}
                                );
                            } else {
                                setLocalizedText(eta, 'translation.done', 'Đã hoàn tất.');
                            }
                        }
                        setLocalizedText(start, remaining > 0 ? 'translation.resume' : 'translation.done',
                            remaining > 0 ? 'Tiếp tục dịch' : 'Đã hoàn tất');
                        start.disabled = remaining === 0;
                        resumeRequired = rateLimited;
                    } catch (error) {
                        if (isCancelled || error?.name === 'AbortError') {
                            markUnfinishedRows();
                            setLocalizedText(status, 'translation.stopped', `Đã dừng. ${completed} dòng đã được giữ lại; các dòng còn lại cần kiểm tra.`, {count: completed});
                            setLocalizedText(eta, 'translation.resume_hint', 'Bạn có thể mở lại để tiếp tục dịch.');
                            setLocalizedText(start, 'translation.resume', 'Tiếp tục dịch');
                            return;
                        }
                        console.error('[TMS MyMemory] Gọi API thất bại.', error);
                        updateProgress();
                        status.textContent = error instanceof Error ? error.message : 'Không thể dịch tự động.';
                        resumeRequired = rateLimited;
                        setLocalizedText(eta, rateLimited
                                ? 'translation.auto_wait'
                                : (completed > 0 ? 'translation.saved_rows' : 'translation.no_rows_saved'),
                            rateLimited
                                ? 'Hệ thống sẽ tự động chờ 8 giây trước khi cho phép tiếp tục.'
                                : (completed > 0
                                    ? `Đã giữ lại ${completed} dòng đã lưu. Nhấn "Tiếp tục dịch" để chạy tiếp.`
                                    : 'Chưa có dòng nào được lưu.'),
                            completed > 0 && !rateLimited ? {count: completed} : {});
                        setLocalizedText(start, 'translation.resume', 'Tiếp tục dịch');
                        if (rateLimited) {
                            await waitWithCountdown(8, 'translation.wait_rate_limit');
                        }
                    } finally {
                        activeController = null;
                        isRunning = false;
                        isCancelled = false;
                        if (forcePlaceholderCheckbox) forcePlaceholderCheckbox.disabled = false;
                        start.disabled = Math.max(totalRows - translatedRows, 0) === 0;
                        setOpenerState();
                    }
                });
            }
        })();
    </script>
</body>
</html>