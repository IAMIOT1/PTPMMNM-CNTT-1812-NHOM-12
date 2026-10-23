<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    $sql = "CREATE TABLE IF NOT EXISTS glossary (
        id SERIAL PRIMARY KEY,
        project_id INT NOT NULL,
        locale VARCHAR(10) NOT NULL,
        term TEXT NOT NULL,
        translation TEXT NOT NULL,
        description TEXT,
        CONSTRAINT unique_glossary_term UNIQUE (project_id, locale, term)
    );";

    queryParams($conn, $sql, []);
    echo "✅ Đã tạo bảng glossary thành công!\n";
} catch (Exception $e) {
    echo "❌ Lỗi: " . $e->getMessage() . "\n";
}
