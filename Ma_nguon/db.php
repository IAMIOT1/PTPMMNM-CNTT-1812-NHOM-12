<?php
$conn = pg_connect("dbname=tms_db user=postgres");

if (!$conn) {
    die("Kết nối cơ sở dữ liệu thất bại!\n");
}

// 1. Tạo phiên bản nguồn mới (v1.0.0)
$versionNumber = 'v1.0.0';
$resultVersion = pg_query_params($conn, "INSERT INTO source_versions (version_number, created_at) VALUES ($1, CURRENT_TIMESTAMP) RETURNING id", [$versionNumber]);
$rowVersion = pg_fetch_assoc($resultVersion);
$newVersionId = $rowVersion['id'];

echo "✅ Đã tạo phiên bản mới thành công với ID: {$newVersionId}\n";

// 2. Mảng dữ liệu mẫu
$sampleData = [
    'auth.login' => 'Đăng nhập hệ thống',
    'auth.logout' => 'Đăng xuất',
    'common.save' => 'Lưu thay đổi',
    'common.cancel' => 'Hủy bỏ'
];

// 3. Chèn khóa dịch vào bảng strings
foreach ($sampleData as $keyPath => $sourceText) {
    pg_query_params($conn, "INSERT INTO strings (source_version_id, key_path, source_text) VALUES ($1, $2, $3)", [$newVersionId, $keyPath, $sourceText]);
}

echo "🚀 Đã nạp thành công dữ liệu mẫu vào phiên bản ID {$newVersionId}!\n";