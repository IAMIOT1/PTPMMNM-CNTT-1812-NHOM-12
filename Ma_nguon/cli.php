<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TranslationRepository.php';
require_once __DIR__ . '/src/ReleaseExporter.php';
require_once __DIR__ . '/src/ReviewManager.php';
require_once __DIR__ . '/src/TranslationStats.php';

$args = $argv;
$command = $args[1] ?? null;

$conn = db();

switch ($command) {
    case 'import':
        $projectId = (int)($args[2] ?? 1);
        $versionNumber = $args[3] ?? 'v1.0.0';
        $filePath = $args[4] ?? null;

        if (!$filePath || !file_exists($filePath)) {
            fwrite(STDERR, "Lỗi: Vui lòng cung cấp đường dẫn tệp JSON hợp lệ.\nCách dùng: php cli.php import <project_id> <version> <path_to_json>\n");
            exit(1);
        }

        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, "Lỗi: Tệp JSON không đúng định dạng.\n");
            exit(1);
        }

        $repo = new TranslationRepository($conn);
        $versionId = $repo->importSourceVersion($projectId, $versionNumber, $data);
        echo "-> Nhập thành công phiên bản nguồn [{$versionNumber}] với ID: {$versionId}\n";
        break;

    case 'export':
        $projectId = (int)($args[2] ?? 1);
        $locale = $args[3] ?? 'vi';
        $versionNumber = $args[4] ?? 'v1.0.0';

        // Tự động tạo đường dẫn lưu tệp vật lý
        $outputPath = __DIR__ . "/releases/{$locale}/{$versionNumber}.json";

        $exporter = new ReleaseExporter($conn);
        $jsonPayload = $exporter->generateRelease($projectId, $locale, $versionNumber, $outputPath);

        echo "=== XUẤT GÓI PHÁT HÀNH THÀNH CÔNG ===\n";
        echo "- Ngôn ngữ: {$locale}\n";
        echo "- Phiên bản: {$versionNumber}\n";
        echo "- Tệp lưu tại: {$outputPath}\n\n";
        echo "Nội dung JSON:\n" . $jsonPayload . "\n";
        break;
    case 'stats':
        $projectId = (int)($args[2] ?? 1);
        $versionId = (int)($args[3] ?? 1);
        $locale = $args[4] ?? 'vi';

        $statsManager = new TranslationStats($conn);
        $stats = $statsManager->getProgress($projectId, $versionId, $locale);

        echo "=== THỐNG KÊ TIẾN ĐỘ DỊCH [{$locale}] (Phiên bản ID: {$versionId}) ===\n";
        echo "- Tổng số chuỗi: {$stats['total']}\n";
        echo "- Đã dịch: {$stats['translated']}\n";
        echo "- Đã duyệt (Approved): {$stats['approved']}\n";
        echo "- Tỷ lệ hoàn thành: {$stats['percentage']}%\n";
        break;

    default:
        echo "=== HỆ THỐNG QUẢN LÝ BẢN DỊCH (CLI) ===\n";
        echo "Các lệnh hỗ trợ:\n";
        echo "  1. Nhập nguồn:  php cli.php import <project_id> <version> <json_file>\n";
        echo "  2. Xuất bản:    php cli.php export <project_id> <locale> <version>\n";
        echo "  3. Thống kê:    php cli.php stats <project_id> <version_id> <locale>\n";
        break;
}
