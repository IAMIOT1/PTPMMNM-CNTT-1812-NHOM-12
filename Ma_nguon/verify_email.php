<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Database.php';

$conn = db();
$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
$message = '';
$error = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isDirectLink = $requestMethod === 'GET' &&
    isset($_GET['email'], $_GET['code']);
$code = $requestMethod === 'POST'
    ? trim((string)($_POST['code'] ?? ''))
    : trim((string)($_GET['code'] ?? ''));

if ($error === '' && ($isDirectLink || $requestMethod === 'POST')) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
        $error = $isDirectLink
            ? 'Mã xác thực không hợp lệ hoặc đã hết hạn'
            : 'Mã xác thực không chính xác hoặc liên kết đã hết hạn';
    } else {
        try {
            $result = queryParams(
                $conn,
                'UPDATE users
                 SET is_verified = TRUE, verification_code = NULL
                 WHERE lower(email) = lower($1)
                   AND verification_code = $2
                   AND is_verified = FALSE
                 RETURNING id',
                [$email, $code]
            );
            if (pg_fetch_assoc($result)) {
                header('Location: login.php?verified=success', true, 303);
                exit;
            }
            $error = $isDirectLink
                ? 'Mã xác thực không hợp lệ hoặc đã hết hạn'
                : 'Mã xác thực không chính xác hoặc liên kết đã hết hạn';
        } catch (Throwable $exception) {
            error_log('EMAIL_VERIFICATION_FAILED: ' . $exception->getMessage());
            $error = 'Không thể xác thực tài khoản lúc này. Vui lòng thử lại sau.';
        }
    }
}
?>
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Xác thực email</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 p-4">
<main class="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm">
<h1 class="text-2xl font-bold text-indigo-600">Xác thực tài khoản</h1>
<p class="mt-2 text-sm text-gray-500">Nhập mã 6 chữ số đã được gửi tới email của bạn.</p>
<?php if ($error !== ''): ?><p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" class="mt-6 space-y-4">
<input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required class="w-full rounded-lg border px-3 py-2 text-center text-xl tracking-widest">
<button class="w-full rounded-lg bg-indigo-600 py-2.5 font-semibold text-white">Xác thực tài khoản</button>
</form></main></body></html>
