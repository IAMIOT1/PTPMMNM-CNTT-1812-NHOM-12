<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/ProjectAccess.php';

function renderReleaseValue(mixed $value): void {
    if (!is_array($value)) {
        echo '<span class="whitespace-pre-wrap break-words">' .
            htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</span>';
        return;
    }
    echo '<ul class="ml-5 mt-1 list-disc space-y-1">';
    foreach ($value as $key => $child) {
        echo '<li><span class="font-mono font-semibold text-indigo-700">' .
            htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '</span>: ';
        renderReleaseValue($child);
        echo '</li>';
    }
    echo '</ul>';
}

$connection = db();
$auth = new AuthManager($connection);
$user = $auth->getCurrentUser();
if ($user === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/release_view.php'));
}
$releaseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($releaseId === false || $releaseId === null || $releaseId < 1) {
    http_response_code(400);
    exit('ID gói phát hành không hợp lệ.');
}
$releaseRes = queryParams(
    $connection,
    'SELECT rs.id, rs.project_id, rs.source_version_id, rs.locale, rs.version_number,
            rs.json_content, rs.created_at, p.name AS project_name
     FROM release_snapshots rs
     JOIN projects p ON p.id = rs.project_id
     WHERE rs.id = $1',
    [$releaseId]
);
$release = pg_fetch_assoc($releaseRes);
if (!$release || !ProjectAccess::canTranslate($connection, (int)$release['project_id'], $user)) {
    http_response_code(404);
    exit('Không tìm thấy gói hoặc bạn không có quyền truy cập.');
}
try {
    $package = json_decode($release['json_content'], true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    error_log('INVALID_RELEASE_SNAPSHOT: ' . $e->getMessage());
    http_response_code(500);
    exit('Nội dung gói phát hành không hợp lệ.');
}

if (($_GET['download'] ?? '') === '1') {
    if (!ProjectAccess::canManageRelease($connection, (int)$release['project_id'], (int)$user['id'])) {
        http_response_code(403);
        exit('Chỉ Trưởng nhóm của dự án mới được tải tệp JSON phát hành.');
    }
    $filename = 'release-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $release['locale']) .
        '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $release['version_number']) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($release['json_content']));
    echo $release['json_content'];
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="releases.package_preview">Xem trước gói phát hành - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-slate-50 text-slate-800">
<main class="mx-auto max-w-5xl space-y-5 px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <a class="text-sm font-semibold text-indigo-700" href="release_history.php?project_id=<?= (int)$release['project_id'] ?>">← <span data-i18n="releases.history">Lịch sử phát hành</span></a>
        <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
    </div>
    <section class="rounded-xl border bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold" data-i18n="releases.package_preview">Xem trước gói ngôn ngữ</h1>
                <p class="mt-1 text-sm text-slate-500" data-i18n-ignore><?= htmlspecialchars($release['project_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($release['locale'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($release['version_number'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-xs text-slate-400"><span data-i18n="releases.published">Phát hành</span> <?= htmlspecialchars($release['created_at'], ENT_QUOTES, 'UTF-8') ?> <span data-i18n="releases.immutable">· gói bất biến</span></p>
            </div>
            <a class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" href="release_view.php?id=<?= (int)$release['id'] ?>&amp;download=1" data-i18n="releases.download_json">Tải JSON</a>
        </div>
        <div class="mt-6 rounded-lg bg-slate-50 p-5" data-i18n-ignore>
            <?php renderReleaseValue($package); ?>
        </div>
    </section>
</main>
<script src="tms-i18n.js" defer></script>
</body>
</html>
