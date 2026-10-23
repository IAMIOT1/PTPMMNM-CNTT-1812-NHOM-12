<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams(
        $connection,
        'ALTER TABLE projects
         ADD COLUMN IF NOT EXISTS created_by INT REFERENCES users(id) ON DELETE SET NULL',
        []
    );
    queryParams(
        $connection,
        "UPDATE projects p
         SET created_by = (
             SELECT pm.user_id
             FROM project_members pm
             WHERE pm.project_id = p.id AND pm.role_in_project = 'Team Lead'
             ORDER BY pm.user_id
             LIMIT 1
         )
         WHERE p.created_by IS NULL",
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS projects_created_by_idx ON projects (created_by)',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Project ownership migration completed.\n";
} catch (Throwable $e) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Project ownership migration failed: {$e->getMessage()}\n");
    exit(1);
}
