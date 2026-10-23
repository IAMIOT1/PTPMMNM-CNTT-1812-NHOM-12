<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/JsonParser.php';
require_once __DIR__ . '/src/TranslationRepository.php';
require_once __DIR__ . '/src/VariableValidator.php';
require_once __DIR__ . '/src/ReleaseExporter.php';
require_once __DIR__ . '/src/ReviewManager.php';
require_once __DIR__ . '/src/TranslationStats.php';

try {
    $conn = db();
    $repo = new TranslationRepository($conn);

    $sampleJson = [
        "auth" => [
            "login" => "Đăng nhập {name}",
            "welcome" => "Chào mừng bạn quay lại, có {count} tin nhắn mới."
        ],
        "common" => [         
            "button" => "Xác nhận"
        ]
    ];

    echo "=== 1. KIỂM THỬ NHẬP JSON LỒNG NHAU ===\n";
    $versionId = $repo->importSourceVersion(1, "v1.0.0", $sampleJson);
    echo "-> Nhập thành công phiên bản nguồn với ID: {$versionId}\n\n";

    echo "=== 2. KIỂM THỬ TRÌNH KIỂM TRA BIẾN (VariableValidator) ===\n";
    $sourceText = "Chào mừng bạn quay lại, có {count} tin nhắn mới.";
    
    $goodTranslation = "Welcome back, you have {count} new messages.";
    $badTranslation = "Welcome back, you have new messages."; 

    $res1 = VariableValidator::validate($sourceText, $goodTranslation);
    $res2 = VariableValidator::validate($sourceText, $badTranslation);

    echo "Bản dịch đúng ('$goodTranslation'): " . ($res1 ? "HỢP LỆ (ĐẠT)" : "LỖI") . "\n";
    echo "Bản dịch thiếu biến ('$badTranslation'): " . ($res2 ? "HỢP LỆ" : "KHÔNG HỢP LỆ (Bị chặn đúng chuẩn)") . "\n";

    // Bổ sung phần kiểm thử xuất bản gói ngôn ngữ
    echo "\n=== 3. KIỂM THỬ XUẤT GÓI NGÔN NGỮ (Release Exporter) ===\n";
    $exporter = new ReleaseExporter($conn);
    $releasedJson = $exporter->generateRelease(1, 'en', 'v1.0.0');
    echo "-> Đã tạo và lưu gói phát hành JSON thành công:\n";
    echo $releasedJson . "\n";

	// Bổ sung phần kiểm thử bộ lọc kiểm duyệt (Reviews)
    echo "\n=== 4. KIỂM THỬ QUẢN LÝ KIỂM DUYỆT (ReviewManager) ===\n";
    $reviewManager = new ReviewManager($conn);
    $translatorRes = queryParams(
        $conn,
        "SELECT pm.user_id
         FROM project_members pm
         WHERE pm.project_id = $1 AND pm.role_in_project IN ('Translator', 'Team Lead')
         ORDER BY pm.user_id LIMIT 1",
        [1]
    );
    $translatorId = (int)pg_fetch_result($translatorRes, 0, 'user_id');
    $reviewerRes = queryParams(
        $conn,
        "SELECT pm.user_id
         FROM project_members pm
         WHERE pm.project_id = $1 AND pm.role_in_project IN ('Reviewer', 'Team Lead')
         ORDER BY pm.user_id LIMIT 1",
        [1]
    );
    $reviewerId = (int)pg_fetch_result($reviewerRes, 0, 'user_id');
    if ($translatorId <= 0 || $reviewerId <= 0) {
        throw new RuntimeException('Assign a Translator and Reviewer/Team Lead to project 1 before running this demo.');
    }

    // Lấy toàn bộ các chuỗi thuộc phiên bản nguồn vừa nhập ($versionId)
    $resStrings = queryParams($conn, "SELECT id, source_text FROM strings WHERE source_version_id = $1", [$versionId]);
    
    $lastTranslationId = 0;
    while ($stringRow = pg_fetch_assoc($resStrings)) {
        $stringId = (int)$stringRow['id'];
        
        // Thêm bản dịch tiếng Việt cho chuỗi này
        $insTrans = queryParams(
            $conn, 
            "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
             VALUES ($1, 'vi', $2, 'draft', $3)
             ON CONFLICT (string_id, locale) 
             DO UPDATE SET translated_text = EXCLUDED.translated_text,
                           status = 'draft', user_id = EXCLUDED.user_id
             RETURNING id",
            [$stringId, "Đã dịch cho: " . $stringRow['source_text'], $translatorId]
        );
        $transRow = pg_fetch_assoc($insTrans);
        $lastTranslationId = (int)$transRow['id'];

        // Ghi nhận lịch sử kiểm duyệt (approved)
        $reviewManager->submitReview($lastTranslationId, $reviewerId, 'approved', 'Phê duyệt tự động cho phiên bản.');
    }
    echo "-> Đã dịch và phê duyệt toàn bộ chuỗi thuộc phiên bản ID: {$versionId}\n";// Bổ sung phần kiểm thử thống kê tiến độ
    echo "\n=== 5. KIỂM THỬ THỐNG KÊ TIẾN ĐỘ DỊCH (TranslationStats) ===\n";
    $statsManager = new TranslationStats($conn);
    // Lấy ID của version nguồn vừa nhập ở bước 1
    $stats = $statsManager->getProgress(1, $versionId, 'vi');
    
    echo "-> Thống kê ngôn ngữ [vi] cho phiên bản ID: {$versionId}\n";
    echo "- Tổng số chuỗi gốc: {$stats['total']}\n";
    echo "- Đã dịch: {$stats['translated']}\n";
    echo "- Đã duyệt (Approved): {$stats['approved']}\n";
    echo "- Tỷ lệ hoàn thành: {$stats['percentage']}%\n";



} catch (Throwable $e) {
    fwrite(STDERR, "Lỗi thực thi demo: " . $e->getMessage() . "\n");
    exit(1);
}
