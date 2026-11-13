<?php

declare(strict_types=1);
require_once __DIR__ . '/EnvLoader.php';
// Dùng lại biến môi trường cho lệnh psql ngoài PHP. Không nạp mã PHP cấu hình.
$configPath = __DIR__ . '/../.local/db.json';
if (is_file($configPath)) {
    $config = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($config)) {
        throw new RuntimeException('DATABASE_CONFIG_INVALID');
    }
    foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('DATABASE_CONFIG_INVALID');
        }
        putenv($key . '=' . $config[$key]);
    }
}
function db(): PgSql\Connection
{
    if (!extension_loaded('pgsql')) {
        throw new RuntimeException('PGSQL_EXTENSION_MISSING');
    }

    $databaseUrls = [];
    foreach (['DATABASE_URL', 'DATABASE_EXTERNAL_URL', 'RENDER_EXTERNAL_DATABASE_URL'] as $key) {
        $databaseUrl = getenv($key);
        if ((!is_string($databaseUrl) || trim($databaseUrl) === '') && isset($_ENV[$key])) {
            $databaseUrl = $_ENV[$key];
        }
        if (is_string($databaseUrl) && trim($databaseUrl) !== '' && !in_array($databaseUrl, $databaseUrls, true)) {
            $databaseUrls[] = trim($databaseUrl);
        }
    }

    $urlConnectionFailed = false;
    foreach ($databaseUrls as $databaseUrl) {
        try {
            $connection = @pg_connect(databaseConnectionStringFromUrl($databaseUrl));
        } catch (Throwable) {
            $urlConnectionFailed = true;
            continue;
        }
        if ($connection) {
            return $connection;
        }
        $urlConnectionFailed = true;
    }

    foreach (['PGHOST', 'PGDATABASE', 'PGUSER'] as $key) {
        if (!getenv($key)) {
            throw new RuntimeException($urlConnectionFailed ? 'DATABASE_UNAVAILABLE' : 'DATABASE_CONFIG_MISSING');
        }
    }
    $connection = @pg_connect('connect_timeout=3');
    if (!$connection) {
        throw new RuntimeException('DATABASE_UNAVAILABLE');
    }
    return $connection;
}

function databaseConnectionStringFromUrl(string $databaseUrl): string
{
    $parts = parse_url($databaseUrl);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)) {
        throw new RuntimeException('DATABASE_URL_INVALID');
    }

    $host = rawurldecode((string)($parts['host'] ?? ''));
    $user = rawurldecode((string)($parts['user'] ?? ''));
    $password = rawurldecode((string)($parts['pass'] ?? ''));
    $database = rawurldecode(ltrim((string)($parts['path'] ?? ''), '/'));
    $port = $parts['port'] ?? 5432;
    if ($host === '' || $user === '' || $database === '' || !is_int($port) || $port < 1 || $port > 65535) {
        throw new RuntimeException('DATABASE_URL_INVALID');
    }

    $values = [
        'host' => $host,
        'port' => (string)$port,
        'dbname' => $database,
        'user' => $user,
        'password' => $password,
        'connect_timeout' => '3',
    ];
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    foreach (['sslmode', 'sslrootcert', 'sslcert', 'sslkey'] as $option) {
        if (isset($query[$option]) && is_string($query[$option]) && $query[$option] !== '') {
            $values[$option] = $query[$option];
        }
    }

    $connectionParts = [];
    foreach ($values as $key => $value) {
        $escapedValue = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        $connectionParts[] = $key . "='" . $escapedValue . "'";
    }
    return implode(' ', $connectionParts);
}

function queryParams(PgSql\Connection $connection, string $sql, array $params): PgSql\Result
{
    $result = @pg_query_params($connection, $sql, $params);
    if ($result === false) {
        $error = trim((string)pg_last_error($connection));
        throw new RuntimeException(
            $error !== '' ? 'DATABASE_OPERATION_FAILED: ' . $error : 'DATABASE_OPERATION_FAILED'
        );
    }
    return $result;
}
