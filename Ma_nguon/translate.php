<?php
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/VariableValidator.php';
require_once __DIR__ . '/src/AuditLogger.php';
require_once __DIR__ . '/src/NotificationManager.php';

if (!isset($_SESSION['user_id'])) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translate.php'));
}

$conn = db();
$auth = new AuthManager($conn);
if (!$auth->isLoggedIn()) {
    AuthManager::redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translate.php'));
}
$currentUser = $auth->getCurrentUser();
$csrfToken = Csrf::token();
$notificationManager = new NotificationManager($conn);
$notifications = $notificationManager->recentForUser((int)$currentUser['id']);
$unreadNotificationCount = $notificationManager->unreadCount((int)$currentUser['id']);
$logger = new AuditLogger($conn);
$message = '';
$error = '';

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : null;
if ($versionId === null) {
    $versionRes = queryParams(
        $conn,
        "SELECT DISTINCT source_version_id FROM strings WHERE source_version_id IS NOT NULL ORDER BY source_version_id DESC LIMIT 1",
        []
    );
    $versionRow = pg_fetch_assoc($versionRes);

    if ($versionRow) {
        $versionId = (int)$versionRow['source_version_id'];
    } else {
        $versionRes = queryParams($conn, "SELECT id FROM source_versions ORDER BY id DESC LIMIT 1", []);
        $versionRow = pg_fetch_assoc($versionRes);
        $versionId = $versionRow ? (int)$versionRow['id'] : 1;
    }
}
$locale = $_GET['locale'] ?? 'vi';
$currentVersionRes = queryParams(
    $conn,
    'SELECT id, project_id FROM source_versions WHERE id = $1',
    [$versionId]
);
$currentVersion = pg_fetch_assoc($currentVersionRes);
$projectId = $currentVersion ? (int)$currentVersion['project_id'] : 1;

// Xử lý lưu bản dịch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['string_id'])) {
    Csrf::verify($_POST);
    $stringId = (int)($_POST['string_id'] ?? 0);
    $translatedText = trim((string)($_POST['translated_text'] ?? ''));
    $expectedUpdatedAt = (string)($_POST['translation_updated_at'] ?? '');

    $sourceRes = queryParams(
        $conn,
        'SELECT project_id, source_text FROM strings WHERE id = $1 AND source_version_id = $2',
        [$stringId, $versionId]
    );
    $sourceRow = pg_fetch_assoc($sourceRes);

    if (!$sourceRow) {
        $error = 'Không tìm thấy chuỗi nguồn để lưu bản dịch.';
    } elseif (!ProjectAccess::canTranslate($conn, (int)$sourceRow['project_id'], $currentUser)) {
        http_response_code(403);
        $error = 'Bạn chưa là thành viên của dự án này.';
    } elseif (!VariableValidator::validate($sourceRow['source_text'], $translatedText)) {
        $error = "Lỗi: Bản dịch chứa sai hoặc thiếu biến thay thế (placeholder) so với bản gốc!";
    } else {
        if (pg_query($conn, 'BEGIN') === false) {
            throw new RuntimeException('TRANSLATION_SAVE_TRANSACTION_FAILED');
        }
        try {
            queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
            $saveRes = queryParams(
                $conn,
                "INSERT INTO translations (string_id, locale, translated_text, status, updated_at, user_id)
                 VALUES ($1, $2, $3, 'draft', clock_timestamp(), $5)
                 ON CONFLICT (string_id, locale)
                 DO UPDATE SET translated_text = EXCLUDED.translated_text,
                               status = 'draft',
                               updated_at = clock_timestamp(),
                               user_id = EXCLUDED.user_id
                 WHERE translations.updated_at = NULLIF($4, '')::timestamptz
                 RETURNING id",
                [$stringId, $locale, $translatedText, $expectedUpdatedAt, $currentUser['id']]
            );
            if (pg_query($conn, 'COMMIT') === false) {
                throw new RuntimeException('TRANSLATION_SAVE_COMMIT_FAILED');
            }
        } catch (Throwable $e) {
            if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($conn, 'ROLLBACK');
            }
            throw $e;
        }
        if (pg_fetch_assoc($saveRes)) {
            $logger->log((int)$sourceRow['project_id'], $stringId, 'UPDATE_TRANSLATION', "Cập nhật bản dịch cho khóa ID $stringId ($locale)");
            $message = "Đã lưu bản dịch thành công!";
        } else {
            $error = 'Bản dịch đã được người khác cập nhật. Hãy tải lại trang và áp dụng thay đổi của bạn trên nội dung mới nhất.';
        }
    }
}

// Lấy danh sách chuỗi và bản dịch hiện tại
$sql = "SELECT s.id as string_id, s.key_path, s.source_text, t.translated_text, t.status,
               COALESCE(t.updated_at::text, '') AS translation_updated_at
        FROM strings s 
        LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
        WHERE s.source_version_id = $1";
$res = queryParams($conn, $sql, [$versionId, $locale]);
$strings = [];
while ($row = pg_fetch_assoc($res)) {
    $strings[] = $row;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dịch thuật - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
</head>
<body class="bg-gray-50 min-h-screen p-6">
    <div class="tms-shell max-w-6xl mx-auto bg-white p-6 rounded-xl shadow">
        <div class="tms-topbar flex flex-col sm:flex-row justify-between items-center mb-6 p-4 gap-4">
            <h1 class="text-2xl font-bold text-indigo-600">✍️ Giao diện Dịch thuật & Kiểm tra Biến</h1>
            <div class="flex items-center gap-3 text-sm">
                <?php require __DIR__ . '/templates/tms-header.php'; ?>
                <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Quay lại Trang chủ</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="tms-table-wrap">
        <table class="w-full border-collapse border border-gray-200">
            <thead>
                <tr class="bg-gray-100 text-left">
                    <th class="p-3 border">Khóa (Key Path)</th>
                    <th class="p-3 border">Văn bản gốc (Source)</th>
                    <th class="p-3 border">Bản dịch (<?= htmlspecialchars($locale) ?>)</th>
                    <th class="p-3 border">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($strings as $item): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="string_id" value="<?= $item['string_id'] ?>">
                        <input type="hidden" name="translation_updated_at" value="<?= htmlspecialchars($item['translation_updated_at']) ?>">
                        <td class="p-3 border font-mono text-sm text-indigo-700"><?= htmlspecialchars($item['key_path']) ?></td>
                        <td class="p-3 border text-sm"><?= htmlspecialchars($item['source_text']) ?></td>
                        <td class="p-3 border">
                            <textarea name="translated_text" rows="2" class="w-full border p-2 rounded text-sm focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($item['translated_text'] ?? '') ?></textarea>
                        </td>
                        <td class="p-3 border text-center">
                            <button type="submit" class="bg-indigo-600 text-white px-3 py-1 rounded hover:bg-indigo-700 text-sm font-bold">Lưu</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</body>
</html>