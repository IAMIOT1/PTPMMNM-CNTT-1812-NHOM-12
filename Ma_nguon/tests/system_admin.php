<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/AuthManager.php';
require __DIR__ . '/../src/ProjectAccess.php';
require __DIR__ . '/../src/AuditLogger.php';

try {
    $connection = db();
} catch (Throwable) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start system administration test transaction');
    }
    $transactionOpen = true;
    $_SESSION = [];

    $password = 'system-admin-test-password';
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $adminName = 'sysadmin_' . bin2hex(random_bytes(5));
    $adminResult = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role)
         VALUES ($1, $2, $3, 'admin')
         RETURNING id, role, is_active",
        [$adminName, $adminName . '@example.test', $passwordHash]
    );
    $admin = pg_fetch_assoc($adminResult);
    same($admin['role'], 'admin', 'system-admin-role-can-be-stored');
    same($admin['is_active'], 't', 'new-user-is-active-by-default');
    same(ProjectAccess::isSystemAdmin($admin), true, 'admin-role-is-recognized');

    $userName = 'systemuser_' . bin2hex(random_bytes(5));
    $userResult = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role)
         VALUES ($1, $2, $3, 'user')
         RETURNING id, role",
        [$userName, $userName . '@example.test', $passwordHash]
    );
    same(pg_fetch_result($userResult, 0, 'role'), 'user', 'normal-system-role-can-be-stored');
    queryParams(
        $connection,
        'INSERT INTO projects (name, created_by) VALUES ($1, $2), ($3, $2)',
        ['Admin count project one ' . bin2hex(random_bytes(3)), (int)pg_fetch_result($userResult, 0, 'id'), 'Admin count project two ' . bin2hex(random_bytes(3))]
    );
    $projectCountResult = queryParams(
        $connection,
        'SELECT u.id, COUNT(p.id)::int AS project_count
         FROM users u
         LEFT JOIN projects p ON p.created_by = u.id
         WHERE u.id = $1
         GROUP BY u.id',
        [(int)pg_fetch_result($userResult, 0, 'id')]
    );
    same((int)pg_fetch_result($projectCountResult, 0, 'project_count'), 2, 'admin-user-owned-project-count-is-accurate');
    $zeroProjectCountResult = queryParams(
        $connection,
        'SELECT u.id, COUNT(p.id)::int AS project_count
         FROM users u
         LEFT JOIN projects p ON p.created_by = u.id
         WHERE u.id = $1
         GROUP BY u.id',
        [(int)$admin['id']]
    );
    same((int)pg_fetch_result($zeroProjectCountResult, 0, 'project_count'), 0, 'admin-user-with-no-owned-projects-counts-zero');
    $supportTicketResult = queryParams(
        $connection,
        'INSERT INTO support_tickets (user_id, title, message, image_path)
         VALUES ($1, $2, $3, $4)
         RETURNING id, status, image_path',
        [(int)pg_fetch_result($userResult, 0, 'id'), 'Test support request', 'This is a test support message.', 'uploads/support/test-image.png']
    );
    $supportTicket = pg_fetch_assoc($supportTicketResult);
    same($supportTicket['status'], 'pending', 'support-ticket-starts-pending');
    same($supportTicket['image_path'], 'uploads/support/test-image.png', 'support-ticket-stores-attachment-path');
    $userTicketHistory = queryParams(
        $connection,
        'SELECT id FROM support_tickets WHERE user_id = $1 AND id = $2',
        [(int)pg_fetch_result($userResult, 0, 'id'), (int)$supportTicket['id']]
    );
    same(pg_num_rows($userTicketHistory), 1, 'user-can-query-own-ticket-history');
    queryParams(
        $connection,
        "UPDATE support_tickets SET status = 'resolved' WHERE id = $1 AND status = 'pending'",
        [(int)$supportTicket['id']]
    );
    same(
        pg_fetch_result(queryParams($connection, 'SELECT status FROM support_tickets WHERE id = $1', [(int)$supportTicket['id']]), 0, 0),
        'resolved',
        'support-ticket-can-be-resolved'
    );
    $profilePage = file_get_contents(__DIR__ . '/../profile.php');
    same(str_contains($profilePage, 'submit_support_ticket'), true, 'profile-page-submits-support-ticket');
    same(str_contains($profilePage, 'WHERE user_id = $1'), true, 'profile-page-loads-only-own-support-tickets');
    same(str_contains($profilePage, 'enctype="multipart/form-data"'), true, 'support-form-submits-multipart-data');
    same(str_contains($profilePage, 'name="support_image"'), true, 'support-form-accepts-image-attachment');
    same(str_contains($profilePage, '$ticket[\'image_path\']'), true, 'profile-ticket-history-shows-image-thumbnail');
    same(str_contains(file_get_contents(__DIR__ . '/../admin_users.php'), '$ticket[\'image_path\']'), true, 'admin-ticket-queue-shows-image-thumbnail');
    same(str_contains($profilePage, "header('Location: profile.php?status=support_submitted#support-requests', true, 303);"), true, 'support-submission-uses-post-redirect-get');
    same(str_contains($profilePage, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')"), true, 'profile-form-actions-require-post-request');

    pg_query($connection, 'SAVEPOINT invalid_system_role');
    $invalidRoleRejected = false;
    try {
        queryParams(
            $connection,
            "INSERT INTO users (username, email, password_hash, role)
             VALUES ($1, $2, $3, 'superuser')",
            ['invalid_' . bin2hex(random_bytes(5)), 'invalid_' . bin2hex(random_bytes(5)) . '@example.test', $passwordHash]
        );
    } catch (Throwable) {
        $invalidRoleRejected = true;
        pg_query($connection, 'ROLLBACK TO SAVEPOINT invalid_system_role');
    }
    pg_query($connection, 'RELEASE SAVEPOINT invalid_system_role');
    same($invalidRoleRejected, true, 'unknown-system-role-is-rejected');
    $adminPage = file_get_contents(__DIR__ . '/../admin_users.php');
    same(str_contains($adminPage, "\$_SESSION['role'] ?? '') !== 'admin'"), true, 'admin-page-checks-session-role');
    same(str_contains($adminPage, "header('Location: index.php', true, 303);"), true, 'non-admin-redirects-to-home');
    same(str_contains($adminPage, '!ProjectAccess::isSystemAdmin($currentUser)'), true, 'admin-page-rechecks-database-role');
    same(str_contains($adminPage, "\$systemRoles = ['admin', 'user'];"), true, 'admin-page-limits-global-roles');
    same(str_contains($adminPage, 'Tổng số tài khoản'), true, 'admin-page-shows-account-total');
    same(str_contains($adminPage, "id=\"user-search\""), true, 'admin-page-has-user-search');
    same(str_contains($adminPage, "id=\"user-role-filter\""), true, 'admin-page-has-role-filter');
    same(str_contains($adminPage, "name=\"action\" value=\"toggle_status\""), true, 'admin-page-can-toggle-account-status');
    same(!str_contains($adminPage, 'name="new_password"'), true, 'admin-page-removes-admin-password-reset');
    same(str_contains($adminPage, 'max-h-[450px] overflow-x-auto overflow-y-auto'), true, 'admin-user-table-is-scrollable');
    same(str_contains($adminPage, 'sticky top-0 z-10'), true, 'admin-user-table-header-is-sticky');
    same(str_contains($adminPage, 'id="user-page-previous"'), true, 'admin-user-pagination-has-previous-control');
    same(str_contains($adminPage, 'id="user-page-next"'), true, 'admin-user-pagination-has-next-control');
    same(str_contains($adminPage, 'id="user-page-size"'), true, 'admin-user-pagination-has-page-size-selector');
    same(str_contains($adminPage, "value=\"bulk_action\""), true, 'admin-user-page-has-bulk-action-handler');
    same(str_contains($adminPage, 'name="user_ids[]"'), true, 'admin-user-page-selects-rows-for-bulk-actions');
    same(str_contains($adminPage, 'value="lock">Khóa các tài khoản đã chọn'), true, 'admin-user-page-can-bulk-lock');
    same(str_contains($adminPage, 'value="delete">Xóa các tài khoản đã chọn'), true, 'admin-user-page-can-bulk-delete');
    same(str_contains($adminPage, 'admin_users.php?export=csv'), true, 'admin-user-page-exports-csv');
    same(str_contains($adminPage, "fputcsv(\$output"), true, 'admin-user-export-writes-csv');
    same(!str_contains($adminPage, "value=\"reset_password\""), true, 'admin-user-page-removes-quick-password-reset');
    same(str_contains(file_get_contents(__DIR__ . '/../login.php'), 'forgot_password.php')
        && str_contains(file_get_contents(__DIR__ . '/../forgot_password.php'), 'password_reset_tokens')
        && str_contains(file_get_contents(__DIR__ . '/../reset_password.php'), 'PASSWORD_DEFAULT'),
        true,
        'password-recovery-uses-expiring-reset-link');
    same(str_contains($adminPage, 'Nhật ký hoạt động'), true, 'admin-page-displays-audit-log');
    same(str_contains($adminPage, 'name="audit_from"'), true, 'audit-log-filters-by-start-date');
    same(str_contains($adminPage, 'name="audit_to"'), true, 'audit-log-filters-by-end-date');
    same(str_contains($adminPage, 'audit_page'), true, 'audit-log-supports-pagination');
    same(str_contains($adminPage, "a.ip_address"), true, 'audit-log-displays-client-ip');
    same(str_contains($adminPage, 'name="action" value="resolve_support_ticket"'), true, 'admin-can-resolve-support-ticket');
    same(str_contains($adminPage, '$pendingTicketsCount > 0'), true, 'admin-header-shows-pending-ticket-badge');
    same(str_contains($adminPage, 'Yêu cầu hỗ trợ từ User'), true, 'admin-page-lists-support-tickets');
    same(str_contains($adminPage, 'Đang chờ xử lý'), true, 'admin-support-page-has-pending-section');
    same(str_contains($adminPage, 'Lịch sử hỗ trợ'), true, 'admin-support-page-has-resolved-history');
    same(str_contains($adminPage, "WHERE t.status = 'resolved'"), true, 'admin-support-page-queries-resolved-tickets');
    same(str_contains($adminPage, 'max-h-[350px] space-y-3 overflow-y-auto'), true, 'admin-resolved-ticket-list-is-scrollable');
    same(str_contains($adminPage, 'max-h-[350px] space-y-2 overflow-y-auto'), true, 'admin-pending-tickets-use-compact-scroll-container');
    same(str_contains($adminPage, 'mt-2 flex justify-end'), true, 'admin-pending-ticket-action-aligns-to-end');
    same(str_contains($adminPage, 'LEFT JOIN projects p ON p.created_by = u.id'), true, 'admin-user-list-counts-owned-projects');
    same(str_contains($adminPage, 'COUNT(p.id)::int AS project_count'), true, 'admin-user-list-uses-project-count');
    same(str_contains($adminPage, '>Số dự án</th>'), true, 'admin-user-table-shows-project-count');
    same(str_contains($adminPage, '(int)$user[\'project_count\']'), true, 'admin-user-table-renders-project-count');

    $lockedName = 'locked_' . bin2hex(random_bytes(5));
    $lockedResult = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role, is_active)
         VALUES ($1, $2, $3, 'user', FALSE)
         RETURNING id",
        [$lockedName, $lockedName . '@example.test', $passwordHash]
    );
    $lockedId = (int)pg_fetch_result($lockedResult, 0, 'id');
    same(ProjectAccess::isSystemAdmin(['role' => 'user']), false, 'user-is-not-system-admin');
    $auth = new AuthManager($connection);
    same($auth->login($lockedName, $password), false, 'locked-account-cannot-login');
    same($auth->login($userName, $password), true, 'active-user-can-login');
    same($_SESSION['user']['role'] ?? null, 'user', 'regular-user-session-includes-role');
    same($auth->login($adminName, $password), true, 'active-admin-can-login');
    same($auth->getCurrentUser()['role'], 'admin', 'current-user-role-is-loaded-from-database');
    same($_SESSION['user']['role'] ?? null, 'admin', 'session-user-includes-admin-role');

    queryParams($connection, "UPDATE users SET role = 'user' WHERE id = $1", [(int)$admin['id']]);
    same($auth->getCurrentUser()['role'], 'user', 'current-role-refreshes-from-database');
    same($_SESSION['user']['role'] ?? null, 'user', 'session-user-role-refreshes-from-database');

    queryParams($connection, 'UPDATE users SET is_active = FALSE WHERE id = $1', [(int)$admin['id']]);
    same($auth->getCurrentUser(), null, 'deactivated-session-is-invalidated');
    same($auth->login($adminName, $password), false, 'deactivated-account-cannot-login');
    same($auth->login($lockedName, $password), false, 'locked-user-stays-unable-to-login');
    same($lockedId > 0, true, 'locked-user-is-persisted-for-test');
    $loginPage = file_get_contents(__DIR__ . '/../login.php');
    same(str_contains($loginPage, "'admin_users.php'"), true, 'login-routes-admin-to-user-management');
    same(str_contains($loginPage, ": 'index.php'"), true, 'login-routes-regular-user-to-workspace');
    $workspacePage = file_get_contents(__DIR__ . '/../index.php');
    same(str_contains($workspacePage, "header('Location: admin_users.php', true, 303);"), true, 'admin-workspace-request-redirects-to-admin');

    $projectResult = queryParams(
        $connection,
        'INSERT INTO projects (name) VALUES ($1) RETURNING id',
        ['System administration test']
    );
    $projectId = (int)pg_fetch_result($projectResult, 0, 'id');
    $previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    (new AuditLogger($connection))->log(
        $projectId,
        null,
        'SYNC_APPROVED_TRANSLATIONS',
        'Admin audit sync test',
        (int)$admin['id']
    );
    $syncAuditResult = queryParams(
        $connection,
        "SELECT actor_user_id, ip_address FROM audit_logs
         WHERE project_id = $1 AND action_type = 'SYNC_APPROVED_TRANSLATIONS'
           AND details = 'Admin audit sync test'",
        [$projectId]
    );
    $syncAudit = pg_fetch_assoc($syncAuditResult);
    same((int)$syncAudit['actor_user_id'], (int)$admin['id'], 'sync-audit-records-actor');
    same($syncAudit['ip_address'], '203.0.113.8', 'sync-audit-records-ip');
    if ($previousRemoteAddress === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
    }
    $filteredAuditCount = queryParams(
        $connection,
        "SELECT COUNT(*) FROM audit_logs a
         WHERE (a.action_type LIKE 'ADMIN_USER_%' OR a.action_type LIKE 'ADMIN_USERS_%'
                OR a.action_type LIKE 'SYNC_APPROVED_TRANSLATIONS%')
           AND a.created_at >= $1::date
           AND a.created_at < ($2::date + INTERVAL '1 day')",
        [date('Y-m-d'), date('Y-m-d')]
    );
    same((int)pg_fetch_result($filteredAuditCount, 0, 0) > 0, true, 'audit-log-date-range-query-returns-events');
    $paginatedAuditRows = queryParams(
        $connection,
        "SELECT a.id FROM audit_logs a
         WHERE a.action_type LIKE 'SYNC_APPROVED_TRANSLATIONS%'
         ORDER BY a.created_at DESC, a.id DESC LIMIT $1 OFFSET $2",
        [10, 0]
    );
    same(pg_num_rows($paginatedAuditRows) > 0, true, 'audit-log-pagination-query-returns-sync-event');
    queryParams(
        $connection,
        "INSERT INTO project_members (project_id, user_id, role_in_project)
         VALUES ($1, $2, 'Reviewer')",
        [$projectId, $lockedId]
    );
    same(
        ProjectAccess::roleFor($connection, $projectId, $lockedId),
        'Reviewer',
        'project-member-assignment-supports-project-role'
    );

    queryParams(
        $connection,
        'INSERT INTO audit_logs (project_id, actor_user_id, target_user_id, action_type, details)
         VALUES (NULL, $1, $2, $3, $4)',
        [(int)$admin['id'], $lockedId, 'ADMIN_USER_UPDATED', 'Role/status test']
    );
    $auditResult = queryParams(
        $connection,
        "SELECT COUNT(*) FROM audit_logs
         WHERE actor_user_id = $1 AND target_user_id = $2 AND action_type = 'ADMIN_USER_UPDATED'",
        [(int)$admin['id'], $lockedId]
    );
    same((int)pg_fetch_result($auditResult, 0, 0), 1, 'user-management-audit-can-be-recorded-without-project');
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL system administration: ' . $exception->getMessage() . "\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

finish();
