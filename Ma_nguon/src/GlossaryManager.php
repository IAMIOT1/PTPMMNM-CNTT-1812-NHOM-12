<?php
declare(strict_types=1);

class GlossaryManager {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function addTerm(?int $projectId, string $locale, string $term, string $translation, string $description = ''): void {
        $sql = "INSERT INTO glossary (project_id, locale, term, translation, description) 
                VALUES ($1, $2, $3, $4, $5)
                ON CONFLICT (project_id, locale, term) 
                DO UPDATE SET translation = EXCLUDED.translation, description = EXCLUDED.description";
        queryParams($this->conn, $sql, [$projectId, $locale, $term, $translation, $description]);
    }

    public function saveProjectTerm(
        int $projectId,
        string $locale,
        string $term,
        string $translation,
        string $description,
        ?int $termId = null
    ): int {
        if ($termId === null) {
            $result = queryParams(
                $this->conn,
                'INSERT INTO glossary (project_id, locale, term, translation, description)
                 VALUES ($1, $2, $3, $4, $5)
                 ON CONFLICT (project_id, locale, term) DO NOTHING
                 RETURNING id',
                [$projectId, $locale, $term, $translation, $description]
            );
        } else {
            $result = queryParams(
                $this->conn,
                'UPDATE glossary
                 SET term = $1, translation = $2, description = $3
                 WHERE id = $4 AND project_id = $5 AND locale = $6
                 RETURNING id',
                [$term, $translation, $description, $termId, $projectId, $locale]
            );
        }

        $row = pg_fetch_assoc($result);
        if (!$row) {
            throw new RuntimeException($termId === null ? 'GLOSSARY_TERM_EXISTS' : 'GLOSSARY_TERM_NOT_FOUND');
        }
        return (int)$row['id'];
    }

    public function deleteProjectTerm(int $projectId, string $locale, int $termId): bool
    {
        $result = queryParams(
            $this->conn,
            'DELETE FROM glossary WHERE id = $1 AND project_id = $2 AND locale = $3 RETURNING id',
            [$termId, $projectId, $locale]
        );
        return pg_fetch_assoc($result) !== false;
    }

    public function getTerms(int $projectId, string $locale): array {
        $res = queryParams(
            $this->conn, 
            "SELECT id, project_id, term, translation, description FROM glossary
             WHERE locale = $1 AND (project_id = $2 OR project_id IS NULL)
             ORDER BY length(term) DESC",
            [$locale, $projectId]
        );
        $terms = [];
        while ($row = pg_fetch_assoc($res)) {
            $terms[] = $row;
        }
        return $terms;
    }
}
