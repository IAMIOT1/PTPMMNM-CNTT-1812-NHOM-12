<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(array $input): void
    {
        if (!self::isValid($input)) {
            http_response_code(403);
            exit('CSRF_INVALID');
        }
    }

    public static function isValid(array $input): bool
    {
        $token = $input['csrf_token'] ?? '';
        return is_string($token) && isset($_SESSION['csrf_token']) &&
            hash_equals($_SESSION['csrf_token'], $token);
    }
}
