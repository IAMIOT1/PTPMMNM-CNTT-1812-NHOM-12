<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    queryParams(
        $conn,
        "CREATE TABLE IF NOT EXISTS locales (
            code VARCHAR(10) PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )",
        []
    );

    queryParams(
        $conn,
        "INSERT INTO locales (code, name) VALUES
            ('vi', 'Tiếng Việt'),
            ('en', 'English'),
            ('ja', 'Japanese')
         ON CONFLICT (code) DO NOTHING",
        []
    );

    echo "Supported locales are ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Locale migration failed: {$e->getMessage()}\n");
    exit(1);
}
