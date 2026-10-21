<?php
declare(strict_types=1);
require_once __DIR__.'/Database.php';

final class RegistrationStats {
    public function __construct(private PgSql\Connection $connection) {}

    public function statsForEvent(int $eventId): array {
        if ($eventId <= 0) {
            return [];
        }
        
        $result = queryParams(
            $this->connection,
            'SELECT e.id AS event_id, e.title, COUNT(r.id) AS total ' .
            'FROM events e ' .
            'LEFT JOIN registrations r ON e.id = r.event_id ' .
            'WHERE e.id = $1 ' .
            'GROUP BY e.id, e.title',
            [$eventId]
        );
        
        $row = pg_fetch_assoc($result);
        if (!$row) {
            return [];
        }

        return [
            'event_id' => (int) $row['event_id'],
            'title'    => (string) $row['title'],
            'total'    => (int) $row['total'],
        ];
    }
}

function registrationStats(mixed $connOrEventId, mixed $eventidOrConn = null): array {
    if ($connOrEventId instanceof PgSql\Connection) {
        $connection = $connOrEventId;
        $eventId = (int)$eventidOrConn;
    } else {
        $eventId = (int)$connOrEventId;
        $connection = db();
    }
    $repo = new RegistrationStats($connection);
    return $repo->statsForEvent($eventId);
}
