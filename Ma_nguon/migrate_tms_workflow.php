<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    if (pg_query($conn, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams(
        $conn,
        'CREATE TABLE IF NOT EXISTS locales (
            code VARCHAR(10) PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        )',
        []
    );
    queryParams(
        $conn,
        "INSERT INTO locales (code, name) VALUES
            ('vi', 'Tiếng Việt'),
            ('en', 'English'),
            ('ja', 'Japanese')
         ON CONFLICT (code) DO NOTHING",
        []
    );
    queryParams(
        $conn,
        'ALTER TABLE strings
         ADD COLUMN IF NOT EXISTS project_id INT REFERENCES projects(id) ON DELETE CASCADE',
        []
    );
    queryParams(
        $conn,
        'UPDATE strings s
         SET project_id = v.project_id
         FROM source_versions v
         WHERE s.source_version_id = v.id AND s.project_id IS NULL',
        []
    );
    $uniqueIndexRes = queryParams(
        $conn,
        "SELECT EXISTS (
            SELECT 1 FROM pg_indexes
            WHERE schemaname = current_schema()
              AND tablename = 'strings'
              AND indexdef LIKE 'CREATE UNIQUE INDEX% (source_version_id, key_path)%'
        ) AS has_unique_key",
        []
    );
    if (pg_fetch_result($uniqueIndexRes, 0, 'has_unique_key') !== 't') {
        queryParams(
            $conn,
            'CREATE UNIQUE INDEX strings_source_version_key_path_uidx
             ON strings (source_version_id, key_path)',
            []
        );
    }
    queryParams(
        $conn,
        'CREATE TABLE IF NOT EXISTS release_snapshots (
            id BIGSERIAL PRIMARY KEY,
            project_id INT NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
            source_version_id INT NOT NULL REFERENCES source_versions(id) ON DELETE RESTRICT,
            locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE RESTRICT,
            version_number VARCHAR(50) NOT NULL,
            json_content TEXT NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source_version_id, locale)
        )',
        []
    );
    queryParams(
        $conn,
        "CREATE OR REPLACE FUNCTION reject_release_snapshot_mutation() RETURNS trigger AS \$\$
         BEGIN
             RAISE EXCEPTION 'Release snapshots are immutable';
         END;
         \$\$ LANGUAGE plpgsql",
        []
    );
    queryParams($conn, 'DROP TRIGGER IF EXISTS release_snapshots_immutable ON release_snapshots', []);
    queryParams(
        $conn,
        'CREATE TRIGGER release_snapshots_immutable
         BEFORE UPDATE OR DELETE ON release_snapshots
         FOR EACH ROW EXECUTE FUNCTION reject_release_snapshot_mutation()',
        []
    );

    if (pg_query($conn, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "TMS workflow migration completed.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PgSql\Connection) {
        pg_query($conn, 'ROLLBACK');
    }
    fwrite(STDERR, "TMS workflow migration failed: {$e->getMessage()}\n");
    exit(1);
}
