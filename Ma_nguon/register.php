<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/SmtpMailer.php';

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
        $verificationCode = (string)random_int(100000, 999999);
        $transactionStarted = false;
        try {
            if (pg_query($connection, 'BEGIN') === false) {
                throw new RuntimeException('REGISTRATION_TRANSACTION_BEGIN_FAILED');
            }
            $transactionStarted = true;
            $existingResult = queryParams(
                $connection,
                'SELECT id, username, email, is_verified
                 FROM users
                 WHERE lower(username) = lower($1) OR lower(email) = lower($2)
                 FOR UPDATE',
                [$username, $email]
            );
            $existing = pg_fetch_assoc($existingResult);

            if ($existing && $existing['is_verified'] === 't') {
                throw new InvalidArgumentException('Username hoặc email đã được sử dụng.');
            }

            if ($existing) {
                $conflictResult = queryParams(
                    $connection,
                    'SELECT id FROM users
                     WHERE id <> $1 AND (lower(username) = lower($2) OR lower(email) = lower($3))
                     LIMIT 1',
                    [(int)$existing['id'], $username, $email]
                );
                if (pg_num_rows($conflictResult) > 0) {
                    throw new InvalidArgumentException('Username hoặc email đã được sử dụng.');
                }
                queryParams(
                    $connection,
                    'UPDATE users
                     SET username = $1, email = $2, password_hash = $3,
                         verification_code = $4, is_verified = FALSE
                     WHERE id = $5',
                    [$username, $email, $passwordHash, $verificationCode, (int)$existing['id']]
                );
            } else {
                $insertResult = queryParams(
                    $connection,
                    "INSERT INTO users (username, email, password_hash, role, verification_code, is_verified)
                     VALUES ($1, $2, $3, 'user', $4, FALSE)
                     ON CONFLICT DO NOTHING
                     RETURNING id",
                    [$username, $email, $passwordHash, $verificationCode]
                );
                if (!pg_fetch_assoc($insertResult)) {
                    throw new InvalidArgumentException('Username hoặc email đã được sử dụng.');
                }
            }

            $mailer = new SmtpMailer();
            if (!$mailer->send(
                $email,
                'Mã xác thực tài khoản TMS',
                "Mã xác thực của bạn là: {$verificationCode}\nMã có hiệu lực trong 15 phút."
            )) {
                throw new RuntimeException('REGISTRATION_VERIFICATION_EMAIL_FAILED: ' . $mailer->errorInfo);
            }
            if (pg_query($connection, 'COMMIT') === false) {
                throw new RuntimeException('REGISTRATION_TRANSACTION_COMMIT_FAILED');
            }
            $transactionStarted = false;
            header('Location: verify_email.php?email=' . rawurlencode($email), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            if ($transactionStarted) {
                pg_query($connection, 'ROLLBACK');
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                pg_query($connection, 'ROLLBACK');
            }
            error_log('REGISTRATION_VERIFICATION_FAILED: ' . $exception->getMessage());
            $error = 'Không thể hoàn tất đăng ký. Vui lòng thử lại sau.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="auth.register_title">Đăng ký - Enterprise TMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-light">
<div class="position-fixed top-0 end-0 p-3 d-flex align-items-center gap-2" style="z-index: 10">
    <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
    <?php require __DIR__ . '/templates/language-switcher.php'; ?>
</div>
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <section class="card tms-auth-card">
                <div class="card-body p-4 p-md-5">
                    <div class="tms-auth-brand">🌍</div>
                    <h1 class="h3 text-center text-primary mb-2" data-i18n="auth.register_title">Tạo tài khoản TMS</h1>
                    <p class="text-secondary text-center mb-4" data-i18n="auth.registration_restricted">Tài khoản mới không được cấp quyền quản trị hệ thống.</p>

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
                            <label class="form-label" for="confirm_password" data-i18n="auth.confirm_password">Confirm Password</label>
                            <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit" data-i18n="auth.register">Đăng ký</button>
                    </form>

                    <p class="text-center mt-4 mb-0">
                        <span data-i18n="auth.have_account">Đã có tài khoản?</span> <a href="login.php" data-i18n="auth.login_link">Đăng nhập</a>
                    </p>
                </div>
            </section>
        </div>
    </div>
</main>
<script src="tms-i18n.js" defer></script>
</body>
</html>
