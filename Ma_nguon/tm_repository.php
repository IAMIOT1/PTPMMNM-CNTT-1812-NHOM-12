<?php
declare(strict_types=1);

$wantsJson = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (
        (string)($_POST['response_format'] ?? '') === 'json'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    );
$sendJson = static function (array $payload, int $statusCode = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if ($wantsJson) {
    ini_set('display_errors', '0');
    ob_start();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(static function (Throwable $exception) use ($sendJson): void {
        error_log('TM_REPOSITORY_JSON_REQUEST_FAILED: ' . $exception->getMessage());
        $sendJson(['success' => false, 'message' => 'Không thể xử lý yêu cầu kho TM. Vui lòng thử lại.'], 500);
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
        error_log('TM_REPOSITORY_JSON_REQUEST_FATAL: ' . $error['message']);
        $sendJson(['success' => false, 'message' => 'Không thể xử lý yêu cầu kho TM. Vui lòng thử lại.'], 500);
    });
}

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/TmRepository.php';

$conn = db();
$auth = new AuthManager($conn);
$user = $auth->getCurrentUser();
if ($user === null) {
    if ($wantsJson) {
        $sendJson(['success' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
    }
    $auth->redirectToLogin('/tm_repository.php');
}
$csrfToken = Csrf::token();
$repository = new TmRepository($conn, dirname(__DIR__) . '/storage/tm-repository');
$canManage = ProjectAccess::isSystemAdmin($user);
if (!$canManage) {
    $accessResult = queryParams(
        $conn,
        "SELECT EXISTS (
            SELECT 1 FROM project_members
            WHERE user_id = $1 AND role_in_project = 'Team Lead'
         )",
        [(int)$user['id']]
    );
    $canManage = (bool)pg_fetch_result($accessResult, 0, 0);
}
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::isValid($_POST)) {
        if ($wantsJson) {
            $sendJson(['success' => false, 'message' => 'Phiên làm việc không hợp lệ. Tải lại trang và thử lại.'], 403);
        }
        http_response_code(403);
        exit('Phiên làm việc không hợp lệ.');
    }
    if (!$canManage) {
        if ($wantsJson) {
            $sendJson(['success' => false, 'message' => 'Bạn không có quyền quản lý kho TM.'], 403);
        }
        http_response_code(403);
        exit('Chỉ quản trị viên hoặc Trưởng nhóm mới có thể quản lý kho TM.');
    }
    try {
        if (($_POST['action'] ?? '') === 'upload_tm_csv') {
            $upload = $_FILES['tm_csv'] ?? [];
            if (!is_array($upload)) {
                throw new InvalidArgumentException('TM_REPOSITORY_UPLOAD_INVALID');
            }
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
            $result = $repository->importUploaded($upload['tmp_name'], $upload['name']);
            if ($wantsJson) {
                $path = $repository->resolveFilename($result['filename']);
                $stat = stat($path);
                if ($stat === false) {
                    throw new RuntimeException('TM_REPOSITORY_FILE_STAT_FAILED');
                }
                $sendJson([
                    'success' => true,
                    'message' => "Đã nhập {$result['rows']} cặp câu vào kho TM.",
                    'file' => [
                        'name' => $result['filename'],
                        'size' => (int)$stat['size'],
                        'modified_at' => (int)$stat['mtime'],
                        'rows' => $result['rows'],
                    ],
                ]);
            }
            $success = "Đã nhập {$result['rows']} cặp câu vào kho TM.";
        } elseif (($_POST['action'] ?? '') === 'delete_tm_csv') {
            $repository->delete((string)($_POST['filename'] ?? ''));
            $success = 'Đã xóa file khỏi kho lưu trữ.';
        }
    } catch (Throwable $exception) {
        error_log('TM_REPOSITORY_ACTION_FAILED: ' . $exception->getMessage());
        if ($wantsJson) {
            $message = match ($exception->getMessage()) {
                'TM_REPOSITORY_UPLOAD_INVALID' => 'Vui lòng chọn file CSV hợp lệ để tải lên.',
                'TM_REPOSITORY_CSV_EXTENSION_REQUIRED' => 'Chỉ chấp nhận tệp có đuôi .csv.',
                'TM_REPOSITORY_UPLOAD_TOO_LARGE' => 'Tệp CSV trống hoặc vượt quá giới hạn 5 MB.',
                'TM_REPOSITORY_CSV_EMPTY' => 'Tệp CSV không có dữ liệu hợp lệ.',
                'TM_REPOSITORY_CSV_COLUMNS_REQUIRED' => 'CSV cần có các cột locale, source_text và target_text.',
                'TM_REPOSITORY_STORAGE_NOT_WRITABLE' => 'Thư mục lưu trữ TM không có quyền ghi.',
                default => 'Không thể nhập file TM. Vui lòng kiểm tra định dạng CSV và thử lại.',
            };
            $statusCode = $exception instanceof InvalidArgumentException ? 400 : 500;
            $sendJson(['success' => false, 'message' => $message], $statusCode);
        }
        $error = 'Không thể xử lý file TM. Vui lòng kiểm tra định dạng CSV và thử lại.';
    }
}

if (($_GET['action'] ?? '') === 'download') {
    $path = $repository->resolveFilename((string)($_GET['filename'] ?? ''));
    if (!is_file($path)) {
        http_response_code(404);
        exit('Không tìm thấy file.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$files = $repository->listFiles();
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="tm.title">TM Repository &amp; CSV Manager - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body>
<main class="tms-shell mx-auto max-w-7xl px-4 py-8">
    <header class="tms-topbar mb-8 flex flex-col gap-5 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="min-w-0">
            <nav aria-label="Breadcrumb" class="mb-2 flex items-center gap-2 text-xs font-medium text-slate-400">
                <a href="index.php" class="transition hover:text-indigo-600" data-i18n="menu.workspace">Workspace</a>
                <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L10.94 10 7.23 6.29a.75.75 0 1 1 1.06-1.06l4.24 4.24a.75.75 0 0 1 0 1.06l-4.24 4.24a.75.75 0 0 1-1.08 0Z" clip-rule="evenodd"/></svg>
                <span class="text-slate-600" data-i18n="tm.breadcrumb">TM Repository</span>
            </nav>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl" data-i18n="tm.title">TM Repository &amp; CSV Manager</h1>
                <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-700">TRANSLATION MEMORY</span>
            </div>
            <p class="mt-1 text-sm text-slate-500" data-i18n="tm.subtitle">Quản lý các tệp bộ nhớ dịch dùng chung trong hệ thống.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 self-start sm:self-center">
            <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
            <a href="index.php" class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-slate-200 px-3.5 py-2 text-sm font-semibold text-slate-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700">
                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 1 1 1.06 1.06l-3.22 3.22h10.69A.75.75 0 0 1 17 10Z" clip-rule="evenodd"/></svg>
                <span data-i18n="menu.workspace">Workspace</span>
            </a>
        </div>
    </header>
    <?php if ($success !== ''): ?><div class="tms-notice mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="tms-notice mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($canManage): ?>
    <section class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="upload-heading">
        <div class="mb-4">
            <h2 id="upload-heading" class="text-base font-semibold text-slate-900" data-i18n="tm.upload_heading">Thêm tệp vào kho TM</h2>
            <p class="mt-1 text-sm text-slate-500" data-i18n="tm.upload_description">Tệp CSV sẽ được nhập vào Translation Memory dùng chung.</p>
        </div>
        <form id="tm-upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="upload_tm_csv">
            <label id="tm-dropzone" for="tm-csv-input" class="group flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50/70 px-5 py-8 text-center transition hover:border-indigo-400 hover:bg-indigo-50/60 focus-within:border-indigo-500 focus-within:ring-4 focus-within:ring-indigo-100 sm:py-10">
                <span class="mb-3 grid h-12 w-12 place-items-center rounded-xl bg-indigo-100 text-indigo-600 transition group-hover:bg-indigo-600 group-hover:text-white">
                    <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M7 18a4.6 4.6 0 0 1-.6-9.16A6 6 0 0 1 18 7.5a4.5 4.5 0 0 1 .5 8.97M12 12v9m0-9 3.5 3.5M12 12 8.5 15.5"/></svg>
                </span>
                <span class="text-sm font-semibold text-slate-800"><span data-i18n="tm.dropzone">Kéo thả file CSV vào đây hoặc</span> <span class="text-indigo-600" data-i18n="tm.choose_file">bấm để chọn file</span></span>
                <span id="tm-file-selection" class="mt-1.5 text-xs text-slate-500">Định dạng CSV · Tối đa 5 MB mỗi file · Có thể chọn nhiều file</span>
                <input id="tm-csv-input" class="sr-only" type="file" name="tm_csv" accept=".csv,text/csv" multiple required>
            </label>
            <div class="mt-4 flex flex-col items-stretch justify-between gap-3 sm:flex-row sm:items-center">
                <p class="text-xs leading-5 text-slate-500">Cần có các cột <code class="rounded bg-slate-100 px-1 py-0.5 text-slate-700">locale</code>, <code class="rounded bg-slate-100 px-1 py-0.5 text-slate-700">source_text</code> và <code class="rounded bg-slate-100 px-1 py-0.5 text-slate-700">target_text</code>.</p>
                <button class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-not-allowed disabled:opacity-50" type="submit">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3.5 3A1.5 1.5 0 0 0 2 4.5v11A1.5 1.5 0 0 0 3.5 17h13a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 16.5 6H10L8 3H3.5Z"/><path d="M10 8a.75.75 0 0 1 .75.75v2.19l.72-.72a.75.75 0 1 1 1.06 1.06l-2 2a.75.75 0 0 1-1.06 0l-2-2a.75.75 0 1 1 1.06-1.06l.72.72V8.75A.75.75 0 0 1 10 8Z" fill="#fff"/></svg>
                    <span data-i18n="tm.upload">Tải lên kho lưu trữ</span>
                </button>
            </div>
        </form>
        <p id="tm-upload-status" class="mt-3 text-sm" role="status" aria-live="polite"></p>
        <div id="tm-upload-progress" class="mt-3 hidden" aria-live="polite">
            <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-600">
                <span id="tm-upload-progress-label" data-i18n="tm.uploading">Đang tải lên...</span>
                <span id="tm-upload-progress-count">0/0</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                <div id="tm-upload-progress-bar" class="h-full w-0 rounded-full bg-indigo-600 transition-all duration-300"></div>
            </div>
            <ul id="tm-upload-file-status" class="mt-3 space-y-1.5 text-xs text-slate-600"></ul>
        </div>
    </section>
    <?php endif; ?>

    <section class="tms-main-panel overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="files-heading">
        <div class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <h2 id="files-heading" class="text-base font-semibold text-slate-900" data-i18n="tm.files_heading">Tệp trong kho</h2>
                <p class="mt-0.5 text-sm text-slate-500" data-i18n="tm.files_description">Các tệp CSV được sử dụng làm nguồn gợi ý dịch thuật.</p>
            </div>
            <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span><span id="tm-file-count"><?= count($files) ?></span> <span data-i18n="tm.file_count">tệp</span></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="border-b border-slate-100 bg-slate-50/80">
                    <tr class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3.5 sm:px-6" data-i18n="tm.file_name">Tên file</th>
                        <th class="px-5 py-3.5" data-i18n="tm.file_size">Kích thước</th>
                        <th class="px-5 py-3.5" data-i18n="tm.updated">Cập nhật</th>
                        <th class="px-5 py-3.5 text-right" data-i18n="common.actions">Thao tác</th>
                    </tr>
                </thead>
                <tbody id="tm-files-list" class="divide-y divide-slate-100">
                <?php foreach ($files as $file): ?>
                    <tr class="transition hover:bg-slate-50/70">
                        <td class="px-5 py-4 sm:px-6"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7l-5-5H4.5Zm7.25 1.81L15.19 7.25h-2.69a.75.75 0 0 1-.75-.75V3.81ZM6.75 11a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5h5a.75.75 0 0 0 0-1.5h-5Z" clip-rule="evenodd"/></svg></span><span class="truncate font-semibold text-slate-800" data-i18n-ignore><?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?></span></div></td>
                        <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= number_format($file['size']) ?> bytes</td>
                        <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= date('d/m/Y H:i', $file['modified_at']) ?></td>
                        <td class="whitespace-nowrap px-5 py-4 text-right">
                            <a class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-semibold text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-700" href="?action=download&amp;filename=<?= rawurlencode($file['name']) ?>"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v7.19l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.22 2.22V2.75A.75.75 0 0 1 10 2ZM3.5 13a.75.75 0 0 1 .75.75v2.75h11.5v-2.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-13a.75.75 0 0 1-.75-.75v-3.5A.75.75 0 0 1 3.5 13Z" clip-rule="evenodd"/></svg><span data-i18n="tm.download">Tải xuống</span></a>
                            <?php if ($canManage): ?><form class="inline" method="post" onsubmit="return confirm('Xóa file này?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete_tm_csv"><input type="hidden" name="filename" value="<?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?>"><button class="inline-flex items-center rounded-md px-2.5 py-1.5 font-semibold text-slate-500 transition hover:bg-red-50 hover:text-red-600" type="submit">Xóa</button></form><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr id="tm-files-empty" <?= $files === [] ? '' : 'hidden' ?>>
                        <td colspan="4" class="px-6 py-14 text-center">
                            <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-slate-100 text-slate-400"><svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h6.5L19 9.25v10A1.75 1.75 0 0 1 17.25 21h-10a1.75 1.75 0 0 1-1.75-1.75V5.5A1.75 1.75 0 0 1 7.25 3.75ZM13 4v5.5h5.5M8.5 14h7m-7 3h7"/></svg></span>
                            <p class="font-semibold text-slate-700" data-i18n="tm.empty">Chưa có file TM nào trong kho</p>
                            <p class="mt-1 text-sm text-slate-500" data-i18n="<?= $canManage ? 'tm.empty_manager_hint' : 'tm.empty_viewer_hint' ?>"><?= $canManage ? 'Tải lên tệp CSV đầu tiên để bắt đầu sử dụng kho bộ nhớ dịch.' : 'Các tệp bộ nhớ dịch dùng chung sẽ xuất hiện tại đây.' ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="tms-i18n.js" defer></script>
<?php if ($canManage): ?>
<script>
(() => {
    const form = document.getElementById('tm-upload-form');
    const status = document.getElementById('tm-upload-status');
    const fileList = document.getElementById('tm-files-list');
    const emptyState = document.getElementById('tm-files-empty');
    const fileInput = document.getElementById('tm-csv-input');
    const dropzone = document.getElementById('tm-dropzone');
    const fileSelection = document.getElementById('tm-file-selection');
    const fileCount = document.getElementById('tm-file-count');
    const progress = document.getElementById('tm-upload-progress');
    const progressLabel = document.getElementById('tm-upload-progress-label');
    const progressCount = document.getElementById('tm-upload-progress-count');
    const progressBar = document.getElementById('tm-upload-progress-bar');
    const fileStatusList = document.getElementById('tm-upload-file-status');
    if (!form || !status || !fileList || !emptyState || !fileInput || !dropzone || !fileSelection || !fileCount
        || !progress || !progressLabel || !progressCount || !progressBar || !fileStatusList) return;

    const submitButton = form.querySelector('button[type="submit"]');
    const setLocalizedText = (element, key, fallback, parameters = {}) => {
        element.dataset.i18n = key;
        ['count', 'size', 'current', 'total', 'name', 'uploaded'].forEach((name) => {
            element.removeAttribute(`data-i18n-${name}`);
        });
        Object.entries(parameters).forEach(([name, value]) => {
            element.setAttribute(`data-i18n-${name}`, String(value));
        });
        element.textContent = fallback.replace(/\{([a-z0-9_]+)\}/gi, (_, name) => String(parameters[name] ?? ''));
    };
    const clearLocalizedText = (element) => {
        element.removeAttribute('data-i18n');
        element.textContent = '';
    };
    const isValidFile = (file) => Boolean(
        file && file.name.toLowerCase().endsWith('.csv') && file.size > 0 && file.size <= 5_000_000
    );
    const selectedFiles = () => Array.from(fileInput.files || []);
    const setSelectedFiles = (files, rejectedFiles = []) => {
        if (files.length === 0) {
            setLocalizedText(fileSelection, 'tm.file_requirements', 'Định dạng CSV · Tối đa 5 MB mỗi file · Có thể chọn nhiều file');
            if (submitButton) submitButton.disabled = true;
            if (rejectedFiles.length > 0) {
                setLocalizedText(status, 'tm.skipped_invalid_count', 'Đã bỏ qua {count} file không hợp lệ. Chỉ có thể tải file CSV tối đa 5 MB.', { count: rejectedFiles.length });
                status.className = 'mt-3 text-sm text-red-700';
            }
            return;
        }
        const totalSize = files.reduce((total, file) => total + file.size, 0);
        setLocalizedText(fileSelection, 'tm.selected_summary', '{count} file đã chọn · {size} MB tổng cộng', {
            count: files.length,
            size: (totalSize / 1024 / 1024).toFixed(2)
        });
        if (submitButton) submitButton.disabled = false;
        if (rejectedFiles.length > 0) {
            setLocalizedText(status, 'tm.skipped_non_csv_count', 'Đã bỏ qua {count} file không phải CSV hoặc vượt quá 5 MB.', { count: rejectedFiles.length });
        } else {
            clearLocalizedText(status);
        }
        status.className = rejectedFiles.length > 0 ? 'mt-3 text-sm text-amber-700' : 'mt-3 text-sm';
    };
    const storeSelectedFiles = (files) => {
        const candidates = Array.from(files);
        const validFiles = candidates.filter(isValidFile);
        const rejectedFiles = candidates.filter((file) => !isValidFile(file));
        const transfer = new DataTransfer();
        validFiles.forEach((file) => transfer.items.add(file));
        fileInput.files = transfer.files;
        setSelectedFiles(validFiles, rejectedFiles);
    };
    fileInput.addEventListener('change', () => storeSelectedFiles(fileInput.files || []));
    for (const eventName of ['dragenter', 'dragover']) {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-indigo-500', 'bg-indigo-50');
        });
    }
    for (const eventName of ['dragleave', 'drop']) {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-indigo-500', 'bg-indigo-50');
        });
    }
    dropzone.addEventListener('drop', (event) => {
        const files = Array.from(event.dataTransfer?.files || []);
        if (files.length > 0) storeSelectedFiles(files);
    });

    const appendUploadedFile = (file, csrfToken) => {
        const row = document.createElement('tr');
        row.className = 'transition hover:bg-slate-50/70';
        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-4 sm:px-6';
        const fileIdentity = document.createElement('div');
        fileIdentity.className = 'flex items-center gap-3';
        const fileIcon = document.createElement('span');
        fileIcon.className = 'grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600';
        fileIcon.innerHTML = '<svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7l-5-5H4.5Zm7.25 1.81L15.19 7.25h-2.69a.75.75 0 0 1-.75-.75V3.81ZM6.75 11a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5h5a.75.75 0 0 0 0-1.5h-5Z" clip-rule="evenodd"/></svg>';
        const fileName = document.createElement('span');
        fileName.className = 'truncate font-semibold text-slate-800';
        fileName.dataset.i18nIgnore = '';
        fileName.textContent = file.name;
        fileIdentity.append(fileIcon, fileName);
        nameCell.append(fileIdentity);
        const sizeCell = document.createElement('td');
        sizeCell.className = 'whitespace-nowrap px-5 py-4 text-slate-600';
        sizeCell.textContent = `${Number(file.size).toLocaleString()} bytes`;
        const modifiedCell = document.createElement('td');
        modifiedCell.className = 'whitespace-nowrap px-5 py-4 text-slate-600';
        modifiedCell.textContent = new Date(Number(file.modified_at) * 1000).toLocaleString('vi-VN');
        const actionsCell = document.createElement('td');
        actionsCell.className = 'whitespace-nowrap px-5 py-4 text-right';
        const download = document.createElement('a');
        download.className = 'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 font-semibold text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-700';
        download.href = `?action=download&filename=${encodeURIComponent(file.name)}`;
        download.innerHTML = '<svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v7.19l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.22 2.22V2.75A.75.75 0 0 1 10 2ZM3.5 13a.75.75 0 0 1 .75.75v2.75h11.5v-2.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-13a.75.75 0 0 1-.75-.75v-3.5A.75.75 0 0 1 3.5 13Z" clip-rule="evenodd"/></svg>';
        const downloadLabel = document.createElement('span');
        setLocalizedText(downloadLabel, 'tm.download', 'Tải xuống');
        download.append(downloadLabel);
        const deleteForm = document.createElement('form');
        deleteForm.className = 'inline';
        deleteForm.method = 'post';
        deleteForm.addEventListener('submit', (deleteEvent) => {
            if (!window.confirm('Xóa file này?')) deleteEvent.preventDefault();
        });
        for (const [name, value] of Object.entries({
            csrf_token: csrfToken,
            action: 'delete_tm_csv',
            filename: file.name
        })) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            deleteForm.append(input);
        }
        const deleteButton = document.createElement('button');
        deleteButton.className = 'inline-flex items-center rounded-md px-2.5 py-1.5 font-semibold text-slate-500 transition hover:bg-red-50 hover:text-red-600';
        deleteButton.type = 'submit';
        setLocalizedText(deleteButton, 'workspace.delete', 'Xóa');
        deleteForm.append(deleteButton);
        actionsCell.append(download, deleteForm);
        row.append(nameCell, sizeCell, modifiedCell, actionsCell);
        fileList.prepend(row);
        emptyState.hidden = true;
        fileCount.textContent = String(Number(fileCount.textContent) + 1);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const files = selectedFiles();
        if (files.length === 0) {
            setSelectedFiles(files);
            return;
        }

        const button = submitButton;
        const csrfToken = form.elements.csrf_token.value;
        fileStatusList.replaceChildren();
        const statuses = files.map((file) => {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-3';
            const name = document.createElement('span');
            name.className = 'truncate';
            name.textContent = file.name;
            const itemStatus = document.createElement('span');
            itemStatus.className = 'shrink-0 text-slate-500';
            setLocalizedText(itemStatus, 'tm.waiting', 'Chờ tải lên');
            item.append(name, itemStatus);
            fileStatusList.append(item);
            return itemStatus;
        });
        progress.classList.remove('hidden');
        progress.hidden = false;
        setLocalizedText(progressLabel, 'tm.upload_preparing', 'Đang chuẩn bị tải lên...');
        progressCount.textContent = `0/${files.length}`;
        progressBar.style.width = '0%';
        status.textContent = '';
        if (button) button.disabled = true;
        fileInput.disabled = true;

        let uploaded = 0;
        const failures = [];
        for (let index = 0; index < files.length; index++) {
            const file = files[index];
            const itemStatus = statuses[index];
            setLocalizedText(progressLabel, 'tm.uploading_file', 'Đang tải file {current}/{total}: {name}', {
                current: index + 1,
                total: files.length,
                name: file.name
            });
            setLocalizedText(itemStatus, 'tm.uploading', 'Đang tải lên...');
            itemStatus.className = 'shrink-0 text-indigo-600';
            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('action', 'upload_tm_csv');
                formData.append('tm_csv', file, file.name);
                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json' }
                });
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    throw new Error('Máy chủ trả về phản hồi không phải JSON.');
                }
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Không thể tải lên file TM.');
                }
                appendUploadedFile(result.file, csrfToken);
                uploaded++;
                setLocalizedText(itemStatus, 'tm.uploaded', 'Đã tải lên');
                itemStatus.className = 'shrink-0 text-emerald-700';
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Không thể tải lên file TM.';
                failures.push(`${file.name}: ${message}`);
                setLocalizedText(itemStatus, 'tm.failed', 'Thất bại');
                itemStatus.className = 'shrink-0 text-red-600';
                itemStatus.title = message;
            }
            progressCount.textContent = `${index + 1}/${files.length}`;
            progressBar.style.width = `${((index + 1) / files.length) * 100}%`;
        }

        if (uploaded === files.length) {
            setLocalizedText(status, 'tm.upload_success_all', 'Đã tải lên thành công tất cả {count} file CSV. Danh sách kho TM đã được cập nhật.', { count: uploaded });
            status.className = 'mt-3 text-sm text-emerald-700';
        } else if (uploaded > 0) {
            setLocalizedText(status, 'tm.upload_success_partial', 'Đã tải lên {uploaded}/{total} file. Một số file chưa tải được.', { uploaded, total: files.length });
            status.className = 'mt-3 text-sm text-amber-700';
        } else {
            setLocalizedText(status, 'tm.upload_failed', 'Không thể tải lên các file đã chọn.');
            status.className = 'mt-3 text-sm text-red-700';
        }
        if (failures.length > 0) {
            status.title = failures.join('\n');
        } else {
            status.removeAttribute('title');
        }
        if (uploaded === files.length) {
            setLocalizedText(progressLabel, 'tm.upload_complete', 'Hoàn tất tải lên');
        } else {
            setLocalizedText(progressLabel, 'tm.upload_complete_partial', 'Hoàn tất: {uploaded}/{total} file thành công', {
                uploaded,
                total: files.length
            });
        }
        fileInput.disabled = false;
        form.reset();
        setSelectedFiles([]);
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/templates/tms-ai-chat.php'; ?>
</body>
</html>
