<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $connection = db();
    queryParams(
        $connection,
        'ALTER TABLE source_versions ADD COLUMN IF NOT EXISTS source_html TEXT',
        []
    );
    echo "Live preview snapshot migration completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Live preview snapshot migration failed: {$exception->getMessage()}\n");
    exit(1);
}
