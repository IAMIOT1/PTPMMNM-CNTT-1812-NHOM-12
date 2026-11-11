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
        "CREATE TABLE IF NOT EXISTS release_sync_history (
            id BIGSERIAL PRIMARY KEY,
            project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            source_version_id INT REFERENCES source_versions(id) ON DELETE SET NULL,
            type VARCHAR(20) NOT NULL CHECK (type IN ('version_lock', 'api_sync')),
            version_number VARCHAR(50),
            locale VARCHAR(10) REFERENCES locales(code) ON DELETE SET NULL,
            endpoint_url VARCHAR(2048),
            record_count INT NOT NULL DEFAULT 0 CHECK (record_count >= 0),
            status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
            actor_user_id INT REFERENCES users(id) ON DELETE SET NULL,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at TIMESTAMP WITH TIME ZONE,
            revoked_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
            CHECK (
                (type = 'version_lock' AND endpoint_url IS NULL)
                OR (type = 'api_sync' AND endpoint_url IS NOT NULL)
            )
        )",
        []
    );
    queryParams(
        $conn,
        "ALTER TABLE release_sync_history
         ADD COLUMN IF NOT EXISTS new_data JSONB,
         ADD COLUMN IF NOT EXISTS target_rollback_data JSONB,
         ADD COLUMN IF NOT EXISTS requires_auth BOOLEAN NOT NULL DEFAULT FALSE",
        []
    );
    queryParams(
        $conn,
        "CREATE UNIQUE INDEX IF NOT EXISTS release_sync_history_version_lock_uidx
         ON release_sync_history (source_version_id)
         WHERE type = 'version_lock' AND source_version_id IS NOT NULL",
        []
    );
    queryParams(
        $conn,
        'CREATE INDEX IF NOT EXISTS release_sync_history_project_created_idx
         ON release_sync_history (project_id, created_at DESC, id DESC)',
        []
    );
    queryParams(
        $conn,
        "INSERT INTO release_sync_history
            (project_id, source_version_id, type, version_number, record_count, actor_user_id, created_at)
         SELECT sv.project_id, sv.id, 'version_lock', sv.version_number,
                (SELECT COUNT(*)::INT FROM strings s WHERE s.source_version_id = sv.id),
                sv.frozen_by_user_id, sv.frozen_at
         FROM source_versions sv
         WHERE sv.frozen_at IS NOT NULL AND sv.project_id IS NOT NULL
         ON CONFLICT DO NOTHING",
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
