<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Database.php';

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams($connection, 'ALTER TABLE audit_logs ALTER COLUMN project_id DROP NOT NULL', []);
    queryParams(
        $connection,
        'ALTER TABLE audit_logs
         ADD COLUMN IF NOT EXISTS target_user_id INT REFERENCES users(id) ON DELETE SET NULL,
         ADD COLUMN IF NOT EXISTS actor_user_id INT REFERENCES users(id) ON DELETE SET NULL,
         ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45)',
        []
    );
    queryParams(
        $connection,
        "DO \$\$
         DECLARE
             constraint_row RECORD;
         BEGIN
             FOR constraint_row IN
                 SELECT DISTINCT c.conname
                 FROM pg_constraint c
                 JOIN LATERAL unnest(c.conkey) AS key_column(attnum) ON TRUE
                 JOIN pg_attribute a
                   ON a.attrelid = c.conrelid AND a.attnum = key_column.attnum
                 WHERE c.conrelid = 'audit_logs'::regclass
                   AND c.contype = 'f'
                   AND a.attname = 'project_id'
             LOOP
                 EXECUTE format('ALTER TABLE audit_logs DROP CONSTRAINT %I', constraint_row.conname);
             END LOOP;
         END
         \$\$",
        []
    );
    queryParams(
        $connection,
        'UPDATE audit_logs a
         SET project_id = NULL
         WHERE a.project_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM projects p WHERE p.id = a.project_id)',
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE audit_logs
         ADD CONSTRAINT audit_logs_project_id_fkey
         FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS audit_logs_project_created_idx
         ON audit_logs (project_id, created_at DESC, id DESC)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS audit_logs_actor_created_idx
         ON audit_logs (actor_user_id, created_at DESC, id DESC)',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Audit retention migration completed.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Audit retention migration failed: {$exception->getMessage()}\n");
    exit(1);
}
