<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    $sql = "CREATE TABLE IF NOT EXISTS translation_memory (
        id SERIAL PRIMARY KEY,
        project_id INT NOT NULL,
        locale VARCHAR(10) NOT NULL,
        source_text TEXT NOT NULL,
        translated_text TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT unique_tm_entry UNIQUE (project_id, locale, source_text)
    );";

    queryParams($conn, $sql, []);
    echo "✅ Đã tạo bảng translation_memory thành công!\n";
} catch (Exception $e) {
    echo "❌ Lỗi: " . $e->getMessage() . "\n";
}
