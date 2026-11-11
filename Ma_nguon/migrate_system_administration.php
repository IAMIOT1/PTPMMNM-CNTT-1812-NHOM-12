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
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE",
        []
    );
    queryParams(
        $connection,
        'UPDATE users SET is_active = TRUE WHERE is_active IS NULL',
        []
    );
    queryParams($connection, 'ALTER TABLE users ALTER COLUMN is_active SET DEFAULT TRUE', []);
    queryParams($connection, 'ALTER TABLE users ALTER COLUMN is_active SET NOT NULL', []);
    queryParams($connection, 'ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check', []);
    queryParams(
        $connection,
        "UPDATE users
         SET role = CASE lower(role)
             WHEN 'admin' THEN 'admin'
             ELSE 'user'
         END
         WHERE role IS NULL OR role NOT IN ('admin', 'user')",
        []
    );
    queryParams($connection, "ALTER TABLE users ALTER COLUMN role SET DEFAULT 'user'", []);
    queryParams($connection, 'ALTER TABLE users ALTER COLUMN role SET NOT NULL', []);
    queryParams(
        $connection,
        "ALTER TABLE users ADD CONSTRAINT users_role_check
         CHECK (role IN ('admin', 'user'))",
        []
    );

    queryParams(
        $connection,
        "CREATE TABLE IF NOT EXISTS project_members (
            project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role_in_project VARCHAR(20) NOT NULL
                CHECK (role_in_project IN ('Team Lead', 'Translator', 'Reviewer')),
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, user_id)
        )",
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS project_members_user_idx
         ON project_members (user_id, project_id)',
        []
    );

    queryParams(
        $connection,
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id SERIAL PRIMARY KEY,
            project_id INT REFERENCES projects(id) ON DELETE CASCADE,
            string_id INT REFERENCES strings(id) ON DELETE SET NULL,
            action_type VARCHAR(50) NOT NULL,
            details TEXT,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        []
    );
    queryParams($connection, 'ALTER TABLE audit_logs ALTER COLUMN project_id DROP NOT NULL', []);
    queryParams(
        $connection,
        'ALTER TABLE audit_logs
         ADD COLUMN IF NOT EXISTS actor_user_id INT REFERENCES users(id) ON DELETE SET NULL',
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE audit_logs
         ADD COLUMN IF NOT EXISTS target_user_id INT REFERENCES users(id) ON DELETE SET NULL',
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS audit_logs_actor_created_idx
         ON audit_logs (actor_user_id, created_at DESC)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS audit_logs_target_created_idx
         ON audit_logs (target_user_id, created_at DESC)',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "System administration migration completed.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "System administration migration failed: {$exception->getMessage()}\n");
    exit(1);
}
