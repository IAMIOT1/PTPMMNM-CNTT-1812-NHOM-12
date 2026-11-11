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
        'CREATE TABLE IF NOT EXISTS user_notification_preferences (
            user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            notify_new_projects BOOLEAN NOT NULL DEFAULT TRUE,
            notify_comments BOOLEAN NOT NULL DEFAULT TRUE,
            updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Account preferences migration completed.\n";
} catch (Throwable $exception) {
    if (
        $connection instanceof PgSql\Connection
        && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE
    ) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Account preferences migration failed: {$exception->getMessage()}\n");
    exit(1);
}
