<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class NotificationManager
{
    public function __construct(private PgSql\Connection $connection)
    {
    }

    public function unreadCount(int $userId): int
    {
        $result = queryParams(
            $this->connection,
            'SELECT COUNT(*) FROM notifications WHERE user_id = $1 AND is_read = FALSE',
            [$userId]
        );
        return (int)pg_fetch_result($result, 0, 0);
    }

    public function recentForUser(int $userId, int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));
        $result = queryParams(
            $this->connection,
            'SELECT id, message, is_read, created_at
             FROM notifications
             WHERE user_id = $1
             ORDER BY created_at DESC, id DESC
             LIMIT $2',
            [$userId, $limit]
        );
        $notifications = [];
        while ($row = pg_fetch_assoc($result)) {
            $notifications[] = $row;
        }
        return $notifications;
    }

    public static function create(
        PgSql\Connection $connection,
        int $userId,
        string $message
    ): void {
        queryParams(
            $connection,
            'INSERT INTO notifications (user_id, message) VALUES ($1, $2)',
            [$userId, $message]
        );
    }
}
