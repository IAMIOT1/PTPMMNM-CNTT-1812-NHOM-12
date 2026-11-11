<?php
declare(strict_types=1);

class AuditLogger {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function log(
        int $projectId,
        ?int $stringId,
        string $actionType,
        string $details,
        ?int $actorUserId = null
    ): void {
        $actorUserId ??= isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipAddress = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)
            ? $remoteAddress
            : null;
        $sql = "INSERT INTO audit_logs
                (project_id, string_id, actor_user_id, action_type, details, ip_address)
                VALUES ($1, $2, COALESCE($3, NULLIF(current_setting('tms.user_id', TRUE), '')::INT), $4, $5, $6)";
        queryParams(
            $this->conn,
            $sql,
            [$projectId, $stringId, $actorUserId, $actionType, $details, $ipAddress]
        );
    }

    public function getLogs(?int $projectId = null, int $limit = 1000, array $filters = []): array {
        $sql = "SELECT a.*, COALESCE(u.username, 'Hệ thống') AS actor_username
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.actor_user_id";
        $params = [];
        $conditions = [];
        if ($projectId !== null) {
            $conditions[] = 'a.project_id = $1';
            $params[] = $projectId;
        }
        if (($filters['action'] ?? '') !== '') {
            $conditions[] = 'a.action_type = $' . (count($params) + 1);
            $params[] = (string)$filters['action'];
        }
        if (($filters['user_id'] ?? '') !== '' && filter_var($filters['user_id'], FILTER_VALIDATE_INT)) {
            $conditions[] = 'a.actor_user_id = $' . (count($params) + 1);
            $params[] = (int)$filters['user_id'];
        }
        if (($filters['from'] ?? '') !== '' && strtotime((string)$filters['from']) !== false) {
            $conditions[] = 'a.created_at >= $' . (count($params) + 1) . '::timestamptz';
            $params[] = (string)$filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '' && strtotime((string)$filters['to']) !== false) {
            $conditions[] = 'a.created_at < ($' . (count($params) + 1) . '::date + INTERVAL \'1 day\')';
            $params[] = (string)$filters['to'];
        }
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY a.id DESC LIMIT $" . (count($params) + 1);
        $params[] = $limit;
        $result = queryParams($this->conn, $sql, $params);
        if ($result) {
            $rows = pg_fetch_all($result);
            return $rows !== false ? $rows : [];
        }
        return [];
    }
}