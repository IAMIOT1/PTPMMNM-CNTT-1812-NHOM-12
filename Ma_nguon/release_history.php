<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/RemoteContentService.php';

$connection = db();
$auth = new AuthManager($connection);
$user = $auth->getCurrentUser();
if ($user === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/release_history.php'));
}
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId < 1) {
    http_response_code(400);
    exit('ID dự án không hợp lệ.');
}
$projectRes = queryParams(
    $connection,
    'SELECT id, name FROM projects WHERE id = $1',
    [$projectId]
);
$project = pg_fetch_assoc($projectRes);
if (!$project || !ProjectAccess::canTranslate($connection, (int)$projectId, $user)) {
    http_response_code(404);
    exit('Không tìm thấy dự án hoặc bạn không có quyền truy cập.');
}

$canReview = ProjectAccess::canReview($connection, (int)$projectId, $user);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_api_sync') {
    if (!Csrf::isValid($_POST)) {
        http_response_code(403);
        exit('Phiên làm việc không hợp lệ.');
    }
    if (!$canReview) {
        http_response_code(403);
        exit('Bạn không có quyền quản lý lịch sử đồng bộ.');
    }
    $confirmationPassword = (string)($_POST['password'] ?? '');
    $passwordResult = queryParams(
        $connection,
        'SELECT password_hash FROM users WHERE id = $1 AND is_active = TRUE',
        [(int)$user['id']]
    );
    $passwordRow = pg_fetch_assoc($passwordResult);
    if (
        $confirmationPassword === '' ||
        !$passwordRow ||
        !password_verify($confirmationPassword, (string)$passwordRow['password_hash'])
    ) {
        header(
            'Location: release_history.php?project_id=' . (int)$projectId .
            '&tab=api_sync&notice=password_invalid',
            true,
            303
        );
        exit;
    }

    $historyId = filter_var($_POST['history_id'] ?? null, FILTER_VALIDATE_INT);
    if ($historyId === false || $historyId === null || $historyId < 1) {
        http_response_code(400);
        exit('Bản ghi lịch sử không hợp lệ.');
    }
    $remoteRollbackCompleted = false;
    try {
        if (pg_query($connection, 'BEGIN') === false) {
            throw new RuntimeException('SYNC_ROLLBACK_TRANSACTION_FAILED');
        }
        $historyResult = queryParams(
            $connection,
            "SELECT h.endpoint_url, h.target_rollback_data::TEXT AS target_rollback_data,
                    h.requires_auth, h.status,
                    EXISTS (
                        SELECT 1 FROM release_sync_history newer
                        WHERE newer.project_id = h.project_id
                          AND newer.type = 'api_sync'
                          AND newer.endpoint_url = h.endpoint_url
                          AND newer.status = 'active'
                          AND (newer.created_at > h.created_at
                               OR (newer.created_at = h.created_at AND newer.id > h.id))
                    ) AS has_newer_active
             FROM release_sync_history h
             WHERE h.id = $1 AND h.project_id = $2 AND h.type = 'api_sync'
             FOR UPDATE",
            [$historyId, $projectId]
        );
        $history = pg_fetch_assoc($historyResult);
        if (!$history || $history['status'] !== 'active') {
            throw new RuntimeException('SYNC_HISTORY_NOT_ACTIVE');
        }
        if ($history['has_newer_active'] === 't') {
            throw new RuntimeException('SYNC_ROLLBACK_NEWER_FIRST');
        }
        if ($history['target_rollback_data'] === null) {
            throw new RuntimeException('SYNC_ROLLBACK_SNAPSHOT_MISSING');
        }

        $rollbackUrl = trim((string)($_POST['rollback_url'] ?? ''));
        RemoteContentService::validatePublicUrl($rollbackUrl);
        if (RemoteContentService::endpointForHistory($rollbackUrl) !== $history['endpoint_url']) {
            throw new InvalidArgumentException('SYNC_ROLLBACK_ENDPOINT_MISMATCH');
        }
        $rollbackMethod = strtoupper(trim((string)($_POST['rollback_method'] ?? 'PUT')));
        if (!in_array($rollbackMethod, ['PUT', 'PATCH'], true)) {
            throw new InvalidArgumentException('SYNC_ROLLBACK_METHOD_INVALID');
        }
        if (
            strtolower(rtrim((string)parse_url($rollbackUrl, PHP_URL_HOST), '.')) === 'api.npoint.io'
            && $rollbackMethod !== 'PUT'
        ) {
            throw new InvalidArgumentException('SYNC_ROLLBACK_METHOD_INVALID');
        }
        (new RemoteContentService())->fetch(
            $rollbackUrl,
            $rollbackMethod,
            (string)$history['target_rollback_data']
        );
        $remoteRollbackCompleted = true;
        $revokeResult = queryParams(
            $connection,
            "UPDATE release_sync_history
             SET status = 'revoked', revoked_at = clock_timestamp(), revoked_by_user_id = $1
             WHERE id = $2 AND project_id = $3 AND type = 'api_sync' AND status = 'active'
             RETURNING id",
            [(int)$user['id'], $historyId, $projectId]
        );
        if (!pg_fetch_assoc($revokeResult)) {
            throw new RuntimeException('SYNC_HISTORY_REVOKE_FAILED');
        }
        if (pg_query($connection, 'COMMIT') === false) {
            throw new RuntimeException('SYNC_ROLLBACK_COMMIT_FAILED');
        }
        $notice = 'revoked';
    } catch (Throwable $exception) {
        if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
            pg_query($connection, 'ROLLBACK');
        }
        error_log('TMS API sync rollback failed: ' . $exception->getMessage());
        $notice = 'rollback_failed';
    }
    header(
        'Location: release_history.php?project_id=' . (int)$projectId .
        '&tab=api_sync&notice=' . $notice .
        ($remoteRollbackCompleted && $notice !== 'revoked' ? '&remote_restored=1' : ''),
        true,
        303
    );
    exit;
}

$tab = ($_GET['tab'] ?? '') === 'api_sync' ? 'api_sync' : 'version_lock';
$notice = (string)($_GET['notice'] ?? '');
$csrfToken = Csrf::token();
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$locksRes = queryParams(
    $connection,
    "SELECT h.version_number, h.record_count, h.created_at, COALESCE(u.username, '—') AS actor_username
     FROM release_sync_history h
     LEFT JOIN users u ON u.id = h.actor_user_id
     WHERE h.project_id = $1 AND h.type = 'version_lock'
     ORDER BY h.created_at DESC, h.id DESC",
    [$projectId]
);
$locks = [];
while ($row = pg_fetch_assoc($locksRes)) {
    $locks[] = $row;
}

$syncsRes = queryParams(
    $connection,
    "SELECT h.id, h.version_number, h.locale, h.endpoint_url, h.record_count, h.status,
            (h.target_rollback_data IS NOT NULL) AS can_rollback,
            h.requires_auth,
            NOT EXISTS (
                SELECT 1
                FROM release_sync_history newer
                WHERE newer.project_id = h.project_id
                  AND newer.type = 'api_sync'
                  AND newer.endpoint_url = h.endpoint_url
                  AND newer.status = 'active'
                  AND (newer.created_at > h.created_at
                       OR (newer.created_at = h.created_at AND newer.id > h.id))
            ) AS is_latest_active,
            h.created_at, h.revoked_at, COALESCE(actor.username, '—') AS actor_username,
            COALESCE(revoker.username, '—') AS revoker_username
     FROM release_sync_history h
     LEFT JOIN users actor ON actor.id = h.actor_user_id
     LEFT JOIN users revoker ON revoker.id = h.revoked_by_user_id
     WHERE h.project_id = $1 AND h.type = 'api_sync'
     ORDER BY h.created_at DESC, h.id DESC",
    [$projectId]
);
$syncs = [];
while ($row = pg_fetch_assoc($syncsRes)) {
    $syncs[] = $row;
}

$releasesRes = queryParams(
    $connection,
    'SELECT rs.id, rs.locale, rs.version_number, rs.created_at, sv.frozen_at
     FROM release_snapshots rs
     JOIN source_versions sv ON sv.id = rs.source_version_id
     WHERE rs.project_id = $1
     ORDER BY rs.created_at DESC, rs.id DESC',
    [$projectId]
);
$releases = [];
while ($row = pg_fetch_assoc($releasesRes)) {
    $releases[] = $row;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="releases.history">Lịch sử phát hành - TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-slate-50 text-slate-800">
<main class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <a class="text-sm font-semibold text-indigo-700" href="project_detail.php?id=<?= (int)$projectId ?>" data-i18n="releases.back_project">← Quay lại dự án</a>
        <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
    </div>
    <section class="mt-5 rounded-xl border bg-white p-6 shadow-sm">
        <h1 class="text-xl font-bold"><span data-i18n="releases.history">Lịch sử phát hành</span> · <?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="mt-1 text-sm text-slate-500" data-i18n="releases.description">Mỗi gói là một ảnh chụp bất biến; xem trước hoặc tải lại gói cũ để phục hồi nội dung đã phát hành.</p>
        <?php if ($notice === 'revoked'): ?>
            <p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-i18n="releases.sync_revoked_notice">Đã khôi phục dữ liệu trên endpoint và đánh dấu bản ghi là đã gỡ.</p>
        <?php elseif ($notice === 'already_revoked'): ?>
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800" role="status" data-i18n="releases.sync_already_revoked">Bản ghi đồng bộ không tồn tại hoặc đã được gỡ.</p>
        <?php elseif ($notice === 'password_invalid'): ?>
            <p class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800" role="alert" data-i18n="releases.password_invalid">Mật khẩu không chính xác</p>
        <?php elseif ($notice === 'rollback_failed'): ?>
            <p class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800" role="alert" data-i18n="<?= ($_GET['remote_restored'] ?? '') === '1' ? 'releases.rollback_record_failed' : 'releases.rollback_failed' ?>">
                <?= ($_GET['remote_restored'] ?? '') === '1'
                    ? 'Endpoint đã được khôi phục nhưng TMS chưa thể cập nhật lịch sử. Hãy kiểm tra endpoint và lịch sử trước khi thử lại.'
                    : 'Không thể khôi phục endpoint; lịch sử vẫn đang hoạt động. Kiểm tra URL, phương thức, token và thử lại.' ?>
            </p>
        <?php endif; ?>

        <nav class="mt-6 flex flex-wrap gap-2 border-b" aria-label="Release history tabs">
            <a class="border-b-2 px-4 py-2 text-sm font-semibold <?= $tab === 'version_lock' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>"
               href="release_history.php?project_id=<?= (int)$projectId ?>&amp;tab=version_lock"
               <?= $tab === 'version_lock' ? 'aria-current="page"' : '' ?>
               data-i18n="releases.version_lock_tab">Lịch sử chốt phiên bản</a>
            <a class="border-b-2 px-4 py-2 text-sm font-semibold <?= $tab === 'api_sync' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>"
               href="release_history.php?project_id=<?= (int)$projectId ?>&amp;tab=api_sync"
               <?= $tab === 'api_sync' ? 'aria-current="page"' : '' ?>
               data-i18n="releases.api_sync_tab">Lịch sử đồng bộ API</a>
        </nav>

        <?php if ($tab === 'version_lock'): ?>
            <h2 class="mt-5 text-lg font-semibold" data-i18n="releases.version_lock_heading">Các phiên bản đã chốt</h2>
            <?php if ($locks): ?>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-xs uppercase text-slate-500">
                            <th class="px-3 py-3" data-i18n="releases.version">Phiên bản</th>
                            <th class="px-3 py-3" data-i18n="releases.record_count">Số lượng câu</th>
                            <th class="px-3 py-3" data-i18n="releases.actor">Người thực hiện</th>
                            <th class="px-3 py-3" data-i18n="releases.created">Thời gian</th>
                        </tr></thead>
                        <tbody class="divide-y">
                        <?php foreach ($locks as $lock): ?>
                            <tr>
                                <td class="px-3 py-3 font-semibold"><?= $escape((string)$lock['version_number']) ?></td>
                                <td class="px-3 py-3"><?= number_format((int)$lock['record_count']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$lock['actor_username']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$lock['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="mt-3 rounded-lg bg-slate-50 p-4 text-sm text-slate-600" data-i18n="releases.lock_empty">Chưa có phiên bản nào được chốt.</p>
            <?php endif; ?>

            <h2 class="mt-8 text-lg font-semibold" data-i18n="releases.packages_heading">Các gói phát hành</h2>
            <?php if ($releases): ?>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-xs uppercase text-slate-500">
                            <th class="px-3 py-3" data-i18n="releases.version">Phiên bản</th>
                            <th class="px-3 py-3" data-i18n="releases.locale">Ngôn ngữ</th>
                            <th class="px-3 py-3" data-i18n="releases.created">Thời gian</th>
                            <th class="px-3 py-3" data-i18n="releases.status">Trạng thái</th>
                            <th class="px-3 py-3" data-i18n="releases.package">Gói</th>
                        </tr></thead>
                        <tbody class="divide-y">
                        <?php foreach ($releases as $release): ?>
                            <tr>
                                <td class="px-3 py-3 font-semibold"><?= $escape((string)$release['version_number']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$release['locale']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$release['created_at']) ?></td>
                                <td class="px-3 py-3" data-i18n="<?= $release['frozen_at'] !== null ? 'releases.frozen' : 'releases.snapshot' ?>"><?= $release['frozen_at'] !== null ? 'Đã chốt' : 'Ảnh chụp bất biến' ?></td>
                                <td class="space-x-3 px-3 py-3">
                                    <a class="font-semibold text-indigo-700" href="release_view.php?id=<?= (int)$release['id'] ?>" data-i18n="releases.preview">Xem trước</a>
                                    <?php if (ProjectAccess::canManageRelease($connection, (int)$projectId, (int)$user['id'])): ?>
                                        <a class="font-semibold text-indigo-700" href="release_view.php?id=<?= (int)$release['id'] ?>&amp;download=1" data-i18n="releases.download_json">Tải JSON</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="mt-3 rounded-lg bg-slate-50 p-4 text-sm text-slate-600" data-i18n="releases.empty">Chưa có gói phát hành nào.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" data-i18n="releases.revoke_scope">Gỡ sẽ gửi dữ liệu khôi phục tới endpoint bằng PUT/PATCH rồi mới đánh dấu lịch sử là đã gỡ. Token không được lưu và cần nhập lại.</p>
            <?php if ($syncs): ?>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b text-xs uppercase text-slate-500">
                            <th class="px-3 py-3" data-i18n="releases.version">Phiên bản</th>
                            <th class="px-3 py-3" data-i18n="releases.locale">Ngôn ngữ</th>
                            <th class="px-3 py-3" data-i18n="releases.endpoint">Endpoint đích</th>
                            <th class="px-3 py-3" data-i18n="releases.record_count">Số lượng bản ghi</th>
                            <th class="px-3 py-3" data-i18n="releases.actor">Người thực hiện</th>
                            <th class="px-3 py-3" data-i18n="releases.created">Thời gian</th>
                            <th class="px-3 py-3" data-i18n="releases.status">Trạng thái</th>
                            <th class="px-3 py-3" data-i18n="releases.actions">Thao tác</th>
                        </tr></thead>
                        <tbody class="divide-y">
                        <?php foreach ($syncs as $sync): ?>
                            <tr>
                                <td class="px-3 py-3 font-semibold"><?= $escape((string)$sync['version_number']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$sync['locale']) ?></td>
                                <td class="max-w-xs break-all px-3 py-3"><?= $escape((string)$sync['endpoint_url']) ?></td>
                                <td class="px-3 py-3"><?= number_format((int)$sync['record_count']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$sync['actor_username']) ?></td>
                                <td class="px-3 py-3"><?= $escape((string)$sync['created_at']) ?></td>
                                <td class="px-3 py-3">
                                    <?php if ($sync['status'] === 'revoked'): ?>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600" data-i18n="releases.sync_revoked">Đã gỡ</span>
                                        <span class="mt-1 block text-xs text-slate-500"><?= $escape((string)$sync['revoked_at']) ?> · <?= $escape((string)$sync['revoker_username']) ?></span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700" data-i18n="releases.sync_active">Đang hoạt động</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3">
                                    <?php if ($canReview && $sync['status'] === 'active'): ?>
                                        <?php if ($sync['can_rollback'] === 't' && $sync['is_latest_active'] === 't'): ?>
                                        <form method="post" action="release_history.php?project_id=<?= (int)$projectId ?>&amp;tab=api_sync">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="revoke_api_sync">
                                            <input type="hidden" name="history_id" value="<?= (int)$sync['id'] ?>">
                                            <label class="mb-1 block text-xs text-slate-500" data-i18n="releases.rollback_url">Endpoint URL (nhập lại query credentials nếu có)</label>
                                            <input class="mb-2 w-64 max-w-full rounded-md border border-slate-300 px-2 py-1 text-xs" type="url" name="rollback_url" value="<?= $escape((string)$sync['endpoint_url']) ?>" required>
                                            <label class="mb-1 block text-xs text-slate-500" data-i18n="releases.rollback_method">Phương thức khôi phục</label>
                                            <select class="mb-2 rounded-md border border-slate-300 px-2 py-1 text-xs" name="rollback_method">
                                                <option value="PUT">PUT</option>
                                                <option value="PATCH">PATCH</option>
                                            </select>
                                            <label class="mb-1 block text-xs text-slate-500" data-i18n="releases.confirm_password">Mật khẩu xác nhận</label>
                                            <input class="mb-2 w-64 max-w-full rounded-md border border-slate-300 px-2 py-1 text-xs" type="password" name="password" autocomplete="current-password" required>
                                            <button class="rounded-md border border-rose-200 px-3 py-1.5 font-semibold text-rose-700 hover:bg-rose-50" type="submit" data-i18n="releases.revoke">Gỡ</button>
                                        </form>
                                        <?php elseif ($sync['can_rollback'] !== 't'): ?>
                                            <span class="text-xs text-slate-500" data-i18n="releases.rollback_snapshot_missing">Không có snapshot để khôi phục</span>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500" data-i18n="releases.rollback_newer_first">Gỡ các lần đồng bộ mới hơn trước</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600" data-i18n="releases.sync_empty">Chưa có lịch sử đồng bộ API.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<script src="tms-i18n.js" defer></script>
</body>
</html>
