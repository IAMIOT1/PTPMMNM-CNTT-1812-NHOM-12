<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(254) UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'translator',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );";

    queryParams($conn, $sql, []);
    echo "Users table is ready; no privileged account was created.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "User migration failed: {$e->getMessage()}\n");
    exit(1);
}
