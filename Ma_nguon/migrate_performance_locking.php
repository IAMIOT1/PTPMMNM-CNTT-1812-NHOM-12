<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$conn = db();
foreach ([
    "CREATE TABLE IF NOT EXISTS translation_locks (
        string_id INT NOT NULL REFERENCES strings(id) ON DELETE CASCADE,
        locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
        user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        acquired_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
        PRIMARY KEY (string_id, locale)
    )",
    "CREATE INDEX IF NOT EXISTS translation_locks_expiry_idx
     ON translation_locks (expires_at)",
    "CREATE INDEX IF NOT EXISTS strings_source_version_key_idx
     ON strings (source_version_id, key_path, id)",
    "CREATE INDEX IF NOT EXISTS translations_string_locale_updated_idx
     ON translations (string_id, locale, updated_at)",
    "CREATE INDEX IF NOT EXISTS translation_memory_exact_idx
     ON translation_memory (locale, project_id, source_text)",
    "CREATE INDEX IF NOT EXISTS translation_memory_global_exact_idx
     ON translation_memory (locale, source_text)
     WHERE project_id IS NULL",
] as $sql) {
    queryParams($conn, $sql, []);
}

try {
    queryParams($conn, 'CREATE EXTENSION IF NOT EXISTS pg_trgm', []);
    queryParams(
        $conn,
        'CREATE INDEX IF NOT EXISTS translation_memory_fuzzy_idx
         ON translation_memory USING gin (lower(source_text) gin_trgm_ops)',
        []
    );
} catch (Throwable $exception) {
    error_log('TM_TRGM_INDEX_UNAVAILABLE: ' . $exception->getMessage());
}

echo "Performance and translation locking migration completed.\n";
