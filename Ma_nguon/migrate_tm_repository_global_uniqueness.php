<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$conn = db();
queryParams($conn, <<<'SQL'
ALTER TABLE translation_memory
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
SQL, []);
queryParams($conn, <<<'SQL'
WITH ranked_entries AS (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY locale, source_text ORDER BY id DESC) AS duplicate_rank
    FROM translation_memory
    WHERE project_id IS NULL
)
DELETE FROM translation_memory AS tm
USING ranked_entries
WHERE tm.id = ranked_entries.id
  AND ranked_entries.duplicate_rank > 1
SQL, []);
queryParams($conn, <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS translation_memory_global_locale_source_uidx
    ON translation_memory (locale, source_text)
    WHERE project_id IS NULL
SQL, []);

echo "TM Repository global uniqueness migration completed.\n";