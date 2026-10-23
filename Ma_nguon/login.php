<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';

$conn = db();
$auth = new AuthManager($conn);
$error = '';

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$next = AuthManager::safeReturnPath((string)($_GET['next'] ?? '/index.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $next = AuthManager::safeReturnPath((string)($_POST['next'] ?? '/index.php'));

    if ($auth->login($username, $password)) {
        header('Location: ' . $next, true, 303);
        exit;
    } else {
        $error = 'Tên đăng nhập hoặc mật khẩu không chính xác!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen font-sans">
    <div class="tms-auth-card max-w-md w-full bg-white p-8 rounded-2xl shadow-sm border border-gray-100">
        <div class="text-center mb-8">
            <div class="tms-auth-brand" aria-hidden="true">🌍</div>
            <h1 class="text-2xl font-bold text-indigo-600">Enterprise TMS</h1>
            <p class="text-sm text-gray-500 mt-1">Đăng nhập để truy cập hệ thống quản lý dịch thuật</p>
        </div>

        <?php if (($_GET['registered'] ?? '') === '1'): ?>
            <div class="mb-6 bg-green-50 border-l-4 border-green-400 p-4 rounded-md" role="status">
                <p class="text-green-700 text-sm font-medium">Đăng ký thành công. Vui lòng đăng nhập.</p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-md">
                <p class="text-red-700 text-sm font-medium"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Tên đăng nhập</label>
                <input type="text" name="username" autocomplete="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Mật khẩu</label>
                <input type="password" name="password" autocomplete="current-password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-lg font-semibold text-sm shadow-sm transition-colors">
                Đăng nhập hệ thống
            </button>
        </form>
        
        <div class="mt-6 text-center text-sm text-gray-500">
            Chưa có tài khoản? <a class="text-indigo-600 font-semibold hover:underline" href="register.php">Đăng ký ngay</a>
        </div>
    </div>
</body>
</html>