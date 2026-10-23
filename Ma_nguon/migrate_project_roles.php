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
        "CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash TEXT NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'translator',
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(254)',
        []
    );
    queryParams(
        $connection,
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'translator'",
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP',
        []
    );
    queryParams(
        $connection,
        "UPDATE users
         SET email = username || '@legacy.invalid'
         WHERE email IS NULL OR btrim(email) = ''",
        []
    );
    queryParams($connection, 'ALTER TABLE users ALTER COLUMN email SET NOT NULL', []);
    queryParams(
        $connection,
        "CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uidx ON users (lower(email))",
        []
    );
    queryParams(
        $connection,
        "CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower_uidx ON users (lower(username))",
        []
    );
    queryParams(
        $connection,
        "ALTER TABLE users ALTER COLUMN role SET DEFAULT 'translator'",
        []
    );
    queryParams(
        $connection,
        'CREATE TABLE IF NOT EXISTS project_members (
            project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role_in_project VARCHAR(20) NOT NULL
                CHECK (role_in_project IN (\'Team Lead\', \'Translator\', \'Reviewer\')),
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (project_id, user_id)
        )',
        []
    );
    queryParams(
        $connection,
        'ALTER TABLE translations
         ADD COLUMN IF NOT EXISTS user_id INT REFERENCES users(id) ON DELETE SET NULL',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS translations_user_status_idx
         ON translations (user_id, status)',
        []
    );
    queryParams(
        $connection,
        'CREATE TABLE IF NOT EXISTS reviews (
            id SERIAL PRIMARY KEY,
            translation_id INT REFERENCES translations(id) ON DELETE CASCADE,
            reviewer_id INT REFERENCES users(id) ON DELETE SET NULL,
            status VARCHAR(20) NOT NULL,
            comment TEXT,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS project_members_user_idx ON project_members (user_id, project_id)',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Project-based roles migration completed.\n";
} catch (Throwable $e) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Project-based roles migration failed: {$e->getMessage()}\n");
    exit(1);
}
