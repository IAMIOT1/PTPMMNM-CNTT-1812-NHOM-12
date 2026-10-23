<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';

if (($_GET['action'] ?? '') === 'logout') {
    AuthManager::destroySession();
    header('Location: index.php?action=login', true, 303);
    exit;
}

if (($_GET['action'] ?? '') === 'login') {
    require __DIR__ . '/login.php';
    exit;
}

require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';
require_once __DIR__ . '/src/JsonParser.php';
require_once __DIR__ . '/src/TranslationStats.php';
require_once __DIR__ . '/src/ReleaseExporter.php';
require_once __DIR__ . '/src/TranslationRepository.php';
require_once __DIR__ . '/src/TranslationMemory.php';
require_once __DIR__ . '/src/AiTranslator.php';
require_once __DIR__ . '/src/GlossaryManager.php';
require_once __DIR__ . '/src/AuditLogger.php';
require_once __DIR__ . '/src/VariableValidator.php';
require_once __DIR__ . '/src/NotificationManager.php';
require_once __DIR__ . '/src/TranslationPackageExporter.php';

$conn = db();
$auth = new AuthManager($conn);

$currentUser = $auth->getCurrentUser();
$isAuthenticated = $currentUser !== null;
$isAdmin = $isAuthenticated && ProjectAccess::isSystemAdmin($currentUser);
$csrfToken = $isAuthenticated ? Csrf::token() : null;

if (!$isAuthenticated) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/index.php'));
}
$notificationManager = new NotificationManager($conn);
$notifications = $notificationManager->recentForUser((int)$currentUser['id']);
$unreadNotificationCount = $notificationManager->unreadCount((int)$currentUser['id']);

$successMessage = '';
$errorMessage = '';
if (($_GET['created'] ?? '') === '1') {
    $successMessage = 'Tạo dự án mới thành công. Bạn là Team Lead của dự án.';
}

$currentLocale = $_GET['locale'] ?? 'vi';

$projects = [];
$projectsRes = queryParams(
    $conn,
    'SELECT DISTINCT p.id, p.name, p.description
     FROM projects p
     LEFT JOIN project_members pm ON pm.project_id = p.id
     WHERE p.created_by = $1 OR pm.user_id = $1
     ORDER BY p.name, p.id',
    [(int)$currentUser['id']]
);
while ($project = pg_fetch_assoc($projectsRes)) {
    $projects[] = $project;
}

if (!defined('TMS_PROJECT_DETAIL')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_project') {
        Csrf::verify($_POST);
        $projectName = trim((string)($_POST['project_name'] ?? ''));
        $projectDescription = trim((string)($_POST['description'] ?? ''));
        $projectNameLength = preg_match_all('/./us', $projectName);
        $descriptionLength = preg_match_all('/./us', $projectDescription);

        if ($projectNameLength === false || $projectNameLength === 0 || $projectNameLength > 150) {
            $errorMessage = 'Tên dự án là bắt buộc và không được dài quá 150 ký tự.';
        } elseif ($descriptionLength === false || $descriptionLength > 5000) {
            $errorMessage = 'Mô tả dự án không được dài quá 5000 ký tự.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                throw new RuntimeException('PROJECT_CREATE_TRANSACTION_FAILED');
            }
            try {
                $createdProjectRes = queryParams(
                    $conn,
                    'INSERT INTO projects (name, description, created_by) VALUES ($1, $2, $3) RETURNING id',
                    [
                        $projectName,
                        $projectDescription !== '' ? $projectDescription : null,
                        (int)$currentUser['id']
                    ]
                );
                $newProjectId = (int)pg_fetch_result($createdProjectRes, 0, 'id');
                queryParams(
                    $conn,
                    "INSERT INTO project_members (project_id, user_id, role_in_project)
                     VALUES ($1, $2, 'Team Lead')",
                    [$newProjectId, (int)$currentUser['id']]
                );
                if (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('PROJECT_CREATE_COMMIT_FAILED');
                }
            } catch (Throwable $e) {
                if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                    pg_query($conn, 'ROLLBACK');
                }
                throw $e;
            }
            header('Location: project_detail.php?id=' . $newProjectId . '&created=1', true, 303);
            exit;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dự án của tôi - Enterprise TMS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="tms.css">
    </head>
    <body class="bg-gray-50 text-gray-800 font-sans">
    <main class="tms-shell max-w-7xl mx-auto px-4 py-8">
        <header class="tms-topbar flex flex-col md:flex-row justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100 gap-4">
            <div>
                <a href="index.php" class="text-2xl font-bold text-indigo-600">🌍 Enterprise TMS</a>
                <p class="text-sm text-gray-500 mt-1">Không gian làm việc dự án dịch thuật của bạn</p>
            </div>
            <div class="flex items-center gap-3">
                <?php require __DIR__ . '/templates/tms-header.php'; ?>
            </div>
        </header>

        <?php if ($successMessage !== ''): ?>
            <div class="tms-notice mb-6 bg-green-50 border-l-4 border-green-400 p-4 text-green-800">
                <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="tms-notice mb-6 bg-red-50 border-l-4 border-red-400 p-4 text-red-800">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="tms-accent-indigo bg-white p-6 md:p-8 rounded-xl shadow-sm border border-gray-100">
            <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Workspace</p>
                    <h1 class="text-2xl font-bold text-slate-900 mt-1">Dự án của tôi</h1>
                    <p class="text-sm text-slate-500 mt-1">Các dự án bạn đã tạo hoặc được thêm làm thành viên.</p>
                </div>
                <button type="button" class="tms-button-primary text-white px-4 py-2.5 rounded-lg text-sm font-semibold" data-open-project-modal>
                    + Tạo dự án mới
                </button>
            </div>

            <?php if ($projects): ?>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($projects as $project): ?>
                        <a class="tms-project-link rounded-xl border border-slate-200 p-5 hover:border-indigo-400 hover:bg-indigo-50"
                           href="project_detail.php?id=<?= (int)$project['id'] ?>">
                            <span class="flex items-start justify-between gap-3">
                                <span class="grid h-11 w-11 place-items-center rounded-xl bg-indigo-100 text-lg font-bold text-indigo-700" aria-hidden="true">
                                    <?= htmlspecialchars(strtoupper(substr((string)$project['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">ID: <?= (int)$project['id'] ?></span>
                            </span>
                            <span class="mt-4 block text-base font-bold text-slate-900"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="mt-1 block min-h-10 text-sm text-slate-500"><?= htmlspecialchars((string)($project['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Chưa có mô tả dự án.' ?></span>
                            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-indigo-600">Mở workspace <span aria-hidden="true">→</span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rounded-xl border border-dashed border-indigo-200 bg-indigo-50/60 px-6 py-10 text-center">
                    <p class="text-base font-semibold text-slate-800">Bạn chưa có dự án nào.</p>
                    <p class="mt-1 text-sm text-slate-500">Tạo dự án mới hoặc nhờ Team Lead thêm bạn vào dự án qua User ID.</p>
                    <button type="button" class="tms-button-primary mt-5 text-white px-4 py-2 rounded-lg text-sm font-semibold" data-open-project-modal>
                        Tạo dự án đầu tiên
                    </button>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <dialog class="tms-modal" id="create-project-modal" aria-labelledby="create-project-title">
        <form method="post" action="index.php" class="tms-modal-card">
            <input type="hidden" name="action" value="create_project">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">New project</p>
                    <h2 id="create-project-title" class="mt-1 text-xl font-bold text-slate-900">Tạo dự án mới</h2>
                    <p class="mt-1 text-sm text-slate-500">Bạn sẽ tự động trở thành Team Lead của dự án.</p>
                </div>
                <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-project-modal>×</button>
            </div>
            <label class="mt-5 block text-sm font-semibold text-slate-700" for="project_name">Tên dự án</label>
            <input id="project_name" name="project_name" maxlength="150" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm" placeholder="Ví dụ: Ứng dụng di động">
            <label class="mt-4 block text-sm font-semibold text-slate-700" for="project_description">Mô tả</label>
            <textarea id="project_description" name="description" maxlength="5000" rows="3" class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm" placeholder="Mục tiêu hoặc ngữ cảnh dự án"></textarea>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="tms-modal-cancel" data-close-project-modal>Hủy</button>
                <button type="submit" class="tms-button-primary text-white px-5 py-2 rounded-lg text-sm font-semibold">Tạo dự án</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const modal = document.getElementById('create-project-modal');
            document.querySelectorAll('[data-open-project-modal]').forEach((button) => {
                button.addEventListener('click', () => modal.showModal());
            });
            document.querySelectorAll('[data-close-project-modal]').forEach((button) => {
                button.addEventListener('click', () => modal.close());
            });
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.close();
            });
            <?php if ($errorMessage !== ''): ?>
            modal.showModal();
            <?php endif; ?>
        })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

$requestedProjectId = isset($_GET['project_id'])
    ? max(1, (int)$_GET['project_id'])
    : ($projects ? (int)$projects[0]['id'] : null);
if ($requestedProjectId !== null && !in_array(
    $requestedProjectId,
    array_map(static fn(array $project): int => (int)$project['id'], $projects),
    true
)) {
    $requestedProjectId = $projects ? (int)$projects[0]['id'] : null;
}
$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : null;
if ($versionId !== null) {
    $versionAccessRes = queryParams(
        $conn,
        'SELECT sv.id
         FROM source_versions sv
         JOIN projects p ON p.id = sv.project_id
         WHERE sv.id = $1
           AND sv.project_id = $3
           AND (p.created_by = $2 OR EXISTS (
               SELECT 1 FROM project_members pm
               WHERE pm.project_id = p.id AND pm.user_id = $2
           ))',
        [$versionId, (int)$currentUser['id'], $requestedProjectId]
    );
    if (!pg_fetch_assoc($versionAccessRes)) {
        $versionId = null;
    }
}
if ($versionId === null && $requestedProjectId !== null) {
    $versionRes = queryParams(
        $conn,
        'SELECT v.id
         FROM source_versions v
         WHERE v.project_id = $1
           AND EXISTS (SELECT 1 FROM strings s WHERE s.source_version_id = v.id)
         ORDER BY v.id DESC LIMIT 1',
        [$requestedProjectId]
    );
    $versionRow = pg_fetch_assoc($versionRes);
    if ($versionRow) {
        $versionId = (int)$versionRow['id'];
    } else {
        $versionRes = queryParams(
            $conn,
            'SELECT id FROM source_versions WHERE project_id = $1 ORDER BY id DESC LIMIT 1',
            [$requestedProjectId]
        );
        $versionRow = pg_fetch_assoc($versionRes);
        $versionId = $versionRow ? (int)$versionRow['id'] : 0;
    }
} elseif ($versionId === null) {
    $versionId = 0;
}

$versionRes = queryParams($conn, "SELECT id, version_number, project_id FROM source_versions WHERE id = $1 LIMIT 1", [$versionId]);
$currentVersion = pg_fetch_assoc($versionRes);
$projectId = $currentVersion
    ? (int)$currentVersion['project_id']
    : ($requestedProjectId ?? 0);
if (!$currentVersion && $requestedProjectId !== null) {
    $projectId = $requestedProjectId;
}
$hasSourceStrings = false;
if ($currentVersion) {
    $sourceCountRes = queryParams(
        $conn,
        'SELECT EXISTS (
             SELECT 1 FROM strings
             WHERE source_version_id = $1 AND project_id = $2
         )',
        [(int)$currentVersion['id'], $projectId]
    );
    $hasSourceStrings = pg_fetch_result($sourceCountRes, 0, 0) === 't';
}
$projectRes = queryParams($conn, 'SELECT id, name, created_by FROM projects WHERE id = $1', [$projectId]);
$projectRow = pg_fetch_assoc($projectRes);
$projectExists = $projectRow !== false;
$projectName = $projectRow ? (string)$projectRow['name'] : '';
$currentProjectRole = $isAuthenticated && $projectExists
    ? ProjectAccess::roleFor($conn, $projectId, (int)$currentUser['id'])
    : null;
$canManageCurrentProject = $isAuthenticated && $projectExists &&
    ProjectAccess::canManageMembers($conn, $projectId, $currentUser);
$canTranslateCurrentProject = $isAuthenticated && $currentVersion &&
    ProjectAccess::canTranslate($conn, $projectId, $currentUser);
$canReviewCurrentProject = $isAuthenticated && $currentVersion &&
    ProjectAccess::canReview($conn, $projectId, $currentUser);
$versionQuery = $currentVersion
    ? 'version_id=' . (int)$versionId
    : 'project_id=' . $projectId;
$workspaceAction = 'project_detail.php?id=' . $projectId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAuthenticated) {
        $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/index.php'));
    }
    Csrf::verify($_POST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $auditLogger = new AuditLogger($conn);

    if ($action === 'create_project') {
        $projectName = trim((string)($_POST['project_name'] ?? ''));
        $projectDescription = trim((string)($_POST['description'] ?? ''));
        $projectNameLength = preg_match_all('/./us', $projectName);
        $descriptionLength = preg_match_all('/./us', $projectDescription);
        if ($projectNameLength === false || $projectNameLength === 0 || $projectNameLength > 150) {
            $errorMessage = 'Tên dự án là bắt buộc và không được dài quá 150 ký tự.';
        } elseif ($descriptionLength === false || $descriptionLength > 5000) {
            $errorMessage = 'Mô tả dự án không được dài quá 5000 ký tự.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                throw new RuntimeException('PROJECT_CREATE_TRANSACTION_FAILED');
            }
            try {
                $projectRes = queryParams(
                    $conn,
                    'INSERT INTO projects (name, description, created_by) VALUES ($1, $2, $3) RETURNING id',
                    [$projectName, $projectDescription !== '' ? $projectDescription : null, (int)$currentUser['id']]
                );
                $newProjectId = (int)pg_fetch_result($projectRes, 0, 'id');
                queryParams(
                    $conn,
                    "INSERT INTO project_members (project_id, user_id, role_in_project)
                     VALUES ($1, $2, 'Team Lead')",
                    [$newProjectId, (int)$currentUser['id']]
                );
                if (pg_query($conn, 'COMMIT') === false) {
                    throw new RuntimeException('PROJECT_CREATE_COMMIT_FAILED');
                }
            } catch (Throwable $e) {
                if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                    pg_query($conn, 'ROLLBACK');
                }
                throw $e;
            }

            header(
                'Location: index.php?project_id=' . $newProjectId . '&created=1',
                true,
                303
            );
            exit;
        }
    } elseif ($action === 'add_project_member') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $memberRole = (string)($_POST['role_in_project'] ?? '');
        if (!in_array($memberRole, ['Team Lead', 'Translator', 'Reviewer'], true)) {
            $errorMessage = 'Vai trò trong dự án không hợp lệ.';
        } elseif (
            $targetProjectId !== $projectId ||
            ProjectAccess::roleFor($conn, $targetProjectId, (int)$currentUser['id']) !== 'Team Lead'
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Team Lead của dự án hiện tại mới được quản lý thành viên.';
        } else {
            if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('PROJECT_MEMBER_UPDATE_TRANSACTION_FAILED');
            }
            try {
                    $memberRes = queryParams(
                        $conn,
                        'INSERT INTO project_members (project_id, user_id, role_in_project)
                         SELECT p.id, u.id, $3
                         FROM projects p CROSS JOIN users u
                         WHERE p.id = $1 AND u.id = $2
                         ON CONFLICT (project_id, user_id)
                         DO UPDATE SET role_in_project = EXCLUDED.role_in_project
                         WHERE project_members.role_in_project IS DISTINCT FROM EXCLUDED.role_in_project
                         RETURNING user_id',
                        [$targetProjectId, $memberUserId, $memberRole]
                    );
                    $memberChanged = pg_fetch_assoc($memberRes) !== false;
                    if ($memberChanged) {
                        $projectNameRes = queryParams($conn, 'SELECT name FROM projects WHERE id = $1', [$targetProjectId]);
                        $projectName = (string)pg_fetch_result($projectNameRes, 0, 'name');
                        NotificationManager::create(
                            $conn,
                            $memberUserId,
                            "Bạn đã được thêm vào dự án {$projectName} với vai trò {$memberRole}."
                        );
                    }
                    if (pg_query($conn, 'COMMIT') === false) {
                        throw new RuntimeException('PROJECT_MEMBER_UPDATE_COMMIT_FAILED');
                    }
            } catch (Throwable $e) {
                    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                        pg_query($conn, 'ROLLBACK');
                    }
                    throw $e;
            }
            if ($memberChanged) {
                    $successMessage = 'Đã thêm thành viên hoặc cập nhật vai trò, đồng thời gửi thông báo.';
            } else {
                    $existingMemberRes = queryParams(
                        $conn,
                        'SELECT 1 FROM project_members WHERE project_id = $1 AND user_id = $2',
                        [$targetProjectId, $memberUserId]
                    );
                    if (pg_fetch_assoc($existingMemberRes)) {
                        $successMessage = 'Thành viên đã có đúng vai trò này.';
                    } else {
                        $errorMessage = 'Không tìm thấy dự án hoặc User ID.';
                    }
            }
        }
    } elseif ($action === 'update_project_member_role') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        $memberRole = (string)($_POST['role_in_project'] ?? '');
        if (!in_array($memberRole, ['Translator', 'Reviewer'], true)) {
            $errorMessage = 'Vai trò mới không hợp lệ.';
        } elseif (
            $targetProjectId !== $projectId ||
            ProjectAccess::roleFor($conn, $targetProjectId, (int)$currentUser['id']) !== 'Team Lead'
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Team Lead của dự án hiện tại mới được quản lý thành viên.';
        } else {
            $updateMemberRes = queryParams(
                $conn,
                'UPDATE project_members pm
                 SET role_in_project = $3
                 FROM projects p
                 WHERE pm.project_id = $1
                   AND pm.user_id = $2
                   AND p.id = pm.project_id
                   AND p.created_by IS DISTINCT FROM pm.user_id
                   AND pm.role_in_project IS DISTINCT FROM $3
                 RETURNING pm.user_id',
                [$targetProjectId, $memberUserId, $memberRole]
            );
            if (pg_fetch_assoc($updateMemberRes)) {
                $successMessage = 'Đã cập nhật vai trò thành viên.';
            } else {
                $memberExistsRes = queryParams(
                    $conn,
                    'SELECT 1 FROM project_members WHERE project_id = $1 AND user_id = $2',
                    [$targetProjectId, $memberUserId]
                );
                $errorMessage = pg_fetch_assoc($memberExistsRes)
                    ? 'Không thể sửa vai trò của chủ dự án hoặc vai trò không thay đổi.'
                    : 'Không tìm thấy thành viên trong dự án.';
            }
        }
    } elseif ($action === 'remove_project_member') {
        $targetProjectId = (int)($_POST['project_id'] ?? 0);
        $memberUserId = (int)($_POST['user_id'] ?? 0);
        if (
            $targetProjectId !== $projectId ||
            ProjectAccess::roleFor($conn, $targetProjectId, (int)$currentUser['id']) !== 'Team Lead'
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Team Lead của dự án hiện tại mới được quản lý thành viên.';
        } elseif ($memberUserId < 1) {
            $errorMessage = 'User ID thành viên không hợp lệ.';
        } else {
            $removeMemberRes = queryParams(
                $conn,
                'DELETE FROM project_members pm
                 USING projects p
                 WHERE pm.project_id = $1
                   AND pm.user_id = $2
                   AND p.id = pm.project_id
                   AND p.created_by IS DISTINCT FROM pm.user_id
                 RETURNING pm.user_id',
                [$targetProjectId, $memberUserId]
            );
            if (pg_fetch_assoc($removeMemberRes)) {
                $successMessage = 'Đã xóa thành viên khỏi dự án.';
            } else {
                $errorMessage = 'Không tìm thấy thành viên hoặc không thể xóa chủ dự án.';
            }
        }
    } elseif ($action === 'save_translation') {
        $stringId = (int)($_POST['string_id'] ?? 0);
        $translatedText = (string)($_POST['translated_text'] ?? '');
        $status = (string)($_POST['status'] ?? 'draft');
        $expectedUpdatedAt = (string)($_POST['translation_updated_at'] ?? '');

        if (!in_array($status, ['draft', 'approved', 'rejected', 'needs_review'], true)) {
            $errorMessage = 'Trạng thái bản dịch không hợp lệ.';
        } else {
            $sourceRes = queryParams(
                $conn,
                'SELECT project_id, source_text FROM strings
                 WHERE id = $1 AND source_version_id = $2',
                [$stringId, $versionId]
            );
            $sourceRow = pg_fetch_assoc($sourceRes);

            if (!$sourceRow) {
                $errorMessage = 'Không tìm thấy chuỗi nguồn để lưu bản dịch.';
            } elseif (!ProjectAccess::canTranslate($conn, (int)$sourceRow['project_id'], $currentUser)) {
                http_response_code(403);
                $errorMessage = 'Bạn chưa là thành viên của dự án này.';
            } elseif (!VariableValidator::validate($sourceRow['source_text'], $translatedText)) {
                $errorMessage = 'Bản dịch phải giữ nguyên các biến trong chuỗi nguồn (ví dụ: {name}).';
            } elseif (
                !in_array($status, ['draft'], true) &&
                !ProjectAccess::canReview($conn, (int)$sourceRow['project_id'], $currentUser)
            ) {
                http_response_code(403);
                $errorMessage = 'Chỉ Reviewer hoặc Team Lead của dự án mới được duyệt/thay đổi trạng thái review.';
            } else {
                $priorTranslationRes = queryParams(
                    $conn,
                    'SELECT id, user_id, status FROM translations WHERE string_id = $1 AND locale = $2',
                    [$stringId, $currentLocale]
                );
                $priorTranslation = pg_fetch_assoc($priorTranslationRes);
                if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('TRANSLATION_SAVE_TRANSACTION_FAILED');
                }
                try {
                    queryParams(
                        $conn,
                        "SELECT set_config('tms.user_id', $1, TRUE)",
                        [(string)$currentUser['id']]
                    );
                $saveRes = queryParams(
                    $conn,
                    "INSERT INTO translations (string_id, locale, translated_text, status, updated_at, user_id)
                     VALUES ($1, $2, $3, $4, clock_timestamp(), $6)
                     ON CONFLICT (string_id, locale)
                     DO UPDATE SET translated_text = EXCLUDED.translated_text,
                                   status = EXCLUDED.status,
                                   updated_at = clock_timestamp(),
                                   user_id = EXCLUDED.user_id
                     WHERE translations.updated_at = NULLIF($5, '')::timestamptz
                     RETURNING id",
                    [$stringId, $currentLocale, $translatedText, $status, $expectedUpdatedAt, $currentUser['id']]
                );
                $savedTranslation = pg_fetch_assoc($saveRes);
                    if (pg_query($conn, 'COMMIT') === false) {
                        throw new RuntimeException('TRANSLATION_SAVE_COMMIT_FAILED');
                    }
                } catch (Throwable $e) {
                    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                        pg_query($conn, 'ROLLBACK');
                    }
                    throw $e;
                }
                if ($savedTranslation) {
                    $tm = new TranslationMemory($conn);
                    $tm->saveToMemory((int)$sourceRow['project_id'], $currentLocale, $sourceRow['source_text'], $translatedText);

                    $auditLogger->log($projectId, $stringId, 'UPDATE_TRANSLATION', "User [{$currentUser['username']}] cập nhật bản dịch [{$currentLocale}]: \"{$translatedText}\"");
                    if (in_array($status, ['approved', 'rejected'], true)) {
                        queryParams(
                            $conn,
                            'INSERT INTO reviews (translation_id, reviewer_id, status, comment)
                             VALUES ($1, $2, $3, $4)',
                            [(int)$savedTranslation['id'], (int)$currentUser['id'], $status, 'Review từ giao diện TMS']
                        );
                    }
                    if (
                        in_array($status, ['approved', 'needs_review', 'rejected'], true) &&
                        $priorTranslation &&
                        $priorTranslation['user_id'] !== null &&
                        (int)$priorTranslation['user_id'] !== (int)$currentUser['id'] &&
                        $priorTranslation['status'] !== $status
                    ) {
                        $reviewMessage = $status === 'approved'
                            ? 'Bản dịch của bạn đã được duyệt.'
                            : 'Bản dịch của bạn cần được xem xét và chỉnh sửa.';
                        NotificationManager::create($conn, (int)$priorTranslation['user_id'], $reviewMessage);
                    }
                    $successMessage = "Đã lưu bản dịch thành công!";
                } else {
                    $errorMessage = 'Bản dịch đã được người khác cập nhật. Hãy tải lại trang và áp dụng thay đổi của bạn trên nội dung mới nhất.';
                }
            }
        }
    } 
    elseif ($action === 'add_glossary' && $currentVersion) {
        if (!$isAdmin) {
            $errorMessage = "Bạn không có quyền thực hiện hành động này!";
        } else {
            $term = trim($_POST['term'] ?? '');
            $translation = trim($_POST['translation'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (!empty($term) && !empty($translation)) {
                $glossaryManager = new GlossaryManager($conn);
                $glossaryManager->addTerm($projectId, $currentLocale, $term, $translation, $description);

                $auditLogger->log($projectId, null, 'ADD_GLOSSARY', "User [{$currentUser['username']}] thêm thuật ngữ [{$currentLocale}]: {$term} -> {$translation}");
                $successMessage = "Đã thêm thuật ngữ [{$term}] thành công!";
            } else {
                $errorMessage = "Vui lòng nhập đầy đủ thông tin thuật ngữ!";
            }
        }
    }
    elseif ($action === 'ai_translate' && $currentVersion) {
        if (!ProjectAccess::canTranslate($conn, $projectId, $currentUser)) {
            http_response_code(403);
            $errorMessage = 'Bạn chưa là thành viên của dự án này.';
        } else {
            $aiTranslator = new AiTranslator($conn);
            $count = $aiTranslator->autoTranslateMissing((int)$currentVersion['id'], $currentLocale, (int)$currentUser['id']);

            $auditLogger->log($projectId, null, 'AI_TRANSLATE', "User [{$currentUser['username']}] kích hoạt AI dịch {$count} chuỗi [{$currentLocale}]");
            $successMessage = "AI đã tự động dịch thành công {$count} chuỗi!";
        }
    }
    elseif ($action === 'export_release' && $currentVersion) {
        if (!$isAdmin) {
            $errorMessage = "Chỉ Admin mới có quyền xuất bản gói ngôn ngữ!";
        } else {
            $versionNumber = $currentVersion['version_number'];
            $outputPath = __DIR__ . "/releases/{$currentLocale}/{$versionNumber}.json";
            
            $exporter = new ReleaseExporter($conn);
            $exporter->generateRelease($projectId, $currentLocale, $versionNumber, $outputPath);

            $auditLogger->log($projectId, null, 'EXPORT_RELEASE', "User [{$currentUser['username']}] xuất bản gói [{$currentLocale}] v{$versionNumber}");
            $successMessage = "Đã xuất bản gói ngôn ngữ thành công!";
        }
    }
    elseif ($action === 'export_translation_package' && $currentVersion) {
            if (!$canReviewCurrentProject) {
                http_response_code(403);
                $errorMessage = 'Chỉ Team Lead hoặc Reviewer của dự án mới được xuất tệp dịch.';
            } else {
                $exporter = new TranslationPackageExporter($conn);
                $json = $exporter->export((int)$currentVersion['id'], $currentLocale);
                $safeLocale = preg_replace('/[^A-Za-z0-9_-]/', '', $currentLocale);
                $safeVersion = preg_replace('/[^A-Za-z0-9._-]/', '_', $currentVersion['version_number']);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="translation-' . $safeLocale . '-' . $safeVersion . '.json"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            }
        }
        elseif ($action === 'rollback_translation' && $currentVersion) {
            $historyId = (int)($_POST['history_id'] ?? 0);
            $translationId = (int)($_POST['translation_id'] ?? 0);
            $expectedUpdatedAt = (string)($_POST['translation_updated_at'] ?? '');
            $historyRes = queryParams(
                $conn,
                'SELECT h.old_text, s.project_id, t.string_id, t.locale, s.source_text, t.updated_at
                 FROM translation_history h
                 JOIN translations t ON t.id = h.translation_id
                 JOIN strings s ON s.id = t.string_id
                 WHERE h.id = $1 AND t.id = $2 AND t.locale = $3
                   AND s.source_version_id = $4',
                [$historyId, $translationId, $currentLocale, $versionId]
            );
            $historyRow = pg_fetch_assoc($historyRes);
            if (!$historyRow || !ProjectAccess::canReview($conn, (int)$historyRow['project_id'], $currentUser)) {
                http_response_code(403);
                $errorMessage = 'Không tìm thấy lịch sử hoặc bạn không có quyền hoàn tác.';
            } elseif (!VariableValidator::validate($historyRow['source_text'], (string)($historyRow['old_text'] ?? ''))) {
                $errorMessage = 'Không thể hoàn tác vì bản cũ không còn khớp placeholder với chuỗi nguồn.';
            } else {
                if (pg_query($conn, 'BEGIN') === false) {
                    throw new RuntimeException('TRANSLATION_ROLLBACK_TRANSACTION_FAILED');
                }
                try {
                    queryParams($conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$currentUser['id']]);
                    $rollbackRes = queryParams(
                        $conn,
                        "UPDATE translations
                         SET translated_text = $1, status = 'needs_review', updated_at = clock_timestamp()
                         WHERE id = $2 AND updated_at = NULLIF($3, '')::timestamptz
                         RETURNING id",
                        [$historyRow['old_text'], $translationId, $expectedUpdatedAt]
                    );
                    if (pg_query($conn, 'COMMIT') === false) {
                        throw new RuntimeException('TRANSLATION_ROLLBACK_COMMIT_FAILED');
                    }
                } catch (Throwable $e) {
                    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
                        pg_query($conn, 'ROLLBACK');
                    }
                    throw $e;
                }
                if (pg_fetch_assoc($rollbackRes)) {
                    $successMessage = 'Đã hoàn tác bản dịch về nội dung đã chọn và chuyển trạng thái sang cần xem xét.';
                } else {
                    $errorMessage = 'Bản dịch đã thay đổi trong lúc hoàn tác. Hãy tải lại lịch sử.';
                }
            }
        }
    elseif ($action === 'import_source') {
        if (
            !($isAdmin || ProjectAccess::roleFor($conn, $projectId, (int)$currentUser['id']) === 'Team Lead') ||
            (int)($_POST['project_id'] ?? 0) !== $projectId
        ) {
            http_response_code(403);
            $errorMessage = 'Chỉ Team Lead của dự án hiện tại mới được nhập tệp JSON nguồn.';
        } else {
            $versionNumber = trim($_POST['version_number'] ?? '');

            if (!empty($versionNumber) && isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['json_file']['tmp_name'];
                $jsonContent = file_get_contents($tmpName);
                $data = json_decode($jsonContent, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $importProjectId = $projectId;
                    $repository = new TranslationRepository($conn);
                    $versionId = $repository->importSourceVersion($importProjectId, $versionNumber, $data);

                    $auditLogger->log($importProjectId, null, 'IMPORT_SOURCE', "User [{$currentUser['username']}] import phiên bản [{$versionNumber}]");
                    $successMessage = "Import tệp JSON nguồn thành công!";
                    
                    $versionRes = queryParams($conn, "SELECT id, version_number, project_id FROM source_versions WHERE id = $1", [$versionId]);
                    $currentVersion = pg_fetch_assoc($versionRes);
                    $projectId = $currentVersion ? (int)$currentVersion['project_id'] : $importProjectId;
                } else {
                    $errorMessage = "Tệp JSON không hợp lệ!";
                }
            } else {
                $errorMessage = "Vui lòng chọn tệp JSON hợp lệ!";
            }
        }
    }
}

$stats = ['total' => 0, 'translated' => 0, 'approved' => 0, 'percentage' => 0.0];
$strings = [];
$glossaryTerms = [];
$auditLogs = [];

if ($currentVersion && $canTranslateCurrentProject) {
    $statsManager = new TranslationStats($conn);
    $stats = $statsManager->getProgress($projectId, $versionId, $currentLocale);

    $glossaryManager = new GlossaryManager($conn);
    $glossaryTerms = $glossaryManager->getTerms($projectId, $currentLocale);

    $auditLogger = new AuditLogger($conn);
    $auditLogs = $auditLogger->getLogs($projectId);

    $sqlStrings = "SELECT s.id AS string_id, s.key_path, s.source_text,
                          t.id AS translation_id,
                          COALESCE(t.translated_text, '') AS translated_text, 
                          COALESCE(t.status, 'draft') AS status,
                          COALESCE(t.updated_at::text, '') AS translation_updated_at
                   FROM strings s
                   LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
                   WHERE s.source_version_id = $2";
    $stringParams = [$currentLocale, $versionId];
    $statusFilter = (string)($_GET['status'] ?? '');
    if ($statusFilter === 'translated') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') IN ('draft', 'translated')";
    } elseif ($statusFilter === 'approved') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') = 'approved'";
    } elseif ($statusFilter === 'needs_review') {
        $sqlStrings .= " AND COALESCE(t.status, 'draft') IN ('needs_review', 'rejected')";
    }
    $searchQuery = trim((string)($_GET['q'] ?? ''));
    if ($searchQuery !== '') {
        $sqlStrings .= ' AND (s.key_path ILIKE $3 OR s.source_text ILIKE $3 OR COALESCE(t.translated_text, \'\') ILIKE $3)';
        $stringParams[] = '%' . $searchQuery . '%';
    }
    $sqlStrings .= ' ORDER BY s.key_path';
    $stringsRes = queryParams($conn, $sqlStrings, $stringParams);
    while ($row = pg_fetch_assoc($stringsRes)) {
        $strings[] = $row;
    }
}
$historyStringId = isset($_GET['history_string_id']) ? (int)$_GET['history_string_id'] : 0;
$translationHistory = [];
if ($currentVersion && $canReviewCurrentProject && $historyStringId > 0) {
    $historyRes = queryParams(
        $conn,
        'SELECT h.id, h.translation_id, h.old_text, h.new_text,
                h.changed_by_user_id, h.created_at, u.username, t.updated_at
         FROM translation_history h
         JOIN translations t ON t.id = h.translation_id
         JOIN strings s ON s.id = t.string_id
         LEFT JOIN users u ON u.id = h.changed_by_user_id
         WHERE s.id = $1 AND s.source_version_id = $2 AND t.locale = $3
         ORDER BY h.created_at DESC, h.id DESC',
        [$historyStringId, $versionId, $currentLocale]
    );
    while ($historyRow = pg_fetch_assoc($historyRes)) {
        $translationHistory[] = $historyRow;
    }
}
$canAccessMemberManagement = $projects !== [];
$projectMembers = [];
if ($currentProjectRole !== null) {
    $membersRes = queryParams(
        $conn,
        'SELECT u.id, u.username, u.email, pm.role_in_project,
                (p.created_by = u.id) AS is_project_owner
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         JOIN projects p ON p.id = pm.project_id
         WHERE pm.project_id = $1 ORDER BY u.username',
        [$projectId]
    );
    while ($row = pg_fetch_assoc($membersRes)) {
        $projectMembers[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise TMS - Full Featured</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
    <div class="tms-shell max-w-7xl mx-auto px-4 py-8">
        <header class="tms-topbar flex flex-col md:flex-row justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100 gap-4">
            <div>
                <h1 class="text-2xl font-bold text-indigo-600">🌍 Enterprise TMS & Full Suite</h1>
                <p class="text-sm text-gray-500 mt-1">Hệ thống quản lý dịch thuật cấp độ doanh nghiệp tích hợp TM, AI, Glossary & RBAC</p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <?php if (defined('TMS_PROJECT_DETAIL')): ?>
                    <a href="index.php" class="tms-filter-reset">← Dự án của tôi</a>
                <?php endif; ?>
                <?php require __DIR__ . '/templates/tms-header.php'; ?>

                <!-- Bộ lọc ngôn ngữ -->
                <div class="flex items-center gap-2 bg-gray-50 px-3 py-2 rounded-lg border border-gray-200">
                    <span class="text-xs font-semibold text-gray-500 uppercase">Ngôn ngữ:</span>
                    <select onchange="location = this.value;" class="bg-transparent text-sm font-bold text-indigo-600 focus:outline-none cursor-pointer">
                        <option value="<?= $workspaceAction ?>&amp;locale=vi&amp;<?= $versionQuery ?>" <?= $currentLocale === 'vi' ? 'selected' : '' ?>>🇻🇳 vi</option>
                            <option value="<?= $workspaceAction ?>&amp;locale=en&amp;<?= $versionQuery ?>" <?= $currentLocale === 'en' ? 'selected' : '' ?>>🇬🇧 en</option>
                            <option value="<?= $workspaceAction ?>&amp;locale=ja&amp;<?= $versionQuery ?>" <?= $currentLocale === 'ja' ? 'selected' : '' ?>>🇯🇵 ja</option>
                    </select>
                </div>

                <?php if ($currentVersion && $canTranslateCurrentProject): ?>
                    <form method="POST" class="inline" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                        <input type="hidden" name="action" value="ai_translate">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg font-medium text-xs shadow-sm transition-colors">
                            🤖 AI Translate
                        </button>
                    </form>

                <?php endif; ?>
            </div>
        </header>

        <?php if ($projectId > 0 && $currentVersion && $hasSourceStrings && $canTranslateCurrentProject): ?>
        <div class="row tms-statistics-row mb-4">
            <div class="col-md-3">
                <div class="tms-stat-card">
                    <p class="text-sm font-medium text-gray-500">Tổng số chuỗi gốc</p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?= $stats['total'] ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="tms-stat-card">
                    <p class="text-sm font-medium text-gray-500">Đã dịch (<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>)</p>
                    <p class="text-3xl font-bold text-blue-600 mt-2"><?= $stats['translated'] ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="tms-stat-card">
                    <p class="text-sm font-medium text-gray-500">Đã duyệt (Approved)</p>
                    <p class="text-3xl font-bold text-green-600 mt-2"><?= $stats['approved'] ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="tms-stat-card">
                    <p class="text-sm font-medium text-gray-500">Tỷ lệ hoàn thành</p>
                    <p class="text-3xl font-bold text-indigo-600 mt-2"><?= $stats['percentage'] ?>%</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="tms-dashboard-grid">
        <?php if (!defined('TMS_PROJECT_DETAIL')): ?>
        <section class="tms-main-panel tms-my-projects tms-accent-indigo mb-8 rounded-xl bg-white p-6 shadow-sm border border-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-lg font-semibold">Dự án của tôi</h2>
                    <p class="text-sm text-gray-500">Các dự án bạn đã tạo hoặc được thêm làm thành viên.</p>
                </div>
            </div>
            <?php if ($projects): ?>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($projects as $project): ?>
                        <a class="tms-project-link rounded-xl border border-gray-200 p-4 hover:border-indigo-400 hover:bg-indigo-50"
                           href="project_detail.php?id=<?= (int)$project['id'] ?>">
                            <span class="block font-semibold text-indigo-700"><?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="mt-1 block text-xs text-gray-500">Project ID: <?= (int)$project['id'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Bạn chưa có dự án nào. Hãy tạo dự án mới hoặc nhờ Team Lead thêm bạn vào dự án qua User ID.
                </p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- Thông báo -->
        <?php if (!empty($successMessage)): ?>
            <div class="tms-notice mb-6 bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                <p class="text-green-700 font-medium"><?= htmlspecialchars($successMessage) ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="tms-notice mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-md shadow-sm">
                <p class="text-red-700 font-medium"><?= htmlspecialchars($errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <div class="row tms-project-layout-row">
        <!-- Import Form (Chỉ Admin) -->
        <?php if (defined('TMS_PROJECT_DETAIL') && $isAuthenticated && ($isAdmin || $currentProjectRole === 'Team Lead')): ?>
        <div class="col-lg-4 tms-project-admin-column tms-import-column">
        <div class="tms-side-panel tms-import-panel tms-accent-violet mb-5 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">📥 Nhập tệp JSON nguồn mới</h2>
            <p class="mb-4 text-sm text-gray-500">Dự án: <?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID: <?= $projectId ?>)</p>
            <form method="POST" enctype="multipart/form-data" class="tms-import-form" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                <input type="hidden" name="action" value="import_source">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <div class="tms-import-version">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="import-version">Phiên bản</label>
                    <input id="import-version" type="text" name="version_number" placeholder="v1.0.1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="tms-import-file">
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="source-json-file">Tệp JSON nguồn</label>
                    <div class="tms-file-picker">
                        <input id="source-json-file" class="tms-file-input" type="file" name="json_file" accept=".json,application/json" required>
                        <label class="tms-file-button" for="source-json-file">Chọn tệp</label>
                        <span class="tms-file-name" data-file-name>Chưa có tệp nào được chọn</span>
                    </div>
                </div>
                <button type="submit" class="tms-button-primary tms-import-submit bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-lg font-semibold text-sm shadow-sm transition-colors">
                    Import
                </button>
            </form>
            <script>
                (() => {
                    const fileInput = document.getElementById('source-json-file');
                    const fileName = document.querySelector('[data-file-name]');
                    fileInput.addEventListener('change', () => {
                        fileName.textContent = fileInput.files.length
                            ? fileInput.files[0].name
                            : 'Chưa có tệp nào được chọn';
                        fileName.title = fileName.textContent;
                    });
                })();
            </script>
        </div>
        </div>
        <?php endif; ?>

        <?php if ($isAuthenticated && !defined('TMS_PROJECT_DETAIL')): ?>
        <section class="tms-side-panel tms-create-project tms-accent-indigo tms-highlight mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-lg font-semibold text-gray-800 mb-2">Tạo dự án mới</h2>
            <p class="text-sm text-gray-500 mb-4">Người tạo dự án sẽ tự động được gán vai trò Team Lead.</p>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="create_project">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="grid gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="project_name">Tên dự án</label>
                        <input id="project_name" name="project_name" maxlength="150" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1" for="project_description">Mô tả</label>
                        <textarea id="project_description" name="description" maxlength="5000" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></textarea>
                    </div>
                </div>
                <button type="submit" class="tms-button-primary mt-4 bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-semibold text-sm">Tạo dự án</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if (defined('TMS_PROJECT_DETAIL') && $currentProjectRole !== null): ?>
        <div class="col-lg-8 tms-project-member-column">
        <section class="tms-main-panel tms-members-panel tms-accent-emerald mb-5 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-800 mb-1">Thành viên dự án</h2>
                    <p class="text-sm text-gray-500">Dự án: <?= htmlspecialchars((string)($projectName ?? ''), ENT_QUOTES, 'UTF-8') ?> (ID: <?= $projectId ?>) — <span class="tms-role-badge <?= $currentProjectRole === 'Team Lead' ? 'tms-role-lead' : ($currentProjectRole === 'Translator' ? 'tms-role-translator' : 'tms-role-reviewer') ?>"><?= htmlspecialchars($currentProjectRole, ENT_QUOTES, 'UTF-8') ?></span></p>
                </div>
                <?php if ($currentProjectRole === 'Team Lead'): ?>
                    <button type="button" class="tms-button-primary px-4 py-2 text-sm font-semibold text-white" data-open-member-modal>
                        + Thêm thành viên
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($currentProjectRole === 'Team Lead'): ?>
            <dialog class="tms-modal" id="add-member-modal" aria-labelledby="add-member-title">
                <form method="POST" class="tms-modal-card" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="add_project_member">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Project team</p>
                            <h3 id="add-member-title" class="mt-1 text-xl font-bold text-slate-900">Thêm thành viên</h3>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-member-modal>×</button>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700" for="member_user_id">User ID</label>
                    <input id="member_user_id" type="number" name="user_id" min="1" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                    <label class="mt-4 block text-sm font-semibold text-slate-700" for="role_in_project">Vai trò</label>
                    <select id="role_in_project" name="role_in_project" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                        <option value="Translator">Translator</option>
                        <option value="Reviewer">Reviewer</option>
                        <option value="Team Lead">Team Lead</option>
                    </select>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="tms-modal-cancel" data-close-member-modal>Hủy</button>
                        <button type="submit" class="tms-button-primary px-5 py-2 text-sm font-semibold text-white">Thêm thành viên</button>
                    </div>
                </form>
            </dialog>
            <?php endif; ?>
            <div class="tms-table-wrap overflow-x-auto mt-5">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-gray-500"><th class="py-2">User ID</th><th>Username</th><th>Email</th><th>Vai trò</th><?php if ($currentProjectRole === 'Team Lead'): ?><th class="text-right">Thao tác</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($projectMembers as $member): ?>
                        <tr class="border-b last:border-0">
                            <td class="py-2"><?= (int)$member['id'] ?></td>
                            <td><?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $roleBadgeClass = match ($member['role_in_project']) {
                                    'Team Lead' => 'tms-role-lead',
                                    'Translator' => 'tms-role-translator',
                                    'Reviewer' => 'tms-role-reviewer',
                                    default => 'tms-role-default',
                                };
                                ?>
                                <span class="tms-role-badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($member['role_in_project'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <?php if ($currentProjectRole === 'Team Lead'): ?>
                            <td class="text-right">
                                <?php if ($member['is_project_owner'] !== 't'): ?>
                                    <div class="tms-member-actions">
                                        <button type="button"
                                                class="tms-member-action tms-member-edit"
                                                aria-label="Sửa vai trò của <?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-edit-member
                                                data-user-id="<?= (int)$member['id'] ?>"
                                                data-username="<?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-role="<?= htmlspecialchars($member['role_in_project'], ENT_QUOTES, 'UTF-8') ?>">✎</button>
                                        <form method="POST" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>"
                                              onsubmit="return confirm('Bạn có chắc muốn xóa thành viên này khỏi dự án?');">
                                            <input type="hidden" name="action" value="remove_project_member">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$member['id'] ?>">
                                            <button type="submit" class="tms-member-action tms-member-delete" aria-label="Xóa <?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?>">×</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">Chủ dự án</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($currentProjectRole === 'Team Lead'): ?>
            <dialog class="tms-modal" id="edit-member-modal" aria-labelledby="edit-member-title">
                <form method="POST" class="tms-modal-card" action="project_detail.php?id=<?= $projectId ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="update_project_member_role">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="project_id" value="<?= $projectId ?>">
                    <input type="hidden" name="user_id" value="" data-edit-user-id>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-widest text-indigo-500">Project team</p>
                            <h3 id="edit-member-title" class="mt-1 text-xl font-bold text-slate-900">Sửa vai trò thành viên</h3>
                            <p class="mt-1 text-sm text-slate-500" data-edit-member-name></p>
                        </div>
                        <button type="button" class="tms-modal-close" aria-label="Đóng" data-close-edit-modal>×</button>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700" for="edit-role-in-project">Vai trò</label>
                    <select id="edit-role-in-project" name="role_in_project" required class="mt-1 w-full px-3 py-2.5 border border-slate-300 rounded-xl text-sm">
                        <option value="Translator">Translator</option>
                        <option value="Reviewer">Reviewer</option>
                    </select>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="tms-modal-cancel" data-close-edit-modal>Hủy</button>
                        <button type="submit" class="tms-button-primary px-5 py-2 text-sm font-semibold text-white">Lưu thay đổi</button>
                    </div>
                </form>
            </dialog>
            <script>
                (() => {
                    const addModal = document.getElementById('add-member-modal');
                    const editModal = document.getElementById('edit-member-modal');
                    document.querySelectorAll('[data-open-member-modal]').forEach((button) => {
                        button.addEventListener('click', () => addModal.showModal());
                    });
                    document.querySelectorAll('[data-close-member-modal]').forEach((button) => {
                        button.addEventListener('click', () => addModal.close());
                    });
                    document.querySelectorAll('[data-edit-member]').forEach((button) => {
                        button.addEventListener('click', () => {
                            editModal.querySelector('[data-edit-user-id]').value = button.dataset.userId;
                            editModal.querySelector('[data-edit-member-name]').textContent = button.dataset.username;
                            editModal.querySelector('#edit-role-in-project').value =
                                button.dataset.role === 'Reviewer' ? 'Reviewer' : 'Translator';
                            editModal.showModal();
                        });
                    });
                    document.querySelectorAll('[data-close-edit-modal]').forEach((button) => {
                        button.addEventListener('click', () => editModal.close());
                    });
                    [addModal, editModal].filter(Boolean).forEach((modal) => {
                        modal.addEventListener('click', (event) => {
                            if (event.target === modal) modal.close();
                        });
                    });
                })();
            </script>
            <?php endif; ?>
        </section>
        </div>
        <?php endif; ?>
        <?php if (defined('TMS_PROJECT_DETAIL') && $currentVersion && ($canReviewCurrentProject || $isAdmin)): ?>
        <div class="col-lg-4 tms-project-admin-column tms-export-column">
        <section class="tms-export-panel tms-accent-indigo mb-5 bg-white p-5 rounded-xl shadow-sm border border-gray-100">
            <h2 class="text-base font-semibold text-gray-800 mb-3">📦 Xuất bản</h2>
            <div class="grid gap-2">
                <?php if ($canReviewCurrentProject): ?>
                <form method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="export_translation_package">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="tms-button-primary w-full px-4 py-2.5 text-sm font-semibold text-white">⬇ Xuất tệp JSON</button>
                </form>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                <form method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">
                    <input type="hidden" name="action" value="export_release">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="tms-button-secondary w-full px-4 py-2.5 text-sm font-semibold">🚀 Xuất bản JSON</button>
                </form>
                <?php endif; ?>
            </div>
        </section>
        </div>
        <?php endif; ?>
        </div>

        <div class="row tms-bottom-layout-row">
        <?php if ($projectId > 0 && (!$currentVersion || !$hasSourceStrings)): ?>
            <div class="col-12 tms-main-panel tms-notice bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                <p class="text-yellow-700">Chưa có dữ liệu phiên bản nguồn cho dự án này.</p>
            </div>
        <?php elseif ($canTranslateCurrentProject): ?>
            <!-- Glossary Section -->
            <div class="col-12 tms-main-panel tms-accent-violet mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">📖 Quản lý Từ điển Thuật ngữ (Glossary - <span class="uppercase text-indigo-600"><?= $currentLocale ?></span>)</h2>
                <?php if ($isAdmin): ?>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end mb-6" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                    <input type="hidden" name="action" value="add_glossary">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Thuật ngữ gốc</label>
                        <input type="text" name="term" placeholder="Dashboard" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Bản dịch chuẩn</label>
                        <input type="text" name="translation" placeholder="Bảng điều khiển" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Ghi chú</label>
                        <input type="text" name="description" placeholder="Ngữ cảnh..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold text-sm shadow-sm transition-colors">
                            Thêm Thuật ngữ
                        </button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (!empty($glossaryTerms)): ?>
                    <div class="tms-table-wrap overflow-x-auto">
                        <table class="w-full text-left border-collapse border border-gray-100 rounded-lg overflow-hidden text-sm">
                            <thead>
                                <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                    <th class="px-4 py-2 font-semibold border-b">Thuật ngữ gốc</th>
                                    <th class="px-4 py-2 font-semibold border-b">Bản dịch chuẩn</th>
                                    <th class="px-4 py-2 font-semibold border-b">Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($glossaryTerms as $g): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 font-mono font-medium text-indigo-600"><?= htmlspecialchars($g['term']) ?></td>
                                        <td class="px-4 py-2 font-semibold text-gray-800"><?= htmlspecialchars($g['translation']) ?></td>
                                        <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($g['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic">Chưa có thuật ngữ nào được thiết lập.</p>
                <?php endif; ?>
            </div>

            <!-- Translation Table -->
            <div class="col-12 tms-main-panel tms-accent-indigo bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-800">Bản dịch ngôn ngữ: <span class="uppercase text-indigo-600"><?= $currentLocale ?></span></h2>
                    <div class="flex items-center gap-2">
                        <span class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full font-medium">Phiên bản ID: <?= $currentVersion['id'] ?></span>
                    </div>
                </div>
                <form method="get" class="tms-translation-filters">
                    <input type="hidden" name="id" value="<?= $projectId ?>">
                    <input type="hidden" name="locale" value="<?= htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="version_id" value="<?= (int)$versionId ?>">
                    <label>
                        <span>Trạng thái</span>
                        <select name="status">
                            <option value="">Tất cả trạng thái</option>
                            <option value="translated" <?= $statusFilter === 'translated' ? 'selected' : '' ?>>Draft / Translated</option>
                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="needs_review" <?= $statusFilter === 'needs_review' ? 'selected' : '' ?>>Needs Review / Rejected</option>
                        </select>
                    </label>
                    <label class="tms-search-field">
                        <span>Tìm kiếm</span>
                        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Key, văn bản gốc hoặc bản dịch">
                    </label>
                    <button type="submit" class="tms-button-primary bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold">Lọc</button>
                    <a class="tms-filter-reset" href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">Xóa lọc</a>
                </form>
                <div class="tms-table-wrap overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                <th class="px-6 py-3 font-semibold w-1/5">Khóa (Key Path)</th>
                                <th class="px-6 py-3 font-semibold w-1/4">Văn bản gốc</th>
                                <th class="px-6 py-3 font-semibold w-1/3">Bản dịch</th>
                                <th class="px-6 py-3 font-semibold">Trạng thái</th>
                                <th class="px-6 py-3 font-semibold text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-sm">
                            <?php foreach ($strings as $row): ?>
                                <form method="POST" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>">
                                    <input type="hidden" name="action" value="save_translation">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="string_id" value="<?= $row['string_id'] ?>">
                                    <input type="hidden" name="translation_updated_at" value="<?= htmlspecialchars($row['translation_updated_at']) ?>">
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 font-mono text-indigo-600 font-medium align-middle">
                                            <?= htmlspecialchars($row['key_path']) ?>
                                        </td>
                                        <td class="px-6 py-4 text-gray-600 align-middle">
                                            <?= htmlspecialchars($row['source_text']) ?>
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <input type="text" name="translated_text" value="<?= htmlspecialchars($row['translated_text']) ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                                        </td>
                                        <td class="px-6 py-4 align-middle">
                                            <select name="status" data-status="<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>" class="tms-status tms-status-<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?> px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="this.className = 'tms-status tms-status-' + this.value + ' px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500'">
                                                <?php if ($canReviewCurrentProject): ?>
                                                    <option value="draft" <?= $row['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                                    <option value="approved" <?= $row['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                                                    <option value="rejected" <?= $row['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                                    <option value="needs_review" <?= $row['status'] === 'needs_review' ? 'selected' : '' ?>>Needs Review</option>
                                                <?php else: ?>
                                                    <option value="draft">Draft</option>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 text-right align-middle">
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <button type="submit" <?= $canTranslateCurrentProject ? '' : 'disabled' ?> class="tms-button-primary bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-xs font-semibold shadow-sm transition-colors">Lưu</button>
                                                <?php if ($canReviewCurrentProject && $row['translation_id']): ?>
                                                    <a href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= $versionId ?>&amp;history_string_id=<?= (int)$row['string_id'] ?>#translation-history" class="tms-history-link">Lịch sử</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                </form>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($canReviewCurrentProject && $historyStringId > 0): ?>
                <section id="translation-history" class="col-12 tms-main-panel tms-accent-violet bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-8">
                    <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Lịch sử chỉnh sửa</h2>
                            <p class="text-sm text-slate-500">Chỉ Team Lead và Reviewer có thể hoàn tác.</p>
                        </div>
                        <a class="tms-filter-reset" href="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>">Đóng lịch sử</a>
                    </div>
                    <?php if ($translationHistory): ?>
                        <div class="tms-table-wrap">
                            <table class="w-full text-left text-sm">
                                <thead><tr><th class="px-4 py-3">Thời gian / Người sửa</th><th class="px-4 py-3">Nội dung trước</th><th class="px-4 py-3">Nội dung sau</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                <?php foreach ($translationHistory as $history): ?>
                                    <tr>
                                        <td class="px-4 py-3">
                                            <span class="block font-medium"><?= htmlspecialchars($history['username'] ?? 'Hệ thống', ENT_QUOTES, 'UTF-8') ?></span>
                                            <time class="text-xs text-slate-500"><?= htmlspecialchars($history['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                                        </td>
                                        <td class="max-w-xs whitespace-pre-wrap break-words px-4 py-3 text-slate-600"><?= htmlspecialchars($history['old_text'] ?? '(bản dịch mới)', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="max-w-xs whitespace-pre-wrap break-words px-4 py-3 text-slate-800"><?= htmlspecialchars($history['new_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($history['old_text'] !== null): ?>
                                                <form method="post" action="<?= $workspaceAction ?>&amp;locale=<?= rawurlencode($currentLocale) ?>&amp;version_id=<?= (int)$versionId ?>&amp;history_string_id=<?= $historyStringId ?>#translation-history">
                                                    <input type="hidden" name="action" value="rollback_translation">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="history_id" value="<?= (int)$history['id'] ?>">
                                                    <input type="hidden" name="translation_id" value="<?= (int)$history['translation_id'] ?>">
                                                    <input type="hidden" name="translation_updated_at" value="<?= htmlspecialchars($history['updated_at'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="tms-history-rollback">Hoàn tác</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Chưa có lịch sử cho chuỗi dịch này.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Audit Trail Section -->
            <div class="col-12 tms-main-panel tms-accent-slate bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">📋 Nhật ký hoạt động & Kiểm toán (Audit Trail)</h2>
                <?php if (!empty($auditLogs)): ?>
                    <div class="tms-table-wrap overflow-x-auto">
                        <table class="w-full text-left border-collapse border border-gray-100 rounded-lg overflow-hidden text-sm">
                            <thead>
                                <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wider">
                                    <th class="px-4 py-2 font-semibold border-b">Loại hành động</th>
                                    <th class="px-4 py-2 font-semibold border-b">Chi tiết thay đổi</th>
                                    <th class="px-4 py-2 font-semibold border-b">Thời gian</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 font-mono font-bold text-indigo-600 text-xs"><?= htmlspecialchars($log['action_type']) ?></td>
                                        <td class="px-4 py-2 text-gray-800"><?= htmlspecialchars($log['details']) ?></td>
                                        <td class="px-4 py-2 text-gray-400 text-xs"><?= htmlspecialchars($log['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic">Chưa có nhật ký hoạt động nào được ghi nhận.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </div>
</body>
</html>