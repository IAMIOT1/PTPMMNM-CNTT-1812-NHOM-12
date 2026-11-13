<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

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
    header('Content-Type: application/json');
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
        if (($_POST['action'] ?? '') === 'delete_tm_csv') {
            $repository->delete((string)($_POST['filename'] ?? ''));
            $success = 'Đã xóa file khỏi kho lưu trữ.';
        }
    } catch (Throwable $exception) {
        error_log('TM_REPOSITORY_DELETE_FAILED: ' . $exception->getMessage());
        if ($wantsJson) {
            $sendJson(['success' => false, 'message' => $exception->getMessage()], 500);
        }
        $error = 'Không thể xóa file TM.';
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
        <form id="tm-upload-form" action="api_upload_tm.php" method="post" enctype="multipart/form-data">
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
                        <td class="px-5 py-4 sm:px-6"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7l-5-5H4.5Zm7.25 1.81L15.19 7.25h-2.69a.75.75 0 0 1-.75-.75V3.81ZM6.75 11a.75.75 0 0 1 .75-.75h5a.75.75 0 0 1 0 1.5h-5a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5h5a.75.75 0 0 0 0-1.5h-5Z" clip-rule="evenodd"/></svg></span><button type="button" class="cursor-pointer truncate text-left font-semibold text-indigo-700 hover:underline focus:outline-none focus:ring-2 focus:ring-indigo-500" data-tm-file="<?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?>" data-i18n-ignore><?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?></button></div></td>
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
    <dialog id="tm-details-dialog" data-can-edit="<?= $canManage ? '1' : '0' ?>" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" class="m-auto max-h-[90vh] w-[min(96vw,72rem)] overflow-hidden rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/50">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
            <div class="min-w-0">
                <h2 id="tm-details-title" class="truncate text-lg font-semibold">Chi tiết kho TM</h2>
                <p id="tm-details-status" class="mt-1 text-sm text-slate-500" role="status" aria-live="polite"></p>
            </div>
            <button type="button" data-close-details class="grid h-9 w-9 shrink-0 place-items-center rounded-md text-slate-500 hover:bg-slate-100" aria-label="Đóng">
                <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4.22 4.22a.75.75 0 0 1 1.06 0L10 8.94l4.72-4.72a.75.75 0 1 1 1.06 1.06L11.06 10l4.72 4.72a.75.75 0 1 1-1.06 1.06L10 11.06l-4.72 4.72a.75.75 0 1 1-1.06-1.06L8.94 10 4.22 5.28a.75.75 0 0 1 0-1.06Z"/></svg>
            </button>
        </div>
        <div class="max-h-[72vh] overflow-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="w-24 px-4 py-3">Locale</th>
                        <th class="w-[34%] px-4 py-3">Source text</th>
                        <th class="w-[34%] px-4 py-3">Target text</th>
                        <?php if ($canManage): ?><th class="w-24 px-4 py-3 text-right">Thao tác</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tm-details-rows" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </dialog>
</main>
<script src="tms-i18n.js" defer></script>
<?php if ($canManage): ?>
<script>
(() => {
    const form = document.getElementById('tm-upload-form');
    const fileInput = document.getElementById('tm-csv-input');
    const dropzone = document.getElementById('tm-dropzone');
    const fileSelection = document.getElementById('tm-file-selection');
    const status = document.getElementById('tm-upload-status');
    const fileStatusList = document.getElementById('tm-upload-file-status');
    const progress = document.getElementById('tm-upload-progress');
    const progressLabel = document.getElementById('tm-upload-progress-label');
    const progressCount = document.getElementById('tm-upload-progress-count');
    const progressBar = document.getElementById('tm-upload-progress-bar');
    if (!form || !fileInput || !dropzone || !fileSelection || !status || !fileStatusList
        || !progress || !progressLabel || !progressCount || !progressBar) return;

    const submitButton = form.querySelector('button[type="submit"]');
    const maxBytes = 5_000_000;
    const selectedFiles = () => Array.from(fileInput.files || []);
    const showSelection = (files) => {
        const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
        fileSelection.textContent = files.length
            ? `${files.length} file đã chọn · ${(totalBytes / 1024 / 1024).toFixed(2)} MB`
            : 'Định dạng CSV · Tối đa 5 MB mỗi file · Có thể chọn nhiều file';
        if (submitButton) submitButton.disabled = files.length === 0;
    };
    const selectFiles = (source) => {
        const transfer = new DataTransfer();
        for (const file of Array.from(source || [])) {
            if (!(file instanceof File)) return;
            if (!file.name.toLowerCase().endsWith('.csv') || file.size === 0 || file.size > maxBytes) continue;
            transfer.items.add(file);
        }
        fileInput.files = transfer.files;
        showSelection(selectedFiles());
    };

    fileInput.addEventListener('change', () => selectFiles(fileInput.files));
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
    dropzone.addEventListener('drop', (event) => selectFiles(event.dataTransfer?.files));

    const appendFileRow = (file, csrfToken) => {
        const row = document.createElement('tr');
        row.className = 'transition hover:bg-slate-50/70';
        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-4 sm:px-6';
        const fileButton = document.createElement('button');
        fileButton.type = 'button';
        fileButton.className = 'cursor-pointer truncate text-left font-semibold text-indigo-700 hover:underline';
        fileButton.dataset.tmFile = file.name;
        fileButton.textContent = file.name;
        nameCell.append(fileButton);
        const sizeCell = document.createElement('td');
        sizeCell.className = 'whitespace-nowrap px-5 py-4 text-slate-600';
        sizeCell.textContent = `${Number(file.size).toLocaleString()} bytes`;
        const modifiedCell = document.createElement('td');
        modifiedCell.className = 'whitespace-nowrap px-5 py-4 text-slate-600';
        modifiedCell.textContent = new Date(Number(file.modified_at) * 1000).toLocaleString('vi-VN');
        const actionsCell = document.createElement('td');
        actionsCell.className = 'whitespace-nowrap px-5 py-4 text-right';
        const download = document.createElement('a');
        download.href = `?action=download&filename=${encodeURIComponent(file.name)}`;
        download.textContent = 'Tải xuống';
        const deleteForm = document.createElement('form');
        deleteForm.className = 'inline';
        deleteForm.method = 'post';
        deleteForm.addEventListener('submit', (event) => {
            if (!window.confirm('Xóa file này?')) event.preventDefault();
        });
        for (const [name, value] of Object.entries({ csrf_token: csrfToken, action: 'delete_tm_csv', filename: file.name })) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            deleteForm.append(input);
        }
        const deleteButton = document.createElement('button');
        deleteButton.type = 'submit';
        deleteButton.textContent = 'Xóa';
        deleteForm.append(deleteButton);
        actionsCell.append(download, deleteForm);
        row.append(nameCell, sizeCell, modifiedCell, actionsCell);
        document.getElementById('tm-files-list').prepend(row);
        document.getElementById('tm-files-empty').hidden = true;
        const count = document.getElementById('tm-file-count');
        count.textContent = String(Number(count.textContent) + 1);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const files = selectedFiles();
        if (!files.length) return;
        for (const file of files) {
            if (!(file instanceof File)) return;
        }

        const csrfToken = form.elements.csrf_token.value;
        fileStatusList.replaceChildren();
        status.textContent = '';
        progress.hidden = false;
        progress.classList.remove('hidden');
        progressBar.style.width = '0%';
        progressCount.textContent = `0/${files.length}`;
        if (submitButton) submitButton.disabled = true;
        fileInput.disabled = true;

        let completed = 0;
        let imported = 0;
        const failures = [];
        for (const [index, file] of files.entries()) {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-3';
            const name = document.createElement('span');
            name.className = 'truncate';
            name.textContent = file.name;
            const itemStatus = document.createElement('span');
            itemStatus.textContent = 'Đang tải lên';
            item.append(name, itemStatus);
            fileStatusList.append(item);
            progressLabel.textContent = `Đang tải file ${index + 1}/${files.length}: ${file.name}`;

            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('action', 'upload_tm_csv');
                formData.append('tm_csv', file, file.name);
                const response = await fetch('api_upload_tm.php', {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json' }
                });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch {
                    throw new Error(responseText.slice(0, 300) || 'Máy chủ trả về phản hồi không phải JSON.');
                }
                if (!response.ok || !result.success) throw new Error(result.message || 'Không thể tải lên file TM.');
                appendFileRow(result.file, csrfToken);
                imported += Number(result.imported || 0);
                itemStatus.textContent = `Đã nhập ${Number(result.imported || 0)} dòng`;
                itemStatus.className = 'shrink-0 text-emerald-700';
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Không thể tải lên file TM.';
                failures.push(`${file.name}: ${message}`);
                itemStatus.textContent = 'Thất bại';
                itemStatus.title = message;
                itemStatus.className = 'shrink-0 text-red-600';
            }
            completed++;
            progressCount.textContent = `${completed}/${files.length}`;
            progressBar.style.width = `${(completed / files.length) * 100}%`;
        }

        status.textContent = failures.length
            ? `${imported} dòng đã nhập; ${failures.length} file lỗi. ${failures.join(' | ')}`
            : `Tải lên thành công ${files.length} file, nhập ${imported} dòng.`;
        status.className = `mt-3 text-sm ${failures.length ? 'text-amber-700' : 'text-emerald-700'}`;
        progressLabel.textContent = 'Hoàn tất tải lên';
        fileInput.disabled = false;
        if (submitButton) submitButton.disabled = false;
        fileInput.value = '';
        showSelection([]);
        progress.hidden = true;
        progress.classList.add('hidden');
        progressBar.style.width = '0%';
        progressCount.textContent = '0/0';
        progressLabel.textContent = 'Đang tải lên...';
    });

    showSelection(selectedFiles());
})();
</script>
<?php endif; ?>
<script>
(() => {
    const fileList = document.getElementById('tm-files-list');
    const dialog = document.getElementById('tm-details-dialog');
    const title = document.getElementById('tm-details-title');
    const status = document.getElementById('tm-details-status');
    const rows = document.getElementById('tm-details-rows');
    if (!fileList || !dialog || !title || !status || !rows) return;

    const canEdit = dialog.dataset.canEdit === '1';
    const csrfToken = dialog.dataset.csrfToken;
    const appendCell = (row, value, className = '') => {
        const cell = document.createElement('td');
        cell.className = `px-4 py-3 align-top ${className}`;
        cell.textContent = value;
        row.append(cell);
        return cell;
    };
    const renderEntry = (entry) => {
        const row = document.createElement('tr');
        appendCell(row, entry.locale, 'font-medium text-slate-700');
        appendCell(row, entry.source_text, 'whitespace-pre-wrap break-words text-slate-600');
        const targetCell = document.createElement('td');
        targetCell.className = 'px-4 py-3 align-top';
        if (canEdit) {
            const target = document.createElement('textarea');
            target.className = 'w-full min-w-56 rounded-md border border-slate-300 px-2.5 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100';
            target.rows = 2;
            target.value = entry.target_text;
            target.dataset.targetText = '';
            targetCell.append(target);
        } else {
            targetCell.textContent = entry.target_text;
            targetCell.classList.add('whitespace-pre-wrap', 'break-words', 'text-slate-600');
        }
        row.append(targetCell);
        if (canEdit) {
            const actions = document.createElement('td');
            actions.className = 'px-4 py-3 text-right align-top';
            const save = document.createElement('button');
            save.type = 'button';
            save.className = 'rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50';
            save.textContent = 'Lưu';
            save.dataset.saveEntry = '';
            save.dataset.rowIndex = String(entry.row_index);
            actions.append(save);
            row.append(actions);
        }
        rows.append(row);
    };

    const openFileDetails = async (filename) => {
        title.textContent = filename;
        status.textContent = 'Đang tải dữ liệu...';
        rows.replaceChildren();
        if (!dialog.open) dialog.showModal();
        try {
            const response = await fetch(`api_get_tm_details.php?filename=${encodeURIComponent(filename)}`, {
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Không thể tải chi tiết file.');
            result.entries.forEach(renderEntry);
            status.textContent = `${result.entries.length} câu trong file`;
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Không thể tải chi tiết file.';
        }
    };

    fileList.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-tm-file]') : null;
        if (trigger) openFileDetails(trigger.dataset.tmFile);
    });
    dialog.querySelector('[data-close-details]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    rows.addEventListener('click', async (event) => {
        const save = event.target instanceof Element ? event.target.closest('[data-save-entry]') : null;
        if (!save) return;
        const row = save.closest('tr');
        const target = row.querySelector('[data-target-text]');
        if (!target || !canEdit) return;

        save.disabled = true;
        status.textContent = 'Đang lưu...';
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('filename', title.textContent);
        formData.append('row_index', save.dataset.rowIndex);
        formData.append('target_text', target.value);
        try {
            const response = await fetch('api_update_tm_entry.php', {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Không thể lưu bản dịch.');
            target.value = result.entry.target_text;
            status.textContent = result.message;
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Không thể lưu bản dịch.';
        } finally {
            save.disabled = false;
        }
    });
})();
</script>
<?php require __DIR__ . '/templates/tms-ai-chat.php'; ?>
</body>
</html>
