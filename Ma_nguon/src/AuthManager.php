<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

class AuthManager {
    private $conn;
    private string $lastLoginError = '';

    public function __construct($conn) {
        $this->conn = $conn;
        queryParams($this->conn, 'ALTER TABLE users ADD COLUMN IF NOT EXISTS is_verified BOOLEAN NOT NULL DEFAULT TRUE', []);
        queryParams($this->conn, 'ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_code VARCHAR(6)', []);
    }

    /**
     * Xác thực đăng nhập người dùng
     */
    public function login(string $username, string $password): bool {
        $res = queryParams(
            $this->conn,
            'SELECT id, username, password_hash, role, is_verified
             FROM users
             WHERE username = $1 AND is_active = TRUE',
            [$username]
        );
        $user = pg_fetch_assoc($res);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['is_verified'] !== 't') {
                $this->lastLoginError = 'EMAIL_NOT_VERIFIED';
                return false;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'role' => (string)$user['role'],
            ];
            return true;
        }
        return false;
    }

    public function lastLoginError(): string
    {
        return $this->lastLoginError;
    }

    /**
     * Đăng xuất
     */
    public function logout(): void {
        self::destroySession();
    }

    public static function destroySession(): void {
        if (session_status() === PHP_SESSION_NONE && !session_start()) {
            throw new RuntimeException('SESSION_START_FAILED');
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $cookieDeleted = setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
            if (!$cookieDeleted) {
                throw new RuntimeException('SESSION_COOKIE_CLEAR_FAILED');
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
            throw new RuntimeException('SESSION_DESTROY_FAILED');
        }
    }

    /**
     * Kiểm tra xem đã đăng nhập chưa
     */
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }

    /**
     * Lấy thông tin người dùng hiện tại
     */
    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) {
            return null;
        }
        $result = queryParams(
            $this->conn,
            'SELECT id, username, email, role, is_active FROM users WHERE id = $1',
            [(int)$_SESSION['user_id']]
        );
        $user = pg_fetch_assoc($result);
        if (!$user || $user['is_active'] !== 't') {
            self::destroySession();
            return null;
        }

        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)$user['role'],
        ];
        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'is_active' => true
        ];
    }

    public static function redirectToLogin(string $returnTo): never {
        $returnTo = self::safeReturnPath($returnTo);
        header('Location: login.php?next=' . rawurlencode($returnTo), true, 303);
        exit;
    }

    public static function safeReturnPath(string $returnTo): string {
        $parts = parse_url($returnTo);
        if (!str_starts_with($returnTo, '/') ||
            str_starts_with($returnTo, '//') ||
            str_contains($returnTo, '\\') ||
            str_contains($returnTo, "\r") ||
            str_contains($returnTo, "\n") ||
            $parts === false ||
            isset($parts['scheme']) ||
            isset($parts['host'])) {
            return '/index.php';
        }
        return $returnTo;
    }

    /**
     * Kiểm tra quyền hạn (Role check)
     */
    public function hasRole(array $allowedRoles): bool {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        return in_array($user['role'], $allowedRoles, true);
    }
}
