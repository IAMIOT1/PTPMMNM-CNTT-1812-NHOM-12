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
}
