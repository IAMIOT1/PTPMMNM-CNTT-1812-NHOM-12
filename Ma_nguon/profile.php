<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/NotificationManager.php';

if (!isset($_SESSION['user_id'])) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/profile.php'));
}

$connection = db();
$auth = new AuthManager($connection);
if (!$auth->isLoggedIn()) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/profile.php'));
}

$currentUser = $auth->getCurrentUser();
if ($currentUser === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/profile.php'));
}
$userId = (int)$currentUser['id'];
$csrfToken = Csrf::token();
$notificationManager = new NotificationManager($connection);
$notifications = $notificationManager->recentForUser($userId);
$unreadNotificationCount = $notificationManager->unreadCount($userId);
$message = '';
$error = '';
if (($_GET['status'] ?? '') === 'support_submitted') {
    $message = 'Đã gửi yêu cầu hỗ trợ. Quản trị viên sẽ xem xét sớm.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    if (($_POST['action'] ?? '') === 'save_notification_preferences') {
        queryParams(
            $connection,
            'INSERT INTO user_notification_preferences
                (user_id, notify_new_projects, notify_comments, updated_at)
             VALUES ($1, $2, $3, CURRENT_TIMESTAMP)
             ON CONFLICT (user_id) DO UPDATE
             SET notify_new_projects = EXCLUDED.notify_new_projects,
                 notify_comments = EXCLUDED.notify_comments,
                 updated_at = CURRENT_TIMESTAMP',
            [
                $userId,
                isset($_POST['notify_new_projects']) ? 'true' : 'false',
                isset($_POST['notify_comments']) ? 'true' : 'false',
            ]
        );
        $message = 'NOTIFICATION_PREFERENCES_SAVED';
    } elseif (($_POST['action'] ?? '') === 'submit_support_ticket') {
        $title = trim((string)($_POST['ticket_title'] ?? ''));
        $ticketMessage = trim((string)($_POST['ticket_message'] ?? ''));
        $titleLength = preg_match_all('/./us', $title);
        $messageLength = preg_match_all('/./us', $ticketMessage);
        $supportImage = $_FILES['support_image'] ?? null;
        $imagePath = null;
        if ($titleLength === false || $titleLength < 3 || $titleLength > 200) {
            $error = 'Tiêu đề yêu cầu hỗ trợ cần dài từ 3 đến 200 ký tự.';
        } elseif ($messageLength === false || $messageLength < 10 || $messageLength > 10000) {
            $error = 'Nội dung yêu cầu hỗ trợ cần dài từ 10 đến 10000 ký tự.';
        } else {
            try {
                if ($supportImage !== null) {
                    if (!is_array($supportImage) || !is_int($supportImage['error'] ?? null)) {
                        throw new InvalidArgumentException('Dữ liệu ảnh tải lên không hợp lệ.');
                    }
                    if ($supportImage['error'] !== UPLOAD_ERR_NO_FILE) {
                        if ($supportImage['error'] !== UPLOAD_ERR_OK ||
                            !is_string($supportImage['tmp_name'] ?? null) ||
                            !is_uploaded_file($supportImage['tmp_name'])) {
                            throw new InvalidArgumentException(
                                in_array($supportImage['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                                    ? 'Ảnh vượt quá dung lượng cho phép 5 MB.'
                                    : 'Không thể nhận ảnh tải lên. Vui lòng thử lại.'
                            );
                        }
                        $imageSize = filesize($supportImage['tmp_name']);
                        if ($imageSize === false || $imageSize < 1 || $imageSize > 5 * 1024 * 1024) {
                            throw new InvalidArgumentException('Ảnh phải có dung lượng tối đa 5 MB.');
                        }
                        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $fileInfo->file($supportImage['tmp_name']);
                        $allowedImageTypes = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp',
                        ];
                        if (!is_string($mimeType) || !isset($allowedImageTypes[$mimeType])) {
                            throw new InvalidArgumentException('Chỉ chấp nhận ảnh JPEG, PNG, GIF hoặc WebP.');
                        }

                        $uploadDirectory = __DIR__ . '/uploads/support';
                        if (!is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0750, true) &&
                            !is_dir($uploadDirectory)) {
                            throw new RuntimeException('Không thể tạo thư mục lưu ảnh hỗ trợ.');
                        }
                        if (!is_writable($uploadDirectory)) {
                            throw new RuntimeException('Thư mục lưu ảnh hỗ trợ không có quyền ghi.');
                        }
                        $filename = bin2hex(random_bytes(24)) . '.' . $allowedImageTypes[$mimeType];
                        $imagePath = 'uploads/support/' . $filename;
                        if (!move_uploaded_file($supportImage['tmp_name'], __DIR__ . '/' . $imagePath)) {
                            throw new RuntimeException('Không thể lưu ảnh hỗ trợ đã tải lên.');
                        }
                    }
                }

                queryParams(
                    $connection,
                    'INSERT INTO support_tickets (user_id, title, message, image_path)
                     VALUES ($1, $2, $3, $4)',
                    [$userId, $title, $ticketMessage, $imagePath]
                );
                header('Location: profile.php?status=support_submitted#support-requests', true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                if ($imagePath !== null && is_file(__DIR__ . '/' . $imagePath)) {
                    unlink(__DIR__ . '/' . $imagePath);
                }
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($imagePath !== null && is_file(__DIR__ . '/' . $imagePath)) {
                    unlink(__DIR__ . '/' . $imagePath);
                }
                error_log('SUPPORT_TICKET_SUBMISSION_FAILED: ' . $exception->getMessage());
                $error = 'Không thể gửi yêu cầu hỗ trợ lúc này. Vui lòng thử lại.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $passwordRes = queryParams(
            $connection,
            'SELECT password_hash FROM users WHERE id = $1',
            [$userId]
        );
        $passwordRow = pg_fetch_assoc($passwordRes);
        if (!$passwordRow || !password_verify($currentPassword, $passwordRow['password_hash'])) {
            $error = 'Mật khẩu hiện tại không chính xác.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Mật khẩu mới cần có ít nhất 8 ký tự.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Mật khẩu mới xác nhận không khớp.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            if ($passwordHash === false) {
                throw new RuntimeException('PASSWORD_HASH_FAILED');
            }
            queryParams(
                $connection,
                'UPDATE users SET password_hash = $1 WHERE id = $2',
                [$passwordHash, $userId]
            );
            session_regenerate_id(true);
            $message = 'Đổi mật khẩu thành công.';
        }
    }
}

$supportTicketsResult = queryParams(
    $connection,
    'SELECT id, title, message, status, image_path, created_at
     FROM support_tickets
     WHERE user_id = $1
     ORDER BY created_at DESC, id DESC',
    [$userId]
);
$supportTickets = pg_fetch_all($supportTicketsResult) ?: [];

$userRes = queryParams(
    $connection,
    'SELECT id, username, email, created_at FROM users WHERE id = $1',
    [$userId]
);
$user = pg_fetch_assoc($userRes);
if (!$user) {
    $auth->logout();
    header('Location: index.php?action=login', true, 303);
    exit;
}
$statsRes = queryParams(
    $connection,
    "SELECT
        COUNT(*) FILTER (
            WHERE NULLIF(BTRIM(translated_text), '') IS NOT NULL
        ) AS contributed_count,
        COUNT(*) FILTER (
            WHERE status = 'approved' AND NULLIF(BTRIM(translated_text), '') IS NOT NULL
        ) AS approved_count
     FROM translations
     WHERE user_id = $1",
    [$userId]
);
$stats = pg_fetch_assoc($statsRes);
$projectStatsRes = queryParams(
    $connection,
    'SELECT COUNT(DISTINCT p.id) AS participated_count,
            COUNT(DISTINCT p.id) FILTER (
                WHERE EXISTS (
                    SELECT 1 FROM release_snapshots rs WHERE rs.project_id = p.id
                )
            ) AS released_count
     FROM projects p
     LEFT JOIN project_members pm ON pm.project_id = p.id
     WHERE p.created_by = $1 OR pm.user_id = $1',
    [$userId]
);
$projectStats = pg_fetch_assoc($projectStatsRes);
$preferencesRes = queryParams(
    $connection,
    'SELECT notify_new_projects, notify_comments
     FROM user_notification_preferences
     WHERE user_id = $1',
    [$userId]
);
$notificationPreferences = pg_fetch_assoc($preferencesRes) ?: [
    'notify_new_projects' => 't',
    'notify_comments' => 't',
];
$activityRes = queryParams(
    $connection,
    "SELECT activity.*
     FROM (
         SELECT h.created_at,
                'translation'::text AS activity_type,
                p.name AS project_name,
                s.key_path,
                s.source_text,
                h.old_text,
                h.new_text,
                NULL::text AS action_type,
                NULL::text AS details
         FROM translation_history h
         JOIN translations t ON t.id = h.translation_id
         JOIN strings s ON s.id = t.string_id
         JOIN source_versions sv ON sv.id = s.source_version_id
         JOIN projects p ON p.id = sv.project_id
         WHERE h.changed_by_user_id = $1
         UNION ALL
         SELECT a.created_at,
                'audit'::text AS activity_type,
                COALESCE(p.name, 'TMS') AS project_name,
                s.key_path,
                s.source_text,
                NULL::text AS old_text,
                NULL::text AS new_text,
                a.action_type,
                a.details
         FROM audit_logs a
         LEFT JOIN projects p ON p.id = a.project_id
         LEFT JOIN strings s ON s.id = a.string_id
         WHERE a.actor_user_id = $1
     ) AS activity
     ORDER BY activity.created_at DESC
     LIMIT 12",
    [$userId]
);
$recentActivity = pg_fetch_all($activityRes) ?: [];
$messageTranslationKey = match ($message) {
    'Đổi mật khẩu thành công.' => 'profile.password_changed',
    'NOTIFICATION_PREFERENCES_SAVED' => 'profile.preferences_saved',
    default => null,
};
$errorTranslationKey = match ($error) {
    'Mật khẩu hiện tại không chính xác.' => 'profile.password_error_current',
    'Mật khẩu mới cần có ít nhất 8 ký tự.' => 'profile.password_error_length',
    'Mật khẩu mới xác nhận không khớp.' => 'profile.password_error_confirmation',
    default => null,
};
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="profile.breadcrumb">Trang cá nhân - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<main class="tms-shell mx-auto max-w-6xl px-4 py-8">
    <header class="tms-topbar mb-8 flex flex-col gap-5 rounded-xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div>
            <nav aria-label="Breadcrumb" class="mb-2 flex items-center gap-2 text-xs font-medium text-slate-400">
                <a href="index.php" class="transition hover:text-indigo-600">Workspace</a>
                <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L10.94 10 7.23 6.29a.75.75 0 1 1 1.06-1.06l4.24 4.24a.75.75 0 0 1 0 1.06l-4.24 4.24a.75.75 0 0 1-1.08 0Z" clip-rule="evenodd"/></svg>
                <span class="text-slate-600">Trang cá nhân</span>
            </nav>
            <a href="index.php" class="text-lg font-bold tracking-tight text-indigo-600">🌍 Enterprise TMS</a>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <?php require __DIR__ . '/templates/tms-header.php'; ?>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-100 font-bold">✓</span><span <?= $messageTranslationKey !== null ? 'data-i18n="' . htmlspecialchars($messageTranslationKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($message === 'NOTIFICATION_PREFERENCES_SAVED' ? 'Đã lưu cài đặt thông báo.' : $message, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-red-100 font-bold">!</span><span <?= $errorTranslationKey !== null ? 'data-i18n="' . htmlspecialchars($errorTranslationKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>

    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900" data-i18n="profile.title">Cài đặt tài khoản</h1>
        <p class="mt-1 text-sm text-slate-500" data-i18n="profile.description">Quản lý thông tin cá nhân, theo dõi hoạt động và bảo vệ tài khoản.</p>
    </div>

    <section id="account-settings" class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-indigo-50/80 via-white to-violet-50/60 px-5 py-6 sm:px-7">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <span class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-2xl font-bold text-white shadow-md shadow-indigo-200"><?= htmlspecialchars(strtoupper(substr((string)$user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600" data-i18n="profile.identity">Hồ sơ cá nhân</p>
                    <h2 class="mt-1 truncate text-xl font-bold text-slate-900"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-1 break-all text-sm text-slate-600"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span class="w-fit rounded-lg border border-slate-200 bg-white/80 px-3 py-2 text-xs font-semibold text-slate-500">ID <?= (int)$user['id'] ?></span>
            </div>
        </div>
        <dl class="grid divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0">
            <div class="px-5 py-4 sm:border-r sm:border-slate-100 sm:px-7">
                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400" data-i18n="profile.username">Tên người dùng</dt>
                <dd class="mt-1 font-semibold text-slate-800"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
            <div class="px-5 py-4 sm:px-7">
                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400" data-i18n="profile.joined">Ngày tham gia</dt>
                <dd class="mt-1 font-semibold text-slate-800"><?= htmlspecialchars(date('d/m/Y', strtotime((string)$user['created_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
        </dl>
    </section>

    <section class="mb-6" aria-labelledby="profile-stats-heading">
        <div class="mb-4">
            <h2 id="profile-stats-heading" class="text-lg font-bold text-slate-900" data-i18n="profile.activity_heading">Hiệu suất dịch thuật</h2>
            <p class="mt-1 text-sm text-slate-500" data-i18n="profile.activity_description">Tổng hợp đóng góp của bạn trên các dự án.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="grid h-10 w-10 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M4.25 3A1.25 1.25 0 0 0 3 4.25v11.5A1.25 1.25 0 0 0 4.25 17h11.5A1.25 1.25 0 0 0 17 15.75V6l-3-3H4.25Zm8.5 1.5 2.75 2.75h-2.75V4.5ZM6 10h8v1.5H6V10Zm0 3h8v1.5H6V13Z"/></svg></span>
                <p class="mt-4 text-sm font-medium text-slate-500" data-i18n="profile.contributed">Mục dịch đã đóng góp</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= (int)$stats['contributed_count'] ?></p>
                <p class="mt-1 text-xs text-slate-400" data-i18n="profile.contributed_hint">Bản dịch có nội dung do bạn lưu</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="grid h-10 w-10 place-items-center rounded-lg bg-emerald-50 text-emerald-600"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1.75a.75.75 0 0 1 .39.11l6 3.5a.75.75 0 0 1 .36.65v3.74c0 3.8-2.55 6.47-6.4 8.44a.75.75 0 0 1-.7 0c-3.85-1.97-6.4-4.64-6.4-8.44V6.01a.75.75 0 0 1 .36-.65l6-3.5a.75.75 0 0 1 .39-.11Zm3.03 6.72a.75.75 0 0 0-1.06-1.06L9 10.38 8.03 9.4a.75.75 0 1 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.06 0l3.5-3.5Z" clip-rule="evenodd"/></svg></span>
                <p class="mt-4 text-sm font-medium text-slate-500" data-i18n="profile.approved">Bản dịch được duyệt</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= (int)$stats['approved_count'] ?></p>
                <p class="mt-1 text-xs text-slate-400" data-i18n="profile.approved_hint">Đã được Trưởng nhóm/Reviewer duyệt</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="grid h-10 w-10 place-items-center rounded-lg bg-violet-50 text-violet-600"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M7 3.25A2.75 2.75 0 0 0 4.25 6v.5a2.75 2.75 0 0 0 5.5 0V6A2.75 2.75 0 0 0 7 3.25ZM3 14.5A3.5 3.5 0 0 1 6.5 11h1a3.5 3.5 0 0 1 3.5 3.5V17H3v-2.5Zm9.25-8.25A2.25 2.25 0 1 1 16.75 6a2.25 2.25 0 0 1-4.5.25ZM12 12h1.5a3.5 3.5 0 0 1 3.5 3.5V17h-4v-2.5c0-.9-.22-1.75-.61-2.5H12Z"/></svg></span>
                <p class="mt-4 text-sm font-medium text-slate-500" data-i18n="profile.projects">Dự án tham gia</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= (int)$projectStats['participated_count'] ?></p>
                <p class="mt-1 text-xs text-slate-400" data-i18n="profile.projects_hint">Có tên trong thành viên hoặc là người tạo</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="grid h-10 w-10 place-items-center rounded-lg bg-amber-50 text-amber-600"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .53.22l6.25 6.25a.75.75 0 0 1 0 1.06l-6.25 6.25a.75.75 0 0 1-1.06 0l-6.25-6.25a.75.75 0 0 1 0-1.06l6.25-6.25A.75.75 0 0 1 10 2Zm2.28 6.97a.75.75 0 1 0-1.06-1.06L9.25 9.88l-.47-.47a.75.75 0 0 0-1.06 1.06l1 1a.75.75 0 0 0 1.06 0l2.5-2.5Z" clip-rule="evenodd"/></svg></span>
                <p class="mt-4 text-sm font-medium text-slate-500" data-i18n="profile.released">Dự án đã phát hành</p>
                <p class="mt-1 text-2xl font-bold tracking-tight text-slate-900"><?= (int)$projectStats['released_count'] ?></p>
                <p class="mt-1 text-xs text-slate-400" data-i18n="profile.released_hint">Có ít nhất một bản dịch được xuất bản</p>
            </article>
        </div>
    </section>

    <section id="recent-activity" class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-activity-heading">
        <div class="sticky top-0 z-10 border-b border-slate-100 bg-white/95 px-5 py-3 backdrop-blur sm:px-6">
            <h2 id="recent-activity-heading" class="text-lg font-bold text-slate-900" data-i18n="profile.recent_activity">Nhật ký hoạt động gần đây</h2>
            <p class="mt-0.5 text-xs text-slate-500" data-i18n="profile.recent_activity_hint">Các bản dịch và thao tác gần đây của bạn.</p>
        </div>
        <?php if ($recentActivity): ?>
            <ol class="max-h-[450px] divide-y divide-slate-100 overflow-y-auto">
                <?php foreach ($recentActivity as $activity): ?>
                    <?php
                    $isTranslationActivity = $activity['activity_type'] === 'translation';
                    $activityLabel = $isTranslationActivity
                        ? ($activity['old_text'] === null ? 'profile.translation_created' : 'profile.translation_updated')
                        : 'profile.project_activity';
                    ?>
                    <li class="flex gap-2.5 px-4 py-2.5 sm:gap-3 sm:px-5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg <?= $isTranslationActivity ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-600' ?>">
                            <?php if ($isTranslationActivity): ?>
                                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M4.25 3A1.25 1.25 0 0 0 3 4.25v11.5A1.25 1.25 0 0 0 4.25 17h11.5A1.25 1.25 0 0 0 17 15.75V6l-3-3H4.25Zm8.5 1.5 2.75 2.75h-2.75V4.5ZM6 10h8v1.5H6V10Zm0 3h8v1.5H6V13Z"/></svg>
                            <?php else: ?>
                                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.25h14M4.5 16V7.5L10 4l5.5 3.5V16M7 9h6m-6 3h6"/></svg>
                            <?php endif; ?>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col justify-between gap-1 sm:flex-row sm:items-start">
                                <p class="text-sm font-semibold text-slate-800" data-i18n="<?= $activityLabel ?>"><?= $isTranslationActivity ? ($activity['old_text'] === null ? 'Đã tạo bản dịch' : 'Đã cập nhật bản dịch') : 'Thao tác trong dự án' ?></p>
                                <time class="shrink-0 text-[11px] text-slate-400"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] font-medium text-indigo-700"><?= htmlspecialchars((string)$activity['project_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if ($isTranslationActivity): ?>
                                <dl class="mt-1.5 grid gap-x-3 gap-y-0.5 text-xs sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                                    <div class="truncate text-slate-500"><dt class="inline font-semibold" data-i18n="activity.key">Khóa</dt><dd class="ml-1 inline"><?= htmlspecialchars((string)$activity['key_path'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <?php if (is_string($activity['source_text'] ?? null) && $activity['source_text'] !== ''): ?>
                                        <div class="truncate text-slate-500"><dt class="inline font-medium" data-i18n="activity.source">Văn bản gốc</dt><dd class="ml-1 inline text-slate-600"><?= htmlspecialchars((string)$activity['source_text'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <?php endif; ?>
                                    <?php if (is_string($activity['new_text'] ?? null) && $activity['new_text'] !== ''): ?>
                                        <div class="truncate text-slate-500 sm:col-span-2"><dt class="inline font-medium" data-i18n="activity.translation_text">Bản dịch</dt><dd class="ml-1 inline text-slate-700"><?= htmlspecialchars((string)$activity['new_text'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <?php endif; ?>
                                </dl>
                            <?php elseif (is_string($activity['details'] ?? null) && $activity['details'] !== ''): ?>
                                <p class="mt-1 truncate text-xs text-slate-600"><?= htmlspecialchars((string)$activity['details'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <div class="px-5 py-10 text-center sm:px-6">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-slate-100 text-slate-400"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="7.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M10 5.75v4.5l2.75 1.5"/></svg></span>
                <p class="mt-3 text-sm text-slate-500" data-i18n="profile.no_activity">Chưa có hoạt động gần đây.</p>
            </div>
        <?php endif; ?>
    </section>

    <section id="support-requests" class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="support-requests-heading">
        <div class="mb-5">
            <h2 id="support-requests-heading" class="text-lg font-bold text-slate-900">Hỗ trợ và báo cáo sự cố</h2>
            <p class="mt-1 text-sm text-slate-500">Gửi vấn đề tới quản trị viên và theo dõi trạng thái xử lý.</p>
        </div>
        <form method="post" enctype="multipart/form-data" class="mb-7 grid gap-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="submit_support_ticket">
            <label class="text-sm font-semibold text-slate-700">Tiêu đề
                <input type="text" name="ticket_title" required minlength="3" maxlength="200"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-slate-700">Nội dung
                <textarea name="ticket_message" required minlength="10" maxlength="10000" rows="4"
                          class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
            </label>
            <label class="text-sm font-semibold text-slate-700">Ảnh chụp màn hình (tùy chọn, tối đa 5 MB)
                <input type="file" name="support_image" accept="image/*"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </label>
            <button type="submit" class="w-fit rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Gửi yêu cầu hỗ trợ</button>
        </form>

        <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-600">Lịch sử yêu cầu</h3>
        <?php if ($supportTickets): ?>
            <div class="max-h-[400px] overflow-y-auto rounded-lg border border-slate-200">
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($supportTickets as $ticket): ?>
                        <li class="p-3 sm:p-3.5">
                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                <p class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars($ticket['title'], ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold <?= $ticket['status'] === 'resolved' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' ?>">
                                    <?= $ticket['status'] === 'resolved' ? 'Đã giải quyết' : 'Đang chờ xử lý' ?>
                                </span>
                            </div>
                            <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-5 text-slate-600"><?= htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if (is_string($ticket['image_path'] ?? null) && $ticket['image_path'] !== ''): ?>
                                <a class="mt-2 inline-block" href="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                    <img class="max-h-32 max-w-full rounded-md border border-slate-200 object-contain" src="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Ảnh đính kèm yêu cầu hỗ trợ">
                                </a>
                            <?php endif; ?>
                            <time class="mt-1.5 block text-[11px] text-slate-400"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ticket['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Bạn chưa gửi yêu cầu hỗ trợ nào.</p>
        <?php endif; ?>
    </section>

    <section id="notification-settings" class="mb-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="notification-settings-heading">
        <div class="mb-5">
            <h2 id="notification-settings-heading" class="text-lg font-bold text-slate-900" data-i18n="profile.notifications_heading">Cài đặt thông báo</h2>
            <p class="mt-1 text-sm text-slate-500" data-i18n="profile.notifications_description">Chọn những cập nhật bạn muốn nhận trong trung tâm thông báo.</p>
        </div>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_notification_preferences">
            <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-slate-200 p-4 transition hover:bg-slate-50">
                <span>
                    <span class="block text-sm font-semibold text-slate-800" data-i18n="profile.notify_projects">Lời mời tham gia dự án</span>
                    <span class="mt-1 block text-xs text-slate-500" data-i18n="profile.notify_projects_hint">Khi bạn được thêm vào dự án hoặc được cập nhật vai trò.</span>
                </span>
                <input class="tms-preference-toggle" type="checkbox" name="notify_new_projects" value="1" <?= $notificationPreferences['notify_new_projects'] === 't' ? 'checked' : '' ?>>
            </label>
            <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-slate-200 p-4 transition hover:bg-slate-50">
                <span>
                    <span class="block text-sm font-semibold text-slate-800" data-i18n="profile.notify_comments">Nhận xét và đánh giá bản dịch</span>
                    <span class="mt-1 block text-xs text-slate-500" data-i18n="profile.notify_comments_hint">Nhận xét mới hoặc phản hồi về trạng thái bản dịch của bạn.</span>
                </span>
                <input class="tms-preference-toggle" type="checkbox" name="notify_comments" value="1" <?= $notificationPreferences['notify_comments'] === 't' ? 'checked' : '' ?>>
            </label>
            <button class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200" type="submit"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.75A1.75 1.75 0 0 1 4.75 3h10.5A1.75 1.75 0 0 1 17 4.75v10.5A1.75 1.75 0 0 1 15.25 17h-10.5A1.75 1.75 0 0 1 3 15.25V4.75Zm10.78 3.28a.75.75 0 0 0-1.06-1.06L9 10.69 7.28 8.97a.75.75 0 1 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.06 0l4.25-4.25Z" clip-rule="evenodd"/></svg><span data-i18n="profile.save_preferences">Lưu cài đặt thông báo</span></button>
        </form>
    </section>

    <section id="change-password" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="password-heading">
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.25" y="8.25" width="13.5" height="9" rx="1.75"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.25 8.25V5.75a3.75 3.75 0 0 1 7.5 0v2.5m-3.75 3.25v2.5"/></svg></span>
                <div>
                    <h2 id="password-heading" class="text-lg font-bold text-slate-900" data-i18n="profile.password_heading">Bảo mật tài khoản</h2>
                    <p class="mt-1 text-sm text-slate-500" data-i18n="profile.password_description">Đổi mật khẩu định kỳ để bảo vệ tài khoản của bạn.</p>
                </div>
            </div>
            <button id="password-toggle" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 transition hover:border-indigo-300 hover:bg-indigo-100 focus:outline-none focus:ring-4 focus:ring-indigo-100" type="button" aria-expanded="false" aria-controls="password-panel">
                <span id="password-toggle-label" data-i18n="profile.password_toggle">Đổi mật khẩu</span>
                <svg id="password-toggle-icon" aria-hidden="true" class="h-4 w-4 transition-transform duration-200" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <div id="password-panel" class="tms-password-panel" aria-hidden="true" inert>
            <div class="tms-password-panel-inner">
                <form id="change-password-form" method="post" class="grid gap-4 border-t border-slate-100 px-5 py-5 sm:grid-cols-2 sm:px-6">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700" for="current_password" data-i18n="profile.current_password">Mật khẩu hiện tại</label>
                        <input class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700" for="new_password" data-i18n="profile.new_password">Mật khẩu mới</label>
                        <input class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
                        <p class="mt-1.5 text-xs text-slate-500" data-i18n="profile.password_hint">Sử dụng ít nhất 8 ký tự.</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-slate-700" for="confirm_password" data-i18n="profile.confirm_password">Xác nhận mật khẩu mới</label>
                        <input class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="flex items-end">
                        <button class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200" type="submit"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.75A1.75 1.75 0 0 1 4.75 3h10.5A1.75 1.75 0 0 1 17 4.75v10.5A1.75 1.75 0 0 1 15.25 17h-10.5A1.75 1.75 0 0 1 3 15.25V4.75Zm10.78 3.28a.75.75 0 0 0-1.06-1.06L9 10.69 7.28 8.97a.75.75 0 1 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.06 0l4.25-4.25Z" clip-rule="evenodd"/></svg><span data-i18n="profile.save_password">Cập nhật mật khẩu</span></button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<script>
(() => {
    const section = document.getElementById('change-password');
    const toggle = document.getElementById('password-toggle');
    const toggleLabel = document.getElementById('password-toggle-label');
    const toggleIcon = document.getElementById('password-toggle-icon');
    const panel = document.getElementById('password-panel');
    const form = document.getElementById('change-password-form');
    const password = document.getElementById('new_password');
    const confirmation = document.getElementById('confirm_password');
    if (!section || !toggle || !toggleLabel || !toggleIcon || !panel || !form || !password || !confirmation) return;

    const setOpen = (open) => {
        section.classList.toggle('is-password-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
        const language = document.documentElement.dataset.language || 'vi';
        const labels = {
            vi: { open: 'Thu gọn', closed: 'Đổi mật khẩu' },
            en: { open: 'Close', closed: 'Change password' }
        };
        toggleLabel.textContent = open ? labels[language].open : labels[language].closed;
        toggleIcon.classList.toggle('rotate-180', open);
    };
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        setOpen(open);
        if (open) {
            panel.querySelector('input[type="password"]')?.focus({ preventScroll: true });
        }
    });
    document.addEventListener('tms:language-changed', (event) => {
        const dictionary = event.detail?.dictionary;
        if (dictionary) {
            toggleLabel.textContent = toggle.getAttribute('aria-expanded') === 'true'
                ? dictionary['profile.password_collapse']
                : dictionary['profile.password_toggle'];
        }
    });
    if (window.location.hash === '#change-password') setOpen(true);

    const validate = () => confirmation.setCustomValidity(
        confirmation.value !== '' && confirmation.value !== password.value
            ? 'Mật khẩu xác nhận không khớp.'
            : ''
    );
    password.addEventListener('input', validate);
    confirmation.addEventListener('input', validate);
})();
</script>
</body>
</html>
