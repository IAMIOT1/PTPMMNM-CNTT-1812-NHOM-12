<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class ProjectAccess
{
    public static function isSystemAdmin(array $user): bool
    {
        return ($user['role'] ?? null) === 'admin';
    }

    public static function roleFor(
        PgSql\Connection $connection,
        int $projectId,
        int $userId
    ): ?string {
        $result = queryParams(
            $connection,
            'SELECT role_in_project FROM project_members
             WHERE project_id = $1 AND user_id = $2',
            [$projectId, $userId]
        );
        $row = pg_fetch_assoc($result);
        return $row ? $row['role_in_project'] : null;
    }

    public static function canManageMembers(
        PgSql\Connection $connection,
        int $projectId,
        array $user
    ): bool {
        return self::isSystemAdmin($user) ||
            self::roleFor($connection, $projectId, (int)$user['id']) === 'Team Lead';
    }

    public static function canTranslate(
        PgSql\Connection $connection,
        int $projectId,
        array $user
    ): bool {
        return in_array(
            self::roleFor($connection, $projectId, (int)$user['id']),
            ['Team Lead', 'Translator', 'Reviewer'],
            true
        );
    }

    public static function canReview(
        PgSql\Connection $connection,
        int $projectId,
        array $user
    ): bool {
        return in_array(
            self::roleFor($connection, $projectId, (int)$user['id']),
            ['Team Lead', 'Reviewer'],
            true
        );
    }
}
