<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Csrf.php';

$conn = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    if ($identifier === '' || strlen($identifier) > 254) {
        $error = 'Vui lòng nhập email hoặc tên đăng nhập hợp lệ.';
    } else {
        try {
            $result = queryParams(
                $conn,
                'SELECT id, email FROM users WHERE is_active = TRUE
                 AND (lower(email) = lower($1) OR lower(username) = lower($1)) LIMIT 1',
                [$identifier]
            );
            $user = pg_fetch_assoc($result);
            if ($user) {
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $tokenHash = hash('sha256', $token);
                queryParams($conn, 'DELETE FROM password_reset_tokens WHERE user_id = $1 OR expires_at < CURRENT_TIMESTAMP', [(int)$user['id']]);
                queryParams(
                    $conn,
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                     VALUES ($1, $2, CURRENT_TIMESTAMP + INTERVAL \'1 hour\')',
                    [(int)$user['id'], $tokenHash]
                );
                $baseUrl = rtrim((string)(getenv('TMS_PUBLIC_URL') ?: ''), '/');
                if ($baseUrl === '') {
                    throw new RuntimeException('TMS_PUBLIC_URL_MISSING');
                }
                $resetUrl = $baseUrl . '/reset_password.php?token=' . rawurlencode($token);
                $from = trim((string)(getenv('TMS_MAIL_FROM') ?: ''));
                if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) ||
                    !mail(
                        (string)$user['email'],
                        'Yeu cau dat lai mat khau TMS',
                        "Mo lien ket sau trong vong 1 gio de dat mat khau moi:\n\n" . $resetUrl,
                        "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                    )) {
                    throw new RuntimeException('PASSWORD_RESET_EMAIL_FAILED');
                }
            }
            $message = 'Nếu tài khoản tồn tại, hướng dẫn đặt lại mật khẩu đã được gửi tới email đăng ký.';
        } catch (Throwable $exception) {
            error_log('PASSWORD_RESET_REQUEST_FAILED: ' . $exception->getMessage());
            $error = 'Không thể gửi yêu cầu lúc này. Vui lòng thử lại sau.';
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Quên mật khẩu</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 p-4">
<main class="w-full max-w-md rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
    <h1 class="text-2xl font-bold text-indigo-600">Quên mật khẩu?</h1>
    <p class="mt-2 text-sm text-gray-500">Nhập email hoặc tên đăng nhập để nhận liên kết đặt lại mật khẩu.</p>
    <?php if ($message !== ''): ?><p class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" class="mt-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="block text-sm font-semibold text-gray-700">Email hoặc tên đăng nhập
            <input name="identifier" required maxlength="254" autocomplete="username" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <button class="w-full rounded-lg bg-indigo-600 py-2.5 font-semibold text-white hover:bg-indigo-700">Gửi liên kết đặt lại</button>
    </form>
    <a class="mt-5 block text-center text-sm font-semibold text-indigo-600 hover:underline" href="login.php">Quay lại đăng nhập</a>
</main>
</body>
</html>
