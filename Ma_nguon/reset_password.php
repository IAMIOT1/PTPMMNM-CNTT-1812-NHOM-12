<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Csrf.php';

$conn = db();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$validToken = $token !== '' && preg_match('/^[A-Za-z0-9_-]{40,50}$/', $token) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!$validToken) {
        $error = 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';
    } elseif (strlen($password) < 8 || $password !== $confirmation) {
        $error = 'Mật khẩu mới cần ít nhất 8 ký tự và phải khớp xác nhận.';
    } else {
        $tokenHash = hash('sha256', $token);
        $result = queryParams(
            $conn,
            'SELECT id, user_id FROM password_reset_tokens
             WHERE token_hash = $1 AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP',
            [$tokenHash]
        );
        $reset = pg_fetch_assoc($result);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!$reset || $hash === false) {
            $error = 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';
        } else {
            queryParams($conn, 'UPDATE users SET password_hash = $1 WHERE id = $2', [$hash, (int)$reset['user_id']]);
            queryParams($conn, 'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = $1', [(int)$reset['id']]);
            header('Location: login.php?reset=1', true, 303);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Đặt lại mật khẩu</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 p-4">
<main class="w-full max-w-md rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
    <h1 class="text-2xl font-bold text-indigo-600">Đặt lại mật khẩu</h1>
    <?php if ($error !== ''): ?><p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" class="mt-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label class="block text-sm font-semibold text-gray-700">Mật khẩu mới
            <input type="password" name="password" required minlength="8" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-semibold text-gray-700">Xác nhận mật khẩu
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <button class="w-full rounded-lg bg-indigo-600 py-2.5 font-semibold text-white hover:bg-indigo-700">Cập nhật mật khẩu</button>
    </form>
</main>
</body>
</html>
