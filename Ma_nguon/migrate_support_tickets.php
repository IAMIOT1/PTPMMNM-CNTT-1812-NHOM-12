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
        "CREATE TABLE IF NOT EXISTS support_tickets (
            id BIGSERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title VARCHAR(200) NOT NULL CHECK (length(btrim(title)) BETWEEN 3 AND 200),
            message TEXT NOT NULL CHECK (length(btrim(message)) BETWEEN 10 AND 10000),
            status VARCHAR(10) NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'resolved')),
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS image_path VARCHAR(500)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS support_tickets_status_created_idx
         ON support_tickets (status, created_at DESC, id DESC)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS support_tickets_user_created_idx
         ON support_tickets (user_id, created_at DESC, id DESC)',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Support tickets migration completed.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Support tickets migration failed: {$exception->getMessage()}\n");
    exit(1);
}
