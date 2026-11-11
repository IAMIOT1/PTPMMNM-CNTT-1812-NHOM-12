<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/ProjectAccess.php';

$connection = db();
$auth = new AuthManager($connection);
$currentUser = $auth->getCurrentUser();
if (($_SESSION['role'] ?? '') !== 'admin' ||
    $currentUser === null ||
    !ProjectAccess::isSystemAdmin($currentUser)) {
    header('Location: index.php', true, 303);
    exit;
}

$systemRoles = ['admin', 'user'];
$successMessage = '';
$errorMessage = '';
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$writeAdminAudit = static function (?int $targetUserId, string $action, string $details) use (
    $connection,
    $currentUser
): void {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $ipAddress = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)
        ? $remoteAddress
        : null;
    queryParams(
        $connection,
        'INSERT INTO audit_logs (actor_user_id, target_user_id, action_type, details, ip_address)
         VALUES ($1, $2, $3, $4, $5)',
        [
            (int)$currentUser['id'],
            $targetUserId,
            $action,
            $details,
            $ipAddress,
        ]
    );
};

if (($_GET['export'] ?? '') === 'csv') {
    $exportResult = queryParams(
        $connection,
        'SELECT u.id, u.username, u.email, u.role, u.is_active, u.created_at,
                COUNT(p.id)::int AS project_count
         FROM users u
         LEFT JOIN projects p ON p.created_by = u.id
         GROUP BY u.id
         ORDER BY u.username',
        []
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tms-users-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('USER_EXPORT_STREAM_FAILED');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Tên đăng nhập', 'Email', 'Vai trò', 'Số dự án', 'Trạng thái', 'Ngày tạo'], ',', '"', '');
    while ($user = pg_fetch_assoc($exportResult)) {
        $safeCell = static function (string $value): string {
            return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
        };
        fputcsv($output, [
            (int)$user['id'],
            $safeCell((string)$user['username']),
            $safeCell((string)$user['email']),
            $safeCell((string)$user['role']),
            (int)$user['project_count'],
            $user['is_active'] === 't' ? 'Đang hoạt động' : 'Bị khóa',
            $safeCell((string)$user['created_at']),
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::isValid($_POST)) {
        http_response_code(403);
        exit('CSRF_INVALID');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if (pg_query($connection, 'BEGIN') === false) {
            throw new RuntimeException('ADMIN_TRANSACTION_FAILED');
        }

        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                throw new InvalidArgumentException('Tên đăng nhập cần dài 3–50 ký tự và chỉ gồm chữ, số, dấu chấm, gạch dưới hoặc gạch ngang.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new InvalidArgumentException('Vui lòng nhập địa chỉ email hợp lệ.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('Mật khẩu cần có ít nhất 8 ký tự.');
            }
            if (!in_array($role, $systemRoles, true)) {
                throw new InvalidArgumentException('Vai trò người dùng không hợp lệ.');
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            if ($passwordHash === false) {
                throw new RuntimeException('PASSWORD_HASH_FAILED');
            }
            $created = queryParams(
                $connection,
                'INSERT INTO users (username, email, password_hash, role, is_verified)
                 VALUES ($1, $2, $3, $4, TRUE)
                 ON CONFLICT DO NOTHING
                 RETURNING id',
                [$username, $email, $passwordHash, $role]
            );
            $newUserId = pg_fetch_result($created, 0, 'id');
            if ($newUserId === false) {
                throw new InvalidArgumentException('Tên đăng nhập hoặc email đã được sử dụng.');
            }
            $writeAdminAudit((int)$newUserId, 'ADMIN_USER_CREATED', "Created user [$username] (ID $newUserId) with role $role");
            $successMessage = 'Đã tạo tài khoản người dùng.';
        } elseif ($action === 'update_user') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string)($_POST['username'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $role = (string)($_POST['role'] ?? '');
            if ($userId === false || $userId === null || $userId < 1 ||
                !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
                !in_array($role, $systemRoles, true)) {
                throw new InvalidArgumentException('Thông tin người dùng không hợp lệ.');
            }

            $activeAdminsResult = queryParams(
                $connection,
                "SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE ORDER BY id FOR UPDATE",
                []
            );
            $activeAdminIds = pg_fetch_all($activeAdminsResult) ?: [];
            $targetResult = queryParams(
                $connection,
                'SELECT id, username, email, role FROM users WHERE id = $1 FOR UPDATE',
                [(int)$userId]
            );
            $target = pg_fetch_assoc($targetResult);
            if (!$target) {
                throw new InvalidArgumentException('Không tìm thấy người dùng cần cập nhật.');
            }
            if ((int)$userId === (int)$currentUser['id'] && $role !== 'admin') {
                throw new InvalidArgumentException('Bạn không thể tự hạ quyền tài khoản đang đăng nhập.');
            }
            if ($target['role'] === 'admin' && $role !== 'admin' && count($activeAdminIds) <= 1) {
                throw new InvalidArgumentException('Không thể hạ quyền quản trị viên đang hoạt động cuối cùng.');
            }
            $duplicate = queryParams(
                $connection,
                'SELECT id FROM users
                 WHERE id <> $1 AND (lower(username) = lower($2) OR lower(email) = lower($3))
                 LIMIT 1',
                [(int)$userId, $username, $email]
            );
            if (pg_num_rows($duplicate) > 0) {
                throw new InvalidArgumentException('Tên đăng nhập hoặc email đã được sử dụng.');
            }
            queryParams(
                $connection,
                'UPDATE users SET username = $1, email = $2, role = $3 WHERE id = $4',
                [$username, $email, $role, (int)$userId]
            );
            $writeAdminAudit(
                (int)$userId,
                'ADMIN_USER_UPDATED',
                sprintf(
                    'User ID %d: username/email/role updated (%s, %s, %s)',
                    (int)$userId,
                    $username,
                    $email,
                    $role
                )
            );
            $successMessage = 'Đã cập nhật tài khoản người dùng.';
        } elseif ($action === 'toggle_status') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $activeValue = (string)($_POST['is_active'] ?? '');
            if ($userId === false || $userId === null || $userId < 1 ||
                !in_array($activeValue, ['0', '1'], true)) {
                throw new InvalidArgumentException('Tài khoản hoặc trạng thái không hợp lệ.');
            }
            if ((int)$userId === (int)$currentUser['id']) {
                throw new InvalidArgumentException('Bạn không thể khóa tài khoản đang đăng nhập.');
            }
            $isActive = $activeValue === '1';
            $activeAdminIds = [];
            if (!$isActive) {
                $activeAdminsResult = queryParams(
                    $connection,
                    "SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE ORDER BY id FOR UPDATE",
                    []
                );
                $activeAdminIds = pg_fetch_all($activeAdminsResult) ?: [];
            }
            $targetResult = queryParams(
                $connection,
                'SELECT id, username, role, is_active FROM users WHERE id = $1 FOR UPDATE',
                [(int)$userId]
            );
            $target = pg_fetch_assoc($targetResult);
            if (!$target) {
                throw new InvalidArgumentException('Không tìm thấy người dùng cần cập nhật.');
            }
            if (!$isActive && $target['role'] === 'admin' && $target['is_active'] === 't' &&
                count($activeAdminIds) <= 1) {
                throw new InvalidArgumentException('Không thể khóa quản trị viên đang hoạt động cuối cùng.');
            }
            queryParams(
                $connection,
                'UPDATE users SET is_active = $1 WHERE id = $2',
                [$isActive ? 'true' : 'false', (int)$userId]
            );
            $writeAdminAudit(
                (int)$userId,
                'ADMIN_USER_STATUS_CHANGED',
                sprintf('User [%s] (ID %d) set active=%s', $target['username'], (int)$userId, $isActive ? 'true' : 'false')
            );
            $successMessage = $isActive ? 'Đã mở khóa tài khoản.' : 'Đã khóa tài khoản.';
        } elseif ($action === 'bulk_action') {
            $selectedIds = $_POST['user_ids'] ?? null;
            $bulkAction = (string)($_POST['bulk_action'] ?? '');
            if (!is_array($selectedIds) || !$selectedIds || count($selectedIds) > 500 ||
                !in_array($bulkAction, ['lock', 'delete'], true)) {
                throw new InvalidArgumentException('Hãy chọn từ 1 đến 500 tài khoản và một thao tác hợp lệ.');
            }
            $userIds = [];
            foreach ($selectedIds as $selectedId) {
                $validatedId = filter_var($selectedId, FILTER_VALIDATE_INT);
                if ($validatedId === false || $validatedId === null || $validatedId < 1) {
                    throw new InvalidArgumentException('Danh sách tài khoản được chọn không hợp lệ.');
                }
                $userIds[] = (int)$validatedId;
            }
            $userIds = array_values(array_unique($userIds));
            if (count($userIds) !== count($selectedIds)) {
                throw new InvalidArgumentException('Danh sách tài khoản được chọn không hợp lệ.');
            }
            if (in_array((int)$currentUser['id'], $userIds, true)) {
                throw new InvalidArgumentException('Không thể khóa hoặc xóa tài khoản đang đăng nhập.');
            }

            $activeAdminResult = queryParams(
                $connection,
                "SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE ORDER BY id FOR UPDATE",
                []
            );
            $activeAdminIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                pg_fetch_all($activeAdminResult) ?: []
            );
            $placeholders = [];
            foreach (array_keys($userIds) as $index) {
                $placeholders[] = '$' . ($index + 1);
            }
            $idList = implode(', ', $placeholders);
            $targetsResult = queryParams(
                $connection,
                "SELECT id, username, role, is_active FROM users
                 WHERE id IN ($idList) ORDER BY id FOR UPDATE",
                $userIds
            );
            $targets = pg_fetch_all($targetsResult) ?: [];
            if (count($targets) !== count($userIds)) {
                throw new InvalidArgumentException('Một hoặc nhiều tài khoản không còn tồn tại.');
            }

            if ($bulkAction === 'lock') {
                $activeAdminsSelected = count(array_filter(
                    $targets,
                    static fn(array $target): bool => $target['role'] === 'admin'
                        && $target['is_active'] === 't'
                ));
                if (count($activeAdminIds) - $activeAdminsSelected < 1) {
                    throw new InvalidArgumentException('Không thể khóa toàn bộ quản trị viên đang hoạt động.');
                }
                queryParams(
                    $connection,
                    "UPDATE users SET is_active = FALSE
                     WHERE id IN ($idList) AND is_active = TRUE",
                    $userIds
                );
                $auditAction = 'ADMIN_USERS_BULK_LOCKED';
                $successMessage = 'Đã khóa ' . count($targets) . ' tài khoản được chọn.';
            } else {
                $activeAdminsSelected = count(array_filter(
                    $targets,
                    static fn(array $target): bool => $target['role'] === 'admin'
                        && $target['is_active'] === 't'
                ));
                if (count($activeAdminIds) - $activeAdminsSelected < 1) {
                    throw new InvalidArgumentException('Không thể xóa toàn bộ quản trị viên đang hoạt động.');
                }
                $auditAction = 'ADMIN_USERS_BULK_DELETED';
                $successMessage = 'Đã xóa ' . count($targets) . ' tài khoản được chọn.';
            }

            foreach ($targets as $target) {
                $writeAdminAudit(
                    (int)$target['id'],
                    $auditAction,
                    sprintf('%s user [%s] (ID %d)', $bulkAction === 'lock' ? 'Locked' : 'Deleted', $target['username'], (int)$target['id'])
                );
            }
            if ($bulkAction === 'delete') {
                queryParams($connection, "DELETE FROM users WHERE id IN ($idList)", $userIds);
            }
        } elseif ($action === 'resolve_support_ticket') {
            $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
            if ($ticketId === false || $ticketId === null || $ticketId < 1) {
                throw new InvalidArgumentException('Yêu cầu hỗ trợ không hợp lệ.');
            }
            $ticketResult = queryParams(
                $connection,
                'SELECT id, user_id, title, status FROM support_tickets WHERE id = $1 FOR UPDATE',
                [(int)$ticketId]
            );
            $ticket = pg_fetch_assoc($ticketResult);
            if (!$ticket) {
                throw new InvalidArgumentException('Không tìm thấy yêu cầu hỗ trợ.');
            }
            if ($ticket['status'] !== 'pending') {
                throw new InvalidArgumentException('Yêu cầu này đã được xử lý trước đó.');
            }
            queryParams(
                $connection,
                "UPDATE support_tickets SET status = 'resolved' WHERE id = $1",
                [(int)$ticketId]
            );
            $writeAdminAudit(
                (int)$ticket['user_id'],
                'SUPPORT_TICKET_RESOLVED',
                sprintf('Resolved support ticket #%d: %s', (int)$ticketId, $ticket['title'])
            );
            $successMessage = 'Đã đánh dấu yêu cầu hỗ trợ là đã giải quyết.';
        } elseif ($action === 'delete_user') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId < 1) {
                throw new InvalidArgumentException('Tài khoản cần xóa không hợp lệ.');
            }
            if ((int)$userId === (int)$currentUser['id']) {
                throw new InvalidArgumentException('Bạn không thể xóa tài khoản đang đăng nhập.');
            }
            $activeAdminsResult = queryParams(
                $connection,
                "SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE ORDER BY id FOR UPDATE",
                []
            );
            $activeAdminIds = pg_fetch_all($activeAdminsResult) ?: [];
            $targetResult = queryParams(
                $connection,
                'SELECT id, username, role, is_active FROM users WHERE id = $1 FOR UPDATE',
                [(int)$userId]
            );
            $target = pg_fetch_assoc($targetResult);
            if (!$target) {
                throw new InvalidArgumentException('Không tìm thấy người dùng cần xóa.');
            }
            if ($target['role'] === 'admin' && $target['is_active'] === 't' && count($activeAdminIds) <= 1) {
                throw new InvalidArgumentException('Không thể xóa quản trị viên đang hoạt động cuối cùng.');
            }
            $writeAdminAudit(
                (int)$userId,
                'ADMIN_USER_DELETED',
                sprintf('Deleted user [%s] (ID %d)', $target['username'], (int)$userId)
            );
            queryParams($connection, 'DELETE FROM users WHERE id = $1', [(int)$userId]);
            $successMessage = 'Đã xóa tài khoản người dùng.';
        } else {
            throw new InvalidArgumentException('Thao tác quản trị không hợp lệ.');
        }

        if (pg_query($connection, 'COMMIT') === false) {
            throw new RuntimeException('ADMIN_COMMIT_FAILED');
        }
    } catch (Throwable $exception) {
        if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
            pg_query($connection, 'ROLLBACK');
        }
        if ($exception instanceof InvalidArgumentException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('SYSTEM_ADMIN_USER_ACTION_FAILED: ' . $exception->getMessage());
            $errorMessage = 'Không thể lưu thay đổi quản trị. Vui lòng thử lại.';
        }
    }
}

$usersResult = queryParams(
    $connection,
    'SELECT u.id, u.username, u.email, u.role, u.is_active, u.created_at,
            COUNT(p.id)::int AS project_count
     FROM users u
     LEFT JOIN projects p ON p.created_by = u.id
     GROUP BY u.id
     ORDER BY u.username',
    []
);
$users = pg_fetch_all($usersResult) ?: [];
$userCounts = ['admin' => 0, 'user' => 0];
foreach ($users as $user) {
    if (isset($userCounts[$user['role']])) {
        $userCounts[$user['role']]++;
    }
}
$pendingTicketsCountResult = queryParams(
    $connection,
    "SELECT COUNT(*) FROM support_tickets WHERE status = 'pending'",
    []
);
$pendingTicketsCount = (int)pg_fetch_result($pendingTicketsCountResult, 0, 0);
$pendingTicketsResult = queryParams(
    $connection,
    "SELECT t.id, t.user_id, t.title, t.message, t.image_path, t.created_at, u.username, u.email
     FROM support_tickets t
     JOIN users u ON u.id = t.user_id
     WHERE t.status = 'pending'
     ORDER BY t.created_at ASC, t.id ASC
     LIMIT 200",
    []
);
$pendingTickets = pg_fetch_all($pendingTicketsResult) ?: [];
$resolvedTicketsCountResult = queryParams(
    $connection,
    "SELECT COUNT(*) FROM support_tickets WHERE status = 'resolved'",
    []
);
$resolvedTicketsCount = (int)pg_fetch_result($resolvedTicketsCountResult, 0, 0);
$resolvedTicketsResult = queryParams(
    $connection,
    "SELECT t.id, t.user_id, t.title, t.message, t.image_path, t.created_at, u.username, u.email
     FROM support_tickets t
     JOIN users u ON u.id = t.user_id
     WHERE t.status = 'resolved'
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 200",
    []
);
$resolvedTickets = pg_fetch_all($resolvedTicketsResult) ?: [];
$auditFromDate = trim((string)($_GET['audit_from'] ?? ''));
$auditToDate = trim((string)($_GET['audit_to'] ?? ''));
$auditPageSize = (int)($_GET['audit_page_size'] ?? 25);
if (!in_array($auditPageSize, [10, 25, 50], true)) {
    $auditPageSize = 25;
}
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditFilterError = '';
$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
};
if (($auditFromDate !== '' && !$isValidDate($auditFromDate)) ||
    ($auditToDate !== '' && !$isValidDate($auditToDate))) {
    $auditFilterError = 'Ngày lọc không hợp lệ. Hãy chọn ngày theo định dạng YYYY-MM-DD.';
    $auditFromDate = '';
    $auditToDate = '';
}
if ($auditFromDate !== '' && $auditToDate !== '' && $auditFromDate > $auditToDate) {
    $auditFilterError = 'Ngày bắt đầu không được sau ngày kết thúc.';
    $auditFromDate = '';
    $auditToDate = '';
}
$auditConditions = [
    "(a.action_type LIKE 'ADMIN_USER_%' OR a.action_type LIKE 'ADMIN_USERS_%' OR a.action_type LIKE 'SYNC_APPROVED_TRANSLATIONS%' OR a.action_type = 'SUPPORT_TICKET_RESOLVED')",
];
$auditParams = [];
if ($auditFromDate !== '') {
    $auditParams[] = $auditFromDate;
    $auditConditions[] = 'a.created_at >= $' . count($auditParams) . '::date';
}
if ($auditToDate !== '') {
    $auditParams[] = $auditToDate;
    $auditConditions[] = 'a.created_at < ($' . count($auditParams) . '::date + INTERVAL \'1 day\')';
}
$auditWhereSql = implode(' AND ', $auditConditions);
$auditCountResult = queryParams(
    $connection,
    "SELECT COUNT(*) FROM audit_logs a WHERE $auditWhereSql",
    $auditParams
);
$auditTotal = (int)pg_fetch_result($auditCountResult, 0, 0);
$auditPageCount = max(1, (int)ceil($auditTotal / $auditPageSize));
$auditPage = min($auditPage, $auditPageCount);
$auditOffset = ($auditPage - 1) * $auditPageSize;
$auditRowsParams = [...$auditParams, $auditPageSize, $auditOffset];
$auditRowsResult = queryParams(
    $connection,
    "SELECT a.id, a.action_type, a.details, a.ip_address, a.created_at,
            COALESCE(actor.username, 'Tài khoản đã xóa') AS actor_username,
            target.username AS target_username
     FROM audit_logs a
     LEFT JOIN users actor ON actor.id = a.actor_user_id
     LEFT JOIN users target ON target.id = a.target_user_id
     WHERE $auditWhereSql
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT $" . (count($auditParams) + 1) . ' OFFSET $' . (count($auditParams) + 2),
    $auditRowsParams
);
$auditRows = pg_fetch_all($auditRowsResult) ?: [];
$auditQuery = static function (int $page) use ($auditFromDate, $auditToDate, $auditPageSize): string {
    return '?' . http_build_query([
        'audit_from' => $auditFromDate,
        'audit_to' => $auditToDate,
        'audit_page_size' => $auditPageSize,
        'audit_page' => $page,
    ]);
};
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title data-i18n="admin.title">Quản lý người dùng - Enterprise TMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="tms.css">
    <script src="tms-i18n.js" defer></script>
    <script src="tms-theme.js" defer></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
<main class="tms-shell mx-auto max-w-7xl px-4 py-8">
    <header class="tms-topbar mb-8 flex flex-col items-center justify-between gap-4 rounded-xl border border-gray-100 bg-white p-6 shadow-sm md:flex-row">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600">Enterprise TMS · Admin</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900">Quản lý người dùng</h1>
            <p class="mt-1 text-sm text-gray-500">Quản lý tài khoản và vai trò hệ thống</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-gray-600"><?= $escape($currentUser['username']) ?></span>
            <a href="#support-tickets" class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100">
                Yêu cầu hỗ trợ
                <?php if ($pendingTicketsCount > 0): ?>
                    <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white"><?= $pendingTicketsCount > 99 ? '99+' : $pendingTicketsCount ?></span>
                <?php endif; ?>
            </a>
            <?php require __DIR__ . '/templates/theme-toggle.php'; ?>
            <a class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
               href="index.php?action=logout">Đăng xuất</a>
        </div>
    </header>

    <?php if ($successMessage !== ''): ?>
        <p class="mb-5 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status"><?= $escape($successMessage) ?></p>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <p class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert"><?= $escape($errorMessage) ?></p>
    <?php endif; ?>

    <section class="mb-8 grid gap-4 sm:grid-cols-3" aria-label="Thống kê tài khoản">
        <article class="rounded-xl border border-indigo-100 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-gray-500">Tổng số tài khoản</p>
            <p class="mt-2 text-3xl font-bold text-gray-900"><?= count($users) ?></p>
        </article>
        <article class="rounded-xl border border-indigo-100 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-gray-500">Số lượng Admin</p>
            <p class="mt-2 text-3xl font-bold text-indigo-700"><?= $userCounts['admin'] ?></p>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-medium text-gray-500">Số lượng User</p>
            <p class="mt-2 text-3xl font-bold text-slate-700"><?= $userCounts['user'] ?></p>
        </article>
    </section>

    <section class="mb-8 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <h1 class="text-xl font-bold text-gray-900">Thêm người dùng</h1>
        <form method="post" class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-5">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="create_user">
            <label class="text-sm font-semibold text-gray-700">Tên đăng nhập
                <input name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-gray-700">Email
                <input type="email" name="email" required maxlength="254" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-gray-700">Mật khẩu
                <input type="password" name="password" required minlength="8" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-gray-700">Vai trò
                <select name="role" required class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2">
                    <option value="user">user</option>
                    <option value="admin">admin</option>
                </select>
            </label>
            <button type="submit" class="self-end rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Thêm tài khoản</button>
        </form>
    </section>

    <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Danh sách người dùng</h2>
                <p class="mt-1 text-sm text-gray-500">Chỉ có hai vai trò hệ thống: admin và user.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="rounded-full bg-indigo-50 px-3 py-1 text-sm font-semibold text-indigo-700"><?= count($users) ?> tài khoản</span>
                <a href="admin_users.php?export=csv"
                   class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm font-semibold text-green-800 hover:bg-green-100">
                    Xuất Excel (CSV)
                </a>
            </div>
        </div>
        <div class="mb-5 grid gap-3 sm:grid-cols-[minmax(0,1fr)_220px]">
            <label class="text-sm font-semibold text-gray-700">Tìm kiếm tài khoản
                <input type="search" id="user-search" autocomplete="off" placeholder="Tên đăng nhập hoặc email"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 font-normal">
            </label>
            <label class="text-sm font-semibold text-gray-700">Lọc theo vai trò
                <select id="user-role-filter" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2">
                    <option value="all">Tất cả</option>
                    <option value="admin">Chỉ Admin</option>
                    <option value="user">Chỉ User</option>
                </select>
            </label>
        </div>
        <form id="bulk-users-form" method="post" class="mb-4 hidden items-center gap-3 rounded-lg border border-indigo-100 bg-indigo-50 p-3">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="action" value="bulk_action">
            <label for="bulk-action" class="text-sm font-semibold text-indigo-900">Thao tác với <span id="bulk-selected-count">0</span> tài khoản đã chọn</label>
            <select id="bulk-action" name="bulk_action" required class="rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm">
                <option value="lock">Khóa các tài khoản đã chọn</option>
                <option value="delete">Xóa các tài khoản đã chọn</option>
            </select>
            <button type="submit" class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Thực hiện</button>
        </form>
        <div class="max-h-[450px] overflow-x-auto overflow-y-auto rounded-lg border border-gray-100">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="sticky top-0 z-10 border-b bg-white text-xs uppercase text-gray-500 shadow-sm">
                    <tr>
                        <th class="px-3 py-3"><input type="checkbox" id="user-select-page" aria-label="Chọn tất cả tài khoản trên trang này"></th>
                        <th class="px-3 py-3">ID</th><th class="px-3 py-3">Tên đăng nhập / Email</th><th class="px-3 py-3">Vai trò</th><th class="px-3 py-3">Số dự án</th><th class="px-3 py-3">Trạng thái</th><th class="px-3 py-3">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($users as $user): ?>
                    <tr data-user-row data-role="<?= $escape($user['role']) ?>"
                        data-search="<?= $escape(strtolower($user['username'] . ' ' . $user['email'])) ?>">
                        <td class="px-3 py-4">
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$user['id'] ?>" form="bulk-users-form"
                                   data-user-select <?= (int)$user['id'] === (int)$currentUser['id'] ? 'disabled title="Không thể thao tác hàng loạt với tài khoản đang đăng nhập"' : '' ?>
                                   aria-label="Chọn <?= $escape($user['username']) ?>">
                        </td>
                        <td class="px-3 py-4 font-medium"><?= (int)$user['id'] ?></td>
                        <td class="px-3 py-4">
                            <p class="font-semibold text-gray-900"><?= $escape($user['username']) ?></p>
                            <p class="text-xs text-gray-500"><?= $escape($user['email']) ?></p>
                        </td>
                        <td class="px-3 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $user['role'] === 'admin' ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-700' ?>">
                                <?= $escape($user['role']) ?>
                            </span>
                        </td>
                        <td class="px-3 py-4">
                            <span class="inline-flex min-w-8 justify-center rounded-full bg-slate-100 px-2.5 py-1 text-sm font-semibold text-slate-700">
                                <?= (int)$user['project_count'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $user['is_active'] === 't' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' ?>">
                                <?= $user['is_active'] === 't' ? 'Đang hoạt động' : 'Bị khóa' ?>
                            </span>
                            <form method="post" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= $user['is_active'] === 't' ? '0' : '1' ?>">
                                <button type="submit"
                                        <?= (int)$user['id'] === (int)$currentUser['id'] ? 'disabled title="Không thể khóa tài khoản đang đăng nhập"' : '' ?>
                                        class="rounded border px-2 py-1 text-xs font-semibold <?= $user['is_active'] === 't' ? 'border-amber-200 text-amber-800 hover:bg-amber-50' : 'border-green-200 text-green-800 hover:bg-green-50' ?> disabled:cursor-not-allowed disabled:opacity-50">
                                    <?= $user['is_active'] === 't' ? 'Khóa' : 'Mở khóa' ?>
                                </button>
                            </form>
                        </td>
                        <td class="px-3 py-4">
                            <div class="flex items-start gap-2">
                                <details>
                                    <summary class="cursor-pointer rounded-lg border border-indigo-200 px-3 py-2 font-semibold text-indigo-700 hover:bg-indigo-50">Sửa</summary>
                                    <form method="post" class="mt-2 grid min-w-64 gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <label class="text-xs font-semibold text-gray-700">Tên đăng nhập
                                            <input name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]+" value="<?= $escape($user['username']) ?>" class="mt-1 block w-full rounded border border-gray-300 px-2 py-1.5">
                                        </label>
                                        <label class="text-xs font-semibold text-gray-700">Email
                                            <input type="email" name="email" required maxlength="254" value="<?= $escape($user['email']) ?>" class="mt-1 block w-full rounded border border-gray-300 px-2 py-1.5">
                                        </label>
                                        <label class="text-xs font-semibold text-gray-700">Vai trò
                                            <select name="role" required class="mt-1 block w-full rounded border border-gray-300 bg-white px-2 py-1.5">
                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                            </select>
                                        </label>
                                        <button type="submit" class="rounded bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-700">Lưu thay đổi</button>
                                    </form>
                                </details>
                                <form method="post" onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản này?')">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 font-semibold text-red-700 hover:bg-red-50">Xóa</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr id="user-list-empty" hidden><td class="px-3 py-5 text-gray-500" colspan="7">Không tìm thấy tài khoản phù hợp.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                <label for="user-page-size">Số dòng mỗi trang</label>
                <select id="user-page-size" class="rounded-lg border border-gray-300 bg-white px-2 py-1.5">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                </select>
                <span id="user-pagination-summary" aria-live="polite"></span>
            </div>
            <nav class="flex items-center gap-2" aria-label="Phân trang danh sách người dùng">
                <button type="button" id="user-page-previous" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">Trang trước</button>
                <span id="user-page-indicator" class="min-w-20 text-center text-sm font-medium text-gray-700" aria-live="polite">Trang 1 / 1</span>
                <button type="button" id="user-page-next" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">Trang sau</button>
            </nav>
        </div>
    </section>

    <section id="support-tickets" class="mt-8 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <div class="mb-5">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Yêu cầu hỗ trợ từ User</h2>
                <p class="mt-1 text-sm text-gray-500">Theo dõi yêu cầu đang chờ và các yêu cầu đã giải quyết.</p>
            </div>
        </div>
        <section aria-labelledby="pending-support-heading">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 id="pending-support-heading" class="text-base font-bold text-gray-900">Đang chờ xử lý</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Các báo cáo mới cần quản trị viên xem xét.</p>
                </div>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900"><?= $pendingTicketsCount ?> đang chờ</span>
            </div>
        <?php if ($pendingTickets): ?>
            <div class="max-h-[350px] space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50/60 p-2.5">
                <?php foreach ($pendingTickets as $ticket): ?>
                    <article class="rounded-lg border border-amber-100 bg-white p-3 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                            <div class="min-w-0 flex-1">
                                <h4 class="break-words text-sm font-semibold text-gray-900"><?= htmlspecialchars($ticket['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="mt-0.5 break-words text-xs text-gray-500">
                                    <?= htmlspecialchars($ticket['username'], ENT_QUOTES, 'UTF-8') ?>
                                    · <?= htmlspecialchars($ticket['email'], ENT_QUOTES, 'UTF-8') ?>
                                    · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ticket['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">Đang chờ</span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-5 text-gray-700"><?= htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if (is_string($ticket['image_path'] ?? null) && $ticket['image_path'] !== ''): ?>
                            <a class="mt-2 inline-block" href="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                <img class="max-h-32 max-w-full rounded-md border border-gray-200 object-contain" src="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Ảnh đính kèm yêu cầu hỗ trợ">
                            </a>
                        <?php endif; ?>
                        <form method="post" class="mt-2 flex justify-end">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="resolve_support_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                            <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">Đánh dấu đã giải quyết</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($pendingTicketsCount > count($pendingTickets)): ?>
                <p class="mt-3 text-xs text-gray-500">Đang hiển thị tối đa 200 yêu cầu; tổng số yêu cầu đang chờ: <?= $pendingTicketsCount ?>.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800">Không có yêu cầu nào đang chờ xử lý.</p>
        <?php endif; ?>
        </section>

        <section class="mt-7 border-t border-slate-100 pt-6" aria-labelledby="resolved-support-heading">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 id="resolved-support-heading" class="text-base font-bold text-gray-900">Lịch sử hỗ trợ</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Các yêu cầu đã được quản trị viên giải quyết.</p>
                </div>
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800"><?= $resolvedTicketsCount ?> đã giải quyết</span>
            </div>
            <?php if ($resolvedTickets): ?>
                <div class="max-h-[350px] space-y-3 overflow-y-auto rounded-lg border border-slate-100 p-3">
                    <?php foreach ($resolvedTickets as $ticket): ?>
                        <article class="rounded-xl border border-emerald-100 bg-emerald-50/30 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-semibold text-gray-900"><?= htmlspecialchars($ticket['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <p class="mt-1 text-xs text-gray-500">
                                        <?= htmlspecialchars($ticket['username'], ENT_QUOTES, 'UTF-8') ?>
                                        · <?= htmlspecialchars($ticket['email'], ENT_QUOTES, 'UTF-8') ?>
                                        · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ticket['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">Đã giải quyết</span>
                            </div>
                            <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-gray-700"><?= htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if (is_string($ticket['image_path'] ?? null) && $ticket['image_path'] !== ''): ?>
                                <a class="mt-3 inline-block" href="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                    <img class="max-h-48 max-w-full rounded-lg border border-gray-200 object-contain" src="<?= htmlspecialchars($ticket['image_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Ảnh đính kèm yêu cầu hỗ trợ">
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($resolvedTicketsCount > count($resolvedTickets)): ?>
                    <p class="mt-3 text-xs text-gray-500">Đang hiển thị tối đa 200 yêu cầu đã giải quyết.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">Chưa có yêu cầu hỗ trợ nào được giải quyết.</p>
            <?php endif; ?>
        </section>
    </section>

    <section class="mt-8 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Nhật ký hoạt động</h2>
                <p class="mt-1 text-sm text-gray-500">Thao tác tài khoản, đồng bộ bản dịch và xử lý hỗ trợ.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700"><?= $auditTotal ?> sự kiện</span>
        </div>
        <?php if ($auditFilterError !== ''): ?>
            <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="alert"><?= $escape($auditFilterError) ?></p>
        <?php endif; ?>
        <form method="get" action="admin_users.php" class="mb-5 flex flex-wrap items-end gap-3">
            <label class="text-sm font-semibold text-gray-700">Từ ngày
                <input type="date" name="audit_from" value="<?= $escape($auditFromDate) ?>" class="mt-1 block rounded-lg border border-gray-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-gray-700">Đến ngày
                <input type="date" name="audit_to" value="<?= $escape($auditToDate) ?>" class="mt-1 block rounded-lg border border-gray-300 px-3 py-2">
            </label>
            <label class="text-sm font-semibold text-gray-700">Số dòng
                <select name="audit_page_size" class="mt-1 block rounded-lg border border-gray-300 bg-white px-3 py-2">
                    <?php foreach ([10, 25, 50] as $pageSize): ?>
                        <option value="<?= $pageSize ?>" <?= $auditPageSize === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Lọc nhật ký</button>
            <a href="admin_users.php" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Xóa bộ lọc</a>
        </form>
        <div class="max-h-[420px] overflow-auto rounded-lg border border-gray-100">
            <table class="w-full min-w-[850px] text-left text-sm">
                <thead class="sticky top-0 z-10 border-b bg-white text-xs uppercase text-gray-500 shadow-sm">
                    <tr>
                        <th class="px-3 py-3">ID</th>
                        <th class="px-3 py-3">Người thực hiện</th>
                        <th class="px-3 py-3">Hành động</th>
                        <th class="px-3 py-3">Đối tượng / Chi tiết</th>
                        <th class="px-3 py-3">Địa chỉ IP</th>
                        <th class="px-3 py-3">Thời gian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($auditRows as $audit): ?>
                        <tr>
                            <td class="px-3 py-3"><?= (int)$audit['id'] ?></td>
                            <td class="px-3 py-3 font-medium"><?= $escape($audit['actor_username']) ?></td>
                            <td class="px-3 py-3"><span class="rounded bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-800"><?= $escape($audit['action_type']) ?></span></td>
                            <td class="px-3 py-3">
                                <?php if (!empty($audit['target_username'])): ?><p class="font-medium"><?= $escape($audit['target_username']) ?></p><?php endif; ?>
                                <p class="text-xs text-gray-600"><?= $escape((string)($audit['details'] ?? '')) ?></p>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs"><?= $escape((string)($audit['ip_address'] ?? '—')) ?></td>
                            <td class="whitespace-nowrap px-3 py-3"><?= $escape((string)$audit['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$auditRows): ?><tr><td class="px-3 py-5 text-gray-500" colspan="6">Không có nhật ký trong khoảng thời gian này.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4">
            <span class="text-sm text-gray-600">Trang <?= $auditPage ?> / <?= $auditPageCount ?> · <?= $auditTotal ?> sự kiện</span>
            <nav class="flex gap-2" aria-label="Phân trang nhật ký hoạt động">
                <?php if ($auditPage > 1): ?>
                    <a href="admin_users.php<?= $escape($auditQuery($auditPage - 1)) ?>" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Trang trước</a>
                <?php else: ?>
                    <span class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-400">Trang trước</span>
                <?php endif; ?>
                <?php if ($auditPage < $auditPageCount): ?>
                    <a href="admin_users.php<?= $escape($auditQuery($auditPage + 1)) ?>" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Trang sau</a>
                <?php else: ?>
                    <span class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-400">Trang sau</span>
                <?php endif; ?>
            </nav>
        </div>
    </section>
</main>
<script>
(() => {
    const searchInput = document.getElementById('user-search');
    const roleFilter = document.getElementById('user-role-filter');
    const pageSizeInput = document.getElementById('user-page-size');
    const previousButton = document.getElementById('user-page-previous');
    const nextButton = document.getElementById('user-page-next');
    const pageIndicator = document.getElementById('user-page-indicator');
    const paginationSummary = document.getElementById('user-pagination-summary');
    const emptyRow = document.getElementById('user-list-empty');
    const selectPageCheckbox = document.getElementById('user-select-page');
    const bulkForm = document.getElementById('bulk-users-form');
    const bulkSelectedCount = document.getElementById('bulk-selected-count');
    const rows = Array.from(document.querySelectorAll('[data-user-row]'));
    let currentPage = 1;
    let currentPageRows = [];

    const renderUsers = () => {
        const query = searchInput.value.trim().toLocaleLowerCase();
        const role = roleFilter.value;
        const filteredRows = rows.filter((row) => {
            return row.dataset.search.includes(query) && (role === 'all' || row.dataset.role === role);
        });
        const pageSize = Number(pageSizeInput.value);
        const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, pageCount);
        const firstIndex = (currentPage - 1) * pageSize;
        currentPageRows = filteredRows.slice(firstIndex, firstIndex + pageSize);
        const visibleRows = new Set(currentPageRows);

        rows.forEach((row) => {
            row.hidden = !visibleRows.has(row);
        });
        emptyRow.hidden = filteredRows.length !== 0;
        previousButton.disabled = currentPage <= 1;
        nextButton.disabled = currentPage >= pageCount;
        pageIndicator.textContent = `Trang ${currentPage} / ${pageCount}`;
        paginationSummary.textContent = filteredRows.length === 0
            ? 'Hiển thị 0 tài khoản'
            : `Hiển thị ${firstIndex + 1}–${Math.min(firstIndex + pageSize, filteredRows.length)} trong ${filteredRows.length} tài khoản`;
        updateBulkSelection();
    };

    const updateBulkSelection = () => {
        const selectedCount = rows.filter((row) => row.querySelector('[data-user-select]').checked).length;
        bulkSelectedCount.textContent = String(selectedCount);
        bulkForm.classList.toggle('hidden', selectedCount === 0);
        bulkForm.classList.toggle('flex', selectedCount > 0);
        const selectablePageRows = currentPageRows
            .map((row) => row.querySelector('[data-user-select]'))
            .filter((checkbox) => !checkbox.disabled);
        const selectedPageCount = selectablePageRows.filter((checkbox) => checkbox.checked).length;
        selectPageCheckbox.checked = selectablePageRows.length > 0 && selectedPageCount === selectablePageRows.length;
        selectPageCheckbox.indeterminate = selectedPageCount > 0 && selectedPageCount < selectablePageRows.length;
    };

    searchInput.addEventListener('input', () => {
        currentPage = 1;
        renderUsers();
    });
    roleFilter.addEventListener('change', () => {
        currentPage = 1;
        renderUsers();
    });
    pageSizeInput.addEventListener('change', () => {
        currentPage = 1;
        renderUsers();
    });
    previousButton.addEventListener('click', () => {
        currentPage--;
        renderUsers();
    });
    nextButton.addEventListener('click', () => {
        currentPage++;
        renderUsers();
    });
    selectPageCheckbox.addEventListener('change', () => {
        currentPageRows.forEach((row) => {
            const checkbox = row.querySelector('[data-user-select]');
            if (!checkbox.disabled) checkbox.checked = selectPageCheckbox.checked;
        });
        updateBulkSelection();
    });
    rows.forEach((row) => row.querySelector('[data-user-select]').addEventListener('change', updateBulkSelection));
    bulkForm.addEventListener('submit', (event) => {
        if (document.getElementById('bulk-action').value === 'delete' &&
            !window.confirm(`Xóa ${rows.filter((row) => row.querySelector('[data-user-select]').checked).length} tài khoản đã chọn?`)) {
            event.preventDefault();
        }
    });
    renderUsers();
})();
</script>
<?php require __DIR__ . '/templates/tms-ai-chat.php'; ?>
</body>
</html>
