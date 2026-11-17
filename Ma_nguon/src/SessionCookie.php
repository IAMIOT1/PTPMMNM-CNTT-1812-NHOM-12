<?php
declare(strict_types=1);

final class AppSession
{
    public static function start(): void
    {
        $status = session_status();
        if ($status === PHP_SESSION_ACTIVE) {
            return;
        }
        if ($status !== PHP_SESSION_NONE) {
            throw new RuntimeException('SESSION_UNAVAILABLE');
        }

        if (ini_set('session.use_strict_mode', '1') === false ||
            ini_set('session.use_only_cookies', '1') === false) {
            throw new RuntimeException('SESSION_CONFIGURATION_FAILED');
        }

        $httpsValue = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        $directHttps = $httpsValue !== '' && !in_array($httpsValue, ['off', '0', 'false'], true);
        $forwardedProto = strtolower(trim(explode(
            ',',
            (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
        )[0]));

        if (!session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $directHttps || $forwardedProto === 'https',
        ])) {
            throw new RuntimeException('SESSION_COOKIE_CONFIGURATION_FAILED');
        }
        if (!session_start()) {
            throw new RuntimeException('SESSION_START_FAILED');
        }
    }
}
