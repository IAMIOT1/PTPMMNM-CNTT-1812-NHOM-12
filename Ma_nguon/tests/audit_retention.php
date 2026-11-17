<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/AuditLogger.php';
require __DIR__ . '/../src/AuditLogPresenter.php';

try {
    $connection = db();
} catch (Throwable) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start audit retention test transaction');
    }
    $transactionOpen = true;

    $actorName = 'audit_actor_' . bin2hex(random_bytes(5));
    $targetName = 'audit_target_' . bin2hex(random_bytes(5));
    $actorResult = queryParams(
        $connection,
        'INSERT INTO users (username, email, password_hash) VALUES ($1, $2, $3) RETURNING id',
        [$actorName, $actorName . '@example.test', password_hash('audit-test-password', PASSWORD_BCRYPT)]
    );
    $actorId = (int)pg_fetch_result($actorResult, 0, 'id');
    $targetResult = queryParams(
        $connection,
        'INSERT INTO users (username, email, password_hash) VALUES ($1, $2, $3) RETURNING id',
        [$targetName, $targetName . '@example.test', password_hash('audit-test-password', PASSWORD_BCRYPT)]
    );
    $targetId = (int)pg_fetch_result($targetResult, 0, 'id');
    $projectResult = queryParams(
        $connection,
        'INSERT INTO projects (name, created_by) VALUES ($1, $2) RETURNING id',
        ['Audit retention test', $actorId]
    );
    $projectId = (int)pg_fetch_result($projectResult, 0, 'id');

    (new AuditLogger($connection))->log(
        $projectId,
        null,
        'TEST_ASSIGNMENT',
        'Assigned test user to the project.',
        $actorId,
        $targetId
    );
    $auditResult = queryParams(
        $connection,
        'SELECT id, actor_user_id, target_user_id, action_type, details, created_at
         FROM audit_logs WHERE project_id = $1 AND action_type = $2 ORDER BY id DESC LIMIT 1',
        [$projectId, 'TEST_ASSIGNMENT']
    );
    $audit = pg_fetch_assoc($auditResult);
    same((int)$audit['actor_user_id'], $actorId, 'audit-stores-actor');
    same((int)$audit['target_user_id'], $targetId, 'audit-stores-target');
    same($audit['details'], 'Assigned test user to the project.', 'audit-stores-action-details');
    same($audit['created_at'] !== null, true, 'audit-stores-timestamp');
    same(
        AuditLogPresenter::describe('SET_PROJECT_MEMBER_ROLE', 'Set user ID 123 project role to Reviewer.'),
        [
            'key' => 'audit.project_member_role_set',
            'params' => ['user_id' => '123', 'role' => 'Reviewer'],
        ],
        'audit-project-role-change-is-presented'
    );

    queryParams($connection, 'DELETE FROM projects WHERE id = $1', [$projectId]);
    $retainedResult = queryParams(
        $connection,
        'SELECT project_id, actor_user_id, target_user_id
         FROM audit_logs WHERE id = $1',
        [(int)$audit['id']]
    );
    $retainedAudit = pg_fetch_assoc($retainedResult);
    same($retainedAudit !== false, true, 'audit-survives-project-deletion');
    same($retainedAudit['project_id'], null, 'deleted-project-reference-is-cleared');
    same((int)$retainedAudit['actor_user_id'], $actorId, 'audit-actor-survives-project-deletion');
    same((int)$retainedAudit['target_user_id'], $targetId, 'audit-target-survives-project-deletion');
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL audit retention: ' . $exception->getMessage() . ' (' . pg_last_error($connection) . ")\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
}

finish();
