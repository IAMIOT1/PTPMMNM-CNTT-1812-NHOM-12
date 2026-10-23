mkdir -p database

cat << 'EOF' > database/db.php
<?php
declare(strict_types=1);

function getDbConnection() {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'tms_db';
    $user = getenv('DB_USER') ?: 'postgres';
    $password = getenv('DB_PASSWORD') ?: 'postgres';

    $conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
    $conn = pg_connect($conn_string);
    if (!$conn) {
        die("Không thể kết nối cơ sở dữ liệu PostgreSQL.");
    }
    return $conn;
}

function queryParams($conn, string $sql, array $params = []) {
    $result = pg_query_params($conn, $sql, $params);
    if (!$result) {
        throw new Exception(pg_last_error($conn));
    }
    return $result;
}
EOF