<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ProjectSyncState.php';

try {
    $connection = db();
} catch (Throwable) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start project sync test transaction');
    }
    $transactionOpen = true;

    $users = [];
    foreach (['lead', 'translator', 'reviewer'] as $name) {
        $username = 'sync_' . $name . '_' . bin2hex(random_bytes(5));
        $result = queryParams(
            $connection,
            'INSERT INTO users (username, email, password_hash)
             VALUES ($1, $2, $3) RETURNING id',
            [
                $username,
                $username . '@example.test',
                password_hash('sync-test-password', PASSWORD_BCRYPT),
            ]
        );
        $users[$name] = (int)pg_fetch_result($result, 0, 'id');
    }

    $projectResult = queryParams(
        $connection,
        'INSERT INTO projects (name, created_by) VALUES ($1, $2) RETURNING id',
        ['Project sync test', $users['lead']]
    );
    $projectId = (int)pg_fetch_result($projectResult, 0, 'id');
    foreach (['lead' => 'Team Lead', 'translator' => 'Translator', 'reviewer' => 'Reviewer'] as $name => $role) {
        queryParams(
            $connection,
            'INSERT INTO project_members (project_id, user_id, role_in_project) VALUES ($1, $2, $3)',
            [$projectId, $users[$name], $role]
        );
    }

    $initialRevision = ProjectSyncState::fingerprint($connection, $users['lead']);
    same(ProjectSyncState::fingerprint($connection, $users['translator']), $initialRevision, 'project-state-shared-with-translator');
    same(ProjectSyncState::fingerprint($connection, $users['reviewer']), $initialRevision, 'project-state-shared-with-reviewer');

    queryParams(
        $connection,
        "UPDATE project_members SET role_in_project = 'Translator'
         WHERE project_id = $1 AND user_id = $2",
        [$projectId, $users['reviewer']]
    );
    $roleRevision = ProjectSyncState::fingerprint($connection, $users['lead']);
    same($roleRevision !== $initialRevision, true, 'assignment-change-updates-project-revision');
    same(ProjectSyncState::fingerprint($connection, $users['translator']), $roleRevision, 'assignment-change-shared-with-translator');
    same(ProjectSyncState::fingerprint($connection, $users['reviewer']), $roleRevision, 'assignment-change-shared-with-reviewer');

    queryParams(
        $connection,
        'UPDATE projects SET name = $1 WHERE id = $2',
        ['Renamed project sync test', $projectId]
    );
    same(
        ProjectSyncState::fingerprint($connection, $users['lead']) !== $roleRevision,
        true,
        'project-change-updates-project-revision'
    );
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL project sync: ' . $exception->getMessage() . ' (' . pg_last_error($connection) . ")\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
}

finish();
