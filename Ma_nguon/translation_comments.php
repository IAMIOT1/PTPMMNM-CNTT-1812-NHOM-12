<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/NotificationManager.php';

$connection = db();
$auth = new AuthManager($connection);
$user = $auth->getCurrentUser();
if ($user === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/translation_comments.php'));
}

$stringId = filter_input(INPUT_GET, 'string_id', FILTER_VALIDATE_INT);
$locale = trim((string)($_GET['locale'] ?? ''));
if ($stringId === false || $stringId === null || $stringId < 1 || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{1,9}$/', $locale)) {
    http_response_code(400);
    exit('Yêu cầu không hợp lệ.');
}

$stringRes = queryParams(
    $connection,
    'SELECT s.id, s.key_path, s.source_text, s.source_version_id, p.id AS project_id,
            p.name AS project_name
     FROM strings s
     JOIN source_versions sv ON sv.id = s.source_version_id
     JOIN projects p ON p.id = sv.project_id
     WHERE s.id = $1',
    [$stringId]
);
$string = pg_fetch_assoc($stringRes);
if (
    !$string ||
    !ProjectAccess::canTranslate($connection, (int)$string['project_id'], $user)
) {
    http_response_code(404);
    exit('Không tìm thấy chuỗi hoặc bạn không có quyền truy cập.');
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $length = preg_match_all('/./us', $comment);
    if ($length === false || $length < 1 || $length > 5000) {
        $errorMessage = 'Nhận xét phải có từ 1 đến 5000 ký tự.';
    } else {
        queryParams(
            $connection,
            'INSERT INTO translation_comments (string_id, locale, user_id, comment)
             VALUES ($1, $2, $3, $4)',
            [$stringId, $locale, (int)$user['id'], $comment]
        );
        $memberResult = queryParams(
            $connection,
            'SELECT pm.user_id
             FROM project_members pm
             WHERE pm.project_id = $1 AND pm.user_id <> $2',
            [(int)$string['project_id'], (int)$user['id']]
        );
        while ($member = pg_fetch_assoc($memberResult)) {
            NotificationManager::create(
                $connection,
                (int)$member['user_id'],
                "Có bình luận mới trong chuỗi [{$string['key_path']}] của dự án [{$string['project_name']}].",
                'comments'
            );
        }
        header(
            'Location: translation_comments.php?string_id=' . $stringId . '&locale=' . rawurlencode($locale),
            true,
            303
        );
        exit;
    }
}

$translationRes = queryParams(
    $connection,
    'SELECT translated_text, status FROM translations WHERE string_id = $1 AND locale = $2',
    [$stringId, $locale]
);
$translation = pg_fetch_assoc($translationRes);
$commentsRes = queryParams(
    $connection,
    'SELECT c.comment, c.created_at, COALESCE(u.username, \'Người dùng đã xóa\') AS username
     FROM translation_comments c
     LEFT JOIN users u ON u.id = c.user_id
     WHERE c.string_id = $1 AND c.locale = $2
     ORDER BY c.created_at, c.id',
    [$stringId, $locale]
);
$comments = [];
while ($commentRow = pg_fetch_assoc($commentsRes)) {
    $comments[] = $commentRow;
}
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="translation.comments">Nhận xét bản dịch - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-slate-50 text-slate-800">
<main class="mx-auto max-w-4xl space-y-6 px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <a class="text-sm font-semibold text-indigo-700" href="project_detail.php?id=<?= (int)$string['project_id'] ?>&amp;locale=<?= rawurlencode($locale) ?>&amp;version_id=<?= (int)$string['source_version_id'] ?>">← <span data-i18n="comments.back">Quay lại dự án</span></a>
        <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
        <?php require __DIR__ . '/templates/language-switcher.php'; ?>
    </div>
    <section class="rounded-xl border bg-white p-6 shadow-sm">
        <h1 class="text-xl font-bold"><span data-i18n="comments.review">Nhận xét:</span> <?= htmlspecialchars($string['key_path'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($string['project_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?></p>
        <dl class="mt-5 grid gap-4">
            <div><dt class="text-xs font-bold uppercase text-slate-500" data-i18n="comments.source">Chuỗi nguồn</dt><dd class="mt-1 whitespace-pre-wrap" data-i18n-ignore><?= htmlspecialchars($string['source_text'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt class="text-xs font-bold uppercase text-slate-500" data-i18n="comments.current_translation">Bản dịch hiện tại</dt><dd class="mt-1 whitespace-pre-wrap" data-i18n-ignore><?= htmlspecialchars($translation['translated_text'] ?? '(chưa có)', ENT_QUOTES, 'UTF-8') ?></dd></div>
        </dl>
    </section>
    <section class="rounded-xl border bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold" data-i18n="comments.title">Thảo luận</h2>
        <?php if ($comments): ?>
            <ol class="mt-4 space-y-4">
                <?php foreach ($comments as $item): ?>
                    <li class="border-l-2 border-indigo-200 pl-4">
                        <p class="whitespace-pre-wrap" data-i18n-ignore><?= htmlspecialchars($item['comment'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-1 text-xs text-slate-500" data-i18n-ignore><?= htmlspecialchars($item['username'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="mt-3 text-sm text-slate-500" data-i18n="comments.empty">Chưa có nhận xét nào cho chuỗi này.</p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?><p class="mt-4 text-sm text-red-700"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" class="mt-5 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label class="block text-sm font-semibold" for="comment" data-i18n="comments.add">Thêm nhận xét</label>
            <textarea id="comment" name="comment" maxlength="5000" required rows="4" class="w-full rounded-lg border border-slate-300 p-3"></textarea>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" data-i18n="comments.send">Gửi nhận xét</button>
        </form>
    </section>
</main>
<script src="tms-i18n.js" defer></script>
</body>
</html>
