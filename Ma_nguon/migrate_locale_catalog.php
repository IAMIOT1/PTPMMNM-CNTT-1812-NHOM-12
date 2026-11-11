<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/LanguageCatalog.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('LOCALE_CATALOG_MIGRATION_BEGIN_FAILED');
    }

    $rows = LanguageCatalog::databaseRows();
    $placeholders = [];
    $parameters = [];
    foreach ($rows as $index => $language) {
        $codeParameter = $index * 2 + 1;
        $nameParameter = $codeParameter + 1;
        $placeholders[] = sprintf('($%d, $%d)', $codeParameter, $nameParameter);
        $parameters[] = $language['code'];
        $parameters[] = $language['name'];
    }
    queryParams(
        $connection,
        'INSERT INTO locales (code, name) VALUES ' . implode(', ', $placeholders) .
        ' ON CONFLICT (code) DO NOTHING',
        $parameters
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('LOCALE_CATALOG_MIGRATION_COMMIT_FAILED');
    }
    echo "ISO 639-1 locale catalog migration completed.\n";
} catch (Throwable $exception) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Locale catalog migration failed: " . $exception->getMessage() . "\n");
    exit(1);
}
