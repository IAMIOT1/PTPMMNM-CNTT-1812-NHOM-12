<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $connection = db();
    queryParams(
        $connection,
        'ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45)',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS audit_logs_action_created_idx
         ON audit_logs (action_type, created_at DESC, id DESC)',
        []
    );
    echo "Audit log IP address and lookup index are ready.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Audit log migration failed: {$exception->getMessage()}\n");
    exit(1);
}
