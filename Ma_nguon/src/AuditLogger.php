<?php
declare(strict_types=1);

class AuditLogger {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function log(int $projectId, ?int $stringId, string $actionType, string $details): void {
        $sql = "INSERT INTO audit_logs (project_id, string_id, action_type, details) VALUES ($1, $2, $3, $4)";
        queryParams($this->conn, $sql, [$projectId, $stringId, $actionType, $details]);
    }

    public function getLogs(int $limit = 50): array {
        $sql = "SELECT * FROM audit_logs ORDER BY id DESC LIMIT $1";
        $result = queryParams($this->conn, $sql, [$limit]);
        if ($result) {
            $rows = pg_fetch_all($result);
            return $rows !== false ? $rows : [];
        }
        return [];
    }
}