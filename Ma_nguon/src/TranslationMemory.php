<?php
declare(strict_types=1);

class TranslationMemory {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function saveToMemory(int $projectId, string $locale, string $sourceText, string $translatedText): void {
        $sql = "INSERT INTO translation_memory (project_id, locale, source_text, translated_text) 
                VALUES ($1, $2, $3, $4) 
                ON CONFLICT (project_id, locale, source_text) 
                DO UPDATE SET translated_text = EXCLUDED.translated_text";
        queryParams($this->conn, $sql, [$projectId, $locale, $sourceText, $translatedText]);
    }

    public function findSuggestions(int $projectId, string $locale, string $sourceText, int $limit = 10): array
    {
        $result = queryParams(
            $this->conn,
            'SELECT source_text, translated_text, project_id
             FROM translation_memory
             WHERE locale = $1 AND (project_id = $2 OR project_id IS NULL)
             ORDER BY CASE WHEN source_text = $3 THEN 0 ELSE 1 END, id DESC
             LIMIT $4',
            [$locale, $projectId, $sourceText, max(1, min($limit, 50))]
        );
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
}
