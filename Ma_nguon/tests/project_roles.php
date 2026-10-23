<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ProjectAccess.php';
require __DIR__ . '/../src/ReviewManager.php';
require __DIR__ . '/../src/AuthManager.php';

try {
    $connection = db();
} catch (Throwable $e) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start test transaction');
    }
    $transactionOpen = true;

    $projectRes = queryParams(
        $connection,
        'INSERT INTO projects (name) VALUES ($1) RETURNING id',
        ['Project role test']
    );
    $projectId = (int)pg_fetch_result($projectRes, 0, 'id');
    $registeredUsername = 'signup_' . bin2hex(random_bytes(5));
    $registeredEmail = $registeredUsername . '@example.test';
    $registeredPassword = 'signup-test-password';
    $registeredHash = password_hash($registeredPassword, PASSWORD_BCRYPT);
    $registrationRes = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role)
         VALUES ($1, $2, $3, 'translator')
         ON CONFLICT DO NOTHING
         RETURNING id, role, password_hash",
        [$registeredUsername, $registeredEmail, $registeredHash]
    );
    $registeredUser = pg_fetch_assoc($registrationRes);
    same($registeredUser['role'], 'translator', 'registration-assigns-normal-role');
    same(password_verify($registeredPassword, $registeredUser['password_hash']), true, 'registration-stores-bcrypt-hash');
    $duplicateRes = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role)
         VALUES ($1, $2, $3, 'translator')
         ON CONFLICT DO NOTHING
         RETURNING id",
        [strtoupper($registeredUsername), 'other_' . $registeredEmail, $registeredHash]
    );
    same(pg_num_rows($duplicateRes), 0, 'registration-rejects-duplicate-username-case-insensitively');

    $users = [];
    foreach (['lead', 'translator', 'reviewer'] as $roleName) {
        $userRes = queryParams(
            $connection,
            "INSERT INTO users (username, email, password_hash)
             VALUES ($1, $2, $3) RETURNING id, role",
            [
                'rbac_' . $roleName . '_' . bin2hex(random_bytes(5)),
                'rbac_' . $roleName . '_' . bin2hex(random_bytes(5)) . '@example.test',
                password_hash('test-password', PASSWORD_BCRYPT)
            ]
        );
        $users[$roleName] = pg_fetch_assoc($userRes);
    }
    $adminRes = queryParams(
        $connection,
        "INSERT INTO users (username, email, password_hash, role)
         VALUES ($1, $2, $3, 'admin') RETURNING id, role",
        [
            'rbac_admin_' . bin2hex(random_bytes(5)),
            'rbac_admin_' . bin2hex(random_bytes(5)) . '@example.test',
            password_hash('test-password', PASSWORD_BCRYPT)
        ]
    );
    $users['admin'] = pg_fetch_assoc($adminRes);
    same($users['translator']['role'], 'translator', 'new-user-is-not-system-admin');
    same(AuthManager::safeReturnPath('/profile.php'), '/profile.php', 'login-return-path-preserves-local-page');
    same(AuthManager::safeReturnPath('//example.test/'), '/index.php', 'login-return-path-rejects-external-host');
    same(AuthManager::safeReturnPath('/\\example.test/'), '/index.php', 'login-return-path-rejects-backslash-host');

    foreach (['lead' => 'Team Lead', 'translator' => 'Translator', 'reviewer' => 'Reviewer'] as $name => $role) {
        queryParams(
            $connection,
            'INSERT INTO project_members (project_id, user_id, role_in_project) VALUES ($1, $2, $3)',
            [$projectId, (int)$users[$name]['id'], $role]
        );
    }
    queryParams(
        $connection,
        'UPDATE projects SET created_by = $1 WHERE id = $2',
        [(int)$registeredUser['id'], $projectId]
    );
    $ownerProjectRes = queryParams(
        $connection,
        "INSERT INTO projects (name, created_by)
         VALUES ('Owner-only project role test', $1) RETURNING id",
        [(int)$registeredUser['id']]
    );
    $ownerOnlyProjectId = (int)pg_fetch_result($ownerProjectRes, 0, 'id');
    queryParams(
        $connection,
        "INSERT INTO project_members (project_id, user_id, role_in_project)
         VALUES ($1, $2, 'Team Lead')",
        [$ownerOnlyProjectId, (int)$registeredUser['id']]
    );
    $ownerRoleChange = queryParams(
        $connection,
        'UPDATE project_members pm
         SET role_in_project = $3
         FROM projects p
         WHERE pm.project_id = $1 AND pm.user_id = $2
           AND p.id = pm.project_id AND p.created_by IS DISTINCT FROM pm.user_id
           AND pm.role_in_project IS DISTINCT FROM $3
         RETURNING pm.user_id',
        [$ownerOnlyProjectId, (int)$registeredUser['id'], 'Translator']
    );
    same(pg_num_rows($ownerRoleChange), 0, 'project-owner-role-cannot-be-changed');
    $ownerRemoval = queryParams(
        $connection,
        'DELETE FROM project_members pm
         USING projects p
         WHERE pm.project_id = $1 AND pm.user_id = $2
           AND p.id = pm.project_id AND p.created_by IS DISTINCT FROM pm.user_id
         RETURNING pm.user_id',
        [$ownerOnlyProjectId, (int)$registeredUser['id']]
    );
    same(pg_num_rows($ownerRemoval), 0, 'project-owner-cannot-be-removed');
    $memberRoleChange = queryParams(
        $connection,
        'UPDATE project_members pm
         SET role_in_project = $3
         FROM projects p
         WHERE pm.project_id = $1 AND pm.user_id = $2
           AND p.id = pm.project_id AND p.created_by IS DISTINCT FROM pm.user_id
           AND pm.role_in_project IS DISTINCT FROM $3
         RETURNING pm.user_id',
        [$projectId, (int)$users['reviewer']['id'], 'Translator']
    );
    same(pg_num_rows($memberRoleChange), 1, 'non-owner-role-can-be-updated');
    $memberRemoval = queryParams(
        $connection,
        'DELETE FROM project_members pm
         USING projects p
         WHERE pm.project_id = $1 AND pm.user_id = $2
           AND p.id = pm.project_id AND p.created_by IS DISTINCT FROM pm.user_id
         RETURNING pm.user_id',
        [$projectId, (int)$users['reviewer']['id']]
    );
    same(pg_num_rows($memberRemoval), 1, 'non-owner-member-can-be-removed');
    queryParams(
        $connection,
        'INSERT INTO project_members (project_id, user_id, role_in_project)
         VALUES ($1, $2, $3)',
        [$projectId, (int)$users['reviewer']['id'], 'Reviewer']
    );
    queryParams(
        $connection,
        "INSERT INTO projects (name) VALUES ('Unrelated project role test')",
        []
    );
    $memberProjectsRes = queryParams(
        $connection,
        'SELECT DISTINCT p.id
         FROM projects p
         LEFT JOIN project_members pm ON pm.project_id = p.id
         WHERE p.created_by = $1 OR pm.user_id = $1
         ORDER BY p.id',
        [(int)$users['translator']['id']]
    );
    same(pg_fetch_all_columns($memberProjectsRes), [(string)$projectId], 'my-projects-includes-membership');
    $ownerProjectsRes = queryParams(
        $connection,
        'SELECT DISTINCT p.id
         FROM projects p
         LEFT JOIN project_members pm ON pm.project_id = p.id
         WHERE p.created_by = $1 OR pm.user_id = $1
         ORDER BY p.id',
        [(int)$registeredUser['id']]
    );
    same(
        pg_fetch_all_columns($ownerProjectsRes),
        [(string)$projectId, (string)$ownerOnlyProjectId],
        'my-projects-includes-owner'
    );
    $unrelatedProjectsRes = queryParams(
        $connection,
        'SELECT DISTINCT p.id
         FROM projects p
         LEFT JOIN project_members pm ON pm.project_id = p.id
         WHERE p.created_by = $1 OR pm.user_id = $1',
        [(int)$users['reviewer']['id']]
    );
    same(pg_num_rows($unrelatedProjectsRes), 1, 'my-projects-lists-only-user-related-projects');

    $translator = ['id' => (int)$users['translator']['id'], 'role' => $users['translator']['role']];
    $reviewer = ['id' => (int)$users['reviewer']['id'], 'role' => $users['reviewer']['role']];
    $lead = ['id' => (int)$users['lead']['id'], 'role' => $users['lead']['role']];
    $admin = ['id' => (int)$users['admin']['id'], 'role' => $users['admin']['role']];
    same(ProjectAccess::canTranslate($connection, $projectId, $translator), true, 'translator-can-translate');
    same(ProjectAccess::canReview($connection, $projectId, $translator), false, 'translator-cannot-review');
    same(ProjectAccess::canReview($connection, $projectId, $reviewer), true, 'reviewer-can-review');
    same(ProjectAccess::canManageMembers($connection, $projectId, $lead), true, 'team-lead-can-manage-members');
    same(ProjectAccess::canManageMembers($connection, $projectId, $reviewer), false, 'reviewer-cannot-manage-members');
    same(ProjectAccess::canManageMembers($connection, $projectId, $admin), true, 'system-admin-can-manage-members');
    same(ProjectAccess::canTranslate($connection, $projectId, $admin), false, 'admin-still-needs-project-membership-to-translate');

    $versionRes = queryParams(
        $connection,
        'INSERT INTO source_versions (project_id, version_number) VALUES ($1, $2) RETURNING id',
        [$projectId, 'rbac-test']
    );
    $versionId = (int)pg_fetch_result($versionRes, 0, 'id');
    $stringRes = queryParams(
        $connection,
        'INSERT INTO strings (project_id, source_version_id, key_path, source_text)
         VALUES ($1, $2, $3, $4) RETURNING id',
        [$projectId, $versionId, 'hello', 'Hello']
    );
    $stringId = (int)pg_fetch_result($stringRes, 0, 'id');
    $translationRes = queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, user_id)
         VALUES ($1, 'en', 'Hello', $2) RETURNING id",
        [$stringId, (int)$users['translator']['id']]
    );
    $translationId = (int)pg_fetch_result($translationRes, 0, 'id');

    $denied = false;
    try {
        (new ReviewManager($connection))->submitReview(
            $translationId,
            (int)$users['translator']['id'],
            'approved'
        );
    } catch (DomainException $e) {
        $denied = true;
    }
    same($denied, true, 'review-write-enforces-project-role');

    (new ReviewManager($connection))->submitReview(
        $translationId,
        (int)$users['reviewer']['id'],
        'approved'
    );
    $statusRes = queryParams(
        $connection,
        'SELECT status FROM translations WHERE id = $1',
        [$translationId]
    );
    same(pg_fetch_result($statusRes, 0, 'status'), 'approved', 'reviewer-can-approve');
    $notificationRes = queryParams(
        $connection,
        'SELECT message FROM notifications WHERE user_id = $1 ORDER BY id DESC LIMIT 1',
        [(int)$users['translator']['id']]
    );
    same(
        pg_fetch_result($notificationRes, 0, 'message'),
        'Bản dịch của bạn đã được duyệt.',
        'reviewer-approval-notifies-translator'
    );
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL project roles: ' . $e->getMessage() . ' (' . pg_last_error($connection) . ")\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
}

finish();
