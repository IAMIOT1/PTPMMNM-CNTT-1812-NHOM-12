<?php
declare(strict_types=1);

class GlossaryManager {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function addTerm(int $projectId, string $locale, string $term, string $translation, string $description = ''): void {
        $sql = "INSERT INTO glossary (project_id, locale, term, translation, description) 
                VALUES ($1, $2, $3, $4, $5) 
                ON CONFLICT (project_id, locale, term) 
                DO UPDATE SET translation = EXCLUDED.translation, description = EXCLUDED.description";
        queryParams($this->conn, $sql, [$projectId, $locale, $term, $translation, $description]);
    }

    public function getTerms(int $projectId, string $locale): array {
        $res = queryParams(
            $this->conn, 
            "SELECT term, translation, description FROM glossary WHERE project_id = $1 AND locale = $2", 
            [$projectId, $locale]
        );
        $terms = [];
        while ($row = pg_fetch_assoc($res)) {
            $terms[] = $row;
        }
        return $terms;
    }
}
