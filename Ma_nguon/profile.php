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
$userId = (int)$currentUser['id'];
$csrfToken = Csrf::token();
$notificationManager = new NotificationManager($connection);
$notifications = $notificationManager->recentForUser($userId);
$unreadNotificationCount = $notificationManager->unreadCount($userId);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
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
        COUNT(*) FILTER (WHERE status IN ('draft', 'translated')) AS translated_count,
        COUNT(*) FILTER (WHERE status = 'approved') AS approved_count,
        COUNT(*) FILTER (WHERE status IN ('needs_review', 'rejected', 'pending')) AS review_count
     FROM translations
     WHERE user_id = $1",
    [$userId]
);
$stats = pg_fetch_assoc($statsRes);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trang cá nhân - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<main class="tms-shell max-w-5xl mx-auto px-4 py-8">
    <header class="tms-topbar flex flex-col md:flex-row justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100 gap-4">
        <div>
            <a href="index.php" class="text-2xl font-bold text-indigo-600">🌍 Enterprise TMS</a>
            <p class="text-sm text-gray-500 mt-1">Hệ thống quản lý dịch thuật</p>
        </div>
        <?php require __DIR__ . '/templates/tms-header.php'; ?>
    </header>

    <?php if ($message !== ''): ?>
        <div class="mb-6 rounded bg-green-50 border-l-4 border-green-400 p-4 text-green-800"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="mb-6 rounded bg-red-50 border-l-4 border-red-400 p-4 text-red-800"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
        <h1 class="text-xl font-bold mb-4">Thông tin cá nhân</h1>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">User ID</dt><dd class="font-semibold"><?= (int)$user['id'] ?></dd></div>
            <div><dt class="text-sm text-gray-500">Username</dt><dd class="font-semibold"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt class="text-sm text-gray-500">Email</dt><dd class="font-semibold"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt class="text-sm text-gray-500">Ngày tạo</dt><dd class="font-semibold"><?= htmlspecialchars($user['created_at'], ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>
    </section>

    <section class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
        <h2 class="text-xl font-bold mb-4">Thống kê dịch thuật</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg bg-blue-50 p-4"><p class="text-sm text-blue-700">Đã dịch</p><p class="text-2xl font-bold"><?= (int)$stats['translated_count'] ?></p></div>
            <div class="rounded-lg bg-green-50 p-4"><p class="text-sm text-green-700">Đã duyệt</p><p class="text-2xl font-bold"><?= (int)$stats['approved_count'] ?></p></div>
            <div class="rounded-lg bg-amber-50 p-4"><p class="text-sm text-amber-700">Cần kiểm tra / Từ chối</p><p class="text-2xl font-bold"><?= (int)$stats['review_count'] ?></p></div>
        </div>
    </section>

    <section class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
        <h2 class="text-xl font-bold mb-4">Đổi mật khẩu</h2>
        <form method="post" class="grid gap-4 md:grid-cols-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div><label class="block text-sm font-medium mb-1" for="current_password">Mật khẩu hiện tại</label><input class="w-full rounded border px-3 py-2" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
            <div><label class="block text-sm font-medium mb-1" for="new_password">Mật khẩu mới</label><input class="w-full rounded border px-3 py-2" type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required></div>
            <div><label class="block text-sm font-medium mb-1" for="confirm_password">Xác nhận mật khẩu mới</label><input class="w-full rounded border px-3 py-2" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
            <div class="md:col-span-3"><button class="rounded bg-indigo-600 px-5 py-2 font-semibold text-white hover:bg-indigo-700" type="submit">Cập nhật mật khẩu</button></div>
        </form>
    </section>
</main>
</body>
</html>
