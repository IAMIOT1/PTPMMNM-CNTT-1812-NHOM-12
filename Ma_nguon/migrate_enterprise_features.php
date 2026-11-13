<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$conn = db();
queryParams($conn, <<<'SQL'
CREATE TABLE IF NOT EXISTS translation_memory (
    id BIGSERIAL PRIMARY KEY,
    project_id INT,
    locale VARCHAR(10) NOT NULL,
    source_text TEXT NOT NULL,
    translated_text TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_tm_entry UNIQUE (project_id, locale, source_text)
)
SQL, []);
queryParams($conn, <<<'SQL'
ALTER TABLE translation_memory
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
SQL, []);
queryParams($conn, <<<'SQL'
CREATE TABLE IF NOT EXISTS glossary (
    id BIGSERIAL PRIMARY KEY,
    project_id INT,
    locale VARCHAR(10) NOT NULL,
    term TEXT NOT NULL,
    translation TEXT NOT NULL,
    description TEXT,
    CONSTRAINT unique_glossary_term UNIQUE (project_id, locale, term)
)
SQL, []);
queryParams($conn, <<<'SQL'
ALTER TABLE translation_memory
    ALTER COLUMN project_id DROP NOT NULL
SQL, []);
queryParams($conn, <<<'SQL'
ALTER TABLE glossary
    ALTER COLUMN project_id DROP NOT NULL
SQL, []);
queryParams($conn, <<<'SQL'
CREATE INDEX IF NOT EXISTS translation_memory_locale_source_idx
    ON translation_memory (locale, source_text);
SQL, []);
queryParams($conn, <<<'SQL'
CREATE INDEX IF NOT EXISTS glossary_locale_term_idx
    ON glossary (locale, term)
SQL, []);
echo "Enterprise TM/glossary migration completed.\n";
