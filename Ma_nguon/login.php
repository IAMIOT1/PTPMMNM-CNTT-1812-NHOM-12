<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';

$conn = db();
$auth = new AuthManager($conn);
$error = '';

if ($auth->isLoggedIn()) {
    $currentUser = $auth->getCurrentUser();
    header(
        'Location: ' . (($currentUser['role'] ?? '') === 'admin' ? 'admin_users.php' : 'index.php'),
        true,
        303
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($auth->login($username, $password)) {
        $destination = ($_SESSION['user']['role'] ?? '') === 'admin'
            ? 'admin_users.php'
            : 'index.php';
        header('Location: ' . $destination, true, 303);
        exit;
    } else {
        $error = $auth->lastLoginError() === 'EMAIL_NOT_VERIFIED'
            ? 'Vui lòng xác thực email trước khi đăng nhập.'
            : 'Tên đăng nhập hoặc mật khẩu không chính xác!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="auth.login_title">Đăng nhập - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen font-sans">
    <div class="fixed right-4 top-4 z-10 flex items-center gap-2">
        <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
        <?php require __DIR__ . '/templates/language-switcher.php'; ?>
    </div>
    <div class="tms-auth-card max-w-md w-full bg-white p-8 rounded-2xl shadow-sm border border-gray-100">
        <div class="text-center mb-8">
            <div class="tms-auth-brand" aria-hidden="true">🌍</div>
            <h1 class="text-2xl font-bold text-indigo-600">Enterprise TMS</h1>
            <p class="text-sm text-gray-500 mt-1" data-i18n="auth.login_description">Đăng nhập để truy cập hệ thống quản lý dịch thuật</p>
        </div>

        <?php if (($_GET['registered'] ?? '') === '1'): ?>
            <div class="mb-6 bg-green-50 border-l-4 border-green-400 p-4 rounded-md" role="status">
                <p class="text-green-700 text-sm font-medium" data-i18n="auth.register_success">Đăng ký thành công. Vui lòng đăng nhập.</p>
            </div>
        <?php endif; ?>
        <?php if (in_array($_GET['verify'] ?? '', ['1', 'success'], true) ||
            in_array($_GET['verified'] ?? '', ['1', 'success'], true)): ?>
            <div class="mb-6 rounded-md border-l-4 border-green-400 bg-green-50 p-4" role="status"><p class="text-sm font-medium text-green-700">Email đã được xác thực. Bạn có thể đăng nhập.</p></div>
        <?php endif; ?>
        <?php if (($_GET['reset'] ?? '') === '1'): ?>
            <div class="mb-6 rounded-md border-l-4 border-green-400 bg-green-50 p-4" role="status">
                <p class="text-sm font-medium text-green-700">Mật khẩu đã được cập nhật. Vui lòng đăng nhập.</p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-md">
                <p class="text-red-700 text-sm font-medium"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" data-i18n="auth.username">Tên đăng nhập</label>
                <input type="text" name="username" autocomplete="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" data-i18n="auth.password">Mật khẩu</label>
                <input type="password" name="password" autocomplete="current-password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold text-sm shadow-sm transition-colors">
                <span data-i18n="auth.login">Đăng nhập hệ thống</span>
            </button>
            <div class="text-right">
                <a class="text-sm font-semibold text-indigo-600 hover:underline" href="forgot_password.php">Quên mật khẩu?</a>
            </div>
        </form>
        
        <div class="mt-6 text-center text-sm text-gray-500">
            <span data-i18n="auth.no_account">Chưa có tài khoản?</span> <a class="text-indigo-600 font-semibold hover:underline" href="register.php" data-i18n="auth.register_now">Đăng ký ngay</a>
        </div>
    </div>
    <script src="tms-i18n.js" defer></script>
</body>
</html>