<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    $sql = "CREATE TABLE IF NOT EXISTS audit_logs (
        id SERIAL PRIMARY KEY,
        project_id INT NOT NULL,
        string_id INT,
        action_type VARCHAR(50) NOT NULL,
        details TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );";

    queryParams($conn, $sql, []);
    echo "✅ Đã tạo bảng audit_logs thành công!\n";
} catch (Exception $e) {
    echo "❌ Lỗi: " . $e->getMessage() . "\n";
}
