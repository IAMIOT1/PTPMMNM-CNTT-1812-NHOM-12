<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams($connection, 'ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check', []);
    queryParams(
        $connection,
        "UPDATE users
         SET role = CASE WHEN lower(role) = 'admin' THEN 'admin' ELSE 'user' END
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

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Global user roles normalized to admin and user.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Admin user role migration failed: {$exception->getMessage()}\n");
    exit(1);
}
