<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Database.php';

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams($connection, 'ALTER TABLE support_tickets ALTER COLUMN status TYPE VARCHAR(16)', []);
    queryParams(
        $connection,
        'ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_status_check',
        []
    );
    queryParams(
        $connection,
        "ALTER TABLE support_tickets
         ADD CONSTRAINT support_tickets_status_check
         CHECK (status IN ('pending', 'in_progress', 'resolved'))",
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Support ticket status migration completed.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Support ticket status migration failed: {$exception->getMessage()}\n");
    exit(1);
}
