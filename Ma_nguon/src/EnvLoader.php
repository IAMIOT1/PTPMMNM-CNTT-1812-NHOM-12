<?php
declare(strict_types=1);

final class EnvLoader
{
    public static function load(?string $path = null): void
    {
        $path ??= dirname(__DIR__) . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            if (getenv($key) !== false) {
                continue;
            }
            if (strlen($value) >= 2 &&
                (($value[0] === '"' && $value[-1] === '"') ||
                 ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            } else {
                $comment = strpos($value, ' #');
                if ($comment !== false) {
                    $value = rtrim(substr($value, 0, $comment));
                }
            }
            $value = str_replace(['\\n', '\\r', '\\"', "\\'"], ["\n", "\r", '"', "'"], $value);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] ??= $value;
        }
    }
}

EnvLoader::load();
