<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

/**
 * Migration: Tạo bảng project_locales để quản lý các ngôn ngữ đã kích hoạt cho dự án
 */

try {
    $conn = db();
    
    if (pg_query($conn, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }
    
    queryParams(
        $conn,
        'CREATE TABLE IF NOT EXISTS project_locales (
            id BIGSERIAL PRIMARY KEY,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            version_id INTEGER REFERENCES source_versions(id) ON DELETE CASCADE,
            locale_code VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
            activated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );

    queryParams(
        $conn,
        'ALTER TABLE project_locales
         ADD COLUMN IF NOT EXISTS version_id INTEGER REFERENCES source_versions(id) ON DELETE CASCADE',
        []
    );

    queryParams(
        $conn,
        "DELETE FROM project_locales newer
         USING project_locales older
         WHERE newer.project_id = older.project_id
           AND newer.version_id IS NOT DISTINCT FROM older.version_id
           AND LOWER(newer.locale_code) = LOWER(older.locale_code)
           AND newer.id > older.id",
        []
    );

    queryParams(
        $conn,
        "UPDATE project_locales pl
         SET locale_code = LOWER(pl.locale_code)
         WHERE pl.locale_code <> LOWER(pl.locale_code)",
        []
    );

    queryParams(
        $conn,
        "DO \$\$
         DECLARE constraint_row RECORD;
         BEGIN
             FOR constraint_row IN
                 SELECT conname
                 FROM pg_constraint
                 WHERE conrelid = 'project_locales'::regclass
                   AND contype = 'u'
                   AND pg_get_constraintdef(oid) = 'UNIQUE (project_id, locale_code)'
             LOOP
                 EXECUTE format('ALTER TABLE project_locales DROP CONSTRAINT %I', constraint_row.conname);
             END LOOP;
         END
         \$\$",
        []
    );

    queryParams(
        $conn,
        'CREATE UNIQUE INDEX IF NOT EXISTS project_locales_project_global_uidx
         ON project_locales (project_id, locale_code)
         WHERE version_id IS NULL',
        []
    );
    queryParams(
        $conn,
        'CREATE UNIQUE INDEX IF NOT EXISTS project_locales_project_version_uidx
         ON project_locales (project_id, version_id, locale_code)
         WHERE version_id IS NOT NULL',
        []
    );
    
    queryParams(
        $conn,
        'CREATE INDEX IF NOT EXISTS project_locales_project_idx
         ON project_locales (project_id, version_id, locale_code)',
        []
    );
    
    // Active mặc định vi và en cho các dự án đã có
    $activeLocales = ['vi', 'en'];
    foreach ($activeLocales as $locale) {
        $exists = pg_fetch_assoc(
            queryParams($conn, 'SELECT code FROM locales WHERE code = $1', [$locale])
        );
        if ($exists) {
            queryParams(
                $conn,
                'INSERT INTO project_locales (project_id, locale_code)
                 SELECT p.id, $1::VARCHAR(10)
                 FROM projects p
                 WHERE NOT EXISTS (
                     SELECT 1 FROM project_locales pl
                     WHERE pl.project_id = p.id
                       AND pl.version_id IS NULL
                       AND pl.locale_code = $1::VARCHAR(10)
                 )',
                [$locale]
            );
        }
    }
    
    if (pg_query($conn, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    
    echo "Project locales migration completed.\n";
    
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PgSql\Connection && 
        pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($conn, 'ROLLBACK');
    }
    fwrite(STDERR, "Project locales migration failed: " . $e->getMessage() . "\n");
    exit(1);
}