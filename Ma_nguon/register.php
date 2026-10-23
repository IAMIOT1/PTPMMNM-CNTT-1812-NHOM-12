<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';

$connection = db();
$auth = new AuthManager($connection);
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Tên đăng nhập cần dài 3–50 ký tự và chỉ gồm chữ, số, dấu chấm, gạch dưới hoặc gạch ngang.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Vui lòng nhập địa chỉ email hợp lệ.';
    } elseif (strlen($password) < 8) {
        $error = 'Mật khẩu cần có ít nhất 8 ký tự.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        if ($passwordHash === false) {
            throw new RuntimeException('PASSWORD_HASH_FAILED');
        }
        $result = queryParams(
            $connection,
            "INSERT INTO users (username, email, password_hash, role)
             VALUES ($1, $2, $3, 'translator')
             ON CONFLICT DO NOTHING
             RETURNING id",
            [$username, $email, $passwordHash]
        );
        if (pg_fetch_assoc($result)) {
            header('Location: login.php?registered=1', true, 303);
            exit;
        }
        $error = 'Username hoặc email đã được sử dụng.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng ký - Enterprise TMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="tms.css">
</head>
<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <section class="card tms-auth-card">
                <div class="card-body p-4 p-md-5">
                    <div class="tms-auth-brand">🌍</div>
                    <h1 class="h3 text-center text-primary mb-2">Tạo tài khoản TMS</h1>
                    <p class="text-secondary text-center mb-4">Tài khoản mới không được cấp quyền quản trị hệ thống.</p>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" class="vstack gap-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div>
                            <label class="form-label" for="username">Username</label>
                            <input class="form-control" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" maxlength="50" autocomplete="username" required>
                        </div>
                        <div>
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" maxlength="254" autocomplete="email" required>
                        </div>
                        <div>
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div>
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Đăng ký</button>
                    </form>

                    <p class="text-center mt-4 mb-0">
                        Đã có tài khoản? <a href="login.php">Đăng nhập</a>
                    </p>
                </div>
            </section>
        </div>
    </div>
</main>
</body>
</html>
